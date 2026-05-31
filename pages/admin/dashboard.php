<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'dashboard';
$pageTitle    = 'Admin Dashboard';
$pageSubtitle = 'Welcome back, ' . $user['name'] . '!';

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

// ─── Accepted requests ────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
    WHERE status = 'accepted'
");
$acceptedRequests = $stmt->fetchColumn();

// ─── Total groups ─────────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM groups_table
");
$totalGroups = $stmt->fetchColumn();

// ─── Total subjects ───────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM subjects
");
$totalSubjects = $stmt->fetchColumn();

// ─── Recent requests ──────────────────────────
$stmt = $pdo->query("
    SELECT
        r.*,
        u.name AS professor_name,
        s.name AS subject_name
    FROM requests r
    JOIN users u         ON r.professor_id = u.id
    LEFT JOIN subjects s ON r.subject_id   = s.id
    ORDER BY r.created_at DESC
    LIMIT 5
");
$recentRequests = $stmt->fetchAll();

// ─── Helpers ──────────────────────────────────
function statusBadge($status) {
    return match($status) {
        'accepted' => [
            'bg'    => '#e8f5ee',
            'color' => '#3a8a5a',
            'label' => 'Accepted'
        ],
        'rejected' => [
            'bg'    => '#fde8e8',
            'color' => '#c0392b',
            'label' => 'Rejected'
        ],
        default => [
            'bg'    => '#fff3e0',
            'color' => '#e08a2a',
            'label' => 'Pending'
        ],
    };
}

function requestTypeLabel($type) {
    return match($type) {
        'new_class'       => 'New Class',
        'schedule_change' => 'Change Time',
        'overload'        => 'Overload',
        'room_change'     => 'Room Change',
        default           => ucfirst($type),
    };
}

function requestTypeIcon($type) {
    return match($type) {
        'new_class'       => 'fa-plus-circle',
        'schedule_change' => 'fa-clock-rotate-left',
        'overload'        => 'fa-layer-group',
        'room_change'     => 'fa-door-open',
        default           => 'fa-paper-plane',
    };
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60)    . 'm ago';
    if ($diff < 86400) return floor($diff / 3600)  . 'h ago';
    return                     floor($diff / 86400) . 'd ago';
}

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div style="
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:20px;
    margin-bottom:24px;">

    <!-- Total Groups -->
    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Groups</p>
            <div class="stat-value"><?= $totalGroups ?></div>
            <p class="stat-sub">Student groups</p>
        </div>
    </div>

    <!-- Total Subjects -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-book"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Subjects</p>
            <div class="stat-value"><?= $totalSubjects ?></div>
            <p class="stat-sub">Active subjects</p>
        </div>
    </div>

    <!-- Accepted Requests -->
    <div class="stat-card">
        <div class="stat-icon cream">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Accepted</p>
            <div class="stat-value"><?= $acceptedRequests ?></div>
            <p class="stat-sub">Accepted requests</p>
        </div>
    </div>

        <!-- Pending Requests -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Pending Requests</p>
            <div class="stat-value"><?= $pendingRequests ?></div>
            <p class="stat-sub">Awaiting action</p>
        </div>
    </div>

</div>

