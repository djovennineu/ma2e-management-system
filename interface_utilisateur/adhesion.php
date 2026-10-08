<?php
session_start();
require_once '../config/database.php';

$errors = [];
$success = false;

// ============================================================
// TRAITEMENT PHP — Soumission du formulaire
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'soumettre') {

    $nom                    = trim($_POST['nom'] ?? '');
    $prenoms                = trim($_POST['prenom'] ?? '');
    $sexe                   = ($_POST['civilite'] ?? '') === 'M' ? 'homme' : (($_POST['civilite'] ?? '') === 'Mme' ? 'femme' : '');
    $date_naissance         = $_POST['dateNaissance'] ?? null;
    $email                  = trim($_POST['email'] ?? '');
    $telephone              = trim($_POST['tel'] ?? '');
    $adresse                = trim($_POST['adresse'] ?? '');
    $ville                  = trim($_POST['ville'] ?? '');
    $situation_matrimoniale = $_POST['situation'] ?? '';
    $nombre_enfants         = (int)($_POST['nombre_enfants'] ?? 0);
    $societe_code           = trim($_POST['employeur'] ?? '');
    $matricule_pro          = trim($_POST['matricule'] ?? '');

    $situation_map = [
        'Célibataire' => 'celibataire',
        'Marié(e)'    => 'marie',
        'Divorcé(e)'  => 'divorce',
        'Veuf/Veuve'  => 'veuf',
    ];
    $situation_matrimoniale = $situation_map[$situation_matrimoniale] ?? '';

    // Validations
    if (empty($_POST['civilite'] ?? ''))  $errors[] = "La civilité est obligatoire.";
    if (empty($nom))                      $errors[] = "Le nom est obligatoire.";
    if (empty($prenoms))                  $errors[] = "Le prénom est obligatoire.";
    if (empty($date_naissance))           $errors[] = "La date de naissance est obligatoire.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide.";
    if (empty($telephone))                $errors[] = "Le téléphone est obligatoire.";
    if (empty($adresse))                  $errors[] = "L'adresse est obligatoire.";
    if (empty($ville))                    $errors[] = "La ville est obligatoire.";
    if (empty($societe_code))             $errors[] = "L'employeur est obligatoire.";
    if (empty($matricule_pro))            $errors[] = "Le matricule employé est obligatoire.";

    // Unicité
    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT id FROM candidats WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) $errors[] = "Cette adresse email a déjà été utilisée pour une demande.";

        $chk2 = $pdo->prepare("SELECT id FROM candidats WHERE telephone = ?");
        $chk2->execute([$telephone]);
        if ($chk2->fetch()) $errors[] = "Ce numéro de téléphone a déjà été utilisé pour une demande.";

        $chk3 = $pdo->prepare("SELECT id FROM candidats WHERE matricule_pro = ?");
        $chk3->execute([$matricule_pro]);
        if ($chk3->fetch()) $errors[] = "Ce matricule employé a déjà été utilisé pour une demande.";

        $chk4 = $pdo->prepare("SELECT id FROM membres WHERE email = ? OR telephone = ? OR matricule_pro = ?");
        $chk4->execute([$email, $telephone, $matricule_pro]);
        if ($chk4->fetch()) $errors[] = "Ces informations correspondent à un membre déjà inscrit à la MA2E.";
    }

    // Société
    $societe_id = null;
    if (empty($errors)) {
        $s = $pdo->prepare("SELECT id FROM societes WHERE code = ?");
        $s->execute([$societe_code]);
        $soc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$soc) {
            $errors[] = "Société introuvable.";
        } else {
            $societe_id = $soc['id'];
        }
    }

    // Upload
    $upload_dir = '../uploads/candidats/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $piece_identite_path      = null;
    $bulletin_salaire_path    = null;
    $photo1_path              = null;
    $formulaire_adhesion_path = null;

    $allowed_docs   = ['application/pdf', 'image/jpeg', 'image/png'];
    $allowed_photos = ['image/jpeg', 'image/png'];
    $max_doc        = 5 * 1024 * 1024;
    $max_photo      = 2 * 1024 * 1024;

    function uploadFichier($file_key, $allowed, $max_size, $upload_dir, &$errors, $label) {
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) return null;
        $file = $_FILES[$file_key];
        if ($file['error'] !== UPLOAD_ERR_OK) { $errors[] = "Erreur lors de l'upload de $label."; return null; }
        if ($file['size'] > $max_size) { $errors[] = "$label dépasse la taille maximale autorisée."; return null; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) { $errors[] = "Format de $label non autorisé."; return null; }
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('doc_', true) . '.' . strtolower($ext);
        $dest     = $upload_dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) { $errors[] = "Impossible de sauvegarder $label."; return null; }
        return $dest;
    }

    if (empty($errors)) {
        $piece_identite_path      = uploadFichier('piece_identite',    $allowed_docs,   $max_doc,   $upload_dir, $errors, "la pièce d'identité");
        $bulletin_salaire_path    = uploadFichier('bulletin_salaire',  $allowed_docs,   $max_doc,   $upload_dir, $errors, "le bulletin de salaire");
        $photo1_path              = uploadFichier('photo_identite',    $allowed_photos, $max_photo, $upload_dir, $errors, "la photo d'identité");
        $formulaire_adhesion_path = uploadFichier('formulaire_adhesion',$allowed_docs,  $max_doc,   $upload_dir, $errors, "le formulaire d'adhésion");
    }

    // Insertion
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO candidats
                (nom, prenoms, matricule_pro, sexe, situation_matrimoniale,
                 email, telephone, adresse, ville, pays,
                 nombre_enfants, date_naissance, societe_id,
                 piece_identite_path, bulletin_salaire_path, photo1_path,
                 formulaire_adhesion_path, statut, date_soumission)
            VALUES (?,?,?,?,?,?,?,?,?,'Côte d\'Ivoire',?,?,?,?,?,?,?,'en_attente',NOW())
        ");
        $stmt->execute([
            $nom, $prenoms, $matricule_pro, $sexe, $situation_matrimoniale,
            $email, $telephone, $adresse, $ville,
            $nombre_enfants, $date_naissance ?: null, $societe_id,
            $piece_identite_path, $bulletin_salaire_path, $photo1_path,
            $formulaire_adhesion_path,
        ]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MA2E — Adhésion</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
    :root {
        --green:#90F876;--green-dark:#6cd452;--green-glow:rgba(144,248,118,0.15);
        --black:#0c0c0c;--dark:#141414;--dark-2:#1e1e1e;--white:#ffffff;
        --grey-bg:#f6f6f4;--grey-light:#eeeeea;--grey-text:#666666;
        --orange:#F3A93B;--red:#e74c3c;
        --radius-sm:8px;--radius-md:14px;--radius-lg:22px;--radius-xl:32px;
        --shadow-sm:0 2px 12px rgba(0,0,0,0.06);--shadow-md:0 8px 32px rgba(0,0,0,0.10);--shadow-lg:0 20px 60px rgba(0,0,0,0.14);
        --ease-out:cubic-bezier(0.4,0,0.2,1);--ease-spring:cubic-bezier(0.175,0.885,0.32,1.275);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{font-family:'DM Sans',sans-serif;background:var(--grey-bg);color:var(--black);overflow-x:hidden;padding-top:80px;line-height:1.6}

    @keyframes fadeUp{from{opacity:0;transform:translateY(36px)}to{opacity:1;transform:translateY(0)}}
    @keyframes fadeLeft{from{opacity:0;transform:translateX(-40px)}to{opacity:1;transform:translateX(0)}}
    @keyframes scaleIn{from{opacity:0;transform:scale(0.88)}to{opacity:1;transform:scale(1)}}
    @keyframes checkPop{0%{opacity:0;transform:scale(0) rotate(-20deg)}70%{transform:scale(1.15) rotate(5deg)}100%{opacity:1;transform:scale(1) rotate(0deg)}}
    @keyframes shimmer{0%{background-position:-200% center}100%{background-position:200% center}}
    @keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(144,248,118,0.4)}50%{box-shadow:0 0 0 12px rgba(144,248,118,0)}}

    .anim-hidden{opacity:0;transform:translateY(40px);transition:opacity .7s var(--ease-out),transform .7s var(--ease-out)}
    .anim-hidden.from-left{transform:translateX(-45px)}
    .anim-hidden.from-right{transform:translateX(45px)}
    .anim-hidden.from-scale{transform:scale(0.88)}
    .anim-hidden.is-visible{opacity:1;transform:translate(0) scale(1)}
    .anim-stagger .anim-hidden:nth-child(1){transition-delay:.05s}
    .anim-stagger .anim-hidden:nth-child(2){transition-delay:.12s}
    .anim-stagger .anim-hidden:nth-child(3){transition-delay:.19s}
    .anim-stagger .anim-hidden:nth-child(4){transition-delay:.26s}

    /* NAVBAR */
    .navbar{position:fixed;top:0;left:0;width:100%;height:80px;display:flex;align-items:center;justify-content:space-between;padding:0 5%;background:rgba(10,10,10,0.9);backdrop-filter:blur(14px);border-bottom:1px solid rgba(144,248,118,0.15);z-index:2000;transition:height .35s var(--ease-out),box-shadow .35s ease}
    .navbar.scrolled{height:65px;box-shadow:0 4px 24px rgba(0,0,0,0.4);border-bottom-color:rgba(144,248,118,0.25)}
    .navbar-brand img{width:130px;height:auto;display:block;filter:brightness(1.1)}
    .navbar-menu{position:absolute;top:calc(100% - 4px);left:50%;transform:translateX(-50%);padding:0 18px;background:rgba(12,12,12,0.97);border-radius:50px;border:1px solid rgba(144,248,118,0.12);box-shadow:0 12px 48px rgba(0,0,0,0.4);backdrop-filter:blur(14px);z-index:1000}
    .arii-menu{display:flex;list-style:none;align-items:center}
    .arii-menu>li>a{display:block;padding:16px 15px;color:rgba(255,255,255,0.85);text-decoration:none;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;transition:color .25s ease;position:relative}
    .arii-menu>li>a::after{content:'';position:absolute;bottom:10px;left:50%;width:calc(100% - 20px);height:2px;background:var(--green);border-radius:2px;transform:translateX(-50%) scaleX(0);transform-origin:center;transition:transform .3s var(--ease-spring)}
    .arii-menu>li>a:hover{color:var(--green)}
    .arii-menu>li>a:hover::after{transform:translateX(-50%) scaleX(1)}
    .submenu{position:absolute;top:calc(100% + 8px);left:0;min-width:210px;list-style:none;padding:8px 0;z-index:1001;background:#fff;border-radius:var(--radius-md);border:1px solid rgba(0,0,0,0.06);box-shadow:var(--shadow-lg);opacity:0;visibility:hidden;transform:translateY(8px);transition:opacity .25s ease,transform .25s ease,visibility .25s}
    .submenu a{display:block;padding:10px 20px;color:#333;text-decoration:none;font-size:13px;font-weight:500;transition:background .2s ease,color .2s ease,padding-left .2s ease}
    .submenu a:hover{background:#f5fdf2;color:var(--green-dark);padding-left:26px}
    @media(min-width:993px){.arii-menu li:hover>.submenu{opacity:1;visibility:visible;transform:translateY(0)}.submenu .has-submenu:hover>.submenu{top:0;left:100%}}
    .btn-espace-membre{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;background:var(--green);color:#000;border:2px solid transparent;border-radius:var(--radius-sm);text-decoration:none;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;flex-shrink:0;transition:background .3s ease,color .3s ease,border-color .3s ease,transform .3s var(--ease-spring),box-shadow .3s ease}
    .btn-espace-membre:hover{background:transparent;color:var(--green);border-color:var(--green);transform:translateY(-2px);box-shadow:0 8px 20px rgba(144,248,118,0.2)}
    .menu-toggle{display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:6px;border-radius:8px;z-index:1100}
    .menu-toggle span{display:block;width:22px;height:2px;background:#fff;border-radius:2px;transition:transform .35s var(--ease-spring),opacity .25s ease,background .25s ease}
    .menu-toggle.active span:nth-child(1){transform:translateY(7px) rotate(45deg);background:var(--green)}
    .menu-toggle.active span:nth-child(2){opacity:0}
    .menu-toggle.active span:nth-child(3){transform:translateY(-7px) rotate(-45deg);background:var(--green)}
    .menu-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);backdrop-filter:blur(0px);z-index:999;pointer-events:none;transition:background .4s ease,backdrop-filter .4s ease}
    .menu-overlay.active{background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);pointer-events:auto}
    @media(max-width:992px){
        .menu-toggle{display:flex}
        .navbar-brand{order:1}.btn-espace-membre{order:2;margin:0 auto;padding:8px 12px;font-size:10px}.menu-toggle{order:3}
        .navbar-menu{position:fixed;top:0;left:-100%;transform:none;width:min(290px,80vw);height:100vh;padding-top:80px;margin-top:0;border-radius:0;overflow-y:auto;display:block;transition:left .42s var(--ease-out),box-shadow .4s ease}
        .navbar-menu.active{left:0;box-shadow:8px 0 40px rgba(0,0,0,0.5)}
        .arii-menu{flex-direction:column;align-items:stretch;padding:12px 0 40px}
        .arii-menu>li{border-bottom:1px solid rgba(255,255,255,0.05)}
        .arii-menu>li>a{padding:16px 24px;font-size:13px;color:rgba(255,255,255,0.8)}
        .arii-menu>li>a::after{display:none}
        .submenu{position:static;display:none;width:100%;background:rgba(255,255,255,0.04);box-shadow:none;border-radius:0;padding-left:20px;opacity:1;visibility:visible;transform:none;border:none}
        .arii-menu li.active>.submenu{display:block}
        .submenu a{color:#ccc;padding:12px 20px}
        .submenu a:hover{background:rgba(144,248,118,0.08);color:var(--green);padding-left:28px}
    }

    /* HERO */
    .adhesion-hero{background:linear-gradient(135deg,var(--dark) 0%,#0f2818 55%,#0d1a0d 100%);padding:80px 5% 100px;position:relative;overflow:hidden}
    .adhesion-hero::before{content:'';position:absolute;top:-120px;right:-80px;width:500px;height:500px;background:radial-gradient(circle,rgba(144,248,118,0.08) 0%,transparent 65%);border-radius:50%;pointer-events:none}
    .adhesion-hero::after{content:'';position:absolute;bottom:-80px;left:-60px;width:360px;height:360px;background:radial-gradient(circle,rgba(144,248,118,0.05) 0%,transparent 65%);border-radius:50%;pointer-events:none}
    .hero-dots{position:absolute;inset:0;background-image:radial-gradient(rgba(144,248,118,0.07) 1px,transparent 1px);background-size:32px 32px;pointer-events:none}
    .adhesion-hero__inner{position:relative;z-index:1;max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
    .adhesion-hero__badge{display:inline-flex;align-items:center;gap:8px;background:rgba(144,248,118,0.1);border:1px solid rgba(144,248,118,0.25);border-radius:50px;padding:5px 16px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--green);margin-bottom:22px;animation:fadeLeft .7s var(--ease-out) .1s both}
    .adhesion-hero__title{font-family:'Syne',sans-serif;font-size:clamp(2.2rem,4.5vw,3.6rem);font-weight:800;color:var(--white);line-height:1.1;letter-spacing:-1.5px;margin-bottom:20px;animation:fadeLeft .7s var(--ease-out) .2s both}
    .adhesion-hero__title em{font-style:normal;background:linear-gradient(90deg,var(--green),#c8ff9e,var(--green));background-size:200% auto;-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;animation:shimmer 3s linear 1s infinite}
    .adhesion-hero__sub{font-size:1.05rem;color:rgba(255,255,255,0.65);max-width:440px;line-height:1.75;margin-bottom:36px;animation:fadeLeft .7s var(--ease-out) .3s both}
    .hero-perks{display:flex;flex-direction:column;gap:12px;animation:fadeLeft .7s var(--ease-out) .4s both}
    .hero-perk{display:flex;align-items:center;gap:12px;font-size:14px;color:rgba(255,255,255,0.8)}
    .hero-perk__icon{width:32px;height:32px;border-radius:8px;background:rgba(144,248,118,0.12);border:1px solid rgba(144,248,118,0.2);display:flex;align-items:center;justify-content:center;color:var(--green);font-size:.95rem;flex-shrink:0}
    .adhesion-hero__card{position:relative;overflow:hidden;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:var(--radius-xl);padding:40px 36px;backdrop-filter:blur(16px);animation:scaleIn .8s var(--ease-spring) .3s both}
    .adhesion-hero__card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--green),transparent)}
    .card-title{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:var(--white);margin-bottom:6px}
    .card-sub{font-size:13px;color:rgba(255,255,255,0.5);margin-bottom:28px}
    .card-steps{display:flex;flex-direction:column;gap:14px;margin-bottom:32px}
    .card-step{display:flex;align-items:center;gap:14px}
    .step-num{width:34px;height:34px;border-radius:50%;background:rgba(144,248,118,0.12);border:1px solid rgba(144,248,118,0.3);font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--green);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .step-text{font-size:13.5px;color:rgba(255,255,255,0.75);line-height:1.4}
    .step-text strong{color:var(--white)}
    .card-cta{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:16px 20px;background:var(--green);color:var(--black);font-family:'Syne',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;border-radius:50px;text-decoration:none;transition:transform .3s var(--ease-spring),box-shadow .3s;animation:pulse 3s ease-in-out 2s infinite}
    .card-cta:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 16px 40px rgba(144,248,118,0.3);animation:none}

    /* BARRE PROGRESSION */
    .steps-progress{position:sticky;top:80px;z-index:100;background:var(--white);padding:32px 5%;border-bottom:1px solid rgba(0,0,0,0.06);box-shadow:var(--shadow-sm)}
    .steps-progress__inner{position:relative;max-width:900px;margin:0 auto;display:flex;align-items:center}
    .steps-progress__inner::before{content:'';position:absolute;top:20px;left:0;right:0;height:2px;background:var(--grey-light);z-index:0}
    .steps-progress__line{position:absolute;top:20px;left:0;height:2px;background:linear-gradient(90deg,var(--green),var(--green-dark));border-radius:2px;transition:width .5s var(--ease-out);z-index:1}
    .step-item{flex:1;position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer}
    .step-circle{width:42px;height:42px;border-radius:50%;background:var(--grey-light);border:2px solid var(--grey-light);display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--grey-text);transition:all .4s var(--ease-spring)}
    .step-item.active .step-circle{background:var(--green);border-color:var(--green);color:var(--black);box-shadow:0 0 0 6px rgba(144,248,118,0.2);transform:scale(1.1)}
    .step-item.done .step-circle{background:var(--black);border-color:var(--black);color:var(--green)}
    .step-item.done .step-circle::after{content:'\F26B';font-family:'bootstrap-icons';font-size:16px}
    .step-item.done .step-num-text{display:none}
    .step-label{font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--grey-text);text-align:center;transition:color .3s}
    .step-item.active .step-label{color:var(--black)}
    .step-item.done .step-label{color:var(--green-dark)}
    @media(max-width:640px){.step-label{display:none}.steps-progress__inner::before{top:18px}.steps-progress__line{top:18px}.step-circle{width:36px;height:36px;font-size:13px}}

    /* FORMULAIRE */
    .form-section{padding:60px 5% 80px;max-width:960px;margin:0 auto}
    .form-panel{background:var(--white);border-radius:var(--radius-xl);border:1px solid rgba(0,0,0,0.05);box-shadow:var(--shadow-md);overflow:hidden}
    .form-panel__header{display:flex;align-items:center;gap:16px;padding:32px 40px 24px;border-bottom:1px solid rgba(0,0,0,0.06)}
    .form-panel__icon{width:52px;height:52px;border-radius:var(--radius-md);background:rgba(144,248,118,0.1);border:1px solid rgba(144,248,118,0.25);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--green-dark);flex-shrink:0}
    .form-panel__title{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;color:var(--black)}
    .form-panel__subtitle{font-size:13.5px;color:var(--grey-text);margin-top:2px}
    .form-panel__body{padding:36px 40px 40px}
    .form-step{display:none}
    .form-step.active{display:block;animation:fadeUp .5s var(--ease-out) both}
    .fields-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px 24px}
    .fields-grid.one-col{grid-template-columns:1fr}
    .field-full{grid-column:1/-1}
    .field{display:flex;flex-direction:column;gap:7px}
    .field label{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--black)}
    .field label .req{color:var(--green-dark);margin-left:2px}
    .field input,.field select,.field textarea{width:100%;padding:13px 16px;background:#fafaf8;border:1.5px solid rgba(0,0,0,0.1);border-radius:var(--radius-md);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--black);outline:none;-webkit-appearance:none;transition:border-color .25s,box-shadow .25s,background .25s}
    .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--green-dark);box-shadow:0 0 0 4px rgba(144,248,118,0.12);background:var(--white)}
    .field input.error{border-color:var(--red);box-shadow:0 0 0 4px rgba(231,76,60,0.08)}
    .field select.error{border-color:var(--red)}
    .field-error{display:none;font-size:12px;color:var(--red);margin-top:-4px}
    .field input.error+.field-error{display:block}
    .field select.error+.field-error{display:block}
    .field textarea{resize:vertical;min-height:110px}
    .choice-group{display:flex;flex-wrap:wrap;gap:10px}
    .choice-btn{display:flex;align-items:center;gap:8px;padding:10px 18px;background:#fafaf8;border:1.5px solid rgba(0,0,0,0.1);border-radius:50px;cursor:pointer;font-size:13px;font-weight:500;color:var(--black);user-select:none;transition:all .25s var(--ease-spring)}
    .choice-btn input{display:none}
    .choice-btn:hover{border-color:var(--green-dark);background:rgba(144,248,118,0.06)}
    .choice-btn.selected{background:rgba(144,248,118,0.12);border-color:var(--green-dark);font-weight:700}
    .choice-btn.selected::before{content:'\F26B';font-family:'bootstrap-icons';color:var(--green-dark);font-size:.85rem}

    /* UPLOAD */
    .file-upload{position:relative;overflow:hidden;border:2px dashed rgba(0,0,0,0.12);border-radius:var(--radius-lg);padding:32px 20px;text-align:center;cursor:pointer;transition:border-color .25s,background .25s}
    .file-upload:hover,.file-upload.dragover{border-color:var(--green-dark);background:rgba(144,248,118,0.04)}
    .file-upload input{position:absolute;inset:0;opacity:0;cursor:pointer}
    .file-upload__icon{display:block;font-size:2.5rem;color:var(--grey-text);margin-bottom:10px}
    .file-upload__text{font-size:14px;color:var(--grey-text);line-height:1.5}
    .file-upload__text strong{color:var(--black)}
    .file-upload__hint{font-size:12px;color:#aaa;margin-top:4px}
    .file-preview{display:flex;flex-direction:column;gap:8px;margin-top:14px}
    .file-item{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:#f5f5f2;border-radius:var(--radius-sm);font-size:13px}
    .file-item__name{display:flex;align-items:center;gap:8px;color:var(--black)}
    .file-item__name i{color:var(--green-dark)}
    .file-item__remove{background:none;border:none;cursor:pointer;color:var(--red);font-size:1rem}

    /* RECAP */
    .recap-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .recap-block{background:#fafaf8;border-radius:var(--radius-lg);border:1px solid rgba(0,0,0,0.05);padding:22px 24px}
    .recap-block__title{display:flex;align-items:center;gap:8px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--grey-text);margin-bottom:14px}
    .recap-block__title i{color:var(--green-dark);font-size:1rem}
    .recap-row{display:flex;justify-content:space-between;align-items:baseline;font-size:13.5px;padding:5px 0;border-bottom:1px solid rgba(0,0,0,0.04)}
    .recap-row:last-child{border-bottom:none}
    .recap-row span:first-child{color:var(--grey-text)}
    .recap-row span:last-child{font-weight:600;color:var(--black)}
    .checkbox-field{display:flex;align-items:flex-start;gap:12px;padding:16px 20px;background:#fafaf8;border:1.5px solid rgba(0,0,0,0.08);border-radius:var(--radius-md);cursor:pointer;transition:border-color .25s}
    .checkbox-field:hover{border-color:var(--green-dark)}
    .checkbox-field input[type="checkbox"]{display:none}
    .checkbox-custom{width:22px;height:22px;border-radius:6px;border:2px solid rgba(0,0,0,0.15);background:var(--white);flex-shrink:0;margin-top:1px;display:flex;align-items:center;justify-content:center;transition:all .25s var(--ease-spring)}
    .checkbox-field.checked .checkbox-custom{background:var(--black);border-color:var(--black)}
    .checkbox-field.checked .checkbox-custom::after{content:'\F26B';font-family:'bootstrap-icons';color:var(--green);font-size:.85rem;animation:checkPop .35s var(--ease-spring) both}
    .checkbox-text{font-size:13.5px;color:var(--black);line-height:1.55}
    .checkbox-text a{color:var(--green-dark);text-decoration:none;font-weight:600}
    .checkbox-text a:hover{text-decoration:underline}

    /* NAVIGATION BOUTONS */
    .form-nav{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:36px;padding-top:28px;border-top:1px solid rgba(0,0,0,0.06)}
    .btn-prev{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;background:transparent;border:1.5px solid rgba(0,0,0,0.12);border-radius:50px;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--grey-text);cursor:pointer;transition:all .25s ease}
    .btn-prev:hover{border-color:var(--black);color:var(--black);transform:translateX(-2px)}
    .btn-next{display:inline-flex;align-items:center;gap:10px;padding:14px 32px;background:var(--black);border:none;border-radius:50px;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--green);cursor:pointer;margin-left:auto;transition:all .3s var(--ease-spring)}
    .btn-next:hover{background:var(--green);color:var(--black);transform:translateY(-2px);box-shadow:0 12px 28px rgba(144,248,118,0.2)}
    .btn-submit{display:inline-flex;align-items:center;gap:10px;padding:15px 36px;background:var(--green);border:none;border-radius:50px;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--black);cursor:pointer;margin-left:auto;transition:all .3s var(--ease-spring)}
    .btn-submit:hover{transform:translateY(-3px) scale(1.02);box-shadow:0 16px 40px rgba(144,248,118,0.3)}

    /* SUCCÈS */
    .success-screen{display:none;text-align:center;padding:80px 40px}
    .success-screen.show{display:block;animation:fadeUp .6s var(--ease-out) both}
    .success-icon{width:90px;height:90px;border-radius:50%;background:rgba(144,248,118,0.12);border:2px solid rgba(144,248,118,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 28px;animation:checkPop .7s var(--ease-spring) .2s both}
    .success-icon i{font-size:2.8rem;color:var(--green-dark)}
    .success-title{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--black);margin-bottom:12px}
    .success-sub{font-size:1rem;color:var(--grey-text);max-width:420px;margin:0 auto 36px;line-height:1.7}
    .success-actions{display:flex;justify-content:center;gap:14px;flex-wrap:wrap}
    .btn-home{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;background:var(--black);color:var(--green);border-radius:50px;text-decoration:none;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;transition:all .3s var(--ease-spring)}
    .btn-home:hover{background:var(--green);color:var(--black);transform:translateY(-2px)}

    /* AVANTAGES */
    .avantages-section{position:relative;overflow:hidden;padding:80px 5%;background:var(--dark)}
    .avantages-section::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,rgba(144,248,118,0.3),transparent)}
    .avantages-section__inner{max-width:1100px;margin:0 auto}
    .section-label{display:inline-flex;align-items:center;gap:8px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2.5px;color:var(--green);margin-bottom:14px}
    .section-label::before{content:'';width:24px;height:2px;background:var(--green);border-radius:2px}
    .section-title{font-family:'Syne',sans-serif;font-size:clamp(1.8rem,3.5vw,2.8rem);font-weight:800;color:var(--white);line-height:1.15;letter-spacing:-1px;margin-bottom:12px}
    .section-sub{font-size:1rem;color:rgba(255,255,255,0.55);max-width:500px;margin-bottom:52px}
    .avantages-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
    .avantage-card{position:relative;overflow:hidden;padding:30px 26px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:var(--radius-lg);transition:transform .4s var(--ease-spring),border-color .3s,box-shadow .4s}
    .avantage-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--green),var(--green-dark));transform:scaleX(0);transform-origin:left;transition:transform .4s var(--ease-out)}
    .avantage-card:hover{transform:translateY(-8px);border-color:rgba(144,248,118,0.2);box-shadow:0 20px 50px rgba(0,0,0,0.3)}
    .avantage-card:hover::after{transform:scaleX(1)}
    .avantage-icon{width:52px;height:52px;border-radius:14px;background:rgba(144,248,118,0.1);border:1px solid rgba(144,248,118,0.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--green);margin-bottom:18px;transition:transform .35s var(--ease-spring)}
    .avantage-card:hover .avantage-icon{transform:rotate(-8deg) scale(1.1)}
    .avantage-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--white);margin-bottom:8px}
    .avantage-text{font-size:13.5px;color:rgba(255,255,255,0.55);line-height:1.65}

    /* FOOTER */
    .pied-de-page{background:#0e0e0e;color:var(--white);padding:60px 0 22px;border-top:1px solid rgba(255,255,255,0.05)}
    .conteneur-footer{max-width:1200px;margin:0 auto;padding:0 5%;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:48px}
    .section-footer h3{position:relative;padding-bottom:12px;margin-bottom:22px;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--orange)}
    .section-footer h3::after{content:'';position:absolute;bottom:0;left:0;width:26px;height:2px;background:var(--orange);border-radius:2px;transition:width .4s ease}
    .section-footer:hover h3::after{width:46px}
    .footer-logo{max-width:130px;margin-bottom:18px;display:block;opacity:.9}
    .liens-footer,.infos-contact{list-style:none;padding:0}
    .liens-footer li,.infos-contact li{display:flex;align-items:center;gap:10px;margin-bottom:10px;color:#888;font-size:13.5px}
    .liens-footer a{color:#888;text-decoration:none;transition:color .3s,padding-left .3s}
    .liens-footer a:hover{color:var(--orange);padding-left:5px}
    .reseaux-sociaux a{display:inline-block;color:#777;font-size:1.3rem;margin-right:14px;transition:color .3s,transform .3s}
    .reseaux-sociaux a:hover{color:var(--orange);transform:translateY(-3px)}
    .bas-footer{margin-top:52px;padding-top:22px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;font-size:12.5px;color:#444}

    /* RESPONSIVE */
    @media(max-width:900px){.adhesion-hero__inner{grid-template-columns:1fr;gap:40px}.avantages-grid{grid-template-columns:1fr 1fr}.recap-grid{grid-template-columns:1fr}.form-panel__body{padding:28px 24px}.form-panel__header{padding:24px}}
    @media(max-width:640px){.fields-grid{grid-template-columns:1fr}.avantages-grid{grid-template-columns:1fr}.form-nav{flex-direction:column-reverse}.btn-next,.btn-submit{width:100%;justify-content:center}.btn-prev{width:100%;justify-content:center}}
    </style>
</head>
<body>

<div class="menu-overlay" id="menuOverlay"></div>

<!-- NAVBAR -->
<nav class="navbar" id="mainNavbar">
    <div class="navbar-brand">
        <a href="page_home.php"><img src="../photo/images.png" alt="Logo MA2E"></a>
    </div>
    <button class="menu-toggle" id="menuToggle" aria-label="Ouvrir le menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <div class="navbar-menu" id="navbarMenu">
        <ul class="arii-menu">
            <li><a href="page_home.php">Accueil</a></li>
            <li class="has-submenu">
                <a href="#" class="submenu-trigger">MA2E</a>
                <ul class="submenu">
                    <li><a href="ma2e_histoie.php">Histoire</a></li>
                    <li><a href="organisation.php">Organisation</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="#" class="submenu-trigger">Produits</a>
                <ul class="submenu">
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Produits Épargne</a>
                        <ul class="submenu">
                            <li><a href="epargne_expresse.php">Épargne expresse</a></li>
                            <li><a href="epargne_ordinaire.php">Épargne ordinaire</a></li>
                            <li><a href="epargne_logement.php">Épargne logement</a></li>
                            <li><a href="epargne_depot.php">Dépôts à terme simple</a></li>
                            <li><a href="epargne_depotprogressive.php">Dépôts à terme progressifs</a></li>
                        </ul>
                    </li>
                    <li><a href="depot_de_garantie.php">Dépôt de garantie</a></li>
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Formules de crédit</a>
                        <ul class="submenu">
                            <li><a href="fcredit_ordinaire.php">Crédit ordinaire</a></li>
                            <li><a href="fcredit_expresse.php">Crédit expresse</a></li>
                            <li><a href="fcredit_immobilier.php">Crédit immobilier</a></li>
                            <li><a href="fcredit_immobiliermoderer.php">Crédit immobilier différé</a></li>
                        </ul>
                    </li>
                    <li class="has-submenu">
                        <a href="#" class="submenu-trigger">Projet immobilier</a>
                        <ul class="submenu">
                            <li><a href="projetimmobilier_1tran.php">1ère tranche</a></li>
                            <li><a href="projetimmobilier_2tran.php">2ème tranche</a></li>
                        </ul>
                    </li>
                </ul>
            </li>
            <li><a href="actualiter.php">Actualités</a></li>
            <li><a href="contacte.php">Contact</a></li>
        </ul>
    </div>
    <a href="../connexion/pageconnexion.php" class="btn-espace-membre"><i class="bi bi-person-circle"></i> Espace Membre</a>
</nav>

<!-- HERO -->
<section class="adhesion-hero">
    <div class="hero-dots"></div>
    <div class="adhesion-hero__inner">
        <div class="adhesion-hero__text">
            <div class="adhesion-hero__badge"><i class="bi bi-stars"></i> Rejoignez la communauté</div>
            <h1 class="adhesion-hero__title">Devenez membre<br><em>MA2E</em> dès aujourd'hui</h1>
            <p class="adhesion-hero__sub">Bénéficiez de produits d'épargne, de crédit et de solutions immobilières pensées exclusivement pour les agents de l'eau et de l'électricité.</p>
            <div class="hero-perks">
                <div class="hero-perk"><div class="hero-perk__icon"><i class="bi bi-shield-check"></i></div><span>Épargne sécurisée et rémunérée</span></div>
                <div class="hero-perk"><div class="hero-perk__icon"><i class="bi bi-house-heart"></i></div><span>Accès aux projets immobiliers MA2E</span></div>
                <div class="hero-perk"><div class="hero-perk__icon"><i class="bi bi-credit-card-2-front"></i></div><span>Crédits à taux avantageux</span></div>
                <div class="hero-perk"><div class="hero-perk__icon"><i class="bi bi-people"></i></div><span>+3 000 membres actifs</span></div>
            </div>
        </div>
        <div class="adhesion-hero__card">
            <div class="card-title">Processus d'adhésion</div>
            <div class="card-sub">Simple, rapide, 100% en ligne</div>
            <div class="card-steps">
                <div class="card-step"><div class="step-num">1</div><div class="step-text"><strong>Informations personnelles</strong><br>Identité et coordonnées</div></div>
                <div class="card-step"><div class="step-num">2</div><div class="step-text"><strong>Informations professionnelles</strong><br>Employeur et poste</div></div>
                <div class="card-step"><div class="step-num">3</div><div class="step-text"><strong>Pièces justificatives</strong><br>Documents requis + formulaire signé</div></div>
                <div class="card-step"><div class="step-num">4</div><div class="step-text"><strong>Validation &amp; confirmation</strong><br>Récapitulatif final</div></div>
            </div>
            <a href="#formulaire" class="card-cta"><i class="bi bi-arrow-down-circle-fill"></i> Commencer ma demande</a>
        </div>
    </div>
</section>

<!-- BARRE DE PROGRESSION -->
<div class="steps-progress" id="stepsBar">
    <div class="steps-progress__inner">
        <div class="steps-progress__line" id="progressLine" style="width:0%"></div>
        <div class="step-item active" data-step="1"><div class="step-circle"><span class="step-num-text">1</span></div><span class="step-label">Identité</span></div>
        <div class="step-item" data-step="2"><div class="step-circle"><span class="step-num-text">2</span></div><span class="step-label">Profession</span></div>
        <div class="step-item" data-step="3"><div class="step-circle"><span class="step-num-text">3</span></div><span class="step-label">Documents</span></div>
        <div class="step-item" data-step="4"><div class="step-circle"><span class="step-num-text">4</span></div><span class="step-label">Validation</span></div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div style="max-width:860px;margin:20px auto;background:#fef2f2;border-left:4px solid #ef4444;border-radius:10px;padding:16px 20px;">
    <strong style="color:#991b1b;display:block;margin-bottom:8px;"><i class="bi bi-exclamation-triangle"></i> Veuillez corriger les erreurs suivantes :</strong>
    <ul style="margin:0;padding-left:18px;color:#991b1b;">
        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- FORMULAIRE -->
<div class="form-section" id="formulaire">
    <div class="form-panel anim-hidden">

        <!-- Succès -->
        <div class="success-screen <?php echo $success ? 'show' : ''; ?>" id="successScreen">
            <div class="success-icon"><i class="bi bi-check2-circle"></i></div>
            <h2 class="success-title">Demande envoyée !</h2>
            <p class="success-sub">Votre demande d'adhésion a bien été reçue. Notre équipe vous contactera par mail dans les 48h ouvrées.</p>
            <div class="success-actions">
                <a href="page_home.php" class="btn-home"><i class="bi bi-house"></i> Retour à l'accueil</a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="adhesionForm" novalidate>
            <input type="hidden" name="action" value="soumettre">

            <!-- ÉTAPE 1 -->
            <div class="form-step <?php echo (!$success) ? 'active' : ''; ?>" id="step-1">
                <div class="form-panel__header">
                    <div class="form-panel__icon"><i class="bi bi-person-badge"></i></div>
                    <div>
                        <div class="form-panel__title">Informations personnelles</div>
                        <div class="form-panel__subtitle">Renseignez votre identité et vos coordonnées</div>
                    </div>
                </div>
                <div class="form-panel__body">
                    <div class="fields-grid">
                        <div class="field">
                            <label>Civilité <span class="req">*</span></label>
                            <div class="choice-group" id="civiliteGroup">
                                <label class="choice-btn <?php echo (($_POST['civilite']??'')==='M')?'selected':''; ?>"><input type="radio" name="civilite" value="M" <?php echo (($_POST['civilite']??'')==='M')?'checked':''; ?>> Homme</label>
                                <label class="choice-btn <?php echo (($_POST['civilite']??'')==='Mme')?'selected':''; ?>"><input type="radio" name="civilite" value="Mme" <?php echo (($_POST['civilite']??'')==='Mme')?'checked':''; ?>> Femme</label>
                            </div>
                        </div>
                        <div class="field">
                            <label>Date de naissance <span class="req">*</span></label>
                            <input type="date" id="dateNaissance" name="dateNaissance" value="<?php echo htmlspecialchars($_POST['dateNaissance']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Nom <span class="req">*</span></label>
                            <input type="text" id="nom" name="nom" placeholder="Votre nom de famille" value="<?php echo htmlspecialchars($_POST['nom']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Prénom(s) <span class="req">*</span></label>
                            <input type="text" id="prenom" name="prenom" placeholder="Votre prénom" value="<?php echo htmlspecialchars($_POST['prenom']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" id="email" name="email" placeholder="exemple@mail.com" value="<?php echo htmlspecialchars($_POST['email']??''); ?>">
                            <span class="field-error">Email invalide</span>
                        </div>
                        <div class="field">
                            <label>Téléphone <span class="req">*</span></label>
                            <input type="tel" id="tel" name="tel" placeholder="+225 07 XX XX XX XX" value="<?php echo htmlspecialchars($_POST['tel']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field field-full">
                            <label>Adresse complète <span class="req">*</span></label>
                            <input type="text" id="adresse" name="adresse" placeholder="Commune, Quartier, Rue..." value="<?php echo htmlspecialchars($_POST['adresse']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Ville <span class="req">*</span></label>
                            <select id="ville" name="ville">
                                <option value="">Sélectionner…</option>
                                <?php foreach(['Abidjan','Bouaké','Daloa','Korhogo','San-Pédro','Yamoussoukro','Autre'] as $v): ?>
                                  <option <?php echo(($_POST['ville']??'')===$v)?'selected':''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error">Veuillez choisir une ville</span>
                        </div>
                        <div class="field">
                            <label>Situation familiale</label>
                            <select id="situation" name="situation">
                                <option value="">Sélectionner…</option>
                                <?php foreach(['Célibataire','Marié(e)','Divorcé(e)','Veuf/Veuve'] as $s): ?>
                                    <option <?php echo(($_POST['situation']??'')===$s)?'selected':''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Nombre d'enfants</label>
                            <select id="nombre_enfants" name="nombre_enfants">
                                <option value="0">0</option>
                                <?php for($i=1;$i<=15;$i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo(($_POST['nombre_enfants']??0)==$i)?'selected':''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-nav">
                        <div></div>
                        <button type="button" class="btn-next" onclick="goToStep(2)">Étape suivante <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 2 -->
            <div class="form-step" id="step-2">
                <div class="form-panel__header">
                    <div class="form-panel__icon"><i class="bi bi-briefcase"></i></div>
                    <div>
                        <div class="form-panel__title">Informations professionnelles</div>
                        <div class="form-panel__subtitle">Votre employeur et votre situation au travail</div>
                    </div>
                </div>
                <div class="form-panel__body">
                    <div class="fields-grid">
                        <div class="field">
                            <label>Employeur <span class="req">*</span></label>
                            <select id="employeur" name="employeur">
                                <option value="">Sélectionner…</option>
                                <?php foreach(['CIE','SODECI','GS2E','SGA2E','SIVE','CIPREL'] as $e): ?>
                                    <option value="<?php echo $e; ?>" <?php echo(($_POST['employeur']??'')===$e)?'selected':''; ?>><?php echo $e; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Matricule employé <span class="req">*</span></label>
                            <input type="text" id="matricule" name="matricule" placeholder="Ex: CIE-2024-XXXXX" value="<?php echo htmlspecialchars($_POST['matricule']??''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>
                        <div class="field">
                            <label>Poste / Fonction <span style="font-size:11px;font-weight:400;text-transform:none">(optionnel)</span></label>
                            <input type="text" id="poste" name="poste" placeholder="Votre intitulé de poste" value="<?php echo htmlspecialchars($_POST['poste']??''); ?>">
                        </div>
                    </div>
                    <div class="form-nav">
                        <button type="button" class="btn-prev" onclick="goToStep(1)"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="button" class="btn-next" onclick="goToStep(3)">Étape suivante <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 3 -->
            <div class="form-step" id="step-3">
                <div class="form-panel__header">
                    <div class="form-panel__icon"><i class="bi bi-folder2-open"></i></div>
                    <div>
                        <div class="form-panel__title">Pièces justificatives</div>
                        <div class="form-panel__subtitle">Formats acceptés : PDF, JPG, PNG — 5 Mo max par fichier</div>
                    </div>
                </div>
                <div class="form-panel__body">
                    <div class="fields-grid one-col">

                        <div class="field">
                            <label>Pièce d'identité (CNI ou Passeport) <span class="req">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="piece_identite" accept=".pdf,.jpg,.jpeg,.png">
                                <i class="bi bi-card-image file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">PDF, JPG, PNG — 5 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Dernier bulletin de salaire <span class="req">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="bulletin_salaire" accept=".pdf,.jpg,.jpeg,.png">
                                <i class="bi bi-receipt file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">PDF, JPG, PNG — 5 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Formulaire d'adhésion signé <span class="req">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="formulaire_adhesion" accept=".pdf,.jpg,.jpeg,.png">
                                <i class="bi bi-file-earmark-text file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">PDF, JPG, PNG — 5 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Photo d'identité récente <span style="font-size:11px;font-weight:400;text-transform:none">(optionnel)</span></label>
                            <div class="file-upload">
                                <input type="file" name="photo_identite" accept=".jpg,.jpeg,.png">
                                <i class="bi bi-person-square file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">JPG, PNG — 2 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                    </div>
                    <div class="form-nav">
                        <button type="button" class="btn-prev" onclick="goToStep(2)"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="button" class="btn-next" onclick="goToStep(4)">Vérifier ma demande <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </div>

            <!-- ÉTAPE 4 -->
            <div class="form-step" id="step-4">
                <div class="form-panel__header">
                    <div class="form-panel__icon"><i class="bi bi-clipboard2-check"></i></div>
                    <div>
                        <div class="form-panel__title">Vérification &amp; Validation</div>
                        <div class="form-panel__subtitle">Relisez vos informations avant de soumettre</div>
                    </div>
                </div>
                <div class="form-panel__body">
                    <div class="recap-grid">
                        <div class="recap-block">
                            <div class="recap-block__title"><i class="bi bi-person"></i> Identité</div>
                            <div class="recap-row"><span>Nom complet</span><span id="r-nom">—</span></div>
                            <div class="recap-row"><span>Email</span><span id="r-email">—</span></div>
                            <div class="recap-row"><span>Téléphone</span><span id="r-tel">—</span></div>
                            <div class="recap-row"><span>Ville</span><span id="r-ville">—</span></div>
                        </div>
                        <div class="recap-block">
                            <div class="recap-block__title"><i class="bi bi-briefcase"></i> Profession</div>
                            <div class="recap-row"><span>Employeur</span><span id="r-employeur">—</span></div>
                            <div class="recap-row"><span>Matricule</span><span id="r-matricule">—</span></div>
                            <div class="recap-row"><span>Poste</span><span id="r-poste">—</span></div>
                        </div>
                    </div>
                    <div style="margin-top:24px;display:flex;flex-direction:column;gap:12px">
                        <label class="checkbox-field" id="checkCGU">
                            <input type="checkbox" id="cgusInput" name="cgu">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">J'accepte les <a href="#">conditions générales d'adhésion</a> et la <a href="#">politique de confidentialité</a> de la MA2E. <span style="color:var(--red)">*</span></span>
                        </label>
                        <label class="checkbox-field" id="checkInfo">
                            <input type="checkbox" id="infoInput" name="certif">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">Je certifie que les informations fournies sont exactes et complètes.</span>
                        </label>
                    </div>
                    <div class="form-nav">
                        <button type="button" class="btn-prev" onclick="goToStep(3)"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="submit" class="btn-submit" id="submitBtn" onclick="return validateSubmit()">
                            <i class="bi bi-send-fill"></i> Soumettre ma demande
                        </button>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- AVANTAGES -->
<section class="avantages-section">
    <div class="avantages-section__inner">
        <div class="section-label">Pourquoi nous choisir</div>
        <h2 class="section-title anim-hidden">Les avantages de la<br>mutuelle MA2E</h2>
        <p class="section-sub anim-hidden">Depuis plus de 14 ans, nous accompagnons les agents du secteur de l'énergie et de l'eau vers leurs projets de vie.</p>
        <div class="avantages-grid anim-stagger">
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-piggy-bank"></i></div><div class="avantage-title">Épargne rémunérée</div><div class="avantage-text">Faites fructifier votre argent avec nos produits d'épargne ordinaire, expresse ou logement à taux compétitifs.</div></div>
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-house-heart"></i></div><div class="avantage-title">Accès immobilier</div><div class="avantage-text">Réalisez votre rêve de propriété grâce à nos projets immobiliers et crédits différés accessibles aux membres.</div></div>
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-credit-card-2-front"></i></div><div class="avantage-title">Crédits avantageux</div><div class="avantage-text">Bénéficiez de crédits ordinaires ou express à des conditions préférentielles réservées aux adhérents MA2E.</div></div>
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-shield-lock"></i></div><div class="avantage-title">Sécurité garantie</div><div class="avantage-text">Vos fonds sont sécurisés et supervisés par le Conseil de Surveillance et le Comité de Déontologie de la MA2E.</div></div>
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-people"></i></div><div class="avantage-title">Communauté solide</div><div class="avantage-text">Plus de 3 000 membres actifs. Une mutuelle à taille humaine qui comprend les réalités du secteur de l'énergie.</div></div>
            <div class="avantage-card anim-hidden"><div class="avantage-icon"><i class="bi bi-headset"></i></div><div class="avantage-title">Accompagnement dédié</div><div class="avantage-text">Notre équipe vous guide à chaque étape, de l'adhésion jusqu'à la réalisation de vos projets financiers.</div></div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="pied-de-page">
    <div class="conteneur-footer">
        <div class="section-footer">
            <img src="../photo/logosansnom.png" alt="MA2E" class="footer-logo">
            <p style="color:#666;font-size:13.5px;line-height:1.7">La mutuelle des agents de l'eau et de l'électricité depuis 14 ans.</p>
            <div class="reseaux-sociaux" style="margin-top:18px">
                <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
            </div>
        </div>
        <div class="section-footer">
            <h3>Nos Produits</h3>
            <ul class="liens-footer">
                <li><a href="#">Épargne</a></li><li><a href="#">Crédit</a></li>
                <li><a href="#">Immobilier</a></li><li><a href="#">Dépôt de garantie</a></li>
            </ul>
        </div>
        <div class="section-footer">
            <h3>Contactez-nous</h3>
            <ul class="infos-contact">
                <li><i class="bi bi-telephone"></i> +225 27 21 23 64 84</li>
                <li><i class="bi bi-envelope"></i> info@ma2e.ci</li>
                <li><i class="bi bi-geo-alt"></i> Abidjan — Plateau, Immeuble SIDAM</li>
            </ul>
        </div>
    </div>
    <div class="bas-footer"><p>&copy; 2026 MA2E — Tous droits réservés.</p></div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {

    <?php if ($success): ?>
    document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
    document.getElementById('successScreen').classList.add('show');
    updateProgress(5);
    <?php endif; ?>

    // Navbar scroll
    const navbar = document.getElementById('mainNavbar');
    if (navbar) window.addEventListener('scroll', () => navbar.classList.toggle('scrolled', window.scrollY > 50), { passive: true });

    // Menu mobile
    const toggle = document.getElementById('menuToggle');
    const menu   = document.getElementById('navbarMenu');
    const overlay= document.getElementById('menuOverlay');
    if (toggle && menu && overlay) {
        const open  = () => { toggle.classList.add('active'); menu.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow='hidden'; };
        const close = () => { toggle.classList.remove('active'); menu.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow=''; };
        toggle.addEventListener('click', () => toggle.classList.contains('active') ? close() : open());
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', e => { if(e.key==='Escape') close(); });
    }

    // Sous-menus mobile
    document.querySelectorAll('.submenu-trigger').forEach(t => {
        t.addEventListener('click', function(e) {
            if(window.innerWidth <= 992) { e.preventDefault(); this.parentElement.classList.toggle('active'); }
        });
    });

    // Animations scroll
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if(e.isIntersecting){ e.target.classList.add('is-visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.anim-hidden').forEach(el => obs.observe(el));

    // Radio visuels
    document.querySelectorAll('.choice-btn').forEach(btn => {
        const input = btn.querySelector('input');
        if (!input) return;
        btn.addEventListener('click', () => {
            if (input.type === 'radio') {
                btn.closest('.choice-group')?.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected'); input.checked = true;
            } else { btn.classList.toggle('selected'); input.checked = btn.classList.contains('selected'); }
        });
    });

    // Checkboxes
    document.querySelectorAll('.checkbox-field').forEach(field => {
        field.addEventListener('click', () => {
            field.classList.toggle('checked');
            const input = field.querySelector('input[type="checkbox"]');
            if (input) input.checked = field.classList.contains('checked');
        });
    });

    // Upload fichiers
    document.querySelectorAll('.file-upload').forEach(zone => {
        const input   = zone.querySelector('input[type="file"]');
        const preview = zone.querySelector('.file-preview');
        if (!input || !preview) return;
        const showFile = file => {
            preview.innerHTML = `<div class="file-item"><span class="file-item__name"><i class="bi bi-file-earmark-check"></i>${file.name}</span><button type="button" class="file-item__remove"><i class="bi bi-x-lg"></i></button></div>`;
            zone.style.borderColor = 'var(--green-dark)';
        };
        input.addEventListener('change', () => { if(input.files[0]) showFile(input.files[0]); });
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault(); zone.classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) { const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; showFile(file); }
        });
        preview.addEventListener('click', e => {
            if (e.target.closest('.file-item__remove')) { input.value=''; zone.style.borderColor=''; preview.innerHTML=''; }
        });
    });

    // Indicateur rempli
    document.querySelectorAll('.field input, .field select').forEach(el => {
        el.addEventListener('input', () => el.closest('.field')?.classList.toggle('field-filled', el.value.trim() !== ''));
    });
});

// ── NAVIGATION ──
let currentStep = 1;
const TOTAL_STEPS = 4;

function showFieldError(el, msg) {
    el.classList.add('error');
    let err = el.parentElement.querySelector('.field-error');
    if (err) { err.textContent = msg; err.style.display = 'block'; }
}
function clearFieldError(el) {
    el.classList.remove('error');
    let err = el.parentElement.querySelector('.field-error');
    if (err) err.style.display = 'none';
}

async function checkUnique(field, value, type) {
    try {
        const url = window.location.pathname.replace(/\/[^\/]+$/, '/') + 'check_unique.php';
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: `type=${type}&value=${encodeURIComponent(value)}`
        });
        if (!res.ok) return false;
        const data = await res.json();
        return data.exists === true;
    } catch(e) { console.warn('check_unique:', e); return false; }
}

