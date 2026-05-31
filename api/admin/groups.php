<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');

$data = json_decode(file_get_contents('php://input'), true) ?? [];

function fail(string $msg): void {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit();
}
function ok(string $msg): void {
    echo json_encode(['success' => true, 'message' => $msg]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.');
}

$action = $data['action'] ?? '';

// ─── Assign: place a student into a group (replaces current group) ──
if ($action === 'assign') {
    $studentId = (int)($data['student_id'] ?? 0);
    $groupId   = (int)($data['group_id']   ?? 0);
    if (!$studentId || !$groupId) fail('Missing student or group.');

    // Verify student exists
    $s = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student' AND is_active = 1");
    $s->execute([$studentId]);
    $student = $s->fetch();
    if (!$student) fail('Student not found.');

    // Check capacity
    $capQ = $pdo->prepare("SELECT group_name, capacity FROM groups_table WHERE id = ?");
    $capQ->execute([$groupId]);
    $group = $capQ->fetch();
    if (!$group) fail('Group not found.');

    $cntQ = $pdo->prepare("SELECT COUNT(*) FROM student_groups WHERE group_id = ?");
    $cntQ->execute([$groupId]);
    $current = (int)$cntQ->fetchColumn();

    // Check if student is already in this group (don't count against capacity)
    $alreadyQ = $pdo->prepare("SELECT COUNT(*) FROM student_groups WHERE student_id = ? AND group_id = ?");
    $alreadyQ->execute([$studentId, $groupId]);
    $alreadyIn = (bool)$alreadyQ->fetchColumn();

    if (!$alreadyIn && $current >= (int)$group['capacity']) {
        fail("Group \"{$group['group_name']}\" is at full capacity ({$group['capacity']}).");
    }

    // Remove from any current group, then assign
    $pdo->prepare("DELETE FROM student_groups WHERE student_id = ?")->execute([$studentId]);
    $pdo->prepare("INSERT INTO student_groups (student_id, group_id) VALUES (?,?)")
        ->execute([$studentId, $groupId]);

    sendNotification(
        $pdo, $studentId,
        'Group Assignment',
        "You have been assigned to \"{$group['group_name']}\" by the administrator.",
        'info'
    );
    ok("Student assigned to \"{$group['group_name']}\".");
}

// ─── Move: change a student's group ─────────────────────────────────
if ($action === 'move') {
    $studentId = (int)($data['student_id']  ?? 0);
    $toGroupId = (int)($data['to_group_id'] ?? 0);
    if (!$studentId || !$toGroupId) fail('Missing parameters.');

    $s = $pdo->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
    $s->execute([$studentId]);
    if (!$s->fetch()) fail('Student not found.');

    $capQ = $pdo->prepare("SELECT group_name, capacity FROM groups_table WHERE id = ?");
    $capQ->execute([$toGroupId]);
    $group = $capQ->fetch();
    if (!$group) fail('Target group not found.');

    $cntQ = $pdo->prepare("SELECT COUNT(*) FROM student_groups WHERE group_id = ?");
    $cntQ->execute([$toGroupId]);
    if ((int)$cntQ->fetchColumn() >= (int)$group['capacity']) {
        fail("Target group \"{$group['group_name']}\" is at full capacity.");
    }

    $pdo->prepare("DELETE FROM student_groups WHERE student_id = ?")->execute([$studentId]);
    $pdo->prepare("INSERT INTO student_groups (student_id, group_id) VALUES (?,?)")
        ->execute([$studentId, $toGroupId]);

    sendNotification(
        $pdo, $studentId,
        'Group Changed',
        "You have been moved to \"{$group['group_name']}\" by the administrator.",
        'info'
    );
    ok("Student moved to \"{$group['group_name']}\".");
}

// ─── Switch: swap two students between their groups ──────────────────
if ($action === 'switch') {
    $s1 = (int)($data['student1_id'] ?? 0);
    $s2 = (int)($data['student2_id'] ?? 0);
    if (!$s1 || !$s2)   fail('Two student IDs are required.');
    if ($s1 === $s2)     fail('Select two different students.');

    $q = $pdo->prepare("SELECT group_id FROM student_groups WHERE student_id = ?");
    $q->execute([$s1]); $g1 = $q->fetchColumn();
    $q->execute([$s2]); $g2 = $q->fetchColumn();

    if (!$g1 || !$g2) fail('Both students must be in a group to switch.');
    if ($g1 === $g2)  fail('Students are already in the same group.');

    $pdo->prepare("UPDATE student_groups SET group_id = ? WHERE student_id = ?")->execute([$g2, $s1]);
    $pdo->prepare("UPDATE student_groups SET group_id = ? WHERE student_id = ?")->execute([$g1, $s2]);

    sendNotification($pdo, $s1, 'Group Switched', 'Your group has been switched by the administrator.', 'info');
    sendNotification($pdo, $s2, 'Group Switched', 'Your group has been switched by the administrator.', 'info');
    ok('Students switched successfully.');
}

// ─── Remove: unassign a student from their group ─────────────────────
if ($action === 'remove') {
    $studentId = (int)($data['student_id'] ?? 0);
    if (!$studentId) fail('Missing student ID.');

    $pdo->prepare("DELETE FROM student_groups WHERE student_id = ?")->execute([$studentId]);
    sendNotification(
        $pdo, $studentId,
        'Removed from Group',
        'You have been removed from your group by the administrator.',
        'warning'
    );
    ok('Student removed from group.');
}

fail('Unknown action.');
?>
