<?php
require_once '../config/database.php';

$success = false;
$error   = '';
$token   = trim($_GET['token'] ?? '');

// ============================================================
// Vérification du token
// ============================================================
if (empty($token)) {
    header('Location: reinitialisation_mdp.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT * FROM password_reset_tokens
    WHERE token = ? AND utilise = 0 AND expiration > NOW()
    LIMIT 1
");
$stmt->execute([$token]);
$resetToken = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resetToken) {
    $error = "Ce lien est invalide ou a expiré. Veuillez faire une nouvelle demande.";
}

// ============================================================
// TRAITEMENT DU NOUVEAU MOT DE PASSE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetToken && empty($error)) {

    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // CORRECTION #3 : validation complexité
    $erreurs_mdp = [];
    if (strlen($password) < 8) {
        $erreurs_mdp[] = "Au moins 8 caractères";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $erreurs_mdp[] = "Au moins une lettre majuscule";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $erreurs_mdp[] = "Au moins un chiffre";
    }
    if ($password !== $confirm) {
        $erreurs_mdp[] = "Les deux mots de passe ne correspondent pas";
    }

    if (!empty($erreurs_mdp)) {
        $error = implode('<br>', array_map(fn($e) => "• $e", $erreurs_mdp));
    } else {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_BCRYPT);

            // CORRECTION #8 : champ 'password' confirmé + mot_de_passe_temporaire = 0
            // (le membre a choisi son mot de passe → plus temporaire)
            $stmt = $pdo->prepare("
                UPDATE utilisateurs
                SET password = ?,
                    mot_de_passe_temporaire = 0
                WHERE email = ?
            ");
            $stmt->execute([$hash, $resetToken['email']]);

            // CORRECTION #11 : supprimer le token après usage (pas juste marquer utilise=1)
            $pdo->prepare("DELETE FROM password_reset_tokens WHERE id = ?")
                ->execute([$resetToken['id']]);

            // Nettoyer aussi les anciens tokens expirés pour cet email
            $pdo->prepare("
                DELETE FROM password_reset_tokens
                WHERE email = ? OR expiration < NOW()
            ")->execute([$resetToken['email']]);

            $pdo->commit();
            $success = true;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la réinitialisation. Veuillez réessayer.";
            error_log("Reset password error: " . $e->getMessage());
        }
    }
}

// Redirection automatique après succès
if ($success === true) {
    header("refresh:3;url=pageconnexion.php");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nouveau mot de passe — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:'DM Sans',sans-serif;
  background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}
.container{max-width:460px;width:100%}

/* CARD */
.card{
  background:white;
  border-radius:20px;
  padding:40px 36px;
  box-shadow:0 25px 60px rgba(0,0,0,.25);
}

