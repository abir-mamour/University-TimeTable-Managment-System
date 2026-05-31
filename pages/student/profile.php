<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('student');
$user = currentUser();

$activePage   = 'profile';
$pageTitle    = 'Profile';
$pageSubtitle = 'View your personal information.';

// ─── Unread notifications ─────────────────────
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

// ─── Full profile ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        u.*,
        g.group_name,
        g.level,
        d.name AS department_name
    FROM users u
    LEFT JOIN student_groups sg ON sg.student_id = u.id
    LEFT JOIN groups_table   g  ON sg.group_id   = g.id
    LEFT JOIN departments    d  ON u.department_id = d.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Profile Hero Card ────────────────────── -->
<div class="card" style="
    padding:0;
    overflow:hidden;
    margin-bottom:24px;">

    <!-- Cover Banner -->
    <div style="
        height:140px;
        background:linear-gradient(
            135deg,
            var(--color-mint-dark) 0%,
            #8ECBB6 50%,
            var(--color-sage) 100%
        );
        position:relative;">

        <!-- Decorative circles -->
        <div style="
            position:absolute;
            top:-30px; right:-30px;
            width:140px; height:140px;
            border-radius:50%;
            background:rgba(255,255,255,0.10);">
        </div>
        <div style="
            position:absolute;
            bottom:-20px; left:60px;
            width:80px; height:80px;
            border-radius:50%;
            background:rgba(255,255,255,0.08);">
        </div>
    </div>

    <!-- Profile Info Row -->
    <div style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:20px 32px 28px;
        flex-wrap:wrap;
        gap:16px;
        border-bottom:1px solid var(--color-border);">

        <div style="
            display:flex;
            align-items:center;
            gap:20px;">

            <!-- Avatar -->
            <div style="
                width:90px;
                height:90px;
                border-radius:50%;
                background:var(--color-mint-light);
                border:4px solid var(--color-white);
                box-shadow:var(--shadow-md);
                display:flex;
                align-items:center;
                justify-content:center;
                flex-shrink:0;
                margin-top:-60px;">
                <i class="fa-solid fa-user-graduate"
                   style="
                       font-size:38px;
                       color:var(--color-mint-dark);">
                </i>
            </div>

            <!-- Name & Info -->
            <div>
                <h2 style="
                    font-size:22px;
                    font-weight:700;
                    color:var(--color-text-dark);
                    margin-bottom:6px;">
                    <?= htmlspecialchars($profile['name']) ?>
                </h2>
                <div style="
                    display:flex;
                    align-items:center;
                    gap:10px;
                    flex-wrap:wrap;">
                    <span style="
                        font-size:13px;
                        color:var(--color-text-light);
                        display:flex;
                        align-items:center;
                        gap:5px;">
                        <i class="fa-solid fa-building-columns"
                           style="font-size:11px;">
                        </i>
                        <?= htmlspecialchars(
                            $profile['department_name'] ?? 'N/A'
                        ) ?>
                    </span>
                    <span style="
                        padding:3px 12px;
                        background:var(--color-mint-light);
                        border:1px solid var(--color-mint-dark);
                        border-radius:20px;
                        font-size:11px;
                        font-weight:600;
                        color:var(--color-mint-dark);">
                        <i class="fa-solid fa-user-graduate"
                           style="margin-right:3px;">
                        </i>
                        Student
                    </span>
                </div>
            </div>
        </div>

        <!-- ID Badge -->
        <div style="
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 20px;
            background:var(--color-cream);
            border:1px solid var(--color-border);
            border-radius:var(--radius-md);">
            <div style="
                width:36px; height:36px;
                border-radius:var(--radius-sm);
                background:var(--color-mint-light);
                display:flex;
                align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-id-badge"
                   style="color:var(--color-mint-dark);
                          font-size:15px;">
                </i>
            </div>
            <div>
                <p style="
                    font-size:11px;
                    color:var(--color-text-light);
                    margin-bottom:2px;">
                    Student ID
                </p>
                <p style="
                    font-size:15px;
                    font-weight:700;
                    color:var(--color-text-dark);">
                    <?= htmlspecialchars($profile['reg_number']) ?>
                </p>
            </div>
        </div>

    </div>
</div>

<!-- ─── Info + Group Row ──────────────────────── -->
<div style="display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;">

    <!-- Personal Information -->
    <div class="card" style="padding:0; overflow:hidden;">

        <!-- Header -->
        <div style="
            padding:16px 22px;
            border-bottom:1px solid var(--color-border);
            background:var(--color-cream);">
            <h3 style="
                font-size:14px;
                font-weight:700;
                color:var(--color-text-dark);
                display:flex;
                align-items:center;
                gap:8px;">
                <i class="fa-solid fa-circle-info"
                   style="color:var(--color-mint-dark);">
                </i>
                Personal Information
            </h3>
        </div>

        <!-- Info List -->
        <div class="info-list">

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <span class="info-label">Student ID</span>
                <span class="info-value">
                    <?= htmlspecialchars($profile['reg_number']) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-user"></i>
                </div>
                <span class="info-label">Full Name</span>
                <span class="info-value">
                    <?= htmlspecialchars($profile['name']) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <span class="info-label">Email</span>
                <span class="info-value">
                    <?= htmlspecialchars($profile['email'] ?? 'N/A') ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <span class="info-label">Department</span>
                <span class="info-value">
                    <?= htmlspecialchars(
                        $profile['department_name'] ?? 'N/A'
                    ) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-calendar-plus"></i>
                </div>
                <span class="info-label">Registered On</span>
                <span class="info-value">
                    <?= date('d F Y',
                        strtotime($profile['created_at'])
                    ) ?>
                </span>
            </div>

        </div>
    </div>

    <!-- Academic Information -->
    <div class="card" style="padding:0; overflow:hidden;">

        <!-- Header -->
        <div style="
            padding:16px 22px;
            border-bottom:1px solid var(--color-border);
            background:var(--color-cream);">
            <h3 style="
                font-size:14px;
                font-weight:700;
                color:var(--color-text-dark);
                display:flex;
                align-items:center;
                gap:8px;">
                <i class="fa-solid fa-graduation-cap"
                   style="color:var(--color-mint-dark);">
                </i>
                Academic Information
            </h3>
        </div>

        <!-- Info List -->
        <div class="info-list">

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <span class="info-label">Year / Level</span>
                <span class="info-value">
                    <?= htmlspecialchars($profile['level'] ?? 'N/A') ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <span class="info-label">Cycle</span>
                <span class="info-value">Licence</span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <span class="info-label">Section</span>
                <span class="info-value">
                    <?= htmlspecialchars(
                        $profile['department_name'] ?? 'N/A'
                    ) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <span class="info-label">Group</span>
                <span class="info-value highlight">
                    <?= htmlspecialchars(
                        $profile['group_name'] ?? 'N/A'
                    ) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <span class="info-label">Semester</span>
                <span class="info-value">
                    <?php
                    $level = $profile['level'] ?? '';
                    $semester = match($level) {
                        'L1' => 'Semester 1 - 2',
                        'L2' => 'Semester 3 - 4',
                        'L3' => 'Semester 5 - 6',
                        'M1' => 'Semester 7 - 8',
                        'M2' => 'Semester 9 - 10',
                        default => 'N/A'
                    };
                    echo htmlspecialchars($semester);
                    ?>
                </span>
            </div>

        </div>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>