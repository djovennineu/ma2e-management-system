<?php
session_start();

// Si déjà connecté, rediriger directement
if (isset($_SESSION['user_id'])) {
    header("Location: " . getRedirectUrl($_SESSION['role']));
    exit;
}

require_once '../config/database.php';

$error_message = "";

// ============================================================
// FONCTION : Redirection selon le rôle
// ============================================================
function getRedirectUrl(string $role): string {
    $routes = [
        'super_admin'             => '../interface_superAdmin/dashboard.php',
        'administrateur'          => '../interface_administrateur/dashboardAdminstrateur.php',
        'comptable'               => '../interface_gestionComptable/dashboard_comptable.php',
        'membre'                  => '../interface_membre/espace_membre.php',
        'gestionnaire_credit'     => '../interface_gestionCredit/dashboard_gestionnaire_credit.php',
        'gestionnaire_immobilier' => '../interface_gestionnaire_immobilier/dashboard_gestionnaire_immo.php',
    ];
    return $routes[$role] ?? 'pageconnexion.php';
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $matricule = strtoupper(trim($_POST['matricule'] ?? ''));
    $password  = $_POST['password'] ?? '';

    if (empty($matricule) || empty($password)) {
        $error_message = "Veuillez remplir tous les champs.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE matricule = ? AND actif = 1 LIMIT 1");
        $stmt->execute([$matricule]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {

            // Rehash automatique si nécessaire
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?")
                    ->execute([$newHash, $user['id']]);
            }

            // Sécurité session
            session_regenerate_id(true);

            // Enregistrement de la session
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['nom']       = $user['nom'];
            $_SESSION['prenoms']   = $user['prenoms'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['membre_id'] = $user['membre_id'];
            $_SESSION['matricule'] = $user['matricule'];

            // Mise à jour dernière connexion
            $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?")
                ->execute([$user['id']]);

            // Redirection selon le rôle
            header("Location: " . getRedirectUrl($user['role']));
            exit;

        } else {
            $error_message = "Matricule ou mot de passe incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MA2E - Connexion</title>
    <link rel="stylesheet" href="pageconnexion.css">
</head>
<body>
    <div class="container">
        <div class="form-container">

            <div class="form-blob">
                <img src="asset/blob.svg" alt="" class="blob-image blob-image--1">
                <img src="asset/blob.svg" alt="" class="blob-image blob-image--2">
                <img src="asset/blob.svg" alt="" class="blob-image blob-image--3">
            </div>

            <div class="form-header">
                <a href="../interface_utilisateur/page_home.php"><img src="asset/logosansnom.png" alt="Logo MA2E" class="form-logo"></a>
                <p>Veuillez entrer vos identifiants</p>
                <h1>Bienvenue sur votre espace membre</h1>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-error" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form class="form-box" method="POST" action="" autocomplete="off">

                <div class="input-group">
                    <input
                        type="text"
                        name="matricule"
                        id="matricule"
                        class="input-field"
                        placeholder=" "
                        required
                        autofocus
                        autocomplete="username"
                        value="<?php echo htmlspecialchars($_POST['matricule'] ?? ''); ?>"
                    >
                    <label for="matricule" class="floating-label">Matricule</label>
                </div>

                <div class="input-group">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="input-field"
                        placeholder=" "
                        required
                        autocomplete="current-password"
                    >
                    <label for="password" class="floating-label">Mot de passe</label>
                    <div class="eye-icon" onclick="togglePassword()" role="button" aria-label="Afficher/masquer le mot de passe" tabindex="0">
                        <svg id="eye-svg" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>
                </div>

                <div class="input-group ckeckbox-group">
                    <div class="form-col remember-me">
                        <input type="checkbox" id="remember-me-checkbox" class="checkbox-field">
                        <label for="remember-me-checkbox">Se souvenir de moi</label>
                    </div>
                    <div class="form-col">
                        <a href="reinitialisation_mdp.php" class="form-link"> mot de passe oublier</a>
                    </div>
                </div>

                <button type="submit" class="form-btn form-btn--submit">Se connecter</button>

            </form>
        </div>
    </div>

    <script>
    function togglePassword() {
        const passwordInput = document.getElementById("password");
        const eyeIcon = document.querySelector(".eye-icon");
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        eyeIcon.innerHTML = isHidden
            ? `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/>
                <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/>
                <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/>
                <path d="m2 2 20 20"/></svg>`
            : `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/>
                <circle cx="12" cy="12" r="3"/></svg>`;
    }

    document.querySelector('.eye-icon').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePassword();
        }
    });
    </script>
</body>
</html>