<!-- ─── Recent Requests ───────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <!-- Header -->
    <div style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:18px 24px;
        border-bottom:1px solid var(--color-border);
        background:var(--color-cream);">

        <h3 style="
            font-size:15px;
            font-weight:700;
            color:var(--color-text-dark);
            display:flex;
            align-items:center;
            gap:8px;">
            <i class="fa-solid fa-inbox"
               style="color:var(--color-mint-dark);">
            </i>
            Recent Requests
        </h3>

        <?php if ($pendingRequests > 0): ?>
            <span style="
                padding:3px 12px;
                background:var(--color-rose-light);
                border:1px solid var(--color-rose);
                border-radius:20px;
                font-size:12px;
                font-weight:600;
                color:var(--color-rose-dark);">
                <?= $pendingRequests ?> pending
            </span>
        <?php endif; ?>

    </div>

    <!-- Table -->
    <?php if (empty($recentRequests)): ?>

        <div style="
            text-align:center;
            padding:50px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-inbox"
               style="
                   font-size:40px;
                   display:block;
                   margin-bottom:14px;
                   opacity:0.3;">
            </i>
            <p style="
                font-size:14px;
                font-weight:600;
                color:var(--color-text-dark);
                margin-bottom:4px;">
                No requests yet
            </p>
            <p style="font-size:13px;">
                Professor requests will appear here.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="
                    background:var(--color-sage);
                    border-bottom:2px solid var(--color-border);">
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Professor
                    </th>
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Request Type
                    </th>
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Details
                    </th>
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Date
                    </th>
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Status
                    </th>
                    <th style="
                        padding:12px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Action
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRequests as $i => $req):
                    $badge = statusBadge($req['status']);
                ?>
                <tr style="
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

                    <!-- Professor -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:10px;">
                            <div style="
                                width:34px; height:34px;
                                border-radius:50%;
                                background:var(--color-mint-light);
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid fa-chalkboard-user"
                                   style="
                                       font-size:13px;
                                       color:var(--color-mint-dark);">
                                </i>
                            </div>
                            <span style="
                                font-size:13px;
                                font-weight:600;
                                color:var(--color-text-dark);">
                                <?= htmlspecialchars(
                                    $req['professor_name']
                                ) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Type -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:8px;">
                            <i class="fa-solid <?= requestTypeIcon(
                                $req['request_type']
                            ) ?>"
                               style="
                                   color:var(--color-mint-dark);
                                   font-size:13px;">
                            </i>
                            <span style="
                                font-size:13px;
                                font-weight:500;
                                color:var(--color-text-dark);">
                                <?= requestTypeLabel(
                                    $req['request_type']
                                ) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Details -->
                    <td style="
                        padding:16px 20px;
                        max-width:220px;">
                        <p style="
                            font-size:13px;
                            color:var(--color-text-mid);
                            line-height:1.4;
                            white-space:nowrap;
                            overflow:hidden;
                            text-overflow:ellipsis;
                            max-width:200px;">
                            <?= htmlspecialchars($req['details']) ?>
                        </p>
                    </td>

                    <!-- Date -->
                    <td style="padding:16px 20px;">
                        <span style="
                            font-size:12px;
                            color:var(--color-text-light);">
                            <?= timeAgo($req['created_at']) ?>
                        </span>
                    </td>

                    <!-- Status -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:5px 12px;
                            background:<?= $badge['bg'] ?>;
                            color:<?= $badge['color'] ?>;
                            border-radius:20px;
                            font-size:12px;
                            font-weight:600;">
                            <?= $badge['label'] ?>
                        </span>
                    </td>

                    <!-- Action -->
                    <td style="padding:16px 20px;">
                        <?php if ($req['status'] === 'pending'): ?>
                            <a href="/TimeTable/pages/admin/requests.php"
                               style="
                                   display:inline-flex;
                                   align-items:center;
                                   gap:5px;
                                   padding:6px 14px;
                                   background:var(--color-mint-dark);
                                   color:white;
                                   border-radius:var(--radius-md);
                                   font-size:12px;
                                   font-weight:600;
                                   text-decoration:none;
                                   transition:var(--transition);"
                               onmouseover="this.style.background='#6BB8A0'"
                               onmouseout="this.style.background=
                                   'var(--color-mint-dark)'">
                                Review
                                <i class="fa-solid fa-arrow-right"
                                   style="font-size:10px;">
                                </i>
                            </a>
                        <?php else: ?>
                            <span style="
                                font-size:13px;
                                color:var(--color-border);">
                                —
                            </span>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- View All -->
        <a href="/TimeTable/pages/admin/requests.php"
           class="view-full-bar">
            <span>View All Requests</span>
            <i class="fa-solid fa-arrow-right"></i>
        </a>

    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>