<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
$user = currentUser();

$activePage   = 'dashboard';
$pageTitle    = 'Professor Dashboard';
$pageSubtitle = 'Welcome back, ' . $user['name'] . '!';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Professor department ─────────────────────
$stmt = $pdo->prepare("
    SELECT d.name as department
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.id = ?
");
$stmt->execute([$user['id']]);
$profInfo = $stmt->fetch();

// ─── Pending requests ─────────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM requests
    WHERE professor_id = ? AND status = 'pending'
");
$stmt->execute([$user['id']]);
$pendingRequests = $stmt->fetchColumn();

// ─── Today's classes ──────────────────────────
$today = date('l');
$now   = strtotime(date('H:i'));

$stmt = $pdo->prepare("
    SELECT
        t.*,
        s.name AS subject_name,
        g.group_name,
        r.room_name,
        t.session_type
    FROM timetable t
    JOIN subjects     s ON t.subject_id = s.id
    JOIN groups_table g ON t.group_id   = g.id
    JOIN rooms        r ON t.room_id    = r.id
    WHERE t.professor_id = ?
      AND t.day          = ?
      AND t.is_active    = 1
    ORDER BY t.time_start
");
$stmt->execute([$user['id'], $today]);
$todayClasses = $stmt->fetchAll();

// ─── Latest notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 3
");
$stmt->execute([$user['id']]);
$latestNotifs = $stmt->fetchAll();

// ─── Status helper ────────────────────────────
function getStatus($start, $end) {
    $now = strtotime(date('H:i'));
    $s   = strtotime($start);
    $e   = strtotime($end);
    if ($now >= $s && $now <= $e)
        return ['In Progress', 'status-progress', 'divider-mint'];
    if ($now < $s && ($s - $now) <= 7200)
        return ['Up Next',     'status-next',     'divider-sage'];
    return     ['Upcoming',    'status-upcoming',  'divider-rose'];
}

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row">

    <!-- 1. Today's Classes -->
    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-calendar-day"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Today's Classes</p>
            <div class="stat-value"><?= count($todayClasses) ?></div>
            <?php
            $nextClass = null;
            foreach ($todayClasses as $c) {
                if (strtotime($c['time_start']) > $now) {
                    $nextClass = $c;
                    break;
                }
            }
            ?>
            <?php if ($nextClass): ?>
                <p class="stat-sub">
                    Next class in
                    <?= gmdate('G\h i\m',
                        strtotime($nextClass['time_start']) - $now
                    ) ?>
                </p>
            <?php else: ?>
                <p class="stat-sub">No upcoming classes today</p>
            <?php endif; ?>
        </div>
        <a href="/TimeTable/pages/professor/schedule.php"
           class="stat-arrow">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>

    <!-- 2. Pending Requests -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Pending Requests</p>
            <div class="stat-value"><?= $pendingRequests ?></div>
            <p class="stat-sub">Awaiting admin response</p>
        </div>
        <a href="/TimeTable/pages/professor/requests.php"
           class="stat-arrow">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>

    <!-- 3. Latest Updates -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-bell"></i>
        </div>
        <div class="stat-body">
            <div style="display:flex;
                        align-items:center;
                        gap:8px;
                        margin-bottom:10px;">
                <p class="stat-label" style="margin:0;">
                    Latest Updates
                </p>
                <?php if ($unreadCount > 0): ?>
                    <span class="updates-badge">
                        <?= $unreadCount ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (empty($latestNotifs)): ?>
                <p class="stat-sub">No new updates</p>
            <?php else: ?>
                <?php foreach ($latestNotifs as $n): ?>
                    <div class="update-item">
                        <i class="fa-solid fa-circle-dot"></i>
                        <?= htmlspecialchars($n['title']) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <a href="/TimeTable/pages/professor/notifications.php"
               class="view-all-link">
                View all updates
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

</div>

<!-- ─── Today's Schedule ──────────────────────── -->
<div class="card">
    <div class="section-header">
        <div class="section-title">
            <i class="fa-solid fa-calendar-days"></i>
            Today's Schedule
        </div>
        <span class="section-date">
            <?= date('l, d F Y') ?>
        </span>
    </div>

    <?php if (empty($todayClasses)): ?>
        <div style="text-align:center;
                    padding:40px;
                    color:var(--color-text-light);">
            <i class="fa-solid fa-calendar-xmark"
               style="font-size:40px;
                      display:block;
                      margin-bottom:12px;
                      opacity:0.4;">
            </i>
            <p>No classes scheduled for today.</p>
        </div>
    <?php else: ?>
        <div class="schedule-list">
            <?php foreach ($todayClasses as $class):
                [$statusLabel, $statusClass, $divClass] =
                    getStatus(
                        $class['time_start'],
                        $class['time_end']
                    );
                $sessionLabel = match($class['session_type']) {
                    'lecture' => 'Lesson',
                    'lab'     => 'TP',
                    'seminar' => 'TD',
                    default   => 'Class'
                };
            ?>
                <div class="schedule-item">

                    <!-- Time -->
                    <div class="schedule-time">
                        <div class="time-start">
                            <?= date('H:i',
                                strtotime($class['time_start'])
                            ) ?>
                        </div>
                        <div class="time-dash">—</div>
                        <div class="time-end">
                            <?= date('H:i',
                                strtotime($class['time_end'])
                            ) ?>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="schedule-divider <?= $divClass ?>">
                    </div>

                    <!-- Info -->
                    <div class="schedule-info">
                        <div class="schedule-subject">
                            <?= htmlspecialchars($class['subject_name']) ?>
                            <span style="
                                font-size:11px;
                                font-weight:600;
                                padding:2px 7px;
                                border-radius:4px;
                                margin-left:6px;
                                background:var(--color-sage);
                                color:var(--color-text-mid);">
                                <?= $sessionLabel ?>
                            </span>
                        </div>
                        <div class="schedule-meta">
                            <span class="meta-item">
                                <i class="fa-solid fa-users"></i>
                                <?= htmlspecialchars($class['group_name']) ?>
                            </span>
                            <span class="meta-item">
                                <i class="fa-solid fa-location-dot"></i>
                                <?= htmlspecialchars($class['room_name']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Status -->
                    <span class="schedule-status <?= $statusClass ?>">
                        <?= $statusLabel ?>
                    </span>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- View Full Schedule -->
    <a href="/TimeTable/pages/professor/schedule.php"
       class="view-full-bar"
       style="margin-top:16px;">
        <span>View Full Schedule</span>
        <i class="fa-solid fa-arrow-right"></i>
    </a>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>