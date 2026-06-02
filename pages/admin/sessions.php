<?php
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'sessions';
$pageTitle    = 'Session Assignments';
$pageSubtitle = 'Define which sessions the CSP solver must schedule.';

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Load sessions with display names ─────────
$sessions = $pdo->query("
    SELECT
        sa.id,
        sa.professor_id,
        sa.subject_id,
        sa.group_id,
        sa.session_type,
        sa.created_at,
        u.name           AS professor_name,
        s.name           AS subject_name,
        s.code           AS subject_code,
        g.group_name,
        g.level          AS group_level
    FROM session_assignments sa
    JOIN users         u ON u.id = sa.professor_id
    JOIN subjects      s ON s.id = sa.subject_id
    JOIN groups_table  g ON g.id = sa.group_id
    ORDER BY u.name, s.name, g.group_name, sa.session_type
")->fetchAll();

// ─── Schedule settings ────────────────────────
$scheduleSettings = loadScheduleSettings($pdo);
$previewSlots     = computeTimeSlots($scheduleSettings);

// ─── Dropdowns for the modal ───────────────────
$professors = $pdo->query("
    SELECT id, name FROM users
    WHERE role = 'professor' AND is_active = 1
    ORDER BY name
")->fetchAll();

$subjects = $pdo->query("
    SELECT id, name, code FROM subjects ORDER BY name
")->fetchAll();

$groups = $pdo->query("
    SELECT id, group_name, level FROM groups_table ORDER BY level, group_name
")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats ────────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(4,1fr); margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-list-check"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Sessions</p>
            <div class="stat-value"><?= count($sessions) ?></div>
            <p class="stat-sub">To be scheduled</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-chalkboard"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Lectures</p>
            <div class="stat-value">
                <?= count(array_filter($sessions, fn($s) => $s['session_type'] === 'lecture')) ?>
            </div>
            <p class="stat-sub">Lecture sessions</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-flask"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Labs</p>
            <div class="stat-value">
                <?= count(array_filter($sessions, fn($s) => $s['session_type'] === 'lab')) ?>
            </div>
            <p class="stat-sub">Lab sessions</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Seminars</p>
            <div class="stat-value">
                <?= count(array_filter($sessions, fn($s) => $s['session_type'] === 'seminar')) ?>
            </div>
            <p class="stat-sub">Seminar sessions</p>
        </div>
    </div>

</div>

<!-- ─── Info Banner ───────────────────────────── -->
<div style="
    display:flex;
    align-items:flex-start;
    gap:14px;
    padding:14px 18px;
    background:var(--color-mint-light);
    border:1px solid var(--color-mint-dark);
    border-radius:var(--radius-md);
    margin-bottom:20px;">
    <i class="fa-solid fa-circle-info"
       style="color:var(--color-mint-dark); font-size:18px; flex-shrink:0; margin-top:2px;"></i>
    <p style="font-size:13px; color:var(--color-text-mid);">
        Sessions listed here will be automatically assigned a
        <strong>day, time, and room</strong> by the CSP solver when you
        <a href="/TimeTable/pages/admin/generate.php"
           style="color:var(--color-mint-dark); font-weight:600;">
            generate the timetable
        </a>.
        No time input is needed — just define <em>who</em> teaches
        <em>what</em> to <em>which group</em>.
    </p>
</div>

<!-- ─── Schedule Settings Card ───────────────── -->
<div class="card" style="margin-bottom:20px; padding:20px 24px;">

    <p style="font-size:13px; font-weight:700; color:var(--color-text-dark); margin-bottom:16px; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-sliders" style="color:var(--color-mint-dark);"></i>
        Schedule Settings
    </p>

    <div style="display:flex; align-items:flex-start; gap:24px; flex-wrap:wrap;">

        <!-- Left column: two rows of settings -->
        <div style="flex:1; min-width:280px; display:flex; flex-direction:column; gap:18px;">

            <!-- Row 1: Break between sessions -->
            <div>
                <p style="font-size:12px; font-weight:600; color:var(--color-text-mid); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-clock" style="color:var(--color-mint-dark); font-size:11px;"></i>
                    Break Between Sessions
                </p>
                <div style="display:flex; gap:6px; flex-wrap:wrap;" id="breakBtns">
                    <?php foreach ([0, 10, 15, 30, 45] as $min): ?>
                        <?php $active = (int)$scheduleSettings['break_duration_minutes'] === $min; ?>
                        <button type="button"
                                data-break="<?= $min ?>"
                                onclick="setBreak(<?= $min ?>)"
                                style="
                                    padding:6px 14px;
                                    border-radius:var(--radius-md);
                                    font-size:13px; font-weight:600;
                                    cursor:pointer;
                                    transition:var(--transition);
                                    border:2px solid <?= $active ? 'var(--color-mint-dark)' : 'var(--color-border)' ?>;
                                    background:<?= $active ? 'var(--color-mint-dark)' : 'var(--color-white)' ?>;
                                    color:<?= $active ? 'white' : 'var(--color-text-dark)' ?>;">
                            <?= $min ?> min
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Row 2: Lunch break window -->
            <div>
                <p style="font-size:12px; font-weight:600; color:var(--color-text-mid); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-utensils" style="color:#e08a2a; font-size:11px;"></i>
                    Lunch Break
                </p>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="font-size:12px; color:var(--color-text-light); white-space:nowrap;">From</label>
                        <input type="time"
                               id="lunchFrom"
                               value="<?= substr($scheduleSettings['lunch_start_time'], 0, 5) ?>"
                               onchange="saveLunch()"
                               style="
                                   height:38px; padding:0 10px;
                                   border:1.5px solid var(--color-border);
                                   border-radius:var(--radius-md);
                                   font-size:13px; font-weight:600;
                                   color:var(--color-text-dark);
                                   background:var(--color-cream);
                                   cursor:pointer;">
                    </div>
                    <span style="font-size:13px; color:var(--color-text-light);">—</span>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="font-size:12px; color:var(--color-text-light); white-space:nowrap;">To</label>
                        <input type="time"
                               id="lunchTo"
                               value="<?= substr($scheduleSettings['lunch_end_time'], 0, 5) ?>"
                               onchange="saveLunch()"
                               style="
                                   height:38px; padding:0 10px;
                                   border:1.5px solid var(--color-border);
                                   border-radius:var(--radius-md);
                                   font-size:13px; font-weight:600;
                                   color:var(--color-text-dark);
                                   background:var(--color-cream);
                                   cursor:pointer;">
                    </div>
                    <span id="lunchValidation" style="font-size:12px; display:none;"></span>
                </div>

            </div>

        </div>

        <!-- Right: resulting slots preview -->
        <div style="
            background:var(--color-cream);
            border:1px solid var(--color-border);
            border-radius:var(--radius-md);
            padding:12px 18px;
            min-width:200px;
            align-self:stretch;
            display:flex; flex-direction:column; justify-content:center;">
            <p style="font-size:11px; font-weight:700; color:var(--color-text-light); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">
                Resulting Time Slots
            </p>
            <div id="slotsPreview" style="display:flex; flex-direction:column; gap:5px;">
                <?php foreach ($previewSlots as $sl): ?>
                    <span style="font-size:12px; font-weight:600; color:var(--color-text-dark);">
                        <i class="fa-regular fa-clock" style="font-size:10px; color:var(--color-mint-dark); margin-right:4px;"></i>
                        <?= $sl['label'] ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <!-- Save status -->
    <div id="breakSaveStatus" style="display:none; margin-top:12px; font-size:13px;"></div>

</div>

<!-- ─── Toolbar ──────────────────────────────── -->
<div style="
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;">

    <div style="position:relative; width:300px;">
        <i class="fa-solid fa-magnifying-glass"
           style="
               position:absolute; left:12px; top:50%;
               transform:translateY(-50%);
               color:var(--color-text-light); font-size:13px;">
        </i>
        <input type="text"
               id="searchInput"
               placeholder="Search sessions..."
               oninput="filterSessions()"
               style="
                   width:100%; height:42px;
                   padding:0 14px 0 36px;
                   border:1.5px solid var(--color-border);
                   border-radius:var(--radius-md);
                   font-size:13px;
                   background:var(--color-white);
                   color:var(--color-text-dark);">
    </div>

    <div style="display:flex; gap:10px; align-items:center;">
        <button onclick="openGenerateModal()"
                style="
                    display:flex; align-items:center; gap:8px;
                    padding:10px 18px;
                    background:linear-gradient(135deg,#8B5CF6,#7C3AED);
                    color:white; border:none;
                    border-radius:var(--radius-md);
                    font-size:14px; font-weight:600;
                    cursor:pointer;
                    box-shadow:0 4px 12px rgba(124,58,237,0.35);
                    transition:var(--transition);"
                onmouseover="this.style.background='linear-gradient(135deg,#7C3AED,#6D28D9)'"
                onmouseout="this.style.background='linear-gradient(135deg,#8B5CF6,#7C3AED)'">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            Generate Timetable
        </button>
        <button onclick="openModal()"
                style="
                    display:flex; align-items:center; gap:8px;
                    padding:10px 20px;
                    background:var(--color-mint-dark);
                    color:white; border:none;
                    border-radius:var(--radius-md);
                    font-size:14px; font-weight:600;
                    cursor:pointer;
                    box-shadow:0 4px 12px rgba(142,203,182,0.40);
                    transition:var(--transition);"
                onmouseover="this.style.background='#6BB8A0'"
                onmouseout="this.style.background='var(--color-mint-dark)'">
            <i class="fa-solid fa-plus"></i>
            Add Session
        </button>
    </div>

</div>

<!-- ─── Sessions Table ────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($sessions)): ?>

        <div style="
            text-align:center; padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-list-check"
               style="
                   font-size:48px; display:block;
                   margin-bottom:16px; opacity:0.3;">
            </i>
            <p style="font-size:15px; font-weight:600; color:var(--color-text-dark); margin-bottom:6px;">
                No sessions yet
            </p>
            <p style="font-size:13px;">
                Click "Add Session" to define what the solver should schedule.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;" id="sessionsTable">
            <thead>
                <tr style="background:var(--color-sage); border-bottom:2px solid var(--color-border);">
                    <th style="padding:14px 20px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Professor</th>
                    <th style="padding:14px 20px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Subject</th>
                    <th style="padding:14px 20px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Group</th>
                    <th style="padding:14px 20px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Type</th>
                    <th style="padding:14px 20px; text-align:center; font-size:12px; font-weight:600; color:var(--color-text-mid);">Action</th>
                </tr>
            </thead>
            <tbody id="sessionsBody">
                <?php foreach ($sessions as $i => $s):
                    $typeInfo = match($s['session_type']) {
                        'lecture' => ['label' => 'Lecture', 'bg' => 'var(--color-mint-light)', 'color' => 'var(--color-mint-dark)', 'icon' => 'fa-chalkboard'],
                        'lab'     => ['label' => 'Lab',     'bg' => 'var(--color-rose-light)', 'color' => 'var(--color-rose-dark)', 'icon' => 'fa-flask'],
                        'seminar' => ['label' => 'Seminar', 'bg' => 'var(--color-sage)',       'color' => '#5a8a6a',                'icon' => 'fa-users'],
                        default   => ['label' => ucfirst($s['session_type']), 'bg' => 'var(--color-cream)', 'color' => 'var(--color-text-mid)', 'icon' => 'fa-calendar'],
                    };
                ?>
                <tr class="session-row"
                    style="
                        border-bottom:1px solid var(--color-border);
                        background:<?= $i % 2 === 0 ? 'var(--color-white)' : 'var(--color-cream)' ?>;
                        transition:var(--transition);"
                    onmouseover="this.style.background='var(--color-mint-light)'"
                    onmouseout="this.style.background='<?= $i % 2 === 0 ? 'var(--color-white)' : 'var(--color-cream)' ?>'">

                    <!-- Professor -->
                    <td style="padding:14px 20px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="
                                width:34px; height:34px;
                                border-radius:50%;
                                background:var(--color-mint-light);
                                display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fa-solid fa-chalkboard-user"
                                   style="font-size:14px; color:var(--color-mint-dark);"></i>
                            </div>
                            <span style="font-size:14px; font-weight:600; color:var(--color-text-dark);">
                                <?= htmlspecialchars($s['professor_name']) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Subject -->
                    <td style="padding:14px 20px;">
                        <div>
                            <span style="font-size:14px; font-weight:600; color:var(--color-text-dark);">
                                <?= htmlspecialchars($s['subject_name']) ?>
                            </span>
                            <span style="
                                display:inline-block;
                                margin-left:8px;
                                font-size:11px;
                                padding:1px 7px;
                                background:var(--color-sage);
                                color:var(--color-text-mid);
                                border-radius:4px;">
                                <?= htmlspecialchars($s['subject_code']) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Group -->
                    <td style="padding:14px 20px;">
                        <span style="font-size:13px; color:var(--color-text-dark);">
                            <?= htmlspecialchars($s['group_name']) ?>
                        </span>
                        <span style="font-size:11px; color:var(--color-text-light); margin-left:4px;">
                            <?= htmlspecialchars($s['group_level']) ?>
                        </span>
                    </td>

                    <!-- Type -->
                    <td style="padding:14px 20px;">
                        <span style="
                            padding:4px 12px;
                            background:<?= $typeInfo['bg'] ?>;
                            color:<?= $typeInfo['color'] ?>;
                            border-radius:20px;
                            font-size:12px; font-weight:600;
                            display:inline-flex; align-items:center; gap:6px;">
                            <i class="fa-solid <?= $typeInfo['icon'] ?>" style="font-size:11px;"></i>
                            <?= $typeInfo['label'] ?>
                        </span>
                    </td>

                    <!-- Delete -->
                    <td style="padding:14px 20px; text-align:center;">
                        <button onclick="deleteSession(
                            <?= $s['id'] ?>,
                            '<?= addslashes($s['professor_name']) ?> — <?= addslashes($s['subject_name']) ?>'
                        )"
                            style="
                                width:34px; height:34px;
                                border-radius:var(--radius-sm);
                                background:var(--color-rose-light);
                                border:1px solid var(--color-rose);
                                color:var(--color-rose-dark);
                                cursor:pointer;
                                display:inline-flex; align-items:center; justify-content:center;
                                transition:var(--transition);"
                            onmouseover="this.style.background='var(--color-rose)'; this.style.color='white';"
                            onmouseout="this.style.background='var(--color-rose-light)'; this.style.color='var(--color-rose-dark)';">
                            <i class="fa-solid fa-trash" style="font-size:13px;"></i>
                        </button>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     ADD SESSION MODAL
═══════════════════════════════════════════════ -->
<div id="sessionModal"
     style="
         display:none;
         position:fixed; inset:0;
         background:rgba(0,0,0,0.50);
         z-index:999;
         align-items:center;
         justify-content:center;">

    <div style="
        background:var(--color-white);
        border-radius:var(--radius-lg);
        padding:32px;
        width:100%; max-width:480px;
        margin:20px;
        box-shadow:var(--shadow-lg);
        position:relative;">

        <!-- Close -->
        <button onclick="closeModal()"
                style="
                    position:absolute; top:16px; right:16px;
                    width:32px; height:32px;
                    border-radius:50%;
                    background:var(--color-sage);
                    border:none; cursor:pointer;
                    display:flex; align-items:center; justify-content:center;
                    font-size:14px; color:var(--color-text-mid);">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Title -->
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:24px;">
            <div style="
                width:42px; height:42px;
                border-radius:var(--radius-md);
                background:var(--color-mint-light);
                display:flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-plus" style="color:var(--color-mint-dark); font-size:18px;"></i>
            </div>
            <div>
                <h3 style="font-size:17px; font-weight:700; color:var(--color-text-dark);">
                    Add Session
                </h3>
                <p style="font-size:13px; color:var(--color-text-light);">
                    The solver will assign time &amp; room automatically
                </p>
            </div>
        </div>

        <!-- Alert -->
        <div id="modalAlert"
             style="
                 display:none;
                 align-items:center; gap:8px;
                 padding:10px 14px;
                 border-radius:var(--radius-md);
                 font-size:13px;
                 margin-bottom:16px;">
        </div>

        <!-- Form -->
        <form id="sessionForm">

            <!-- Professor -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--color-text-dark); margin-bottom:8px;">
                    Professor
                </label>
                <select id="profId"
                        style="
                            width:100%; height:46px; padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px; color:var(--color-text-dark);
                            background:var(--color-cream); cursor:pointer;"
                        required>
                    <option value="">— Select professor —</option>
                    <?php foreach ($professors as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subject -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--color-text-dark); margin-bottom:8px;">
                    Subject
                </label>
                <select id="subjectId"
                        style="
                            width:100%; height:46px; padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px; color:var(--color-text-dark);
                            background:var(--color-cream); cursor:pointer;"
                        required>
                    <option value="">— Select subject —</option>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>">
                            <?= htmlspecialchars($sub['name']) ?> (<?= htmlspecialchars($sub['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Group -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--color-text-dark); margin-bottom:8px;">
                    Group
                </label>
                <select id="groupId"
                        style="
                            width:100%; height:46px; padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px; color:var(--color-text-dark);
                            background:var(--color-cream); cursor:pointer;"
                        required>
                    <option value="">— Select group —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>">
                            <?= htmlspecialchars($g['group_name']) ?> — <?= htmlspecialchars($g['level']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Session Type -->
            <div style="margin-bottom:24px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--color-text-dark); margin-bottom:8px;">
                    Session Type
                </label>
                <select id="sessionType"
                        style="
                            width:100%; height:46px; padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px; color:var(--color-text-dark);
                            background:var(--color-cream); cursor:pointer;"
                        required>
                    <option value="lecture">Lecture</option>
                    <option value="lab">Lab</option>
                    <option value="seminar">Seminar</option>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:12px;">
                <button type="button" onclick="closeModal()"
                        style="
                            flex:1; height:46px;
                            background:var(--color-sage);
                            color:var(--color-text-dark);
                            border:none; border-radius:var(--radius-md);
                            font-size:14px; font-weight:600; cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="submitBtn"
                        style="
                            flex:2; height:46px;
                            background:var(--color-mint-dark);
                            color:white; border:none;
                            border-radius:var(--radius-md);
                            font-size:14px; font-weight:600; cursor:pointer;
                            display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span id="submitText">Add Session</span>
                </button>
            </div>

        </form>
    </div>
</div>

<script>

function filterSessions() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.session-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function openModal() {
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('sessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('sessionModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('sessionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function showModalAlert(success, message) {
    const box = document.getElementById('modalAlert');
    box.style.display    = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.color      = success ? '#3a8a5a'  : '#c0392b';
    box.innerHTML = `<i class="fa-solid ${success ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i> ${message}`;
}

document.getElementById('sessionForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn  = document.getElementById('submitBtn');
    const text = document.getElementById('submitText');
    btn.disabled     = true;
    text.textContent = 'Adding...';

    const data = {
        professor_id: document.getElementById('profId').value,
        subject_id:   document.getElementById('subjectId').value,
        group_id:     document.getElementById('groupId').value,
        session_type: document.getElementById('sessionType').value,
    };

    try {
        const res    = await fetch('/TimeTable/api/admin/sessions.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
        const result = await res.json();

        if (result.success) {
            showModalAlert(true, result.message);
            setTimeout(() => window.location.reload(), 900);
        } else {
            showModalAlert(false, result.message);
            btn.disabled     = false;
            text.textContent = 'Add Session';
        }
    } catch (err) {
        showModalAlert(false, 'Connection error. Please try again.');
        btn.disabled     = false;
        text.textContent = 'Add Session';
    }
});

// ─── Schedule settings state ──────────────────
let _currentBreak  = <?= (int)$scheduleSettings['break_duration_minutes'] ?>;
const _sessionMin  = <?= (int)$scheduleSettings['session_duration_minutes'] ?>;
const _dayStart    = '<?= $scheduleSettings['day_start_time'] ?>';
const _dayEnd      = '<?= $scheduleSettings['day_end_time'] ?>';

function getLunchFrom() { return document.getElementById('lunchFrom').value; }
function getLunchTo()   { return document.getElementById('lunchTo').value;   }

// ─── Shared slot-computation (mirrors PHP) ────
function toSec(t) {
    const parts = t.split(':').map(Number);
    return parts[0] * 3600 + parts[1] * 60 + (parts[2] || 0);
}
function toLabel(sec) {
    const h = String(Math.floor(sec / 3600)).padStart(2, '0');
    const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
    return h + ':' + m;
}

function computeSlots(breakMin, lunchFrom, lunchTo) {
    const sessionSec  = _sessionMin * 60;
    const breakSec    = breakMin * 60;
    const lunchStartS = toSec(lunchFrom);
    const lunchEndS   = toSec(lunchTo);
    const dayEndS     = toSec(_dayEnd);
    let   current     = toSec(_dayStart);
    const slots       = [];

    while (true) {
        const sessionEnd = current + sessionSec;
        if (sessionEnd > dayEndS) break;

        const overlapsLunch = (current < lunchEndS && sessionEnd > lunchStartS);

        if (!overlapsLunch) {
            slots.push(toLabel(current) + ' - ' + toLabel(sessionEnd));
            current = sessionEnd + breakSec;
        } else {
            // Lunch cancels adjacent breaks — resume directly at lunch end
            current = lunchEndS;
        }
        if (current >= dayEndS) break;
    }
    return slots;
}

function updateSlotsPreview() {
    const slots = computeSlots(_currentBreak, getLunchFrom(), getLunchTo());
    document.getElementById('slotsPreview').innerHTML = slots.length
        ? slots.map(s =>
            `<span style="font-size:12px;font-weight:600;color:var(--color-text-dark);">
                <i class="fa-regular fa-clock" style="font-size:10px;color:var(--color-mint-dark);margin-right:4px;"></i>${s}
             </span>`).join('')
        : '<span style="font-size:12px;color:var(--color-rose-dark);">No slots fit — check times</span>';
}

// ─── Save helper ──────────────────────────────
let _saveTimer = null;
async function saveSettings(payload, label) {
    const status = document.getElementById('breakSaveStatus');
    status.style.display = 'block';
    status.style.color   = 'var(--color-text-light)';
    status.innerHTML     = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
        const res    = await fetch('/TimeTable/api/admin/settings.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const result = await res.json();

        if (result.success) {
            status.style.color = '#3a8a5a';
            status.innerHTML   = `<i class="fa-solid fa-circle-check"></i> Saved — ${label}`;
        } else {
            status.style.color = '#c0392b';
            status.innerHTML   = `<i class="fa-solid fa-circle-xmark"></i> ${result.message}`;
        }
    } catch (err) {
        status.style.color = '#c0392b';
        status.innerHTML   = '<i class="fa-solid fa-circle-xmark"></i> Connection error';
    }
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(() => { status.style.display = 'none'; }, 3500);
}

// ─── Break-time selector ──────────────────────
async function setBreak(minutes) {
    if (minutes === _currentBreak) return;
    _currentBreak = minutes;

    document.querySelectorAll('#breakBtns button').forEach(btn => {
        const active = parseInt(btn.dataset.break) === minutes;
        btn.style.border     = active ? '2px solid var(--color-mint-dark)' : '2px solid var(--color-border)';
        btn.style.background = active ? 'var(--color-mint-dark)' : 'var(--color-white)';
        btn.style.color      = active ? 'white' : 'var(--color-text-dark)';
    });

    updateSlotsPreview();
    await saveSettings({ break_duration_minutes: minutes }, `${minutes} min break between sessions`);
}

// ─── Lunch break pickers ──────────────────────
let _lunchTimer = null;
function saveLunch() {
    const from = getLunchFrom();
    const to   = getLunchTo();

    // Validate from < to
    const validation = document.getElementById('lunchValidation');
    if (from >= to) {
        validation.style.display = 'inline';
        validation.style.color   = '#c0392b';
        validation.textContent   = '⚠ "From" must be before "To"';
        return;
    }
    validation.style.display = 'none';

    updateSlotsPreview();

    // Debounce saves (wait for user to finish picking)
    clearTimeout(_lunchTimer);
    _lunchTimer = setTimeout(async () => {
        await saveSettings({
            lunch_start_time: from + ':00',
            lunch_end_time:   to   + ':00',
        }, `Lunch ${from} – ${to}`);
    }, 600);
}


async function deleteSession(id, label) {
    if (!confirm(`Remove session "${label}"?`)) return;

    try {
        const res    = await fetch('/TimeTable/api/admin/sessions.php', {
            method:  'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ id }),
        });
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

<!-- ═══════════════════════════════════════════════
     GENERATE TIMETABLE MODAL
═══════════════════════════════════════════════ -->
<style>
.gen-toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.gen-toggle input{opacity:0;width:0;height:0}
.gen-toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:12px;cursor:pointer;transition:.3s}
.gen-toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:white;border-radius:50%;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.gen-toggle input:checked + .gen-toggle-slider{background:#7C3AED}
.gen-toggle input:checked + .gen-toggle-slider:before{transform:translateX(20px)}
.weight-stepper{display:flex;align-items:center;border:1.5px solid var(--color-border);border-radius:8px;overflow:hidden;flex-shrink:0}
.weight-btn{width:28px;height:28px;background:var(--color-sage);border:none;cursor:pointer;font-size:14px;font-weight:700;color:var(--color-text-mid);transition:.15s;display:flex;align-items:center;justify-content:center}
.weight-btn:hover{background:#d4dbd7;color:var(--color-text-dark)}
.weight-val{width:32px;height:28px;background:white;font-size:13px;font-weight:700;color:var(--color-text-dark);display:flex;align-items:center;justify-content:center;border-left:1.5px solid var(--color-border);border-right:1.5px solid var(--color-border)}
.sc-row{transition:opacity .2s}
.sc-row.sc-disabled{opacity:.4}
.sc-row.sc-disabled .weight-stepper{pointer-events:none}
</style>

<div id="generateModal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;">

    <div style="background:var(--color-white);border-radius:var(--radius-lg);width:100%;max-width:600px;margin:20px;box-shadow:0 24px 64px rgba(0,0,0,.22);position:relative;max-height:92vh;overflow-y:auto;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#8B5CF6,#7C3AED);border-radius:var(--radius-lg) var(--radius-lg) 0 0;padding:22px 26px;display:flex;align-items:center;gap:14px;position:sticky;top:0;z-index:1;">
            <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-wand-magic-sparkles" style="color:white;font-size:20px;"></i>
            </div>
            <div>
                <h3 style="color:white;font-size:17px;font-weight:700;margin:0;">Generate Timetable</h3>
                <p style="color:rgba(255,255,255,.75);font-size:13px;margin:4px 0 0;">Configure constraints and run the CSP solver</p>
            </div>
            <button onclick="closeGenerateModal()" style="margin-left:auto;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.18);border:none;cursor:pointer;color:white;font-size:16px;display:flex;align-items:center;justify-content:center;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="padding:24px 26px;">

            <!-- Settings panel -->
            <div id="genSettings">

                <!-- Hard Constraints -->
                <div style="margin-bottom:22px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span style="padding:3px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Hard</span>
                        <span style="font-size:13px;font-weight:600;color:var(--color-text-mid);">Constraints — must always hold</span>
                    </div>

                    <!-- Fixed -->
                    <div style="border:1.5px solid #fecaca;border-radius:var(--radius-md);overflow:hidden;margin-bottom:10px;">
                        <div style="padding:7px 14px;background:#fff5f5;border-bottom:1px solid #fecaca;display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-lock" style="font-size:10px;color:#ef4444;"></i>
                            <span style="font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;">Always enforced — cannot be disabled</span>
                        </div>
                        <div style="padding:10px 14px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                            <?php foreach ([
                                ['HC1','No Room Double-Booking','fa-door-open'],
                                ['HC2','No Professor Double-Booking','fa-chalkboard-user'],
                                ['HC3','No Group Overlap','fa-users'],
                                ['HC5','Max 4 Prof Sessions/Day','fa-clock'],
                                ['HC6','Max 4 Group Sessions/Day','fa-calendar-day'],
                            ] as $hc): ?>
                            <div style="display:flex;align-items:center;gap:7px;padding:6px 8px;background:#fef9f9;border:1px solid #fee2e2;border-radius:6px;">
                                <i class="fa-solid <?= $hc[2] ?>" style="font-size:11px;color:#ef4444;flex-shrink:0;"></i>
                                <span style="font-size:11px;font-weight:600;color:var(--color-text-mid);">
                                    <span style="color:#dc2626;font-weight:700;margin-right:3px;"><?= $hc[0] ?></span><?= $hc[1] ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Configurable -->
                    <div style="border:1.5px solid #fecaca;border-radius:var(--radius-md);overflow:hidden;">
                        <div style="padding:7px 14px;background:#fff5f5;border-bottom:1px solid #fecaca;display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-sliders" style="font-size:10px;color:#ef4444;"></i>
                            <span style="font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;">Configurable — toggle on / off</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid #fecaca;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc7Lunch" checked onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="font-size:13px;font-weight:600;color:var(--color-text-dark);display:flex;align-items:center;gap:6px;">
                                    <span style="color:#dc2626;font-weight:700;font-size:11px;">HC7</span> No Lunch Break Sessions
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">Prevent sessions from being scheduled during the lunch window</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid #fecaca;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc8Saturday" checked onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="font-size:13px;font-weight:600;color:var(--color-text-dark);display:flex;align-items:center;gap:6px;">
                                    <span style="color:#dc2626;font-weight:700;font-size:11px;">HC8</span> No Saturday Sessions
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">Block all Saturday slots from the solver domain</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;">
                            <label class="gen-toggle">
                                <input type="checkbox" id="hc4Availability" checked onchange="saveGenSettings()">
                                <span class="gen-toggle-slider"></span>
                            </label>
                            <div style="flex:1;">
                                <div style="font-size:13px;font-weight:600;color:var(--color-text-dark);display:flex;align-items:center;gap:6px;">
                                    <span style="color:#dc2626;font-weight:700;font-size:11px;">HC4</span> Respect Professor Availability
                                </div>
                                <div style="font-size:12px;color:var(--color-text-light);margin-top:2px;">Only assign sessions within declared availability windows</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Soft Constraints -->
                <div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                        <span style="padding:3px 8px;border-radius:4px;background:#ede9fe;color:#6D28D9;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Soft</span>
                        <span style="font-size:13px;font-weight:600;color:var(--color-text-mid);">Constraints — optimisation goals &nbsp;<span style="font-weight:400;font-size:12px;">(weight 0–20)</span></span>
                    </div>

                    <?php foreach ([
                        ['SC1',15,'Lecture Before TD / TP',        'Schedule lectures earlier in the week than their companion TD/TP sessions'],
                        ['SC2',15,'Spread Group Sessions',          "Distribute a group's sessions across different days rather than clustering them"],
                        ['SC3',10,'Morning Slots for Lectures',     'Prefer 08:00–11:30 slots for lecture (CM) sessions'],
                        ['SC4', 5,'Room Type Match',                'Assign labs to lab rooms, lectures to lecture halls, seminars to seminar rooms'],
                        ['SC5', 5,'Best-fit Room Capacity',         'Prefer rooms whose capacity is closest to (but not below) the group size'],
                        ['SC6',20,'Professor Preferred Slots',      "Reward placing sessions inside a professor's declared availability windows"],
                        ['SC7',15,'Cluster Professor Days (≤ 2)',   "Group all of a professor's sessions into as few working days as possible"],
                    ] as [$key, $w, $title, $desc]): ?>
                    <div class="sc-row" id="row_<?= $key ?>"
                         style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--color-border);border-radius:var(--radius-md);margin-bottom:6px;">
                        <input type="checkbox" id="chk_<?= $key ?>" checked
                               onchange="toggleSC('<?= $key ?>')"
                               style="width:16px;height:16px;accent-color:#7C3AED;cursor:pointer;flex-shrink:0;">
                        <span style="padding:2px 7px;border-radius:4px;background:#ede9fe;color:#6D28D9;font-size:11px;font-weight:700;flex-shrink:0;"><?= $key ?></span>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--color-text-dark);"><?= $title ?></div>
                            <div style="font-size:11px;color:var(--color-text-light);margin-top:2px;line-height:1.4;"><?= $desc ?></div>
                        </div>
                        <div class="weight-stepper">
                            <button type="button" class="weight-btn" onclick="adjustWeight('<?= $key ?>',-1)">−</button>
                            <span class="weight-val" id="wval_<?= $key ?>"><?= $w ?></span>
                            <button type="button" class="weight-btn" onclick="adjustWeight('<?= $key ?>',+1)">+</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- /#genSettings -->

            <!-- Progress -->
            <div id="genProgress" style="display:none;text-align:center;padding:16px 0;">
                <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#8B5CF6,#7C3AED);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color:white;font-size:22px;"></i>
                </div>
                <p style="font-size:15px;font-weight:600;color:var(--color-text-dark);margin:0 0 6px;">Generating timetable…</p>
                <p id="genProgressMsg" style="font-size:13px;color:var(--color-text-light);margin:0 0 20px;">Running CSP solver with backtracking…</p>
                <div style="height:8px;background:var(--color-sage);border-radius:4px;overflow:hidden;">
                    <div id="genProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,#8B5CF6,#7C3AED);border-radius:4px;transition:width .4s ease;"></div>
                </div>
            </div>

            <!-- Result -->
            <div id="genResult" style="display:none;"></div>

            <!-- Preview -->
            <div id="genPreview" style="display:none;">
                <div id="genPreviewStats" style="display:flex;gap:0;border:1.5px solid #ddd6fe;border-radius:var(--radius-md);overflow:hidden;margin-bottom:14px;"></div>
                <div style="font-size:11px;font-weight:700;color:var(--color-text-light);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-table-cells" style="color:#7C3AED;"></i>
                    Proposed Schedule — review before saving
                </div>
                <div id="genPreviewTable" style="max-height:300px;overflow-y:auto;border:1.5px solid var(--color-border);border-radius:var(--radius-md);font-size:12px;"></div>
                <p style="font-size:12px;color:var(--color-text-light);margin:10px 0 0;display:flex;align-items:flex-start;gap:6px;line-height:1.5;">
                    <i class="fa-solid fa-circle-info" style="color:#7C3AED;margin-top:1px;flex-shrink:0;"></i>
                    This schedule has not been saved yet. Accept to overwrite the current timetable, or re-configure to run the solver again.
                </p>
            </div>

            <!-- Logs -->
            <div id="genLogsPanel" style="display:none;margin-top:14px;">
                <button onclick="toggleLogs()" style="display:flex;align-items:center;gap:6px;background:none;border:none;cursor:pointer;font-size:13px;font-weight:600;color:var(--color-text-light);padding:0 0 8px;">
                    <i class="fa-solid fa-chevron-right" id="logsChevron" style="font-size:10px;transition:.2s;"></i>
                    Solver logs
                </button>
                <div id="genLogs" style="display:none;background:#0f172a;border-radius:8px;padding:14px;max-height:200px;overflow-y:auto;font-family:monospace;font-size:11px;line-height:1.6;"></div>
            </div>

        </div>

        <!-- Footer -->
        <div style="padding:16px 26px;border-top:1px solid var(--color-border);display:flex;justify-content:flex-end;gap:12px;position:sticky;bottom:0;background:var(--color-white);border-radius:0 0 var(--radius-lg) var(--radius-lg);">
            <button id="genCancelBtn" onclick="closeGenerateModal()"
                    style="padding:10px 24px;height:42px;background:var(--color-sage);color:var(--color-text-dark);border:none;border-radius:var(--radius-md);font-size:14px;font-weight:600;cursor:pointer;">
                Cancel
            </button>
            <button id="genRunBtn" onclick="startGeneration()"
                    style="display:flex;align-items:center;gap:8px;padding:10px 24px;height:42px;background:linear-gradient(135deg,#8B5CF6,#7C3AED);color:white;border:none;border-radius:var(--radius-md);font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(124,58,237,.4);">
                <i class="fa-solid fa-play"></i>
                Generate Timetable
            </button>
            <button id="genAcceptBtn" onclick="saveGenerated()"
                    style="display:none;align-items:center;gap:8px;padding:10px 24px;height:42px;background:linear-gradient(135deg,#22c55e,#16a34a);color:white;border:none;border-radius:var(--radius-md);font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(22,163,74,.35);">
                <i class="fa-solid fa-circle-check"></i>
                Accept &amp; Save
            </button>
        </div>

    </div>
</div>

<script>
const GEN_LS_KEY = 'tt_gen_settings';
const weights    = { SC1:15, SC2:15, SC3:10, SC4:5, SC5:5, SC6:20, SC7:15 };
let   _pendingSessions = null;

function saveGenSettings() {
    const sc = {};
    for (const k of Object.keys(weights)) {
        sc[k] = document.getElementById('chk_' + k)?.checked ?? true;
    }
    localStorage.setItem(GEN_LS_KEY, JSON.stringify({
        hc7: document.getElementById('hc7Lunch')?.checked        ?? true,
        hc8: document.getElementById('hc8Saturday')?.checked     ?? true,
        hc4: document.getElementById('hc4Availability')?.checked ?? true,
        weights: { ...weights }, sc,
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

function openGenerateModal() {
    ['genSettings','genRunBtn'].forEach(id => document.getElementById(id).style.display = 'block');
    document.getElementById('genRunBtn').style.display = 'flex';
    ['genProgress','genResult','genPreview','genLogsPanel','genAcceptBtn']
        .forEach(id => document.getElementById(id).style.display = 'none');
    const cb = document.getElementById('genCancelBtn');
    cb.textContent = 'Cancel'; cb.style.background = 'var(--color-sage)';
    cb.style.color = 'var(--color-text-dark)'; cb.onclick = closeGenerateModal;
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
    const msgs = ['Loading data…','Generating constraint domains…','Applying MRV heuristic…','Running backtracking search…','Checking hard constraints…','Scoring soft constraints…','Almost done…'];
    let pct = 0, msgIdx = 0;
    const ticker = setInterval(() => {
        if (pct < 85) { pct += Math.random() * 9 + 2; progressBar.style.width = Math.min(pct, 85) + '%'; }
        if (msgIdx < msgs.length - 1) progressMsg.textContent = msgs[++msgIdx];
    }, 1100);
    try {
        const res = await fetch('/TimeTable/api/timetable/generate.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ generate: true, constraints: collectConfig() }),
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

function renderPreview(result) {
    const dayOrder = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];
    const typeInfo = {
        lecture: { label:'Lesson', color:'#2563eb', bg:'#dbeafe' },
        lab:     { label:'TP',     color:'#d97706', bg:'#fef3c7' },
        seminar: { label:'TD',     color:'#7c3aed', bg:'#ede9fe' },
    };
    const stats = [
        { value: result.sessions,    label: 'Sessions',   color: '#6D28D9' },
        { value: result.score,       label: 'Score',      color: '#059669' },
        { value: result.backtracks,  label: 'Backtracks', color: '#d97706' },
        { value: result.elapsed+'s', label: 'Elapsed',    color: '#2563eb' },
    ];
    document.getElementById('genPreviewStats').innerHTML =
        stats.map((s, i) => `
            <div style="flex:1;text-align:center;padding:12px 8px;${i < stats.length-1 ? 'border-right:1.5px solid #ddd6fe;' : ''}background:${i % 2 === 0 ? '#faf8ff' : 'white'};">
                <div style="font-size:20px;font-weight:700;color:${s.color};">${s.value}</div>
                <div style="font-size:11px;color:var(--color-text-light);margin-top:2px;">${s.label}</div>
            </div>`).join('');
    const byDay = {};
    for (const s of result.sessions_data) {
        if (!byDay[s.day]) byDay[s.day] = [];
        byDay[s.day].push(s);
    }
    let html = `<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:var(--color-sage);position:sticky;top:0;z-index:1;">
        <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);white-space:nowrap;">Day &amp; Time</th>
        <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Subject</th>
        <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Professor</th>
        <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Group</th>
        <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:var(--color-text-mid);">Room</th>
    </tr></thead><tbody>`;
    for (const day of dayOrder) {
        const sessions = (byDay[day] || []).sort((a,b) => a.time_start.localeCompare(b.time_start));
        if (!sessions.length) continue;
        html += `<tr><td colspan="5" style="padding:5px 10px;font-size:11px;font-weight:700;color:#7C3AED;background:linear-gradient(90deg,#f5f3ff,transparent);border-top:1.5px solid #ddd6fe;">${day}</td></tr>`;
        for (const s of sessions) {
            const ti = typeInfo[s.session_type] ?? typeInfo.lecture;
            const t  = s.time_start.substring(0,5) + '–' + s.time_end.substring(0,5);
            html += `<tr style="border-bottom:1px solid var(--color-border);">
                <td style="padding:7px 10px;white-space:nowrap;">
                    <span style="display:inline-block;padding:1px 6px;border-radius:3px;background:${ti.bg};color:${ti.color};font-size:10px;font-weight:700;margin-bottom:2px;">${ti.label}</span>
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
    document.getElementById('genAcceptBtn').style.display = 'flex';
    const cb = document.getElementById('genCancelBtn');
    cb.textContent = '← Re-configure'; cb.style.background = 'var(--color-sage)'; cb.style.color = 'var(--color-text-dark)';
    cb.onclick = () => {
        document.getElementById('genPreview').style.display   = 'none';
        document.getElementById('genAcceptBtn').style.display = 'none';
        document.getElementById('genSettings').style.display  = 'block';
        document.getElementById('genRunBtn').style.display    = 'flex';
        cb.textContent = 'Cancel'; cb.onclick = closeGenerateModal;
    };
}

async function saveGenerated() {
    if (!_pendingSessions) return;
    document.getElementById('genPreview').style.display   = 'none';
    document.getElementById('genAcceptBtn').style.display = 'none';
    const cancelBtn = document.getElementById('genCancelBtn');
    cancelBtn.textContent = 'Cancel'; cancelBtn.onclick = closeGenerateModal;
    const progress    = document.getElementById('genProgress');
    const progressBar = document.getElementById('genProgressBar');
    const progressMsg = document.getElementById('genProgressMsg');
    progress.style.display = 'block'; progressBar.style.width = '55%';
    progressMsg.textContent = 'Saving timetable to database…';
    try {
        const res = await fetch('/TimeTable/api/timetable/generate.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirm_save: true, sessions_data: _pendingSessions }),
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

function renderFinalResult(result) {
    const resultDiv = document.getElementById('genResult');
    const runBtn    = document.getElementById('genRunBtn');
    const cancelBtn = document.getElementById('genCancelBtn');
    if (result.success) {
        resultDiv.innerHTML = `
            <div style="padding:16px 18px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:var(--radius-md);display:flex;align-items:flex-start;gap:12px;">
                <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:22px;margin-top:2px;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#15803d;margin-bottom:4px;">Timetable saved successfully</div>
                    <div style="font-size:13px;color:#166534;">${result.message}</div>
                </div>
            </div>`;
        runBtn.style.display       = 'none';
        cancelBtn.textContent      = 'Close & Go to Timetable';
        cancelBtn.style.background = 'linear-gradient(135deg,#8B5CF6,#7C3AED)';
        cancelBtn.style.color      = 'white';
        cancelBtn.onclick          = () => window.location.href = '/TimeTable/pages/admin/timetable.php';
    } else {
        resultDiv.innerHTML = `
            <div style="padding:16px 18px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:var(--radius-md);display:flex;align-items:flex-start;gap:12px;">
                <i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:22px;margin-top:2px;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:14px;font-weight:700;color:#b91c1c;margin-bottom:4px;">${_pendingSessions ? 'Save failed' : 'Generation failed'}</div>
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

function showLogs(logs) {
    if (!logs?.length) return;
    const colors = { INFO:'#86efac', WARN:'#fde68a', ERROR:'#fca5a5' };
    document.getElementById('genLogsPanel').style.display = 'block';
    document.getElementById('genLogs').innerHTML =
        logs.map(l => `<div style="color:${colors[l.level] ?? '#e2e8f0'};">[${l.time}] <span style="font-weight:700;">${l.level}</span> ${l.msg}</div>`).join('');
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
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
