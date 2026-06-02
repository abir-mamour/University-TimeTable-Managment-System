<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('professor');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data  = json_decode(file_get_contents('php://input'), true);
$slots = $data['slots'] ?? [];

$validDays = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];

// Ensure change-tracking table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS availability_changes (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        professor_id     INT NOT NULL,
        day              VARCHAR(20) NOT NULL,
        time_start       VARCHAR(10) NOT NULL,
        time_end         VARCHAR(10) NOT NULL,
        change_type      ENUM('added','removed') NOT NULL,
        changed_by       ENUM('admin','professor') NOT NULL,
        changed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        seen_by_admin    TINYINT(1) NOT NULL DEFAULT 0,
        seen_by_professor TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_prof (professor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

try {
    // ─── Snapshot current slots BEFORE saving ─────
    $snap = $pdo->prepare("SELECT day, time_start, time_end FROM availability WHERE professor_id = ?");
    $snap->execute([$user['id']]);
    $oldSlots = $snap->fetchAll(PDO::FETCH_ASSOC);

    // ─── Delete old, insert new ────────────────────
    $pdo->prepare("DELETE FROM availability WHERE professor_id = ?")->execute([$user['id']]);

    $newSlots = [];
    if (!empty($slots)) {
        $stmt = $pdo->prepare("INSERT INTO availability (professor_id, day, time_start, time_end) VALUES (?, ?, ?, ?)");
        foreach ($slots as $slot) {
            $day   = $slot['day']        ?? '';
            $start = $slot['time_start'] ?? '';
            $end   = $slot['time_end']   ?? '';
            if (!in_array($day, $validDays, true) || empty($start) || empty($end)) continue;
            $stmt->execute([$user['id'], $day, $start, $end]);
            $newSlots[] = ['day' => $day, 'time_start' => $start, 'time_end' => $end];
        }
    }

    // ─── Compute diff ──────────────────────────────
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

    // ─── Record changes (replace previous professor records) ──
    if (!empty($added) || !empty($removed)) {
        $pdo->prepare("
            DELETE FROM availability_changes
            WHERE professor_id = ? AND changed_by = 'professor'
        ")->execute([$user['id']]);

        $chg = $pdo->prepare("
            INSERT INTO availability_changes
                (professor_id, day, time_start, time_end, change_type, changed_by, seen_by_professor)
            VALUES (?, ?, ?, ?, ?, 'professor', 1)
        ");
        foreach ($added   as $s) $chg->execute([$user['id'], $s['day'], $s['time_start'], $s['time_end'], 'added']);
        foreach ($removed as $s) $chg->execute([$user['id'], $s['day'], $s['time_start'], $s['time_end'], 'removed']);

        // ─── Notify all admins ─────────────────────
        $total  = count($added) + count($removed);
        $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1")
                      ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            sendNotification(
                $pdo, $adminId,
                'Availability Changed',
                'Prof. ' . $user['name'] . ' updated their availability ('
                    . $total . ' slot' . ($total !== 1 ? 's' : '') . ' changed). Please review.',
                'info'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Availability saved successfully.',
        'count'   => count($newSlots),
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit();
?>
