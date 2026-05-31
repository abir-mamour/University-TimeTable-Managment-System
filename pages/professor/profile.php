<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
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

// ─── Full profile ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*, d.name AS department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// ─── Assigned subjects ────────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT
        s.name         AS subject_name,
        s.code         AS subject_code,
        t.session_type,
        COUNT(DISTINCT t.group_id) AS group_count
    FROM timetable t
    JOIN subjects s ON t.subject_id = s.id
    WHERE t.professor_id = ?
      AND t.is_active    = 1
    GROUP BY s.id, t.session_type
    ORDER BY s.name
");
$stmt->execute([$user['id']]);
$assignedSubjects = $stmt->fetchAll();

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
                <i class="fa-solid fa-chalkboard-user"
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
                        <i class="fa-solid fa-chalkboard-user"
                           style="margin-right:3px;">
                        </i>
                        Professor
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
                    Professor ID
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

<!-- ─── Info + Subjects Row ───────────────────── -->
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

        <!-- Rows -->
        <div class="info-list">

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <span class="info-label">Professor ID</span>
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
                <span class="info-label">Joined On</span>
                <span class="info-value">
                    <?= date('d F Y',
                        strtotime($profile['created_at'])
                    ) ?>
                </span>
            </div>

        </div>
    </div>

    <!-- Assigned Subjects -->
    <div class="card" style="padding:0; overflow:hidden;">

        <!-- Header -->
        <div style="
            padding:16px 22px;
            border-bottom:1px solid var(--color-border);
            background:var(--color-cream);
            display:flex;
            align-items:center;
            justify-content:space-between;">
            <h3 style="
                font-size:14px;
                font-weight:700;
                color:var(--color-text-dark);
                display:flex;
                align-items:center;
                gap:8px;">
                <i class="fa-solid fa-book"
                   style="color:var(--color-mint-dark);">
                </i>
                Assigned Subjects
            </h3>
            <span style="
                padding:3px 10px;
                background:var(--color-mint-light);
                border-radius:20px;
                font-size:12px;
                font-weight:600;
                color:var(--color-mint-dark);">
                <?= count($assignedSubjects) ?> subjects
            </span>
        </div>

        <!-- Subjects -->
        <?php if (empty($assignedSubjects)): ?>
            <div style="
                text-align:center;
                padding:40px 20px;
                color:var(--color-text-light);">
                <i class="fa-solid fa-book-open"
                   style="font-size:36px;
                          display:block;
                          margin-bottom:12px;
                          opacity:0.3;">
                </i>
                <p style="font-size:13px;">
                    No subjects assigned yet.
                </p>
            </div>
        <?php else: ?>
            <div style="
                padding:16px;
                display:flex;
                flex-direction:column;
                gap:10px;
                max-height:320px;
                overflow-y:auto;">
                <?php foreach ($assignedSubjects as $sub):
                    $badge = match($sub['session_type']) {
                        'lecture' => [
                            'bg'     => '#e8f5ee',
                            'border' => '#b7dfca',
                            'color'  => '#3a8a5a',
                            'label'  => 'Lesson',
                            'icon'   => 'fa-chalkboard'
                        ],
                        'lab' => [
                            'bg'     => 'var(--color-mint-light)',
                            'border' => 'var(--color-mint-dark)',
                            'color'  => 'var(--color-mint-dark)',
                            'label'  => 'TP',
                            'icon'   => 'fa-flask'
                        ],
                        'seminar' => [
                            'bg'     => 'var(--color-rose-light)',
                            'border' => 'var(--color-rose)',
                            'color'  => 'var(--color-rose-dark)',
                            'label'  => 'TD',
                            'icon'   => 'fa-users'
                        ],
                        default => [
                            'bg'     => 'var(--color-sage)',
                            'border' => 'var(--color-border)',
                            'color'  => 'var(--color-text-mid)',
                            'label'  => 'Class',
                            'icon'   => 'fa-book'
                        ],
                    };
                ?>
                    <div style="
                        display:flex;
                        align-items:center;
                        gap:12px;
                        padding:12px 14px;
                        border-radius:var(--radius-md);
                        border:1px solid var(--color-border);
                        background:var(--color-white);
                        transition:var(--transition);"
                        onmouseover="this.style.background='var(--color-cream)'"
                        onmouseout="this.style.background='var(--color-white)'">

                        <!-- Icon -->
                        <div style="
                            width:40px; height:40px;
                            border-radius:var(--radius-md);
                            background:<?= $badge['bg'] ?>;
                            border:1px solid <?= $badge['border'] ?>;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            flex-shrink:0;">
                            <i class="fa-solid <?= $badge['icon'] ?>"
                               style="font-size:15px;
                                      color:<?= $badge['color'] ?>;">
                            </i>
                        </div>

                        <!-- Info -->
                        <div style="flex:1; min-width:0;">
                            <p style="
                                font-size:13px;
                                font-weight:600;
                                color:var(--color-text-dark);
                                margin-bottom:3px;
                                white-space:nowrap;
                                overflow:hidden;
                                text-overflow:ellipsis;">
                                <?= htmlspecialchars($sub['subject_name']) ?>
                            </p>
                            <div style="
                                display:flex;
                                align-items:center;
                                gap:8px;">
                                <span style="
                                    font-size:11px;
                                    color:var(--color-text-light);
                                    background:var(--color-cream);
                                    padding:1px 6px;
                                    border-radius:4px;">
                                    <?= htmlspecialchars($sub['subject_code']) ?>
                                </span>
                                <span style="
                                    font-size:11px;
                                    color:var(--color-text-light);
                                    display:flex;
                                    align-items:center;
                                    gap:3px;">
                                    <i class="fa-solid fa-users"
                                       style="font-size:10px;">
                                    </i>
                                    <?= $sub['group_count'] ?>
                                    <?= $sub['group_count'] > 1
                                        ? 'groups' : 'group' ?>
                                </span>
                            </div>
                        </div>

                        <!-- Badge -->
                        <span style="
                            padding:4px 10px;
                            background:<?= $badge['bg'] ?>;
                            border:1px solid <?= $badge['border'] ?>;
                            border-radius:20px;
                            font-size:11px;
                            font-weight:600;
                            color:<?= $badge['color'] ?>;
                            white-space:nowrap;">
                            <?= $badge['label'] ?>
                        </span>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>