<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$data   = json_decode(file_get_contents('php://input'), true);

// ─── GET: fetch availability for a professor ──
if ($method === 'GET') {
    $profId = (int)($_GET['professor_id'] ?? 0);

    if (!$profId) {
        echo json_encode(['success' => false, 'message' => 'Professor ID required.']);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT day, time_start, time_end
        FROM availability
        WHERE professor_id = ?
        ORDER BY FIELD(day,
            'Saturday','Sunday','Monday',
            'Tuesday','Wednesday','Thursday'
        ), time_start
    ");
    $stmt->execute([$profId]);

    echo json_encode(['success' => true, 'slots' => $stmt->fetchAll()]);
    exit();
}

// ─── POST: save availability + notify ─────────
if ($method === 'POST') {
    $profId = (int)($data['professor_id'] ?? 0);
    $slots  = $data['slots'] ?? [];

    if (!$profId) {
        echo json_encode(['success' => false, 'message' => 'Professor ID required.']);
        exit();
    }

    // Verify professor exists
    $check = $pdo->prepare("
        SELECT id, name FROM users
        WHERE id = ? AND role = 'professor' AND is_active = 1
    ");
    $check->execute([$profId]);
    $professor = $check->fetch();

    if (!$professor) {
        echo json_encode(['success' => false, 'message' => 'Professor not found.']);
        exit();
    }

    $validDays = [
        'Saturday','Sunday','Monday',
        'Tuesday','Wednesday','Thursday'
    ];

    try {
        // ─── Replace availability ──────────────
        $pdo->prepare("
            DELETE FROM availability WHERE professor_id = ?
        ")->execute([$profId]);

        $saved = 0;
        if (!empty($slots)) {
            $stmt = $pdo->prepare("
                INSERT INTO availability
                    (professor_id, day, time_start, time_end)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($slots as $slot) {
                $day   = $slot['day']        ?? '';
                $start = $slot['time_start'] ?? '';
                $end   = $slot['time_end']   ?? '';

                if (!in_array($day, $validDays, true)
                    || empty($start)
                    || empty($end)) {
                    continue;
                }

                $stmt->execute([$profId, $day, $start, $end]);
                $saved++;
            }
        }

        // ─── Notify the professor ──────────────
        if ($saved > 0) {
            $slotSummary = $saved . ' time slot' . ($saved !== 1 ? 's' : '');
            sendNotification(
                $pdo,
                $profId,
                'Availability Updated',
                'Your availability has been updated by the administrator. '
                . $slotSummary . ' have been set. '
                . 'Please review your schedule.',
                'info'
            );
        } else {
            sendNotification(
                $pdo,
                $profId,
                'Availability Cleared',
                'Your availability has been cleared by the administrator. '
                . 'You currently have no available slots set. '
                . 'Please contact the admin if this is incorrect.',
                'warning'
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Availability saved and professor notified.',
            'count'   => $saved,
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage(),
        ]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();
?>
