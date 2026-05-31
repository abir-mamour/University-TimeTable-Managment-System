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
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

function fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}
function ok(string $msg): void {
    echo json_encode(['success' => true, 'message' => $msg]);
    exit();
}

// ─── Conflict check (excludes self on edit) ───
function checkConflicts(
    PDO    $pdo,
    string $day,
    string $start,
    int    $profId,
    int    $groupId,
    int    $roomId,
    ?int   $excludeId = null
): void {
    $ex = $excludeId ? "AND id != {$excludeId}" : '';

    $stmt = $pdo->prepare("
        SELECT id FROM timetable
        WHERE room_id=? AND day=? AND time_start=? AND is_active=1 {$ex}
        LIMIT 1
    ");
    $stmt->execute([$roomId, $day, $start]);
    if ($stmt->fetch()) fail('Room is already booked at this time slot.');

    $stmt = $pdo->prepare("
        SELECT id FROM timetable
        WHERE professor_id=? AND day=? AND time_start=? AND is_active=1 {$ex}
        LIMIT 1
    ");
    $stmt->execute([$profId, $day, $start]);
    if ($stmt->fetch()) fail('Professor already has a class at this time.');

    $stmt = $pdo->prepare("
        SELECT id FROM timetable
        WHERE group_id=? AND day=? AND time_start=? AND is_active=1 {$ex}
        LIMIT 1
    ");
    $stmt->execute([$groupId, $day, $start]);
    if ($stmt->fetch()) fail('This group already has a class at this time.');
}

// ─── Fetch a session with all names joined ────
function fetchSessionFull(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT t.*,
               u.name  AS professor_name,
               s.name  AS subject_name,
               g.group_name,
               r.room_name
        FROM timetable t
        JOIN users        u ON t.professor_id = u.id
        JOIN subjects     s ON t.subject_id   = s.id
        JOIN groups_table g ON t.group_id     = g.id
        JOIN rooms        r ON t.room_id      = r.id
        WHERE t.id = ? AND t.is_active = 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ─── Look up a single name ────────────────────
function lookupName(PDO $pdo, string $table, string $col, int $id): string {
    $stmt = $pdo->prepare("SELECT {$col} FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: '');
}

try {

    // ═══ POST: Add or Update ══════════════════
    if ($method === 'POST') {

        $id      = isset($data['id']) && $data['id'] ? (int)$data['id'] : null;
        $day     = $data['day']          ?? '';
        $type    = $data['session_type'] ?? '';
        $start   = $data['time_start']   ?? '';
        $end     = $data['time_end']     ?? '';
        $profId  = (int)($data['professor_id'] ?? 0);
        $subId   = (int)($data['subject_id']   ?? 0);
        $groupId = (int)($data['group_id']     ?? 0);
        $roomId  = (int)($data['room_id']      ?? 0);

        if (!$day || !$type || !$start || !$end || !$profId || !$subId || !$groupId || !$roomId) {
            fail('All fields are required.');
        }

        $validDays = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];
        if (!in_array($day, $validDays, true)) fail('Invalid day selected.');
        if (!in_array($type, ['lecture','lab','seminar'], true)) fail('Invalid session type.');

        checkConflicts($pdo, $day, $start, $profId, $groupId, $roomId, $id);

        // Resolve names for notification messages
        $subjectName = lookupName($pdo, 'subjects',     'name',       $subId);
        $profName    = lookupName($pdo, 'users',        'name',       $profId);
        $groupName   = lookupName($pdo, 'groups_table', 'group_name', $groupId);
        $roomName    = lookupName($pdo, 'rooms',        'room_name',  $roomId);
        $dayTime     = "{$day} at " . substr($start, 0, 5);

        // ─── UPDATE ───────────────────────────
        if ($id) {

            $old = fetchSessionFull($pdo, $id);
            if (!$old) fail('Session not found.');

            $pdo->prepare("
                UPDATE timetable
                SET professor_id=?, subject_id=?, group_id=?,
                    room_id=?, day=?, time_start=?, time_end=?, session_type=?
                WHERE id=? AND is_active=1
            ")->execute([$profId, $subId, $groupId, $roomId, $day, $start, $end, $type, $id]);

            $oldProfId  = (int)$old['professor_id'];
            $oldGroupId = (int)$old['group_id'];
            $oldDayTime = "{$old['day']} at " . substr($old['time_start'], 0, 5);

            // ── Professor notifications ────────
            if ($oldProfId !== $profId) {
                // Old professor: their session was taken away
                sendNotification(
                    $pdo, $oldProfId,
                    'Session Reassigned',
                    "\"{$old['subject_name']}\" on {$oldDayTime} has been reassigned to another professor.",
                    'warning'
                );
                // New professor: new assignment
                sendNotification(
                    $pdo, $profId,
                    'New Session Assigned',
                    "You have been assigned to teach \"{$subjectName}\" on {$dayTime} in {$roomName}.",
                    'success'
                );
            } else {
                // Same professor: inform of update details
                $details = buildChangeDetails($old, $day, $start, $end, $type, $subjectName, $groupName, $roomName);
                sendNotification(
                    $pdo, $profId,
                    'Session Updated',
                    "Your \"{$subjectName}\" session on {$dayTime} has been updated"
                    . ($details ? ": {$details}." : '.'),
                    'info'
                );
            }

            // ── Group notifications ────────────
            if ($oldGroupId !== $groupId) {
                // Old group: their class is gone
                notifyGroup(
                    $pdo, $oldGroupId,
                    'Class Cancelled',
                    "\"{$old['subject_name']}\" on {$oldDayTime} has been cancelled for your group.",
                    'warning'
                );
                // New group: new class
                notifyGroup(
                    $pdo, $groupId,
                    'New Class Added',
                    "A new class \"{$subjectName}\" has been added on {$dayTime} in {$roomName}.",
                    'success'
                );
            } else {
                // Same group: update message
                notifyGroup(
                    $pdo, $groupId,
                    'Timetable Updated',
                    "\"{$subjectName}\" on {$dayTime} has been updated.",
                    'info'
                );
            }

            ok('Session updated successfully.');
        }

        // ─── INSERT ───────────────────────────
        $pdo->prepare("
            INSERT INTO timetable
                (professor_id, subject_id, group_id,
                 room_id, day, time_start, time_end, session_type, is_active)
            VALUES (?,?,?,?,?,?,?,?,1)
        ")->execute([$profId, $subId, $groupId, $roomId, $day, $start, $end, $type]);

        sendNotification(
            $pdo, $profId,
            'New Session Assigned',
            "You have been assigned to teach \"{$subjectName}\" on {$dayTime} in {$roomName} for group {$groupName}.",
            'success'
        );

        notifyGroup(
            $pdo, $groupId,
            'New Class Added',
            "A new class \"{$subjectName}\" has been scheduled on {$dayTime} in {$roomName}.",
            'success'
        );

        ok('Session added successfully.');
    }

    // ═══ DELETE ═══════════════════════════════
    elseif ($method === 'DELETE') {

        $id = (int)($data['id'] ?? 0);
        if (!$id) fail('Session ID is required.');

        $session = fetchSessionFull($pdo, $id);
        if (!$session) fail('Session not found.');

        $pdo->prepare("DELETE FROM timetable WHERE id = ?")->execute([$id]);

        $dayTime = "{$session['day']} at " . substr($session['time_start'], 0, 5);

        sendNotification(
            $pdo, (int)$session['professor_id'],
            'Session Removed',
            "\"{$session['subject_name']}\" on {$dayTime} has been removed from your schedule.",
            'warning'
        );

        notifyGroup(
            $pdo, (int)$session['group_id'],
            'Class Cancelled',
            "\"{$session['subject_name']}\" on {$dayTime} has been cancelled.",
            'warning'
        );

        ok('Session deleted successfully.');
    }

    else {
        fail('Method not allowed.');
    }

} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        $msg = 'This time slot is already taken.';
        if (str_contains($e->getMessage(), 'no_room_conflict')) {
            $msg = 'This room is already booked at that time.';
        } elseif (str_contains($e->getMessage(), 'no_prof_conflict')) {
            $msg = 'This professor already has a class at that time.';
        } elseif (str_contains($e->getMessage(), 'no_group_conflict')) {
            $msg = 'This group already has a class at that time.';
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
exit();

// ─── Build human-readable change list ─────────
function buildChangeDetails(
    array  $old,
    string $day,
    string $start,
    string $end,
    string $type,
    string $subjectName,
    string $groupName,
    string $roomName
): string {
    $parts = [];
    if ($old['day']          !== $day)                      $parts[] = "day changed to {$day}";
    if (substr($old['time_start'],0,5) !== substr($start,0,5)) $parts[] = "time changed to " . substr($start,0,5);
    if ($old['subject_name'] !== $subjectName)              $parts[] = "subject changed to {$subjectName}";
    if ($old['group_name']   !== $groupName)                $parts[] = "group changed to {$groupName}";
    if ($old['room_name']    !== $roomName)                 $parts[] = "room changed to {$roomName}";
    if ($old['session_type'] !== $type)                     $parts[] = "type changed to {$type}";
    return implode(', ', $parts);
}
?>
