<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('student');
$user = currentUser();

$activePage   = 'timetable';
$pageTitle    = 'My Timetable';
$pageSubtitle = 'View your weekly schedule, including lessons, TP, and TD sessions.';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Student group ────────────────────────────
$stmt = $pdo->prepare("
    SELECT g.id AS group_id, g.group_name, g.level, d.name AS department
    FROM student_groups sg
    JOIN groups_table g ON sg.group_id     = g.id
    JOIN departments  d ON g.department_id = d.id
    WHERE sg.student_id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$studentGroup = $stmt->fetch(PDO::FETCH_ASSOC);

// ─── Sessions for this student ────────────────
$stmt = $pdo->prepare("
    SELECT
        t.day, t.time_start, t.time_end, t.session_type,
        s.name AS subject_name,
        u.name AS professor_name,
        r.room_name
    FROM timetable t
    JOIN subjects       s  ON t.subject_id  = s.id
    JOIN users          u  ON t.professor_id = u.id
    JOIN rooms          r  ON t.room_id      = r.id
    JOIN student_groups sg ON sg.group_id    = t.group_id
    WHERE sg.student_id = ? AND t.is_active = 1
    ORDER BY
        FIELD(t.day,'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'),
        t.time_start
");
$stmt->execute([$user['id']]);
$allSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Build slots from schedule settings ───────
$scheduleSettings = loadScheduleSettings($pdo);
$slots            = computeTimeSlots($scheduleSettings);

// Include any DB time_starts not in the computed set
$existingStarts = array_unique(array_column($allSessions, 'time_start'));
$slotStarts     = array_column($slots, 'start');
foreach ($existingStarts as $ts) {
    if (!in_array($ts, $slotStarts)) {
        $startEpoch = strtotime($ts);
        $sessionSec = (int)$scheduleSettings['session_duration_minutes'] * 60;
        $slots[] = [
            'start' => $ts,
            'end'   => date('H:i:s', $startEpoch + $sessionSec),
            'label' => date('H:i', $startEpoch) . ' - ' . date('H:i', $startEpoch + $sessionSec),
        ];
    }
}
usort($slots, fn($a, $b) => strcmp($a['start'], $b['start']));

// ─── Build schedule items (slots + breaks + lunch) ─
$lunchStartTs  = strtotime($scheduleSettings['lunch_start_time']);
$lunchEndTs    = strtotime($scheduleSettings['lunch_end_time']);
$scheduleItems = [];

for ($i = 0; $i < count($slots); $i++) {
    $scheduleItems[] = ['type' => 'slot', 'data' => $slots[$i]];

    if ($i >= count($slots) - 1) {
        continue;
    }

    $gapStartTs    = strtotime($slots[$i]['end']);
    $gapEndTs      = strtotime($slots[$i + 1]['start']);

    if ($gapEndTs <= $gapStartTs) {
        continue;
    }

    $overlapsLunch = ($gapStartTs < $lunchEndTs && $gapEndTs > $lunchStartTs);

    if ($overlapsLunch) {
        if ($gapStartTs < $lunchStartTs) {
            $preMins = (int)(($lunchStartTs - $gapStartTs) / 60);
            if ($preMins > 0) {
                $scheduleItems[] = [
                    'type' => 'break',
                    'from' => date('H:i', $gapStartTs),
                    'to'   => date('H:i', $lunchStartTs),
                    'mins' => $preMins,
                ];
            }
        }

        $lFrom = max($gapStartTs, $lunchStartTs);
        $lTo   = min($gapEndTs,   $lunchEndTs);
        $scheduleItems[] = [
            'type' => 'lunch',
            'from' => date('H:i', $lFrom),
            'to'   => date('H:i', $lTo),
            'mins' => (int)(($lTo - $lFrom) / 60),
        ];

        if ($gapEndTs > $lunchEndTs) {
            $postMins = (int)(($gapEndTs - $lunchEndTs) / 60);
            if ($postMins > 0) {
                $scheduleItems[] = [
                    'type' => 'break',
                    'from' => date('H:i', $lunchEndTs),
                    'to'   => date('H:i', $gapEndTs),
                    'mins' => $postMins,
                ];
            }
        }
    } else {
        $mins = (int)(($gapEndTs - $gapStartTs) / 60);
        if ($mins > 0) {
            $scheduleItems[] = [
                'type' => 'break',
                'from' => date('H:i', $gapStartTs),
                'to'   => date('H:i', $gapEndTs),
                'mins' => $mins,
            ];
        }
    }
}

// ─── Grid indexed by [day][time_start] ────────
$grid = [];
foreach ($allSessions as $s) {
    $grid[$s['day']][$s['time_start']][] = $s;
}

$days  = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
$today = date('l');

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

<!-- ─── Timetable ────────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">
    <div class="timetable-scroll">
        <table class="timetable-grid">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php foreach ($days as $day): ?>
                        <th style="<?= $day === $today
                            ? 'background:var(--color-mint); color:var(--color-text-dark);'
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
                <?php foreach ($scheduleItems as $item): ?>

                <?php if ($item['type'] === 'break'): ?>
                <!-- ── Break row ───────────── -->
                <tr style="height:28px;">
                    <td colspan="<?= 1 + count($days) ?>"
                        style="
                            padding:0 16px;
                            background:repeating-linear-gradient(
                                45deg,
                                #f8f9fa, #f8f9fa 4px,
                                #f1f3f5  4px, #f1f3f5 8px
                            );
                            border-top:1px dashed #ced4da;
                            border-bottom:1px dashed #ced4da;
                            text-align:center;">
                        <span style="
                            font-size:11px; font-weight:600; color:#9ca3af;
                            display:flex; align-items:center;
                            justify-content:center; gap:6px; white-space:nowrap;">
                            <i class="fa-solid fa-mug-hot" style="font-size:10px;"></i>
                            Break &nbsp;·&nbsp; <?= $item['mins'] ?> min
                            &nbsp;
                            <span style="font-weight:400;"><?= $item['from'] ?> – <?= $item['to'] ?></span>
                        </span>
                    </td>
                </tr>

                <?php elseif ($item['type'] === 'lunch'): ?>
                <!-- ── Lunch row ───────────── -->
                <tr style="height:38px;">
                    <td colspan="<?= 1 + count($days) ?>"
                        style="
                            padding:0 16px;
                            background:linear-gradient(90deg,#fef9ec,#fffbf0,#fef9ec);
                            border-top:2px solid #f59e0b;
                            border-bottom:2px solid #f59e0b;
                            text-align:center;">
                        <span style="
                            font-size:12px; font-weight:700; color:#92400e;
                            display:flex; align-items:center;
                            justify-content:center; gap:8px; white-space:nowrap;">
                            <i class="fa-solid fa-utensils" style="font-size:11px; color:#f59e0b;"></i>
                            Lunch Break &nbsp;·&nbsp; <?= $item['mins'] ?> min
                            &nbsp;
                            <span style="font-weight:500; color:#b45309;"><?= $item['from'] ?> – <?= $item['to'] ?></span>
                        </span>
                    </td>
                </tr>

                <?php else: /* slot */ ?>
                <?php $slot = $item['data']; ?>
                <tr>
                    <td class="time-cell"><?= $slot['label'] ?></td>

                    <?php foreach ($days as $day): ?>
                        <td style="
                            vertical-align:top; padding:6px;
                            <?= $day === $today ? 'background:rgba(192,225,210,0.10);' : '' ?>">

                            <?php $sessions = $grid[$day][$slot['start']] ?? []; ?>

                            <?php if (!empty($sessions)):
                                foreach ($sessions as $s):
                                    [$bClass, $bLabel] = match($s['session_type']) {
                                        'lecture' => ['badge-lesson', 'Lesson'],
                                        'lab'     => ['badge-tp',     'TP'],
                                        'seminar' => ['badge-td',     'TD'],
                                        default   => ['badge-lesson', 'Lesson'],
                                    };
                                    $cClass = match($s['session_type']) {
                                        'lecture' => 'cell-lesson',
                                        'lab'     => 'cell-tp',
                                        'seminar' => 'cell-td',
                                        default   => 'cell-lesson',
                                    };
                            ?>
                                <div class="class-cell <?= $cClass ?>"
                                     data-type="<?= $s['session_type'] ?>">
                                    <span class="cell-type-badge <?= $bClass ?>">
                                        <?= $bLabel ?>
                                    </span>
                                    <div class="cell-subject">
                                        <?= htmlspecialchars($s['subject_name']) ?>
                                    </div>
                                    <div class="cell-meta">
                                        <?= htmlspecialchars($s['professor_name']) ?>
                                        <br>
                                        <?= htmlspecialchars($s['room_name']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-cell">—</div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>

                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ─── Legend ───────────────────────────── -->
    <div style="padding:16px 20px; border-top:1px solid var(--color-border);">
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
