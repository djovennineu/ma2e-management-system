<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';
 
$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];
 
$compte_id = (int)($_GET['id'] ?? 0);
if (!$compte_id) { header('Location: espace_membre.php'); exit; }
 
// Récupérer le compte (vérifie que le compte appartient bien au membre connecté)
$stmt = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
           tce.retrait_autorise, tce.plafond_solde, tce.depot_minimum
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.id = ? AND ce.membre_id = ?
");
$stmt->execute([$compte_id, $membre_id]);
$compte = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$compte) { header('Location: espace_membre.php'); exit; }
 
// Récupérer le membre
$stmt2 = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
$stmt2->execute([$membre_id]);
$membre = $stmt2->fetch(PDO::FETCH_ASSOC);
 
// Plan épargne si Expresse
$plan = null;
if ($compte['type_code'] === 'expresse') {
    $p = $pdo->prepare("SELECT * FROM plans_epargne_expresse WHERE compte_id = ? AND statut='en_cours' LIMIT 1");
    $p->execute([$compte_id]);
    $plan = $p->fetch(PDO::FETCH_ASSOC);
}
 
// ─── RÈGLES MA2E : calcul du solde minimum ───────────────────────────────────
$solde_min = 0;
if ($compte['type_code'] === 'expresse') {
    $solde_min = $membre['cotisation_mensuelle'] * 24;
} elseif ($compte['type_code'] === 'ordinaire') {
    $solde_min = 3500;
}
// Logement, DATS, DATAVP, Garantie, Credits → solde_min = 0

