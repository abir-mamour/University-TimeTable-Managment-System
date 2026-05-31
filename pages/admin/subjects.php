<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'subjects';
$pageTitle    = 'Subjects Management';
$pageSubtitle = 'Manage course subjects and their details.';

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

// ─── Get all subjects ─────────────────────────
$stmt = $pdo->query("
    SELECT
        s.*,
        d.name AS department_name,
        COUNT(t.id) AS session_count
    FROM subjects s
    LEFT JOIN departments d ON s.department_id = d.id
    LEFT JOIN timetable   t ON t.subject_id    = s.id
        AND t.is_active = 1
    GROUP BY s.id
    ORDER BY s.name
");
$subjects = $stmt->fetchAll();

// ─── Get departments for dropdown ─────────────
$departments = $pdo->query("
    SELECT id, name FROM departments ORDER BY name
")->fetchAll();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(3,1fr);
            margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-book-open"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Subjects</p>
            <div class="stat-value"><?= count($subjects) ?></div>
            <p class="stat-sub">All subjects</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Scheduled</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $subjects,
                    fn($s) => $s['session_count'] > 0
                )) ?>
            </div>
            <p class="stat-sub">With active sessions</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Unscheduled</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $subjects,
                    fn($s) => $s['session_count'] == 0
                )) ?>
            </div>
            <p class="stat-sub">No sessions yet</p>
        </div>
    </div>

</div>

<!-- ─── Top Bar ───────────────────────────────── -->
<div style="
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
    gap:12px;
    flex-wrap:wrap;">

    <!-- Search -->
    <div style="position:relative; width:280px;">
        <i class="fa-solid fa-magnifying-glass"
           style="
               position:absolute;
               left:12px;
               top:50%;
               transform:translateY(-50%);
               color:var(--color-text-light);
               font-size:13px;">
        </i>
        <input type="text"
               id="searchInput"
               placeholder="Search subjects..."
               oninput="searchSubjects()"
               style="
                   width:100%;
                   height:42px;
                   padding:0 14px 0 36px;
                   border:1.5px solid var(--color-border);
                   border-radius:var(--radius-md);
                   font-size:13px;
                   background:var(--color-white);
                   color:var(--color-text-dark);">
    </div>

    <!-- Add Subject Button -->
    <button onclick="openModal()"
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
                transition:var(--transition);"
            onmouseover="this.style.background='#6BB8A0'"
            onmouseout="this.style.background='var(--color-mint-dark)'">
        <i class="fa-solid fa-plus"></i>
        Add Subject
    </button>

</div>

