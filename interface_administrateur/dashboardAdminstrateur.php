<?php
require_once '../connexion/auth_check.php';
checkRole('administrateur');
require_once '../config/database.php'; // ← ajouter cette ligne

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Administrateur — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
  --gold: #C9A84C;
  --gold-light: #E8D5A3;
  --gold-bg: #F5EDD6;
  --dark: #1a1a1a;
  --dark2: #2a2a2a;
  --sidebar-w: 200px;
  --white: #ffffff;
  --bg: #F0EDE8;
  --text: #333;
  --text-light: #888;
  --card: #ffffff;
  --border: #e8e3db;
  --green: #4CAF50;
  --blue: #2196F3;
  --yellow: #FFC107;
  --red: #F44336;
}
.dark {
  --bg: #181818;
  --card: #242424;
  --text: #f0f0f0;
  --text-light: #888;
  --border: #333;
  --white: #242424;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--text);
  display: flex;
  height: 100vh;
  overflow: hidden;
  transition: background 0.3s, color 0.3s;
}
/* ── SIDEBAR ── */
.sidebar {
  width: var(--sidebar-w);
  background: var(--dark);
  display: flex;
  flex-direction: column;
  padding: 24px 0;
  flex-shrink: 0;
  transition: transform 0.3s ease, background 0.3s;
  z-index: 100;
}
.logo {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 20px 28px;
  border-bottom: 1px solid #333;
}
.nav { flex: 1; padding: 20px 12px; display: flex; flex-direction: column; gap: 4px; }
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  color: #999;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  text-decoration: none;
}
.nav-item:hover { background: #2a2a2a; color: #ccc; }
.nav-item.active { background: var(--gold); color: white; }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.sidebar-bottom { padding: 16px 12px; border-top: 1px solid #333; }
.user-card {
  display: flex; align-items: center; gap: 10px;
  padding: 10px;
  border-radius: 10px;
  cursor: pointer;
  transition: background 0.2s;
}
.user-card:hover { background: #2a2a2a; }
.avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: var(--gold);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: white;
  flex-shrink: 0;
}
.user-info .name { font-size: 13px; font-weight: 600; color: white; }
.user-info .status {
  font-size: 11px; color: #4CAF50;
  display: flex; align-items: center; gap: 4px;
}
.user-info .status::before {
  content: '';
  width: 6px; height: 6px;
  background: #4CAF50;
  border-radius: 50%;
  display: inline-block;
}
.theme-toggle {
  display: flex;
  background: #2a2a2a;
  border-radius: 20px;
  padding: 3px;
  margin-top: 12px;
  gap: 2px;
}
.theme-btn {
  flex: 1; padding: 6px 0;
  border: none; background: transparent;
  color: #777; font-size: 12px; font-weight: 500;
  border-radius: 16px; cursor: pointer;
  transition: all 0.2s;
  font-family: 'DM Sans', sans-serif;
}
.theme-btn.active { background: var(--gold); color: white; }

/* ── MAIN ── */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.content {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 0;
  overflow: hidden;
}

/* ── CENTER ── */
.center {
  padding: 28px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.page-title {
  font-family: 'Playfair Display', serif;
  font-size: 26px;
  font-weight: 600;
  color: var(--text);
  animation: fadeUp 0.4s ease both;
}

/* Welcome Banner */
.welcome-banner {
  background: var(--gold);
  border-radius: 16px;
  padding: 24px 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: relative;
  overflow: hidden;
  animation: fadeUp 0.4s 0.1s ease both;
}
.welcome-banner::before {
  content: '';
  position: absolute;
  top: -30px; right: 120px;
  width: 120px; height: 120px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
}
.welcome-banner::after {
  content: '';
  position: absolute;
  bottom: -40px; right: 200px;
  width: 160px; height: 160px;
  background: rgba(255,255,255,0.06);
  border-radius: 50%;
}
.welcome-text h2 { font-size: 20px; font-weight: 700; color: white; margin-bottom: 6px; }
.welcome-text p { font-size: 13px; color: rgba(255,255,255,0.8); margin-bottom: 16px; }
.banner-btns { display: flex; gap: 10px; }
.btn-outline {
  padding: 8px 16px;
  border: 1.5px solid rgba(255,255,255,0.7);
  background: transparent;
  color: white;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'DM Sans', sans-serif;
  text-decoration: none;
  display: inline-block;
}
.btn-outline:hover { background: rgba(255,255,255,0.15); }
.welcome-avatar {
  width: 80px; height: 80px; border-radius: 50%;
  background: rgba(255,255,255,0.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; font-weight: 700; color: white;
  border: 3px solid rgba(255,255,255,0.4);
  flex-shrink: 0;
  z-index: 1;
}

/* Stats */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  animation: fadeUp 0.4s 0.2s ease both;
}
.stat-card {
  background: var(--card);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid var(--border);
  transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
.stat-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.stat-icon svg { width: 20px; height: 20px; }
.stat-icon.gold { background: var(--gold-bg); color: var(--gold); }
.stat-icon.blue { background: #E3F2FD; color: #2196F3; }
.stat-icon.green { background: #E8F5E9; color: #4CAF50; }
.stat-icon.red { background: #FFF3E0; color: #FF9800; }
.stat-num { font-size: 22px; font-weight: 700; color: var(--text); line-height: 1; }
.stat-label { font-size: 11.5px; color: var(--text-light); margin-top: 2px; }

/* Team Section */
.team-section {
  background: var(--card);
  border-radius: 16px;
  padding: 22px;
  border: 1px solid var(--border);
  animation: fadeUp 0.4s 0.3s ease both;
}
.section-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 20px;
}
.section-title { font-size: 15px; font-weight: 700; color: var(--text); }
.section-sub { font-size: 13px; color: var(--text-light); }
.team-layout { display: flex; gap: 28px; align-items: center; }

/* Donut Chart */
.donut-wrap { position: relative; flex-shrink: 0; }
.donut-label {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
}
.donut-label .num { font-size: 24px; font-weight: 700; color: var(--text); }
.donut-label .sub { font-size: 10px; color: var(--text-light); }
.team-list { flex: 1; display: flex; flex-direction: column; gap: 12px; }
.team-item { display: flex; flex-direction: column; gap: 5px; }
.team-item-header { display: flex; align-items: center; justify-content: space-between; }
.team-name { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; color: var(--text); }
.team-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.team-count { font-size: 13px; font-weight: 600; color: var(--text); }
.progress-bar { height: 5px; background: var(--border); border-radius: 10px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 10px; transition: width 1s ease; }

/* ── RIGHT PANEL ── */
.right-panel {
  background: var(--card);
  border-left: 1px solid var(--border);
  padding: 28px 20px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.panel-title {
  font-family: 'Playfair Display', serif;
  font-size: 18px;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 4px;
}
.activity-section { display: flex; flex-direction: column; gap: 10px; }
.activity-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.activity-label { font-size: 13px; font-weight: 600; color: var(--text); }
.view-all { font-size: 12px; color: var(--gold); cursor: pointer; font-weight: 500; }
.view-all:hover { text-decoration: underline; }

/* Event Card */
.event-card {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 10px 12px;
  border-radius: 10px;
  background: var(--bg);
  border: 1px solid var(--border);
  transition: transform 0.2s;
  cursor: pointer;
  animation: fadeUp 0.4s ease both;
}
.event-card:hover { transform: translateX(3px); }
.event-date { text-align: center; flex-shrink: 0; width: 36px; }
.event-date .day { font-size: 17px; font-weight: 700; color: var(--gold); line-height: 1; }
.event-date .month { font-size: 10px; color: var(--text-light); text-transform: uppercase; font-weight: 600; }
.event-info .title { font-size: 12.5px; font-weight: 600; color: var(--text); }
.event-info .time { font-size: 11px; color: var(--text-light); margin-top: 2px; }

/* Shoutouts */
.shoutout-item {
  display: flex; gap: 10px; align-items: flex-start;
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
  animation: fadeUp 0.4s ease both;
}
.shoutout-item:last-child { border-bottom: none; }
.shoutout-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: white;
  flex-shrink: 0;
}
.shoutout-info .sname { font-size: 12.5px; font-weight: 600; color: var(--text); }
.shoutout-info .smsg { font-size: 11.5px; color: var(--text-light); margin-top: 1px; }

/* Candidats en attente */
.candidats-section {
  background: var(--card);
  border-radius: 16px;
  padding: 22px;
  border: 1px solid var(--border);
  animation: fadeUp 0.4s 0.4s ease both;
}
.badge {
  display: inline-flex; align-items: center;
  padding: 3px 10px; border-radius: 20px;
  font-size: 11.5px; font-weight: 600;
}
.badge-attente { background: #FFF8E1; color: #F57F17; }
.badge-accepte { background: #E8F5E9; color: #2E7D32; }
.badge-refuse  { background: #FFEBEE; color: #C62828; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 14px; text-align: left;
  font-size: 11.5px; font-weight: 700;
  color: var(--text-light); text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 2px solid var(--border);
  background: var(--bg);
}
tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
tbody tr:hover { background: var(--bg); }
tbody tr:last-child { border-bottom: none; }
td { padding: 11px 14px; font-size: 13px; vertical-align: middle; }

/* Animations */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Scrollbar */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* ── TOPBAR MOBILE ── */
.hamburger { display: none; background: none; border: none; cursor: pointer; padding: 8px; color: var(--text); }
.hamburger svg { width: 22px; height: 22px; }
.topbar {
  display: none; align-items: center; justify-content: space-between;
  padding: 14px 20px; background: var(--card); border-bottom: 1px solid var(--border);
}
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
.overlay.open { display: block; }

/* ── RESPONSIVE ── */
@media (max-width: 1024px) {
  .content { grid-template-columns: 1fr; }
  .right-panel { border-left: none; border-top: 1px solid var(--border); overflow-y: visible; }
  .center { overflow-y: visible; }
  body { overflow-y: auto; height: auto; }
  .main { overflow: visible; }
  .content { overflow: visible; }
}
@media (max-width: 768px) {
  body { flex-direction: column; height: auto; overflow-y: auto; }
  .topbar { display: flex; position: sticky; top: 0; z-index: 50; }
  .hamburger { display: flex; }
  .sidebar {
    position: fixed; top: 0; left: 0;
    width: 260px; height: 100vh; z-index: 200;
    transform: translateX(-100%); overflow-y: auto;
    box-shadow: 4px 0 20px rgba(0,0,0,0.3);
  }
  .sidebar.open { transform: translateX(0); }
  .overlay { z-index: 150; }
  .main { flex: 1; }
  .content { grid-template-columns: 1fr; overflow: visible; }
  .center { padding: 16px; gap: 16px; overflow-y: visible; }
  .welcome-banner { flex-direction: column; align-items: flex-start; gap: 16px; padding: 20px; }
  .welcome-avatar { display: none; }
  .banner-btns { flex-wrap: wrap; gap: 8px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .team-layout { flex-direction: column; align-items: center; }
  .team-list { width: 100%; }
  .right-panel { border-left: none; border-top: 1px solid var(--border); padding: 16px; overflow-y: visible; }
}
@media (max-width: 400px) {
  .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .stat-num { font-size: 18px; }
}
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <img src="asset/logosansnom.png" alt="Logo MA2E" width="75" height="45">
  <button class="hamburger" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
</div>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <img src="asset/logosansnom.png" alt="Logo MA2E" width="75" height="45">
  </div>
  <nav class="nav">
    <a class="nav-item active" href="dashboardAdminstrateur.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="nav-item" href="TraitementCAndidat.php">
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
      <div class="avatar"><?php echo strtoupper(substr($_SESSION['nom'], 0, 1)); ?></div>
      <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($_SESSION['nom'] . ' ' . $_SESSION['prenoms']); ?></div>
        <div class="status">Active</div>
      </div>
    </div>
    <div class="theme-toggle">
      <button class="theme-btn active" onclick="setTheme('light', this)">☀ Light</button>
      <button class="theme-btn" onclick="setTheme('dark', this)">🌙 Dark</button>
    </div>
  </div>
</aside>

<!-- MAIN -->
<main class="main">
  <div class="content">

    <!-- ── CENTER ── -->
    <div class="center">
      <h1 class="page-title">Dashboard Administrateur</h1>

      <!-- Welcome Banner -->
      <div class="welcome-banner">
        <div class="welcome-text">
          <h2>Bienvenue, <?php echo htmlspecialchars($_SESSION['prenoms'] . ' ' . $_SESSION['nom']); ?> !</h2>
          <p>Voici un aperçu de l'activité MA2E aujourd'hui.</p>
          <div class="banner-btns">
            <a href="candidats.php" class="btn-outline">Voir les candidats</a>
            <a href="membres.php" class="btn-outline">Voir les membres</a>
          </div>
        </div>
        <div class="welcome-avatar"><?php echo strtoupper(substr($_SESSION['prenoms'], 0, 1) . substr($_SESSION['nom'], 0, 1)); ?></div>
      </div>

      <!-- Stats dynamiques depuis la base -->
      <?php
        $nb_candidats_attente = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut = 'en_attente'")->fetchColumn();
        $nb_membres_actifs    = $pdo->query("SELECT COUNT(*) FROM membres WHERE statut = 'actif'")->fetchColumn();
        $nb_credits_encours   = $pdo->query("SELECT COUNT(*) FROM credits WHERE statut = 'en_cours'")->fetchColumn();
        $nb_candidats_total   = $pdo->query("SELECT COUNT(*) FROM candidats")->fetchColumn();
      ?>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon gold">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          </div>
          <div>
            <div class="stat-num"><?php echo $nb_membres_actifs; ?></div>
            <div class="stat-label">Membres actifs</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/></svg>
          </div>
          <div>
            <div class="stat-num"><?php echo $nb_candidats_total; ?></div>
            <div class="stat-label">Total candidats</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </div>
          <div>
            <div class="stat-num" style="color:var(--yellow)"><?php echo $nb_candidats_attente; ?></div>
            <div class="stat-label">En attente</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          </div>
          <div>
            <div class="stat-num"><?php echo $nb_credits_encours; ?></div>
            <div class="stat-label">Crédits en cours</div>
          </div>
        </div>
      </div>

      <!-- Derniers candidats en attente -->
      <?php
        $derniers = $pdo->query("
          SELECT c.*, s.code as societe_code
          FROM candidats c
          JOIN societes s ON c.societe_id = s.id
          WHERE c.statut = 'en_attente'
          ORDER BY c.date_soumission DESC
          LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
      ?>
      <div class="candidats-section">
        <div class="section-header">
          <div>
            <div class="section-title">Candidatures en attente</div>
            <div class="section-sub"><?php echo $nb_candidats_attente; ?> demande(s) à traiter</div>
          </div>
          <a href="candidats.php" style="font-size:12px;color:var(--gold);font-weight:600;text-decoration:none;">Voir tout →</a>
        </div>
        <?php if (empty($derniers)): ?>
          <p style="text-align:center;color:var(--text-light);padding:20px 0;font-size:13px;">Aucune candidature en attente.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table>
            <thead>
              <tr>
                <th>Candidat</th>
                <th>Société</th>
                <th>Date</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($derniers as $c): ?>
              <tr>
                <td>
                  <div style="font-weight:600"><?php echo htmlspecialchars($c['nom'] . ' ' . $c['prenoms']); ?></div>
                  <div style="font-size:12px;color:var(--text-light)"><?php echo htmlspecialchars($c['email']); ?></div>
                </td>
                <td><span style="background:var(--gold-bg);color:var(--gold);padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600"><?php echo htmlspecialchars($c['societe_code']); ?></span></td>
                <td style="color:var(--text-light);font-size:12px"><?php echo date('d/m/Y', strtotime($c['date_soumission'])); ?></td>
                <td><span class="badge badge-attente">En attente</span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- fin .center -->

    <!-- ── RIGHT PANEL ── -->
    <div class="right-panel">
      <div>
        <div class="panel-title">Activité</div>
        <div style="font-size:12px;color:var(--text-light)"><?php echo date('d F Y'); ?></div>
      </div>

      <!-- Répartition par société -->
      <?php
        $societes_stats = $pdo->query("
          SELECT s.code, s.nom, COUNT(m.id) as nb
          FROM societes s
          LEFT JOIN membres m ON m.societe_id = s.id AND m.statut = 'actif'
          GROUP BY s.id
          ORDER BY nb DESC
          LIMIT 4
        ")->fetchAll(PDO::FETCH_ASSOC);
        $total_membres = array_sum(array_column($societes_stats, 'nb'));
        $colors = ['#C9A84C','#2196F3','#4CAF50','#F44336'];
      ?>
      <div class="activity-section">
        <div class="activity-header">
          <span class="activity-label">Membres par société</span>
        </div>
        <div class="team-list">
          <?php foreach ($societes_stats as $i => $s): ?>
          <div class="team-item">
            <div class="team-item-header">
              <span class="team-name">
                <span class="team-dot" style="background:<?php echo $colors[$i % 4]; ?>"></span>
                <?php echo htmlspecialchars($s['code']); ?>
              </span>
              <span class="team-count"><?php echo $s['nb']; ?></span>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?php echo $total_membres > 0 ? round(($s['nb']/$total_membres)*100) : 0; ?>%;background:<?php echo $colors[$i % 4]; ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Dernières actions -->
      <div class="activity-section">
        <div class="activity-header">
          <span class="activity-label">Dernières candidatures</span>
          <span class="view-all" onclick="location.href='candidats.php'">Voir tout</span>
        </div>
        <?php
          $recents = $pdo->query("
            SELECT nom, prenoms, statut, date_soumission
            FROM candidats ORDER BY date_soumission DESC LIMIT 4
          ")->fetchAll(PDO::FETCH_ASSOC);
          $av_colors = ['#C9A84C','#2196F3','#4CAF50','#9C27B0'];
        ?>
        <?php foreach ($recents as $i => $r): ?>
        <div class="shoutout-item">
          <div class="shoutout-avatar" style="background:<?php echo $av_colors[$i % 4]; ?>">
            <?php echo strtoupper(substr($r['nom'],0,1).substr($r['prenoms'],0,1)); ?>
          </div>
          <div class="shoutout-info">
            <div class="sname"><?php echo htmlspecialchars($r['nom'].' '.$r['prenoms']); ?></div>
            <div class="smsg"><?php echo date('d/m/Y', strtotime($r['date_soumission'])); ?> ·
              <span style="color:<?php echo $r['statut']==='en_attente'?'#F57F17':($r['statut']==='accepte'?'#2E7D32':'#C62828'); ?>">
                <?php echo ucfirst(str_replace('_',' ',$r['statut'])); ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- fin .right-panel -->

  </div><!-- fin .content -->
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
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', () => {
    if (window.innerWidth <= 768) toggleSidebar();
  });
});
// Restaurer le thème
const saved = localStorage.getItem('theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
// Animer les barres de progression
window.addEventListener('load', () => {
  document.querySelectorAll('.progress-fill').forEach(bar => {
    const w = bar.style.width;
    bar.style.width = '0';
    setTimeout(() => bar.style.width = w, 200);
  });
});
</script>
</body>
</html>