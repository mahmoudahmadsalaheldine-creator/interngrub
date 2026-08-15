<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM task WHERE task_id = ?");
$stmt->execute([$id]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: ' . BASE_URL . '/admin/tasks.php');
    exit;
}

// Managers can only review tasks assigned to interns in their own department.
$scopeDeptId = managerScopeDeptId();
if ($scopeDeptId !== null) {
    $deptCheck = $pdo->prepare("
        SELECT COUNT(*) FROM task_assignment ta
        JOIN intern_profile ip ON ta.intern_id = ip.intern_id
        WHERE ta.task_id = ? AND ip.department_id = ?
    ");
    $deptCheck->execute([$id, $scopeDeptId]);
    if ((int)$deptCheck->fetchColumn() === 0) {
        header('Location: ' . BASE_URL . '/admin/tasks.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $feedback = trim($_POST['feedback'] ?? '');

    $stmt = $pdo->prepare("
        SELECT u.user_id FROM task_assignment ta
        JOIN intern_profile ip ON ta.intern_id = ip.intern_id
        JOIN user u ON ip.user_id = u.user_id
        WHERE ta.task_id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $internUserId = $stmt->fetchColumn();

    if ($action === 'approve') {
        $pdo->prepare("UPDATE task SET task_status = 'done' WHERE task_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE task_assignment SET assignment_status = 'completed' WHERE task_id = ?")->execute([$id]);
        if ($internUserId) {
            $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                ->execute([$internUserId, "Your task \"{$task['title']}\" was approved and marked done."]);
        }
        setToast('Task approved and marked done.');
    } elseif ($action === 'send_back') {
        $pdo->prepare("UPDATE task SET task_status = 'in_progress' WHERE task_id = ?")->execute([$id]);
        if ($feedback !== '') {
            $pdo->prepare("INSERT INTO task_update (task_id, user_id, update_text) VALUES (?, ?, ?)")
                ->execute([$id, $_SESSION['user_id'], "[Feedback] " . $feedback]);
        }
        if ($internUserId) {
            $msg = "Your task \"{$task['title']}\" was sent back to In Progress.";
            if ($feedback !== '') $msg .= " Feedback: " . $feedback;
            $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                ->execute([$internUserId, $msg]);
        }
        setToast('Task sent back to In Progress.', 'info');
    }
    header('Location: ' . BASE_URL . '/admin/tasks.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT tu.*, u.full_name
    FROM task_update tu
    JOIN user u ON tu.user_id = u.user_id
    WHERE tu.task_id = ?
    ORDER BY tu.updated_at ASC
");
$stmt->execute([$id]);
$updates = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.full_name, ip.intern_id FROM task_assignment ta
    JOIN intern_profile ip ON ta.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    WHERE ta.task_id = ?
");
$stmt->execute([$id]);
$assigneeRow = $stmt->fetch();
$assignee = $assigneeRow['full_name'] ?? null;

$pageTitle = 'Review Task';
$pageSubtitle = 'Approve or send work back to the intern';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<a href="<?= BASE_URL ?>/admin/tasks.php" class="back-link">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back to board
</a>

<div class="card" style="max-width:700px;margin-bottom:0;">
    <div class="card-header" style="align-items:flex-start;">
        <div class="flex items-center gap-2" style="font-size:17px;font-weight:700;color:var(--navy);">
            <?= htmlspecialchars($task['title']) ?>
            <?= statusPillFor($taskStat, $task['task_status']) ?>
        </div>
    </div>
    <div class="card-body">
        <div class="flex items-center gap-3 mb-4">
            <?php if (!empty($assigneeRow['intern_id'])): ?>
                <div class="avatar" style="<?= avatarStyle(internColor($assigneeRow['intern_id']), 30, 12) ?>"><?= htmlspecialchars(getInitials($assignee)) ?></div>
            <?php endif; ?>
            <div class="text-sm">Assigned to <span class="font-semibold" style="color:var(--navy);"><?= htmlspecialchars($assignee ?? 'Unassigned') ?></span><?php if ($task['due_date']): ?> &middot; Due <?= htmlspecialchars($task['due_date']) ?><?php endif; ?></div>
        </div>
        <p style="font-size:14px;color:#334155;line-height:22px;margin:0 0 22px;"><?= nl2br(htmlspecialchars($task['description'] ?? 'No description.')) ?></p>

        <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:12px;">Intern updates</div>
        <?php if (empty($updates)): ?>
            <p class="text-sm text-muted" style="margin-bottom:22px;">No updates posted.</p>
        <?php endif; ?>
        <?php foreach ($updates as $i => $u): ?>
            <div style="background:#F7F8FA;border:1px solid #EEF1F4;border-radius:12px;padding:14px;margin-bottom:<?= $i === count($updates) - 1 ? '22px' : '12px' ?>;">
                <div style="margin-bottom:4px;">
                    <span style="font-size:12.5px;font-weight:600;color:var(--navy);"><?= htmlspecialchars($u['full_name']) ?></span>
                    <span style="font-size:12.5px;color:var(--muted-lightest);font-weight:500;"> · <?= htmlspecialchars($u['updated_at']) ?></span>
                </div>
                <div style="font-size:13px;color:var(--muted-dark);line-height:19px;"><?= nl2br(htmlspecialchars($u['update_text'])) ?></div>
            </div>
        <?php endforeach; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="form-group">
                <label class="form-label">Feedback (required if sending back)</label>
                <textarea name="feedback" class="form-control" style="min-height:84px;" rows="3"></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" name="action" value="send_back" class="btn btn-outline">Send back</button>
                <button type="submit" name="action" value="approve" class="btn btn-primary">Approve</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

