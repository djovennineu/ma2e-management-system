<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_credit');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$membre_id = (int)($_GET['id'] ?? 0);

if (!$membre_id) {
    header('Location: credits_en_cours.php');
    exit;
}

// Récupérer les informations du membre
$stmt = $pdo->prepare("
    SELECT m.*, s.nom as societe_nom, s.code as societe_code
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$stmt->execute([$membre_id]);
$membre = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$membre) {
    header('Location: credits_en_cours.php');
    exit;
}

// Récupérer les comptes du membre
$comptes = $pdo->prepare("
    SELECT ce.*, tce.code as type_code, tce.libelle as type_libelle,
           tce.plafond_solde, tce.depot_minimum
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut != 'cloture'
    ORDER BY ce.date_ouverture ASC
");
$comptes->execute([$membre_id]);
$comptes_list = $comptes->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les crédits du membre
$credits = $pdo->prepare("
    SELECT c.*, tc.libelle as type_libelle, tc.code as type_code
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    WHERE c.membre_id = ? 
    ORDER BY c.date_mise_en_place DESC
");
$credits->execute([$membre_id]);
$credits_list = $credits->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les demandes de crédit
$demandes = $pdo->prepare("
    SELECT dc.*, tc.libelle as type_libelle
    FROM demandes_credit dc
    JOIN types_credit tc ON dc.type_credit_id = tc.id
    WHERE dc.membre_id = ? 
    ORDER BY dc.date_demande DESC
    LIMIT 10
");
$demandes->execute([$membre_id]);
$demandes_list = $demandes->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les demandes de retrait
$retraits = $pdo->prepare("
    SELECT dr.* FROM demandes_retrait dr
    WHERE dr.membre_id = ? 
    ORDER BY dr.created_at DESC
    LIMIT 10
");
$retraits->execute([$membre_id]);
$retraits_list = $retraits->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$total_epargne = array_sum(array_column($comptes_list, 'solde'));
$total_credits = array_sum(array_column($credits_list, 'montant_accorde'));
$encours_credits = array_sum(array_column($credits_list, 'capital_restant_du'));
$nb_credits_actifs = count(array_filter($credits_list, fn($c) => $c['statut'] === 'en_cours'));

$categories_labels = [
    'directeur'           => 'Directeur',
    'cadre'               => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Supérieure',
    'maitrise_1_2'        => 'Maîtrise 1 et 2',
    'employe_ouvrier'     => 'Employé / Ouvrier',
];

$compte_config = [
    'expresse'  => ['emoji'=>'💰','color'=>'#C9A84C','bg'=>'#F5EDD6','label'=>'EXPRESSE'],
    'ordinaire' => ['emoji'=>'🏦','color'=>'#2196F3','bg'=>'#E3F2FD','label'=>'ORDINAIRE'],
    'logement'  => ['emoji'=>'🏠','color'=>'#4CAF50','bg'=>'#E8F5E9','label'=>'LOGEMENT'],
    'dats'      => ['emoji'=>'📈','color'=>'#9C27B0','bg'=>'#F3E5F5','label'=>'DATS'],
    'datavp'    => ['emoji'=>'📊','color'=>'#FF9800','bg'=>'#FFF3E0','label'=>'DATAVP'],
    'garantie'  => ['emoji'=>'🔒','color'=>'#F44336','bg'=>'#FFEBEE','label'=>'GARANTIE'],
    'credits'   => ['emoji'=>'💳','color'=>'#4CAF50','bg'=>'#E8F5E9','label'=>'CRÉDITS'],
];

function fmt($n) {
    return number_format($n, 0, ',', ' ');
}

function getCreditStatusBadge($statut) {
    switch ($statut) {
        case 'en_cours': return ['class' => 'b-cours', 'text' => 'En cours'];
        case 'en_retard': return ['class' => 'b-retard', 'text' => 'En retard'];
        case 'solde': return ['class' => 'b-solde', 'text' => 'Soldé'];
        default: return ['class' => 'b-avenir', 'text' => ucfirst($statut)];
    }
}

$initiales = strtoupper(substr($membre['nom'], 0, 1) . substr($membre['prenoms'], 0, 1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Détail du membre — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
<style>
/* ===== RESET & TOKENS ===== */
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#C9A84C;
  --gold-bg:#F5EDD6;
  --gold-deep:#A5732E;
  --sidebar-w:228px;
  --radius-sm:8px;
  --radius-md:10px;
  --radius-lg:14px;
  --shadow-card:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
}

/* LIGHT */
body{
  --bg:#F0EDE8;
  --card:#FFFFFF;
  --card-inner:#F7F5F2;
  --border:#E8E3DB;
  --border-strong:#D9D2C8;
  --text:#1A1A1A;
  --text-2:#6B6560;
  --text-3:#A09890;
  --green:#2E7D32; --green-bg:#E8F5E9;
  --blue:#1565C0;  --blue-bg:#E3F2FD;
  --orange:#E65100;--orange-bg:#FFF3E0;
  --red:#C62828;   --red-bg:#FFEBEE;
}
body.dark{
  --bg:#141414;
  --card:#1F1F1F;
  --card-inner:#2A2A2A;
  --border:#333;
  --border-strong:#444;
  --text:#F0F0F0;
  --text-2:#9A9490;
  --text-3:#666;
  --green:#81C784; --green-bg:#1B3A1C;
  --blue:#90CAF9;  --blue-bg:#0D253A;
  --orange:#FFB74D;--orange-bg:#3A2200;
  --red:#EF9A9A;   --red-bg:#3A1111;
}

body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .25s,color .25s}

/* ===== SIDEBAR ===== */
.sidebar{
  width:var(--sidebar-w);
  background:#111;
  display:flex;flex-direction:column;
  padding:0;flex-shrink:0;z-index:100;
  transition:transform .3s;
}
.logo{padding:22px 20px 20px;border-bottom:1px solid #252525}
.logo-wordmark{font-size:19px;font-weight:700;color:#fff;letter-spacing:1.5px}
.logo-sub{font-size:9.5px;color:var(--gold);letter-spacing:.8px;text-transform:uppercase;margin-top:3px}

.nav{flex:1;padding:14px 10px;display:flex;flex-direction:column;gap:2px;overflow-y:auto}
.nav-section{font-size:9px;font-weight:700;color:#3A3A3A;text-transform:uppercase;letter-spacing:1.2px;padding:12px 10px 5px}
.nav-item{
  display:flex;align-items:center;gap:9px;
  padding:9px 11px;border-radius:var(--radius-sm);
  color:#777;font-size:13px;font-weight:500;
  cursor:pointer;transition:all .15s;text-decoration:none;
}
.nav-item:hover{background:#1C1C1C;color:#CCC}
.nav-item.active{background:var(--gold);color:#fff}
.nav-item svg{width:14px;height:14px;flex-shrink:0;stroke:currentColor}

.sidebar-bottom{padding:14px 10px;border-top:1px solid #252525}
.user-row{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:var(--radius-sm)}
.avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.u-name{font-size:12.5px;font-weight:600;color:#E0E0E0}
.u-role{font-size:10px;color:var(--gold);margin-top:1px}

.theme-toggle{display:flex;background:#1C1C1C;border-radius:20px;padding:3px;margin-top:10px;gap:2px}
.theme-btn{flex:1;padding:5px 0;border:none;background:transparent;color:#666;font-size:11px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .15s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:#fff}

/* ===== TOPBAR MOBILE ===== */
.topbar{display:none;align-items:center;justify-content:space-between;padding:13px 18px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}

/* ===== MAIN AREA ===== */
.main{flex:1;overflow-y:auto;padding:24px 28px;display:flex;flex-direction:column;gap:18px}

/* Breadcrumb */
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--text-2)}
.breadcrumb a{color:var(--text-2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4;font-size:11px}
.breadcrumb .cur{color:var(--text);font-weight:600}

/* Page title */
.page-title{font-size:22px;font-weight:700;color:var(--text);line-height:1.25}
.page-sub{font-size:13px;color:var(--text-2);margin-top:3px}

/* Stats row */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.stat-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius-md);padding:13px 14px;
  box-shadow:var(--shadow-card);
}
.stat-val{font-size:18px;font-weight:700;color:var(--text)}
.stat-val.gold{color:var(--gold)}
.stat-lbl{font-size:10px;color:var(--text-2);margin-top:3px;text-transform:uppercase;letter-spacing:.4px}

/* Cards */
.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius-lg);overflow:hidden;
  box-shadow:var(--shadow-card);
}
.card-head{
  padding:13px 16px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.card-head-icon{
  width:26px;height:26px;border-radius:7px;
  background:var(--gold-bg);
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.card-head-icon svg{width:13px;height:13px;stroke:var(--gold-deep)}
.card-head-title{font-size:13px;font-weight:700;color:var(--text)}
.card-body{padding:14px 16px}

/* Info grid */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:var(--card-inner);border-radius:var(--radius-sm);padding:10px 12px;border:1px solid var(--border)}
.info-label{font-size:9.5px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.info-value{font-size:12.5px;font-weight:600;color:var(--text);word-break:break-word}
.info-value.gold{color:var(--gold)}

/* Avatar */
.membre-header{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.membre-avatar{width:48px;height:48px;border-radius:50%;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:var(--gold-deep);flex-shrink:0;border:2px solid var(--gold)}
.membre-name{font-size:16px;font-weight:700;color:var(--text);margin-bottom:4px}
.membre-meta{font-size:11px;color:var(--text-2)}

/* Comptes */
.comptes-list{display:flex;flex-direction:column;gap:8px}
.compte-item{display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--card-inner);border-radius:var(--radius-sm);border:1px solid var(--border)}
.compte-icon{font-size:18px}
.compte-info{flex:1}
.compte-type{font-size:12px;font-weight:600;color:var(--text)}
.compte-num{font-size:10px;color:var(--text-2)}
.compte-solde{font-size:13px;font-weight:700;color:var(--gold)}

/* Tableaux */
.table-wrap{overflow-x:auto}
table.data{width:100%;border-collapse:collapse;font-size:12px}
table.data th{
  text-align:left;padding:10px 12px;
  font-size:10px;font-weight:700;color:var(--text-2);
  text-transform:uppercase;letter-spacing:.5px;
  background:var(--card-inner);border-bottom:1px solid var(--border);
  white-space:nowrap;
}
table.data td{
  padding:8px 12px;border-bottom:1px solid var(--border);
  color:var(--text);vertical-align:middle;
}
table.data tbody tr:last-child td{border-bottom:none}
table.data tbody tr:hover td{background:var(--card-inner)}
.montant{font-weight:600;color:var(--gold)}

/* Badges */
.badge{padding:3px 9px;border-radius:20px;font-size:10px;font-weight:600;display:inline-block;white-space:nowrap}
.b-cours{background:var(--green-bg);color:var(--green)}
.b-retard{background:var(--red-bg);color:var(--red)}
.b-solde{background:var(--blue-bg);color:var(--blue)}
.b-avenir{background:var(--orange-bg);color:var(--orange)}

/* Boutons */
.btn-back{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 16px;background:var(--card);
  border:1px solid var(--border);border-radius:var(--radius-sm);
  color:var(--text-2);text-decoration:none;font-size:12.5px;
  font-weight:500;transition:all .15s;box-shadow:var(--shadow-card);
  align-self:flex-start;
}
.btn-back:hover{border-color:var(--gold);color:var(--gold)}
.btn-back svg{width:13px;height:13px;stroke:currentColor}

.btn-link{
  color:var(--gold);text-decoration:none;font-size:12px;
  display:inline-flex;align-items:center;gap:5px;
}
.btn-link:hover{text-decoration:underline}

.empty-state{text-align:center;padding:30px;color:var(--text-2);font-size:13px}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .section-grid{grid-template-columns:1fr}
  .info-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <button onclick="toggleSidebar()" style="background:none;border:none;cursor:pointer;color:var(--text);display:flex;align-items:center">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-size:17px;font-weight:700;color:var(--text)">MA2E</span>
  <div style="width:28px"></div>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-wordmark">MA2E</div>
    <div class="logo-sub">Gestionnaire Crédit</div>
  </div>
  <nav class="nav">
    <div class="nav-section">Principal</div>
    <a class="nav-item" href="dashboard_gestionnaire_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="nav-section">Crédits</div>
    <a class="nav-item" href="traiter_demande_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Traiter demandes
    </a>
    <a class="nav-item" href="credits_en_cours.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Crédits en cours
    </a>
    <a class="nav-item" href="details_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Détail crédit
    </a>
    <a class="nav-item" href="details_membre.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Détail membre
    </a>
    <a class="nav-item" href="suivi_remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Suivi remboursements
    </a>
    <div class="nav-section">Compte</div>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>
      Déconnexion
    </a>
  </nav>
  <div class="sidebar-bottom">
    <div class="user-row">
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'], 0, 1)); ?></div>
      <div>
        <div class="u-name"><?php echo htmlspecialchars($_SESSION['prenoms'] . ' ' . $_SESSION['nom']); ?></div>
        <div class="u-role">Gestionnaire Crédit</div>
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
    <a href="credits_en_cours.php">Crédits en cours</a>
    <span class="sep">›</span>
    <a href="details_credit.php">Détail crédit</a>
    <span class="sep">›</span>
    <span class="cur">Détail membre</span>
  </div>

  <div class="fadeUp">
    <div class="page-title">Détail du membre</div>
    <div class="page-sub">
      <?php echo htmlspecialchars($membre['numero_membre']); ?>
      &nbsp;·&nbsp;
      <?php echo htmlspecialchars($membre['societe_code']); ?>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-row fadeUp">
    <div class="stat-card">
      <div class="stat-val gold"><?php echo fmt($total_epargne); ?> F</div>
      <div class="stat-lbl">Épargne totale</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?php echo count($comptes_list); ?></div>
      <div class="stat-lbl">Comptes actifs</div>
    </div>
    <div class="stat-card">
      <div class="stat-val gold"><?php echo fmt($encours_credits); ?> F</div>
      <div class="stat-lbl">Encours crédits</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?php echo $nb_credits_actifs; ?></div>
      <div class="stat-lbl">Crédits en cours</div>
    </div>
  </div>

  <!-- INFOS MEMBRE + COMPTES -->
  <div class="section-grid fadeUp">

    <!-- Informations personnelles -->
    <div class="card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <span class="card-head-title">Informations personnelles</span>
      </div>
      <div class="card-body">
        <div class="membre-header">
          <div class="membre-avatar"><?php echo $initiales; ?></div>
          <div>
            <div class="membre-name"><?php echo htmlspecialchars($membre['prenoms'] . ' ' . $membre['nom']); ?></div>
            <div class="membre-meta">
              Membre depuis <?php echo date('d/m/Y', strtotime($membre['date_adhesion'])); ?>
            </div>
          </div>
        </div>
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Numéro membre</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['numero_membre']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Catégorie</div>
            <div class="info-value"><?php echo $categories_labels[$membre['categorie']] ?? ucfirst($membre['categorie']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Email</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['email']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Téléphone</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['telephone'] ?? 'Non renseigné'); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Adresse</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['adresse'] ?? 'Non renseignée'); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Ville</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['ville'] ?? 'Non renseignée'); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Société</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['societe_code'] . ' — ' . $membre['societe_nom']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Matricule pro</div>
            <div class="info-value"><?php echo htmlspecialchars($membre['matricule_pro'] ?? 'Non renseigné'); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Salaire net</div>
            <div class="info-value gold"><?php echo fmt($membre['salaire_net']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Quotité cessible</div>
            <div class="info-value"><?php echo fmt($membre['quotite_cessible']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Cotisation mensuelle</div>
            <div class="info-value"><?php echo fmt($membre['cotisation_mensuelle']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Statut</div>
            <div class="info-value">
              <span class="badge <?php echo $membre['statut'] === 'actif' ? 'b-cours' : 'b-retard'; ?>">
                <?php echo $membre['statut'] === 'actif' ? 'Actif' : ucfirst($membre['statut']); ?>
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Comptes épargne -->
    <div class="card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <span class="card-head-title">Comptes épargne</span>
      </div>
      <div class="card-body">
        <?php if (empty($comptes_list)): ?>
          <div class="empty-state">Aucun compte épargne actif</div>
        <?php else: ?>
          <div class="comptes-list">
            <?php foreach ($comptes_list as $cpt):
              $cfg = $compte_config[$cpt['type_code']] ?? ['emoji'=>'💼','color'=>'#888','bg'=>'#eee','label'=>strtoupper($cpt['type_libelle'])];
            ?>
            <div class="compte-item">
              <div class="compte-icon"><?php echo $cfg['emoji']; ?></div>
              <div class="compte-info">
                <div class="compte-type"><?php echo htmlspecialchars($cpt['type_libelle']); ?></div>
                <div class="compte-num"><?php echo htmlspecialchars($cpt['numero_compte']); ?></div>
              </div>
              <div class="compte-solde"><?php echo fmt($cpt['solde']); ?> F</div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- CRÉDITS -->
  <div class="card fadeUp">
    <div class="card-head">
      <div class="card-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      </div>
      <span class="card-head-title">Crédits du membre</span>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($credits_list)): ?>
        <div class="empty-state">Aucun crédit enregistré</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>N° Crédit</th>
                <th>Type</th>
                <th>Montant</th>
                <th>Restant</th>
                <th>Mensualité</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($credits_list as $c):
                $badge = getCreditStatusBadge($c['statut']);
              ?>
              <tr>
                <td style="font-family:monospace;font-size:11px"><?php echo htmlspecialchars($c['numero_credit']); ?></td>
                <td><?php echo htmlspecialchars($c['type_libelle']); ?></td>
                <td class="montant"><?php echo fmt($c['montant_accorde']); ?> F</td>
                <td class="montant"><?php echo fmt($c['capital_restant_du']); ?> F</td>
                <td class="montant"><?php echo fmt($c['mensualite']); ?> F</td>
                <td><span class="badge <?php echo $badge['class']; ?>"><?php echo $badge['text']; ?></span></td>
                <td><a href="details_credit.php?id=<?php echo $c['id']; ?>" class="btn-link">Voir →</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- DEMANDES DE CRÉDIT -->
  <?php if (!empty($demandes_list)): ?>
  <div class="card fadeUp">
    <div class="card-head">
      <div class="card-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <span class="card-head-title">Demandes de crédit</span>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Montant</th>
              <th>Durée</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($demandes_list as $d): ?>
            <tr>
              <td><?php echo date('d/m/Y', strtotime($d['date_demande'])); ?></td>
              <td><?php echo htmlspecialchars($d['type_libelle']); ?></td>
              <td class="montant"><?php echo fmt($d['montant_demande']); ?> F</td>
              <td><?php echo $d['duree_demandee_mois']; ?> mois</td>
              <td>
                <span class="badge <?php echo $d['statut'] === 'en_attente' ? 'b-avenir' : ($d['statut'] === 'approuve' ? 'b-cours' : 'b-solde'); ?>">
                  <?php echo $d['statut'] === 'en_attente' ? 'En attente' : ($d['statut'] === 'approuve' ? 'Approuvé' : 'Refusé'); ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- DEMANDES DE RETRAIT -->
  <?php if (!empty($retraits_list)): ?>
  <div class="card fadeUp">
    <div class="card-head">
      <div class="card-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>
      </div>
      <span class="card-head-title">Demandes de retrait</span>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Date</th>
              <th>Montant</th>
              <th>Canal</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($retraits_list as $r): ?>
            <tr>
              <td><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
              <td class="montant"><?php echo fmt($r['montant']); ?> F</td>
              <td><?php echo ucfirst($r['canal']); ?></td>
              <td>
                <span class="badge <?php echo $r['statut'] === 'en_attente' ? 'b-avenir' : ($r['statut'] === 'validee' ? 'b-cours' : 'b-retard'); ?>">
                  <?php echo $r['statut'] === 'en_attente' ? 'En attente' : ($r['statut'] === 'validee' ? 'Validée' : 'Rejetée'); ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- BOUTON RETOUR -->
  <a href="javascript:history.back()" class="btn-back fadeUp">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Retour
  </a>

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
  document.querySelectorAll('.theme-btn').forEach((b, i) => b.classList.toggle('active', i === 1));
}
</script>
</body>
</html>