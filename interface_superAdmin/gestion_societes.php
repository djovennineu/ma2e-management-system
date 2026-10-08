<?php
require_once '../connexion/auth_check.php';
checkRole('super_admin');
require_once '../config/database.php';

$success = '';
$error = '';

// Récupérer toutes les sociétés
$societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// CU-XX : Ajouter une société
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    $nom = trim($_POST['nom'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($nom) || empty($code)) {
        $error = "Le nom et le code sont obligatoires.";
    } else {
        // Vérifier si code existe déjà
        $check = $pdo->prepare("SELECT id FROM societes WHERE code = ?");
        $check->execute([$code]);
        if ($check->fetch()) {
            $error = "Ce code société existe déjà.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO societes (nom, code, adresse, telephone, email, actif) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$nom, $code, $adresse, $telephone, $email]);
            $success = "Société <strong>" . htmlspecialchars($nom) . "</strong> ajoutée avec succès.";
            // Recharger
            $societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll();
        }
    }
}

// CU-XX : Modifier une société
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier') {
    $id = intval($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $actif = intval($_POST['actif'] ?? 1);
    
    if ($id <= 0 || empty($nom) || empty($code)) {
        $error = "Données invalides.";
    } else {
        // Vérifier si code existe déjà (sauf pour cette société)
        $check = $pdo->prepare("SELECT id FROM societes WHERE code = ? AND id != ?");
        $check->execute([$code, $id]);
        if ($check->fetch()) {
            $error = "Ce code société existe déjà.";
        } else {
            $stmt = $pdo->prepare("UPDATE societes SET nom=?, code=?, adresse=?, telephone=?, email=?, actif=? WHERE id=?");
            $stmt->execute([$nom, $code, $adresse, $telephone, $email, $actif, $id]);
            $success = "Société <strong>" . htmlspecialchars($nom) . "</strong> modifiée avec succès.";
            $societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll();
        }
    }
}

// CU-XX : Supprimer une société
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        $error = "ID invalide.";
    } else {
        // Vérifier si des membres sont rattachés
        $check = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE societe_id = ?");
        $check->execute([$id]);
        $membreCount = $check->fetchColumn();
        
        if ($membreCount > 0) {
            $error = "Impossible de supprimer cette société : $membreCount membre(s) y sont rattachés.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM societes WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Société supprimée avec succès.";
            $societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll();
        }
    }
}

