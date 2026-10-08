<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_credit');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$credit_id = (int)($_GET['id'] ?? 0);

if (!$credit_id) {
    header('Location: credits_en_cours.php');
    exit;
}

// Récupérer les informations du crédit
$stmt = $pdo->prepare("
    SELECT c.*, tc.libelle as type_libelle, tc.code as type_code,
           m.id as membre_id, m.nom, m.prenoms, m.numero_membre, m.email, m.telephone,
           m.salaire_net, m.quotite_cessible,
           s.nom as societe_nom, s.code as societe_code
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    JOIN societes s ON m.societe_id = s.id
    WHERE c.id = ?
");
$stmt->execute([$credit_id]);
$credit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$credit) {
    header('Location: credits_en_cours.php');
    exit;
}

// Récupérer l'échéancier
$echeancier = $pdo->prepare("
    SELECT * FROM echeanciers 
    WHERE credit_id = ? 
    ORDER BY numero_echeance ASC
");
$echeancier->execute([$credit_id]);
$echeances = $echeancier->fetchAll(PDO::FETCH_ASSOC);

// Récupérer l'historique des remboursements (mouvements)
$remboursements = $pdo->prepare("
    SELECT * FROM mouvements 
    WHERE type = 'remboursement' 
    AND commentaire LIKE ? 
    ORDER BY date_operation DESC 
    LIMIT 20
");
$remboursements->execute(['%' . $credit['numero_credit'] . '%']);
$remboursements_list = $remboursements->fetchAll(PDO::FETCH_ASSOC);

// Statistiques sur l'échéancier
$total_paye        = 0;
$total_restant     = 0;
$nb_echeances_payees    = 0;
$nb_echeances_restantes = 0;
$prochaine_echeance = null;

foreach ($echeances as $e) {
    if ($e['statut'] === 'paye') {
        $total_paye += $e['mensualite_due'];
        $nb_echeances_payees++;
    } else {
        $total_restant += $e['mensualite_due'];
        $nb_echeances_restantes++;
        if (!$prochaine_echeance) {
            $prochaine_echeance = $e;
        }
    }
}

$progression = $credit['montant_accorde'] > 0
    ? (($credit['montant_accorde'] - $credit['capital_restant_du']) / $credit['montant_accorde']) * 100
    : 0;

function fmt($n) {
    return number_format($n, 0, ',', ' ');
}

function getStatusBadge($statut) {
    switch ($statut) {
        case 'paye':            return ['class' => 'b-paye',    'text' => 'Payé'];
        case 'paye_partiellement': return ['class' => 'b-partiel', 'text' => 'Partiel'];
        case 'en_retard':       return ['class' => 'b-retard',  'text' => 'En retard'];
        default:                return ['class' => 'b-avenir',  'text' => 'À venir'];
    }
}

$initiales = strtoupper(substr($credit['prenoms'], 0, 1) . substr($credit['nom'], 0, 1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Détail du crédit — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
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

/* Action buttons */
.action-buttons{display:flex;gap:12px;flex-wrap:wrap;margin-top:5px}
.action-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;background:var(--card);
  border:1px solid var(--border);border-radius:var(--radius-sm);
  color:var(--text-2);text-decoration:none;font-size:12px;
  font-weight:500;transition:all .15s;cursor:pointer;
}
.action-btn:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-bg)}
.action-btn svg{width:13px;height:13px;stroke:currentColor}

/* ===== STATS MINI ===== */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.stat-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius-md);padding:13px 14px;
  box-shadow:var(--shadow-card);
}
.stat-val{font-size:18px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stat-val.gold{color:var(--gold)}
.stat-lbl{font-size:10px;color:var(--text-2);margin-top:3px;text-transform:uppercase;letter-spacing:.4px}

/* ===== PROGRESS BAR ===== */
.progress-box{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--radius-md);padding:16px 18px;
  box-shadow:var(--shadow-card);
}
.prog-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:11px}
.prog-title{font-size:13px;font-weight:600;color:var(--text)}
.prog-pct{font-size:14px;font-weight:700;color:var(--gold)}
.prog-bar-bg{height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin-bottom:9px}
.prog-bar-fill{height:100%;border-radius:4px;background:var(--gold);transition:width .6s ease}
.prog-footer{display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-2)}

/* ===== CARDS ===== */
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

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.info-item{background:var(--card-inner);border-radius:var(--radius-sm);padding:10px 12px;border:1px solid var(--border)}
.info-label{font-size:9.5px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.info-value{font-size:12.5px;font-weight:600;color:var(--text);word-break:break-word}
.info-value.gold{color:var(--gold)}
.info-value a{color:var(--gold);text-decoration:none}
.info-value a:hover{text-decoration:underline}

/* Avatar membre */
.membre-header{display:flex;align-items:center;gap:12px;padding:0 0 12px;margin-bottom:12px;border-bottom:1px solid var(--border)}
.membre-avatar{width:40px;height:40px;border-radius:50%;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--gold-deep);flex-shrink:0;border:2px solid var(--gold)}
.membre-name{font-size:14px;font-weight:700;color:var(--text)}
.membre-meta{font-size:11px;color:var(--text-2);margin-top:2px}

