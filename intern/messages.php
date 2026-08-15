<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo    = getDB();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

// Send message reply to HR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $body       = trim($_POST['body'] ?? '');
    $msgErr = vFirst([vRequired($body, 'Message'), vMaxLen($body, 5000, 'Message')]);
    if ($receiverId > 0 && $msgErr === '') {
        $pdo->prepare("INSERT INTO message (sender_id, receiver_id, body) VALUES (?,?,?)")
            ->execute([$userId, $receiverId, $body]);
    }
    header('Location: ' . BASE_URL . '/intern/messages.php?hr_id=' . $receiverId);
    exit;
}

// Mark messages as read when viewing a conversation
$selectedHrUserId = (int)($_GET['hr_id'] ?? 0);
if ($selectedHrUserId > 0) {
    $pdo->prepare("UPDATE message SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")
        ->execute([$selectedHrUserId, $userId]);
}

// Get all HR users the intern has conversations with OR all HR users
$hrUsers = $pdo->prepare("
    SELECT u.user_id, u.full_name,
           (SELECT body FROM message WHERE (sender_id=u.user_id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.user_id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
           (SELECT created_at FROM message WHERE (sender_id=u.user_id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.user_id) ORDER BY created_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM message WHERE sender_id=u.user_id AND receiver_id=? AND is_read=0) AS unread
    FROM user u
    WHERE u.role = 'hr' AND u.status = 'active'
    ORDER BY last_time DESC, u.full_name ASC
");
$hrUsers->execute([$userId, $userId, $userId, $userId, $userId]);
$hrUsers = $hrUsers->fetchAll();

// Auto-select if only one HR
if (!$selectedHrUserId && count($hrUsers) === 1) {
    $selectedHrUserId = (int)$hrUsers[0]['user_id'];
    $pdo->prepare("UPDATE message SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")
        ->execute([$selectedHrUserId, $userId]);
}

// Get conversation
$messages = [];
$selectedHr = null;
if ($selectedHrUserId > 0) {
    $stmt = $pdo->prepare("SELECT full_name FROM user WHERE user_id = ?");
    $stmt->execute([$selectedHrUserId]);
    $selectedHr = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name AS sender_name
        FROM message m
        JOIN user u ON m.sender_id = u.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$userId, $selectedHrUserId, $selectedHrUserId, $userId]);
    $messages = $stmt->fetchAll();
}

$pageTitle    = 'Messages';
$pageSubtitle = 'Your messages from HR';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if (count($hrUsers) > 1): ?>
<!-- Two-column layout when multiple HR users exist -->
<div class="msg-sidebar-layout" style="display:grid;grid-template-columns:280px 1fr;gap:0;height:calc(100vh - 120px);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;">

    <!-- Left: HR list -->
    <div style="border-right:1.5px solid var(--border);overflow-y:auto;display:flex;flex-direction:column;">
        <div style="padding:16px;border-bottom:1.5px solid var(--border);font-size:13px;font-weight:700;color:var(--navy);">HR Team</div>
        <?php foreach ($hrUsers as $hr):
            $isSelected = ((int)$hr['user_id'] === $selectedHrUserId);
            $initials = ''; foreach (explode(' ', $hr['full_name']) as $p) $initials .= strtoupper(substr($p,0,1)); $initials = substr($initials,0,2);
        ?>
        <a href="<?= BASE_URL ?>/intern/messages.php?hr_id=<?= (int)$hr['user_id'] ?>"
           style="display:flex;align-items:center;gap:12px;padding:14px 16px;text-decoration:none;border-bottom:1px solid #F1F5F9;background:<?= $isSelected ? '#F5F3FF' : '#fff' ?>;">
            <div style="width:38px;height:38px;border-radius:10px;background:#2D2B6B;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= htmlspecialchars($initials) ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:<?= $hr['unread'] > 0 ? '700' : '600' ?>;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($hr['full_name']) ?></div>
                <?php if ($hr['last_msg']): ?>
                <div style="font-size:11.5px;color:var(--muted-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($hr['last_msg'],0,38,'…')) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($hr['unread'] > 0): ?>
            <span style="min-width:20px;height:20px;border-radius:999px;background:var(--purple);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;"><?= (int)$hr['unread'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Right: conversation -->
    <?php include __DIR__ . '/messages_thread.inc.php'; ?>
</div>

<?php else: ?>
<!-- Single-column layout when one HR or auto-selected -->
<div style="display:flex;flex-direction:column;height:calc(100vh - 120px);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;">
    <?php include __DIR__ . '/messages_thread.inc.php'; ?>
</div>
<?php endif; ?>

<script>
var msgArea = document.getElementById('msgArea');
if (msgArea) msgArea.scrollTop = msgArea.scrollHeight;
document.querySelectorAll('.msg-time[data-utc]').forEach(function(el) {
    var d = new Date(el.dataset.utc.replace(' ', 'T') + 'Z');
    el.textContent = d.toLocaleString('en-US', {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
});
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
