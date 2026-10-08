<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

$membre = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
$membre->execute([$membre_id]);
$membre = $membre->fetch(PDO::FETCH_ASSOC);

$success = '';
$error   = '';

// Compte présélectionné depuis fiche_epargne.php
$compte_id_pre = (int)($_GET['compte_id'] ?? 0);

// Comptes qui autorisent les retraits
$comptes = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ?
    AND ce.statut = 'actif'
    AND tce.retrait_autorise = 1
    ORDER BY ce.date_ouverture ASC
");
$comptes->execute([$membre_id]);
$comptes = $comptes->fetchAll(PDO::FETCH_ASSOC);

// Demandes de retrait en cours
$demandes_en_cours = [];
try {
    $d = $pdo->prepare("
        SELECT dr.*, tce.libelle AS compte_libelle, ce.numero_compte
        FROM demandes_retrait dr
        JOIN comptes_epargne ce ON dr.compte_id = ce.id
        JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
        WHERE dr.membre_id = ?
        ORDER BY dr.created_at DESC
        LIMIT 5
    ");
    $d->execute([$membre_id]);
    $demandes_en_cours = $d->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table pas encore créée
}

// ── ACTION : SOUMETTRE DEMANDE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'demander') {
    $compte_id = (int)($_POST['compte_id'] ?? 0);
    $montant   = (float)($_POST['montant']   ?? 0);
    $motif     = trim($_POST['motif']        ?? '');
    $canal     = $_POST['canal']             ?? '';
    $telephone = trim($_POST['telephone']    ?? '');
    $canaux    = ['especes', 'virement', 'mobile_money'];

    // Récupérer le compte
    $cpt = $pdo->prepare("
        SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle, tce.retrait_autorise
        FROM comptes_epargne ce
        JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
        WHERE ce.id = ? AND ce.membre_id = ? AND ce.statut = 'actif'
    ");
    $cpt->execute([$compte_id, $membre_id]);
    $cpt = $cpt->fetch(PDO::FETCH_ASSOC);

    if (!$cpt) {
        $error = "Compte introuvable.";
    } elseif (!$cpt['retrait_autorise']) {
        $error = "Les retraits ne sont pas autorisés sur ce compte.";
    } elseif ($montant <= 0) {
        $error = "Le montant doit être supérieur à 0.";
    } elseif ($cpt['type_code'] === 'expresse' && $montant < 5000) {
        $error = "Retrait minimum sur le compte Expresse : 5 000 F CFA.";
    } elseif ($montant > $cpt['solde_disponible']) {
        $error = "Solde disponible insuffisant. Disponible : ".number_format($cpt['solde_disponible'],0,',',' ')." F CFA.";
    } elseif (!in_array($canal, $canaux)) {
        $error = "Canal de retrait invalide.";
    } elseif ($canal === 'mobile_money' && empty($telephone)) {
        $error = "Veuillez renseigner votre numéro de téléphone pour le retrait mobile money.";
    } else {
        // Vérifier solde minimum après retrait
        $solde_min = $cpt['type_code'] === 'expresse'
            ? $membre['cotisation_mensuelle'] * 24
            : ($cpt['type_code'] === 'ordinaire' ? 3500 : 0);

        if ($cpt['solde'] - $montant < $solde_min) {
            $error = "Après ce retrait, votre solde serait inférieur au minimum requis (".number_format($solde_min,0,',',' ')." F CFA).";
        } else {
            // Vérifier 1 retrait/mois pour Expresse
            if ($cpt['type_code'] === 'expresse') {
                try {
                    $nb = $pdo->prepare("
                        SELECT COUNT(*) FROM demandes_retrait
                        WHERE compte_id = ? AND statut IN ('en_attente','validee')
                        AND DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
                    ");
                    $nb->execute([$compte_id]);
                    if ($nb->fetchColumn() >= 1) {
                        $error = "Vous avez déjà une demande de retrait ce mois sur ce compte. Maximum 1 retrait par mois.";
                    }
                } catch(Exception $e) {}
            }

            if (empty($error)) {
                try {
                    $ref = 'RET-'.date('YmdHis').'-'.rand(100,999);
                    
                    $commentaire = $motif;
                    if ($canal === 'mobile_money' && !empty($telephone)) {
                        $commentaire = ($motif ? $motif . " - " : "") . "Tél: " . $telephone;
                    }
                    
                    $pdo->prepare("
                        INSERT INTO demandes_retrait
                            (membre_id, compte_id, reference, montant, canal, motif,
                             solde_disponible, statut, created_at)
                        VALUES (?,?,?,?,?,?,?,'en_attente',NOW())
                    ")->execute([
                        $membre_id, $compte_id, $ref, $montant,
                        $canal, $commentaire ?: null,
                        $cpt['solde_disponible']
                    ]);

                    $canal_label = match($canal) {
                        'especes' => 'au bureau MA2E',
                        'virement' => 'par virement bancaire',
                        'mobile_money' => 'via Mobile Money (Wave)',
                        default => $canal
                    };
                    
                    $success = "Votre demande de retrait de <strong>".number_format($montant,0,',',' ')." F CFA</strong> a été soumise avec succès.<br>
                        Référence : <strong>$ref</strong><br>
                        Mode de récupération : $canal_label<br>
                        Le comptable traitera votre demande dans les meilleurs délais.";

                    // Recharger demandes
                    $d->execute([$membre_id]);
                    $demandes_en_cours = $d->fetchAll(PDO::FETCH_ASSOC);

                } catch (Exception $e) {
                    if (strpos($e->getMessage(), "doesn't exist") !== false) {
                        $error = "La table demandes_retrait n'existe pas encore. Veuillez exécuter le script SQL de mise à jour.";
                    } else {
                        $error = "Erreur : ".$e->getMessage();
                    }
                }
            }
        }
    }
}

$compte_colors = [
    'expresse'  => ['emoji'=>'💰','color'=>'#C9A84C','bg'=>'#F5EDD6'],
    'ordinaire' => ['emoji'=>'🏦','color'=>'#2196F3','bg'=>'#E3F2FD'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demande de retrait — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
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

/* HEADER */
.header{background:linear-gradient(135deg,var(--dark),var(--dark3));padding:20px 24px 65px;position:relative;overflow:hidden}
.header::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;background:radial-gradient(circle,rgba(201,168,76,.12) 0%,transparent 70%);border-radius:50%}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0;transition:background .3s}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px;transition:all .2s}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px;position:relative;z-index:1}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;transition:all .2s}
.t-btn.active{background:rgba(255,255,255,.4)}

