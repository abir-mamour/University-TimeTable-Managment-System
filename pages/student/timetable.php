<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('student');
$user = currentUser();

$activePage   = 'timetable';
$pageTitle    = 'My Timetable';
$pageSubtitle = 'View your weekly schedule, including lessons, TP, and TD sessions.';

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
$group = $studentGroup;

// ─── Get full timetable ───────────────────────
$stmt = $pdo->prepare("
    SELECT 
        t.*,
        s.name AS subject_name,
        u.name AS professor_name,
        r.room_name,
        t.session_type
    FROM timetable t
    JOIN subjects       s  ON t.subject_id   = s.id
    JOIN users          u  ON t.professor_id = u.id
    JOIN rooms          r  ON t.room_id      = r.id
    JOIN student_groups sg ON sg.group_id    = t.group_id
    WHERE sg.student_id = ? 
      AND t.is_active   = 1
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

function badgeClass($type) {
    return match($type) {
        'lecture' => ['badge-lesson', 'Lesson'],
        'lab'     => ['badge-tp',     'TP'],
        'seminar' => ['badge-td',     'TD'],
        default   => ['badge-lesson', 'Lesson'],
    };
}

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Filter Tabs ───────────────────────────── -->
<div class="timetable-filters">
    <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">
            <i class="fa-solid fa-table-cells"></i>
            All (Lessons + TP + TD)
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
                        <th><?= $day ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $slot): ?>
                <tr>
                    <td class="time-cell">
                        <?= $slot ?>
                    </td>
                    <?php foreach ($days as $day): ?>
                        <td>
                            <?php if (isset($grid[$day][$slot])):
                                $c = $grid[$day][$slot];
                                [$bClass, $bLabel] = badgeClass(
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
                                        <?= htmlspecialchars($c['professor_name']) ?>
                                        <br>
                                        <?= htmlspecialchars($c['room_name']) ?>
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
    <div style="padding:16px 20px;
                border-top:1px solid var(--color-border);">
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
        <div class="timetable-note">
            <i class="fa-solid fa-circle-info"></i>
            TP and TD sessions are in addition to your regular lessons.
        </div>
    </div>
</div>

<!-- ─── Filter Script ────────────────────────── -->
<script>
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {

        // Remove active from all tabs
        document.querySelectorAll('.filter-tab')
            .forEach(t => t.classList.remove('active'));

        // Set active on clicked
        this.classList.add('active');

        const filter = this.dataset.filter;

        // Show/hide cells
        document.querySelectorAll('.class-cell').forEach(cell => {
            const td = cell.closest('td');
            if (filter === 'all' || cell.dataset.type === filter) {
                td.style.opacity        = '1';
                td.style.pointerEvents  = 'auto';
            } else {
                td.style.opacity        = '0.2';
                td.style.pointerEvents  = 'none';
            }
        });
    });
});
</script>

<?php
// ─── Load footer ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>