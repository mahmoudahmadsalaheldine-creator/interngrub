<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT intern_id FROM intern_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$internId = $stmt->fetchColumn();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';
    $reason    = trim($_POST['reason'] ?? '');

    $error = vFirst([
        vRequired($startDate, 'Start date'), vDate($startDate, 'Start date'),
        vRequired($endDate, 'End date'),     vDate($endDate, 'End date'),
        vMaxLen($reason, 1000, 'Reason'),
    ]);
    if ($error === '' && strtotime($endDate) < strtotime($startDate)) {
        $error = 'End date cannot be before start date.';
    }
    if ($error === '') {
        $stmt = $pdo->prepare("INSERT INTO leave_request (intern_id, start_date, end_date, reason, approval_status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$internId, $startDate, $endDate, $reason !== '' ? $reason : null]);

        $admins = $pdo->query("SELECT user_id FROM user WHERE role IN ('admin','manager')")->fetchAll();
        foreach ($admins as $a) {
            $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'leave')")
                ->execute([$a['user_id'], $_SESSION['full_name'] . " submitted a leave request ({$startDate} to {$endDate})."]);
        }
        $success = 'Leave request submitted.';
    }
}

$stmt = $pdo->prepare("SELECT * FROM leave_request WHERE intern_id = ? ORDER BY request_date DESC");
$stmt->execute([$internId]);
$leaves = $stmt->fetchAll();

// No leave-balance/allotment policy exists anywhere in this schema, so we don't fabricate
// a fixed annual allowance. We instead show real, derivable metrics: days taken (approved),
// pending requests, and total requests filed.
$daysTaken = 0;
$pendingCount = 0;
foreach ($leaves as $l) {
    if ($l['approval_status'] === 'approved') {
        $daysTaken += (strtotime($l['end_date']) - strtotime($l['start_date'])) / 86400 + 1;
    }
    if ($l['approval_status'] === 'pending') $pendingCount++;
}

$pageTitle = 'Leave Request';
$pageSubtitle = 'Request time off and track decisions';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="flex items-center justify-between gap-3 mb-6" style="flex-wrap:wrap;">
    <span class="text-sm text-muted">Your time-off history and balances</span>
    <button class="btn btn-primary" onclick="openModal('requestLeaveModal')" style="box-shadow:0 6px 14px rgba(123,94,167,.28);">+ Request Leave</button>
</div>

<div class="stat-grid-plain" style="grid-template-columns:repeat(3,1fr);">
    <div class="stat-card"><div class="stat-plain-label">Days taken</div><div class="stat-plain-value" style="color:var(--navy);"><?= (int)$daysTaken ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Pending</div><div class="stat-plain-value" style="color:#B45309;"><?= $pendingCount ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Total requests</div><div class="stat-plain-value" style="color:#6A4D94;"><?= count($leaves) ?></div></div>
</div>

<div class="card">
    <div class="card-header"><div class="card-header-title">My requests</div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>From</th><th>To</th><th>Reason</th><th>Manager note</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($leaves)): ?>
                    <tr><td colspan="5" class="text-muted">No leave requests submitted.</td></tr>
                <?php endif; ?>
                <?php foreach ($leaves as $l): ?>
                    <tr>
                        <td><?= htmlspecialchars($l['start_date']) ?></td>
                        <td><?= htmlspecialchars($l['end_date']) ?></td>
                        <td style="max-width:220px;color:var(--muted-dark);"><?= htmlspecialchars($l['reason'] ?? '') ?></td>
                        <td style="max-width:220px;color:var(--muted-light);"><?= htmlspecialchars($l['supervisor_note'] ?? '') ?></td>
                        <td><?= statusPillFor($leaveStat, $l['approval_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="requestLeaveModal" style="display:none;">
    <div class="modal" style="width:520px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Request leave</div>
                <div class="modal-subtitle">Submit a time-off request to your supervisor</div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">From</label>
                        <input type="date" name="start_date" class="form-control" required data-val-required="From date" data-val-date data-val-label="From date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">To</label>
                        <input type="date" name="end_date" class="form-control" required data-val-required="To date" data-val-date data-val-label="To date">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" style="min-height:84px;" rows="3"></textarea>
                </div>
                <div class="info-banner info-banner-green">Your supervisor will review this request and notify you once it's approved or rejected.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('requestLeaveModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit request</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



