<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_immobilier');
require_once '../config/database.php';

// Statistiques
// Logements disponibles
$stmt = $pdo->query("
    SELECT SUM(nombre_disponible) as total_disponibles,
           SUM(nombre_total) as total_logements
    FROM types_logement
");
$stats_logements = $stmt->fetch(PDO::FETCH_ASSOC);

// Demandes en attente
$stmt = $pdo->query("
    SELECT COUNT(*) as en_attente
    FROM demandes_logement
    WHERE statut = 'en_attente'
");
$demandes_attente = $stmt->fetchColumn();

// Demandes approuvées (crédit à déclencher)
$stmt = $pdo->query("
    SELECT COUNT(*) as approuvees
    FROM demandes_logement
    WHERE statut = 'approuve' AND credit_immobilier_id IS NULL
");
$demandes_approuvees = $stmt->fetchColumn();

// Logements attribués
$stmt = $pdo->query("
    SELECT COUNT(*) as attribues
    FROM demandes_logement
    WHERE statut = 'livre'
");
$attribues = $stmt->fetchColumn();

// Répartition par site
$sites = $pdo->query("
    SELECT s.nom, 
           SUM(tl.nombre_total) as total,
           SUM(tl.nombre_disponible) as disponibles
    FROM sites_immobiliers s
    JOIN types_logement tl ON s.id = tl.site_id
    GROUP BY s.id
")->fetchAll(PDO::FETCH_ASSOC);

$dernieres_demandes = $pdo->query("
    SELECT dl.id, dl.statut, dl.date_demande,
           m.nom, m.prenoms, m.numero_membre,
           tl.libelle AS type_logement,
           si.nom AS site_nom
    FROM demandes_logement dl
    JOIN membres m ON m.id = dl.membre_id
    JOIN types_logement tl ON tl.id = dl.type_logement_id
    JOIN sites_immobiliers si ON si.id = tl.site_id
    ORDER BY dl.date_demande DESC, dl.id DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$compte_technique_immo = null;
try {
    $stmt = $pdo->query("
        SELECT code, libelle, solde, statut
        FROM comptes_techniques
        WHERE code = 'TECH-IMMO-MA2E'
        LIMIT 1
    ");
    $compte_technique_immo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $compte_technique_immo = null;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MA2E — Gestion Immobilière</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
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
.logout-btn{width:100%;margin-top:12px;padding:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#999;font-size:12px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.2s;display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none}
.logout-btn:hover{background:rgba(255,0,0,0.15);color:#ff6b6b;border-color:rgba(255,0,0,0.2)}
.main{flex:1;padding:32px;overflow-x:hidden}
.page-header{margin-bottom:28px}
.page-header h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--text);margin-bottom:4px}
.page-header p{font-size:14px;color:var(--text-light)}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;transition:transform 0.2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-info .stat-value{font-size:28px;font-weight:700;color:var(--text);line-height:1.2}
.stat-info .stat-label{font-size:12px;color:var(--text-light);margin-top:4px}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center}
.stat-icon svg{width:24px;height:24px}
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:18px 24px;border-bottom:1px solid var(--border);font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px}
.sites-list{padding:8px 0}
.site-item{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--border)}
.site-item:last-child{border-bottom:none}
.site-name{font-weight:600;font-size:14px;color:var(--text)}
.site-stats{font-size:13px;color:var(--text-light)}
.progress-bar{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:6px;width:150px}
.progress-fill{height:100%;border-radius:3px;background:var(--gold)}
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.hamburger svg{width:22px;height:22px}
.overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150}
.overlay-mob.open{display:block}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;transition:all 0.2s}
.btn-primary{background:var(--gold);color:white}
.btn-primary:hover{background:#b8922e}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp 0.4s ease both}
@media(max-width:1024px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-grid{grid-template-columns:1fr}
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
    <a class="nav-item active" href="dashboard_gestionnaire_immo.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
    <a class="nav-item" href="gestion_logements.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Logements</a>
    <a class="nav-item" href="attributions.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Attributions</a>
    <a class="nav-item" href="../connexion/logout.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion</a>
  </nav>
  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar"><?php echo strtoupper(substr($_SESSION['nom'],0,1)); ?></div>
      <div><div class="admin-name"><?php echo htmlspecialchars($_SESSION['nom']); ?></div><div class="admin-role">Gestionnaire Immobilier</div></div>
    </div>
    <a href="../connexion/logout.php" class="logout-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion</a>
  </div>
</aside>
<main class="main">
  <div class="page-header fadeUp">
    <h1>Gestion Immobilière</h1>
    <p>Gérez les logements MA2E et les attributions aux membres</p>
  </div>

  <div class="stats-grid fadeUp">
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value"><?php echo $stats_logements['total_logements'] ?? 0; ?></div><div class="stat-label">Total logements</div></div>
      <div class="stat-icon" style="background:var(--blue-bg);color:var(--blue)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value" style="color:#22c55e"><?php echo $stats_logements['total_disponibles'] ?? 0; ?></div><div class="stat-label">Disponibles</div></div>
      <div class="stat-icon" style="background:var(--green-bg);color:var(--green)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value" style="color:#f97316"><?php echo $demandes_attente; ?></div><div class="stat-label">Demandes en attente</div></div>
      <div class="stat-icon" style="background:var(--orange-bg);color:var(--orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
    </div>
    <div class="stat-card">
      <div class="stat-info"><div class="stat-value" style="color:#3b82f6"><?php echo $demandes_approuvees; ?></div><div class="stat-label">Crédits à déclencher</div></div>
      <div class="stat-icon" style="background:var(--blue-bg);color:var(--blue)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
    </div>
  </div>

  <div class="card fadeUp">
    <div class="card-header"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Compte technique immobilier</div>
    <div class="sites-list">
      <?php if ($compte_technique_immo): ?>
      <div class="site-item">
        <div>
          <div class="site-name"><?php echo htmlspecialchars($compte_technique_immo['libelle']); ?></div>
          <div class="site-stats">Code : <?php echo htmlspecialchars($compte_technique_immo['code']); ?> · Statut : <?php echo htmlspecialchars($compte_technique_immo['statut']); ?></div>
        </div>
        <div class="site-name" style="color:var(--gold)"><?php echo number_format($compte_technique_immo['solde'], 0, ',', ' '); ?> F</div>
      </div>
      <?php else: ?>
      <div class="site-item">
        <div>
          <div class="site-name">Compte technique immobilier absent</div>
          <div class="site-stats">Exécute la migration SQL pour créer `comptes_techniques` et `TECH-IMMO-MA2E`.</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card fadeUp">
    <div class="card-header"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Répartition par site</div>
    <div class="sites-list">
      <?php foreach ($sites as $site): 
        $pourcentage = $site['total'] > 0 ? (($site['total'] - $site['disponibles']) / $site['total']) * 100 : 0;
      ?>
      <div class="site-item">
        <div>
          <div class="site-name"><?php echo htmlspecialchars($site['nom']); ?></div>
          <div class="site-stats"><?php echo $site['disponibles']; ?> disponibles / <?php echo $site['total']; ?> total</div>
          <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $pourcentage; ?>%"></div></div>
        </div>
        <a href="gestion_logements.php?site=<?php echo $site['nom']; ?>" class="btn btn-primary" style="padding:6px 12px;font-size:12px">Gérer</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card fadeUp">
    <div class="card-header"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Actions rapides</div>
    <div class="sites-list" style="display:flex;gap:16px;padding:20px">
      <a href="gestion_logements.php" class="btn btn-primary">➕ Gérer les logements</a>
      <a href="attributions.php" class="btn btn-primary">📋 Gérer les attributions</a>
    </div>
  </div>

  <div class="card fadeUp">
    <div class="card-header"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>Dernières demandes logement</div>
    <div class="sites-list">
      <?php if (empty($dernieres_demandes)): ?>
      <div class="site-item"><div class="site-stats">Aucune demande logement enregistrée.</div></div>
      <?php else: ?>
      <?php foreach ($dernieres_demandes as $demande): ?>
      <div class="site-item">
        <div>
          <div class="site-name"><?php echo htmlspecialchars($demande['prenoms'] . ' ' . $demande['nom']); ?></div>
          <div class="site-stats"><?php echo htmlspecialchars($demande['numero_membre']); ?> · <?php echo htmlspecialchars($demande['type_logement']); ?> · <?php echo htmlspecialchars($demande['site_nom']); ?></div>
        </div>
        <div class="site-stats"><?php echo htmlspecialchars($demande['statut']); ?></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</main>
</div>

<script>
function toggleNav() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay-mob').classList.toggle('open');
}
</script>
</body>
</html>
