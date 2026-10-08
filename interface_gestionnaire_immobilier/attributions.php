<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_immobilier');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'] ?? 0;
$success = '';
$error = '';

function mensualiteCredit(float $montant, float $taux, int $duree): float
{
    if ($montant <= 0 || $duree <= 0) {
        return 0.0;
    }

    $tauxMensuel = $taux / 100 / 12;
    if ($tauxMensuel <= 0) {
        return $montant / $duree;
    }

    return $montant * $tauxMensuel / (1 - pow(1 + $tauxMensuel, -$duree));
}

function getOrCreateCompteCreditsMembre(PDO $pdo, int $membreId): array
{
    $stmt = $pdo->prepare("
        SELECT ce.*
        FROM comptes_epargne ce
        JOIN types_compte_epargne tce ON tce.id = ce.type_compte_id
        WHERE ce.membre_id = ? AND tce.code = 'credits' AND ce.statut = 'actif'
        LIMIT 1
    ");
    $stmt->execute([$membreId]);
    $compte = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($compte) {
        return $compte;
    }

    $typeId = $pdo->query("SELECT id FROM types_compte_epargne WHERE code = 'credits' LIMIT 1")->fetchColumn();
    if (!$typeId) {
        throw new RuntimeException("Le type de compte 'credits' est introuvable.");
    }

    $numero = 'CRED-' . $membreId . '-' . date('YmdHis');
    $pdo->prepare("
        INSERT INTO comptes_epargne
            (membre_id, type_compte_id, numero_compte, solde, solde_bloque,
             solde_disponible, taux_interet, statut, date_ouverture, retenue_source)
        VALUES (?, ?, ?, 0, 0, 0, 0, 'actif', NOW(), 0)
    ")->execute([$membreId, $typeId, $numero]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'numero_compte' => $numero,
        'solde' => 0,
        'solde_bloque' => 0,
        'solde_disponible' => 0,
    ];
}

function getOrCreateCompteTechniqueImmobilier(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM comptes_techniques
        WHERE code = 'TECH-IMMO-MA2E' AND statut = 'actif'
        LIMIT 1
    ");
    $stmt->execute();
    $compte = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($compte) {
        return $compte;
    }

    try {
        $pdo->prepare("
            INSERT INTO comptes_techniques
                (code, libelle, solde, statut)
            VALUES ('TECH-IMMO-MA2E', 'Compte technique immobilier MA2E', 0, 'actif')
        ")->execute();
    } catch (Exception $e) {
        throw new RuntimeException(
            "Impossible de créer automatiquement le compte technique immobilier. "
            . "Exécutez d'abord la migration SQL des comptes techniques puis relancez l'approbation. Détail : "
            . $e->getMessage()
        );
    }

    return [
        'id' => (int)$pdo->lastInsertId(),
        'code' => 'TECH-IMMO-MA2E',
        'libelle' => 'Compte technique immobilier MA2E',
        'solde' => 0,
    ];
}

function creerEcheancierCredit(PDO $pdo, int $creditId, float $montantAccorde, float $tauxAnnuel, int $dureeMois, float $mensualite): void
{
    $capitalRestant = $montantAccorde;

    for ($i = 1; $i <= $dureeMois; $i++) {
        $interet = $capitalRestant * ($tauxAnnuel / 100 / 12);
        $capital = $mensualite - $interet;

        if ($i === $dureeMois) {
            $capital = $capitalRestant;
        }

        $capitalRestant -= $capital;

        $pdo->prepare("
            INSERT INTO echeanciers
                (credit_id, numero_echeance, date_echeance, capital_du,
                 interet_du, mensualite_due, solde_restant, statut)
            VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?, ?, 'a_venir')
        ")->execute([
            $creditId,
            $i,
            $i,
            round($capital, 2),
            round($interet, 2),
            round($mensualite, 2),
            max(0, round($capitalRestant, 2)),
        ]);
    }
}