// ─── RÈGLES MA2E : vérification retrait disponible ce mois ───────────────────
$retrait_deja_ce_mois = false;
if ($compte['type_code'] === 'expresse') {
    $chk = $pdo->prepare("
        SELECT COUNT(*) FROM mouvements
        WHERE compte_id = ?
          AND type = 'retrait'
          AND DATE_FORMAT(date_operation, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ");
    $chk->execute([$compte_id]);
    $retrait_deja_ce_mois = ($chk->fetchColumn() > 0);
}
 
// ─── RÈGLES MA2E : vérification compte à terme non échu ──────────────────────
$compte_a_terme_non_echu = false;
if (in_array($compte['type_code'], ['dats', 'datavp'])) {
    if (!empty($compte['date_echeance']) && strtotime($compte['date_echeance']) > time()) {
        $compte_a_terme_non_echu = true;
    }
}
 
// ─── RÈGLES MA2E : vérification versement DATAVP du mois ─────────────────────
$versement_datavp_due = false;
if ($compte['type_code'] === 'datavp' && $compte['statut'] === 'actif' && !$compte_a_terme_non_echu) {
    $check = $pdo->prepare("
        SELECT COUNT(*) as nb_versements
        FROM mouvements 
        WHERE compte_id = ? 
          AND type IN ('depot', 'depot_mobile')
          AND date_operation >= DATE_FORMAT(NOW(), '%Y-%m-01')
    ");
    $check->execute([$compte_id]);
    $result = $check->fetch();
    if ($result['nb_versements'] == 0) {
        $versement_datavp_due = true;
    }
}
 
// ─── RÈGLES MA2E : logique des boutons par type de compte ────────────────────
$peut_retirer      = false;
$peut_deposer      = false;
$raison_blocage    = '';
 
switch ($compte['type_code']) {
    case 'expresse':
        if ($retrait_deja_ce_mois) {
            $raison_blocage = '1 retrait maximum par mois déjà effectué';
        } elseif ($compte['solde_disponible'] < 5000) {
            $raison_blocage = 'Montant disponible insuffisant (minimum 5 000 F)';
        } elseif (($compte['solde_disponible'] - 5000) < $solde_min) {
            $raison_blocage = 'Solde minimum de ' . number_format($solde_min, 0, ',', ' ') . ' F requis après retrait';
        } else {
            $peut_retirer = true;
        }
        $peut_deposer = true;
        break;
 
    case 'ordinaire':
        if ($compte['solde_disponible'] <= 3500) {
            $raison_blocage = 'Solde minimum de 3 500 F requis sur le compte Ordinaire';
        } else {
            $peut_retirer = true;
        }
        $peut_deposer = true;
        break;
 
    case 'logement':
        $peut_deposer = true;
        $peut_retirer = false;
        break;
 
    case 'datavp':
        $peut_deposer = true;
        $peut_retirer = false;
        if ($compte_a_terme_non_echu) {
            $raison_blocage = 'Aucun retrait autorisé avant l\'échéance du ' . date('d/m/Y', strtotime($compte['date_echeance']));
        }
        break;
 
    case 'credits':
        // Compte Crédits : retrait libre, pas de dépôt manuel
        if ($compte['solde_disponible'] <= 0) {
            $raison_blocage = 'Solde disponible insuffisant';
        } else {
            $peut_retirer = true;
        }
        $peut_deposer = false; // Pas de dépôt manuel sur le compte Crédits
        break;
 
    case 'dats':
        $peut_retirer = false;
        $peut_deposer = false;
        if ($compte_a_terme_non_echu) {
            $raison_blocage = 'Aucun mouvement autorisé avant l\'échéance du ' . date('d/m/Y', strtotime($compte['date_echeance']));
        } else {
            $raison_blocage = 'Compte à terme arrivé à échéance — contactez votre comptable';
        }
        break;
 
    case 'garantie':
        $peut_retirer = false;
        $peut_deposer = false;
        $raison_blocage = 'Compte bloqué — libéré à la clôture du crédit associé';
        break;
}
 
// Mouvements du compte (20 derniers)
$mvts = $pdo->prepare("
    SELECT * FROM mouvements
    WHERE compte_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$mvts->execute([$compte_id]);
$mouvements = $mvts->fetchAll(PDO::FETCH_ASSOC);
 
// ─── CONFIG VISUELLE PAR TYPE ─────────────────────────────────────────────────
$compte_config = [
    'expresse'  => ['icon'=>'💰','color'=>'#C9A84C','gradient'=>'linear-gradient(135deg,#C9A84C,#e8b84b)'],
    'ordinaire' => ['icon'=>'🏦','color'=>'#2196F3','gradient'=>'linear-gradient(135deg,#1976D2,#64B5F6)'],
    'logement'  => ['icon'=>'🏠','color'=>'#4CAF50','gradient'=>'linear-gradient(135deg,#388E3C,#81C784)'],
    'dats'      => ['icon'=>'📈','color'=>'#9C27B0','gradient'=>'linear-gradient(135deg,#7B1FA2,#CE93D8)'],
    'datavp'    => ['icon'=>'📊','color'=>'#FF9800','gradient'=>'linear-gradient(135deg,#F57C00,#FFB74D)'],
    'garantie'  => ['icon'=>'🔒','color'=>'#F44336','gradient'=>'linear-gradient(135deg,#C62828,#EF9A9A)'],
    'credits'   => ['icon'=>'💳','color'=>'#4CAF50','gradient'=>'linear-gradient(135deg,#4CAF50,#81C784)'],
];
$cfg = $compte_config[$compte['type_code']] ?? $compte_config['expresse'];

// Montant minimum pour dépôt selon type de compte
$depot_min = 500;
if ($compte['type_code'] === 'ordinaire') {
    $depot_min = 3500;
} elseif ($compte['type_code'] === 'datavp' && $compte['versement_mensuel'] > 0) {
    $depot_min = $compte['versement_mensuel'];
}
// Pour le compte Crédits, pas de dépôt donc pas de min

// Progression DATAVP
$datavp_progression = null;
if ($compte['type_code'] === 'datavp' && $compte['duree_mois'] > 0) {
    $nb_versements = isset($compte['nb_versements_effectues']) ? $compte['nb_versements_effectues'] : 0;
    $datavp_progression = [
        'effectues' => $nb_versements,
        'total' => $compte['duree_mois'],
        'pourcentage' => ($nb_versements / $compte['duree_mois']) * 100,
        'reste' => max(0, $compte['duree_mois'] - $nb_versements)
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($compte['type_libelle']); ?> — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336;--accent:<?php echo $cfg['color']; ?>}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}
 
.header{background:<?php echo $cfg['gradient']; ?>;padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0;transition:background .3s}
.header::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:radial-gradient(circle,rgba(255,255,255,.1) 0%,transparent 70%);border-radius:50%}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px;transition:all .2s}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;transition:all .2s}
.t-btn.active{background:rgba(255,255,255,.4)}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px;position:relative;z-index:1}
 
