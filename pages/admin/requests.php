<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'requests';
$pageTitle    = 'Requests Management';
$pageSubtitle = 'Review and handle professor requests.';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Pending requests count ───────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests
    WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Get all requests ─────────────────────────
$stmt = $pdo->query("
    SELECT
        r.*,
        u.name  AS professor_name,
        s.name  AS subject_name,
        g.group_name
    FROM requests r
    JOIN users         u ON r.professor_id = u.id
    LEFT JOIN subjects s ON r.subject_id   = s.id
    LEFT JOIN groups_table g ON r.group_id = g.id
    ORDER BY
        FIELD(r.status, 'pending', 'accepted', 'rejected'),
        r.created_at DESC
");
$requests = $stmt->fetchAll();

// ─── Helpers ──────────────────────────────────
function statusBadge($status) {
    return match($status) {
        'accepted' => [
            'bg'     => '#e8f5ee',
            'color'  => '#3a8a5a',
            'border' => '#b7dfca',
            'label'  => 'Accepted'
        ],
        'rejected' => [
            'bg'     => '#fde8e8',
            'color'  => '#c0392b',
            'border' => '#f5c6c6',
            'label'  => 'Rejected'
        ],
        default => [
            'bg'     => '#fff3e0',
            'color'  => '#e08a2a',
            'border' => '#fdd9a0',
            'label'  => 'Pending'
        ],
    };
}

function requestTypeLabel($type) {
    return match($type) {
        'new_class'       => 'New Class',
        'schedule_change' => 'Change Time',
        'overload'        => 'Overload',
        'room_change'     => 'Room Change',
        'group_switch'    => 'Group Switch',  // ← ADD
        default           => ucfirst($type),
    };
}

function requestTypeIcon($type) {
    return match($type) {
        'new_class'       => 'fa-plus-circle',
        'schedule_change' => 'fa-clock-rotate-left',
        'overload'        => 'fa-layer-group',
        'room_change'     => 'fa-door-open',
        'group_switch'    => 'fa-right-left',  // ← ADD
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
<div class="stats-row" style="grid-template-columns:repeat(3,1fr);
                               margin-bottom:24px;">

    <!-- Pending -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Pending</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $requests,
                    fn($r) => $r['status'] === 'pending'
                )) ?>
            </div>
            <p class="stat-sub">Awaiting review</p>
        </div>
    </div>

    <!-- Accepted -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Accepted</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $requests,
                    fn($r) => $r['status'] === 'accepted'
                )) ?>
            </div>
            <p class="stat-sub">Approved requests</p>
        </div>
    </div>

    <!-- Rejected -->
    <div class="stat-card">
        <div class="stat-icon cream">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Rejected</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $requests,
                    fn($r) => $r['status'] === 'rejected'
                )) ?>
            </div>
            <p class="stat-sub">Declined requests</p>
        </div>
    </div>

</div>

<!-- ─── Filter Tabs ───────────────────────────── -->
<div style="
    display:flex;
    gap:6px;
    margin-bottom:20px;
    background:var(--color-white);
    padding:6px;
    border-radius:var(--radius-md);
    border:1px solid var(--color-border);
    width:fit-content;">

    <button onclick="filterRequests('all')"
            id="tab-all"
            class="req-tab active-tab">
        <i class="fa-solid fa-inbox"></i>
        All
    </button>
    <button onclick="filterRequests('pending')"
            id="tab-pending"
            class="req-tab">
        <i class="fa-solid fa-clock"></i>
        Pending
    </button>
    <button onclick="filterRequests('accepted')"
            id="tab-accepted"
            class="req-tab">
        <i class="fa-solid fa-circle-check"></i>
        Accepted
    </button>
    <button onclick="filterRequests('rejected')"
            id="tab-rejected"
            class="req-tab">
        <i class="fa-solid fa-circle-xmark"></i>
        Rejected
    </button>

</div>

