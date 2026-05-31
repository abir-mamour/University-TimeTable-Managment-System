<?php
// ─── Setup ────────────────────────────────────
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'rooms';
$pageTitle    = 'Rooms Management';
$pageSubtitle = 'Manage classrooms and laboratories.';

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

// ─── Get all rooms ────────────────────────────
$stmt = $pdo->query("
    SELECT
        r.*,
        COUNT(t.id) AS session_count
    FROM rooms r
    LEFT JOIN timetable t ON t.room_id = r.id
        AND t.is_active = 1
    GROUP BY r.id
    ORDER BY r.room_name
");
$rooms = $stmt->fetchAll();

// ─── Load header ──────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Stats Row ─────────────────────────────── -->
<div class="stats-row"
     style="grid-template-columns:repeat(3,1fr);
            margin-bottom:24px;">

    <!-- Total Rooms -->
    <div class="stat-card">
        <div class="stat-icon mint">
            <i class="fa-solid fa-door-open"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Total Rooms</p>
            <div class="stat-value"><?= count($rooms) ?></div>
            <p class="stat-sub">All rooms</p>
        </div>
    </div>

    <!-- Lecture Rooms -->
    <div class="stat-card">
        <div class="stat-icon sage">
            <i class="fa-solid fa-chalkboard"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Lecture Rooms</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $rooms,
                    fn($r) => $r['type'] === 'lecture'
                )) ?>
            </div>
            <p class="stat-sub">Lecture halls</p>
        </div>
    </div>

    <!-- Labs -->
    <div class="stat-card">
        <div class="stat-icon rose">
            <i class="fa-solid fa-flask"></i>
        </div>
        <div class="stat-body">
            <p class="stat-label">Labs</p>
            <div class="stat-value">
                <?= count(array_filter(
                    $rooms,
                    fn($r) => $r['type'] === 'lab'
                )) ?>
            </div>
            <p class="stat-sub">Laboratory rooms</p>
        </div>
    </div>

</div>

<!-- ─── Top Bar ───────────────────────────────── -->
<div style="
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;">

    <!-- Search -->
    <div style="position:relative; width:280px;">
        <i class="fa-solid fa-magnifying-glass"
           style="
               position:absolute;
               left:12px;
               top:50%;
               transform:translateY(-50%);
               color:var(--color-text-light);
               font-size:13px;">
        </i>
        <input type="text"
               id="searchInput"
               placeholder="Search rooms..."
               oninput="searchRooms()"
               style="
                   width:100%;
                   height:42px;
                   padding:0 14px 0 36px;
                   border:1.5px solid var(--color-border);
                   border-radius:var(--radius-md);
                   font-size:13px;
                   background:var(--color-white);
                   color:var(--color-text-dark);">
    </div>

    <!-- Add Room Button -->
    <button onclick="openModal()"
            style="
                display:flex;
                align-items:center;
                gap:8px;
                padding:10px 20px;
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
        <i class="fa-solid fa-plus"></i>
        Add Room
    </button>

</div>

