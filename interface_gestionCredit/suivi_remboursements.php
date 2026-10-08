<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_credit');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];

// ============================================================
// FILTRES ET RECHERCHE
// ============================================================
$search = trim($_GET['search'] ?? '');
$statut = $_GET['statut'] ?? '';
$type_credit = $_GET['type_credit'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$limit = (int)($_GET['limit'] ?? 50);

// Construction de la requête pour les crédits avec échéances
$sql = "
    SELECT c.*, tc.libelle as type_libelle, tc.code as type_code,
           m.nom, m.prenoms, m.numero_membre, m.email, m.telephone,
           s.nom as societe_nom, s.code as societe_code,
           (
               SELECT COUNT(*) FROM echeanciers 
               WHERE credit_id = c.id AND statut = 'en_retard'
           ) as nb_echeances_retard,
           (
               SELECT COUNT(*) FROM echeanciers 
               WHERE credit_id = c.id AND statut = 'a_venir'
           ) as nb_echeances_restantes,
           (
               SELECT MIN(date_echeance) FROM echeanciers 
               WHERE credit_id = c.id AND statut = 'a_venir'
           ) as prochaine_echeance
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    JOIN societes s ON m.societe_id = s.id
    WHERE c.statut IN ('en_cours', 'en_retard')
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (m.nom LIKE ? OR m.prenoms LIKE ? OR m.numero_membre LIKE ? OR c.numero_credit LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type_credit)) {
    $sql .= " AND tc.code = ?";
    $params[] = $type_credit;
}

if (!empty($statut)) {
    $sql .= " AND c.statut = ?";
    $params[] = $statut;
}

if (!empty($date_debut)) {
    $sql .= " AND c.date_mise_en_place >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $sql .= " AND c.date_mise_en_place <= ?";
    $params[] = $date_fin;
}

$sql .= " ORDER BY FIELD(c.statut, 'en_retard', 'en_cours'), c.date_mise_en_place ASC LIMIT " . (int)$limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// STATISTIQUES
// ============================================================
$stats = [];
$stats['total'] = count($credits);
$stats['total_encours'] = array_sum(array_column($credits, 'capital_restant_du'));
$stats['nb_en_cours'] = count(array_filter($credits, fn($c) => $c['statut'] === 'en_cours'));
$stats['nb_en_retard'] = count(array_filter($credits, fn($c) => $c['statut'] === 'en_retard'));
$stats['nb_retard_immediat'] = count(array_filter($credits, fn($c) => $c['nb_echeances_retard'] > 0));
$stats['montant_retard'] = array_sum(array_filter(array_column($credits, 'capital_restant_du'), function($k) use ($credits) {
    return $credits[$k]['nb_echeances_retard'] > 0;
}, ARRAY_FILTER_USE_KEY));

