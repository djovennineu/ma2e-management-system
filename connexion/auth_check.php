<?php
// ============================================================
// auth_check.php — À inclure EN HAUT de chaque dashboard
// Usage : require_once 'auth_check.php'; checkRole('super_admin');
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkRole(string ...$roles): void {
    if (!isset($_SESSION['user_id'])) {
        header("Location: pageconnexion.php");
        exit;
    }
    if (!in_array($_SESSION['role'], $roles)) {
        header("Location: pageconnexion.php");
        exit;
    }
}
?>