<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('professor');
$user = currentUser();

$activePage   = 'availability';
$pageTitle    = 'Set Availability';
$pageSubtitle = 'Set your available time slots for scheduling.';

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

// ─── Get current availability ─────────────────
$stmt = $pdo->prepare("
    SELECT * FROM availability
    WHERE professor_id = ?
    ORDER BY FIELD(day,
        'Saturday','Sunday','Monday',
        'Tuesday','Wednesday','Thursday'
    ), time_start
");
$stmt->execute([$user['id']]);
$availabilities = $stmt->fetchAll();

// ─── Group by day ─────────────────────────────
$availByDay = [];
foreach ($availabilities as $a) {
    $availByDay[$a['day']][] = $a;
}

$days  = [
    'Saturday','Sunday','Monday',
    'Tuesday','Wednesday','Thursday'
];

$slots = [
    '08:00' => '08:00 - 09:30',
    '10:00' => '10:00 - 11:30',
    '13:00' => '13:00 - 14:30',
    '14:30' => '14:30 - 16:00',
    '16:00' => '16:00 - 17:30',
];

// ─── Build checked slots ──────────────────────
$checkedSlots = [];
foreach ($availabilities as $a) {
    $key = $a['day'] . '_' . substr($a['time_start'], 0, 5);
    $checkedSlots[$key] = true;
}

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Info Banner ───────────────────────────── -->
<div style="
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px 18px;
    background:var(--color-mint-light);
    border:1px solid var(--color-mint-dark);
    border-radius:var(--radius-md);
    margin-bottom:24px;">
    <i class="fa-solid fa-circle-info"
       style="color:var(--color-mint-dark);
              font-size:18px;
              flex-shrink:0;">
    </i>
    <p style="font-size:13px;
               color:var(--color-text-mid);
               line-height:1.5;">
        Select the time slots when you are
        <strong>available</strong> to teach.
        The admin will use this information
        when scheduling your classes.
    </p>
</div>

<!-- ─── Success / Error Alert ────────────────── -->
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
        border-bottom:1px solid var(--color-border);">
        <div style="display:flex;
                    align-items:center;
                    gap:12px;">
            <div style="
                width:40px; height:40px;
                border-radius:var(--radius-md);
                background:var(--color-mint-light);
                display:flex;
                align-items:center;
                justify-content:center;">
                <i class="fa-solid fa-calendar-check"
                   style="color:var(--color-mint-dark);
                          font-size:18px;">
                </i>
            </div>
            <div>
                <h3 style="font-size:16px;
                           font-weight:700;
                           color:var(--color-text-dark);">
                    Weekly Availability
                </h3>
                <p style="font-size:12px;
                          color:var(--color-text-light);">
                    Click slots to toggle availability
                </p>
            </div>
        </div>

        <!-- Legend -->
        <div style="display:flex; gap:16px;">
            <div style="display:flex;
                        align-items:center;
                        gap:6px;
                        font-size:12px;
                        color:var(--color-text-mid);">
                <span style="
                    width:14px; height:14px;
                    border-radius:4px;
                    background:var(--color-mint-dark);
                    display:inline-block;">
                </span>
                Available
            </div>
            <div style="display:flex;
                        align-items:center;
                        gap:6px;
                        font-size:12px;
                        color:var(--color-text-mid);">
                <span style="
                    width:14px; height:14px;
                    border-radius:4px;
                    background:var(--color-sage);
                    border:1px solid var(--color-border);
                    display:inline-block;">
                </span>
                Not Available
            </div>
        </div>
    </div>

    <!-- Grid -->
    <div style="overflow-x:auto;">
        <table style="width:100%;
                      border-collapse:collapse;
                      min-width:600px;">
            <thead>
                <tr style="background:var(--color-sage);">
                    <th style="
                        padding:14px 16px;
                        text-align:left;
                        font-size:13px;
                        font-weight:600;
                        color:var(--color-text-mid);
                        width:140px;
                        border-bottom:2px solid var(--color-border);">
                        Time Slot
                    </th>
                    <?php foreach ($days as $day): ?>
                        <th style="
                            padding:14px 12px;
                            text-align:center;
                            font-size:13px;
                            font-weight:600;
                            color:var(--color-text-mid);
                            border-bottom:2px solid var(--color-border);">
                            <?= $day ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slots as $timeKey => $slotLabel): ?>
                <tr style="border-bottom:1px solid var(--color-border);">

                    <!-- Time label -->
                    <td style="
                        padding:12px 16px;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);
                        background:var(--color-cream);
                        white-space:nowrap;">
                        <?= $slotLabel ?>
                    </td>

                    <?php foreach ($days as $day):
                        $key       = $day . '_' . $timeKey;
                        $isChecked = isset($checkedSlots[$key]);
                        $slotEnd   = array_keys($slots);
                        $idx       = array_search($timeKey, array_keys($slots));
                        $timeEnd   = array_values($slots);
                        // Extract end time
                        $endTime   = explode(' - ', $slotLabel)[1];
                    ?>
                        <td style="
                            padding:10px 12px;
                            text-align:center;">
                            <div class="avail-slot <?= $isChecked ? 'avail-on' : 'avail-off' ?>"
                                 data-day="<?= $day ?>"
                                 data-start="<?= $timeKey ?>:00"
                                 data-end="<?= $endTime ?>:00"
                                 data-checked="<?= $isChecked ? '1' : '0' ?>"
                                 onclick="toggleSlot(this)"
                                 style="
                                    width:100%;
                                    min-height:48px;
                                    border-radius:var(--radius-md);
                                    cursor:pointer;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    transition:all 0.2s ease;
                                    background:<?= $isChecked
                                        ? 'var(--color-mint-dark)'
                                        : 'var(--color-sage)' ?>;
                                    border:2px solid <?= $isChecked
                                        ? 'var(--color-mint-dark)'
                                        : 'var(--color-border)' ?>;">
                                <?php if ($isChecked): ?>
                                    <i class="fa-solid fa-check"
                                       style="color:white; font-size:14px;">
                                    </i>
                                <?php else: ?>
                                    <i class="fa-solid fa-plus"
                                       style="color:var(--color-text-light);
                                              font-size:12px;">
                                    </i>
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
        justify-content:space-between;">

        <p style="font-size:13px;
                   color:var(--color-text-light);">
            <i class="fa-solid fa-circle-info"
               style="color:var(--color-mint-dark);">
            </i>
            &nbsp;
            <span id="selectedCount">
                <?= count($availabilities) ?>
            </span>
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
                        font-size:13px;
                        font-weight:600;
                        cursor:pointer;
                        transition:var(--transition);"
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
                        color:white;
                        border:none;
                        border-radius:var(--radius-md);
                        font-size:13px;
                        font-weight:600;
                        cursor:pointer;
                        display:flex;
                        align-items:center;
                        gap:8px;
                        transition:var(--transition);"
                    onmouseover="this.style.background='#6BB8A0'"
                    onmouseout="this.style.background='var(--color-mint-dark)'">
                <i class="fa-solid fa-floppy-disk"></i>
                <span id="saveBtnText">Save Availability</span>
            </button>
        </div>
    </div>

