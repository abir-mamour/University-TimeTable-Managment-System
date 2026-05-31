<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'users';
$pageTitle    = 'Users Management';
$pageSubtitle = 'Manage professors and students.';

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

// ─── Get all users ────────────────────────────
$stmt = $pdo->query("
    SELECT
        u.*,
        d.name AS department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.role != 'admin'
    ORDER BY u.role, u.name
");
$users = $stmt->fetchAll();

// ─── Get departments ──────────────────────────
$stmt = $pdo->query("
    SELECT * FROM departments
    ORDER BY name
");
$departments = $stmt->fetchAll();

// ─── Get groups ───────────────────────────────
$stmt = $pdo->query("
    SELECT * FROM groups_table
    ORDER BY level, group_name
");
$groups = $stmt->fetchAll();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(3,1fr);
            margin-bottom:24px;">

    <!-- Total Users -->
    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Users</p>
            <div class="stat-value"><?= count($users) ?></div>
            <p class="stat-sub">Professors & students</p>
        </div>
    </div>

    <!-- Professors -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Professors</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $users,
                    fn($u) => $u['role'] === 'professor'
                )) ?>
            </div>
            <p class="stat-sub">Active teachers</p>
        </div>
    </div>

    <!-- Students -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-user-graduate"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Students</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $users,
                    fn($u) => $u['role'] === 'student'
                )) ?>
            </div>
            <p class="stat-sub">Enrolled students</p>
        </div>
    </div>

</div>

<!-- ─── Top Bar ───────────────────────────────── -->
<div style="
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
    gap:16px;
    flex-wrap:wrap;">

    <!-- Left: Search + Filter -->
    <div style="display:flex; gap:10px; flex-wrap:wrap;">

        <!-- Search -->
        <div style="position:relative; width:260px;">
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
                   placeholder="Search users..."
                   oninput="searchUsers()"
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

        <!-- Role Filter -->
        <div style="
            display:flex;
            gap:6px;
            background:var(--color-white);
            padding:5px;
            border-radius:var(--radius-md);
            border:1px solid var(--color-border);">
            <button onclick="filterUsers('all')"
                    id="tab-all"
                    class="user-tab active-tab">
                All
            </button>
            <button onclick="filterUsers('professor')"
                    id="tab-professor"
                    class="user-tab">
                <i class="fa-solid fa-chalkboard-user"></i>
                Professors
            </button>
            <button onclick="filterUsers('student')"
                    id="tab-student"
                    class="user-tab">
                <i class="fa-solid fa-user-graduate"></i>
                Students
            </button>
        </div>

    </div>

    <!-- Add User Button -->
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
        <i class="fa-solid fa-user-plus"></i>
        Add User
    </button>

</div>

