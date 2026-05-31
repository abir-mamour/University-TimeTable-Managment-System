<?php
// ═══════════════════════════════════════════════════════════════
//  CSP TIMETABLE SOLVER
//  Constraint Satisfaction Problem - Backtracking Search
//  Variables  : Sessions (professor, subject, group, type)
//  Domains    : (day × timeslot × room)
//  Constraints: Hard (must hold) + Soft (scored)
// ═══════════════════════════════════════════════════════════════

declare(strict_types=1);

session_start();
header('Content-Type: application/json');
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

requireRole('admin');
$admin = currentUser();

// ═══════════════════════════════════════════════════════════════
//  CONFIGURATION
// ═══════════════════════════════════════════════════════════════

const WORKING_DAYS = [
    'Saturday', 'Sunday', 'Monday',
    'Tuesday',  'Wednesday', 'Thursday',
];

// TIME_SLOTS and LUNCH values are loaded dynamically from the
// settings table via loadScheduleSettings() in the main block.

const MAX_PROF_SESSIONS_PER_DAY  = 4;
const MAX_GROUP_SESSIONS_PER_DAY = 4;

// Default soft-constraint weights (overridden by POST config)
const DEFAULT_WEIGHTS = [
    'SC1' => 15, 'SC2' => 15, 'SC3' => 10,
    'SC4' =>  5, 'SC5' =>  5, 'SC6' => 20,
    'SC7' => 15,
];

// ═══════════════════════════════════════════════════════════════
//  LOGGER
// ═══════════════════════════════════════════════════════════════

final class Logger
{
    private static array $entries = [];

    public static function info(string $msg): void
    {
        self::$entries[] = [
            'level' => 'INFO',
            'msg'   => $msg,
            'time'  => date('H:i:s'),
        ];
    }

    public static function warn(string $msg): void
    {
        self::$entries[] = [
            'level' => 'WARN',
            'msg'   => $msg,
            'time'  => date('H:i:s'),
        ];
    }

    public static function error(string $msg): void
    {
        self::$entries[] = [
            'level' => 'ERROR',
            'msg'   => $msg,
            'time'  => date('H:i:s'),
        ];
    }

    public static function all(): array
    {
        return self::$entries;
    }
}

// ═══════════════════════════════════════════════════════════════
//  DATA LOADER
//  Load everything in a fixed number of queries (no N+1)
// ═══════════════════════════════════════════════════════════════

