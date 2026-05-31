<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'availability';
$pageTitle    = 'Professor Availability';
$pageSubtitle = 'Manage time slot availability for each professor.';

// ─── Unread notifications ─────────────────────
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

// ─── Pending requests ─────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── All active professors ─────────────────────
$professors = $pdo->query("
    SELECT u.id, u.name, d.name AS department
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE u.role = 'professor' AND u.is_active = 1
    ORDER BY u.name
")->fetchAll();

// ─── Selected professor ───────────────────────
$selectedProfId = (int)($_GET['prof_id'] ?? 0);
$selectedProf   = null;
$availabilities = [];
$checkedSlots   = [];

if ($selectedProfId) {
    foreach ($professors as $p) {
        if ((int)$p['id'] === $selectedProfId) {
            $selectedProf = $p;
            break;
        }
    }

    if ($selectedProf) {
        $stmt = $pdo->prepare("
            SELECT * FROM availability
            WHERE professor_id = ?
            ORDER BY FIELD(day,
                'Saturday','Sunday','Monday',
                'Tuesday','Wednesday','Thursday'
            ), time_start
        ");
        $stmt->execute([$selectedProfId]);
        $availabilities = $stmt->fetchAll();

        foreach ($availabilities as $a) {
            $key = $a['day'] . '_' . substr($a['time_start'], 0, 5);
            $checkedSlots[$key] = true;
        }
    }
}

$days = [
    'Saturday','Sunday','Monday',
    'Tuesday','Wednesday','Thursday'
];

$slots = [
    '08:00' => ['label' => '08:00 - 09:30', 'end' => '09:30'],
    '10:00' => ['label' => '10:00 - 11:30', 'end' => '11:30'],
    '13:00' => ['label' => '13:00 - 14:30', 'end' => '14:30'],
    '14:30' => ['label' => '14:30 - 16:00', 'end' => '16:00'],
    '16:00' => ['label' => '16:00 - 17:30', 'end' => '17:30'],
];

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Professor Selector ────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div style="
        display:flex;
        align-items:center;
        gap:14px;
        flex-wrap:wrap;">

        <div style="
            width:42px; height:42px;
            border-radius:var(--radius-md);
            background:var(--color-mint-light);
            display:flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;">
            <i class="fa-solid fa-chalkboard-user"
               style="color:var(--color-mint-dark); font-size:18px;"></i>
        </div>

        <div style="flex:1; min-width:200px;">
            <p style="font-size:12px; font-weight:600;
                       color:var(--color-text-light);
                       margin-bottom:4px;">
                SELECT PROFESSOR
            </p>
            <form method="GET" style="display:flex; gap:10px; align-items:center;">
                <select name="prof_id"
                        style="
                            flex:1;
                            height:42px;
                            padding:0 14px;
                            border:1.5px solid var(--color-border);
                            border-radius:var(--radius-md);
                            font-size:14px;
                            color:var(--color-text-dark);
                            background:var(--color-cream);
                            cursor:pointer;"
                        onchange="this.form.submit()">
                    <option value="">— Choose a professor —</option>
                    <?php foreach ($professors as $p): ?>
                        <option value="<?= $p['id'] ?>"
                            <?= $selectedProfId === (int)$p['id']
                                ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                            <?= $p['department']
                                ? '(' . htmlspecialchars($p['department']) . ')'
                                : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selectedProf): ?>
            <div style="
                padding:8px 16px;
                background:var(--color-mint-light);
                border:1px solid var(--color-mint-dark);
                border-radius:var(--radius-md);
                font-size:13px;
                font-weight:600;
                color:var(--color-mint-dark);
                display:flex;
                align-items:center;
                gap:6px;">
                <i class="fa-solid fa-circle-check"></i>
                <?= count($availabilities) ?> slot(s) currently set
            </div>
        <?php endif; ?>

    </div>
</div>

<?php if (!$selectedProfId): ?>

<!-- ─── Empty State ───────────────────────────── -->
<div class="card" style="text-align:center; padding:60px 40px;">
    <i class="fa-solid fa-calendar-check"
       style="font-size:52px; color:var(--color-border);
              display:block; margin-bottom:16px;">
    </i>
    <p style="font-size:15px; font-weight:600;
               color:var(--color-text-dark); margin-bottom:6px;">
        Select a professor to manage their availability
    </p>
    <p style="font-size:13px; color:var(--color-text-light);">
        Use the dropdown above to choose a professor.
    </p>
</div>

<?php else: ?>

<!-- ─── Alert Box ─────────────────────────────── -->
<div id="alertBox"
     style="display:none;
            align-items:center;
            gap:10px;
            padding:12px 16px;
            border-radius:var(--radius-md);
            font-size:14px;
            margin-bottom:20px;">
    <i class="fa-solid fa-circle-check" id="alertIcon"></i>
    <span id="alertText"></span>
</div>

<!-- ─── Availability Grid ────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <!-- Card Header -->
    <div style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:20px 24px;
        border-bottom:1px solid var(--color-border);
        flex-wrap:wrap;
        gap:12px;">

        <div style="display:flex; align-items:center; gap:12px;">
            <div style="
                width:40px; height:40px;
                border-radius:var(--radius-md);
                background:var(--color-mint-light);
                display:flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-calendar-check"
                   style="color:var(--color-mint-dark); font-size:18px;"></i>
            </div>
            <div>
                <h3 style="font-size:16px; font-weight:700;
                           color:var(--color-text-dark);">
                    <?= htmlspecialchars($selectedProf['name']) ?>
                </h3>
                <p style="font-size:12px; color:var(--color-text-light);">
                    Click slots to toggle · Save to notify professor
                </p>
            </div>
        </div>

        <!-- Legend -->
        <div style="display:flex; gap:16px;">
            <div style="display:flex; align-items:center; gap:6px;
                        font-size:12px; color:var(--color-text-mid);">
                <span style="width:14px; height:14px; border-radius:4px;
                             background:var(--color-mint-dark);
                             display:inline-block;"></span>
                Available
            </div>
            <div style="display:flex; align-items:center; gap:6px;
                        font-size:12px; color:var(--color-text-mid);">
                <span style="width:14px; height:14px; border-radius:4px;
                             background:var(--color-sage);
                             border:1px solid var(--color-border);
                             display:inline-block;"></span>
                Not Available
            </div>
        </div>
    </div>

    <!-- Grid -->
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:600px;">
            <thead>
                <tr style="background:var(--color-sage);">
                    <th style="
                        padding:14px 16px; text-align:left;
                        font-size:13px; font-weight:600;
                        color:var(--color-text-mid); width:140px;
                        border-bottom:2px solid var(--color-border);">
                        Time Slot
                    </th>
                    <?php foreach ($days as $day): ?>
                        <th style="
                            padding:14px 12px; text-align:center;
                            font-size:13px; font-weight:600;
                            color:var(--color-text-mid);
                            border-bottom:2px solid var(--color-border);">
                            <?= $day ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $timeKey => $slotInfo): ?>
                <tr style="border-bottom:1px solid var(--color-border);">

                    <td style="
                        padding:12px 16px;
                        font-size:12px; font-weight:600;
                        color:var(--color-text-mid);
                        background:var(--color-cream);
                        white-space:nowrap;">
                        <?= $slotInfo['label'] ?>
                    </td>

                    <?php foreach ($days as $day):
                        $key       = $day . '_' . $timeKey;
                        $isChecked = isset($checkedSlots[$key]);
                    ?>
                        <td style="padding:10px 12px; text-align:center;">
                            <div class="avail-slot <?= $isChecked ? 'avail-on' : 'avail-off' ?>"
                                 data-day="<?= $day ?>"
                                 data-start="<?= $timeKey ?>:00"
                                 data-end="<?= $slotInfo['end'] ?>:00"
                                 data-checked="<?= $isChecked ? '1' : '0' ?>"
                                 onclick="toggleSlot(this)"
                                 style="
                                    width:100%; min-height:48px;
                                    border-radius:var(--radius-md);
                                    cursor:pointer;
                                    display:flex; align-items:center; justify-content:center;
                                    transition:all 0.2s ease;
                                    background:<?= $isChecked
                                        ? 'var(--color-mint-dark)'
                                        : 'var(--color-sage)' ?>;
                                    border:2px solid <?= $isChecked
                                        ? 'var(--color-mint-dark)'
                                        : 'var(--color-border)' ?>;">
                                <?php if ($isChecked): ?>
                                    <i class="fa-solid fa-check"
                                       style="color:white; font-size:14px;"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-plus"
                                       style="color:var(--color-text-light); font-size:12px;"></i>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endforeach; ?>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer -->
    <div style="
        padding:16px 24px;
        border-top:1px solid var(--color-border);
        display:flex;
        align-items:center;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:12px;">

        <p style="font-size:13px; color:var(--color-text-light);">
            <i class="fa-solid fa-bell"
               style="color:var(--color-mint-dark);"></i>
            &nbsp;Professor will be notified on save ·
            <span id="selectedCount"><?= count($availabilities) ?></span>
            slot(s) selected
        </p>

        <div style="display:flex; gap:10px;">
            <button onclick="clearAll()"
                    style="
                        padding:10px 20px;
                        background:var(--color-rose-light);
                        color:var(--color-rose-dark);
                        border:1px solid var(--color-rose);
                        border-radius:var(--radius-md);
                        font-size:13px; font-weight:600;
                        cursor:pointer; transition:var(--transition);"
                    onmouseover="this.style.background='var(--color-rose)';
                                 this.style.color='white';"
                    onmouseout="this.style.background='var(--color-rose-light)';
                                this.style.color='var(--color-rose-dark)';">
                <i class="fa-solid fa-trash"></i>
                Clear All
            </button>

            <button onclick="saveAvailability()"
                    id="saveBtn"
                    style="
                        padding:10px 24px;
                        background:var(--color-mint-dark);
                        color:white; border:none;
                        border-radius:var(--radius-md);
                        font-size:13px; font-weight:600;
                        cursor:pointer;
                        display:flex; align-items:center; gap:8px;
                        transition:var(--transition);"
                    onmouseover="this.style.background='#6BB8A0'"
                    onmouseout="this.style.background='var(--color-mint-dark)'">
                <i class="fa-solid fa-floppy-disk"></i>
                <span id="saveBtnText">Save &amp; Notify Professor</span>
            </button>
        </div>
    </div>

