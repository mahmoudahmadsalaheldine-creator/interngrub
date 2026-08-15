<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$pdo  = getDB();
$like = "%$q%";
$results = [];

// Search interns
$stmt = $pdo->prepare("
    SELECT ip.intern_id, u.full_name, u.username,
           ip.position, d.department_name
    FROM user u
    JOIN intern_profile ip ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE u.role = 'intern'
      AND (u.full_name LIKE ? OR u.username LIKE ? OR ip.position LIKE ?)
    LIMIT 5
");
$stmt->execute([$like, $like, $like]);
foreach ($stmt->fetchAll() as $row) {
    $parts    = explode(' ', trim($row['full_name']));
    $initials = implode('', array_map(fn($p) => strtoupper(substr($p, 0, 1)), $parts));
    $sub      = trim(($row['position'] ?? '') . ($row['department_name'] ? ' · ' . $row['department_name'] : ''));
    $results[] = [
        'type'     => 'intern',
        'label'    => $row['full_name'],
        'sub'      => $sub,
        'url'      => BASE_URL . '/admin/intern_profile.php?id=' . (int)$row['intern_id'],
        'initials' => substr($initials, 0, 2),
    ];
}

// Search tasks (joined through task_assignment → intern_profile → user)
$stmt = $pdo->prepare("
    SELECT t.task_id, t.title, t.task_status,
           u.full_name AS intern_name
    FROM task t
    LEFT JOIN task_assignment ta ON ta.task_id = t.task_id AND ta.assignment_status = 'active'
    LEFT JOIN intern_profile ip ON ip.intern_id = ta.intern_id
    LEFT JOIN user u ON u.user_id = ip.user_id
    WHERE t.title LIKE ? OR u.full_name LIKE ?
    GROUP BY t.task_id
    LIMIT 5
");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $row) {
    $statusLabel = ucfirst(str_replace('_', ' ', $row['task_status'] ?? ''));
    $results[] = [
        'type'  => 'task',
        'label' => $row['title'],
        'sub'   => ($row['intern_name'] ? 'Assigned to ' . $row['intern_name'] . ' · ' : '') . $statusLabel,
        'url'   => BASE_URL . '/admin/tasks.php',
    ];
}

echo json_encode($results);
