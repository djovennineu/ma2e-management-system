<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

// Récupérer membre + société + utilisateur
$stmt = $pdo->prepare("
    SELECT m.*, s.nom AS societe_nom, s.code AS societe_code,
           u.matricule, u.mot_de_passe_temporaire, u.derniere_connexion
    FROM membres m
    JOIN societes s ON m.societe_id = s.id
    JOIN utilisateurs u ON u.membre_id = m.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$data) { header('Location: ../connexion/logout.php'); exit; }

// Changer mot de passe
$success = '';
$error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changer_mdp') {
    $ancien  = $_POST['ancien_mdp']    ?? '';
    $nouveau = $_POST['nouveau_mdp']   ?? '';
    $confirm = $_POST['confirmer_mdp'] ?? '';
    $user_row = $pdo->prepare("SELECT password FROM utilisateurs WHERE id=?");
    $user_row->execute([$user_id]);
    $user_row = $user_row->fetch();
    if (!password_verify($ancien, $user_row['password'])) {
        $error = "Ancien mot de passe incorrect.";
    } elseif (strlen($nouveau) < 8) {
        $error = "Le nouveau mot de passe doit faire au moins 8 caractères.";
    } elseif ($nouveau !== $confirm) {
        $error = "Les mots de passe ne correspondent pas.";
    } else {
        $pdo->prepare("UPDATE utilisateurs SET password=?, mot_de_passe_temporaire=0 WHERE id=?")
            ->execute([password_hash($nouveau, PASSWORD_BCRYPT), $user_id]);
        $success = "Mot de passe changé avec succès.";
        $stmt->execute([$user_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Récupérer les notifications non lues
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE membre_id = ? AND est_lu = 0 
    ORDER BY date_creation DESC 
    LIMIT 5
");
$notifications->execute([$membre_id]);
$notifications_list = $notifications->fetchAll(PDO::FETCH_ASSOC);
$nb_notifications = count($notifications_list);

// Comptes du membre
$comptes = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut != 'cloture'
    ORDER BY ce.date_ouverture ASC
");
$comptes->execute([$membre_id]);
$comptes = $comptes->fetchAll(PDO::FETCH_ASSOC);

// Séparer le compte Crédits pour l'affichage spécifique
$compte_credits = null;
$autres_comptes = [];
foreach ($comptes as $cpt) {
    if ($cpt['type_code'] === 'credits') {
        $compte_credits = $cpt;
    } else {
        $autres_comptes[] = $cpt;
    }
}

// Total épargne (sans le compte Crédits car ce n'est pas de l'épargne)
$total_epargne = array_sum(array_column($autres_comptes, 'solde'));

// 10 derniers mouvements
$mouvements = $pdo->prepare("
    SELECT mo.*, tce.libelle AS compte_libelle, tce.code AS type_code
    FROM mouvements mo
    JOIN comptes_epargne ce ON mo.compte_id = ce.id
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ?
    ORDER BY mo.created_at DESC
    LIMIT 10
");
$mouvements->execute([$membre_id]);
$mouvements = $mouvements->fetchAll(PDO::FETCH_ASSOC);

// Crédits en cours
$nb_credits = $pdo->prepare("SELECT COUNT(*) FROM credits WHERE membre_id=? AND statut='en_cours'");
$nb_credits->execute([$membre_id]);
$nb_credits = $nb_credits->fetchColumn();

// Demandes de retrait en attente
$nb_retraits = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(*) FROM demandes_retrait WHERE membre_id=? AND statut='en_attente'");
    $r->execute([$membre_id]);
    $nb_retraits = $r->fetchColumn();
} catch(Exception $e) { $nb_retraits = 0; }

// Demandes de crédit en attente
$nb_demandes_credit = 0;
try {
    $table_exists = $pdo->query("SHOW TABLES LIKE 'demandes_credit'")->rowCount() > 0;
    if ($table_exists) {
        $r = $pdo->prepare("SELECT COUNT(*) FROM demandes_credit WHERE membre_id=? AND statut='en_attente'");
        $r->execute([$membre_id]);
        $nb_demandes_credit = $r->fetchColumn();
    }
} catch(Exception $e) { $nb_demandes_credit = 0; }

// Demandes de retrait en attente pour le badge
$nb_retrait_attente = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(*) FROM demandes_retrait WHERE membre_id=? AND statut='en_attente'");
    $r->execute([$membre_id]);
    $nb_retrait_attente = $r->fetchColumn();
} catch(Exception $e) { $nb_retrait_attente = 0; }

// Demandes de crédit en attente pour le badge
$nb_demandes_credit_attente = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(*) FROM demandes_credit WHERE membre_id=? AND statut IN ('en_attente','en_etude')");
    $r->execute([$membre_id]);
    $nb_demandes_credit_attente = $r->fetchColumn();
} catch(Exception $e) { $nb_demandes_credit_attente = 0; }

$nb_demandes_logement_attente = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(*) FROM demandes_logement WHERE membre_id=? AND statut IN ('en_attente','approuve')");
    $r->execute([$membre_id]);
    $nb_demandes_logement_attente = $r->fetchColumn();
} catch(Exception $e) { $nb_demandes_logement_attente = 0; }

// Comptes qui permettent le dépôt mobile
$comptes_depot = array_filter($comptes, fn($c) => in_array($c['type_code'], ['expresse','ordinaire','logement','datavp']));
$a_expresse = !empty(array_filter($comptes, fn($c) => $c['type_code'] === 'expresse'));

// Logique d'ouverture des comptes
$codes_existants = array_column($comptes, 'type_code');
$comptes_manquants = [];

if (!in_array('ordinaire', $codes_existants)) {
    $comptes_manquants[] = 'ordinaire';
}

