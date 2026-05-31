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

        $id   = $data['id']   ?? null;
        $name = trim($data['name'] ?? '');
        $code = trim($data['code'] ?? '');

        if (empty($name) || empty($code)) {
            echo json_encode([
                'success' => false,
                'message' => 'Department name and code are required.'
            ]);
            exit();
        }

        if ($id) {
            // ─── UPDATE ───────────────────────
            $check = $pdo->prepare("
                SELECT id FROM departments
                WHERE code = ? AND id != ?
            ");
            $check->execute([$code, $id]);
            if ($check->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A department with this code already exists.'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE departments
                SET name = ?, code = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $code, $id]);

            echo json_encode([
                'success' => true,
                'message' => 'Department updated successfully.'
            ]);

        } else {
            // ─── INSERT ───────────────────────
            $check = $pdo->prepare("
                SELECT id FROM departments WHERE code = ?
            ");
            $check->execute([$code]);
            if ($check->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'A department with this code already exists.'
                ]);
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO departments (name, code) VALUES (?, ?)
            ");
            $stmt->execute([$name, $code]);

            echo json_encode([
                'success' => true,
                'message' => 'Department added successfully.'
            ]);
        }
    }

    // ─── DELETE ───────────────────────────────
    elseif ($method === 'DELETE') {

        $id = $data['id'] ?? null;

        if (!$id) {
            echo json_encode([
                'success' => false,
                'message' => 'Department ID is required.'
            ]);
            exit();
        }

        // Block if department has users
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM users WHERE department_id = ?
        ");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete a department that has users assigned to it.'
            ]);
            exit();
        }

        // Block if department has subjects
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM subjects WHERE department_id = ?
        ");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete a department that has subjects assigned to it.'
            ]);
            exit();
        }

        // Block if department has groups
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM groups_table WHERE department_id = ?
        ");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete a department that has student groups assigned to it.'
            ]);
            exit();
        }

        $pdo->prepare("
            DELETE FROM departments WHERE id = ?
        ")->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Department deleted successfully.'
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
