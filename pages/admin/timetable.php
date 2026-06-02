<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'timetable';
$pageTitle    = 'Timetable Management';
$pageSubtitle = 'View and manage all scheduled sessions.';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Pending requests ─────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
    WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Get all active sessions ──────────────────
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
$allSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Get professors for modal ─────────────────
$professors = $pdo->query("
    SELECT id, name FROM users
    WHERE role = 'professor' AND is_active = 1
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Get subjects for modal ───────────────────
$subjects = $pdo->query("
    SELECT id, name, code FROM subjects
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Get groups for modal ─────────────────────
$groups = $pdo->query("
    SELECT id, group_name, level FROM groups_table
    ORDER BY level, group_name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Unique levels for filter bar ─────────────
$levels = array_values(array_unique(array_column($groups, 'level')));
sort($levels);

// ─── Get rooms for modal ──────────────────────
$rooms = $pdo->query("
    SELECT id, room_name, type, capacity FROM rooms
    WHERE is_active = 1
    ORDER BY room_name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Professor availability for JS check ──────
$availRows = $pdo->query("
    SELECT professor_id, day, time_start, time_end FROM availability
")->fetchAll(PDO::FETCH_ASSOC);
$profAvailability = [];
foreach ($availRows as $row) {
    $profAvailability[$row['professor_id']][$row['day']][] = [
        'start' => $row['time_start'],
        'end'   => $row['time_end'],
    ];
}

// ─── Already-occupied professor slots for JS ──
$occupiedRows = $pdo->query("
    SELECT professor_id, day, time_start FROM timetable
    WHERE is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);
$profOccupied = [];
foreach ($occupiedRows as $row) {
    $profOccupied[$row['professor_id']][$row['day']][] =
        substr($row['time_start'], 0, 5);
}

// ─── Build timetable grid ─────────────────────
$days = [
    'Saturday', 'Sunday', 'Monday',
    'Tuesday',  'Wednesday', 'Thursday',
];

// Load schedule settings and compute slots dynamically
$scheduleSettings = loadScheduleSettings($pdo);
$slots            = computeTimeSlots($scheduleSettings);

// Add any extra time_starts already in the DB that don't
// match a computed slot (backwards-compatibility with old data)
$existingStarts = array_unique(array_column($allSessions, 'time_start'));
$slotStarts     = array_column($slots, 'start');
foreach ($existingStarts as $ts) {
    if (!in_array($ts, $slotStarts)) {
        $startEpoch = strtotime($ts);
        $sessionSec = (int)$scheduleSettings['session_duration_minutes'] * 60;
        $slots[] = [
            'start' => $ts,
            'end'   => date('H:i:s', $startEpoch + $sessionSec),
            'label' => date('H:i', $startEpoch)
                     . ' - '
                     . date('H:i', $startEpoch + $sessionSec),
        ];
    }
}
usort($slots, fn($a, $b) => strcmp($a['start'], $b['start']));

// ─── Build schedule items (slots + breaks + lunch + gaps) ─
$lunchStartTs   = strtotime($scheduleSettings['lunch_start_time']);
$lunchEndTs     = strtotime($scheduleSettings['lunch_end_time']);
$breakMins      = (int)$scheduleSettings['break_duration_minutes'];
$scheduleItems  = [];

for ($i = 0; $i < count($slots); $i++) {
    $scheduleItems[] = ['type' => 'slot', 'data' => $slots[$i]];

    if ($i >= count($slots) - 1) {
        continue;
    }

    $gapStartTs = strtotime($slots[$i]['end']);
    $gapEndTs   = strtotime($slots[$i + 1]['start']);

    if ($gapEndTs <= $gapStartTs) {
        continue;
    }

    $overlapsLunch = ($gapStartTs < $lunchEndTs && $gapEndTs > $lunchStartTs);

    if ($overlapsLunch) {
        // Dead time before the lunch window starts
        if ($gapStartTs < $lunchStartTs) {
            $preMins = (int)(($lunchStartTs - $gapStartTs) / 60);
            if ($preMins > 0) {
                $scheduleItems[] = [
                    'type' => 'gap',
                    'from' => date('H:i', $gapStartTs),
                    'to'   => date('H:i', $lunchStartTs),
                    'mins' => $preMins,
                ];
            }
        }

        $lFrom     = max($gapStartTs, $lunchStartTs);
        $lTo       = min($gapEndTs,   $lunchEndTs);
        $lunchMins = (int)(($lTo - $lFrom) / 60);
        $scheduleItems[] = [
            'type' => 'lunch',
            'from' => date('H:i', $lFrom),
            'to'   => date('H:i', $lTo),
            'mins' => $lunchMins,
        ];

        // Dead time after the lunch window ends
        if ($gapEndTs > $lunchEndTs) {
            $postMins = (int)(($gapEndTs - $lunchEndTs) / 60);
            if ($postMins > 0) {
                $scheduleItems[] = [
                    'type' => 'gap',
                    'from' => date('H:i', $lunchEndTs),
                    'to'   => date('H:i', $gapEndTs),
                    'mins' => $postMins,
                ];
            }
        }
    } else {
        $mins = (int)(($gapEndTs - $gapStartTs) / 60);
        if ($mins > 0) {
            // Any inter-slot gap longer than the configured break is dead time
            $type = ($mins > $breakMins) ? 'gap' : 'break';
            $scheduleItems[] = [
                'type' => $type,
                'from' => date('H:i', $gapStartTs),
                'to'   => date('H:i', $gapEndTs),
                'mins' => $mins,
            ];
        }
    }
}

// ─── Index grid by [day][time_start] ──────────
$grid = [];
foreach ($allSessions as $s) {
    $grid[$s['day']][$s['time_start']][] = $s;
}

// ─── Helpers ──────────────────────────────────
function cellStyle(string $type): array
{
    return match ($type) {
        'lecture' => [
            'class'  => 'cell-lesson',
            'badge'  => 'badge-lesson',
            'label'  => 'Lesson',
        ],
        'lab'     => [
            'class'  => 'cell-tp',
            'badge'  => 'badge-tp',
            'label'  => 'TP',
        ],
        'seminar' => [
            'class'  => 'cell-td',
            'badge'  => 'badge-td',
            'label'  => 'TD',
        ],
        default   => [
            'class'  => 'cell-lesson',
            'badge'  => 'badge-lesson',
            'label'  => 'Class',
        ],
    };
}

$today = date('l');

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(4,1fr);
            margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Sessions</p>
            <div class="stat-value"><?= count($allSessions) ?></div>
            <p class="stat-sub">This week</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-chalkboard"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Lessons</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $allSessions,
                    fn($s) => $s['session_type'] === 'lecture'
                )) ?>
            </div>
            <p class="stat-sub">Lecture sessions</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon cream">
            <i class="fa-solid fa-flask"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">TP Sessions</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $allSessions,
                    fn($s) => $s['session_type'] === 'lab'
                )) ?>
            </div>
            <p class="stat-sub">Lab sessions</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">TD Sessions</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $allSessions,
                    fn($s) => $s['session_type'] === 'seminar'
                )) ?>
            </div>
            <p class="stat-sub">Tutorial sessions</p>
        </div>
    </div>

</div>

