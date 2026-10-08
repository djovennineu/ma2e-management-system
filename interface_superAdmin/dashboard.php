<?php
require_once '../connexion/auth_check.php';
checkRole('super_admin');
require_once '../config/database.php';

// ============================================
// STATISTIQUES RÉELLES MA2E
// ============================================

// Total membres
$stmt = $pdo->query("SELECT COUNT(*) FROM membres");
$totalMembres = $stmt->fetchColumn();

// Membres actifs
$stmt = $pdo->query("SELECT COUNT(*) FROM membres WHERE statut = 'actif'");
$membresActifs = $stmt->fetchColumn();

// Membres suspendus/désactivés
$stmt = $pdo->query("SELECT COUNT(*) FROM membres WHERE statut IN ('suspendu', 'desactive', 'sorti')");
$membresInactifs = $stmt->fetchColumn();

// Total sociétés
$stmt = $pdo->query("SELECT COUNT(*) FROM societes");
$totalSocietes = $stmt->fetchColumn();

// Total utilisateurs internes (hors super_admin et membres)
$stmt = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role NOT IN ('super_admin', 'membre')");
$totalUtilisateurs = $stmt->fetchColumn();

// Crédits en cours
$stmt = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut = 'en_cours'");
$creditsEnCours = $stmt->fetchColumn();

// Crédits en retard
$stmt = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut = 'en_retard'");
$creditsEnRetard = $stmt->fetchColumn();

// Montant total des crédits accordés
$stmt = $pdo->query("SELECT SUM(montant_accorde) FROM credits WHERE statut IN ('en_cours', 'en_retard')");
$totalCreditsAccordes = $stmt->fetchColumn() ?? 0;

// Capital restant dû total
$stmt = $pdo->query("SELECT SUM(capital_restant_du) FROM credits WHERE statut IN ('en_cours', 'en_retard')");
$totalCapitalRestant = $stmt->fetchColumn() ?? 0;

// Épargne totale (tous comptes)
$stmt = $pdo->query("SELECT SUM(solde) FROM comptes_epargne WHERE statut = 'actif'");
$totalEpargne = $stmt->fetchColumn() ?? 0;

// Demandes de crédit en attente
$stmt = $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut = 'en_attente'");
$demandesCreditAttente = $stmt->fetchColumn();

// Demandes de retrait en attente
$stmt = $pdo->query("SELECT COUNT(*) FROM demandes_retrait WHERE statut = 'en_attente'");
$demandesRetraitAttente = $stmt->fetchColumn();

// Candidatures en attente
$stmt = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut = 'en_attente'");
$candidaturesAttente = $stmt->fetchColumn();

// ============================================
// ÉVOLUTION MENSUELLE (derniers 6 mois)
// ============================================
$evolutionMembres = [];
$evolutionCredits = [];

for ($i = 5; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $annee = substr($mois, 0, 4);
    $moisNum = substr($mois, 5, 2);
    
    // Membres inscrits ce mois
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE DATE_FORMAT(date_adhesion, '%Y-%m') = ?");
    $stmt->execute([$mois]);
    $evolutionMembres[] = $stmt->fetchColumn();
    
    // Crédits accordés ce mois
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM credits WHERE DATE_FORMAT(date_mise_en_place, '%Y-%m') = ?");
    $stmt->execute([$mois]);
    $evolutionCredits[] = $stmt->fetchColumn();
}

$moisLabels = ['J-5', 'J-4', 'J-3', 'J-2', 'Mois dernier', 'Ce mois'];

// ============================================
// RÉPARTITION PAR CATÉGORIE
// ============================================
$categories = [
    'directeur' => 'Directeur',
    'cadre' => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Sup.',
    'maitrise_1_2' => 'Maîtrise 1&2',
    'employe_ouvrier' => 'Employé/Ouvrier'
];

$repartitionCategories = [];
foreach ($categories as $key => $label) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE categorie = ?");
    $stmt->execute([$key]);
    $repartitionCategories[$label] = $stmt->fetchColumn();
}