function goToStep(n) {
    if (n <= currentStep) {
        document.getElementById(`step-${currentStep}`)?.classList.remove('active');
        currentStep = n;
        document.getElementById(`step-${n}`)?.classList.add('active');
        updateProgress(n);
        document.getElementById('stepsBar')?.scrollIntoView({ behavior:'smooth', block:'start' });
        if (n === 4) fillRecap();
        return;
    }
    validateAndGo(n);
}

async function validateAndGo(n) {
    let valid = true;

    // ÉTAPE 1
    if (currentStep === 1) {
        const civilite  = document.querySelector('input[name="civilite"]:checked');
        const dateNaiss = document.getElementById('dateNaissance');
        const nom       = document.getElementById('nom');
        const prenom    = document.getElementById('prenom');
        const email     = document.getElementById('email');
        const tel       = document.getElementById('tel');
        const adresse   = document.getElementById('adresse');
        const ville     = document.getElementById('ville');

        if (!civilite) { document.getElementById('civiliteGroup').style.outline='2px solid var(--red)'; valid=false; }
        else document.getElementById('civiliteGroup').style.outline='';

        if (!dateNaiss.value) { showFieldError(dateNaiss,'La date de naissance est requise'); valid=false; } else clearFieldError(dateNaiss);
        if (!nom.value.trim()) { showFieldError(nom,'Le nom est requis'); valid=false; } else clearFieldError(nom);
        if (!prenom.value.trim()) { showFieldError(prenom,'Le prénom est requis'); valid=false; } else clearFieldError(prenom);

        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value);
        if (!emailOk) { showFieldError(email,'Email invalide'); valid=false; } else clearFieldError(email);
        if (!tel.value.trim()) { showFieldError(tel,'Le téléphone est requis'); valid=false; } else clearFieldError(tel);
        if (!adresse.value.trim()) { showFieldError(adresse,"L'adresse est requise"); valid=false; } else clearFieldError(adresse);
        if (!ville.value) { showFieldError(ville,'Veuillez choisir une ville'); valid=false; } else clearFieldError(ville);

        if (!valid) { document.getElementById('step-1').querySelector('.form-panel__body').scrollIntoView({behavior:'smooth',block:'start'}); return; }

        const emailExists = await checkUnique(email, email.value.trim(), 'email');
        if (emailExists) { showFieldError(email,'Cet email est déjà associé à une demande ou un membre'); return; }

        const telExists = await checkUnique(tel, tel.value.trim(), 'telephone');
        if (telExists) { showFieldError(tel,'Ce numéro est déjà associé à une demande ou un membre'); return; }
    }

    // ÉTAPE 2
    if (currentStep === 2) {
        const emp = document.getElementById('employeur');
        const mat = document.getElementById('matricule');
        if (!emp.value) { showFieldError(emp,"L'employeur est requis"); valid=false; } else clearFieldError(emp);
        if (!mat.value.trim()) { showFieldError(mat,'Le matricule est requis'); valid=false; } else clearFieldError(mat);
        if (!valid) return;

        const matExists = await checkUnique(mat, mat.value.trim(), 'matricule_pro');
        if (matExists) { showFieldError(mat,'Ce matricule a déjà été utilisé pour une demande'); return; }
    }

    // ÉTAPE 3 — pièce d'identité + bulletin + formulaire obligatoires
    if (currentStep === 3) {
        const cni        = document.querySelector('input[name="piece_identite"]');
        const bulletin   = document.querySelector('input[name="bulletin_salaire"]');
        const formulaire = document.querySelector('input[name="formulaire_adhesion"]');
        let v = true;

        [
            { input: cni,        label: 'Pièce d\'identité obligatoire' },
            { input: bulletin,   label: 'Bulletin de salaire obligatoire' },
            { input: formulaire, label: 'Formulaire d\'adhésion obligatoire' },
        ].forEach(({ input, label }) => {
            const zone = input?.closest('.field')?.querySelector('.file-upload');
            if (!input || !input.files || input.files.length === 0) {
                if (zone) zone.style.borderColor = 'var(--red)';
                v = false;
            } else {
                if (zone) zone.style.borderColor = '';
            }
        });

        if (!v) { document.getElementById('step-3').querySelector('.form-panel__body').scrollIntoView({behavior:'smooth',block:'start'}); return; }
    }

    // Changer d'étape
    document.getElementById(`step-${currentStep}`)?.classList.remove('active');
    currentStep = n;
    document.getElementById(`step-${n}`)?.classList.add('active');
    updateProgress(n);
    document.getElementById('stepsBar')?.scrollIntoView({ behavior:'smooth', block:'start' });
    if (n === 4) fillRecap();
}

