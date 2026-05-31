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

    // ─── ADD or UPDATE ────────────────────────
    if ($method === 'POST') {

        $id       = $data['id']        ?? null;
        $name     = trim($data['room_name'] ?? '');
        $type     = $data['type']      ?? 'lecture';
        $capacity = $data['capacity']  ?? 30;
        $active   = $data['is_active'] ?? 1;

        if (empty($name)) {
            echo json_encode([
                'success' => false,
                'message' => 'Room name is required.'
            ]);
            exit();
        }

        $validTypes = ['lecture', 'lab', 'seminar'];
        if (!in_array($type, $validTypes)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid room type.'
            ]);
            exit();
        }

        if ($id) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE rooms
                SET room_name = ?,
                    type      = ?,
                    capacity  = ?,
                    is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $capacity, $active, $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Room updated successfully.'
            ]);
        } else {
            // INSERT
            // Check duplicate name
            $check = $pdo->prepare("
                SELECT id FROM rooms WHERE room_name = ?
            ");
            $check->execute([$name]);

            if ($check->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A room with this name already exists.'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO rooms
                    (room_name, type, capacity, is_active)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $type, $capacity, $active]);

            echo json_encode([
                'success' => true,
                'message' => 'Room added successfully.'
            ]);
        }
    }

    // ─── DELETE ───────────────────────────────
    elseif ($method === 'DELETE') {
        $id = $data['id'] ?? null;

        if (!$id) {
            echo json_encode([
                'success' => false,
                'message' => 'Room ID is required.'
            ]);
            exit();
        }

        // Check if room has sessions
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM timetable
            WHERE room_id = ? AND is_active = 1
        ");
        $check->execute([$id]);

        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete room with active sessions.'
            ]);
            exit();
        }

        $pdo->prepare("
            DELETE FROM rooms WHERE id = ?
        ")->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Room deleted successfully.'
        ]);
    }

    else {
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
exit();
?>