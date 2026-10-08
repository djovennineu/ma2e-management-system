<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

// ── STATS ──

// 1. Nombre de membres actifs
$nb_membres = $pdo->query("SELECT COUNT(*) FROM membres WHERE statut='actif'")->fetchColumn();
$nb_demandes_retrait = $pdo->query("
    SELECT COUNT(*) FROM demandes_retrait WHERE statut = 'en_attente'
")->fetchColumn();

$nb_nouvelles_demandes = $pdo->query("
    SELECT COUNT(*) FROM demandes_retrait 
    WHERE statut = 'en_attente' 
    AND DATE(created_at) = CURDATE()
")->fetchColumn();
// 2. Total épargne collectée ce mois
$epargne_mois = $pdo->query("
    SELECT COALESCE(SUM(montant),0) FROM mouvements
    WHERE type IN ('depot','prelevement_salaire')
    AND DATE_FORMAT(date_operation,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
")->fetchColumn();

// 3. Prélèvements du mois en cours
$nb_prelevements = $pdo->query("
    SELECT COUNT(DISTINCT ce.membre_id) FROM mouvements mo
    JOIN comptes_epargne ce ON mo.compte_id = ce.id
    WHERE mo.type = 'prelevement_salaire'
    AND DATE_FORMAT(mo.date_operation,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
")->fetchColumn();

$total_prelevements = $pdo->query("
    SELECT COALESCE(SUM(montant),0) FROM mouvements
    WHERE type = 'prelevement_salaire'
    AND DATE_FORMAT(date_operation,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
")->fetchColumn();

// 4. Total crédits en cours
$nb_credits = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut='en_cours'")->fetchColumn();
$total_credits = $pdo->query("SELECT COALESCE(SUM(capital_restant_du),0) FROM credits WHERE statut='en_cours'")->fetchColumn();

// 5. Membres en retard (ont un crédit en_retard)
$nb_retard = $pdo->query("
    SELECT COUNT(DISTINCT membre_id) FROM credits WHERE statut='en_retard'
")->fetchColumn();

// 6. Vérifier les prélèvements en retard (mois précédent non prélevé)
$mois_precedent = date('Y-m', strtotime('-1 month'));
$mois_precedent_label = date('F Y', strtotime('-1 month'));

$prelevements_retard = $pdo->prepare("
    SELECT COUNT(DISTINCT ce.membre_id) as nb_membres,
           SUM(ce.versement_mensuel) as total_attendu
    FROM membres m
    JOIN comptes_epargne ce ON ce.membre_id = m.id
    WHERE m.statut = 'actif'
    AND ce.statut = 'actif'
    AND ce.retenue_source = 1
    AND COALESCE(ce.versement_mensuel, 0) > 0
    AND NOT EXISTS (
        SELECT 1 FROM mouvements mo
        WHERE mo.compte_id = ce.id
        AND mo.type = 'prelevement_salaire'
        AND YEAR(mo.date_operation) = YEAR(?)
        AND MONTH(mo.date_operation) = MONTH(?)
    )
");
$prelevements_retard->execute([$mois_precedent . '-01', $mois_precedent . '-01']);
$prelevements_retard_data = $prelevements_retard->fetch(PDO::FETCH_ASSOC);
$nb_prelevements_retard = $prelevements_retard_data['nb_membres'] ?? 0;
$total_prelevements_retard = $prelevements_retard_data['total_attendu'] ?? 0;

// 7. Dernières opérations (15)
$dernieres_ops = $pdo->query("
    SELECT mo.*, m.nom, m.prenoms, m.numero_membre,
           tce.libelle AS compte_libelle, tce.code AS type_code
    FROM mouvements mo
    JOIN comptes_epargne ce ON mo.compte_id = ce.id
    JOIN membres m ON ce.membre_id = m.id
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    ORDER BY mo.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// 8. Sociétés pour filtre prélèvement
$societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Mois en cours
$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_courant = $mois_fr[(int)date('n')] . ' ' . date('Y');

// Pour chaque société, compter les prélèvements en retard
$societes_retard = [];
foreach ($societes as $s) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ce.id) as nb_retard
        FROM membres m
        JOIN comptes_epargne ce ON ce.membre_id = m.id
        WHERE m.societe_id = ? 
        AND m.statut = 'actif'
        AND ce.statut = 'actif'
        AND ce.retenue_source = 1
        AND COALESCE(ce.versement_mensuel, 0) > 0
        AND NOT EXISTS (
            SELECT 1 FROM mouvements mo
            WHERE mo.compte_id = ce.id
            AND mo.type = 'prelevement_salaire'
            AND YEAR(mo.date_operation) = YEAR(?)
            AND MONTH(mo.date_operation) = MONTH(?)
        )
    ");
    $stmt->execute([$s['id'], $mois_precedent . '-01', $mois_precedent . '-01']);
    $nb = $stmt->fetchColumn();
    if ($nb > 0) {
        $societes_retard[$s['id']] = ['nom' => $s['nom'], 'code' => $s['code'], 'nb' => $nb];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Comptable — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3;--orange:#FF9800}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#777;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100}
.logo{padding:0 20px 28px;border-bottom:1px solid #333;display:flex;align-items:center;gap:10px}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.logo-sub{font-size:10px;color:#C9A84C;letter-spacing:.5px;text-transform:uppercase}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nav-section{font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:1px;padding:12px 12px 6px}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#C9A84C}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}

/* MAIN */
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:22px}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:600;color:var(--text)}
.page-sub{font-size:13.5px;color:var(--text-light);margin-top:3px}
.topbar-right{display:flex;align-items:center;gap:10px}
.btn-primary{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:var(--gold);color:white;border:none;border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-primary:hover{background:#b8922e;transform:translateY(-1px)}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:var(--card);color:var(--text);border:1.5px solid var(--border);border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-secondary:hover{border-color:var(--gold);color:var(--gold)}

/* ALERTES */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:16px}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-warning{background:#FFF8E1;color:#E65100;border-left:4px solid #FFC107}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-info{background:#E3F2FD;color:#1565C0;border-left:4px solid #1976D2}
.alert svg{width:18px;height:18px;flex-shrink:0}

/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:flex-start;gap:14px;transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.stat-icon svg{width:22px;height:22px}
.stat-label{font-size:12px;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.stat-value{font-size:22px;font-weight:800;color:var(--text);line-height:1;margin-bottom:3px}
.stat-sub{font-size:12px;color:var(--text-light)}
.stat-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;margin-top:4px}

/* GRILLE 2 COL */
.grid-2{display:grid;grid-template-columns:2fr 1fr;gap:20px}

/* CARD */
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title svg{width:15px;height:15px;color:var(--gold)}
.card-body{padding:0}

/* TABLE OPÉRATIONS */
table{width:100%;border-collapse:collapse}
thead th{padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);background:var(--bg);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody tr:last-child{border-bottom:none}
td{padding:11px 16px;font-size:13px;vertical-align:middle}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge-depot{background:#E8F5E9;color:#2E7D32}
.badge-retrait{background:#FFEBEE;color:#C62828}
.badge-prelevement{background:#E3F2FD;color:#1565C0}
.badge-interet{background:#F3E5F5;color:#6A1B9A}
.badge-virement{background:#FFF8E1;color:#F57F17}
.mvt-plus{color:#2E7D32;font-weight:700}
.mvt-minus{color:#C62828;font-weight:700}

/* PRÉLÈVEMENTS RAPIDES */
.prelevement-card{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:20px}
.prelevement-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px}
.prelevement-sub{font-size:12px;color:var(--text-light);margin-bottom:16px}
.societe-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:10px;background:var(--bg);margin-bottom:8px;cursor:pointer;transition:all .2s;text-decoration:none}
.societe-item:hover{background:var(--gold-bg);border:1px solid #e8c97a}
.societe-item:last-child{margin-bottom:0}
.societe-nom{font-size:13px;font-weight:600;color:var(--text)}
.societe-membres{font-size:11.5px;color:var(--text-light)}
.societe-arrow{color:var(--gold)}
.societe-warning{color:#F57F17;font-size:11px;margin-left:8px}

/* RETARD */
.retard-item{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)}
.retard-item:last-child{border-bottom:none}
.retard-avatar{width:32px;height:32px;border-radius:50%;background:#FFEBEE;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#C62828;flex-shrink:0}
.retard-nom{font-size:13px;font-weight:600;color:var(--text)}
.retard-info{font-size:11.5px;color:var(--text-light)}
.retard-montant{font-size:13px;font-weight:700;color:#C62828;white-space:nowrap}

/* TOPBAR MOBILE */
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger-btn{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar-mobile{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar-mobile">
  <button class="hamburger-btn" onclick="toggleSidebar()">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-family:'Playfair Display',serif;font-size:18px;color:var(--text)">MA2E</span>
  <div style="width:34px"></div>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div>
      <div class="logo-text">MA2E</div>
      <div class="logo-sub">Comptable</div>
    </div>
  </div>
  <nav class="nav">
    <div class="nav-section">Principal</div>
    <a class="nav-item active" href="dashboard_comptable.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>

    <div class="nav-section">Opérations</div>
    <a class="nav-item" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Prélèvement salaire
    </a>
    <a class="nav-item" href="saisir_versement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Versement espèces/virement
    </a>
     <a class="nav-item active" href="valider_retraits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>
      Valider retraits
    </a>
    <a class="nav-item" href="remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Remboursements crédit
    </a>

    <div class="nav-section">Calculs</div>
    <a class="nav-item" href="calcul_interets.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Calcul des intérêts
    </a>
    <a class="nav-item" href="rapports.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Rapports financiers
    </a>

    <div class="nav-section">Compte</div>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>
      Déconnexion
    </a>
  </nav>
  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'],0,1)); ?></div>
      <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($_SESSION['prenoms'].' '.$_SESSION['nom']); ?></div>
        <div class="role">Comptable</div>
      </div>
    </div>
    <div class="theme-toggle">
      <button class="theme-btn active" onclick="setTheme('light',this)">☀ Light</button>
      <button class="theme-btn" onclick="setTheme('dark',this)">🌙 Dark</button>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar fadeUp">
    <div>
      <h1 class="page-title">Dashboard</h1>
      <p class="page-sub">Vue d'ensemble — <?php echo $mois_courant; ?></p>
    </div>
    <div class="topbar-right">
      <a href="prelevement_salaire.php" class="btn-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Prélèvement mensuel
      </a>
      <a href="saisir_versement.php" class="btn-secondary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Saisir versement
      </a>
    </div>
  </div>

  <!-- ALERTE PRÉLÈVEMENTS EN RETARD -->
  <?php if ($nb_prelevements_retard > 0): ?>
  <div class="alert alert-error fadeUp">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
      <strong>⚠️ PRÉLÈVEMENTS EN RETARD !</strong><br>
      Le prélèvement pour le mois de <strong><?php echo $mois_precedent_label; ?></strong> n'a pas été effectué pour <strong><?php echo $nb_prelevements_retard; ?></strong> membre(s).<br>
      Montant total non prélevé : <strong><?php echo number_format($total_prelevements_retard, 0, ',', ' '); ?> F CFA</strong>
    </div>
  </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-grid fadeUp">

    <!-- Membres actifs -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#E3F2FD">
        <svg viewBox="0 0 24 24" fill="none" stroke="#1976D2" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      </div>
      <div>
        <div class="stat-label">Membres actifs</div>
        <div class="stat-value" style="color:#1976D2"><?php echo number_format($nb_membres); ?></div>
        <div class="stat-sub">Membres en règle</div>
      </div>
    </div>

    <div class="stat-card">
  <div class="stat-left">
    <div class="stat-value" style="color:#FF9800"><?php echo $nb_demandes_retrait; ?></div>
    <div class="stat-label">Demandes de retrait</div>
    <?php if ($nb_nouvelles_demandes > 0): ?>
    <div class="stat-sub" style="color:#FF9800; font-weight:600;">✨ +<?php echo $nb_nouvelles_demandes; ?> nouvelle(s) aujourd'hui</div>
    <?php endif; ?>
  </div>
  <div class="stat-icon warning">💸</div>
</div>

    <!-- Épargne collectée ce mois -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#F5EDD6">
        <svg viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div>
        <div class="stat-label">Épargne collectée</div>
        <div class="stat-value" style="color:var(--gold)"><?php echo number_format($epargne_mois/1000,0); ?>k</div>
        <div class="stat-sub"><?php echo number_format($epargne_mois,0,',',' '); ?> F CFA ce mois</div>
      </div>
    </div>

    <!-- Prélèvements du mois -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#E8F5E9">
        <svg viewBox="0 0 24 24" fill="none" stroke="#388E3C" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      </div>
      <div>
        <div class="stat-label">Prélèvements salaire</div>
        <div class="stat-value" style="color:#388E3C"><?php echo $nb_prelevements; ?></div>
        <div class="stat-sub"><?php echo number_format($total_prelevements,0,',',' '); ?> F CFA — <?php echo $mois_courant; ?></div>
        <span class="stat-badge" style="background:#E8F5E9;color:#2E7D32">
          <?php echo $nb_membres > 0 ? round($nb_prelevements/$nb_membres*100) : 0; ?>% traités
        </span>
      </div>
    </div>

    <!-- Crédits en cours -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#F3E5F5">
        <svg viewBox="0 0 24 24" fill="none" stroke="#7B1FA2" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      </div>
      <div>
        <div class="stat-label">Crédits en cours</div>
        <div class="stat-value" style="color:#7B1FA2"><?php echo $nb_credits; ?></div>
        <div class="stat-sub"><?php echo number_format($total_credits,0,',',' '); ?> F CFA encours</div>
      </div>
    </div>

    <!-- Membres en retard -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#FFEBEE">
        <svg viewBox="0 0 24 24" fill="none" stroke="#C62828" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div>
        <div class="stat-label">Membres en retard</div>
        <div class="stat-value" style="color:#C62828"><?php echo $nb_retard; ?></div>
        <div class="stat-sub">Crédits en retard de paiement</div>
        <?php if ($nb_retard > 0): ?>
        <span class="stat-badge" style="background:#FFEBEE;color:#C62828">⚠️ Action requise</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Taux de recouvrement -->
    <div class="stat-card">
      <div class="stat-icon" style="background:#FFF3E0">
        <svg viewBox="0 0 24 24" fill="none" stroke="#F57C00" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
      </div>
      <div>
        <div class="stat-label">Taux de recouvrement</div>
        <?php
        $taux = $nb_membres > 0 ? round(($nb_membres - $nb_retard) / $nb_membres * 100) : 100;
        ?>
        <div class="stat-value" style="color:#F57C00"><?php echo $taux; ?>%</div>
        <div class="stat-sub">Membres à jour / total actifs</div>
      </div>
    </div>

  </div>

  <!-- GRILLE : OPÉRATIONS + PRÉLÈVEMENTS PAR SOCIÉTÉ -->
  <div class="grid-2 fadeUp">

    <!-- Dernières opérations -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
          Dernières opérations
        </span>
        <a href="historique_operations.php" style="font-size:12px;color:var(--gold);font-weight:600;text-decoration:none">Voir tout →</a>
      </div>
      <div class="card-body" style="overflow-x:auto">
        <?php if (empty($dernieres_ops)): ?>
          <div style="text-align:center;padding:30px;color:var(--text-light);font-size:13px">Aucune opération enregistrée</div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Membre</th>
              <th>Type</th>
              <th>Compte</th>
              <th>Montant</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $type_labels = ['depot'=>'Dépôt','retrait'=>'Retrait','virement'=>'Virement','interet'=>'Intérêts','penalite'=>'Pénalité','remboursement'=>'Remboursement','prelevement_salaire'=>'Prélèvement','garantie'=>'Garantie','deblocage_garantie'=>'Déblocage'];
            $badge_classes = ['depot'=>'badge-depot','retrait'=>'badge-retrait','prelevement_salaire'=>'badge-prelevement','interet'=>'badge-interet','virement'=>'badge-virement'];
            foreach ($dernieres_ops as $op):
              $is_credit = in_array($op['type'],['depot','interet','remboursement','deblocage_garantie','prelevement_salaire']);
              $badge_class = $badge_classes[$op['type']] ?? 'badge-depot';
            ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($op['nom'].' '.$op['prenoms']); ?></div>
                <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($op['numero_membre']); ?></div>
              </td>
              <td><span class="badge <?php echo $badge_class; ?>"><?php echo $type_labels[$op['type']] ?? ucfirst($op['type']); ?></span></td>
              <td style="font-size:12px;color:var(--text-light)"><?php echo htmlspecialchars($op['compte_libelle']); ?></td>
              <td class="<?php echo $is_credit?'mvt-plus':'mvt-minus'; ?>">
                <?php echo $is_credit?'+':'-'; ?><?php echo number_format($op['montant'],0,',',' '); ?> F
              </td>
              <td style="font-size:12px;color:var(--text-light)"><?php echo date('d/m/Y', strtotime($op['date_operation'])); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Prélèvement par société -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- Lancer prélèvement -->
      <div class="prelevement-card">
        <div class="prelevement-title">🏢 Prélèvement mensuel</div>
        <div class="prelevement-sub">Sélectionnez une société pour lancer le prélèvement de <?php echo $mois_courant; ?></div>
        <?php foreach ($societes as $s):
          $nb_m = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE societe_id=? AND statut='actif'");
          $nb_m->execute([$s['id']]);
          $nb_m = $nb_m->fetchColumn();

          $deja = $pdo->prepare("
            SELECT COUNT(DISTINCT ce.membre_id) FROM mouvements mo
            JOIN comptes_epargne ce ON mo.compte_id = ce.id
            JOIN membres m ON ce.membre_id = m.id
            WHERE m.societe_id=? AND mo.type='prelevement_salaire'
            AND DATE_FORMAT(mo.date_operation,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
          ");
          $deja->execute([$s['id']]);
          $deja = $deja->fetchColumn();
          
          $a_retard = isset($societes_retard[$s['id']]);
        ?>
        <a href="prelevement_salaire.php?societe=<?php echo $s['id']; ?>" class="societe-item" style="border:1px solid transparent">
          <div>
            <div class="societe-nom">
              <?php echo htmlspecialchars($s['code'].' — '.$s['nom']); ?>
              <?php if ($a_retard): ?>
                <span class="societe-warning">⚠️ <?php echo $societes_retard[$s['id']]['nb']; ?> prélèvement(s) en retard</span>
              <?php endif; ?>
            </div>
            <div class="societe-membres">
              <?php echo $nb_m; ?> membres actifs
              <?php if ($deja > 0): ?>
                · <span style="color:#2E7D32;font-weight:600"><?php echo $deja; ?> traités ✓</span>
              <?php endif; ?>
            </div>
          </div>
          <svg class="societe-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Membres en retard de crédit -->
      <?php if ($nb_retard > 0): ?>
      <div class="card">
        <div class="card-header">
          <span class="card-title" style="color:#C62828">
            <svg viewBox="0 0 24 24" fill="none" stroke="#C62828" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Membres en retard de crédit
          </span>
        </div>
        <div class="card-body">
          <?php
          $retards = $pdo->query("
            SELECT m.nom, m.prenoms, m.numero_membre,
                   SUM(cr.capital_restant_du) AS total_du
            FROM credits cr
            JOIN membres m ON cr.membre_id = m.id
            WHERE cr.statut = 'en_retard'
            GROUP BY m.id
            LIMIT 5
          ")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($retards as $r):
            $ini = strtoupper(substr($r['nom'],0,1).substr($r['prenoms'],0,1));
          ?>
          <div class="retard-item">
            <div class="retard-avatar"><?php echo $ini; ?></div>
            <div style="flex:1">
              <div class="retard-nom"><?php echo htmlspecialchars($r['nom'].' '.$r['prenoms']); ?></div>
              <div class="retard-info"><?php echo htmlspecialchars($r['numero_membre']); ?></div>
            </div>
            <div class="retard-montant"><?php echo number_format($r['total_du'],0,',',' '); ?> F</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

</main>

<script>
function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>
