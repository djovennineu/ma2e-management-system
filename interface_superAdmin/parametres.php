<?php
require_once '../connexion/auth_check.php';
checkRole('super_admin');
require_once '../config/database.php';

$success = '';
$error   = '';

// ============================================================
// INITIALISATION : Insérer les paramètres manquants en base
// ============================================================
$parametres_defaut = [
    // Cotisations mensuelles
    ['cotisation_directeur',           '10000', 'Cotisation mensuelle Directeur',                  'cotisations'],
    ['cotisation_cadre',               '7500',  'Cotisation mensuelle Cadre',                      'cotisations'],
    ['cotisation_maitrise_sup',        '5000',  'Cotisation mensuelle Maîtrise Supérieure',        'cotisations'],
    ['cotisation_maitrise_1_2',        '3000',  'Cotisation mensuelle Maîtrise 1 & 2',             'cotisations'],
    ['cotisation_employe',             '1500',  'Cotisation mensuelle Employé / Ouvrier',          'cotisations'],

    // Taux épargne
    ['taux_interet_epargne',           '3.5',   'Taux d\'intérêt épargne annuel (%)',              'epargne'],
    ['taux_interet_dats_min',          '3.5',   'Taux DATS minimum (%)',                           'epargne'],
    ['taux_interet_dats_max',          '4.0',   'Taux DATS maximum (%)',                           'epargne'],
    ['taux_interet_datavp_min',        '3.5',   'Taux DATAVP minimum (%)',                         'epargne'],
    ['taux_interet_datavp_max',        '3.9',   'Taux DATAVP maximum (%)',                         'epargne'],

    // Pénalités épargne
    ['penalite_retrait_anticipe_dats',  '2',    'Pénalité retrait anticipé DATS (%)',              'penalites'],
    ['penalite_retrait_anticipe_datavp_avant_2ans', '2', 'Pénalité retrait DATAVP avant 2 ans (%)', 'penalites'],
    ['penalite_retrait_anticipe_datavp_apres_2ans', '1', 'Pénalité retrait DATAVP après 2 ans (%)', 'penalites'],

    // Pénalités crédit
    ['penalite_remboursement_anticipe', '1',    'Pénalité remboursement anticipé crédit (%)',      'penalites'],

    // Frais crédit Express
    ['frais_dossier_credit_express',   '2000',  'Frais dossier crédit Express (F CFA forfait)',   'frais'],
    ['fonds_garantie_credit_express',  '0.5',   'Fonds de garantie crédit Express (%)',            'frais'],

    // Frais crédit Ordinaire
    ['frais_dossier_credit_ord',       '0.5',   'Frais dossier crédit Ordinaire (%)',              'frais'],
    ['frais_dossier_credit_ord_min',   '5000',  'Frais dossier crédit Ordinaire minimum (F CFA)', 'frais'],
    ['frais_dossier_credit_ord_max',   '50000', 'Frais dossier crédit Ordinaire maximum (F CFA)', 'frais'],
    ['fonds_garantie_credit_ord',      '1',     'Fonds de garantie crédit Ordinaire (%)',          'frais'],

    // Frais crédit Immobilier
    ['frais_dossier_credit_immo',      '100000','Frais dossier crédit Immobilier (F CFA)',         'frais'],
    ['fonds_garantie_credit_immo',     '50000', 'Fonds de garantie crédit Immobilier (F CFA)',     'frais'],

    // Taux crédit Express selon ancienneté (en mois)
    ['taux_credit_express_24_48',      '9',     'Taux Express 24–47 mois d\'ancienneté (%)',       'taux_credits'],
    ['taux_credit_express_48_72',      '8.5',   'Taux Express 48–71 mois d\'ancienneté (%)',       'taux_credits'],
    ['taux_credit_express_72_96',      '8',     'Taux Express 72–95 mois d\'ancienneté (%)',       'taux_credits'],
    ['taux_credit_express_96_120',     '7.5',   'Taux Express 96–119 mois d\'ancienneté (%)',      'taux_credits'],
    ['taux_credit_express_120_plus',   '7',     'Taux Express 120 mois et + (%)',                  'taux_credits'],

    // Taux crédit Ordinaire selon ratio épargne/crédit
    ['taux_credit_ord_ratio_bas',      '7',     'Taux Ordinaire ratio < 50% (%)',                  'taux_credits'],
    ['taux_credit_ord_ratio_moyen',    '6.5',   'Taux Ordinaire ratio 50–74% (%)',                 'taux_credits'],
    ['taux_credit_ord_ratio_haut',     '6',     'Taux Ordinaire ratio >= 75% (%)',                 'taux_credits'],

    // Taux crédit Immobilier
    ['taux_credit_immo',               '7',     'Taux crédit Immobilier fixe (%)',                 'taux_credits'],
    ['taux_credit_differe',            '7',     'Taux crédit Différé (%)',                         'taux_credits'],

    // Seuils et quotités
    ['quotite_supplement',             '15000', 'Supplément quotité cessible (F CFA)',             'seuils'],
    ['mensualite_min_credit',          '5000',  'Mensualité minimum crédit (F CFA)',               'seuils'],
    ['apport_logement_pct_min',        '20',    'Apport logement minimum (%)',                     'seuils'],
    ['apport_logement_pct_max',        '35',    'Apport logement maximum (%)',                     'seuils'],
    ['garantie_seuil_50pct',           '7000000','Seuil crédit ordinaire pour garantie 50% (F)',  'seuils'],
    ['solde_mini_expresse',            '24',    'Nombre de mois cotisés minimum épargne Expresse', 'seuils'],
    ['retrait_min_expresse',           '5000',  'Montant minimum retrait épargne Expresse (F CFA)','seuils'],
    ['retrait_max_par_mois_expresse',  '1',     'Nombre maximum de retraits/mois épargne Expresse','seuils'],
];

