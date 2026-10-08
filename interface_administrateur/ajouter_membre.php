<?php
require_once '../connexion/auth_check.php';
checkRole('administrateur');
require_once '../config/database.php';
require_once '../config/mailer.php';

$success = '';
$error   = '';

// Récupérer les sociétés pour le formulaire
$societes = $pdo->query("SELECT id, code, nom FROM societes ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Catégories et cotisations
$cotisations_par_categorie = [
    'directeur'           => 10000,
    'cadre'               => 5000,
    'maitrise_superieure' => 3000,
    'maitrise_1_2'        => 1500,
    'employe_ouvrier'     => 1500,
];

$categories_labels = [
    'directeur'           => 'Directeur (10 000 F/mois)',
    'cadre'               => 'Cadre (5 000 F/mois)',
    'maitrise_superieure' => 'Maîtrise Supérieure (3 000 F/mois)',
    'maitrise_1_2'        => 'Maîtrise 1 et 2 (1 500 F/mois)',
    'employe_ouvrier'     => 'Employé / Ouvrier (1 500 F/mois)',
];

$sexes = ['homme' => 'Homme', 'femme' => 'Femme'];
$situations = ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf/Veuve'];

$lien_connexion = 'http://' . $_SERVER['HTTP_HOST'] . '/SOUTENANCE_pro/connexion/pageconnexion.php';

// Dossier d'upload des documents
$upload_dir = '../uploads/membres/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    
    // Récupération des données
    $societe_id         = (int)($_POST['societe_id'] ?? 0);
    $nom                = trim($_POST['nom'] ?? '');
    $prenoms            = trim($_POST['prenoms'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $telephone          = trim($_POST['telephone'] ?? '');
    $adresse            = trim($_POST['adresse'] ?? '');
    $ville              = trim($_POST['ville'] ?? '');
    $date_naissance     = $_POST['date_naissance'] ?? '';
    $sexe               = $_POST['sexe'] ?? '';
    $situation          = $_POST['situation_matrimoniale'] ?? '';
    $nombre_enfants     = (int)($_POST['nombre_enfants'] ?? 0);
    $numero_piece       = trim($_POST['numero_piece_identite'] ?? '');
    $matricule_pro      = trim($_POST['matricule_pro'] ?? '');
    $categorie          = $_POST['categorie'] ?? '';
    $salaire_net        = (float)($_POST['salaire_net'] ?? 0);
    
    // Stockage des chemins des fichiers (juste pour les stocker sur le serveur, pas en base)
    $uploaded_files = [];
    
    // Upload photo
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = 'photo_' . date('YmdHis') . '_' . rand(100, 999) . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $uploaded_files['photo'] = $target;
        }
    }
    
    // Upload pièce d'identité
    if (isset($_FILES['piece_identite']) && $_FILES['piece_identite']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['piece_identite']['name'], PATHINFO_EXTENSION);
        $filename = 'piece_' . date('YmdHis') . '_' . rand(100, 999) . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['piece_identite']['tmp_name'], $target)) {
            $uploaded_files['piece_identite'] = $target;
        }
    }
    
    // Upload bulletin de salaire
    if (isset($_FILES['bulletin_salaire']) && $_FILES['bulletin_salaire']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['bulletin_salaire']['name'], PATHINFO_EXTENSION);
        $filename = 'bulletin_' . date('YmdHis') . '_' . rand(100, 999) . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['bulletin_salaire']['tmp_name'], $target)) {
            $uploaded_files['bulletin_salaire'] = $target;
        }
    }
    
    // Upload formulaire d'adhésion
    if (isset($_FILES['formulaire_adhesion']) && $_FILES['formulaire_adhesion']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['formulaire_adhesion']['name'], PATHINFO_EXTENSION);
        $filename = 'formulaire_' . date('YmdHis') . '_' . rand(100, 999) . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['formulaire_adhesion']['tmp_name'], $target)) {
            $uploaded_files['formulaire_adhesion'] = $target;
        }
    }
    
    // Validations
    if (empty($societe_id)) {
        $error = "Veuillez sélectionner une société.";
    } elseif (empty($nom)) {
        $error = "Le nom est requis.";
    } elseif (empty($prenoms)) {
        $error = "Les prénoms sont requis.";
    } elseif (empty($email)) {
        $error = "L'email est requis.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'email n'est pas valide.";
    } elseif (empty($categorie) || !array_key_exists($categorie, $cotisations_par_categorie)) {
        $error = "Veuillez sélectionner une catégorie professionnelle.";
    } else {
        // Vérifier si l'email existe déjà
        $check = $pdo->prepare("SELECT id FROM membres WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = "Un membre avec cet email existe déjà.";
        } else {
            try {
                $pdo->beginTransaction();
                
                $cotisation_mensuelle = $cotisations_par_categorie[$categorie];
                $quotite_cessible     = $salaire_net > 0 ? round($salaire_net / 3, 2) : 0;
                
                // 1. Générer numero_membre
                $annee    = date('Y');
                $base_num = 'MA2E-'.$annee.'-';
                $last_s   = $pdo->prepare("SELECT numero_membre FROM membres WHERE numero_membre LIKE ? ORDER BY numero_membre DESC LIMIT 1");
                $last_s->execute([$base_num.'%']);
                $last_num = $last_s->fetchColumn();
                $parts_n  = explode('-', $last_num ?: '');
                $seq      = $last_num ? (int)end($parts_n) + 1 : 1;
                $numero_membre = $base_num . str_pad($seq, 3, '0', STR_PAD_LEFT);
                
                // 2. Insérer dans membres - sans les colonnes documents
                $sql = "
                    INSERT INTO membres
                        (societe_id, numero_membre, nom, prenoms, matricule_pro,
                         sexe, situation_matrimoniale, email, telephone,
                         adresse, ville, pays, nombre_enfants,
                         date_naissance, numero_piece_identite,
                         categorie, salaire_net, quotite_cessible, cotisation_mensuelle,
                         date_adhesion, statut)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ";
                
                $params = [
                    $societe_id, $numero_membre,
                    $nom, $prenoms, $matricule_pro,
                    $sexe, $situation, $email, $telephone,
                    $adresse, $ville, "Côte d'Ivoire", $nombre_enfants,
                    $date_naissance ?: null, $numero_piece,
                    $categorie, $salaire_net, $quotite_cessible, $cotisation_mensuelle,
                    date('Y-m-d'), 'actif'
                ];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $membre_id = $pdo->lastInsertId();
                
                // 3. Générer matricule utilisateur
                $base_mat = 'MBR-'.$annee.'-';
                $last_mat = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE matricule LIKE ? ORDER BY matricule DESC LIMIT 1");
                $last_mat->execute([$base_mat.'%']);
                $last_m   = $last_mat->fetchColumn();
                $parts_m  = explode('-', $last_m ?: '');
                $seq_m    = $last_m ? (int)end($parts_m) + 1 : 1;
                $matricule_user = $base_mat . str_pad($seq_m, 3, '0', STR_PAD_LEFT);
                
                // 4. Mot de passe temporaire
                $mdp_temp = ucfirst(strtolower($nom)) . rand(100, 999);
                $hash     = password_hash($mdp_temp, PASSWORD_BCRYPT);
                
                // 5. Créer compte utilisateur
                $pdo->prepare("
                    INSERT INTO utilisateurs
                        (membre_id, matricule, nom, prenoms, email, password, role, actif, mot_de_passe_temporaire)
                    VALUES (?,?,?,?,?,?,'membre',1,1)
                ")->execute([$membre_id, $matricule_user, $nom, $prenoms, $email, $hash]);
                
                // 6. Créer compte épargne Expresse avec retenue source
                $type_exp = $pdo->query("SELECT id FROM types_compte_epargne WHERE code='expresse' LIMIT 1")->fetchColumn();
                $num_cpt  = 'EXP-'.$annee.'-'.str_pad($membre_id, 5, '0', STR_PAD_LEFT);
                
                $pdo->prepare("
                    INSERT INTO comptes_epargne
                        (membre_id, type_compte_id, numero_compte, solde, solde_bloque, solde_disponible,
                         taux_interet, statut, date_ouverture, retenue_source, versement_mensuel)
                    VALUES (?,?,?,0,0,0,3.50,'actif',?,1,?)
                ")->execute([$membre_id, $type_exp, $num_cpt, date('Y-m-d'), $cotisation_mensuelle]);
                $compte_exp_id = $pdo->lastInsertId();
                
                // 7. Créer plan épargne 24 mois
                $pdo->prepare("
                    INSERT INTO plans_epargne_expresse
                        (compte_id, membre_id, montant_mensuel, date_debut, date_fin, mois_cotises, statut)
                    VALUES (?,?,?,?,?,0,'en_cours')
                ")->execute([
                    $compte_exp_id, $membre_id, $cotisation_mensuelle,
                    date('Y-m-d'),
                    date('Y-m-d', strtotime('+24 months'))
                ]);
                
                $pdo->commit();
                
                // 8. Envoyer email de bienvenue
                $cotisation_fmt = number_format($cotisation_mensuelle, 0, ',', ' ') . ' F CFA';
                $solde_min_fmt  = number_format($cotisation_mensuelle * 24, 0, ',', ' ') . ' F CFA';
                
                $email_envoye = envoyerEmailAcceptation(
                    $email,
                    $nom,
                    $prenoms,
                    $numero_membre,
                    $matricule_user,
                    $mdp_temp,
                    $cotisation_fmt,
                    $solde_min_fmt,
                    $lien_connexion
                );
                
                $success = "
                    <strong>Membre ajouté avec succès !</strong><br>
                    Numéro membre : <strong>$numero_membre</strong><br>
                    Catégorie : <strong>{$categories_labels[$categorie]}</strong><br>
                    Cotisation mensuelle : <strong>$cotisation_fmt</strong><br>
                    Matricule de connexion : <strong>$matricule_user</strong><br>
                    Mot de passe temporaire : <strong>$mdp_temp</strong><br>
                    " . ($email_envoye
                        ? "<span style='color:#2E7D32'>✅ Email de bienvenue envoyé à $email</span>"
                        : "<span style='color:#F57F17'>⚠️ Email non envoyé — communiquer les infos manuellement</span>"
                    );
                
                // Afficher un message pour les documents
                if (!empty($uploaded_files)) {
                    $success .= "<br><br><span style='color:#2196F3'>📎 Les documents ont été sauvegardés dans le dossier uploads/membres/</span>";
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Erreur lors de l'ajout : " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ajouter un membre — MA2E</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root{--gold:#C9A84C;--gold-dark:#b8922e;--gold-bg:#F5EDD6;--dark:#1a1a1a;--bg:#F0EDE8;--text:#1a1a1a;--text-light:#888;--card:#fff;--border:#e8e3db;--green:#4CAF50;--red:#F44336}
.dark{--bg:#121212;--card:#1e1e1e;--text:#f0f0f0;--text-light:#777;--border:#2a2a2a}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;transition:background .3s,color .3s}

/* HEADER */
.header{background:linear-gradient(135deg,var(--dark),#2a2a2a);padding:20px 24px 65px;position:relative;overflow:hidden}
.header::after{content:'';position:absolute;bottom:-30px;left:0;right:0;height:60px;background:var(--bg);border-radius:30px 30px 0 0}
.header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
.btn-back{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;font-weight:600;background:rgba(0,0,0,.15);padding:8px 14px;border-radius:20px}
.btn-back:hover{background:rgba(0,0,0,.25);color:white}
.header-label{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);text-transform:uppercase;letter-spacing:1px;margin-top:12px}
.t-btns{display:flex;gap:4px}
.t-btn{width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;font-size:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center}
.t-btn.active{background:rgba(255,255,255,.4)}

/* CONTENT */
.content{max-width:900px;margin:0 auto;padding:20px}

/* ALERTS */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;display:flex;align-items:flex-start;gap:10px;margin-bottom:20px}
.alert-success{background:#E8F5E9;color:#1B5E20;border-left:4px solid #4CAF50}
.alert-error{background:#FFEBEE;color:#B71C1C;border-left:4px solid #F44336}
.alert-info{background:#E3F2FD;color:#1565C0;border-left:4px solid #1976D2}

/* CARD */
.card{background:var(--card);border-radius:16px;border:1px solid var(--border);overflow:hidden;margin-bottom:24px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header-icon{font-size:22px}
.card-header-title{font-size:16px;font-weight:700;color:var(--text)}
.card-body{padding:20px}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.form-group.full-width{grid-column:1/-1}
.form-group label{font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px}
.form-group input,.form-group select,.form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:var(--bg);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold)}
.form-group textarea{resize:vertical;min-height:80px}
.form-hint{font-size:11px;color:var(--text-light);margin-top:4px}

/* Upload */
.upload-area{border:2px dashed var(--border);border-radius:12px;padding:16px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.upload-area:hover{border-color:var(--gold);background:var(--gold-bg)}
.upload-area input{display:none}
.upload-icon{font-size:32px;margin-bottom:8px}
.upload-text{font-size:12px;color:var(--text-light)}
.file-name{font-size:11px;color:var(--gold);margin-top:4px;word-break:break-all}

/* Preview */
.preview-box{background:#E8F5E9;border:1px solid #A5D6A7;border-radius:12px;padding:16px;margin-top:16px;display:none}
.preview-title{font-size:13px;font-weight:700;color:#2E7D32;margin-bottom:10px}
.preview-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #C8E6C9}
.preview-row:last-child{border-bottom:none;padding-top:8px}
.preview-label{color:#4CAF50}
.preview-value{font-weight:600;color:#1B5E20}

.btn-submit{width:100%;padding:14px;background:var(--gold);color:white;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:8px}
.btn-submit:hover{background:var(--gold-dark)}

@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.fadeUp{animation:fadeUp .35s ease both}

@media(max-width:768px){
  .form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<header class="header">
  <div class="header-top fadeUp">
    <a href="membres.php" class="btn-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Retour
    </a>
    <div class="t-btns">
      <button class="t-btn active" onclick="setTheme('light',this)">☀</button>
      <button class="t-btn" onclick="setTheme('dark',this)">🌙</button>
    </div>
  </div>
  <div class="header-label">👥 Ajouter un membre</div>
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

  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">📝</span>
      <span class="card-header-title">Informations du membre</span>
    </div>
    <div class="card-body">
      <form method="POST" id="formMembre" enctype="multipart/form-data">
        <input type="hidden" name="action" value="ajouter">
        
        <div class="form-grid">
          
          <!-- Société -->
          <div class="form-group">
            <label>Société *</label>
            <select name="societe_id" required>
              <option value="">-- Sélectionner --</option>
              <?php foreach ($societes as $s): ?>
              <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['code'] . ' — ' . $s['nom']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <!-- Catégorie -->
          <div class="form-group">
            <label>Catégorie professionnelle *</label>
            <select name="categorie" id="categorie" required onchange="updatePreview()">
              <option value="">-- Sélectionner --</option>
              <?php foreach ($categories_labels as $key => $label): ?>
              <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <!-- Nom -->
          <div class="form-group">
            <label>Nom *</label>
            <input type="text" name="nom" required placeholder="Nom de famille">
          </div>
          
          <!-- Prénoms -->
          <div class="form-group">
            <label>Prénoms *</label>
            <input type="text" name="prenoms" required placeholder="Prénoms complets">
          </div>
          
          <!-- Email -->
          <div class="form-group">
            <label>Email *</label>
            <input type="email" name="email" required placeholder="adresse@email.com">
          </div>
          
          <!-- Téléphone -->
          <div class="form-group">
            <label>Téléphone</label>
            <input type="tel" name="telephone" placeholder="07 XX XX XX XX">
          </div>
          
          <!-- Sexe -->
          <div class="form-group">
            <label>Sexe</label>
            <select name="sexe">
              <option value="">-- Non renseigné --</option>
              <?php foreach ($sexes as $key => $label): ?>
              <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <!-- Situation matrimoniale -->
          <div class="form-group">
            <label>Situation matrimoniale</label>
            <select name="situation_matrimoniale">
              <option value="">-- Non renseigné --</option>
              <?php foreach ($situations as $key => $label): ?>
              <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <!-- Date de naissance -->
          <div class="form-group">
            <label>Date de naissance</label>
            <input type="date" name="date_naissance">
          </div>
          
          <!-- N° pièce d'identité -->
          <div class="form-group">
            <label>N° pièce d'identité</label>
            <input type="text" name="numero_piece_identite" placeholder="CNI, Passeport...">
          </div>
          
          <!-- Nombre d'enfants -->
          <div class="form-group">
            <label>Nombre d'enfants</label>
            <input type="number" name="nombre_enfants" min="0" value="0">
          </div>
          
          <!-- Adresse -->
          <div class="form-group full-width">
            <label>Adresse</label>
            <input type="text" name="adresse" placeholder="Adresse complète">
          </div>
          
          <!-- Ville -->
          <div class="form-group">
            <label>Ville</label>
            <input type="text" name="ville" placeholder="Ville">
          </div>
          
          <!-- Matricule professionnel -->
          <div class="form-group">
            <label>Matricule professionnel</label>
            <input type="text" name="matricule_pro" placeholder="Matricule société">
          </div>
          
          <!-- Salaire net -->
          <div class="form-group">
            <label>Salaire net mensuel (F CFA)</label>
            <input type="number" name="salaire_net" id="salaireNet" min="0" step="1000" placeholder="Ex: 250000" oninput="updatePreview()">
            <span class="form-hint">Permet de calculer la quotité cessible (1/3 du salaire)</span>
          </div>
          
        </div>
        
        <!-- DOCUMENTS -->
        <div class="card" style="margin-top:20px; margin-bottom:0">
          <div class="card-header">
            <span class="card-header-icon">📎</span>
            <span class="card-header-title">Documents justificatifs</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              
              <!-- Photo d'identité -->
              <div class="upload-area" onclick="document.getElementById('photo_input').click()">
                <div class="upload-icon">📸</div>
                <div class="upload-text">Cliquez pour ajouter une photo</div>
                <div id="photo_name" class="file-name"></div>
                <input type="file" id="photo_input" name="photo" accept="image/*" style="display:none" onchange="updateFileName(this, 'photo_name')">
              </div>
              
              <!-- Pièce d'identité -->
              <div class="upload-area" onclick="document.getElementById('piece_input').click()">
                <div class="upload-icon">🪪</div>
                <div class="upload-text">Cliquez pour ajouter la pièce d'identité</div>
                <div id="piece_name" class="file-name"></div>
                <input type="file" id="piece_input" name="piece_identite" accept="image/*,application/pdf" style="display:none" onchange="updateFileName(this, 'piece_name')">
              </div>
              
              <!-- Bulletin de salaire -->
              <div class="upload-area" onclick="document.getElementById('bulletin_input').click()">
                <div class="upload-icon">📄</div>
                <div class="upload-text">Cliquez pour ajouter le bulletin de salaire</div>
                <div id="bulletin_name" class="file-name"></div>
                <input type="file" id="bulletin_input" name="bulletin_salaire" accept="image/*,application/pdf" style="display:none" onchange="updateFileName(this, 'bulletin_name')">
              </div>
              
              <!-- Formulaire d'adhésion -->
              <div class="upload-area" onclick="document.getElementById('formulaire_input').click()">
                <div class="upload-icon">📋</div>
                <div class="upload-text">Cliquez pour ajouter le formulaire d'adhésion</div>
                <div id="formulaire_name" class="file-name"></div>
                <input type="file" id="formulaire_input" name="formulaire_adhesion" accept="image/*,application/pdf" style="display:none" onchange="updateFileName(this, 'formulaire_name')">
              </div>
              
            </div>
          </div>
        </div>
        
        <!-- Aperçu -->
        <div class="preview-box" id="previewBox">
          <div class="preview-title">📊 Récapitulatif</div>
          <div class="preview-row">
            <span class="preview-label">Cotisation mensuelle</span>
            <span class="preview-value" id="previewCotisation">—</span>
          </div>
          <div class="preview-row">
            <span class="preview-label">Solde minimum Expresse (24 mois)</span>
            <span class="preview-value" id="previewSoldeMin">—</span>
          </div>
          <div class="preview-row" id="previewQuotiteRow" style="display:none">
            <span class="preview-label">Quotité cessible (1/3 salaire)</span>
            <span class="preview-value" id="previewQuotite">—</span>
          </div>
          <div class="preview-row">
            <span class="preview-label">Retenue à la source</span>
            <span class="preview-value" style="color:#2E7D32">✅ Activée (prélèvement automatique)</span>
          </div>
        </div>
        
        <button type="submit" class="btn-submit" onclick="return confirm('Confirmer l\'ajout de ce membre ? Un email de bienvenue sera envoyé.')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"/><polygon points="18 2 22 6 12 16 8 16 8 12 18 2"/></svg>
          Ajouter le membre
        </button>
        
      </form>
    </div>
  </div>
  
  <!-- Informations importantes -->
  <div class="card fadeUp">
    <div class="card-header">
      <span class="card-header-icon">ℹ️</span>
      <span class="card-header-title">Informations importantes</span>
    </div>
    <div class="card-body">
      <ul style="list-style:none; padding-left:0; font-size:13px; color:var(--text-light); line-height:1.8">
        <li>✅ <strong>Compte Expresse</strong> créé automatiquement avec retenue à la source</li>
        <li>✅ <strong>Plan épargne 24 mois</strong> initialisé automatiquement</li>
        <li>✅ <strong>Email de bienvenue</strong> envoyé avec les identifiants de connexion</li>
        <li>✅ Les documents sont stockés dans le dossier <code>uploads/membres/</code></li>
        <li>⚠️ Le mot de passe temporaire est généré automatiquement : <strong>Nom + 3 chiffres aléatoires</strong></li>
      </ul>
    </div>
  </div>

</div>

<script>
const cotisations = {
    directeur: 10000,
    cadre: 5000,
    maitrise_superieure: 3000,
    maitrise_1_2: 1500,
    employe_ouvrier: 1500
};

function formatMontant(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' F CFA';
}

function updatePreview() {
    const categorie = document.getElementById('categorie').value;
    const salaire = parseFloat(document.getElementById('salaireNet').value) || 0;
    const previewBox = document.getElementById('previewBox');
    
    if (!categorie || !cotisations[categorie]) {
        previewBox.style.display = 'none';
        return;
    }
    
    const cotisation = cotisations[categorie];
    document.getElementById('previewCotisation').textContent = formatMontant(cotisation);
    document.getElementById('previewSoldeMin').textContent = formatMontant(cotisation * 24);
    
    const quotiteRow = document.getElementById('previewQuotiteRow');
    if (salaire > 0) {
        const quotite = Math.round(salaire / 3);
        document.getElementById('previewQuotite').textContent = formatMontant(quotite);
        quotiteRow.style.display = 'flex';
    } else {
        quotiteRow.style.display = 'none';
    }
    
    previewBox.style.display = 'block';
}

function updateFileName(input, displayId) {
    const display = document.getElementById(displayId);
    if (input.files && input.files[0]) {
        display.textContent = input.files[0].name;
        display.style.color = '#2E7D32';
    } else {
        display.textContent = '';
    }
}

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