<!-- ─── Rooms Table ───────────────────────────── -->
<div class="card" style="padding:0; overflow:hidden;">

    <?php if (empty($rooms)): ?>

        <div style="
            text-align:center;
            padding:60px 40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-door-open"
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
                No rooms yet
            </p>
            <p style="font-size:13px;">
                Click "Add Room" to add your first room.
            </p>
        </div>

    <?php else: ?>

        <table style="width:100%; border-collapse:collapse;"
               id="roomsTable">
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
                        Room Name
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Type
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Capacity
                    </th>
                    <th style="
                        padding:14px 20px;
                        text-align:left;
                        font-size:12px;
                        font-weight:600;
                        color:var(--color-text-mid);">
                        Sessions / Week
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
            <tbody id="roomsBody">
                <?php foreach ($rooms as $i => $room):
                    $typeInfo = match($room['type']) {
                        'lecture' => [
                            'label' => 'Lecture',
                            'icon'  => 'fa-chalkboard',
                            'bg'    => 'var(--color-mint-light)',
                            'color' => 'var(--color-mint-dark)'
                        ],
                        'lab' => [
                            'label' => 'Lab',
                            'icon'  => 'fa-flask',
                            'bg'    => 'var(--color-rose-light)',
                            'color' => 'var(--color-rose-dark)'
                        ],
                        'seminar' => [
                            'label' => 'Seminar',
                            'icon'  => 'fa-users',
                            'bg'    => 'var(--color-sage)',
                            'color' => '#5a8a6a'
                        ],
                        default => [
                            'label' => 'Other',
                            'icon'  => 'fa-door-open',
                            'bg'    => 'var(--color-cream)',
                            'color' => 'var(--color-text-mid)'
                        ],
                    };
                ?>
                <tr class="room-row"
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

                    <!-- Room Name -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:12px;">
                            <div style="
                                width:38px; height:38px;
                                border-radius:var(--radius-md);
                                background:<?= $typeInfo['bg'] ?>;
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                flex-shrink:0;">
                                <i class="fa-solid <?= $typeInfo['icon'] ?>"
                                   style="
                                       font-size:16px;
                                       color:<?= $typeInfo['color'] ?>;">
                                </i>
                            </div>
                            <span style="
                                font-size:14px;
                                font-weight:600;
                                color:var(--color-text-dark);">
                                <?= htmlspecialchars($room['room_name']) ?>
                            </span>
                        </div>
                    </td>

                    <!-- Type -->
                    <td style="padding:16px 20px;">
                        <span style="
                            padding:4px 12px;
                            background:<?= $typeInfo['bg'] ?>;
                            color:<?= $typeInfo['color'] ?>;
                            border-radius:20px;
                            font-size:12px;
                            font-weight:600;">
                            <?= $typeInfo['label'] ?>
                        </span>
                    </td>

                    <!-- Capacity -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:6px;">
                            <i class="fa-solid fa-users"
                               style="
                                   font-size:12px;
                                   color:var(--color-text-light);">
                            </i>
                            <span style="
                                font-size:14px;
                                color:var(--color-text-dark);
                                font-weight:500;">
                                <?= $room['capacity'] ?>
                                <span style="
                                    font-size:11px;
                                    color:var(--color-text-light);
                                    font-weight:400;">
                                    seats
                                </span>
                            </span>
                        </div>
                    </td>

                    <!-- Sessions -->
                    <td style="padding:16px 20px;">
                        <div style="
                            display:flex;
                            align-items:center;
                            gap:6px;">
                            <i class="fa-solid fa-calendar-check"
                               style="
                                   font-size:12px;
                                   color:var(--color-text-light);">
                            </i>
                            <span style="
                                font-size:14px;
                                color:var(--color-text-dark);
                                font-weight:500;">
                                <?= $room['session_count'] ?>
                                <span style="
                                    font-size:11px;
                                    color:var(--color-text-light);
                                    font-weight:400;">
                                    sessions
                                </span>
                            </span>
                        </div>
                    </td>

                    <!-- Status -->
                    <td style="padding:16px 20px;">
                        <?php if ($room['is_active']): ?>
                            <span style="
                                padding:4px 12px;
                                background:#e8f5ee;
                                color:#3a8a5a;
                                border:1px solid #b7dfca;
                                border-radius:20px;
                                font-size:12px;
                                font-weight:600;
                                display:inline-flex;
                                align-items:center;
                                gap:5px;">
                                <i class="fa-solid fa-circle"
                                   style="font-size:7px;">
                                </i>
                                Active
                            </span>
                        <?php else: ?>
                            <span style="
                                padding:4px 12px;
                                background:#fde8e8;
                                color:#c0392b;
                                border:1px solid #f5c6c6;
                                border-radius:20px;
                                font-size:12px;
                                font-weight:600;
                                display:inline-flex;
                                align-items:center;
                                gap:5px;">
                                <i class="fa-solid fa-circle"
                                   style="font-size:7px;">
                                </i>
                                Inactive
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td style="
                        padding:16px 20px;
                        text-align:center;">
                        <div style="
                            display:flex;
                            gap:8px;
                            justify-content:center;">

                            <!-- Edit -->
                            <button onclick="openEditModal(
                                <?= $room['id'] ?>,
                                '<?= addslashes($room['room_name']) ?>',
                                '<?= $room['type'] ?>',
                                <?= $room['capacity'] ?>,
                                <?= $room['is_active'] ?>
                            )"
                                style="
                                    width:34px; height:34px;
                                    border-radius:var(--radius-sm);
                                    background:var(--color-mint-light);
                                    border:1px solid var(--color-mint-dark);
                                    color:var(--color-mint-dark);
                                    cursor:pointer;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    transition:var(--transition);"
                                onmouseover="
                                    this.style.background='var(--color-mint-dark)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background='var(--color-mint-light)';
                                    this.style.color='var(--color-mint-dark)';">
                                <i class="fa-solid fa-pen"
                                   style="font-size:13px;">
                                </i>
                            </button>

                            <!-- Delete -->
                            <button onclick="deleteRoom(
                                <?= $room['id'] ?>,
                                '<?= addslashes($room['room_name']) ?>'
                            )"
                                style="
                                    width:34px; height:34px;
                                    border-radius:var(--radius-sm);
                                    background:var(--color-rose-light);
                                    border:1px solid var(--color-rose);
                                    color:var(--color-rose-dark);
                                    cursor:pointer;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    transition:var(--transition);"
                                onmouseover="
                                    this.style.background='var(--color-rose)';
                                    this.style.color='white';"
                                onmouseout="
                                    this.style.background='var(--color-rose-light)';
                                    this.style.color='var(--color-rose-dark)';">
                                <i class="fa-solid fa-trash"
                                   style="font-size:13px;">
                                </i>
                            </button>

                        </div>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════
     ADD / EDIT MODAL
