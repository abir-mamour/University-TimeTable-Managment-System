<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireLogin();
$user = currentUser();

try {
    if ($user['role'] === 'admin') {
        $stmt = $pdo->query("
            SELECT
                t.id,
                t.day,
                t.time_start,
                t.time_end,
                t.session_type,
                u.id   AS professor_id,
                u.name AS professor_name,
                s.id   AS subject_id,
                s.name AS subject_name,
                s.code AS subject_code,
                g.id   AS group_id,
                g.group_name,
                g.level,
                r.id   AS room_id,
                r.room_name,
                r.type AS room_type
            FROM timetable t
            JOIN users        u ON t.professor_id = u.id
            JOIN subjects     s ON t.subject_id   = s.id
            JOIN groups_table g ON t.group_id     = g.id
            JOIN rooms        r ON t.room_id      = r.id
            WHERE t.is_active = 1
            ORDER BY
                FIELD(t.day,
                    'Saturday','Sunday','Monday',
                    'Tuesday','Wednesday','Thursday'
                ),
                t.time_start
        ");
        $sessions = $stmt->fetchAll();

    } elseif ($user['role'] === 'professor') {
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.day,
                t.time_start,
                t.time_end,
                t.session_type,
                s.name AS subject_name,
                s.code AS subject_code,
                g.group_name,
                g.level,
                r.room_name,
                r.type AS room_type
            FROM timetable t
            JOIN subjects     s ON t.subject_id = s.id
            JOIN groups_table g ON t.group_id   = g.id
            JOIN rooms        r ON t.room_id    = r.id
            WHERE t.professor_id = ?
              AND t.is_active    = 1
            ORDER BY
                FIELD(t.day,
                    'Saturday','Sunday','Monday',
                    'Tuesday','Wednesday','Thursday'
                ),
                t.time_start
        ");
        $stmt->execute([$user['id']]);
        $sessions = $stmt->fetchAll();

    } else {
        // student
        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.day,
                t.time_start,
                t.time_end,
                t.session_type,
                s.name AS subject_name,
                s.code AS subject_code,
                u.name AS professor_name,
                r.room_name,
                r.type AS room_type
            FROM timetable t
            JOIN subjects       s  ON t.subject_id   = s.id
            JOIN users          u  ON t.professor_id = u.id
            JOIN rooms          r  ON t.room_id      = r.id
            JOIN student_groups sg ON sg.group_id    = t.group_id
            WHERE sg.student_id = ?
              AND t.is_active   = 1
            ORDER BY
                FIELD(t.day,
                    'Saturday','Sunday','Monday',
                    'Tuesday','Wednesday','Thursday'
                ),
                t.time_start
        ");
        $stmt->execute([$user['id']]);
        $sessions = $stmt->fetchAll();
    }

    echo json_encode([
        'success'  => true,
        'sessions' => $sessions,
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
exit();
?>