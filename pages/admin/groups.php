<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'groups';
$pageTitle    = 'Group Management';
$pageSubtitle = 'Assign, move and switch students between groups.';

// ─── Notifications / pending ──────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

$pendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pending'")->fetchColumn();

// ─── Groups with student count ────────────────
$groupRows = $pdo->query("
    SELECT g.id, g.group_name, g.level, g.capacity,
           d.name AS department_name,
           COUNT(sg.student_id) AS student_count
    FROM groups_table g
    LEFT JOIN departments   d  ON g.department_id = d.id
    LEFT JOIN student_groups sg ON g.id = sg.group_id
    GROUP BY g.id
    ORDER BY g.level, g.group_name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── All students with current group ─────────
$studentRows = $pdo->query("
    SELECT u.id, u.name, u.reg_number, u.email,
           sg.group_id,
           g.group_name, g.level
    FROM users u
    LEFT JOIN student_groups sg ON u.id = sg.student_id
    LEFT JOIN groups_table    g ON sg.group_id = g.id
    WHERE u.role = 'student' AND u.is_active = 1
    ORDER BY g.level, g.group_name, u.name
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Build JS-friendly maps ───────────────────
// group_id → [students]
$groupStudents = [];
foreach ($groupRows as $g) {
    $groupStudents[$g['id']] = [];
}
$unassigned = [];
foreach ($studentRows as $s) {
    if ($s['group_id']) {
        $groupStudents[$s['group_id']][] = $s;
    } else {
        $unassigned[] = $s;
    }
}

$totalStudents   = count($studentRows);
$assignedCount   = $totalStudents - count($unassigned);
$unassignedCount = count($unassigned);

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats ──────────────────────────────────── -->
<div class="stats-row" style="grid-template-columns:repeat(4,1fr); margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-icon mint"><i class="fa-solid fa-layer-group"></i></div>
        <div class="stat-body">
            <p class="stat-label">Total Groups</p>
            <div class="stat-value"><?= count($groupRows) ?></div>
            <p class="stat-sub">Active sections</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon sage"><i class="fa-solid fa-user-graduate"></i></div>
        <div class="stat-body">
            <p class="stat-label">Total Students</p>
            <div class="stat-value"><?= $totalStudents ?></div>
            <p class="stat-sub">All enrolled</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon cream"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-body">
            <p class="stat-label">Assigned</p>
            <div class="stat-value"><?= $assignedCount ?></div>
            <p class="stat-sub">In a group</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div class="stat-body">
            <p class="stat-label">Unassigned</p>
            <div class="stat-value"><?= $unassignedCount ?></div>
            <p class="stat-sub">No group yet</p>
        </div>
    </div>

</div>

<!-- ─── Main Layout ─────────────────────────────── -->
<div style="display:grid; grid-template-columns:300px 1fr; gap:20px; align-items:start;">

    <!-- ─── Left: Group List ───────────────────── -->
    <div class="card" style="padding:0; overflow:hidden;">

        <div style="padding:16px 18px; border-bottom:1px solid var(--color-border); display:flex; align-items:center; justify-content:space-between;">
            <span style="font-size:14px; font-weight:700; color:var(--color-text-dark);">
                <i class="fa-solid fa-layer-group" style="color:var(--color-mint-dark); margin-right:6px;"></i>
                Groups
            </span>
        </div>

        <div style="overflow-y:auto; max-height:600px;">

            <!-- Unassigned virtual entry -->
            <div class="group-list-item <?= $unassignedCount > 0 ? '' : 'group-list-dim' ?>"
                 id="item-unassigned"
                 onclick="selectGroup('unassigned')"
                 style="padding:14px 18px; cursor:pointer; border-bottom:1px solid var(--color-border); transition:var(--transition);">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <div>
                        <div style="font-size:13px; font-weight:600; color:var(--color-text-dark);">
                            <i class="fa-solid fa-user-xmark" style="color:var(--color-rose); margin-right:6px;"></i>
                            Unassigned
                        </div>
                        <div style="font-size:11px; color:var(--color-text-light); margin-top:2px;">
                            Students without a group
                        </div>
                    </div>
                    <span style="
                        background:var(--color-rose-light);
                        color:var(--color-rose-dark);
                        font-size:11px; font-weight:700;
                        padding:2px 8px;
                        border-radius:20px;">
                        <?= $unassignedCount ?>
                    </span>
                </div>
            </div>

            <!-- Real groups -->
            <?php foreach ($groupRows as $g):
                $pct = $g['capacity'] > 0
                    ? min(100, round($g['student_count'] / $g['capacity'] * 100))
                    : 0;
                $barColor = $pct >= 90 ? 'var(--color-rose)'
                          : ($pct >= 70 ? '#e08a2a'
                          : 'var(--color-mint-dark)');
            ?>
            <div class="group-list-item"
                 id="item-<?= $g['id'] ?>"
                 onclick="selectGroup(<?= $g['id'] ?>)"
                 style="padding:14px 18px; cursor:pointer; border-bottom:1px solid var(--color-border); transition:var(--transition);">

                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <div>
                        <div style="font-size:13px; font-weight:600; color:var(--color-text-dark);">
                            <?= htmlspecialchars($g['group_name']) ?>
                        </div>
                        <div style="font-size:11px; color:var(--color-text-light); margin-top:1px;">
                            <?= htmlspecialchars($g['department_name'] ?? '—') ?>
                        </div>
                    </div>
                    <span style="
                        background:var(--color-mint-light);
                        color:var(--color-mint-dark);
                        font-size:10px; font-weight:700;
                        padding:2px 7px;
                        border-radius:12px;">
                        <?= htmlspecialchars($g['level']) ?>
                    </span>
                </div>

                <!-- Capacity bar -->
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="flex:1; height:4px; background:var(--color-border); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?= $pct ?>%; background:<?= $barColor ?>; border-radius:4px; transition:width 0.4s;"></div>
                    </div>
                    <span style="font-size:11px; color:var(--color-text-light); white-space:nowrap;">
                        <?= $g['student_count'] ?>/<?= $g['capacity'] ?>
                    </span>
                </div>

            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- ─── Right: Group Detail ─────────────────── -->
    <div class="card" id="detailPanel" style="padding:0; overflow:hidden; min-height:400px;">

        <!-- Placeholder -->
        <div id="detailPlaceholder"
             style="display:flex; flex-direction:column; align-items:center; justify-content:center;
                    height:400px; color:var(--color-text-light); text-align:center; padding:32px;">
            <i class="fa-solid fa-layer-group" style="font-size:48px; color:var(--color-border); margin-bottom:16px;"></i>
            <p style="font-size:15px; font-weight:600; color:var(--color-text-mid);">Select a group</p>
            <p style="font-size:13px; margin-top:4px;">Click a group on the left to manage its students.</p>
        </div>

        <!-- Detail content (filled by JS) -->
        <div id="detailContent" style="display:none;">

            <!-- Header -->
            <div style="padding:16px 20px; border-bottom:1px solid var(--color-border);
                        display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span id="detailGroupName"
                              style="font-size:16px; font-weight:700; color:var(--color-text-dark);"></span>
                        <span id="detailGroupLevel"
                              style="font-size:11px; font-weight:700; padding:2px 8px;
                                     background:var(--color-mint-light); color:var(--color-mint-dark);
                                     border-radius:12px;"></span>
                    </div>
                    <p id="detailGroupMeta"
                       style="font-size:12px; color:var(--color-text-light); margin-top:3px;"></p>
                </div>
                <div id="detailActions" style="display:flex; gap:8px; flex-wrap:wrap;"></div>
            </div>

            <!-- Student list -->
            <div id="studentListWrap" style="overflow-y:auto; max-height:520px;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--color-sage);">
                            <th style="padding:10px 20px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Student</th>
                            <th style="padding:10px 14px; text-align:left; font-size:12px; font-weight:600; color:var(--color-text-mid);">Reg #</th>
                            <th style="padding:10px 14px; text-align:center; font-size:12px; font-weight:600; color:var(--color-text-mid);">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="studentListBody"></tbody>
                </table>

                <!-- Empty state -->
                <div id="studentListEmpty"
                     style="display:none; padding:40px; text-align:center; color:var(--color-text-light);">
                    <i class="fa-solid fa-users-slash" style="font-size:32px; color:var(--color-border); margin-bottom:12px; display:block;"></i>
                    <p id="studentListEmptyMsg" style="font-size:14px;">No students in this group.</p>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- ═══ MODALS ═══════════════════════════════════════════════════════ -->

<!-- ─── Assign / Move Modal ──────────────────────────────────────── -->
<div id="assignModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.50); z-index:999; align-items:center; justify-content:center;">
    <div style="background:var(--color-white); border-radius:var(--radius-lg); padding:28px; width:100%; max-width:480px; margin:20px; box-shadow:var(--shadow-lg); position:relative; max-height:90vh; overflow-y:auto;">

        <button onclick="closeModal('assignModal')"
                style="position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; background:var(--color-sage); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--color-text-mid);">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
            <div style="width:40px; height:40px; border-radius:var(--radius-md); background:var(--color-mint-light); display:flex; align-items:center; justify-content:center;">
                <i id="assignModalIcon" class="fa-solid fa-user-plus" style="color:var(--color-mint-dark);"></i>
            </div>
            <div>
                <h3 id="assignModalTitle" style="font-size:16px; font-weight:700; color:var(--color-text-dark);">Assign to Group</h3>
                <p id="assignModalSub" style="font-size:12px; color:var(--color-text-light);">Select a group for this student</p>
            </div>
        </div>

        <div id="assignModalAlert" style="display:none; padding:10px 14px; border-radius:var(--radius-md); font-size:13px; margin-bottom:14px;"></div>

        <!-- Student select (hidden when pre-filled) -->
        <div id="assignStudentRow" style="margin-bottom:14px;">
            <label class="form-label">Student</label>
            <input type="text" id="assignStudentSearch"
                   class="form-input"
                   placeholder="Search student..."
                   oninput="filterAssignStudents()"
                   style="height:44px; padding:0 14px; margin-bottom:8px;">
            <select id="assignStudentSelect" class="form-input" size="5"
                    style="height:130px; padding:6px 0; border-radius:var(--radius-md);">
            </select>
        </div>

        <!-- Group select (hidden when pre-filled) -->
        <div id="assignGroupRow" style="margin-bottom:20px;">
            <label class="form-label">Target Group</label>
            <select id="assignGroupSelect" class="form-input" style="height:46px; padding:0 14px;">
                <option value="">Select group…</option>
                <?php foreach ($groupRows as $g): ?>
                    <option value="<?= $g['id'] ?>" data-name="<?= htmlspecialchars($g['group_name']) ?>">
                        <?= htmlspecialchars($g['group_name']) ?>
                        (<?= htmlspecialchars($g['level']) ?>) —
                        <?= $g['student_count'] ?>/<?= $g['capacity'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" id="assignHiddenStudent" value="">
        <input type="hidden" id="assignHiddenGroup"   value="">
        <input type="hidden" id="assignAction"         value="assign">

        <div style="display:flex; gap:10px;">
            <button onclick="closeModal('assignModal')"
                    style="flex:1; height:44px; background:var(--color-sage); color:var(--color-text-dark); border:none; border-radius:var(--radius-md); font-size:14px; font-weight:600; cursor:pointer;">
                Cancel
            </button>
            <button id="assignSubmitBtn" onclick="submitAssignMove()"
                    style="flex:2; height:44px; background:var(--color-mint-dark); color:white; border:none; border-radius:var(--radius-md); font-size:14px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                <i class="fa-solid fa-floppy-disk"></i>
                <span id="assignSubmitText">Assign</span>
            </button>
        </div>
    </div>
</div>

<!-- ─── Switch Modal ─────────────────────────────────────────────── -->
<div id="switchModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.50); z-index:999; align-items:center; justify-content:center;">
    <div style="background:var(--color-white); border-radius:var(--radius-lg); padding:28px; width:100%; max-width:520px; margin:20px; box-shadow:var(--shadow-lg); position:relative;">

        <button onclick="closeModal('switchModal')"
                style="position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; background:var(--color-sage); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--color-text-mid);">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
            <div style="width:40px; height:40px; border-radius:var(--radius-md); background:#fff3e0; display:flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-arrow-right-arrow-left" style="color:#e08a2a;"></i>
            </div>
            <div>
                <h3 style="font-size:16px; font-weight:700; color:var(--color-text-dark);">Switch Two Students</h3>
                <p style="font-size:12px; color:var(--color-text-light);">Swap the groups of any two students</p>
            </div>
        </div>

        <div id="switchModalAlert" style="display:none; padding:10px 14px; border-radius:var(--radius-md); font-size:13px; margin-bottom:14px;"></div>

        <!-- Student A -->
        <div style="margin-bottom:14px;">
            <label class="form-label">Student A</label>
            <select id="switchStudent1" class="form-input" style="height:46px; padding:0 14px;" onchange="updateSwitchInfo()">
                <option value="">Select student…</option>
                <?php foreach ($studentRows as $s): if (!$s['group_id']) continue; ?>
                    <option value="<?= $s['id'] ?>"
                            data-group="<?= $s['group_id'] ?>"
                            data-gname="<?= htmlspecialchars($s['group_name']) ?>">
                        <?= htmlspecialchars($s['name']) ?>
                        — <?= htmlspecialchars($s['group_name'] ?? '?') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Swap icon -->
        <div style="text-align:center; margin:4px 0; color:var(--color-mint-dark); font-size:20px;">
            <i class="fa-solid fa-arrow-up-arrow-down"></i>
        </div>

        <!-- Student B -->
        <div style="margin-bottom:20px;">
            <label class="form-label">Student B</label>
            <select id="switchStudent2" class="form-input" style="height:46px; padding:0 14px;" onchange="updateSwitchInfo()">
                <option value="">Select student…</option>
                <?php foreach ($studentRows as $s): if (!$s['group_id']) continue; ?>
                    <option value="<?= $s['id'] ?>"
                            data-group="<?= $s['group_id'] ?>"
                            data-gname="<?= htmlspecialchars($s['group_name']) ?>">
                        <?= htmlspecialchars($s['name']) ?>
                        — <?= htmlspecialchars($s['group_name'] ?? '?') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Preview -->
        <div id="switchPreview"
             style="display:none; padding:12px 16px; border-radius:var(--radius-md); background:var(--color-sage); margin-bottom:20px; font-size:13px; color:var(--color-text-mid);">
        </div>

        <div style="display:flex; gap:10px;">
            <button onclick="closeModal('switchModal')"
                    style="flex:1; height:44px; background:var(--color-sage); color:var(--color-text-dark); border:none; border-radius:var(--radius-md); font-size:14px; font-weight:600; cursor:pointer;">
                Cancel
            </button>
            <button onclick="submitSwitch()"
                    style="flex:2; height:44px; background:#e08a2a; color:white; border:none; border-radius:var(--radius-md); font-size:14px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                Switch Groups
            </button>
        </div>
    </div>
</div>

<!-- ─── Styles ────────────────────────────────────────────────────── -->
<style>
.group-list-item:hover   { background: var(--color-sage); }
.group-list-item.active  { background: var(--color-mint-light); border-left: 3px solid var(--color-mint-dark); }
.group-list-dim          { opacity: 0.5; }
.student-row:hover       { background: var(--color-sage); }
.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 5px; padding: 5px 11px; border: none; border-radius: var(--radius-sm);
    font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
    font-family: var(--font-main);
}
.btn-move   { background: var(--color-mint-light); color: var(--color-mint-dark); }
.btn-move:hover   { background: var(--color-mint-dark); color: white; }
.btn-switch { background: #fff3e0; color: #e08a2a; }
.btn-switch:hover { background: #e08a2a; color: white; }
.btn-remove { background: var(--color-rose-light); color: var(--color-rose-dark); }
.btn-remove:hover { background: var(--color-rose); color: white; }
.btn-assign { background: var(--color-mint-light); color: var(--color-mint-dark); }
.btn-assign:hover { background: var(--color-mint-dark); color: white; }
</style>

<!-- ─── JavaScript ───────────────────────────────────────────────── -->
<script>
// ─── Data from PHP ────────────────────────────
const groups = <?= json_encode(array_values($groupRows)) ?>;
const groupStudents = <?= json_encode($groupStudents) ?>;
const unassigned    = <?= json_encode(array_values($unassigned)) ?>;
const allStudents   = <?= json_encode(array_values($studentRows)) ?>;

let currentGroupId = null;

// ─── Select / render a group ──────────────────
function selectGroup(id) {
    currentGroupId = id;

    // Highlight sidebar
    document.querySelectorAll('.group-list-item').forEach(el => el.classList.remove('active'));
    const item = document.getElementById(id === 'unassigned' ? 'item-unassigned' : `item-${id}`);
    if (item) item.classList.add('active');

    document.getElementById('detailPlaceholder').style.display = 'none';
    document.getElementById('detailContent').style.display     = '';

    if (id === 'unassigned') {
        renderUnassigned();
    } else {
        renderGroup(parseInt(id));
    }
}

// ─── Render a real group ──────────────────────
function renderGroup(gid) {
    const g        = groups.find(x => x.id === gid);
    const students = groupStudents[gid] || [];

    document.getElementById('detailGroupName').textContent  = g.group_name;
    document.getElementById('detailGroupLevel').textContent = g.level;
    document.getElementById('detailGroupMeta').textContent  =
        `${g.department_name || 'No department'} · ${students.length}/${g.capacity} students`;

    // Action buttons
    document.getElementById('detailActions').innerHTML = `
        <button class="action-btn btn-switch" onclick="openSwitchModal(null)">
            <i class="fa-solid fa-arrow-right-arrow-left"></i> Switch Students
        </button>
        <button class="action-btn" onclick="openAssignToGroup(${gid})"
                style="background:var(--color-mint-dark); color:white; padding:7px 16px;">
            <i class="fa-solid fa-user-plus"></i> Add Student
        </button>
    `;

    renderStudentTable(students, false);
}

// ─── Render unassigned panel ──────────────────
function renderUnassigned() {
    document.getElementById('detailGroupName').textContent  = 'Unassigned Students';
    document.getElementById('detailGroupLevel').textContent = '';
    document.getElementById('detailGroupMeta').textContent  =
        `${unassigned.length} student(s) with no group assigned`;

    document.getElementById('detailActions').innerHTML = '';

    renderStudentTable(unassigned, true);
}

// ─── Build the student table rows ────────────
function renderStudentTable(students, isUnassigned) {
    const tbody = document.getElementById('studentListBody');
    const empty = document.getElementById('studentListEmpty');
    const emptyMsg = document.getElementById('studentListEmptyMsg');

    if (students.length === 0) {
        tbody.innerHTML = '';
        empty.style.display = '';
        emptyMsg.textContent = isUnassigned
            ? 'All students are assigned to a group.'
            : 'No students in this group yet.';
        return;
    }

    empty.style.display = 'none';

    tbody.innerHTML = students.map(s => `
        <tr class="student-row" style="border-bottom:1px solid var(--color-border); transition:var(--transition);">
            <td style="padding:12px 20px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:34px; height:34px; border-radius:50%; background:var(--color-mint-light);
                                display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fa-solid fa-user" style="font-size:13px; color:var(--color-mint-dark);"></i>
                    </div>
                    <div>
                        <div style="font-size:13px; font-weight:600; color:var(--color-text-dark);">
                            ${escHtml(s.name)}
                        </div>
                        <div style="font-size:11px; color:var(--color-text-light);">
                            ${escHtml(s.email || '')}
                        </div>
                    </div>
                </div>
            </td>
            <td style="padding:12px 14px;">
                <span style="font-family:monospace; font-size:12px; color:var(--color-text-mid);
                             background:var(--color-cream); padding:2px 7px; border-radius:4px;">
                    ${escHtml(s.reg_number || '—')}
                </span>
            </td>
            <td style="padding:12px 14px; text-align:center;">
                <div style="display:flex; align-items:center; justify-content:center; gap:6px; flex-wrap:wrap;">
                    ${isUnassigned ? `
                        <button class="action-btn btn-assign" onclick='openAssignStudent(${JSON.stringify(s)})'>
                            <i class="fa-solid fa-user-plus"></i> Assign
                        </button>
                    ` : `
                        <button class="action-btn btn-move" onclick='openMoveStudent(${JSON.stringify(s)})'>
                            <i class="fa-solid fa-right-left"></i> Move
                        </button>
                        <button class="action-btn btn-switch" onclick='openSwitchModal(${s.id})'>
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> Switch
                        </button>
                        <button class="action-btn btn-remove" onclick="removeStudent(${s.id}, '${escHtml(s.name).replace(/'/g,"\\'")}')">
                            <i class="fa-solid fa-user-minus"></i>
                        </button>
                    `}
                </div>
            </td>
        </tr>
    `).join('');
}

// ─── Open: Assign an unassigned student ───────
function openAssignStudent(student) {
    document.getElementById('assignModalTitle').textContent = 'Assign Student to Group';
    document.getElementById('assignModalSub').textContent   = `Choose a group for ${student.name}`;
    document.getElementById('assignModalIcon').className    = 'fa-solid fa-user-plus';
    document.getElementById('assignStudentRow').style.display = 'none';
    document.getElementById('assignGroupRow').style.display   = '';
    document.getElementById('assignHiddenStudent').value = student.id;
    document.getElementById('assignHiddenGroup').value   = '';
    document.getElementById('assignAction').value        = 'assign';
    document.getElementById('assignSubmitText').textContent = 'Assign';
    document.getElementById('assignModalAlert').style.display = 'none';
    document.getElementById('assignGroupSelect').value   = '';
    document.getElementById('assignModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Open: Move a student (pick target group) ─
function openMoveStudent(student) {
    document.getElementById('assignModalTitle').textContent = 'Move Student';
    document.getElementById('assignModalSub').textContent   = `Move ${student.name} to a different group`;
    document.getElementById('assignModalIcon').className    = 'fa-solid fa-right-left';
    document.getElementById('assignStudentRow').style.display = 'none';
    document.getElementById('assignGroupRow').style.display   = '';
    document.getElementById('assignHiddenStudent').value = student.id;
    document.getElementById('assignHiddenGroup').value   = '';
    document.getElementById('assignAction').value        = 'move';
    document.getElementById('assignSubmitText').textContent = 'Move';
    document.getElementById('assignModalAlert').style.display = 'none';
    // Pre-exclude current group
    const sel = document.getElementById('assignGroupSelect');
    [...sel.options].forEach(o => {
        o.disabled = o.value == student.group_id;
        o.style.color = o.disabled ? 'var(--color-text-light)' : '';
    });
    sel.value = '';
    document.getElementById('assignModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Open: Assign students from a full group list (Add to group) ──
function openAssignToGroup(groupId) {
    document.getElementById('assignModalTitle').textContent = 'Add Student to Group';
    document.getElementById('assignModalSub').textContent   = 'Search and pick an unassigned student';
    document.getElementById('assignModalIcon').className    = 'fa-solid fa-user-plus';
    document.getElementById('assignStudentRow').style.display = '';
    document.getElementById('assignGroupRow').style.display   = 'none';
    document.getElementById('assignHiddenStudent').value = '';
    document.getElementById('assignHiddenGroup').value   = groupId;
    document.getElementById('assignAction').value        = 'assign';
    document.getElementById('assignSubmitText').textContent = 'Assign to Group';
    document.getElementById('assignModalAlert').style.display = 'none';
    document.getElementById('assignStudentSearch').value = '';
    populateAssignStudentList('');
    document.getElementById('assignModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Populate student list for Assign-to-group ─
function populateAssignStudentList(query) {
    const sel = document.getElementById('assignStudentSelect');
    const filtered = allStudents.filter(s =>
        !s.group_id &&
        (s.name.toLowerCase().includes(query.toLowerCase()) ||
         (s.reg_number || '').toLowerCase().includes(query.toLowerCase()))
    );
    sel.innerHTML = filtered.length
        ? filtered.map(s =>
            `<option value="${s.id}">${escHtml(s.name)} (${escHtml(s.reg_number || '—')})</option>`
          ).join('')
        : '<option disabled>No unassigned students found</option>';
}

function filterAssignStudents() {
    populateAssignStudentList(document.getElementById('assignStudentSearch').value);
}

// ─── Submit Assign / Move ─────────────────────
async function submitAssignMove() {
    const action    = document.getElementById('assignAction').value;
    let studentId   = document.getElementById('assignHiddenStudent').value;
    let groupId     = document.getElementById('assignHiddenGroup').value;

    // If student not pre-filled, get from list
    if (!studentId) {
        const sel = document.getElementById('assignStudentSelect');
        studentId = sel.value;
        if (!studentId) { showAssignAlert(false, 'Please select a student.'); return; }
    }
    // If group not pre-filled, get from select
    if (!groupId) {
        const sel = document.getElementById('assignGroupSelect');
        groupId = sel.value;
        if (!groupId) { showAssignAlert(false, 'Please select a group.'); return; }
    }

    const payload = action === 'move'
        ? { action: 'move', student_id: +studentId, to_group_id: +groupId }
        : { action: 'assign', student_id: +studentId, group_id: +groupId };

    const btn = document.getElementById('assignSubmitBtn');
    btn.disabled = true;

    const res  = await fetch('/TimeTable/api/admin/groups.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await res.json();
    btn.disabled = false;

    if (data.success) {
        showAssignAlert(true, data.message);
        setTimeout(() => window.location.reload(), 900);
    } else {
        showAssignAlert(false, data.message);
    }
}

function showAssignAlert(ok, msg) {
    const box = document.getElementById('assignModalAlert');
    box.style.display    = 'block';
    box.style.background = ok ? '#e8f5ee' : '#fde8e8';
    box.style.color      = ok ? '#3a8a5a'  : '#c0392b';
    box.textContent      = msg;
}

// ─── Open Switch Modal ────────────────────────
function openSwitchModal(preselectedId) {
    document.getElementById('switchStudent1').value = preselectedId || '';
    document.getElementById('switchStudent2').value = '';
    document.getElementById('switchPreview').style.display = 'none';
    document.getElementById('switchModalAlert').style.display = 'none';
    updateSwitchInfo();
    document.getElementById('switchModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function updateSwitchInfo() {
    const s1 = document.getElementById('switchStudent1');
    const s2 = document.getElementById('switchStudent2');
    const preview = document.getElementById('switchPreview');

    const o1 = s1.options[s1.selectedIndex];
    const o2 = s2.options[s2.selectedIndex];

    if (s1.value && s2.value && s1.value !== s2.value) {
        const n1 = o1.text.split('—')[0].trim();
        const n2 = o2.text.split('—')[0].trim();
        const g1 = o1.dataset.gname || '?';
        const g2 = o2.dataset.gname || '?';
        preview.style.display = '';
        preview.innerHTML = `
            <b>${escHtml(n1)}</b> (${escHtml(g1)}) &nbsp;↔&nbsp; <b>${escHtml(n2)}</b> (${escHtml(g2)})
            <br><span style="font-size:11px; color:var(--color-text-light);">After switch: ${escHtml(n1)} → ${escHtml(g2)}, ${escHtml(n2)} → ${escHtml(g1)}</span>
        `;
    } else {
        preview.style.display = 'none';
    }
}

// ─── Submit Switch ────────────────────────────
async function submitSwitch() {
    const s1 = +document.getElementById('switchStudent1').value;
    const s2 = +document.getElementById('switchStudent2').value;

    if (!s1 || !s2)   { showSwitchAlert(false, 'Select two students.'); return; }
    if (s1 === s2)    { showSwitchAlert(false, 'Select two different students.'); return; }

    const g1 = document.getElementById('switchStudent1').options[
        document.getElementById('switchStudent1').selectedIndex
    ].dataset.group;
    const g2 = document.getElementById('switchStudent2').options[
        document.getElementById('switchStudent2').selectedIndex
    ].dataset.group;
    if (g1 === g2) { showSwitchAlert(false, 'Both students are in the same group — nothing to switch.'); return; }

    const res  = await fetch('/TimeTable/api/admin/groups.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'switch', student1_id: s1, student2_id: s2 }),
    });
    const data = await res.json();

    if (data.success) {
        showSwitchAlert(true, data.message);
        setTimeout(() => window.location.reload(), 900);
    } else {
        showSwitchAlert(false, data.message);
    }
}

function showSwitchAlert(ok, msg) {
    const box = document.getElementById('switchModalAlert');
    box.style.display    = 'block';
    box.style.background = ok ? '#e8f5ee' : '#fde8e8';
    box.style.color      = ok ? '#3a8a5a'  : '#c0392b';
    box.textContent      = msg;
}

// ─── Remove student from group ────────────────
async function removeStudent(studentId, name) {
    if (!confirm(`Remove "${name}" from their group?`)) return;

    const res  = await fetch('/TimeTable/api/admin/groups.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove', student_id: studentId }),
    });
    const data = await res.json();

    if (data.success) {
        window.location.reload();
    } else {
        alert('Error: ' + data.message);
    }
}

// ─── Close modals ─────────────────────────────
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}

['assignModal','switchModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});

// ─── Utility ──────────────────────────────────
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// Auto-open first group if any
<?php if (!empty($groupRows)): ?>
selectGroup(<?= $groupRows[0]['id'] ?>);
<?php elseif ($unassignedCount > 0): ?>
selectGroup('unassigned');
<?php endif; ?>
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
