<?php
require_once '../connexion/auth_check.php';
checkRole('administrateur');
require_once '../config/database.php';
require_once '../config/mailer.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: TraitementCAndidat.php'); exit; }

$stmt = $pdo->prepare("
    SELECT c.*, s.nom AS societe_nom, s.code AS societe_code
    FROM candidats c
    JOIN societes s ON c.societe_id = s.id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) { header('Location: TraitementCAndidat.php'); exit; }

$success      = '';
$error        = '';
$email_envoye = false;

$cotisations_par_categorie = [
    'directeur'           => 10000,
    'cadre'               => 5000,
    'maitrise_superieure' => 3000,
    'maitrise_1_2'        => 1500,
    'employe_ouvrier'     => 1500,
];
$categories_labels = [
    'directeur'           => 'Directeur',
    'cadre'               => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Supérieure',
    'maitrise_1_2'        => 'Maîtrise 1 et 2',
    'employe_ouvrier'     => 'Employé / Ouvrier',
];

$lien_connexion = 'http://' . $_SERVER['HTTP_HOST'] . '/SOUTENANCE_pro/connexion/pageconnexion.php';

// ============================================================
// ACTION : ACCEPTER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accepter') {

    $categorie   = $_POST['categorie']   ?? '';
    $salaire_net = (float)($_POST['salaire_net'] ?? 0);

    if ($c['statut'] !== 'en_attente') {
        $error = "Ce dossier a déjà été traité.";
    } elseif (!array_key_exists($categorie, $cotisations_par_categorie)) {
        $error = "Veuillez sélectionner la catégorie professionnelle du membre.";
    } else {
        try {
            $pdo->beginTransaction();

            $cotisation_mensuelle = $cotisations_par_categorie[$categorie];
            $quotite_cessible     = $salaire_net > 0 ? round($salaire_net / 3, 2) : 0;

            // 1. Générer numero_membre
            $annee    = date('Y');
            $base_num = 'MA2E-'.$annee.'-';
            $last_s   = $pdo->prepare("SELECT numero_membre FROM membres WHERE numero_membre LIKE ? ORDER BY numero_membre DESC LIMIT 1");
            $last_s->execute([$base_num.'%']);
            $last_num = $last_s->fetchColumn();
            $parts_n  = explode('-', $last_num ?: '');
            $seq      = $last_num ? (int)end($parts_n) + 1 : 1;
            $numero_membre = $base_num . str_pad($seq, 3, '0', STR_PAD_LEFT);

            // 2. Insérer dans membres
            $pdo->prepare("
                INSERT INTO membres
                    (candidat_id, societe_id, numero_membre, nom, prenoms, matricule_pro,
                     sexe, situation_matrimoniale, email, telephone,
                     adresse, ville, pays, nombre_enfants,
                     date_naissance, numero_piece_identite, photo_path,
                     categorie, salaire_net, quotite_cessible, cotisation_mensuelle,
                     date_adhesion, statut)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $c['id'], $c['societe_id'], $numero_membre,
                $c['nom'], $c['prenoms'], $c['matricule_pro'],
                $c['sexe'], $c['situation_matrimoniale'],
                $c['email'], $c['telephone'],
                $c['adresse'], $c['ville'], $c['pays'] ?? "Côte d'Ivoire",
                $c['nombre_enfants'], $c['date_naissance'],
                $c['numero_piece_identite'], $c['photo1_path'],
                $categorie, $salaire_net, $quotite_cessible, $cotisation_mensuelle,
                date('Y-m-d'), 'actif'
            ]);
            $membre_id = $pdo->lastInsertId();

            // 3. Générer matricule utilisateur
            $base_mat = 'MBR-'.$annee.'-';
            $last_mat = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE matricule LIKE ? ORDER BY matricule DESC LIMIT 1");
            $last_mat->execute([$base_mat.'%']);
            $last_m   = $last_mat->fetchColumn();
            $parts_m  = explode('-', $last_m ?: '');
            $seq_m    = $last_m ? (int)end($parts_m) + 1 : 1;
            $matricule_user = $base_mat . str_pad($seq_m, 3, '0', STR_PAD_LEFT);

            // 4. Mot de passe temporaire
            $mdp_temp = ucfirst(strtolower($c['nom'])) . rand(100, 999);
            $hash     = password_hash($mdp_temp, PASSWORD_BCRYPT);

            // 5. Créer compte utilisateur
            $pdo->prepare("
                INSERT INTO utilisateurs
                    (membre_id, matricule, nom, prenoms, email, password, role, actif, mot_de_passe_temporaire)
                VALUES (?,?,?,?,?,?,'membre',1,1)
            ")->execute([$membre_id, $matricule_user, $c['nom'], $c['prenoms'], $c['email'], $hash]);

            // Dans la partie acceptation du candidat (après la création du compte Expresse)

// 6. Créer compte épargne Expresse AVEC retenue source
$type_exp = $pdo->query("SELECT id FROM types_compte_epargne WHERE code='expresse' LIMIT 1")->fetchColumn();
$num_cpt_exp = 'EXP-'.$annee.'-'.str_pad($membre_id, 5, '0', STR_PAD_LEFT);

$pdo->prepare("
    INSERT INTO comptes_epargne
        (membre_id, type_compte_id, numero_compte, solde, solde_bloque, solde_disponible,
         taux_interet, statut, date_ouverture, retenue_source, versement_mensuel)
    VALUES (?,?,?,0,0,0,3.50,'actif',?,1,?)
")->execute([$membre_id, $type_exp, $num_cpt_exp, date('Y-m-d'), $cotisation_mensuelle]);
$compte_exp_id = $pdo->lastInsertId();

// 6.1 CRÉER LE COMPTE CRÉDITS (NOUVEAU)
$type_credits = $pdo->query("SELECT id FROM types_compte_epargne WHERE code='credits' LIMIT 1")->fetchColumn();
$num_cpt_credits = 'CRD-'.$annee.'-'.str_pad($membre_id, 5, '0', STR_PAD_LEFT);

$pdo->prepare("
    INSERT INTO comptes_epargne
        (membre_id, type_compte_id, numero_compte, solde, solde_bloque, solde_disponible,
         taux_interet, statut, date_ouverture, retenue_source)
    VALUES (?,?,?,0,0,0,0,'actif',?,0)
")->execute([$membre_id, $type_credits, $num_cpt_credits, date('Y-m-d')]);

// 7. Créer plan épargne 24 mois
// ... code existant ...

            // 7. Créer plan épargne 24 mois
            $pdo->prepare("
                INSERT INTO plans_epargne_expresse
                    (compte_id, membre_id, montant_mensuel, date_debut, date_fin, mois_cotises, statut)
                VALUES (?,?,?,?,?,0,'en_cours')
            ")->execute([
                $compte_exp_id, $membre_id, $cotisation_mensuelle,
                date('Y-m-d'),
                date('Y-m-d', strtotime('+24 months'))
            ]);

            // 8. Mettre à jour statut candidat
            $pdo->prepare("UPDATE candidats SET statut='accepte', date_traitement=NOW() WHERE id=?")
                ->execute([$id]);

            $pdo->commit();

            // 9. Envoyer email de bienvenue
            $cotisation_fmt = number_format($cotisation_mensuelle, 0, ',', ' ') . ' F CFA';
            $solde_min_fmt  = number_format($cotisation_mensuelle * 24, 0, ',', ' ') . ' F CFA';

            $email_envoye = envoyerEmailAcceptation(
                $c['email'],
                $c['nom'],
                $c['prenoms'],
                $numero_membre,
                $matricule_user,
                $mdp_temp,
                $cotisation_fmt,
                $solde_min_fmt,
                $lien_connexion
            );

            $success = "
                <strong>Dossier accepté avec succès !</strong><br>
                Numéro membre : <strong>$numero_membre</strong><br>
                Catégorie : <strong>{$categories_labels[$categorie]}</strong><br>
                Cotisation mensuelle : <strong>$cotisation_fmt</strong><br>
                Matricule de connexion : <strong>$matricule_user</strong><br>
                Mot de passe temporaire : <strong>$mdp_temp</strong><br>
                " . ($email_envoye
                    ? "<span style='color:#2E7D32'>✅ Email de bienvenue envoyé à {$c['email']}</span>"
                    : "<span style='color:#F57F17'>⚠️ Email non envoyé — communiquer les infos manuellement</span>"
                );

            $stmt->execute([$id]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur lors du traitement : " . $e->getMessage();
        }
    }
}

