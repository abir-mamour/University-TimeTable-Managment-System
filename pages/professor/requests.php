<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
$user = currentUser();

$activePage   = 'requests';
$pageTitle    = 'My Requests';
$pageSubtitle = 'Send and track your requests to the administrator.';

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

// ─── Pending requests count ───────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM requests
    WHERE professor_id = ? AND status = 'pending'
");
$stmt->execute([$user['id']]);
$pendingRequests = $stmt->fetchColumn();

// ─── Get all requests ─────────────────────────
$stmt = $pdo->prepare("
    SELECT
        r.*,
        s.name AS subject_name,
        g.group_name
    FROM requests r
    LEFT JOIN subjects     s ON r.subject_id = s.id
    LEFT JOIN groups_table g ON r.group_id   = g.id
    WHERE r.professor_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

// ─── Get subjects for form ────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT s.id, s.name
    FROM subjects s
    JOIN timetable t ON t.subject_id = s.id
    WHERE t.professor_id = ?
");
$stmt->execute([$user['id']]);
$subjects = $stmt->fetchAll();

// ─── Get groups for form ──────────────────────
$stmt = $pdo->prepare("
    SELECT DISTINCT g.id, g.group_name
    FROM groups_table g
    JOIN timetable t ON t.group_id = g.id
    WHERE t.professor_id = ?
");
$stmt->execute([$user['id']]);
$groups = $stmt->fetchAll();

// ─── Helpers ──────────────────────────────────
function statusBadge($status) {
    return match($status) {
        'accepted' => [
            'background:#e8f5ee; color:#3a8a5a; border:1px solid #b7dfca;',
            'Accepted'
        ],
        'rejected' => [
            'background:#fde8e8; color:#c0392b; border:1px solid #f5c6c6;',
            'Rejected'
        ],
        default => [
            'background:#fff3e0; color:#e08a2a; border:1px solid #fdd9a0;',
            'Pending'
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

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Top Row ───────────────────────────────── -->
<div style="display:flex;
            align-items:center;
            justify-content:space-between;
            margin-bottom:24px;">

    <!-- Stats -->
    <div style="display:flex; gap:16px;">

        <div style="
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 20px;
            background:var(--color-white);
            border-radius:var(--radius-md);
            border:1px solid var(--color-border);
            box-shadow:var(--shadow-sm);">
            <div style="
                width:36px; height:36px;
                border-radius:var(--radius-sm);
                background:var(--color-mint-light);
                display:flex; align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-inbox"
                   style="color:var(--color-mint-dark); font-size:15px;">
                </i>
            </div>
            <div>
                <p style="font-size:11px;
                           color:var(--color-text-light);">
                    Total Requests
                </p>
                <p style="font-size:18px;
                           font-weight:700;
                           color:var(--color-text-dark);
                           line-height:1;">
                    <?= count($requests) ?>
                </p>
            </div>
        </div>

        <div style="
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 20px;
            background:var(--color-white);
            border-radius:var(--radius-md);
            border:1px solid var(--color-border);
            box-shadow:var(--shadow-sm);">
            <div style="
                width:36px; height:36px;
                border-radius:var(--radius-sm);
                background:#fff3e0;
                display:flex; align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-clock"
                   style="color:#e08a2a; font-size:15px;">
                </i>
            </div>
            <div>
                <p style="font-size:11px;
                           color:var(--color-text-light);">
                    Pending
                </p>
                <p style="font-size:18px;
                           font-weight:700;
                           color:var(--color-text-dark);
                           line-height:1;">
                    <?= $pendingRequests ?>
                </p>
            </div>
        </div>

        <?php
        $acceptedCount = count(array_filter(
            $requests, fn($r) => $r['status'] === 'accepted'
        ));
        ?>
        <div style="
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 20px;
            background:var(--color-white);
            border-radius:var(--radius-md);
            border:1px solid var(--color-border);
            box-shadow:var(--shadow-sm);">
            <div style="
                width:36px; height:36px;
                border-radius:var(--radius-sm);
                background:#e8f5ee;
                display:flex; align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-circle-check"
                   style="color:#3a8a5a; font-size:15px;">
                </i>
            </div>
            <div>
                <p style="font-size:11px;
                           color:var(--color-text-light);">
                    Accepted
                </p>
                <p style="font-size:18px;
                           font-weight:700;
                           color:var(--color-text-dark);
                           line-height:1;">
                    <?= $acceptedCount ?>
                </p>
            </div>
        </div>

    </div>

    <!-- Send Request Button -->
    <button onclick="openModal()"
            style="
                display:flex;
                align-items:center;
                gap:8px;
                padding:12px 20px;
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
        <i class="fa-solid fa-paper-plane"></i>
        Send Request
    </button>

</div>

<!-- ─── Requests Table ────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($requests)): ?>

        <div style="text-align:center;
                    padding:60px 40px;
                    color:var(--color-text-light);">
            <i class="fa-solid fa-inbox"
               style="font-size:48px;
                      display:block;
                      margin-bottom:16px;
                      opacity:0.3;">
            </i>
            <p style="font-size:15px;
                      font-weight:600;
                      color:var(--color-text-dark);
                      margin-bottom:6px;">
                No requests yet
            </p>
            <p style="font-size:13px;">
                Click "Send Request" to submit your first request.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%;
                      border-collapse:collapse;">
            <thead>
                <tr style="background:var(--color-sage);
                           border-bottom:2px solid var(--color-border);">
                    <th style="padding:14px 20px;
                               text-align:left;
                               font-size:13px;
                               font-weight:600;
                               color:var(--color-text-mid);">
                        Request Type
                    </th>
                    <th style="padding:14px 20px;
                               text-align:left;
                               font-size:13px;
                               font-weight:600;
                               color:var(--color-text-mid);">
                        Details
                    </th>
                    <th style="padding:14px 20px;
                               text-align:left;
                               font-size:13px;
                               font-weight:600;
                               color:var(--color-text-mid);">
                        Date
                    </th>
                    <th style="padding:14px 20px;
                               text-align:left;
                               font-size:13px;
                               font-weight:600;
                               color:var(--color-text-mid);">
                        Status
                    </th>
                    <th style="padding:14px 20px;
                               text-align:left;
                               font-size:13px;
                               font-weight:600;
                               color:var(--color-text-mid);">
                        Admin Note
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $i => $req):
                    [$badgeStyle, $badgeLabel] = statusBadge($req['status']);
                ?>
                <tr style="
                    border-bottom:1px solid var(--color-border);
                    background:<?= $i % 2 === 0
                        ? 'var(--color-white)'
                        : 'var(--color-cream)' ?>;
                    transition:var(--transition);"
                    onmouseover="this.style.background='var(--color-mint-light)'"
                    onmouseout="this.style.background='<?= $i % 2 === 0
                        ? 'var(--color-white)'
                        : 'var(--color-cream)' ?>'">

                    <!-- Request Type -->
                    <td style="padding:16px 20px;">
                        <div style="display:flex;
                                    align-items:center;
                                    gap:10px;">
                            <div style="
                                width:34px; height:34px;
                                border-radius:var(--radius-sm);
                                background:var(--color-mint-light);
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid <?= requestTypeIcon($req['request_type']) ?>"
                                   style="font-size:14px;
                                          color:var(--color-mint-dark);">
                                </i>
                            </div>
                            <span style="font-size:14px;
                                         font-weight:600;
                                         color:var(--color-text-dark);">
                                <?= requestTypeLabel($req['request_type']) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Details -->
                    <td style="padding:16px 20px;
                               max-width:250px;">
                        <p style="font-size:14px;
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
                        <?php if ($req['group_name']): ?>
                            <span style="
                                font-size:11px;
                                color:var(--color-text-light);
                                display:flex;
                                align-items:center;
                                gap:4px;
                                margin-top:2px;">
                                <i class="fa-solid fa-users"
                                   style="font-size:10px;">
                                </i>
                                <?= htmlspecialchars($req['group_name']) ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Date -->
                    <td style="padding:16px 20px;
                               white-space:nowrap;">
                        <span style="font-size:14px;
                                     color:var(--color-text-mid);">
                            <?= date('d M Y',
                                strtotime($req['created_at'])
                            ) ?>
                        </span>
                    </td>

                    <!-- Status -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:5px 14px;
                            border-radius:20px;
                            font-size:13px;
                            font-weight:600;
                            <?= $badgeStyle ?>">
                            <?= $badgeLabel ?>
                        </span>
                    </td>

                    <!-- Admin Note -->
                    <td style="padding:16px 20px;
                               max-width:200px;">
                        <?php if ($req['admin_note']): ?>
                            <p style="font-size:13px;
                                       color:var(--color-text-mid);
                                       line-height:1.4;">
                                <?= htmlspecialchars($req['admin_note']) ?>
                            </p>
                        <?php else: ?>
                            <span style="font-size:13px;
                                         color:var(--color-border);">
                                —
                            </span>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     SEND REQUEST MODAL
═══════════════════════════════════════════════ -->
<div id="requestModal"
     style="display:none;
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
        max-width:520px;
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
        <div style="display:flex;
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
                <i class="fa-solid fa-paper-plane"
                   style="color:var(--color-mint-dark);
                          font-size:18px;">
                </i>
            </div>
            <div>
                <h3 style="font-size:18px;
                           font-weight:700;
                           color:var(--color-text-dark);">
                    Send Request
                </h3>
                <p style="font-size:13px;
                          color:var(--color-text-light);">
                    Submit a request to the administrator
                </p>
            </div>
        </div>

        <!-- Success Message -->
        <div id="successMsg"
             style="display:none;
                    background:#e8f5ee;
                    border:1px solid #b7dfca;
                    border-radius:var(--radius-md);
                    padding:12px 16px;
                    margin-bottom:16px;
                    font-size:14px;
                    color:#3a8a5a;
                    display:none;
                    align-items:center;
                    gap:8px;">
            <i class="fa-solid fa-circle-check"></i>
            Request sent successfully!
        </div>

        <!-- Error Message -->
        <div id="errorMsg"
             style="display:none;
                    background:#fde8e8;
                    border:1px solid #f5c6c6;
                    border-radius:var(--radius-md);
                    padding:12px 16px;
                    margin-bottom:16px;
                    font-size:14px;
                    color:#c0392b;
                    align-items:center;
                    gap:8px;">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span id="errorMsgText"></span>
        </div>

        <!-- Form -->
        <form id="requestForm">

            <!-- Request Type -->
            <div style="margin-bottom:18px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Request Type
                </label>
                <select name="request_type" id="requestType"
                        style="
                            width:100%;
                            height:46px;
                            padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px;
                            color:var(--color-text-dark);
                            background:var(--color-cream);
                            cursor:pointer;"
                        required>
                    <option value="">Select request type</option>
                    <option value="new_class">New Class</option>
                    <option value="schedule_change">Change Time</option>
                    <option value="overload">Overload</option>
                    <option value="room_change">Room Change</option>
                </select>
            </div>

            <!-- Subject -->
            <div style="margin-bottom:18px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Subject
                    <span style="color:var(--color-text-light);
                                 font-weight:400;">
                        (optional)
                    </span>
                </label>
                <select name="subject_id"
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
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['id'] ?>">
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Group -->
            <div style="margin-bottom:18px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Group
                    <span style="color:var(--color-text-light);
                                 font-weight:400;">
                        (optional)
                    </span>
                </label>
                <select name="group_id"
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
                    <option value="">Select group</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>">
                            <?= htmlspecialchars($g['group_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Details -->
            <div style="margin-bottom:24px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Details
                </label>
                <textarea name="details"
                          placeholder="Describe your request..."
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
                              line-height:1.5;"
                          required></textarea>
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
                    <i class="fa-solid fa-paper-plane"></i>
                    <span id="submitText">Send Request</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

// ─── Open / Close Modal ───────────────────────
function openModal() {
    document.getElementById('requestModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('requestModal').style.display = 'none';
    document.body.style.overflow = '';
    document.getElementById('requestForm').reset();
    document.getElementById('successMsg').style.display = 'none';
    document.getElementById('errorMsg').style.display   = 'none';
}

// ─── Close on backdrop click ──────────────────
document.getElementById('requestModal')
    .addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

// ─── Submit Form ──────────────────────────────
document.getElementById('requestForm')
    .addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn  = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');

        // Loading state
        submitBtn.disabled    = true;
        submitText.innerHTML  =
            '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';

        const formData = new FormData(this);
        const data = {
            request_type: formData.get('request_type'),
            subject_id:   formData.get('subject_id')  || null,
            group_id:     formData.get('group_id')    || null,
            details:      formData.get('details'),
        };

        try {
            const res = await fetch(
                '/TimeTable/api/requests/send.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(data)
                }
            );

            const result = await res.json();

            if (result.success) {
                document.getElementById('successMsg')
                    .style.display = 'flex';
                // Reload after 1.5 seconds
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                document.getElementById('errorMsgText')
                    .textContent = result.message;
                document.getElementById('errorMsg')
                    .style.display = 'flex';
                submitBtn.disabled   = false;
                submitText.innerHTML =
                    '<i class="fa-solid fa-paper-plane"></i> Send Request';
            }

        } catch(err) {
            document.getElementById('errorMsgText')
                .textContent = 'Connection error. Please try again.';
            document.getElementById('errorMsg')
                .style.display = 'flex';
            submitBtn.disabled   = false;
            submitText.innerHTML =
                '<i class="fa-solid fa-paper-plane"></i> Send Request';
        }
    });
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>