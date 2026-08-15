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
if ($scopeDeptId !== null) {
    $deptFilter = (string)$scopeDeptId;
}
$search = trim($_GET['q'] ?? '');
$internIdFilter = (int)($_GET['intern_id'] ?? 0);

$sql = "
    SELECT
        u.full_name,
        u.email,
        d.department_name,
        SUM(a.status = 'present') AS present_days,
        SUM(a.status = 'late') AS late_days,
        SUM(a.status = 'absent') AS absent_days,
        SUM(a.status = 'on_leave') AS leave_days,
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
    LEFT JOIN attendance a ON a.intern_id = ip.intern_id AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
    WHERE 1=1
";
$params = [$month];
if ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($internIdFilter > 0) { $sql .= " AND ip.intern_id = ?"; $params[] = $internIdFilter; }
if ($search !== '') { $sql .= " AND u.full_name LIKE ?"; $params[] = "%$search%"; }
$sql .= " GROUP BY ip.intern_id ORDER BY u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'teaminterngrub_report_' . $month . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Full Name', 'Email', 'Department', 'Present Days', 'Late Days', 'Absent Days', 'Leave Days', 'Avg Hours', 'Tasks Done', 'Tasks Total']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['full_name'],
        $r['email'],
        $r['department_name'] ?? '',
        (int)$r['present_days'],
        (int)$r['late_days'],
        (int)$r['absent_days'],
        (int)$r['leave_days'],
        $r['avg_hours'] !== null ? $r['avg_hours'] : '',
        (int)$r['tasks_done'],
        (int)$r['tasks_total'],
    ]);
}

fclose($out);
exit;