function creerCreditDiffereAutomatique(
    PDO $pdo,
    int $membreId,
    ?int $demandeCreditId,
    int $creditImmobilierId,
    float $mensualiteImmo,
    float $quotiteCessible,
    int $dureeMois,
    int $annee,
    int $sequence
): ?int {
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(mensualite), 0)
        FROM credits
        WHERE membre_id = ? AND statut IN ('en_cours', 'en_retard') AND id != ?
    ");
    $sumStmt->execute([$membreId, $creditImmobilierId]);
    $mensualitesActives = (float)$sumStmt->fetchColumn();

    $quotiteSupportable = max(0, $quotiteCessible - $mensualitesActives - 15000);
    $partDiffereeMens = max(0, $mensualiteImmo - $quotiteSupportable);

    if ($partDiffereeMens <= 0) {
        return null;
    }

    $typeDiffereId = $pdo->query("SELECT id FROM types_credit WHERE code = 'differe' LIMIT 1")->fetchColumn();
    if (!$typeDiffereId) {
        throw new RuntimeException("Le type de crédit 'differe' est introuvable.");
    }

    $montantDiffere = round($partDiffereeMens * $dureeMois, 2);
    $numeroDiffere = 'DIF-' . $annee . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

    $pdo->prepare("
        INSERT INTO credits
            (demande_id, membre_id, type_credit_id, numero_credit,
             montant_accorde, taux_annuel, duree_mois, mensualite,
             capital_restant_du, frais_dossier, fonds_garantie,
             compte_garantie_id, statut, date_mise_en_place,
             date_fin_prevue, credit_immobilier_id)
        VALUES (?, ?, ?, ?, ?, 7.00, ?, 0, ?, 0, 0, NULL, 'en_cours',
                NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?)
    ")->execute([
        $demandeCreditId,
        $membreId,
        $typeDiffereId,
        $numeroDiffere,
        $montantDiffere,
        $dureeMois,
        $montantDiffere,
        $dureeMois,
        $creditImmobilierId,
    ]);

    $creditDiffereId = (int)$pdo->lastInsertId();
    $interetDiffere = $montantDiffere * (7.00 / 100 / 12) * $dureeMois;

    $pdo->prepare("
        INSERT INTO echeanciers
            (credit_id, numero_echeance, date_echeance, capital_du,
             interet_du, mensualite_due, solde_restant, statut)
        VALUES (?, 1, DATE_ADD(CURDATE(), INTERVAL ? MONTH), ?, ?, ?, 0, 'a_venir')
    ")->execute([
        $creditDiffereId,
        $dureeMois,
        $montantDiffere,
        round($interetDiffere, 2),
        round($montantDiffere + $interetDiffere, 2),
    ]);

    return $creditDiffereId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $demande_id = (int)($_POST['demande_id'] ?? 0);
    $motif_refus = trim($_POST['motif_refus'] ?? '');

    $demande_stmt = $pdo->prepare("
        SELECT dl.*, m.nom, m.prenoms, m.quotite_cessible, m.statut AS membre_statut,
               tl.libelle AS type_logement_libelle, tl.prix_total, tl.apport_obligatoire,
               tl.nombre_disponible, tl.duree_credit_mois, tl.taux_credit
        FROM demandes_logement dl
        JOIN membres m ON m.id = dl.membre_id
        JOIN types_logement tl ON tl.id = dl.type_logement_id
        WHERE dl.id = ?
        LIMIT 1
    ");
    $demande_stmt->execute([$demande_id]);
    $demande = $demande_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$demande) {
        $error = "Demande introuvable.";
    } else {
        try {
            if ($action === 'refuser') {
                if ($motif_refus === '') {
                    throw new RuntimeException("Le motif de refus est obligatoire.");
                }

                $pdo->prepare("
                    UPDATE demandes_logement
                    SET statut = 'refuse',
                        motif_refus = ?,
                        commentaire = COALESCE(commentaire, ?),
                        date_traitement = NOW(),
                        date_decision = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$motif_refus, $motif_refus, $demande_id]);

                $success = "Demande logement refusee.";
            }

            if ($action === 'approuver') {
                if ((int)$demande['nombre_disponible'] <= 0) {
                    throw new RuntimeException("Aucun logement disponible sur ce type.");
                }

                $pdo->beginTransaction();

                $stock_stmt = $pdo->prepare("SELECT nombre_disponible FROM types_logement WHERE id = ? FOR UPDATE");
                $stock_stmt->execute([$demande['type_logement_id']]);
                $stock = (int)$stock_stmt->fetchColumn();

                if ($stock <= 0) {
                    throw new RuntimeException("Le stock vient d'etre epuise sur ce type.");
                }

                $compteLogementStmt = $pdo->prepare("
                    SELECT ce.*
                    FROM comptes_epargne ce
                    JOIN types_compte_epargne tce ON tce.id = ce.type_compte_id
                    WHERE ce.id = ? AND ce.membre_id = ? AND tce.code = 'logement' AND ce.statut = 'actif'
                    LIMIT 1
                    FOR UPDATE
                ");
                $compteLogementStmt->execute([(int)$demande['compte_logement_id'], (int)$demande['membre_id']]);
                $compteLogement = $compteLogementStmt->fetch(PDO::FETCH_ASSOC);

                if (!$compteLogement) {
                    throw new RuntimeException("Le compte logement du membre est introuvable ou inactif.");
                }

                $apportUtilise = min((float)$compteLogement['solde'], (float)$demande['prix_total']);
                if ($apportUtilise < (float)$demande['apport_obligatoire']) {
                    throw new RuntimeException(
                        "Le solde actuel du compte logement ne couvre plus l'apport obligatoire. "
                        . "Disponible : " . number_format((float)$compteLogement['solde'], 0, ',', ' ')
                        . " F."
                    );
                }

                $typeImmoId = $pdo->query("SELECT id FROM types_credit WHERE code = 'immobilier' LIMIT 1")->fetchColumn();
                if (!$typeImmoId) {
                    throw new RuntimeException("Le type de crédit 'immobilier' est introuvable.");
                }

                $compteTechnique = getOrCreateCompteTechniqueImmobilier($pdo);
                $compteCreditsMembre = getOrCreateCompteCreditsMembre($pdo, (int)$demande['membre_id']);

                $prixTotal = (float)$demande['prix_total'];
                $montantFinance = max(0, $prixTotal - $apportUtilise);
                $tauxCredit = (float)($demande['taux_credit'] ?: 7.00);
                $dureeCredit = (int)($demande['duree_credit_mois'] ?: 168);
                $mensualite = mensualiteCredit($montantFinance, $tauxCredit, $dureeCredit);
                $fraisDossier = 100000.0;
                $fondsGarantie = 50000.0;
                $montantNetVerse = max(0, $montantFinance - $fraisDossier - $fondsGarantie);

                $annee = (int)date('Y');
                $countCredits = (int)$pdo->query("SELECT COUNT(*) FROM credits")->fetchColumn();
                $numeroCredit = 'CRD-' . $annee . '-' . str_pad($countCredits + 1, 5, '0', STR_PAD_LEFT);

                $pdo->prepare("
                    UPDATE comptes_epargne
                    SET solde = solde - ?, solde_disponible = solde_disponible - ?
                    WHERE id = ?
                ")->execute([$apportUtilise, $apportUtilise, $compteLogement['id']]);

                $soldeTechniqueAvant = (float)$compteTechnique['solde'];
                $soldeTechniqueApres = $soldeTechniqueAvant + $apportUtilise;
                $pdo->prepare("UPDATE comptes_techniques SET solde = ? WHERE id = ?")
                    ->execute([$soldeTechniqueApres, $compteTechnique['id']]);

                $refSortieApport = 'APL-OUT-' . date('YmdHis') . '-' . $demande_id;
                $pdo->prepare("
                    INSERT INTO mouvements
                        (compte_id, compte_technique_id, reference, type, montant, solde_avant, solde_apres,
                         date_valeur, date_operation, canal, utilisateur_id, commentaire)
                    VALUES (?, NULL, ?, 'virement', ?, ?, ?, CURDATE(), CURDATE(), 'systeme', ?, ?)
                ")->execute([
                    $compteLogement['id'],
                    $refSortieApport,
                    $apportUtilise,
                    (float)$compteLogement['solde'],
                    (float)$compteLogement['solde'] - $apportUtilise,
                    $user_id,
                    "Transfert apport logement vers compte technique interne pour la demande logement #$demande_id",
                ]);

                $refEntreeApport = 'APL-IN-' . date('YmdHis') . '-' . $demande_id;
                $pdo->prepare("
                    INSERT INTO mouvements
                        (compte_id, compte_technique_id, reference, type, montant, solde_avant, solde_apres,
                         date_valeur, date_operation, canal, utilisateur_id, commentaire)
                    VALUES (NULL, ?, ?, 'virement', ?, ?, ?, CURDATE(), CURDATE(), 'systeme', ?, ?)
                ")->execute([
                    $compteTechnique['id'],
                    $refEntreeApport,
                    $apportUtilise,
                    $soldeTechniqueAvant,
                    $soldeTechniqueApres,
                    $user_id,
                    "Reception apport logement membre {$demande['membre_id']} sur compte technique immobilier",
                ]);

                $pdo->prepare("
                    INSERT INTO credits
                        (demande_id, membre_id, type_credit_id, numero_credit,
                         montant_accorde, taux_annuel, duree_mois, mensualite,
                         capital_restant_du, frais_dossier, fonds_garantie,
                         compte_garantie_id, statut, date_mise_en_place, date_fin_prevue)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'en_cours',
                            NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH))
                ")->execute([
                    $demande['membre_id'],
                    $typeImmoId,
                    $numeroCredit,
                    $montantFinance,
                    $tauxCredit,
                    $dureeCredit,
                    $mensualite,
                    $montantFinance,
                    $fraisDossier,
                    $fondsGarantie,
                    $dureeCredit,
                ]);
                $creditId = (int)$pdo->lastInsertId();

                creerEcheancierCredit($pdo, $creditId, $montantFinance, $tauxCredit, $dureeCredit, $mensualite);

                $creditDiffereId = creerCreditDiffereAutomatique(
                    $pdo,
                    (int)$demande['membre_id'],
                    null,
                    $creditId,
                    $mensualite,
                    (float)$demande['quotite_cessible'],
                    $dureeCredit,
                    $annee,
                    $countCredits + 2
                );

                $soldeCreditsAvant = (float)$compteCreditsMembre['solde'];
                $soldeCreditsApres = $soldeCreditsAvant + $montantNetVerse;
                $pdo->prepare("
                    UPDATE comptes_epargne
                    SET solde = ?, solde_disponible = ?
                    WHERE id = ?
                ")->execute([
                    $soldeCreditsApres,
                    $soldeCreditsApres - (float)($compteCreditsMembre['solde_bloque'] ?? 0),
                    $compteCreditsMembre['id'],
                ]);

                $refOctroi = 'OCT-IMMO-' . date('YmdHis') . '-' . $creditId;
                $pdo->prepare("
                    INSERT INTO mouvements
                        (compte_id, reference, type, montant, solde_avant, solde_apres,
                         date_valeur, date_operation, canal, utilisateur_id, commentaire)
                    VALUES (?, ?, 'credit_octroi', ?, ?, ?, CURDATE(), CURDATE(), 'systeme', ?, ?)
                ")->execute([
                    $compteCreditsMembre['id'],
                    $refOctroi,
                    $montantNetVerse,
                    $soldeCreditsAvant,
                    $soldeCreditsApres,
                    $user_id,
                    "Octroi automatique du crédit immobilier $numeroCredit après approbation logement",
                ]);

                $pdo->prepare("
                    UPDATE types_logement
                    SET nombre_disponible = nombre_disponible - 1
                    WHERE id = ?
                ")->execute([$demande['type_logement_id']]);

                $pdo->prepare("
                    UPDATE demandes_logement
                    SET statut = 'approuve',
                        montant_apport_verse = ?,
                        credit_immobilier_id = ?,
                        motif_refus = NULL,
                        date_traitement = NOW(),
                        date_decision = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$apportUtilise, $creditId, $demande_id]);

                $pdo->prepare("
                    INSERT INTO notifications
                        (membre_id, titre, message, type, lien, date_creation)
                    VALUES (?, ?, ?, 'success', 'mes_credits.php', NOW())
                ")->execute([
                    $demande['membre_id'],
                    "🏠 Demande logement approuvée",
                    "Votre demande logement a été approuvée. Le crédit immobilier $numeroCredit a été déclenché automatiquement pour un montant de "
                    . number_format($montantFinance, 0, ',', ' ') . " F après prise en compte de votre apport de "
                    . number_format($apportUtilise, 0, ',', ' ') . " F."
                    . ($creditDiffereId ? " Un crédit différé complémentaire a également été créé." : ""),
                ]);

                $pdo->commit();
                $success = "Demande approuvée. Le logement est réservé, l'apport a été transféré vers le compte technique interne et le crédit immobilier a été créé automatiquement.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$demandes = $pdo->query("
    SELECT dl.*, m.nom, m.prenoms, m.numero_membre, m.quotite_cessible,
           tl.libelle AS type_logement_libelle, tl.prix_total, tl.apport_obligatoire,
           tl.nombre_disponible, tl.duree_credit_mois, tl.taux_credit,
           si.nom AS site_nom
    FROM demandes_logement dl
    JOIN membres m ON m.id = dl.membre_id
    JOIN types_logement tl ON tl.id = dl.type_logement_id
    JOIN sites_immobiliers si ON si.id = tl.site_id
    ORDER BY
      CASE dl.statut
        WHEN 'en_attente' THEN 1
        WHEN 'approuve' THEN 2
        WHEN 'refuse' THEN 3
        WHEN 'livre' THEN 4
        ELSE 5
      END,
      dl.date_demande DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attributions logements — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--bg:#F4F2EE;--card:#fff;--text:#1f1f1f;--muted:#777;--border:#e9e3d9;--green:#4CAF50;--red:#F44336;--orange:#FF9800;--blue:#2196F3}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text)}
