<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

$success = '';
$error   = '';
$membre  = null;
$comptes = [];

// ── RECHERCHE MEMBRE ──
$search     = trim($_GET['search'] ?? '');
$membre_id  = (int)($_GET['membre_id'] ?? 0);
$resultats  = [];

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT m.*, s.code AS societe_code, s.nom AS societe_nom
        FROM membres m
        JOIN societes s ON m.societe_id = s.id
        WHERE m.statut = 'actif'
        AND (m.nom LIKE ? OR m.prenoms LIKE ? OR m.numero_membre LIKE ? OR m.matricule_pro LIKE ?)
        ORDER BY m.nom ASC
        LIMIT 10
    ");
    $p = '%'.$search.'%';
    $stmt->execute([$p, $p, $p, $p]);
    $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($membre_id) {
    $stmt = $pdo->prepare("
        SELECT m.*, s.code AS societe_code, s.nom AS societe_nom
        FROM membres m
        JOIN societes s ON m.societe_id = s.id
        WHERE m.id = ?
    ");
    $stmt->execute([$membre_id]);
    $membre = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($membre) {
        $stmt2 = $pdo->prepare("
            SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
                   tce.retrait_autorise, tce.plafond_solde, tce.depot_minimum
            FROM comptes_epargne ce
            JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
            WHERE ce.membre_id = ? AND ce.statut = 'actif'
            AND tce.code NOT IN ('dats')
            ORDER BY ce.date_ouverture ASC
        ");
        $stmt2->execute([$membre_id]);
        $comptes = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── FONCTION DE CALCUL DE DATE DE VALEUR ──
function calculerDateValeur($date_operation, $type_code) {
    if ($type_code === 'ordinaire') {
        $jour = (int)date('d', strtotime($date_operation));
        $mois = (int)date('m', strtotime($date_operation));
        $annee = (int)date('Y', strtotime($date_operation));
        
        // Dépôt du 01 au 15 → valeur au 16 du mois
        if ($jour <= 15) {
            return date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
        } 
        // Dépôt du 16 au 31 → valeur au 1er du mois suivant
        else {
            return date('Y-m-d', mktime(0, 0, 0, $mois + 1, 1, $annee));
        }
    }
    // Pour les autres comptes, date de valeur = date d'opération
    return $date_operation;
}

// ── ACTION : ENREGISTRER VERSEMENT ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'versement') {
    $mid       = (int)($_POST['membre_id']  ?? 0);
    $compte_id = (int)($_POST['compte_id']  ?? 0);
    $montant   = (float)($_POST['montant']  ?? 0);
    $canal     = $_POST['canal']            ?? '';
    $date_op   = $_POST['date_operation']   ?? date('Y-m-d');
    $reference = trim($_POST['reference']   ?? '');
    $commentaire = trim($_POST['commentaire'] ?? '');
    $telephone = trim($_POST['telephone']   ?? '');
    $canaux    = ['especes', 'virement', 'cheque', 'mobile_money'];

    // Récupérer le compte
    $cpt = $pdo->prepare("
        SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
               tce.plafond_solde, tce.depot_minimum
        FROM comptes_epargne ce
        JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
        WHERE ce.id = ? AND ce.membre_id = ?
    ");
    $cpt->execute([$compte_id, $mid]);
    $cpt = $cpt->fetch(PDO::FETCH_ASSOC);

    // Récupérer le membre
    $mbr = $pdo->prepare("SELECT * FROM membres WHERE id=?");
    $mbr->execute([$mid]);
    $mbr = $mbr->fetch(PDO::FETCH_ASSOC);

    if (!$cpt || !$mbr) {
        $error = "Compte ou membre introuvable.";
    } elseif ($montant <= 0) {
        $error = "Le montant doit être supérieur à 0.";
    } elseif (!in_array($canal, $canaux)) {
        $error = "Canal invalide.";
    } elseif ($canal === 'mobile_money' && empty($telephone)) {
        $error = "Veuillez renseigner le numéro de téléphone pour le dépôt mobile money.";
    } elseif ($cpt['type_code'] === 'dats') {
        $error = "Aucun versement n'est autorisé sur un compte DATS après ouverture.";
    } elseif ($cpt['depot_minimum'] && $montant < $cpt['depot_minimum']) {
        $error = "Montant minimum pour ce compte : ".number_format($cpt['depot_minimum'],0,',',' ')." F CFA.";
    } elseif ($cpt['plafond_solde'] && ($cpt['solde'] + $montant) > $cpt['plafond_solde']) {
        $error = "Ce versement dépasserait le plafond autorisé (".number_format($cpt['plafond_solde'],0,',',' ')." F CFA).";
    } elseif ($cpt['type_code'] === 'datavp' && $montant < 5000) {
        $error = "Versement minimum DATAVP : 5 000 F CFA.";
    } else {
        try {
            $pdo->beginTransaction();

            $solde_avant = $cpt['solde'];
            $solde_apres = $solde_avant + $montant;

            // Calculer la date de valeur selon le type de compte
            $date_valeur = calculerDateValeur($date_op, $cpt['type_code']);

            // Référence auto si non fournie
            if (empty($reference)) {
                $prefix = '';
                if ($canal === 'virement') $prefix = 'VIR';
                elseif ($canal === 'cheque') $prefix = 'CHQ';
                elseif ($canal === 'mobile_money') $prefix = 'MOB';
                else $prefix = strtoupper(substr($canal, 0, 3));
                $reference = $prefix.'-'.date('YmdHis').'-'.str_pad($mid,4,'0',STR_PAD_LEFT);
            }

            // Commentaire avec téléphone pour mobile money
            $commentaire_final = $commentaire;
            if ($canal === 'mobile_money' && !empty($telephone)) {
                $commentaire_final = ($commentaire ? $commentaire . ' - ' : '') . 'Tél: ' . $telephone;
            }

            // Enregistrer le mouvement
            $pdo->prepare("
                INSERT INTO mouvements
                    (compte_id, reference, type, montant, solde_avant, solde_apres,
                     date_valeur, date_operation, canal, utilisateur_id, commentaire)
                VALUES (?,?,'depot',?,?,?,?,?,?,?,?)
            ")->execute([
                $compte_id, $reference, $montant,
                $solde_avant, $solde_apres,
                $date_valeur, $date_op,
                $canal,
                $_SESSION['user_id'],
                $commentaire_final ?: 'Versement '.$canal.' — saisi par comptable'
            ]);

            // Mettre à jour le solde
            $pdo->prepare("
                UPDATE comptes_epargne
                SET solde=?, solde_disponible=?
                WHERE id=?
            ")->execute([
                $solde_apres,
                $solde_apres - $cpt['solde_bloque'],
                $compte_id
            ]);

            // Pour DATAVP : incrémenter le compteur de versements
            if ($cpt['type_code'] === 'datavp') {
                // Vérifier si la colonne nb_versements_effectues existe
                $check_column = $pdo->query("SHOW COLUMNS FROM comptes_epargne LIKE 'nb_versements_effectues'");
                if ($check_column->rowCount() > 0) {
                    $pdo->prepare("
                        UPDATE comptes_epargne
                        SET nb_versements_effectues = COALESCE(nb_versements_effectues, 0) + 1
                        WHERE id = ?
                    ")->execute([$compte_id]);
                }
            }

            $pdo->commit();

            $canal_label = match($canal) {
                'especes' => 'espèces',
                'virement' => 'virement bancaire',
                'cheque' => 'chèque',
                'mobile_money' => 'Mobile Money',
                default => $canal
            };

            $success = "
                Versement de <strong>".number_format($montant,0,',',' ')." F CFA</strong>
                enregistré avec succès sur le compte
                <strong>{$cpt['type_libelle']}</strong>
                de <strong>{$mbr['nom']} {$mbr['prenoms']}</strong>.<br>
                Référence : <code style='background:var(--bg);padding:2px 6px;border-radius:4px'>$reference</code> —
                Canal : <strong>$canal_label</strong><br>
                Date de valeur : <strong>".date('d/m/Y', strtotime($date_valeur))."</strong>
            ";

            // Recharger comptes
            $stmt2 = $pdo->prepare("
                SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
                       tce.plafond_solde, tce.depot_minimum
                FROM comptes_epargne ce
                JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
                WHERE ce.membre_id = ? AND ce.statut = 'actif'
                AND tce.code NOT IN ('dats')
                ORDER BY ce.date_ouverture ASC
            ");
            $stmt2->execute([$mid]);
            $comptes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Garder le membre affiché
            $membre_id = $mid;
            $stmt->execute([$membre_id]);
            $membre = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur : ".$e->getMessage();
        }
    }
}

$categories_labels = [
    'directeur'           => 'Directeur',
    'cadre'               => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Supérieure',
    'maitrise_1_2'        => 'Maîtrise 1 et 2',
    'employe_ouvrier'     => 'Employé / Ouvrier',
];

$compte_colors = [
    'expresse'  => ['color'=>'#C9A84C','bg'=>'#F5EDD6','emoji'=>'💰'],
    'ordinaire' => ['color'=>'#2196F3','bg'=>'#E3F2FD','emoji'=>'🏦'],
    'logement'  => ['color'=>'#4CAF50','bg'=>'#E8F5E9','emoji'=>'🏠'],
    'datavp'    => ['color'=>'#FF9800','bg'=>'#FFF3E0','emoji'=>'📊'],
    'garantie'  => ['color'=>'#F44336','bg'=>'#FFEBEE','emoji'=>'🔒'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Saisir Versement — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3}
.dark{--bg:#181818;--card:#242424;--text:#f0f0f0;--text-light:#777;--border:#333}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;transition:background .3s,color .3s}
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;z-index:100}
.logo{padding:0 20px 28px;border-bottom:1px solid #333}
.logo-text{font-family:'Playfair Display',serif;font-size:20px;color:white;letter-spacing:1px}
.logo-sub{font-size:10px;color:#C9A84C;letter-spacing:.5px;text-transform:uppercase;margin-top:2px}
.nav{flex:1;padding:20px 12px;display:flex;flex-direction:column;gap:4px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#999;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none}
.nav-item:hover{background:#2a2a2a;color:#ccc}
.nav-item.active{background:var(--gold);color:white}
.nav-item svg{width:16px;height:16px;flex-shrink:0}
.nav-section{font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:1px;padding:12px 12px 6px}
.sidebar-bottom{padding:16px 12px;border-top:1px solid #333}
.user-card{display:flex;align-items:center;gap:10px;padding:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:white}
.user-info .name{font-size:13px;font-weight:600;color:white}
.user-info .role{font-size:11px;color:#C9A84C}
.theme-toggle{display:flex;background:#2a2a2a;border-radius:20px;padding:3px;margin-top:12px;gap:2px}
.theme-btn{flex:1;padding:6px 0;border:none;background:transparent;color:#777;font-size:12px;font-weight:500;border-radius:16px;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.theme-btn.active{background:var(--gold);color:white}
.main{flex:1;overflow-y:auto;padding:28px;display:flex;flex-direction:column;gap:20px}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-light)}
.breadcrumb a{color:var(--text-light);text-decoration:none}
.breadcrumb a:hover{color:var(--gold)}
.breadcrumb .sep{opacity:.4}
.breadcrumb .cur{color:var(--text);font-weight:600}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start}
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title svg{width:15px;height:15px;color:var(--gold)}
.card-body{padding:20px}
.search-wrap{position:relative}
.search-wrap svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:17px;height:17px;color:var(--text-light)}
.search-input{width:100%;padding:12px 14px 12px 42px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.search-input:focus{border-color:var(--gold)}
.resultats-list{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.resultat-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:1.5px solid var(--border);cursor:pointer;transition:all .2s;text-decoration:none;background:var(--bg)}
.resultat-item:hover{border-color:var(--gold);background:var(--gold-bg)}
.resultat-avatar{width:38px;height:38px;border-radius:50%;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--gold);flex-shrink:0}
.resultat-nom{font-size:13.5px;font-weight:600;color:var(--text)}
.resultat-info{font-size:12px;color:var(--text-light)}
.membre-card{background:linear-gradient(135deg,var(--dark),#2a2a2a);border-radius:14px;padding:20px;color:white;position:relative;overflow:hidden;margin-bottom:0}
.membre-card::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:radial-gradient(circle,rgba(201,168,76,0.2) 0%,transparent 70%);border-radius:50%}
.membre-avatar{width:52px;height:52px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:white;margin-bottom:12px}
.membre-nom{font-size:17px;font-weight:700;color:white;margin-bottom:3px}
.membre-matricule{font-size:12px;color:var(--gold);margin-bottom:8px}
.membre-infos{display:flex;flex-wrap:wrap;gap:12px}
.membre-info-item{font-size:12px;color:rgba(255,255,255,0.6);display:flex;align-items:center;gap:5px}
.comptes-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.compte-option{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:2px solid var(--border);cursor:pointer;transition:all .2s;background:var(--bg)}
.compte-option:hover{border-color:var(--gold)}
.compte-option.selected{border-color:var(--gold);background:var(--gold-bg)}
.compte-option input[type=radio]{accent-color:var(--gold)}
.compte-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.compte-type{font-size:13px;font-weight:700;color:var(--text)}
.compte-num{font-size:11px;color:var(--text-light)}
.compte-solde{font-size:13px;font-weight:700;text-align:right}
.compte-solde-lbl{font-size:10.5px;color:var(--text-light);text-align:right}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:11px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold)}
.form-hint{font-size:11.5px;color:var(--text-light)}
.canal-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
.canal-btn{padding:10px;border:2px solid var(--border);border-radius:9px;text-align:center;cursor:pointer;transition:all .2s;font-size:12.5px;font-weight:600;color:var(--text-light);background:var(--bg)}
.canal-btn:hover{border-color:var(--gold);color:var(--gold)}
.canal-btn.active{border-color:var(--gold);background:var(--gold-bg);color:var(--gold)}
.canal-emoji{font-size:20px;display:block;margin-bottom:4px}
.preview-box{background:linear-gradient(135deg,#E8F5E9,#F1F8E9);border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin-bottom:14px}
.preview-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid rgba(76,175,80,0.2)}
.preview-row:last-child{border-bottom:none;padding-top:8px;margin-top:4px}
.preview-lbl{color:#388E3C}
.preview-val{font-weight:700;color:#1B5E20}
.preview-total{font-size:18px;font-weight:800;color:#1B5E20}
.btn-valider{width:100%;padding:14px;background:linear-gradient(135deg,var(--green),#388E3C);color:white;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-valider:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(76,175,80,.3)}
.btn-valider:disabled{opacity:.5;cursor:not-allowed;transform:none}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}
@media(max-width:1024px){.grid-2{grid-template-columns:1fr}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar-mobile{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
  .canal-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<div class="topbar-mobile">
  <button onclick="toggleSidebar()" style="background:none;border:none;cursor:pointer;color:var(--text)">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span style="font-family:'Playfair Display',serif;font-size:18px">MA2E</span>
  <div style="width:34px"></div>
</div>
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="logo">
    <div class="logo-text">MA2E</div>
    <div class="logo-sub">Comptable</div>
  </div>
  <nav class="nav">
    <div class="nav-section">Principal</div>
    <a class="nav-item" href="dashboard_comptable.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="nav-section">Opérations</div>
    <a class="nav-item" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Prélèvement salaire
    </a>
    <a class="nav-item active" href="saisir_versement.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Versement espèces/virement
    </a>
     <a class="nav-item active" href="valider_retraits.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>
      Valider retraits
    </a>
    <a class="nav-item" href="remboursements.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Remboursements crédit
    </a>
    <div class="nav-section">Calculs</div>
    <a class="nav-item" href="calcul_interets.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      Calcul des intérêts
    </a>
    <a class="nav-item" href="rapports.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Rapports financiers
    </a>
    <div class="nav-section">Compte</div>
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
        <div class="role">Comptable</div>
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
    <a href="dashboard_comptable.php">Dashboard</a>
    <span class="sep">›</span>
    <span class="cur">Versement espèces / virement</span>
  </div>

  <div class="fadeUp">
    <h1 style="font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text)">Saisir un versement</h1>
    <p style="font-size:13.5px;color:var(--text-light);margin-top:4px">Enregistrez un versement en espèces, virement bancaire, chèque ou Mobile Money</p>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <div><?php echo $success; ?></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error fadeUp">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <div><?php echo htmlspecialchars($error); ?></div>
  </div>
  <?php endif; ?>

  <div class="grid-2 fadeUp">

    <!-- COLONNE GAUCHE : RECHERCHE -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <div class="card">
        <div class="card-header">
          <span class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Étape 1 — Rechercher le membre
          </span>
        </div>
        <div class="card-body">
          <form method="GET">
            <?php if ($membre_id): ?>
              <input type="hidden" name="membre_id" value="<?php echo $membre_id; ?>">
            <?php endif; ?>
            <div class="search-wrap">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="search" class="search-input"
                placeholder="Nom, prénom, numéro membre, matricule..."
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off">
            </div>
            <button type="submit" style="margin-top:10px;width:100%;padding:10px;background:var(--gold);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif">
              Rechercher
            </button>
          </form>

          <?php if (!empty($resultats)): ?>
          <div class="resultats-list">
            <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-top:14px;margin-bottom:6px">
              <?php echo count($resultats); ?> résultat(s)
            </div>
            <?php foreach ($resultats as $r):
              $ini = strtoupper(substr($r['nom'],0,1).substr($r['prenoms'],0,1));
            ?>
            <a href="?membre_id=<?php echo $r['id']; ?>" class="resultat-item">
              <div class="resultat-avatar"><?php echo $ini; ?></div>
              <div style="flex:1">
                <div class="resultat-nom"><?php echo htmlspecialchars($r['nom'].' '.$r['prenoms']); ?></div>
                <div class="resultat-info"><?php echo htmlspecialchars($r['numero_membre']); ?> · <?php echo htmlspecialchars($r['societe_code']); ?></div>
              </div>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endforeach; ?>
          </div>
          <?php elseif ($search !== '' && empty($resultats)): ?>
          <div style="text-align:center;padding:20px;color:var(--text-light);font-size:13px;margin-top:10px">
            Aucun membre trouvé pour "<?php echo htmlspecialchars($search); ?>"
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Membre sélectionné -->
      <?php if ($membre): ?>
      <div class="membre-card">
        <div class="membre-avatar"><?php echo strtoupper(substr($membre['nom'],0,1).substr($membre['prenoms'],0,1)); ?></div>
        <div class="membre-nom"><?php echo htmlspecialchars($membre['nom'].' '.$membre['prenoms']); ?></div>
        <div class="membre-matricule"><?php echo htmlspecialchars($membre['numero_membre']); ?></div>
        <div class="membre-infos">
          <div class="membre-info-item">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <?php echo htmlspecialchars($membre['societe_code'].' — '.$membre['societe_nom']); ?>
          </div>
          <div class="membre-info-item">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?php echo $categories_labels[$membre['categorie']] ?? ucfirst($membre['categorie']); ?>
          </div>
          <div class="membre-info-item">
            💰 Cotisation : <?php echo number_format($membre['cotisation_mensuelle'],0,',',' '); ?> F/mois
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- COLONNE DROITE : FORMULAIRE -->
    <?php if ($membre && !empty($comptes)): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          Étape 2 — Saisir le versement
        </span>
      </div>
      <div class="card-body">
        <form method="POST" id="formVersement">
          <input type="hidden" name="action" value="versement">
          <input type="hidden" name="membre_id" value="<?php echo $membre['id']; ?>">
          <input type="hidden" name="compte_id" id="compteIdHidden" value="">

          <!-- Sélection compte -->
          <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
            Compte à créditer *
          </div>
          <div class="comptes-list" id="comptesList">
            <?php foreach ($comptes as $cpt):
              $cfg = $compte_colors[$cpt['type_code']] ?? ['color'=>'#888','bg'=>'#eee','emoji'=>'💼'];
            ?>
            <div class="compte-option" onclick="selectCompte(<?php echo $cpt['id']; ?>, <?php echo $cpt['depot_minimum'] ?? 0; ?>, <?php echo $cpt['plafond_solde'] ?? 0; ?>, '<?php echo $cpt['type_libelle']; ?>', <?php echo $cpt['solde']; ?>)" data-id="<?php echo $cpt['id']; ?>">
              <input type="radio" name="_compte_radio" value="<?php echo $cpt['id']; ?>">
              <div class="compte-icon" style="background:<?php echo $cfg['bg']; ?>"><?php echo $cfg['emoji']; ?></div>
              <div style="flex:1">
                <div class="compte-type"><?php echo htmlspecialchars($cpt['type_libelle']); ?></div>
                <div class="compte-num"><?php echo htmlspecialchars($cpt['numero_compte']); ?></div>
              </div>
              <div>
                <div class="compte-solde" style="color:<?php echo $cfg['color']; ?>"><?php echo number_format($cpt['solde'],0,',',' '); ?> F</div>
                <div class="compte-solde-lbl">Solde actuel</div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Canal -->
          <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">
            Canal de paiement *
          </div>
          <div class="canal-grid">
            <div class="canal-btn" onclick="selectCanal('especes',this)">
              <span class="canal-emoji">💵</span>Espèces
            </div>
            <div class="canal-btn" onclick="selectCanal('virement',this)">
              <span class="canal-emoji">🏦</span>Virement
            </div>
            <div class="canal-btn" onclick="selectCanal('cheque',this)">
              <span class="canal-emoji">📄</span>Chèque
            </div>
            <div class="canal-btn" onclick="selectCanal('mobile_money',this)">
              <span class="canal-emoji">📱</span>Mobile Money
            </div>
          </div>
          <input type="hidden" name="canal" id="canalHidden" value="">

          <!-- Numéro de téléphone pour Mobile Money -->
          <div id="telephoneGroup" class="form-group" style="display:none;">
            <label>Numéro de téléphone *</label>
            <input type="tel" name="telephone" id="telephoneInput" placeholder="Ex: 07 XX XX XX XX" maxlength="14">
            <span class="form-hint">Numéro associé au compte Mobile Money</span>
          </div>

          <!-- Montant -->
          <div class="form-group">
            <label>Montant (F CFA) *</label>
            <input type="number" name="montant" id="montantInput" min="1" step="100"
              placeholder="Ex: 50 000" required oninput="updatePreview()">
            <span class="form-hint" id="montantHint"></span>
          </div>

          <!-- Date -->
          <div class="form-group">
            <label>Date de l'opération *</label>
            <input type="date" name="date_operation" value="<?php echo date('Y-m-d'); ?>" required onchange="updatePreview()">
          </div>

          <!-- Référence (pour virement/chèque) -->
          <div id="refWrap" style="display:none">
            <div class="form-group">
              <label>Référence <span id="refLabel"></span></label>
              <input type="text" name="reference" placeholder="Ex: VIR-20260321-001 ou CHQ-123456">
              <span class="form-hint">Référence du virement ou numéro du chèque</span>
            </div>
          </div>

          <!-- Commentaire -->
          <div class="form-group">
            <label>Commentaire <span style="font-weight:400;text-transform:none">— optionnel</span></label>
            <input type="text" name="commentaire" placeholder="Ex: Versement mensuel mars 2026">
          </div>

          <!-- Preview -->
          <div class="preview-box" id="previewBox" style="display:none">
            <div class="preview-row">
              <span class="preview-lbl">Compte</span>
              <strong class="preview-val" id="prvCompte">—</strong>
            </div>
            <div class="preview-row">
              <span class="preview-lbl">Solde actuel</span>
              <strong class="preview-val" id="prvSoldeAvant">—</strong>
            </div>
            <div class="preview-row">
              <span class="preview-lbl">Canal</span>
              <strong class="preview-val" id="prvCanal">—</strong>
            </div>
            <div class="preview-row">
              <span class="preview-lbl">Solde après versement</span>
              <strong class="preview-val preview-total" id="prvSoldeApres">—</strong>
            </div>
          </div>

          <button type="submit" class="btn-valider" id="btnValider" disabled
            onclick="return validerForm()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Valider le versement
          </button>
        </form>
      </div>
    </div>

    <?php elseif ($membre && empty($comptes)): ?>
    <div style="background:var(--card);border-radius:14px;border:1px solid var(--border);padding:30px;text-align:center;color:var(--text-light)">
      <div style="font-size:32px;margin-bottom:10px">🚫</div>
      <div style="font-size:14px;font-weight:600">Aucun compte disponible</div>
      <div style="font-size:13px;margin-top:6px">Ce membre n'a pas de compte actif pour recevoir un versement.</div>
    </div>

    <?php else: ?>
    <div style="background:var(--card);border-radius:14px;border:2px dashed var(--border);padding:50px;text-align:center;color:var(--text-light)">
      <div style="font-size:40px;margin-bottom:12px">👆</div>
      <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px">Recherchez d'abord un membre</div>
      <div style="font-size:13px">Utilisez la barre de recherche à gauche pour trouver le membre</div>
    </div>
    <?php endif; ?>

  </div>

</main>

<script>
let selectedCompteId   = null;
let selectedCompteNom  = '';
let selectedSolde      = 0;
let selectedDepotMin   = 0;
let selectedPlafond    = 0;
let selectedCanal      = '';

function selectCompte(id, depotMin, plafond, nom, solde) {
  selectedCompteId  = id;
  selectedCompteNom = nom;
  selectedSolde     = solde;
  selectedDepotMin  = depotMin;
  selectedPlafond   = plafond;

  document.getElementById('compteIdHidden').value = id;

  document.querySelectorAll('.compte-option').forEach(el => {
    el.classList.toggle('selected', el.dataset.id == id);
    const radio = el.querySelector('input[type=radio]');
    if (radio) radio.checked = el.dataset.id == id;
  });

  let hint = '';
  if (depotMin > 0) hint += 'Minimum : ' + fmt(depotMin) + ' F CFA. ';
  if (plafond > 0)  hint += 'Plafond : ' + fmt(plafond) + ' F CFA.';
  document.getElementById('montantHint').textContent = hint;

  updatePreview();
  checkBtn();
}

function selectCanal(canal, btn) {
  selectedCanal = canal;
  document.getElementById('canalHidden').value = canal;
  document.querySelectorAll('.canal-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const refWrap = document.getElementById('refWrap');
  const refLbl  = document.getElementById('refLabel');
  const telephoneGroup = document.getElementById('telephoneGroup');
  
  if (canal === 'virement' || canal === 'cheque') {
    refWrap.style.display = 'block';
    refLbl.textContent = canal === 'virement' ? '(virement)' : '(chèque)';
    telephoneGroup.style.display = 'none';
  } else if (canal === 'mobile_money') {
    refWrap.style.display = 'none';
    telephoneGroup.style.display = 'block';
  } else {
    refWrap.style.display = 'none';
    telephoneGroup.style.display = 'none';
  }

  updatePreview();
  checkBtn();
}

function fmt(n) {
  return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
}

function updatePreview() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  if (!selectedCompteId || !selectedCanal || montant <= 0) {
    document.getElementById('previewBox').style.display = 'none';
    return;
  }

  const canalLabels = {
    especes: '💵 Espèces',
    virement: '🏦 Virement bancaire',
    cheque: '📄 Chèque',
    mobile_money: '📱 Mobile Money'
  };
  document.getElementById('prvCompte').textContent     = selectedCompteNom;
  document.getElementById('prvSoldeAvant').textContent = fmt(selectedSolde) + ' F CFA';
  document.getElementById('prvCanal').textContent      = canalLabels[selectedCanal] || selectedCanal;
  document.getElementById('prvSoldeApres').textContent = fmt(selectedSolde + montant) + ' F CFA';
  document.getElementById('previewBox').style.display  = 'block';
  checkBtn();
}

function checkBtn() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  let ok = selectedCompteId && selectedCanal && montant > 0;
  
  if (selectedCanal === 'mobile_money') {
    const telephone = document.getElementById('telephoneInput')?.value.trim();
    ok = ok && telephone && telephone.length >= 8;
  }
  
  document.getElementById('btnValider').disabled = !ok;
}

function validerForm() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  if (!selectedCompteId) { alert('Veuillez sélectionner un compte.'); return false; }
  if (!selectedCanal)    { alert('Veuillez sélectionner un canal.'); return false; }
  if (montant <= 0)      { alert('Veuillez saisir un montant.'); return false; }
  if (selectedCanal === 'mobile_money') {
    const telephone = document.getElementById('telephoneInput').value.trim();
    if (!telephone || telephone.length < 8) {
      alert('Veuillez saisir un numéro de téléphone valide (8 à 12 chiffres).');
      return false;
    }
  }
  if (selectedDepotMin > 0 && montant < selectedDepotMin) {
    alert('Montant minimum pour ce compte : ' + fmt(selectedDepotMin) + ' F CFA');
    return false;
  }
  if (selectedPlafond > 0 && (selectedSolde + montant) > selectedPlafond) {
    alert('Ce versement dépasserait le plafond de ' + fmt(selectedPlafond) + ' F CFA');
    return false;
  }
  return confirm('Confirmer le versement de ' + fmt(montant) + ' F CFA sur ' + selectedCompteNom + ' ?');
}

document.getElementById('montantInput')?.addEventListener('input', () => { updatePreview(); checkBtn(); });
document.getElementById('telephoneInput')?.addEventListener('input', checkBtn);

function setTheme(theme, btn) {
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.body.classList.toggle('dark', theme === 'dark');
  localStorage.setItem('ma2e_theme', theme);
}
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}
const saved = localStorage.getItem('ma2e_theme');
if (saved === 'dark') {
  document.body.classList.add('dark');
  document.querySelectorAll('.theme-btn').forEach((b,i) => b.classList.toggle('active', i===1));
}
</script>
</body>
</html>