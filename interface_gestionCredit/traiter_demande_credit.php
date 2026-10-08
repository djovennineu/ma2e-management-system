<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_credit');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$nom     = $_SESSION['nom']    ?? '';
$prenoms = $_SESSION['prenoms'] ?? '';

$demande_id = (int)($_GET['id'] ?? 0);

// ============================================================
// CAS 1 : PAS D'ID → Liste des demandes en attente
// ============================================================
if (!$demande_id) {
    $demandes_dispo = $pdo->prepare("
        SELECT dc.id, dc.montant_demande, dc.date_demande, dc.statut,
               tc.libelle AS type_libelle,
               m.nom, m.prenoms, m.numero_membre
        FROM demandes_credit dc
        JOIN types_credit tc ON dc.type_credit_id = tc.id
        JOIN membres m ON dc.membre_id = m.id
        WHERE dc.statut IN ('en_attente','en_etude')
        ORDER BY dc.date_demande DESC
    ");
    $demandes_dispo->execute();
    $demandes_list = $demandes_dispo->fetchAll();

    if (empty($demandes_list)) { ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aucune demande — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:#F0EDE8;min-height:100vh;display:flex;align-items:center;justify-content:center}.empty-card{background:white;max-width:500px;margin:20px;padding:40px;border-radius:24px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.05)}.empty-icon{font-size:64px;margin-bottom:20px}h2{font-size:24px;color:#1a1a1a;margin-bottom:12px}p{color:#666;margin-bottom:24px;line-height:1.5}.btn{display:inline-block;padding:12px 24px;background:#C9A84C;color:white;text-decoration:none;border-radius:12px;font-weight:600}</style>
</head><body>
<div class="empty-card"><div class="empty-icon">📋</div><h2>Aucune demande en attente</h2>
<p>Il n'y a actuellement aucune demande de crédit à traiter.</p>
<a href="dashboard_gestionnaire_credit.php" class="btn">Retour au dashboard</a></div>
</body></html>
    <?php exit; }
    ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sélectionner une demande — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:#F0EDE8;padding:40px 20px}.container{max-width:1000px;margin:0 auto}.header{margin-bottom:30px}h1{font-size:28px;color:#1a1a1a;margin-bottom:8px}.subtitle{color:#666;font-size:14px}.card{background:white;border-radius:20px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05)}table{width:100%;border-collapse:collapse}th{text-align:left;padding:16px 20px;background:#f8f8f8;color:#666;font-weight:600;font-size:13px;text-transform:uppercase}td{padding:14px 20px;border-bottom:1px solid #eee;color:#333}.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}.badge-attente{background:#FFF8E1;color:#F57F17}.badge-etude{background:#E3F2FD;color:#1565C0}.btn{display:inline-block;padding:6px 16px;background:#C9A84C;color:white;text-decoration:none;border-radius:8px;font-size:13px;font-weight:500}.btn:hover{background:#b8922e}.montant{font-weight:600;color:#C9A84C}.back-link{display:inline-block;margin-top:20px;color:#C9A84C;text-decoration:none;font-weight:500}</style>
</head><body>
<div class="container">
  <div class="header"><h1>📋 Sélectionner une demande</h1><p class="subtitle">Choisissez une demande de crédit à traiter</p></div>
  <div class="card"><table>
    <thead><tr><th>ID</th><th>Date</th><th>Membre</th><th>Type</th><th>Montant</th><th>Statut</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($demandes_list as $d): ?>
    <tr>
      <td>#<?php echo $d['id']; ?></td>
      <td><?php echo date('d/m/Y', strtotime($d['date_demande'])); ?></td>
      <td><?php echo htmlspecialchars($d['prenoms'].' '.$d['nom']); ?></td>
      <td><?php echo htmlspecialchars($d['type_libelle']); ?></td>
      <td class="montant"><?php echo number_format($d['montant_demande'],0,',',' '); ?> F</td>
      <td><span class="badge <?php echo $d['statut']==='en_attente'?'badge-attente':'badge-etude'; ?>">
        <?php echo $d['statut']==='en_attente'?'⏳ En attente':'🔍 En étude'; ?></span></td>
      <td><a href="?id=<?php echo $d['id']; ?>" class="btn">Traiter</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <a href="dashboard_gestionnaire_credit.php" class="back-link">← Retour au dashboard</a>
</div></body></html>
    <?php exit;
}

// ============================================================
// CAS 2 : Chargement de la demande
// ============================================================
$stmt = $pdo->prepare("
    SELECT dc.*, tc.libelle AS type_libelle, tc.code AS type_code,
           tc.montant_min, tc.montant_max, tc.duree_max_mois,
           m.id AS membre_id, m.nom, m.prenoms, m.numero_membre,
           m.salaire_net, m.quotite_cessible, m.date_adhesion,
           m.email AS membre_email,
           s.nom AS societe_nom, s.code AS societe_code
    FROM demandes_credit dc
    JOIN types_credit tc ON dc.type_credit_id = tc.id
    JOIN membres m ON dc.membre_id = m.id
    JOIN societes s ON m.societe_id = s.id
    WHERE dc.id = ? AND dc.statut IN ('en_attente','en_etude')
");
$stmt->execute([$demande_id]);
$demande = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$demande) { ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Introuvable — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'DM Sans',sans-serif;background:#F0EDE8;min-height:100vh;display:flex;align-items:center;justify-content:center}.card{background:white;max-width:500px;margin:20px;padding:40px;border-radius:24px;text-align:center}.btn{display:inline-block;padding:12px 24px;background:#C9A84C;color:white;text-decoration:none;border-radius:12px;font-weight:600;margin-top:20px}</style>
</head><body><div class="card"><div style="font-size:64px;margin-bottom:20px">❌</div>
<h2 style="font-size:24px;margin-bottom:12px">Demande introuvable</h2>
<p style="color:#666">La demande n°<?php echo $demande_id; ?> n'existe pas ou a déjà été traitée.</p>
<a href="dashboard_gestionnaire_credit.php" class="btn">Retour au dashboard</a></div>
</body></html>
<?php exit; }

// ============================================================
// COMPTES DU MEMBRE
// ============================================================
$comptes = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut = 'actif'
");
$comptes->execute([$demande['membre_id']]);
$comptes_list    = $comptes->fetchAll(PDO::FETCH_ASSOC);
$comptes_par_type = [];
foreach ($comptes_list as $c) $comptes_par_type[$c['type_code']] = $c;

// ============================================================
// CALCULS TAUX & ÉLIGIBILITÉ
// ============================================================
$now           = new DateTime();
$date_adhesion = new DateTime($demande['date_adhesion']);
$diff_adhesion = $now->diff($date_adhesion);
$anciennete_mois = $diff_adhesion->y * 12 + $diff_adhesion->m;

// CORRECTION #12 : ancienneté expresse en MOIS
$anciennete_exp_mois = 0;
if (isset($comptes_par_type['expresse'])) {
    $date_ouv    = new DateTime($comptes_par_type['expresse']['date_ouverture']);
    $diff_exp    = $now->diff($date_ouv);
    $anciennete_exp_mois = $diff_exp->y * 12 + $diff_exp->m;
}

// CORRECTION #12 : taux express basé sur les MOIS
$taux_calcule = (float)$demande['taux_propose'];

if ($demande['type_code'] === 'express') {
    if ($anciennete_exp_mois >= 120)     $taux_calcule = 7.00;
    elseif ($anciennete_exp_mois >= 96)  $taux_calcule = 7.50;
    elseif ($anciennete_exp_mois >= 72)  $taux_calcule = 8.00;
    elseif ($anciennete_exp_mois >= 48)  $taux_calcule = 8.50;
    elseif ($anciennete_exp_mois >= 24)  $taux_calcule = 9.00;
    else                                  $taux_calcule = 9.00;
}

if ($demande['type_code'] === 'ordinaire') {
    $solde_ord = $comptes_par_type['ordinaire']['solde'] ?? 0;
    $ratio     = ($solde_ord > 0 && $demande['montant_demande'] > 0)
               ? min($solde_ord / $demande['montant_demande'], 9.99)
               : 0;
    if ($ratio >= 0.75)     $taux_calcule = 6.00;
    elseif ($ratio >= 0.50) $taux_calcule = 6.50;
    else                    $taux_calcule = 7.00;
}

if ($demande['type_code'] === 'immobilier') {
    $taux_calcule = 7.00;
}

// Mensualité
$taux_mensuel = $taux_calcule / 100 / 12;
$mensualite   = ($taux_mensuel > 0)
    ? $demande['montant_demande'] * $taux_mensuel / (1 - pow(1 + $taux_mensuel, -$demande['duree_demandee_mois']))
    : $demande['montant_demande'] / $demande['duree_demandee_mois'];

// Quotité nécessaire (mensualité + 15 000 F) — TOUS types
$quotite_necessaire = $mensualite + 15000;
$quotite_ok         = ($quotite_necessaire <= $demande['quotite_cessible']);

// Apport immobilier — CORRECTION #16 : min 20%
$apport_requis = 0;
$apport_ok     = true;
$solde_logement = $comptes_par_type['logement']['solde'] ?? 0;
if ($demande['type_code'] === 'immobilier') {
    $apport_requis = $demande['montant_demande'] * 0.20; // min 20%
    $apport_ok     = ($solde_logement >= $apport_requis);
}

// ── Garantie ordinaire ──
// CORRECTION #13 : moitié immédiate, moitié déduite du crédit
$garantie_totale    = 0;
$garantie_immediate = 0;  // moitié disponible immédiatement par le membre
$garantie_sur_credit = 0; // moitié déduite du montant versé

if ($demande['type_code'] === 'ordinaire') {
    $garantie_totale     = $demande['montant_demande'] >= 7000000
        ? $demande['montant_demande'] * 0.50
        : $demande['montant_demande'] * 0.25;
    $garantie_immediate  = $garantie_totale / 2;
    $garantie_sur_credit = $garantie_totale / 2;
}

// ── Frais de dossier ──
$frais_dossier = 0;
if ($demande['type_code'] === 'express') {
    $frais_dossier = 2000;
} elseif ($demande['type_code'] === 'ordinaire') {
    $frais_dossier = $demande['montant_demande'] * 0.005;
    $frais_dossier = max(5000, min(50000, $frais_dossier));
} elseif ($demande['type_code'] === 'immobilier') {
    $frais_dossier = 100000;
}

// ── Fonds de garantie ──
$fonds_garantie = 0;
if ($demande['type_code'] === 'express') {
    $fonds_garantie = $demande['montant_demande'] * 0.005;
} elseif ($demande['type_code'] === 'ordinaire') {
    $fonds_garantie = $demande['montant_demande'] * 0.01;
} elseif ($demande['type_code'] === 'immobilier') {
    $fonds_garantie = 50000;
}

// CORRECTION #13 : montant net versé au membre
// = montant - frais dossier - fonds garantie - moitié garantie (ordinaire)
$montant_net_verse = $demande['montant_demande'] - $frais_dossier - $fonds_garantie - $garantie_sur_credit;

// ============================================================
// TRAITEMENT POST
// ============================================================
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $motif  = trim($_POST['motif'] ?? '');

    // ──────────────────────────────────────
    // ACTION : APPROUVER
    // ──────────────────────────────────────
    if ($action === 'approuver') {

        // Vérifications finales avant approbation
        if (!$quotite_ok) {
            $error = "Impossible d'approuver : la quotité nécessaire ("
                   . number_format($quotite_necessaire, 0, ',', ' ')
                   . " F) dépasse la quotité cessible ("
                   . number_format($demande['quotite_cessible'], 0, ',', ' ') . " F).";
        } elseif ($demande['type_code'] === 'immobilier' && !$apport_ok) {
            $error = "Impossible d'approuver : apport insuffisant. Requis : "
                   . number_format($apport_requis, 0, ',', ' ')
                   . " F (20% minimum). Disponible : "
                   . number_format($solde_logement, 0, ',', ' ') . " F.";
        } else {
            try {
                $pdo->beginTransaction();

                $annee = date('Y');
                $count = $pdo->query("SELECT COUNT(*) FROM credits")->fetchColumn();
                $num_credit = 'CRD-' . $annee . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

                // ── Compte de garantie (Ordinaire) ──
                // CORRECTION #13 : créer/créditer avec garantie_immediate seulement
                $compte_garantie_id = null;
                if ($demande['type_code'] === 'ordinaire' && $garantie_totale > 0) {

                    $grt_exist = $pdo->prepare("
                        SELECT id FROM comptes_epargne
                        WHERE membre_id = ?
                          AND type_compte_id = (SELECT id FROM types_compte_epargne WHERE code='garantie' LIMIT 1)
                          AND statut = 'actif'
                        LIMIT 1
                    ");
                    $grt_exist->execute([$demande['membre_id']]);
                    $existing = $grt_exist->fetch();

                    if ($existing) {
                        $compte_garantie_id = $existing['id'];
                        $pdo->prepare("
                            UPDATE comptes_epargne
                            SET solde = solde + ?,
                                solde_bloque = solde_bloque + ?
                            WHERE id = ?
                        ")->execute([$garantie_immediate, $garantie_immediate, $compte_garantie_id]);
                    } else {
                        $num_grt = 'GRT-' . $annee . '-' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
                        $pdo->prepare("
                            INSERT INTO comptes_epargne
                                (membre_id, type_compte_id, numero_compte, solde, solde_bloque,
                                 solde_disponible, taux_interet, statut, date_ouverture, retenue_source)
                            VALUES (?,
                                (SELECT id FROM types_compte_epargne WHERE code='garantie' LIMIT 1),
                                ?, ?, ?, 0, 0, 'actif', NOW(), 0)
                        ")->execute([
                            $demande['membre_id'],
                            $num_grt,
                            $garantie_immediate,
                            $garantie_immediate
                        ]);
                        $compte_garantie_id = $pdo->lastInsertId();
                    }
                }

                // ── Créer le crédit ──
                $pdo->prepare("
                    INSERT INTO credits
                        (demande_id, membre_id, type_credit_id, numero_credit,
                         montant_accorde, taux_annuel, duree_mois, mensualite,
                         capital_restant_du, frais_dossier, fonds_garantie,
                         compte_garantie_id, statut, date_mise_en_place,
                         date_fin_prevue)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_cours',
                            NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH))
                ")->execute([
                    $demande_id,
                    $demande['membre_id'],
                    $demande['type_credit_id'],
                    $num_credit,
                    $demande['montant_demande'],
                    $taux_calcule,
                    $demande['duree_demandee_mois'],
                    $mensualite,
                    $demande['montant_demande'],
                    $frais_dossier,
                    $fonds_garantie,
                    $compte_garantie_id,
                    $demande['duree_demandee_mois']
                ]);
                $credit_id = $pdo->lastInsertId();

                // ── Générer l'échéancier ──
                $capital_restant_ech = $demande['montant_demande'];
                for ($i = 1; $i <= $demande['duree_demandee_mois']; $i++) {
                    $interet_ech = $capital_restant_ech * ($taux_calcule / 100 / 12);
                    $capital_ech = $mensualite - $interet_ech;
                    if ($i === $demande['duree_demandee_mois']) {
                        // Dernière échéance : ajustement arrondi
                        $capital_ech = $capital_restant_ech;
                    }
                    $capital_restant_ech -= $capital_ech;

                    $pdo->prepare("
                        INSERT INTO echeanciers
                            (credit_id, numero_echeance, date_echeance, capital_du,
                             interet_du, mensualite_due, solde_restant, statut)
                        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MONTH),
                                ?, ?, ?, ?, 'a_venir')
                    ")->execute([
                        $credit_id, $i, $i,
                        round($capital_ech, 2),
                        round($interet_ech, 2),
                        round($mensualite, 2),
                        max(0, round($capital_restant_ech, 2))
                    ]);
                }

                // ── Crédit différé (immobilier) ── CORRECTION #17
                // Si la mensualité dépasse la quotité cessible disponible,
                // on génère automatiquement un crédit différé
                if ($demande['type_code'] === 'immobilier') {
                    // Mensualités déjà en cours
                    $mens_en_cours = (float)$pdo->prepare("
                        SELECT COALESCE(SUM(mensualite),0) FROM credits
                        WHERE membre_id = ? AND statut IN ('en_cours','en_retard') AND id != ?
                    ")->execute([$demande['membre_id'], $credit_id])
                    ? $pdo->query("SELECT COALESCE(SUM(mensualite),0) FROM credits WHERE membre_id = {$demande['membre_id']} AND statut IN ('en_cours','en_retard') AND id != $credit_id")->fetchColumn()
                    : 0;

                    $quotite_disponible  = $demande['quotite_cessible'] - $mens_en_cours - 15000;
                    $part_differee_mens  = max(0, $mensualite - $quotite_disponible);

                    if ($part_differee_mens > 0) {
                        // Montant du différé = part non supportée × durée
                        $montant_differe = round($part_differee_mens * $demande['duree_demandee_mois']);
                        $num_differe     = 'DIF-' . $annee . '-' . str_pad($count + 2, 5, '0', STR_PAD_LEFT);

                        $pdo->prepare("
                            INSERT INTO credits
                                (demande_id, membre_id, type_credit_id, numero_credit,
                                 montant_accorde, taux_annuel, duree_mois, mensualite,
                                 capital_restant_du, frais_dossier, fonds_garantie,
                                 compte_garantie_id, statut, date_mise_en_place,
                                 date_fin_prevue, credit_immobilier_id)
                            VALUES (?, ?, (SELECT id FROM types_credit WHERE code='differe' LIMIT 1),
                                    ?, ?, 7.00, ?, 0, ?, 0, 0, NULL, 'en_cours',
                                    NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?)
                        ")->execute([
                            $demande_id,
                            $demande['membre_id'],
                            $num_differe,
                            $montant_differe,
                            $demande['duree_demandee_mois'],
                            $montant_differe,
                            $demande['duree_demandee_mois'],
                            $credit_id
                        ]);

                        $credit_differe_id = $pdo->lastInsertId();

                        // Une seule échéance à la fin (paiement en une seule fois)
                        $interet_differe = $montant_differe * (7.00 / 100 / 12) * $demande['duree_demandee_mois'];
                        $pdo->prepare("
                            INSERT INTO echeanciers
                                (credit_id, numero_echeance, date_echeance, capital_du,
                                 interet_du, mensualite_due, solde_restant, statut)
                            VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? MONTH),
                                    ?, ?, ?, 0, 'a_venir')
                        ")->execute([
                            $credit_differe_id,
                            $demande['duree_demandee_mois'],
                            $montant_differe,
                            round($interet_differe, 2),
                            round($montant_differe + $interet_differe, 2)
                        ]);
                    }
                }

                // ── Créditer le compte Crédits ──
                // CORRECTION #13 : on verse le montant NET (après déductions)
                $compte_credits = $pdo->prepare("
                    SELECT ce.* FROM comptes_epargne ce
                    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
                    WHERE ce.membre_id = ? AND tce.code = 'credits' AND ce.statut = 'actif'
                    LIMIT 1
                ");
                $compte_credits->execute([$demande['membre_id']]);
                $compte_credits = $compte_credits->fetch(PDO::FETCH_ASSOC);

                if (!$compte_credits) {
                    // Créer le compte Crédits s'il n'existe pas
                    $type_cred_id = $pdo->query("SELECT id FROM types_compte_epargne WHERE code='credits' LIMIT 1")->fetchColumn();
                    $num_cred_cpt = 'CRED-' . $demande['membre_id'] . '-' . date('Ymd') . '-' . rand(100,999);
                    $pdo->prepare("
                        INSERT INTO comptes_epargne
                            (membre_id, type_compte_id, numero_compte, solde, solde_bloque,
                             solde_disponible, taux_interet, statut, date_ouverture, retenue_source)
                        VALUES (?, ?, ?, 0, 0, 0, 0, 'actif', NOW(), 0)
                    ")->execute([$demande['membre_id'], $type_cred_id, $num_cred_cpt]);
                    $id_new = $pdo->lastInsertId();
                    $compte_credits = ['id' => $id_new, 'solde' => 0, 'solde_bloque' => 0];
                }

                $solde_avant = (float)$compte_credits['solde'];
                $solde_apres = $solde_avant + $montant_net_verse;

                // Mise à jour solde
                $pdo->prepare("
                    UPDATE comptes_epargne
                    SET solde = ?, solde_disponible = ?
                    WHERE id = ?
                ")->execute([
                    $solde_apres,
                    $solde_apres - (float)($compte_credits['solde_bloque'] ?? 0),
                    $compte_credits['id']
                ]);

                // Mouvement octroi crédit (montant net)
                $ref_oct = 'OCT-' . date('YmdHis') . '-' . str_pad($credit_id, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("
                    INSERT INTO mouvements
                        (compte_id, reference, type, montant, solde_avant, solde_apres,
                         date_valeur, date_operation, canal, utilisateur_id, commentaire)
                    VALUES (?, ?, 'credit_octroi', ?, ?, ?, ?, ?, 'virement', ?, ?)
                ")->execute([
                    $compte_credits['id'], $ref_oct,
                    $montant_net_verse,
                    $solde_avant, $solde_apres,
                    date('Y-m-d'), date('Y-m-d'),
                    $user_id,
                    "Octroi crédit $num_credit — net après déductions"
                ]);

                // Mouvement frais de dossier (si applicable)
                if ($frais_dossier > 0) {
                    $ref_frais = 'FRS-' . date('YmdHis') . '-' . rand(100,999);
                    $pdo->prepare("
                        INSERT INTO mouvements
                            (compte_id, reference, type, montant, solde_avant, solde_apres,
                             date_valeur, date_operation, canal, utilisateur_id, commentaire)
                        VALUES (?, ?, 'frais_dossier', ?, ?, ?, ?, ?, 'systeme', ?, ?)
                    ")->execute([
                        $compte_credits['id'], $ref_frais,
                        $frais_dossier,
                        $solde_avant, $solde_avant, // frais déduits avant versement
                        date('Y-m-d'), date('Y-m-d'),
                        $user_id,
                        "Frais de dossier crédit $num_credit"
                    ]);
                }

                // Mouvement fonds de garantie
                if ($fonds_garantie > 0) {
                    $ref_fonds = 'FDG-' . date('YmdHis') . '-' . rand(100,999);
                    $pdo->prepare("
                        INSERT INTO mouvements
                            (compte_id, reference, type, montant, solde_avant, solde_apres,
                             date_valeur, date_operation, canal, utilisateur_id, commentaire)
                        VALUES (?, ?, 'fonds_garantie', ?, ?, ?, ?, ?, 'systeme', ?, ?)
                    ")->execute([
                        $compte_credits['id'], $ref_fonds,
                        $fonds_garantie,
                        $solde_avant, $solde_avant,
                        date('Y-m-d'), date('Y-m-d'),
                        $user_id,
                        "Fonds de garantie crédit $num_credit (1%)"
                    ]);
                }

                // Mise à jour statut demande
                $pdo->prepare("
                    UPDATE demandes_credit
                    SET statut = 'approuve', date_traitement = NOW(), instructeur_id = ?
                    WHERE id = ?
                ")->execute([$user_id, $demande_id]);

                // Notification membre
                $pdo->prepare("
                    INSERT INTO notifications
                        (membre_id, titre, message, type, lien, date_creation)
                    VALUES (?, ?, ?, 'success', 'mes_credits.php', NOW())
                ")->execute([
                    $demande['membre_id'],
                    "💰 Crédit approuvé — $num_credit",
                    "Votre " . $demande['type_libelle'] . " de "
                    . number_format($demande['montant_demande'], 0, ',', ' ')
                    . " F a été approuvé. Montant net reçu : "
                    . number_format($montant_net_verse, 0, ',', ' ') . " F (après déduction des frais)."
                ]);

                $pdo->commit();

                $success = "✅ Crédit <strong>$num_credit</strong> approuvé avec succès !<br>"
                         . "• Montant accordé : " . number_format($demande['montant_demande'], 0, ',', ' ') . " F<br>"
                         . "• Frais dossier déduits : " . number_format($frais_dossier, 0, ',', ' ') . " F<br>"
                         . "• Fonds garantie déduits : " . number_format($fonds_garantie, 0, ',', ' ') . " F"
                         . ($garantie_sur_credit > 0 ? "<br>• ½ Garantie déduite : " . number_format($garantie_sur_credit, 0, ',', ' ') . " F" : "")
                         . "<br>• <strong>Montant net versé : " . number_format($montant_net_verse, 0, ',', ' ') . " F</strong>";

                header("refresh:3;url=dashboard_gestionnaire_credit.php");

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erreur lors de l'approbation : " . $e->getMessage();
            }
        }

    // ──────────────────────────────────────
    // ACTION : REFUSER
    // ──────────────────────────────────────
    } elseif ($action === 'refuser') {
        if (empty($motif)) {
            $error = "Veuillez indiquer un motif de refus.";
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE demandes_credit
                    SET statut = 'refuse', motif_refus = ?,
                        date_traitement = NOW(), instructeur_id = ?
                    WHERE id = ?
                ")->execute([$motif, $user_id, $demande_id]);

                $pdo->prepare("
                    INSERT INTO notifications
                        (membre_id, titre, message, type, lien, date_creation)
                    VALUES (?, 'Demande de crédit refusée', ?, 'danger', 'mes_demandes_credit.php', NOW())
                ")->execute([
                    $demande['membre_id'],
                    "Votre demande de " . $demande['type_libelle']
                    . " du " . date('d/m/Y', strtotime($demande['date_demande']))
                    . " a été refusée. Motif : $motif"
                ]);

                $pdo->commit();
                $success = "Demande refusée.";
                header("refresh:2;url=dashboard_gestionnaire_credit.php");

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erreur : " . $e->getMessage();
            }
        }

    // ──────────────────────────────────────
    // ACTION : METTRE EN ÉTUDE
    // ──────────────────────────────────────
    } elseif ($action === 'en_etude') {
        try {
            $pdo->prepare("
                UPDATE demandes_credit
                SET statut = 'en_etude', date_traitement = NOW(), instructeur_id = ?
                WHERE id = ?
            ")->execute([$user_id, $demande_id]);
            $success = "Demande mise en étude.";
            header("refresh:1;url=dashboard_gestionnaire_credit.php");
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Traiter demande — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px}
.btn-back:hover{background:rgba(0,0,0,.25)}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center}
.t-btn.active{background:rgba(255,255,255,.4)}
.content{max-width:820px;margin:0 auto;padding:20px}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-warn{background:#FFF8E1;color:#F57F17;border-left:4px solid #FF9800}
.card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:22px}
.card-header-title{font-size:15px;font-weight:700;color:var(--text)}
.card-body{padding:20px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.info-item{background:var(--bg);border-radius:12px;padding:13px}
.info-label{font-size:11px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.info-value{font-size:15px;font-weight:700;color:var(--text)}
.info-value.gold{color:var(--gold)}
.info-value.red{color:var(--red)}
.info-value.green{color:var(--green)}
.badge{padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;display:inline-block}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-etude{background:#E3F2FD;color:#1565C0}

/* Simulation box */
.sim-box{background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:12px;padding:18px;margin-top:0}
.sim-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px}
.sim-row:last-child{border-bottom:none;padding-top:10px;margin-top:4px}
.sim-label{color:rgba(255,255,255,.6)}
.sim-val{color:white;font-weight:700}
.sim-val.gold{color:var(--gold)}
.sim-val.red{color:#EF9A9A}
.sim-val.green{color:#81C784}

/* Net versé highlight */
.net-verse-box{background:linear-gradient(135deg,#1B5E20,#2E7D32);border-radius:10px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;justify-content:space-between}
.net-verse-label{color:rgba(255,255,255,.8);font-size:13px}
.net-verse-val{color:white;font-size:20px;font-weight:800}

/* Formulaire décision */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;resize:vertical;min-height:90px}
.form-group textarea:focus{border-color:var(--gold);outline:none}
.btn-group{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap}
.btn-primary{padding:12px 20px;background:var(--gold);color:white;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-primary:hover{background:var(--gold-dark)}
.btn-primary:disabled{opacity:.4;cursor:not-allowed}
.btn-danger{padding:12px 20px;background:var(--red);color:white;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.btn-danger:hover{background:#c62828}
.btn-secondary{padding:12px 20px;background:var(--bg);color:var(--text);border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.btn-secondary:hover{border-color:var(--gold);color:var(--gold)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}
@media(max-width:600px){.info-grid{grid-template-columns:1fr}.btn-group{flex-direction:column}}
</style>
</head>
<body>

<header class="header">
  <div class="header-top fadeUp">
    <a href="dashboard_gestionnaire_credit.php" class="btn-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
    <div class="t-btns">
      <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
      <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
    </div>
  </div>
  <div class="header-label">📋 Traiter la demande #<?php echo $demande_id; ?></div>
</header>

<div class="content">

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <div><?php echo $success; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><?php echo htmlspecialchars($error); ?></div>
  </div>
  <?php endif; ?>

  <!-- DEMANDEUR -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">👤</span>
      <span class="card-header-title">Informations du demandeur</span>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Nom complet</div>
          <div class="info-value"><?php echo htmlspecialchars($demande['prenoms'].' '.$demande['nom']); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Numéro membre</div>
          <div class="info-value"><?php echo htmlspecialchars($demande['numero_membre']); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Société</div>
          <div class="info-value"><?php echo htmlspecialchars($demande['societe_nom']); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Ancienneté</div>
          <div class="info-value"><?php echo $anciennete_mois; ?> mois</div>
        </div>
        <div class="info-item">
          <div class="info-label">Salaire net</div>
          <div class="info-value gold"><?php echo number_format($demande['salaire_net'], 0, ',', ' '); ?> F</div>
        </div>
        <div class="info-item">
          <div class="info-label">Quotité cessible</div>
          <div class="info-value"><?php echo number_format($demande['quotite_cessible'], 0, ',', ' '); ?> F</div>
        </div>
        <?php if ($demande['type_code'] === 'express'): ?>
        <div class="info-item">
          <div class="info-label">Ancienneté Expresse</div>
          <!-- CORRECTION #12 : affichage en mois -->
          <div class="info-value"><?php echo $anciennete_exp_mois; ?> mois</div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- DÉTAILS DEMANDE -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">💰</span>
      <span class="card-header-title">Détails de la demande</span>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">Type de crédit</div>
          <div class="info-value"><?php echo htmlspecialchars($demande['type_libelle']); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Date de demande</div>
          <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($demande['date_demande'])); ?></div>
        </div>
        <div class="info-item">
          <div class="info-label">Montant demandé</div>
          <div class="info-value gold"><?php echo number_format($demande['montant_demande'], 0, ',', ' '); ?> F</div>
        </div>
        <div class="info-item">
          <div class="info-label">Durée</div>
          <div class="info-value"><?php echo $demande['duree_demandee_mois']; ?> mois
            <?php if (in_array($demande['duree_demandee_mois'], [144,168])): ?>
            (<?php echo $demande['duree_demandee_mois'] == 144 ? '12 ans' : '14 ans'; ?>)
            <?php endif; ?>
          </div>
        </div>
        <div class="info-item" style="grid-column:1/-1">
          <div class="info-label">Objet</div>
          <div class="info-value" style="font-size:13px"><?php echo nl2br(htmlspecialchars($demande['objet'])); ?></div>
        </div>
        <div class="info-item" style="grid-column:1/-1">
          <div class="info-label">Statut</div>
          <div class="info-value">
            <span class="badge <?php echo $demande['statut']==='en_attente'?'badge-attente':'badge-etude'; ?>">
              <?php echo $demande['statut']==='en_attente'?'⏳ En attente':'🔍 En étude'; ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- COMPTES ÉPARGNE -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">🏦</span>
      <span class="card-header-title">Comptes épargne du membre</span>
    </div>
    <div class="card-body">
      <?php if (!empty($comptes_list)): ?>
      <div class="info-grid">
        <?php foreach ($comptes_list as $c): ?>
        <div class="info-item">
          <div class="info-label"><?php echo htmlspecialchars($c['type_libelle']); ?></div>
          <div class="info-value"><?php echo number_format($c['solde'], 0, ',', ' '); ?> F</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="color:var(--text-light)">Aucun compte épargne actif.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- SIMULATION & DÉDUCTIONS -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">📊</span>
      <span class="card-header-title">Simulation & Déductions</span>
    </div>
    <div class="card-body">

      <div class="sim-box">
        <!-- CORRECTION #12 : taux basé sur mois -->
        <div class="sim-row">
          <span class="sim-label">Taux d'intérêt
            <?php if ($demande['type_code'] === 'express'): ?>
              (ancienneté <?php echo $anciennete_exp_mois; ?> mois)
            <?php endif; ?>
          </span>
          <span class="sim-val gold"><?php echo number_format($taux_calcule, 2); ?>% / an</span>
        </div>
        <div class="sim-row">
          <span class="sim-label">Mensualité</span>
          <span class="sim-val gold"><?php echo number_format($mensualite, 0, ',', ' '); ?> F</span>
        </div>
        <div class="sim-row">
          <span class="sim-label">Quotité nécessaire (mensualité + 15 000 F)</span>
          <span class="sim-val <?php echo $quotite_ok ? 'green' : 'red'; ?>">
            <?php echo number_format($quotite_necessaire, 0, ',', ' '); ?> F
          </span>
        </div>
        <div class="sim-row">
          <span class="sim-label">Quotité cessible disponible</span>
          <span class="sim-val"><?php echo number_format($demande['quotite_cessible'], 0, ',', ' '); ?> F</span>
        </div>

        <!-- Frais & Fonds -->
        <?php if ($frais_dossier > 0): ?>
        <div class="sim-row">
          <span class="sim-label">Frais de dossier (déduits du crédit)</span>
          <span class="sim-val red">− <?php echo number_format($frais_dossier, 0, ',', ' '); ?> F</span>
        </div>
        <?php endif; ?>
        <?php if ($fonds_garantie > 0): ?>
        <div class="sim-row">
          <span class="sim-label">Fonds de garantie (déduits du crédit)</span>
          <span class="sim-val red">− <?php echo number_format($fonds_garantie, 0, ',', ' '); ?> F</span>
        </div>
        <?php endif; ?>

        <!-- CORRECTION #13 : garantie moitié/moitié -->
        <?php if ($demande['type_code'] === 'ordinaire' && $garantie_totale > 0): ?>
        <div class="sim-row">
          <span class="sim-label">Garantie totale (<?php echo $demande['montant_demande'] >= 7000000 ? '50%' : '25%'; ?>)</span>
          <span class="sim-val"><?php echo number_format($garantie_totale, 0, ',', ' '); ?> F</span>
        </div>
        <div class="sim-row">
          <span class="sim-label">↳ Moitié immédiate (par le membre)</span>
          <span class="sim-val"><?php echo number_format($garantie_immediate, 0, ',', ' '); ?> F</span>
        </div>
        <div class="sim-row">
          <span class="sim-label">↳ Moitié déduite du crédit versé</span>
          <span class="sim-val red">− <?php echo number_format($garantie_sur_credit, 0, ',', ' '); ?> F</span>
        </div>
        <?php endif; ?>

        <!-- Apport immobilier CORRECTION #16 -->
        <?php if ($demande['type_code'] === 'immobilier'): ?>
        <div class="sim-row">
          <span class="sim-label">Apport requis (minimum 20%)</span>
          <span class="sim-val <?php echo $apport_ok ? 'green' : 'red'; ?>">
            <?php echo number_format($apport_requis, 0, ',', ' '); ?> F
          </span>
        </div>
        <div class="sim-row">
          <span class="sim-label">Épargne Logement disponible</span>
          <span class="sim-val"><?php echo number_format($solde_logement, 0, ',', ' '); ?> F</span>
        </div>
        <?php endif; ?>

      </div>

      <!-- Montant net versé -->
      <div class="net-verse-box">
        <div class="net-verse-label">💰 Montant net versé au membre</div>
        <div class="net-verse-val"><?php echo number_format($montant_net_verse, 0, ',', ' '); ?> F</div>
      </div>

      <!-- Alertes blocantes -->
      <?php if (!$quotite_ok): ?>
      <div class="alert alert-error" style="margin-top:16px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>⚠️ La quotité nécessaire (<?php echo number_format($quotite_necessaire, 0, ',', ' '); ?> F) dépasse la quotité cessible (<?php echo number_format($demande['quotite_cessible'], 0, ',', ' '); ?> F). Ce crédit <strong>ne peut pas être approuvé</strong>.</div>
      </div>
      <?php endif; ?>
      <?php if ($demande['type_code'] === 'immobilier' && !$apport_ok): ?>
      <div class="alert alert-error" style="margin-top:16px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>⚠️ L'apport minimum de 20% (<?php echo number_format($apport_requis, 0, ',', ' '); ?> F) n'est pas atteint. Épargne logement disponible : <?php echo number_format($solde_logement, 0, ',', ' '); ?> F.</div>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- DÉCISION -->
  <form method="POST" id="decisionForm">
    <input type="hidden" name="action" id="formAction" value="">

    <div class="card fadeUp">
      <div class="card-header">
        <span class="card-header-icon">⚖️</span>
        <span class="card-header-title">Décision du gestionnaire</span>
      </div>
      <div class="card-body">

        <div id="motifRefusZone" style="display:none">
          <div class="form-group">
            <label>Motif du refus *</label>
            <textarea name="motif" id="motifText"
              placeholder="Ex: Quotité insuffisante, dossier incomplet, capacité de remboursement insuffisante..."></textarea>
          </div>
        </div>

        <div class="btn-group">
          <button type="button" class="btn-secondary" onclick="setAction('en_etude')">
            🔍 Mettre en étude
          </button>
          <?php if ($quotite_ok && ($demande['type_code'] !== 'immobilier' || $apport_ok)): ?>
          <button type="button" class="btn-primary" onclick="setAction('approuver')">
            ✅ Approuver le crédit
          </button>
          <?php else: ?>
          <button type="button" class="btn-primary" disabled title="Conditions non remplies">
            ✅ Approuver (bloqué)
          </button>
          <?php endif; ?>
          <button type="button" class="btn-danger" onclick="showRefus()">
            ❌ Refuser
          </button>
        </div>

      </div>
    </div>
  </form>

</div>

<script>
function setAction(action) {
  const motifText = document.getElementById('motifText').value.trim();
  if (action === 'refuser' && !motifText) {
    alert('Veuillez indiquer un motif de refus.');
    return;
  }
  if (action === 'approuver') {
    if (!confirm('Confirmer l\'approbation du crédit ?\n\nMontant accordé : <?php echo number_format($demande['montant_demande'],0,',',' '); ?> F\nMontant net versé : <?php echo number_format($montant_net_verse,0,',',' '); ?> F')) return;
  }
  document.getElementById('formAction').value = action;
  document.getElementById('decisionForm').submit();
}
function showRefus() {
  document.getElementById('motifRefusZone').style.display = 'block';
  document.getElementById('formAction').value = 'refuser';
  document.getElementById('motifText').focus();
  // Changer le bouton Refuser en Confirmer refus
  document.querySelector('.btn-danger').textContent = '❌ Confirmer le refus';
  document.querySelector('.btn-danger').onclick = function() { setAction('refuser'); };
}
function setTheme(theme, btn) {
  document.querySelectorAll('.t-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.t-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>