<!-- ─── Subjects Table ─────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($subjects)): ?>

        <div style="
            text-align:center;
            padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-book-open"
               style="
                   font-size:48px;
                   display:block;
                   margin-bottom:16px;
                   opacity:0.3;">
            </i>
            <p style="
                font-size:15px;
                font-weight:600;
                color:var(--color-text-dark);
                margin-bottom:6px;">
                No subjects yet
            </p>
            <p style="font-size:13px;">
                Click "Add Subject" to add your first subject.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;"
               id="subjectsTable">
            <thead>
                <tr style="
                    background:var(--color-sage);
                    border-bottom:2px solid var(--color-border);">
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Subject
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Code
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Department
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Credits
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Sessions / Week
                    </th>
                    <th style="padding:14px 20px; text-align:center;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody id="subjectsBody">
                <?php foreach ($subjects as $i => $sub): ?>
                <tr class="subject-row"
                    style="
                        border-bottom:1px solid var(--color-border);
                        background:<?= $i % 2 === 0
                            ? 'var(--color-white)'
                            : 'var(--color-cream)' ?>;
                        transition:var(--transition);"
                    onmouseover="this.style.background='var(--color-mint-light)'"
                    onmouseout="this.style.background='<?= $i % 2 === 0
                        ? 'var(--color-white)'
                        : 'var(--color-cream)' ?>'">

                    <!-- Subject Name -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:12px;">
                            <div style="
                                width:38px; height:38px;
                                border-radius:var(--radius-md);
                                background:var(--color-mint-light);
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid fa-book"
                                   style="font-size:15px;
                                          color:var(--color-mint-dark);">
                                </i>
                            </div>
                            <span style="
                                font-size:14px;
                                font-weight:600;
                                color:var(--color-text-dark);">
                                <?= htmlspecialchars($sub['name']) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Code -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:4px 12px;
                            background:var(--color-sage);
                            color:var(--color-text-mid);
                            border-radius:6px;
                            font-size:12px;
                            font-weight:700;
                            font-family:monospace;
                            letter-spacing:0.5px;">
                            <?= htmlspecialchars($sub['code']) ?>
                        </span>
                    </td>

                    <!-- Department -->
                    <td style="padding:16px 20px;">
                        <span style="
                            font-size:13px;
                            color:var(--color-text-mid);">
                            <?= $sub['department_name']
                                ? htmlspecialchars($sub['department_name'])
                                : '—' ?>
                        </span>
                    </td>

                    <!-- Credits -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:6px;">
                            <i class="fa-solid fa-star"
                               style="font-size:11px;
                                      color:var(--color-text-light);">
                            </i>
                            <span style="
                                font-size:14px;
                                font-weight:500;
                                color:var(--color-text-dark);">
                                <?= $sub['credits'] ?>
                                <span style="
                                    font-size:11px;
                                    color:var(--color-text-light);
                                    font-weight:400;">
                                    cr
                                </span>
                            </span>
                        </div>
                    </td>

                    <!-- Sessions -->
                    <td style="padding:16px 20px;">
                        <?php if ($sub['session_count'] > 0): ?>
                            <span style="
                                display:inline-flex;
                                align-items:center;
                                gap:5px;
                                padding:4px 10px;
                                background:#e8f5ee;
                                color:#3a8a5a;
                                border-radius:20px;
                                font-size:12px;
                                font-weight:600;">
                                <i class="fa-solid fa-circle"
                                   style="font-size:6px;"></i>
                                <?= $sub['session_count'] ?> sessions
                            </span>
                        <?php else: ?>
                            <span style="
                                font-size:13px;
                                color:var(--color-text-light);">
                                —
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="padding:16px 20px; text-align:center;">
                        <div style="
                            display:flex;
                            gap:8px;
                            justify-content:center;">

                            <!-- Edit -->
                            <button onclick="openEditModal(
                                <?= $sub['id'] ?>,
                                '<?= addslashes($sub['name']) ?>',
                                '<?= addslashes($sub['code']) ?>',
                                <?= $sub['department_id'] ?: 'null' ?>,
                                <?= $sub['credits'] ?>
                            )"
                                style="
                                    width:34px; height:34px;
                                    border-radius:var(--radius-sm);
                                    background:var(--color-mint-light);
                                    border:1px solid var(--color-mint-dark);
                                    color:var(--color-mint-dark);
                                    cursor:pointer;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    transition:var(--transition);"
                                onmouseover="
                                    this.style.background='var(--color-mint-dark)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background='var(--color-mint-light)';
                                    this.style.color='var(--color-mint-dark)';">
                                <i class="fa-solid fa-pen"
                                   style="font-size:13px;"></i>
                            </button>

                            <!-- Delete -->
                            <button onclick="deleteSubject(
                                <?= $sub['id'] ?>,
                                '<?= addslashes($sub['name']) ?>'
                            )"
                                style="
                                    width:34px; height:34px;
                                    border-radius:var(--radius-sm);
                                    background:var(--color-rose-light);
                                    border:1px solid var(--color-rose);
                                    color:var(--color-rose-dark);
                                    cursor:pointer;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    transition:var(--transition);"
                                onmouseover="
                                    this.style.background='var(--color-rose)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background='var(--color-rose-light)';
                                    this.style.color='var(--color-rose-dark)';">
                                <i class="fa-solid fa-trash"
                                   style="font-size:13px;"></i>
                            </button>

                        </div>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     ADD / EDIT MODAL
