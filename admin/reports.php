<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();

$scopeDeptId = managerScopeDeptId();
$month       = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$deptFilter = $_GET['department_id'] ?? '';
// Managers are locked to their own department regardless of what's in the URL.
if ($scopeDeptId !== null) {
    $deptFilter = (string)$scopeDeptId;
}
$search = trim($_GET['q'] ?? '');
$internIdFilter = (int)($_GET['intern_id'] ?? 0);

// Attendance Summary (selected month)
$sql = "
    SELECT
        SUM(a.status = 'present') AS present_count,
        SUM(a.status = 'late') AS late_count,
        SUM(a.status = 'absent') AS absent_count,
        SUM(a.status = 'on_leave') AS leave_count,
        COUNT(*) AS total
    FROM attendance a
    JOIN intern_profile ip ON a.intern_id = ip.intern_id
    WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
";
$params = [$month];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attSummary = $stmt->fetch();

// Late/Absent records (last 30 days)
$sql = "
    SELECT a.attendance_date, a.status, u.full_name
    FROM attendance a
    JOIN intern_profile ip ON a.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    WHERE a.status IN ('late', 'absent') AND a.attendance_date >= CURDATE() - INTERVAL 30 DAY
";
$params = [];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
if ($search !== '') { $sql .= " AND u.full_name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY a.attendance_date DESC LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lateAbsent = $stmt->fetchAll();

// Completed tasks (scoped to interns in-scope when filtered)
$sql = "
    SELECT t.title, t.due_date, GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') AS assignees
    FROM task t
    LEFT JOIN task_assignment ta ON t.task_id = ta.task_id
    LEFT JOIN intern_profile ip ON ta.intern_id = ip.intern_id
    LEFT JOIN user u ON ip.user_id = u.user_id
    WHERE t.task_status = 'done'
";
$params = [];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
$sql .= " GROUP BY t.task_id ORDER BY t.due_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$completedTasks = $stmt->fetchAll();

// Pending tasks
$sql = "
    SELECT t.title, t.due_date, t.task_status, GROUP_CONCAT(DISTINCT u.full_name SEPARATOR ', ') AS assignees
    FROM task t
    LEFT JOIN task_assignment ta ON t.task_id = ta.task_id
    LEFT JOIN intern_profile ip ON ta.intern_id = ip.intern_id
    LEFT JOIN user u ON ip.user_id = u.user_id
    WHERE t.task_status IN ('to_do', 'in_progress', 'pending_review')
";
$params = [];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
$sql .= " GROUP BY t.task_id ORDER BY t.due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingTasks = $stmt->fetchAll();

// Intern performance overview
$sql = "
    SELECT
        u.full_name,
        d.department_name,
        COUNT(DISTINCT a.attendance_id) AS attendance_days,
        SUM(a.status = 'present') AS present_days,
        SUM(a.status = 'late') AS late_days,
        SUM(a.status = 'absent') AS absent_days,
        ROUND(AVG(a.total_hours), 1) AS avg_hours,
        (SELECT COUNT(*) FROM task_assignment ta2
            JOIN task t2 ON ta2.task_id = t2.task_id
            WHERE ta2.intern_id = ip.intern_id AND t2.task_status = 'done') AS tasks_done,
        (SELECT COUNT(*) FROM task_assignment ta3
            JOIN task t3 ON ta3.task_id = t3.task_id
            WHERE ta3.intern_id = ip.intern_id) AS tasks_total
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    LEFT JOIN attendance a ON a.intern_id = ip.intern_id
    WHERE 1=1
";
$params = [];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
if ($search !== '') { $sql .= " AND u.full_name LIKE ?"; $params[] = "%$search%"; }
$sql .= " GROUP BY ip.intern_id ORDER BY u.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$performance = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

// Load intern list for the dropdown (scoped by dept if manager)
$internListSql = "SELECT ip.intern_id, u.full_name FROM intern_profile ip JOIN user u ON ip.user_id = u.user_id WHERE 1=1";
$internListParams = [];
if ($deptFilter !== '') { $internListSql .= " AND ip.department_id = ?"; $internListParams[] = $deptFilter; }
$internListSql .= " ORDER BY u.full_name";
$internListStmt = $pdo->prepare($internListSql);
$internListStmt->execute($internListParams);
$internList = $internListStmt->fetchAll();

$pageTitle = 'Reports & Analytics';
$pageSubtitle = 'Attendance, tasks and performance insights';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<!-- Filter card: dropdowns only -->
<div class="card" style="margin-bottom:12px;">
    <form id="reportsForm" method="GET" action="" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <?php if ($scopeDeptId === null): ?>
            <select name="department_id" class="form-control" style="width:auto;">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['department_id'] ?>" <?= (string)$deptFilter === (string)$d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="intern_id" class="form-control" style="width:auto;">
            <option value="0">All Interns</option>
            <?php foreach ($internList as $i): ?>
                <option value="<?= (int)$i['intern_id'] ?>" <?= $internIdFilter === (int)$i['intern_id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>" style="width:auto;">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($deptFilter || $internIdFilter || $search): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count + export -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <form method="GET" style="flex:1;display:flex;gap:0;">
        <?php if ($deptFilter): ?><input type="hidden" name="department_id" value="<?= htmlspecialchars($deptFilter) ?>"><?php endif; ?>
        <?php if ($internIdFilter): ?><input type="hidden" name="intern_id" value="<?= $internIdFilter ?>"><?php endif; ?>
        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <div class="search-wrap" style="width:100%;max-width:320px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="liveSearch" name="q" class="form-control search-input" placeholder="Search interns…" value="<?= htmlspecialchars($search) ?>" data-target="reportsTable" data-also="lateAbsentTable" autocomplete="off">
        </div>
    </form>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;"><?= count($performance) ?> intern<?= count($performance) !== 1 ? 's' : '' ?></span>
    <a href="<?= BASE_URL ?>/admin/export_report.php?<?= http_build_query(['month' => $month, 'department_id' => $deptFilter, 'intern_id' => $internIdFilter, 'q' => $search]) ?>"
       style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#E3F5EA;color:#15803D;border:1.5px solid #86EFAC;text-decoration:none;flex-shrink:0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div class="stat-grid-plain">
    <div class="stat-card"><div class="stat-plain-label">Present (this month)</div><div class="stat-plain-value" style="color:#6A4D94;"><?= (int)($attSummary['present_count'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Late (this month)</div><div class="stat-plain-value" style="color:#B45309;"><?= (int)($attSummary['late_count'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Absent (this month)</div><div class="stat-plain-value" style="color:#DC2626;"><?= (int)($attSummary['absent_count'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">On Leave (this month)</div><div class="stat-plain-value" style="color:#2563EB;"><?= (int)($attSummary['leave_count'] ?? 0) ?></div></div>
</div>

<div class="two-col-grid">
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Late &amp; absent (last 30 days)</div></div>
        <div class="table-wrap">
            <table id="lateAbsentTable">
                <thead><tr><th>Intern</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($lateAbsent)): ?>
                        <tr><td colspan="3" class="text-muted">No late or absent records.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($lateAbsent as $r): ?>
                        <tr>
                            <td>
                                <div class="intern-cell">
                                    <div class="avatar" style="<?= avatarStyle('#7B5EA7', 28, 11) ?>"><?= htmlspecialchars(getInitials($r['full_name'])) ?></div>
                                    <span><?= htmlspecialchars($r['full_name']) ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($r['attendance_date']) ?></td>
                            <td><?= statusPillFor($attendanceStat, $r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Completed tasks</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Title</th><th>Assignee</th><th>Due</th></tr></thead>
                <tbody>
                    <?php if (empty($completedTasks)): ?>
                        <tr><td colspan="3" class="text-muted">No completed tasks.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($completedTasks as $t): ?>
                        <tr>
                            <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($t['title']) ?></td>
                            <td><?= htmlspecialchars($t['assignees'] ?? '') ?></td>
                            <td><?= htmlspecialchars($t['due_date'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-header-title">Pending tasks</div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Assignee(s)</th><th>Status</th><th>Due Date</th></tr></thead>
            <tbody>
                <?php if (empty($pendingTasks)): ?>
                    <tr><td colspan="4" class="text-muted">No pending tasks.</td></tr>
                <?php endif; ?>
                <?php foreach ($pendingTasks as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['title']) ?></td>
                        <td><?= htmlspecialchars($t['assignees'] ?? '') ?></td>
                        <td><?= statusPillFor($taskStat, $t['task_status']) ?></td>
                        <td><?= htmlspecialchars($t['due_date'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-header-title">Intern performance overview</div></div>
    <div class="table-wrap">
        <table id="reportsTable" style="min-width:620px;">
            <thead><tr><th>Intern</th><th>Department</th><th>Present</th><th>Late</th><th>Absent</th><th>Avg Hrs</th><th>Tasks</th></tr></thead>
            <tbody>
                <?php foreach ($performance as $p): ?>
                    <tr>
                        <td>
                            <div class="intern-cell">
                                <div class="avatar" style="<?= avatarStyle('#7B5EA7', 28, 11) ?>"><?= htmlspecialchars(getInitials($p['full_name'])) ?></div>
                                <span><?= htmlspecialchars($p['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($p['department_name'] ?? '') ?></td>
                        <td style="color:#6A4D94;font-weight:600;"><?= (int)$p['present_days'] ?></td>
                        <td style="color:#B45309;font-weight:600;"><?= (int)$p['late_days'] ?></td>
                        <td style="color:#DC2626;font-weight:600;"><?= (int)$p['absent_days'] ?></td>
                        <td><?= $p['avg_hours'] !== null ? htmlspecialchars($p['avg_hours']) : '' ?></td>
                        <td style="font-weight:600;"><?= (int)$p['tasks_done'] ?> / <?= (int)$p['tasks_total'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