<!-- ─── Users Table ───────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($users)): ?>

        <div style="
            text-align:center;
            padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-users"
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
                No users yet
            </p>
            <p style="font-size:13px;">
                Click "Add User" to add your first user.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;"
               id="usersTable">
            <thead>
                <tr style="
                    background:var(--color-sage);
                    border-bottom:2px solid var(--color-border);">
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        User
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Registration ID
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Role
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Department
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Status
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:center;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody id="usersBody">
                <?php foreach ($users as $i => $u):
                    $roleInfo = match($u['role']) {
                        'professor' => [
                            'label' => 'Professor',
                            'icon'  => 'fa-chalkboard-user',
                            'bg'    => 'var(--color-mint-light)',
                            'color' => 'var(--color-mint-dark)'
                        ],
                        'student' => [
                            'label' => 'Student',
                            'icon'  => 'fa-user-graduate',
                            'bg'    => 'var(--color-sage)',
                            'color' => '#5a8a6a'
                        ],
                        default => [
                            'label' => ucfirst($u['role']),
                            'icon'  => 'fa-user',
                            'bg'    => 'var(--color-cream)',
                            'color' => 'var(--color-text-mid)'
                        ],
                    };
                ?>
                <tr class="user-row"
                    data-role="<?= $u['role'] ?>"
                    data-name="<?= strtolower($u['name']) ?>"
                    style="
                        border-bottom:1px solid var(--color-border);
                        background:<?= $i % 2 === 0
                            ? 'var(--color-white)'
                            : 'var(--color-cream)' ?>;
                        transition:var(--transition);"
                    onmouseover="this.style.background=
                        'var(--color-mint-light)'"
                    onmouseout="this.style.background='<?= $i % 2 === 0
                        ? 'var(--color-white)'
                        : 'var(--color-cream)' ?>'">

                    <!-- User -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:12px;">
                            <div style="
                                width:40px; height:40px;
                                border-radius:50%;
                                background:<?= $roleInfo['bg'] ?>;
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid <?= $roleInfo['icon'] ?>"
                                   style="
                                       font-size:16px;
                                       color:<?= $roleInfo['color'] ?>;">
                                </i>
                            </div>
                            <div>
                                <p style="
                                    font-size:13px;
                                    font-weight:600;
                                    color:var(--color-text-dark);
                                    margin-bottom:2px;">
                                    <?= htmlspecialchars($u['name']) ?>
                                </p>
                                <p style="
                                    font-size:11px;
                                    color:var(--color-text-light);">
                                    <?= htmlspecialchars(
                                        $u['email'] ?? 'No email'
                                    ) ?>
                                </p>
                            </div>
                        </div>
                    </td>

                    <!-- Reg ID -->
                    <td style="padding:16px 20px;">
                        <span style="
                            font-size:13px;
                            font-weight:600;
                            color:var(--color-text-dark);
                            background:var(--color-cream);
                            padding:4px 10px;
                            border-radius:var(--radius-sm);
                            border:1px solid var(--color-border);">
                            <?= htmlspecialchars($u['reg_number']) ?>
                        </span>
                    </td>

                    <!-- Role -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:4px 12px;
                            background:<?= $roleInfo['bg'] ?>;
                            color:<?= $roleInfo['color'] ?>;
                            border-radius:20px;
                            font-size:12px;
                            font-weight:600;
                            display:inline-flex;
                            align-items:center;
                            gap:5px;">
                            <i class="fa-solid <?= $roleInfo['icon'] ?>"
                               style="font-size:10px;">
                            </i>
                            <?= $roleInfo['label'] ?>
                        </span>
                    </td>

                    <!-- Department -->
                    <td style="padding:16px 20px;">
                        <span style="
                            font-size:13px;
                            color:var(--color-text-mid);">
                            <?= htmlspecialchars(
                                $u['department_name'] ?? '—'
                            ) ?>
                        </span>
                    </td>

                    <!-- Status -->
                    <td style="padding:16px 20px;">
                        <?php if ($u['is_active']): ?>
                            <span style="
                                padding:4px 12px;
                                background:#e8f5ee;
                                color:#3a8a5a;
                                border:1px solid #b7dfca;
                                border-radius:20px;
                                font-size:12px;
                                font-weight:600;
                                display:inline-flex;
                                align-items:center;
                                gap:5px;">
                                <i class="fa-solid fa-circle"
                                   style="font-size:7px;">
                                </i>
                                Active
                            </span>
                        <?php else: ?>
                            <span style="
                                padding:4px 12px;
                                background:#fde8e8;
                                color:#c0392b;
                                border:1px solid #f5c6c6;
                                border-radius:20px;
                                font-size:12px;
                                font-weight:600;
                                display:inline-flex;
                                align-items:center;
                                gap:5px;">
                                <i class="fa-solid fa-circle"
                                   style="font-size:7px;">
                                </i>
                                Inactive
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="
                        padding:16px 20px;
                        text-align:center;">
                        <div style="
                            display:flex;
                            gap:8px;
                            justify-content:center;">

                            <!-- Edit -->
                            <button onclick="openEditModal(
                                <?= $u['id'] ?>,
                                '<?= addslashes($u['name']) ?>',
                                '<?= addslashes($u['reg_number']) ?>',
                                '<?= addslashes($u['email'] ?? '') ?>',
                                '<?= $u['role'] ?>',
                                <?= $u['department_id'] ?? 'null' ?>,
                                <?= $u['is_active'] ?>
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
                                    this.style.background=
                                        'var(--color-mint-dark)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background=
                                        'var(--color-mint-light)';
                                    this.style.color=
                                        'var(--color-mint-dark)';">
                                <i class="fa-solid fa-pen"
                                   style="font-size:13px;">
                                </i>
                            </button>

                            <!-- Delete -->
                            <button onclick="deleteUser(
                                <?= $u['id'] ?>,
                                '<?= addslashes($u['name']) ?>'
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
                                    this.style.background=
                                        'var(--color-rose)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background=
                                        'var(--color-rose-light)';
                                    this.style.color=
                                        'var(--color-rose-dark)';">
                                <i class="fa-solid fa-trash"
                                   style="font-size:13px;">
                                </i>
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
<div id="userModal"
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
        max-width:500px;
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
                <i class="fa-solid fa-user-plus"
                   style="
                       color:var(--color-mint-dark);
                       font-size:18px;">
                </i>
            </div>
            <div>
                <h3 id="modalTitle"
                    style="
                        font-size:17px;
                        font-weight:700;
                        color:var(--color-text-dark);">
                    Add User
                </h3>
                <p style="
                    font-size:13px;
                    color:var(--color-text-light);">
                    Fill in the user details below
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
        <form id="userForm">
            <input type="hidden" id="userId" value="">

            <!-- Name -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Full Name
                </label>
                <input type="text"
                       id="userName"
                       placeholder="e.g. Dr. Ahmed Benali"
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

            <!-- Registration Number -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Registration Number
                </label>
                <input type="text"
                       id="userReg"
                       placeholder="e.g. PROF004"
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

            <!-- Email -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Email
                    <span style="
                        color:var(--color-text-light);
                        font-weight:400;">
                        (optional)
                    </span>
                </label>
                <input type="email"
                       id="userEmail"
                       placeholder="e.g. ahmed@school.dz"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           color:var(--color-text-dark);
                           background:var(--color-cream);">
            </div>

            <!-- Password -->
            <div style="margin-bottom:16px;" id="passwordField">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Password
                    <span id="passwordNote"
                          style="
                              color:var(--color-text-light);
                              font-weight:400;">
                    </span>
                </label>
                <input type="password"
                       id="userPassword"
                       placeholder="Enter password"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           color:var(--color-text-dark);
                           background:var(--color-cream);">
            </div>

            <!-- Role -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Role
                </label>
                <select id="userRole"
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
                    <option value="professor">Professor</option>
                    <option value="student">Student</option>
                </select>
            </div>

            <!-- Department -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Department
                </label>
                <select id="userDepartment"
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
                    <option value="">Select department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            <?= htmlspecialchars($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div style="margin-bottom:24px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Status
                </label>
                <select id="userStatus"
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
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
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
                    <span id="submitText">Save User</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ─── Tab + Search Styles ───────────────────── -->
<style>
.user-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-mid);
    cursor: pointer;
    border: none;
    background: transparent;
    transition: var(--transition);
}