/* ===== TABLE ÉCHÉANCIER ===== */
.ech-wrap{overflow-x:auto}
table.ech{width:100%;border-collapse:collapse;font-size:12px}
table.ech th{
  text-align:left;padding:10px 13px;
  font-size:10px;font-weight:700;color:var(--text-2);
  text-transform:uppercase;letter-spacing:.5px;
  background:var(--card-inner);border-bottom:1px solid var(--border);
  white-space:nowrap;
}
table.ech td{
  padding:9px 13px;border-bottom:1px solid var(--border);
  color:var(--text);vertical-align:middle;
}
table.ech tbody tr:last-child td{border-bottom:none}
table.ech tbody tr:hover td{background:var(--card-inner)}
.montant{font-weight:600;color:var(--gold)}
.num-badge{
  display:inline-flex;align-items:center;justify-content:center;
  width:24px;height:24px;border-radius:50%;
  background:var(--card-inner);border:1px solid var(--border);
  font-size:10.5px;font-weight:600;color:var(--text-2);
}

/* ===== HISTORIQUE REMBOURSEMENTS ===== */
.histo-wrap{overflow-x:auto}
table.histo{width:100%;border-collapse:collapse;font-size:12px}
table.histo th{
  text-align:left;padding:10px 13px;
  font-size:10px;font-weight:700;color:var(--text-2);
  text-transform:uppercase;letter-spacing:.5px;
  background:var(--card-inner);border-bottom:1px solid var(--border);
  white-space:nowrap;
}
table.histo td{
  padding:9px 13px;border-bottom:1px solid var(--border);
  color:var(--text);vertical-align:middle;
}
table.histo tbody tr:last-child td{border-bottom:none}
table.histo tbody tr:hover td{background:var(--card-inner)}

/* ===== BADGES ===== */
.badge{padding:3px 9px;border-radius:20px;font-size:10px;font-weight:600;display:inline-block;white-space:nowrap}
.b-paye   {background:var(--green-bg); color:var(--green)}
.b-avenir {background:var(--blue-bg);  color:var(--blue)}
.b-retard {background:var(--red-bg);   color:var(--red)}
.b-partiel{background:var(--orange-bg);color:var(--orange)}

/* ===== BOUTON RETOUR ===== */
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

/* ===== EMPTY STATE ===== */
.empty-state{text-align:center;padding:40px;color:var(--text-2);font-size:13px}

/* ===== FADE UP ANIMATION ===== */
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fadeUp{animation:fadeUp .35s ease both}
.fadeUp:nth-child(1){animation-delay:.05s}
.fadeUp:nth-child(2){animation-delay:.1s}
.fadeUp:nth-child(3){animation-delay:.15s}
.fadeUp:nth-child(4){animation-delay:.2s}
.fadeUp:nth-child(5){animation-delay:.25s}
.fadeUp:nth-child(6){animation-delay:.3s}
.fadeUp:nth-child(7){animation-delay:.35s}

/* ===== RESPONSIVE ===== */
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar{display:flex}
  .sidebar{
    position:fixed;top:0;left:0;height:100vh;
    z-index:200;transform:translateX(-100%);transition:transform .3s;
  }
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .section-grid{grid-template-columns:1fr}
  .info-grid{grid-template-columns:1fr}
  .action-buttons{justify-content:center}
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

<!-- ===== SIDEBAR ===== -->
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
    <a class="nav-item active" href="details_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Détail crédit
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
      <button class="theme-btn"        onclick="setTheme('dark',this)">🌙 Dark</button>
    </div>
  </div>
</aside>