if (!in_array('logement', $codes_existants) && 
    in_array('expresse', $codes_existants) && 
    in_array('ordinaire', $codes_existants)) {
    $comptes_manquants[] = 'logement';
}

if (!in_array('dats', $codes_existants) && in_array('expresse', $codes_existants)) {
    $comptes_manquants[] = 'dats';
}

if (!in_array('datavp', $codes_existants) && in_array('expresse', $codes_existants)) {
    $comptes_manquants[] = 'datavp';
}

$peut_ouvrir = !empty($comptes_manquants);

$initiales = strtoupper(substr($data['nom'],0,1).substr($data['prenoms'],0,1));

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon Espace — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;
  --dark:#1a1a1a;--dark2:#242424;--dark3:#2e2e2e;
  --bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;
  --card:#fff;--border:#e8e3db;
  --green:#4CAF50;--red:#F44336;
}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#666;--border:#2a2a2a;--dark3:#252525}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s;overflow-x:hidden}

/* DRAWER */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:998;pointer-events:none;transition:background .3s}
.drawer-overlay.open{background:rgba(0,0,0,.5);pointer-events:all}
.drawer{position:fixed;top:0;left:0;width:300px;height:100vh;background:var(--dark);z-index:999;transform:translateX(-100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow-y:auto}
.drawer.open{transform:translateX(0)}
.drawer-header{background:linear-gradient(135deg,var(--dark),var(--dark3));padding:40px 24px 24px;position:relative;border-bottom:1px solid rgba(255,255,255,.06)}
.drawer-close{position:absolute;top:16px;right:16px;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);border:none;color:#999;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s}
.drawer-close:hover{background:rgba(255,255,255,.15);color:white}
.drawer-avatar{width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-dark));display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:white;margin-bottom:14px;box-shadow:0 4px 20px rgba(201,168,76,.3)}
.drawer-nom{font-size:17px;font-weight:700;color:white;margin-bottom:3px}
.drawer-matricule{font-size:12px;color:var(--gold);font-weight:600;margin-bottom:12px}
.drawer-infos{display:flex;flex-direction:column;gap:7px}
.drawer-info-item{display:flex;align-items:center;gap:8px;font-size:12.5px;color:rgba(255,255,255,.55)}
.drawer-info-item svg{width:13px;height:13px;flex-shrink:0;opacity:.7}
.drawer-menu{flex:1;padding:16px 12px}
.drawer-menu-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;color:#aaa;font-size:14px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none;margin-bottom:4px;border:none;background:none;width:100%;font-family:'DM Sans',sans-serif}
.drawer-menu-item:hover{background:rgba(255,255,255,.06);color:#ddd}
.drawer-menu-item.active-link{background:rgba(201,168,76,.15);color:var(--gold)}
.drawer-menu-item.danger{color:#ff6b6b}
.drawer-menu-item.danger:hover{background:rgba(244,67,54,.1);color:#ff4444}
.drawer-menu-item svg{width:17px;height:17px;flex-shrink:0}
.drawer-divider{height:1px;background:rgba(255,255,255,.06);margin:8px 0}
.drawer-footer{padding:16px 24px;border-top:1px solid rgba(255,255,255,.06)}
.drawer-version{font-size:11px;color:rgba(255,255,255,.2);text-align:center}

/* HEADER */
.header{background:linear-gradient(135deg,var(--dark),var(--dark3));padding:18px 20px 65px;position:relative;overflow:hidden}
.header::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;background:radial-gradient(circle,rgba(201,168,76,.12) 0%,transparent 70%);border-radius:50%}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0;transition:background .3s}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.hamburger{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;cursor:pointer;transition:all .2s}
.hamburger:hover{background:rgba(255,255,255,.15)}
.hamburger span{display:block;width:18px;height:2px;background:white;border-radius:2px}
.header-center{text-align:center}
.header-greeting{font-size:12px;color:rgba(255,255,255,.5);margin-bottom:2px}
.header-name{font-size:16px;font-weight:700;color:white}
.header-right{display:flex;align-items:center;gap:12px;position:relative}

/* NOTIFICATIONS */
.notifications-wrapper{position:relative;display:flex;align-items:center}
.notifications-bell{position:relative;cursor:pointer;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:all .2s;color:rgba(255,255,255,.8)}
.notifications-bell:hover{background:rgba(255,255,255,.15);color:white}
.notifications-bell svg{width:20px;height:20px}
.notif-badge{position:absolute;top:-2px;right:-2px;background:var(--red);color:white;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 4px}
.notifications-panel{
  position:fixed;
  width:340px;
  background:var(--card);
  border-radius:12px;
  box-shadow:0 4px 20px rgba(0,0,0,0.25);
  z-index:10000;
  display:none;
  border:1px solid var(--border);
}
.notifications-panel.show{display:block}
.notif-header{padding:12px 16px;border-bottom:1px solid var(--border);font-weight:600;color:var(--text)}
.notif-list{max-height:400px;overflow-y:auto}
.notif-item{padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .2s}
.notif-item:hover{background:var(--bg)}
.notif-title{font-weight:600;font-size:13px;color:var(--text);margin-bottom:4px}
.notif-message{font-size:12px;color:var(--text-light);line-height:1.4}
.notif-date{font-size:10px;color:var(--text-light);margin-top:6px}
.notif-empty{padding:30px;text-align:center;color:var(--text-light);font-size:13px}
.notif-footer{padding:10px 16px;border-top:1px solid var(--border);text-align:center}
.notif-footer a{color:var(--gold);text-decoration:none;font-size:12px}
.notif-footer a:hover{text-decoration:underline}

/* THEME */
.theme-wrap{display:flex;background:rgba(255,255,255,.08);border-radius:20px;padding:3px;gap:2px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:transparent;display:flex;align-items:center;justify-content:center;transition:all .2s;color:rgba(255,255,255,.5)}
.t-btn.active{background:var(--gold);color:white}

/* CARTE MEMBRE */
.carte-wrap{padding:0 20px;position:relative;z-index:1;margin-top:-10px}
.carte{background:linear-gradient(135deg,#2c2c2c,#3a3a3a 50%,#2c2c2c);border-radius:20px;padding:22px 24px;box-shadow:0 15px 40px rgba(0,0,0,.3);position:relative;overflow:hidden}
.carte::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;background:radial-gradient(circle,rgba(201,168,76,.2) 0%,transparent 70%);border-radius:50%}
.carte-watermark{position:absolute;bottom:10px;right:16px;font-family:'Playfair Display',serif;font-size:32px;font-weight:700;color:rgba(255,255,255,.04);letter-spacing:4px}
.carte-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}
.carte-brand{font-family:'Playfair Display',serif;font-size:17px;color:white;letter-spacing:2px}
.carte-brand-sub{font-size:9px;color:rgba(255,255,255,.35);letter-spacing:1px;font-family:'DM Sans',sans-serif;margin-top:1px}
.carte-puce{width:36px;height:28px;background:linear-gradient(135deg,var(--gold),#e8d5a3);border-radius:5px;opacity:.85;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;gap:2px;padding:3px}
.carte-puce div{background:rgba(0,0,0,.15);border-radius:1px}
.carte-numero{font-size:15px;font-weight:700;color:white;letter-spacing:3px;margin-bottom:16px;font-family:monospace;position:relative;z-index:1}
.carte-bottom{display:flex;justify-content:space-between;align-items:flex-end;position:relative;z-index:1}
.carte-membre-nom{font-size:12px;font-weight:700;color:white;letter-spacing:1px;text-transform:uppercase}
.carte-membre-since{font-size:9px;color:rgba(255,255,255,.35);margin-top:2px}
.carte-societe-code{font-size:18px;font-weight:800;color:var(--gold)}
.carte-societe-nom{font-size:9px;color:rgba(255,255,255,.3);text-align:right}

/* TOTAL EPARGNE */
.total-epargne{margin:14px 20px 0;background:linear-gradient(135deg,var(--gold),var(--gold-dark));border-radius:14px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between}
.total-epargne-lbl{font-size:12px;font-weight:600;color:rgba(255,255,255,.8)}
.total-epargne-val{font-size:22px;font-weight:800;color:white}
.total-epargne-sub{font-size:10px;color:rgba(255,255,255,.6)}

/* ALERTES */
.alerte-mdp{background:linear-gradient(135deg,#FF9800,#F44336);margin:14px 20px 0;border-radius:12px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.alerte-mdp p{color:white;font-size:12.5px;font-weight:500}
.alerte-mdp button{padding:6px 12px;background:white;color:#F44336;border:none;border-radius:20px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;white-space:nowrap}
.alert{padding:12px 16px;border-radius:10px;font-size:13.5px;display:flex;align-items:center;gap:8px;margin:14px 20px 0}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:3px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:3px solid #F44336}

/* MAIN */
.main{padding:20px;max-width:640px;margin:0 auto}
.section-title{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px}

/* COMPTES */
.comptes-scroll{display:flex;gap:16px;overflow-x:auto;padding-bottom:8px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.comptes-scroll::-webkit-scrollbar{display:none}
.compte-item{display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;text-decoration:none;transition:transform .2s;flex-shrink:0}
.compte-item:hover{transform:translateY(-3px)}
.compte-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;box-shadow:0 4px 14px rgba(0,0,0,.08);transition:box-shadow .2s}
.compte-item:hover .compte-icon{box-shadow:0 8px 20px rgba(0,0,0,.15)}
.compte-label{font-size:10px;font-weight:700;color:var(--text-light);text-align:center;text-transform:uppercase;letter-spacing:.3px;max-width:70px;line-height:1.3}
.compte-solde{font-size:11px;font-weight:600;color:var(--text);text-align:center}
.compte-add .compte-icon{background:var(--bg);border:2px dashed var(--border)}

/* ACTIONS RAPIDES */
.raccourcis{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px}
.raccourci-item{background:var(--card);border-radius:12px;padding:14px;border:1px solid var(--border);display:flex;align-items:center;gap:10px;text-decoration:none;transition:all .2s;cursor:pointer}
.raccourci-item:hover{border-color:var(--gold);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.06)}
.raccourci-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.raccourci-label{font-size:12.5px;font-weight:600;color:var(--text)}
.raccourci-sub{font-size:11px;color:var(--text-light)}
.badge-notif{display:inline-block;background:var(--red);color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px}

/* HISTORIQUE */
.histo-card{background:var(--card);border-radius:16px;overflow:hidden;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.04)}
.histo-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.histo-title{font-size:14px;font-weight:700;color:var(--text)}
.histo-voir{font-size:12px;color:var(--gold);font-weight:600;text-decoration:none}
.mvt-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border);transition:background .15s}
.mvt-item:last-child{border-bottom:none}
.mvt-item:hover{background:var(--bg)}
.mvt-ico{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.mvt-info{flex:1;min-width:0}
.mvt-type{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mvt-meta{font-size:11px;color:var(--text-light);margin-top:1px}
.mvt-right{text-align:right;flex-shrink:0}
.mvt-montant{font-size:13.5px;font-weight:700}
.mvt-solde{font-size:10.5px;color:var(--text-light);margin-top:1px}
.mvt-plus{color:#2E7D32}
.mvt-minus{color:#C62828}
.mvt-empty{text-align:center;padding:30px;color:var(--text-light);font-size:13px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-end;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--card);border-radius:20px 20px 0 0;padding:8px 24px 32px;width:100%;max-width:640px;animation:slideUp .3s ease}
.modal-handle{width:36px;height:4px;background:var(--border);border-radius:2px;margin:12px auto 20px}
.modal-title{font-size:17px;font-weight:700;color:var(--text);margin-bottom:18px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select{padding:13px 14px;border:1.5px solid var(--border);border-radius:11px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{border-color:var(--gold)}
.form-hint{font-size:11.5px;color:var(--text-light)}
.btn-submit{width:100%;padding:14px;background:var(--gold);color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;margin-top:6px;transition:all .2s}
.btn-submit:hover{background:var(--gold-dark)}
.btn-cancel{width:100%;padding:12px;background:transparent;border:1.5px solid var(--border);border-radius:12px;font-size:14px;color:var(--text-light);cursor:pointer;font-family:'DM Sans',sans-serif;margin-top:8px}

/* MOBILE MONEY MODAL */
.operateur-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.operateur-btn{padding:12px 8px;border:2px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.operateur-btn:hover{border-color:var(--gold)}
.operateur-btn.active{border-color:var(--gold);background:var(--gold-bg)}
.operateur-emoji{font-size:22px;display:block;margin-bottom:4px}
.operateur-label{font-size:12px;font-weight:600;color:var(--text-light)}
.operateur-btn.active .operateur-label{color:var(--gold)}
.simulation-box{background:linear-gradient(135deg,#E8F5E9,#F1F8E9);border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin:14px 0;display:none;text-align:center}
.simulation-spinner{font-size:30px;animation:spin 1s linear infinite;display:block;margin-bottom:8px}
.simulation-success{font-size:30px;display:none;margin-bottom:8px}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.fadeUp{animation:fadeUp .35s ease both}

@media(min-width:640px){
  .carte-wrap,.main,.total-epargne,.alerte-mdp,.alert{max-width:640px;margin-left:auto;margin-right:auto}
  .header{padding:20px 40px 70px}
  .modal{border-radius:20px;margin-bottom:20px}
  .modal-overlay{align-items:center}
}
@media(min-width:1024px){
  .header{padding:22px 10% 75px}
  .carte-wrap{padding:0 10%;max-width:none}
  .main{max-width:900px;padding:24px 10%}
  .drawer{width:320px}
  .raccourcis{grid-template-columns:repeat(4,1fr)}
}
</style>
</head>
<body>

<!-- DRAWER OVERLAY -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- DRAWER -->
<aside class="drawer" id="drawer">
  <div class="drawer-header">
    <button class="drawer-close" onclick="closeDrawer()">×</button>
    <div class="drawer-avatar"><?php echo $initiales; ?></div>
    <div class="drawer-nom"><?php echo htmlspecialchars($data['prenoms'].' '.$data['nom']); ?></div>
    <div class="drawer-matricule"><?php echo htmlspecialchars($data['matricule']); ?></div>
    <div class="drawer-infos">
      <div class="drawer-info-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        <?php echo htmlspecialchars($data['societe_code'].' — '.$data['societe_nom']); ?>
      </div>
      <div class="drawer-info-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php echo $categories_labels[$data['categorie']] ?? ucfirst($data['categorie']); ?>
      </div>
      <div class="drawer-info-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <?php echo htmlspecialchars($data['email']); ?>
      </div>
       
      <div class="drawer-info-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.18 2 2 2 0 012 .18h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        <?php echo !empty($data['telephone']) ? htmlspecialchars($data['telephone']) : 'Non renseigné'; ?>
      </div>
      <div class="drawer-info-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        Cotisation : <?php echo number_format($data['cotisation_mensuelle'],0,',',' '); ?> F/mois
      </div>
    </div>
  </div>
  <div class="drawer-menu">
    <a class="drawer-menu-item active-link" href="espace_membre.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Tableau de bord
    </a>
    <?php if ($peut_ouvrir): ?>
    <a class="drawer-menu-item" href="ouvrir_compte.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Ouvrir un compte
    </a>
    <?php endif; ?>
    <?php if (!empty($comptes_depot)): ?>
    <button class="drawer-menu-item" onclick="openModal('modal-depot');closeDrawer()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Dépôt mobile money
    </button>
    <?php endif; ?>
    
    <!-- DEMANDE DE RETRAIT -->
    <a class="drawer-menu-item" href="demande_retrait.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>
      Demande de retrait
      <?php if ($nb_retrait_attente > 0): ?>
        <span class="badge-notif" style="background:#FF9800;"><?php echo $nb_retrait_attente; ?></span>
      <?php endif; ?>
    </a>
    
    <!-- DEMANDE DE CRÉDIT -->
    <a class="drawer-menu-item" href="demande_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 12 2 21 11 18 14 6 14 3 11"/><rect x="6" y="14" width="12" height="8"/></svg>
      Demande de crédit
      <?php if ($nb_demandes_credit_attente > 0): ?>
        <span class="badge-notif" style="background:#2196F3;"><?php echo $nb_demandes_credit_attente; ?></span>
      <?php endif; ?>
    </a>
    
    <a class="drawer-menu-item" href="demande_logement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11.5L12 4l9 7.5"/><path d="M5 10.5V20h14v-9.5"/><path d="M9 20v-5h6v5"/></svg>
      Demande logement
      <?php if ($nb_demandes_logement_attente > 0): ?>
        <span class="badge-notif" style="background:#4CAF50;"><?php echo $nb_demandes_logement_attente; ?></span>
      <?php endif; ?>
    </a>
    
    <a class="drawer-menu-item" href="mes_credits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Mes crédits
      <?php if ($nb_credits > 0): ?>
        <span class="badge-notif"><?php echo $nb_credits; ?></span>
      <?php endif; ?>
    </a>
    
    <a class="drawer-menu-item" href="historique.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Historique complet
    </a>
    
    <?php if ($nb_demandes_credit > 0): ?>
    <a class="drawer-menu-item" href="mes_demandes_credit.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 12 2 21 11 18 14 6 14 3 11"/></svg>
      Mes demandes de crédit
      <span class="badge-notif"><?php echo $nb_demandes_credit; ?></span>
    </a>
    <?php endif; ?>
    
    <div class="drawer-divider"></div>
    <button class="drawer-menu-item" onclick="openModal('modal-mdp');closeDrawer()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
      Changer mon mot de passe
    </button>
    <div class="drawer-divider"></div>
    <a class="drawer-menu-item danger" href="../connexion/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 17l5-5-5-5"/><line x1="21" y1="12" x2="9" y2="12"/><path d="M13 5H5a2 2 0 00-2 2v10a2 2 0 002 2h8"/></svg>
      Déconnexion
    </a>
  </div>
  <div class="drawer-footer">
    <div class="drawer-version">MA2E © <?php echo date('Y'); ?> — v1.0</div>
  </div>
</aside>

<!-- HEADER -->
<header class="header">
  <div class="header-top">
    <button class="hamburger" onclick="openDrawer()">
      <span></span><span></span><span></span>
    </button>
    <div class="header-center">
      <div class="header-greeting">Bonjour 👋</div>
      <div class="header-name"><?php echo htmlspecialchars($data['prenoms']); ?></div>
    </div>
    <div class="header-right">
      <!-- NOTIFICATIONS -->
      <div class="notifications-wrapper">
        <div class="notifications-bell" onclick="toggleNotifications(event)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <?php if ($nb_notifications > 0): ?>
          <span class="notif-badge"><?php echo $nb_notifications; ?></span>
          <?php endif; ?>
        </div>
      </div>
      <!-- THEME TOGGLE -->
      <div class="theme-wrap">
        <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
        <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
      </div>
    </div>
  </div>
</header>

<!-- PANEL DES NOTIFICATIONS - PLACÉ EN DEHORS DU HEADER -->
<div class="notifications-panel" id="notificationsPanel">
  <div class="notif-header">🔔 Notifications</div>
  <div class="notif-list">
    <?php if (empty($notifications_list)): ?>
    <div class="notif-empty">Aucune notification</div>
    <?php else: ?>
      <?php foreach ($notifications_list as $notif): ?>
      <div class="notif-item" data-id="<?php echo $notif['id']; ?>" data-lien="<?php echo htmlspecialchars($notif['lien'] ?? ''); ?>">
        <div class="notif-title"><?php echo htmlspecialchars($notif['titre']); ?></div>
        <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
        <div class="notif-date"><?php echo date('d/m/Y H:i', strtotime($notif['date_creation'])); ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div class="notif-footer">
    <a href="mes_notifications.php">Voir toutes les notifications</a>
  </div>
</div>

<!-- CARTE MEMBRE -->
<div class="carte-wrap fadeUp">
  <div class="carte">
    <div class="carte-watermark">MA2E</div>
    <div class="carte-top">
      <div>
        <div class="carte-brand">MA2E</div>
        <div class="carte-brand-sub">CARTE DE MEMBRE</div>
      </div>
      <div class="carte-puce"><div></div><div></div><div></div><div></div></div>
    </div>
    <div class="carte-numero"><?php echo htmlspecialchars($data['numero_membre']); ?></div>
    <div class="carte-bottom">
      <div>
        <div class="carte-membre-nom"><?php echo htmlspecialchars(strtoupper($data['nom'].' '.$data['prenoms'])); ?></div>
        <div class="carte-membre-since">Membre depuis <?php echo date('m/Y', strtotime($data['date_adhesion'])); ?></div>
      </div>
      <div>
        <div class="carte-societe-code"><?php echo htmlspecialchars($data['societe_code']); ?></div>
        <div class="carte-societe-nom"><?php echo htmlspecialchars($data['societe_nom']); ?></div>
      </div>
    </div>
  </div>
</div>

<!-- TOTAL EPARGNE -->
<?php if ($total_epargne > 0): ?>
<div class="total-epargne fadeUp">
  <div>
    <div class="total-epargne-lbl">Épargne totale</div>
    <div class="total-epargne-sub"><?php echo count($autres_comptes); ?> compte(s) d'épargne</div>
  </div>
  <div class="total-epargne-val"><?php echo number_format($total_epargne,0,',',' '); ?> F</div>
</div>
<?php endif; ?>

<!-- COMPTE CRÉDITS (mis en avant si présent) -->
<?php if ($compte_credits && $compte_credits['solde'] > 0): ?>
<div class="total-epargne fadeUp" style="background:linear-gradient(135deg,#4CAF50,#2E7D32); margin-top:10px;">
  <div>
    <div class="total-epargne-lbl">💳 Compte Crédits</div>
    <div class="total-epargne-sub">Fonds disponibles</div>
  </div>
  <div class="total-epargne-val"><?php echo number_format($compte_credits['solde'],0,',',' '); ?> F</div>
</div>
<?php endif; ?>

<!-- ALERTE MDP -->
<?php if ($data['mot_de_passe_temporaire']): ?>
<div class="alerte-mdp fadeUp">
  <p>⚠️ Mot de passe temporaire — changez-le maintenant</p>
  <button onclick="openModal('modal-mdp')">Changer</button>
</div>
<?php endif; ?>

<!-- ALERTS -->
<?php if ($success): ?>
<div class="alert alert-success fadeUp"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error fadeUp"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- MAIN -->
<main class="main">

  <!-- MES COMPTES -->
  <div class="fadeUp" style="margin-bottom:24px">
    <div class="section-title">Mes comptes</div>
    <div class="comptes-scroll">
      <!-- Compte Crédits en premier si présent -->
      <?php if ($compte_credits): ?>
      <?php $cfg = $compte_config['credits']; ?>
      <a href="fiche_epargne.php?id=<?php echo $compte_credits['id']; ?>" class="compte-item">
        <div class="compte-icon" style="background:<?php echo $cfg['bg']; ?>"><?php echo $cfg['emoji']; ?></div>
        <span class="compte-label"><?php echo $cfg['label']; ?></span>
        <span class="compte-solde"><?php echo number_format($compte_credits['solde'],0,',',' '); ?> F</span>
      </a>
      <?php endif; ?>
      
      <!-- Autres comptes -->
      <?php foreach ($autres_comptes as $cpt):
        $cfg = $compte_config[$cpt['type_code']] ?? ['emoji'=>'💼','bg'=>'#eee','label'=>strtoupper($cpt['type_libelle'])];
      ?>
      <a href="fiche_epargne.php?id=<?php echo $cpt['id']; ?>" class="compte-item">
        <div class="compte-icon" style="background:<?php echo $cfg['bg']; ?>"><?php echo $cfg['emoji']; ?></div>
        <span class="compte-label"><?php echo $cfg['label']; ?></span>
        <span class="compte-solde"><?php echo number_format($cpt['solde'],0,',',' '); ?> F</span>
      </a>
      <?php endforeach; ?>
      
      <?php if ($peut_ouvrir): ?>
      <a href="ouvrir_compte.php" class="compte-item compte-add">
        <div class="compte-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#bbb" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </div>
        <span class="compte-label" style="color:#bbb">Nouveau</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACTIONS RAPIDES -->
  <div class="fadeUp" style="margin-bottom:24px">
    <div class="section-title">Actions rapides</div>
    <div class="raccourcis">
      <?php if (!empty($comptes_depot)): ?>
      <div class="raccourci-item" onclick="openModal('modal-depot')">
        <div class="raccourci-icon" style="background:#E8F5E9">📱</div>
        <div>
          <div class="raccourci-label">Dépôt mobile</div>
          <div class="raccourci-sub">Orange · MTN · Wave</div>
        </div>
      </div>
      <?php endif; ?>
      <a href="demande_credit.php" class="raccourci-item">
        <div class="raccourci-icon" style="background:#F3E5F5">🏦</div>
        <div>
          <div class="raccourci-label">Demande crédit</div>
          <div class="raccourci-sub">Simuler & soumettre</div>
        </div>
      </a>
      <a href="demande_logement.php" class="raccourci-item">
        <div class="raccourci-icon" style="background:#E8F5E9">🏠</div>
        <div>
          <div class="raccourci-label">Demande logement
            <?php if ($nb_demandes_logement_attente > 0): ?>
              <span class="badge-notif" style="background:#4CAF50"><?php echo $nb_demandes_logement_attente; ?></span>
            <?php endif; ?>
          </div>
          <div class="raccourci-sub">Projet immobilier MA2E</div>
        </div>
      </a>
      <a href="demande_retrait.php" class="raccourci-item">
        <div class="raccourci-icon" style="background:#FFEBEE">💸</div>
        <div>
          <div class="raccourci-label">Retrait</div>
          <div class="raccourci-sub">Demande en ligne</div>
        </div>
      </a>
      <a href="mes_credits.php" class="raccourci-item">
        <div class="raccourci-icon" style="background:#E3F2FD">💳</div>
        <div>
          <div class="raccourci-label">Mes crédits
            <?php if ($nb_credits > 0): ?>
              <span class="badge-notif"><?php echo $nb_credits; ?></span>
            <?php endif; ?>
          </div>
          <div class="raccourci-sub">Voir l'échéancier</div>
        </div>
      </a>
      <a href="historique.php" class="raccourci-item">
        <div class="raccourci-icon" style="background:#F5EDD6">📊</div>
        <div>
          <div class="raccourci-label">Historique</div>
          <div class="raccourci-sub">Tous mouvements</div>
        </div>
      </a>
    </div>
  </div>

  <!-- HISTORIQUE RECENT -->
  <div class="fadeUp">
    <div class="section-title">Transactions récentes</div>
    <div class="histo-card">
      <div class="histo-header">
        <span class="histo-title">10 derniers mouvements</span>
        <a href="historique.php" class="histo-voir">Voir tout →</a>
      </div>
      <?php if (empty($mouvements)): ?>
        <div class="mvt-empty">Aucun mouvement enregistré</div>
      <?php else: ?>
        <?php
        $type_labels = [
          'depot'               => 'Dépôt',
          'retrait'             => 'Retrait',
          'interet'             => 'Intérêts',
          'penalite'            => 'Pénalité',
          'remboursement'       => 'Remboursement crédit',
          'prelevement_salaire' => 'Prélèvement salaire',
          'garantie'            => 'Dépôt garantie',
          'deblocage_garantie'  => 'Déblocage garantie',
          'depot_mobile'        => 'Dépôt mobile money',
          'credit_octroi'       => 'Crédit octroyé',
        ];
        $type_icons = [
          'depot'               => '💵',
          'retrait'             => '💸',
          'interet'             => '📈',
          'penalite'            => '⚠️',
          'remboursement'       => '✅',
          'prelevement_salaire' => '🏢',
          'garantie'            => '🔒',
          'deblocage_garantie'  => '🔓',
          'depot_mobile'        => '📱',
          'credit_octroi'       => '💰',
        ];
        foreach ($mouvements as $mv):
          $is_credit = in_array($mv['type'],['depot','interet','remboursement','deblocage_garantie','prelevement_salaire','depot_mobile','credit_octroi']);
        ?>
        <div class="mvt-item">
          <div class="mvt-ico" style="background:<?php echo $is_credit?'#E8F5E9':'#FFEBEE'; ?>">
            <?php echo $type_icons[$mv['type']] ?? '💰'; ?>
          </div>
          <div class="mvt-info">
            <div class="mvt-type"><?php echo $type_labels[$mv['type']] ?? ucfirst($mv['type']); ?></div>
            <div class="mvt-meta"><?php echo htmlspecialchars($mv['compte_libelle']); ?> · <?php echo date('d/m/Y', strtotime($mv['date_operation'])); ?></div>
          </div>
          <div class="mvt-right">
            <div class="mvt-montant <?php echo $is_credit?'mvt-plus':'mvt-minus'; ?>">
              <?php echo $is_credit?'+':'-'; ?><?php echo number_format($mv['montant'],0,',',' '); ?> F
            </div>
            <div class="mvt-solde">→ <?php echo number_format($mv['solde_apres'],0,',',' '); ?> F</div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</main>

<!-- ══ MODAL DÉPÔT MOBILE MONEY ══ -->
<div class="modal-overlay" id="modal-depot">
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">📱 Dépôt via Mobile Money</div>
    <div id="depot-etape1">
      <div class="form-group">
        <label>Compte à créditer *</label>
        <select id="depot-compte">
          <option value="">-- Choisir un compte --</option>
          <?php foreach ($comptes_depot as $cpt):
            $cfg = $compte_config[$cpt['type_code']] ?? ['emoji'=>'💼'];
          ?>
          <option value="<?php echo $cpt['id']; ?>"
                  data-min="<?php echo $cpt['type_code']==='ordinaire'?3500:500; ?>"
                  data-max="<?php echo $cpt['plafond_solde'] ?? 0; ?>">
            <?php echo $cfg['emoji']; ?> <?php echo htmlspecialchars($cpt['type_libelle']); ?>
            — Solde : <?php echo number_format($cpt['solde'],0,',',' '); ?> F
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Opérateur *</div>
      <div class="operateur-grid">
        <div class="operateur-btn" onclick="selectOperateur('orange',this)">
          <span class="operateur-emoji">🟠</span>
          <span class="operateur-label">Orange Money</span>
        </div>
        <div class="operateur-btn" onclick="selectOperateur('mtn',this)">
          <span class="operateur-emoji">🟡</span>
          <span class="operateur-label">MTN MoMo</span>
        </div>
        <div class="operateur-btn" onclick="selectOperateur('wave',this)">
          <span class="operateur-emoji">🔵</span>
          <span class="operateur-label">Wave</span>
        </div>
      </div>
      <input type="hidden" id="depot-operateur" value="">
      <div class="form-group">
        <label>Numéro de téléphone *</label>
        <input type="tel" id="depot-telephone" placeholder="Ex: 07 XX XX XX XX" maxlength="14">
      </div>
      <div class="form-group">
        <label>Montant (F CFA) *</label>
        <input type="number" id="depot-montant" min="500" step="100" placeholder="Ex: 10 000">
        <span class="form-hint" id="depot-hint"></span>
      </div>
      <button class="btn-submit" onclick="lancerDepot()">Payer maintenant</button>
      <button class="btn-cancel" onclick="closeModal('modal-depot')">Annuler</button>
    </div>
    <div id="depot-simulation" style="display:none">
      <div class="simulation-box" id="simBox" style="display:block">
        <span class="simulation-spinner" id="simSpinner">⏳</span>
        <span class="simulation-success" id="simSuccess">✅</span>
        <div id="simMessage" style="font-size:14px;font-weight:600;"></div>
        <div id="simRef" style="font-size:11px;margin-top:6px;font-family:monospace"></div>
      </div>
      <button class="btn-submit" id="btnSimOk" style="display:none" onclick="closeModal('modal-depot');location.reload()">Fermer</button>
    </div>
  </div>
</div>

<!-- ══ MODAL CHANGER MDP ══ -->
<div class="modal-overlay" id="modal-mdp">
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">🔑 Changer mon mot de passe</div>
    <form method="POST">
      <input type="hidden" name="action" value="changer_mdp">
      <div class="form-group">
        <label>Ancien mot de passe</label>
        <input type="password" name="ancien_mdp" required placeholder="Mot de passe actuel">
      </div>
      <div class="form-group">
        <label>Nouveau mot de passe</label>
        <input type="password" name="nouveau_mdp" required placeholder="Minimum 8 caractères">
      </div>
      <div class="form-group">
        <label>Confirmer</label>
        <input type="password" name="confirmer_mdp" required placeholder="Répéter le nouveau mot de passe">
      </div>
      <button type="submit" class="btn-submit">Confirmer</button>
      <button type="button" class="btn-cancel" onclick="closeModal('modal-mdp')">Annuler</button>
    </form>
  </div>
</div>

<script>
// ── DRAWER ──
function openDrawer(){document.getElementById('drawer').classList.add('open');document.getElementById('drawerOverlay').classList.add('open');document.body.style.overflow='hidden'}
function closeDrawer(){document.getElementById('drawer').classList.remove('open');document.getElementById('drawerOverlay').classList.remove('open');document.body.style.overflow=''}

// ── MODALS ──
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')})
});

// ── NOTIFICATIONS ──
function toggleNotifications(event) {
  event.stopPropagation();
  const panel = document.getElementById('notificationsPanel');
  const bell = document.querySelector('.notifications-bell');
  
  if (panel.classList.contains('show')) {
    panel.classList.remove('show');
  } else {
    const rect = bell.getBoundingClientRect();
    panel.style.position = 'fixed';
    panel.style.top = (rect.bottom + 8) + 'px';
    panel.style.right = (window.innerWidth - rect.right) + 'px';
    panel.style.left = 'auto';
    panel.classList.add('show');
  }
}

document.addEventListener('click', function(e) {
  const panel = document.getElementById('notificationsPanel');
  const bell = document.querySelector('.notifications-bell');
  if (panel && panel.classList.contains('show') && !panel.contains(e.target) && !bell.contains(e.target)) {
    panel.classList.remove('show');
  }
});

window.addEventListener('resize', function() {
  const panel = document.getElementById('notificationsPanel');
  if (panel && panel.classList.contains('show')) {
    const bell = document.querySelector('.notifications-bell');
    const rect = bell.getBoundingClientRect();
    panel.style.top = (rect.bottom + 8) + 'px';
    panel.style.right = (window.innerWidth - rect.right) + 'px';
  }
});

document.querySelectorAll('.notif-item').forEach(item => {
  item.addEventListener('click', function(e) {
    e.stopPropagation();
    const id = this.dataset.id;
    const lien = this.dataset.lien;
    if (id) {
      fetch('marquer_notification_lue.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
      });
    }
    if (lien) {
      window.location.href = lien;
    } else {
      this.style.opacity = '0.6';
    }
  });
});

// ── DÉPÔT MOBILE MONEY ──
let operateurSelectionne = '';

function selectOperateur(op, btn) {
  operateurSelectionne = op;
  document.getElementById('depot-operateur').value = op;
  document.querySelectorAll('.operateur-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  updateDepotHint();
}

function updateDepotHint() {
  const sel = document.getElementById('depot-compte');
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;
  const min = parseInt(opt.dataset.min) || 500;
  let hint = 'Minimum : ' + min.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ') + ' F CFA.';
  const max = parseInt(opt.dataset.max);
  if (max > 0) hint += ' Plafond : ' + max.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ') + ' F CFA.';
  document.getElementById('depot-hint').textContent = hint;
  document.getElementById('depot-montant').min = min;
}

document.getElementById('depot-compte').addEventListener('change', updateDepotHint);

function lancerDepot() {
  const compteId = document.getElementById('depot-compte').value;
  const operateur = operateurSelectionne;
  const telephone = document.getElementById('depot-telephone').value.trim();
  const montant = parseFloat(document.getElementById('depot-montant').value) || 0;
  const sel = document.getElementById('depot-compte');
  const opt = sel.options[sel.selectedIndex];
  const min = parseInt(opt.dataset.min) || 500;

  if (!compteId) { alert('Veuillez choisir un compte.'); return; }
  if (!operateur) { alert('Veuillez choisir un opérateur.'); return; }
  if (!telephone) { alert('Veuillez saisir votre numéro de téléphone.'); return; }
  if (montant < min) { alert('Montant minimum : ' + min + ' F CFA.'); return; }

  document.getElementById('depot-etape1').style.display = 'none';
  document.getElementById('depot-simulation').style.display = 'block';

  const ops = {orange:'Orange Money', mtn:'MTN MoMo', wave:'Wave'};
  const simMessage = document.getElementById('simMessage');
  const simSpinner = document.getElementById('simSpinner');
  const simSuccess = document.getElementById('simSuccess');
  const simRef = document.getElementById('simRef');
  const btnSimOk = document.getElementById('btnSimOk');
  
  simMessage.textContent = 'Paiement ' + ops[operateur] + ' en cours...';
  simMessage.style.color = '#1B5E20';
  simSpinner.style.display = 'block';
  simSuccess.style.display = 'none';
  btnSimOk.style.display = 'none';
  simRef.textContent = '';

  setTimeout(() => {
    const formData = new FormData();
    formData.append('action', 'depot_mobile');
    formData.append('compte_id', compteId);
    formData.append('operateur', operateur);
    formData.append('telephone', telephone);
    formData.append('montant', montant);

    fetch('traitement_depot_mobile.php', {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      simSpinner.style.display = 'none';
      if (data.success) {
        simSuccess.style.display = 'block';
        simMessage.textContent = data.message || 'Paiement confirmé !';
        simMessage.style.color = '#1B5E20';
      } else {
        simSuccess.style.display = 'none';
        simMessage.textContent = '❌ ' + (data.message || 'Erreur lors du traitement');
        simMessage.style.color = '#C62828';
      }
      simRef.textContent = 'Réf : ' + (data.reference || 'MOB-SIM-' + Date.now());
      btnSimOk.style.display = 'block';
    })
    .catch(() => {
      simSpinner.style.display = 'none';
      simSuccess.style.display = 'block';
      const montantFmt = Math.round(montant).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
      simMessage.textContent = montantFmt + ' F CFA reçus avec succès !';
      simMessage.style.color = '#1B5E20';
      simRef.textContent = 'Réf : MOB-SIM-' + Date.now();
      btnSimOk.style.display = 'block';
    });
  }, 3000);
}

// ── THÈME ──
function setTheme(theme, btn) {
  document.querySelectorAll('.t-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.t-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}

<?php if ($data['mot_de_passe_temporaire']): ?>
window.addEventListener('load', () => openModal('modal-mdp'));
<?php endif; ?>
</script>
</body>
</html>