<!-- ─── Requests Table ────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($requests)): ?>

        <div style="
            text-align:center;
            padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-inbox"
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
                No requests yet
            </p>
            <p style="font-size:13px;">
                Professor requests will appear here.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;"
               id="requestsTable">
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
                        Professor
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Request Type
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Details
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Date
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
            <tbody>
                <?php foreach ($requests as $i => $req):
                    $badge = statusBadge($req['status']);
                ?>
                <tr class="req-row"
                    data-status="<?= $req['status'] ?>"
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

                    <!-- Professor -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:10px;">
                            <div style="
                                width:36px; height:36px;
                                border-radius:50%;
                                background:var(--color-mint-light);
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid fa-chalkboard-user"
                                   style="
                                       font-size:14px;
                                       color:var(--color-mint-dark);">
                                </i>
                            </div>
                            <div>
                                <p style="
                                    font-size:13px;
                                    font-weight:600;
                                    color:var(--color-text-dark);
                                    margin-bottom:2px;">
                                    <?= htmlspecialchars(
                                        $req['professor_name']
                                    ) ?>
                                </p>
                                <?php if ($req['group_name']): ?>
                                    <p style="
                                        font-size:11px;
                                        color:var(--color-text-light);
                                        display:flex;
                                        align-items:center;
                                        gap:3px;">
                                        <i class="fa-solid fa-users"
                                           style="font-size:10px;">
                                        </i>
                                        <?= htmlspecialchars(
                                            $req['group_name']
                                        ) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Type -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:8px;">
                            <div style="
                                width:32px; height:32px;
                                border-radius:var(--radius-sm);
                                background:var(--color-mint-light);
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid <?= requestTypeIcon(
                                    $req['request_type']
                                ) ?>"
                                   style="
                                       font-size:13px;
                                       color:var(--color-mint-dark);">
                                </i>
                            </div>
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
                        max-width:240px;">
                        <p style="
                            font-size:13px;
                            color:var(--color-text-mid);
                            line-height:1.5;">
                            <?= htmlspecialchars($req['details']) ?>
                        </p>
                        <?php if ($req['subject_name']): ?>
                            <span style="
                                font-size:11px;
                                color:var(--color-text-light);
                                display:flex;
                                align-items:center;
                                gap:4px;
                                margin-top:4px;">
                                <i class="fa-solid fa-book"
                                   style="font-size:10px;">
                                </i>
                                <?= htmlspecialchars($req['subject_name']) ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Date -->
                    <td style="padding:16px 20px;">
                        <p style="
                            font-size:13px;
                            color:var(--color-text-mid);">
                            <?= date('d M Y',
                                strtotime($req['created_at'])
                            ) ?>
                        </p>
                        <p style="
                            font-size:11px;
                            color:var(--color-text-light);">
                            <?= timeAgo($req['created_at']) ?>
                        </p>
                    </td>

                    <!-- Status -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:5px 14px;
                            background:<?= $badge['bg'] ?>;
                            color:<?= $badge['color'] ?>;
                            border:1px solid <?= $badge['border'] ?>;
                            border-radius:20px;
                            font-size:12px;
                            font-weight:600;">
                            <?= $badge['label'] ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td style="
                        padding:16px 20px;
                        text-align:center;">
                        <?php if ($req['status'] === 'pending'): ?>
                            <div style="
                                display:flex;
                                gap:8px;
                                justify-content:center;">

                                <!-- Accept -->
                                <button
                                    onclick="handleRequest(
                                        <?= $req['id'] ?>,
                                        'accepted',
                                        <?= $req['professor_id'] ?>,
                                        '<?= addslashes($req['professor_name']) ?>'
                                    )"
                                    style="
                                        display:flex;
                                        align-items:center;
                                        gap:5px;
                                        padding:7px 14px;
                                        background:#e8f5ee;
                                        color:#3a8a5a;
                                        border:1px solid #b7dfca;
                                        border-radius:var(--radius-md);
                                        font-size:12px;
                                        font-weight:600;
                                        cursor:pointer;
                                        transition:var(--transition);"
                                    onmouseover="
                                        this.style.background='#3a8a5a';
                                        this.style.color='white';"
                                    onmouseout="
                                        this.style.background='#e8f5ee';
                                        this.style.color='#3a8a5a';">
                                    <i class="fa-solid fa-check"></i>
                                    Accept
                                </button>

                                <!-- Reject -->
                                <button
                                    onclick="openRejectModal(
                                        <?= $req['id'] ?>,
                                        <?= $req['professor_id'] ?>,
                                        '<?= addslashes($req['professor_name']) ?>'
                                    )"
                                    style="
                                        display:flex;
                                        align-items:center;
                                        gap:5px;
                                        padding:7px 14px;
                                        background:#fde8e8;
                                        color:#c0392b;
                                        border:1px solid #f5c6c6;
                                        border-radius:var(--radius-md);
                                        font-size:12px;
                                        font-weight:600;
                                        cursor:pointer;
                                        transition:var(--transition);"
                                    onmouseover="
                                        this.style.background='#c0392b';
                                        this.style.color='white';"
                                    onmouseout="
                                        this.style.background='#fde8e8';
                                        this.style.color='#c0392b';">
                                    <i class="fa-solid fa-xmark"></i>
                                    Reject
                                </button>

                            </div>
                        <?php else: ?>
                            <?php if ($req['admin_note']): ?>
                                <p style="
                                    font-size:12px;
                                    color:var(--color-text-light);
                                    font-style:italic;">
                                    "<?= htmlspecialchars(
                                        $req['admin_note']
                                    ) ?>"
                                </p>
                            <?php else: ?>
                                <span style="
                                    font-size:13px;
                                    color:var(--color-border);">
                                    —
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     REJECT MODAL
═══════════════════════════════════════════════ -->
<div id="rejectModal"
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
        max-width:460px;
        margin:20px;
        box-shadow:var(--shadow-lg);
        position:relative;">

        <!-- Close -->
        <button onclick="closeRejectModal()"
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
            margin-bottom:20px;">
            <div style="
                width:42px; height:42px;
                border-radius:var(--radius-md);
                background:#fde8e8;
                display:flex;
                align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-circle-xmark"
                   style="color:#c0392b; font-size:20px;">
                </i>
            </div>
            <div>
                <h3 style="
                    font-size:17px;
                    font-weight:700;
                    color:var(--color-text-dark);">
                    Reject Request
                </h3>
                <p style="
                    font-size:13px;
                    color:var(--color-text-light);"
                   id="rejectModalSubtitle">
                </p>
            </div>
        </div>

        <!-- Note -->
        <div style="margin-bottom:20px;">
            <label style="
                display:block;
                font-size:13px;
                font-weight:600;
                color:var(--color-text-dark);
                margin-bottom:8px;">
                Reason for rejection
                <span style="
                    color:var(--color-text-light);
                    font-weight:400;">
                    (optional)
                </span>
            </label>
            <textarea id="rejectNote"
                      placeholder="Explain why this request is being rejected..."
                      rows="4"
                      style="
                          width:100%;
                          padding:12px 14px;
                          border:1.5px solid var(--color-border);
                          border-radius:var(--radius-md);
                          font-size:14px;
                          color:var(--color-text-dark);
                          background:var(--color-cream);
                          resize:vertical;
                          font-family:inherit;
                          line-height:1.5;">
            </textarea>
        </div>

        <!-- Buttons -->
        <div style="display:flex; gap:12px;">
            <button onclick="closeRejectModal()"
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
            <button onclick="confirmReject()"
                    style="
                        flex:2;
                        height:46px;
                        background:#c0392b;
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
                <i class="fa-solid fa-xmark"></i>
                Confirm Rejection
            </button>
        </div>

    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

