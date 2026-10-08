<?php
require_once __DIR__ . '/env.php';

$db = ma2eEnv('MA2E_DB_NAME', 'ma2e');
$user = ma2eEnv('MA2E_DB_USER');
$pass = ma2eEnv('MA2E_DB_PASSWORD');
$socket = ma2eEnv('MA2E_DB_SOCKET');

$dsn = $socket !== ''
    ? "mysql:unix_socket=$socket;dbname=$db;charset=utf8mb4"
    : 'mysql:host=' . ma2eEnv('MA2E_DB_HOST', '127.0.0.1')
        . ';port=' . (int)ma2eEnv('MA2E_DB_PORT', '3306')
        . ";dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log('MA2E database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Connexion à la base indisponible. Vérifiez la configuration locale dans .env.');
}
