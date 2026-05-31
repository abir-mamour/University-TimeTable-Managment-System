<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireRole('professor');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

$data  = json_decode(file_get_contents('php://input'), true);
$slots = $data['slots'] ?? [];

$validDays = [
    'Saturday','Sunday','Monday',
    'Tuesday','Wednesday','Thursday'
];

try {
    // ─── Delete old availability ───────────────
    $pdo->prepare("
        DELETE FROM availability
        WHERE professor_id = ?
    ")->execute([$user['id']]);

    // ─── Insert new slots ─────────────────────
    if (!empty($slots)) {
        $stmt = $pdo->prepare("
            INSERT INTO availability
                (professor_id, day, time_start, time_end)
            VALUES (?, ?, ?, ?)
        ");

        $saved = 0;
        foreach ($slots as $slot) {
            $day   = $slot['day']        ?? '';
            $start = $slot['time_start'] ?? '';
            $end   = $slot['time_end']   ?? '';

            if (!in_array($day, $validDays, true)
                || empty($start)
                || empty($end)) {
                continue;
            }

            $stmt->execute([
                $user['id'],
                $day,
                $start,
                $end
            ]);
            $saved++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Availability saved successfully.',
        'count'   => $saved ?? 0
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
exit();
?>