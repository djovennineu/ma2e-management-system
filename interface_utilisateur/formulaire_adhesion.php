<?php
session_start();
require_once '../config/database.php';

$errors = [];
$success = false;

// ============================================================
// TRAITEMENT PHP — Soumission du formulaire
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'soumettre') {

    // --- Récupération des champs ---
    $nom                    = trim($_POST['nom'] ?? '');
    $prenoms                = trim($_POST['prenom'] ?? '');
    $sexe                   = $_POST['civilite'] === 'M' ? 'homme' : ($_POST['civilite'] === 'Mme' ? 'femme' : '');
    $date_naissance         = $_POST['dateNaissance'] ?? null;
    $email                  = trim($_POST['email'] ?? '');
    $telephone              = trim($_POST['tel'] ?? '');
    $adresse                = trim($_POST['adresse'] ?? '');
    $ville                  = trim($_POST['ville'] ?? '');
    $situation_matrimoniale = $_POST['situation'] ?? '';
    $nombre_enfants         = (int)($_POST['nombre_enfants'] ?? 0);
    $societe_code           = trim($_POST['employeur'] ?? '');
    $matricule_pro          = trim($_POST['matricule'] ?? '');

    // Mapping situation
    $situation_map = [
        'Célibataire' => 'celibataire',
        'Marié(e)'    => 'marie',
        'Divorcé(e)'  => 'divorce',
        'Veuf/Veuve'  => 'veuf',
    ];
    $situation_matrimoniale = $situation_map[$situation_matrimoniale] ?? '';

    // --- Validations ---
    if (empty($nom))            $errors[] = "Le nom est obligatoire.";
    if (empty($prenoms))        $errors[] = "Le prénom est obligatoire.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide.";
    if (empty($telephone))      $errors[] = "Le téléphone est obligatoire.";
    if (empty($societe_code))   $errors[] = "L'employeur est obligatoire.";
    if (empty($matricule_pro))  $errors[] = "Le matricule employé est obligatoire.";

    // --- Validations supplémentaires obligatoires ---
    if (empty($_POST['civilite'] ?? ''))    $errors[] = "La civilité est obligatoire.";
    if (empty($date_naissance))             $errors[] = "La date de naissance est obligatoire.";
    if (empty($adresse))                    $errors[] = "L'adresse est obligatoire.";
    if (empty($ville))                      $errors[] = "La ville est obligatoire.";

    // Vérification unicité en base : email, téléphone, matricule_pro
    if (empty($errors)) {
        // Email
        $chk = $pdo->prepare("SELECT id FROM candidats WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $errors[] = "Cette adresse email a déjà été utilisée pour une demande.";
        }
        // Téléphone
        $chk2 = $pdo->prepare("SELECT id FROM candidats WHERE telephone = ?");
        $chk2->execute([$telephone]);
        if ($chk2->fetch()) {
            $errors[] = "Ce numéro de téléphone a déjà été utilisé pour une demande.";
        }
        // Matricule professionnel
        $chk3 = $pdo->prepare("SELECT id FROM candidats WHERE matricule_pro = ?");
        $chk3->execute([$matricule_pro]);
        if ($chk3->fetch()) {
            $errors[] = "Ce matricule employé a déjà été utilisé pour une demande.";
        }
        // Vérifier aussi dans membres (déjà membre ?)
        $chk4 = $pdo->prepare("SELECT id FROM membres WHERE email = ? OR telephone = ? OR matricule_pro = ?");
        $chk4->execute([$email, $telephone, $matricule_pro]);
        if ($chk4->fetch()) {
            $errors[] = "Ces informations correspondent à un membre déjà inscrit à la MA2E.";
        }
    }

    // Récupérer l'id de la société
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

    // --- Upload des fichiers ---
    $upload_dir = '../uploads/candidats/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $piece_identite_path      = null;
    $bulletin_salaire_path    = null;
    $photo1_path              = null;

    $allowed_docs  = ['application/pdf', 'image/jpeg', 'image/png'];
    $allowed_photos = ['image/jpeg', 'image/png'];
    $max_size_doc  = 5 * 1024 * 1024; // 5Mo
    $max_size_photo = 2 * 1024 * 1024; // 2Mo

    function uploadFichier($file_key, $allowed, $max_size, $upload_dir, &$errors, $label) {
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $file = $_FILES[$file_key];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erreur lors de l'upload de $label.";
            return null;
        }
        if ($file['size'] > $max_size) {
            $errors[] = "$label dépasse la taille maximale autorisée.";
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            $errors[] = "Format de $label non autorisé.";
            return null;
        }
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('doc_', true) . '.' . strtolower($ext);
        $dest     = $upload_dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = "Impossible de sauvegarder $label.";
            return null;
        }
        return $dest;
    }

    if (empty($errors)) {
        $piece_identite_path   = uploadFichier('piece_identite',   $allowed_docs,   $max_size_doc,   $upload_dir, $errors, "la pièce d'identité");
        $bulletin_salaire_path = uploadFichier('bulletin_salaire', $allowed_docs,   $max_size_doc,   $upload_dir, $errors, "le bulletin de salaire");
        $photo1_path           = uploadFichier('photo_identite',   $allowed_photos, $max_size_photo, $upload_dir, $errors, "la photo d'identité");
    }

    // --- Insertion en base ---
    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO candidats
                (nom, prenoms, matricule_pro, sexe, situation_matrimoniale,
                 email, telephone, adresse, ville, pays,
                 nombre_enfants, date_naissance,
                 societe_id,
                 piece_identite_path, bulletin_salaire_path, photo1_path,
                 statut, date_soumission)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?, ?, 'Côte d\'Ivoire',
                 ?, ?,
                 ?,
                 ?, ?, ?,
                 'en_attente', NOW())
        ");
        $stmt->execute([
            $nom, $prenoms, $matricule_pro, $sexe, $situation_matrimoniale,
            $email, $telephone, $adresse, $ville,
            $nombre_enfants, $date_naissance ?: null,
            $societe_id,
            $piece_identite_path, $bulletin_salaire_path, $photo1_path,
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
<title>Demande d'adhésion — MA2E</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Votre CSS existant ici -->
<link rel="stylesheet" href="adhesion.css">
</head>
<body>

<!-- Votre navbar ici -->

<?php if (!empty($errors)): ?>
<div class="alert-errors" style="max-width:800px;margin:20px auto;background:#fef2f2;border-left:4px solid #ef4444;border-radius:10px;padding:16px 20px;">
    <strong style="color:#991b1b;display:block;margin-bottom:8px;"><i class="bi bi-exclamation-triangle"></i> Veuillez corriger les erreurs suivantes :</strong>
    <ul style="margin:0;padding-left:18px;color:#991b1b;">
        <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ============================================================
     FORMULAIRE
     ============================================================ -->
<div class="form-section" id="formulaire">
    <div class="form-panel anim-hidden">

        <!-- ── Écran de succès ── -->
        <div class="success-screen <?php echo $success ? 'show' : ''; ?>" id="successScreen">
            <div class="success-icon"><i class="bi bi-check2-circle"></i></div>
            <h2 class="success-title">Demande envoyée !</h2>
            <p class="success-sub">Votre demande d'adhésion a bien été reçue. Notre équipe vous contactera dans les 48h ouvrées.</p>
            <div class="success-actions">
                <a href="page_home.php" class="btn-home">
                    <i class="bi bi-house"></i> Retour à l'accueil
                </a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="adhesionForm" novalidate>
            <input type="hidden" name="action" value="soumettre">

            <!-- ── Étape 1 — Informations personnelles ── -->
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
                            <div class="choice-group">
                                <label class="choice-btn <?php echo (($_POST['civilite'] ?? '') === 'M') ? 'selected' : ''; ?>">
                                    <input type="radio" name="civilite" value="M" <?php echo (($_POST['civilite'] ?? '') === 'M') ? 'checked' : ''; ?>> Homme
                                </label>
                                <label class="choice-btn <?php echo (($_POST['civilite'] ?? '') === 'Mme') ? 'selected' : ''; ?>">
                                    <input type="radio" name="civilite" value="Mme" <?php echo (($_POST['civilite'] ?? '') === 'Mme') ? 'checked' : ''; ?>> Femme
                                </label>
                            </div>
                        </div>

                        <div class="field">
                            <label>Date de naissance <span class="req">*</span></label>
                            <input type="date" id="dateNaissance" name="dateNaissance"
                                   value="<?php echo htmlspecialchars($_POST['dateNaissance'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Nom <span class="req">*</span></label>
                            <input type="text" id="nom" name="nom" placeholder="Votre nom de famille"
                                   value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Prénom(s) <span class="req">*</span></label>
                            <input type="text" id="prenom" name="prenom" placeholder="Votre prénom"
                                   value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" id="email" name="email" placeholder="exemple@mail.com"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            <span class="field-error">Email invalide</span>
                        </div>

                        <div class="field">
                            <label>Téléphone <span class="req">*</span></label>
                            <input type="tel" id="tel" name="tel" placeholder="+225 07 XX XX XX XX"
                                   value="<?php echo htmlspecialchars($_POST['tel'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field field-full">
                            <label>Adresse complète <span class="req">*</span></label>
                            <input type="text" id="adresse" name="adresse" placeholder="Commune, Quartier, Rue..."
                                   value="<?php echo htmlspecialchars($_POST['adresse'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Ville <span class="req">*</span></label>
                            <select id="ville" name="ville">
                                <option value="">Sélectionner…</option>
                                <?php foreach (['Abidjan','Bouaké','Daloa','Korhogo','San-Pédro','Yamoussoukro','Autre'] as $v): ?>
                                    <option <?php echo (($_POST['ville'] ?? '') === $v) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error">Veuillez choisir une ville</span>
                        </div>

                        <div class="field">
                            <label>Situation familiale</label>
                            <select id="situation" name="situation">
                                <option value="">Sélectionner…</option>
                                <?php foreach (['Célibataire','Marié(e)','Divorcé(e)','Veuf/Veuve'] as $s): ?>
                                    <option <?php echo (($_POST['situation'] ?? '') === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label>Nombre d'enfants</label>
                            <select id="nombre_enfants" name="nombre_enfants">
                                <option value="0">0</option>
                                <?php for ($i = 1; $i <= 15; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo (($_POST['nombre_enfants'] ?? 0) == $i) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                    </div>

                    <div class="form-nav">
                        <div></div>
                        <button type="button" class="btn-next" onclick="goToStep(2)">
                            Étape suivante <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Étape 2 — Informations professionnelles ── -->
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
                                <?php foreach (['CIE','SODECI','GS2E','SGA2E','SIVE','CIPREL'] as $e): ?>
                                    <option value="<?php echo $e; ?>" <?php echo (($_POST['employeur'] ?? '') === $e) ? 'selected' : ''; ?>>
                                        <?php echo $e; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Matricule employé <span class="req">*</span></label>
                            <input type="text" id="matricule" name="matricule" placeholder="Ex: CIE-2024-XXXXX"
                                   value="<?php echo htmlspecialchars($_POST['matricule'] ?? ''); ?>">
                            <span class="field-error">Ce champ est requis</span>
                        </div>

                        <div class="field">
                            <label>Poste / Fonction <span style="color:var(--text-light);font-size:11px">(optionnel)</span></label>
                            <input type="text" id="poste" name="poste" placeholder="Votre intitulé de poste"
                                   value="<?php echo htmlspecialchars($_POST['poste'] ?? ''); ?>">
                        </div>

                    </div>

                    <div class="form-nav">
                        <button type="button" class="btn-prev" onclick="goToStep(1)"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="button" class="btn-next" onclick="goToStep(3)">Étape suivante <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </div>

            <!-- ── Étape 3 — Pièces justificatives ── -->
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
                                <input type="file" name="piece_identite" accept=".pdf,.jpg,.jpeg,.png" data-target="cni">
                                <i class="bi bi-card-image file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">PDF, JPG, PNG — 5 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Dernier bulletin de salaire <span class="req">*</span></label>
                            <div class="file-upload">
                                <input type="file" name="bulletin_salaire" accept=".pdf,.jpg,.jpeg,.png" data-target="bulletin">
                                <i class="bi bi-receipt file-upload__icon"></i>
                                <div class="file-upload__text"><strong>Glissez votre fichier ici</strong> ou cliquez pour parcourir</div>
                                <div class="file-upload__hint">PDF, JPG, PNG — 5 Mo maximum</div>
                                <div class="file-preview"></div>
                            </div>
                        </div>

                        <div class="field">
                            <label>Photo d'identité récente</label>
                            <div class="file-upload">
                                <input type="file" name="photo_identite" accept=".jpg,.jpeg,.png" data-target="photo">
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

            <!-- ── Étape 4 — Récapitulatif & Validation ── -->
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

                    <div style="margin-top:24px;display:flex;flex-direction:column;gap:12px;">
                        <label class="checkbox-field" id="checkCGU">
                            <input type="checkbox" id="cgusInput" name="cgu">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">
                                J'accepte les <a href="#">conditions générales d'adhésion</a> et la
                                <a href="#">politique de confidentialité</a> de la MA2E.
                                <span style="color:var(--red);">*</span>
                            </span>
                        </label>
                        <label class="checkbox-field" id="checkInfo">
                            <input type="checkbox" id="infoInput" name="certif">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">
                                Je certifie que les informations fournies sont exactes et complètes.
                            </span>
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

        </form><!-- fin form -->
    </div>
</div>

<!-- reste de votre page (avantages, footer...) -->

<script>
document.addEventListener('DOMContentLoaded', () => {

    /* ── Afficher succès si PHP a validé ── */
    <?php if ($success): ?>
    document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
    document.getElementById('successScreen').classList.add('show');
    updateProgress(5);
    <?php endif; ?>

    /* ── Navbar scroll ── */
    const navbar = document.getElementById('mainNavbar');
    if (navbar) {
        window.addEventListener('scroll', () => {
            navbar.classList.toggle('scrolled', window.scrollY > 50);
        }, { passive: true });
    }

    /* ── Menu mobile ── */
    const toggle  = document.getElementById('menuToggle');
    const menu    = document.getElementById('navbarMenu');
    const overlay = document.getElementById('menuOverlay');
    if (toggle && menu && overlay) {
        const openMenu  = () => { toggle.classList.add('active'); menu.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow = 'hidden'; };
        const closeMenu = () => { toggle.classList.remove('active'); menu.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow = ''; };
        toggle.addEventListener('click', () => toggle.classList.contains('active') ? closeMenu() : openMenu());
        overlay.addEventListener('click', closeMenu);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
    }

    /* ── Sous-menus mobile ── */
    document.querySelectorAll('.submenu-trigger').forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            if (window.innerWidth <= 992) { e.preventDefault(); this.parentElement.classList.toggle('active'); }
        });
    });

    /* ── Animations scroll ── */
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('is-visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    document.querySelectorAll('.anim-hidden').forEach(el => obs.observe(el));

    /* ── Boutons radio visuels ── */
    document.querySelectorAll('.choice-btn').forEach(btn => {
        const input = btn.querySelector('input');
        if (!input) return;
        btn.addEventListener('click', () => {
            if (input.type === 'radio') {
                btn.closest('.choice-group')?.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                input.checked = true;
            } else {
                btn.classList.toggle('selected');
                input.checked = btn.classList.contains('selected');
            }
        });
    });

    /* ── Cases à cocher ── */
    document.querySelectorAll('.checkbox-field').forEach(field => {
        field.addEventListener('click', () => {
            field.classList.toggle('checked');
            const input = field.querySelector('input[type="checkbox"]');
            if (input) input.checked = field.classList.contains('checked');
        });
    });

    /* ── Upload fichiers ── */
    document.querySelectorAll('.file-upload').forEach(zone => {
        const input   = zone.querySelector('input[type="file"]');
        const preview = zone.querySelector('.file-preview');
        if (!input || !preview) return;

        const showFile = file => {
            preview.innerHTML = `
                <div class="file-item">
                    <span class="file-item__name"><i class="bi bi-file-earmark-check"></i>${file.name}</span>
                    <button type="button" class="file-item__remove" onclick="this.closest('.file-preview').innerHTML='';this.closest('.file-upload').style.borderColor=''">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>`;
            zone.style.borderColor = 'var(--green-dark)';
        };

        // L'input est en position absolute opacity:0 sur toute la zone (via CSS)
        // Il capte nativement le clic — on écoute juste son changement
        input.addEventListener('change', () => {
            if (input.files[0]) showFile(input.files[0]);
        });

        // Drag & drop
        zone.addEventListener('dragover', e => {
            e.preventDefault();
            zone.classList.add('dragover');
        });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', e => {
            e.preventDefault();
            zone.classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) {
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                showFile(file);
            }
        });

        // Quand un fichier est supprimé, réinitialiser l'input
        preview.addEventListener('click', e => {
            if (e.target.closest('.file-item__remove')) {
                input.value = '';
                zone.style.borderColor = '';
                preview.innerHTML = '';
            }
        });
    });

    /* ── Indicateur champ rempli ── */
    document.querySelectorAll('.field input, .field select').forEach(el => {
        el.addEventListener('input', () => {
            el.closest('.field')?.classList.toggle('field-filled', el.value.trim() !== '');
        });
    });

});

