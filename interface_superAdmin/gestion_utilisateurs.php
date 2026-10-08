<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: pageconnexion.php"); 
    exit;
}

$success = ""; 
$error = "";

$roles_labels = [
    'gestionnaire_credit'     => 'Gestionnaire Crédit',
    'gestionnaire_immobilier' => 'Gestionnaire Immobilier',
    'comptable'               => 'Comptable',
    'administrateur'          => 'Administrateur',
];

$roles_prefix = [
    'gestionnaire_credit'     => 'GC',
    'gestionnaire_immobilier' => 'GI',
    'comptable'               => 'CPT',
    'administrateur'          => 'ADM',
];

// Génère le prochain matricule : PREFIXE-ANNEE-XXX
function genererMatricule(PDO $pdo, string $role, array $prefixes): string {
    $prefix = $prefixes[$role] ?? 'USR';
    $annee  = date('Y');
    $base   = $prefix . '-' . $annee . '-';
    $stmt   = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE matricule LIKE ? ORDER BY matricule DESC LIMIT 1");
    $stmt->execute([$base . '%']);
    $last   = $stmt->fetchColumn();
    $parts  = explode('-', $last);
    $num    = $last ? (int)end($parts) + 1 : 1;
    return $base . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// CU-38 : Créer un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer') {
    $nom = trim($_POST['nom'] ?? ''); 
    $prenoms = trim($_POST['prenoms'] ?? '');
    $email = trim($_POST['email'] ?? ''); 
    $role = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($nom) || empty($prenoms) || empty($email) || empty($role) || empty($password)) { 
        $error = "Tous les champs sont obligatoires."; 
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { 
        $error = "Email invalide."; 
    } elseif (strlen($password) < 8) { 
        $error = "Mot de passe trop court (min. 8 caractères)."; 
    } elseif (!array_key_exists($role, $roles_labels)) { 
        $error = "Rôle invalide."; 
    } else {
        $chk = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?"); 
        $chk->execute([$email]);
        if ($chk->fetch()) { 
            $error = "Cet email est déjà utilisé."; 
        } else {
            $matricule = genererMatricule($pdo, $role, $roles_prefix);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO utilisateurs (matricule,nom,prenoms,email,password,role,actif,mot_de_passe_temporaire) VALUES (?,?,?,?,?,?,1,1)")
                ->execute([$matricule, $nom, $prenoms, $email, $hash, $role]);
            $success = "Utilisateur créé. Matricule attribué : <strong>$matricule</strong>";
        }
    }
}

// CU-39 : Réinitialiser mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $uid = (int)($_POST['user_id'] ?? 0); 
    $np = $_POST['new_password'] ?? '';
    if ($uid <= 0 || strlen($np) < 8) { 
        $error = "Mot de passe invalide (min. 8 caractères)."; 
    } else { 
        $hash = password_hash($np, PASSWORD_BCRYPT); 
        $pdo->prepare("UPDATE utilisateurs SET password=?, mot_de_passe_temporaire=1 WHERE id=? AND role!='super_admin'")->execute([$hash, $uid]); 
        $success = "Mot de passe réinitialisé."; 
    }
}

// CU-40 : Activer/Désactiver compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_actif') {
    $uid = (int)($_POST['user_id'] ?? 0); 
    $actif = (int)($_POST['actif'] ?? 0);
    if ($uid > 0) { 
        $pdo->prepare("UPDATE utilisateurs SET actif=? WHERE id=? AND role!='super_admin'")->execute([$actif, $uid]); 
        $success = $actif ? "Compte activé." : "Compte désactivé."; 
    }
}

// CU-41 : Changer rôle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changer_role') {
    $uid = (int)($_POST['user_id'] ?? 0); 
    $role = $_POST['role'] ?? '';
    if ($uid > 0 && array_key_exists($role, $roles_labels)) { 
        $pdo->prepare("UPDATE utilisateurs SET role=? WHERE id=? AND role!='super_admin'")->execute([$role, $uid]); 
        $success = "Rôle mis à jour."; 
    } else { 
        $error = "Données invalides."; 
    }
}