// Statistiques
$total = count($societes);
$actives = count(array_filter($societes, fn($s) => $s['actif'] == 1));
$inactives = $total - $actives;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MA2E — Gestion des sociétés</title>
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
.logout-btn{width:100%;margin-top:12px;padding:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#999;font-size:12px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.2s;display:flex;align-items:center;justify-content:center;gap:6px}
.logout-btn:hover{background:rgba(255,0,0,0.15);color:#ff6b6b;border-color:rgba(255,0,0,0.2)}
.main{flex:1;padding:32px;overflow-x:hidden}
.page-header{margin-bottom:28px}
.page-header h1{font-family:'Playfair Display',serif;font-size:26px;color:var(--text);margin-bottom:4px}
.page-header p{font-size:14px;color:var(--text-light)}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:24px;display:flex;align-items:center;gap:10px;animation:fadeIn 0.3s ease}
.alert-success{background:var(--green-bg);color:#166534;border-left:4px solid var(--green)}
.alert-error{background:var(--red-bg);color:#991b1b;border-left:4px solid var(--red)}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-box{background:var(--card);border-radius:12px;padding:18px 20px;border:1px solid var(--border);display:flex;align-items:center;gap:14px;transition:transform 0.2s}
.stat-box:hover{transform:translateY(-2px)}
.stat-icon-wrap{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.stat-icon-wrap svg{width:20px;height:20px}
.stat-val{font-size:22px;font-weight:700;color:var(--text);line-height:1}
.stat-lbl{font-size:12px;color:var(--text-light);margin-top:2px}
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.card-title{font-size:15px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-body{padding:24px}
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:12.5px;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:0.5px}
.form-group input,.form-group select,.form-group textarea{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;color:var(--text);background:#fafaf8;font-family:'DM Sans',sans-serif;transition:border-color 0.2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--gold);background:white}
.form-actions{margin-top:20px;display:flex;gap:12px;align-items:center}
.btn{padding:10px 22px;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.2s;display:inline-flex;align-items:center;gap:7px}
.btn-primary{background:var(--gold);color:white}
.btn-primary:hover{background:#b8922e}
.btn-danger{background:var(--red-bg);color:var(--red);border:1px solid #fecaca}
.btn-danger:hover{background:var(--red);color:white}
.btn-success{background:var(--green-bg);color:#166534;border:1px solid #bbf7d0}
.btn-success:hover{background:var(--green);color:white}
.btn-ghost{background:transparent;color:var(--text-light);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--bg)}
.btn-sm{padding:6px 12px;font-size:12px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 16px;text-align:left;font-size:11.5px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid var(--border);background:#faf9f7}
tbody tr{border-bottom:1px solid var(--border);transition:background 0.15s}
tbody tr:hover{background:#faf9f7}
tbody tr:last-child{border-bottom:none}
td{padding:13px 16px;font-size:13.5px;vertical-align:middle}
.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge-active{background:var(--green-bg);color:#166534}
.badge-inactive{background:var(--red-bg);color:#991b1b}
.actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 25px 60px rgba(0,0,0,0.2);animation:modalIn 0.25s ease}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.modal-title{font-size:17px;font-weight:700;color:var(--text)}
.modal-close{background:none;border:none;cursor:pointer;color:var(--text-light);font-size:20px;line-height:1}
.modal-close:hover{color:var(--text)}
.modal-body{display:flex;flex-direction:column;gap:14px}
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.hamburger svg{width:22px;height:22px}
.overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150}
.overlay-mob.open{display:block}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes modalIn{from{opacity:0;transform:scale(0.95) translateY(10px)}to{opacity:1;transform:none}}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp 0.4s ease both}
@media(max-width:1024px){.stats-row{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .stats-row{grid-template-columns:1fr 1fr;gap:10px}
  .form-grid{grid-template-columns:1fr}
  .form-actions{flex-direction:column;align-items:stretch}
  .actions{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){.stats-row{grid-template-columns:1fr}}
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
    <a class="nav-item" href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Tableau de bord</a>
    <a class="nav-item" href="gestion_utilisateurs.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Utilisateurs</a>
    <a class="nav-item active" href="gestion_societes.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Sociétés</a>
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
    <h1>Gestion des sociétés partenaires</h1>
    <p>Gérez les entreprises (CIE, SODECI, etc.) affiliées à la MA2E</p>
  </div>

  <?php if($success): ?>
  <div class="alert alert-success fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <?php echo $success; ?>
  </div>
  <?php endif; ?>
  <?php if($error): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <?php echo htmlspecialchars($error); ?>
  </div>
  <?php endif; ?>

  <!-- STATISTIQUES -->
  <div class="stats-row fadeUp">
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#f0fdf4">
        <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
      </div>
      <div><div class="stat-val"><?php echo $total; ?></div><div class="stat-lbl">Total sociétés</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#f0fdf4">
        <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div><div class="stat-val" style="color:#22c55e"><?php echo $actives; ?></div><div class="stat-lbl">Actives</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#fef2f2">
        <svg viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
      </div>
      <div><div class="stat-val" style="color:#ef4444"><?php echo $inactives; ?></div><div class="stat-lbl">Inactives</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#eff6ff">
        <svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
      </div>
      <div><div class="stat-val" style="color:#3b82f6">CIE/SODECI</div><div class="stat-lbl">Principales</div></div>
    </div>
  </div>

  <!-- FORMULAIRE AJOUT -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>Ajouter une société</span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="ajouter">
        <div class="form-grid">
          <div class="form-group"><label>Nom *</label><input type="text" name="nom" placeholder="Ex: Compagnie Ivoirienne d'Électricité" required></div>
          <div class="form-group"><label>Code *</label><input type="text" name="code" placeholder="Ex: CIE, SODECI, CI-ENERGIES" required></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="contact@cie.ci"></div>
          <div class="form-group"><label>Téléphone</label><input type="text" name="telephone" placeholder="+225 XX XX XX XX"></div>
          <div class="form-group"><label>Adresse</label><textarea name="adresse" rows="2" placeholder="Adresse complète"></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Ajouter la société</button>
        </div>
      </form>
    </div>
  </div>

  <!-- LISTE DES SOCIÉTÉS -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>Liste des sociétés (<?php echo $total; ?>)</span>
      <input type="text" id="search" placeholder="🔍 Rechercher..." onkeyup="filterTable()" style="padding:8px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;width:220px">
    </div>
    <div class="table-wrap">
      <table id="societesTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nom</th>
            <th>Code</th>
            <th>Email</th>
            <th>Téléphone</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($societes)): ?>
          <tr>
            <td colspan="7" style="text-align:center;color:var(--text-light);padding:30px">Aucune société enregistrée.</td>
          </tr>
          <?php else: ?>
            <?php foreach($societes as $s): ?>
            <tr>
              <td><?php echo $s['id']; ?></td>
              <td><strong><?php echo htmlspecialchars($s['nom']); ?></strong></td>
              <td><code style="background:var(--gold-bg);color:var(--gold);padding:3px 9px;border-radius:5px;font-size:12px;font-weight:700"><?php echo htmlspecialchars($s['code']); ?></code></td>
              <td><?php echo htmlspecialchars($s['email'] ?? '—'); ?></td>
              <td><?php echo htmlspecialchars($s['telephone'] ?? '—'); ?></td>
              <td>
                <span class="badge <?php echo $s['actif'] ? 'badge-active' : 'badge-inactive'; ?>">
                  <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
                  <?php echo $s['actif'] ? 'Actif' : 'Inactif'; ?>
                </span>
              </td>
              <td>
                <div class="actions">
                  <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Modifier
                  </button>
                  <button class="btn btn-sm <?php echo $s['actif'] ? 'btn-danger' : 'btn-success'; ?>" onclick="openDeleteModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['nom']); ?>', <?php echo $s['actif']; ?>)">
                    <?php if($s['actif']): ?>
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                      Désactiver
                    <?php else: ?>
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      Activer
                    <?php endif; ?>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</div>

<!-- MODAL MODIFICATION -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">✏️ Modifier la société</span>
      <button class="modal-close" onclick="closeModal('modal-edit')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="modifier">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal-body">
        <div class="form-group"><label>Nom *</label><input type="text" name="nom" id="edit-nom" required></div>
        <div class="form-group"><label>Code *</label><input type="text" name="code" id="edit-code" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="edit-email"></div>
        <div class="form-group"><label>Téléphone</label><input type="text" name="telephone" id="edit-telephone"></div>
        <div class="form-group"><label>Adresse</label><textarea name="adresse" id="edit-adresse" rows="2"></textarea></div>
        <div class="form-group"><label>Statut</label>
          <select name="actif" id="edit-actif">
            <option value="1">Actif</option>
            <option value="0">Inactif</option>
          </select>
        </div>
        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn btn-primary" style="flex:1">Enregistrer</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('modal-edit')" style="flex:1">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- MODAL SUPPRESSION/DÉSACTIVATION -->
<div class="modal-overlay" id="modal-delete">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="delete-title">Confirmation</span>
      <button class="modal-close" onclick="closeModal('modal-delete')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" id="delete-action" value="">
      <input type="hidden" name="id" id="delete-id">
      <div class="modal-body">
        <p id="delete-message" style="font-size:13.5px;color:var(--text-light)"></p>
        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn" id="delete-confirm-btn" style="flex:1">Confirmer</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('modal-delete')" style="flex:1">Annuler</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function filterTable() {
  const q = document.getElementById('search').value.toLowerCase();
  const rows = document.querySelectorAll('#societesTable tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(q) ? '' : 'none';
  });
}

function openEditModal(s) {
  document.getElementById('edit-id').value = s.id;
  document.getElementById('edit-nom').value = s.nom;
  document.getElementById('edit-code').value = s.code;
  document.getElementById('edit-email').value = s.email || '';
  document.getElementById('edit-telephone').value = s.telephone || '';
  document.getElementById('edit-adresse').value = s.adresse || '';
  document.getElementById('edit-actif').value = s.actif;
  document.getElementById('modal-edit').classList.add('open');
}

function openDeleteModal(id, nom, actif) {
  const title = document.getElementById('delete-title');
  const message = document.getElementById('delete-message');
  const confirmBtn = document.getElementById('delete-confirm-btn');
  const actionInput = document.getElementById('delete-action');
  const idInput = document.getElementById('delete-id');
  
  if (actif == 1) {
    // Désactiver
    title.textContent = '🔒 Désactiver la société';
    message.innerHTML = `Voulez-vous vraiment désactiver la société <strong>${nom}</strong> ?<br><br>Les membres rattachés ne pourront plus s'engager dans de nouvelles opérations.`;
    confirmBtn.textContent = 'Désactiver';
    confirmBtn.classList.remove('btn-success');
    confirmBtn.classList.add('btn-danger');
    actionInput.value = 'modifier';
    idInput.value = id;
    // Il faut aussi envoyer actif=0
    // On va ajouter un champ hidden dans le formulaire
    let hiddenActif = document.getElementById('delete-actif-hidden');
    if (!hiddenActif) {
      hiddenActif = document.createElement('input');
      hiddenActif.type = 'hidden';
      hiddenActif.name = 'actif';
      hiddenActif.id = 'delete-actif-hidden';
      document.querySelector('#modal-delete form').appendChild(hiddenActif);
    }
    hiddenActif.value = '0';
  } else {
    // Activer
    title.textContent = '✅ Activer la société';
    message.innerHTML = `Voulez-vous vraiment réactiver la société <strong>${nom}</strong> ?`;
    confirmBtn.textContent = 'Activer';
    confirmBtn.classList.remove('btn-danger');
    confirmBtn.classList.add('btn-success');
    actionInput.value = 'modifier';
    idInput.value = id;
    let hiddenActif = document.getElementById('delete-actif-hidden');
    if (!hiddenActif) {
      hiddenActif = document.createElement('input');
      hiddenActif.type = 'hidden';
      hiddenActif.name = 'actif';
      hiddenActif.id = 'delete-actif-hidden';
      document.querySelector('#modal-delete form').appendChild(hiddenActif);
    }
    hiddenActif.value = '1';
  }
  document.getElementById('modal-delete').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Fermer modal en cliquant sur l'overlay
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

function toggleNav() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay-mob').classList.toggle('open');
}
</script>
</body>
</html>