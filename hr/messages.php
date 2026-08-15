<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo    = getDB();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $body       = trim($_POST['body'] ?? '');
    $msgErr = vFirst([vRequired($body, 'Message'), vMaxLen($body, 5000, 'Message')]);
    if ($receiverId > 0 && $msgErr === '') {
        $pdo->prepare("INSERT INTO message (sender_id, receiver_id, body) VALUES (?,?,?)")
            ->execute([$userId, $receiverId, $body]);
    }
    header('Location: ' . BASE_URL . '/hr/messages.php?intern_id=' . $receiverId);
    exit;
}

// Mark messages as read when viewing a conversation
$selectedInternUserId = (int)($_GET['intern_id'] ?? 0);
if ($selectedInternUserId > 0) {
    $pdo->prepare("UPDATE message SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?")
        ->execute([$selectedInternUserId, $userId]);
}

// Get all active interns
$stmt = $pdo->prepare("
    SELECT u.user_id, u.full_name,
           (SELECT body FROM message WHERE (sender_id=u.user_id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.user_id) ORDER BY created_at DESC LIMIT 1) AS last_msg,
           (SELECT created_at FROM message WHERE (sender_id=u.user_id AND receiver_id=?) OR (sender_id=? AND receiver_id=u.user_id) ORDER BY created_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM message WHERE sender_id=u.user_id AND receiver_id=? AND is_read=0) AS unread
    FROM user u
    JOIN intern_profile ip ON ip.user_id = u.user_id
    WHERE u.status = 'active' AND ip.internship_status = 'active'
    ORDER BY last_time DESC, u.full_name ASC
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId]);
$interns = $stmt->fetchAll();

// Get conversation
$messages = [];
$selectedIntern = null;
if ($selectedInternUserId > 0) {
    $stmt = $pdo->prepare("SELECT u.full_name FROM user u WHERE u.user_id = ?");
    $stmt->execute([$selectedInternUserId]);
    $selectedIntern = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name AS sender_name
        FROM message m
        JOIN user u ON m.sender_id = u.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$userId, $selectedInternUserId, $selectedInternUserId, $userId]);
    $messages = $stmt->fetchAll();
}

$pageTitle    = 'Messages';
$pageSubtitle = 'Chat with interns';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="msg-sidebar-layout" style="display:grid;grid-template-columns:300px 1fr;gap:0;height:calc(100vh - 120px);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;">

    <!-- Left: intern list -->
    <div style="border-right:1.5px solid var(--border);overflow-y:auto;display:flex;flex-direction:column;">
        <div style="padding:16px;border-bottom:1.5px solid var(--border);font-size:13px;font-weight:700;color:var(--navy);">Interns</div>
        <?php if (empty($interns)): ?>
            <div style="padding:24px;font-size:13px;color:var(--muted-dark);text-align:center;">No active interns.</div>
        <?php endif; ?>
        <?php foreach ($interns as $intern):
            $isSelected = ((int)$intern['user_id'] === $selectedInternUserId);
            $initials = '';
            foreach (explode(' ', $intern['full_name']) as $p) $initials .= strtoupper(substr($p,0,1));
            $initials = substr($initials, 0, 2);
        ?>
        <a href="<?= BASE_URL ?>/hr/messages.php?intern_id=<?= (int)$intern['user_id'] ?>"
           style="display:flex;align-items:center;gap:12px;padding:14px 16px;text-decoration:none;border-bottom:1px solid #F1F5F9;background:<?= $isSelected ? '#F5F3FF' : '#fff' ?>;transition:background .12s;"
           onmouseenter="if(!<?= $isSelected ? 'true' : 'false' ?>)this.style.background='#FAFBFC'" onmouseleave="if(!<?= $isSelected ? 'true' : 'false' ?>)this.style.background='#fff'">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;"><?= htmlspecialchars($initials) ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:<?= $intern['unread'] > 0 ? '700' : '600' ?>;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($intern['full_name']) ?></div>
                <?php if ($intern['last_msg']): ?>
                <div style="font-size:11.5px;color:var(--muted-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($intern['last_msg'], 0, 40, '…')) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($intern['unread'] > 0): ?>
            <span style="min-width:20px;height:20px;border-radius:999px;background:var(--purple);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0;"><?= (int)$intern['unread'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Right: conversation -->
    <div style="display:flex;flex-direction:column;">
        <?php if ($selectedIntern): ?>
        <!-- Header -->
        <div style="padding:14px 20px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:12px;">
            <div style="width:36px;height:36px;border-radius:9px;background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">
                <?php $i=''; foreach(explode(' ',$selectedIntern['full_name']) as $p) $i.=strtoupper(substr($p,0,1)); echo htmlspecialchars(substr($i,0,2)); ?>
            </div>
            <div style="font-size:14px;font-weight:700;color:var(--navy);"><?= htmlspecialchars($selectedIntern['full_name']) ?></div>
        </div>

        <!-- Messages -->
        <div id="msgArea" style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:12px;">
            <?php if (empty($messages)): ?>
                <div style="text-align:center;color:var(--muted-dark);font-size:13px;margin-top:40px;">No messages yet. Say hello!</div>
            <?php endif; ?>
            <?php foreach ($messages as $msg):
                $isMe = ((int)$msg['sender_id'] === $userId);
            ?>
            <div style="display:flex;flex-direction:column;align-items:<?= $isMe ? 'flex-end' : 'flex-start' ?>;">
                <div style="max-width:65%;padding:10px 14px;border-radius:<?= $isMe ? '14px 14px 4px 14px' : '14px 14px 14px 4px' ?>;background:<?= $isMe ? 'var(--purple)' : '#F1F5F9' ?>;color:<?= $isMe ? '#fff' : 'var(--navy)' ?>;font-size:13.5px;line-height:1.5;word-break:break-word;">
                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                </div>
                <div style="font-size:10.5px;color:var(--muted-dark);margin-top:3px;"><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Send form -->
        <div style="padding:14px 20px;border-top:1.5px solid var(--border);">
            <form method="POST" action="" style="display:flex;gap:10px;align-items:flex-end;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="receiver_id" value="<?= (int)$selectedInternUserId ?>">
                <textarea name="body" class="form-control" rows="2" placeholder="Type a message…" style="flex:1;resize:none;min-height:44px;" required onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.closest('form').submit();}"></textarea>
                <button type="submit" name="send_message" value="1" class="btn btn-primary" style="flex-shrink:0;height:44px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send
                </button>
            </form>
        </div>

        <?php else: ?>
        <div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:var(--muted-dark);">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <div style="font-size:14px;">Select an intern to start messaging</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-scroll to bottom of messages
var msgArea = document.getElementById('msgArea');
if (msgArea) msgArea.scrollTop = msgArea.scrollHeight;
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
