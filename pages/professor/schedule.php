<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
$user = currentUser();

$activePage   = 'schedule';
$pageTitle    = 'My Schedule';
$pageSubtitle = 'View your weekly teaching schedule.';

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

// ─── Get full schedule ────────────────────────
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
      AND t.is_active    = 1
    ORDER BY FIELD(t.day,
    'Saturday','Sunday','Monday',
    'Tuesday','Wednesday','Thursday'
), t.time_start
");
$stmt->execute([$user['id']]);
$allClasses = $stmt->fetchAll();

// ─── Build grid ───────────────────────────────
$days  = [
    'Saturday',
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
];
$slots = [
    '08:00 - 09:30',
    '10:00 - 11:30',
    '13:00 - 14:30',
    '14:30 - 16:00',
    '16:00 - 17:30'
];

$grid = [];
foreach ($allClasses as $c) {
    $timeKey = date('H:i', strtotime($c['time_start']))
             . ' - '
             . date('H:i', strtotime($c['time_end']));
    $grid[$c['day']][$timeKey] = $c;
}

// ─── Helpers ──────────────────────────────────
function cellClass($type) {
    return match($type) {
        'lecture' => 'cell-lesson',
        'lab'     => 'cell-tp',
        'seminar' => 'cell-td',
        default   => 'cell-lesson',
    };
}

function badgeInfo($type) {
    return match($type) {
        'lecture' => ['badge-lesson', 'Lesson'],
        'lab'     => ['badge-tp',     'TP'],
        'seminar' => ['badge-td',     'TD'],
        default   => ['badge-lesson', 'Lesson'],
    };
}

$today = date('l');

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Filter Tabs ───────────────────────────── -->
<div class="timetable-filters">
    <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">
            <i class="fa-solid fa-table-cells"></i>
            All Sessions
        </button>
        <button class="filter-tab" data-filter="lecture">
            <i class="fa-solid fa-chalkboard"></i>
            Lessons Only
        </button>
        <button class="filter-tab" data-filter="lab">
            <i class="fa-solid fa-flask"></i>
            TP Only
        </button>
        <button class="filter-tab" data-filter="seminar">
            <i class="fa-solid fa-users"></i>
            TD Only
        </button>
    </div>
    <div class="week-selector">
        <i class="fa-solid fa-calendar-week"></i>
        This Week
        <i class="fa-solid fa-chevron-down"></i>
    </div>
</div>

<!-- ─── Timetable Grid ───────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">
    <div class="timetable-scroll">
        <table class="timetable-grid">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php foreach ($days as $day): ?>
                        <th style="<?= $day === $today
                            ? 'background:var(--color-mint);
                               color:var(--color-text-dark);'
                            : '' ?>">
                            <?= $day ?>
                            <?php if ($day === $today): ?>
                                <span style="
                                    display:block;
                                    font-size:10px;
                                    font-weight:500;
                                    color:var(--color-text-mid);
                                    margin-top:2px;">
                                    Today
                                </span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $slot): ?>
                <tr>
                    <td class="time-cell"><?= $slot ?></td>
                    <?php foreach ($days as $day): ?>
                        <td style="<?= $day === $today
                            ? 'background:rgba(192,225,210,0.10);'
                            : '' ?>">
                            <?php if (isset($grid[$day][$slot])):
                                $c = $grid[$day][$slot];
                                [$bClass, $bLabel] = badgeInfo(
                                    $c['session_type']
                                );
                            ?>
                                <div class="class-cell <?= cellClass($c['session_type']) ?>"
                                     data-type="<?= $c['session_type'] ?>">
                                    <span class="cell-type-badge <?= $bClass ?>">
                                        <?= $bLabel ?>
                                    </span>
                                    <div class="cell-subject">
                                        <?= htmlspecialchars($c['subject_name']) ?>
                                    </div>
                                    <div class="cell-meta">
                                        <span>
                                            <i class="fa-solid fa-users"
                                               style="font-size:10px;">
                                            </i>
                                            <?= htmlspecialchars($c['group_name']) ?>
                                        </span>
                                        <br>
                                        <span>
                                            <i class="fa-solid fa-location-dot"
                                               style="font-size:10px;">
                                            </i>
                                            <?= htmlspecialchars($c['room_name']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="empty-cell">—</div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ─── Legend ───────────────────────────── -->
    <div style="
        padding:16px 20px;
        border-top:1px solid var(--color-border);
        display:flex;
        align-items:center;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:12px;">

        <div class="timetable-legend">
            <div class="legend-item">
                <span class="legend-dot legend-lesson"></span>
                Lesson (Course)
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-tp"></span>
                TP (Tutorial Practice)
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-td"></span>
                TD (Tutorial Directed)
            </div>
        </div>

        <div style="
            font-size:13px;
            color:var(--color-text-light);
            display:flex;
            align-items:center;
            gap:6px;">
            <i class="fa-solid fa-circle-info"
               style="color:var(--color-mint-dark);">
            </i>
            Total sessions this week:
            <strong style="color:var(--color-text-dark);">
                <?= count($allClasses) ?>
            </strong>
        </div>

    </div>
</div>

<!-- ─── Filter Script ────────────────────────── -->
<script>
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.filter-tab')
            .forEach(t => t.classList.remove('active'));
        this.classList.add('active');

        const filter = this.dataset.filter;

        document.querySelectorAll('.class-cell').forEach(cell => {
            const td = cell.closest('td');
            if (filter === 'all' || cell.dataset.type === filter) {
                td.style.opacity       = '1';
                td.style.pointerEvents = 'auto';
            } else {
                td.style.opacity       = '0.2';
                td.style.pointerEvents = 'none';
            }
        });
    });
});
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>