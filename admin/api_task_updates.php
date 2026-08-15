<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

header('Content-Type: application/json');

$pdo    = getDB();
$taskId = (int)($_GET['task_id'] ?? 0);

if ($taskId <= 0) { echo '[]'; exit; }

$stmt = $pdo->prepare("
    SELECT tu.update_text, tu.updated_at, u.full_name
    FROM task_update tu
    JOIN user u ON tu.user_id = u.user_id
    WHERE tu.task_id = ?
    ORDER BY tu.updated_at ASC
");
$stmt->execute([$taskId]);
echo json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC));
