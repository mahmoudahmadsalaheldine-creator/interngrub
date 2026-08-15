<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ip.*, u.full_name, u.username, u.email, u.phone, u.status AS user_status, d.department_name,
           sup.full_name AS supervisor_name
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    LEFT JOIN user sup ON ip.supervisor_id = sup.user_id
    WHERE ip.intern_id = ?
");
$stmt->execute([$id]);
$intern = $stmt->fetch();

if (!$intern) {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}

// Managers can't view interns outside their own department, even via direct URL.
$scopeDeptId = managerScopeDeptId();
if ($scopeDeptId !== null && (int)($intern['department_id'] ?? 0) !== $scopeDeptId) {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE intern_id = ? ORDER BY attendance_date DESC LIMIT 15");
$stmt->execute([$id]);
$attendance = $stmt->fetchAll();

// Attendance rate for the progress bar on this profile (same logic as interns.php list).
$rateStmt = $pdo->prepare("
    SELECT ROUND(100 * SUM(status IN ('present','late')) / COUNT(*), 0) AS rate
    FROM attendance
    WHERE intern_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
");
$rateStmt->execute([$id]);
$rate = (int)($rateStmt->fetchColumn() ?: 0);

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

$pageTitle = 'Intern Profile';
$pageSubtitle = 'Full record and attendance history';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<a href="<?= BASE_URL ?>/admin/interns.php" class="back-link">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Back to interns
</a>

<div class="profile-hero">
    <div class="avatar" style="<?= avatarStyle(internColor($intern['intern_id']), 64, 24) ?>"><?= htmlspecialchars(getInitials($intern['full_name'])) ?></div>
    <div style="flex:1;min-width:160px;">
        <div class="profile-hero-name profile-hero-name-lg"><?= htmlspecialchars($intern['full_name']) ?></div>
        <div class="profile-hero-meta"><?= htmlspecialchars($intern['position'] ?? 'No position set') ?> &middot; <?= htmlspecialchars($intern['department_name'] ?? 'No department') ?></div>
    </div>
    <button type="button" class="btn btn-outline" onclick="openModal('editInternModal')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
    </button>
    <button class="btn btn-destructive" onclick="openModal('deleteInternModal')">Delete</button>
</div>

<div class="two-col-grid">
    <div class="card" style="margin-bottom:0;">
        <div class="card-body">
            <div class="card-title">Profile details</div>
            <div class="detail-grid">
                <div>
                    <div class="detail-label">Username</div>
                    <div class="detail-value" style="font-family:monospace;"><?= htmlspecialchars($intern['username'] ?? '') ?></div>
                </div>
                <div>
                    <div class="detail-label">Password</div>
                    <div class="detail-value" style="display:flex;align-items:center;gap:8px;">
                        <span id="pwPlaceholder" style="letter-spacing:3px;color:var(--muted-dark);">••••••••</span>
                        <button type="button" onclick="openModal('editInternModal');document.getElementById('ei_npw').focus();" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;padding:0;">Reset</button>
                    </div>
                </div>
                <div>
                    <div class="detail-label">Phone</div>
                    <div class="detail-value"><?= htmlspecialchars($intern['phone'] ?? '') ?></div>
                </div>
                <div>
                    <div class="detail-label">Supervisor</div>
                    <div class="detail-value"><?= htmlspecialchars($intern['supervisor_name'] ?? '') ?></div>
                </div>
                <div>
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><?= statusPillFor($internStat, $intern['internship_status']) ?></div>
                </div>
                <div>
                    <div class="detail-label">Start Date</div>
                    <div class="detail-value"><?= htmlspecialchars($intern['start_date'] ?? '') ?></div>
                </div>
                <div>
                    <div class="detail-label">End Date</div>
                    <div class="detail-value"><?= htmlspecialchars($intern['end_date'] ?? '') ?></div>
                </div>
            </div>
            <div class="detail-divider">
                <div class="flex justify-between" style="margin-bottom:7px;">
                    <span class="text-sm text-muted">Attendance this month</span>
                    <span class="text-sm font-semibold" style="color:var(--navy);"><?= $rate ?>%</span>
                </div>
                <div class="progress-track-lg"><div class="progress-fill" style="width:<?= $rate ?>%;background:<?= progFill($rate) ?>;"></div></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Recent attendance</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($attendance)): ?>
                        <tr><td colspan="4" class="text-muted">No attendance records.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($attendance as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['attendance_date']) ?></td>
                            <td><?= timePill($a['check_in']) ?></td>
                            <td><?= timePill($a['check_out']) ?></td>
                            <td><?= statusPillFor($attendanceStat, $a['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="deleteInternModal" style="display:none;">
    <div class="modal confirm-modal" style="width:420px;">
        <div class="confirm-icon" style="background:#FCE4E4;color:#DC2626;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
        </div>
        <div class="confirm-title">Delete this intern?</div>
        <div class="confirm-body">You're about to delete <strong style="color:#2D2B6B;"><?= htmlspecialchars($intern['full_name']) ?></strong>. Their records and history will be removed. This can't be undone.</div>
        <div class="confirm-btn-row">
            <button type="button" class="btn btn-outline" onclick="closeModal('deleteInternModal')">Cancel</button>
            <form method="POST" action="<?= BASE_URL ?>/admin/delete_intern.php?id=<?= $id ?>" style="flex:1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="btn btn-danger-solid" style="width:100%;">Delete</button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editInternModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div class="modal-title">Edit intern</div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>/admin/edit_intern.php?id=<?= $id ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($intern['full_name']) ?>" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" id="ei_un" name="username" class="form-control" value="<?= htmlspecialchars($intern['username'] ?? '') ?>" required data-val-required="Username" data-val-username>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($intern['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($intern['position'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>New password <span style="font-weight:400;color:var(--muted-dark);font-size:12px;">(leave blank to keep current)</span></span>
                        <button type="button" onclick="suggestPwFromUsername('ei_un','ei_npw','ei_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="ei_npw" name="new_password" class="form-control" placeholder="New password" style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'ei_strength')" data-val-if-filled data-val-minlen="8" data-val-label="New password">
                        <button type="button" onclick="toggleEye('ei_npw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="ei_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>" <?= $d['department_id'] == $intern['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Internship status</label>
                        <select name="internship_status" id="ei_status" class="form-control">
                            <?php foreach (['active', 'completed', 'terminated', 'on_leave'] as $s): ?>
                                <option value="<?= $s ?>" <?= $intern['internship_status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start date</label>
                        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($intern['start_date'] ?? '') ?>" data-val-date data-val-label="Start date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End date</label>
                        <input type="date" name="end_date" id="ei_end_date" class="form-control" value="<?= htmlspecialchars($intern['end_date'] ?? '') ?>" oninput="autoStatusOnDate(this.value)" data-val-date data-val-label="End date">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Probation End Date <span style="font-size:11px;color:#94A3B8;font-weight:400;">(HR notified when this date passes)</span></label>
                    <input type="date" name="probation_end_date" class="form-control" value="<?= htmlspecialchars($intern['probation_end_date'] ?? '') ?>" data-val-date data-val-label="Probation end date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editInternModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function autoStatusOnDate(val) {
    var sel = document.getElementById('ei_status');
    if (!sel || !val) return;
    var today = new Date(); today.setHours(0,0,0,0);
    var chosen = new Date(val); chosen.setHours(0,0,0,0);
    if (chosen < today) {
        if (sel.value === 'active') sel.value = 'completed';
    } else {
        if (sel.value === 'completed') sel.value = 'active';
    }
}
function toggleEye(inputId, btn) {
    var f = document.getElementById(inputId);
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    btn.innerHTML = show
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
</script>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