.user-tab:hover {
    background: var(--color-sage);
}

.active-tab {
    background: var(--color-mint-dark) !important;
    color: white !important;
}
</style>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

// ─── Filter by role ───────────────────────────
function filterUsers(role) {
    document.querySelectorAll('.user-tab').forEach(btn => {
        btn.style.background = 'transparent';
        btn.style.color      = 'var(--color-text-mid)';
    });

    const active = document.getElementById('tab-' + role);
    active.style.background = 'var(--color-mint-dark)';
    active.style.color      = 'white';

    document.querySelectorAll('.user-row').forEach(row => {
        row.style.display = (
            role === 'all' ||
            row.dataset.role === role
        ) ? '' : 'none';
    });
}

// ─── Search ───────────────────────────────────
function searchUsers() {
    const query = document.getElementById('searchInput')
        .value.toLowerCase();
    document.querySelectorAll('.user-row').forEach(row => {
        row.style.display =
            row.textContent.toLowerCase().includes(query)
                ? '' : 'none';
    });
}

// ─── Open Add Modal ───────────────────────────
function openModal() {
    document.getElementById('modalTitle').textContent  = 'Add User';
    document.getElementById('userId').value            = '';
    document.getElementById('userName').value          = '';
    document.getElementById('userReg').value           = '';
    document.getElementById('userEmail').value         = '';
    document.getElementById('userPassword').value      = '';
    document.getElementById('userRole').value          = 'professor';
    document.getElementById('userDepartment').value    = '';
    document.getElementById('userStatus').value        = '1';
    document.getElementById('submitText').textContent  = 'Save User';
    document.getElementById('passwordNote').textContent = '(required)';
    document.getElementById('userPassword').required   = true;
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('userModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Open Edit Modal ──────────────────────────
function openEditModal(
    id, name, reg, email, role, deptId, status
) {
    document.getElementById('modalTitle').textContent  = 'Edit User';
    document.getElementById('userId').value            = id;
    document.getElementById('userName').value          = name;
    document.getElementById('userReg').value           = reg;
    document.getElementById('userEmail').value         = email;
    document.getElementById('userPassword').value      = '';
    document.getElementById('userRole').value          = role;
    document.getElementById('userDepartment').value    = deptId || '';
    document.getElementById('userStatus').value        = status;
    document.getElementById('submitText').textContent  = 'Update User';
    document.getElementById('passwordNote').textContent =
        '(leave empty to keep current)';
    document.getElementById('userPassword').required   = false;
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('userModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Close Modal ──────────────────────────────
function closeModal() {
    document.getElementById('userModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ─── Close on backdrop ────────────────────────
document.getElementById('userModal')
    .addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

// ─── Show modal alert ─────────────────────────
function showModalAlert(success, message) {
    const box = document.getElementById('modalAlert');
    box.style.display    = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.color      = success ? '#3a8a5a'  : '#c0392b';
    box.innerHTML = `
        <i class="fa-solid ${success
            ? 'fa-circle-check'
            : 'fa-circle-exclamation'}">
        </i>
        &nbsp;${message}`;
}

// ─── Submit Form ──────────────────────────────
document.getElementById('userForm')
    .addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn  = document.getElementById('submitBtn');
        const text = document.getElementById('submitText');

        btn.disabled     = true;
        text.textContent = 'Saving...';

        const data = {
            id:            document.getElementById('userId').value,
            name:          document.getElementById('userName').value.trim(),
            reg_number:    document.getElementById('userReg').value.trim(),
            email:         document.getElementById('userEmail').value.trim(),
            password:      document.getElementById('userPassword').value,
            role:          document.getElementById('userRole').value,
            department_id: document.getElementById('userDepartment').value || null,
            is_active:     document.getElementById('userStatus').value,
        };

        try {
            const res = await fetch(
                '/TimeTable/api/admin/users.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(data)
                }
            );

            const result = await res.json();

            if (result.success) {
                showModalAlert(true, result.message);
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showModalAlert(false, result.message);
                btn.disabled     = false;
                text.textContent = data.id ? 'Update User' : 'Save User';
            }

        } catch(err) {
            showModalAlert(false, 'Connection error. Please try again.');
            btn.disabled     = false;
            text.textContent = data.id ? 'Update User' : 'Save User';
        }
    });

// ─── Delete User ──────────────────────────────
async function deleteUser(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"?`)) return;

    try {
        const res = await fetch(
            '/TimeTable/api/admin/users.php',
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