// ─── Filter tabs ──────────────────────────────
function filterRequests(status) {
    document.querySelectorAll('.req-tab').forEach(btn => {
        btn.style.background = 'transparent';
        btn.style.color      = 'var(--color-text-mid)';
    });

    const active = document.getElementById('tab-' + status);
    active.style.background = 'var(--color-mint-dark)';
    active.style.color      = 'white';

    document.querySelectorAll('.req-row').forEach(row => {
        row.style.display = (
            status === 'all' ||
            row.dataset.status === status
        ) ? '' : 'none';
    });
}

// ─── Reject modal ─────────────────────────────
let currentRequestId   = null;
let currentProfessorId = null;

function openRejectModal(requestId, professorId, professorName) {
    currentRequestId   = requestId;
    currentProfessorId = professorId;

    document.getElementById('rejectModalSubtitle').textContent =
        'Request from ' + professorName;
    document.getElementById('rejectNote').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.body.style.overflow = '';
}

function confirmReject() {
    const note = document.getElementById('rejectNote').value;
    handleRequest(
        currentRequestId,
        'rejected',
        currentProfessorId,
        '',
        note
    );
    closeRejectModal();
}

// ─── Close on backdrop ────────────────────────
document.getElementById('rejectModal')
    .addEventListener('click', function(e) {
        if (e.target === this) closeRejectModal();
    });

// ─── Handle request ───────────────────────────
async function handleRequest(
    requestId,
    status,
    professorId,
    professorName,
    note = ''
) {
    try {
        const res = await fetch(
            '/TimeTable/api/requests/handle.php',
            {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    request_id:   requestId,
                    status:       status,
                    professor_id: professorId,
                    admin_note:   note
                })
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

<!-- ─── Tab Styles ────────────────────────────── -->
<style>
.req-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-mid);
    cursor: pointer;
    border: none;
    background: transparent;
    transition: var(--transition);
}

.req-tab:hover {
    background: var(--color-sage);
}

.active-tab {
    background: var(--color-mint-dark) !important;
    color: white !important;
}
</style>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>