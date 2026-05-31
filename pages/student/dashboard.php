<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('student');
$user = currentUser();

$activePage   = 'dashboard';
$pageTitle    = 'Student Dashboard';
$pageSubtitle = 'Welcome back, ' . $user['name'] . '!';

// ─── Unread notifications count ───────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Student group ────────────────────────────
$stmt = $pdo->prepare("
    SELECT g.group_name, g.level, d.name as department
    FROM student_groups sg
    JOIN groups_table g ON sg.group_id = g.id
    JOIN departments d  ON g.department_id = d.id
    WHERE sg.student_id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$studentGroup = $stmt->fetch();

// ─── Today's classes ──────────────────────────
$today = date('l');
$now   = strtotime(date('H:i'));

$stmt = $pdo->prepare("
    SELECT 
        t.*,
        s.name AS subject_name,
        u.name AS professor_name,
        r.room_name
    FROM timetable t
    JOIN subjects       s  ON t.subject_id   = s.id
    JOIN users          u  ON t.professor_id = u.id
    JOIN rooms          r  ON t.room_id      = r.id
    JOIN student_groups sg ON sg.group_id    = t.group_id
    WHERE sg.student_id = ? 
      AND t.day         = ? 
      AND t.is_active   = 1
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
<div class="stats-row stats-row-2">

    <!-- Today's Classes Card -->
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
        <a href="/TimeTable/pages/student/timetable.php"
           class="stat-arrow">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>

    <!-- Latest Updates Card -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-bell"></i>
        </div>
        <div class="stat-body">
            <div style="display:flex; align-items:center;
                        gap:8px; margin-bottom:10px;">
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

            <a href="/TimeTable/pages/student/notifications.php"
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
        <div style="text-align:center; padding:40px;
                    color:var(--color-text-light);">
            <i class="fa-solid fa-calendar-xmark"
               style="font-size:40px;
                      margin-bottom:12px;
                      display:block;
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

                    <!-- Color divider -->
                    <div class="schedule-divider <?= $divClass ?>">
                    </div>

                    <!-- Info -->
                    <div class="schedule-info">
                        <div class="schedule-subject">
                            <?= htmlspecialchars($class['subject_name']) ?>
                        </div>
                        <div class="schedule-meta">
                            <span class="meta-item">
                                <i class="fa-solid fa-chalkboard-user"></i>
                                <?= htmlspecialchars($class['professor_name']) ?>
                            </span>
                            <span class="meta-item">
                                <i class="fa-solid fa-location-dot"></i>
                                <?= htmlspecialchars($class['room_name']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Status badge -->
                    <span class="schedule-status <?= $statusClass ?>">
                        <?= $statusLabel ?>
                    </span>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- View Full Timetable -->
    <a href="/TimeTable/pages/student/timetable.php"
       class="view-full-bar"
       style="margin-top:16px;">
        <span>View Full Timetable</span>
        <i class="fa-solid fa-arrow-right"></i>
    </a>
</div>

<?php
// ─── Load footer ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>