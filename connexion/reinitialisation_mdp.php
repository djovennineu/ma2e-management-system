<?php
require_once '../config/database.php';
require_once '../config/mailer.php';

$success = '';
$error   = '';

// ============================================================
// Créer la table password_reset_tokens si elle n'existe pas
// ============================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email       VARCHAR(150) NOT NULL,
            token       VARCHAR(100) NOT NULL UNIQUE,
            expiration  DATETIME NOT NULL,
            utilise     TINYINT(1) DEFAULT 0,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Table déjà existante — ignoré
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identifiant = trim($_POST['identifiant'] ?? '');

    if (empty($identifiant)) {
        $error = "Veuillez saisir votre email ou matricule.";
    } else {

        // Chercher l'utilisateur par email ou matricule
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.nom, u.prenoms, u.matricule,
                   m.email AS membre_email
            FROM utilisateurs u
            LEFT JOIN membres m ON u.membre_id = m.id
            WHERE u.email = ?
               OR u.matricule = ?
               OR (m.email IS NOT NULL AND m.email = ?)
            LIMIT 1
        ");
        $stmt->execute([$identifiant, $identifiant, $identifiant]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // CORRECTION #6 : même message succès que l'utilisateur existe ou non
        // → on ne confirme pas si l'email/matricule est valide ou non
        if ($user) {
            $email = !empty($user['email']) ? $user['email'] : $user['membre_email'];

            if (!empty($email)) {
                // Générer un token sécurisé
                $token      = bin2hex(random_bytes(32));
                $expiration = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Supprimer les anciens tokens pour cet email
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?")
                    ->execute([$email]);

                // Insérer le nouveau token
                $pdo->prepare("
                    INSERT INTO password_reset_tokens (email, token, expiration)
                    VALUES (?, ?, ?)
                ")->execute([$email, $token, $expiration]);

                // CORRECTION #7 : protocole HTTPS détecté automatiquement
                $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $resetLink = $protocol . "://" . $_SERVER['HTTP_HOST']
                           . dirname($_SERVER['PHP_SELF'])
                           . "/reset_password.php?token=" . $token;

                // Envoi de l'email
                try {
                    $mail = creerMailer();
                    $mail->addAddress($email, trim($user['prenoms'] . ' ' . $user['nom']));
                    $mail->Subject = '🔐 Réinitialisation de votre mot de passe MA2E';
                    $mail->isHTML(true);

                    $nom_complet = htmlspecialchars(trim($user['prenoms'] . ' ' . $user['nom']));

                    $mail->Body = '
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1)">

  <tr><td style="background:linear-gradient(135deg,#1a1a2e 0%,#C9A84C 100%);padding:32px;text-align:center">
    <h1 style="margin:0;color:#ffffff;font-size:26px;letter-spacing:2px;font-family:Georgia,serif">MA2E</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.75);font-size:12px">Mutuelle des Agents de l\'Eau et de l\'Électricité</p>
  </td></tr>

  <tr><td style="padding:32px">
    <p style="margin:0 0 16px;color:#333;font-size:15px;line-height:1.6">
      Bonjour <strong>' . $nom_complet . '</strong>,
    </p>
    <p style="margin:0 0 20px;color:#555;font-size:14px;line-height:1.6">
      Nous avons reçu une demande de réinitialisation du mot de passe associé à votre compte MA2E.
      Si vous êtes à l\'origine de cette demande, cliquez sur le bouton ci-dessous.
    </p>
    <div style="text-align:center;margin:28px 0">
      <a href="' . $resetLink . '"
         style="display:inline-block;background:#C9A84C;color:#ffffff;padding:14px 28px;
                text-decoration:none;border-radius:10px;font-weight:700;font-size:15px;
                letter-spacing:.5px">
        🔑 Réinitialiser mon mot de passe
      </a>
    </div>
    <p style="margin:0 0 10px;color:#888;font-size:12px;text-align:center">
      Ce lien est valable pendant <strong>1 heure</strong>.
    </p>
    <hr style="border:none;border-top:1px solid #eee;margin:20px 0">
    <p style="margin:0;color:#aaa;font-size:11px;text-align:center">
      Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.<br>
      Votre mot de passe restera inchangé.
    </p>
  </td></tr>

  <tr><td style="background:#1a1a1a;padding:18px;text-align:center">
    <p style="margin:0;color:#C9A84C;font-size:11px">
      MA2E — © ' . date('Y') . ' Tous droits réservés
    </p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>';

                    $mail->AltBody = "Bonjour " . trim($user['prenoms'] . ' ' . $user['nom']) . ",\n\n"
                        . "Nous avons reçu une demande de réinitialisation de votre mot de passe MA2E.\n\n"
                        . "Cliquez sur ce lien pour réinitialiser :\n" . $resetLink . "\n\n"
                        . "Ce lien est valable 1 heure.\n\n"
                        . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n\n"
                        . "MA2E";

                    $mail->send();

                } catch (Exception $e) {
                    error_log("Erreur envoi email reset : " . $e->getMessage());
                    // On n'expose pas l'erreur à l'utilisateur
                }
            }
        }

        // CORRECTION #6 : message générique identique
        // qu'un compte soit trouvé ou non → pas de fuite d'information
        $success = "Si un compte correspond à cet identifiant, un email de réinitialisation a été envoyé. "
                 . "Vérifiez votre boîte de réception (et vos spams).";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mot de passe oublié — MA2E</title>
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
.logo h1{
  font-family:'Playfair Display',serif;
  font-size:30px;
  color:#C9A84C;
  letter-spacing:2px;
  margin-bottom:4px;
}
.logo p{font-size:12px;color:#999}

/* ICÔNE */
.icon-wrap{
  width:72px;height:72px;
  background:#FBF5E6;
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;
}
.icon-wrap svg{width:34px;height:34px;stroke:#C9A84C}

/* TITRE */
.card-title{font-size:21px;font-weight:700;color:#1a1a1a;text-align:center;margin-bottom:8px}
.card-subtitle{font-size:13.5px;color:#777;text-align:center;margin-bottom:24px;line-height:1.6}

/* ALERTES */
.alert{
  padding:13px 16px;
  border-radius:10px;
  font-size:13.5px;
  margin-bottom:20px;
  line-height:1.5;
}
.alert-success{background:#E8F5E9;color:#2E7D32;border-left:4px solid #4CAF50}
.alert-error  {background:#FFEBEE;color:#C62828;border-left:4px solid #F44336}

/* FORMULAIRE */
.form-group{margin-bottom:18px}
label{
  display:block;
  font-size:12.5px;
  font-weight:700;
  color:#444;
  margin-bottom:7px;
  text-transform:uppercase;
  letter-spacing:.4px;
}

.input-wrap{position:relative}
.input-wrap svg{
  position:absolute;
  left:14px;top:50%;transform:translateY(-50%);
  width:17px;height:17px;
  stroke:#aaa;pointer-events:none;
}
input[type=text]{
  width:100%;
  padding:13px 14px 13px 42px;
  border:1.5px solid #e0e0e0;
  border-radius:11px;
  font-size:14px;
  font-family:'DM Sans',sans-serif;
  color:#333;
  transition:border-color .2s,box-shadow .2s;
  background:#fafafa;
}
input[type=text]:focus{
  outline:none;
  border-color:#C9A84C;
  box-shadow:0 0 0 3px rgba(201,168,76,.15);
  background:#fff;
}

/* BOUTON */
.btn{
  width:100%;
  padding:14px;
  background:#C9A84C;
  color:white;
  border:none;
  border-radius:11px;
  font-size:15px;
  font-weight:700;
  cursor:pointer;
  font-family:'DM Sans',sans-serif;
  transition:background .2s,transform .1s;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  margin-top:6px;
}
.btn:hover{background:#b8922e}
.btn:active{transform:scale(.98)}

/* LIEN RETOUR */
.back-link{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  margin-top:22px;
  font-size:13px;
  color:#888;
  text-decoration:none;
  transition:color .2s;
}
.back-link:hover{color:#C9A84C}

/* FOOTER */
.footer{
  text-align:center;
  margin-top:20px;
  font-size:11px;
  color:rgba(255,255,255,.4);
}

/* Succès state */
.success-icon{
  width:80px;height:80px;
  background:#E8F5E9;
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;
}
.success-icon svg{width:40px;height:40px;stroke:#4CAF50}

@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.card{animation:fadeUp .4s ease both}
</style>
</head>
<body>

<div class="container">
  <div class="card">

    <?php if ($success): ?>

      <!-- ÉTAT SUCCÈS -->
      <div class="logo"><h1>MA2E</h1><p>Mutuelle des Agents de l'Eau et de l'Électricité</p></div>
      <div class="success-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
          <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
      </div>
      <div class="card-title">Email envoyé !</div>
      <div class="card-subtitle" style="margin-bottom:20px">
        <?php echo htmlspecialchars($success); ?>
      </div>
      <a href="pageconnexion.php" class="btn">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Retour à la connexion
      </a>

    <?php else: ?>

      <!-- FORMULAIRE -->
      <div class="logo"><h1>MA2E</h1><p>Mutuelle des Agents de l'Eau et de l'Électricité</p></div>

      <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
      </div>

      <div class="card-title">Mot de passe oublié ?</div>
      <div class="card-subtitle">
        Entrez votre email ou votre matricule.<br>
        Nous vous enverrons un lien de réinitialisation.
      </div>

      <?php if ($error): ?>
      <div class="alert alert-error">
        ⚠️ <?php echo htmlspecialchars($error); ?>
      </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <div class="form-group">
          <label for="identifiant">Email ou matricule</label>
          <div class="input-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <!-- CORRECTION #8 : placeholder matricule complet -->
            <input
              type="text"
              id="identifiant"
              name="identifiant"
              placeholder="ex: jean.dupont@mail.com ou ADM-001"
              value="<?php echo htmlspecialchars($_POST['identifiant'] ?? ''); ?>"
              required
              autocomplete="username"
            >
          </div>
        </div>

        <button type="submit" class="btn">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          Envoyer le lien de réinitialisation
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

</body>
</html>