/* CONTENT */
.content{max-width:640px;margin:0 auto;padding:20px}

/* ALERTS */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;animation:fadeUp .3s ease}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-info{background:#E3F2FD;color:#1565C0;border-left:4px solid #1976D2}

/* CARD */
.card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:22px}
.card-header-title{font-size:15px;font-weight:700;color:var(--text)}
.card-body{padding:20px}

/* COMPTES */
.section-label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.comptes-list{display:flex;flex-direction:column;gap:10px;margin-bottom:20px}
.compte-opt{display:flex;align-items:center;gap:12px;padding:14px 16px;border:2px solid var(--border);border-radius:12px;cursor:pointer;transition:all .2s;background:var(--card)}
.compte-opt:hover{border-color:var(--gold);transform:translateY(-1px)}
.compte-opt.selected{border-color:var(--gold);background:var(--gold-bg)}
.compte-opt input[type=radio]{accent-color:var(--gold);flex-shrink:0;width:18px;height:18px}
.compte-icon{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.compte-info{flex:1}
.compte-nom{font-size:14px;font-weight:700;color:var(--text)}
.compte-num{font-size:11px;color:var(--text-light);margin-top:2px}
.compte-solde{text-align:right}
.compte-solde-val{font-size:16px;font-weight:800;color:var(--gold)}
.compte-solde-lbl{font-size:10px;color:var(--text-light)}
.compte-solde-min{font-size:10px;color:#F57F17;margin-top:2px}

/* FORMULAIRE */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold)}
.form-group textarea{resize:vertical;min-height:80px}
.form-hint{font-size:11px;color:var(--text-light);margin-top:4px}