/* LOGO */
.logo{text-align:center;margin-bottom:28px}
.logo h1{font-family:'Playfair Display',serif;font-size:30px;color:#C9A84C;letter-spacing:2px;margin-bottom:4px}
.logo p{font-size:12px;color:#999}

/* ICÔNES */
.icon-wrap{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.icon-wrap.lock{background:#FBF5E6}
.icon-wrap.success{background:#E8F5E9}
.icon-wrap.error{background:#FFEBEE}
.icon-wrap svg{width:34px;height:34px}

/* TITRE */
.card-title{font-size:21px;font-weight:700;color:#1a1a1a;text-align:center;margin-bottom:8px}
.card-subtitle{font-size:13.5px;color:#777;text-align:center;margin-bottom:24px;line-height:1.6}

/* ALERTES */
.alert{padding:13px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;line-height:1.7}
.alert-success{background:#E8F5E9;color:#2E7D32;border-left:4px solid #4CAF50}
.alert-error  {background:#FFEBEE;color:#C62828;border-left:4px solid #F44336}

/* FORMULAIRE */
.form-group{margin-bottom:18px}
label{display:block;font-size:12px;font-weight:700;color:#444;margin-bottom:7px;text-transform:uppercase;letter-spacing:.4px}

/* Input avec icône + bouton voir */
.input-wrap{position:relative}
.input-wrap .input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:17px;height:17px;stroke:#aaa;pointer-events:none}
.input-wrap .toggle-eye{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:4px;color:#aaa;transition:color .2s}
.input-wrap .toggle-eye:hover{color:#C9A84C}
.input-wrap .toggle-eye svg{width:17px;height:17px;stroke:currentColor}

input[type=password],input[type=text]{
  width:100%;
  padding:13px 42px 13px 42px;
  border:1.5px solid #e0e0e0;
  border-radius:11px;
  font-size:14px;
  font-family:'DM Sans',sans-serif;
  color:#333;
  transition:border-color .2s,box-shadow .2s;
  background:#fafafa;
}
input[type=password]:focus,input[type=text]:focus{
  outline:none;
  border-color:#C9A84C;
  box-shadow:0 0 0 3px rgba(201,168,76,.15);
  background:#fff;
}

/* CORRECTION #3 : indicateur de force du mot de passe */
.force-wrap{margin-top:8px}
.force-bar{height:5px;background:#eee;border-radius:3px;overflow:hidden;margin-bottom:6px}
.force-fill{height:100%;border-radius:3px;transition:width .3s,background .3s;width:0}
.force-label{font-size:11.5px;color:#888;font-weight:600}
.criteres{margin-top:8px;display:flex;flex-direction:column;gap:4px}
.critere{display:flex;align-items:center;gap:7px;font-size:12px;color:#aaa;transition:color .2s}
.critere.ok{color:#2E7D32}
.critere .dot{width:7px;height:7px;border-radius:50%;background:#ddd;flex-shrink:0;transition:background .2s}
.critere.ok .dot{background:#4CAF50}

/* BOUTON */
.btn{
  width:100%;padding:14px;
  background:#C9A84C;color:white;
  border:none;border-radius:11px;
  font-size:15px;font-weight:700;cursor:pointer;
  font-family:'DM Sans',sans-serif;
  transition:background .2s,transform .1s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:6px;
}
.btn:hover{background:#b8922e}
.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.45;cursor:not-allowed}

/* LIEN */
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:22px;font-size:13px;color:#888;text-decoration:none;transition:color .2s}
.back-link:hover{color:#C9A84C}

/* FOOTER */
.footer{text-align:center;margin-top:20px;font-size:11px;color:rgba(255,255,255,.4)}

@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.card{animation:fadeUp .4s ease both}
</style>
</head>
<body>

<div class="container">
  <div class="card">

    <div class="logo">
      <h1>MA2E</h1>
      <p>Mutuelle des Agents de l'Eau et de l'Électricité</p>
    </div>

    <?php if ($success === true): ?>
    <!-- ══ SUCCÈS ══ -->
    <div class="icon-wrap success">
      <svg viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2">
        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
    </div>
    <div class="card-title">Mot de passe réinitialisé !</div>
    <div class="card-subtitle">
      Votre nouveau mot de passe a été enregistré avec succès.<br>
      Vous allez être redirigé vers la connexion dans quelques secondes…
    </div>
    <a href="pageconnexion.php" class="btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
      Se connecter maintenant
    </a>

    <?php elseif (!empty($error) && !$resetToken): ?>
    <!-- ══ TOKEN INVALIDE / EXPIRÉ ══ -->
    <div class="icon-wrap error">
      <svg viewBox="0 0 24 24" fill="none" stroke="#F44336" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="15" y1="9" x2="9" y2="15"/>
        <line x1="9" y1="9" x2="15" y2="15"/>
      </svg>
    </div>
    <div class="card-title">Lien invalide</div>
    <div class="card-subtitle"><?php echo htmlspecialchars($error); ?></div>
    <a href="reinitialisation_mdp.php" class="btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.85"/></svg>
      Faire une nouvelle demande
    </a>

    <?php else: ?>
    <!-- ══ FORMULAIRE ══ -->
    <div class="icon-wrap lock">
      <svg viewBox="0 0 24 24" fill="none" stroke="#C9A84C" stroke-width="1.8">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
      </svg>
    </div>
    <div class="card-title">Nouveau mot de passe</div>
    <div class="card-subtitle">Créez un mot de passe sécurisé pour votre compte MA2E.</div>

    <?php if (!empty($error)): ?>
    <div class="alert alert-error">
      ⚠️ <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="resetForm" novalidate>

      <!-- Nouveau mot de passe -->
      <div class="form-group">
        <label for="password">Nouveau mot de passe</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
          <input type="password" id="password" name="password"
                 placeholder="Minimum 8 caractères"
                 autocomplete="new-password"
                 oninput="evaluerForce()"
                 required>
          <button type="button" class="toggle-eye" onclick="toggleVoir('password', this)" title="Voir/masquer">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" id="eye-password">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>

        <!-- CORRECTION #3 : indicateur de force -->
        <div class="force-wrap">
          <div class="force-bar"><div class="force-fill" id="forceFill"></div></div>
          <div class="force-label" id="forceLabel">Saisissez un mot de passe</div>
          <div class="criteres">
            <div class="critere" id="crit-length"><span class="dot"></span> Au moins 8 caractères</div>
            <div class="critere" id="crit-upper"> <span class="dot"></span> Au moins une majuscule</div>
            <div class="critere" id="crit-digit"> <span class="dot"></span> Au moins un chiffre</div>
          </div>
        </div>
      </div>

      <!-- Confirmation -->
      <div class="form-group">
        <label for="confirm_password">Confirmer le mot de passe</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
          <input type="password" id="confirm_password" name="confirm_password"
                 placeholder="Répétez le mot de passe"
                 autocomplete="new-password"
                 oninput="evaluerCorrespondance()"
                 required>
          <button type="button" class="toggle-eye" onclick="toggleVoir('confirm_password', this)" title="Voir/masquer">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" id="eye-confirm_password">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div id="matchMsg" style="font-size:12px;margin-top:6px;display:none"></div>
      </div>

      <button type="submit" class="btn" id="btnSubmit" disabled>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Enregistrer le nouveau mot de passe
      </button>

    </form>

    <a href="pageconnexion.php" class="back-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour à la connexion
    </a>

    <?php endif; ?>

  </div>

  <div class="footer">
    &copy; <?php echo date('Y'); ?> MA2E — Tous droits réservés
  </div>
</div>

<script>
// ── Indicateur de force du mot de passe ──
function evaluerForce() {
  const pwd      = document.getElementById('password').value;
  const fill     = document.getElementById('forceFill');
  const label    = document.getElementById('forceLabel');
  const critLen  = document.getElementById('crit-length');
  const critUp   = document.getElementById('crit-upper');
  const critDig  = document.getElementById('crit-digit');

  const okLen  = pwd.length >= 8;
  const okUp   = /[A-Z]/.test(pwd);
  const okDig  = /[0-9]/.test(pwd);

  // Critères visuels
  critLen.classList.toggle('ok', okLen);
  critUp .classList.toggle('ok', okUp);
  critDig.classList.toggle('ok', okDig);

  // Score
  const score = [okLen, okUp, okDig].filter(Boolean).length;

  const config = [
    { pct: '0%',   color: '#eee',    txt: 'Saisissez un mot de passe' },
    { pct: '33%',  color: '#F44336', txt: 'Faible' },
    { pct: '66%',  color: '#FF9800', txt: 'Moyen' },
    { pct: '100%', color: '#4CAF50', txt: 'Fort ✓' },
  ];

  const idx = pwd.length === 0 ? 0 : score;
  fill.style.width      = config[idx].pct;
  fill.style.background = config[idx].color;
  label.textContent     = config[idx].txt;
  label.style.color     = config[idx].color === '#eee' ? '#aaa' : config[idx].color;

  evaluerCorrespondance();
  majBouton();
}

// ── Vérification correspondance ──
function evaluerCorrespondance() {
  const pwd  = document.getElementById('password').value;
  const conf = document.getElementById('confirm_password').value;
  const msg  = document.getElementById('matchMsg');

  if (conf.length === 0) {
    msg.style.display = 'none';
    majBouton(); return;
  }

  if (pwd === conf) {
    msg.style.display = 'block';
    msg.style.color   = '#2E7D32';
    msg.textContent   = '✓ Les mots de passe correspondent';
  } else {
    msg.style.display = 'block';
    msg.style.color   = '#C62828';
    msg.textContent   = '✗ Les mots de passe ne correspondent pas';
  }
  majBouton();
}

// ── Activer le bouton uniquement si tout est valide ──
function majBouton() {
  const pwd  = document.getElementById('password').value;
  const conf = document.getElementById('confirm_password').value;

  const okLen = pwd.length >= 8;
  const okUp  = /[A-Z]/.test(pwd);
  const okDig = /[0-9]/.test(pwd);
  const okMatch = pwd === conf && conf.length > 0;

  document.getElementById('btnSubmit').disabled = !(okLen && okUp && okDig && okMatch);
}

// ── Toggle voir/masquer mot de passe ──
function toggleVoir(fieldId, btn) {
  const input = document.getElementById(fieldId);
  const eye   = document.getElementById('eye-' + fieldId);
  const isHidden = input.type === 'password';

  input.type = isHidden ? 'text' : 'password';

  // Changer l'icône
  eye.innerHTML = isHidden
    ? `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`
    : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
}

// Validation finale avant soumission
document.getElementById('resetForm')?.addEventListener('submit', function(e) {
  const pwd  = document.getElementById('password').value;
  const conf = document.getElementById('confirm_password').value;

  if (pwd.length < 8 || !/[A-Z]/.test(pwd) || !/[0-9]/.test(pwd)) {
    alert('Le mot de passe ne respecte pas les critères de sécurité.');
    e.preventDefault(); return;
  }
  if (pwd !== conf) {
    alert('Les mots de passe ne correspondent pas.');
    e.preventDefault(); return;
  }
});
</script>

</body>
</html>