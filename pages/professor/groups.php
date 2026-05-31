<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
$user = currentUser();

$activePage   = 'groups';
$pageTitle    = 'Student Groups';
$pageSubtitle = 'View the groups assigned to you.';

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

// ─── Get assigned groups ──────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT
        g.id,
        g.group_name,
        g.level,
        g.capacity,
        d.name AS department_name,
        COUNT(DISTINCT sg.student_id) AS student_count,
        COUNT(DISTINCT t.id)          AS session_count,
        GROUP_CONCAT(
            DISTINCT s.name
            ORDER BY s.name
            SEPARATOR ', '
        ) AS subjects
    FROM timetable t
    JOIN groups_table  g  ON t.group_id      = g.id
    LEFT JOIN departments   d  ON g.department_id = d.id
    LEFT JOIN student_groups sg ON sg.group_id    = g.id
    LEFT JOIN subjects      s  ON t.subject_id    = s.id
    WHERE t.professor_id = ?
      AND t.is_active    = 1
    GROUP BY g.id
    ORDER BY g.level, g.group_name
");
$stmt->execute([$user['id']]);
$groups = $stmt->fetchAll();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row" style="grid-template-columns:repeat(3,1fr);">

    <!-- Total Groups -->
    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Groups</p>
            <div class="stat-value"><?= count($groups) ?></div>
            <p class="stat-sub">Assigned to you</p>
        </div>
    </div>

    <!-- Total Students -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-user-graduate"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Students</p>
            <div class="stat-value">
                <?= array_sum(array_column($groups,'student_count')) ?>
            </div>
            <p class="stat-sub">Across all groups</p>
        </div>
    </div>

    <!-- Total Sessions -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Sessions</p>
            <div class="stat-value">
                <?= array_sum(array_column($groups,'session_count')) ?>
            </div>
            <p class="stat-sub">Per week</p>
        </div>
    </div>

</div>

<!-- ─── Groups List ───────────────────────────── -->
<?php if (empty($groups)): ?>

    <div class="card" style="text-align:center;
                              padding:60px 40px;">
        <i class="fa-solid fa-users-slash"
           style="font-size:48px;
                  display:block;
                  margin-bottom:16px;
                  color:var(--color-border);">
        </i>
        <p style="font-size:15px;
                  font-weight:600;
                  color:var(--color-text-dark);
                  margin-bottom:6px;">
            No groups assigned yet
        </p>
        <p style="font-size:13px;
                  color:var(--color-text-light);">
            Contact your administrator to get groups assigned.
        </p>
    </div>

