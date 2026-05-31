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
?>