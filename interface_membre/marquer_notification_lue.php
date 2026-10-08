<?php
require_once '../connexion/auth_check.php';
checkRole('membre');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notif_id = (int)($_POST['id'] ?? 0);
    $membre_id = $_SESSION['membre_id'];
    
    $pdo->prepare("
        UPDATE notifications 
        SET est_lu = 1, date_lecture = NOW() 
        WHERE id = ? AND membre_id = ?
    ")->execute([$notif_id, $membre_id]);
    
    echo json_encode(['success' => true]);
}
?>