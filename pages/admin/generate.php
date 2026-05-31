<?php
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

requireRole('admin');
$user = currentUser();

$activePage   = 'timetable';
$pageTitle    = 'Generate Timetable';
$pageSubtitle = 'Automatic CSP-based timetable generation.';

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user['id']]);
$unreadCount = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM requests WHERE status = 'pending'
");
$pendingRequests = $stmt->fetchColumn();

// ─── Load sessions for preview ────────────────
try {
    $sessionList = $pdo->query("
        SELECT
            sa.id,
            sa.session_type,
            u.name          AS professor_name,
            s.name          AS subject_name,
            s.code          AS subject_code,
            g.group_name,
            g.level         AS group_level
        FROM session_assignments sa
        JOIN users        u ON u.id = sa.professor_id
        JOIN subjects     s ON s.id = sa.subject_id
        JOIN groups_table g ON g.id = sa.group_id
        ORDER BY u.name, s.name, g.group_name
    ")->fetchAll();
} catch (\Exception $e) {
    $sessionList = [];
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
?>

<!-- ─── Info Banner ───────────────────────────── -->
<div style="
    display:flex;
    align-items:flex-start;
    gap:14px;
    padding:16px 20px;
    background:var(--color-mint-light);
    border:1px solid var(--color-mint-dark);
    border-radius:var(--radius-md);
    margin-bottom:24px;">
    <i class="fa-solid fa-circle-info"
       style="
           color:var(--color-mint-dark);
           font-size:20px;
           flex-shrink:0;
           margin-top:2px;">
    </i>
    <div>
        <p style="
            font-size:14px;
            font-weight:600;
            color:var(--color-text-dark);
            margin-bottom:4px;">
            CSP Automatic Timetable Generator
        </p>
        <p style="font-size:13px; color:var(--color-text-mid);">
            This system uses a
            <strong>Constraint Satisfaction Problem (CSP) solver</strong>
            with backtracking to generate a conflict-free timetable.
            All hard constraints (room conflicts, professor overlaps,
            group clashes, lunch breaks, availability) are guaranteed
            to be respected.
        </p>
    </div>
</div>

<!-- ─── Constraints Overview ─────────────────── -->
<div style="
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    margin-bottom:24px;">

    <!-- Hard Constraints -->
    <div class="card">
        <h3 style="
            font-size:14px;
            font-weight:700;
            color:var(--color-text-dark);
            margin-bottom:14px;
            display:flex;
            align-items:center;
            gap:8px;">
            <i class="fa-solid fa-lock"
               style="color:var(--color-rose-dark);">
            </i>
            Hard Constraints
            <span style="
                font-size:11px;
                font-weight:400;
                color:var(--color-text-light);">
                (always enforced)
            </span>
        </h3>
        <div style="
            display:flex;
            flex-direction:column;
            gap:8px;">
            <?php
            $hcs = [
                ['HC1', 'No room double-booking'],
                ['HC2', 'No professor double-booking'],
                ['HC3', 'No group time overlap'],
                ['HC4', 'Respect professor availability'],
                ['HC5', 'Max 4 sessions/day per professor'],
                ['HC6', 'Max 4 sessions/day per group'],
                ['HC7', 'No sessions during lunch (configurable)'],
                ['HC8', 'No sessions on Saturday (configurable)'],
            ];
            foreach ($hcs as $hc): ?>
                <div style="
                    display:flex;
                    align-items:center;
                    gap:10px;
                    padding:8px 12px;
                    background:var(--color-cream);
                    border-radius:var(--radius-sm);">
                    <span style="
                        font-size:11px;
                        font-weight:700;
                        padding:2px 7px;
                        background:#fde8e8;
                        color:#c0392b;
                        border-radius:4px;
                        white-space:nowrap;">
                        <?= $hc[0] ?>
                    </span>
                    <span style="
                        font-size:13px;
                        color:var(--color-text-mid);">
                        <?= $hc[1] ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Soft Constraints -->
    <div class="card">
        <h3 style="
            font-size:14px;
            font-weight:700;
            color:var(--color-text-dark);
            margin-bottom:14px;
            display:flex;
            align-items:center;
            gap:8px;">
            <i class="fa-solid fa-star"
               style="color:#e08a2a;">
            </i>
            Soft Constraints
            <span style="
                font-size:11px;
                font-weight:400;
                color:var(--color-text-light);">
                (score-based)
            </span>
        </h3>
        <div style="
            display:flex;
            flex-direction:column;
            gap:8px;">
            <?php
            $scs = [
                ['SC1', 'Lecture before TD/TP',           '+15'],
                ['SC2', 'Spread sessions across week',    '+15'],
                ['SC3', 'Morning slots for lectures',     '+10'],
                ['SC4', 'Room type matches session',      '+5'],
                ['SC5', 'Best-fit room capacity',         '+5'],
                ['SC6', 'Professor preferred time slots', '+20'],
            ];
            foreach ($scs as $sc): ?>
                <div style="
                    display:flex;
                    align-items:center;
                    gap:10px;
                    padding:8px 12px;
                    background:var(--color-cream);
                    border-radius:var(--radius-sm);">
                    <span style="
                        font-size:11px;
                        font-weight:700;
                        padding:2px 7px;
                        background:var(--color-mint-light);
                        color:var(--color-mint-dark);
                        border-radius:4px;
                        white-space:nowrap;">
                        <?= $sc[0] ?>
                    </span>
                    <span style="
                        font-size:13px;
                        color:var(--color-text-mid);
                        flex:1;">
                        <?= $sc[1] ?>
                    </span>
                    <span style="
                        font-size:12px;
                        font-weight:700;
                        color:#3a8a5a;">
                        <?= $sc[2] ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- ─── Sessions Panel ───────────────────────── -->
<div class="card" style="padding:0; overflow:hidden; margin-bottom:24px;">

    <!-- Header -->
    <div style="
        padding:16px 20px;
        background:var(--color-cream);
        border-bottom:1px solid var(--color-border);
        display:flex;
        align-items:center;
        justify-content:space-between;">
        <h3 style="
            font-size:14px; font-weight:700;
            color:var(--color-text-dark);
            display:flex; align-items:center; gap:8px;">
            <i class="fa-solid fa-list-check" style="color:var(--color-mint-dark);"></i>
            Sessions to Schedule
            <span style="
                background:var(--color-mint-light);
                color:var(--color-mint-dark);
                font-size:11px; font-weight:700;
                padding:2px 8px;
                border-radius:12px;">
                <?= count($sessionList) ?>
            </span>
        </h3>
        <a href="/TimeTable/pages/admin/sessions.php"
           style="
               display:flex; align-items:center; gap:6px;
               padding:6px 14px;
               background:var(--color-mint-light);
               color:var(--color-mint-dark);
               border-radius:var(--radius-sm);
               font-size:13px; font-weight:600;
               text-decoration:none;
               transition:var(--transition);"
           onmouseover="this.style.background='var(--color-mint-dark)'; this.style.color='white';"
           onmouseout="this.style.background='var(--color-mint-light)'; this.style.color='var(--color-mint-dark)';">
            <i class="fa-solid fa-pen-to-square" style="font-size:12px;"></i>
            Manage Sessions
        </a>
    </div>

    <?php if (empty($sessionList)): ?>
        <div style="
            text-align:center; padding:40px;
            color:var(--color-text-light);">
            <i class="fa-solid fa-triangle-exclamation"
               style="font-size:32px; color:#e08a2a; display:block; margin-bottom:12px;"></i>
            <p style="font-size:14px; font-weight:600; color:var(--color-text-dark); margin-bottom:6px;">
                No sessions defined
            </p>
            <p style="font-size:13px; margin-bottom:16px;">
                Add sessions before generating the timetable.
            </p>
            <a href="/TimeTable/pages/admin/sessions.php"
               style="
                   display:inline-flex; align-items:center; gap:6px;
                   padding:8px 20px;
                   background:var(--color-mint-dark); color:white;
                   border-radius:var(--radius-md);
                   font-size:13px; font-weight:600;
                   text-decoration:none;">
                <i class="fa-solid fa-plus"></i>
                Add Sessions
            </a>
        </div>
    <?php else: ?>
        <div style="max-height:280px; overflow-y:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--color-sage); position:sticky; top:0;">
                        <th style="padding:10px 16px; text-align:left; font-size:11px; font-weight:600; color:var(--color-text-mid);">#</th>
                        <th style="padding:10px 16px; text-align:left; font-size:11px; font-weight:600; color:var(--color-text-mid);">Professor</th>
                        <th style="padding:10px 16px; text-align:left; font-size:11px; font-weight:600; color:var(--color-text-mid);">Subject</th>
                        <th style="padding:10px 16px; text-align:left; font-size:11px; font-weight:600; color:var(--color-text-mid);">Group</th>
                        <th style="padding:10px 16px; text-align:left; font-size:11px; font-weight:600; color:var(--color-text-mid);">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessionList as $i => $s):
                        $typeInfo = match($s['session_type']) {
                            'lecture' => ['label' => 'Lecture', 'bg' => 'var(--color-mint-light)', 'color' => 'var(--color-mint-dark)'],
                            'lab'     => ['label' => 'Lab',     'bg' => 'var(--color-rose-light)', 'color' => 'var(--color-rose-dark)'],
                            'seminar' => ['label' => 'Seminar', 'bg' => 'var(--color-sage)',       'color' => '#5a8a6a'],
                            default   => ['label' => ucfirst($s['session_type']), 'bg' => 'var(--color-cream)', 'color' => 'var(--color-text-mid)'],
                        };
                    ?>
                        <tr style="
                            border-bottom:1px solid var(--color-border);
                            background:<?= $i % 2 === 0 ? 'var(--color-white)' : 'var(--color-cream)' ?>;">
                            <td style="padding:10px 16px; font-size:12px; color:var(--color-text-light);"><?= $i + 1 ?></td>
                            <td style="padding:10px 16px; font-size:13px; font-weight:500; color:var(--color-text-dark);">
                                <?= htmlspecialchars($s['professor_name']) ?>
                            </td>
                            <td style="padding:10px 16px; font-size:13px; color:var(--color-text-dark);">
                                <?= htmlspecialchars($s['subject_name']) ?>
                                <span style="font-size:11px; color:var(--color-text-light); margin-left:4px;">
                                    <?= htmlspecialchars($s['subject_code']) ?>
                                </span>
                            </td>
                            <td style="padding:10px 16px; font-size:13px; color:var(--color-text-dark);">
                                <?= htmlspecialchars($s['group_name']) ?>
                            </td>
                            <td style="padding:10px 16px;">
                                <span style="
                                    padding:3px 10px;
                                    background:<?= $typeInfo['bg'] ?>;
                                    color:<?= $typeInfo['color'] ?>;
                                    border-radius:12px;
                                    font-size:11px; font-weight:600;">
                                    <?= $typeInfo['label'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<!-- ─── Generate Card ────────────────────────── -->
<div class="card" style="text-align:center; padding:40px;">

    <!-- Icon -->
    <div style="
        width:80px; height:80px;
        border-radius:50%;
        background:var(--color-mint-light);
        display:flex;
        align-items:center;
        justify-content:center;
        margin:0 auto 20px;">
        <i class="fa-solid fa-calendar-plus"
           style="
               font-size:34px;
               color:var(--color-mint-dark);">
        </i>
    </div>

    <h2 style="
        font-size:20px;
        font-weight:700;
        color:var(--color-text-dark);
        margin-bottom:8px;">
        Ready to Generate
    </h2>
    <p style="
        font-size:14px;
        color:var(--color-text-light);
        margin-bottom:28px;
        max-width:400px;
        margin-left:auto;
        margin-right:auto;">
        The system will automatically assign all sessions
        to time slots while respecting all constraints.
    </p>

    <!-- Result Box -->
    <div id="resultBox" style="display:none;
                                margin-bottom:24px;">
    </div>

    <!-- Progress -->
    <div id="progressBox"
         style="display:none; margin-bottom:24px;">
        <div style="
            background:var(--color-sage);
            border-radius:var(--radius-md);
            height:8px;
            overflow:hidden;
            margin-bottom:12px;">
            <div id="progressBar"
                 style="
                     height:100%;
                     background:var(--color-mint-dark);
                     border-radius:var(--radius-md);
                     width:0%;
                     transition:width 0.3s ease;">
            </div>
        </div>
        <p id="progressText"
           style="
               font-size:13px;
               color:var(--color-text-mid);">
            Initializing...
        </p>
    </div>

    <!-- Generate Button -->
    <button id="generateBtn"
            onclick="startGeneration()"
            <?= empty($sessionList) ? 'disabled title="Add sessions first"' : '' ?>
            style="
                display:inline-flex;
                align-items:center;
                gap:10px;
                padding:14px 36px;
                background:var(--color-mint-dark);
                color:white;
                border:none;
                border-radius:var(--radius-md);
                font-size:15px;
                font-weight:600;
                cursor:pointer;
                box-shadow:0 4px 16px rgba(142,203,182,0.50);
                transition:var(--transition);"
            onmouseover="this.style.background='#6BB8A0'"
            onmouseout="this.style.background='var(--color-mint-dark)'">
        <i class="fa-solid fa-wand-magic-sparkles"></i>
        Generate Timetable
    </button>

    <!-- View Timetable Link (hidden until success) -->
    <div id="viewLink" style="display:none; margin-top:16px;">
        <a href="/TimeTable/pages/admin/timetable.php"
           style="
               font-size:14px;
               font-weight:600;
               color:var(--color-mint-dark);
               text-decoration:none;
               display:inline-flex;
               align-items:center;
               gap:6px;">
            View Generated Timetable
            <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>

</div>

<!-- ─── Logs Panel ────────────────────────────── -->
<div id="logsPanel"
     style="display:none; margin-top:20px;">
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="
            padding:14px 20px;
            background:var(--color-cream);
            border-bottom:1px solid var(--color-border);
            display:flex;
            align-items:center;
            justify-content:space-between;">
            <h3 style="
                font-size:14px;
                font-weight:700;
                color:var(--color-text-dark);
                display:flex;
                align-items:center;
                gap:8px;">
                <i class="fa-solid fa-terminal"
                   style="color:var(--color-mint-dark);">
                </i>
                Generation Logs
            </h3>
            <button onclick="
                document.getElementById('logsContent')
                    .style.display =
                document.getElementById('logsContent')
                    .style.display === 'none'
                    ? 'block' : 'none'"
                    style="
                        padding:4px 12px;
                        background:var(--color-sage);
                        border:none;
                        border-radius:var(--radius-sm);
                        font-size:12px;
                        cursor:pointer;">
                Toggle
            </button>
        </div>
        <div id="logsContent"
             style="
                 padding:16px 20px;
                 max-height:300px;
                 overflow-y:auto;
                 font-family:monospace;
                 font-size:12px;
                 line-height:1.6;
                 background:#1a2332;
                 color:#c0e1d2;">
        </div>
    </div>
</div>

<!-- ─── Script ───────────────────────────────── -->
<script>
let progressInterval;

async function startGeneration() {
    const btn        = document.getElementById('generateBtn');
    const progressBox= document.getElementById('progressBox');
    const progressBar= document.getElementById('progressBar');
    const progressTxt= document.getElementById('progressText');
    const resultBox  = document.getElementById('resultBox');
    const logsPanel  = document.getElementById('logsPanel');
    const viewLink   = document.getElementById('viewLink');

    // Reset UI
    btn.disabled            = true;
    btn.innerHTML           =
        '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
    progressBox.style.display = 'block';
    resultBox.style.display   = 'none';
    viewLink.style.display    = 'none';

    // Animate progress bar
    let progress = 0;
    const steps  = [
        'Loading database data...',
        'Generating domains...',
        'Applying MRV heuristic...',
        'Running CSP solver...',
        'Checking hard constraints...',
        'Scoring soft constraints...',
        'Backtracking search...',
        'Saving timetable...',
        'Notifying users...',
    ];
    let stepIndex = 0;

    progressInterval = setInterval(() => {
        if (progress < 90) {
            progress += Math.random() * 8;
            progress  = Math.min(progress, 90);
            progressBar.style.width = progress + '%';
            if (stepIndex < steps.length - 1) {
                progressTxt.textContent = steps[stepIndex++];
            }
        }
    }, 600);

    try {
        const res = await fetch(
            '/TimeTable/api/timetable/generate.php',
            {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ generate: true })
            }
        );

        const result = await res.json();

        clearInterval(progressInterval);
        progressBar.style.width = '100%';
        progressTxt.textContent = 'Done!';

        setTimeout(() => {
            progressBox.style.display = 'none';

            if (result.success) {
                resultBox.innerHTML = `
                    <div style="
                        padding:20px;
                        background:#e8f5ee;
                        border:1px solid #b7dfca;
                        border-radius:var(--radius-md);
                        text-align:center;">
                        <i class="fa-solid fa-circle-check"
                           style="
                               font-size:32px;
                               color:#3a8a5a;
                               display:block;
                               margin-bottom:10px;">
                        </i>
                        <p style="
                            font-size:16px;
                            font-weight:700;
                            color:#3a8a5a;
                            margin-bottom:6px;">
                            Timetable Generated Successfully!
                        </p>
                        <p style="font-size:13px; color:#5a8a6a;">
                            ${result.sessions_total} sessions scheduled
                            &nbsp;·&nbsp;
                            Score: ${result.total_score} pts
                            &nbsp;·&nbsp;
                            Time: ${result.elapsed_sec}s
                        </p>
                    </div>`;
                viewLink.style.display = 'block';
            } else {
                resultBox.innerHTML = `
                    <div style="
                        padding:20px;
                        background:#fde8e8;
                        border:1px solid #f5c6c6;
                        border-radius:var(--radius-md);
                        text-align:center;">
                        <i class="fa-solid fa-circle-xmark"
                           style="
                               font-size:32px;
                               color:#c0392b;
                               display:block;
                               margin-bottom:10px;">
                        </i>
                        <p style="
                            font-size:16px;
                            font-weight:700;
                            color:#c0392b;
                            margin-bottom:6px;">
                            Generation Failed
                        </p>
                        <p style="font-size:13px; color:#c0392b;">
                            ${result.message}
                        </p>
                    </div>`;
            }

            resultBox.style.display = 'block';

            // Show logs
            if (result.logs && result.logs.length > 0) {
                logsPanel.style.display = 'block';
                const content =
                    document.getElementById('logsContent');
                content.innerHTML = result.logs.map(log => {
                    const colors = {
                        'INFO'  : '#c0e1d2',
                        'WARN'  : '#fdd9a0',
                        'ERROR' : '#f5c6c6',
                    };
                    const color = colors[log.level] || '#c0e1d2';
                    return `<div style="color:${color};">
                        [${log.time}] [${log.level}] ${log.msg}
                    </div>`;
                }).join('');
                content.scrollTop = content.scrollHeight;
            }

        }, 500);

    } catch(err) {
        clearInterval(progressInterval);
        progressBox.style.display = 'none';
        resultBox.innerHTML = `
            <div style="
                padding:16px;
                background:#fde8e8;
                border-radius:var(--radius-md);">
                <p style="color:#c0392b; font-size:14px;">
                    Connection error: ${err.message}
                </p>
            </div>`;
        resultBox.style.display = 'block';
    }

    btn.disabled  = false;
    btn.innerHTML =
        '<i class="fa-solid fa-wand-magic-sparkles"></i>'
      + ' Generate Timetable';
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>