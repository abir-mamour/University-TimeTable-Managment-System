<?php
session_start();
header('Content-Type: application/json');

// ─── Fix path ─────────────────────────────────
$root = dirname(dirname(__DIR__));
require_once $root . '/config/app.php';
require_once $root . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'No data received. Raw: ' . $raw
    ]);
    exit();
}

$reg  = trim($data['reg_number'] ?? '');
$pass = $data['password']        ?? '';

if (empty($reg) || empty($pass)) {
    echo json_encode([
        'success' => false,
        'message' => 'All fields are required.'
    ]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name, password, role
        FROM users
        WHERE reg_number = ?
        LIMIT 1
    ");
    $stmt->execute([$reg]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'DB Error: ' . $e->getMessage()
    ]);
    exit();
}

if (!$user || !password_verify($pass, $user['password'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid registration number or password.'
    ]);
    exit();
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['name']    = $user['name'];
$_SESSION['role']    = $user['role'];

echo json_encode([
    'success'  => true,
    'message'  => 'Login successful.',
    'redirect' => BASE_URL . '/pages/' . $user['role'] . '/dashboard.php'
]);
exit();
?>