// Insérer uniquement si la clé n'existe pas encore
foreach ($parametres_defaut as [$cle, $valeur, $description, $categorie]) {
    try {
        $pdo->prepare("
            INSERT IGNORE INTO parametres_systeme (cle, valeur, description, categorie)
            VALUES (?, ?, ?, ?)
        ")->execute([$cle, $valeur, $description, $categorie]);
    } catch (Exception $e) {
        // Colonnes description/categorie peut-être absentes — on ignore silencieusement
        try {
            $pdo->prepare("INSERT IGNORE INTO parametres_systeme (cle, valeur) VALUES (?, ?)")
                ->execute([$cle, $valeur]);
        } catch (Exception $e2) { /* ignoré */ }
    }
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {

    // Validations des plages
    $validations = [
        'cotisation_directeur'           => ['min' => 1500,  'max' => 100000, 'type' => 'int'],
        'cotisation_cadre'               => ['min' => 1500,  'max' => 100000, 'type' => 'int'],
        'cotisation_maitrise_sup'        => ['min' => 1500,  'max' => 100000, 'type' => 'int'],
        'cotisation_maitrise_1_2'        => ['min' => 1500,  'max' => 100000, 'type' => 'int'],
        'cotisation_employe'             => ['min' => 1500,  'max' => 100000, 'type' => 'int'],
        'taux_interet_epargne'           => ['min' => 0,     'max' => 20,     'type' => 'float'],
        'taux_interet_dats_min'          => ['min' => 0,     'max' => 20,     'type' => 'float'],
        'taux_interet_dats_max'          => ['min' => 0,     'max' => 20,     'type' => 'float'],
        'taux_interet_datavp_min'        => ['min' => 0,     'max' => 20,     'type' => 'float'],
        'taux_interet_datavp_max'        => ['min' => 0,     'max' => 20,     'type' => 'float'],
        'penalite_retrait_anticipe_dats' => ['min' => 0,     'max' => 10,     'type' => 'float'],
        'penalite_retrait_anticipe_datavp_avant_2ans' => ['min' => 0, 'max' => 10, 'type' => 'float'],
        'penalite_retrait_anticipe_datavp_apres_2ans' => ['min' => 0, 'max' => 10, 'type' => 'float'],
        'penalite_remboursement_anticipe'=> ['min' => 0,     'max' => 10,     'type' => 'float'],
        'frais_dossier_credit_express'   => ['min' => 0,     'max' => 50000,  'type' => 'int'],
        'fonds_garantie_credit_express'  => ['min' => 0,     'max' => 10,     'type' => 'float'],
        'frais_dossier_credit_ord'       => ['min' => 0,     'max' => 10,     'type' => 'float'],
        'frais_dossier_credit_ord_min'   => ['min' => 0,     'max' => 100000, 'type' => 'int'],
        'frais_dossier_credit_ord_max'   => ['min' => 0,     'max' => 500000, 'type' => 'int'],
        'fonds_garantie_credit_ord'      => ['min' => 0,     'max' => 10,     'type' => 'float'],
        'frais_dossier_credit_immo'      => ['min' => 0,     'max' => 500000, 'type' => 'int'],
        'fonds_garantie_credit_immo'     => ['min' => 0,     'max' => 500000, 'type' => 'int'],
        'taux_credit_express_24_48'      => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_express_48_72'      => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_express_72_96'      => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_express_96_120'     => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_express_120_plus'   => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_ord_ratio_bas'      => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_ord_ratio_moyen'    => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_ord_ratio_haut'     => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_immo'               => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'taux_credit_differe'            => ['min' => 0,     'max' => 30,     'type' => 'float'],
        'quotite_supplement'             => ['min' => 0,     'max' => 100000, 'type' => 'int'],
        'mensualite_min_credit'          => ['min' => 0,     'max' => 50000,  'type' => 'int'],
        'apport_logement_pct_min'        => ['min' => 0,     'max' => 100,    'type' => 'float'],
        'apport_logement_pct_max'        => ['min' => 0,     'max' => 100,    'type' => 'float'],
        'garantie_seuil_50pct'           => ['min' => 0,     'max' => 100000000, 'type' => 'int'],
        'solde_mini_expresse'            => ['min' => 1,     'max' => 60,     'type' => 'int'],
        'retrait_min_expresse'           => ['min' => 0,     'max' => 1000000,'type' => 'int'],
        'retrait_max_par_mois_expresse'  => ['min' => 0,     'max' => 10,     'type' => 'int'],
    ];

    $erreurs_validation = [];
    foreach ($validations as $cle => $regles) {
        if (!isset($_POST[$cle])) continue;
        $val = str_replace(',', '.', trim($_POST[$cle]));
        $val_num = $regles['type'] === 'int' ? (int)$val : (float)$val;
        if ($val_num < $regles['min'] || $val_num > $regles['max']) {
            $erreurs_validation[] = "Valeur hors plage pour <strong>$cle</strong> (min: {$regles['min']}, max: {$regles['max']}).";
        }
    }

    // Vérification cohérence apport min/max
    $apport_min = (float)str_replace(',', '.', $_POST['apport_logement_pct_min'] ?? 20);
    $apport_max = (float)str_replace(',', '.', $_POST['apport_logement_pct_max'] ?? 35);
    if ($apport_min > $apport_max) {
        $erreurs_validation[] = "L'apport minimum ne peut pas dépasser l'apport maximum.";
    }

    // Vérification cohérence taux DATS min/max
    $dats_min = (float)str_replace(',', '.', $_POST['taux_interet_dats_min'] ?? 3.5);
    $dats_max = (float)str_replace(',', '.', $_POST['taux_interet_dats_max'] ?? 4);
    if ($dats_min > $dats_max) {
        $erreurs_validation[] = "Le taux DATS minimum ne peut pas dépasser le maximum.";
    }

    if (!empty($erreurs_validation)) {
        $error = implode('<br>', $erreurs_validation);
    } else {
        try {
            $pdo->beginTransaction();

            foreach ($_POST as $cle => $valeur) {
                if ($cle === 'action') continue;
                $valeur = str_replace(',', '.', trim($valeur));
                if ($valeur === '') continue;
                $pdo->prepare("UPDATE parametres_systeme SET valeur = ? WHERE cle = ?")
                    ->execute([$valeur, $cle]);
            }

            $pdo->commit();
            $success = "Paramètres mis à jour avec succès.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// Charger tous les paramètres
$stmt = $pdo->query("SELECT * FROM parametres_systeme ORDER BY cle");
$parametres = $stmt->fetchAll(PDO::FETCH_ASSOC);
$p = [];
foreach ($parametres as $param) $p[$param['cle']] = $param['valeur'];

// Helper pour récupérer une valeur avec défaut
function pv($p, $cle, $defaut = '0') {
    return htmlspecialchars($p[$cle] ?? $defaut);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MA2E — Paramètres système</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#FBF5E6;--dark:#1a1a2e;--bg:#F4F2EE;--card:#ffffff;--text:#1e1e2e;--text-light:#7a7a8c;--border:#E8E4DC;--green:#22c55e;--green-bg:#f0fdf4;--red:#ef4444;--red-bg:#fef2f2}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:100vh;z-index:200}
.logo{display:flex;align-items:center;gap:10px;padding:0 20px 24px;border-bottom:1px solid rgba(255,255,255,.08)}
.logo-icon{width:34px;height:34px;background:var(--gold);border-radius:9px;display:flex;align-items:center;justify-content:center}
.logo-icon svg{width:18px;height:18px;fill:white}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.nav{flex:1;padding:18px 12px;display:flex;flex-direction:column;gap:3px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:#777;font-size:13px;font-weight:500;text-decoration:none;transition:all .2s}
.nav-item:hover{background:rgba(255,255,255,.05);color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-footer{padding:16px;border-top:1px solid rgba(255,255,255,.08)}
.admin-avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white}
.admin-name{font-size:13px;font-weight:600;color:white}
.admin-role{font-size:11px;color:#e8b84b}

.main{flex:1;padding:32px;overflow-x:hidden;max-width:calc(100% - 220px)}
.page-header{margin-bottom:28px}
.page-header h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--text);margin-bottom:4px}
.page-header p{font-size:14px;color:var(--text-light)}

.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:24px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:var(--green-bg);color:#166534;border-left:4px solid var(--green)}
.alert-error{background:var(--red-bg);color:#991b1b;border-left:4px solid var(--red)}

/* TABS navigation sections */
.tabs{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.tab-btn{padding:8px 16px;border:1.5px solid var(--border);border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;background:var(--card);color:var(--text-light);transition:all .2s;font-family:'DM Sans',sans-serif}
.tab-btn.active{background:var(--gold);color:white;border-color:var(--gold)}
.tab-section{display:none}
.tab-section.active{display:block}

.params-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
.param-card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 22px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;color:var(--text);display:flex;align-items:center;gap:8px}
.card-body{padding:18px 22px}
.param-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--border);gap:12px}
.param-row:last-child{border-bottom:none;padding-bottom:0}
.param-label{font-size:13px;font-weight:500;color:var(--text);flex:1;line-height:1.4}
.param-input-wrap{display:flex;align-items:center;gap:6px;flex-shrink:0}
.param-input{width:120px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;text-align:right;transition:border-color .2s}
.param-input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.param-input.wide{width:150px}
.param-suffix{font-size:11.5px;color:var(--text-light);white-space:nowrap}

/* Note règle */
.param-note{font-size:11px;color:var(--text-light);margin-top:2px;font-style:italic}

/* Barre actions sticky */
.form-actions{position:sticky;bottom:0;background:var(--card);border-top:1px solid var(--border);padding:16px 24px;margin-top:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;z-index:10}
.btn{padding:10px 22px;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:inline-flex;align-items:center;gap:7px}
.btn-primary{background:var(--gold);color:white}
.btn-primary:hover{background:#b8922e}
.btn-ghost{background:transparent;color:var(--text-light);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--bg)}

/* Topbar mobile */
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:150}
.overlay-mob.open{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .4s ease both}

@media(max-width:1100px){.params-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px;max-width:100%}
  .param-row{flex-direction:column;align-items:flex-start}
  .param-input{width:100%}
  .param-input-wrap{width:100%}
  .form-actions{flex-direction:column}
  .btn{width:100%;justify-content:center}
}
</style>
</head>
<body>

<div class="topbar">
  <div style="display:flex;align-items:center;gap:8px">
    <div style="width:28px;height:28px;background:var(--gold);border-radius:7px;display:flex;align-items:center;justify-content:center">
      <svg viewBox="0 0 24 24" fill="white" width="16" height="16"><path d="M3 12l9-9 9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9"/></svg>
    </div>
    <span style="font-family:'Playfair Display',serif;font-weight:600;font-size:17px">MA2E</span>
  </div>
  <button class="hamburger" onclick="toggleNav()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
</div>
<div class="overlay-mob" id="overlay-mob" onclick="toggleNav()"></div>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="logo">
      <div class="logo-icon"><svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9"/></svg></div>
      <span class="logo-text">MA2E</span>
    </div>
    <nav class="nav">
      <a class="nav-item" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
      </a>
      <a class="nav-item" href="gestion_utilisateurs.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Utilisateurs
      </a>
      <a class="nav-item" href="gestion_societes.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Sociétés
      </a>
      <a class="nav-item active" href="parametres.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>Paramètres
      </a>
      <a class="nav-item" href="../connexion/logout.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion
      </a>
    </nav>
    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px;padding:10px">
        <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['nom'] ?? 'A', 0, 1)); ?></div>
        <div>
          <div class="admin-name"><?php echo htmlspecialchars($_SESSION['nom'] ?? ''); ?></div>
          <div class="admin-role">Super Admin</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="page-header fadeUp">
      <h1>⚙️ Paramètres système</h1>
      <p>Configurez les taux, cotisations, frais et seuils de la MA2E</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success fadeUp">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <div><?php echo $success; ?></div>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error fadeUp">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <div><?php echo $error; ?></div>
    </div>
    <?php endif; ?>

    <!-- TABS -->
    <div class="tabs fadeUp">
      <button class="tab-btn active" onclick="showTab('cotisations', this)">👥 Cotisations</button>
      <button class="tab-btn" onclick="showTab('epargne', this)">💰 Épargne</button>
      <button class="tab-btn" onclick="showTab('taux_credits', this)">📊 Taux crédits</button>
      <button class="tab-btn" onclick="showTab('frais', this)">📋 Frais & Garanties</button>
      <button class="tab-btn" onclick="showTab('penalites', this)">⚠️ Pénalités</button>
      <button class="tab-btn" onclick="showTab('seuils', this)">🔧 Seuils</button>
    </div>

    <form method="POST" id="mainForm">
      <input type="hidden" name="action" value="update">

      <!-- ══ COTISATIONS ══ -->
      <div class="tab-section active" id="tab-cotisations">
        <div class="params-grid">
          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
              Cotisations mensuelles par catégorie
            </div>
            <div class="card-body">
              <?php
              $cats = [
                'cotisation_directeur'    => ['Directeur',            '10000'],
                'cotisation_cadre'        => ['Cadre',                '7500'],
                'cotisation_maitrise_sup' => ['Maîtrise Supérieure',  '5000'],
                'cotisation_maitrise_1_2' => ['Maîtrise 1 & 2',       '3000'],
                'cotisation_employe'      => ['Employé / Ouvrier',    '1500'],
              ];
              foreach ($cats as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <div>
                  <div class="param-label"><?php echo $label; ?></div>
                  <div class="param-note">Min: 1 500 F — Max: 100 000 F</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="100" min="1500" max="100000">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ ÉPARGNE ══ -->
      <div class="tab-section" id="tab-epargne">
        <div class="params-grid">
          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
              Taux d'intérêt épargne
            </div>
            <div class="card-body">
              <?php
              $taux_ep = [
                'taux_interet_epargne'    => ['Expresse / Ordinaire (annuel)', '3.5'],
                'taux_interet_dats_min'   => ['DATS — taux minimum',           '3.5'],
                'taux_interet_dats_max'   => ['DATS — taux maximum',           '4.0'],
                'taux_interet_datavp_min' => ['DATAVP — taux minimum',         '3.5'],
                'taux_interet_datavp_max' => ['DATAVP — taux maximum',         '3.9'],
              ];
              foreach ($taux_ep as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <span class="param-label"><?php echo $label; ?></span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="0.1" min="0" max="20">
                  <span class="param-suffix">% / an</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ TAUX CRÉDITS ══ -->
      <div class="tab-section" id="tab-taux_credits">
        <div class="params-grid">

          <!-- Express -->
          <div class="param-card fadeUp">
            <div class="card-header">⚡ Taux Crédit Express (selon ancienneté)</div>
            <div class="card-body">
              <?php
              $taux_exp = [
                'taux_credit_express_24_48'   => ['24 à 47 mois',   '9'],
                'taux_credit_express_48_72'   => ['48 à 71 mois',   '8.5'],
                'taux_credit_express_72_96'   => ['72 à 95 mois',   '8'],
                'taux_credit_express_96_120'  => ['96 à 119 mois',  '7.5'],
                'taux_credit_express_120_plus'=> ['120 mois et +',  '7'],
              ];
              foreach ($taux_exp as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <span class="param-label"><?php echo $label; ?></span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="0.5" min="0" max="30">
                  <span class="param-suffix">% / an</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Ordinaire -->
          <div class="param-card fadeUp">
            <div class="card-header">🏦 Taux Crédit Ordinaire (selon ratio)</div>
            <div class="card-body">
              <?php
              $taux_ord = [
                'taux_credit_ord_ratio_bas'   => ['Épargne/Crédit < 50%',   '7'],
                'taux_credit_ord_ratio_moyen' => ['Épargne/Crédit 50–74%',  '6.5'],
                'taux_credit_ord_ratio_haut'  => ['Épargne/Crédit ≥ 75%',   '6'],
              ];
              foreach ($taux_ord as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <span class="param-label"><?php echo $label; ?></span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="0.5" min="0" max="30">
                  <span class="param-suffix">% / an</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Immobilier & Différé -->
          <div class="param-card fadeUp">
            <div class="card-header">🏠 Taux Crédit Immobilier & Différé</div>
            <div class="card-body">
              <?php
              $taux_immo = [
                'taux_credit_immo'    => ['Crédit Immobilier (fixe)', '7'],
                'taux_credit_differe' => ['Crédit Différé',           '7'],
              ];
              foreach ($taux_immo as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <span class="param-label"><?php echo $label; ?></span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="0.5" min="0" max="30">
                  <span class="param-suffix">% / an</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

        </div>
      </div>

      <!-- ══ FRAIS & GARANTIES ══ -->
      <div class="tab-section" id="tab-frais">
        <div class="params-grid">

          <!-- Crédit Express -->
          <div class="param-card fadeUp">
            <div class="card-header">⚡ Frais — Crédit Express</div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Frais de dossier (forfait)</div>
                  <div class="param-note">Déduits du montant versé</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="frais_dossier_credit_express"
                         value="<?php echo pv($p, 'frais_dossier_credit_express', '2000'); ?>"
                         step="100" min="0" max="50000">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Fonds de garantie</div>
                  <div class="param-note">Déduits du montant versé</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="fonds_garantie_credit_express"
                         value="<?php echo pv($p, 'fonds_garantie_credit_express', '0.5'); ?>"
                         step="0.1" min="0" max="10">
                  <span class="param-suffix">%</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Crédit Ordinaire -->
          <div class="param-card fadeUp">
            <div class="card-header">🏦 Frais — Crédit Ordinaire</div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Frais de dossier</div>
                  <div class="param-note">Déduits du montant versé</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="frais_dossier_credit_ord"
                         value="<?php echo pv($p, 'frais_dossier_credit_ord', '0.5'); ?>"
                         step="0.1" min="0" max="10">
                  <span class="param-suffix">%</span>
                </div>
              </div>
              <div class="param-row">
                <span class="param-label">Frais dossier minimum</span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="frais_dossier_credit_ord_min"
                         value="<?php echo pv($p, 'frais_dossier_credit_ord_min', '5000'); ?>"
                         step="100" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <span class="param-label">Frais dossier maximum</span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="frais_dossier_credit_ord_max"
                         value="<?php echo pv($p, 'frais_dossier_credit_ord_max', '50000'); ?>"
                         step="100" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Fonds de garantie</div>
                  <div class="param-note">Déduits du montant versé</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="fonds_garantie_credit_ord"
                         value="<?php echo pv($p, 'fonds_garantie_credit_ord', '1'); ?>"
                         step="0.1" min="0" max="10">
                  <span class="param-suffix">%</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Crédit Immobilier -->
          <div class="param-card fadeUp">
            <div class="card-header">🏠 Frais — Crédit Immobilier</div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Frais de dossier (forfait)</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="frais_dossier_credit_immo"
                         value="<?php echo pv($p, 'frais_dossier_credit_immo', '100000'); ?>"
                         step="1000" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Fonds de garantie (forfait)</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="fonds_garantie_credit_immo"
                         value="<?php echo pv($p, 'fonds_garantie_credit_immo', '50000'); ?>"
                         step="1000" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ══ PÉNALITÉS ══ -->
      <div class="tab-section" id="tab-penalites">
        <div class="params-grid">
          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Pénalités épargne
            </div>
            <div class="card-body">
              <?php
              $pen_ep = [
                'penalite_retrait_anticipe_dats'             => ['DATS — retrait anticipé', '2'],
                'penalite_retrait_anticipe_datavp_avant_2ans'=> ['DATAVP — retrait avant 2 ans', '2'],
                'penalite_retrait_anticipe_datavp_apres_2ans'=> ['DATAVP — retrait après 2 ans', '1'],
              ];
              foreach ($pen_ep as $cle => [$label, $defaut]): ?>
              <div class="param-row">
                <span class="param-label"><?php echo $label; ?></span>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="<?php echo $cle; ?>"
                         value="<?php echo pv($p, $cle, $defaut); ?>"
                         step="0.5" min="0" max="10">
                  <span class="param-suffix">%</span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              Pénalités crédit
            </div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Remboursement anticipé</div>
                  <div class="param-note">Appliqué sur le capital restant dû</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="penalite_remboursement_anticipe"
                         value="<?php echo pv($p, 'penalite_remboursement_anticipe', '1'); ?>"
                         step="0.5" min="0" max="10">
                  <span class="param-suffix">%</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══ SEUILS ══ -->
      <div class="tab-section" id="tab-seuils">
        <div class="params-grid">
          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/></svg>
              Seuils crédits
            </div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Supplément quotité cessible</div>
                  <div class="param-note">Ajouté à la mensualité pour vérifier la quotité</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="quotite_supplement"
                         value="<?php echo pv($p, 'quotite_supplement', '15000'); ?>"
                         step="1000" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Mensualité minimum crédit</div>
                  <div class="param-note">Express et Ordinaire</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="mensualite_min_credit"
                         value="<?php echo pv($p, 'mensualite_min_credit', '5000'); ?>"
                         step="500" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Seuil garantie 50% (crédit ordinaire)</div>
                  <div class="param-note">En dessous : 25%, au-dessus : 50%</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="garantie_seuil_50pct"
                         value="<?php echo pv($p, 'garantie_seuil_50pct', '7000000'); ?>"
                         step="100000" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
            </div>
          </div>

          <div class="param-card fadeUp">
            <div class="card-header">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              Seuils immobilier & épargne
            </div>
            <div class="card-body">
              <div class="param-row">
                <div>
                  <div class="param-label">Apport logement minimum</div>
                  <div class="param-note">% du coût du logement</div>
                </div>
                <div class="param-input-wrap">
                  <!-- CORRECTION #1 : stocké en % (20) pas en décimal (0.20) -->
                  <input type="number" class="param-input" name="apport_logement_pct_min"
                         value="<?php echo pv($p, 'apport_logement_pct_min', '20'); ?>"
                         step="1" min="0" max="100">
                  <span class="param-suffix">%</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Apport logement maximum</div>
                  <div class="param-note">% du coût du logement</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="apport_logement_pct_max"
                         value="<?php echo pv($p, 'apport_logement_pct_max', '35'); ?>"
                         step="1" min="0" max="100">
                  <span class="param-suffix">%</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Ancienneté minimum épargne Expresse</div>
                  <div class="param-note">Pour accéder au crédit Express</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="solde_mini_expresse"
                         value="<?php echo pv($p, 'solde_mini_expresse', '24'); ?>"
                         step="1" min="1" max="60">
                  <span class="param-suffix">mois</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Retrait minimum épargne Expresse</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input wide" name="retrait_min_expresse"
                         value="<?php echo pv($p, 'retrait_min_expresse', '5000'); ?>"
                         step="500" min="0">
                  <span class="param-suffix">F CFA</span>
                </div>
              </div>
              <div class="param-row">
                <div>
                  <div class="param-label">Retraits maximum / mois (Expresse)</div>
                </div>
                <div class="param-input-wrap">
                  <input type="number" class="param-input" name="retrait_max_par_mois_expresse"
                         value="<?php echo pv($p, 'retrait_max_par_mois_expresse', '1'); ?>"
                         step="1" min="0" max="10">
                  <span class="param-suffix">/ mois</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ACTIONS STICKY -->
      <div class="form-actions fadeUp">
        <div style="font-size:12px;color:var(--text-light);display:flex;align-items:center;gap:6px">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Les modifications sont appliquées immédiatement
        </div>
        <button type="submit" class="btn btn-primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Enregistrer tous les paramètres
        </button>
      </div>

    </form>
  </main>
</div>

<script>
function showTab(id, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}
function toggleNav() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay-mob').classList.toggle('open');
}
</script>
</body>
</html>