function loadData(PDO $pdo): array
{
    Logger::info('Loading database data...');

    // Rooms indexed by id
    $rooms = $pdo->query("
        SELECT id, room_name, capacity, type
        FROM rooms
        WHERE is_active = 1
        ORDER BY capacity
    ")->fetchAll(PDO::FETCH_ASSOC);
    $roomsById = array_column($rooms, null, 'id');

    // Groups indexed by id
    $groups = $pdo->query("
        SELECT id, group_name, level, capacity
        FROM groups_table
    ")->fetchAll(PDO::FETCH_ASSOC);
    $groupsById = array_column($groups, null, 'id');

    // Professors indexed by id
    $professors = $pdo->query("
        SELECT id, name
        FROM users
        WHERE role = 'professor' AND is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    $professorsById = array_column($professors, null, 'id');

    // Subjects indexed by id
    $subjects = $pdo->query("
        SELECT id, name, code
        FROM subjects
    ")->fetchAll(PDO::FETCH_ASSOC);
    $subjectsById = array_column($subjects, null, 'id');

    // Availability: [professor_id][day] = [[start,end], ...]
    $availRows = $pdo->query("
        SELECT professor_id, day, time_start, time_end
        FROM availability
    ")->fetchAll(PDO::FETCH_ASSOC);

    $availability = [];
    foreach ($availRows as $row) {
        $availability[$row['professor_id']][$row['day']][] = [
            'start' => $row['time_start'],
            'end'   => $row['time_end'],
        ];
    }

    // Sessions to schedule
    // These come from session_assignments table:
    // (professor_id, subject_id, group_id, session_type)
    // If this table doesn't exist yet, fall back to
    // distinct rows from timetable
    try {
        $sessions = $pdo->query("
            SELECT
                professor_id,
                subject_id,
                group_id,
                session_type
            FROM session_assignments
            ORDER BY professor_id, group_id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // Fallback: derive from existing timetable
        Logger::warn('session_assignments table not found, '
                   . 'falling back to timetable table.');
        $sessions = $pdo->query("
            SELECT DISTINCT
                professor_id,
                subject_id,
                group_id,
                session_type
            FROM timetable
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    Logger::info(sprintf(
        'Loaded: %d professors, %d groups, %d rooms, '
      . '%d subjects, %d sessions',
        count($professorsById),
        count($groupsById),
        count($roomsById),
        count($subjectsById),
        count($sessions)
    ));

    return [
        'rooms'        => $roomsById,
        'groups'       => $groupsById,
        'professors'   => $professorsById,
        'subjects'     => $subjectsById,
        'availability' => $availability,
        'sessions'     => array_values($sessions),
    ];
}

// ═══════════════════════════════════════════════════════════════
//  DOMAIN GENERATION
//  For each session variable, compute all valid
//  (day, timeslot, room) triples BEFORE search begins.
//  Apply structural pruning here, not during backtracking.
// ═══════════════════════════════════════════════════════════════

function generateDomains(array $data, array $cfg): array
{
    Logger::info('Generating and pruning domains...');

    $forbidSaturday  = $cfg['hc8_saturday']     ?? true;
    $respectAvail    = $cfg['hc4_availability']  ?? true;
    $domains = [];

    foreach ($data['sessions'] as $idx => $session) {
        $profId  = (int)$session['professor_id'];
        $groupId = (int)$session['group_id'];
        $type    = $session['session_type'];

        $groupCap = (int)($data['groups'][$groupId]['capacity'] ?? 30);
        $domain   = [];

        foreach (WORKING_DAYS as $day) {

            // HC8: skip Saturday when enabled
            if ($forbidSaturday && $day === 'Saturday') {
                continue;
            }

            foreach ($cfg['time_slots'] as $slot) {

                // HC7: skip lunch break slots when enabled
                if (($cfg['hc7_lunch'] ?? true) && isInLunchBreak(
                    $slot['start'],
                    $cfg['lunch_start'],
                    $cfg['lunch_end']
                )) {
                    continue;
                }

                // HC4: respect availability when enabled
                if ($respectAvail && !isProfAvailableInSlot(
                    $profId, $day,
                    $slot['start'], $slot['end'],
                    $data['availability']
                )) {
                    continue;
                }

                foreach ($data['rooms'] as $room) {
                    if ((int)$room['capacity'] < $groupCap) {
                        continue;
                    }
                    $domain[] = [
                        'day'        => $day,
                        'time_start' => $slot['start'],
                        'time_end'   => $slot['end'],
                        'room_id'    => (int)$room['id'],
                        'room_type'  => $room['type'],
                        'room_cap'   => (int)$room['capacity'],
                    ];
                }
            }
        }

        $domains[$idx] = $domain;

        if (empty($domain)) {
            Logger::warn(sprintf(
                'Session %d has EMPTY domain (prof=%d, group=%d, type=%s)',
                $idx, $profId, $groupId, $type
            ));
        } else {
            Logger::info(sprintf(
                'Session %d domain size: %d', $idx, count($domain)
            ));
        }
    }

    return $domains;
}

// ═══════════════════════════════════════════════════════════════
//  MRV HEURISTIC
//  Order unassigned variables by domain size ascending.
//  Ties broken by number of constraints (degree heuristic).
// ═══════════════════════════════════════════════════════════════

function mrvOrder(array $sessions, array $domains): array
{
    Logger::info('Applying MRV heuristic...');

    $order = [];
    foreach ($sessions as $idx => $session) {
        $order[] = [
            'idx'     => $idx,
            'session' => $session,
            'size'    => count($domains[$idx]),
        ];
    }

    // Sort ascending by domain size
    usort($order, static function (array $a, array $b): int {
        return $a['size'] <=> $b['size'];
    });

    return $order;
}

// ═══════════════════════════════════════════════════════════════
//  HARD CONSTRAINT CHECKER
//  Checks all hard constraints against FULL assignment state.
//  Returns [ok => bool, reason => string]
// ═══════════════════════════════════════════════════════════════

function checkHard(
    array $candidate,
    array $assigned,
    array $cfg = []
): array
{
    $day   = $candidate['day'];
    $start = $candidate['time_start'];
    $profId  = (int)$candidate['professor_id'];
    $groupId = (int)$candidate['group_id'];
    $roomId  = (int)$candidate['room_id'];

    // HC7: Lunch break (configurable)
    if (($cfg['hc7_lunch'] ?? true) && isInLunchBreak($start, $cfg['lunch_start'], $cfg['lunch_end'])) {
        return fail('HC7: Lunch break slot');
    }

    // HC8: Saturday (enforced only when config says so)
    if (($cfg['hc8_saturday'] ?? true) && $day === 'Saturday') {
        return fail('HC8: Saturday is forbidden');
    }

    $profDayCount  = 0;
    $groupDayCount = 0;

    foreach ($assigned as $a) {
        $sameTime = ($a['day'] === $day && $a['time_start'] === $start);

        // HC1: Room double-booking
        if ($sameTime && (int)$a['room_id'] === $roomId) {
            return fail("HC1: Room {$roomId} already booked on "
                       . "{$day} at {$start}");
        }

        // HC2: Professor double-booking
        if ($sameTime && (int)$a['professor_id'] === $profId) {
            return fail("HC2: Professor {$profId} already busy on "
                       . "{$day} at {$start}");
        }

        // HC3: Group overlap
        if ($sameTime && (int)$a['group_id'] === $groupId) {
            return fail("HC3: Group {$groupId} already has class on "
                       . "{$day} at {$start}");
        }

        // Accumulate daily counts for HC5 / HC6
        if ($a['day'] === $day) {
            if ((int)$a['professor_id'] === $profId) {
                $profDayCount++;
            }
            if ((int)$a['group_id'] === $groupId) {
                $groupDayCount++;
            }
        }
    }

    // HC5: Max professor sessions per day
    if ($profDayCount >= MAX_PROF_SESSIONS_PER_DAY) {
        return fail("HC5: Professor {$profId} already has "
                   . MAX_PROF_SESSIONS_PER_DAY
                   . " sessions on {$day}");
    }

    // HC6: Max group sessions per day
    if ($groupDayCount >= MAX_GROUP_SESSIONS_PER_DAY) {
        return fail("HC6: Group {$groupId} already has "
                   . MAX_GROUP_SESSIONS_PER_DAY
                   . " sessions on {$day}");
    }

    return ['ok' => true, 'reason' => ''];
}

// ═══════════════════════════════════════════════════════════════
//  SOFT CONSTRAINT SCORER
//  Returns integer score for a candidate assignment.
//  Higher score = better quality placement.
// ═══════════════════════════════════════════════════════════════

function scoreSoft(
    array $candidate,
    array $assigned,
    array $data,
    array $cfg = []
): int
{
    $w = array_merge(DEFAULT_WEIGHTS, $cfg['weights'] ?? []);

    $score   = 0;
    $day     = $candidate['day'];
    $start   = $candidate['time_start'];
    $type    = $candidate['session_type'];
    $profId  = (int)$candidate['professor_id'];
    $groupId = (int)$candidate['group_id'];
    $subId   = (int)$candidate['subject_id'];
    $roomId  = (int)$candidate['room_id'];

    // ─── SC1: Lecture before TD/TP ────────────
    if ($type === 'lecture') {
        $score += $w['SC1'];
    } else {
        foreach ($assigned as $a) {
            if ((int)$a['group_id']   === $groupId
             && (int)$a['subject_id'] === $subId
             && $a['session_type']    === 'lecture') {
                $score += $w['SC1'];
                break;
            }
        }
    }

    // ─── SC2: Spread group sessions across week ─
    $groupDays = [];
    foreach ($assigned as $a) {
        if ((int)$a['group_id'] === $groupId) {
            $groupDays[$a['day']] = true;
        }
    }
    if (!isset($groupDays[$day])) {
        $score += $w['SC2'];
    }

    // ─── SC3: Morning slots for lectures ──────
    if ($type === 'lecture'
     && in_array($start, ['08:00:00', '10:00:00'], true)) {
        $score += $w['SC3'];
    }

    // ─── SC4: Room type matches session type ──
    $room     = $data['rooms'][$roomId] ?? [];
    $roomType = $room['type'] ?? '';
    $expected = match ($type) {
        'lecture' => 'lecture', 'lab' => 'lab', 'seminar' => 'seminar',
        default   => '',
    };
    if ($roomType === $expected) {
        $score += $w['SC4'];
    }

    // ─── SC5: Best-fit room capacity ──────────
    $groupCap = (int)($data['groups'][$groupId]['capacity'] ?? 30);
    $roomCap  = (int)($room['capacity'] ?? 0);
    $waste    = $roomCap - $groupCap;
    if ($waste >= 0 && $waste <= 10) {
        $score += $w['SC5'];
    }

    // ─── SC6: Professor preferred time slot ───
    $profAvail = $data['availability'][$profId][$day] ?? [];
    foreach ($profAvail as $slot) {
        if ($slot['start'] <= $start && $slot['end'] > $start) {
            $score += $w['SC6'];
            break;
        }
    }

    // ─── SC7: Professor works on max 2 days ───
    // Reward placing session on a day the prof ALREADY teaches
    // so the solver naturally clusters sessions into fewer days
    if ($w['SC7'] > 0) {
        $profDays = [];
        foreach ($assigned as $a) {
            if ((int)$a['professor_id'] === $profId) {
                $profDays[$a['day']] = true;
            }
        }
        if (isset($profDays[$day])) {
            // Placing on an already-used day = good (clustering)
            $score += $w['SC7'];
        } elseif (count($profDays) >= 2) {
            // Would open a 3rd+ day = penalise
            $score -= (int)($w['SC7'] * 0.8);
        }
    }

    return $score;
}

// ═══════════════════════════════════════════════════════════════
//  LCV HEURISTIC
//  For each value in the domain of the current variable,
//  check hard constraints against current assignment,
//  compute soft score, and sort by score descending.
//  Returns only valid values, ordered best-first.
// ═══════════════════════════════════════════════════════════════

function lcvOrder(
    int   $idx,
    array $session,
    array $domain,
    array $assigned,
    array $data,
    array $cfg = []
): array
{
    $valid = [];

    foreach ($domain as $value) {
        // Build full candidate
        $candidate                  = $value;
        $candidate['professor_id']  = $session['professor_id'];
        $candidate['subject_id']    = $session['subject_id'];
        $candidate['group_id']      = $session['group_id'];
        $candidate['session_type']  = $session['session_type'];

        // Hard constraint check against FULL assigned set
        $hc = checkHard($candidate, $assigned, $cfg);
        if (!$hc['ok']) {
            continue;
        }

        // Soft score
        $score = scoreSoft($candidate, $assigned, $data, $cfg);

        $valid[] = [
            'value'     => $value,
            'candidate' => $candidate,
            'score'     => $score,
        ];
    }

    // Sort descending by score (best value first = LCV)
    usort($valid, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    return $valid;
}

// ═══════════════════════════════════════════════════════════════
//  FORWARD CHECKING
//  After placing a session, prune domains of ALL
//  remaining unassigned sessions.
//  Uses FULL current assignment including the new placement.
//  Returns false if any domain becomes empty (wipeout).
// ═══════════════════════════════════════════════════════════════

function forwardCheck(
    array  $newAssignment,
    array  &$domains,
    array  $remainingIndices,
    array  $sessions,
    array  $assigned,          // full set including newAssignment
    array  $cfg = []
): bool
{
    foreach ($remainingIndices as $idx) {
        $session   = $sessions[$idx];
        $newDomain = [];

        foreach ($domains[$idx] as $value) {
            $candidate                 = $value;
            $candidate['professor_id'] = $session['professor_id'];
            $candidate['subject_id']   = $session['subject_id'];
            $candidate['group_id']     = $session['group_id'];
            $candidate['session_type'] = $session['session_type'];

            $hc = checkHard($candidate, $assigned, $cfg);
            if ($hc['ok']) {
                $newDomain[] = $value;
            }
        }

        if (empty($newDomain)) {
            Logger::warn(sprintf(
                'Forward check: domain wipeout for session %d '
              . 'after placing prof=%s day=%s %s',
                $idx,
                $newAssignment['professor_id'],
                $newAssignment['day'],
                $newAssignment['time_start']
            ));
            return false;
        }

        $domains[$idx] = $newDomain;
    }

    return true;
}

// ═══════════════════════════════════════════════════════════════
//  BACKTRACKING SEARCH
//  Recursive CSP search with:
//    - MRV ordering (pre-computed)
//    - LCV value ordering
//    - Forward checking after each assignment
//    - Full backtracking (no artificial depth limit)
// ═══════════════════════════════════════════════════════════════

const MAX_BACKTRACKS = 50000;

function backtrack(
    array  $mrvOrder,
    int    $pos,
    array  &$domains,
    array  &$assigned,
    array  $data,
    int    &$backtracks,
    array  $cfg = []
): bool
{
    // Abort if too many backtracks
    if ($backtracks >= MAX_BACKTRACKS) {
        Logger::warn('Max backtracks reached, aborting.');
        return false;
    }

    // Base case: all sessions assigned
    if ($pos >= count($mrvOrder)) {
        Logger::info('Solution found! Total backtracks: ' . $backtracks);
        return true;
    }

    $item    = $mrvOrder[$pos];
    $idx     = $item['idx'];
    $session = $item['session'];

    Logger::info(sprintf(
        'Session %d/%d: prof=%d group=%d type=%s domain=%d',
        $pos + 1,
        count($mrvOrder),
        $session['professor_id'],
        $session['group_id'],
        $session['session_type'],
        count($domains[$idx])
    ));

    // Get valid values ordered by LCV (soft score)
    $orderedValues = lcvOrder(
        $idx,
        $session,
        $domains[$idx],
        $assigned,
        $data,
        $cfg
    );

    if (empty($orderedValues)) {
        Logger::warn("No valid values for session {$idx}, backtracking");
        $backtracks++;
        return false;
    }

    foreach ($orderedValues as $item2) {
        $candidate = $item2['candidate'];

        // Save domain state for backtracking
        $savedDomains = $domains;

        // Make assignment
        $assigned[] = $candidate;

        // Compute remaining indices
        $remaining = [];
        for ($i = $pos + 1; $i < count($mrvOrder); $i++) {
            $remaining[] = $mrvOrder[$i]['idx'];
        }

        // Forward checking with FULL assigned array
        $fcOk = forwardCheck(
            $candidate,
            $domains,
            $remaining,
            $data['sessions'],
            $assigned,     // ← correct: full current assignment
            $cfg
        );

        if ($fcOk) {
            // Recurse
            $result = backtrack(
                $mrvOrder,
                $pos + 1,
                $domains,
                $assigned,
                $data,
                $backtracks,
                $cfg
            );

            if ($result) {
                return true;
            }
        }

        // Undo assignment (backtrack)
        array_pop($assigned);
        $domains = $savedDomains;
        $backtracks++;
    }

    Logger::warn("Exhausted all values for session {$idx}");
    return false;
}

// ═══════════════════════════════════════════════════════════════
//  FINAL VALIDATION
//  Hard post-solution check. Should always pass
//  if the solver is correct, but included as safety net.
// ═══════════════════════════════════════════════════════════════

function validateSolution(array $assigned, array $cfg = []): array
{
    $violations = [];

    for ($i = 0; $i < count($assigned); $i++) {
        $a = $assigned[$i];

        // HC7: lunch break (configurable)
        if (($cfg['hc7_lunch'] ?? true) && isInLunchBreak($a['time_start'], $cfg['lunch_start'] ?? '12:00:00', $cfg['lunch_end'] ?? '14:00:00')) {
            $violations[] = "HC7: session $i in lunch break";
        }

        // HC8: Saturday forbidden (when enabled)
        if (($cfg['hc8_saturday'] ?? true) && $a['day'] === 'Saturday') {
            $violations[] = "HC8: session $i on forbidden day {$a['day']}";
        }

        for ($j = $i + 1; $j < count($assigned); $j++) {
            $b = $assigned[$j];

            $sameTime = ($a['day']        === $b['day']
                      && $a['time_start'] === $b['time_start']);

            if ($sameTime) {
                if ((int)$a['room_id'] === (int)$b['room_id']) {
                    $violations[] = "HC1: room conflict at "
                                  . "{$a['day']} {$a['time_start']}";
                }
                if ((int)$a['professor_id'] === (int)$b['professor_id']) {
                    $violations[] = "HC2: professor conflict at "
                                  . "{$a['day']} {$a['time_start']}";
                }
                if ((int)$a['group_id'] === (int)$b['group_id']) {
                    $violations[] = "HC3: group conflict at "
                                  . "{$a['day']} {$a['time_start']}";
                }
            }
        }
    }

    return $violations;
}

// ═══════════════════════════════════════════════════════════════
//  SAVE TO DATABASE
// ═══════════════════════════════════════════════════════════════

function saveToDatabase(PDO $pdo, array $assigned): void
{
    Logger::info('Saving ' . count($assigned) . ' sessions...');

    // ── Wrap in transaction ───────────────────
    $pdo->beginTransaction();

    try {
        // ── Clear ALL existing timetable rows ─
        // Must DELETE not just soft-disable
        // because UNIQUE keys block re-insertion
        $pdo->exec("DELETE FROM timetable");

        Logger::info('Old timetable cleared.');

        // ── Insert new sessions ───────────────
        $stmt = $pdo->prepare("
            INSERT INTO timetable
                (professor_id, group_id, room_id,
                 subject_id, day, time_start,
                 time_end, session_type, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");

        foreach ($assigned as $i => $a) {
            $stmt->execute([
                $a['professor_id'],
                $a['group_id'],
                $a['room_id'],
                $a['subject_id'],
                $a['day'],
                $a['time_start'],
                $a['time_end'],
                $a['session_type'],
            ]);
            Logger::info(sprintf(
                'Inserted session %d: %s %s room=%d',
                $i + 1,
                $a['day'],
                $a['time_start'],
                $a['room_id']
            ));
        }

        $pdo->commit();
        Logger::info('All ' . count($assigned) . ' sessions saved.');

    } catch (\PDOException $e) {
        $pdo->rollBack();
        Logger::error('Save failed: ' . $e->getMessage());
        throw $e;
    }
}

// ═══════════════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════════════

function isInLunchBreak(
    string $timeStart,
    string $lunchStart = '12:00:00',
    string $lunchEnd   = '14:00:00'
): bool {
    return ($timeStart >= $lunchStart && $timeStart < $lunchEnd);
}

/**
 * Returns true if professor has declared availability
 * covering the given slot, OR if no availability is
 * declared at all (meaning "always available").
 */
function isProfAvailableInSlot(
    int    $profId,
    string $day,
    string $slotStart,
    string $slotEnd,
    array  $availability
): bool
{
    // No availability declared → always available
    if (empty($availability[$profId])) {
        return true;
    }

    // Availability declared but not for this day → not available
    if (empty($availability[$profId][$day])) {
        return false;
    }

    // Check if any declared window covers this slot
    foreach ($availability[$profId][$day] as $window) {
        if ($window['start'] <= $slotStart
         && $window['end']   >= $slotEnd) {
            return true;
        }
    }

    return false;
}

function fail(string $reason): array
{
    return ['ok' => false, 'reason' => $reason];
}

// ═══════════════════════════════════════════════════════════════
//  MAIN ENTRY POINT
// ═══════════════════════════════════════════════════════════════

try {
    $t0 = microtime(true);
    Logger::info('=== CSP Solver Started ===');

    // ── 0. Parse request config ───────────────
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $rawCfg = $input['constraints'] ?? [];

    // Load schedule settings (break time, session duration, etc.)
    $scheduleSettings = loadScheduleSettings($pdo);
    $timeSlots        = computeTimeSlots($scheduleSettings);

    Logger::info(sprintf(
        'Schedule: break=%dmin session=%dmin slots=%d [%s]',
        $scheduleSettings['break_duration_minutes'],
        $scheduleSettings['session_duration_minutes'],
        count($timeSlots),
        implode(', ', array_map(fn($s) => $s['start'], $timeSlots))
    ));

    $config = [
        'hc7_lunch'       => (bool)($rawCfg['hc7_lunch']        ?? true),
        'hc8_saturday'    => (bool)($rawCfg['hc8_saturday']    ?? true),
        'hc4_availability'=> (bool)($rawCfg['hc4_availability'] ?? true),
        'weights'         => [],
        'time_slots'      => $timeSlots,
        'lunch_start'     => $scheduleSettings['lunch_start_time'],
        'lunch_end'       => $scheduleSettings['lunch_end_time'],
    ];

    // Merge per-SC weights (clamp 0–20)
    $rawWeights = $rawCfg['weights'] ?? [];
    foreach (DEFAULT_WEIGHTS as $key => $default) {
        $val = isset($rawWeights[$key]) ? (int)$rawWeights[$key] : $default;
        $config['weights'][$key] = max(0, min(20, $val));
    }

    Logger::info(sprintf(
        'Config: saturday=%s availability=%s weights=%s',
        $config['hc8_saturday']     ? 'blocked' : 'allowed',
        $config['hc4_availability'] ? 'respected' : 'ignored',
        implode(',', array_map(
            fn($k, $v) => "{$k}:{$v}",
            array_keys($config['weights']),
            $config['weights']
        ))
    ));

    // ── 0b. confirm_save: persist pre-approved sessions ──
    if (!empty($input['confirm_save'])) {
        $sessions = $input['sessions_data'] ?? [];
        if (empty($sessions)) {
            echo json_encode([
                'success' => false,
                'message' => 'No sessions to save.',
                'logs'    => Logger::all(),
            ]);
            exit();
        }

        Logger::info('Saving ' . count($sessions) . ' pre-approved sessions…');
        saveToDatabase($pdo, $sessions);

        $userIds = $pdo->query("
            SELECT id FROM users
            WHERE role IN ('professor','student') AND is_active = 1
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($userIds as $uid) {
            sendNotification(
                $pdo, (int)$uid,
                'New Timetable Published',
                'A new timetable has been generated. '
              . 'Please check your updated schedule.',
                'success'
            );
        }

        Logger::info('Timetable saved and notifications sent.');
        echo json_encode([
            'success' => true,
            'message' => count($sessions) . ' sessions saved successfully.',
            'logs'    => Logger::all(),
        ]);
        exit();
    }

    // ── 1. Load data ──────────────────────────
    $data = loadData($pdo);

    if (empty($data['sessions'])) {
        echo json_encode([
            'success' => false,
            'message' => 'No sessions to schedule.',
            'logs'    => Logger::all(),
        ]);
        exit();
    }

    // ── 2. Generate & prune domains ───────────
    $domains = generateDomains($data, $config);

    // Abort if any domain is empty
    $emptyDomains = [];
    foreach ($domains as $idx => $domain) {
        if (empty($domain)) {
            $s = $data['sessions'][$idx];
            $emptyDomains[] = sprintf(
                'Session %d (prof=%d, group=%d, type=%s)',
                $idx,
                $s['professor_id'],
                $s['group_id'],
                $s['session_type']
            );
        }
    }

    if (!empty($emptyDomains)) {
        Logger::error('Empty domains detected - unsolvable');
        echo json_encode([
            'success'      => false,
            'message'      => 'Some sessions have no valid placement. '
                            . 'Check professor availability or add more rooms.',
            'empty_domains'=> $emptyDomains,
            'logs'         => Logger::all(),
        ]);
        exit();
    }

    // ── 3. MRV ordering ───────────────────────
    $order = mrvOrder($data['sessions'], $domains);

    // ── 4. Run backtracking search ────────────
    $assigned   = [];
    $backtracks = 0;

    Logger::info('Starting backtracking search...');

    $found = backtrack(
        $order,
        0,
        $domains,
        $assigned,
        $data,
        $backtracks,
        $config
    );

    $elapsed = round(microtime(true) - $t0, 3);

    if (!$found) {
        Logger::error('No solution found after ' . $backtracks . ' backtracks');
        echo json_encode([
            'success'    => false,
            'message'    => 'No valid timetable could be generated. '
                          . 'Try relaxing constraints (availability, '
                          . 'rooms, or session limits).',
            'backtracks' => $backtracks,
            'elapsed'    => $elapsed,
            'logs'       => Logger::all(),
        ]);
        exit();
    }

    // ── 5. Post-solution validation ───────────
    Logger::info('Running final validation...');
    $violations = validateSolution($assigned, $config);

    if (!empty($violations)) {
        Logger::error('Validation failed: ' . implode('; ', $violations));
        echo json_encode([
            'success'    => false,
            'message'    => 'Internal solver error: hard constraint '
                          . 'violations in output. Please report.',
            'violations' => $violations,
            'logs'       => Logger::all(),
        ]);
        exit();
    }

    // ── 6. Compute total soft score ───────────
    $totalScore = 0;
    foreach ($assigned as $a) {
        $totalScore += scoreSoft($a, $assigned, $data, $config);
    }

    // ── 7. Enrich with display names for preview ─
    $enriched = array_map(function ($a) use ($data) {
        return array_merge($a, [
            'professor_name' => $data['professors'][(int)$a['professor_id']]['name']      ?? '',
            'subject_name'   => $data['subjects'][(int)$a['subject_id']]['name']          ?? '',
            'group_name'     => $data['groups'][(int)$a['group_id']]['group_name']        ?? '',
            'room_name'      => $data['rooms'][(int)$a['room_id']]['room_name']           ?? '',
        ]);
    }, $assigned);

    Logger::info('=== CSP Solver Finished — awaiting admin approval ===');

    // NOTE: Not saving yet — admin must review the preview and confirm.
    echo json_encode([
        'success'       => true,
        'message'       => 'Timetable generated. Please review and confirm.',
        'sessions'      => count($assigned),
        'score'         => $totalScore,
        'backtracks'    => $backtracks,
        'elapsed'       => $elapsed,
        'sessions_data' => $enriched,
        'logs'          => Logger::all(),
    ]);

} catch (\Throwable $e) {
    Logger::error('Uncaught: ' . $e->getMessage()
                . ' in ' . $e->getFile()
                . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage(),
        'logs'    => Logger::all(),
    ]);
}
exit();