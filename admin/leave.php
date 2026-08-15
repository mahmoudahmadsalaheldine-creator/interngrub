<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();
$scopeDeptId = managerScopeDeptId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_id'])) {
    csrf_verify();
    $leaveId = (int)$_POST['leave_id'];
    $decision = $_POST['decision'] ?? '';
    $note = trim($_POST['supervisor_note'] ?? '');

    if (in_array($decision, ['approved', 'rejected'], true)) {
        $stmt = $pdo->prepare("
            SELECT lr.*, ip.user_id, ip.department_id
            FROM leave_request lr
            JOIN intern_profile ip ON lr.intern_id = ip.intern_id
            WHERE lr.leave_id = ?
        ");
        $stmt->execute([$leaveId]);
        $leave = $stmt->fetch();

        // Managers can only decide on leave requests from interns in their own department.
        if ($leave && $scopeDeptId !== null && (int)($leave['department_id'] ?? 0) !== $scopeDeptId) {
            $leave = false;
        }

        if ($leave) {
            $stmt = $pdo->prepare("UPDATE leave_request SET approval_status = ?, approved_by = ?, approval_date = NOW(), supervisor_note = ? WHERE leave_id = ?");
            $stmt->execute([$decision, $_SESSION['user_id'], $note !== '' ? $note : null, $leaveId]);

            $msg = "Your leave request (" . $leave['start_date'] . " to " . $leave['end_date'] . ") was {$decision} by " . $_SESSION['full_name'] . ".";
            $stmt = $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'leave')");
            $stmt->execute([$leave['user_id'], $msg]);
            setToast($decision === 'approved' ? 'Leave request approved.' : 'Leave request rejected.', $decision === 'approved' ? 'success' : 'info');
        }
    }
    header('Location: ' . BASE_URL . '/admin/leave.php');
    exit;
}

$filter     = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) $filter = 'pending';
$deptFilter = $_GET['department_id'] ?? '';
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to'] ?? '';
if ($scopeDeptId !== null) $deptFilter = (string)$scopeDeptId;

$countsSql = "
    SELECT lr.approval_status, COUNT(*) AS c
    FROM leave_request lr
    JOIN intern_profile ip ON lr.intern_id = ip.intern_id
    WHERE 1=1
";
$countsParams = [];
if ($scopeDeptId !== null) { $countsSql .= " AND ip.department_id = ?"; $countsParams[] = $scopeDeptId; }
$countsSql .= " GROUP BY lr.approval_status";
$countsStmt = $pdo->prepare($countsSql);
$countsStmt->execute($countsParams);
$counts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$sql = "
    SELECT lr.*, u.full_name, d.department_name
    FROM leave_request lr
    JOIN intern_profile ip ON lr.intern_id = ip.intern_id
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE 1=1
";
$params = [];
if ($scopeDeptId !== null)  { $sql .= " AND ip.department_id = ?"; $params[] = $scopeDeptId; }
elseif ($deptFilter !== '') { $sql .= " AND ip.department_id = ?"; $params[] = $deptFilter; }
if ($filter !== 'all')      { $sql .= " AND lr.approval_status = ?"; $params[] = $filter; }
if ($dateFrom !== '')       { $sql .= " AND lr.start_date >= ?"; $params[] = $dateFrom; }
if ($dateTo !== '')         { $sql .= " AND lr.end_date <= ?"; $params[] = $dateTo; }
$sql .= " ORDER BY lr.request_date DESC";

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll();