</div>

<script>
const PROF_ID = <?= $selectedProfId ?>;

function toggleSlot(el) {
    const isOn = el.dataset.checked === '1';
    if (isOn) {
        el.dataset.checked   = '0';
        el.style.background  = 'var(--color-sage)';
        el.style.borderColor = 'var(--color-border)';
        el.innerHTML = `<i class="fa-solid fa-plus"
            style="color:var(--color-text-light); font-size:12px;"></i>`;
    } else {
        el.dataset.checked   = '1';
        el.style.background  = 'var(--color-mint-dark)';
        el.style.borderColor = 'var(--color-mint-dark)';
        el.innerHTML = `<i class="fa-solid fa-check"
            style="color:white; font-size:14px;"></i>`;
    }
    updateCount();
}

function clearAll() {
    document.querySelectorAll('.avail-slot').forEach(el => {
        el.dataset.checked   = '0';
        el.style.background  = 'var(--color-sage)';
        el.style.borderColor = 'var(--color-border)';
        el.innerHTML = `<i class="fa-solid fa-plus"
            style="color:var(--color-text-light); font-size:12px;"></i>`;
    });
    updateCount();
}

function updateCount() {
    const count = document.querySelectorAll('.avail-slot[data-checked="1"]').length;
    document.getElementById('selectedCount').textContent = count;
}

