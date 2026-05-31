<?php
require_once '../../config/app.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

requireLogin();
$user = currentUser();

$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

$unread = array_filter($notifications, fn($n) => !$n['is_read']);

echo json_encode([
    'success'      => true,
    'notifications'=> $notifications,
    'unread_count' => count($unread)
]);
exit();