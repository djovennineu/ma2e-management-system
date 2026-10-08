<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

// Récupérer le membre
$membre = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom, s.code AS societe_code
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$membre->execute([$membre_id]);
$membre = $membre->fetch(PDO::FETCH_ASSOC);
if (!$membre || $membre['statut'] !== 'actif') {
    header('Location: espace_membre.php'); exit;
}

$success = '';
$error   = '';

// ── COMPTES DU MEMBRE ──
$comptes = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut = 'actif'
");
$comptes->execute([$membre_id]);
$comptes_list = $comptes->fetchAll(PDO::FETCH_ASSOC);
$comptes_par_type = [];
foreach ($comptes_list as $c) $comptes_par_type[$c['type_code']] = $c;

// ── CRÉDITS EN COURS ──
$credits_en_cours = $pdo->prepare("
    SELECT c.*, tc.code AS type_code
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    WHERE c.membre_id = ? AND c.statut IN ('en_cours','en_retard')
");
$credits_en_cours->execute([$membre_id]);
$credits_actifs = $credits_en_cours->fetchAll(PDO::FETCH_ASSOC);
$a_credit_retard  = !empty(array_filter($credits_actifs, fn($c) => $c['statut'] === 'en_retard'));
$a_credit_express  = !empty(array_filter($credits_actifs, fn($c) => $c['type_code'] === 'express'));
$a_credit_ordinaire= !empty(array_filter($credits_actifs, fn($c) => $c['type_code'] === 'ordinaire'));
$a_credit_immo     = !empty(array_filter($credits_actifs, fn($c) => $c['type_code'] === 'immobilier'));

// ── DEMANDES EN ATTENTE ──
$demandes_attente = $pdo->prepare("
    SELECT dc.*, tc.code AS type_code, tc.libelle AS type_libelle
    FROM demandes_credit dc
    JOIN types_credit tc ON dc.type_credit_id = tc.id
    WHERE dc.membre_id = ? AND dc.statut IN ('en_attente','en_etude')
");
$demandes_attente->execute([$membre_id]);
$demandes_pending = $demandes_attente->fetchAll(PDO::FETCH_ASSOC);
$types_pending = array_column($demandes_pending, 'type_code');

// ── ANCIENNETÉ MEMBRE (en mois) ──
$date_adhesion   = new DateTime($membre['date_adhesion']);
$now             = new DateTime();
$diff_adhesion   = $now->diff($date_adhesion);
$anciennete_mois = $diff_adhesion->y * 12 + $diff_adhesion->m;

// ── ANCIENNETÉ ÉPARGNE EXPRESSE (en MOIS) ── CORRECTION #6
$anciennete_exp_mois = 0;
if (isset($comptes_par_type['expresse'])) {
    $date_ouv = new DateTime($comptes_par_type['expresse']['date_ouverture']);
    $diff_exp = $now->diff($date_ouv);
    $anciennete_exp_mois = $diff_exp->y * 12 + $diff_exp->m;
}

// ── CALCUL QUOTITÉ CESSIBLE DISPONIBLE ──
$quotite_cessible     = $membre['quotite_cessible'];
$mensualites_en_cours = array_sum(array_column($credits_actifs, 'mensualite'));
$quotite_disponible   = max(0, $quotite_cessible - $mensualites_en_cours);

// ── TAUX CRÉDIT EXPRESS selon ancienneté en MOIS ── CORRECTION #6
$taux_express    = null; // null = pas encore éligible
$express_eligible_taux = true;

if ($anciennete_exp_mois >= 120)     $taux_express = 7.00;
elseif ($anciennete_exp_mois >= 96)  $taux_express = 7.50;
elseif ($anciennete_exp_mois >= 72)  $taux_express = 8.00;
elseif ($anciennete_exp_mois >= 48)  $taux_express = 8.50;
elseif ($anciennete_exp_mois >= 24)  $taux_express = 9.00;
else {
    $taux_express = 9.00; // taux par défaut
    $express_eligible_taux = false;
}

// ── ÉLIGIBILITÉ PAR TYPE DE CRÉDIT ──

// ─────────────────────────────────────
// CRÉDIT EXPRESS
// ─────────────────────────────────────
$raisons_ko_express = [];
if (!isset($comptes_par_type['expresse']))
    $raisons_ko_express[] = "Pas de compte Expresse actif";
if (!$express_eligible_taux)
    $raisons_ko_express[] = "Épargne expresse insuffisante (minimum 24 mois requis, vous avez $anciennete_exp_mois mois)";
if ($a_credit_retard)
    $raisons_ko_express[] = "Crédit en retard de paiement";
if ($a_credit_express)
    $raisons_ko_express[] = "Vous avez déjà un Crédit Express en cours";
if (in_array('express', $types_pending))
    $raisons_ko_express[] = "Demande en cours d'étude";
// CORRECTION #7 : vérification quotité pour express
if ($quotite_disponible <= 0)
    $raisons_ko_express[] = "Quotité cessible insuffisante (disponible : " . number_format($quotite_disponible, 0, ',', ' ') . " F)";

$eligibilite['express'] = [
    'ok'                  => empty($raisons_ko_express),
    'raisons'             => $raisons_ko_express,
    'taux'                => $taux_express,
    'montant_min'         => 50000,
    'montant_max'         => 2000000,
    'duree_max'           => 24,
    'solde_exp'           => $comptes_par_type['expresse']['solde'] ?? 0,
    'frais_dossier'       => 2000,       // forfait fixe
    'fonds_garantie_pct'  => 0.005,      // 0,5%
];

// ─────────────────────────────────────
// CRÉDIT ORDINAIRE
// ─────────────────────────────────────
$raisons_ko_ord = [];
if (!isset($comptes_par_type['expresse']))
    $raisons_ko_ord[] = "Pas de compte Expresse actif";
if (!isset($comptes_par_type['ordinaire']))
    $raisons_ko_ord[] = "Pas de compte Ordinaire actif";
if ($a_credit_retard)
    $raisons_ko_ord[] = "Crédit en retard de paiement";
if ($a_credit_ordinaire)
    $raisons_ko_ord[] = "Vous avez déjà un Crédit Ordinaire en cours";
if (in_array('ordinaire', $types_pending))
    $raisons_ko_ord[] = "Demande en cours d'étude";
if ($anciennete_mois < 6)
    $raisons_ko_ord[] = "Ancienneté insuffisante (min. 6 mois, vous avez $anciennete_mois mois)";
if ($quotite_disponible <= 0)
    $raisons_ko_ord[] = "Quotité cessible insuffisante";

$solde_ord = $comptes_par_type['ordinaire']['solde'] ?? 0;
$eligibilite['ordinaire'] = [
    'ok'                  => empty($raisons_ko_ord),
    'raisons'             => $raisons_ko_ord,
    'montant_min'         => 100000,
    'montant_max'         => 10000000,
    'duree_max'           => 60,
    'solde_ord'           => $solde_ord,
    'frais_dossier_pct'   => 0.005,    // 0,5%
    'frais_dossier_min'   => 5000,
    'frais_dossier_max'   => 50000,
    'fonds_garantie_pct'  => 0.01,     // 1%
    'mensualite_min'      => 5000,
];

$solde_logement = $comptes_par_type['logement']['solde'] ?? 0;
$eligibilite['immobilier'] = [
    'ok'             => false,
    'raisons'        => [
        "Le credit immobilier ne se demande plus ici.",
        "Passez par le parcours 'Demande logement' pour choisir un type de logement MA2E.",
    ],
    'montant_max'    => 20000000,
    'durees_dispo'   => [144, 168],
    'duree_max'      => 168,
    'taux'           => 7.00,
    'solde_logement' => $solde_logement,
    'frais_dossier'  => 100000,
    'fonds_garantie' => 50000,
    'apport_min_pct' => 20,
    'apport_max_pct' => 35,
];

// ── ACTION : SOUMETTRE DEMANDE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'soumettre') {
    $type_code = $_POST['type_credit']  ?? '';
    $montant   = (float)($_POST['montant'] ?? 0);
    $duree     = (int)($_POST['duree_mois'] ?? 0);
    $objet     = trim($_POST['objet'] ?? '');

    // Récupérer le type crédit en base
    $type_credit = $pdo->prepare("SELECT * FROM types_credit WHERE code = ?");
    $type_credit->execute([$type_code]);
    $type_credit = $type_credit->fetch(PDO::FETCH_ASSOC);

    if ($type_code === 'immobilier') {
        header('Location: demande_logement.php');
        exit;
    } elseif (!$type_credit) {
        $error = "Type de crédit invalide.";
    } elseif (!$eligibilite[$type_code]['ok']) {
        $error = "Vous n'êtes pas éligible à ce type de crédit.";
    } elseif ($montant <= 0) {
        $error = "Le montant doit être supérieur à 0.";
    } elseif ($type_credit['montant_min'] && $montant < $type_credit['montant_min']) {
        $error = "Montant minimum : " . number_format($type_credit['montant_min'], 0, ',', ' ') . " F CFA.";
    } elseif ($type_credit['montant_max'] && $montant > $type_credit['montant_max']) {
        $error = "Montant maximum : " . number_format($type_credit['montant_max'], 0, ',', ' ') . " F CFA.";
    } elseif ($duree <= 0 || $duree > $type_credit['duree_max_mois']) {
        $error = "Durée invalide (max " . $type_credit['duree_max_mois'] . " mois).";
    } elseif (empty($objet)) {
        $error = "Veuillez indiquer l'objet de votre demande.";
    } else {

        // ── Calcul taux proposé ──
        $taux_propose = $type_credit['taux_an_defaut'];

        // CORRECTION #6 : taux express en mois
        if ($type_code === 'express') {
            $taux_propose = $taux_express;
        }

        // Taux ordinaire selon ratio épargne/crédit
        if ($type_code === 'ordinaire') {
            $ratio = ($montant > 0 && $solde_ord > 0) ? min($solde_ord / $montant, 9.99) : 0;
            if ($ratio >= 0.75)      $taux_propose = 6.00;
            elseif ($ratio >= 0.50)  $taux_propose = 6.50;
            else                     $taux_propose = 7.00;
        }

        // Taux immobilier fixe
        if ($type_code === 'immobilier') {
            $taux_propose = 7.00;
        }

        // ── Calcul mensualité ──
        $taux_mensuel = $taux_propose / 100 / 12;
        $mensualite   = ($taux_mensuel > 0)
            ? $montant * $taux_mensuel / (1 - pow(1 + $taux_mensuel, -$duree))
            : $montant / $duree;

        // CORRECTION #8 : mensualité min 5 000 F pour EXPRESS aussi
        if (in_array($type_code, ['express', 'ordinaire']) && $mensualite < 5000) {
            $error = "La mensualité minimum est de 5 000 F CFA. Votre mensualité serait de "
                   . number_format($mensualite, 0, ',', ' ') . " F. Réduisez la durée ou augmentez le montant.";
        }

        // CORRECTION #11 : vérifier quotité pour TOUS les types
        if (empty($error)) {
            $quotite_necessaire = $mensualite + 15000;
            if ($quotite_necessaire > $quotite_cessible) {
                $error = "Mensualité + 15 000 F (" . number_format($quotite_necessaire, 0, ',', ' ')
                       . " F) dépasse votre quotité cessible (" . number_format($quotite_cessible, 0, ',', ' ') . " F).";
            }
        }

        // CORRECTION #9 : vérifier apport immobilier entre 20% et 35%
        if (empty($error) && $type_code === 'immobilier') {
            $apport_min_requis = $montant * ($apport_min_pct / 100);
            if ($solde_logement < $apport_min_requis) {
                $error = "Apport insuffisant. Vous devez avoir au minimum "
                       . number_format($apport_min_requis, 0, ',', ' ')
                       . " F sur votre compte Logement (" . $apport_min_pct . "% du prix). "
                       . "Actuellement : " . number_format($solde_logement, 0, ',', ' ') . " F.";
            }
        }

        // CORRECTION #10 : durée immobilier limitée à 144 ou 168
        if (empty($error) && $type_code === 'immobilier') {
            if (!in_array($duree, [144, 168])) {
                $error = "La durée du crédit immobilier doit être 144 mois (12 ans) ou 168 mois (14 ans).";
            }
        }

        if (empty($error)) {
            try {
                $pdo->prepare("
                    INSERT INTO demandes_credit
                        (membre_id, type_credit_id, montant_demande, duree_demandee_mois,
                         objet, taux_propose, statut, date_demande)
                    VALUES (?, ?, ?, ?, ?, ?, 'en_attente', NOW())
                ")->execute([
                    $membre_id, $type_credit['id'], $montant,
                    $duree, $objet, $taux_propose
                ]);

                $success = "Votre demande de <strong>" . htmlspecialchars($type_credit['libelle']) . "</strong>
                    de <strong>" . number_format($montant, 0, ',', ' ') . " F CFA</strong>
                    sur <strong>$duree mois</strong> a été soumise avec succès.<br>
                    Le gestionnaire de crédit l'étudiera dans les meilleurs délais.";

                // Recharger demandes
                $demandes_attente->execute([$membre_id]);
                $demandes_pending = $demandes_attente->fetchAll(PDO::FETCH_ASSOC);
                $types_pending    = array_column($demandes_pending, 'type_code');

            } catch (Exception $e) {
                $error = "Erreur : " . $e->getMessage();
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
<title>Demande de crédit — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--dark2:#242424;--dark3:#2e2e2e;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0;transition:background .3s}
.header::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);border-radius:50%}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px;transition:all .2s}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px;position:relative;z-index:1}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;transition:all .2s}
.t-btn.active{background:rgba(255,255,255,.4)}
.content{max-width:640px;margin:0 auto;padding:20px}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;animation:fadeUp .3s ease}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-info{background:#E3F2FD;color:#1565C0;border-left:4px solid #1976D2}
.profil-card{background:var(--dark);border-radius:14px;padding:20px;margin-bottom:20px;display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.profil-item{background:rgba(255,255,255,.06);border-radius:10px;padding:12px 14px}
.profil-lbl{font-size:11px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.profil-val{font-size:15px;font-weight:700;color:white}
.profil-val.gold{color:var(--gold)}
.profil-val.green{color:#81C784}
.profil-val.red{color:#EF9A9A}
.pending-card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.pending-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text)}
.pending-item{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--border)}
.pending-item:last-child{border-bottom:none}
.badge{padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-etude{background:#E3F2FD;color:#1565C0}
.section-label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.credits-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px}
.credit-card{border:2px solid var(--border);border-radius:14px;padding:18px;cursor:pointer;transition:all .2s;background:var(--card);position:relative}
.credit-card:hover:not(.disabled){border-color:var(--gold);transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.08)}
.credit-card.selected{border-color:var(--gold);background:var(--gold-bg)}
.credit-card.disabled{opacity:.5;cursor:not-allowed}
.credit-emoji{font-size:28px;margin-bottom:10px}
.credit-nom{font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px}
.credit-desc{font-size:12px;color:var(--text-light);line-height:1.5;margin-bottom:8px}
.credit-taux{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;background:#E8F5E9;color:#2E7D32}
.credit-raison{font-size:11px;color:var(--red);margin-top:6px;line-height:1.4}
.form-card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px;display:none}
.form-card.visible{display:block;animation:fadeUp .3s ease}
.form-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.form-header-emoji{font-size:22px}
.form-header-title{font-size:14px;font-weight:700;color:var(--text)}
.form-body{padding:20px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold)}
.form-group textarea{resize:vertical;min-height:80px}
.form-hint{font-size:11.5px;color:var(--text-light);margin-top:2px}
.form-hint.warn{color:#F57F17;font-weight:600}
.garantie-box{background:var(--bg);border-left:3px solid var(--gold);border-radius:0 10px 10px 0;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--text-light);line-height:1.6}

/* FRAIS BOX */
.frais-box{background:#E3F2FD;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#1565C0;line-height:1.8;border-left:3px solid #1976D2}
.frais-box strong{color:#0D47A1}

/* DUREE BUTTONS pour immo */
.duree-btns{display:flex;gap:10px;margin-top:6px}
.duree-btn{flex:1;padding:12px;border:2px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;background:var(--bg);font-size:13px;font-weight:600;transition:all .2s}
.duree-btn.active{border-color:var(--gold);background:var(--gold-bg);color:var(--gold)}

.simulateur{background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:12px;padding:18px;margin-bottom:16px}
.sim-title{font-size:12px;font-weight:700;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.sim-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.sim-item{background:rgba(255,255,255,.06);border-radius:8px;padding:10px 12px;text-align:center}
.sim-val{font-size:16px;font-weight:800;color:white}
.sim-lbl{font-size:10.5px;color:rgba(255,255,255,.5);margin-top:2px}
.sim-mensualite{font-size:22px;font-weight:800;color:var(--gold)}
.sim-quotite{font-size:12px;margin-top:8px;padding:8px 12px;border-radius:8px}
.sim-ok{background:rgba(76,175,80,.15);color:#81C784}
.sim-ko{background:rgba(244,67,54,.15);color:#EF9A9A}
.btn-submit{width:100%;padding:14px;background:var(--gold);color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{background:var(--gold-dark)}
.btn-submit:disabled{opacity:.4;cursor:not-allowed}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}
@media(max-width:500px){.credits-grid{grid-template-columns:1fr}.profil-card{grid-template-columns:1fr}.sim-grid{grid-template-columns:1fr}.duree-btns{flex-direction:column}}
@media(min-width:768px){.header{padding:24px 40px 70px}.content{max-width:640px;margin:0 auto}}
</style>
</head>
<body>

<header class="header">
  <div class="header-top fadeUp">
    <a href="espace_membre.php" class="btn-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
    <div class="t-btns">
      <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
      <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
    </div>
  </div>
  <div class="header-label">💰 Demande de crédit</div>
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

  <!-- PROFIL FINANCIER -->
  <div class="profil-card fadeUp">
    <div class="profil-item">
      <div class="profil-lbl">Salaire net</div>
      <div class="profil-val gold"><?php echo number_format($membre['salaire_net'], 0, ',', ' '); ?> F</div>
    </div>
    <div class="profil-item">
      <div class="profil-lbl">Quotité cessible</div>
      <div class="profil-val gold"><?php echo number_format($quotite_cessible, 0, ',', ' '); ?> F</div>
    </div>
    <div class="profil-item">
      <div class="profil-lbl">Mensualités en cours</div>
      <div class="profil-val <?php echo $mensualites_en_cours > 0 ? 'red' : 'green'; ?>">
        <?php echo number_format($mensualites_en_cours, 0, ',', ' '); ?> F
      </div>
    </div>
    <div class="profil-item">
      <div class="profil-lbl">Quotité disponible</div>
      <div class="profil-val <?php echo $quotite_disponible > 0 ? 'green' : 'red'; ?>">
        <?php echo number_format($quotite_disponible, 0, ',', ' '); ?> F
      </div>
    </div>
    <div class="profil-item">
      <div class="profil-lbl">Épargne Expresse</div>
      <div class="profil-val"><?php echo number_format($comptes_par_type['expresse']['solde'] ?? 0, 0, ',', ' '); ?> F</div>
    </div>
    <div class="profil-item">
      <div class="profil-lbl">Ancienneté expresse</div>
      <!-- CORRECTION #6 : affichage en mois -->
      <div class="profil-val"><?php echo $anciennete_exp_mois; ?> mois</div>
    </div>
    <?php if (isset($comptes_par_type['ordinaire'])): ?>
    <div class="profil-item">
      <div class="profil-lbl">Épargne Ordinaire</div>
      <div class="profil-val"><?php echo number_format($comptes_par_type['ordinaire']['solde'], 0, ',', ' '); ?> F</div>
    </div>
    <?php endif; ?>
    <?php if (isset($comptes_par_type['logement'])): ?>
    <div class="profil-item">
      <div class="profil-lbl">Épargne Logement</div>
      <div class="profil-val"><?php echo number_format($comptes_par_type['logement']['solde'], 0, ',', ' '); ?> F</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- DEMANDES EN COURS -->
  <?php if (!empty($demandes_pending)): ?>
  <div class="pending-card fadeUp">
    <div class="pending-header">📋 Mes demandes en cours</div>
    <?php foreach ($demandes_pending as $d): ?>
    <div class="pending-item">
      <div>
        <div style="font-weight:600"><?php echo htmlspecialchars($d['type_libelle']); ?></div>
        <div style="font-size:11.5px;color:var(--text-light)">
          <?php echo number_format($d['montant_demande'], 0, ',', ' '); ?> F ·
          <?php echo $d['duree_demandee_mois']; ?> mois ·
          <?php echo date('d/m/Y', strtotime($d['date_demande'])); ?>
        </div>
      </div>
      <span class="badge <?php echo $d['statut'] === 'en_attente' ? 'badge-attente' : 'badge-etude'; ?>">
        <?php echo $d['statut'] === 'en_attente' ? '⏳ En attente' : '🔍 En étude'; ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($a_credit_retard): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>Vous avez un crédit en retard de paiement. Régularisez votre situation avant de faire une nouvelle demande.</div>
  </div>
  <?php endif; ?>

  <!-- TYPES DE CRÉDIT -->
  <form method="POST" id="formCredit">
    <input type="hidden" name="action" value="soumettre">
    <input type="hidden" name="type_credit" id="typeCreditHidden" value="">
    <!-- CORRECTION #10 : champ durée séparé pour immo -->
    <input type="hidden" name="duree_mois" id="dureeMoisHidden" value="">

    <div class="section-label fadeUp">Choisir le type de crédit</div>
    <div class="credits-grid fadeUp">

      <!-- EXPRESS -->
      <?php $e = $eligibilite['express']; ?>
      <div class="credit-card <?php echo !$e['ok'] ? 'disabled' : ''; ?>"
           data-code="express"
           data-emoji="⚡"
           data-nom="Crédit Express"
           data-taux="<?php echo $e['taux']; ?>"
           data-min="50000"
           data-max="2000000"
           data-duree="24"
           data-duree-type="libre"
           data-frais-dossier="<?php echo $e['frais_dossier']; ?>"
           data-fonds-garantie-pct="<?php echo $e['fonds_garantie_pct']; ?>"
           data-mensualite-min="5000"
           data-garantie="⚡ Taux : <?php echo $e['taux']; ?>% / an (ancienneté : <?php echo $anciennete_exp_mois; ?> mois)<br>💰 Frais dossier : 2 000 F (déduits du crédit)<br>🛡️ Fonds garantie : 0,5% (déduits du crédit)<br>📌 Mensualité min : 5 000 F<br>✅ Quotité : mensualité + 15 000 F"
           onclick="<?php echo $e['ok'] ? 'selectCredit(this)' : ''; ?>">
        <div class="credit-emoji">⚡</div>
        <div class="credit-nom">Crédit Express</div>
        <div class="credit-desc">Besoins ponctuels. 50 000 à 2 000 000 F. Max 24 mois.</div>
        <span class="credit-taux"><?php echo $e['taux']; ?>% / an</span>
        <?php if (!$e['ok']): ?>
          <div class="credit-raison">
            <?php foreach ($e['raisons'] as $r): ?>⚠️ <?php echo $r; ?><br><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ORDINAIRE -->
      <?php $o = $eligibilite['ordinaire']; ?>
      <div class="credit-card <?php echo !$o['ok'] ? 'disabled' : ''; ?>"
           data-code="ordinaire"
           data-emoji="🏦"
           data-nom="Crédit Ordinaire"
           data-taux="7"
           data-min="100000"
           data-max="10000000"
           data-duree="60"
           data-duree-type="libre"
           data-frais-dossier-pct="<?php echo $o['frais_dossier_pct']; ?>"
           data-frais-dossier-min="<?php echo $o['frais_dossier_min']; ?>"
           data-frais-dossier-max="<?php echo $o['frais_dossier_max']; ?>"
           data-fonds-garantie-pct="<?php echo $o['fonds_garantie_pct']; ?>"
           data-mensualite-min="5000"
           data-garantie="🔒 Garantie : 25% (ou 50% si ≥ 7M). Moitié immédiate, moitié déduite du crédit<br>📊 Taux : 6% à 7% selon ratio épargne/crédit<br>💰 Frais dossier : 0,5% (min 5 000 F, max 50 000 F) déduits du crédit<br>🛡️ Fonds garantie : 1% déduits du crédit<br>📌 Mensualité min : 5 000 F"
           onclick="<?php echo $o['ok'] ? 'selectCredit(this)' : ''; ?>">
        <div class="credit-emoji">🏦</div>
        <div class="credit-nom">Crédit Ordinaire</div>
        <div class="credit-desc">Projets importants. 100 000 à 10 000 000 F. Max 60 mois.</div>
        <span class="credit-taux">6% à 7% / an</span>
        <?php if (!$o['ok']): ?>
          <div class="credit-raison">
            <?php foreach ($o['raisons'] as $r): ?>⚠️ <?php echo $r; ?><br><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- IMMOBILIER -->
      <?php $i = $eligibilite['immobilier']; ?>
      <div class="credit-card <?php echo !$i['ok'] ? 'disabled' : ''; ?>"
           data-code="immobilier"
           data-emoji="🏠"
           data-nom="Crédit Immobilier"
           data-taux="7"
           data-min="0"
           data-max="20000000"
           data-duree="168"
           data-duree-type="choix"
           data-frais-dossier="<?php echo $i['frais_dossier']; ?>"
           data-fonds-garantie="<?php echo $i['fonds_garantie']; ?>"
           data-apport-min-pct="<?php echo $i['apport_min_pct']; ?>"
           data-apport-max-pct="<?php echo $i['apport_max_pct']; ?>"
           data-garantie="🏠 Apport requis : <?php echo $i['apport_min_pct']; ?>% à <?php echo $i['apport_max_pct']; ?>% du coût du logement<br>📊 Taux : 7% fixe<br>💰 Frais dossier : <?php echo number_format($i['frais_dossier'], 0, ',', ' '); ?> F<br>🛡️ Fonds garantie : <?php echo number_format($i['fonds_garantie'], 0, ',', ' '); ?> F<br>📅 Durée : 144 mois (12 ans) ou 168 mois (14 ans)"
           onclick="<?php echo $i['ok'] ? 'selectCredit(this)' : ''; ?>">
        <div class="credit-emoji">🏠</div>
        <div class="credit-nom">Crédit Immobilier</div>
        <div class="credit-desc">Le parcours immobilier passe maintenant par la demande logement et l'attribution d'un type de logement MA2E.</div>
        <span class="credit-taux">7% / an fixe</span>
        <?php if (!$i['ok']): ?>
          <div class="credit-raison">
            <?php foreach ($i['raisons'] as $r): ?>⚠️ <?php echo $r; ?><br><?php endforeach; ?>
          </div>
          <div style="margin-top:10px">
            <a href="demande_logement.php" style="display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:10px;background:#F5EDD6;color:#7a5d17;text-decoration:none;font-size:12px;font-weight:700">Ouvrir la demande logement</a>
          </div>
        <?php else: ?>
          <?php if ($solde_logement > 0): ?>
          <div style="font-size:11px;color:#2E7D32;margin-top:6px">
            ✅ Épargne logement : <?php echo number_format($solde_logement, 0, ',', ' '); ?> F
            (apport requis : <?php echo $i['apport_min_pct']; ?>% à <?php echo $i['apport_max_pct']; ?>%)
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- DIFFÉRÉ -->
      <div class="credit-card disabled" style="opacity:.35">
        <div class="credit-emoji">📋</div>
        <div class="credit-nom">Crédit Différé</div>
      <div class="credit-desc">Généré automatiquement apres attribution d'un logement et creation du credit immobilier principal.</div>
        <span class="credit-taux" style="background:#eee;color:#aaa">Automatique</span>
        <div class="credit-raison">⚠️ Non disponible à la demande directe</div>
      </div>

    </div>

    <!-- FORMULAIRE DE DEMANDE -->
    <div class="form-card" id="formDetails">
      <div class="form-header">
        <span class="form-header-emoji" id="formEmoji"></span>
        <div>
          <div class="form-header-title" id="formNom"></div>
          <div style="font-size:12px;color:var(--text-light)">Remplissez les paramètres de votre demande</div>
        </div>
      </div>
      <div class="form-body">

        <!-- Info garantie -->
        <div class="garantie-box" id="garantieBox"></div>

        <!-- Info frais déduits -->
        <div class="frais-box" id="fraisBox" style="display:none"></div>

        <!-- Montant -->
        <div class="form-group">
          <label>Montant souhaité (F CFA) *</label>
          <input type="number" name="montant" id="montantInput" min="1" step="1000"
            placeholder="Ex: 500 000" required oninput="updateSimulateur()">
          <span class="form-hint" id="montantHint"></span>
        </div>

        <!-- Durée — saisie libre (express/ordinaire) -->
        <div class="form-group" id="dureeLibreGroup">
          <label>Durée de remboursement (mois) *</label>
          <input type="number" id="dureeLibreInput" min="1" step="1"
            placeholder="Ex: 24" required oninput="onDureeLibreChange()">
          <span class="form-hint" id="dureeHint"></span>
        </div>

        <!-- CORRECTION #10 : Durée choix boutons (immobilier uniquement) -->
        <div class="form-group" id="dureeChoixGroup" style="display:none">
          <label>Durée de remboursement *</label>
          <div class="duree-btns">
            <div class="duree-btn" onclick="selectDuree(144, this)">
              <div style="font-size:16px;font-weight:800">144 mois</div>
              <div style="font-size:11px;color:var(--text-light)">12 ans</div>
            </div>
            <div class="duree-btn" onclick="selectDuree(168, this)">
              <div style="font-size:16px;font-weight:800">168 mois</div>
              <div style="font-size:11px;color:var(--text-light)">14 ans</div>
            </div>
          </div>
        </div>

        <!-- Objet -->
        <div class="form-group">
          <label>Objet de la demande *</label>
          <textarea name="objet" placeholder="Ex: Achat véhicule, travaux maison, urgence familiale..."
            required minlength="10"></textarea>
          <span class="form-hint">Décrivez brièvement l'utilisation prévue du crédit</span>
        </div>

        <!-- Simulateur -->
        <div class="simulateur" id="simulateur" style="display:none">
          <div class="sim-title">📊 Simulation de remboursement</div>
          <div class="sim-grid">
            <div class="sim-item" style="grid-column:1/-1">
              <div class="sim-mensualite" id="simMensualite">—</div>
              <div class="sim-lbl">Mensualité estimée (F CFA)</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simTaux">—</div>
              <div class="sim-lbl">Taux annuel</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simTotal">—</div>
              <div class="sim-lbl">Coût total du crédit</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simInterets">—</div>
              <div class="sim-lbl">Total intérêts</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simGarantie">—</div>
              <div class="sim-lbl">Dépôt de garantie</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simFrais">—</div>
              <div class="sim-lbl">Frais dossier</div>
            </div>
            <div class="sim-item">
              <div class="sim-val" id="simFonds">—</div>
              <div class="sim-lbl">Fonds de garantie</div>
            </div>
            <div class="sim-item" style="grid-column:1/-1;background:rgba(201,168,76,.15)">
              <div class="sim-val" id="simMontantNet" style="color:var(--gold)">—</div>
              <div class="sim-lbl">💰 Montant net reçu</div>
            </div>
          </div>
          <div class="sim-quotite" id="simQuotite"></div>
          <div class="sim-quotite" id="simApport" style="margin-top:6px;display:none"></div>
        </div>

        <button type="submit" class="btn-submit" id="btnSubmit" disabled
          onclick="return confirm('Confirmer la soumission de votre demande de crédit ?')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Soumettre la demande
        </button>

      </div>
    </div>

  </form>

</div>

<script>
const QUOTITE_CESSIBLE   = <?php echo $quotite_cessible; ?>;
const QUOTITE_DISPONIBLE = <?php echo $quotite_disponible; ?>;
const SOLDE_ORDINAIRE    = <?php echo $solde_ord; ?>;
const SOLDE_LOGEMENT     = <?php echo $solde_logement; ?>;
// CORRECTION #6 : ancienneté en mois
const ANCIENNETE_EXP_MOIS = <?php echo $anciennete_exp_mois; ?>;

let creditSelectionne = null;
let dureeSelectionnee = 0;

function selectCredit(el) {
  document.querySelectorAll('.credit-card:not(.disabled)').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');

  creditSelectionne = {
    code              : el.dataset.code,
    emoji             : el.dataset.emoji,
    nom               : el.dataset.nom,
    taux              : parseFloat(el.dataset.taux),
    min               : parseInt(el.dataset.min),
    max               : parseInt(el.dataset.max),
    duree             : parseInt(el.dataset.duree),
    dureeType         : el.dataset.dureeType, // 'libre' ou 'choix'
    garantie          : el.dataset.garantie,
    fraisDossier      : parseFloat(el.dataset.fraisDossier) || 0,
    fraisDossierPct   : parseFloat(el.dataset.fraisDossierPct) || 0,
    fraisDossierMin   : parseInt(el.dataset.fraisDossierMin) || 0,
    fraisDossierMax   : parseInt(el.dataset.fraisDossierMax) || 0,
    fondsGarantiePct  : parseFloat(el.dataset.fondsGarantiePct) || 0,
    fondsGarantie     : parseFloat(el.dataset.fondsGarantie) || 0,
    mensualiteMin     : parseInt(el.dataset.mensualiteMin) || 0,
    apportMinPct      : parseInt(el.dataset.apportMinPct) || 0,
    apportMaxPct      : parseInt(el.dataset.apportMaxPct) || 0,
  };

  document.getElementById('typeCreditHidden').value = creditSelectionne.code;
  document.getElementById('formEmoji').textContent  = creditSelectionne.emoji;
  document.getElementById('formNom').textContent    = creditSelectionne.nom;
  document.getElementById('garantieBox').innerHTML  = creditSelectionne.garantie;

  // CORRECTION #10 : afficher les bons champs durée
  const libreGroup = document.getElementById('dureeLibreGroup');
  const choixGroup = document.getElementById('dureeChoixGroup');
  if (creditSelectionne.dureeType === 'choix') {
    libreGroup.style.display = 'none';
    choixGroup.style.display = 'block';
    // Réinitialiser durée
    dureeSelectionnee = 0;
    document.getElementById('dureeMoisHidden').value = '';
    document.querySelectorAll('.duree-btn').forEach(b => b.classList.remove('active'));
  } else {
    libreGroup.style.display = 'block';
    choixGroup.style.display = 'none';
    document.getElementById('dureeLibreInput').max = creditSelectionne.duree;
  }

  // Hints montant
  let hint = '';
  if (creditSelectionne.min > 0) hint += 'Min : ' + fmt(creditSelectionne.min) + ' F. ';
  if (creditSelectionne.max > 0) hint += 'Max : ' + fmt(creditSelectionne.max) + ' F.';
  document.getElementById('montantHint').textContent = hint;
  if (creditSelectionne.dureeType === 'libre')
    document.getElementById('dureeHint').textContent = 'Maximum : ' + creditSelectionne.duree + ' mois.';

  const inp = document.getElementById('montantInput');
  inp.min = creditSelectionne.min;
  inp.max = creditSelectionne.max;
  inp.value = '';

  // Frais box
  const fraisBox = document.getElementById('fraisBox');
  if (creditSelectionne.code === 'express') {
    fraisBox.style.display = 'block';
    fraisBox.innerHTML = '💡 <strong>Frais déduits du montant versé :</strong><br>'
      + '• Frais de dossier : 2 000 F<br>'
      + '• Fonds de garantie : 0,5% du crédit<br>'
      + '<strong>Montant net reçu = Montant demandé − frais − fonds de garantie</strong>';
  } else if (creditSelectionne.code === 'ordinaire') {
    fraisBox.style.display = 'block';
    fraisBox.innerHTML = '💡 <strong>Frais déduits du montant versé :</strong><br>'
      + '• Frais de dossier : 0,5% (min 5 000 F, max 50 000 F)<br>'
      + '• Fonds de garantie : 1% du crédit<br>'
      + '• Garantie : 25% (ou 50% si ≥ 7M), dont moitié déduite du crédit<br>'
      + '<strong>Montant net reçu = Montant − frais − fonds de garantie − ½ garantie</strong>';
  } else if (creditSelectionne.code === 'immobilier') {
    fraisBox.style.display = 'block';
    fraisBox.innerHTML = '💡 <strong>Frais à la mise en place :</strong><br>'
      + '• Frais de dossier : 100 000 F<br>'
      + '• Fonds de garantie : 50 000 F';
  } else {
    fraisBox.style.display = 'none';
  }

  document.getElementById('simApport').style.display =
    creditSelectionne.code === 'immobilier' ? 'block' : 'none';

  document.getElementById('formDetails').classList.add('visible');
  document.getElementById('simulateur').style.display = 'none';
  document.getElementById('btnSubmit').disabled = true;
  document.getElementById('formDetails').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// CORRECTION #10 : sélection durée immobilier
function selectDuree(duree, btn) {
  dureeSelectionnee = duree;
  document.getElementById('dureeMoisHidden').value = duree;
  document.querySelectorAll('.duree-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  updateSimulateur();
}

function onDureeLibreChange() {
  const v = parseInt(document.getElementById('dureeLibreInput').value) || 0;
  dureeSelectionnee = v;
  document.getElementById('dureeMoisHidden').value = v;
  updateSimulateur();
}

function updateSimulateur() {
  if (!creditSelectionne) return;
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  const duree   = dureeSelectionnee;

  if (montant <= 0 || duree <= 0) {
    document.getElementById('simulateur').style.display = 'none';
    document.getElementById('btnSubmit').disabled = true;
    return;
  }

  // CORRECTION #6 : taux dynamique en MOIS
  let taux = creditSelectionne.taux;
  if (creditSelectionne.code === 'express') {
    if (ANCIENNETE_EXP_MOIS >= 120)     taux = 7.00;
    else if (ANCIENNETE_EXP_MOIS >= 96) taux = 7.50;
    else if (ANCIENNETE_EXP_MOIS >= 72) taux = 8.00;
    else if (ANCIENNETE_EXP_MOIS >= 48) taux = 8.50;
    else                                 taux = 9.00;
  }
  if (creditSelectionne.code === 'ordinaire') {
    const ratio = SOLDE_ORDINAIRE > 0 ? Math.min(SOLDE_ORDINAIRE / montant, 9.99) : 0;
    if (ratio >= 0.75)      taux = 6.00;
    else if (ratio >= 0.50) taux = 6.50;
    else                    taux = 7.00;
  }

  // Mensualité
  const t = taux / 100 / 12;
  const mensualite = t > 0
    ? montant * t / (1 - Math.pow(1 + t, -duree))
    : montant / duree;

  const total    = mensualite * duree;
  const interets = total - montant;

  // Calcul frais, fonds, garantie, montant net
  let fraisDossier = 0, fondsGarantie = 0, garantie = 0, montantNet = montant;

  if (creditSelectionne.code === 'express') {
    fraisDossier  = creditSelectionne.fraisDossier; // 2 000 F
    fondsGarantie = montant * creditSelectionne.fondsGarantiePct; // 0,5%
    montantNet    = montant - fraisDossier - fondsGarantie;
  }
  if (creditSelectionne.code === 'ordinaire') {
    fraisDossier  = Math.max(creditSelectionne.fraisDossierMin,
                    Math.min(creditSelectionne.fraisDossierMax, montant * creditSelectionne.fraisDossierPct));
    fondsGarantie = montant * creditSelectionne.fondsGarantiePct; // 1%
    garantie      = montant >= 7000000 ? montant * 0.5 : montant * 0.25;
    const garantieDeduite = garantie / 2; // moitié déduite du crédit
    montantNet    = montant - fraisDossier - fondsGarantie - garantieDeduite;
  }
  if (creditSelectionne.code === 'immobilier') {
    fraisDossier  = creditSelectionne.fraisDossier; // 100 000 F
    fondsGarantie = creditSelectionne.fondsGarantie; // 50 000 F
    montantNet    = montant; // l'apport couvre le reste
  }

  document.getElementById('simMensualite').textContent = fmt(mensualite) + ' F';
  document.getElementById('simTaux').textContent       = taux.toFixed(2) + '%';
  document.getElementById('simTotal').textContent      = fmt(total) + ' F';
  document.getElementById('simInterets').textContent   = fmt(interets) + ' F';
  document.getElementById('simGarantie').textContent   = garantie > 0 ? fmt(garantie) + ' F' : '—';
  document.getElementById('simFrais').textContent      = fraisDossier > 0 ? fmt(fraisDossier) + ' F' : '—';
  document.getElementById('simFonds').textContent      = fondsGarantie > 0 ? fmt(fondsGarantie) + ' F' : '—';
  document.getElementById('simMontantNet').textContent = fmt(montantNet) + ' F';
  document.getElementById('simulateur').style.display  = 'block';

  // Quotité
  const qDiv = document.getElementById('simQuotite');
  const quotiteNecessaire = mensualite + 15000;
  if (quotiteNecessaire <= QUOTITE_CESSIBLE) {
    qDiv.className   = 'sim-quotite sim-ok';
    qDiv.textContent = '✅ Mensualité + 15 000 F (' + fmt(quotiteNecessaire) + ' F) dans la quotité cessible (' + fmt(QUOTITE_CESSIBLE) + ' F)';
  } else {
    qDiv.className   = 'sim-quotite sim-ko';
    qDiv.textContent = '⚠️ Mensualité + 15 000 F (' + fmt(quotiteNecessaire) + ' F) dépasse la quotité cessible (' + fmt(QUOTITE_CESSIBLE) + ' F)';
  }

  // Apport immobilier
  const apportDiv = document.getElementById('simApport');
  if (creditSelectionne.code === 'immobilier') {
    const apportMinRequis = montant * (creditSelectionne.apportMinPct / 100);
    const apportMaxRequis = montant * (creditSelectionne.apportMaxPct / 100);
    if (SOLDE_LOGEMENT >= apportMinRequis) {
      apportDiv.className   = 'sim-quotite sim-ok';
      apportDiv.textContent = '✅ Apport suffisant : ' + fmt(SOLDE_LOGEMENT) + ' F '
        + '(requis : ' + fmt(apportMinRequis) + ' à ' + fmt(apportMaxRequis) + ' F)';
    } else {
      apportDiv.className   = 'sim-quotite sim-ko';
      apportDiv.textContent = '⚠️ Apport insuffisant : ' + fmt(SOLDE_LOGEMENT) + ' F '
        + '(requis min : ' + fmt(apportMinRequis) + ' F — ' + creditSelectionne.apportMinPct + '%)';
    }
  }

  // CORRECTION #8 : mensualité min 5 000 F pour tous sauf immo
  let mensualiteOk = true;
  if (creditSelectionne.mensualiteMin > 0 && mensualite < creditSelectionne.mensualiteMin) {
    mensualiteOk = false;
  }

  // Activer le bouton si tout est valide
  let valid = montant >= creditSelectionne.min
    && (creditSelectionne.max === 0 || montant <= creditSelectionne.max)
    && duree > 0
    && duree <= creditSelectionne.duree
    && mensualiteOk
    && quotiteNecessaire <= QUOTITE_CESSIBLE;

  if (creditSelectionne.code === 'immobilier') {
    const apportMinRequis = montant * (creditSelectionne.apportMinPct / 100);
    valid = valid && (SOLDE_LOGEMENT >= apportMinRequis);
    valid = valid && (duree === 144 || duree === 168); // CORRECTION #10
  }

  document.getElementById('btnSubmit').disabled = !valid;
}

function fmt(n) {
  return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
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
  document.querySelectorAll('.t-btn').forEach((b, i) => b.classList.toggle('active', i === 1));
}
</script>
</body>
</html>
