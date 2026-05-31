<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$data   = json_decode(file_get_contents('php://input'), true);

function fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}
function ok(string $msg): void {
    echo json_encode(['success' => true, 'message' => $msg]);
    exit();
}

try {

    // ─── ADD or UPDATE ────────────────────────
    if ($method === 'POST') {

        $id       = $data['id']              ?? null;
        $name     = trim($data['name']       ?? '');
        $reg      = trim($data['reg_number'] ?? '');
        $email    = trim($data['email']      ?? '');
        $password = $data['password']        ?? '';
        $role     = $data['role']            ?? 'student';
        $deptId   = $data['department_id']   ?? null;
        $active   = (int)($data['is_active'] ?? 1);

        if (empty($name) || empty($reg)) {
            fail('Name and registration number are required.');
        }

        if (!in_array($role, ['professor', 'student'], true)) {
            fail('Invalid role.');
        }

        // ─── UPDATE ───────────────────────────
        if ($id) {

            // Fetch current state before changing anything
            $prev = $pdo->prepare("
                SELECT name, reg_number, email, role,
                       department_id, is_active
                FROM users WHERE id = ?
            ");
            $prev->execute([$id]);
            $old = $prev->fetch(PDO::FETCH_ASSOC);

            if (!$old) fail('User not found.');

            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("
                    UPDATE users
                    SET name=?, reg_number=?, email=?,
                        password=?, role=?, department_id=?, is_active=?
                    WHERE id=?
                ")->execute([
                    $name, $reg, $email ?: null,
                    $hash, $role, $deptId ?: null, $active, $id,
                ]);
            } else {
                $pdo->prepare("
                    UPDATE users
                    SET name=?, reg_number=?, email=?,
                        role=?, department_id=?, is_active=?
                    WHERE id=?
                ")->execute([
                    $name, $reg, $email ?: null,
                    $role, $deptId ?: null, $active, $id,
                ]);
            }

            // ── Build change summary ───────────
            $changes = [];

            if ($old['name'] !== $name) {
                $changes[] = "name updated to \"{$name}\"";
            }
            if (($old['email'] ?? '') !== ($email ?: '')) {
                $changes[] = 'email address updated';
            }
            if ($old['role'] !== $role) {
                $changes[] = "role changed to {$role}";
            }
            if ((int)$old['is_active'] !== $active) {
                $changes[] = $active ? 'account activated' : 'account deactivated';
            }
            if (!empty($password)) {
                $changes[] = 'password changed';
            }

            // ── Send notification ──────────────
            if (!empty($changes)) {
                // Pick the most important type
                $type = 'info';
                if (!$active && (int)$old['is_active']) {
                    $type = 'warning';
                } elseif ($active && !(int)$old['is_active']) {
                    $type = 'success';
                }

                // Deactivation gets its own focused message
                if (!$active && (int)$old['is_active']) {
                    sendNotification(
                        $pdo, (int)$id,
                        'Account Deactivated',
                        'Your account has been deactivated by the administrator. '
                        . 'Contact the admin if you believe this is an error.',
                        'warning'
                    );
                } elseif ($active && !(int)$old['is_active']) {
                    sendNotification(
                        $pdo, (int)$id,
                        'Account Activated',
                        'Your account has been activated. You can now log in to the system.',
                        'success'
                    );
                } else {
                    $summary = implode(', ', $changes);
                    sendNotification(
                        $pdo, (int)$id,
                        'Account Updated',
                        "Your account has been updated by the administrator: {$summary}.",
                        $type
                    );
                }
            }

            ok('User updated successfully.');

        }

        // ─── INSERT ───────────────────────────
        if (empty($password)) {
            fail('Password is required for new users.');
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE reg_number = ?");
        $check->execute([$reg]);
        if ($check->fetch()) {
            fail('Registration number already exists.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO users
                (name, reg_number, email, password,
                 role, department_id, is_active)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([
            $name, $reg, $email ?: null,
            $hash, $role, $deptId ?: null, $active,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Welcome notification
        sendNotification(
            $pdo, $newId,
            'Welcome to the Timetable System',
            "Hello {$name}! Your {$role} account has been created. "
            . "Your registration number is {$reg}. "
            . 'You can now log in and access your timetable.',
            'success'
        );

        ok('User added successfully.');
    }

    // ─── DELETE ───────────────────────────────
    elseif ($method === 'DELETE') {

        $id = (int)($data['id'] ?? 0);
        if (!$id) fail('User ID is required.');

        // We can't notify after deleting — notifications cascade-delete anyway
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

        ok('User deleted successfully.');
    }

    else {
        fail('Method not allowed.');
    }

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
exit();
?>