/* ============================================================
   NAVIGATION ÉTAPES
   ============================================================ */
let currentStep = 1;
const TOTAL_STEPS = 4;

// Affiche un message d'erreur sous un champ
function showFieldError(el, msg) {
    el.classList.add('error');
    let errEl = el.parentElement.querySelector('.field-error');
    if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
}
function clearFieldError(el) {
    el.classList.remove('error');
    let errEl = el.parentElement.querySelector('.field-error');
    if (errEl) errEl.style.display = 'none';
}

// Validation AJAX unicité (téléphone + matricule)
async function checkUnique(field, value, checkType) {
    try {
        // Chemin relatif au dossier courant de la page
        const url = window.location.pathname.replace(/\/[^\/]+$/, '/') + 'check_unique.php';
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `type=${checkType}&value=${encodeURIComponent(value)}`
        });
        if (!res.ok) return false;
        const data = await res.json();
        return data.exists === true;
    } catch (e) {
        // En cas d'erreur réseau on laisse passer (la vérif PHP prendra le relais)
        console.warn('check_unique error:', e);
        return false;
    }
}

function goToStep(n) {
    if (n <= currentStep) {
        // Navigation arrière : toujours autorisée
        document.getElementById(`step-${currentStep}`)?.classList.remove('active');
        currentStep = n;
        document.getElementById(`step-${n}`)?.classList.add('active');
        updateProgress(n);
        document.getElementById('stepsBar')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (n === 4) fillRecap();
        return;
    }
    validateAndGo(n);
}

