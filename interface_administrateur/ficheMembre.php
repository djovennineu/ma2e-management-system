<?php
require_once '../connexion/auth_check.php';
checkRole('administrateur');
require_once '../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: membres.php'); exit; }

// Récupérer le membre
$stmt = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom, s.code AS societe_code
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    WHERE m.id = ?
");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { header('Location: membres.php'); exit; }

// Récupérer les sociétés pour le formulaire
$societes = $pdo->query("SELECT id, nom, code FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll();

// Compte épargne expresse
$compte_exp = $pdo->prepare("
    SELECT ce.*, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND tce.code = 'expresse'
    LIMIT 1
");
$compte_exp->execute([$id]);
$compte_exp = $compte_exp->fetch(PDO::FETCH_ASSOC);

// Tous les comptes du membre
$comptes = $pdo->prepare("
    SELECT ce.*, tce.libelle AS type_libelle, tce.code AS type_code
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ?
    ORDER BY ce.date_ouverture ASC
");
$comptes->execute([$id]);
$comptes = $comptes->fetchAll(PDO::FETCH_ASSOC);

// Plan épargne expresse
$plan = null;
if ($compte_exp) {
    $p = $pdo->prepare("SELECT * FROM plans_epargne_expresse WHERE compte_id = ? LIMIT 1");
    $p->execute([$compte_exp['id']]);
    $plan = $p->fetch(PDO::FETCH_ASSOC);
}

// Derniers mouvements (5)
$mouvements = [];
if ($compte_exp) {
    $mv = $pdo->prepare("
        SELECT mo.*, u.nom AS fait_par
        FROM mouvements mo
        JOIN utilisateurs u ON mo.utilisateur_id = u.id
        WHERE mo.compte_id = ?
        ORDER BY mo.created_at DESC
        LIMIT 5
    ");
    $mv->execute([$compte_exp['id']]);
    $mouvements = $mv->fetchAll(PDO::FETCH_ASSOC);
}

// Crédits en cours
$credits = $pdo->prepare("
    SELECT cr.*, tc.libelle AS type_libelle
    FROM credits cr
    JOIN types_credit tc ON cr.type_credit_id = tc.id
    WHERE cr.membre_id = ?
    ORDER BY cr.created_at DESC
");
$credits->execute([$id]);
$credits = $credits->fetchAll(PDO::FETCH_ASSOC);

// Compte utilisateur lié
$user = $pdo->prepare("SELECT * FROM utilisateurs WHERE membre_id = ? LIMIT 1");
$user->execute([$id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

$success = '';
$error   = '';

// ── ACTION : Changer statut membre ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changer_statut') {
    $nouveau_statut = $_POST['nouveau_statut'] ?? '';
    $motif_sortie   = $_POST['motif_sortie'] ?? null;
    $statuts_valides = ['actif', 'suspendu', 'desactive', 'sorti'];
    if (in_array($nouveau_statut, $statuts_valides)) {
        $date_sortie = ($nouveau_statut === 'sorti') ? date('Y-m-d') : null;
        $pdo->prepare("UPDATE membres SET statut=?, motif_sortie=?, date_sortie=? WHERE id=?")
            ->execute([$nouveau_statut, $motif_sortie ?: null, $date_sortie, $id]);
        $success = "Statut mis à jour : <strong>" . ucfirst($nouveau_statut) . "</strong>";
        // Recharger
        $stmt->execute([$id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ── ACTION : Modifier informations personnelles ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_personnel') {
    $nom = trim($_POST['nom']);
    $prenoms = trim($_POST['prenoms']);
    $email = trim($_POST['email']);
    $telephone = trim($_POST['telephone']);
    $adresse = trim($_POST['adresse']);
    $ville = trim($_POST['ville']);
    $date_naissance = $_POST['date_naissance'] ?: null;
    $sexe = $_POST['sexe'] ?? null;
    $situation_matrimoniale = $_POST['situation_matrimoniale'] ?? null;
    $nombre_enfants = intval($_POST['nombre_enfants'] ?? 0);
    
    try {
        $sql = "UPDATE membres SET 
                    nom = ?,
                    prenoms = ?,
                    email = ?,
                    telephone = ?,
                    adresse = ?,
                    ville = ?,
                    date_naissance = ?,
                    sexe = ?,
                    situation_matrimoniale = ?,
                    nombre_enfants = ?
                WHERE id = ?";
        $pdo->prepare($sql)->execute([
            $nom, $prenoms, $email, $telephone, $adresse, 
            $ville, $date_naissance, $sexe, $situation_matrimoniale, $nombre_enfants, $id
        ]);
        
        // Mettre à jour aussi l'utilisateur si besoin
        if ($user) {
            $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenoms = ?, email = ? WHERE membre_id = ?")
                ->execute([$nom, $prenoms, $email, $id]);
        }
        
        $success = "Informations personnelles mises à jour avec succès";
        $stmt->execute([$id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        $user = $pdo->prepare("SELECT * FROM utilisateurs WHERE membre_id = ? LIMIT 1");
        $user->execute([$id]);
        $user = $user->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}

// ── ACTION : Modifier informations professionnelles ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_professionnel') {
    $societe_id = intval($_POST['societe_id']);
    $categorie = $_POST['categorie'];
    $salaire_net = floatval($_POST['salaire_net']);
    $matricule_pro = trim($_POST['matricule_pro']);
    
    // Calcul de la cotisation selon catégorie
    $cotisation = match($categorie) {
        'directeur' => 10000,
        'cadre' => 5000,
        'maitrise_superieure' => 3000,
        default => 1500
    };
    
    // Calcul de la quotité cessible (1/3 du salaire)
    $quotite = $salaire_net / 3;
    
    try {
        $sql = "UPDATE membres SET 
                    societe_id = ?,
                    categorie = ?,
                    salaire_net = ?,
                    quotite_cessible = ?,
                    cotisation_mensuelle = ?,
                    matricule_pro = ?
                WHERE id = ?";
        $pdo->prepare($sql)->execute([
            $societe_id, $categorie, $salaire_net, $quotite, $cotisation, $matricule_pro, $id
        ]);
        
        $success = "Informations professionnelles mises à jour avec succès";
        $stmt->execute([$id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}

// ── ACTION : Modifier mot de passe utilisateur ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_password') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (strlen($new_password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères";
    } elseif ($new_password !== $confirm_password) {
        $error = "Les mots de passe ne correspondent pas";
    } elseif ($user) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE utilisateurs SET password = ?, mot_de_passe_temporaire = 1 WHERE id = ?")
            ->execute([$hashed_password, $user['id']]);
        $success = "Mot de passe modifié avec succès. L'utilisateur devra le changer à la prochaine connexion.";
        $user['mot_de_passe_temporaire'] = 1;
    } else {
        $error = "Aucun compte utilisateur trouvé pour ce membre";
    }
}

$categories_labels = [
    'directeur'           => 'Directeur',
    'cadre'               => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Supérieure',
    'maitrise_1_2'        => 'Maîtrise 1 et 2',
    'employe_ouvrier'     => 'Employé / Ouvrier',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fiche membre — <?php echo htmlspecialchars($m['nom'].' '.$m['prenoms']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:200px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#888;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100}
.logo{display:flex;align-items:center;gap:8px;padding:0 20px 28px;border-bottom:1px solid #333}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white;flex-shrink:0}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#e8b84b}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:20px}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-light)}
.breadcrumb a{color:var(--text-light);text-decoration:none}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .current{color:var(--text);font-weight:600}
/* HEADER */
.fiche-header{background:var(--card);border-radius:16px;padding:28px;border:1px solid var(--border);display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.fiche-avatar{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:white;flex-shrink:0;background:var(--gold)}
.fiche-nom{font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text);margin-bottom:6px}
.fiche-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.fiche-meta-item{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-light)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.badge-actif{background:#E8F5E9;color:#2E7D32}
.badge-suspendu{background:#FFF8E1;color:#F57F17}
.badge-desactive{background:#FFEBEE;color:#C62828}
.badge-sorti{background:#F3F4F6;color:#6B7280}
/* ALERTS */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:10px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
/* GRILLE */
.fiche-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.info-card{background:var(--card);border-radius:14px;padding:22px;border:1px solid var(--border)}
.info-card-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;padding-bottom:12px;border-bottom:1px solid var(--border)}
.info-card-title-left{display:flex;align-items:center;gap:8px}
.info-card-title svg{width:16px;height:16px;color:var(--gold)}
.edit-btn{background:none;border:none;color:var(--gold);cursor:pointer;font-size:12px;padding:4px 8px;border-radius:6px;transition:all .2s}
.edit-btn:hover{background:var(--gold-bg)}
.info-row{display:flex;flex-direction:column;gap:3px;padding:10px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none;padding-bottom:0}
.info-label{font-size:11.5px;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.info-value{font-size:14px;color:var(--text);font-weight:500}
.info-value.empty{color:var(--text-light);font-style:italic;font-weight:400}
/* MODAL */
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center}
.modal-content{background:var(--card);border-radius:16px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;animation:fadeUp 0.3s ease}
.modal-header{padding:20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:20px}
.modal-footer{padding:20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px}
.modal-close{background:none;border:none;font-size:24px;cursor:pointer;color:var(--text-light)}
.form-group{margin-bottom:16px}
.form-group label{display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--text)}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--gold)}
.btn-submit{padding:10px 20px;background:var(--gold);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer}
.btn-cancel{padding:10px 20px;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;color:var(--text);cursor:pointer}
/* COMPTES ÉPARGNE */
.compte-card{background:var(--bg);border:1.5px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px}
.compte-card:last-child{margin-bottom:0}
.compte-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.compte-type{font-size:13px;font-weight:700;color:var(--text)}
.compte-num{font-size:11px;color:var(--text-light)}
.compte-solde{font-size:22px;font-weight:800;color:var(--gold)}
.compte-solde-lbl{font-size:11px;color:var(--text-light)}
.compte-details{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.compte-detail-item{display:flex;flex-direction:column;gap:2px}
.compte-detail-lbl{font-size:11px;color:var(--text-light)}
.compte-detail-val{font-size:13px;font-weight:600;color:var(--text)}
/* MOUVEMENTS */
.mvt-item{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)}
.mvt-item:last-child{border-bottom:none}
.mvt-type{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500}
.mvt-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.mvt-date{font-size:11.5px;color:var(--text-light)}
.mvt-montant{font-size:14px;font-weight:700}
.mvt-plus{color:#2E7D32}
.mvt-minus{color:#C62828}
/* CRÉDITS */
.credit-item{background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px}
.credit-item:last-child{margin-bottom:0}
/* STATUT ACTION */
.statut-form{display:flex;flex-direction:column;gap:10px}
.statut-form select,.statut-form textarea{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none}
.btn-save{padding:11px 22px;background:var(--gold);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-save:hover{background:#b8922e}
/* TOPBAR MOBILE */
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .4s ease both}
@media(max-width:1024px){.fiche-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .fiche-header{flex-direction:column;align-items:flex-start}
}
</style>
</head>
<body>

<div class="topbar">
  <img src="asset/logosansnom.png" alt="MA2E" width="75" height="45">
  <button class="hamburger" onclick="toggleSidebar()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="logo"><img src="asset/logosansnom.png" alt="MA2E" width="75" height="45"></div>
  <nav class="nav">
    <a class="nav-item" href="dashboardAdminstrateur.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard
    </a>
    <a class="nav-item" href="TraitementCAndidat.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Candidats
    </a>
    <a class="nav-item active" href="membres.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Membres
    </a>
    <a class="nav-item" href="ajouter_membre.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
      Ajouter un membre
    </a>
    <a class="nav-item" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>Déconnexion
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

<main class="main">

  <!-- BREADCRUMB -->
  <div class="breadcrumb fadeUp">
    <a href="dashboardAdminstrateur.php">Dashboard</a>
    <span class="sep">›</span>
    <a href="membres.php">Membres</a>
    <span class="sep">›</span>
    <span class="current"><?php echo htmlspecialchars($m['nom'].' '.$m['prenoms']); ?></span>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <i class="fas fa-check-circle"></i>
    <?php echo $success; ?>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <i class="fas fa-exclamation-triangle"></i>
    <?php echo htmlspecialchars($error); ?>
  </div>
  <?php endif; ?>

  <!-- HEADER FICHE -->
  <div class="fiche-header fadeUp">
    <div class="fiche-avatar"><?php echo strtoupper(substr($m['nom'],0,1).substr($m['prenoms'],0,1)); ?></div>
    <div style="flex:1">
      <div class="fiche-nom"><?php echo htmlspecialchars($m['nom'].' '.$m['prenoms']); ?></div>
      <div class="fiche-meta">
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
          <?php echo htmlspecialchars($m['societe_code'].' — '.$m['societe_nom']); ?>
        </span>
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <?php echo htmlspecialchars($m['email']); ?>
        </span>
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.63A2 2 0 012 .18h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
          <?php echo htmlspecialchars($m['telephone'] ?? '—'); ?>
        </span>
        <?php
        $badge_class = match($m['statut']) {
            'actif'     => 'badge-actif',
            'suspendu'  => 'badge-suspendu',
            'desactive' => 'badge-desactive',
            'sorti'     => 'badge-sorti',
            default     => 'badge-actif'
        };
        $statut_label = match($m['statut']) {
            'actif'     => 'Actif',
            'suspendu'  => 'Suspendu',
            'desactive' => 'Désactivé',
            'sorti'     => 'Sorti',
            default     => ucfirst($m['statut'])
        };
        ?>
        <span class="badge <?php echo $badge_class; ?>"><?php echo $statut_label; ?></span>
        <code style="background:var(--gold-bg);color:var(--gold);padding:3px 9px;border-radius:5px;font-size:12px;font-weight:700">
          <?php echo htmlspecialchars($m['numero_membre']); ?>
        </code>
      </div>
    </div>
    <a href="membres.php" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;font-size:13px;color:var(--text-light);text-decoration:none;white-space:nowrap">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
  </div>

  <div class="fiche-grid fadeUp">

    <!-- COLONNE GAUCHE -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <!-- Infos personnelles -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Informations personnelles
          </div>
          <button class="edit-btn" onclick="openModal('personnel')">
            <i class="fas fa-edit"></i> Modifier
          </button>
        </div>
        <div class="info-row"><div class="info-label">Nom complet</div><div class="info-value"><?php echo htmlspecialchars($m['nom'].' '.$m['prenoms']); ?></div></div>
        <div class="info-row"><div class="info-label">Sexe</div><div class="info-value <?php echo empty($m['sexe'])?'empty':''; ?>"><?php echo !empty($m['sexe'])?ucfirst($m['sexe']):'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Date de naissance</div><div class="info-value <?php echo empty($m['date_naissance'])?'empty':''; ?>"><?php echo !empty($m['date_naissance'])?date('d/m/Y',strtotime($m['date_naissance'])):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Situation matrimoniale</div><div class="info-value <?php echo empty($m['situation_matrimoniale'])?'empty':''; ?>"><?php echo !empty($m['situation_matrimoniale'])?ucfirst($m['situation_matrimoniale']):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Nombre d'enfants</div><div class="info-value"><?php echo $m['nombre_enfants']??0; ?></div></div>
        <div class="info-row"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($m['email']); ?></div></div>
        <div class="info-row"><div class="info-label">Téléphone</div><div class="info-value <?php echo empty($m['telephone'])?'empty':''; ?>"><?php echo !empty($m['telephone'])?htmlspecialchars($m['telephone']):'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Adresse</div><div class="info-value <?php echo empty($m['adresse'])?'empty':''; ?>"><?php echo !empty($m['adresse'])?htmlspecialchars($m['adresse']):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Ville</div><div class="info-value <?php echo empty($m['ville'])?'empty':''; ?>"><?php echo !empty($m['ville'])?htmlspecialchars($m['ville']):'Non renseignée'; ?></div></div>
      </div>

      <!-- Infos professionnelles -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            Informations professionnelles
          </div>
          <button class="edit-btn" onclick="openModal('professionnel')">
            <i class="fas fa-edit"></i> Modifier
          </button>
        </div>
        <div class="info-row"><div class="info-label">Société</div><div class="info-value"><?php echo htmlspecialchars($m['societe_code'].' — '.$m['societe_nom']); ?></div></div>
        <div class="info-row"><div class="info-label">Matricule professionnel</div><div class="info-value <?php echo empty($m['matricule_pro'])?'empty':''; ?>"><?php echo !empty($m['matricule_pro'])?htmlspecialchars($m['matricule_pro']):'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Catégorie</div><div class="info-value"><?php echo $categories_labels[$m['categorie']] ?? ucfirst($m['categorie']); ?></div></div>
        <div class="info-row"><div class="info-label">Salaire net</div><div class="info-value <?php echo empty($m['salaire_net'])||$m['salaire_net']==0?'empty':''; ?>"><?php echo $m['salaire_net']>0?number_format($m['salaire_net'],0,',',' ').' F CFA':'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Quotité cessible</div><div class="info-value <?php echo empty($m['quotite_cessible'])||$m['quotite_cessible']==0?'empty':''; ?>"><?php echo $m['quotite_cessible']>0?number_format($m['quotite_cessible'],0,',',' ').' F CFA':'Non calculée'; ?></div></div>
        <div class="info-row"><div class="info-label">Cotisation mensuelle</div><div class="info-value" style="color:var(--gold);font-weight:700"><?php echo number_format($m['cotisation_mensuelle'],0,',',' ').' F CFA'; ?></div></div>
        <div class="info-row"><div class="info-label">Date d'adhésion</div><div class="info-value"><?php echo date('d/m/Y', strtotime($m['date_adhesion'])); ?></div></div>
      </div>

      <!-- Compte utilisateur -->
      <?php if ($user): ?>
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Compte de connexion
          </div>
          <button class="edit-btn" onclick="openModal('password')">
            <i class="fas fa-key"></i> Changer mot de passe
          </button>
        </div>
        <div class="info-row"><div class="info-label">Matricule</div><div class="info-value"><code style="background:var(--gold-bg);color:var(--gold);padding:2px 8px;border-radius:5px;font-size:13px"><?php echo htmlspecialchars($user['matricule']); ?></code></div></div>
        <div class="info-row"><div class="info-label">Statut compte</div><div class="info-value"><?php echo $user['actif'] ? '<span style="color:#2E7D32">✅ Actif</span>' : '<span style="color:#C62828">❌ Désactivé</span>'; ?></div></div>
        <div class="info-row"><div class="info-label">Mot de passe temporaire</div><div class="info-value"><?php echo $user['mot_de_passe_temporaire'] ? '<span style="color:#F57F17">⚠️ Oui — à changer</span>' : '<span style="color:#2E7D32">✅ Changé</span>'; ?></div></div>
        <div class="info-row"><div class="info-label">Dernière connexion</div><div class="info-value <?php echo empty($user['derniere_connexion'])?'empty':''; ?>"><?php echo !empty($user['derniere_connexion'])?date('d/m/Y à H:i',strtotime($user['derniere_connexion'])):'Jamais connecté'; ?></div></div>
      </div>
      <?php endif; ?>

    </div>

    <!-- COLONNE DROITE -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <!-- Comptes épargne -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            Comptes épargne
          </div>
        </div>
        <?php if (empty($comptes)): ?>
          <p style="color:var(--text-light);font-size:13px;text-align:center;padding:20px 0">Aucun compte épargne</p>
        <?php else: ?>
          <?php foreach ($comptes as $cpt): ?>
          <div class="compte-card">
            <div class="compte-header">
              <div>
                <div class="compte-type"><?php echo htmlspecialchars($cpt['type_libelle']); ?></div>
                <div class="compte-num"><?php echo htmlspecialchars($cpt['numero_compte']); ?></div>
              </div>
              <span style="background:<?php echo $cpt['statut']==='actif'?'#E8F5E9':'#FFEBEE'; ?>;color:<?php echo $cpt['statut']==='actif'?'#2E7D32':'#C62828'; ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600">
                <?php echo ucfirst($cpt['statut']); ?>
              </span>
            </div>
            <div class="compte-solde"><?php echo number_format($cpt['solde'],0,',',' '); ?> F CFA</div>
            <div class="compte-solde-lbl">Solde total</div>
            <div class="compte-details">
              <div class="compte-detail-item">
                <span class="compte-detail-lbl">Disponible</span>
                <span class="compte-detail-val" style="color:#2E7D32"><?php echo number_format($cpt['solde_disponible'],0,',',' '); ?> F</span>
              </div>
              <div class="compte-detail-item">
                <span class="compte-detail-lbl">Bloqué</span>
                <span class="compte-detail-val" style="color:#F57F17"><?php echo number_format($cpt['solde_bloque'],0,',',' '); ?> F</span>
              </div>
              <div class="compte-detail-item">
                <span class="compte-detail-lbl">Taux intérêt</span>
                <span class="compte-detail-val"><?php echo $cpt['taux_interet']; ?>%</span>
              </div>
              <div class="compte-detail-item">
                <span class="compte-detail-lbl">Ouvert le</span>
                <span class="compte-detail-val"><?php echo date('d/m/Y', strtotime($cpt['date_ouverture'])); ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($plan): ?>
        <div style="margin-top:14px;background:#E3F2FD;border-radius:10px;padding:14px">
          <div style="font-size:12px;font-weight:700;color:#1565C0;margin-bottom:8px">📅 Plan épargne 24 mois</div>
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
            <span style="color:#1976D2">Mois cotisés</span>
            <strong style="color:#0D47A1"><?php echo $plan['mois_cotises']; ?> / 24</strong>
          </div>
          <div style="height:6px;background:#BBDEFB;border-radius:3px;overflow:hidden">
            <div style="height:100%;width:<?php echo round($plan['mois_cotises']/24*100); ?>%;background:#2196F3;border-radius:3px"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:#1976D2;margin-top:4px">
            <span>Début : <?php echo date('d/m/Y', strtotime($plan['date_debut'])); ?></span>
            <span>Fin : <?php echo date('d/m/Y', strtotime($plan['date_fin'])); ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Derniers mouvements -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            Derniers mouvements
          </div>
        </div>
        <?php if (empty($mouvements)): ?>
          <p style="color:var(--text-light);font-size:13px;text-align:center;padding:20px 0">Aucun mouvement enregistré</p>
        <?php else: ?>
          <?php foreach ($mouvements as $mv):
            $is_credit = in_array($mv['type'], ['depot','interet','remboursement','deblocage_garantie']);
            $type_labels = [
              'depot'               => 'Dépôt',
              'retrait'             => 'Retrait',
              'virement'            => 'Virement',
              'interet'             => 'Intérêts',
              'penalite'            => 'Pénalité',
              'remboursement'       => 'Remboursement',
              'prelevement_salaire' => 'Prélèvement salaire',
              'garantie'            => 'Dépôt garantie',
              'deblocage_garantie'  => 'Déblocage garantie',
            ];
          ?>
          <div class="mvt-item">
            <div>
              <div class="mvt-type">
                <span class="mvt-dot" style="background:<?php echo $is_credit?'#4CAF50':'#F44336'; ?>"></span>
                <?php echo $type_labels[$mv['type']] ?? ucfirst($mv['type']); ?>
              </div>
              <div class="mvt-date"><?php echo date('d/m/Y', strtotime($mv['date_operation'])); ?> — <?php echo htmlspecialchars($mv['fait_par']); ?></div>
            </div>
            <div class="mvt-montant <?php echo $is_credit?'mvt-plus':'mvt-minus'; ?>">
              <?php echo $is_credit?'+':'-'; ?><?php echo number_format($mv['montant'],0,',',' '); ?> F
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Crédits -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Crédits
          </div>
        </div>
        <?php if (empty($credits)): ?>
          <p style="color:var(--text-light);font-size:13px;text-align:center;padding:20px 0">Aucun crédit</p>
        <?php else: ?>
          <?php foreach ($credits as $cr):
            $cr_badge = match($cr['statut']) {
              'en_cours'  => ['bg'=>'#E3F2FD','color'=>'#1565C0','label'=>'En cours'],
              'solde'     => ['bg'=>'#E8F5E9','color'=>'#2E7D32','label'=>'Soldé'],
              'en_retard' => ['bg'=>'#FFEBEE','color'=>'#C62828','label'=>'En retard'],
              default     => ['bg'=>'#F3F4F6','color'=>'#6B7280','label'=>ucfirst($cr['statut'])],
            };
          ?>
          <div class="credit-item">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
              <div>
                <div style="font-size:13px;font-weight:700"><?php echo htmlspecialchars($cr['type_libelle']); ?></div>
                <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($cr['numero_credit']); ?></div>
              </div>
              <span style="background:<?php echo $cr_badge['bg']; ?>;color:<?php echo $cr_badge['color']; ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600">
                <?php echo $cr_badge['label']; ?>
              </span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">
              <div><span style="color:var(--text-light)">Montant accordé</span><br><strong><?php echo number_format($cr['montant_accorde'],0,',',' '); ?> F</strong></div>
              <div><span style="color:var(--text-light)">Capital restant</span><br><strong style="color:var(--red)"><?php echo number_format($cr['capital_restant_du'],0,',',' '); ?> F</strong></div>
              <div><span style="color:var(--text-light)">Mensualité</span><br><strong><?php echo number_format($cr['mensualite'],0,',',' '); ?> F</strong></div>
              <div><span style="color:var(--text-light)">Taux annuel</span><br><strong><?php echo $cr['taux_annuel']; ?>%</strong></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Changer statut -->
      <div class="info-card">
        <div class="info-card-title">
          <div class="info-card-title-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Modifier le statut
          </div>
        </div>
        <form method="POST" class="statut-form">
          <input type="hidden" name="action" value="changer_statut">
          <select name="nouveau_statut">
            <option value="actif"     <?php echo $m['statut']==='actif'?'selected':''; ?>>Actif</option>
            <option value="suspendu"  <?php echo $m['statut']==='suspendu'?'selected':''; ?>>Suspendu</option>
            <option value="desactive" <?php echo $m['statut']==='desactive'?'selected':''; ?>>Désactivé</option>
            <option value="sorti"     <?php echo $m['statut']==='sorti'?'selected':''; ?>>Sorti</option>
          </select>
          <select name="motif_sortie" id="motifSortieSelect">
            <option value="">Motif de sortie (si sorti)</option>
            <option value="demission"   <?php echo ($m['motif_sortie']==='demission')?'selected':''; ?>>Démission</option>
            <option value="licenciement"<?php echo ($m['motif_sortie']==='licenciement')?'selected':''; ?>>Licenciement</option>
            <option value="volontaire"  <?php echo ($m['motif_sortie']==='volontaire')?'selected':''; ?>>Volontaire</option>
          </select>
          <button type="submit" class="btn-save"
            onclick="return confirm('Confirmer le changement de statut ?')">
            Enregistrer le statut
          </button>
        </form>
      </div>

    </div>
  </div>

</main>

<!-- MODAL ÉDITION PERSONNELLE -->
<div id="modalPersonnel" class="modal">
  <div class="modal-content">
    <form method="POST">
      <input type="hidden" name="action" value="edit_personnel">
      <div class="modal-header">
        <h3>Modifier les informations personnelles</h3>
        <button type="button" class="modal-close" onclick="closeModal('personnel')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Nom *</label>
          <input type="text" name="nom" value="<?php echo htmlspecialchars($m['nom']); ?>" required>
        </div>
        <div class="form-group">
          <label>Prénoms *</label>
          <input type="text" name="prenoms" value="<?php echo htmlspecialchars($m['prenoms']); ?>" required>
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" value="<?php echo htmlspecialchars($m['email']); ?>" required>
        </div>
        <div class="form-group">
          <label>Téléphone</label>
          <input type="text" name="telephone" value="<?php echo htmlspecialchars($m['telephone'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Sexe</label>
          <select name="sexe">
            <option value="">Non renseigné</option>
            <option value="homme" <?php echo ($m['sexe']??'')=='homme'?'selected':''; ?>>Homme</option>
            <option value="femme" <?php echo ($m['sexe']??'')=='femme'?'selected':''; ?>>Femme</option>
          </select>
        </div>
        <div class="form-group">
          <label>Date de naissance</label>
          <input type="date" name="date_naissance" value="<?php echo $m['date_naissance'] ?? ''; ?>">
        </div>
        <div class="form-group">
          <label>Situation matrimoniale</label>
          <select name="situation_matrimoniale">
            <option value="">Non renseignée</option>
            <option value="celibataire" <?php echo ($m['situation_matrimoniale']??'')=='celibataire'?'selected':''; ?>>Célibataire</option>
            <option value="marie" <?php echo ($m['situation_matrimoniale']??'')=='marie'?'selected':''; ?>>Marié(e)</option>
            <option value="divorce" <?php echo ($m['situation_matrimoniale']??'')=='divorce'?'selected':''; ?>>Divorcé(e)</option>
            <option value="veuf" <?php echo ($m['situation_matrimoniale']??'')=='veuf'?'selected':''; ?>>Veuf/Veuve</option>
          </select>
        </div>
        <div class="form-group">
          <label>Nombre d'enfants</label>
          <input type="number" name="nombre_enfants" value="<?php echo $m['nombre_enfants'] ?? 0; ?>" min="0">
        </div>
        <div class="form-group">
          <label>Adresse</label>
          <textarea name="adresse" rows="2"><?php echo htmlspecialchars($m['adresse'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
          <label>Ville</label>
          <input type="text" name="ville" value="<?php echo htmlspecialchars($m['ville'] ?? ''); ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('personnel')">Annuler</button>
        <button type="submit" class="btn-submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL ÉDITION PROFESSIONNELLE -->
<div id="modalProfessionnel" class="modal">
  <div class="modal-content">
    <form method="POST">
      <input type="hidden" name="action" value="edit_professionnel">
      <div class="modal-header">
        <h3>Modifier les informations professionnelles</h3>
        <button type="button" class="modal-close" onclick="closeModal('professionnel')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Société *</label>
          <select name="societe_id" required>
            <?php foreach ($societes as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id'] == $m['societe_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Matricule professionnel</label>
          <input type="text" name="matricule_pro" value="<?php echo htmlspecialchars($m['matricule_pro'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Catégorie *</label>
          <select name="categorie" required>
            <?php foreach ($categories_labels as $key => $label): ?>
              <option value="<?= $key ?>" <?= $key == $m['categorie'] ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Salaire net (F CFA) *</label>
          <input type="number" name="salaire_net" value="<?php echo $m['salaire_net']; ?>" required>
        </div>
        <p class="info-text" style="font-size:12px;color:var(--text-light);margin-top:-10px">
          ⚠️ La cotisation mensuelle sera automatiquement recalculée selon la catégorie (Directeur:10k, Cadre:5k, Maîtrise sup:3k, Autres:1.5k FCFA)
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('professionnel')">Annuler</button>
        <button type="submit" class="btn-submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL CHANGEMENT MOT DE PASSE -->
<div id="modalPassword" class="modal">
  <div class="modal-content">
    <form method="POST">
      <input type="hidden" name="action" value="edit_password">
      <div class="modal-header">
        <h3>Changer le mot de passe</h3>
        <button type="button" class="modal-close" onclick="closeModal('password')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Nouveau mot de passe *</label>
          <input type="password" name="new_password" required minlength="6">
          <small style="font-size:11px;color:var(--text-light)">Minimum 6 caractères</small>
        </div>
        <div class="form-group">
          <label>Confirmer le mot de passe *</label>
          <input type="password" name="confirm_password" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('password')">Annuler</button>
        <button type="submit" class="btn-submit">Changer le mot de passe</button>
      </div>
    </form>
  </div>
</div>

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

function openModal(type) {
  const modal = document.getElementById('modal' + type.charAt(0).toUpperCase() + type.slice(1));
  if (modal) modal.style.display = 'flex';
}

function closeModal(type) {
  const modal = document.getElementById('modal' + type.charAt(0).toUpperCase() + type.slice(1));
  if (modal) modal.style.display = 'none';
}

// Fermer modal en cliquant en dehors
window.onclick = function(event) {
  if (event.target.classList.contains('modal')) {
    event.target.style.display = 'none';
  }
}

const saved = localStorage.getItem('theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>