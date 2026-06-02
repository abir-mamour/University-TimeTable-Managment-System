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
$stmt = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'");
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
$pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
    ->execute([$user['id']]);

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
        'success' => ['fa-circle-check',        'notif-icon-new'],
        'warning' => ['fa-triangle-exclamation','notif-icon-time'],
        'error'   => ['fa-circle-xmark',        'notif-icon-room'],
        default   => ['fa-bell',                'notif-icon-info'],
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

// Returns destination URL or null (= show modal)
function notifUrl(string $title): ?string {
    return match($title) {
        'New Request'         => '/TimeTable/pages/admin/requests.php',
        'Availability Changed' => '/TimeTable/pages/admin/availability.php',
        default       => null,
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

        <div style="display:flex; align-items:center; gap:14px;">
            <div class="updates-icon">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div>
                <h2 style="font-size:17px; font-weight:700; color:var(--color-text-dark);">
                    All Notifications
                </h2>
                <p style="font-size:13px; color:var(--color-text-light); margin-top:2px;">
                    <?= count($notifications) ?> total
                    <?php if ($unreadCount > 0): ?>
                        &nbsp;·&nbsp;
                        <span style="color:var(--color-rose); font-weight:600;">
                            <?= $unreadCount ?> unread
                        </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div style="display:flex; gap:8px;">
            <button onclick="filterNotifs('all')" id="btn-all"
                    style="padding:7px 14px; border-radius:var(--radius-md);
                           border:1px solid var(--color-border);
                           background:var(--color-mint-dark); color:white;
                           font-size:13px; font-weight:500; cursor:pointer;">
                All
            </button>
            <button onclick="filterNotifs('unread')" id="btn-unread"
                    style="padding:7px 14px; border-radius:var(--radius-md);
                           border:1px solid var(--color-border);
                           background:var(--color-white); color:var(--color-text-mid);
                           font-size:13px; font-weight:500; cursor:pointer;">
                Unread
            </button>
        </div>
    </div>

    <!-- ─── Notifications List ────────────────── -->
    <div class="notif-list" id="notifList">

        <?php if (empty($notifications)): ?>
            <div class="caught-up">
                <div class="caught-up-icon"><i class="fa-solid fa-envelope-open"></i></div>
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
                $url       = notifUrl($notif['title']);
            ?>
            <div class="notif-item <?= $wasUnread ? 'unread' : '' ?>"
                 data-read="<?= $wasUnread ? '0' : '1' ?>"
                 data-url="<?= $url ?? '' ?>"
                 data-title="<?= htmlspecialchars($notif['title'], ENT_QUOTES) ?>"
                 data-message="<?= htmlspecialchars($notif['message'], ENT_QUOTES) ?>"
                 data-time="<?= htmlspecialchars(timeAgo($notif['created_at']), ENT_QUOTES) ?>"
                 style="cursor:pointer;">

                <div class="notif-item-icon <?= $iconClass ?>">
                    <i class="fa-solid <?= $icon ?>"></i>
                </div>

                <span class="notif-dot <?= $dotClass ?>"></span>

                <div class="notif-body">
                    <div class="notif-title">
                        <strong><?= htmlspecialchars($notif['title']) ?></strong>
                    </div>
                    <div class="notif-sub">
                        <?= htmlspecialchars($notif['message']) ?>
                    </div>
                </div>

                <div class="notif-time">
                    <?= timeAgo($notif['created_at']) ?>
                    <?php if ($url): ?>
                        <i class="fa-solid fa-arrow-up-right-from-square notif-arrow"
                           style="color:var(--color-mint-dark);"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-chevron-right notif-arrow"></i>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="caught-up" style="border-top:1px solid var(--color-border); margin-top:8px;">
                <div class="caught-up-icon"><i class="fa-solid fa-envelope-open"></i></div>
                <div class="caught-up-text">
                    <strong>You're all caught up!</strong>
                    <p>No new notifications at the moment.</p>
                </div>
                <i class="fa-solid fa-bell bell-decoration"></i>
            </div>

        <?php endif; ?>

    </div>
</div>

<!-- ─── Detail Modal ──────────────────────────── -->
<div id="notifModal" style="
    display:none; position:fixed; inset:0; z-index:1000;
    background:rgba(0,0,0,0.45); align-items:center; justify-content:center;">
    <div style="
        background:var(--color-white);
        border-radius:var(--radius-lg);
        padding:28px 28px 22px;
        max-width:440px; width:90%;
        box-shadow:0 8px 32px rgba(0,0,0,0.18);
        position:relative;">

        <button onclick="closeModal()" style="
            position:absolute; top:14px; right:14px;
            background:none; border:none; cursor:pointer;
            color:var(--color-text-light); font-size:18px; line-height:1;">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
            <div class="updates-icon" style="flex-shrink:0;">
                <i class="fa-solid fa-bell"></i>
            </div>
            <h3 id="modalTitle" style="
                font-size:16px; font-weight:700;
                color:var(--color-text-dark); margin:0;">
            </h3>
        </div>

        <p id="modalMessage" style="
            font-size:14px; color:var(--color-text-mid);
            line-height:1.6; margin:0 0 16px;">
        </p>

        <p id="modalTime" style="
            font-size:12px; color:var(--color-text-light);
            margin:0; display:flex; align-items:center; gap:6px;">
            <i class="fa-regular fa-clock"></i>
        </p>
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
        item.style.display = (type === 'all' || item.dataset.read === '0') ? 'flex' : 'none';
    });
}

document.querySelectorAll('.notif-item').forEach(item => {
    item.addEventListener('click', () => {
        const url = item.dataset.url;
        if (url) {
            window.location.href = url;
        } else {
            document.getElementById('modalTitle').textContent   = item.dataset.title;
            document.getElementById('modalMessage').textContent = item.dataset.message;
            document.getElementById('modalTime').innerHTML =
                '<i class="fa-regular fa-clock"></i> ' + item.dataset.time;
            const modal = document.getElementById('notifModal');
            modal.style.display = 'flex';
        }
    });
});

function closeModal() {
    document.getElementById('notifModal').style.display = 'none';
}

document.getElementById('notifModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