async function validateAndGo(n) {
    let valid = true;

    // ── ÉTAPE 1 ──
    if (currentStep === 1) {
        const civilite = document.querySelector('input[name="civilite"]:checked');
        const dateNaiss = document.getElementById('dateNaissance');
        const nom    = document.getElementById('nom');
        const prenom = document.getElementById('prenom');
        const email  = document.getElementById('email');
        const tel    = document.getElementById('tel');
        const adresse = document.getElementById('adresse');
        const ville  = document.getElementById('ville');

        // Civilité
        if (!civilite) {
            document.querySelector('.choice-group').style.outline = '2px solid red';
            valid = false;
        } else {
            document.querySelector('.choice-group').style.outline = '';
        }

        // Date naissance
        if (!dateNaiss.value) { showFieldError(dateNaiss, 'La date de naissance est requise'); valid = false; }
        else clearFieldError(dateNaiss);

        // Nom
        if (!nom.value.trim()) { showFieldError(nom, 'Le nom est requis'); valid = false; }
        else clearFieldError(nom);

        // Prénom
        if (!prenom.value.trim()) { showFieldError(prenom, 'Le prénom est requis'); valid = false; }
        else clearFieldError(prenom);

        // Email
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value);
        if (!emailOk) { showFieldError(email, 'Email invalide'); valid = false; }
        else clearFieldError(email);

        // Téléphone
        if (!tel.value.trim()) { showFieldError(tel, 'Le téléphone est requis'); valid = false; }
        else clearFieldError(tel);

        // Adresse
        if (!adresse.value.trim()) { showFieldError(adresse, "L'adresse est requise"); valid = false; }
        else clearFieldError(adresse);

        // Ville
        if (!ville.value) { showFieldError(ville, 'Veuillez choisir une ville'); valid = false; }
        else clearFieldError(ville);

        if (!valid) {
            document.getElementById('step-1').querySelector('.form-panel__body').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        // Vérification AJAX unicité email
        const emailExists = await checkUnique(email, email.value.trim(), 'email');
        if (emailExists) {
            showFieldError(email, 'Cet email est déjà associé à une demande ou un membre');
            return;
        }

        // Vérification AJAX unicité téléphone
        const telExists = await checkUnique(tel, tel.value.trim(), 'telephone');
        if (telExists) {
            showFieldError(tel, 'Ce numéro est déjà associé à une demande ou un membre');
            return;
        }
    }

    // ── ÉTAPE 2 ──
    if (currentStep === 2) {
        const emp = document.getElementById('employeur');
        const mat = document.getElementById('matricule');

        if (!emp.value) { showFieldError(emp, "L'employeur est requis"); valid = false; }
        else clearFieldError(emp);

        if (!mat.value.trim()) { showFieldError(mat, 'Le matricule est requis'); valid = false; }
        else clearFieldError(mat);

        if (!valid) return;

        // Vérification AJAX unicité matricule
        const matExists = await checkUnique(mat, mat.value.trim(), 'matricule_pro');
        if (matExists) {
            showFieldError(mat, 'Ce matricule a déjà été utilisé pour une demande');
            return;
        }
    }

    // ── ÉTAPE 3 ── : pièce d'identité et bulletin obligatoires
    if (currentStep === 3) {
        const cni      = document.querySelector('input[name="piece_identite"]');
        const bulletin = document.querySelector('input[name="bulletin_salaire"]');
        let v = true;

        if (!cni || !cni.files || cni.files.length === 0) {
            cni.closest('.field').querySelector('.file-upload').style.borderColor = 'red';
            v = false;
        } else {
            cni.closest('.field').querySelector('.file-upload').style.borderColor = '';
        }

        if (!bulletin || !bulletin.files || bulletin.files.length === 0) {
            bulletin.closest('.field').querySelector('.file-upload').style.borderColor = 'red';
            v = false;
        } else {
            bulletin.closest('.field').querySelector('.file-upload').style.borderColor = '';
        }

        if (!v) {
            document.getElementById('step-3').querySelector('.form-panel__body').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
    }

    // Tout est valide → changer d'étape
    document.getElementById(`step-${currentStep}`)?.classList.remove('active');
    currentStep = n;
    document.getElementById(`step-${n}`)?.classList.add('active');
    updateProgress(n);
    document.getElementById('stepsBar')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (n === 4) fillRecap();
}

function updateProgress(step) {
    const items = document.querySelectorAll('.step-item');
    const line  = document.getElementById('progressLine');
    items.forEach((item, i) => {
        const s = i + 1;
        item.classList.remove('active', 'done');
        if (s < step)  item.classList.add('done');
        if (s === step) item.classList.add('active');
    });
    if (line) {
        const pct = ((Math.min(step, TOTAL_STEPS) - 1) / (TOTAL_STEPS - 1)) * 100;
        line.style.width = pct + '%';
    }
}

function fillRecap() {
    const get = id => document.getElementById(id)?.value || '—';
    document.getElementById('r-nom').textContent       = [get('prenom'), get('nom')].filter(v => v !== '—').join(' ') || '—';
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
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }
    return true;
}
</script>

</body>
</html>