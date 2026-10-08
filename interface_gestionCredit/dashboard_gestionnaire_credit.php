<?php
require_once '../connexion/auth_check.php';
checkRole('gestionnaire_credit');
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$nom = $_SESSION['nom'] ?? '';
$prenoms = $_SESSION['prenoms'] ?? '';

// ============================================================
// RÉCUPÉRATION DES STATISTIQUES
// ============================================================

// Demandes en attente
$demandes_attente = $pdo->query("
    SELECT COUNT(*) as total 
    FROM demandes_credit 
    WHERE statut = 'en_attente'
")->fetch(PDO::FETCH_ASSOC);
$total_attente = $demandes_attente['total'] ?? 0;

// Demandes en étude
$demandes_etude = $pdo->query("
    SELECT COUNT(*) as total 
    FROM demandes_credit 
    WHERE statut = 'en_etude'
")->fetch(PDO::FETCH_ASSOC);
$total_etude = $demandes_etude['total'] ?? 0;

// Crédits en cours
$credits_cours = $pdo->query("
    SELECT COUNT(*) as total 
    FROM credits 
    WHERE statut = 'en_cours'
")->fetch(PDO::FETCH_ASSOC);
$total_credits = $credits_cours['total'] ?? 0;

// Crédits en retard
$credits_retard = $pdo->query("
    SELECT COUNT(*) as total 
    FROM credits 
    WHERE statut = 'en_retard'
")->fetch(PDO::FETCH_ASSOC);
$total_retard = $credits_retard['total'] ?? 0;

// Montant total des crédits en cours
$montant_total = $pdo->query("
    SELECT SUM(montant_accorde) as total 
    FROM credits 
    WHERE statut IN ('en_cours', 'en_retard')
")->fetch(PDO::FETCH_ASSOC);
$total_montant = $montant_total['total'] ?? 0;

// ============================================================
// RÉCUPÉRATION DES DEMANDES RÉCENTES
// ============================================================
$demandes_recentes = $pdo->prepare("
    SELECT dc.*, tc.libelle as type_libelle, tc.code as type_code,
           m.nom, m.prenoms, m.numero_membre, m.salaire_net, m.quotite_cessible,
           s.nom as societe_nom
    FROM demandes_credit dc
    JOIN types_credit tc ON dc.type_credit_id = tc.id
    JOIN membres m ON dc.membre_id = m.id
    JOIN societes s ON m.societe_id = s.id
    WHERE dc.statut IN ('en_attente', 'en_etude')
    ORDER BY dc.date_demande DESC
    LIMIT 10
");
$demandes_recentes->execute();
$demandes = $demandes_recentes->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// RÉCUPÉRATION DES CRÉDITS RÉCENTS
// ============================================================
$credits_recents = $pdo->prepare("
    SELECT c.*, tc.libelle as type_libelle, tc.code as type_code,
           m.nom, m.prenoms, m.numero_membre
    FROM credits c
    JOIN types_credit tc ON c.type_credit_id = tc.id
    JOIN membres m ON c.membre_id = m.id
    WHERE c.statut IN ('en_cours', 'en_retard')
    ORDER BY c.created_at DESC
    LIMIT 5
");
$credits_recents->execute();
$credits = $credits_recents->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestionnaire Crédit — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
  :root {
    --gold: #C9A84C;
    --gold-light: #E8D5A3;
    --gold-bg: #F5EDD6;
    --dark: #1a1a1a;
    --dark2: #2a2a2a;
    --sidebar-w: 260px;
    --white: #ffffff;
    --bg: #F0EDE8;
    --text: #1a1a1a;
    --text-light: #888;
    --card: #ffffff;
    --border: #e8e3db;
    --green: #4CAF50;
    --blue: #2196F3;
    --yellow: #FFC107;
    --red: #F44336;
    --orange: #FF9800;
  }
  .dark {
    --bg: #121212;
    --card: #1e1e1e;
    --text: #f0f0f0;
    --text-light: #777;
    --border: #2a2a2a;
    --white: #1e1e1e;
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
    transition: transform 0.3s ease;
    z-index: 100;
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 20px 28px;
    border-bottom: 1px solid #333;
  }

  .logo-icon {
    width: 32px; height: 32px;
    background: var(--gold);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
  }

  .logo span {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    color: white;
    font-weight: 600;
    letter-spacing: 1px;
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

  .nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }

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

  .topbar {
    display: none;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
  }

  .hamburger {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    color: var(--text);
    display: none;
  }

  .overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 99;
  }
  .overlay.open { display: block; }

  .content {
    flex: 1;
    overflow-y: auto;
    padding: 28px;
  }

  /* Header */
  .page-header {
    margin-bottom: 24px;
  }

  .page-title {
    font-family: 'Playfair Display', serif;
    font-size: 28px;
    font-weight: 600;
    color: var(--text);
  }

  .page-subtitle {
    font-size: 14px;
    color: var(--text-light);
    margin-top: 4px;
  }

  /* Stats Grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 28px;
  }

  .stat-card {
    background: var(--card);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid var(--border);
    transition: transform 0.2s;
  }
  .stat-card:hover { transform: translateY(-2px); }

  .stat-left .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
    line-height: 1;
  }
  .stat-left .stat-label {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 6px;
  }
  .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .stat-icon.orange { background: #FFF3E0; color: #FF9800; }
  .stat-icon.blue { background: #E3F2FD; color: #2196F3; }
  .stat-icon.green { background: #E8F5E9; color: #4CAF50; }
  .stat-icon.red { background: #FFEBEE; color: #F44336; }

  /* Section */
  .section-card {
    background: var(--card);
    border-radius: 20px;
    border: 1px solid var(--border);
    margin-bottom: 28px;
    overflow: hidden;
  }

  .section-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .section-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
  }

  .section-link {
    font-size: 13px;
    color: var(--gold);
    text-decoration: none;
    font-weight: 500;
  }
  .section-link:hover { text-decoration: underline; }

  /* Table */
  .table-responsive {
    overflow-x: auto;
  }

  .table {
    width: 100%;
    border-collapse: collapse;
  }

  .table th {
    text-align: left;
    padding: 14px 20px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
  }

  .table td {
    padding: 14px 20px;
    font-size: 13px;
    color: var(--text);
    border-bottom: 1px solid var(--border);
  }

  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
  }

  .badge-attente { background: #FFF8E1; color: #F57F17; }
  .badge-etude { background: #E3F2FD; color: #1565C0; }
  .badge-cours { background: #E8F5E9; color: #2E7D32; }
  .badge-retard { background: #FFEBEE; color: #C62828; }

  .btn-sm {
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
  }

  .btn-primary {
    background: var(--gold);
    color: white;
  }
  .btn-primary:hover { background: #b8922e; }

  .btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
  }
  .btn-outline:hover { border-color: var(--gold); color: var(--gold); }

  .montant {
    font-weight: 600;
    color: var(--gold);
  }

  .empty-state {
    text-align: center;
    padding: 48px;
    color: var(--text-light);
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .fadeUp { animation: fadeUp 0.4s ease both; }

  @media (max-width: 768px) {
    body { flex-direction: column; height: auto; overflow-y: auto; }
    .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .hamburger { display: flex; }
    .topbar { display: flex; position: sticky; top: 0; z-index: 50; }
    .content { padding: 20px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  }

  @media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<!-- TOPBAR MOBILE -->
<div class="topbar">
  <div class="topbar-logo">
    <span style="font-family:'Playfair Display';font-weight:600;">MA2E</span>
  </div>
  <button class="hamburger" onclick="toggleSidebar()">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    </div>
    <span>MA2E</span>
  </div>

  <nav class="nav">
    <a class="nav-item active" href="dashboard_gestionnaire_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a class="nav-item" href="traiter_demande_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Traiter demandes
    </a>
    <a class="nav-item" href="suivi_remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Suivi remboursements
    </a>
    <a class="nav-item" href="credits_en_cours.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Crédits en cours
    </a>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>
      Déconnexion
    </a>
  </nav>

  <div class="sidebar-bottom">
    <div class="user-card">
      <div class="avatar"><?php echo strtoupper(substr($prenoms, 0, 1) . substr($nom, 0, 1)); ?></div>
      <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($prenoms . ' ' . $nom); ?></div>
        <div class="status">Gestionnaire Crédit</div>
      </div>
    </div>
    <div class="theme-toggle">
      <button class="theme-btn active" onclick="setTheme('light', this)">☀ Light</button>
      <button class="theme-btn" onclick="setTheme('dark', this)">🌙 Dark</button>
    </div>
  </div>
</aside>

<!-- MAIN CONTENT -->
<main class="main">
  <div class="content">
    
    <div class="page-header fadeUp">
      <h1 class="page-title">Tableau de bord</h1>
      <p class="page-subtitle">Bienvenue, <?php echo htmlspecialchars($prenoms); ?> — Voici l'activité des crédits</p>
    </div>

    <!-- STATS -->
    <div class="stats-grid fadeUp">
      <div class="stat-card">
        <div class="stat-left">
          <div class="stat-value"><?php echo $total_attente; ?></div>
          <div class="stat-label">Demandes en attente</div>
        </div>
        <div class="stat-icon orange">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-left">
          <div class="stat-value"><?php echo $total_etude; ?></div>
          <div class="stat-label">En cours d'étude</div>
        </div>
        <div class="stat-icon blue">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-left">
          <div class="stat-value"><?php echo $total_credits; ?></div>
          <div class="stat-label">Crédits en cours</div>
        </div>
        <div class="stat-icon green">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-left">
          <div class="stat-value"><?php echo $total_retard; ?></div>
          <div class="stat-label">En retard</div>
        </div>
        <div class="stat-icon red">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
      </div>
    </div>

    <!-- DEMANDES EN ATTENTE -->
    <div class="section-card fadeUp">
      <div class="section-header">
        <span class="section-title">📋 Demandes de crédit à traiter</span>
        <a href="traiter_demande_credit.php" class="section-link">Voir toutes →</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Membre</th>
              <th>Société</th>
              <th>Type</th>
              <th>Montant</th>
              <th>Durée</th>
              <th>Statut</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($demandes)): ?>
            <tr>
              <td colspan="8" class="empty-state">Aucune demande en attente</td>
            </tr>
            <?php else: ?>
              <?php foreach ($demandes as $d): ?>
              <tr>
                <td><?php echo date('d/m/Y', strtotime($d['date_demande'])); ?></td>
                <td><?php echo htmlspecialchars($d['prenoms'] . ' ' . $d['nom']); ?></td>
                <td><?php echo htmlspecialchars($d['societe_nom']); ?></td>
                <td><?php echo htmlspecialchars($d['type_libelle']); ?></td>
                <td class="montant"><?php echo number_format($d['montant_demande'], 0, ',', ' '); ?> F</td>
                <td><?php echo $d['duree_demandee_mois']; ?> mois</td>
                <td>
                  <span class="badge <?php echo $d['statut'] === 'en_attente' ? 'badge-attente' : 'badge-etude'; ?>">
                    <?php echo $d['statut'] === 'en_attente' ? '⏳ En attente' : '🔍 En étude'; ?>
                  </span>
                </td>
                <td>
                  <a href="traiter_demande_credit.php?id=<?php echo $d['id']; ?>" class="btn-sm btn-primary">Traiter</a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- CRÉDITS EN COURS -->
    <div class="section-card fadeUp">
      <div class="section-header">
        <span class="section-title">💰 Crédits en cours</span>
        <a href="credits_en_cours.php" class="section-link">Voir tous →</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>N° Crédit</th>
              <th>Membre</th>
              <th>Type</th>
              <th>Montant</th>
              <th>Mensualité</th>
              <th>Restant</th>
              <th>Statut</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($credits)): ?>
            <tr>
              <td colspan="8" class="empty-state">Aucun crédit en cours</td>
            </tr>
            <?php else: ?>
              <?php foreach ($credits as $c): ?>
              <tr>
                <td><?php echo htmlspecialchars($c['numero_credit']); ?></td>
                <td><?php echo htmlspecialchars($c['prenoms'] . ' ' . $c['nom']); ?></td>
                <td><?php echo htmlspecialchars($c['type_libelle']); ?></td>
                <td class="montant"><?php echo number_format($c['montant_accorde'], 0, ',', ' '); ?> F</td>
                <td><?php echo number_format($c['mensualite'], 0, ',', ' '); ?> F</td>
                <td><?php echo number_format($c['capital_restant_du'], 0, ',', ' '); ?> F</td>
                <td>
                  <span class="badge <?php echo $c['statut'] === 'en_cours' ? 'badge-cours' : 'badge-retard'; ?>">
                    <?php echo $c['statut'] === 'en_cours' ? '✅ En cours' : '⚠️ En retard'; ?>
                  </span>
                </td>
                <td>
                  <a href="details_credit.php?id=<?php echo $c['id']; ?>" class="btn-sm btn-outline">Détails</a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- RÉCAPITULATIF FINANCIER -->
    <div class="section-card fadeUp">
      <div class="section-header">
        <span class="section-title">📊 Récapitulatif financier</span>
      </div>
      <div class="table-responsive">
        <table class="table">
          <tbody>
            <tr>
              <td style="width: 50%;">Montant total des crédits en cours</td>
              <td class="montant"><?php echo number_format($total_montant, 0, ',', ' '); ?> F CFA</td>
            </tr>
            <tr>
              <td>Nombre total de crédits actifs</td>
              <td><?php echo $total_credits + $total_retard; ?></td>
            </tr>
            <tr>
              <td>Demandes en attente de traitement</td>
              <td><?php echo $total_attente + $total_etude; ?></td>
            </tr>
          </tbody>
        </table>
      </div>
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
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  sidebar.classList.toggle('open');
  overlay.classList.toggle('open');
}

// Fermer le menu sur mobile
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', () => {
    if (window.innerWidth <= 768) toggleSidebar();
  });
});

const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b, i) => b.classList.toggle('active', i === 1));
}
</script>
</body>
</html>