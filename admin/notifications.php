<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager', 'hr');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['mark_all_read'])) {
        $pdo->prepare("UPDATE notification SET is_read = 1 WHERE user_id = ?")->execute([$_SESSION['user_id']]);
    } elseif (isset($_POST['mark_read_id'])) {
        $pdo->prepare("UPDATE notification SET is_read = 1 WHERE notification_id = ? AND user_id = ?")
            ->execute([(int)$_POST['mark_read_id'], $_SESSION['user_id']]);
    }
    header('Location: ' . BASE_URL . '/admin/notifications.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM notification WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

// type -> [bg, fg, glyph] for the square notif icon box.
$typeStyle = [
    'leave'      => ['bg' => '#FDECC8', 'fg' => '#B45309'],
    'task'       => ['bg' => '#E0EAFF', 'fg' => '#2563EB'],
    'attendance' => ['bg' => '#E3F5EA', 'fg' => '#6A4D94'],
    'general'    => ['bg' => '#EDE8F5', 'fg' => '#7B5EA7'],
];

$pageTitle = 'Notifications';
$pageSubtitle = 'Everything that needs your attention';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="card" style="max-width:760px;margin-bottom:0;">
    <div class="card-header">
        <div class="card-header-title">Notifications</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <button type="submit" name="mark_all_read" value="1" class="btn btn-outline btn-sm">Mark all read</button>
        </form>
    </div>
    <?php if (empty($notifications)): ?>
        <p class="text-muted" style="padding:20px 22px;">No notifications.</p>
    <?php endif; ?>
    <?php foreach ($notifications as $n): ?>
        <?php $st = $typeStyle[$n['type']] ?? $typeStyle['general']; ?>
        <div class="notif-list-row">
            <div class="notif-icon-box" style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;"><?= htmlspecialchars(strtoupper(substr($n['type'], 0, 1))) ?></div>
            <div style="flex:1;min-width:0;">
                <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="notif-time"><?= htmlspecialchars($n['created_at']) ?></div>
            </div>
            <?php if (!$n['is_read']): ?>
                <span class="notif-unread-dot"></span>
            <?php endif; ?>
            <?php if (!$n['is_read']): ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="mark_read_id" value="<?= (int)$n['notification_id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Mark read</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