═══════════════════════════════════════════════ -->
<div id="roomModal"
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
        <div style="
            display:flex;
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
                <i class="fa-solid fa-door-open"
                   id="modalIcon"
                   style="
                       color:var(--color-mint-dark);
                       font-size:18px;">
                </i>
            </div>
            <div>
                <h3 id="modalTitle"
                    style="
                        font-size:17px;
                        font-weight:700;
                        color:var(--color-text-dark);">
                    Add Room
                </h3>
                <p style="
                    font-size:13px;
                    color:var(--color-text-light);">
                    Fill in the room details below
                </p>
            </div>
        </div>

        <!-- Alert -->
        <div id="modalAlert"
             style="
                 display:none;
                 align-items:center;
                 gap:8px;
                 padding:10px 14px;
                 border-radius:var(--radius-md);
                 font-size:13px;
                 margin-bottom:16px;">
        </div>

        <!-- Form -->
        <form id="roomForm">
            <input type="hidden" id="roomId" value="">

            <!-- Room Name -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Room Name
                </label>
                <input type="text"
                       id="roomName"
                       placeholder="e.g. Room A101"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           color:var(--color-text-dark);
                           background:var(--color-cream);"
                       required>
            </div>

            <!-- Type -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Room Type
                </label>
                <select id="roomType"
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
                    <option value="lecture">Lecture</option>
                    <option value="lab">Lab</option>
                    <option value="seminar">Seminar</option>
                </select>
            </div>

            <!-- Capacity -->
            <div style="margin-bottom:16px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Capacity
                </label>
                <input type="number"
                       id="roomCapacity"
                       placeholder="e.g. 30"
                       min="1"
                       style="
                           width:100%;
                           height:46px;
                           padding:0 14px;
                           border:1.5px solid var(--color-border);
                           border-radius:var(--radius-md);
                           font-size:14px;
                           color:var(--color-text-dark);
                           background:var(--color-cream);"
                       required>
            </div>

            <!-- Status -->
            <div style="margin-bottom:24px;">
                <label style="
                    display:block;
                    font-size:13px;
                    font-weight:600;
                    color:var(--color-text-dark);
                    margin-bottom:8px;">
                    Status
                </label>
                <select id="roomStatus"
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
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
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
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span id="submitText">Save Room</span>
                </button>
            </div>

        </form>
    </div>