// Récupérer tous les utilisateurs (hors super_admin)
$users = $pdo->query("SELECT * FROM utilisateurs WHERE role != 'super_admin' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$total = count($users);
$actifs = count(array_filter($users, fn($u) => $u['actif'] == 1));
$inactifs = $total - $actifs;
$membres = count(array_filter($users, fn($u) => $u['role'] === 'membre'));

// Calcul des prochains numéros par rôle pour la preview JS
$counters = [];
foreach ($roles_prefix as $role => $prefix) {
    $annee = date('Y'); 
    $base = $prefix . '-' . $annee . '-';
    $stmt = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE matricule LIKE ? ORDER BY matricule DESC LIMIT 1");
    $stmt->execute([$base . '%']); 
    $last = $stmt->fetchColumn();
    $parts = explode('-', $last);
    $counters[$role] = $last ? (int)end($parts) + 1 : 1;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MA2E — Gestion des utilisateurs</title>
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
.form-group input,.form-group select{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;color:var(--text);background:#fafaf8;font-family:'DM Sans',sans-serif;transition:border-color 0.2s}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--gold);background:white}
.matricule-preview{padding:10px 14px;border:1.5px dashed var(--gold);border-radius:9px;font-size:15px;font-weight:700;color:var(--gold);background:var(--gold-bg);font-family:monospace;letter-spacing:1.5px;min-height:44px;display:flex;align-items:center;gap:8px}
.matricule-preview .hint{font-size:12px;font-weight:400;color:var(--text-light);font-family:'DM Sans',sans-serif;letter-spacing:0}
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
.badge-role{background:var(--gold-bg);color:#92610a}
.badge-membre{background:var(--blue-bg);color:#1d4ed8}
.user-cell{display:flex;align-items:center;gap:10px}
.user-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0}
.actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:16px;padding:28px;width:100%;max-width:440px;box-shadow:0 25px 60px rgba(0,0,0,0.2);animation:modalIn 0.25s ease}
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
    <a class="nav-item active" href="gestion_utilisateurs.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Utilisateurs</a>
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
    <a href="../connexion/logout.php" class="logout-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>Déconnexion</a>
  </div>
</aside>
<main class="main">
  <div class="page-header fadeUp">
    <h1>Gestion des utilisateurs</h1>
    <p>Créez, modifiez et gérez les comptes des employés internes MA2E</p>
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
        <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      </div>
      <div><div class="stat-val"><?php echo $total; ?></div><div class="stat-lbl">Total utilisateurs</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#f0fdf4">
        <svg viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <div><div class="stat-val" style="color:#22c55e"><?php echo $actifs; ?></div><div class="stat-lbl">Comptes actifs</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#fef2f2">
        <svg viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
      </div>
      <div><div class="stat-val" style="color:#ef4444"><?php echo $inactifs; ?></div><div class="stat-lbl">Désactivés</div></div>
    </div>
    <div class="stat-box">
      <div class="stat-icon-wrap" style="background:#eff6ff">
        <svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div><div class="stat-val" style="color:#3b82f6"><?php echo $membres; ?></div><div class="stat-lbl">Membres</div></div>
    </div>
  </div>

  <!-- FORMULAIRE CRÉATION -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>Créer un utilisateur interne</span>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="creer">
        <div class="form-grid">
          <div class="form-group">
            <label>Matricule (généré automatiquement)</label>
            <div class="matricule-preview" id="matricule-preview"><span class="hint">Sélectionnez un rôle</span></div>
          </div>
          <div class="form-group"><label>Nom</label><input type="text" name="nom" placeholder="Nom de famille" required value="<?php echo htmlspecialchars($_POST['nom']??''); ?>"></div>
          <div class="form-group"><label>Prénoms</label><input type="text" name="prenoms" placeholder="Prénoms" required value="<?php echo htmlspecialchars($_POST['prenoms']??''); ?>"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="email@ma2e.ci" required value="<?php echo htmlspecialchars($_POST['email']??''); ?>"></div>
          <div class="form-group">
            <label>Rôle</label>
            <select name="role" id="role-select-form" required onchange="updatePreview()">
              <option value="">-- Choisir un rôle --</option>
              <?php foreach($roles_labels as $k=>$v): ?>
              <option value="<?php echo $k; ?>" <?php echo(($_POST['role']??'')===$k)?'selected':''; ?>><?php echo $v; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Mot de passe initial</label><input type="text" name="password" placeholder="Min. 8 caractères" required></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Créer l'utilisateur</button>
          <span style="font-size:12.5px;color:var(--text-light)">Le matricule est attribué automatiquement à la création.</span>
        </div>
      </form>
    </div>
  </div>

  <!-- LISTE DES UTILISATEURS -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>Liste des utilisateurs (<?php echo $total; ?>)</span>
      <input type="text" id="search" placeholder="🔍 Rechercher..." onkeyup="filterTable()" style="padding:8px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;width:220px">
    </div>
    <div class="table-wrap">
      <table id="usersTable">
        <thead>
          <tr><th>Utilisateur</th><th>Matricule</th><th>Rôle</th><th>Statut</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if(empty($users)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:30px">Aucun utilisateur trouvé.</td></tr>
        <?php else: ?>
        <?php
        $avatar_colors=['#C9A84C','#3b82f6','#22c55e','#f97316','#8b5cf6','#ef4444','#06b6d4'];
        foreach($users as $i=>$u):
          $ini = strtoupper(substr($u['nom'],0,1).substr($u['prenoms'],0,1));
          $clr = $avatar_colors[$i % count($avatar_colors)];
          $rl = $roles_labels[$u['role']] ?? ucfirst(str_replace('_',' ',$u['role']));
          $is_m = $u['role'] === 'membre';
        ?>
        <tr>
          <td>
            <div class="user-cell">
              <div class="user-avatar" style="background:<?php echo $clr; ?>"><?php echo $ini; ?></div>
              <div>
                <div style="font-weight:600;font-size:13.5px"><?php echo htmlspecialchars($u['nom'].' '.$u['prenoms']); ?></div>
                <div style="font-size:12px;color:var(--text-light)"><?php echo htmlspecialchars($u['email']); ?></div>
              </div>
            </div>
           </div>
          <td><code style="background:var(--gold-bg);color:var(--gold);padding:3px 9px;border-radius:5px;font-size:12px;font-weight:700"><?php echo htmlspecialchars($u['matricule']); ?></code></td>
          <td><span class="badge <?php echo $is_m ? 'badge-membre' : 'badge-role'; ?>"><?php echo $rl; ?></span></td>
          <td><span class="badge <?php echo $u['actif'] ? 'badge-active' : 'badge-inactive'; ?>"><span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span><?php echo $u['actif'] ? 'Actif' : 'Inactif'; ?></span></td>
          <td>
            <div class="actions">
              <button class="btn btn-ghost btn-sm" onclick="openResetModal(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars($u['nom'].' '.$u['prenoms']); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>Réinitialiser MDP</button>
              <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_actif"><input type="hidden" name="user_id" value="<?php echo $u['id']; ?>"><input type="hidden" name="actif" value="<?php echo $u['actif'] ? 0 : 1; ?>">
                <button type="submit" class="btn btn-sm <?php echo $u['actif'] ? 'btn-danger' : 'btn-success'; ?>"><?php if($u['actif']): ?><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>Désactiver<?php else: ?><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Activer<?php endif; ?></button>
              </form>
              <button class="btn btn-ghost btn-sm" onclick="openRoleModal(<?php echo $u['id']; ?>,'<?php echo $u['role']; ?>','<?php echo htmlspecialchars($u['nom'].' '.$u['prenoms']); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Changer rôle</button>
            </div>
           </div>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</div>

<!-- MODAL RESET PASSWORD -->
<div class="modal-overlay" id="modal-reset">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">🔑 Réinitialiser le mot de passe</span><button class="modal-close" onclick="closeModal('modal-reset')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="reset-user-id">
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-light)">Pour : <strong id="reset-user-name" style="color:var(--text)"></strong></p>
        <div class="form-group"><label>Nouveau mot de passe</label><input type="text" name="new_password" placeholder="Min. 8 caractères" required></div>
        <p style="font-size:12px;color:var(--text-light)">⚠️ L'utilisateur devra changer ce mot de passe à la prochaine connexion.</p>
        <div style="display:flex;gap:10px;margin-top:4px"><button type="submit" class="btn btn-primary" style="flex:1">Confirmer</button><button type="button" class="btn btn-ghost" onclick="closeModal('modal-reset')" style="flex:1">Annuler</button></div>
      </div>
    </form>
  </div>
</div>

<!-- MODAL CHANGER RÔLE -->
<div class="modal-overlay" id="modal-role">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">✏️ Modifier le rôle</span><button class="modal-close" onclick="closeModal('modal-role')">×</button></div>
    <form method="POST"><input type="hidden" name="action" value="changer_role"><input type="hidden" name="user_id" id="role-user-id">
      <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-light)">Modifier le rôle de : <strong id="role-user-name" style="color:var(--text)"></strong></p>
        <div class="form-group"><label>Nouveau rôle</label><select name="role" id="role-select" required><?php foreach($roles_labels as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?></select></div>
        <div style="display:flex;gap:10px;margin-top:4px"><button type="submit" class="btn btn-primary" style="flex:1">Confirmer</button><button type="button" class="btn btn-ghost" onclick="closeModal('modal-role')" style="flex:1">Annuler</button></div>
      </div>
    </form>
  </div>
</div>

<script>
const prefixes = {
  'gestionnaire_credit':'GC',
  'gestionnaire_immobilier':'GI',
  'comptable':'CPT',
  'administrateur':'ADM'
};
const counters = <?php echo json_encode($counters); ?>;

function updatePreview() {
  const role = document.getElementById('role-select-form').value;
  const el = document.getElementById('matricule-preview');
  const annee = new Date().getFullYear();
  if (!role || !prefixes[role]) {
    el.innerHTML = '<span class="hint">Sélectionnez un rôle</span>';
    return;
  }
  const num = String(counters[role] || 1).padStart(3, '0');
  el.textContent = prefixes[role] + '-' + annee + '-' + num;
}

function openResetModal(id, name) {
  document.getElementById('reset-user-id').value = id;
  document.getElementById('reset-user-name').textContent = name;
  document.getElementById('modal-reset').classList.add('open');
}

function openRoleModal(id, role, name) {
  document.getElementById('role-user-id').value = id;
  document.getElementById('role-user-name').textContent = name;
  document.getElementById('role-select').value = role;
  document.getElementById('modal-role').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});

function filterTable() {
  const q = document.getElementById('search').value.toLowerCase();
  document.querySelectorAll('#usersTable tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function toggleNav() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay-mob').classList.toggle('open');
}

// Initialiser la preview si un rôle est déjà sélectionné
document.addEventListener('DOMContentLoaded', function() {
  updatePreview();
});
</script>
</body>
</html>