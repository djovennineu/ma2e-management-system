<?php
require_once '../connexion/auth_check.php';
checkRole('administrateur');
require_once '../config/database.php';

// ── Filtres ──
$statut   = $_GET['statut']   ?? '';
$societe  = $_GET['societe']  ?? '';
$search   = trim($_GET['search'] ?? '');

$where  = ['1=1'];
$params = [];

if ($statut !== '') {
    $where[]  = 'c.statut = ?';
    $params[] = $statut;
}
if ($societe !== '') {
    $where[]  = 'c.societe_id = ?';
    $params[] = $societe;
}
if ($search !== '') {
    $where[]  = '(c.nom LIKE ? OR c.prenoms LIKE ? OR c.email LIKE ? OR c.matricule_pro LIKE ?)';
    $p = '%'.$search.'%';
    array_push($params, $p, $p, $p, $p);
}

$sql = "
    SELECT c.*, s.nom AS societe_nom, s.code AS societe_code
    FROM candidats c
    JOIN societes s ON c.societe_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.date_soumission DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sociétés pour le filtre
$societes = $pdo->query("SELECT id, nom, code FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Compteurs
$nb_attente  = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut='en_attente'")->fetchColumn();
$nb_accepte  = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut='accepte'")->fetchColumn();
$nb_refuse   = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut='refuse'")->fetchColumn();
$nb_total    = $pdo->query("SELECT COUNT(*) FROM candidats")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Candidatures — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:200px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--blue:#2196F3;--red:#F44336;--orange:#FF9800}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#888;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100;transition:transform .3s}
.logo{display:flex;align-items:center;gap:8px;padding:0 20px 28px;border-bottom:1px solid #333}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;cursor:pointer;transition:background .2s}
.user-card:hover{background:#2a2a2a}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#e8b84b}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}
/* MAIN */
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:20px}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:600;color:var(--text)}
.page-sub{font-size:13.5px;color:var(--text-light);margin-top:4px}
/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.stat-box{background:var(--card);border-radius:12px;padding:16px 20px;border:1px solid var(--border);display:flex;align-items:center;gap:14px;transition:transform .2s;cursor:pointer;text-decoration:none}
.stat-box:hover{transform:translateY(-2px)}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.stat-icon svg{width:20px;height:20px}
.stat-num{font-size:22px;font-weight:700;line-height:1}
.stat-lbl{font-size:11.5px;color:var(--text-light);margin-top:2px}
/* FILTRES */
.filters-bar{background:var(--card);border-radius:12px;padding:16px 20px;border:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filter-input{padding:9px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;transition:border-color .2s;outline:none}
.filter-input:focus{border-color:var(--gold)}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-light)}
.search-wrap input{width:100%;padding:9px 14px 9px 36px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;transition:border-color .2s;outline:none}
.search-wrap input:focus{border-color:var(--gold)}
.btn-reset{padding:9px 16px;background:transparent;border:1.5px solid var(--border);border-radius:9px;font-size:13px;color:var(--text-light);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-reset:hover{border-color:var(--gold);color:var(--gold)}
/* TABLE */
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:15px;font-weight:700;color:var(--text)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:11px 16px;text-align:left;font-size:11.5px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);background:var(--bg);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody tr:last-child{border-bottom:none}
td{padding:12px 16px;font-size:13.5px;vertical-align:middle}
.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-accepte{background:#E8F5E9;color:#2E7D32}
.badge-refuse{background:#FFEBEE;color:#C62828}
.user-cell{display:flex;align-items:center;gap:10px}
.user-av{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0}
.btn-view{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:var(--gold-bg);color:var(--gold);border:1px solid #e8c97a;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif}
.btn-view:hover{background:var(--gold);color:white}
.empty-state{text-align:center;padding:50px 20px;color:var(--text-light)}
.empty-state svg{width:48px;height:48px;margin-bottom:12px;opacity:.3}
/* TOPBAR MOBILE */
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.hamburger svg{width:22px;height:22px}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}
/* ANIMATIONS */
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .4s ease both}
/* RESPONSIVE */
@media(max-width:1024px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-row{grid-template-columns:1fr 1fr;gap:10px}
  .filters-bar{gap:8px}
  thead th:nth-child(4),td:nth-child(4),
  thead th:nth-child(3),td:nth-child(3){display:none}
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <img src="asset/logosansnom.png" alt="MA2E" width="75" height="45">
  <button class="hamburger" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <img src="asset/logosansnom.png" alt="MA2E" width="75" height="45">
  </div>
  <nav class="nav">
    <a class="nav-item" href="dashboardAdminstrateur.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="nav-item active" href="TraitementCAndidat.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Candidats
    </a>
    <a class="nav-item" href="membres.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Membres
    </a>
    <a class="nav-item" href="ajouter_membre.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
    Ajouter un membre
</a>
   
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
        <div class="role">Administrateur</div>
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

  <div class="page-header fadeUp">
    <div>
      <h1 class="page-title">Candidatures</h1>
      <p class="page-sub">Gérez les demandes d'adhésion à la MA2E</p>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-row fadeUp">
    <a class="stat-box" href="candidats.php">
      <div class="stat-icon" style="background:#EEF2FF;color:#3730A3">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      </div>
      <div><div class="stat-num"><?php echo $nb_total; ?></div><div class="stat-lbl">Total</div></div>
    </a>
    <a class="stat-box" href="candidats.php?statut=en_attente">
      <div class="stat-icon" style="background:#FFF8E1;color:#F57F17">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div><div class="stat-num" style="color:#F57F17"><?php echo $nb_attente; ?></div><div class="stat-lbl">En attente</div></div>
    </a>
    <a class="stat-box" href="candidats.php?statut=accepte">
      <div class="stat-icon" style="background:#E8F5E9;color:#2E7D32">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div><div class="stat-num" style="color:#2E7D32"><?php echo $nb_accepte; ?></div><div class="stat-lbl">Acceptées</div></div>
    </a>
    <a class="stat-box" href="candidats.php?statut=refuse">
      <div class="stat-icon" style="background:#FFEBEE;color:#C62828">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div><div class="stat-num" style="color:#C62828"><?php echo $nb_refuse; ?></div><div class="stat-lbl">Refusées</div></div>
    </a>
  </div>

  <!-- FILTRES -->
  <form method="GET" class="filters-bar fadeUp">
    <div class="search-wrap">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="search" placeholder="Rechercher nom, email, matricule..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <select name="statut" class="filter-input" onchange="this.form.submit()">
      <option value="">Tous les statuts</option>
      <option value="en_attente" <?php echo $statut==='en_attente'?'selected':''; ?>>En attente</option>
      <option value="accepte"    <?php echo $statut==='accepte'?'selected':''; ?>>Accepté</option>
      <option value="refuse"     <?php echo $statut==='refuse'?'selected':''; ?>>Refusé</option>
    </select>
    <select name="societe" class="filter-input" onchange="this.form.submit()">
      <option value="">Toutes les sociétés</option>
      <?php foreach ($societes as $s): ?>
        <option value="<?php echo $s['id']; ?>" <?php echo $societe==$s['id']?'selected':''; ?>>
          <?php echo htmlspecialchars($s['code'].' — '.$s['nom']); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-reset">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Filtrer
    </button>
    <?php if ($statut||$societe||$search): ?>
      <a href="candidats.php" class="btn-reset">✕ Réinitialiser</a>
    <?php endif; ?>
  </form>

  <!-- TABLEAU -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title">
        <?php echo count($candidats); ?> candidature(s) trouvée(s)
      </span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Candidat</th>
            <th>Société</th>
            <th>Matricule pro</th>
            <th>Date soumission</th>
            <th>Statut</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($candidats)): ?>
          <tr>
            <td colspan="6">
              <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                <p>Aucune candidature trouvée</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php
          $colors = ['#C9A84C','#2196F3','#4CAF50','#9C27B0','#F44336','#00BCD4'];
          foreach ($candidats as $i => $c):
            $ini = strtoupper(substr($c['nom'],0,1).substr($c['prenoms'],0,1));
            $clr = $colors[$i % count($colors)];
          ?>
          <tr>
            <td>
              <div class="user-cell">
                <div class="user-av" style="background:<?php echo $clr; ?>"><?php echo $ini; ?></div>
                <div>
                  <div style="font-weight:600"><?php echo htmlspecialchars($c['nom'].' '.$c['prenoms']); ?></div>
                  <div style="font-size:12px;color:var(--text-light)"><?php echo htmlspecialchars($c['email']); ?></div>
                </div>
              </div>
            </td>
            <td>
              <span style="background:var(--gold-bg);color:var(--gold);padding:3px 9px;border-radius:6px;font-size:12px;font-weight:600">
                <?php echo htmlspecialchars($c['societe_code']); ?>
              </span>
            </td>
            <td>
              <code style="background:var(--bg);padding:3px 8px;border-radius:5px;font-size:12px">
                <?php echo htmlspecialchars($c['matricule_pro'] ?? '—'); ?>
              </code>
            </td>
            <td style="color:var(--text-light);font-size:13px">
              <?php echo date('d/m/Y à H:i', strtotime($c['date_soumission'])); ?>
            </td>
            <td>
              <span class="badge badge-<?php echo $c['statut'] === 'en_attente' ? 'attente' : $c['statut']; ?>">
                <?php echo $c['statut'] === 'en_attente' ? 'En attente' : ($c['statut'] === 'accepte' ? 'Accepté' : 'Refusé'); ?>
              </span>
            </td>
            <td>
              <a href="ficheCandidat.php?id=<?php echo $c['id']; ?>" class="btn-view">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Voir la fiche
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>

<script>
function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('theme', theme);
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
const saved = localStorage.getItem('theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>