</div>

<!-- ─── Scripts ──────────────────────────────── -->
<script>

// ─── Search ───────────────────────────────────
function searchRooms() {
    const query = document.getElementById('searchInput')
        .value.toLowerCase();
    document.querySelectorAll('.room-row').forEach(row => {
        row.style.display =
            row.textContent.toLowerCase().includes(query)
                ? '' : 'none';
    });
}

// ─── Open Add Modal ───────────────────────────
function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Room';
    document.getElementById('roomId').value           = '';
    document.getElementById('roomName').value         = '';
    document.getElementById('roomType').value         = 'lecture';
    document.getElementById('roomCapacity').value     = '';
    document.getElementById('roomStatus').value       = '1';
    document.getElementById('submitText').textContent = 'Save Room';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('roomModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Open Edit Modal ──────────────────────────
function openEditModal(id, name, type, capacity, status) {
    document.getElementById('modalTitle').textContent  = 'Edit Room';
    document.getElementById('roomId').value            = id;
    document.getElementById('roomName').value          = name;
    document.getElementById('roomType').value          = type;
    document.getElementById('roomCapacity').value      = capacity;
    document.getElementById('roomStatus').value        = status;
    document.getElementById('submitText').textContent  = 'Update Room';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('roomModal').style.display  = 'flex';
    document.body.style.overflow = 'hidden';
}

// ─── Close Modal ──────────────────────────────
function closeModal() {
    document.getElementById('roomModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ─── Close on backdrop ────────────────────────
document.getElementById('roomModal')
    .addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

// ─── Show alert in modal ──────────────────────
function showModalAlert(success, message) {
    const box = document.getElementById('modalAlert');
    box.style.display    = 'flex';
    box.style.background = success ? '#e8f5ee' : '#fde8e8';
    box.style.color      = success ? '#3a8a5a'  : '#c0392b';
    box.innerHTML = `
        <i class="fa-solid ${success
            ? 'fa-circle-check'
            : 'fa-circle-exclamation'}">
        </i>
        ${message}`;
}

// ─── Submit Form ──────────────────────────────
document.getElementById('roomForm')
    .addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn  = document.getElementById('submitBtn');
        const text = document.getElementById('submitText');

        btn.disabled      = true;
        text.textContent  = 'Saving...';

        const data = {
            id:        document.getElementById('roomId').value,
            room_name: document.getElementById('roomName').value.trim(),
            type:      document.getElementById('roomType').value,
            capacity:  document.getElementById('roomCapacity').value,
            is_active: document.getElementById('roomStatus').value,
        };

        try {
            const res = await fetch(
                '/TimeTable/api/admin/rooms.php',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(data)
                }
            );

            const result = await res.json();

            if (result.success) {
                showModalAlert(true, result.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showModalAlert(false, result.message);
                btn.disabled     = false;
                text.textContent = data.id ? 'Update Room' : 'Save Room';
            }

        } catch(err) {
            showModalAlert(false, 'Connection error. Please try again.');
            btn.disabled     = false;
            text.textContent = data.id ? 'Update Room' : 'Save Room';
        }
    });

// ─── Delete Room ──────────────────────────────
async function deleteRoom(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"?`)) return;

    try {
        const res = await fetch(
            '/TimeTable/api/admin/rooms.php',
            {
                method:  'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ id })
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

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>