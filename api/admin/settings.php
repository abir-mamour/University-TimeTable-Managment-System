<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Ensure table + defaults exist
    $settings = loadScheduleSettings($pdo);

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'settings' => $settings]);
        exit();
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $allowed = [
            'break_duration_minutes'   => ['min' => 0,  'max' => 60],
            'session_duration_minutes' => ['min' => 30, 'max' => 180],
            'day_start_time'           => null,
            'lunch_start_time'         => null,
            'lunch_end_time'           => null,
            'day_end_time'             => null,
            'absorb_breaks_into_lunch' => ['min' => 0,  'max' => 1],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO settings (`key`, value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");

        foreach ($allowed as $key => $range) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if ($range !== null) {
                $value = max($range['min'], min($range['max'], (int)$value));
            }

            $stmt->execute([$key, (string)$value]);
        }

        echo json_encode(['success' => true, 'message' => 'Settings saved.']);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
exit();
