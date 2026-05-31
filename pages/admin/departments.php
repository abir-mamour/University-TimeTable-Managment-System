<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'departments';
$pageTitle    = 'Departments Management';
$pageSubtitle = 'Manage academic departments.';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Pending requests ─────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Get all departments with counts ──────────
$stmt = $pdo->query("
    SELECT
        d.*,
        COUNT(DISTINCT u.id)  AS user_count,
        COUNT(DISTINCT s.id)  AS subject_count,
        COUNT(DISTINCT g.id)  AS group_count
    FROM departments d
    LEFT JOIN users        u ON u.department_id = d.id
    LEFT JOIN subjects     s ON s.department_id = d.id
    LEFT JOIN groups_table g ON g.department_id = d.id
    GROUP BY d.id
    ORDER BY d.name
");
$departments = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(3,1fr);
            margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-building-columns"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Departments</p>
            <div class="stat-value"><?= count($departments) ?></div>
            <p class="stat-sub">Academic departments</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Users</p>
            <div class="stat-value">
                <?= array_sum(array_column($departments, 'user_count')) ?>
            </div>
            <p class="stat-sub">Across all departments</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-book-open"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Subjects</p>
            <div class="stat-value">
                <?= array_sum(array_column($departments, 'subject_count')) ?>
            </div>
            <p class="stat-sub">Across all departments</p>
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

    <div style="position:relative; width:280px;">
        <i class="fa-solid fa-magnifying-glass"
           style="
               position:absolute;
               left:12px; top:50%;
               transform:translateY(-50%);
               color:var(--color-text-light);
               font-size:13px;">
        </i>
        <input type="text"
               id="searchInput"
               placeholder="Search departments..."
               oninput="searchDepts()"
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
        Add Department
    </button>

</div>

<!-- ─── Departments Table ─────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($departments)): ?>

        <div style="
            text-align:center;
            padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-building-columns"
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
                No departments yet
            </p>
            <p style="font-size:13px;">
                Click "Add Department" to get started.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;"
               id="deptsTable">
            <thead>
                <tr style="
                    background:var(--color-sage);
                    border-bottom:2px solid var(--color-border);">
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Department
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Code
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Users
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Subjects
                    </th>
                    <th style="padding:14px 20px; text-align:left;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Groups
                    </th>
                    <th style="padding:14px 20px; text-align:center;
                               font-size:12px; font-weight:600;
                               color:var(--color-text-mid);">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody id="deptsBody">
                <?php foreach ($departments as $i => $dept): ?>
                <tr class="dept-row"
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

                    <!-- Name -->
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
                                <i class="fa-solid fa-building-columns"
                                   style="font-size:15px;
                                          color:var(--color-mint-dark);">
                                </i>
                            </div>
                            <span style="
                                font-size:14px;
                                font-weight:600;
                                color:var(--color-text-dark);">
                                <?= htmlspecialchars($dept['name']) ?>
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
                            <?= htmlspecialchars($dept['code']) ?>
                        </span>
                    </td>

                    <!-- Users -->
                    <td style="padding:16px 20px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-user"
                               style="font-size:11px;
                                      color:var(--color-text-light);">
                            </i>
                            <span style="font-size:14px;
                                         font-weight:500;
                                         color:var(--color-text-dark);">
                                <?= $dept['user_count'] ?>
                            </span>
                        </div>
                    </td>

                    <!-- Subjects -->
                    <td style="padding:16px 20px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-book"
                               style="font-size:11px;
                                      color:var(--color-text-light);">
                            </i>
                            <span style="font-size:14px;
                                         font-weight:500;
                                         color:var(--color-text-dark);">
                                <?= $dept['subject_count'] ?>
                            </span>
                        </div>
                    </td>

                    <!-- Groups -->
                    <td style="padding:16px 20px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-users"
                               style="font-size:11px;
                                      color:var(--color-text-light);">
                            </i>
                            <span style="font-size:14px;
                                         font-weight:500;
                                         color:var(--color-text-dark);">
                                <?= $dept['group_count'] ?>
                            </span>
                        </div>
                    </td>

                    <!-- Actions -->
                    <td style="padding:16px 20px; text-align:center;">
                        <div style="display:flex; gap:8px; justify-content:center;">

                            <button onclick="openEditModal(
                                <?= $dept['id'] ?>,
                                '<?= addslashes($dept['name']) ?>',
                                '<?= addslashes($dept['code']) ?>'
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
                                <i class="fa-solid fa-pen" style="font-size:13px;"></i>
                            </button>

                            <button onclick="deleteDept(
                                <?= $dept['id'] ?>,
                                '<?= addslashes($dept['name']) ?>'
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
                                <i class="fa-solid fa-trash" style="font-size:13px;"></i>
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
<div id="deptModal"
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
        max-width:440px;
        margin:20px;
        box-shadow:var(--shadow-lg);
        position:relative;">

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
                <i class="fa-solid fa-building-columns"
                   style="color:var(--color-mint-dark); font-size:18px;"></i>
            </div>
            <div>
                <h3 id="modalTitle"
                    style="
                        font-size:17px;
                        font-weight:700;
                        color:var(--color-text-dark);">
                    Add Department
                </h3>
                <p style="font-size:13px; color:var(--color-text-light);">
                    Fill in the department details below
                </p>
            </div>
        </div>

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

        <form id="deptForm">
            <input type="hidden" id="deptId" value="">

            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Department Name
                </label>
                <input type="text"
                       id="deptName"
                       placeholder="e.g. Computer Science"
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

            <div style="margin-bottom:24px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Department Code
                </label>
                <input type="text"
                       id="deptCode"
                       placeholder="e.g. CS"
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
                    <span id="submitText">Save Department</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

function searchDepts() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.dept-row').forEach(row => {
        row.style.display =
            row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
}

function openModal() {
    document.getElementById('modalTitle').textContent   = 'Add Department';
    document.getElementById('deptId').value             = '';
    document.getElementById('deptName').value           = '';
    document.getElementById('deptCode').value           = '';
    document.getElementById('submitText').textContent   = 'Save Department';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('deptModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

function openEditModal(id, name, code) {
    document.getElementById('modalTitle').textContent   = 'Edit Department';
    document.getElementById('deptId').value             = id;
    document.getElementById('deptName').value           = name;
    document.getElementById('deptCode').value           = code;
    document.getElementById('submitText').textContent   = 'Update Department';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('deptModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('deptModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('deptModal')
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

document.getElementById('deptForm')
    .addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn  = document.getElementById('submitBtn');
        const text = document.getElementById('submitText');
        const id   = document.getElementById('deptId').value;

        btn.disabled     = true;
        text.textContent = 'Saving...';

        const data = {
            id:   id || null,
            name: document.getElementById('deptName').value.trim(),
            code: document.getElementById('deptCode').value.trim().toUpperCase(),
        };

        try {
            const res = await fetch(
                '/TimeTable/api/admin/departments.php',
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
                text.textContent = id ? 'Update Department' : 'Save Department';
            }

        } catch(err) {
            showModalAlert(false, 'Connection error. Please try again.');
            btn.disabled     = false;
            text.textContent = id ? 'Update Department' : 'Save Department';
        }
    });

async function deleteDept(id, name) {
    if (!confirm(`Delete department "${name}"? This cannot be undone.`)) return;

    try {
        const res = await fetch(
            '/TimeTable/api/admin/departments.php',
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