</div>

<!-- ─── Script ───────────────────────────────── -->
<script>

// ─── Toggle slot ──────────────────────────────
function toggleSlot(el) {
    const isOn = el.dataset.checked === '1';

    if (isOn) {
        // Turn OFF
        el.dataset.checked    = '0';
        el.style.background   = 'var(--color-sage)';
        el.style.borderColor  = 'var(--color-border)';
        el.innerHTML = `
            <i class="fa-solid fa-plus"
               style="color:var(--color-text-light);
                      font-size:12px;">
            </i>`;
    } else {
        // Turn ON
        el.dataset.checked    = '1';
        el.style.background   = 'var(--color-mint-dark)';
        el.style.borderColor  = 'var(--color-mint-dark)';
        el.innerHTML = `
            <i class="fa-solid fa-check"
               style="color:white;
                      font-size:14px;">
            </i>`;
    }

    updateCount();
}

// ─── Clear all slots ──────────────────────────
function clearAll() {
    document.querySelectorAll('.avail-slot').forEach(el => {
        el.dataset.checked   = '0';
        el.style.background  = 'var(--color-sage)';
        el.style.borderColor = 'var(--color-border)';
        el.innerHTML = `
            <i class="fa-solid fa-plus"
               style="color:var(--color-text-light);
                      font-size:12px;">
            </i>`;
    });
    updateCount();
}

// ─── Update selected count ────────────────────
function updateCount() {
    const count = document.querySelectorAll(
        '.avail-slot[data-checked="1"]'
    ).length;
    document.getElementById('selectedCount').textContent = count;
}

// ─── Show alert ───────────────────────────────
function showAlert(success, message) {
    const box  = document.getElementById('alertBox');
    const icon = document.getElementById('alertIcon');
    const text = document.getElementById('alertText');

    if (success) {
        box.style.background  = '#e8f5ee';
        box.style.border      = '1px solid #b7dfca';
        box.style.color       = '#3a8a5a';
        icon.className        = 'fa-solid fa-circle-check';
    } else {
        box.style.background  = '#fde8e8';
        box.style.border      = '1px solid #f5c6c6';
        box.style.color       = '#c0392b';
        icon.className        = 'fa-solid fa-circle-exclamation';
    }

    text.textContent     = message;
    box.style.display    = 'flex';

    // Auto hide after 3 seconds
    setTimeout(() => {
        box.style.display = 'none';
    }, 3000);
}

// ─── Save availability ────────────────────────
async function saveAvailability() {
    const saveBtn  = document.getElementById('saveBtn');
    const saveTxt  = document.getElementById('saveBtnText');

    // Loading
    saveBtn.disabled  = true;
    saveTxt.textContent = 'Saving...';

    // Collect all ON slots
    const slots = [];
    document.querySelectorAll('.avail-slot[data-checked="1"]')
        .forEach(el => {
            slots.push({
                day:        el.dataset.day,
                time_start: el.dataset.start,
                time_end:   el.dataset.end,
            });
        });

    try {
        const res = await fetch(
            '/TimeTable/api/professor/availability.php',
            {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ slots })
            }
        );

        const result = await res.json();

        if (result.success) {
            showAlert(true, 'Availability saved successfully!');
        } else {
            showAlert(false, result.message || 'Failed to save.');
        }

    } catch(err) {
        showAlert(false, 'Connection error. Please try again.');
    }

    // Reset button
    saveBtn.disabled    = false;
    saveTxt.textContent = 'Save Availability';
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>