// ============================================
// RÉPARTITION PAR SOCIÉTÉ
// ============================================
$stmt = $pdo->query("
    SELECT s.nom, s.code, COUNT(m.id) as nb_membres
    FROM societes s
    LEFT JOIN membres m ON m.societe_id = s.id
    GROUP BY s.id
    ORDER BY nb_membres DESC
    LIMIT 5
");
$societesRepartition = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MA2E — Dashboard Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--gold:#C9A84C;--gold-bg:#FBF5E6;--dark:#1a1a2e;--bg:#F4F2EE;--card:#ffffff;--text:#1e1e2e;--text-light:#7a7a8c;--border:#E8E4DC;--green:#22c55e;--green-bg:#f0fdf4;--red:#ef4444;--red-bg:#fef2f2;--blue:#3b82f6;--blue-bg:#eff6ff;--orange:#f97316;--orange-bg:#fff7ed}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;position:sticky;top:0;height:100vh;transition:transform 0.3s ease;z-index:200}
.logo{display:flex;align-items:center;gap:10px;padding:0 20px 24px;border-bottom:1px solid rgba(255,255,255,0.08)}
.logo-icon{width:34px;height:34px;background:var(--gold);border-radius:9px;display:flex;align-items:center;justify-content:center}
.logo-icon svg{width:18px;height:18px;fill:white}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.nav{flex:1;padding:18px 12px;display:flex;flex-direction:column;gap:3px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:#777;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all 0.2s}
.nav-item:hover{background:rgba(255,255,255,0.05);color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-footer{padding:16px;border-top:1px solid rgba(255,255,255,0.08)}
.admin-info{display:flex;align-items:center;gap:10px}
.admin-avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white}
.admin-name{font-size:13px;font-weight:600;color:white}
.admin-role{font-size:11px;color:#e8b84b}
.logout-btn{width:100%;margin-top:12px;padding:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#999;font-size:12px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.2s;display:flex;align-items:center;justify-content:center;gap:6px}
.logout-btn:hover{background:rgba(255,0,0,0.15);color:#ff6b6b;border-color:rgba(255,0,0,0.2)}
.main{flex:1;padding:32px;overflow-x:hidden}
.page-header{margin-bottom:28px}
.page-header h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--text);margin-bottom:4px}
.page-header p{font-size:14px;color:var(--text-light)}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;transition:transform 0.2s,box-shadow 0.2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.06)}
.stat-info .stat-value{font-size:28px;font-weight:700;color:var(--text);line-height:1.2}
.stat-info .stat-label{font-size:12px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:24px;height:24px}
.charts-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.chart-card{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:20px}
.chart-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.small-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.small-stat-card{background:var(--card);border-radius:12px;padding:16px;border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.small-stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.small-stat-icon svg{width:18px;height:18px}
.small-stat-info .small-stat-value{font-size:20px;font-weight:700;color:var(--text)}
.small-stat-info .small-stat-label{font-size:11px;color:var(--text-light)}
.societes-card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.societes-card .card-header{padding:18px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px}
.societes-list{padding:8px 0}
.societe-item{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--border)}
.societe-item:last-child{border-bottom:none}
.societe-name{font-weight:600;font-size:14px;color:var(--text)}
.societe-count{background:var(--gold-bg);color:var(--gold);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.hamburger svg{width:22px;height:22px}
.overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150}
.overlay-mob.open{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp 0.4s ease both}
@media(max-width:1024px){.stats-grid{grid-template-columns:repeat(2,1fr)}.charts-row{grid-template-columns:1fr}}
@media(max-width:768px){
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:1fr}
  .small-stats{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:8px">
    <div style="width:28px;height:28px;background:var(--gold);border-radius:7px;display:flex;align-items:center;justify-content:center"><svg viewBox="0 0 24 24" fill="white" width="16" height="16"><path d="M3 12l9-9 9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9"/></svg></div>
    <span style="font-family:'Playfair Display',serif;font-weight:600;font-size:17px">MA2E</span>
  </div>
  <button class="hamburger" onclick="toggleNav()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
</div>
<div class="overlay-mob" id="overlay-mob" onclick="toggleNav()"></div>
<div class="layout">
<aside class="sidebar" id="sidebar">
  <div class="logo"><div class="logo-icon"><svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9"/></svg></div><span class="logo-text">MA2E</span></div>
  <nav class="nav">
    <a class="nav-item active" href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Tableau de bord</a>
    <a class="nav-item" href="gestion_utilisateurs.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Utilisateurs</a>
    <a class="nav-item" href="gestion_societes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Sociétés</a>
        <a class="nav-item" href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>Rapports</a>
    <a class="nav-item" href="parametres.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>Paramètres</a>
    <a class="nav-item" href="../connexion/logout.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion</a>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['nom'],0,1)); ?></div>
      <div><div class="admin-name"><?php echo htmlspecialchars($_SESSION['nom']); ?></div><div class="admin-role">Super Admin</div></div>
    </div>
    <a href="../connexion/logout.php"><button class="logout-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion</button></a>
  </div>
</aside>
<main class="main">
  <div class="page-header fadeUp">
    <h1>Tableau de bord MA2E</h1>
    <p>Vue d'ensemble de l'activité de la mutuelle</p>
  </div>

  <!-- STATS PRINCIPALES -->
  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value"><?php echo number_format($totalMembres, 0, ',', ' '); ?></div><div class="stat-label">Membres</div></div>
      <div class="stat-icon" style="background:var(--blue-bg);color:var(--blue)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value"><?php echo number_format($totalEpargne, 0, ',', ' '); ?> F</div><div class="stat-label">Épargne totale</div></div>
      <div class="stat-icon" style="background:var(--green-bg);color:var(--green)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value"><?php echo number_format($totalCreditsAccordes, 0, ',', ' '); ?> F</div><div class="stat-label">Crédits accordés</div></div>
      <div class="stat-icon" style="background:var(--gold-bg);color:var(--gold)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value"><?php echo number_format($totalCapitalRestant, 0, ',', ' '); ?> F</div><div class="stat-label">Capital restant dû</div></div>
      <div class="stat-icon" style="background:var(--red-bg);color:var(--red)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg></div>
    </div>
  </div>

  <!-- ALERTES & DEMANDES -->
  <div class="small-stats fadeUp">
    <div class="small-stat-card">
      <div class="small-stat-icon" style="background:var(--orange-bg);color:var(--orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
      <div class="small-stat-info"><div class="small-stat-value"><?php echo $creditsEnRetard; ?></div><div class="small-stat-label">Crédits en retard</div></div>
    </div>
    <div class="small-stat-card">
      <div class="small-stat-icon" style="background:var(--blue-bg);color:var(--blue)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
      <div class="small-stat-info"><div class="small-stat-value"><?php echo $demandesCreditAttente; ?></div><div class="small-stat-label">Demandes crédit</div></div>
    </div>
    <div class="small-stat-card">
      <div class="small-stat-icon" style="background:var(--green-bg);color:var(--green)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
      <div class="small-stat-info"><div class="small-stat-value"><?php echo $candidaturesAttente; ?></div><div class="small-stat-label">Candidatures</div></div>
    </div>
  </div>

  <!-- GRAPHIQUES -->
  <div class="charts-row fadeUp">
    <div class="chart-card">
      <div class="chart-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>Évolution des inscriptions</div>
      <canvas id="membersChart" height="200"></canvas>
    </div>
    <div class="chart-card">
      <div class="chart-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>Évolution des crédits accordés</div>
      <canvas id="creditsChart" height="200"></canvas>
    </div>
  </div>

  <div class="charts-row fadeUp">
    <div class="chart-card">
      <div class="chart-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>Répartition par catégorie</div>
      <canvas id="categoriesChart" height="200"></canvas>
    </div>
    <div class="societes-card">
      <div class="card-header"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Top sociétés par nombre de membres</div>
      <div class="societes-list">
        <?php foreach($societesRepartition as $s): ?>
        <div class="societe-item">
          <span class="societe-name"><?php echo htmlspecialchars($s['nom']); ?></span>
          <span class="societe-count"><?php echo $s['nb_membres']; ?> membres</span>
        </div>
        <?php endforeach; ?>
        <?php if(empty($societesRepartition)): ?>
        <div class="societe-item"><span class="societe-name">Aucune société</span><span class="societe-count">0</span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</div>

<script>
function toggleNav() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay-mob').classList.toggle('open');
}

// Graphique membres
const ctx1 = document.getElementById('membersChart').getContext('2d');
new Chart(ctx1, {
  type: 'line',
  data: {
    labels: <?php echo json_encode($moisLabels); ?>,
    datasets: [{
      label: 'Nouveaux membres',
      data: <?php echo json_encode($evolutionMembres); ?>,
      borderColor: '#C9A84C',
      backgroundColor: 'rgba(201, 168, 76, 0.1)',
      tension: 0.3,
      fill: true,
      pointBackgroundColor: '#C9A84C',
      pointBorderColor: '#fff',
      pointRadius: 4,
      pointHoverRadius: 6
    }]
  },
  options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
});

// Graphique crédits
const ctx2 = document.getElementById('creditsChart').getContext('2d');
new Chart(ctx2, {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($moisLabels); ?>,
    datasets: [{
      label: 'Crédits accordés',
      data: <?php echo json_encode($evolutionCredits); ?>,
      backgroundColor: '#C9A84C',
      borderRadius: 6
    }]
  },
  options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
});

// Graphique catégories
const ctx3 = document.getElementById('categoriesChart').getContext('2d');
new Chart(ctx3, {
  type: 'doughnut',
  data: {
    labels: <?php echo json_encode(array_keys($repartitionCategories)); ?>,
    datasets: [{
      data: <?php echo json_encode(array_values($repartitionCategories)); ?>,
      backgroundColor: ['#C9A84C', '#3b82f6', '#22c55e', '#f97316', '#8b5cf6'],
      borderWidth: 0
    }]
  },
  options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
});
</script>
</body>
</html>