<?php else: ?>

    <div style="display:grid;
                grid-template-columns: repeat(2, 1fr);
                gap:20px;">

        <?php foreach ($groups as $group): ?>

            <div class="card"
                 style="padding:0; overflow:hidden;
                        cursor:pointer;
                        transition:var(--transition);"
                 onmouseover="this.style.boxShadow='var(--shadow-md)';
                              this.style.transform='translateY(-2px)'"
                 onmouseout="this.style.boxShadow='var(--shadow-sm)';
                             this.style.transform='translateY(0)'"
                 onclick="toggleGroup(<?= $group['id'] ?>)">

                <!-- Group Header -->
                <div style="
                    padding:20px 24px;
                    background:linear-gradient(
                        135deg,
                        var(--color-mint-light),
                        var(--color-sage)
                    );
                    border-bottom:1px solid var(--color-border);
                    display:flex;
                    align-items:center;
                    justify-content:space-between;">

                    <div style="display:flex;
                                align-items:center;
                                gap:14px;">
                        <div style="
                            width:48px; height:48px;
                            border-radius:50%;
                            background:var(--color-white);
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            box-shadow:var(--shadow-sm);">
                            <i class="fa-solid fa-users"
                               style="font-size:18px;
                                      color:var(--color-mint-dark);">
                            </i>
                        </div>
                        <div>
                            <h3 style="font-size:16px;
                                       font-weight:700;
                                       color:var(--color-text-dark);">
                                <?= htmlspecialchars($group['group_name']) ?>
                            </h3>
                            <p style="font-size:12px;
                                      color:var(--color-text-mid);">
                                <?= htmlspecialchars($group['level']) ?>
                                &nbsp;·&nbsp;
                                <?= htmlspecialchars($group['department_name'] ?? 'N/A') ?>
                            </p>
                        </div>
                    </div>

                    <i class="fa-solid fa-chevron-down"
                       id="chevron-<?= $group['id'] ?>"
                       style="color:var(--color-text-light);
                              font-size:14px;
                              transition:var(--transition);">
                    </i>

                </div>

                <!-- Group Stats -->
                <div style="
                    display:grid;
                    grid-template-columns:repeat(3,1fr);
                    padding:16px 24px;
                    gap:12px;
                    border-bottom:1px solid var(--color-border);">

                    <div style="text-align:center;">
                        <div style="font-size:22px;
                                    font-weight:700;
                                    color:var(--color-text-dark);
                                    line-height:1;">
                            <?= $group['student_count'] ?>
                        </div>
                        <div style="font-size:11px;
                                    color:var(--color-text-light);
                                    margin-top:3px;">
                            Students
                        </div>
                    </div>

                    <div style="text-align:center;
                                border-left:1px solid var(--color-border);
                                border-right:1px solid var(--color-border);">
                        <div style="font-size:22px;
                                    font-weight:700;
                                    color:var(--color-text-dark);
                                    line-height:1;">
                            <?= $group['session_count'] ?>
                        </div>
                        <div style="font-size:11px;
                                    color:var(--color-text-light);
                                    margin-top:3px;">
                            Sessions
                        </div>
                    </div>

                    <div style="text-align:center;">
                        <div style="font-size:22px;
                                    font-weight:700;
                                    color:var(--color-text-dark);
                                    line-height:1;">
                            <?= $group['capacity'] ?>
                        </div>
                        <div style="font-size:11px;
                                    color:var(--color-text-light);
                                    margin-top:3px;">
                            Capacity
                        </div>
                    </div>

                </div>

                <!-- Subjects -->
                <?php if ($group['subjects']): ?>
                    <div style="padding:14px 24px;
                                border-bottom:1px solid var(--color-border);">
                        <p style="font-size:11px;
                                   font-weight:600;
                                   color:var(--color-text-light);
                                   margin-bottom:8px;
                                   text-transform:uppercase;
                                   letter-spacing:0.5px;">
                            Subjects Taught
                        </p>
                        <div style="display:flex;
                                    flex-wrap:wrap;
                                    gap:6px;">
                            <?php
                            $subjectList = explode(', ', $group['subjects']);
                            foreach ($subjectList as $sub):
                            ?>
                                <span style="
                                    padding:4px 10px;
                                    background:var(--color-mint-light);
                                    border:1px solid var(--color-mint-dark);
                                    border-radius:20px;
                                    font-size:12px;
                                    font-weight:500;
                                    color:var(--color-mint-dark);">
                                    <?= htmlspecialchars(trim($sub)) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Student List (collapsible) -->
                <div id="students-<?= $group['id'] ?>"
                     style="display:none;">
                    <?php
                    $stmt2 = $pdo->prepare("
                        SELECT u.name, u.reg_number, u.email
                        FROM users u
                        JOIN student_groups sg ON sg.student_id = u.id
                        WHERE sg.group_id = ?
                        ORDER BY u.name
                    ");
                    $stmt2->execute([$group['id']]);
                    $students = $stmt2->fetchAll();
                    ?>

                    <?php if (empty($students)): ?>
                        <div style="padding:20px 24px;
                                    text-align:center;
                                    color:var(--color-text-light);
                                    font-size:13px;">
                            No students assigned yet.
                        </div>
                    <?php else: ?>
                        <!-- Search -->
                        <div style="padding:12px 24px;
                                    border-bottom:1px solid var(--color-border);">
                            <div style="position:relative;">
                                <i class="fa-solid fa-magnifying-glass"
                                   style="position:absolute;
                                          left:12px;
                                          top:50%;
                                          transform:translateY(-50%);
                                          color:var(--color-text-light);
                                          font-size:13px;">
                                </i>
                                <input type="text"
                                       placeholder="Search students..."
                                       oninput="searchStudents(this, <?= $group['id'] ?>)"
                                       onclick="event.stopPropagation()"
                                       style="
                                           width:100%;
                                           height:38px;
                                           padding:0 14px 0 36px;
                                           border:1.5px solid var(--color-border);
                                           border-radius:var(--radius-md);
                                           font-size:13px;
                                           background:var(--color-cream);
                                           color:var(--color-text-dark);">
                            </div>
                        </div>

                        <!-- Student rows -->
                        <div id="studentList-<?= $group['id'] ?>"
                             style="max-height:280px;
                                    overflow-y:auto;">
                            <?php foreach ($students as $i => $stu): ?>
                                <div class="student-row-<?= $group['id'] ?>"
                                     style="
                                         display:flex;
                                         align-items:center;
                                         gap:12px;
                                         padding:12px 24px;
                                         border-bottom:1px solid var(--color-border);
                                         background:<?= $i % 2 === 0
                                             ? 'var(--color-white)'
                                             : 'var(--color-cream)' ?>;"
                                     onclick="event.stopPropagation()">

                                    <!-- Avatar -->
                                    <div style="
                                        width:34px; height:34px;
                                        border-radius:50%;
                                        background:var(--color-mint-light);
                                        display:flex;
                                        align-items:center;
                                        justify-content:center;
                                        flex-shrink:0;">
                                        <i class="fa-solid fa-user"
                                           style="font-size:13px;
                                                  color:var(--color-mint-dark);">
                                        </i>
                                    </div>

                                    <!-- Info -->
                                    <div style="flex:1;">
                                        <p style="font-size:13px;
                                                   font-weight:600;
                                                   color:var(--color-text-dark);
                                                   margin-bottom:2px;">
                                            <?= htmlspecialchars($stu['name']) ?>
                                        </p>
                                        <p style="font-size:11px;
                                                   color:var(--color-text-light);">
                                            <?= htmlspecialchars($stu['reg_number']) ?>
                                        </p>
                                    </div>

                                    <!-- Email -->
                                    <span style="font-size:12px;
                                                 color:var(--color-text-light);">
                                        <?= htmlspecialchars($stu['email'] ?? '') ?>
                                    </span>

                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<!-- ─── Script ───────────────────────────────── -->
<script>

// ─── Toggle student list ──────────────────────
function toggleGroup(id) {
    const list    = document.getElementById('students-' + id);
    const chevron = document.getElementById('chevron-' + id);
    const isOpen  = list.style.display === 'block';

    list.style.display    = isOpen ? 'none'   : 'block';
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// ─── Search students ──────────────────────────
function searchStudents(input, groupId) {
    const query = input.value.toLowerCase();
    const rows  = document.querySelectorAll(
        '.student-row-' + groupId
    );

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? 'flex' : 'none';
    });
}

</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>