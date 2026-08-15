<?php
require_once __DIR__ . '/includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();

// Auto-deactivate interns whose end_date has passed
autoDeactivateExpiredInterns();

$scopeDeptId = managerScopeDeptId();
$deptJoinIp  = $scopeDeptId !== null ? " AND ip.department_id = " . (int)$scopeDeptId : "";

$totalInterns = (int)$pdo->query("
    SELECT COUNT(*) FROM intern_profile ip
    WHERE ip.internship_status = 'active'" . $deptJoinIp
)->fetchColumn();

$activeInterns = $totalInterns;
$inactiveInterns = (int)$pdo->query("
    SELECT COUNT(*) FROM intern_profile ip
    WHERE ip.internship_status != 'active'" . $deptJoinIp
)->fetchColumn();

$presentToday = (int)$pdo->query("
    SELECT COUNT(*) FROM attendance a
    JOIN intern_profile ip ON a.intern_id = ip.intern_id
    WHERE a.attendance_date = CURDATE() AND a.status IN ('present','late')" . $deptJoinIp
)->fetchColumn();

$pendingLeaves = (int)$pdo->query("
    SELECT COUNT(*) FROM leave_request lr
    JOIN intern_profile ip ON lr.intern_id = ip.intern_id
    WHERE lr.approval_status = 'pending'" . $deptJoinIp
)->fetchColumn();

$openTasks = (int)$pdo->query("
    SELECT COUNT(DISTINCT t.task_id) FROM task t
    LEFT JOIN task_assignment ta ON t.task_id = ta.task_id
    LEFT JOIN intern_profile ip ON ta.intern_id = ip.intern_id
    WHERE t.task_status IN ('to_do','in_progress','pending_review')" . $deptJoinIp
)->fetchColumn();