/* CANAUX */
.canal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
.canal-btn{padding:14px 8px;border:2px solid var(--border);border-radius:12px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.canal-btn:hover{border-color:var(--gold);transform:translateY(-1px)}
.canal-btn.active{border-color:var(--gold);background:var(--gold-bg)}
.canal-emoji{font-size:24px;display:block;margin-bottom:6px}
.canal-label{font-size:12px;font-weight:600;color:var(--text-light)}
.canal-btn.active .canal-label{color:var(--gold)}

/* PREVIEW */
.preview-box{background:linear-gradient(135deg,#E8F5E9,#F1F8E9);border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin-bottom:20px;display:none}
.preview-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(76,175,80,.15)}
.preview-row:last-child{border-bottom:none;padding-top:12px}
.preview-lbl{font-size:12px;color:#388E3C}
.preview-val{font-weight:600;color:#1B5E20}
.preview-total{font-size:20px;font-weight:800;color:#1B5E20}

/* BOUTON */
.btn-submit{width:100%;padding:14px;background:var(--red);color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{background:#C62828}
.btn-submit:disabled{opacity:.4;cursor:not-allowed}

/* DEMANDES EN COURS */
.demandes-card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px}
.demandes-header{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text)}
.demande-item{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid var(--border)}
.demande-item:last-child{border-bottom:none}
.demande-ref{font-size:11px;color:var(--text-light);font-family:monospace}
.demande-montant{font-size:14px;font-weight:700;color:var(--red)}
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-attente{background:#FFF8E1;color:#F57F17}
.badge-validee{background:#E8F5E9;color:#2E7D32}
.badge-rejetee{background:#FFEBEE;color:#C62828}

/* EMPTY */
.empty-state{text-align:center;padding:60px 20px;background:var(--card);border-radius:16px;border:1px solid var(--border)}
.empty-icon{font-size:48px;margin-bottom:16px}
.empty-title{font-size:16px;font-weight:700;margin-bottom:8px}
.empty-text{font-size:13px;color:var(--text-light)}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:640px){
  .content{padding:16px}
  .canal-grid{grid-template-columns:1fr}
  .compte-opt{padding:12px}
  .compte-icon{width:36px;height:36px;font-size:16px}
  .compte-solde-val{font-size:14px}
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
  <div class="header-label">💸 Demande de retrait</div>
</header>

<div class="content">

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

  <!-- DEMANDES EN COURS -->
  <?php if (!empty($demandes_en_cours)): ?>
  <div class="demandes-card fadeUp">
    <div class="demandes-header">📋 Mes demandes récentes</div>
    <?php foreach ($demandes_en_cours as $d):
      $badge_class = match($d['statut']) {
        'en_attente' => 'badge-attente',
        'validee'    => 'badge-validee',
        'rejetee'    => 'badge-rejetee',
        default      => 'badge-attente'
      };
      $statut_label = match($d['statut']) {
        'en_attente' => '⏳ En attente',
        'validee'    => '✅ Validée',
        'rejetee'    => '❌ Rejetée',
        default      => ucfirst($d['statut'])
      };
    ?>
    <div class="demande-item">
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600;"><?php echo htmlspecialchars($d['compte_libelle']); ?></div>
        <div class="demande-ref"><?php echo htmlspecialchars($d['reference']); ?> · <?php echo date('d/m/Y', strtotime($d['created_at'])); ?></div>
      </div>
      <div class="demande-montant">-<?php echo number_format($d['montant'],0,',',' '); ?> F</div>
      <span class="badge <?php echo $badge_class; ?>"><?php echo $statut_label; ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($comptes)): ?>
  <div class="empty-state fadeUp">
    <div class="empty-icon">🚫</div>
    <div class="empty-title">Aucun compte éligible</div>
    <div class="empty-text">Vous n'avez pas de compte autorisant les retraits.</div>
    <a href="espace_membre.php" style="color:var(--gold); text-decoration:none; margin-top:16px; display:inline-block;">← Retour à mon espace</a>
  </div>

  <?php else: ?>

  <form method="POST" id="formRetrait">
    <input type="hidden" name="action" value="demander">
    <input type="hidden" name="compte_id" id="compteIdHidden" value="">
    <input type="hidden" name="canal" id="canalHidden" value="">

    <!-- ÉTAPE 1 : CHOISIR LE COMPTE -->
    <div class="card fadeUp">
      <div class="card-header">
        <span class="card-header-icon">🏦</span>
        <span class="card-header-title">1. Choisir le compte</span>
      </div>
      <div class="card-body">
        <div class="comptes-list">
          <?php foreach ($comptes as $cpt):
            $cc = $compte_colors[$cpt['type_code']] ?? ['emoji'=>'💰','color'=>'#888','bg'=>'#eee'];
            $solde_min = $cpt['type_code'] === 'expresse'
                ? $membre['cotisation_mensuelle'] * 24
                : ($cpt['type_code'] === 'ordinaire' ? 3500 : 0);
            $max_retrait = max(0, $cpt['solde_disponible'] - $solde_min);
            $preselected = $compte_id_pre == $cpt['id'];
          ?>
          <div class="compte-opt <?php echo $preselected ? 'selected' : ''; ?>"
               onclick="selectCompte(<?php echo $cpt['id']; ?>, <?php echo $cpt['solde_disponible']; ?>, <?php echo $solde_min; ?>, <?php echo $max_retrait; ?>, '<?php echo addslashes($cpt['type_libelle']); ?>', '<?php echo $cpt['type_code']; ?>')"
               data-id="<?php echo $cpt['id']; ?>">
            <input type="radio" name="_cpt_radio" <?php echo $preselected ? 'checked' : ''; ?>>
            <div class="compte-icon" style="background:<?php echo $cc['bg']; ?>"><?php echo $cc['emoji']; ?></div>
            <div class="compte-info">
              <div class="compte-nom"><?php echo htmlspecialchars($cpt['type_libelle']); ?></div>
              <div class="compte-num"><?php echo htmlspecialchars($cpt['numero_compte']); ?></div>
              <?php if ($cpt['type_code'] === 'expresse'): ?>
                <div class="compte-solde-min">⚠️ 1 retrait max/mois · Min. 5 000 F</div>
              <?php endif; ?>
            </div>
            <div class="compte-solde">
              <div class="compte-solde-val"><?php echo number_format($cpt['solde_disponible'],0,',',' '); ?> F</div>
              <div class="compte-solde-lbl">Disponible</div>
              <div class="compte-solde-min">Max : <?php echo number_format($max_retrait,0,',',' '); ?> F</div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ÉTAPE 2 : DÉTAILS DU RETRAIT -->
    <div class="card fadeUp">
      <div class="card-header">
        <span class="card-header-icon">📝</span>
        <span class="card-header-title">2. Paramètres du retrait</span>
      </div>
      <div class="card-body">

        <!-- Canal de retrait -->
        <div class="section-label">Mode de récupération</div>
        <div class="canal-grid">
          <div class="canal-btn" onclick="selectCanal('especes', this)">
            <span class="canal-emoji">🏢</span>
            <span class="canal-label">Au bureau</span>
          </div>
          <div class="canal-btn" onclick="selectCanal('virement', this)">
            <span class="canal-emoji">🏦</span>
            <span class="canal-label">Virement</span>
          </div>
          <div class="canal-btn" onclick="selectCanal('mobile_money', this)">
            <span class="canal-emoji">📱</span>
            <span class="canal-label">Mobile Money</span>
          </div>
        </div>
        
        <!-- Numéro de téléphone pour Mobile Money -->
        <div id="telephoneGroup" class="form-group" style="display:none;">
          <label>Numéro de téléphone *</label>
          <input type="tel" name="telephone" id="telephoneInput" placeholder="Ex: 07 XX XX XX XX" maxlength="14">
          <span class="form-hint">Le numéro associé à votre compte Wave/Orange Money</span>
        </div>

        <!-- Montant -->
        <div class="form-group">
          <label>Montant à retirer (F CFA) *</label>
          <input type="number" name="montant" id="montantInput" min="1" step="100"
            placeholder="Ex: 20 000" required oninput="updatePreview()">
          <span class="form-hint" id="montantHint"></span>
        </div>

        <!-- Motif -->
        <div class="form-group">
          <label>Motif <span style="font-weight:400;text-transform:none">— optionnel</span></label>
          <textarea name="motif" placeholder="Ex: Urgence familiale, achat véhicule, etc."></textarea>
        </div>

        <!-- Preview -->
        <div class="preview-box" id="previewBox">
          <div class="preview-row">
            <span class="preview-lbl">Compte</span>
            <strong class="preview-val" id="prvCompte">—</strong>
          </div>
          <div class="preview-row">
            <span class="preview-lbl">Solde disponible</span>
            <strong class="preview-val" id="prvSolde">—</strong>
          </div>
          <div class="preview-row">
            <span class="preview-lbl">Mode de récupération</span>
            <strong class="preview-val" id="prvCanal">—</strong>
          </div>
          <div class="preview-row">
            <span class="preview-lbl">Montant demandé</span>
            <strong class="preview-total" id="prvMontant">—</strong>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="btnSubmit" disabled
          onclick="return confirm('Confirmer la demande de retrait ?')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Soumettre la demande
        </button>

      </div>
    </div>

  </form>
  <?php endif; ?>

</div>

<script>
let selectedCompteId  = <?php echo $compte_id_pre ?: 0; ?>;
let selectedSolde     = 0;
let selectedSoldeMin  = 0;
let selectedMaxRetrait= 0;
let selectedCompteNom = '';
let selectedTypeCode  = '';
let selectedCanal     = '';

// Pré-sélectionner si compte passé en paramètre
<?php if ($compte_id_pre): ?>
<?php foreach ($comptes as $cpt): if ($cpt['id'] == $compte_id_pre): ?>
  const solde_min_pre = <?php echo $cpt['type_code']==='expresse' ? $membre['cotisation_mensuelle']*24 : 3500; ?>;
  selectedSolde      = <?php echo $cpt['solde_disponible']; ?>;
  selectedSoldeMin   = solde_min_pre;
  selectedMaxRetrait = Math.max(0, <?php echo $cpt['solde_disponible']; ?> - solde_min_pre);
  selectedCompteNom  = '<?php echo addslashes($cpt['type_libelle']); ?>';
  selectedTypeCode   = '<?php echo $cpt['type_code']; ?>';
  document.getElementById('compteIdHidden').value = <?php echo $cpt['id']; ?>;
  updateHint();
<?php endif; endforeach; ?>
<?php endif; ?>

function selectCompte(id, solde, soldeMin, maxRetrait, nom, typeCode) {
  selectedCompteId   = id;
  selectedSolde      = solde;
  selectedSoldeMin   = soldeMin;
  selectedMaxRetrait = maxRetrait;
  selectedCompteNom  = nom;
  selectedTypeCode   = typeCode;
  document.getElementById('compteIdHidden').value = id;

  document.querySelectorAll('.compte-opt').forEach(el => {
    el.classList.toggle('selected', el.dataset.id == id);
    el.querySelector('input[type=radio]').checked = el.dataset.id == id;
  });

  updateHint();
  updatePreview();
  checkBtn();
}

function updateHint() {
  let hint = '';
  if (selectedSoldeMin > 0) hint += 'Solde min requis : ' + fmt(selectedSoldeMin) + ' F. ';
  hint += 'Maximum retirable : ' + fmt(selectedMaxRetrait) + ' F CFA.';
  if (selectedTypeCode === 'expresse') hint += ' ⚠️ 1 retrait/mois max, min 5 000 F.';
  const el = document.getElementById('montantHint');
  if (el) el.textContent = hint;
  const inp = document.getElementById('montantInput');
  if (inp) { inp.max = selectedMaxRetrait; inp.min = selectedTypeCode === 'expresse' ? 5000 : 1; }
}

function selectCanal(canal, btn) {
  selectedCanal = canal;
  document.getElementById('canalHidden').value = canal;
  document.querySelectorAll('.canal-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  
  const labels = { especes:'🏢 Au bureau MA2E', virement:'🏦 Virement bancaire', mobile_money:'📱 Mobile Money' };
  document.getElementById('prvCanal').textContent = labels[canal] || canal;
  
  const telephoneGroup = document.getElementById('telephoneGroup');
  if (canal === 'mobile_money') {
    telephoneGroup.style.display = 'block';
    document.getElementById('telephoneInput').required = true;
  } else {
    telephoneGroup.style.display = 'none';
    document.getElementById('telephoneInput').required = false;
  }
  
  updatePreview();
  checkBtn();
}

function updatePreview() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  const box = document.getElementById('previewBox');
  if (!selectedCompteId || !selectedCanal || montant <= 0) { box.style.display = 'none'; return; }
  document.getElementById('prvCompte').textContent = selectedCompteNom;
  document.getElementById('prvSolde').textContent  = fmt(selectedSolde) + ' F';
  document.getElementById('prvMontant').textContent= fmt(montant) + ' F';
  box.style.display = 'block';
  checkBtn();
}

function checkBtn() {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;
  let ok = selectedCompteId && selectedCanal && montant > 0 && montant <= selectedMaxRetrait;
  
  if (selectedCanal === 'mobile_money') {
    const telephone = document.getElementById('telephoneInput').value.trim();
    ok = ok && telephone.length >= 8;
  }
  
  document.getElementById('btnSubmit').disabled = !ok;
}

document.getElementById('telephoneInput')?.addEventListener('input', checkBtn);
document.getElementById('montantInput')?.addEventListener('input', () => { updatePreview(); checkBtn(); });

function fmt(n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' '); }

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
</script>
</body>
</html>