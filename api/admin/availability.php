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

// Ensure change-tracking table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS availability_changes (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        professor_id      INT NOT NULL,
        day               VARCHAR(20) NOT NULL,
        time_start        VARCHAR(10) NOT NULL,
        time_end          VARCHAR(10) NOT NULL,
        change_type       ENUM('added','removed') NOT NULL,
        changed_by        ENUM('admin','professor') NOT NULL,
        changed_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        seen_by_admin     TINYINT(1) NOT NULL DEFAULT 0,
        seen_by_professor TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_prof (professor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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
        ORDER BY FIELD(day,'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'),
                 time_start
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

    $check = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'professor' AND is_active = 1");
    $check->execute([$profId]);
    $professor = $check->fetch();

    if (!$professor) {
        echo json_encode(['success' => false, 'message' => 'Professor not found.']);
        exit();
    }

    $validDays = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];

    try {
        // ─── Snapshot current slots BEFORE saving ─
        $snap = $pdo->prepare("SELECT day, time_start, time_end FROM availability WHERE professor_id = ?");
        $snap->execute([$profId]);
        $oldSlots = $snap->fetchAll(PDO::FETCH_ASSOC);

        // ─── Replace availability ──────────────────
        $pdo->prepare("DELETE FROM availability WHERE professor_id = ?")->execute([$profId]);

        $newSlots = [];
        if (!empty($slots)) {
            $stmt = $pdo->prepare("INSERT INTO availability (professor_id, day, time_start, time_end) VALUES (?, ?, ?, ?)");
            foreach ($slots as $slot) {
                $day   = $slot['day']        ?? '';
                $start = $slot['time_start'] ?? '';
                $end   = $slot['time_end']   ?? '';
                if (!in_array($day, $validDays, true) || empty($start) || empty($end)) continue;
                $stmt->execute([$profId, $day, $start, $end]);
                $newSlots[] = ['day' => $day, 'time_start' => $start, 'time_end' => $end];
            }
        }
        $saved = count($newSlots);

        // ─── Compute diff ──────────────────────────
        $oldSet = [];
        foreach ($oldSlots as $s) {
            $oldSet[$s['day'] . '|' . substr($s['time_start'], 0, 8)] = $s;
        }
        $newSet = [];
        foreach ($newSlots as $s) {
            $newSet[$s['day'] . '|' . substr($s['time_start'], 0, 8)] = $s;
        }

        $added   = [];
        $removed = [];
        foreach ($newSet as $k => $s) {
            if (!isset($oldSet[$k])) $added[] = $s;
        }
        foreach ($oldSet as $k => $s) {
            if (!isset($newSet[$k])) $removed[] = $s;
        }

        // ─── Record changes (replace previous admin records) ──
        if (!empty($added) || !empty($removed)) {
            $pdo->prepare("
                DELETE FROM availability_changes
                WHERE professor_id = ? AND changed_by = 'admin'
            ")->execute([$profId]);

            $chg = $pdo->prepare("
                INSERT INTO availability_changes
                    (professor_id, day, time_start, time_end, change_type, changed_by, seen_by_admin)
                VALUES (?, ?, ?, ?, ?, 'admin', 1)
            ");
            foreach ($added   as $s) $chg->execute([$profId, $s['day'], $s['time_start'], $s['time_end'], 'added']);
            foreach ($removed as $s) $chg->execute([$profId, $s['day'], $s['time_start'], $s['time_end'], 'removed']);
        }

        // ─── Notify the professor ──────────────────
        if ($saved > 0) {
            $slotSummary = $saved . ' time slot' . ($saved !== 1 ? 's' : '');
            sendNotification($pdo, $profId,
                'Availability Updated',
                'Your availability has been updated by the administrator. '
                    . $slotSummary . ' have been set. Please review your schedule.',
                'info'
            );
        } else {
            sendNotification($pdo, $profId,
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
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();
?>
