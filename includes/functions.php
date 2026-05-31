<?php
// ─── Send JSON Response ───────────────────────
function jsonResponse(
    bool $success,
    string $message,
    array $data = []
): void {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ]);
    exit();
}

// ─── Send Notification ────────────────────────
function sendNotification(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $type = 'info'
): void {
    $stmt = $pdo->prepare("
        INSERT INTO notifications
            (user_id, title, message, type)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $title, $message, $type]);
}

// ─── Notify whole group ───────────────────────
function notifyGroup(
    PDO $pdo,
    int $groupId,
    string $title,
    string $message,
    string $type = 'info'
): void {
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE id IN (
            SELECT student_id
            FROM student_groups
            WHERE group_id = ?
        )
    ");
    $stmt->execute([$groupId]);
    $students = $stmt->fetchAll();

    foreach ($students as $student) {
        sendNotification(
            $pdo,
            $student['id'],
            $title,
            $message,
            $type
        );
    }
}

// ─── Sanitize input ───────────────────────────
function clean(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)));
}

// ─── Redirect ─────────────────────────────────
function redirect(string $path): void {
    header('Location: ' . BASE_URL . $path);
    exit();
}

// ─── Schedule settings ────────────────────────
// Auto-creates the settings table if missing,
// then returns merged defaults + stored values.
function loadScheduleSettings(PDO $pdo): array
{
    $defaults = [
        'break_duration_minutes'    => 30,
        'session_duration_minutes'  => 90,
        'day_start_time'            => '08:00:00',
        'lunch_start_time'          => '12:00:00',
        'lunch_end_time'            => '14:00:00',
        'day_end_time'              => '18:00:00',
        'absorb_breaks_into_lunch'  => 0,
    ];

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                `key`  VARCHAR(100) PRIMARY KEY,
                value  VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        foreach ($defaults as $k => $v) {
            $pdo->prepare("
                INSERT IGNORE INTO settings (`key`, value)
                VALUES (?, ?)
            ")->execute([$k, (string)$v]);
        }

        $rows = $pdo->query("
            SELECT `key`, value FROM settings
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_merge($defaults, $rows);

    } catch (\PDOException $e) {
        return $defaults;
    }
}

// ─── Time-slot computation ────────────────────
// Given schedule settings, returns an array of
// [start, end, label] slot definitions.
// Sessions that overlap the lunch window are
// skipped; after lunch the break is reapplied
// once before the first afternoon slot.
function computeTimeSlots(array $s): array
{
    $sessionSec = (int)$s['session_duration_minutes'] * 60;
    $breakSec   = (int)$s['break_duration_minutes']   * 60;
    $lunchStart = strtotime($s['lunch_start_time']);
    $lunchEnd   = strtotime($s['lunch_end_time']);
    $dayEnd     = strtotime($s['day_end_time']);
    $current    = strtotime($s['day_start_time']);

    $slots = [];
    while (true) {
        $sessionEnd = $current + $sessionSec;
        if ($sessionEnd > $dayEnd) {
            break;
        }

        $overlapsLunch = ($current < $lunchEnd && $sessionEnd > $lunchStart);

        if (!$overlapsLunch) {
            $slots[] = [
                'start' => date('H:i:s', $current),
                'end'   => date('H:i:s', $sessionEnd),
                'label' => date('H:i',   $current)
                         . ' - '
                         . date('H:i',   $sessionEnd),
            ];
            $next = $sessionEnd + $breakSec;
        } else {
            // Lunch cancels the adjacent breaks — resume directly at
            // lunch end, no extra break gap added on either side.
            $next = $lunchEnd;
        }

        if ($next >= $dayEnd) {
            break;
        }
        $current = $next;
    }

    return $slots;
}
?>