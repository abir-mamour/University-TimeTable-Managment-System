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

        $id       = $data['id']            ?? null;
        $name     = trim($data['name']     ?? '');
        $code     = trim($data['code']     ?? '');
        $deptId   = $data['department_id'] ?? null;
        $credits  = (int)($data['credits'] ?? 3);

        if (empty($name) || empty($code)) {
            echo json_encode([
                'success' => false,
                'message' => 'Subject name and code are required.'
            ]);
            exit();
        }

        if ($credits < 1 || $credits > 10) {
            echo json_encode([
                'success' => false,
                'message' => 'Credits must be between 1 and 10.'
            ]);
            exit();
        }

        if ($id) {
            // ─── UPDATE ───────────────────────
            // Check code uniqueness excluding self
            $check = $pdo->prepare("
                SELECT id FROM subjects
                WHERE code = ? AND id != ?
            ");
            $check->execute([$code, $id]);
            if ($check->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A subject with this code already exists.'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE subjects
                SET name          = ?,
                    code          = ?,
                    department_id = ?,
                    credits       = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $code,
                $deptId ?: null,
                $credits, $id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Subject updated successfully.'
            ]);

        } else {
            // ─── INSERT ───────────────────────
            $check = $pdo->prepare("
                SELECT id FROM subjects WHERE code = ?
            ");
            $check->execute([$code]);
            if ($check->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A subject with this code already exists.'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO subjects
                    (name, code, department_id, credits)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $code,
                $deptId ?: null,
                $credits
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Subject added successfully.'
            ]);
        }
    }

    // ─── DELETE ───────────────────────────────
    elseif ($method === 'DELETE') {

        $id = $data['id'] ?? null;

        if (!$id) {
            echo json_encode([
                'success' => false,
                'message' => 'Subject ID is required.'
            ]);
            exit();
        }

        // Block delete if subject has active sessions
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM timetable
            WHERE subject_id = ? AND is_active = 1
        ");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete a subject that has active timetable sessions.'
            ]);
            exit();
        }

        $pdo->prepare("
            DELETE FROM subjects WHERE id = ?
        ")->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Subject deleted successfully.'
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