<!-- ─── Unified Filter + Action Bar ───────────── -->
<div style="
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:20px;
    flex-wrap:wrap;">

    <!-- Type tabs -->
    <div class="filter-tabs" style="flex-shrink:0;">
        <button class="filter-tab active" data-filter="all">
            <i class="fa-solid fa-table-cells"></i>All
        </button>
        <button class="filter-tab" data-filter="lecture">
            <i class="fa-solid fa-chalkboard"></i>Lessons
        </button>
        <button class="filter-tab" data-filter="lab">
            <i class="fa-solid fa-flask"></i>TP
        </button>
        <button class="filter-tab" data-filter="seminar">
            <i class="fa-solid fa-users"></i>TD
        </button>
    </div>

    <!-- Divider -->
    <div style="
        width:1px; height:28px;
        background:var(--color-border);
        flex-shrink:0; margin:0 2px;"></div>

    <!-- Filter-by label -->
    <span style="
        font-size:11px;
        font-weight:600;
        color:var(--color-text-light);
        text-transform:uppercase;
        letter-spacing:0.06em;
        white-space:nowrap;
        flex-shrink:0;">
        Filter by:
    </span>

    <!-- Professor -->
    <select id="filterProf"
            class="tbl-filter-select"
            onchange="setAttrFilter('prof', this.value)">
        <option value="">All Professors</option>
        <?php foreach ($professors as $p): ?>
            <option value="<?= $p['id'] ?>">
                <?= htmlspecialchars($p['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Subject -->
    <select id="filterSubject"
            class="tbl-filter-select"
            onchange="setAttrFilter('subject', this.value)">
        <option value="">All Subjects</option>
        <?php foreach ($subjects as $s): ?>
            <option value="<?= $s['id'] ?>">
                <?= htmlspecialchars($s['name']) ?>
                (<?= htmlspecialchars($s['code']) ?>)
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Level -->
    <select id="filterLevel"
            class="tbl-filter-select"
            onchange="setAttrFilter('level', this.value)">
        <option value="">All Levels</option>
        <?php foreach ($levels as $l): ?>
            <option value="<?= htmlspecialchars($l) ?>">
                <?= htmlspecialchars($l) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Group -->
    <select id="filterGroup"
            class="tbl-filter-select"
            onchange="setAttrFilter('group', this.value)">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
            <option value="<?= $g['id'] ?>">
                <?= htmlspecialchars($g['group_name']) ?>
                (<?= htmlspecialchars($g['level']) ?>)
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Clear filters button — hidden until a filter is active -->
    <button id="clearFiltersBtn"
            onclick="clearAttrFilters()"
            style="
                display:none;
                align-items:center;
                gap:6px;
                padding:7px 14px;
                height:36px;
                background:var(--color-rose-light);
                color:var(--color-rose-dark);
                border:1.5px solid var(--color-rose);
                border-radius:var(--radius-md);
                font-size:13px;
                font-weight:600;
                cursor:pointer;
                transition:var(--transition);
                flex-shrink:0;">
        <i class="fa-solid fa-xmark"></i>
        Clear
    </button>

    <!-- Spacer -->
    <div style="flex:1; min-width:8px;"></div>

    <!-- Generate Timetable -->
    <button onclick="openGenerateModal()"
            style="
                display:flex;
                align-items:center;
                gap:8px;
                padding:10px 20px;
                background:linear-gradient(135deg,#8B5CF6,#7C3AED);
                color:white;
                border:none;
                border-radius:var(--radius-md);
                font-size:14px;
                font-weight:600;
                cursor:pointer;
                box-shadow:0 4px 12px rgba(124,58,237,0.35);
                transition:var(--transition);
                flex-shrink:0;"
            onmouseover="this.style.background='linear-gradient(135deg,#7C3AED,#6D28D9)'"
            onmouseout="this.style.background='linear-gradient(135deg,#8B5CF6,#7C3AED)'">
        <i class="fa-solid fa-wand-magic-sparkles"></i>
        Generate
    </button>

    <!-- Add Session -->
    <button onclick="openAddModal()"
            style="
                display:flex;
                align-items:center;
                gap:8px;
                padding:10px 20px;
                background:var(--color-mint-dark);
                color:white;
                border:none;
                border-radius:var(--radius-md);
                font-size:14px;
                font-weight:600;
                cursor:pointer;
                box-shadow:0 4px 12px rgba(142,203,182,0.40);
                transition:var(--transition);
                flex-shrink:0;"
            onmouseover="this.style.background='#6BB8A0'"
            onmouseout="this.style.background='var(--color-mint-dark)'">
        <i class="fa-solid fa-plus"></i>
        Add Session
    </button>

</div>

<!-- ─── Timetable Grid ───────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">
    <div class="timetable-scroll">
        <table class="timetable-grid">

            <!-- Header -->
            <thead>
                <tr>
                    <th style="width:110px;">Time</th>
                    <?php foreach ($days as $day): ?>
                        <th style="
                            <?= $day === $today
                                ? 'background:var(--color-mint);
                                   color:var(--color-text-dark);'
                                : '' ?>">
                            <?= $day ?>
                            <?php if ($day === $today): ?>
                                <span style="
                                    display:block;
                                    font-size:10px;
                                    font-weight:500;
                                    margin-top:2px;
                                    color:var(--color-text-mid);">
                                    Today
                                </span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <!-- Body -->
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
                                #f8f9fa,
                                #f8f9fa 4px,
                                #f1f3f5 4px,
                                #f1f3f5 8px
                            );
                            border-top:1px dashed #ced4da;
                            border-bottom:1px dashed #ced4da;
                            text-align:center;">
                        <span style="
                            font-size:11px;
                            font-weight:600;
                            color:#9ca3af;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            gap:6px;
                            white-space:nowrap;">
                            <i class="fa-solid fa-mug-hot" style="font-size:10px;"></i>
                            Break &nbsp;·&nbsp; <?= $item['mins'] ?> min
                            &nbsp;
                            <span style="font-weight:400;"><?= $item['from'] ?> – <?= $item['to'] ?></span>
                        </span>
                    </td>
                </tr>

                <?php elseif ($item['type'] === 'lunch'): ?>
                <!-- ── Lunch break row ─────── -->
                <tr style="height:38px;">
                    <td colspan="<?= 1 + count($days) ?>"
                        style="
                            padding:0 16px;
                            background:linear-gradient(90deg,#fef9ec,#fffbf0,#fef9ec);
                            border-top:2px solid #f59e0b;
                            border-bottom:2px solid #f59e0b;
                            text-align:center;">
                        <span style="
                            font-size:12px;
                            font-weight:700;
                            color:#92400e;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            gap:8px;
                            white-space:nowrap;">
                            <i class="fa-solid fa-utensils" style="font-size:11px; color:#f59e0b;"></i>
                            Lunch Break &nbsp;·&nbsp; <?= $item['mins'] ?> min
                            &nbsp;
                            <span style="font-weight:500; color:#b45309;"><?= $item['from'] ?> – <?= $item['to'] ?></span>
                        </span>
                    </td>
                </tr>

                <?php elseif ($item['type'] === 'gap'): ?>
                <!-- ── Gap row ────────────────── -->
                <tr style="height:28px;">
                    <td colspan="<?= 1 + count($days) ?>"
                        style="
                            padding:0 16px;
                            background:repeating-linear-gradient(
                                45deg,
                                #fff7f7,
                                #fff7f7 4px,
                                #ffe4e4 4px,
                                #ffe4e4 8px
                            );
                            border-top:1px dashed #f87171;
                            border-bottom:1px dashed #f87171;
                            text-align:center;">
                        <span style="
                            font-size:11px;
                            font-weight:600;
                            color:#dc2626;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            gap:6px;
                            white-space:nowrap;">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:10px;"></i>
                            Gap &nbsp;·&nbsp; <?= $item['mins'] ?> min
                            &nbsp;
                            <span style="font-weight:400;"><?= $item['from'] ?> – <?= $item['to'] ?></span>
                        </span>
                    </td>
                </tr>

                <?php else: /* slot */ ?>
                <?php $slot = $item['data']; ?>
                <tr>
                    <td class="time-cell">
                        <?= $slot['label'] ?>
                    </td>

                    <?php foreach ($days as $day): ?>
                        <td style="
                            vertical-align:top;
                            padding:6px;
                            <?= $day === $today
                                ? 'background:rgba(192,225,210,0.10);'
                                : '' ?>">

                            <?php
                            $sessions = $grid[$day][$slot['start']] ?? [];
                            ?>

                            <?php if (!empty($sessions)): ?>
                                <?php foreach ($sessions as $s):
                                    $style = cellStyle($s['session_type']);
                                ?>
                                    <div class="class-cell <?= $style['class'] ?>"
                                         data-type="<?= $s['session_type'] ?>"
                                         data-id="<?= $s['id'] ?>"
                                         data-prof="<?= $s['professor_id'] ?>"
                                         data-subject="<?= $s['subject_id'] ?>"
                                         data-level="<?= htmlspecialchars($s['level']) ?>"
                                         data-group="<?= $s['group_id'] ?>"
                                         style="
                                             margin-bottom:4px;
                                             position:relative;
                                             cursor:default;">

                                        <!-- Type Badge -->
                                        <span class="cell-type-badge <?= $style['badge'] ?>">
                                            <?= $style['label'] ?>
                                        </span>

                                        <!-- Subject -->
                                        <div class="cell-subject">
                                            <?= htmlspecialchars(
                                                $s['subject_name']
                                            ) ?>
                                        </div>

                                        <!-- Meta -->
                                        <div class="cell-meta">
                                            <span>
                                                <i class="fa-solid fa-chalkboard-user"
                                                   style="font-size:9px;">
                                                </i>
                                                <?= htmlspecialchars(
                                                    $s['professor_name']
                                                ) ?>
                                            </span>
                                            <br>
                                            <span>
                                                <i class="fa-solid fa-users"
                                                   style="font-size:9px;">
                                                </i>
                                                <?= htmlspecialchars(
                                                    $s['group_name']
                                                ) ?>
                                            </span>
                                            <br>
                                            <span>
                                                <i class="fa-solid fa-location-dot"
                                                   style="font-size:9px;">
                                                </i>
                                                <?= htmlspecialchars(
                                                    $s['room_name']
                                                ) ?>
                                            </span>
                                        </div>

                                        <!-- Action Buttons -->
                                        <div style="
                                            position:absolute;
                                            top:4px;
                                            right:4px;
                                            display:flex;
                                            gap:3px;">

                                            <!-- Edit -->
                                            <button
                                                onclick="openEditModal(
                                                    <?= $s['id'] ?>,
                                                    '<?= $s['day'] ?>',
                                                    '<?= $s['time_start'] ?>',
                                                    '<?= $s['time_end'] ?>',
                                                    '<?= $s['session_type'] ?>',
                                                    <?= $s['professor_id'] ?>,
                                                    <?= $s['subject_id'] ?>,
                                                    <?= $s['group_id'] ?>,
                                                    <?= $s['room_id'] ?>
                                                )"
                                                style="
                                                    width:20px;
                                                    height:20px;
                                                    border-radius:4px;
                                                    background:rgba(142,203,182,0.4);
                                                    border:none;
                                                    cursor:pointer;
                                                    display:flex;
                                                    align-items:center;
                                                    justify-content:center;
                                                    font-size:9px;
                                                    color:var(--color-mint-dark);
                                                    transition:var(--transition);"
                                                onmouseover="
                                                    this.style.background=
                                                        'var(--color-mint-dark)';
                                                    this.style.color='white';"
                                                onmouseout="
                                                    this.style.background=
                                                        'rgba(142,203,182,0.4)';
                                                    this.style.color=
                                                        'var(--color-mint-dark)';">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>

                                            <!-- Delete -->
                                            <button
                                                onclick="deleteSession(
                                                    <?= $s['id'] ?>,
                                                    '<?= addslashes(
                                                        $s['subject_name']
                                                    ) ?>'
                                                )"
                                                style="
                                                    width:20px;
                                                    height:20px;
                                                    border-radius:4px;
                                                    background:rgba(220,155,155,0.4);
                                                    border:none;
                                                    cursor:pointer;
                                                    display:flex;
                                                    align-items:center;
                                                    justify-content:center;
                                                    font-size:9px;
                                                    color:var(--color-rose-dark);
                                                    transition:var(--transition);"
                                                onmouseover="
                                                    this.style.background=
                                                        'var(--color-rose)';
                                                    this.style.color='white';"
                                                onmouseout="
                                                    this.style.background=
                                                        'rgba(220,155,155,0.4)';
                                                    this.style.color=
                                                        'var(--color-rose-dark)';">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>

                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            <?php else: ?>
                                <!-- Empty cell - click to add -->
                                <div class="empty-cell"
                                     title="Click to add session"
                                     onclick="openAddModalWithSlot(
                                         '<?= $day ?>',
                                         '<?= $slot['start'] ?>',
                                         '<?= $slot['end'] ?>'
                                     )"
                                     style="cursor:pointer;
                                            transition:var(--transition);"
                                     onmouseover="
                                         this.innerHTML=
                                             '<i class=\'fa-solid fa-plus\' '
                                             + 'style=\'color:var(--color-mint-dark)\'>'
                                             + '</i>';
                                         this.style.background=
                                             'var(--color-mint-light)';"
                                     onmouseout="
                                         this.innerHTML='—';
                                         this.style.background='';">
                                    —
                                </div>
                            <?php endif; ?>

                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endif; /* end slot/break/lunch */ ?>
                <?php endforeach; /* scheduleItems */ ?>
            </tbody>

        </table>
    </div>

    <!-- Legend & Info -->
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
                Lesson (CM)
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-tp"></span>
                TP (Lab)
            </div>
            <div class="legend-item">
                <span class="legend-dot legend-td"></span>
                TD (Seminar)
            </div>
            <div class="legend-item" style="gap:6px;">
                <span style="
                    display:inline-block;
                    width:12px; height:12px;
                    border-radius:2px;
                    background:repeating-linear-gradient(45deg,#f1f3f5,#f1f3f5 3px,#e9ecef 3px,#e9ecef 6px);
                    border:1px dashed #ced4da;
                    flex-shrink:0;">
                </span>
                Break
            </div>
            <div class="legend-item" style="gap:6px;">
                <span style="
                    display:inline-block;
                    width:12px; height:12px;
                    border-radius:2px;
                    background:#fef3c7;
                    border:1.5px solid #f59e0b;
                    flex-shrink:0;">
                </span>
                Lunch Break
            </div>
        </div>

        <p style="
            font-size:12px;
            color:var(--color-text-light);
            display:flex;
            align-items:center;
            gap:6px;">
            <i class="fa-solid fa-circle-info"
               style="color:var(--color-mint-dark);">
            </i>
            Click empty cell to add · Click
            <i class="fa-solid fa-pen"
               style="font-size:10px;">
            </i>
            to edit ·
            <i class="fa-solid fa-xmark"
               style="font-size:10px;">
            </i>
            to delete
        </p>

    </div>
</div>

<!-- ═══════════════════════════════════════════════
     ADD / EDIT SESSION MODAL
═══════════════════════════════════════════════ -->
<div id="sessionModal"
     style="
         display:none;
         position:fixed;
         inset:0;
         background:rgba(0,0,0,0.50);
         z-index:999;
         align-items:center;
         justify-content:center;">

    <div style="
        background:var(--color-white);
        border-radius:var(--radius-lg);
        padding:32px;
        width:100%;
        max-width:540px;
        margin:20px;
        box-shadow:var(--shadow-lg);
        position:relative;
        max-height:90vh;
        overflow-y:auto;">

        <!-- Close -->
        <button onclick="closeModal()"
                style="
                    position:absolute;
                    top:16px; right:16px;
                    width:32px; height:32px;
                    border-radius:50%;
                    background:var(--color-sage);
                    border:none;
                    cursor:pointer;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    font-size:14px;
                    color:var(--color-text-mid);">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Title -->
        <div style="
            display:flex;
            align-items:center;
            gap:12px;
            margin-bottom:24px;">
            <div style="
                width:42px; height:42px;
                border-radius:var(--radius-md);
                background:var(--color-mint-light);
                display:flex;
                align-items:center;
                justify-content:center;">
                <i id="modalIcon"
                   class="fa-solid fa-calendar-plus"
                   style="color:var(--color-mint-dark);
                          font-size:18px;">
                </i>
            </div>
            <div>
                <h3 id="modalTitle"
                    style="
                        font-size:17px;
                        font-weight:700;
                        color:var(--color-text-dark);">
                    Add Session
                </h3>
                <p style="
                    font-size:13px;
                    color:var(--color-text-light);">
                    Fill in the session details below
                </p>
            </div>
        </div>

        <!-- Alert -->
        <div id="modalAlert"
             style="
                 display:none;
                 align-items:center;
                 gap:8px;
                 padding:10px 14px;
                 border-radius:var(--radius-md);
                 font-size:13px;
                 margin-bottom:16px;">
        </div>

        <!-- Form -->
        <form id="sessionForm">
            <input type="hidden" id="sessionId" value="">

            <!-- Row 1: Day + Type -->
            <div style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:14px;
                margin-bottom:16px;">

                <div>
                    <label class="form-label">Day</label>
                    <select id="sessionDay"
                            class="form-input"
                            style="height:46px; padding:0 14px;"
                            onchange="updateSlotStatus()">
                        <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">Session Type</label>
                    <select id="sessionType"
                            class="form-input"
                            style="height:46px; padding:0 14px;">
                        <option value="lecture">Lesson (CM)</option>
                        <option value="lab">TP (Lab)</option>
                        <option value="seminar">TD (Seminar)</option>
                    </select>
                </div>

            </div>

            <!-- Row 2: Start + End -->
            <div style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:14px;
                margin-bottom:16px;">

                <div>
                    <label class="form-label">Start Time</label>
                    <select id="sessionStart"
                            class="form-input"
                            style="height:46px; padding:0 14px;"
                            onchange="autoSetEnd(); updateSlotStatus()">
                        <?php foreach ($slots as $sl): ?>
                            <option value="<?= $sl['start'] ?>">
                                <?= substr($sl['start'], 0, 5) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">End Time</label>
                    <select id="sessionEnd"
                            class="form-input"
                            style="height:46px; padding:0 14px;">
                        <?php foreach ($slots as $sl): ?>
                            <option value="<?= $sl['end'] ?>">
                                <?= substr($sl['end'], 0, 5) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <!-- Professor -->
            <div style="margin-bottom:12px;">
                <label class="form-label">Professor</label>
                <select id="sessionProfessor"
                        class="form-input"
                        style="height:46px; padding:0 14px;"
                        onchange="updateSlotStatus()"
                        required>
                    <option value="">Select professor</option>
                    <?php foreach ($professors as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Slot Status Badge -->
            <div id="slotStatusBadge"
                 style="
                     display:none;
                     align-items:center;
                     gap:8px;
                     padding:9px 14px;
                     border-radius:var(--radius-md);
                     font-size:13px;
                     font-weight:600;
                     margin-bottom:16px;">
            </div>

            <!-- Subject -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Subject</label>
                <select id="sessionSubject"
                        class="form-input"
                        style="height:46px; padding:0 14px;"
                        required>
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= htmlspecialchars($s['name']) ?>
                            (<?= htmlspecialchars($s['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Group -->
            <div style="margin-bottom:16px;">
                <label class="form-label">Group</label>
                <select id="sessionGroup"
                        class="form-input"
                        style="height:46px; padding:0 14px;"
                        required>
                    <option value="">Select group</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>">
                            <?= htmlspecialchars($g['group_name']) ?>
                            (<?= htmlspecialchars($g['level']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Room -->
            <div style="margin-bottom:24px;">
                <label class="form-label">Room</label>
                <select id="sessionRoom"
                        class="form-input"
                        style="height:46px; padding:0 14px;"
                        required>
                    <option value="">Select room</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= $r['id'] ?>">
                            <?= htmlspecialchars($r['room_name']) ?>
                            (<?= ucfirst($r['type']) ?> ·
                            <?= $r['capacity'] ?> seats)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:12px;">
                <button type="button"
                        onclick="closeModal()"
                        style="
                            flex:1;
                            height:46px;
                            background:var(--color-sage);
                            color:var(--color-text-dark);
                            border:none;
                            border-radius:var(--radius-md);
                            font-size:14px;
                            font-weight:600;
                            cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        id="submitBtn"
                        style="
                            flex:2;
                            height:46px;
                            background:var(--color-mint-dark);
                            color:white;
                            border:none;
                            border-radius:var(--radius-md);
                            font-size:14px;
                            font-weight:600;
                            cursor:pointer;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            gap:8px;">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span id="submitText">Save Session</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     GENERATE TIMETABLE MODAL
═══════════════════════════════════════════════ -->
<style>
.gen-toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.gen-toggle input{opacity:0;width:0;height:0}
.gen-toggle-slider{
    position:absolute;inset:0;background:#d1d5db;
    border-radius:12px;cursor:pointer;transition:.3s}
.gen-toggle-slider:before{
    content:'';position:absolute;
    width:18px;height:18px;left:3px;top:3px;
    background:white;border-radius:50%;
    transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.gen-toggle input:checked + .gen-toggle-slider{background:#7C3AED}
.gen-toggle input:checked + .gen-toggle-slider:before{transform:translateX(20px)}
.weight-stepper{
    display:flex;align-items:center;
    border:1.5px solid var(--color-border);
    border-radius:8px;overflow:hidden;flex-shrink:0}
.weight-btn{
    width:28px;height:28px;background:var(--color-sage);
    border:none;cursor:pointer;font-size:14px;font-weight:700;
    color:var(--color-text-mid);transition:.15s;
    display:flex;align-items:center;justify-content:center}
.weight-btn:hover{background:#d4dbd7;color:var(--color-text-dark)}
.weight-val{
    width:32px;height:28px;background:white;
    font-size:13px;font-weight:700;color:var(--color-text-dark);
    display:flex;align-items:center;justify-content:center;
    border-left:1.5px solid var(--color-border);
    border-right:1.5px solid var(--color-border)}
.sc-row{transition:opacity .2s}
.sc-row.sc-disabled{opacity:.4}
.sc-row.sc-disabled .weight-stepper{pointer-events:none}
</style>

<div id="generateModal"
     style="
         display:none;
         position:fixed;
         inset:0;
         background:rgba(0,0,0,.55);
         z-index:1000;
         align-items:center;
         justify-content:center;">

    <div style="
        background:var(--color-white);
        border-radius:var(--radius-lg);
        width:100%;
        max-width:600px;
        margin:20px;
        box-shadow:0 24px 64px rgba(0,0,0,.22);
        position:relative;
        max-height:92vh;
        overflow-y:auto;">

        <!-- ── Header ──────────────────────────── -->
        <div style="
            background:linear-gradient(135deg,#8B5CF6,#7C3AED);
            border-radius:var(--radius-lg) var(--radius-lg) 0 0;
            padding:22px 26px;
            display:flex;
            align-items:center;
            gap:14px;
            position:sticky;
            top:0;
            z-index:1;">
            <div style="
                width:44px;height:44px;border-radius:12px;
                background:rgba(255,255,255,.18);
                display:flex;align-items:center;justify-content:center;
                flex-shrink:0;">
                <i class="fa-solid fa-wand-magic-sparkles"
                   style="color:white;font-size:20px;"></i>
            </div>
            <div>
                <h3 style="color:white;font-size:17px;font-weight:700;margin:0;">
                    Generate Timetable
                </h3>
                <p style="color:rgba(255,255,255,.75);font-size:13px;margin:4px 0 0;">
                    Configure constraints and run the CSP solver
                </p>
            </div>
            <button onclick="closeGenerateModal()"
                    style="
                        margin-left:auto;
                        width:32px;height:32px;border-radius:50%;
                        background:rgba(255,255,255,.18);
                        border:none;cursor:pointer;color:white;
                        font-size:16px;display:flex;
                        align-items:center;justify-content:center;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- ── Body ────────────────────────────── -->
        <div style="padding:24px 26px;">

            <!-- Settings panel -->
            <div id="genSettings">

                <!-- Hard Constraints -->
                <div style="margin-bottom:22px;">
                    <div style="
                        display:flex;align-items:center;gap:8px;
                        margin-bottom:10px;">
                        <span style="
                            padding:3px 8px;border-radius:4px;
                            background:#fee2e2;color:#b91c1c;
                            font-size:11px;font-weight:700;
                            text-transform:uppercase;letter-spacing:.05em;">
                            Hard
                        </span>
                        <span style="font-size:13px;font-weight:600;color:var(--color-text-mid);">
                            Constraints — must always hold
                        </span>
                    </div>

                    <!-- Always-enforced (read-only) -->
                    <div style="
                        border:1.5px solid #fecaca;
                        border-radius:var(--radius-md);
                        overflow:hidden;
                        margin-bottom:10px;">
                        <div style="
                            padding:7px 14px;
                            background:#fff5f5;
                            border-bottom:1px solid #fecaca;
                            display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-lock"
                               style="font-size:10px;color:#ef4444;"></i>
                            <span style="
                                font-size:11px;font-weight:700;
                                color:#dc2626;text-transform:uppercase;
                                letter-spacing:.05em;">
                                Always enforced — cannot be disabled
                            </span>
                        </div>
                        <div style="
                            padding:10px 14px;
                            display:grid;
                            grid-template-columns:1fr 1fr;
                            gap:6px;">
                            <?php
                            $fixedHC = [
                                ['id'=>'HC1','label'=>'No Room Double-Booking',         'icon'=>'fa-door-open'],
                                ['id'=>'HC2','label'=>'No Professor Double-Booking',    'icon'=>'fa-chalkboard-user'],
                                ['id'=>'HC3','label'=>'No Group Overlap',               'icon'=>'fa-users'],
                                ['id'=>'HC5','label'=>'Max 4 Prof Sessions/Day',        'icon'=>'fa-clock'],
                                ['id'=>'HC6','label'=>'Max 4 Group Sessions/Day',       'icon'=>'fa-calendar-day'],
                            ];
                            foreach ($fixedHC as $hc): ?>
                            <div style="
                                display:flex;align-items:center;gap:7px;
                                padding:6px 8px;
                                background:#fef9f9;
                                border:1px solid #fee2e2;
                                border-radius:6px;">
                                <i class="fa-solid <?= $hc['icon'] ?>"
                                   style="font-size:11px;color:#ef4444;flex-shrink:0;"></i>
                                <span style="font-size:11px;font-weight:600;color:var(--color-text-mid);">
                                    <span style="
                                        color:#dc2626;font-weight:700;
                                        margin-right:3px;"><?= $hc['id'] ?></span>
                                    <?= $hc['label'] ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Configurable hard constraints (toggleable) -->
                    <div style="
                        border:1.5px solid #fecaca;
                        border-radius:var(--radius-md);
                        overflow:hidden;">
                        <div style="
                            padding:7px 14px;
                            background:#fff5f5;
                            border-bottom:1px solid #fecaca;
                            display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-sliders"
                               style="font-size:10px;color:#ef4444;"></i>
                            <span style="
                                font-size:11px;font-weight:700;
                                color:#dc2626;text-transform:uppercase;
                                letter-spacing:.05em;">
                                Configurable — toggle on / off
                            </span>
                        </div>

                        <!-- HC7: No Lunch Sessions -->
                        <div style="
                            display:flex;align-items:center;gap:14px;
                            padding:12px 16px;
                            border-bottom:1px solid #fecaca;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc7Lunch" checked
                                       onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="
                                    font-size:13px;font-weight:600;
                                    color:var(--color-text-dark);
                                    display:flex;align-items:center;gap:6px;">
                                    <span style="
                                        color:#dc2626;font-weight:700;
                                        font-size:11px;">HC7</span>
                                    No Lunch Break Sessions
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">
                                    Prevent sessions from being scheduled during the lunch window
                                </div>
                            </div>
                        </div>

                        <!-- HC8: No Saturday -->
                        <div style="
                            display:flex;align-items:center;gap:14px;
                            padding:12px 16px;
                            border-bottom:1px solid #fecaca;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc8Saturday" checked
                                       onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="
                                    font-size:13px;font-weight:600;
                                    color:var(--color-text-dark);
                                    display:flex;align-items:center;gap:6px;">
                                    <span style="
                                        color:#dc2626;font-weight:700;
                                        font-size:11px;">HC8</span>
                                    No Saturday Sessions
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">
                                    Block all Saturday slots from the solver domain
                                </div>
                            </div>
                        </div>

                        <!-- HC4: Respect availability -->
                        <div style="
                            display:flex;align-items:center;gap:14px;
                            padding:12px 16px;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc4Availability" checked
                                       onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="
                                    font-size:13px;font-weight:600;
                                    color:var(--color-text-dark);
                                    display:flex;align-items:center;gap:6px;">
                                    <span style="
                                        color:#dc2626;font-weight:700;
                                        font-size:11px;">HC4</span>
                                    Respect Professor Availability
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">
                                    Only assign sessions within declared availability windows
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Soft Constraints -->
                <div>
                    <div style="
                        display:flex;align-items:center;gap:8px;
                        margin-bottom:10px;">
                        <span style="
                            padding:3px 8px;border-radius:4px;
                            background:#ede9fe;color:#6D28D9;
                            font-size:11px;font-weight:700;
                            text-transform:uppercase;letter-spacing:.05em;">
                            Soft
                        </span>
                        <span style="font-size:13px;font-weight:600;color:var(--color-text-mid);">
                            Constraints — optimisation goals &nbsp;
                            <span style="font-weight:400;font-size:12px;">(weight 0 – 20)</span>
                        </span>
                    </div>

                    <?php
                    $softConstraints = [
                        ['key'=>'SC1','w'=>15,'title'=>'Lecture Before TD / TP',
                         'desc'=>'Schedule lectures earlier in the week than their companion TD/TP sessions'],
                        ['key'=>'SC2','w'=>15,'title'=>'Spread Group Sessions',
                         'desc'=>'Distribute a group\'s sessions across different days rather than clustering them'],
                        ['key'=>'SC3','w'=>10,'title'=>'Morning Slots for Lectures',
                         'desc'=>'Prefer 08:00 – 11:30 slots for lecture (CM) sessions'],
                        ['key'=>'SC4','w'=>5, 'title'=>'Room Type Match',
                         'desc'=>'Assign labs to lab rooms, lectures to lecture halls, seminars to seminar rooms'],
                        ['key'=>'SC5','w'=>5, 'title'=>'Best-fit Room Capacity',
                         'desc'=>'Prefer rooms whose capacity is closest to (but not below) the group size'],
                        ['key'=>'SC6','w'=>20,'title'=>'Professor Preferred Slots',
                         'desc'=>'Reward placing sessions inside a professor\'s declared availability windows'],
                        ['key'=>'SC7','w'=>15,'title'=>'Cluster Professor Days (≤ 2)',
                         'desc'=>'Group all of a professor\'s sessions into as few working days as possible'],
                    ];
                    ?>

                    <?php foreach ($softConstraints as $sc): ?>
                    <div class="sc-row"
                         id="row_<?= $sc['key'] ?>"
                         style="
                             display:flex;
                             align-items:center;
                             gap:10px;
                             padding:10px 14px;
                             border:1.5px solid var(--color-border);
                             border-radius:var(--radius-md);
                             margin-bottom:6px;">
                        <!-- Enable checkbox -->
                        <input type="checkbox"
                               id="chk_<?= $sc['key'] ?>"
                               checked
                               onchange="toggleSC('<?= $sc['key'] ?>')"
                               style="
                                   width:16px;height:16px;
                                   accent-color:#7C3AED;
                                   cursor:pointer;flex-shrink:0;">
                        <!-- Badge -->
                        <span style="
                            padding:2px 7px;border-radius:4px;
                            background:#ede9fe;color:#6D28D9;
                            font-size:11px;font-weight:700;
                            flex-shrink:0;">
                            <?= $sc['key'] ?>
                        </span>
                        <!-- Text -->
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--color-text-dark);">
                                <?= $sc['title'] ?>
                            </div>
                            <div style="font-size:11px;color:var(--color-text-light);margin-top:2px;line-height:1.4;">
                                <?= $sc['desc'] ?>
                            </div>
                        </div>
                        <!-- Weight stepper -->
                        <div class="weight-stepper">
                            <button type="button"
                                    class="weight-btn"
                                    onclick="adjustWeight('<?= $sc['key'] ?>',-1)">−</button>
                            <span class="weight-val"
                                  id="wval_<?= $sc['key'] ?>"><?= $sc['w'] ?></span>
                            <button type="button"
                                    class="weight-btn"
                                    onclick="adjustWeight('<?= $sc['key'] ?>',+1)">+</button>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div><!-- /#genSettings -->

            <!-- Progress panel -->
            <div id="genProgress" style="display:none;text-align:center;padding:16px 0;">
                <div style="
                    width:56px;height:56px;border-radius:50%;
                    background:linear-gradient(135deg,#8B5CF6,#7C3AED);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto 16px;">
                    <i class="fa-solid fa-wand-magic-sparkles"
                       style="color:white;font-size:22px;"></i>
                </div>
                <p style="font-size:15px;font-weight:600;color:var(--color-text-dark);margin:0 0 6px;">
                    Generating timetable…
                </p>
                <p id="genProgressMsg"
                   style="font-size:13px;color:var(--color-text-light);margin:0 0 20px;">
                    Running CSP solver with backtracking…
                </p>
                <div style="
                    height:8px;background:var(--color-sage);
                    border-radius:4px;overflow:hidden;">
                    <div id="genProgressBar"
                         style="
                             height:100%;width:0%;
                             background:linear-gradient(90deg,#8B5CF6,#7C3AED);
                             border-radius:4px;
                             transition:width .4s ease;">
                    </div>
                </div>
            </div>

            <!-- Result panel -->
            <div id="genResult" style="display:none;"></div>

            <!-- Preview panel -->
            <div id="genPreview" style="display:none;">
                <!-- Stats strip -->
                <div id="genPreviewStats" style="
                    display:flex;gap:0;
                    border:1.5px solid #ddd6fe;
                    border-radius:var(--radius-md);
                    overflow:hidden;
                    margin-bottom:14px;"></div>

                <div style="
                    font-size:11px;font-weight:700;
                    color:var(--color-text-light);
                    text-transform:uppercase;letter-spacing:.05em;
                    margin-bottom:8px;
                    display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-table-cells"
                       style="color:#7C3AED;"></i>
                    Proposed Schedule — review before saving
                </div>

                <!-- Sessions table -->
                <div id="genPreviewTable" style="
                    max-height:300px;
                    overflow-y:auto;
                    border:1.5px solid var(--color-border);
                    border-radius:var(--radius-md);
                    font-size:12px;"></div>

                <p style="
                    font-size:12px;color:var(--color-text-light);
                    margin:10px 0 0;
                    display:flex;align-items:flex-start;gap:6px;
                    line-height:1.5;">
                    <i class="fa-solid fa-circle-info"
                       style="color:#7C3AED;margin-top:1px;flex-shrink:0;"></i>
                    This schedule has not been saved yet.
                    Accept to overwrite the current timetable,
                    or re-configure to run the solver again.
                </p>
            </div>

            <!-- Logs panel -->
            <div id="genLogsPanel" style="display:none;margin-top:14px;">
                <button onclick="toggleLogs()"
                        style="
                            display:flex;align-items:center;gap:6px;
                            background:none;border:none;cursor:pointer;
                            font-size:13px;font-weight:600;
                            color:var(--color-text-light);
                            padding:0 0 8px;">
                    <i class="fa-solid fa-chevron-right"
                       id="logsChevron"
                       style="font-size:10px;transition:.2s;"></i>
                    Solver logs
                </button>
                <div id="genLogs"
                     style="
                         display:none;
                         background:#0f172a;
                         border-radius:8px;
                         padding:14px;
                         max-height:200px;
                         overflow-y:auto;
                         font-family:monospace;
                         font-size:11px;
                         line-height:1.6;">
                </div>
            </div>

        </div><!-- /.body -->

        <!-- ── Footer ──────────────────────────── -->
        <div style="
            padding:16px 26px;
            border-top:1px solid var(--color-border);
            display:flex;
            justify-content:flex-end;
            gap:12px;
            position:sticky;
            bottom:0;
            background:var(--color-white);
            border-radius:0 0 var(--radius-lg) var(--radius-lg);">
            <button id="genCancelBtn"
                    onclick="closeGenerateModal()"
                    style="
                        padding:10px 24px;height:42px;
                        background:var(--color-sage);
                        color:var(--color-text-dark);
                        border:none;border-radius:var(--radius-md);
                        font-size:14px;font-weight:600;cursor:pointer;">
                Cancel
            </button>
            <button id="genRunBtn"
                    onclick="startGeneration()"
                    style="
                        display:flex;align-items:center;gap:8px;
                        padding:10px 24px;height:42px;
                        background:linear-gradient(135deg,#8B5CF6,#7C3AED);
                        color:white;border:none;border-radius:var(--radius-md);
                        font-size:14px;font-weight:600;cursor:pointer;
                        box-shadow:0 4px 14px rgba(124,58,237,.4);">
                <i class="fa-solid fa-play"></i>
                Generate Timetable
            </button>
            <button id="genAcceptBtn"
                    onclick="saveGenerated()"
                    style="
                        display:none;align-items:center;gap:8px;
                        padding:10px 24px;height:42px;
                        background:linear-gradient(135deg,#22c55e,#16a34a);
                        color:white;border:none;border-radius:var(--radius-md);
                        font-size:14px;font-weight:600;cursor:pointer;
                        box-shadow:0 4px 14px rgba(22,163,74,.35);">
                <i class="fa-solid fa-circle-check"></i>
                Accept &amp; Save
            </button>
        </div>

    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

// ─── Availability data (from PHP) ────────────
const profAvailability = <?= json_encode($profAvailability) ?>;
const profOccupied     = <?= json_encode($profOccupied) ?>;

// Tracks the original slot when editing, so we
// don't count the session itself as "occupied"
let editOriginal = { profId: null, day: null, timeStart: null };

// ─── Check professor status for a slot ────────
// Returns: 'available' | 'unavailable' | 'occupied'
function checkProfStatus(profId, day, timeStart) {
    profId = String(profId);

    // ── Occupied? ─────────────────────────────
    const occ = profOccupied[profId];
    if (occ && occ[day]) {
        const timeKey    = timeStart.substring(0, 5);
        const editingSelf = (
            editOriginal.profId    === profId &&
            editOriginal.day       === day    &&
            editOriginal.timeStart === timeKey
        );
        if (!editingSelf && occ[day].includes(timeKey)) {
            return 'occupied';
        }
    }

    // ── Availability ──────────────────────────
    const avail = profAvailability[profId];
    if (!avail) return 'available'; // nothing declared → always available
    if (!avail[day]) return 'unavailable'; // declared but not this day

    for (const w of avail[day]) {
        if (w.start <= timeStart && w.end > timeStart) {
            return 'available';
        }
    }
    return 'unavailable';
}

// ─── Update the status badge in the modal ─────
function updateSlotStatus() {
    const profId    = document.getElementById('sessionProfessor').value;
    const day       = document.getElementById('sessionDay').value;
    const timeStart = document.getElementById('sessionStart').value;
    const badge     = document.getElementById('slotStatusBadge');
    const submitBtn = document.getElementById('submitBtn');
    const submitTxt = document.getElementById('submitText');

    if (!profId) {
        badge.style.display = 'none';
        submitBtn.disabled  = false;
        return;
    }

    const status = checkProfStatus(profId, day, timeStart);
    badge.style.display = 'flex';

    if (status === 'available') {
        badge.style.background = '#e8f5ee';
        badge.style.border     = '1px solid #b7dfca';
        badge.style.color      = '#3a8a5a';
        badge.innerHTML = `<i class="fa-solid fa-circle-check"></i>
                           &nbsp;Available — professor is free at this slot`;
        submitBtn.disabled         = false;
        submitBtn.style.background = 'var(--color-mint-dark)';
        submitBtn.style.opacity    = '1';
    } else if (status === 'occupied') {
        badge.style.background = '#fff3e0';
        badge.style.border     = '1px solid #f0c070';
        badge.style.color      = '#b07020';
        badge.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i>
                           &nbsp;Occupied — professor already has a class here`;
        submitBtn.disabled         = true;
        submitBtn.style.background = '#ccc';
        submitBtn.style.opacity    = '0.7';
    } else {
        badge.style.background = '#fde8e8';
        badge.style.border     = '1px solid #f5c6c6';
        badge.style.color      = '#c0392b';
        badge.innerHTML = `<i class="fa-solid fa-circle-xmark"></i>
                           &nbsp;Unavailable — professor has no availability here`;
        submitBtn.disabled         = true;
        submitBtn.style.background = '#ccc';
        submitBtn.style.opacity    = '0.7';
    }
}

// ─── Time slot map (built from PHP) ──────────
const startToEnd = <?= json_encode(
    array_combine(
        array_column($slots, 'start'),
        array_column($slots, 'end')
    )
) ?>;

function autoSetEnd() {
    const start = document.getElementById('sessionStart').value;
    const end   = document.getElementById('sessionEnd');
    if (startToEnd[start]) {
        end.value = startToEnd[start];
    }
}

// ─── Unified filter state ─────────────────────
let activeType    = 'all';
let activeProf    = '';
let activeSubject = '';
let activeLevel   = '';
let activeGroup   = '';

function applyFilters() {
    const anyAttr = activeProf || activeSubject || activeLevel || activeGroup;
    document.getElementById('clearFiltersBtn').style.display =
        anyAttr ? 'flex' : 'none';

    document.querySelectorAll('.class-cell').forEach(cell => {
        const matchType    = activeType    === 'all' || cell.dataset.type    === activeType;
        const matchProf    = activeProf    === ''    || cell.dataset.prof    === activeProf;
        const matchSubject = activeSubject === ''    || cell.dataset.subject === activeSubject;
        const matchLevel   = activeLevel   === ''    || cell.dataset.level   === activeLevel;
        const matchGroup   = activeGroup   === ''    || cell.dataset.group   === activeGroup;
        const match = matchType && matchProf && matchSubject && matchLevel && matchGroup;

        cell.style.opacity       = match ? '1'    : '0.10';
        cell.style.pointerEvents = match ? 'auto' : 'none';
        cell.style.filter        = match ? ''     : 'grayscale(0.4)';
    });

    // Highlight active attr selects
    ['filterProf','filterSubject','filterLevel','filterGroup'].forEach(id => {
        const el = document.getElementById(id);
        if (el.value) {
            el.classList.add('tbl-filter-active');
        } else {
            el.classList.remove('tbl-filter-active');
        }
    });
}

// ─── Type tab clicks ──────────────────────────
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.filter-tab')
            .forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        activeType = this.dataset.filter;
        applyFilters();
    });
});

// ─── Attribute filter selects ─────────────────
function setAttrFilter(key, value) {
    if (key === 'prof')    activeProf    = value;
    if (key === 'subject') activeSubject = value;
    if (key === 'level')   activeLevel   = value;
    if (key === 'group')   activeGroup   = value;
    applyFilters();
}

function clearAttrFilters() {
    activeProf = activeSubject = activeLevel = activeGroup = '';
    document.getElementById('filterProf').value    = '';
    document.getElementById('filterSubject').value = '';
    document.getElementById('filterLevel').value   = '';
    document.getElementById('filterGroup').value   = '';
    applyFilters();
}

// ─── Open Add Modal ───────────────────────────
function openAddModal() {
    resetForm();
    document.getElementById('modalTitle').textContent = 'Add Session';
    document.getElementById('modalIcon').className    =
        'fa-solid fa-calendar-plus';
    document.getElementById('submitText').textContent = 'Save Session';
    document.getElementById('sessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Open Add Modal with prefilled slot ───────
function openAddModalWithSlot(day, start, end) {
    openAddModal();
    document.getElementById('sessionDay').value   = day;
    document.getElementById('sessionStart').value = start;
    document.getElementById('sessionEnd').value   = end;
    updateSlotStatus();
}

// ─── Open Edit Modal ──────────────────────────
function openEditModal(
    id, day, start, end, type,
    profId, subjectId, groupId, roomId
) {
    resetForm();
    document.getElementById('sessionId').value       = id;
    document.getElementById('sessionDay').value      = day;
    document.getElementById('sessionStart').value    = start;
    document.getElementById('sessionEnd').value      = end;
    document.getElementById('sessionType').value     = type;
    document.getElementById('sessionProfessor').value= profId;
    document.getElementById('sessionSubject').value  = subjectId;
    document.getElementById('sessionGroup').value    = groupId;
    document.getElementById('sessionRoom').value     = roomId;

    // Track original slot so editing self isn't flagged as "occupied"
    editOriginal = {
        profId:    String(profId),
        day:       day,
        timeStart: start.substring(0, 5),
    };
    updateSlotStatus();

    document.getElementById('modalTitle').textContent =
        'Edit Session';
    document.getElementById('modalIcon').className    =
        'fa-solid fa-calendar-pen';
    document.getElementById('submitText').textContent =
        'Update Session';
    document.getElementById('sessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Close Modal ──────────────────────────────
function closeModal() {
    document.getElementById('sessionModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ─── Reset Form ───────────────────────────────
function resetForm() {
    document.getElementById('sessionId').value        = '';
    document.getElementById('sessionDay').value       = 'Saturday';
    document.getElementById('sessionStart').value     = '08:00:00';
    document.getElementById('sessionEnd').value       = '09:30:00';
    document.getElementById('sessionType').value      = 'lecture';
    document.getElementById('sessionProfessor').value = '';
    document.getElementById('sessionSubject').value   = '';
    document.getElementById('sessionGroup').value     = '';
    document.getElementById('sessionRoom').value      = '';
    document.getElementById('modalAlert').style.display = 'none';

    // Clear edit tracking and reset badge / button
    editOriginal = { profId: null, day: null, timeStart: null };
    const badge     = document.getElementById('slotStatusBadge');
    const submitBtn = document.getElementById('submitBtn');
    badge.style.display        = 'none';
    submitBtn.disabled         = false;
    submitBtn.style.background = 'var(--color-mint-dark)';
    submitBtn.style.opacity    = '1';
}

// ─── Close on backdrop click ──────────────────
document.getElementById('sessionModal')
    .addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

// ─── Show alert in modal ──────────────────────
function showModalAlert(success, message) {
    const box       = document.getElementById('modalAlert');
    box.style.display    = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.color      = success ? '#3a8a5a'  : '#c0392b';
    box.innerHTML = `
        <i class="fa-solid ${
            success
                ? 'fa-circle-check'
                : 'fa-circle-exclamation'
        }"></i>
        &nbsp;${message}`;
}

// ─── Submit Form ──────────────────────────────
document.getElementById('sessionForm')
    .addEventListener('submit', async function (e) {
        e.preventDefault();

        const btn  = document.getElementById('submitBtn');
        const text = document.getElementById('submitText');
        const id   = document.getElementById('sessionId').value;

        btn.disabled     = true;
        text.textContent = 'Saving...';

        const data = {
            id:           id || null,
            day:          document.getElementById('sessionDay').value,
            session_type: document.getElementById('sessionType').value,
            time_start:   document.getElementById('sessionStart').value,
            time_end:     document.getElementById('sessionEnd').value,
            professor_id: document.getElementById('sessionProfessor').value,
            subject_id:   document.getElementById('sessionSubject').value,
            group_id:     document.getElementById('sessionGroup').value,
            room_id:      document.getElementById('sessionRoom').value,
        };

        try {
            const res = await fetch(
                '/TimeTable/api/timetable/update.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(data),
                }
            );

            const result = await res.json();

            if (result.success) {
                showModalAlert(true, result.message);
                setTimeout(() => window.location.reload(), 900);
            } else {
                showModalAlert(false, result.message);
                btn.disabled     = false;
                text.textContent = id ? 'Update Session' : 'Save Session';
            }

        } catch (err) {
            showModalAlert(false, 'Connection error. Please try again.');
            btn.disabled     = false;
            text.textContent = id ? 'Update Session' : 'Save Session';
        }
    });

// ═══════════════════════════════════════════════
//  GENERATE MODAL
// ═══════════════════════════════════════════════

const GEN_LS_KEY = 'tt_gen_settings';
const weights    = { SC1:15, SC2:15, SC3:10, SC4:5, SC5:5, SC6:20, SC7:15 };
let   _pendingSessions = null; // holds solver output awaiting admin approval

// ── localStorage helpers ──────────────────────
function saveGenSettings() {
    const sc = {};
    for (const k of Object.keys(weights)) {
        sc[k] = document.getElementById('chk_' + k)?.checked ?? true;
    }
    localStorage.setItem(GEN_LS_KEY, JSON.stringify({
        hc7: document.getElementById('hc7Lunch')?.checked        ?? true,
        hc8: document.getElementById('hc8Saturday')?.checked     ?? true,
        hc4: document.getElementById('hc4Availability')?.checked ?? true,
        weights: { ...weights },
        sc,
    }));
}

function loadGenSettings() {
    try {
        const raw = localStorage.getItem(GEN_LS_KEY);
        if (!raw) return;
        const s = JSON.parse(raw);
        const hc7El = document.getElementById('hc7Lunch');
        const hc8El = document.getElementById('hc8Saturday');
        const hc4El = document.getElementById('hc4Availability');
        if (hc7El && s.hc7 !== undefined) hc7El.checked = s.hc7;
        if (hc8El && s.hc8 !== undefined) hc8El.checked = s.hc8;
        if (hc4El && s.hc4 !== undefined) hc4El.checked = s.hc4;
        if (s.weights) {
            for (const [k, v] of Object.entries(s.weights)) {
                if (k in weights) {
                    weights[k] = Math.max(0, Math.min(20, parseInt(v) || 0));
                    const el = document.getElementById('wval_' + k);
                    if (el) el.textContent = weights[k];
                }
            }
        }
        if (s.sc) {
            for (const [k, v] of Object.entries(s.sc)) {
                const cb = document.getElementById('chk_' + k);
                if (cb) { cb.checked = v; toggleSC(k); }
            }
        }
    } catch(e) {}
}

// ── Modal open / close ────────────────────────
function openGenerateModal() {
    // Reset to settings view
    ['genSettings','genRunBtn'].forEach(id =>
        document.getElementById(id).style.display = 'block');
    document.getElementById('genRunBtn').style.display  = 'flex';
    ['genProgress','genResult','genPreview','genLogsPanel','genAcceptBtn']
        .forEach(id => document.getElementById(id).style.display = 'none');

    const cb = document.getElementById('genCancelBtn');
    cb.textContent      = 'Cancel';
    cb.style.background = 'var(--color-sage)';
    cb.style.color      = 'var(--color-text-dark)';
    cb.onclick          = closeGenerateModal;

    loadGenSettings();
    _pendingSessions = null;

    document.getElementById('generateModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeGenerateModal() {
    document.getElementById('generateModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('generateModal')
    .addEventListener('click', e => { if (e.target.id === 'generateModal') closeGenerateModal(); });

// ── Constraint controls ───────────────────────
function adjustWeight(key, delta) {
    weights[key] = Math.max(0, Math.min(20, (weights[key] || 0) + delta));
    document.getElementById('wval_' + key).textContent = weights[key];
    saveGenSettings();
}

function toggleSC(key) {
    const checked = document.getElementById('chk_' + key).checked;
    const row     = document.getElementById('row_' + key);
    row.classList.toggle('sc-disabled', !checked);
    row.style.borderColor = checked ? 'var(--color-border)' : '#e5e7eb';
    saveGenSettings();
}

function collectConfig() {
    const w = {};
    for (const key of Object.keys(weights)) {
        w[key] = document.getElementById('chk_' + key).checked ? weights[key] : 0;
    }
    return {
        hc7_lunch:        document.getElementById('hc7Lunch').checked,
        hc8_saturday:     document.getElementById('hc8Saturday').checked,
        hc4_availability: document.getElementById('hc4Availability').checked,
        weights: w,
    };
}

// ── Run solver ────────────────────────────────
async function startGeneration() {
    saveGenSettings();

    document.getElementById('genSettings').style.display  = 'none';
    document.getElementById('genResult').style.display    = 'none';
    document.getElementById('genPreview').style.display   = 'none';
    document.getElementById('genLogsPanel').style.display = 'none';
    document.getElementById('genRunBtn').style.display    = 'none';
    document.getElementById('genAcceptBtn').style.display = 'none';

    const progress    = document.getElementById('genProgress');
    const progressBar = document.getElementById('genProgressBar');
    const progressMsg = document.getElementById('genProgressMsg');
    progress.style.display  = 'block';
    progressBar.style.width = '0%';

    const msgs = [
        'Loading data…',
        'Generating constraint domains…',
        'Applying MRV heuristic…',
        'Running backtracking search…',
        'Checking hard constraints…',
        'Scoring soft constraints…',
        'Almost done…',
    ];
    let pct = 0, msgIdx = 0;
    const ticker = setInterval(() => {
        if (pct < 85) { pct += Math.random() * 9 + 2; progressBar.style.width = Math.min(pct, 85) + '%'; }
        if (msgIdx < msgs.length - 1) progressMsg.textContent = msgs[++msgIdx];
    }, 1100);

    try {
        const res = await fetch('/TimeTable/api/timetable/generate.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ generate: true, constraints: collectConfig() }),
        });
        const result = await res.json();

        clearInterval(ticker);
        progressBar.style.width = '100%';
        await new Promise(r => setTimeout(r, 380));
        progress.style.display = 'none';

        showLogs(result.logs);

        if (result.success && result.sessions_data?.length) {
            _pendingSessions = result.sessions_data;
            renderPreview(result);
        } else {
            renderFinalResult(result);
        }
    } catch (err) {
        clearInterval(ticker);
        progress.style.display = 'none';
        renderFinalResult({ success: false, message: 'Connection error: ' + err.message });
    }
}

// ── Preview panel ─────────────────────────────
function renderPreview(result) {
    const dayOrder = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];
    const typeInfo = {
        lecture: { label:'Lesson', color:'#2563eb', bg:'#dbeafe' },
        lab:     { label:'TP',     color:'#d97706', bg:'#fef3c7' },
        seminar: { label:'TD',     color:'#7c3aed', bg:'#ede9fe' },
    };

    // Stats strip
    const stats = [
        { value: result.sessions,   label: 'Sessions',   color: '#6D28D9' },
        { value: result.score,      label: 'Score',      color: '#059669' },
        { value: result.backtracks, label: 'Backtracks', color: '#d97706' },
        { value: result.elapsed+'s',label: 'Elapsed',    color: '#2563eb' },
    ];
    document.getElementById('genPreviewStats').innerHTML =
        stats.map((s, i) => `
            <div style="
                flex:1;text-align:center;padding:12px 8px;
                ${i < stats.length-1 ? 'border-right:1.5px solid #ddd6fe;' : ''}
                background:${i % 2 === 0 ? '#faf8ff' : 'white'};">
                <div style="font-size:20px;font-weight:700;color:${s.color};">${s.value}</div>
                <div style="font-size:11px;color:var(--color-text-light);margin-top:2px;">${s.label}</div>
            </div>`).join('');

    // Group sessions by day
    const byDay = {};
    for (const s of result.sessions_data) {
        if (!byDay[s.day]) byDay[s.day] = [];
        byDay[s.day].push(s);
    }

    let html = `<table style="width:100%;border-collapse:collapse;">
        <thead><tr style="background:var(--color-sage);position:sticky;top:0;z-index:1;">
            <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);white-space:nowrap;">Day &amp; Time</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Subject</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Professor</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Group</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Room</th>
        </tr></thead><tbody>`;

    for (const day of dayOrder) {
        const sessions = (byDay[day] || []).sort((a, b) => a.time_start.localeCompare(b.time_start));
        if (!sessions.length) continue;
        html += `<tr><td colspan="5" style="
            padding:5px 10px;font-size:11px;font-weight:700;color:#7C3AED;
            background:linear-gradient(90deg,#f5f3ff,transparent);
            border-top:1.5px solid #ddd6fe;">${day}</td></tr>`;
        for (const s of sessions) {
            const ti = typeInfo[s.session_type] ?? typeInfo.lecture;
            const t  = s.time_start.substring(0,5) + '–' + s.time_end.substring(0,5);
            html += `<tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:7px 10px;white-space:nowrap;">
                    <span style="display:inline-block;padding:1px 6px;border-radius:3px;
                        background:${ti.bg};color:${ti.color};font-size:10px;font-weight:700;margin-bottom:2px;">
                        ${ti.label}</span>
                    <div style="font-size:12px;font-weight:600;color:var(--color-text-dark);">${t}</div>
                </td>
                <td style="padding:7px 10px;font-size:12px;">${escHtml(s.subject_name)}</td>
                <td style="padding:7px 10px;font-size:12px;">${escHtml(s.professor_name)}</td>
                <td style="padding:7px 10px;font-size:12px;">${escHtml(s.group_name)}</td>
                <td style="padding:7px 10px;font-size:12px;">${escHtml(s.room_name)}</td>
            </tr>`;
        }
    }
    html += '</tbody></table>';
    document.getElementById('genPreviewTable').innerHTML = html;

    document.getElementById('genPreview').style.display = 'block';

    // Footer: show Accept & Re-configure
    document.getElementById('genAcceptBtn').style.display = 'flex';

    const cb = document.getElementById('genCancelBtn');
    cb.textContent      = '← Re-configure';
    cb.style.background = 'var(--color-sage)';
    cb.style.color      = 'var(--color-text-dark)';
    cb.onclick = () => {
        document.getElementById('genPreview').style.display   = 'none';
        document.getElementById('genAcceptBtn').style.display = 'none';
        document.getElementById('genSettings').style.display  = 'block';
        document.getElementById('genRunBtn').style.display    = 'flex';
        cb.textContent = 'Cancel';
        cb.onclick     = closeGenerateModal;
    };
}

// ── Accept & Save ─────────────────────────────
async function saveGenerated() {
    if (!_pendingSessions) return;

    document.getElementById('genPreview').style.display   = 'none';
    document.getElementById('genAcceptBtn').style.display = 'none';

    const cancelBtn   = document.getElementById('genCancelBtn');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick     = closeGenerateModal;

    const progress    = document.getElementById('genProgress');
    const progressBar = document.getElementById('genProgressBar');
    const progressMsg = document.getElementById('genProgressMsg');
    progress.style.display  = 'block';
    progressBar.style.width = '55%';
    progressMsg.textContent = 'Saving timetable to database…';

    try {
        const res = await fetch('/TimeTable/api/timetable/generate.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ confirm_save: true, sessions_data: _pendingSessions }),
        });
        const result = await res.json();

        progressBar.style.width = '100%';
        await new Promise(r => setTimeout(r, 300));
        progress.style.display = 'none';
        _pendingSessions = null;
        renderFinalResult(result);
    } catch (err) {
        progress.style.display = 'none';
        renderFinalResult({ success: false, message: 'Save failed: ' + err.message });
    }
}

// ── Final result ──────────────────────────────
function renderFinalResult(result) {
    const resultDiv = document.getElementById('genResult');
    const runBtn    = document.getElementById('genRunBtn');
    const cancelBtn = document.getElementById('genCancelBtn');

    if (result.success) {
        resultDiv.innerHTML = `
            <div style="padding:16px 18px;background:#f0fdf4;border:1.5px solid #86efac;
                border-radius:var(--radius-md);display:flex;align-items:flex-start;gap:12px;">
                <i class="fa-solid fa-circle-check"
                   style="color:#16a34a;font-size:22px;margin-top:2px;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#15803d;margin-bottom:4px;">
                        Timetable saved successfully
                    </div>
                    <div style="font-size:13px;color:#166534;">${result.message}</div>
                </div>
            </div>`;
        runBtn.style.display       = 'none';
        cancelBtn.textContent      = 'Close & Refresh';
        cancelBtn.style.background = 'linear-gradient(135deg,#8B5CF6,#7C3AED)';
        cancelBtn.style.color      = 'white';
        cancelBtn.onclick          = () => window.location.reload();
    } else {
        resultDiv.innerHTML = `
            <div style="padding:16px 18px;background:#fef2f2;border:1.5px solid #fca5a5;
                border-radius:var(--radius-md);display:flex;align-items:flex-start;gap:12px;">
                <i class="fa-solid fa-circle-xmark"
                   style="color:#dc2626;font-size:22px;margin-top:2px;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#b91c1c;margin-bottom:4px;">
                        ${_pendingSessions ? 'Save failed' : 'Generation failed'}
                    </div>
                    <div style="font-size:13px;color:#991b1b;">${result.message ?? 'Unknown error'}</div>
                </div>
            </div>`;
        document.getElementById('genSettings').style.display = 'block';
        runBtn.style.display = 'flex';
        runBtn.innerHTML     = '<i class="fa-solid fa-rotate-right"></i>&nbsp;Retry';
    }
    resultDiv.style.display = 'block';
    showLogs(result.logs);
}

// ── Logs ──────────────────────────────────────
function showLogs(logs) {
    if (!logs?.length) return;
    const colors = { INFO:'#86efac', WARN:'#fde68a', ERROR:'#fca5a5' };
    document.getElementById('genLogsPanel').style.display = 'block';
    document.getElementById('genLogs').innerHTML =
        logs.map(l =>
            `<div style="color:${colors[l.level] ?? '#e2e8f0'};">`
          + `[${l.time}] <span style="font-weight:700;">${l.level}</span> ${l.msg}</div>`
        ).join('');
}

function toggleLogs() {
    const logs    = document.getElementById('genLogs');
    const chevron = document.getElementById('logsChevron');
    const visible = logs.style.display !== 'none';
    logs.style.display      = visible ? 'none' : 'block';
    chevron.style.transform = visible ? '' : 'rotate(90deg)';
}

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ─── Delete Session ───────────────────────────
async function deleteSession(id, name) {
    if (!confirm(`Delete session "${name}"?`)) return;

    try {
        const res = await fetch(
            '/TimeTable/api/timetable/update.php',
            {
                method:  'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ id }),
            }
        );

        const result = await res.json();

        if (result.success) {
            window.location.reload();
        } else {
            alert('Error: ' + result.message);
        }

    } catch (err) {
        alert('Connection error. Please try again.');
    }
}

</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>