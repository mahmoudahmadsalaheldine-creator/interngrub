<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}
csrf_verify();

$id = (int)($_GET['id'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare("SELECT user_id, department_id FROM intern_profile WHERE intern_id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

$scopeDeptId = managerScopeDeptId();
if ($row && $scopeDeptId !== null && (int)($row['department_id'] ?? 0) !== $scopeDeptId) {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}

if ($row) {
    // Deleting the user cascades to intern_profile, attendance, leave_request, task_assignment, etc.
    $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = ?");
    $stmt->execute([$row['user_id']]);
    setToast('Intern deleted.');
}

header('Location: ' . BASE_URL . '/admin/interns.php');
exit;
