<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT intern_id FROM intern_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$internId = $stmt->fetchColumn();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$stmt = $pdo->prepare("
    SELECT * FROM attendance
    WHERE intern_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
    ORDER BY attendance_date DESC
");
$stmt->execute([$internId, $month]);
$rows = $stmt->fetchAll();

$totalRecords = count($rows);
$presentCount = 0;
$absentCount = 0;
$hoursSum = 0;
$hoursCount = 0;
foreach ($rows as $r) {
    if (in_array($r['status'], ['present', 'late'], true)) $presentCount++;
    if ($r['status'] === 'absent') $absentCount++;
    if ($r['total_hours'] !== null) {
        $hoursSum += (float)$r['total_hours'];
        $hoursCount++;
    }
}
$avgHours = $hoursCount > 0 ? round($hoursSum / $hoursCount, 1) : 0;
$attendanceRate = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 1) : 0;

$daysLate = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'late') $daysLate++;
}

$pageTitle = 'My Attendance';
$pageSubtitle = 'Your monthly check-in record';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="stat-grid-plain">
    <div class="stat-card"><div class="stat-plain-label">Days present</div><div class="stat-plain-value" style="color:#6A4D94;"><?= $presentCount ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Late arrivals</div><div class="stat-plain-value" style="color:#B45309;"><?= $daysLate ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Avg hours/day</div><div class="stat-plain-value" style="color:var(--navy);"><?= $avgHours ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Attendance rate</div><div class="stat-plain-value" style="color:#6A4D94;"><?= $attendanceRate ?>%</div></div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-header-title">My attendance records</div>
        <form method="GET" class="flex gap-2">
            <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>" style="width:auto;">
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">Apply</button>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Hours</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-muted">No records for this month.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($r['attendance_date']) ?></td>
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

