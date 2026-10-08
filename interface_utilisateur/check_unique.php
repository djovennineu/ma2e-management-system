<?php
/**
 * check_unique.php
 * Doit être dans le même dossier que le formulaire
 * Appelé uniquement via fetch() en AJAX — jamais inclus dans une autre page
 */

// Bloquer tout accès direct non-AJAX
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    (empty($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) &&
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {
    http_response_code(403);
    exit('Accès interdit');
}

// Empêcher tout cache et forcer JSON
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');

require_once '../config/database.php';

$type  = $_POST['type']  ?? '';
$value = trim($_POST['value'] ?? '');

if (empty($value)) {
    echo json_encode(['exists' => false]);
    exit;
}

$allowed = ['telephone', 'matricule_pro', 'email'];
if (!in_array($type, $allowed, true)) {
    echo json_encode(['exists' => false]);
    exit;
}

$exists = false;

// Vérifier dans candidats
$stmt = $pdo->prepare("SELECT id FROM candidats WHERE `{$type}` = ? LIMIT 1");
$stmt->execute([$value]);
if ($stmt->fetch()) {
    $exists = true;
}

// Vérifier aussi dans membres
if (!$exists) {
    $stmt2 = $pdo->prepare("SELECT id FROM membres WHERE `{$type}` = ? LIMIT 1");
    $stmt2->execute([$value]);
    if ($stmt2->fetch()) {
        $exists = true;
    }
}

echo json_encode(['exists' => $exists]);
exit;