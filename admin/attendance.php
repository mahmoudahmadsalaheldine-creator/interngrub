<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$scopeDeptId = managerScopeDeptId();
$deptFilter = $_GET['department_id'] ?? '';
if ($scopeDeptId !== null) {
    $deptFilter = (string)$scopeDeptId;
}

$sql = "
    SELECT a.*, u.full_name, d.department_name
    FROM attendance a
    JOIN intern_profile ip ON a.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
";
$params = [$month];
if ($deptFilter !== '') {
    $sql .= " AND ip.department_id = ?";
    $params[] = $deptFilter;
}

$internFilter = (int)($_GET['intern_id'] ?? 0);
$search       = trim($_GET['q'] ?? '');

// Filter by intern and/or search
if ($internFilter > 0) {
    $sql .= " AND a.intern_id = ?";
    $params[] = $internFilter;
}
if ($search !== '') {
    $sql .= " AND u.full_name LIKE ?";
    $params[] = "%$search%";
}

// Re-run query with intern/search filters applied
$stmt = $pdo->prepare($sql . " ORDER BY a.attendance_date DESC, u.full_name");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Recompute stats after filtering
$totalRecords = count($rows);
$presentCount = 0; $absentCount = 0; $hoursSum = 0; $hoursCount = 0;
foreach ($rows as $r) {
    if (in_array($r['status'], ['present', 'late'], true)) $presentCount++;
    if ($r['status'] === 'absent') $absentCount++;
    if ($r['total_hours'] !== null) { $hoursSum += (float)$r['total_hours']; $hoursCount++; }
}
$avgHours      = $hoursCount > 0 ? round($hoursSum / $hoursCount, 1) : 0;
$attendanceRate = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 1) : 0;

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

// Intern list for dropdown (scoped)
$internListSql = "SELECT ip.intern_id, u.full_name FROM intern_profile ip JOIN user u ON ip.user_id = u.user_id WHERE 1=1";
$internListParams = [];
if ($scopeDeptId !== null) { $internListSql .= " AND ip.department_id = ?"; $internListParams[] = $scopeDeptId; }
elseif ($deptFilter !== '') { $internListSql .= " AND ip.department_id = ?"; $internListParams[] = $deptFilter; }
$internListSql .= " ORDER BY u.full_name";
$internListStmt = $pdo->prepare($internListSql);
$internListStmt->execute($internListParams);
$internList = $internListStmt->fetchAll();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance_' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Intern','Department','Date','Check In','Check Out','Hours','Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['full_name'], $r['department_name'] ?? '', $r['attendance_date'],
            $r['check_in'] ?? '', $r['check_out'] ?? '',
            $r['total_hours'] ?? '', $r['status'],
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Attendance';
$pageSubtitle = 'Monthly attendance across departments';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<!-- Filter card: dropdowns only -->
<div class="card" style="margin-bottom:12px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>" style="width:auto;">
        <?php if ($scopeDeptId === null): ?>
            <select name="department_id" class="form-control" style="width:auto;">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter == $d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="intern_id" class="form-control" style="width:auto;">
            <option value="0">All Interns</option>
            <?php foreach ($internList as $i): ?>
                <option value="<?= (int)$i['intern_id'] ?>" <?= $internFilter === (int)$i['intern_id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($deptFilter || $internFilter || $search): ?>
            <a href="?month=<?= htmlspecialchars($month) ?>" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count + export -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <form method="GET" style="flex:1;display:flex;gap:0;">
        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <?php if ($deptFilter): ?><input type="hidden" name="department_id" value="<?= htmlspecialchars($deptFilter) ?>"><?php endif; ?>
        <?php if ($internFilter): ?><input type="hidden" name="intern_id" value="<?= $internFilter ?>"><?php endif; ?>
        <div class="search-wrap" style="width:100%;max-width:320px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="liveSearch" name="q" class="form-control search-input" placeholder="Search intern name…" value="<?= htmlspecialchars($search) ?>" data-target="attendanceTable" autocomplete="off">
        </div>
    </form>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;"><?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?></span>
    <a href="?<?= http_build_query(array_merge(['month'=>$month,'department_id'=>$deptFilter,'intern_id'=>$internFilter,'q'=>$search],['export'=>'csv'])) ?>"
       style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#E3F5EA;color:#15803D;border:1.5px solid #86EFAC;text-decoration:none;flex-shrink:0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<div class="stat-grid-plain">
    <div class="stat-card">
        <div class="stat-plain-label">Avg Hours/Day</div>
        <div class="stat-plain-value" style="color:var(--navy);"><?= $avgHours ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-plain-label">Attendance Rate</div>
        <div class="stat-plain-value" style="color:#6A4D94;"><?= $attendanceRate ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-plain-label">Absences</div>
        <div class="stat-plain-value" style="color:#DC2626;"><?= $absentCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-plain-label">Total Records</div>
        <div class="stat-plain-value" style="color:var(--navy);"><?= $totalRecords ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-header-title">Attendance records</div></div>
    <div class="table-wrap">
        <table id="attendanceTable" style="min-width:660px;">
            <thead>
                <tr><th>Intern</th><th>Department</th><th>Date</th><th>In</th><th>Out</th><th>Hrs</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-muted">No records for this filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><?= htmlspecialchars($r['department_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['attendance_date']) ?></td>
                        <td><?= timePill($r['check_in']) ?></td>
                        <td><?= timePill($r['check_out']) ?></td>
                        <td><?= $r['total_hours'] !== null ? htmlspecialchars($r['total_hours']) : '' ?></td>
                        <td><?= statusPillFor($attendanceStat, $r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