<!-- ===== MAIN ===== -->
<main class="main">

  <!-- Breadcrumb -->
  <div class="breadcrumb fadeUp">
    <a href="credits_en_cours.php">Crédits en cours</a>
    <span class="sep">›</span>
    <span class="cur">Détail du crédit</span>
  </div>

  <!-- Titre + Actions -->
  <div class="fadeUp">
    <div class="page-title">Détail du crédit</div>
    <div class="page-sub">
      <?php echo htmlspecialchars($credit['numero_credit']); ?>
      &nbsp;·&nbsp;
      <?php echo htmlspecialchars($credit['type_libelle']); ?>
    </div>
    
    <!-- Boutons d'action -->
    <div class="action-buttons">
      <a href="details_membre.php?id=<?php echo $credit['membre_id']; ?>" class="action-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Voir le profil du membre
      </a>
      <button onclick="exportEcheancier()" class="action-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Exporter l'échéancier (CSV)
      </button>
    </div>
  </div>

  <!-- STATS MINI -->
  <div class="stats-row fadeUp">
    <div class="stat-card">
      <div class="stat-val"><?php echo $nb_echeances_payees; ?></div>
      <div class="stat-lbl">Échéances payées</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?php echo $nb_echeances_restantes; ?></div>
      <div class="stat-lbl">Restantes</div>
    </div>
    <div class="stat-card">
      <div class="stat-val gold"><?php echo fmt($total_paye); ?> F</div>
      <div class="stat-lbl">Déjà remboursé</div>
    </div>
    <div class="stat-card">
      <div class="stat-val gold"><?php echo fmt($total_restant); ?> F</div>
      <div class="stat-lbl">Reste à payer</div>
    </div>
  </div>

  <!-- PROGRESSION -->
  <div class="progress-box fadeUp">
    <div class="prog-header">
      <div class="prog-title">Progression du remboursement</div>
      <div class="prog-pct"><?php echo round($progression); ?>% remboursé</div>
    </div>
    <div class="prog-bar-bg">
      <div class="prog-bar-fill" style="width:<?php echo $progression; ?>%"></div>
    </div>
    <div class="prog-footer">
      <span><?php echo fmt($credit['capital_restant_du']); ?> F restants</span>
      <?php if ($prochaine_echeance): ?>
        <span>Prochaine : <?php echo date('d/m/Y', strtotime($prochaine_echeance['date_echeance'])); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- INFORMATIONS : Crédit + Membre -->
  <div class="section-grid fadeUp">

    <!-- Crédit -->
    <div class="card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <span class="card-head-title">Informations du crédit</span>
      </div>
      <div class="card-body">
        <div class="info-grid">
          <div class="info-item">
            <div class="info-label">Numéro de crédit</div>
            <div class="info-value"><?php echo htmlspecialchars($credit['numero_credit']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Type de crédit</div>
            <div class="info-value"><?php echo htmlspecialchars($credit['type_libelle']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Montant accordé</div>
            <div class="info-value gold"><?php echo fmt($credit['montant_accorde']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Capital restant</div>
            <div class="info-value gold"><?php echo fmt($credit['capital_restant_du']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Taux annuel</div>
            <div class="info-value"><?php echo $credit['taux_annuel']; ?>%</div>
          </div>
          <div class="info-item">
            <div class="info-label">Mensualité</div>
            <div class="info-value"><?php echo fmt($credit['mensualite']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Durée</div>
            <div class="info-value"><?php echo $credit['duree_mois']; ?> mois</div>
          </div>
          <div class="info-item">
            <div class="info-label">Mise en place</div>
            <div class="info-value"><?php echo date('d/m/Y', strtotime($credit['date_mise_en_place'])); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Fin prévue</div>
            <div class="info-value"><?php echo date('d/m/Y', strtotime($credit['date_fin_prevue'])); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Statut</div>
            <div class="info-value">
              <?php
              if ($credit['statut'] === 'en_cours') {
                  echo '<span class="badge b-paye">En cours</span>';
              } elseif ($credit['statut'] === 'en_retard') {
                  echo '<span class="badge b-retard">En retard</span>';
              } else {
                  echo '<span class="badge b-avenir">Soldé</span>';
              }
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Membre -->
    <div class="card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <span class="card-head-title">Informations du membre</span>
      </div>
      <div class="card-body">
        <div class="membre-header">
          <div class="membre-avatar"><?php echo $initiales; ?></div>
          <div>
            <div class="membre-name"><?php echo htmlspecialchars($credit['prenoms'] . ' ' . $credit['nom']); ?></div>
            <div class="membre-meta">
              <?php echo htmlspecialchars($credit['numero_membre']); ?>
              &nbsp;·&nbsp;
              <?php echo htmlspecialchars($credit['societe_code']); ?>
            </div>
          </div>
        </div>
        <div class="info-grid">
          <div class="info-item" style="grid-column:span 2">
            <div class="info-label">Société</div>
            <div class="info-value"><?php echo htmlspecialchars($credit['societe_code'] . ' — ' . $credit['societe_nom']); ?></div>
          </div>
          <div class="info-item" style="grid-column:span 2">
            <div class="info-label">Email</div>
            <div class="info-value"><?php echo htmlspecialchars($credit['email']); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Téléphone</div>
            <div class="info-value"><?php echo htmlspecialchars($credit['telephone'] ?? 'Non renseigné'); ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Salaire net</div>
            <div class="info-value gold"><?php echo fmt($credit['salaire_net']); ?> F</div>
          </div>
          <div class="info-item">
            <div class="info-label">Quotité cessible</div>
            <div class="info-value"><?php echo fmt($credit['quotite_cessible']); ?> F</div>
          </div>
          <div class="info-item" style="grid-column:span 2">
            <div class="info-label">Lien</div>
            <div class="info-value">
              <a href="details_membre.php?id=<?php echo $credit['membre_id']; ?>">
                📋 Voir le profil complet →
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /section-grid -->

  <!-- ÉCHÉANCIER -->
  <div class="card fadeUp">
    <div class="card-head">
      <div class="card-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <span class="card-head-title">Échéancier des remboursements</span>
    </div>
    <div class="card-body" style="padding:0">
      <?php if (empty($echeances)): ?>
        <div class="empty-state">
          <div style="font-size:36px;margin-bottom:10px">📋</div>
          Aucun échéancier généré pour ce crédit.
        </div>
      <?php else: ?>
        <div class="ech-wrap">
          <table class="ech">
            <thead>
              <tr>
                <th>N°</th>
                <th>Date</th>
                <th>Capital</th>
                <th>Intérêts</th>
                <th>Mensualité</th>
                <th>Statut</th>
                <th>Paiement</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($echeances as $e):
                $badge = getStatusBadge($e['statut']);
              ?>
              <tr>
                <td><span class="num-badge"><?php echo $e['numero_echeance']; ?></span></td>
                <td><?php echo date('d/m/Y', strtotime($e['date_echeance'])); ?></td>
                <td class="montant"><?php echo fmt($e['capital_du']); ?> F</td>
                <td class="montant"><?php echo fmt($e['interet_du']); ?> F</td>
                <td class="montant"><?php echo fmt($e['mensualite_due']); ?> F</td>
                <td><span class="badge <?php echo $badge['class']; ?>"><?php echo $badge['text']; ?></span></td>
                <td style="font-size:11.5px;color:var(--text-2)">
                  <?php echo $e['date_paiement'] ? date('d/m/Y', strtotime($e['date_paiement'])) : '—'; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- HISTORIQUE DES REMBOURSEMENTS (NOUVEAU) -->
  <?php if (!empty($remboursements_list)): ?>
  <div class="card fadeUp">
    <div class="card-head">
      <div class="card-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      </div>
      <span class="card-head-title">Historique des remboursements</span>
    </div>
    <div class="card-body" style="padding:0">
      <div class="histo-wrap">
        <table class="histo">
          <thead>
            <tr>
              <th>Date</th>
              <th>Référence</th>
              <th>Montant</th>
              <th>Canal</th>
              <th>Commentaire</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($remboursements_list as $r): ?>
            <tr>
              <td><?php echo date('d/m/Y', strtotime($r['date_operation'])); ?></td>
              <td style="font-family:monospace;font-size:11px"><?php echo htmlspecialchars($r['reference']); ?></td>
              <td class="montant"><?php echo fmt($r['montant']); ?> F</td>
              <td><?php echo ucfirst($r['canal']); ?></td>
              <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($r['commentaire'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- BOUTON RETOUR -->
  <a href="credits_en_cours.php" class="btn-back fadeUp">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Retour à la liste des crédits
  </a>

</main><!-- /main -->

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

// Exporter l'échéancier en CSV
function exportEcheancier() {
  <?php if (!empty($echeances)): ?>
  const echeances = <?php echo json_encode($echeances); ?>;
  const creditNumero = "<?php echo addslashes($credit['numero_credit']); ?>";
  
  // Préparer les données CSV
  let csvContent = "Échéance;Date;Capital (F CFA);Intérêts (F CFA);Mensualité (F CFA);Statut;Date paiement\n";
  
  echeances.forEach(e => {
    const row = [
      e.numero_echeance,
      new Date(e.date_echeance).toLocaleDateString('fr-FR'),
      e.capital_du.toLocaleString('fr-FR'),
      e.interet_du.toLocaleString('fr-FR'),
      e.mensualite_due.toLocaleString('fr-FR'),
      e.statut === 'paye' ? 'Payé' : (e.statut === 'paye_partiellement' ? 'Partiel' : (e.statut === 'en_retard' ? 'En retard' : 'À venir')),
      e.date_paiement ? new Date(e.date_paiement).toLocaleDateString('fr-FR') : ''
    ];
    csvContent += row.map(cell => `"${cell}"`).join(';') + "\n";
  });
  
  // Télécharger le fichier
  const blob = new Blob(["\uFEFF" + csvContent], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  const url = URL.createObjectURL(blob);
  link.setAttribute("href", url);
  link.setAttribute("download", `echeancier_${creditNumero}.csv`);
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  <?php endif; ?>
}

// Restaurer le thème sauvegardé
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b, i) => b.classList.toggle('active', i === 1));
}
</script>
</body>
</html>