$todayAttendance = $pdo->query("
    SELECT a.*, u.full_name, u.email, ip.intern_id, d.department_name
    FROM attendance a
    JOIN intern_profile ip ON a.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE a.attendance_date = CURDATE()" . $deptJoinIp . "
    ORDER BY u.full_name
")->fetchAll();

// Attendance breakdown widget — group today's rows by status in PHP (no extra query needed).
$breakdownCounts = ['present' => 0, 'late' => 0, 'on_leave' => 0, 'absent' => 0];
foreach ($todayAttendance as $row) {
    if (isset($breakdownCounts[$row['status']])) $breakdownCounts[$row['status']]++;
}
$breakdownTotal = array_sum($breakdownCounts);
$breakdownColors = ['present' => '#16A34A', 'late' => '#F59E0B', 'on_leave' => '#2563EB', 'absent' => '#DC2626'];
$breakdownLabels = ['present' => 'Present', 'late' => 'Late', 'on_leave' => 'On leave', 'absent' => 'Absent'];

// Pending leave widget (last 5)
$pendingLeaveRows = $pdo->query("
    SELECT lr.*, ip.intern_id, u.full_name
    FROM leave_request lr
    JOIN intern_profile ip ON lr.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    WHERE lr.approval_status = 'pending'" . $deptJoinIp . "
    ORDER BY lr.request_date DESC
    LIMIT 5
")->fetchAll();

$presentRate = $totalInterns > 0 ? round($presentToday / $totalInterns * 100) : 0;

$pageTitle = 'Dashboard';
$pageSubtitle = 'Overview of today\'s activity across all interns';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="stats-grid stats-grid-3">
    <a href="<?= BASE_URL ?>/admin/interns.php?status=active" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#E3F5EA;color:#7B5EA7;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <span class="trend-badge" style="background:#E3F5EA;color:#15803D;">Active</span>
        </div>
        <div class="stat-value"><?= $activeInterns ?></div>
        <div class="stat-label">Active interns</div>
    </a>
    <a href="<?= BASE_URL ?>/admin/interns.php?status=inactive" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#FCE4E4;color:#DC2626;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg>
            </div>
            <span class="trend-badge" style="background:#FCE4E4;color:#DC2626;">Deactivated</span>
        </div>
        <div class="stat-value"><?= $inactiveInterns ?></div>
        <div class="stat-label">Inactive interns</div>
    </a>
    <a href="<?= BASE_URL ?>/admin/attendance.php" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#E0EAFF;color:#2563EB;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <span class="trend-badge" style="background:#E0EAFF;color:#2563EB;"><?= $presentRate ?>% rate</span>
        </div>
        <div class="stat-value"><?= $presentToday ?></div>
        <div class="stat-label">Present today</div>
    </a>
    <a href="<?= BASE_URL ?>/admin/leave.php?status=pending" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#FFE9D6;color:#EA580C;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <span class="trend-badge" style="background:#FFE9D6;color:#C2410C;">Review</span>
        </div>
        <div class="stat-value"><?= $pendingLeaves ?></div>
        <div class="stat-label">Pending leave</div>
    </a>
    <a href="<?= BASE_URL ?>/admin/tasks.php" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#EDE8F5;color:#7B5EA7;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            </div>
            <span class="trend-badge" style="background:#EDE8F5;color:#7B5EA7;">Open</span>
        </div>
        <div class="stat-value"><?= $openTasks ?></div>
        <div class="stat-label">Open tasks</div>
    </a>
    <a href="<?= BASE_URL ?>/admin/reports.php" class="stat-card card-hover" style="text-decoration:none;color:inherit;">
        <div class="stat-card-top">
            <div class="icon-box" style="background:#F0FDF4;color:#15803D;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18.7 8a3 3 0 1 0-5.3 2.7L9 17"/><path d="M7 12l3 3 7-7"/></svg>
            </div>
            <span class="trend-badge" style="background:#F0FDF4;color:#15803D;">Analytics</span>
        </div>
        <div class="stat-value"><?= $totalInterns ?></div>
        <div class="stat-label">Reports</div>
    </a>
</div>

<div class="two-col-grid" style="grid-template-columns:1.9fr 1fr;">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <div>
                <div class="card-header-title">Today's attendance</div>
                <div class="card-header-sub"><?= date('l, M j') ?></div>
            </div>
            <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-outline btn-sm">View all</a>
        </div>
        <div class="grid-table-header dash-att-grid">
            <span>Intern</span><span>Department</span><span>Check in</span><span>Check out</span><span>Hours</span><span>Status</span>
        </div>
        <?php if (empty($todayAttendance)): ?>
            <div class="grid-table-row dash-att-grid"><span class="text-muted text-sm">No attendance records for today yet.</span></div>
        <?php endif; ?>
        <?php foreach ($todayAttendance as $row): ?>
            <div class="grid-table-row dash-att-grid">
                <div class="intern-cell">
                    <div class="avatar" style="<?= avatarStyle(internColor($row['intern_id']), 40, 14) ?>"><?= htmlspecialchars(getInitials($row['full_name'])) ?></div>
                    <div style="min-width:0;">
                        <div class="intern-cell-name"><?= htmlspecialchars($row['full_name']) ?></div>
                        <div class="intern-cell-email"><?= htmlspecialchars($row['email'] ?? '') ?></div>
                    </div>
                </div>
                <span class="cell-muted"><?= htmlspecialchars($row['department_name'] ?? '') ?></span>
                <span><?= timePill($row['check_in'], false) ?></span>
                <span><?= timePill($row['check_out'], false) ?></span>
                <span class="cell-muted"><?= $row['total_hours'] !== null ? htmlspecialchars($row['total_hours']) : '' ?></span>
                <span><?= statusPillFor($attendanceStat, $row['status']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:18px;">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body">
                <div class="card-title">Attendance breakdown</div>
                <?php foreach ($breakdownLabels as $key => $label): ?>
                    <?php $pct = $breakdownTotal > 0 ? round($breakdownCounts[$key] / $breakdownTotal * 100) : 0; ?>
                    <div style="margin-bottom:14px;">
                        <div class="flex justify-between" style="margin-bottom:6px;">
                            <span class="text-sm text-muted"><?= $label ?></span>
                            <span class="text-sm font-semibold" style="color:var(--navy);"><?= $breakdownCounts[$key] ?></span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $breakdownColors[$key] ?>;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom:0;">
            <div class="card-body">
                <div class="flex justify-between items-center" style="margin-bottom:14px;">
                    <div class="card-header-title" style="margin-bottom:0;">Pending leave</div>
                    <a href="<?= BASE_URL ?>/admin/leave.php?status=pending" style="font-size:11px;font-weight:700;color:#C2410C;background:#FFE9D6;padding:2px 9px;border-radius:999px;cursor:pointer;"><?= $pendingLeaves ?> new</a>
                </div>
                <?php if (empty($pendingLeaveRows)): ?>
                    <p class="text-sm text-muted">No pending leave requests.</p>
                <?php endif; ?>
                <?php foreach ($pendingLeaveRows as $i => $l): ?>
                    <?php
                    $days = (strtotime($l['end_date']) - strtotime($l['start_date'])) / 86400 + 1;
                    $detail = $days . ' day' . ($days != 1 ? 's' : '') . ' · ' . date('M j', strtotime($l['start_date'])) . '–' . date('j', strtotime($l['end_date']));
                    ?>
                    <div class="flex items-center gap-3" style="padding:11px 0;<?= $i > 0 ? 'border-top:1px solid #F4F6F8;' : '' ?>">
                        <div class="avatar" style="<?= avatarStyle(internColor($l['intern_id']), 34, 12.5) ?>"><?= htmlspecialchars(getInitials($l['full_name'])) ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="text-sm font-semibold" style="color:var(--navy);"><?= htmlspecialchars($l['full_name']) ?></div>
                            <div style="font-size:11.5px;color:var(--muted-lightest);"><?= htmlspecialchars($detail) ?></div>
                        </div>
                        <form method="POST" action="<?= BASE_URL ?>/admin/leave.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="leave_id" value="<?= (int)$l['leave_id'] ?>">
                            <input type="hidden" name="supervisor_note" value="">
                            <button type="submit" name="decision" value="approved" title="Approve" style="width:28px;height:28px;border:none;border-radius:8px;background:#E3F5EA;color:#15803D;cursor:pointer;">&#10003;</button>
                        </form>
                        <form method="POST" action="<?= BASE_URL ?>/admin/leave.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="leave_id" value="<?= (int)$l['leave_id'] ?>">
                            <input type="hidden" name="supervisor_note" value="">
                            <button type="submit" name="decision" value="rejected" title="Reject" style="width:28px;height:28px;border:none;border-radius:8px;background:#F1F3F6;color:#94A3B8;cursor:pointer;">&#10005;</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
