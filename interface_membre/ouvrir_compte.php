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

// Comptes existants
$comptes_existants = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.membre_id = ? AND ce.statut != 'cloture'
");
$comptes_existants->execute([$membre_id]);
$comptes_existants = $comptes_existants->fetchAll(PDO::FETCH_ASSOC);
$codes_existants = array_column($comptes_existants, 'type_code');

$a_expresse  = in_array('expresse',  $codes_existants);
$a_ordinaire = in_array('ordinaire', $codes_existants);

// ── ACTION : OUVRIR UN COMPTE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ouvrir') {
    $type_code      = $_POST['type_compte']       ?? '';
    $retenue_source = (int)($_POST['retenue_source'] ?? 0);
    
    // Fonction de conversion robuste
    function cleanNumber($value) {
        if (is_null($value) || $value === '') return 0;
        // Supprimer les espaces
        $value = trim($value);
        // Supprimer les espaces insécables et autres caractères
        $value = preg_replace('/\s+/', '', $value);
        // Remplacer la virgule par un point (pour les décimales)
        $value = str_replace(',', '.', $value);
        // Supprimer tout ce qui n'est pas un nombre, un point ou un signe moins
        $value = preg_replace('/[^0-9\.\-]/', '', $value);
        // Convertir en float
        return floatval($value);
    }
    
    $depot_initial  = cleanNumber($_POST['depot_initial'] ?? '0');
    $versement_mens = cleanNumber($_POST['versement_mensuel'] ?? '0');
    $duree_mois     = intval(cleanNumber($_POST['duree_mois'] ?? '0'));

    $type = $pdo->prepare("SELECT * FROM types_compte_epargne WHERE code = ?");
    $type->execute([$type_code]);
    $type = $type->fetch(PDO::FETCH_ASSOC);

    if (!$type) {
        $error = "Type de compte invalide.";
    } elseif (in_array($type_code, $codes_existants)) {
        $error = "Vous avez déjà un compte {$type['libelle']} actif.";
    } else {
        $ok = true;
        switch ($type_code) {
            case 'ordinaire':
                if (!$a_expresse) { 
                    $error = "Vous devez d'abord avoir un compte Expresse."; 
                    $ok = false; 
                }
                break;
            case 'logement':
                if (!$a_expresse || !$a_ordinaire) { 
                    $error = "Vous devez avoir un compte Expresse ET un compte Ordinaire."; 
                    $ok = false; 
                }
                break;
            case 'dats':
                if (!$a_expresse) { 
                    $error = "Vous devez d'abord avoir un compte Expresse."; 
                    $ok = false; 
                } 
                elseif ($depot_initial < 100000) { 
                    $error = "Dépôt minimum pour un DATS : 100 000 F CFA. Vous avez saisi " . number_format($depot_initial, 0, ',', ' ') . " F CFA."; 
                    $ok = false; 
                } 
                elseif ($duree_mois < 6) { 
                    $error = "Durée minimum pour un DATS : 6 mois. Vous avez saisi $duree_mois mois."; 
                    $ok = false; 
                } 
                elseif ($duree_mois > 36) { 
                    $error = "Durée maximum pour un DATS : 36 mois. Vous avez saisi $duree_mois mois."; 
                    $ok = false; 
                }
                $retenue_source = 0; // DATS jamais en retenue
                break;
            case 'datavp':
                if (!$a_expresse) { 
                    $error = "Vous devez d'abord avoir un compte Expresse."; 
                    $ok = false; 
                } 
                elseif ($depot_initial < 10000) { 
                    $error = "Dépôt d'ouverture minimum : 10 000 F CFA. Vous avez saisi " . number_format($depot_initial, 0, ',', ' ') . " F CFA."; 
                    $ok = false; 
                } 
                elseif ($versement_mens < 5000) { 
                    $error = "Versement mensuel minimum : 5 000 F CFA. Vous avez saisi " . number_format($versement_mens, 0, ',', ' ') . " F CFA."; 
                    $ok = false; 
                } 
                elseif ($duree_mois < 24) { 
                    $error = "Durée minimum pour un DATAVP : 24 mois. Vous avez saisi $duree_mois mois."; 
                    $ok = false; 
                } 
                elseif ($duree_mois > 60) { 
                    $error = "Durée maximum pour un DATAVP : 60 mois. Vous avez saisi $duree_mois mois."; 
                    $ok = false; 
                }
                break;
        }
        
        // 🔒 VALIDATION RETENUE À LA SOURCE
        if (in_array($type_code, ['ordinaire','logement','datavp'])) {
            if ($retenue_source == 1) {
                if ($versement_mens <= 0) {
                    $error = "Vous devez définir un montant mensuel pour la retenue à la source.";
                    $ok = false;
                }
                // Règles spécifiques selon type de compte
                if ($type_code === 'ordinaire' && $versement_mens < 3500) {
                    $error = "Le versement mensuel minimum pour un compte Ordinaire est 3 500 F CFA. Vous avez saisi " . number_format($versement_mens, 0, ',', ' ') . " F CFA.";
                    $ok = false;
                }
                if ($type_code === 'logement' && $versement_mens < 1000) {
                    $error = "Le versement mensuel minimum pour un compte Logement est 1 000 F CFA. Vous avez saisi " . number_format($versement_mens, 0, ',', ' ') . " F CFA.";
                    $ok = false;
                }
                if ($type_code === 'datavp' && $versement_mens < 5000) {
                    $error = "Le versement mensuel minimum pour DATAVP est 5 000 F CFA. Vous avez saisi " . number_format($versement_mens, 0, ',', ' ') . " F CFA.";
                    $ok = false;
                }
            } else {
                // Si manuel → on ne garde pas de montant
                $versement_mens = null;
            }
        }
        
        if ($ok) {
            try {
                $pdo->beginTransaction();

                $annee   = date('Y');
                $prefix  = strtoupper(substr($type_code, 0, 3));
                $count   = $pdo->query("SELECT COUNT(*) FROM comptes_epargne")->fetchColumn();
                $num_cpt = $prefix.'-'.$annee.'-'.str_pad($count+1, 5, '0', STR_PAD_LEFT);

                // Calcul taux
                $taux = 3.50;
                if ($type_code === 'dats') {
                    if ($duree_mois <= 12)      $taux = $depot_initial <= 500000 ? 3.50 : 3.65;
                    elseif ($duree_mois <= 24)  $taux = $depot_initial <= 500000 ? 3.65 : 3.80;
                    else                        $taux = $depot_initial <= 500000 ? 3.80 : 4.00;
                } elseif ($type_code === 'datavp') {
                    if ($duree_mois <= 24)      $taux = 3.50;
                    elseif ($duree_mois <= 48)  $taux = 3.70;
                    else                        $taux = 3.90;
                } elseif ($type_code === 'logement') {
                    $taux = 0;
                }

                $date_echeance = null;
                if (in_array($type_code, ['dats', 'datavp'])) {
                    $date_echeance = date('Y-m-d', strtotime("+{$duree_mois} months"));
                }

                $solde_init = in_array($type_code, ['dats', 'datavp']) ? $depot_initial : 0;

                // INSERT avec nb_versements_effectues pour DATAVP
                $pdo->prepare("
                    INSERT INTO comptes_epargne
                        (membre_id, type_compte_id, numero_compte,
                         solde, solde_bloque, solde_disponible,
                         taux_interet, statut, date_ouverture,
                         date_echeance, duree_mois, versement_mensuel,
                         retenue_source, nb_versements_effectues)
                    VALUES (?,?,?,?,0,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $membre_id, $type['id'], $num_cpt,
                    $solde_init, $solde_init,
                    $taux, 'actif', date('Y-m-d'),
                    $date_echeance,
                    $duree_mois ?: null,
                    $versement_mens ?: null,
                    $retenue_source,
                    0  // Initialisation du compteur de versements pour DATAVP
                ]);
                $nouveau_id = $pdo->lastInsertId();

                // Dépôt initial si applicable
                if ($solde_init > 0) {
                    $ref = 'DEP-'.date('YmdHis').'-'.rand(100,999);
                    $pdo->prepare("
                        INSERT INTO mouvements
                            (compte_id, reference, type, montant, solde_avant, solde_apres,
                             date_valeur, date_operation, canal, utilisateur_id, commentaire)
                        VALUES (?,?,'depot',?,0,?,?,?,'especes',?,?)
                    ")->execute([
                        $nouveau_id, $ref, $solde_init, $solde_init,
                        date('Y-m-d'), date('Y-m-d'),
                        $user_id, "Dépôt initial ouverture {$type['libelle']}"
                    ]);
                    
                    // Pour DATAVP, incrémenter le compteur après le dépôt initial
                    if ($type_code === 'datavp') {
                        $pdo->prepare("
                            UPDATE comptes_epargne 
                            SET nb_versements_effectues = nb_versements_effectues + 1 
                            WHERE id = ?
                        ")->execute([$nouveau_id]);
                    }
                }

                $pdo->commit();

                $mode = $retenue_source
                    ? 'Retenue à la source (prélèvement automatique sur salaire)'
                    : 'Versement manuel (espèces, virement, mobile money ou chèque)';

                $success = "Compte <strong>{$type['libelle']}</strong> ouvert avec succès !<br>
                    Numéro : <strong>$num_cpt</strong><br>
                    Mode de versement : $mode<br>
                    <a href='espace_membre.php' style='color:#1B5E20;text-decoration:underline;margin-top:12px;display:inline-block'>← Retour à mon espace</a>";

                // Recharger comptes
                $comptes_existants_stmt = $pdo->prepare("
                    SELECT ce.*, tce.code AS type_code FROM comptes_epargne ce
                    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
                    WHERE ce.membre_id = ? AND ce.statut != 'cloture'
                ");
                $comptes_existants_stmt->execute([$membre_id]);
                $comptes_existants = $comptes_existants_stmt->fetchAll(PDO::FETCH_ASSOC);
                $codes_existants   = array_column($comptes_existants, 'type_code');
                $a_ordinaire       = in_array('ordinaire', $codes_existants);

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erreur : ".$e->getMessage();
            }
        }
    }
}