$pageTitle = 'Leave Requests';
$pageSubtitle = 'Review and decide on time-off requests';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="stat-grid-plain">
    <div class="stat-card"><div class="stat-plain-label">Pending</div><div class="stat-plain-value" style="color:#B45309;"><?= (int)($counts['pending'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Approved</div><div class="stat-plain-value" style="color:#6A4D94;"><?= (int)($counts['approved'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Rejected</div><div class="stat-plain-value" style="color:#DC2626;"><?= (int)($counts['rejected'] ?? 0) ?></div></div>
    <div class="stat-card"><div class="stat-plain-label">Total</div><div class="stat-plain-value" style="color:var(--navy);"><?= array_sum($counts) ?></div></div>
</div>

<!-- Filter card: dropdowns + dates -->
<div class="card" style="margin-bottom:12px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>">
        <?php if ($scopeDeptId === null): ?>
            <select name="department_id" class="form-control" style="width:auto;">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter === (string)$d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input type="date" name="date_from" class="form-control" style="width:auto;" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="From">
        <input type="date" name="date_to"   class="form-control" style="width:auto;" value="<?= htmlspecialchars($dateTo) ?>"   placeholder="To">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($deptFilter || $dateFrom || $dateTo): ?>
            <a href="?status=<?= htmlspecialchars($filter) ?>" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <div class="search-wrap" style="flex:1;max-width:320px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="liveSearch" class="form-control search-input" placeholder="Search intern name…" data-target="leaveTable" autocomplete="off">
    </div>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;margin-left:auto;"><?= count($leaves) ?> request<?= count($leaves) !== 1 ? 's' : '' ?></span>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-header-title">Leave requests</div>
        <div class="filter-pills">
            <?php foreach (['pending', 'approved', 'rejected', 'all'] as $f): ?>
                <a href="?status=<?= $f ?>" class="filter-pill <?= $filter === $f ? 'active' : '' ?>" style="padding:6px 13px;"><?= ucfirst($f) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-wrap">
        <table id="leaveTable" style="min-width:620px;">
            <thead><tr><th>Intern</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($leaves)): ?>
                    <tr><td colspan="6" class="text-muted">No leave requests found.</td></tr>
                <?php endif; ?>
                <?php foreach ($leaves as $l): ?>
                    <tr>
                        <td>
                            <div class="intern-cell">
                                <div class="avatar" style="<?= avatarStyle(internColor($l['intern_id']), 30, 11.5) ?>"><?= htmlspecialchars(getInitials($l['full_name'])) ?></div>
                                <span class="font-semibold" style="color:var(--navy);"><?= htmlspecialchars($l['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($l['start_date']) ?></td>
                        <td><?= htmlspecialchars($l['end_date']) ?></td>
                        <td style="max-width:260px;"><?= htmlspecialchars($l['reason'] ?? '') ?></td>
                        <td><?= statusPillFor($leaveStat, $l['approval_status']) ?></td>
                        <td>
                            <?php if ($l['approval_status'] === 'pending'): ?>
                                <button class="btn btn-outline btn-sm" onclick="openReview(<?= (int)$l['leave_id'] ?>, '<?= htmlspecialchars($l['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($l['start_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($l['end_date'], ENT_QUOTES) ?>', '<?= htmlspecialchars($l['reason'] ?? '', ENT_QUOTES) ?>')">Review</button>
                            <?php else: ?>
                                <span class="text-muted text-sm"><?= htmlspecialchars($l['supervisor_note'] ?? '') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="reviewModal" style="display:none;">
    <div class="modal" style="width:520px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Review leave request</div>
                <div class="modal-subtitle">Approve or reject this time-off request</div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="leave_id" id="reviewLeaveId">
            <div class="modal-body">
                <div class="flex gap-3 mb-4" style="align-items:center;">
                    <div class="avatar" style="width:42px;height:42px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;background:var(--purple);flex-shrink:0;" id="reviewAvatar"></div>
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--navy);" id="reviewName"></div>
                        <div style="font-size:12.5px;color:var(--muted-light);">Leave request</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="info-chip">
                        <div class="info-chip-label">From</div>
                        <div class="info-chip-value" id="reviewFrom"></div>
                    </div>
                    <div class="info-chip">
                        <div class="info-chip-label">To</div>
                        <div class="info-chip-value" id="reviewTo"></div>
                    </div>
                </div>
                <div class="info-chip mb-4">
                    <div class="info-chip-label">Reason</div>
                    <div style="font-size:13.5px;color:var(--muted-dark);line-height:19px;" id="reviewReason"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Note to intern (optional)</label>
                    <textarea name="supervisor_note" class="form-control" style="min-height:64px;" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="decision" value="rejected" class="btn btn-destructive">Reject</button>
                <button type="submit" name="decision" value="approved" class="btn btn-primary">Approve</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReview(id, name, start, end, reason) {
    document.getElementById('reviewLeaveId').value = id;
    document.getElementById('reviewName').textContent = name;
    document.getElementById('reviewAvatar').textContent = name.split(' ').slice(0,2).map(function(w){return w[0]||'';}).join('').toUpperCase();
    document.getElementById('reviewFrom').textContent = start;
    document.getElementById('reviewTo').textContent = end;
    document.getElementById('reviewReason').textContent = reason || 'No reason provided.';
    openModal('reviewModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



