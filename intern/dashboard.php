<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT intern_id FROM intern_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$internId = $stmt->fetchColumn();

if (!$internId) {
    die('Intern profile not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['check_in'])) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE intern_id = ? AND attendance_date = CURDATE()");
        $stmt->execute([$internId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $error = 'You have already checked in today.';
        } else {
            $now = date('H:i:s');
            $status = (strtotime($now) > strtotime('09:15:00')) ? 'late' : 'present';
            $stmt = $pdo->prepare("INSERT INTO attendance (intern_id, attendance_date, check_in, status) VALUES (?, CURDATE(), ?, ?)");
            $stmt->execute([$internId, $now, $status]);
            setToast($status === 'late' ? 'Checked in. You arrived late today.' : 'Checked in successfully!', $status === 'late' ? 'info' : 'success');
        }
    } elseif (isset($_POST['check_out'])) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE intern_id = ? AND attendance_date = CURDATE()");
        $stmt->execute([$internId]);
        $existing = $stmt->fetch();

        if (!$existing || !$existing['check_in']) {
            $error = 'You must check in before checking out.';
        } elseif ($existing['check_out']) {
            $error = 'You have already checked out today.';
        } else {
            $now = date('H:i:s');
            $hours = round((strtotime($now) - strtotime($existing['check_in'])) / 3600, 1);
            $stmt = $pdo->prepare("UPDATE attendance SET check_out = ?, total_hours = ? WHERE attendance_id = ?");
            $stmt->execute([$now, $hours, $existing['attendance_id']]);
            setToast('Checked out. ' . $hours . 'h logged today.');
        }
    }
    if (!$error) {
        header('Location: ' . BASE_URL . '/intern/dashboard.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE intern_id = ? AND attendance_date = CURDATE()");
$stmt->execute([$internId]);
$today = $stmt->fetch();

$taskCount = $pdo->prepare("
    SELECT COUNT(*) FROM task_assignment ta
    JOIN task t ON ta.task_id = t.task_id
    WHERE ta.intern_id = ? AND t.task_status IN ('to_do', 'in_progress', 'pending_review')
");
$taskCount->execute([$internId]);
$openTaskCount = (int)$taskCount->fetchColumn();

$pendingLeaves = $pdo->prepare("SELECT COUNT(*) FROM leave_request WHERE intern_id = ? AND approval_status = 'pending'");
$pendingLeaves->execute([$internId]);
$pendingLeaveCount = (int)$pendingLeaves->fetchColumn();

$unreadNotif = getUnreadNotifCount($userId);

$stmt = $pdo->prepare("
    SELECT t.*, ta.assignment_status
    FROM task_assignment ta
    JOIN task t ON ta.task_id = t.task_id
    WHERE ta.intern_id = ?
    ORDER BY t.created_at DESC
    LIMIT 5
");
$stmt->execute([$internId]);
$myTasks = $stmt->fetchAll();

// Recent attendance (last 5) for the right-hand widget.
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE intern_id = ? ORDER BY attendance_date DESC LIMIT 5");
$stmt->execute([$internId]);
$recentAttendance = $stmt->fetchAll();

// Attendance rate this month, reused from the same logic as admin/attendance.php.
$rateStmt = $pdo->prepare("
    SELECT ROUND(100 * SUM(status IN ('present','late')) / COUNT(*), 0) AS rate
    FROM attendance
    WHERE intern_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
");
$rateStmt->execute([$internId]);
$attendanceRate = (int)($rateStmt->fetchColumn() ?: 0);

$daysPresentStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE intern_id = ? AND status IN ('present','late') AND DATE_FORMAT(attendance_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
$daysPresentStmt->execute([$internId]);
$daysPresentMonth = (int)$daysPresentStmt->fetchColumn();

$elapsed = '';
if ($today && $today['check_in'] && !$today['check_out']) {
    $mins = floor((time() - strtotime($today['check_in'])) / 60);
    $elapsed = floor($mins / 60) . 'h ' . ($mins % 60) . 'm';
}

// Upcoming holidays (today or within next 7 days)
$holidayStmt = $pdo->prepare("
    SELECT * FROM holiday
    WHERE holiday_date >= CURDATE() AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY holiday_date ASC
    LIMIT 3
");
$holidayStmt->execute();
$upcomingHolidays = $holidayStmt->fetchAll();

$firstName = trim(strtok($_SESSION['full_name'] ?? '', ' '));
$pageTitle = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . ($firstName !== '' ? $firstName : 'there');
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php foreach ($upcomingHolidays as $h):
    $isToday = date('Y-m-d', strtotime($h['holiday_date'])) === date('Y-m-d');
?>
<div style="background:<?= $isToday ? '#E3F5EA' : '#F0F1F9' ?>;border:1px solid <?= $isToday ? '#BBE8CC' : '#D4D6EC' ?>;border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;gap:12px;">
    <div style="width:34px;height:34px;border-radius:8px;background:<?= $isToday ? '#C6EDD4' : '#E2E4F5' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <?php if ($isToday): ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#15803D" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?php else: ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2D2B6B" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <?php endif; ?>
    </div>
    <div>
        <div style="font-size:13px;font-weight:700;color:<?= $isToday ? '#15803D' : 'var(--navy)' ?>;">
            <?= $isToday ? 'Today is a Holiday: ' : 'Upcoming Holiday: ' ?><?= htmlspecialchars($h['title']) ?>
        </div>
        <div style="font-size:11.5px;color:<?= $isToday ? '#4ADE80' : 'var(--muted)' ?>;margin-top:2px;">
            <?= $isToday ? 'Enjoy your day off!' : date('l, M j', strtotime($h['holiday_date'])) ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="two-col-grid" style="grid-template-columns:1.5fr 1fr;align-items:stretch;">
    <?php
        $cardState = 'idle'; // idle | in | done
        if ($today && $today['check_in'] && !$today['check_out']) $cardState = 'in';
        elseif ($today && $today['check_out']) $cardState = 'done';
        $cardBg = $cardState === 'in' ? 'linear-gradient(135deg,#0F766E 0%,#14B8A6 100%)' : ($cardState === 'done' ? 'linear-gradient(135deg,#1D4ED8 0%,#3B82F6 100%)' : 'linear-gradient(135deg,#6A4D94 0%,#9B72CF 100%)');
        $greeting = (int)date('H') < 12 ? 'Good morning' : ((int)date('H') < 17 ? 'Good afternoon' : 'Good evening');
    ?>
    <div class="checkin-card" style="background:<?= $cardBg ?>;position:relative;overflow:hidden;">
        <!-- decorative circles -->
        <div style="position:absolute;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06);top:-50px;right:-40px;pointer-events:none;"></div>
        <div style="position:absolute;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.05);bottom:-20px;right:80px;pointer-events:none;"></div>
        <div>
            <div class="checkin-date" style="opacity:.85;"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?></div>
            <div id="liveClock" style="font-size:48px;font-weight:800;color:#fff;letter-spacing:-.5px;line-height:1;margin:6px 0 10px;"><?= date('g:i:s A') ?></div>
            <?php if ($cardState === 'idle'): ?>
                <div class="checkin-headline">Not checked in yet</div>
                <div class="checkin-detail">Start your day, tap Check in to begin logging.</div>
            <?php elseif ($cardState === 'in'): ?>
                <div class="checkin-headline">✓ You're checked in</div>
                <div class="checkin-detail">
                    Since <strong style="color:#fff;"><?= date('g:i A', strtotime($today['check_in'])) ?></strong>
                    &middot; <strong style="color:#fff;" id="liveElapsed"><?= htmlspecialchars($elapsed) ?></strong> on the clock
                </div>
            <?php else: ?>
                <div class="checkin-headline">Day complete 🎉</div>
                <div class="checkin-detail">
                    <?= date('g:i A', strtotime($today['check_in'])) ?> → <?= date('g:i A', strtotime($today['check_out'])) ?>
                    &middot; <strong style="color:#fff;"><?= htmlspecialchars($today['total_hours']) ?>h</strong> logged
                </div>
            <?php endif; ?>
        </div>
        <?php if ($cardState === 'idle'): ?>
            <form method="POST" action="" style="position:relative;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="checkin-pulse-ring"></div>
                <button type="submit" name="check_in" value="1" class="checkin-btn" style="position:relative;z-index:1;">Check in</button>
            </form>
        <?php elseif ($cardState === 'in'): ?>
            <button type="button" class="checkin-btn" style="background:rgba(255,255,255,.18);backdrop-filter:blur(4px);" onclick="openModal('checkOutModal')">Check out</button>
        <?php endif; ?>
    </div>

    <div class="mini-stat-grid">
        <div class="mini-stat-cell">
            <div class="mini-stat-value" style="color:#6A4D94;"><?= $attendanceRate ?>%</div>
            <div class="mini-stat-label">Attendance rate</div>
        </div>
        <div class="mini-stat-cell">
            <div class="mini-stat-value" style="color:var(--navy);"><?= $daysPresentMonth ?></div>
            <div class="mini-stat-label">Days present</div>
        </div>
        <div class="mini-stat-cell">
            <div class="mini-stat-value" style="color:#2563EB;"><?= $openTaskCount ?></div>
            <div class="mini-stat-label">Open tasks</div>
        </div>
        <div class="mini-stat-cell">
            <div class="mini-stat-value" style="color:#B45309;"><?= $pendingLeaveCount ?></div>
            <div class="mini-stat-label">Pending leave</div>
        </div>
    </div>
</div>

<div class="two-col-grid" style="grid-template-columns:1.5fr 1fr;margin-top:18px;">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div class="card-header-title">My tasks</div>
            <a href="<?= BASE_URL ?>/intern/tasks.php" class="btn btn-outline btn-sm">View all</a>
        </div>
        <?php if (empty($myTasks)): ?>
            <p class="text-muted" style="padding:20px 22px;">No tasks assigned yet.</p>
        <?php endif; ?>
        <?php foreach ($myTasks as $t): ?>
            <div class="flex items-center gap-3" style="padding:14px 22px;border-bottom:1px solid #F4F6F8;">
                <div style="flex:1;min-width:0;">
                    <div class="text-sm font-semibold" style="color:var(--navy);"><?= htmlspecialchars($t['title']) ?></div>
                    <div style="font-size:12px;color:var(--muted-lightest);margin-top:2px;">Due <?= htmlspecialchars($t['due_date'] ?? '') ?></div>
                </div>
                <?= statusPillFor($prioMap, $t['priority']) ?>
                <?= statusPillFor($taskStat, $t['task_status']) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Recent attendance</div></div>
        <?php if (empty($recentAttendance)): ?>
            <p class="text-muted" style="padding:20px 22px;">No attendance records yet.</p>
        <?php endif; ?>
        <?php foreach ($recentAttendance as $a): ?>
            <div class="flex items-center justify-between" style="padding:12px 22px;border-bottom:1px solid #F4F6F8;">
                <span class="text-sm" style="color:var(--muted-dark);"><?= htmlspecialchars($a['attendance_date']) ?></span>
                <div class="flex items-center gap-3">
                    <span style="display:flex;align-items:center;gap:5px;"><?= timePill($a['check_in']) ?><span style="color:var(--muted-lightest);">–</span><?= timePill($a['check_out']) ?></span>
                    <?= statusPillFor($attendanceStat, $a['status']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal-overlay" id="checkOutModal" style="display:none;">
    <div class="modal confirm-modal" style="width:400px;">
        <div class="confirm-icon" style="background:#E3F5EA;color:#7B5EA7;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="confirm-title">Check out for today?</div>
        <div class="confirm-body">
            <?php
                $checkInDisplay = $today ? date('g:i A', strtotime($today['check_in'])) : '';
                $checkOutDisplay = date('g:i A');
                $hoursDisplay = $elapsed ?: 'your hours';
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px;text-align:center;">
                <div>
                    <div style="font-size:11px;color:var(--muted-lightest);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Check-in</div>
                    <div style="font-size:16px;font-weight:700;color:#2D2B6B;"><?= $checkInDisplay ?></div>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--muted-lightest);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Check-out</div>
                    <div style="font-size:16px;font-weight:700;color:#2D2B6B;"><?= $checkOutDisplay ?></div>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--muted-lightest);font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Duration</div>
                    <div style="font-size:16px;font-weight:700;color:#7B5EA7;"><?= $hoursDisplay ?></div>
                </div>
            </div>
            This will log your attendance for <?= date('l, M j') ?>.
        </div>
        <div class="confirm-btn-row">
            <button type="button" class="btn btn-outline" onclick="closeModal('checkOutModal')">Not yet</button>
            <form method="POST" action="" style="flex:1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" name="check_out" value="1" class="btn btn-primary" style="width:100%;font-weight:700;">Check out</button>
            </form>
        </div>
    </div>
</div>

<style>
@keyframes pulseRing {
    0%   { transform:scale(.9); opacity:.7; }
    70%  { transform:scale(1.55); opacity:0; }
    100% { transform:scale(.9); opacity:0; }
}
.checkin-pulse-ring {
    position:absolute;
    top:50%; left:50%;
    transform:translate(-50%,-50%) scale(.9);
    width:100%; height:100%;
    border-radius:inherit;
    border:3px solid rgba(255,255,255,.55);
    animation:pulseRing 1.8s ease-out infinite;
    pointer-events:none;
    border-radius:14px;
}
</style>
<script>
(function() {
    // Live clock
    function pad(n){ return n < 10 ? '0'+n : n; }
    function tickClock() {
        var el = document.getElementById('liveClock');
        if (!el) return;
        var now = new Date();
        var h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        el.textContent = h + ':' + pad(m) + ':' + pad(s) + ' ' + ampm;
    }
    tickClock();
    setInterval(tickClock, 1000);

    // Live elapsed timer (only shown when checked in)
    <?php if ($cardState === 'in' && $today && $today['check_in']): ?>
    var checkInTs = <?= strtotime($today['check_in']) ?> * 1000;
    function tickElapsed() {
        var el = document.getElementById('liveElapsed');
        if (!el) return;
        var secs = Math.floor((Date.now() - checkInTs) / 1000);
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        var s = secs % 60;
        el.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + pad(s) + 's';
    }
    tickElapsed();
    setInterval(tickElapsed, 1000);
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

