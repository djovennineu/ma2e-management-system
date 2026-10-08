<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

$stmt = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom, s.code AS societe_code
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$stmt->execute([$membre_id]);
$membre = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$membre || $membre['statut'] !== 'actif') {
    header('Location: espace_membre.php');
    exit;
}

$success = '';
$error = '';

$comptes = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut = 'actif'
");
$comptes->execute([$membre_id]);
$comptes_list = $comptes->fetchAll(PDO::FETCH_ASSOC);
$comptes_par_type = [];
foreach ($comptes_list as $compte) {
    $comptes_par_type[$compte['type_code']] = $compte;
}

$credits_actifs_stmt = $pdo->prepare("
    SELECT c.*, tc.code AS type_code
    FROM credits c
    JOIN types_credit tc ON tc.id = c.type_credit_id
    WHERE c.membre_id = ? AND c.statut IN ('en_cours','en_retard')
");
$credits_actifs_stmt->execute([$membre_id]);
$credits_actifs = $credits_actifs_stmt->fetchAll(PDO::FETCH_ASSOC);

$a_credit_retard = !empty(array_filter($credits_actifs, fn($credit) => $credit['statut'] === 'en_retard'));
$a_credit_immo = !empty(array_filter($credits_actifs, fn($credit) => $credit['type_code'] === 'immobilier'));
$mensualites_en_cours = array_sum(array_column($credits_actifs, 'mensualite'));
$quotite_disponible = max(0, (float)$membre['quotite_cessible'] - $mensualites_en_cours);
$compte_logement = $comptes_par_type['logement'] ?? null;
$solde_logement = (float)($compte_logement['solde'] ?? 0);

$premier_paiement = null;
if ($compte_logement) {
    $premier_paiement_stmt = $pdo->prepare("
        SELECT MIN(date_operation)
        FROM mouvements
        WHERE compte_id = ?
          AND type IN ('depot', 'prelevement_salaire', 'depot_mobile')
    ");
    $premier_paiement_stmt->execute([$compte_logement['id']]);
    $premier_paiement = $premier_paiement_stmt->fetchColumn() ?: $compte_logement['date_ouverture'];
}

$demandes_stmt = $pdo->prepare("
    SELECT dl.*, tl.libelle AS type_logement_libelle, tl.prix_total, tl.apport_obligatoire,
           si.nom AS site_nom
    FROM demandes_logement dl
    JOIN types_logement tl ON tl.id = dl.type_logement_id
    JOIN sites_immobiliers si ON si.id = tl.site_id
    WHERE dl.membre_id = ?
    ORDER BY dl.created_at DESC, dl.id DESC
");
$demandes_stmt->execute([$membre_id]);
$demandes_logement = $demandes_stmt->fetchAll(PDO::FETCH_ASSOC);

$a_demande_active = !empty(array_filter(
    $demandes_logement,
    fn($demande) => in_array($demande['statut'], ['en_attente', 'approuve'], true) && empty($demande['credit_immobilier_id'])
));

$types_logement_stmt = $pdo->query("
    SELECT tl.*, si.nom AS site_nom, si.localite, si.statut AS site_statut
    FROM types_logement tl
    JOIN sites_immobiliers si ON si.id = tl.site_id
    WHERE si.statut = 'actif'
    ORDER BY si.nom, tl.libelle
");
$types_logement = $types_logement_stmt->fetchAll(PDO::FETCH_ASSOC);

function calcMensualiteImmo(float $montantFinance, float $tauxAnnuel, int $dureeMois): float
{
    if ($montantFinance <= 0 || $dureeMois <= 0) {
        return 0.0;
    }

    $tauxMensuel = $tauxAnnuel / 100 / 12;
    if ($tauxMensuel <= 0) {
        return $montantFinance / $dureeMois;
    }

    return $montantFinance * $tauxMensuel / (1 - pow(1 + $tauxMensuel, -$dureeMois));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'soumettre') {
    $type_logement_id = (int)($_POST['type_logement_id'] ?? 0);
    $commentaire = trim($_POST['commentaire'] ?? '');

    $type_stmt = $pdo->prepare("
        SELECT tl.*, si.nom AS site_nom, si.localite
        FROM types_logement tl
        JOIN sites_immobiliers si ON si.id = tl.site_id
        WHERE tl.id = ? AND si.statut = 'actif'
        LIMIT 1
    ");
    $type_stmt->execute([$type_logement_id]);
    $type_logement = $type_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$type_logement) {
        $error = "Type de logement invalide.";
    } elseif (!$compte_logement) {
        $error = "Vous devez d'abord ouvrir un compte Logement.";
    } elseif ($a_credit_retard) {
        $error = "Vous avez un crédit en retard. Régularisez-le avant de déposer une demande logement.";
    } elseif ($a_credit_immo) {
        $error = "Vous avez déjà un crédit immobilier en cours.";
    } elseif ($a_demande_active) {
        $error = "Vous avez déjà une demande logement en cours de traitement.";
    } elseif ((int)$type_logement['nombre_disponible'] <= 0) {
        $error = "Ce type de logement n'est plus disponible pour le moment.";
    } elseif ($solde_logement < (float)$type_logement['apport_obligatoire']) {
        $error = "Apport insuffisant. Minimum requis : "
            . number_format($type_logement['apport_obligatoire'], 0, ',', ' ')
            . " F CFA. Disponible sur votre compte logement : "
            . number_format($solde_logement, 0, ',', ' ') . " F CFA.";
    } else {
        $taux_credit = (float)($type_logement['taux_credit'] ?: 7.00);
        $duree_credit = (int)($type_logement['duree_credit_mois'] ?: 168);
        $montant_finance = max(0, (float)$type_logement['prix_total'] - $solde_logement);
        $mensualite_estimee = calcMensualiteImmo($montant_finance, $taux_credit, $duree_credit);
        $quotite_necessaire = $mensualite_estimee + 15000;

        if ($quotite_necessaire > (float)$membre['quotite_cessible']) {
            $error = "Votre quotité cessible est insuffisante pour ce logement. "
                . "Mensualité estimée + 15 000 F : "
                . number_format($quotite_necessaire, 0, ',', ' ')
                . " F CFA. Quotité disponible : "
                . number_format($membre['quotite_cessible'], 0, ',', ' ')
                . " F CFA.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO demandes_logement
                        (membre_id, type_logement_id, compte_logement_id, montant_apport_verse,
                         date_premier_paiement, statut, date_demande, commentaire, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'en_attente', NOW(), ?, NOW(), NOW())
                ")->execute([
                    $membre_id,
                    $type_logement['id'],
                    $compte_logement['id'],
                    $solde_logement,
                    $premier_paiement,
                    $commentaire !== '' ? $commentaire : null,
                ]);

                $success = "Votre demande logement a bien ete enregistree pour le type "
                    . htmlspecialchars($type_logement['libelle'])
                    . " sur le site de " . htmlspecialchars($type_logement['site_nom']) . ".";

                $demandes_stmt->execute([$membre_id]);
                $demandes_logement = $demandes_stmt->fetchAll(PDO::FETCH_ASSOC);
                $a_demande_active = true;
            } catch (Exception $e) {
                $error = "Impossible d'enregistrer la demande logement : " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande logement — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#777;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.header{background:linear-gradient(135deg,var(--dark),#2c2c2c);padding:20px 24px 64px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0}
.top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.back{display:inline-flex;align-items:center;gap:8px;color:#f2f2f2;text-decoration:none;background:rgba(255,255,255,.08);padding:9px 14px;border-radius:999px;font-size:14px;font-weight:600}
.title-wrap{position:relative;z-index:1;margin-top:12px}
.eyebrow{font-size:12px;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.68);font-weight:700}
.title{font-family:'Playfair Display',serif;color:white;font-size:30px;margin-top:6px}
.subtitle{color:rgba(255,255,255,.7);font-size:14px;margin-top:6px;max-width:680px}
.content{max-width:1180px;margin:0 auto;padding:24px}
.alert{padding:15px 18px;border-radius:12px;margin-bottom:18px;font-size:14px;line-height:1.6}
.alert-success{background:#E8F5E9;border-left:4px solid var(--green);color:#1B5E20}
.alert-error{background:#FFEBEE;border-left:4px solid var(--red);color:#B71C1C}
.alert-info{background:#E3F2FD;border-left:4px solid var(--blue);color:#0D47A1}
.grid{display:grid;grid-template-columns:340px 1fr;gap:22px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.card-header{padding:18px 20px;border-bottom:1px solid var(--border)}
.card-title{font-size:15px;font-weight:700}
.card-sub{margin-top:4px;color:var(--text-light);font-size:12px}
.card-body{padding:20px}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stat{background:#faf8f3;border:1px solid var(--border);border-radius:14px;padding:14px}
.stat-label{font-size:11px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.stat-value{font-size:18px;font-weight:800}
.types-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.type-card{border:1px solid var(--border);border-radius:16px;padding:18px;background:#fff}
.type-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.type-name{font-size:16px;font-weight:800}
.type-site{font-size:12px;color:var(--text-light);margin-top:4px}
.price{color:var(--gold-dark);font-size:18px;font-weight:800}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}
.meta-item{background:#faf8f3;border-radius:12px;padding:10px 12px}
.meta-label{font-size:10px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.meta-value{font-size:13px;font-weight:700;margin-top:4px}
.hint{font-size:12px;line-height:1.5;color:var(--text-light);margin-top:10px}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-ok{background:#E8F5E9;color:#2E7D32}
.badge-ko{background:#FFEBEE;color:#C62828}
.form-group{margin-top:14px}
.form-group label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-light);margin-bottom:6px}
.form-group textarea{width:100%;min-height:80px;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;background:#faf8f3;font:inherit;resize:vertical}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:12px;padding:12px 16px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none}
.btn-primary{background:var(--gold);color:white}
.btn-primary:hover{background:var(--gold-dark)}
.btn-secondary{background:#f4efe2;color:#7a5d17}
.history-list{display:flex;flex-direction:column;gap:12px}
.history-item{border:1px solid var(--border);border-radius:14px;padding:14px 16px;background:#fff}
.history-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.history-title{font-size:14px;font-weight:700}
.history-sub{font-size:12px;color:var(--text-light);margin-top:4px}
.history-note{margin-top:10px;font-size:12px;color:#555;line-height:1.5}
.empty{padding:28px;text-align:center;color:var(--text-light)}
@media(max-width:960px){.grid{grid-template-columns:1fr}.types-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="header">
  <div class="top">
    <a href="espace_membre.php" class="back">← Retour</a>
  </div>
  <div class="title-wrap">
    <div class="eyebrow">Projet immobilier</div>
    <h1 class="title">Demande logement</h1>
    <p class="subtitle">Choisissez un type de logement MA2E, verifiez votre apport logement et lancez votre demande. Le credit immobilier sera declenche seulement apres validation de votre attribution.</p>
  </div>
</header>

<main class="content">
  <?php if ($success): ?>
  <div class="alert alert-success"><?php echo $success; ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <?php if (!$compte_logement): ?>
  <div class="alert alert-info">
    Vous n'avez pas encore de compte logement actif. Ouvrez d'abord votre compte logement pour pouvoir deposer une demande.
    <div style="margin-top:10px">
      <a href="ouvrir_compte.php" class="btn btn-secondary">Ouvrir un compte logement</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid">
    <section class="card">
      <div class="card-header">
        <div class="card-title">Mon profil immobilier</div>
        <div class="card-sub">Ce bloc sert a verifier rapidement votre eligibilite.</div>
      </div>
      <div class="card-body">
        <div class="stats">
          <div class="stat">
            <div class="stat-label">Epargne logement</div>
            <div class="stat-value"><?php echo number_format($solde_logement, 0, ',', ' '); ?> F</div>
          </div>
          <div class="stat">
            <div class="stat-label">Quotite dispo.</div>
            <div class="stat-value"><?php echo number_format($quotite_disponible, 0, ',', ' '); ?> F</div>
          </div>
          <div class="stat">
            <div class="stat-label">Mensualites en cours</div>
            <div class="stat-value"><?php echo number_format($mensualites_en_cours, 0, ',', ' '); ?> F</div>
          </div>
          <div class="stat">
            <div class="stat-label">Statut credit</div>
            <div class="stat-value"><?php echo $a_credit_retard ? 'En retard' : 'A jour'; ?></div>
          </div>
        </div>

        <div class="hint">
          Conditions principales :
          <br>• compte logement actif
          <br>• apport au moins egal a l'apport obligatoire du type choisi
          <br>• pas de credit immobilier en cours
          <br>• pas de credit en retard
          <br>• quotite cessible compatible avec le financement estime
        </div>

        <div style="margin-top:16px">
          <?php if ($compte_logement): ?>
          <span class="badge <?php echo $solde_logement > 0 ? 'badge-ok' : 'badge-ko'; ?>">
            <?php echo $solde_logement > 0 ? 'Compte logement actif' : 'Compte logement sans apport'; ?>
          </span>
          <?php endif; ?>
          <?php if ($a_credit_immo): ?>
          <span class="badge badge-ko" style="margin-left:8px">Credit immobilier deja en cours</span>
          <?php endif; ?>
          <?php if ($a_demande_active): ?>
          <span class="badge badge-ko" style="margin-left:8px">Demande logement deja ouverte</span>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <div class="card-title">Offres disponibles</div>
        <div class="card-sub">Le membre demande un type de logement. Le credit immobilier ne vient qu'apres approbation.</div>
      </div>
      <div class="card-body">
        <?php if (empty($types_logement)): ?>
        <div class="empty">Aucun type de logement disponible pour le moment.</div>
        <?php else: ?>
        <div class="types-grid">
          <?php foreach ($types_logement as $type): ?>
          <?php
            $taux = (float)($type['taux_credit'] ?: 7.00);
            $duree = (int)($type['duree_credit_mois'] ?: 168);
            $montant_finance = max(0, (float)$type['prix_total'] - $solde_logement);
            $mensualite_estimee = calcMensualiteImmo($montant_finance, $taux, $duree);
            $peut_soumettre = $compte_logement
                && !$a_credit_immo
                && !$a_credit_retard
                && !$a_demande_active
                && (int)$type['nombre_disponible'] > 0
                && $solde_logement >= (float)$type['apport_obligatoire'];
          ?>
          <div class="type-card">
            <div class="type-top">
              <div>
                <div class="type-name"><?php echo htmlspecialchars($type['libelle']); ?></div>
                <div class="type-site"><?php echo htmlspecialchars($type['site_nom']); ?><?php if (!empty($type['localite'])): ?> · <?php echo htmlspecialchars($type['localite']); ?><?php endif; ?></div>
              </div>
              <div class="price"><?php echo number_format($type['prix_total'], 0, ',', ' '); ?> F</div>
            </div>

            <div class="meta">
              <div class="meta-item">
                <div class="meta-label">Apport obligatoire</div>
                <div class="meta-value"><?php echo number_format($type['apport_obligatoire'], 0, ',', ' '); ?> F</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Disponibles</div>
                <div class="meta-value"><?php echo (int)$type['nombre_disponible']; ?> / <?php echo (int)$type['nombre_total']; ?></div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Duree credit</div>
                <div class="meta-value"><?php echo $duree; ?> mois</div>
              </div>
              <div class="meta-item">
                <div class="meta-label">Taux credit</div>
                <div class="meta-value"><?php echo number_format($taux, 2, ',', ' '); ?> %</div>
              </div>
            </div>

            <div class="hint">
              Mensualite estimee apres apport actuel :
              <strong><?php echo number_format($mensualite_estimee, 0, ',', ' '); ?> F</strong>
              <br>Apport actuellement disponible :
              <strong><?php echo number_format($solde_logement, 0, ',', ' '); ?> F</strong>
            </div>

            <form method="POST" style="margin-top:14px">
              <input type="hidden" name="action" value="soumettre">
              <input type="hidden" name="type_logement_id" value="<?php echo (int)$type['id']; ?>">
              <div class="form-group">
                <label>Observation</label>
                <textarea name="commentaire" placeholder="Precisez si besoin votre projet, vos contraintes ou votre preference."></textarea>
              </div>
              <button type="submit" class="btn btn-primary" <?php echo $peut_soumettre ? '' : 'disabled style="opacity:.55;cursor:not-allowed"'; ?>>
                Soumettre cette demande
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section class="card" style="margin-top:22px">
    <div class="card-header">
      <div class="card-title">Mes demandes logement</div>
      <div class="card-sub">Suivi des statuts et des decisions prises par le gestionnaire immobilier.</div>
    </div>
    <div class="card-body">
      <?php if (empty($demandes_logement)): ?>
      <div class="empty">Aucune demande logement enregistree pour l'instant.</div>
      <?php else: ?>
      <div class="history-list">
        <?php foreach ($demandes_logement as $demande): ?>
        <div class="history-item">
          <div class="history-head">
            <div>
              <div class="history-title"><?php echo htmlspecialchars($demande['type_logement_libelle']); ?></div>
              <div class="history-sub">
                <?php echo htmlspecialchars($demande['site_nom']); ?> ·
                demande du <?php echo date('d/m/Y', strtotime($demande['date_demande'])); ?>
              </div>
            </div>
            <span class="badge <?php echo in_array($demande['statut'], ['approuve', 'livre'], true) ? 'badge-ok' : 'badge-ko'; ?>">
              <?php echo htmlspecialchars(strtoupper($demande['statut'])); ?>
            </span>
          </div>
          <div class="history-note">
            Apport verse pris en compte :
            <strong><?php echo number_format($demande['montant_apport_verse'], 0, ',', ' '); ?> F</strong>
            <?php if (!empty($demande['motif_refus'])): ?>
            <br>Motif de refus : <?php echo htmlspecialchars($demande['motif_refus']); ?>
            <?php endif; ?>
            <?php if (!empty($demande['commentaire'])): ?>
            <br>Commentaire : <?php echo htmlspecialchars($demande['commentaire']); ?>
            <?php endif; ?>
            <?php if (!empty($demande['credit_immobilier_id'])): ?>
            <br>Credit immobilier lie : #<?php echo (int)$demande['credit_immobilier_id']; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
