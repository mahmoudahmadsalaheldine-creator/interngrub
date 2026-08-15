<?php /* Shared conversation thread panel for intern/messages.php */ ?>
<div style="display:flex;flex-direction:column;flex:1;min-height:0;">
    <?php if ($selectedHr): ?>

    <!-- Header -->
    <div style="padding:14px 20px;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;">
        <?php $i=''; foreach(explode(' ',$selectedHr['full_name']) as $p) $i.=strtoupper(substr($p,0,1)); ?>
        <div style="width:36px;height:36px;border-radius:9px;background:#2D2B6B;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;"><?= htmlspecialchars(substr($i,0,2)) ?></div>
        <div>
            <div style="font-size:14px;font-weight:700;color:var(--navy);"><?= htmlspecialchars($selectedHr['full_name']) ?></div>
            <div style="font-size:11px;color:var(--muted-dark);">HR</div>
        </div>
    </div>

    <!-- Messages -->
    <div id="msgArea" style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:12px;min-height:0;">
        <?php if (empty($messages)): ?>
            <div style="text-align:center;color:var(--muted-dark);font-size:13px;margin-top:60px;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.25;display:block;margin:0 auto 10px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                No messages yet.
            </div>
        <?php endif; ?>
        <?php foreach ($messages as $msg):
            $isMe = ((int)$msg['sender_id'] === $userId);
        ?>
        <div style="display:flex;flex-direction:column;align-items:<?= $isMe ? 'flex-end' : 'flex-start' ?>;">
            <div style="max-width:65%;padding:10px 14px;border-radius:<?= $isMe ? '14px 14px 4px 14px' : '14px 14px 14px 4px' ?>;background:<?= $isMe ? 'var(--purple)' : '#F1F5F9' ?>;color:<?= $isMe ? '#fff' : 'var(--navy)' ?>;font-size:13.5px;line-height:1.5;word-break:break-word;">
                <?= nl2br(htmlspecialchars($msg['body'])) ?>
            </div>
            <div style="font-size:10.5px;color:var(--muted-dark);margin-top:3px;" class="msg-time" data-utc="<?= htmlspecialchars($msg['created_at']) ?>"></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Send form -->
    <div style="padding:14px 20px;border-top:1.5px solid var(--border);flex-shrink:0;">
        <form method="POST" action="" style="display:flex;gap:10px;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="receiver_id" value="<?= (int)$selectedHrUserId ?>">
            <textarea name="body" class="form-control" rows="2" placeholder="Reply to HR…" style="flex:1;resize:none;min-height:44px;" required onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.closest('form').querySelector('button[type=submit]').click();}"></textarea>
            <button type="submit" name="send_message" value="1" class="btn btn-primary" style="flex-shrink:0;height:44px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send
            </button>
        </form>
    </div>

    <?php else: ?>
    <div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:var(--muted-dark);">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <div style="font-size:14px;">No messages from HR yet.</div>
    </div>
    <?php endif; ?>
</div>