function updateProgress(step) {
    const items = document.querySelectorAll('.step-item');
    const line  = document.getElementById('progressLine');
    items.forEach((item, i) => {
        const s = i + 1;
        item.classList.remove('active','done');
        if (s < step) item.classList.add('done');
        if (s === step) item.classList.add('active');
    });
    if (line) {
        const pct = ((Math.min(step, TOTAL_STEPS) - 1) / (TOTAL_STEPS - 1)) * 100;
        line.style.width = pct + '%';
    }
}

function fillRecap() {
    const get = id => document.getElementById(id)?.value || '—';
    document.getElementById('r-nom').textContent       = [get('prenom'),get('nom')].filter(v=>v!=='—').join(' ') || '—';
    document.getElementById('r-email').textContent     = get('email');
    document.getElementById('r-tel').textContent       = get('tel');
    document.getElementById('r-ville').textContent     = get('ville');
    document.getElementById('r-employeur').textContent = get('employeur');
    document.getElementById('r-matricule').textContent = get('matricule');
    document.getElementById('r-poste').textContent     = get('poste') || '—';
}

function validateSubmit() {
    const cgu = document.getElementById('checkCGU')?.classList.contains('checked');
    if (!cgu) {
        const el = document.getElementById('checkCGU');
        el.style.outline = '2px solid var(--red)';
        el.scrollIntoView({ behavior:'smooth', block:'center' });
        return false;
    }
    return true;
}
</script>
</body>
</html>