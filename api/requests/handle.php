<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');
$admin = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

$data       = json_decode(file_get_contents('php://input'), true);
$requestId  = $data['request_id']   ?? null;
$status     = $data['status']       ?? null;
$profId     = $data['professor_id'] ?? null;
$adminNote  = $data['admin_note']   ?? '';

// ─── Validate ─────────────────────────────────
if (!$requestId || !$status || !$profId) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields.'
    ]);
    exit();
}

if (!in_array($status, ['accepted', 'rejected'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status.'
    ]);
    exit();
}

try {
    // ─── Update request status ────────────────
    $stmt = $pdo->prepare("
        UPDATE requests
        SET
            status     = ?,
            admin_note = ?,
            handled_by = ?,
            handled_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $status,
        $adminNote,
        $admin['id'],
        $requestId
    ]);

    // ─── Get request details ──────────────────
    $stmt = $pdo->prepare("
        SELECT r.*, s.name AS subject_name, g.group_name
        FROM requests r
        LEFT JOIN subjects     s ON r.subject_id = s.id
        LEFT JOIN groups_table g ON r.group_id   = g.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    // ─── Notify professor ─────────────────────
    if ($status === 'accepted') {
        $title   = 'Request Accepted';
        $message = 'Your request has been accepted by the administrator.';
        $type    = 'success';
    } else {
        $title   = 'Request Rejected';
        $message = 'Your request has been rejected.'
                 . ($adminNote ? ' Reason: ' . $adminNote : '');
        $type    = 'error';
    }

    sendNotification($pdo, $profId, $title, $message, $type);

    // ─── If accepted notify students ──────────
    if ($status === 'accepted' && $request['group_id']) {

        // ── Group switch: move student ─────────
        if ($request['request_type'] === 'group_switch') {
            // Remove from current group
            $pdo->prepare("
                DELETE FROM student_groups
                WHERE student_id = ?
            ")->execute([$request['professor_id']]);

            // Assign to new group
            $pdo->prepare("
                INSERT INTO student_groups (student_id, group_id)
                VALUES (?, ?)
            ")->execute([$request['professor_id'], $request['group_id']]);

            // Notify student
            sendNotification(
                $pdo,
                $request['professor_id'],
                'Group Switch Approved',
                'Your group switch request has been approved. '
                . 'You have been moved to your new group.',
                'success'
            );

        } else {
            // ── Regular request: notify group ──
            notifyGroup(
                $pdo,
                $request['group_id'],
                'Timetable Updated',
                'Your timetable has been updated by the administrator.',
                'info'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Request ' . $status . ' successfully.'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
exit();
?>