// ============================================================
// ACTION : REFUSER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refuser') {
    $motif = trim($_POST['motif_refus'] ?? '');
    if (empty($motif)) {
        $error = "Veuillez indiquer un motif de refus.";
    } elseif ($c['statut'] !== 'en_attente') {
        $error = "Ce dossier a déjà été traité.";
    } else {
        $pdo->prepare("UPDATE candidats SET statut='refuse', motif_refus=?, date_traitement=NOW() WHERE id=?")
            ->execute([$motif, $id]);

        $email_envoye = envoyerEmailRefus(
            $c['email'],
            $c['nom'],
            $c['prenoms'],
            $motif
        );

        $success = "Dossier refusé. Le motif a été enregistré. "
            . ($email_envoye
                ? "<span style='color:#2E7D32'>✅ Email envoyé au candidat.</span>"
                : "<span style='color:#F57F17'>⚠️ Email non envoyé.</span>"
            );

        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Infos membre si accepté
$membre = null;
if ($c['statut'] === 'accepte') {
    $m = $pdo->prepare("SELECT * FROM membres WHERE candidat_id = ?");
    $m->execute([$id]);
    $membre = $m->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fiche candidat — <?php echo htmlspecialchars($c['nom'].' '.$c['prenoms']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:200px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#888;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100;transition:transform .3s}
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
.breadcrumb a{color:var(--text-light);text-decoration:none;transition:color .2s}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .current{color:var(--text);font-weight:600}
.fiche-header{background:var(--card);border-radius:16px;padding:28px;border:1px solid var(--border);display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.fiche-avatar{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:white;flex-shrink:0;background:var(--gold)}
.fiche-avatar img{width:80px;height:80px;border-radius:50%;object-fit:cover}
.fiche-nom{font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text);margin-bottom:6px}
.fiche-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.fiche-meta-item{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-light)}
.fiche-meta-item svg{width:14px;height:14px}
.badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-accepte{background:#E8F5E9;color:#2E7D32}
.badge-refuse{background:#FFEBEE;color:#C62828}
.alert{padding:16px 20px;border-radius:12px;font-size:14px;margin-bottom:4px;display:flex;align-items:flex-start;gap:12px;animation:fadeUp .3s ease}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert svg{width:18px;height:18px;flex-shrink:0;margin-top:1px}
.fiche-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.info-card{background:var(--card);border-radius:14px;padding:22px;border:1px solid var(--border)}
.info-card-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:8px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.info-card-title svg{width:16px;height:16px;color:var(--gold)}
.info-row{display:flex;flex-direction:column;gap:3px;padding:10px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none;padding-bottom:0}
.info-label{font-size:11.5px;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.info-value{font-size:14px;color:var(--text);font-weight:500}
.info-value.empty{color:var(--text-light);font-style:italic;font-weight:400}
.docs-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.doc-item{background:var(--bg);border:1.5px solid var(--border);border-radius:12px;padding:16px;text-align:center;transition:all .2s;text-decoration:none;display:block}
.doc-item:hover{border-color:var(--gold);background:var(--gold-bg);transform:translateY(-2px)}
.doc-item svg{width:32px;height:32px;color:var(--gold);margin-bottom:8px}
.doc-item .doc-name{font-size:12.5px;font-weight:600;color:var(--text);margin-bottom:4px}
.doc-item .doc-type{font-size:11px;color:var(--text-light)}
.doc-missing{opacity:.5;cursor:default}
.doc-missing:hover{transform:none;border-color:var(--border);background:var(--bg)}
.actions-card{background:var(--card);border-radius:14px;padding:22px;border:1px solid var(--border)}
.actions-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px}
.btn-accepter{width:100%;padding:13px;background:linear-gradient(135deg,#4CAF50,#388E3C);color:white;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px}
.btn-accepter:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(76,175,80,.3)}
.btn-refuser{width:100%;padding:12px;background:transparent;color:var(--red);border:2px solid #FFCDD2;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-refuser:hover{background:var(--red);color:white;border-color:var(--red)}
.motif-wrap{display:none;margin-top:10px}
.motif-wrap.show{display:block}
.motif-wrap textarea{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;resize:vertical;min-height:90px;outline:none;transition:border-color .2s}
.motif-wrap textarea:focus{border-color:var(--red)}
.btn-confirm-refus{width:100%;margin-top:10px;padding:11px;background:var(--red);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-confirm-refus:hover{background:#C62828}
.membre-info-box{background:#E8F5E9;border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin-top:12px}
.membre-info-box .titre{font-size:13px;font-weight:700;color:#2E7D32;margin-bottom:10px}
.membre-info-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid #C8E6C9}
.membre-info-row:last-child{border-bottom:none}
.membre-info-row span:first-child{color:#4CAF50;font-weight:500}
.membre-info-row span:last-child{font-weight:600;color:#1B5E20}
.topbar{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.hamburger{background:none;border:none;cursor:pointer;padding:6px;color:var(--text)}
.hamburger svg{width:22px;height:22px}
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
  .fiche-header{flex-direction:column;align-items:flex-start;gap:16px}
  .docs-grid{grid-template-columns:1fr 1fr}
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
    <a class="nav-item active" href="TraitementCAndidat.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>Candidats
    </a>
    <a class="nav-item" href="membres.php">
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

  <div class="breadcrumb fadeUp">
    <a href="dashboardAdminstrateur.php">Dashboard</a>
    <span class="sep">›</span>
    <a href="TraitementCAndidat.php">Candidatures</a>
    <span class="sep">›</span>
    <span class="current"><?php echo htmlspecialchars($c['nom'].' '.$c['prenoms']); ?></span>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <div><?php echo $success; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><?php echo htmlspecialchars($error); ?></div>
  </div>
  <?php endif; ?>

  <!-- HEADER FICHE -->
  <div class="fiche-header fadeUp">
    <div class="fiche-avatar">
      <?php if (!empty($c['photo1_path']) && file_exists($c['photo1_path'])): ?>
        <img src="voir_document.php?fichier=<?php echo urlencode(basename($c['photo1_path'])); ?>" alt="Photo">
      <?php else: ?>
        <?php echo strtoupper(substr($c['nom'],0,1).substr($c['prenoms'],0,1)); ?>
      <?php endif; ?>
    </div>
    <div style="flex:1">
      <div class="fiche-nom"><?php echo htmlspecialchars($c['nom'].' '.$c['prenoms']); ?></div>
      <div class="fiche-meta">
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <?php echo htmlspecialchars($c['societe_code'].' — '.$c['societe_nom']); ?>
        </span>
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <?php echo htmlspecialchars($c['email']); ?>
        </span>
        <span class="fiche-meta-item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.63A2 2 0 012 .18h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
          <?php echo htmlspecialchars($c['telephone'] ?? '—'); ?>
        </span>
        <span class="badge badge-<?php echo $c['statut']==='en_attente'?'attente':$c['statut']; ?>">
          <?php echo $c['statut']==='en_attente'?'En attente':($c['statut']==='accepte'?'Accepté':'Refusé'); ?>
        </span>
        <span style="font-size:12px;color:var(--text-light)">
          Soumis le <?php echo date('d/m/Y à H:i', strtotime($c['date_soumission'])); ?>
        </span>
      </div>
    </div>
    <a href="TraitementCAndidat.php" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;font-size:13px;color:var(--text-light);text-decoration:none;white-space:nowrap">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
  </div>

  <div class="fiche-grid fadeUp">

    <!-- COLONNE GAUCHE -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <div class="info-card">
        <div class="info-card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Informations personnelles
        </div>
        <div class="info-row"><div class="info-label">Nom complet</div><div class="info-value"><?php echo htmlspecialchars($c['nom'].' '.$c['prenoms']); ?></div></div>
        <div class="info-row"><div class="info-label">Sexe</div><div class="info-value <?php echo empty($c['sexe'])?'empty':''; ?>"><?php echo !empty($c['sexe'])?ucfirst($c['sexe']):'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Date de naissance</div><div class="info-value <?php echo empty($c['date_naissance'])?'empty':''; ?>"><?php echo !empty($c['date_naissance'])?date('d/m/Y',strtotime($c['date_naissance'])):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Situation matrimoniale</div><div class="info-value <?php echo empty($c['situation_matrimoniale'])?'empty':''; ?>"><?php echo !empty($c['situation_matrimoniale'])?ucfirst($c['situation_matrimoniale']):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Nombre d'enfants</div><div class="info-value"><?php echo $c['nombre_enfants']??0; ?></div></div>
        <div class="info-row"><div class="info-label">N° pièce d'identité</div><div class="info-value <?php echo empty($c['numero_piece_identite'])?'empty':''; ?>"><?php echo !empty($c['numero_piece_identite'])?htmlspecialchars($c['numero_piece_identite']):'Non renseigné'; ?></div></div>
      </div>

      <div class="info-card">
        <div class="info-card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Coordonnées
        </div>
        <div class="info-row"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($c['email']); ?></div></div>
        <div class="info-row"><div class="info-label">Téléphone</div><div class="info-value <?php echo empty($c['telephone'])?'empty':''; ?>"><?php echo !empty($c['telephone'])?htmlspecialchars($c['telephone']):'Non renseigné'; ?></div></div>
        <div class="info-row"><div class="info-label">Adresse</div><div class="info-value <?php echo empty($c['adresse'])?'empty':''; ?>"><?php echo !empty($c['adresse'])?htmlspecialchars($c['adresse']):'Non renseignée'; ?></div></div>
        <div class="info-row"><div class="info-label">Ville</div><div class="info-value <?php echo empty($c['ville'])?'empty':''; ?>"><?php echo !empty($c['ville'])?htmlspecialchars($c['ville']):'Non renseignée'; ?></div></div>
      </div>

      <div class="info-card">
        <div class="info-card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
          Informations professionnelles
        </div>
        <div class="info-row"><div class="info-label">Société</div><div class="info-value"><?php echo htmlspecialchars($c['societe_code'].' — '.$c['societe_nom']); ?></div></div>
        <div class="info-row"><div class="info-label">Matricule professionnel</div><div class="info-value <?php echo empty($c['matricule_pro'])?'empty':''; ?>"><?php echo !empty($c['matricule_pro'])?htmlspecialchars($c['matricule_pro']):'Non renseigné'; ?></div></div>
      </div>

      <!-- DOCUMENTS -->
      <div class="info-card">
        <div class="info-card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Documents soumis
        </div>
        <div class="docs-grid">
          <?php
          $docs = [
            ['path' => $c['piece_identite_path'],      'nom' => "Pièce d'identité",     'icon' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>'],
            ['path' => $c['bulletin_salaire_path'],    'nom' => 'Bulletin de salaire',  'icon' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
            ['path' => $c['photo1_path'],              'nom' => "Photo d'identité",     'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>'],
            ['path' => $c['formulaire_adhesion_path'], 'nom' => "Formulaire d'adhésion",'icon' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'],
          ];
          foreach ($docs as $doc): ?>
          <?php if (!empty($doc['path'])): ?>
            <a href="voir_document.php?fichier=<?php echo urlencode(basename($doc['path'])); ?>" target="_blank" class="doc-item">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><?php echo $doc['icon']; ?></svg>
              <div class="doc-name"><?php echo $doc['nom']; ?></div>
              <div class="doc-type">Cliquer pour voir</div>
            </a>
          <?php else: ?>
            <div class="doc-item doc-missing">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><?php echo $doc['icon']; ?></svg>
              <div class="doc-name"><?php echo $doc['nom']; ?></div>
              <div class="doc-type">Non fourni</div>
            </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($c['statut'] === 'refuse' && !empty($c['motif_refus'])): ?>
      <div class="info-card" style="border-color:#FFCDD2">
        <div class="info-card-title" style="color:#C62828">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          Motif de refus
        </div>
        <p style="font-size:14px;color:#B71C1C;line-height:1.6"><?php echo nl2br(htmlspecialchars($c['motif_refus'])); ?></p>
        <p style="font-size:12px;color:var(--text-light);margin-top:8px">Traité le <?php echo date('d/m/Y à H:i', strtotime($c['date_traitement'])); ?></p>
      </div>
      <?php endif; ?>

    </div>

    <!-- COLONNE DROITE -->
    <div style="display:flex;flex-direction:column;gap:20px">

      <?php if ($c['statut'] === 'en_attente'): ?>
      <div class="actions-card">
        <div class="actions-title">Décision sur ce dossier</div>
        <p style="font-size:13px;color:var(--text-light);margin-bottom:16px;line-height:1.6">
          Vérifiez toutes les informations et les documents avant de prendre une décision. Cette action est <strong>irréversible</strong>.
          <br><span style="color:#2196F3;font-size:12px">📧 Un email sera automatiquement envoyé au candidat.</span>
        </p>

        <form method="POST">
          <input type="hidden" name="action" value="accepter">
          <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px">
            <div style="display:flex;flex-direction:column;gap:5px">
              <label style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px">Catégorie professionnelle <span style="color:var(--red)">*</span></label>
              <select name="categorie" id="selectCategorie" required onchange="updateCotisation()"
                style="padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none">
                <option value="">-- Sélectionner --</option>
                <option value="directeur">Directeur — 10 000 F/mois</option>
                <option value="cadre">Cadre — 5 000 F/mois</option>
                <option value="maitrise_superieure">Maîtrise Supérieure — 3 000 F/mois</option>
                <option value="maitrise_1_2">Maîtrise 1 et 2 — 1 500 F/mois</option>
                <option value="employe_ouvrier">Employé / Ouvrier — 1 500 F/mois</option>
              </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px">
              <label style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px">Salaire net mensuel (F CFA) <span style="font-weight:400;text-transform:none">— optionnel</span></label>
              <input type="number" name="salaire_net" id="salaireNet" min="0" step="1000" placeholder="Ex: 250000"
                style="padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none"
                oninput="updateCotisation()">
              <span style="font-size:11.5px;color:var(--text-light)">Permet de calculer la quotité cessible (1/3 du salaire)</span>
            </div>
            <div id="previewCotisation" style="display:none;background:#E8F5E9;border:1px solid #A5D6A7;border-radius:10px;padding:12px 14px">
              <div style="font-size:12px;font-weight:700;color:#2E7D32;margin-bottom:8px">Récapitulatif calculé automatiquement</div>
              <div style="display:flex;flex-direction:column;gap:5px;font-size:13px">
                <div style="display:flex;justify-content:space-between"><span style="color:#4CAF50">Cotisation mensuelle</span><strong id="previewMontant" style="color:#1B5E20"></strong></div>
                <div style="display:flex;justify-content:space-between"><span style="color:#4CAF50">Solde min Expresse (24 mois)</span><strong id="previewSoldeMin" style="color:#1B5E20"></strong></div>
                <div id="previewQuotite" style="display:none;justify-content:space-between"><span style="color:#4CAF50">Quotité cessible (1/3 salaire)</span><strong id="previewQuotiteVal" style="color:#1B5E20"></strong></div>
              </div>
            </div>
          </div>
          <button type="submit" class="btn-accepter"
            onclick="return document.getElementById('selectCategorie').value ? confirm('Confirmer l\'acceptation ?\n\nUn email de bienvenue sera envoyé automatiquement.') : (alert('Veuillez sélectionner une catégorie.'), false)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Accepter la candidature
          </button>
        </form>

        <button type="button" class="btn-refuser" onclick="document.getElementById('motifWrap').classList.toggle('show')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          Refuser la candidature
        </button>
        <form method="POST">
          <input type="hidden" name="action" value="refuser">
          <div class="motif-wrap" id="motifWrap">
            <textarea name="motif_refus" placeholder="Indiquez le motif du refus..." required></textarea>
            <button type="submit" class="btn-confirm-refus"
              onclick="return confirm('Confirmer le refus ?\n\nUn email sera envoyé automatiquement au candidat.')">
              Confirmer le refus
            </button>
          </div>
        </form>
      </div>

      <?php elseif ($c['statut'] === 'accepte'): ?>
      <div class="actions-card">
        <div class="actions-title" style="color:#2E7D32">✅ Dossier accepté</div>
        <p style="font-size:13px;color:var(--text-light);margin-bottom:12px">
          Ce candidat est maintenant membre de la MA2E.<br>
          Traité le <?php echo date('d/m/Y', strtotime($c['date_traitement'])); ?>.
        </p>
        <?php if ($membre): ?>
        <div class="membre-info-box">
          <div class="titre">Informations du compte créé</div>
          <div class="membre-info-row"><span>Numéro membre</span><span><?php echo htmlspecialchars($membre['numero_membre']); ?></span></div>
          <div class="membre-info-row"><span>Catégorie</span><span><?php echo $categories_labels[$membre['categorie']] ?? ucfirst($membre['categorie']); ?></span></div>
          <div class="membre-info-row"><span>Cotisation mensuelle</span><span><?php echo number_format($membre['cotisation_mensuelle'],0,',',' ').' F CFA'; ?></span></div>
          <div class="membre-info-row"><span>Date d'adhésion</span><span><?php echo date('d/m/Y', strtotime($membre['date_adhesion'])); ?></span></div>
          <div class="membre-info-row"><span>Statut</span><span><?php echo ucfirst($membre['statut']); ?></span></div>
          <div class="membre-info-row"><span>Retenue à la source</span><span>✅ Activée (prélèvement automatique)</span></div>
        </div>
        <?php endif; ?>
        <a href="membres.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:9px 16px;background:var(--gold-bg);color:var(--gold);border:1px solid #e8c97a;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none">
          Voir la liste des membres →
        </a>
      </div>

      <?php else: ?>
      <div class="actions-card" style="border-color:#FFCDD2">
        <div class="actions-title" style="color:#C62828">❌ Dossier refusé</div>
        <p style="font-size:13px;color:var(--text-light)">Ce dossier a été refusé le <?php echo date('d/m/Y', strtotime($c['date_traitement'])); ?>.</p>
      </div>
      <?php endif; ?>

    </div>
  </div>

</main>

<script>
const cotisations = { directeur:10000, cadre:5000, maitrise_superieure:3000, maitrise_1_2:1500, employe_ouvrier:1500 };
function formatMontant(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ') + ' F CFA'; }
function updateCotisation() {
  const cat     = document.getElementById('selectCategorie')?.value;
  const salaire = parseFloat(document.getElementById('salaireNet')?.value) || 0;
  const preview = document.getElementById('previewCotisation');
  if (!cat || !cotisations[cat]) { if(preview) preview.style.display='none'; return; }
  const cot = cotisations[cat];
  document.getElementById('previewMontant').textContent  = formatMontant(cot);
  document.getElementById('previewSoldeMin').textContent = formatMontant(cot * 24);
  const qDiv = document.getElementById('previewQuotite');
  if (salaire > 0) { document.getElementById('previewQuotiteVal').textContent = formatMontant(Math.round(salaire/3)); qDiv.style.display='flex'; }
  else qDiv.style.display = 'none';
  preview.style.display = 'block';
}
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