<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

// Inclure les fonctions communes si le fichier existe
if (file_exists('../config/functions.php')) {
    require_once '../config/functions.php';
}

header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'];
$membre_id = $_SESSION['membre_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'depot_mobile') {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$compte_id = (int)($_POST['compte_id'] ?? 0);
$operateur = $_POST['operateur'] ?? '';
$telephone = trim($_POST['telephone'] ?? '');
$montant   = (float)($_POST['montant'] ?? 0);
$operateurs_valides = ['orange', 'mtn', 'wave'];

// Récupérer le compte avec toutes les informations nécessaires
$cpt = $pdo->prepare("
    SELECT ce.*, tce.code AS type_code, tce.libelle AS type_libelle,
           tce.plafond_solde, tce.depot_minimum,
           tce.retrait_autorise, tce.solde_minimum
    FROM comptes_epargne ce
    JOIN types_compte_epargne tce ON ce.type_compte_id = tce.id
    WHERE ce.id = ? AND ce.membre_id = ? AND ce.statut = 'actif'
");
$cpt->execute([$compte_id, $membre_id]);
$cpt = $cpt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// VALIDATIONS
// ============================================================

if (!$cpt) {
    echo json_encode(['success' => false, 'message' => 'Compte introuvable ou inactif.']);
    exit;
}

// Vérification opérateur
if (!in_array($operateur, $operateurs_valides)) {
    echo json_encode(['success' => false, 'message' => 'Opérateur invalide.']);
    exit;
}

// Vérification téléphone
if (empty($telephone)) {
    echo json_encode(['success' => false, 'message' => 'Numéro de téléphone requis.']);
    exit;
}

// Validation du format téléphone (8 à 12 chiffres)
$telephone_clean = preg_replace('/[^0-9]/', '', $telephone);
if (!preg_match('/^[0-9]{8,12}$/', $telephone_clean)) {
    echo json_encode(['success' => false, 'message' => 'Numéro de téléphone invalide (8 à 12 chiffres).']);
    exit;
}

// Vérification montant minimum général
$depot_min = (float)($cpt['depot_minimum'] ?? 500);
if ($montant < $depot_min) {
    echo json_encode(['success' => false, 'message' => 'Montant minimum : ' . number_format($depot_min, 0, ',', ' ') . ' F CFA.']);
    exit;
}

// Types de comptes autorisés pour dépôt mobile
$types_autorises = ['expresse', 'ordinaire', 'logement', 'datavp'];
if (!in_array($cpt['type_code'], $types_autorises)) {
    echo json_encode(['success' => false, 'message' => 'Ce type de compte ne permet pas les dépôts mobiles.']);
    exit;
}

// Vérification spécifique DATS (aucun dépôt après ouverture)
if ($cpt['type_code'] === 'dats') {
    echo json_encode(['success' => false, 'message' => 'Aucun dépôt n\'est autorisé sur un compte DATS après l\'ouverture.']);
    exit;
}

// Vérification compte à terme échu
if (in_array($cpt['type_code'], ['dats', 'datavp']) && 
    !empty($cpt['date_echeance']) && 
    strtotime($cpt['date_echeance']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Ce compte à terme est arrivé à échéance le ' . date('d/m/Y', strtotime($cpt['date_echeance'])) . '.']);
    exit;
}

// Vérification spécifique Ordinaire
if ($cpt['type_code'] === 'ordinaire' && $montant < 3500) {
    echo json_encode(['success' => false, 'message' => 'Dépôt minimum Ordinaire : 3 500 F CFA.']);
    exit;
}

// Vérification spécifique DATAVP
if ($cpt['type_code'] === 'datavp') {
    if ($montant < 5000) {
        echo json_encode(['success' => false, 'message' => 'Versement minimum DATAVP : 5 000 F CFA.']);
        exit;
    }
    // Vérifier que le montant est au moins égal au versement mensuel (si défini)
    if (!empty($cpt['versement_mensuel']) && $montant < $cpt['versement_mensuel']) {
        echo json_encode(['success' => false, 'message' => 'Le versement doit être au minimum de ' . number_format($cpt['versement_mensuel'], 0, ',', ' ') . ' F CFA (montant mensuel défini).']);
        exit;
    }
}

// Vérification du plafond
if ($cpt['plafond_solde'] && ($cpt['solde'] + $montant) > $cpt['plafond_solde']) {
    echo json_encode(['success' => false, 'message' => 'Ce dépôt dépasserait le plafond autorisé de ' . number_format($cpt['plafond_solde'], 0, ',', ' ') . ' F CFA.']);
    exit;
}

// ============================================================
// FONCTION DE CALCUL DE DATE DE VALEUR (si non définie dans functions.php)
// ============================================================
if (!function_exists('calculerDateValeur')) {
    function calculerDateValeur($date_operation, $type_code, $type_mouvement) {
        if ($type_code === 'ordinaire') {
            $jour = (int)date('d', strtotime($date_operation));
            $mois = (int)date('m', strtotime($date_operation));
            $annee = (int)date('Y', strtotime($date_operation));
            
            if ($type_mouvement === 'depot' || $type_mouvement === 'depot_mobile') {
                // Dépôt du 01 au 15 → valeur au 16 du mois
                if ($jour <= 15) {
                    return date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
                } 
                // Dépôt du 16 au 31 → valeur au 1er du mois suivant
                else {
                    return date('Y-m-d', mktime(0, 0, 0, $mois + 1, 1, $annee));
                }
            } 
            elseif ($type_mouvement === 'retrait') {
                // Retrait du 01 au 15 → valeur au 1er du mois
                if ($jour <= 15) {
                    return date('Y-m-d', mktime(0, 0, 0, $mois, 1, $annee));
                } 
                // Retrait du 16 au 31 → valeur au 16 du mois
                else {
                    return date('Y-m-d', mktime(0, 0, 0, $mois, 16, $annee));
                }
            }
        }
        // Pour les autres comptes, date de valeur = date d'opération
        return $date_operation;
    }
}

// ============================================================
// TRAITEMENT DU DÉPÔT
// ============================================================

try {
    $pdo->beginTransaction();

    $solde_avant = $cpt['solde'];
    $solde_apres = $solde_avant + $montant;
    $ops_labels  = ['orange' => 'Orange Money', 'mtn' => 'MTN MoMo', 'wave' => 'Wave'];
    $op_label    = $ops_labels[$operateur] ?? $operateur;
    $ref         = 'MOB-' . strtoupper(substr($operateur, 0, 3)) . '-' . date('YmdHis') . '-' . rand(100, 999);
    
    $date_operation = date('Y-m-d');
    $date_valeur = calculerDateValeur($date_operation, $cpt['type_code'], 'depot_mobile');

    // Enregistrer le mouvement
    $stmt = $pdo->prepare("
        INSERT INTO mouvements
            (compte_id, reference, type, montant, solde_avant, solde_apres,
             date_valeur, date_operation, canal, utilisateur_id, commentaire)
        VALUES (?, ?, 'depot_mobile', ?, ?, ?, ?, ?, 'mobile_money', ?, ?)
    ");
    $stmt->execute([
        $compte_id, $ref, $montant,
        $solde_avant, $solde_apres,
        $date_valeur, $date_operation,
        $user_id,
        "Dépôt $op_label — " . substr($telephone_clean, -6) . " (simulé)"
    ]);

    // Mettre à jour le solde du compte
    $nouveau_solde_disponible = $solde_apres - $cpt['solde_bloque'];
    $pdo->prepare("
        UPDATE comptes_epargne
        SET solde = ?, solde_disponible = ?
        WHERE id = ?
    ")->execute([$solde_apres, $nouveau_solde_disponible, $compte_id]);

    // Pour DATAVP : incrémenter le compteur de versements
    if ($cpt['type_code'] === 'datavp') {
        // Vérifier si la colonne nb_versements_effectues existe
        try {
            $check_column = $pdo->query("SHOW COLUMNS FROM comptes_epargne LIKE 'nb_versements_effectues'");
            if ($check_column->rowCount() > 0) {
                $pdo->prepare("
                    UPDATE comptes_epargne
                    SET nb_versements_effectues = COALESCE(nb_versements_effectues, 0) + 1
                    WHERE id = ?
                ")->execute([$compte_id]);
                
                // Vérifier si le DATAVP est arrivé à échéance
                $updated = $pdo->prepare("
                    SELECT nb_versements_effectues, duree_mois 
                    FROM comptes_epargne 
                    WHERE id = ?
                ");
                $updated->execute([$compte_id]);
                $datavp = $updated->fetch();
                
                if ($datavp && $datavp['nb_versements_effectues'] >= $datavp['duree_mois']) {
                    error_log("DATAVP $compte_id a atteint son échéance de {$datavp['duree_mois']} mois");
                }
            }
        } catch (Exception $e) {
            // Ignorer l'erreur si le champ n'existe pas
            error_log("DATAVP: mise à jour nb_versements_effectues ignorée - " . $e->getMessage());
        }
    }

    $pdo->commit();

    // Log de sécurité
    error_log("Dépôt mobile OK: membre_id=$membre_id, compte_id=$compte_id, operateur=$operateur, montant=$montant, ref=$ref, date_valeur=$date_valeur");

    $montant_fmt = number_format($montant, 0, ',', ' ');
    echo json_encode([
        'success'       => true,
        'message'       => "$montant_fmt F CFA reçus via $op_label !",
        'reference'     => $ref,
        'nouveau_solde' => $solde_apres,
        'date_valeur'   => date('d/m/Y', strtotime($date_valeur))
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erreur dépôt mobile: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur lors du traitement. Veuillez réessayer.']);
}
?>