// Types disponibles à ouvrir
$tous_types = $pdo->query("
    SELECT * FROM types_compte_epargne
    WHERE code NOT IN ('expresse','garantie')
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$types_disponibles = array_filter($tous_types, fn($t) => !in_array($t['code'], $codes_existants));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ouvrir un compte — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}

/* HEADER */
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:18px 24px 24px;display:flex;align-items:center;justify-content:space-between}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.7);text-decoration:none;font-size:14px;font-weight:600;background:rgba(255,255,255,.08);padding:8px 14px;border-radius:20px;transition:all .2s}
.btn-back:hover{background:rgba(255,255,255,.15);color:white}
.header-title{font-family:'Playfair Display',serif;font-size:18px;color:white;letter-spacing:.5px}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;transition:all .2s}
.t-btn.active{background:var(--gold)}

/* CONTENT */
.content{max-width:680px;margin:0 auto;padding:24px 20px}

/* ALERTS */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;animation:fadeUp .3s ease}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}

/* COMPTES EXISTANTS */
.existants{background:var(--card);border-radius:14px;border:1px solid var(--border);padding:16px 20px;margin-bottom:20px}
.existants-title{font-size:13px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.existants-list{display:flex;gap:10px;flex-wrap:wrap}
.existant-badge{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600}

/* TYPES */
.section-label{font-size:13px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.types-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px}
.type-card{border:2px solid var(--border);border-radius:14px;padding:18px;cursor:pointer;transition:all .2s;background:var(--card);position:relative}
.type-card:hover:not(.disabled){border-color:var(--gold);transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.08)}
.type-card.selected{border-color:var(--gold);background:var(--gold-bg)}
.type-card.disabled{opacity:.4;cursor:not-allowed}
.type-emoji{font-size:30px;margin-bottom:10px}
.type-nom{font-size:14px;font-weight:700;color:var(--text);margin-bottom:5px}
.type-desc{font-size:12px;color:var(--text-light);line-height:1.5;margin-bottom:8px}
.type-taux{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700}
.type-requis{font-size:11px;color:#C62828;margin-top:6px}

/* FORMULAIRE */
.form-card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:20px;display:none}
.form-card.visible{display:block;animation:fadeUp .3s ease}
.form-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.form-header-emoji{font-size:20px}
.form-header-title{font-size:14px;font-weight:700;color:var(--text)}
.form-header-sub{font-size:12px;color:var(--text-light)}
.form-body{padding:20px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select{padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{border-color:var(--gold)}
.form-hint{font-size:11.5px;color:var(--text-light);margin-top:2px}
.form-hint.important{color:#F57F17;font-weight:600}

/* CONDITIONS */
.conditions-box{background:var(--bg);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:var(--text-light);line-height:1.8;border-left:3px solid var(--gold)}
.conditions-box strong{color:var(--text)}

/* RETENUE */
.retenue-section{margin-bottom:16px}
.retenue-title{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.retenue-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.retenue-opt{border:2px solid var(--border);border-radius:12px;padding:14px;cursor:pointer;transition:all .2s;text-align:center}
.retenue-opt:hover{border-color:var(--gold)}
.retenue-opt.selected{border-color:var(--gold);background:var(--gold-bg)}
.retenue-opt-emoji{font-size:22px;margin-bottom:6px}
.retenue-opt-titre{font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px}
.retenue-opt-desc{font-size:11px;color:var(--text-light);line-height:1.4}

/* TAUX PREVIEW */
.taux-preview{background:var(--gold-bg);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:700;color:var(--gold-dark);display:none}

/* BOUTON */
.btn-ouvrir{width:100%;padding:14px;background:var(--gold);color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;margin-top:6px}
.btn-ouvrir:hover{background:var(--gold-dark)}
.btn-ouvrir:disabled{opacity:.4;cursor:not-allowed}

/* EMPTY */
.empty-state{text-align:center;padding:40px;background:var(--card);border-radius:16px;border:1px solid var(--border)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .3s ease both}

@media(max-width:480px){.types-grid{grid-template-columns:1fr}.retenue-options{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="header">
  <a href="espace_membre.php" class="btn-back">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Retour
  </a>
  <span class="header-title">Ouvrir un compte</span>
  <div class="t-btns">
    <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
    <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
  </div>
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

  <?php if (!empty($comptes_existants)): ?>
  <!-- COMPTES DÉJÀ OUVERTS -->
  <div class="existants fadeUp">
    <div class="existants-title">Vos comptes actuels</div>
    <div class="existants-list">
      <?php
      $badge_colors = [
        'expresse'  => ['bg'=>'#F5EDD6','color'=>'#92610a','emoji'=>'💰'],
        'ordinaire' => ['bg'=>'#E3F2FD','color'=>'#1565C0','emoji'=>'🏦'],
        'logement'  => ['bg'=>'#E8F5E9','color'=>'#1B5E20','emoji'=>'🏠'],
        'dats'      => ['bg'=>'#F3E5F5','color'=>'#4A148C','emoji'=>'📈'],
        'datavp'    => ['bg'=>'#FFF3E0','color'=>'#E65100','emoji'=>'📊'],
        'garantie'  => ['bg'=>'#FFEBEE','color'=>'#B71C1C','emoji'=>'🔒'],
      ];
      foreach ($comptes_existants as $ce):
        $bc = $badge_colors[$ce['type_code']] ?? ['bg'=>'#eee','color'=>'#555','emoji'=>'💼'];
      ?>
      <span class="existant-badge" style="background:<?php echo $bc['bg']; ?>;color:<?php echo $bc['color']; ?>">
        <?php echo $bc['emoji']; ?> <?php echo htmlspecialchars($ce['type_libelle']); ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($types_disponibles)): ?>
  <div class="empty-state fadeUp">
    <div style="font-size:40px;margin-bottom:12px">✅</div>
    <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px">Tous vos comptes sont ouverts</div>
    <div style="font-size:13px;color:var(--text-light);margin-bottom:16px">Vous avez ouvert tous les types de comptes disponibles.</div>
    <a href="espace_membre.php" style="display:inline-block;padding:10px 20px;background:var(--gold-bg);color:var(--gold);border-radius:10px;font-size:13px;font-weight:600;text-decoration:none">Retour au dashboard</a>
  </div>

  <?php else: ?>

  <form method="POST" id="formOuvrir">
    <input type="hidden" name="action" value="ouvrir">
    <input type="hidden" name="type_compte" id="typeCompteHidden" value="">
    <input type="hidden" name="retenue_source" id="retenueHidden" value="0">

    <!-- ÉTAPE 1 : TYPE DE COMPTE -->
    <div class="section-label fadeUp">Étape 1 — Choisir le type de compte</div>
    <div class="types-grid fadeUp">
      <?php
      $type_config = [
        'ordinaire' => ['emoji'=>'🏦','desc'=>'Épargne libre, retraits à tout moment','taux'=>'3,5%','color'=>'#2196F3','bg'=>'#E3F2FD','requis'=>'Nécessite un compte Expresse'],
        'logement'  => ['emoji'=>'🏠','desc'=>'Pour acquérir un logement MA2E','taux'=>'0%','color'=>'#4CAF50','bg'=>'#E8F5E9','requis'=>'Nécessite Expresse + Ordinaire'],
        'dats'      => ['emoji'=>'📈','desc'=>'Placement bloqué sur durée fixe','taux'=>'3,5% à 4%','color'=>'#9C27B0','bg'=>'#F3E5F5','requis'=>'Nécessite un compte Expresse'],
        'datavp'    => ['emoji'=>'📊','desc'=>'Versements progressifs sur 2 à 5 ans','taux'=>'3,5% à 3,9%','color'=>'#FF9800','bg'=>'#FFF3E0','requis'=>'Nécessite un compte Expresse'],
      ];
      foreach ($types_disponibles as $t):
        $tc = $type_config[$t['code']] ?? ['emoji'=>'💼','desc'=>'','taux'=>'','color'=>'#888','bg'=>'#eee','requis'=>''];
        $accessible = true;
        if ($t['code']==='ordinaire' && !$a_expresse) $accessible = false;
        if ($t['code']==='logement' && (!$a_expresse || !$a_ordinaire)) $accessible = false;
        if (in_array($t['code'],['dats','datavp']) && !$a_expresse) $accessible = false;
      ?>
      <div class="type-card <?php echo !$accessible ? 'disabled' : ''; ?>"
           data-code="<?php echo $t['code']; ?>"
           onclick="<?php echo $accessible ? "selectType('{$t['code']}','{$t['libelle']}')" : ''; ?>">
        <div class="type-emoji"><?php echo $tc['emoji']; ?></div>
        <div class="type-nom"><?php echo htmlspecialchars($t['libelle']); ?></div>
        <div class="type-desc"><?php echo $tc['desc']; ?></div>
        <span class="type-taux" style="background:<?php echo $tc['bg']; ?>;color:<?php echo $tc['color']; ?>">
          📊 <?php echo $tc['taux']; ?> / an
        </span>
        <?php if (!$accessible): ?>
          <div class="type-requis">⚠️ <?php echo $tc['requis']; ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ÉTAPE 2 : CONDITIONS + PARAMÈTRES -->
    <div class="form-card" id="formDetails">
      <div class="form-header">
        <span class="form-header-emoji" id="formEmoji"></span>
        <div>
          <div class="form-header-title" id="formTitre"></div>
          <div class="form-header-sub">Conditions et paramètres</div>
        </div>
      </div>
      <div class="form-body">

        <!-- Conditions -->
        <div class="conditions-box" id="conditionsBox"></div>

        <!-- Champs DATS -->
        <div id="champsDats" style="display:none">
          <div class="form-group">
            <label>Dépôt initial (F CFA) *</label>
            <input type="number" name="depot_initial_dats" id="datsDepot" min="100000" step="1000" placeholder="Min. 100 000 F CFA" oninput="updateTauxDats()">
            <span class="form-hint important">⚠️ Ce montant sera bloqué jusqu'à l'échéance</span>
          </div>
          <div class="form-group">
            <label>Durée (mois) *</label>
            <input type="number" name="duree_mois_dats" id="datsDuree" min="6" max="36" placeholder="Entre 6 et 36 mois" oninput="updateTauxDats()">
            <span class="form-hint">Minimum 6 mois — Maximum 36 mois</span>
          </div>
          <div class="taux-preview" id="tauxDatsPreview"></div>
        </div>

        <!-- Champs DATAVP -->
        <div id="champsDatavp" style="display:none">
          <div class="form-group">
            <label>Dépôt d'ouverture (F CFA) *</label>
            <input type="number" name="depot_initial_datavp" id="datavpDepot" min="10000" step="1000" placeholder="Min. 10 000 F CFA" oninput="updateTauxDatavp()">
            <span class="form-hint">Minimum 10 000 F CFA</span>
          </div>
          <div class="form-group">
            <label>Versement mensuel (F CFA) *</label>
            <input type="number" name="versement_mensuel_datavp" id="datavpVersement" min="5000" step="500" placeholder="Min. 5 000 F CFA" oninput="checkBtn()">
            <span class="form-hint">Ce montant sera versé chaque mois</span>
          </div>
          <div class="form-group">
            <label>Durée (mois) *</label>
            <input type="number" name="duree_mois_datavp" id="datavpDuree" min="24" max="60" placeholder="Entre 24 et 60 mois" oninput="updateTauxDatavp()">
            <span class="form-hint">Entre 2 ans (24 mois) et 5 ans (60 mois)</span>
          </div>
          <div class="taux-preview" id="tauxDatavpPreview"></div>
        </div>

        <!-- ÉTAPE 3 : MODE DE VERSEMENT -->
        <div class="retenue-section" id="retenueSection" style="display:none">
          <div class="retenue-title">Étape 3 — Mode de versement mensuel</div>
          <div class="retenue-options">
            <div class="retenue-opt" onclick="selectRetenue(1, this)">
              <div class="retenue-opt-emoji">🏢</div>
              <div class="retenue-opt-titre">Retenue à la source</div>
              <div class="retenue-opt-desc">Déduit automatiquement sur votre salaire chaque mois par votre société</div>
            </div>
            <div class="retenue-opt" onclick="selectRetenue(0, this)">
              <div class="retenue-opt-emoji">🤝</div>
              <div class="retenue-opt-titre">Versement manuel</div>
              <div class="retenue-opt-desc">Vous venez déposer en espèces ou faites un virement au bureau MA2E</div>
            </div>
          </div>
        </div>
        
        <!-- Champ montant mensuel pour retenue source -->
        <div id="champVersementMensuel" style="display:none">
          <div class="form-group">
            <label>Montant mensuel (F CFA) *</label>
            <input type="number" name="versement_mensuel_retenue" id="versementMensuelInput"
                   min="1000" step="500" placeholder="Ex: 10 000 F CFA" oninput="checkBtn()">
            <span class="form-hint" id="versementHint"></span>
          </div>
        </div>
        
        <button type="submit" class="btn-ouvrir" id="btnOuvrir" disabled>
          Ouvrir le compte
        </button>

      </div>
    </div>

  </form>
  <?php endif; ?>

</div>

<script>
let typeSelectionne  = '';
let retenueChoisie   = null;
let besoinRetenue    = false;

const conditions = {
  ordinaire: {
    texte: `<strong>Conditions :</strong><br>
    ✅ Dépôt minimum : 3 500 F CFA<br>
    ✅ Solde minimum à maintenir : 3 500 F CFA<br>
    ✅ Plafond maximum : 10 000 000 F CFA<br>
    ✅ Retraits libres à tout moment<br>
    ✅ Taux d'intérêt : 3,5% / an`,
    champs: 'aucun', retenue: true, emoji: '🏦', versement_min: 3500
  },
  logement: {
    texte: `<strong>Conditions :</strong><br>
    ✅ Versements libres (mensuel ou ponctuel)<br>
    ❌ Aucun retrait autorisé<br>
    ❌ Pas d'intérêts<br>
    ✅ Se ferme à la livraison du logement<br>
    ✅ Apport utilise pour la demande logement MA2E (minimum selon le type de logement)<br>
    ✅ Versement mensuel minimum : 1 000 F CFA (si retenue source)`,
    champs: 'aucun', retenue: true, emoji: '🏠', versement_min: 1000
  },
  dats: {
    texte: `<strong>Conditions :</strong><br>
    ✅ Dépôt unique minimum : 100 000 F CFA<br>
    ✅ Durée : 6 à 36 mois<br>
    ❌ Aucun versement ni retrait avant l'échéance<br>
    ⚠️ Pénalité 2% si retrait anticipé (sur le capital)<br>
    ✅ Taux : 3,5% à 4% selon montant et durée<br>
    ✅ Intérêts versés à l'échéance sur compte Ordinaire`,
    champs: 'dats', retenue: false, emoji: '📈', versement_min: null
  },
  datavp: {
    texte: `<strong>Conditions :</strong><br>
    ✅ Versements mensuels min. 5 000 F CFA<br>
    ✅ Dépôt d'ouverture min. 10 000 F CFA<br>
    ✅ Durée : 24 à 60 mois<br>
    ⚠️ Pénalité retrait anticipé : 2% avant 2 ans, 1% après 2 ans<br>
    ✅ Taux : 3,5% à 3,9% selon durée<br>
    ✅ Capital + intérêts versés à l'échéance`,
    champs: 'datavp', retenue: true, emoji: '📊', versement_min: 5000
  },
};

function selectType(code, nom) {
  typeSelectionne = code;
  document.getElementById('typeCompteHidden').value = code;

  // Styles des cartes
  document.querySelectorAll('.type-card').forEach(el => {
    el.classList.toggle('selected', el.dataset.code === code);
  });

  const cfg = conditions[code];
  if (!cfg) return;

  // Mettre à jour le formulaire
  document.getElementById('formEmoji').textContent  = cfg.emoji;
  document.getElementById('formTitre').textContent  = nom;
  document.getElementById('conditionsBox').innerHTML = cfg.texte;

  // Masquer tous les champs spécifiques
  document.getElementById('champsDats').style.display   = 'none';
  document.getElementById('champsDatavp').style.display = 'none';
  document.getElementById('champVersementMensuel').style.display = 'none';

  if (cfg.champs === 'dats')   document.getElementById('champsDats').style.display   = 'block';
  if (cfg.champs === 'datavp') document.getElementById('champsDatavp').style.display = 'block';

  // Retenue à la source
  besoinRetenue = cfg.retenue;
  const retenueSection = document.getElementById('retenueSection');
  if (cfg.retenue) {
    retenueSection.style.display = 'block';
    retenueChoisie = null;
    document.getElementById('retenueHidden').value = '0';
    document.querySelectorAll('.retenue-opt').forEach(o => o.classList.remove('selected'));
  } else {
    retenueSection.style.display = 'none';
    retenueChoisie = 0;
    document.getElementById('retenueHidden').value = '0';
  }

  document.getElementById('formDetails').classList.add('visible');
  checkBtn();
  document.getElementById('formDetails').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function selectRetenue(val, el) {
  retenueChoisie = val;
  document.getElementById('retenueHidden').value = val;

  document.querySelectorAll('.retenue-opt').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');

  const champVersement = document.getElementById('champVersementMensuel');
  const versementHint = document.getElementById('versementHint');
  const cfg = conditions[typeSelectionne];
  
  if (val == 1 && cfg && cfg.retenue && typeSelectionne !== 'datavp') {
    champVersement.style.display = 'block';
    const input = document.getElementById('versementMensuelInput');
    if (cfg.versement_min) {
      input.min = cfg.versement_min;
      versementHint.textContent = `Minimum : ${cfg.versement_min.toLocaleString()} F CFA par mois`;
    }
  } else {
    champVersement.style.display = 'none';
    if (document.getElementById('versementMensuelInput')) {
      document.getElementById('versementMensuelInput').value = '';
    }
  }

  checkBtn();
}

function checkBtn() {
  let ok = typeSelectionne && (!besoinRetenue || retenueChoisie !== null);
  const cfg = conditions[typeSelectionne];
  
  if (retenueChoisie == 1 && cfg && cfg.retenue) {
    let val = 0;
    if (typeSelectionne === 'datavp') {
      val = parseFloat(document.getElementById('datavpVersement')?.value) || 0;
    } else {
      val = parseFloat(document.getElementById('versementMensuelInput')?.value) || 0;
    }
    if (!val || val <= 0) ok = false;
    if (cfg.versement_min && val < cfg.versement_min) ok = false;
  }
  
  // Validation des champs DATS
  if (typeSelectionne === 'dats') {
    const depot = parseFloat(document.getElementById('datsDepot')?.value) || 0;
    const duree = parseInt(document.getElementById('datsDuree')?.value) || 0;
    if (depot < 100000 || duree < 6 || duree > 36) ok = false;
  }
  
  // Validation des champs DATAVP
  if (typeSelectionne === 'datavp') {
    const depot = parseFloat(document.getElementById('datavpDepot')?.value) || 0;
    const versement = parseFloat(document.getElementById('datavpVersement')?.value) || 0;
    const duree = parseInt(document.getElementById('datavpDuree')?.value) || 0;
    if (depot < 10000 || versement < 5000 || duree < 24 || duree > 60) ok = false;
    if (retenueChoisie == 1 && versement < 5000) ok = false;
  }
  
  document.getElementById('btnOuvrir').disabled = !ok;
}

function updateTauxDats() {
  const duree   = parseInt(document.getElementById('datsDuree')?.value) || 0;
  const montant = parseFloat(document.getElementById('datsDepot')?.value) || 0;
  const preview = document.getElementById('tauxDatsPreview');

  if (duree < 6 || montant < 100000) { preview.style.display = 'none'; return; }

  let taux;
  if (duree <= 12)      taux = montant <= 500000 ? '3,50%' : '3,65%';
  else if (duree <= 24) taux = montant <= 500000 ? '3,65%' : '3,80%';
  else                  taux = montant <= 500000 ? '3,80%' : '4,00%';

  preview.innerHTML = `📊 Taux applicable : <strong>${taux} / an</strong>`;
  preview.style.display = 'block';
  checkBtn();
}

function updateTauxDatavp() {
  const duree   = parseInt(document.getElementById('datavpDuree')?.value) || 0;
  const preview = document.getElementById('tauxDatavpPreview');

  if (duree < 24 || duree > 60) { preview.style.display = 'none'; return; }

  let taux;
  if (duree <= 24)      taux = '3,50%';
  else if (duree <= 48) taux = '3,70%';
  else                  taux = '3,90%';

  preview.innerHTML = `📊 Taux applicable : <strong>${taux} / an</strong>`;
  preview.style.display = 'block';
  checkBtn();
}

// Gestionnaire d'événement pour la soumission du formulaire
document.getElementById('formOuvrir').addEventListener('submit', function(e) {
    // Supprimer les anciens champs hidden s'ils existent
    const oldHidden = document.querySelectorAll('#formOuvrir input.hidden-field');
    oldHidden.forEach(el => el.remove());
    
    // En fonction du type de compte sélectionné, ajouter les bons champs
    if (typeSelectionne === 'dats') {
        const depotValue = document.getElementById('datsDepot').value;
        const dureeValue = document.getElementById('datsDuree').value;
        
        const hiddenDepot = document.createElement('input');
        hiddenDepot.type = 'hidden';
        hiddenDepot.name = 'depot_initial';
        hiddenDepot.value = depotValue;
        hiddenDepot.className = 'hidden-field';
        this.appendChild(hiddenDepot);
        
        const hiddenDuree = document.createElement('input');
        hiddenDuree.type = 'hidden';
        hiddenDuree.name = 'duree_mois';
        hiddenDuree.value = dureeValue;
        hiddenDuree.className = 'hidden-field';
        this.appendChild(hiddenDuree);
    }
    
    if (typeSelectionne === 'datavp') {
        const depotValue = document.getElementById('datavpDepot').value;
        const versementValue = document.getElementById('datavpVersement').value;
        const dureeValue = document.getElementById('datavpDuree').value;
        
        const hiddenDepot = document.createElement('input');
        hiddenDepot.type = 'hidden';
        hiddenDepot.name = 'depot_initial';
        hiddenDepot.value = depotValue;
        hiddenDepot.className = 'hidden-field';
        this.appendChild(hiddenDepot);
        
        const hiddenVersement = document.createElement('input');
        hiddenVersement.type = 'hidden';
        hiddenVersement.name = 'versement_mensuel';
        hiddenVersement.value = versementValue;
        hiddenVersement.className = 'hidden-field';
        this.appendChild(hiddenVersement);
        
        const hiddenDuree = document.createElement('input');
        hiddenDuree.type = 'hidden';
        hiddenDuree.name = 'duree_mois';
        hiddenDuree.value = dureeValue;
        hiddenDuree.className = 'hidden-field';
        this.appendChild(hiddenDuree);
    }
    
    if ((typeSelectionne === 'ordinaire' || typeSelectionne === 'logement') && retenueChoisie === 1) {
        const versementValue = document.getElementById('versementMensuelInput').value;
        
        const hiddenVersement = document.createElement('input');
        hiddenVersement.type = 'hidden';
        hiddenVersement.name = 'versement_mensuel';
        hiddenVersement.value = versementValue;
        hiddenVersement.className = 'hidden-field';
        this.appendChild(hiddenVersement);
    }
    
    // Confirmation
    return confirm('Confirmer l\'ouverture de ce compte ?');
});

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