═══════════════════════════════════════════════ -->
<div id="subjectModal"
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
        max-width:480px;
        margin:20px;
        box-shadow:var(--shadow-lg);
        position:relative;">

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
                <i class="fa-solid fa-book-open"
                   style="color:var(--color-mint-dark); font-size:18px;"></i>
            </div>
            <div>
                <h3 id="modalTitle"
                    style="
                        font-size:17px;
                        font-weight:700;
                        color:var(--color-text-dark);">
                    Add Subject
                </h3>
                <p style="font-size:13px; color:var(--color-text-light);">
                    Fill in the subject details below
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
        <form id="subjectForm">
            <input type="hidden" id="subjectId" value="">

            <!-- Name -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Subject Name
                </label>
                <input type="text"
                       id="subjectName"
                       placeholder="e.g. Algorithms"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           color:var(--color-text-dark);
                           background:var(--color-cream);"
                       required>
            </div>

            <!-- Code -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Subject Code
                </label>
                <input type="text"
                       id="subjectCode"
                       placeholder="e.g. CS101"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           font-family:monospace;
                           color:var(--color-text-dark);
                           background:var(--color-cream);"
                       required>
            </div>

            <!-- Department + Credits row -->
            <div style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:14px;
                margin-bottom:24px;">

                <div>
                    <label style="
                        display:block;
                        font-size:13px;
                        font-weight:600;
                        color:var(--color-text-dark);
                        margin-bottom:8px;">
                        Department
                    </label>
                    <select id="subjectDept"
                            style="
                                width:100%;
                                height:46px;
                                padding:0 14px;
                                border:1.5px solid var(--color-border);
                                border-radius:var(--radius-md);
                                font-size:14px;
                                color:var(--color-text-dark);
                                background:var(--color-cream);
                                cursor:pointer;">
                        <option value="">None</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>">
                                <?= htmlspecialchars($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="
                        display:block;
                        font-size:13px;
                        font-weight:600;
                        color:var(--color-text-dark);
                        margin-bottom:8px;">
                        Credits
                    </label>
                    <input type="number"
                           id="subjectCredits"
                           placeholder="e.g. 3"
                           min="1"
                           max="10"
                           value="3"
                           style="
                               width:100%;
                               height:46px;
                               padding:0 14px;
                               border:1.5px solid var(--color-border);
                               border-radius:var(--radius-md);
                               font-size:14px;
                               color:var(--color-text-dark);
                               background:var(--color-cream);"
                           required>
                </div>

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
                    <span id="submitText">Save Subject</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

function searchSubjects() {
    const query = document.getElementById('searchInput')
        .value.toLowerCase();
    document.querySelectorAll('.subject-row').forEach(row => {
        row.style.display =
            row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
}

function openModal() {
    document.getElementById('modalTitle').textContent   = 'Add Subject';
    document.getElementById('subjectId').value          = '';
    document.getElementById('subjectName').value        = '';
    document.getElementById('subjectCode').value        = '';
    document.getElementById('subjectDept').value        = '';
    document.getElementById('subjectCredits').value     = '3';
    document.getElementById('submitText').textContent   = 'Save Subject';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('subjectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function openEditModal(id, name, code, deptId, credits) {
    document.getElementById('modalTitle').textContent   = 'Edit Subject';
    document.getElementById('subjectId').value          = id;
    document.getElementById('subjectName').value        = name;
    document.getElementById('subjectCode').value        = code;
    document.getElementById('subjectDept').value        = deptId ?? '';
    document.getElementById('subjectCredits').value     = credits;
    document.getElementById('submitText').textContent   = 'Update Subject';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('subjectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('subjectModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('subjectModal')
    .addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

function showModalAlert(success, message) {
    const box = document.getElementById('modalAlert');
    box.style.display    = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.color      = success ? '#3a8a5a'  : '#c0392b';
    box.innerHTML = `<i class="fa-solid ${
        success ? 'fa-circle-check' : 'fa-circle-exclamation'
    }"></i>&nbsp;${message}`;
}

document.getElementById('subjectForm')
    .addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn  = document.getElementById('submitBtn');
        const text = document.getElementById('submitText');
        const id   = document.getElementById('subjectId').value;

        btn.disabled     = true;
        text.textContent = 'Saving...';

        const data = {
            id:            id || null,
            name:          document.getElementById('subjectName').value.trim(),
            code:          document.getElementById('subjectCode').value.trim().toUpperCase(),
            department_id: document.getElementById('subjectDept').value || null,
            credits:       document.getElementById('subjectCredits').value,
        };

        try {
            const res = await fetch(
                '/TimeTable/api/admin/subjects.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(data)
                }
            );

            const result = await res.json();

            if (result.success) {
                showModalAlert(true, result.message);
                setTimeout(() => window.location.reload(), 900);
            } else {
                showModalAlert(false, result.message);
                btn.disabled     = false;
                text.textContent = id ? 'Update Subject' : 'Save Subject';
            }

        } catch(err) {
            showModalAlert(false, 'Connection error. Please try again.');
            btn.disabled     = false;
            text.textContent = id ? 'Update Subject' : 'Save Subject';
        }
    });

async function deleteSubject(id, name) {
    if (!confirm(`Delete subject "${name}"? This cannot be undone.`)) return;

    try {
        const res = await fetch(
            '/TimeTable/api/admin/subjects.php',
            {
                method:  'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ id })
            }
        );

        const result = await res.json();

        if (result.success) {
            window.location.reload();
        } else {
            alert('Error: ' + result.message);
        }

    } catch(err) {
        alert('Connection error. Please try again.');
    }
}

</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