function showAlert(success, message) {
    const box  = document.getElementById('alertBox');
    const icon = document.getElementById('alertIcon');
    const text = document.getElementById('alertText');

    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.border     = success ? '1px solid #b7dfca' : '1px solid #f5c6c6';
    box.style.color      = success ? '#3a8a5a' : '#c0392b';
    icon.className       = success
        ? 'fa-solid fa-circle-check'
        : 'fa-solid fa-circle-exclamation';
    text.textContent     = message;
    box.style.display    = 'flex';

    setTimeout(() => { box.style.display = 'none'; }, 4000);
}

async function saveAvailability() {
    const btn = document.getElementById('saveBtn');
    const txt = document.getElementById('saveBtnText');

    btn.disabled     = true;
    txt.textContent  = 'Saving...';

    const slots = [];
    document.querySelectorAll('.avail-slot[data-checked="1"]').forEach(el => {
        slots.push({
            day:        el.dataset.day,
            time_start: el.dataset.start,
            time_end:   el.dataset.end,
        });
    });

    try {
        const res = await fetch('/TimeTable/api/admin/availability.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ professor_id: PROF_ID, slots })
        });

        const result = await res.json();

        if (result.success) {
            showAlert(true,
                result.message + ' (' + result.count + ' slot(s) saved)'
            );
        } else {
            showAlert(false, result.message || 'Failed to save.');
        }

    } catch(err) {
        showAlert(false, 'Connection error. Please try again.');
    }

    btn.disabled    = false;
    txt.textContent = 'Save & Notify Professor';
}
</script>

<?php endif; ?>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
