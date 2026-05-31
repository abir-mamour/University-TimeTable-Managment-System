<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/index.php");
        exit();
    }
}

function requireRole(string $role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header("Location: " . BASE_URL . "/index.php");
        exit();
    }
}

function currentUser(): array {
    return [
        'id'   => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name']    ?? null,
        'role' => $_SESSION['role']    ?? null,
    ];
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}
?>