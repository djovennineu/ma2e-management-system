<?php
require_once '../connexion/auth_check.php';
checkRole('comptable');
require_once '../config/database.php';

// Activer les logs d'erreur (optionnel)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

$societe_id = (int)($_GET['societe'] ?? 0);
$mois       = $_GET['mois'] ?? date('Y-m');

// Sociétés
$societes = $pdo->query("SELECT * FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error   = '';
$rapport = null;

// ============================================================
// ACTION : LANCER LE PRÉLÈVEMENT EN LOT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prelever') {
    $societe_id_post = (int)($_POST['societe_id'] ?? 0);
    $mois_post       = $_POST['mois'] ?? date('Y-m');
    $comptes_ids     = $_POST['comptes'] ?? [];

    if (empty($comptes_ids)) {
        $error = "Aucun compte sélectionné.";
    } else {
        $rapport = ['succes' => [], 'echecs' => [], 'total' => 0];

        try {
            foreach ($comptes_ids as $compte_info) {
                list($compte_id, $membre_id, $montant) = explode('|', $compte_info);
                $compte_id = (int)$compte_id;
                $membre_id = (int)$membre_id;
                $montant = (float)$montant;

                // Récupérer le membre
                $membre = $pdo->prepare("SELECT * FROM membres WHERE id=? AND statut='actif'");
                $membre->execute([$membre_id]);
                $membre = $membre->fetch(PDO::FETCH_ASSOC);
                if (!$membre) continue;

                // Récupérer le compte
                $compte = $pdo->prepare("
                    SELECT ce.*, tce.code AS type_code
                    FROM comptes_epargne ce
                    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
                    WHERE ce.id = ? AND ce.membre_id = ? AND ce.statut = 'actif' AND ce.retenue_source = 1
                ");
                $compte->execute([$compte_id, $membre_id]);
                $compte = $compte->fetch(PDO::FETCH_ASSOC);

                if (!$compte) {
                    $rapport['echecs'][] = [
                        'membre' => $membre['nom'].' '.$membre['prenoms'],
                        'numero' => $membre['numero_membre'],
                        'motif'  => 'Compte introuvable ou retenue source désactivée'
                    ];
                    continue;
                }

                // Vérifier si déjà prélevé ce mois
                $date_ref = $mois_post . '-01';
                $deja = $pdo->prepare("
                    SELECT COUNT(*) as nb
                    FROM mouvements
                    WHERE compte_id = ? 
                    AND type = 'prelevement_salaire'
                    AND YEAR(date_operation) = YEAR(?)
                    AND MONTH(date_operation) = MONTH(?)
                ");
                $deja->execute([$compte_id, $date_ref, $date_ref]);
                $nb_preleve = $deja->fetchColumn();

                if ($nb_preleve > 0) {
                    $rapport['echecs'][] = [
                        'membre' => $membre['nom'].' '.$membre['prenoms'],
                        'numero' => $membre['numero_membre'],
                        'motif'  => "Déjà prélevé ce mois ($mois_post)"
                    ];
                    continue;
                }

                $pdo->beginTransaction();
                try {
                    $solde_avant = $compte['solde'];
                    $solde_apres = $solde_avant + $montant;
                    $ref         = 'PRE-'.date('YmdHis').'-'.str_pad($compte_id, 6, '0', STR_PAD_LEFT);
                    $date_op     = $mois_post . '-01';

                    // Enregistrer le mouvement
                    $stmt = $pdo->prepare("
                        INSERT INTO mouvements
                            (compte_id, reference, type, montant, solde_avant, solde_apres,
                             date_valeur, date_operation, canal, utilisateur_id, commentaire)
                        VALUES (?,?,'prelevement_salaire',?,?,?,?,?,'salaire',?,?)
                    ");
                    $stmt->execute([
                        $compte_id, $ref, $montant,
                        $solde_avant, $solde_apres,
                        $date_op, $date_op,
                        $_SESSION['user_id'],
                        'Prélèvement salaire '.$mois_post . ' - ' . $compte['type_code']
                    ]);

                    // Mettre à jour le solde
                    $stmt = $pdo->prepare("
                        UPDATE comptes_epargne
                        SET solde=?, solde_disponible=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $solde_apres,
                        $solde_apres - $compte['solde_bloque'],
                        $compte_id
                    ]);

                    // Si c'est un compte Expresse, mettre à jour le plan 24 mois
                    if ($compte['type_code'] === 'expresse') {
                        $stmt = $pdo->prepare("
                            UPDATE plans_epargne_expresse
                            SET mois_cotises = mois_cotises + 1
                            WHERE compte_id = ? AND statut = 'en_cours'
                        ");
                        $stmt->execute([$compte_id]);
                    }

                    $pdo->commit();

                    $rapport['succes'][] = [
                        'membre'  => $membre['nom'].' '.$membre['prenoms'],
                        'numero'  => $membre['numero_membre'],
                        'montant' => $montant,
                        'compte'  => $compte['type_code']
                    ];
                    $rapport['total'] += $montant;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $rapport['echecs'][] = [
                        'membre' => $membre['nom'].' '.$membre['prenoms'],
                        'numero' => $membre['numero_membre'],
                        'motif'  => 'Erreur : '.$e->getMessage()
                    ];
                }
            }

            $nb_ok  = count($rapport['succes']);
            $nb_ko  = count($rapport['echecs']);
            $success = "$nb_ok prélèvement(s) effectué(s) avec succès. $nb_ko échec(s).";

        } catch (Exception $e) {
            $error = "Erreur générale : ".$e->getMessage();
        }
    }
}

// ============================================================
// LISTE DES MEMBRES À PRÉLEVER (AVEC RETENUE_SOURCE = 1)
// ============================================================
$membres_liste = [];
if ($societe_id) {
    $stmt = $pdo->prepare("
        SELECT m.*
        FROM membres m
        WHERE m.societe_id = ? AND m.statut = 'actif'
        ORDER BY m.nom ASC
    ");
    $stmt->execute([$societe_id]);
    $membres_temp = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour chaque membre, récupérer les comptes avec retenue_source = 1
    foreach ($membres_temp as $m) {
        $comptes = $pdo->prepare("
            SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
                   COALESCE(ce.versement_mensuel, 0) as versement_mensuel,
                   (
                       SELECT COUNT(*) FROM mouvements
                       WHERE compte_id = ce.id AND type = 'prelevement_salaire'
                       AND YEAR(date_operation) = YEAR(?)
                       AND MONTH(date_operation) = MONTH(?)
                   ) AS deja_preleve
            FROM comptes_epargne ce
            JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
            WHERE ce.membre_id = ? AND ce.statut = 'actif' AND ce.retenue_source = 1
            ORDER BY ce.type_compte_id ASC
        ");
        $date_ref = $mois . '-01';
        $comptes->execute([$date_ref, $date_ref, $m['id']]);
        $comptes_list = $comptes->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($comptes_list)) {
            $m['comptes'] = $comptes_list;
            $membres_liste[] = $m;
        }
    }
}

// Infos société sélectionnée
$societe_sel = null;
if ($societe_id) {
    foreach ($societes as $s) {
        if ($s['id'] == $societe_id) { $societe_sel = $s; break; }
    }
}

// Stats du mois pour la société
$total_mois = 0;
$nb_traites = 0;
$nb_total_comptes = 0;
if ($societe_id) {
    foreach ($membres_liste as $m) {
        foreach ($m['comptes'] as $c) {
            $nb_total_comptes++;
            if ($c['deja_preleve'] > 0) $nb_traites++;
            $total_mois += $c['versement_mensuel'];
        }
    }
}

$nb_non_preleves = $nb_total_comptes - $nb_traites;

// Mois en cours
$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_parts = explode('-', $mois);
$mois_label = $mois_fr[(int)$mois_parts[1]] . ' ' . $mois_parts[0];
$mois_courant = date('Y-m');
$est_en_retard = ($mois < $mois_courant && $nb_non_preleves > 0);

$categories_labels = [
    'directeur'           => 'Directeur',
    'cadre'               => 'Cadre',
    'maitrise_superieure' => 'Maîtrise Sup.',
    'maitrise_1_2'        => 'Maîtrise 1&2',
    'employe_ouvrier'     => 'Employé/Ouvrier',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prélèvement Salaire — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-bg:#F5EDD6;--dark:#1a1a1a;--sidebar-w:220px;--bg:#F0EDE8;--text:#333;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--blue:#2196F3;--orange:#FF9800}
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
.filtres-card{background:var(--card);border-radius:14px;padding:20px;border:1px solid var(--border);display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group select,.form-group input{padding:10px 14px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;min-width:180px}
.form-group select:focus,.form-group input:focus{border-color:var(--gold)}
.btn-filter{padding:10px 20px;background:var(--gold);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-filter:hover{background:#b8922e}
.stats-mini{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.stat-mini{background:var(--card);border-radius:12px;padding:16px;border:1px solid var(--border);text-align:center}
.stat-mini-val{font-size:20px;font-weight:800;color:var(--text)}
.stat-mini-lbl{font-size:11.5px;color:var(--text-light);margin-top:3px}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:16px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-info{background:#E3F2FD;color:#1565C0;border-left:4px solid #1976D2}
.alert-warning{background:#FFF8E1;color:#E65100;border-left:4px solid #FFC107}
.rapport-card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.rapport-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700}
.rapport-section{padding:16px 20px}
.rapport-section-title{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.rapport-item{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-radius:8px;margin-bottom:6px;font-size:13px}
.rapport-item-ok{background:#E8F5E9;color:#1B5E20}
.rapport-item-ko{background:#FFEBEE;color:#C62828}
.card{background:var(--card);border-radius:14px;border:1px solid var(--border);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.card-title{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.card-title svg{width:15px;height:15px;color:var(--gold)}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);background:var(--bg);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:var(--bg)}
tbody tr:last-child{border-bottom:none}
td{padding:11px 16px;font-size:13px;vertical-align:middle}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:600}
.badge-ok{background:#E8F5E9;color:#2E7D32}
.badge-pending{background:#FFF8E1;color:#F57F17}
.badge-retard{background:#FFEBEE;color:#C62828}
.cb-wrap{display:flex;align-items:center;gap:8px}
input[type=checkbox]{width:16px;height:16px;accent-color:var(--gold);cursor:pointer}
.btn-valider{padding:12px 28px;background:linear-gradient(135deg,var(--green),#388E3C);color:white;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;gap:8px}
.btn-valider:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(76,175,80,.3)}
.btn-tout{padding:8px 14px;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;font-size:12.5px;color:var(--text-light);cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s}
.btn-tout:hover{border-color:var(--gold);color:var(--gold)}
.topbar-mobile{display:none;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--card);border-bottom:1px solid var(--border)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99}
.overlay.open{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}
@media(max-width:1200px){.stats-mini{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){
  body{flex-direction:column;height:auto;overflow-y:auto}
  .topbar-mobile{display:flex}
  .sidebar{position:fixed;top:0;left:0;height:100vh;z-index:200;transform:translateX(-100%);transition:transform .3s}
  .sidebar.open{transform:translateX(0)}
  .main{padding:16px}
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

<!-- SIDEBAR -->
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
    <a class="nav-item active" href="prelevement_salaire.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Prélèvement salaire
    </a>
    <a class="nav-item" href="saisir_versement.php">
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

<!-- MAIN -->
<main class="main">

  <!-- BREADCRUMB -->
  <div class="breadcrumb fadeUp">
    <a href="dashboard_comptable.php">Dashboard</a>
    <span class="sep">›</span>
    <span class="cur">Prélèvement salaire</span>
  </div>

  <!-- TITRE -->
  <div class="fadeUp">
    <h1 style="font-family:'Playfair Display',serif;font-size:24px;font-weight:600;color:var(--text)">Prélèvement mensuel sur salaire</h1>
    <p style="font-size:13.5px;color:var(--text-light);margin-top:4px">Enregistrez les cotisations prélevées sur les salaires par société</p>
  </div>

  <!-- FILTRES -->
  <form method="GET" class="filtres-card fadeUp">
    <div class="form-group">
      <label>Société</label>
      <select name="societe" onchange="this.form.submit()">
        <option value="">-- Sélectionner une société --</option>
        <?php foreach ($societes as $s): ?>
          <option value="<?php echo $s['id']; ?>" <?php echo $societe_id==$s['id']?'selected':''; ?>>
            <?php echo htmlspecialchars($s['code'].' — '.$s['nom']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Mois</label>
      <input type="month" name="mois" value="<?php echo $mois; ?>" onchange="this.form.submit()">
    </div>
  </form>

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

  <!-- ALERTE PRÉLÈVEMENTS EN RETARD -->
  <?php if ($societe_id && $nb_non_preleves > 0): ?>
    <?php if ($est_en_retard): ?>
    <div class="alert alert-error fadeUp">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>
        <strong>⚠️ PRÉLÈVEMENTS EN RETARD !</strong><br>
        Le prélèvement pour le mois de <strong><?php echo $mois_label; ?></strong> n'a pas encore été effectué.<br>
        <strong><?php echo $nb_non_preleves; ?></strong> prélèvement(s) en attente. Veuillez les traiter immédiatement.
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info fadeUp">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>
        <strong>📅 Prélèvements du mois</strong><br>
        <strong><?php echo $nb_non_preleves; ?></strong> prélèvement(s) restant(s) à effectuer pour <strong><?php echo $mois_label; ?></strong>.
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- RAPPORT APRÈS TRAITEMENT -->
  <?php if ($rapport): ?>
  <div class="rapport-card fadeUp">
    <div class="rapport-header" style="background:#E8F5E9;color:#1B5E20">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Rapport de prélèvement — <?php echo $mois_label; ?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
      <?php if (!empty($rapport['succes'])): ?>
      <div class="rapport-section" style="border-right:1px solid var(--border)">
        <div class="rapport-section-title" style="color:#2E7D32">✅ Succès (<?php echo count($rapport['succes']); ?>)</div>
        <?php foreach ($rapport['succes'] as $r): ?>
        <div class="rapport-item rapport-item-ok">
          <span><?php echo htmlspecialchars($r['membre']); ?> · <?php echo $r['numero']; ?> (<?php echo strtoupper($r['compte']); ?>)</span>
          <strong>+<?php echo number_format($r['montant'],0,',',' '); ?> F</strong>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;padding:10px;background:#C8E6C9;border-radius:8px;font-size:13px;font-weight:700;color:#1B5E20;text-align:center">
          Total crédité : <?php echo number_format($rapport['total'],0,',',' '); ?> F CFA
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($rapport['echecs'])): ?>
      <div class="rapport-section">
        <div class="rapport-section-title" style="color:#C62828">❌ Échecs (<?php echo count($rapport['echecs']); ?>)</div>
        <?php foreach ($rapport['echecs'] as $r): ?>
        <div class="rapport-item rapport-item-ko">
          <span><?php echo htmlspecialchars($r['membre']); ?></span>
          <span style="font-size:11.5px"><?php echo htmlspecialchars($r['motif']); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- LISTE DES MEMBRES -->
  <?php if ($societe_id && !empty($membres_liste)): ?>

  <!-- Stats mini -->
  <div class="stats-mini fadeUp">
    <div class="stat-mini">
      <div class="stat-mini-val"><?php echo count($membres_liste); ?></div>
      <div class="stat-mini-lbl">Membres avec retenue source</div>
    </div>
    <div class="stat-mini">
      <div class="stat-mini-val" style="color:#2E7D32"><?php echo $nb_traites; ?></div>
      <div class="stat-mini-lbl">Prélèvements déjà effectués</div>
    </div>
    <div class="stat-mini">
      <div class="stat-mini-val" style="color:#F57F17"><?php echo $nb_non_preleves; ?></div>
      <div class="stat-mini-lbl">En attente</div>
    </div>
    <div class="stat-mini">
      <div class="stat-mini-val" style="color:var(--gold)"><?php echo number_format($total_mois,0,',',' '); ?></div>
      <div class="stat-mini-lbl">F CFA total attendu</div>
    </div>
  </div>

  <!-- Tableau membres -->
  <form method="POST" id="formPrelevement">
    <input type="hidden" name="action" value="prelever">
    <input type="hidden" name="societe_id" value="<?php echo $societe_id; ?>">
    <input type="hidden" name="mois" value="<?php echo $mois; ?>">

    <div class="card fadeUp">
      <div class="card-header">
        <span class="card-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          <?php echo htmlspecialchars($societe_sel['code'].' — '.$societe_sel['nom']); ?>
          — <?php echo $mois_label; ?>
        </span>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn-tout" onclick="selectAll(true)">Tout sélectionner</button>
          <button type="button" class="btn-tout" onclick="selectAll(false)">Désélectionner</button>
          <button type="button" class="btn-tout" onclick="selectNonTraites()">Non prélevés seulement</button>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
              <th>Membre</th>
              <th>Catégorie</th>
              <th>Compte</th>
              <th>Montant mensuel</th>
              <th>Statut <?php echo $mois_label; ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($membres_liste as $m): ?>
              <?php foreach ($m['comptes'] as $c): 
                $deja = $c['deja_preleve'] > 0;
                $ini  = strtoupper(substr($m['nom'],0,1).substr($m['prenoms'],0,1));
                $compte_info = $c['id'] . '|' . $m['id'] . '|' . $c['versement_mensuel'];
                $montant_valide = $c['versement_mensuel'] > 0;
              ?>
              <tr style="<?php echo $deja?'opacity:.6':''; ?>">
                <td>
                  <?php if ($montant_valide): ?>
                    <input type="checkbox" name="comptes[]" value="<?php echo $compte_info; ?>"
                      class="cb-compte" <?php echo $deja?'disabled':'checked'; ?>>
                  <?php else: ?>
                    <span style="color:var(--red); font-size:10px;">⚠️ Montant 0</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--gold-bg);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--gold);flex-shrink:0"><?php echo $ini; ?></div>
                    <div>
                      <div style="font-weight:600"><?php echo htmlspecialchars($m['nom'].' '.$m['prenoms']); ?></div>
                      <div style="font-size:11px;color:var(--text-light)"><?php echo htmlspecialchars($m['numero_membre']); ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-size:12.5px"><?php echo $categories_labels[$m['categorie']] ?? ucfirst($m['categorie']); ?></td>
                <td style="font-size:12.5px">
                  <span class="badge" style="background:var(--gold-bg);color:var(--gold)">
                    <?php echo strtoupper($c['type_code']); ?>
                  </span>
                  <div style="font-size:10px;color:var(--text-light)"><?php echo $c['numero_compte']; ?></div>
                </td>
                <td style="font-weight:700;color:var(--gold)"><?php echo number_format($c['versement_mensuel'],0,',',' '); ?> F</td>
                <td>
                  <?php if ($deja): ?>
                    <span class="badge badge-ok">✓ Prélevé</span>
                  <?php elseif ($est_en_retard): ?>
                    <span class="badge badge-retard">⚠️ En retard</span>
                  <?php else: ?>
                    <span class="badge badge-pending">En attente</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:16px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div style="font-size:13.5px;color:var(--text-light)">
          <span id="nbSelectionnes">0</span> prélèvement(s) sélectionné(s) —
          Total : <strong id="totalSelectionnes" style="color:var(--gold)">0 F CFA</strong>
        </div>
        <button type="submit" class="btn-valider"
          onclick="return confirm('Confirmer le prélèvement pour les comptes sélectionnés ?')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Lancer le prélèvement
        </button>
      </div>
    </div>
  </form>

  <?php elseif ($societe_id && empty($membres_liste)): ?>
  <div style="text-align:center;padding:40px;background:var(--card);border-radius:14px;border:1px solid var(--border);color:var(--text-light)">
    <div style="font-size:32px;margin-bottom:12px">👥</div>
    <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px">Aucun membre avec retenue à la source</div>
    <div style="font-size:13px;color:var(--text-light)">Aucun membre de cette société n'a activé la retenue à la source.</div>
  </div>

  <?php elseif (!$societe_id): ?>
  <div style="text-align:center;padding:50px;background:var(--card);border-radius:14px;border:1px solid var(--border)">
    <div style="font-size:40px;margin-bottom:12px">🏢</div>
    <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px">Sélectionnez une société</div>
    <div style="font-size:13px;color:var(--text-light)">Choisissez une société dans le filtre ci-dessus pour voir les membres à prélever</div>
  </div>
  <?php endif; ?>

</main>

<script>
// Montants des prélèvements pour calcul dynamique
const montants = {
  <?php foreach ($membres_liste as $m): ?>
    <?php foreach ($m['comptes'] as $c): ?>
    "<?php echo $c['id'] . '|' . $m['id'] . '|' . $c['versement_mensuel']; ?>": <?php echo $c['versement_mensuel']; ?>,
    <?php endforeach; ?>
  <?php endforeach; ?>
};

function updateTotal() {
  const checked = document.querySelectorAll('.cb-compte:checked:not(:disabled)');
  let total = 0;
  checked.forEach(cb => { total += montants[cb.value] || 0; });
  document.getElementById('nbSelectionnes').textContent = checked.length;
  document.getElementById('totalSelectionnes').textContent = total.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ') + ' F CFA';
}

function toggleAll(cb) {
  document.querySelectorAll('.cb-compte:not(:disabled)').forEach(c => c.checked = cb.checked);
  updateTotal();
}

function selectAll(val) {
  document.querySelectorAll('.cb-compte:not(:disabled)').forEach(c => c.checked = val);
  document.getElementById('checkAll').checked = val;
  updateTotal();
}

function selectNonTraites() {
  document.querySelectorAll('.cb-compte').forEach(c => {
    if (!c.disabled) c.checked = true;
    else c.checked = false;
  });
  updateTotal();
}

document.querySelectorAll('.cb-compte').forEach(cb => {
  cb.addEventListener('change', updateTotal);
});

// Init
updateTotal();

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