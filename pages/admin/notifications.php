<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'notifications';
$pageTitle    = 'Notifications';
$pageSubtitle = 'Stay updated with the latest information.';

// ─── Unread count BEFORE marking as read ──────
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

// ─── Get all notifications ────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// ─── Mark all as read ─────────────────────────
$pdo->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ?
")->execute([$user['id']]);

// ─── Helpers ──────────────────────────────────
function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60)    . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600)  . ' hours ago';
    return                     floor($diff / 86400) . ' days ago';
}

function getNotifIcon($type) {
    return match($type) {
        'success' => ['fa-circle-check',       'notif-icon-new'],
        'warning' => ['fa-triangle-exclamation','notif-icon-time'],
        'error'   => ['fa-circle-xmark',       'notif-icon-room'],
        default   => ['fa-bell',               'notif-icon-info'],
    };
}

function getDot($type) {
    return match($type) {
        'success' => 'dot-green',
        'info'    => 'dot-blue',
        'warning' => 'dot-orange',
        'error'   => 'dot-orange',
        default   => 'dot-grey',
    };
}

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Notifications Card ───────────────────── -->
<div class="card">

    <!-- ─── Header ───────────────────────────── -->
    <div style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        margin-bottom:20px;">

        <div style="
            display:flex;
            align-items:center;
            gap:14px;">
            <div class="updates-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div>
                <h2 style="
                    font-size:17px;
                    font-weight:700;
                    color:var(--color-text-dark);">
                    All Notifications
                </h2>
                <p style="
                    font-size:13px;
                    color:var(--color-text-light);
                    margin-top:2px;">
                    <?= count($notifications) ?> total
                    <?php if ($unreadCount > 0): ?>
                        &nbsp;·&nbsp;
                        <span style="
                            color:var(--color-rose);
                            font-weight:600;">
                            <?= $unreadCount ?> unread
                        </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div style="display:flex; gap:8px;">
            <button onclick="filterNotifs('all')"
                    id="btn-all"
                    style="
                        padding:7px 14px;
                        border-radius:var(--radius-md);
                        border:1px solid var(--color-border);
                        background:var(--color-mint-dark);
                        color:white;
                        font-size:13px;
                        font-weight:500;
                        cursor:pointer;">
                All
            </button>
            <button onclick="filterNotifs('unread')"
                    id="btn-unread"
                    style="
                        padding:7px 14px;
                        border-radius:var(--radius-md);
                        border:1px solid var(--color-border);
                        background:var(--color-white);
                        color:var(--color-text-mid);
                        font-size:13px;
                        font-weight:500;
                        cursor:pointer;">
                Unread
            </button>
        </div>

    </div>

    <!-- ─── Notifications List ────────────────── -->
    <div class="notif-list" id="notifList">

        <?php if (empty($notifications)): ?>

            <div class="caught-up">
                <div class="caught-up-icon">
                    <i class="fa-solid fa-envelope-open"></i>
                </div>
                <div class="caught-up-text">
                    <strong>You're all caught up!</strong>
                    <p>No notifications at the moment.</p>
                </div>
                <i class="fa-solid fa-bell bell-decoration"></i>
            </div>

        <?php else: ?>

            <?php foreach ($notifications as $notif):
                [$icon, $iconClass] = getNotifIcon($notif['type']);
                $dotClass  = getDot($notif['type']);
                $wasUnread = !$notif['is_read'];
            ?>
            <div class="notif-item <?= $wasUnread ? 'unread' : '' ?>"
                 data-read="<?= $wasUnread ? '0' : '1' ?>">

                <!-- Icon -->
                <div class="notif-item-icon <?= $iconClass ?>">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>

                <!-- Dot -->
                <span class="notif-dot <?= $dotClass ?>"></span>

                <!-- Body -->
                <div class="notif-body">
                    <div class="notif-title">
                        <strong>
                            <?= htmlspecialchars($notif['title']) ?>
                        </strong>
                    </div>
                    <div class="notif-sub">
                        <?= htmlspecialchars($notif['message']) ?>
                    </div>
                </div>

                <!-- Time -->
                <div class="notif-time">
                    <?= timeAgo($notif['created_at']) ?>
                    <i class="fa-solid fa-chevron-right notif-arrow"></i>
                </div>

            </div>
            <?php endforeach; ?>

            <!-- All caught up footer -->
            <div class="caught-up"
                 style="
                     border-top:1px solid var(--color-border);
                     margin-top:8px;">
                <div class="caught-up-icon">
                    <i class="fa-solid fa-envelope-open"></i>
                </div>
                <div class="caught-up-text">
                    <strong>You're all caught up!</strong>
                    <p>No new notifications at the moment.</p>
                </div>
                <i class="fa-solid fa-bell bell-decoration"></i>
            </div>

        <?php endif; ?>

    </div>
</div>

<!-- ─── Script ───────────────────────────────── -->
<script>
function filterNotifs(type) {

    const btnAll    = document.getElementById('btn-all');
    const btnUnread = document.getElementById('btn-unread');

    if (type === 'all') {
        btnAll.style.background    = 'var(--color-mint-dark)';
        btnAll.style.color         = 'white';
        btnUnread.style.background = 'var(--color-white)';
        btnUnread.style.color      = 'var(--color-text-mid)';
    } else {
        btnUnread.style.background = 'var(--color-mint-dark)';
        btnUnread.style.color      = 'white';
        btnAll.style.background    = 'var(--color-white)';
        btnAll.style.color         = 'var(--color-text-mid)';
    }

    document.querySelectorAll('.notif-item').forEach(item => {
        if (type === 'all') {
            item.style.display = 'flex';
        } else {
            item.style.display =
                item.dataset.read === '0' ? 'flex' : 'none';
        }
    });
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>