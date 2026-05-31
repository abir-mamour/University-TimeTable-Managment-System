<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$data   = json_decode(file_get_contents('php://input'), true);

try {

    // ─── ADD ──────────────────────────────────────
    if ($method === 'POST') {

        $profId  = (int)($data['professor_id']  ?? 0);
        $subId   = (int)($data['subject_id']    ?? 0);
        $groupId = (int)($data['group_id']       ?? 0);
        $type    = $data['session_type'] ?? '';

        if (!$profId || !$subId || !$groupId || empty($type)) {
            echo json_encode([
                'success' => false,
                'message' => 'All fields are required.',
            ]);
            exit();
        }

        $validTypes = ['lecture', 'lab', 'seminar'];
        if (!in_array($type, $validTypes)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid session type.',
            ]);
            exit();
        }

        // Check duplicate
        $check = $pdo->prepare("
            SELECT id FROM session_assignments
            WHERE professor_id = ?
              AND subject_id   = ?
              AND group_id     = ?
              AND session_type = ?
        ");
        $check->execute([$profId, $subId, $groupId, $type]);

        if ($check->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'This exact session already exists.',
            ]);
            exit();
        }

        $pdo->prepare("
            INSERT INTO session_assignments
                (professor_id, subject_id, group_id, session_type)
            VALUES (?, ?, ?, ?)
        ")->execute([$profId, $subId, $groupId, $type]);

        echo json_encode([
            'success' => true,
            'message' => 'Session added successfully.',
        ]);
    }

    // ─── DELETE ───────────────────────────────────
    elseif ($method === 'DELETE') {
        $id = (int)($data['id'] ?? 0);

        if (!$id) {
            echo json_encode([
                'success' => false,
                'message' => 'Session ID is required.',
            ]);
            exit();
        }

        $pdo->prepare("
            DELETE FROM session_assignments WHERE id = ?
        ")->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Session removed.',
        ]);
    }

    else {
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
exit();
