<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireLogin();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$type       = $data['request_type'] ?? '';
$subject_id = $data['subject_id']   ?? null;
$group_id   = $data['group_id']     ?? null;
$details    = trim($data['details'] ?? '');

// ─── Validate ─────────────────────────────────
if (empty($type) || empty($details)) {
    echo json_encode([
        'success' => false,
        'message' => 'Request type and details are required.'
    ]);
    exit();
}

$validTypes = ['new_class','schedule_change','overload','room_change','group_switch'];
if (!in_array($type, $validTypes)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request type.'
    ]);
    exit();
}

try {
    // ─── Insert request ───────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO requests
            (professor_id, request_type, subject_id,
             group_id, details, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $user['id'],
        $type,
        $subject_id ?: null,
        $group_id   ?: null,
        $details
    ]);

    // ─── Notify all admins ────────────────────
    $admins = $pdo->query("
        SELECT id FROM users WHERE role = 'admin'
    ")->fetchAll();

    $typeLabels = [
        'new_class'       => 'New Class',
        'schedule_change' => 'Change Time',
        'overload'        => 'Overload',
        'room_change'     => 'Room Change',
        'group_switch'    => 'Group Switch',
    ];

    foreach ($admins as $admin) {
        sendNotification(
            $pdo,
            $admin['id'],
            'New Request',
            'Prof. ' . $user['name'] .
            ' sent a new request: ' .
            ($typeLabels[$type] ?? $type),
            'warning'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Request sent successfully.'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
exit();
?>