.page{max-width:1200px;margin:0 auto;padding:28px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.title{font-family:'Playfair Display',serif;font-size:30px}
.sub{color:var(--muted);font-size:14px;margin-top:4px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:none;font-weight:700;cursor:pointer;text-decoration:none}
.btn-primary{background:var(--gold);color:white}
.btn-danger{background:#FFEBEE;color:#B71C1C}
.btn-secondary{background:#f4efe2;color:#7a5d17}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.card-header{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:700}
.list{padding:18px;display:flex;flex-direction:column;gap:16px}
.item{border:1px solid var(--border);border-radius:16px;padding:18px;background:#fff}
.item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.name{font-size:16px;font-weight:800}
.subline{color:var(--muted);font-size:12px;margin-top:4px}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-approuve{background:#E8F5E9;color:#2E7D32}
.badge-refuse{background:#FFEBEE;color:#C62828}
.badge-livre{background:#E3F2FD;color:#1565C0}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}
.box{background:#faf8f3;border-radius:12px;padding:10px 12px}
.box-label{font-size:10px;text-transform:uppercase;color:var(--muted);letter-spacing:.5px}
.box-value{font-size:13px;font-weight:700;margin-top:4px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.note{margin-top:12px;font-size:12px;line-height:1.5;color:#555}
.alert{padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:14px}
.alert-success{background:#E8F5E9;color:#1B5E20}
.alert-error{background:#FFEBEE;color:#B71C1C}
.refus-form{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.refus-form input{flex:1;min-width:240px;padding:10px 12px;border:1.5px solid var(--border);border-radius:10px}
@media(max-width:960px){.grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <div>
      <div class="title">Attributions logements</div>
      <div class="sub">Cette page traite les demandes logement. L'approbation reserve le stock et prepare ensuite le declenchement du credit immobilier.</div>
    </div>
    <a href="dashboard_gestionnaire_immo.php" class="btn btn-primary">Retour dashboard</a>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <section class="card">
    <div class="card-header">Demandes logement</div>
    <div class="list">
      <?php if (empty($demandes)): ?>
      <div style="padding:24px;color:var(--muted);text-align:center">Aucune demande logement a traiter.</div>
      <?php else: ?>
      <?php foreach ($demandes as $demande): ?>
      <?php
        $taux = (float)($demande['taux_credit'] ?: 7.00);
        $duree = (int)($demande['duree_credit_mois'] ?: 168);
        $montant_finance = max(0, (float)$demande['prix_total'] - (float)$demande['montant_apport_verse']);
        $mensualite = mensualiteCredit($montant_finance, $taux, $duree);
        $statut_class = $demande['statut'] === 'approuve' ? 'badge-approuve' : ($demande['statut'] === 'refuse' ? 'badge-refuse' : ($demande['statut'] === 'livre' ? 'badge-livre' : 'badge-attente'));
      ?>
      <article class="item">
        <div class="item-top">
          <div>
            <div class="name"><?php echo htmlspecialchars($demande['prenoms'] . ' ' . $demande['nom']); ?></div>
            <div class="subline">
              <?php echo htmlspecialchars($demande['numero_membre']); ?> ·
              <?php echo htmlspecialchars($demande['type_logement_libelle']); ?> ·
              <?php echo htmlspecialchars($demande['site_nom']); ?>
            </div>
          </div>
          <span class="badge <?php echo $statut_class; ?>"><?php echo htmlspecialchars(strtoupper($demande['statut'])); ?></span>
        </div>

        <div class="grid">
          <div class="box">
            <div class="box-label">Prix logement</div>
            <div class="box-value"><?php echo number_format($demande['prix_total'], 0, ',', ' '); ?> F</div>
          </div>
          <div class="box">
            <div class="box-label">Apport verse</div>
            <div class="box-value"><?php echo number_format($demande['montant_apport_verse'], 0, ',', ' '); ?> F</div>
          </div>
          <div class="box">
            <div class="box-label">Reste a financer</div>
            <div class="box-value"><?php echo number_format($montant_finance, 0, ',', ' '); ?> F</div>
          </div>
          <div class="box">
            <div class="box-label">Mensualite estimee</div>
            <div class="box-value"><?php echo number_format($mensualite, 0, ',', ' '); ?> F</div>
          </div>
        </div>

        <div class="note">
          Apport obligatoire : <strong><?php echo number_format($demande['apport_obligatoire'], 0, ',', ' '); ?> F</strong>
          · Stock disponible : <strong><?php echo (int)$demande['nombre_disponible']; ?></strong>
          · Quotite cessible membre : <strong><?php echo number_format($demande['quotite_cessible'], 0, ',', ' '); ?> F</strong>
          <?php if (!empty($demande['commentaire'])): ?>
          <br>Commentaire membre : <?php echo htmlspecialchars($demande['commentaire']); ?>
          <?php endif; ?>
          <?php if (!empty($demande['motif_refus'])): ?>
          <br>Motif refus : <?php echo htmlspecialchars($demande['motif_refus']); ?>
          <?php endif; ?>
          <?php if (!empty($demande['credit_immobilier_id'])): ?>
          <br>Credit immobilier deja cree : #<?php echo (int)$demande['credit_immobilier_id']; ?>
          <?php endif; ?>
        </div>

        <?php if ($demande['statut'] === 'en_attente'): ?>
        <div class="actions">
          <form method="POST">
            <input type="hidden" name="action" value="approuver">
            <input type="hidden" name="demande_id" value="<?php echo (int)$demande['id']; ?>">
            <button type="submit" class="btn btn-primary">Approuver</button>
          </form>
        </div>
        <form method="POST" class="refus-form">
          <input type="hidden" name="action" value="refuser">
          <input type="hidden" name="demande_id" value="<?php echo (int)$demande['id']; ?>">
          <input type="text" name="motif_refus" placeholder="Motif de refus" required>
          <button type="submit" class="btn btn-danger">Refuser</button>
        </form>
        <?php elseif ($demande['statut'] === 'approuve' && empty($demande['credit_immobilier_id'])): ?>
        <div class="actions">
          <span class="btn btn-secondary">Demande approuvee mais credit non encore créé</span>
        </div>
        <?php elseif ($demande['statut'] === 'approuve' && !empty($demande['credit_immobilier_id'])): ?>
        <div class="actions">
          <span class="btn btn-secondary">Credit immobilier créé automatiquement : #<?php echo (int)$demande['credit_immobilier_id']; ?></span>
        </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
</body>
</html>
