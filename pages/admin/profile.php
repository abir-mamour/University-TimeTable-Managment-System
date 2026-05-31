<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
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

// ─── Pending requests ─────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
    WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Full profile ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*
    FROM users u
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// ─── System stats ─────────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'professor' AND is_active = 1
");
$totalProfessors = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'student' AND is_active = 1
");
$totalStudents = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM rooms
    WHERE is_active = 1
");
$totalRooms = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM timetable
    WHERE is_active = 1
");
$totalSessions = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
");
$totalRequests = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
    WHERE status = 'accepted'
");
$acceptedRequests = $stmt->fetchColumn();

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
        <div style="
            position:absolute;
            top:20px; left:200px;
            width:50px; height:50px;
            border-radius:50%;
            background:rgba(255,255,255,0.06);">
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
                <i class="fa-solid fa-user-shield"
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
                        <i class="fa-solid fa-envelope"
                           style="font-size:11px;">
                        </i>
                        <?= htmlspecialchars(
                            $profile['email'] ?? 'N/A'
                        ) ?>
                    </span>
                    <span style="
                        padding:3px 12px;
                        background:var(--color-rose-light);
                        border:1px solid var(--color-rose);
                        border-radius:20px;
                        font-size:11px;
                        font-weight:600;
                        color:var(--color-rose-dark);">
                        <i class="fa-solid fa-user-shield"
                           style="margin-right:3px;">
                        </i>
                        Administrator
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
                   style="
                       color:var(--color-mint-dark);
                       font-size:15px;">
                </i>
            </div>
            <div>
                <p style="
                    font-size:11px;
                    color:var(--color-text-light);
                    margin-bottom:2px;">
                    Admin ID
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

<!-- ─── Info + Stats Row ──────────────────────── -->
<div style="
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    margin-bottom:20px;">

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
                <span class="info-label">Admin ID</span>
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
                    <?= htmlspecialchars(
                        $profile['email'] ?? 'N/A'
                    ) ?>
                </span>
            </div>

            <div class="info-row">
                <div class="info-icon">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <span class="info-label">Role</span>
                <span class="info-value">
                    <span style="
                        padding:3px 10px;
                        background:var(--color-rose-light);
                        border:1px solid var(--color-rose);
                        border-radius:20px;
                        font-size:11px;
                        font-weight:600;
                        color:var(--color-rose-dark);">
                        Administrator
                    </span>
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

    <!-- System Overview -->
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
                <i class="fa-solid fa-chart-bar"
                   style="color:var(--color-mint-dark);">
                </i>
                System Overview
            </h3>
        </div>

        <!-- Stats List -->
        <div style="padding:16px;">
            <div style="
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:12px;">

                <!-- Professors -->
                <div style="
                    padding:14px;
                    background:var(--color-mint-light);
                    border-radius:var(--radius-md);
                    border:1px solid var(--color-mint-dark);
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-chalkboard-user"
                       style="
                           color:var(--color-mint-dark);
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Professors
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $totalProfessors ?>
                        </p>
                    </div>
                </div>

                <!-- Students -->
                <div style="
                    padding:14px;
                    background:var(--color-sage);
                    border-radius:var(--radius-md);
                    border:1px solid var(--color-border);
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-user-graduate"
                       style="
                           color:#5a8a6a;
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Students
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $totalStudents ?>
                        </p>
                    </div>
                </div>

                <!-- Rooms -->
                <div style="
                    padding:14px;
                    background:var(--color-cream);
                    border-radius:var(--radius-md);
                    border:1px solid var(--color-border);
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-door-open"
                       style="
                           color:#8a7a5a;
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Rooms
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $totalRooms ?>
                        </p>
                    </div>
                </div>

                <!-- Sessions -->
                <div style="
                    padding:14px;
                    background:var(--color-rose-light);
                    border-radius:var(--radius-md);
                    border:1px solid var(--color-rose);
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-calendar-check"
                       style="
                           color:var(--color-rose-dark);
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Sessions
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $totalSessions ?>
                        </p>
                    </div>
                </div>

                <!-- Total Requests -->
                <div style="
                    padding:14px;
                    background:var(--color-white);
                    border-radius:var(--radius-md);
                    border:1px solid var(--color-border);
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-inbox"
                       style="
                           color:var(--color-text-mid);
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Total Requests
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $totalRequests ?>
                        </p>
                    </div>
                </div>

                <!-- Accepted -->
                <div style="
                    padding:14px;
                    background:#e8f5ee;
                    border-radius:var(--radius-md);
                    border:1px solid #b7dfca;
                    display:flex;
                    align-items:center;
                    gap:10px;">
                    <i class="fa-solid fa-circle-check"
                       style="
                           color:#3a8a5a;
                           font-size:20px;
                           width:24px;">
                    </i>
                    <div>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            Accepted
                        </p>
                        <p style="
                            font-size:20px;
                            font-weight:700;
                            color:var(--color-text-dark);
                            line-height:1;">
                            <?= $acceptedRequests ?>
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>