// Récupérer les échéances en retard pour le tableau
$echeances_retard = $pdo->prepare("
    SELECT e.*, c.numero_credit, c.montant_accorde, c.capital_restant_du,
           m.nom, m.prenoms, m.numero_membre, m.email,
           tc.libelle as type_libelle
    FROM echeanciers e
    JOIN credits c ON e.credit_id = c.id
    JOIN membres m ON c.membre_id = m.id
    JOIN types_credit tc ON c.type_credit_id = tc.id
    WHERE e.statut = 'en_retard'
    ORDER BY e.date_echeance ASC
    LIMIT 20
");
$echeances_retard->execute();
$echeances_retard_list = $echeances_retard->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les échéances à venir proches (dans les 7 jours)
$echeances_proches = $pdo->prepare("
    SELECT e.*, c.numero_credit, c.montant_accorde, c.capital_restant_du,
           m.nom, m.prenoms, m.numero_membre, m.email,
           tc.libelle as type_libelle
    FROM echeanciers e
    JOIN credits c ON e.credit_id = c.id
    JOIN membres m ON c.membre_id = m.id
    JOIN types_credit tc ON c.type_credit_id = tc.id
    WHERE e.statut = 'a_venir' 
    AND e.date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY e.date_echeance ASC
");
$echeances_proches->execute();
$echeances_proches_list = $echeances_proches->fetchAll(PDO::FETCH_ASSOC);

// Types de crédit pour le filtre
$types = $pdo->query("SELECT code, libelle FROM types_credit ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Mois pour l'affichage
$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_courant = $mois_fr[(int)date('n')] . ' ' . date('Y');

// Fonction de formatage
function fmt($n) {
    return number_format($n, 0, ',', ' ');
}

// Fonction pour la couleur du statut
function getStatusColor($statut) {
    switch ($statut) {
        case 'en_retard':
            return '#C62828';
        case 'en_cours':
            return '#2E7D32';
        default:
            return '#666';
    }
}

// Fonction pour l'icône du statut
function getStatusIcon($statut) {
    switch ($statut) {
        case 'en_retard':
            return '⚠️';
        case 'en_cours':
            return '✅';
        default:
            return '📌';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Suivi des remboursements — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3;--orange:#FF9800}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#777;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100}
.logo{padding:0 20px 28px;border-bottom:1px solid #333}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.logo-sub{font-size:10px;color:#C9A84C;letter-spacing:.5px;text-transform:uppercase;margin-top:2px}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nav-section{font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:1px;padding:12px 12px 6px}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#C9A84C}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}

/* MAIN */
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:20px}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-light)}
.breadcrumb a{color:var(--text-light);text-decoration:none}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .cur{color:var(--text);font-weight:600}

/* STATS GRID */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.stat-left .stat-value{font-size:28px;font-weight:800;color:var(--text);line-height:1}
.stat-left .stat-label{font-size:12px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px}

/* ALERTES */
.alert-card{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:16px 20px;margin-bottom:20px}
.alert-title{font-size:14px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.alert-list{display:flex;flex-direction:column;gap:10px}
.alert-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--bg);border-radius:10px}
.alert-item-info{flex:1}
.alert-item-nom{font-weight:600;font-size:13px}
.alert-item-detail{font-size:11px;color:var(--text-light);margin-top:2px}
.alert-item-date{font-size:12px;font-weight:600;color:var(--red);margin-right:15px}
.alert-item-montant{font-size:14px;font-weight:700;color:var(--gold)}
.btn-warning{padding:6px 12px;background:var(--orange);color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer;font-family:'DM Sans',sans-serif}
.btn-warning:hover{background:#e65100}

/* FILTRES */
.filter-card{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:20px;margin-bottom:20px}
.filter-title{font-size:14px;font-weight:700;margin-bottom:16px;color:var(--text);display:flex;align-items:center;gap:8px}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.filter-group{display:flex;flex-direction:column;gap:5px}
.filter-group label{font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.filter-group input,.filter-group select{padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;outline:none}
.filter-group input:focus,.filter-group select:focus{border-color:var(--gold)}
.filter-actions{display:flex;gap:12px;margin-top:16px}
.btn-filter{padding:10px 20px;background:var(--gold);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif}
.btn-filter:hover{background:#b8922e}
.btn-reset{padding:10px 20px;background:transparent;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-family:'DM Sans',sans-serif;color:var(--text)}
.btn-reset:hover{border-color:var(--gold);color:var(--gold)}

/* CARD */
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.card-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title svg{width:15px;height:15px;color:var(--gold)}
.card-body{padding:0}

/* TABLE */
.table-responsive{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);background:var(--bg);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody tr:last-child{border-bottom:none}
td{padding:12px 16px;font-size:13px;vertical-align:middle}
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;display:inline-block}
.badge-cours{background:#E8F5E9;color:#2E7D32}
.badge-retard{background:#FFEBEE;color:#C62828}
.badge-proche{background:#FFF8E1;color:#F57F17}
.montant{font-weight:700;color:var(--gold)}
.progress-bar{height:4px;background:var(--border);border-radius:2px;overflow:hidden;width:80px}
.progress-fill{height:100%;border-radius:2px}
.btn-detail{padding:6px 12px;background:var(--gold);color:white;border:none;border-radius:6px;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:12px;transition:all .2s;text-decoration:none;display:inline-block}
.btn-detail:hover{background:#b8922e}

/* TOPBAR MOBILE */
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}
.empty-state{text-align:center;padding:60px;color:var(--text-light)}
.empty-icon{font-size:48px;margin-bottom:16px}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar-mobile{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:1fr}
  .filter-grid{grid-template-columns:1fr}
  .alert-item{flex-direction:column;align-items:flex-start;gap:10px}
  .alert-item-date{align-self:flex-start}
}
</style>
</head>
<body>

<div class="topbar-mobile">
  <button onclick="toggleSidebar()" style="background:none;border:none;cursor:pointer;color:var(--text)">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-family:'Playfair Display',serif;font-size:18px">MA2E</span>
  <div style="width:34px"></div>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-text">MA2E</div>
    <div class="logo-sub">Gestionnaire Crédit</div>
  </div>
  <nav class="nav">
    <div class="nav-section">Principal</div>
    <a class="nav-item" href="dashboard_gestionnaire_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="nav-section">Crédits</div>
    <a class="nav-item" href="traiter_demande_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Traiter demandes
    </a>

    <a class="nav-item active" href="suivi_remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Suivi remboursements
    </a>
    
    <a class="nav-item" href="credits_en_cours.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Crédits en cours
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
        <div class="role">Gestionnaire Crédit</div>
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

  <div class="breadcrumb fadeUp">
    <a href="dashboard_gestionnaire_credit.php">Dashboard</a>
    <span class="sep">›</span>
    <span class="cur">Suivi des remboursements</span>
  </div>

  <div class="fadeUp">
    <h1 style="font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text)">Suivi des remboursements</h1>
    <p style="font-size:13.5px;color:var(--text-light);margin-top:4px">Surveillez les remboursements et les échéances en retard</p>
  </div>

  <!-- STATISTIQUES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo $stats['nb_en_cours']; ?></div>
        <div class="stat-label">Crédits en cours</div>
      </div>
      <div class="stat-icon" style="background:#E8F5E9;color:#2E7D32">✅</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value" style="color:#C62828"><?php echo $stats['nb_en_retard']; ?></div>
        <div class="stat-label">Crédits en retard</div>
      </div>
      <div class="stat-icon" style="background:#FFEBEE;color:#C62828">⚠️</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value"><?php echo fmt($stats['total_encours']/1000); ?>k</div>
        <div class="stat-label">Encours total</div>
      </div>
      <div class="stat-icon" style="background:#E3F2FD;color:#1976D2">💰</div>
    </div>
    <div class="stat-card">
      <div class="stat-left">
        <div class="stat-value" style="color:#F57F17"><?php echo fmt($stats['montant_retard']/1000); ?>k</div>
        <div class="stat-label">Montant en retard</div>
      </div>
      <div class="stat-icon" style="background:#FFF3E0;color:#F57F17">📉</div>
    </div>
  </div>

  <!-- ALERTES ÉCHÉANCES PROCHES -->
  <?php if (!empty($echeances_proches_list)): ?>
  <div class="alert-card fadeUp">
    <div class="alert-title">
      <span>⏰</span>
      <span>Échéances dans les 7 prochains jours</span>
    </div>
    <div class="alert-list">
      <?php foreach ($echeances_proches_list as $e): 
        $jours_restants = ceil((strtotime($e['date_echeance']) - time()) / 86400);
      ?>
      <div class="alert-item">
        <div class="alert-item-info">
          <div class="alert-item-nom"><?php echo htmlspecialchars($e['prenoms'] . ' ' . $e['nom']); ?></div>
          <div class="alert-item-detail"><?php echo htmlspecialchars($e['type_libelle']); ?> · <?php echo htmlspecialchars($e['numero_credit']); ?></div>
        </div>
        <div class="alert-item-date">📅 J+<?php echo $jours_restants; ?> (<?php echo date('d/m/Y', strtotime($e['date_echeance'])); ?>)</div>
        <div class="alert-item-montant"><?php echo fmt($e['mensualite_due']); ?> F</div>
        <a href="details_credit.php?id=<?php echo $e['credit_id']; ?>" class="btn-warning">Voir</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ALERTES ÉCHÉANCES EN RETARD -->
  <?php if (!empty($echeances_retard_list)): ?>
  <div class="alert-card fadeUp" style="border-left:4px solid #C62828">
    <div class="alert-title" style="color:#C62828">
      <span>🚨</span>
      <span>Échéances en retard</span>
    </div>
    <div class="alert-list">
      <?php foreach ($echeances_retard_list as $e): 
        $jours_retard = ceil((time() - strtotime($e['date_echeance'])) / 86400);
      ?>
      <div class="alert-item" style="background:#FFEBEE">
        <div class="alert-item-info">
          <div class="alert-item-nom"><?php echo htmlspecialchars($e['prenoms'] . ' ' . $e['nom']); ?></div>
          <div class="alert-item-detail"><?php echo htmlspecialchars($e['type_libelle']); ?> · <?php echo htmlspecialchars($e['numero_credit']); ?></div>
        </div>
        <div class="alert-item-date" style="color:#C62828">⚠️ Retard de <?php echo $jours_retard; ?> jours</div>
        <div class="alert-item-montant"><?php echo fmt($e['mensualite_due']); ?> F</div>
        <a href="details_credit.php?id=<?php echo $e['credit_id']; ?>" class="btn-warning">Traiter</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- FILTRES -->
  <div class="filter-card fadeUp">
    <div class="filter-title">🔍 Filtrer les crédits</div>
    <form method="GET" id="filterForm">
      <div class="filter-grid">
        <div class="filter-group">
          <label>Recherche</label>
          <input type="text" name="search" placeholder="Membre, numéro crédit..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
          <label>Type de crédit</label>
          <select name="type_credit">
            <option value="">Tous les types</option>
            <?php foreach ($types as $t): ?>
            <option value="<?php echo $t['code']; ?>" <?php echo $type_credit === $t['code'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($t['libelle']); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Statut</label>
          <select name="statut">
            <option value="">Tous les statuts</option>
            <option value="en_cours" <?php echo $statut === 'en_cours' ? 'selected' : ''; ?>>En cours</option>
            <option value="en_retard" <?php echo $statut === 'en_retard' ? 'selected' : ''; ?>>En retard</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Date début</label>
          <input type="date" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>">
        </div>
        <div class="filter-group">
          <label>Date fin</label>
          <input type="date" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>">
        </div>
        <div class="filter-group">
          <label>Limite</label>
          <select name="limit">
            <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20 par page</option>
            <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 par page</option>
            <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 par page</option>
          </select>
        </div>
      </div>
      <div class="filter-actions">
        <button type="submit" class="btn-filter">🔍 Appliquer les filtres</button>
        <a href="suivi_remboursements.php" class="btn-reset">🔄 Réinitialiser</a>
      </div>
    </form>
  </div>

  <!-- LISTE DES CRÉDITS -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        Crédits à suivre
      </span>
      <span class="card-title" style="font-size:12px;font-weight:400">
        <?php echo count($credits); ?> crédit(s) actif(s)
      </span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <?php if (empty($credits)): ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <div>Aucun crédit trouvé</div>
          <div style="font-size:12px;margin-top:8px;">Modifiez vos filtres ou réinitialisez la recherche</div>
        </div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>N° Crédit</th>
              <th>Membre</th>
              <th>Type</th>
              <th>Montant</th>
              <th>Restant</th>
              <th>Progression</th>
              <th>Prochaine échéance</th>
              <th>Statut</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($credits as $c): 
                $progress = ($c['montant_accorde'] - $c['capital_restant_du']) / $c['montant_accorde'] * 100;
                $progress_color = $c['statut'] === 'en_retard' ? '#C62828' : '#C9A84C';
                $prochaine = $c['prochaine_echeance'] ? date('d/m/Y', strtotime($c['prochaine_echeance'])) : '—';
            ?>
            <tr>
              <td style="font-family:monospace;font-size:11px"><?php echo htmlspecialchars($c['numero_credit']); ?></td>
              <td>
                <div style="font-weight:600"><?php echo htmlspecialchars($c['prenoms'] . ' ' . $c['nom']); ?></div>
                <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($c['numero_membre']); ?></div>
              </td>
              <td><?php echo htmlspecialchars($c['type_libelle']); ?></td>
              <td class="montant"><?php echo fmt($c['montant_accorde']); ?> F</td>
              <td class="montant"><?php echo fmt($c['capital_restant_du']); ?> F</td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo $progress; ?>%; background:<?php echo $progress_color; ?>"></div>
                  </div>
                  <span style="font-size:11px"><?php echo round($progress); ?>%</span>
                </div>
              </td>
              <td style="font-size:12px"><?php echo $prochaine; ?></td>
              <td>
                <span class="badge <?php echo $c['statut'] === 'en_cours' ? 'badge-cours' : 'badge-retard'; ?>">
                  <?php echo $c['statut'] === 'en_cours' ? '✅ En cours' : '⚠️ En retard'; ?>
                </span>
                <?php if ($c['nb_echeances_retard'] > 0): ?>
                <div style="font-size:10px;color:#C62828;margin-top:4px"><?php echo $c['nb_echeances_retard']; ?> échéance(s) en retard</div>
                <?php endif; ?>
              </td>
              <td>
                <a href="details_credit.php?id=<?php echo $c['id']; ?>" class="btn-detail">📋 Détails</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

</main>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}

const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>