<?php
/**
 * voir_document.php
 * À placer dans : interface_administrateur/
 * Sert les fichiers uploadés de manière sécurisée
 */

// Vider tout buffer de sortie avant d'envoyer les headers
while (ob_get_level()) ob_end_clean();

session_start();
require_once '../config/database.php';

// 1. Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Accès interdit — Vous devez être connecté.');
}

// 2. Vérifier le rôle
$roles_autorises = ['super_admin', 'administrateur', 'gestionnaire_credit', 'comptable'];
if (!in_array($_SESSION['role'] ?? '', $roles_autorises)) {
    http_response_code(403);
    die('Accès refusé — Droits insuffisants.');
}

// 3. Récupérer et nettoyer le nom du fichier
$fichier = basename($_GET['fichier'] ?? '');
if (empty($fichier)) {
    http_response_code(400);
    die('Fichier non spécifié.');
}

// 4. Construire le chemin réel
// interface_administrateur/ → remonter d'un niveau → SOUTENANCE_pro/uploads/candidats/
$chemin = __DIR__ . '/../uploads/candidats/' . $fichier;

// 5. Vérifier que le fichier existe
if (!file_exists($chemin)) {
    http_response_code(404);
    die('Fichier introuvable. Chemin : ' . htmlspecialchars($chemin));
}

// 6. Vérifier que le fichier appartient bien à un candidat en base
$stmt = $pdo->prepare("
    SELECT id FROM candidats
    WHERE piece_identite_path      LIKE ?
       OR bulletin_salaire_path    LIKE ?
       OR photo1_path              LIKE ?
       OR formulaire_adhesion_path LIKE ?
    LIMIT 1
");
$pattern = '%' . $fichier . '%';
$stmt->execute([$pattern, $pattern, $pattern, $pattern]);
if (!$stmt->fetch()) {
    http_response_code(403);
    die('Accès refusé — Ce fichier ne correspond à aucun document enregistré.');
}

// 7. Déterminer le type MIME
$extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
$types_mime = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$mime = $types_mime[$extension] ?? 'application/octet-stream';

// 8. Envoyer le fichier
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($chemin));
header('Content-Disposition: inline; filename="' . $fichier . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($chemin);
exit;