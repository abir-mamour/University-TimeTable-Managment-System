<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('student');
$user = currentUser();

$activePage   = 'group_switch';
$pageTitle    = 'Switch Group';
$pageSubtitle = 'Request to switch to a different group.';

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

// ─── Full profile with group ───────────────────
$stmt = $pdo->prepare("
    SELECT
        u.*,
        g.id   AS group_id,
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

// ─── Groups at same level only ────────────────
$stmt = $pdo->prepare("
    SELECT
        g.id,
        g.group_name,
        g.level,
        g.capacity,
        COUNT(sg.student_id) AS student_count
    FROM groups_table g
    LEFT JOIN student_groups sg ON sg.group_id = g.id
    WHERE g.level = ?
    GROUP BY g.id
    ORDER BY g.group_name
");
$stmt->execute([$profile['level'] ?? '']);
$allGroups = $stmt->fetchAll();

// ─── Check pending switch request ─────────────
$stmt = $pdo->prepare("
    SELECT * FROM requests
    WHERE professor_id = ?
      AND request_type = 'group_switch'
      AND status       = 'pending'
    LIMIT 1
");
$stmt->execute([$user['id']]);
$pendingSwitch = $stmt->fetch();

// ─── Previous requests history ────────────────
$stmt = $pdo->prepare("
    SELECT r.*, g.group_name AS target_group
    FROM requests r
    LEFT JOIN groups_table g ON r.group_id = g.id
    WHERE r.professor_id  = ?
      AND r.request_type  = 'group_switch'
    ORDER BY r.created_at DESC
");
$stmt->execute([$user['id']]);
$switchHistory = $stmt->fetchAll();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Current Group Banner ─────────────────── -->
<div style="
    display:flex;
    align-items:center;
    gap:16px;
    padding:20px 24px;
    background:var(--color-white);
    border:1px solid var(--color-border);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);
    margin-bottom:24px;">

    <div style="
        width:52px; height:52px;
        border-radius:50%;
        background:var(--color-mint-light);
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;">
        <i class="fa-solid fa-users"
           style="font-size:22px; color:var(--color-mint-dark);"></i>
    </div>

    <div style="flex:1;">
        <p style="font-size:12px; font-weight:600;
                   color:var(--color-text-light);
                   text-transform:uppercase;
                   letter-spacing:0.05em; margin-bottom:4px;">
            Your Current Group
        </p>
        <p style="font-size:18px; font-weight:700;
                   color:var(--color-text-dark);">
            <?= $profile['group_name']
                ? htmlspecialchars($profile['group_name'])
                : 'Not assigned to any group' ?>
        </p>
    </div>

    <?php if ($profile['level']): ?>
        <span style="
            padding:6px 16px;
            background:var(--color-mint-light);
            border:1px solid var(--color-mint-dark);
            border-radius:20px;
            font-size:13px; font-weight:700;
            color:var(--color-mint-dark);">
            <?= htmlspecialchars($profile['level']) ?>
        </span>
    <?php endif; ?>

</div>

<!-- ─── Main Grid ─────────────────────────────── -->
<div style="display:grid;
            grid-template-columns:1fr 1fr;
            gap:24px;
            align-items:start;">

    <!-- ─── Left: Request Form ─────────────────── -->
    <div class="card" style="padding:0; overflow:hidden;">

        <!-- Header -->
        <div style="
            padding:16px 22px;
            border-bottom:1px solid var(--color-border);
            background:var(--color-cream);">
            <h3 style="font-size:15px; font-weight:700;
                       color:var(--color-text-dark);
                       display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-right-left"
                   style="color:var(--color-mint-dark);"></i>
                New Switch Request
            </h3>
        </div>

        <div style="padding:24px;">

            <?php if ($pendingSwitch): ?>
                <!-- Pending request warning -->
                <div style="
                    display:flex; align-items:flex-start; gap:14px;
                    padding:16px 20px;
                    background:#fff3e0;
                    border:1px solid #fdd9a0;
                    border-radius:var(--radius-md);">
                    <i class="fa-solid fa-clock"
                       style="font-size:22px; color:#e08a2a;
                              flex-shrink:0; margin-top:2px;"></i>
                    <div>
                        <p style="font-size:14px; font-weight:700;
                                   color:#b07020; margin-bottom:6px;">
                            Request Pending Review
                        </p>
                        <p style="font-size:13px; color:#c08030;
                                   line-height:1.5;">
                            You already have a pending group switch request.
                            Please wait for the administrator to review it
                            before submitting a new one.
                        </p>
                    </div>
                </div>

            <?php elseif (!$profile['group_id']): ?>
                <!-- Not in a group -->
                <div style="
                    display:flex; align-items:flex-start; gap:14px;
                    padding:16px 20px;
                    background:var(--color-sage);
                    border:1px solid var(--color-border);
                    border-radius:var(--radius-md);">
                    <i class="fa-solid fa-circle-info"
                       style="font-size:22px; color:var(--color-text-light);
                              flex-shrink:0; margin-top:2px;"></i>
                    <p style="font-size:13px; color:var(--color-text-mid);
                               line-height:1.5;">
                        You are not currently assigned to any group.
                        Please contact your administrator to get assigned first.
                    </p>
                </div>

            <?php else: ?>
                <!-- Form -->
                <p style="font-size:13px; color:var(--color-text-mid);
                           line-height:1.6; margin-bottom:20px;">
                    Select your desired group and provide a clear reason.
                    The administrator will review your request and notify you.
                </p>

                <!-- Alert -->
                <div id="switchAlert"
                     style="display:none; align-items:center; gap:10px;
                            padding:12px 16px; border-radius:var(--radius-md);
                            font-size:14px; margin-bottom:16px;">
                </div>

                <form id="switchForm">

                    <!-- Target Group -->
                    <div style="margin-bottom:16px;">
                        <label style="
                            display:block; font-size:13px;
                            font-weight:600; color:var(--color-text-dark);
                            margin-bottom:8px;">
                            Switch to Group
                        </label>
                        <select id="targetGroup"
                                class="form-input"
                                style="height:46px; padding:0 14px;"
                                onchange="updateCapacityInfo()"
                                required>
                            <option value="">— Select target group —</option>
                            <?php foreach ($allGroups as $g): ?>
                                <?php if ($g['id'] == $profile['group_id']) continue; ?>
                                <option value="<?= $g['id'] ?>"
                                        data-capacity="<?= $g['capacity'] ?>"
                                        data-count="<?= $g['student_count'] ?>">
                                    <?= htmlspecialchars($g['group_name']) ?>
                                    (<?= htmlspecialchars($g['level']) ?>) —
                                    <?= $g['student_count'] ?>/<?= $g['capacity'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Capacity Info -->
                        <div id="capacityInfo"
                             style="display:none; margin-top:8px;
                                    padding:8px 12px;
                                    border-radius:var(--radius-sm);
                                    font-size:12px; font-weight:600;">
                        </div>
                    </div>

                    <!-- Reason -->
                    <div style="margin-bottom:20px;">
                        <label style="
                            display:block; font-size:13px;
                            font-weight:600; color:var(--color-text-dark);
                            margin-bottom:8px;">
                            Reason for Switch
                        </label>
                        <textarea id="switchReason"
                                  placeholder="Explain why you want to switch groups..."
                                  rows="4"
                                  style="
                                      width:100%; padding:12px 14px;
                                      border:1.5px solid var(--color-border);
                                      border-radius:var(--radius-md);
                                      font-size:14px; color:var(--color-text-dark);
                                      background:var(--color-cream);
                                      resize:vertical; font-family:inherit;
                                      line-height:1.5;"
                                  required></textarea>
                    </div>

                    <!-- Submit -->
                    <button type="submit" id="switchBtn"
                            style="
                                display:flex; align-items:center; gap:8px;
                                padding:12px 24px;
                                background:var(--color-mint-dark);
                                color:white; border:none;
                                border-radius:var(--radius-md);
                                font-size:14px; font-weight:600;
                                cursor:pointer; transition:var(--transition);"
                            onmouseover="this.style.background='#6BB8A0'"
                            onmouseout="this.style.background='var(--color-mint-dark)'">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span id="switchBtnText">Send Request</span>
                    </button>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <!-- ─── Right: Request History ──────────────── -->
    <div class="card" style="padding:0; overflow:hidden;">

        <!-- Header -->
        <div style="
            padding:16px 22px;
            border-bottom:1px solid var(--color-border);
            background:var(--color-cream);
            display:flex; align-items:center;
            justify-content:space-between;">
            <h3 style="font-size:15px; font-weight:700;
                       color:var(--color-text-dark);
                       display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-clock-rotate-left"
                   style="color:var(--color-mint-dark);"></i>
                Request History
            </h3>
            <span style="
                padding:3px 10px;
                background:var(--color-sage);
                border-radius:20px; font-size:12px;
                font-weight:600; color:var(--color-text-mid);">
                <?= count($switchHistory) ?> total
            </span>
        </div>

        <?php if (empty($switchHistory)): ?>
            <div style="
                text-align:center; padding:48px 24px;
                color:var(--color-text-light);">
                <i class="fa-solid fa-inbox"
                   style="font-size:40px; display:block;
                          margin-bottom:14px; opacity:0.3;"></i>
                <p style="font-size:14px; font-weight:600;
                           color:var(--color-text-mid); margin-bottom:4px;">
                    No requests yet
                </p>
                <p style="font-size:13px;">
                    Your switch requests will appear here.
                </p>
            </div>

        <?php else: ?>
            <div style="max-height:460px; overflow-y:auto;">
                <?php foreach ($switchHistory as $i => $req):
                    $statusStyle = match($req['status']) {
                        'accepted' => [
                            'bg'    => '#e8f5ee',
                            'color' => '#3a8a5a',
                            'border'=> '#b7dfca',
                            'icon'  => 'fa-circle-check',
                            'label' => 'Accepted',
                        ],
                        'rejected' => [
                            'bg'    => '#fde8e8',
                            'color' => '#c0392b',
                            'border'=> '#f5c6c6',
                            'icon'  => 'fa-circle-xmark',
                            'label' => 'Rejected',
                        ],
                        default => [
                            'bg'    => '#fff3e0',
                            'color' => '#e08a2a',
                            'border'=> '#fdd9a0',
                            'icon'  => 'fa-clock',
                            'label' => 'Pending',
                        ],
                    };
                ?>
                <div style="
                    padding:16px 22px;
                    border-bottom:1px solid var(--color-border);
                    background:<?= $i % 2 === 0
                        ? 'var(--color-white)'
                        : 'var(--color-cream)' ?>;">

                    <!-- Top row -->
                    <div style="display:flex; align-items:center;
                                justify-content:space-between;
                                margin-bottom:8px;">

                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-right-left"
                               style="font-size:13px;
                                      color:var(--color-mint-dark);"></i>
                            <span style="font-size:13px; font-weight:600;
                                         color:var(--color-text-dark);">
                                → <?= htmlspecialchars($req['target_group'] ?? 'Unknown Group') ?>
                            </span>
                        </div>

                        <span style="
                            padding:3px 10px;
                            background:<?= $statusStyle['bg'] ?>;
                            border:1px solid <?= $statusStyle['border'] ?>;
                            border-radius:20px; font-size:11px;
                            font-weight:700; color:<?= $statusStyle['color'] ?>;
                            display:inline-flex; align-items:center; gap:5px;">
                            <i class="fa-solid <?= $statusStyle['icon'] ?>"
                               style="font-size:9px;"></i>
                            <?= $statusStyle['label'] ?>
                        </span>

                    </div>

                    <!-- Details -->
                    <p style="font-size:12px; color:var(--color-text-mid);
                               line-height:1.4; margin-bottom:6px;">
                        <?= htmlspecialchars($req['details']) ?>
                    </p>

                    <!-- Admin note -->
                    <?php if ($req['admin_note']): ?>
                        <p style="font-size:12px; color:var(--color-text-light);
                                   font-style:italic; margin-bottom:6px;">
                            <i class="fa-solid fa-comment"
                               style="font-size:10px;"></i>
                            Admin: <?= htmlspecialchars($req['admin_note']) ?>
                        </p>
                    <?php endif; ?>

                    <!-- Date -->
                    <p style="font-size:11px; color:var(--color-text-light);">
                        <i class="fa-solid fa-calendar"
                           style="font-size:10px;"></i>
                        <?= date('d M Y · H:i', strtotime($req['created_at'])) ?>
                    </p>

                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</div>

<!-- ─── Script ───────────────────────────────── -->
<script>
// ─── Capacity indicator ───────────────────────
function updateCapacityInfo() {
    const sel      = document.getElementById('targetGroup');
    const info     = document.getElementById('capacityInfo');
    const opt      = sel.options[sel.selectedIndex];

    if (!sel.value) { info.style.display = 'none'; return; }

    const capacity = parseInt(opt.dataset.capacity);
    const count    = parseInt(opt.dataset.count);
    const pct      = Math.round(count / capacity * 100);
    const isFull   = count >= capacity;

    info.style.display    = 'block';
    info.style.background = isFull ? '#fde8e8' : '#e8f5ee';
    info.style.color      = isFull ? '#c0392b' : '#3a8a5a';
    info.style.border     = isFull
        ? '1px solid #f5c6c6'
        : '1px solid #b7dfca';

    info.innerHTML = isFull
        ? `<i class="fa-solid fa-circle-xmark"></i>
           &nbsp;This group is full (${count}/${capacity})`
        : `<i class="fa-solid fa-circle-check"></i>
           &nbsp;${count}/${capacity} students (${100 - pct}% available)`;
}

// ─── Submit form ──────────────────────────────
document.getElementById('switchForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn     = document.getElementById('switchBtn');
    const btnText = document.getElementById('switchBtnText');
    const groupId = document.getElementById('targetGroup').value;
    const reason  = document.getElementById('switchReason').value.trim();

    if (!groupId) { showAlert(false, 'Please select a target group.'); return; }
    if (!reason)  { showAlert(false, 'Please provide a reason.'); return; }

    btn.disabled        = true;
    btnText.textContent = 'Sending...';

    try {
        const res = await fetch('/TimeTable/api/requests/send.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_type: 'group_switch',
                group_id:     parseInt(groupId),
                details:      reason,
            }),
        });

        const result = await res.json();

        if (result.success) {
            showAlert(true, 'Request sent successfully! The administrator will review it shortly.');
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showAlert(false, result.message || 'Failed to send request.');
            btn.disabled        = false;
            btnText.textContent = 'Send Request';
        }

    } catch(err) {
        showAlert(false, 'Connection error. Please try again.');
        btn.disabled        = false;
        btnText.textContent = 'Send Request';
    }
});

function showAlert(success, message) {
    const box         = document.getElementById('switchAlert');
    box.style.display = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.border     = `1px solid ${success ? '#b7dfca' : '#f5c6c6'}`;
    box.style.color      = success ? '#3a8a5a' : '#c0392b';
    box.innerHTML = `<i class="fa-solid ${
        success ? 'fa-circle-check' : 'fa-circle-exclamation'
    }"></i>&nbsp;${message}`;
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>