.carte-wrap{padding:0 20px;position:relative;z-index:1;margin-top:-10px}
.carte-solde{background:var(--card);border-radius:18px;padding:24px;box-shadow:0 8px 30px rgba(0,0,0,.12)}
.solde-label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.solde-montant{font-size:38px;font-weight:800;color:var(--text);line-height:1;margin-bottom:4px}
.solde-montant span{font-size:16px;font-weight:500;color:var(--text-light)}
.solde-num{font-size:12px;color:var(--text-light);margin-bottom:16px;font-family:monospace}
.solde-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.solde-box{background:var(--bg);border-radius:10px;padding:10px 12px}
.solde-box-lbl{font-size:11px;color:var(--text-light);margin-bottom:3px}
.solde-box-val{font-size:14px;font-weight:700;color:var(--text)}
 
.info-banner{padding:12px 16px;margin:16px 20px 0;border-radius:10px;display:flex;align-items:flex-start;gap:10px;font-size:13px;line-height:1.5}
.info-banner.info{background:#E3F2FD;border-left:4px solid #1976D2;color:#1565C0}
.info-banner.warning{background:#FFF8E1;border-left:4px solid #F57F17;color:#E65100}
.info-banner.blocked{background:#FFEBEE;border-left:4px solid #C62828;color:#B71C1C}
.info-banner.success{background:#E8F5E9;border-left:4px solid #2E7D32;color:#1B5E20}
.info-banner svg{width:18px;height:18px;flex-shrink:0;margin-top:1px}
 
.action-wrap{padding:12px 20px 0;max-width:640px;margin:0 auto;display:flex;flex-direction:column;gap:10px}
.btn-action{width:100%;padding:14px;color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}
.btn-action:hover{opacity:.9;transform:translateY(-1px)}
.btn-action.retrait{background:var(--accent)}
.btn-action.depot{background:#4CAF50}
.btn-action.disabled{background:#ccc;cursor:not-allowed;opacity:.6;transform:none}
 
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
.operateur-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.operateur-btn{padding:12px 8px;border:2px solid var(--border);border-radius:10px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.operateur-btn:hover{border-color:var(--gold)}
.operateur-btn.active{border-color:var(--gold);background:var(--gold-bg)}
.operateur-emoji{font-size:22px;display:block;margin-bottom:4px}
.operateur-label{font-size:12px;font-weight:600;color:var(--text-light)}
.simulation-box{background:linear-gradient(135deg,#E8F5E9,#F1F8E9);border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin:14px 0;display:none;text-align:center}
.simulation-spinner{font-size:30px;animation:spin 1s linear infinite;display:block;margin-bottom:8px}
.simulation-success{font-size:30px;display:none;margin-bottom:8px}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
 
.badge-echeance{display:inline-flex;align-items:center;gap:6px;background:#F3E5F5;color:#6A1B9A;font-size:12px;font-weight:700;padding:6px 12px;border-radius:20px;margin:8px 0 0}
 
.main{padding:20px;max-width:640px;margin:0 auto}
 
.plan-card,.info-card,.mvt-card{background:var(--card);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.mvt-card{padding:0;overflow:hidden}
.card-section-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px;display:flex;align-items:center;gap:6px}
.plan-bar-bg{height:8px;background:var(--border);border-radius:4px;overflow:hidden;margin:8px 0 12px}
.plan-bar-fill{height:100%;border-radius:4px;background:<?php echo $cfg['gradient']; ?>}
.plan-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.plan-stat{text-align:center;background:var(--bg);border-radius:9px;padding:10px}
.plan-stat-val{font-size:18px;font-weight:800;color:var(--accent)}
.plan-stat-lbl{font-size:10.5px;color:var(--text-light);margin-top:2px}
 
.progression-box{background:var(--bg);border-radius:12px;padding:16px;margin-bottom:16px;border:1px solid var(--border)}
.progression-title{font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text)}
.progression-bar-bg{height:10px;background:var(--border);border-radius:5px;overflow:hidden;margin-bottom:12px}
.progression-bar-fill{height:100%;border-radius:5px;background:<?php echo $cfg['gradient']; ?>}
.progression-stats{display:flex;justify-content:space-between;font-size:12px;color:var(--text-light);margin-bottom:12px}
.progression-stats strong{color:var(--text);font-size:14px}
 
.info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none}
.info-lbl{font-size:12.5px;color:var(--text-light)}
.info-val{font-size:13px;font-weight:600;color:var(--text)}
 
.terme-info{background:var(--bg);border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid var(--border)}
.terme-info-title{font-size:13px;font-weight:700;margin-bottom:8px;color:var(--text)}
.terme-row{display:flex;justify-content:space-between;font-size:12.5px;padding:4px 0}
.penalite-warn{font-size:12px;color:#C62828;margin-top:8px;padding:8px;background:#FFEBEE;border-radius:8px}
 
.mvt-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700}
.mvt-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border);transition:background .15s}
.mvt-item:last-child{border-bottom:none}
.mvt-item:hover{background:var(--bg)}
.mvt-ico{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.mvt-info{flex:1}
.mvt-type{font-size:13px;font-weight:600}
.mvt-date{font-size:11px;color:var(--text-light)}
.mvt-montant{font-size:13.5px;font-weight:700}
.mvt-plus{color:#2E7D32}.mvt-minus{color:#C62828}
.mvt-empty{padding:30px;text-align:center;color:var(--text-light);font-size:13px}
 
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.fadeUp{animation:fadeUp .35s ease both}
 
@media(min-width:768px){
  .header{padding:24px 40px 70px}
  .carte-wrap,.action-wrap,.main{max-width:640px;margin-left:auto;margin-right:auto}
  .info-banner{margin:16px auto 0;max-width:640px}
  .modal{border-radius:20px;margin-bottom:20px}
  .modal-overlay{align-items:center}
}
</style>
</head>
<body>
 
<header class="header">
  <div class="header-top fadeUp">
    <a href="espace_membre.php" class="btn-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
    <div class="t-btns">
      <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
      <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
    </div>
  </div>
  <div class="header-label"><?php echo $cfg['icon']; ?> <?php echo htmlspecialchars($compte['type_libelle']); ?></div>
</header>
 
<div class="carte-wrap fadeUp">
  <div class="carte-solde">
    <div class="solde-label">Solde total</div>
    <div class="solde-montant"><?php echo number_format($compte['solde'],0,',',' '); ?> <span>F CFA</span></div>
    <div class="solde-num"><?php echo htmlspecialchars($compte['numero_compte']); ?></div>
    <div class="solde-grid">
      <div class="solde-box">
        <div class="solde-box-lbl">Disponible</div>
        <div class="solde-box-val" style="color:#2E7D32"><?php echo number_format($compte['solde_disponible'],0,',',' '); ?> F</div>
      </div>
      <div class="solde-box">
        <div class="solde-box-lbl">Bloqué</div>
        <div class="solde-box-val" style="color:#F57F17"><?php echo number_format($compte['solde_bloque'],0,',',' '); ?> F</div>
      </div>
      <div class="solde-box">
        <div class="solde-box-lbl">Taux annuel</div>
        <div class="solde-box-val" style="color:var(--accent)"><?php echo $compte['taux_interet']; ?>%</div>
      </div>
      <div class="solde-box">
        <div class="solde-box-lbl">Ouvert le</div>
        <div class="solde-box-val"><?php echo date('d/m/Y', strtotime($compte['date_ouverture'])); ?></div>
      </div>
 
      <?php if ($solde_min > 0): ?>
      <div class="solde-box" style="grid-column:1/-1">
        <div class="solde-box-lbl">Solde minimum requis</div>
        <div class="solde-box-val" style="color:<?php echo $compte['solde'] >= $solde_min ? '#2E7D32' : '#C62828'; ?>">
          <?php echo number_format($solde_min,0,',',' '); ?> F
          <?php echo $compte['solde'] >= $solde_min ? '✅' : '⚠️ Insuffisant'; ?>
        </div>
      </div>
      <?php endif; ?>
 
      <?php if ($compte['type_code'] === 'ordinaire' && $compte['plafond_solde'] > 0): ?>
      <div class="solde-box" style="grid-column:1/-1">
        <div class="solde-box-lbl">Plafond</div>
        <div class="solde-box-val"><?php echo number_format($compte['plafond_solde'],0,',',' '); ?> F</div>
      </div>
      <?php endif; ?>
      
      <?php if ($compte['type_code'] === 'credits'): ?>
      <div class="solde-box" style="grid-column:1/-1; background:#E8F5E9;">
        <div class="solde-box-lbl">Type de compte</div>
        <div class="solde-box-val" style="color:#2E7D32">💳 Compte Crédits — Retraits libres</div>
      </div>
      <?php endif; ?>
    </div>
 
    <?php if (!empty($compte['date_echeance'])): ?>
    <div class="badge-echeance">
      📅 Échéance : <?php echo date('d/m/Y', strtotime($compte['date_echeance'])); ?>
      <?php if ($compte['duree_mois']): ?> — <?php echo $compte['duree_mois']; ?> mois<?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
 
<!-- BANDEAUX D'INFORMATION -->
<?php if ($compte['type_code'] === 'credits'): ?>
<div class="info-banner success fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p><strong>💳 Compte Crédits</strong> — Ce compte reçoit vos crédits approuvés. Les retraits sont libres et sans restriction. Aucun dépôt manuel n'est autorisé sur ce compte.</p>
</div>
 
<?php elseif ($compte['type_code'] === 'garantie'): ?>
<div class="info-banner blocked fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
  <p><strong>Compte Garantie bloqué.</strong> Ce compte est constitué automatiquement lors de l'octroi d'un crédit. Il sera libéré sur votre compte Ordinaire dès le remboursement intégral du crédit associé.</p>
</div>
 
<?php elseif (in_array($compte['type_code'], ['dats', 'datavp']) && $compte_a_terme_non_echu): ?>
<div class="info-banner warning fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p><strong>Compte à terme en cours.</strong> Aucun <?php echo $compte['type_code'] === 'datavp' ? 'retrait' : 'versement ni retrait'; ?> n'est possible avant l'échéance du <strong><?php echo date('d/m/Y', strtotime($compte['date_echeance'])); ?></strong>.
  <?php if ($compte['type_code'] === 'datavp'): ?>
    Les versements mensuels restent autorisés.
  <?php else: ?>
    Un retrait anticipé entraîne une pénalité de 2%.
  <?php endif; ?>
  </p>
</div>
 
<?php elseif ($compte['type_code'] === 'datavp' && $versement_datavp_due): ?>
<div class="info-banner warning fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p><strong>⚠️ Versement mensuel DATAVP dû ce mois.</strong> Le montant minimum est de <strong><?php echo number_format($compte['versement_mensuel'],0,',',' '); ?> F CFA</strong>. Effectuez votre versement via Mobile Money.</p>
</div>
 
<?php elseif ($compte['type_code'] === 'logement'): ?>
<div class="info-banner info fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
  <p>Les <strong>versements</strong> sur ce compte sont libres et sans minimum. <strong>Aucun retrait</strong> n'est autorisé — ce compte sera clôturé à la livraison de votre logement.</p>
</div>
 
<?php elseif ($compte['type_code'] === 'expresse' && $retrait_deja_ce_mois): ?>
<div class="info-banner warning fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p><strong>Retrait du mois déjà effectué.</strong> Le compte Expresse autorise 1 retrait maximum par mois. Vous pourrez effectuer un nouveau retrait à partir du mois prochain.</p>
</div>
 
<?php elseif ($compte['type_code'] === 'expresse' || $compte['type_code'] === 'ordinaire'): ?>
<div class="info-banner info fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p>
    <strong>Versements :</strong> par prélèvement salaire (si activé), ou manuellement via 
    <strong>Mobile Money</strong> (Orange, MTN, Wave) depuis cette page.<br>
    <?php if ($peut_retirer): ?>
      <strong>Retrait :</strong> <?php echo $compte['type_code'] === 'expresse' ? '1 fois par mois maximum (min 5 000 F)' : 'libre (min 3 500 F)'; ?>.
    <?php endif; ?>
  </p>
</div>
 
<?php else: ?>
<div class="info-banner info fadeUp">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <p>
    <?php if ($peut_deposer): ?>
      Effectuez vos <strong>versements</strong> via Mobile Money (Orange, MTN, Wave) depuis cette page.
    <?php else: ?>
      Aucune opération manuelle n'est disponible sur ce compte.
    <?php endif; ?>
  </p>
</div>
<?php endif; ?>
 
<!-- BOUTONS D'ACTION -->
<div class="action-wrap fadeUp">
 
  <?php if ($peut_retirer): ?>
  <a href="demande_retrait.php?compte_id=<?php echo $compte['id']; ?>" class="btn-action retrait">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
    Faire une demande de retrait
  </a>
 
  <?php elseif (!$peut_retirer && !$peut_deposer && !empty($raison_blocage)): ?>
  <button class="btn-action disabled" disabled>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
    <?php echo htmlspecialchars($raison_blocage); ?>
  </button>
 
  <?php elseif (!$peut_retirer && !$peut_deposer): ?>
  <button class="btn-action disabled" disabled>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
    Aucune opération disponible
  </button>
  <?php endif; ?>
 
  <?php if ($peut_deposer): ?>
  <button onclick="openDepotModal()" class="btn-action depot">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12l7-7 7 7"/></svg>
    Effectuer un versement
  </button>
  <?php endif; ?>
 
</div>
 
<main class="main">
 
  <!-- PLAN 24 MOIS (Expresse uniquement) -->
  <?php if ($plan): ?>
  <?php $pct = $plan['mois_cotises'] > 0 ? round($plan['mois_cotises'] / 24 * 100) : 0; ?>
  <div class="plan-card fadeUp">
    <div class="card-section-title">📅 Plan épargne 24 mois</div>
    <div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--text-light)">
      <span>Progression</span>
      <strong style="color:var(--accent)"><?php echo $pct; ?>%</strong>
    </div>
    <div class="plan-bar-bg">
      <div class="plan-bar-fill" style="width:<?php echo $pct; ?>%"></div>
    </div>
    <div class="plan-stats">
      <div class="plan-stat">
        <div class="plan-stat-val"><?php echo $plan['mois_cotises']; ?></div>
        <div class="plan-stat-lbl">Mois cotisés</div>
      </div>
      <div class="plan-stat">
        <div class="plan-stat-val"><?php echo 24 - $plan['mois_cotises']; ?></div>
        <div class="plan-stat-lbl">Restants</div>
      </div>
      <div class="plan-stat">
        <div class="plan-stat-val"><?php echo number_format($membre['cotisation_mensuelle']/1000,0); ?>k</div>
        <div class="plan-stat-lbl">F/mois</div>
      </div>
    </div>
    <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-light)">
      <span>Début : <?php echo date('d/m/Y', strtotime($plan['date_debut'])); ?></span>
      <span>Fin prévue : <?php echo date('d/m/Y', strtotime($plan['date_fin'])); ?></span>
    </div>
    <?php if ($plan['mois_cotises'] >= 24): ?>
    <div style="margin-top:10px;padding:8px 12px;background:#E8F5E9;border-radius:8px;font-size:12.5px;color:#2E7D32;font-weight:600">
      ✅ Plan complété — Éligible au Crédit Expresse
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
 
  <!-- PROGRESSION DATAVP -->
  <?php if ($datavp_progression && $compte['type_code'] === 'datavp'): ?>
  <div class="progression-box fadeUp">
    <div class="progression-title">📊 Progression du plan d'épargne</div>
    <div class="progression-bar-bg">
      <div class="progression-bar-fill" style="width: <?php echo $datavp_progression['pourcentage']; ?>%;"></div>
    </div>
    <div class="progression-stats">
      <span>Versements effectués : <strong><?php echo $datavp_progression['effectues']; ?></strong> / <?php echo $datavp_progression['total']; ?></span>
      <span>Reste : <strong><?php echo $datavp_progression['reste']; ?></strong> mois</span>
    </div>
    <?php if ($datavp_progression['effectues'] >= $datavp_progression['total']): ?>
    <div style="margin-top:12px;padding:10px 12px;background:#E8F5E9;border-radius:8px;border-left:3px solid #4CAF50;">
      <div style="font-size:12px;font-weight:600;color:#2E7D32;">✅ Plan complété !</div>
      <div style="font-size:11px;color:#1B5E20;margin-top:4px;">Vous avez atteint le nombre de versements prévu. Le capital et les intérêts seront versés sur votre compte Ordinaire à l'échéance.</div>
    </div>
    <?php elseif ($datavp_progression['reste'] <= 3): ?>
    <div style="margin-top:12px;padding:10px 12px;background:#FFF8E1;border-radius:8px;border-left:3px solid #FFC107;">
      <div style="font-size:12px;font-weight:600;color:#E65100;">⚠️ Bientôt la fin !</div>
      <div style="font-size:11px;color:#F57F17;margin-top:4px;">Plus que <?php echo $datavp_progression['reste']; ?> versement(s) pour compléter votre plan.</div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
 
  <!-- INFO COMPLÉMENTAIRE DATS/DATAVP -->
  <?php if (in_array($compte['type_code'], ['dats', 'datavp'])): ?>
  <div class="terme-info fadeUp">
    <div class="terme-info-title">📋 Conditions du placement</div>
    <?php if ($compte['type_code'] === 'dats'): ?>
      <div class="terme-row"><span style="color:var(--text-light)">Type</span><span>DATS — Dépôt à terme simple</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Dépôt initial</span><span><?php echo number_format($compte['solde'],0,',',' '); ?> F</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Durée</span><span><?php echo $compte['duree_mois']; ?> mois</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Taux</span><span style="color:var(--accent);font-weight:700"><?php echo $compte['taux_interet']; ?>% / an</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Intérêts versés</span><span>À l'échéance sur compte Ordinaire</span></div>
      <div class="penalite-warn">⚠️ Retrait anticipé : pénalité de 2% sur le capital</div>
    <?php else: ?>
      <div class="terme-row"><span style="color:var(--text-light)">Type</span><span>DATAVP — Plan d'épargne à versements périodiques</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Versement mensuel</span><span><?php echo number_format($compte['versement_mensuel'],0,',',' '); ?> F</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Durée</span><span><?php echo $compte['duree_mois']; ?> mois</span></div>
      <div class="terme-row"><span style="color:var(--text-light)">Taux</span><span style="color:var(--accent);font-weight:700"><?php echo $compte['taux_interet']; ?>% / an</span></div>
      <?php
        $anciennete_mois = (time() - strtotime($compte['date_ouverture'])) / (30 * 86400);
        $penalite = $anciennete_mois < 24 ? '2%' : '1%';
        $penalite_warn = $anciennete_mois < 24 ? 'avant 2 ans' : 'après 2 ans';
      ?>
      <div class="penalite-warn">⚠️ Pénalité retrait anticipé : <?php echo $penalite; ?> (<?php echo $penalite_warn; ?>)</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
 
  <!-- DÉTAILS DU COMPTE -->
  <div class="info-card fadeUp">
    <div class="card-section-title">ℹ️ Détails du compte</div>
    <div class="info-row">
      <span class="info-lbl">Statut</span>
      <span class="info-val" style="color:<?php echo $compte['statut']==='actif'?'#2E7D32':'#F44336'; ?>">
        <?php echo $compte['statut'] === 'actif' ? '✅ Actif' : ucfirst($compte['statut']); ?>
      </span>
    </div>
    <?php if (!in_array($compte['type_code'], ['dats', 'garantie', 'credits'])): ?>
    <div class="info-row">
      <span class="info-lbl">Mode de versement</span>
      <span class="info-val">
        <?php echo !empty($compte['retenue_source']) ? '🏢 Retenue à la source' : '🤝 Versement manuel'; ?>
      </span>
    </div>
    <?php elseif ($compte['type_code'] === 'credits'): ?>
    <div class="info-row">
      <span class="info-lbl">Mode de versement</span>
      <span class="info-val">💳 Réception des crédits uniquement</span>
    </div>
    <?php else: ?>
    <div class="info-row">
      <span class="info-lbl">Mode de versement</span>
      <span class="info-val">🤝 Manuel uniquement</span>
    </div>
    <?php endif; ?>
    <?php if ($compte['taux_interet'] > 0): ?>
    <div class="info-row">
      <span class="info-lbl">Taux d'intérêt</span>
      <span class="info-val" style="color:var(--accent)"><?php echo $compte['taux_interet']; ?>% / an</span>
    </div>
    <?php else: ?>
    <div class="info-row">
      <span class="info-lbl">Taux d'intérêt</span>
      <span class="info-val" style="color:var(--text-light)">Pas d'intérêts sur ce compte</span>
    </div>
    <?php endif; ?>
    <?php if ($compte['date_echeance']): ?>
    <div class="info-row">
      <span class="info-lbl">Date d'échéance</span>
      <span class="info-val"><?php echo date('d/m/Y', strtotime($compte['date_echeance'])); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($compte['duree_mois']): ?>
    <div class="info-row">
      <span class="info-lbl">Durée</span>
      <span class="info-val"><?php echo $compte['duree_mois']; ?> mois</span>
    </div>
    <?php endif; ?>
    <?php if ($compte['versement_mensuel'] && $compte['type_code'] === 'datavp'): ?>
    <div class="info-row">
      <span class="info-lbl">Versement mensuel minimum</span>
      <span class="info-val"><?php echo number_format($compte['versement_mensuel'],0,',',' '); ?> F CFA</span>
    </div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-lbl">Retraits</span>
      <span class="info-val" style="color:<?php echo $peut_retirer ? '#2E7D32' : '#C62828'; ?>">
        <?php echo $peut_retirer ? '✅ Disponible' : '❌ Non disponible'; ?>
      </span>
    </div>
    <?php if (!empty($raison_blocage) && !$peut_retirer): ?>
    <div style="padding:8px 0;font-size:12px;color:#C62828">
      ⚠️ <?php echo htmlspecialchars($raison_blocage); ?>
    </div>
    <?php endif; ?>
  </div>
 
  <!-- MOUVEMENTS RÉCENTS -->
  <div class="mvt-card fadeUp">
    <div class="mvt-header">📋 Mouvements récents (20 derniers)</div>
    <?php if (empty($mouvements)): ?>
      <div class="mvt-empty">Aucun mouvement sur ce compte</div>
    <?php else: ?>
      <?php
      $type_labels = [
        'depot'               => 'Dépôt',
        'retrait'             => 'Retrait',
        'interet'             => 'Intérêts',
        'penalite'            => 'Pénalité',
        'remboursement'       => 'Remboursement',
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
      $types_credit = ['depot','interet','remboursement','deblocage_garantie','prelevement_salaire','depot_mobile','credit_octroi'];
      foreach ($mouvements as $mv):
        $is_c = in_array($mv['type'], $types_credit);
      ?>
      <div class="mvt-item">
        <div class="mvt-ico" style="background:<?php echo $is_c ? '#E8F5E9' : '#FFEBEE'; ?>">
          <?php echo $type_icons[$mv['type']] ?? '💰'; ?>
        </div>
        <div class="mvt-info">
          <div class="mvt-type"><?php echo $type_labels[$mv['type']] ?? ucfirst($mv['type']); ?></div>
          <div class="mvt-date"><?php echo date('d/m/Y', strtotime($mv['date_operation'])); ?> · <?php echo ucfirst($mv['canal']); ?></div>
        </div>
        <div style="text-align:right">
          <div class="mvt-montant <?php echo $is_c ? 'mvt-plus' : 'mvt-minus'; ?>">
            <?php echo $is_c ? '+' : '-'; ?><?php echo number_format($mv['montant'],0,',',' '); ?> F
          </div>
          <div style="font-size:10.5px;color:var(--text-light)">→ <?php echo number_format($mv['solde_apres'],0,',',' '); ?> F</div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
 
</main>
 
<!-- MODAL DÉPÔT MOBILE MONEY -->
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
 
<script>
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

// ── MODAL ──
function openDepotModal() {
  document.getElementById('modal-depot').classList.add('open');
  document.getElementById('depot-etape1').style.display = 'block';
  document.getElementById('depot-simulation').style.display = 'none';
  document.getElementById('depot-montant').value = '';
  document.getElementById('depot-telephone').value = '';
  document.getElementById('depot-operateur').value = '';
  document.querySelectorAll('.operateur-btn').forEach(b => b.classList.remove('active'));
  operateurSelectionne = '';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')})
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
</script>
</body>
</html>