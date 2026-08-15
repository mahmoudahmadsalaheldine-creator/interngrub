<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$pdo     = getDB();
$error   = '';
$success = '';

// ── Add HR ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hr'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $error = vFirst([
        vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
        vUsername($username),
        vRequired($password, 'Password'), vMinLen($password, 8, 'Password'),
        vEmail($email),
        vMaxLen($phone, 20, 'Phone'),
    ]);
    if ($error === '') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username=?");
        $check->execute([$username]);
        if ($check->fetchColumn() > 0) {
            $error = 'That username is already taken.';
        } else {
            $pdo->prepare("INSERT INTO user (username, full_name, email, phone, password, role, status) VALUES (?,?,?,?,?,'hr','active')")
                ->execute([$username, $fullName, $email ?: null, $phone ?: null, password_hash($password, PASSWORD_DEFAULT)]);
            $success = 'HR account created successfully.';
        }
    }
}

// ── Edit HR ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_hr'])) {
    $uid      = (int)($_POST['user_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $editErr = vFirst([
        vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
        vUsername($username),
        vEmail($email),
        vMaxLen($phone, 20, 'Phone'),
        $password !== '' ? vMinLen($password, 8, 'Password') : '',
    ]);
    if ($editErr !== '') { $error = $editErr; }
    if ($uid > 0 && $editErr === '') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username=? AND user_id!=?");
        $check->execute([$username, $uid]);
        if ($check->fetchColumn() > 0) {
            $error = 'That username is already taken by another account.';
        } else {
            if ($password !== '') {
                $pdo->prepare("UPDATE user SET full_name=?,username=?,email=?,phone=?,password=? WHERE user_id=? AND role='hr'")
                    ->execute([$fullName, $username, $email ?: null, $phone ?: null, password_hash($password, PASSWORD_DEFAULT), $uid]);
            } else {
                $pdo->prepare("UPDATE user SET full_name=?,username=?,email=?,phone=? WHERE user_id=? AND role='hr'")
                    ->execute([$fullName, $username, $email ?: null, $phone ?: null, $uid]);
            }
            $success = 'HR account updated successfully.';
        }
    }
}

// ── Delete HR ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_hr'])) {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0) {
        $pdo->prepare("DELETE FROM user WHERE user_id=? AND role='hr'")->execute([$uid]);
        $success = 'HR account deleted.';
    }
}

// ── Toggle status ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $newSt  = $_POST['new_status'] ?? 'active';
    if ($uid > 0 && in_array($newSt, ['active','inactive'])) {
        $pdo->prepare("UPDATE user SET status=? WHERE user_id=? AND role='hr'")->execute([$newSt, $uid]);
        $success = 'Status updated.';
    }
}

// ── Fetch HR accounts ────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$sql    = "SELECT user_id, username, full_name, email, phone, status, created_at FROM user WHERE role='hr'";
$params = [];
if ($search !== '') {
    $sql    .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $params  = ["%$search%", "%$search%", "%$search%"];
}
$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hrUsers = $stmt->fetchAll();

$pageTitle    = 'HR Accounts';
$pageSubtitle = 'Manage HR user accounts';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Top bar -->
<div class="flex items-center gap-3" style="margin-bottom:18px;">
    <button type="button" class="btn btn-primary" onclick="openModal('addHrModal')" style="font-size:14px;padding:10px 22px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add HR Account</button>
    <span style="font-size:13px;color:var(--muted-dark);"><?= count($hrUsers) ?> account<?= count($hrUsers) !== 1 ? 's' : '' ?></span>
</div>

<!-- Search -->
<div class="card" style="margin-bottom:18px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="liveSearch" name="q" class="form-control search-input" placeholder="Search name, username…" value="<?= htmlspecialchars($search) ?>" data-target="hrTable" autocomplete="off">
        </div>
        <button type="submit" class="btn btn-outline">Search</button>
        <?php if ($search): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header">
        <div class="card-header-title">HR Accounts <span style="font-weight:400;color:var(--muted-dark);font-size:13px;">(<?= count($hrUsers) ?>)</span></div>
    </div>
    <div class="table-wrap">
        <table id="hrTable">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($hrUsers)): ?>
                    <tr><td colspan="7" class="text-muted">No HR accounts found.</td></tr>
                <?php endif; ?>
                <?php foreach ($hrUsers as $u): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php
                            $palette = ['#7B5EA7','#0F766E','#1D4ED8','#B45309','#DC2626'];
                            $av = $palette[crc32($u['full_name']) % count($palette)];
                            $initials = getInitials($u['full_name']);
                            ?>
                            <div style="width:34px;height:34px;border-radius:50%;background:<?= $av ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= htmlspecialchars($initials) ?></div>
                            <span style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($u['full_name']) ?></span>
                        </div>
                    </td>
                    <td class="cell-muted"><?= htmlspecialchars($u['username']) ?></td>
                    <td class="cell-muted"><?= htmlspecialchars($u['phone'] ?? '') ?></td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#E3F5EA;color:#15803D;">
                                <span style="width:6px;height:6px;border-radius:50%;background:#15803D;display:inline-block;"></span> Active
                            </span>
                        <?php else: ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#F1F5F9;color:#64748B;">
                                <span style="width:6px;height:6px;border-radius:50%;background:#94A3B8;display:inline-block;"></span> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;justify-content:center;">
                            <!-- Toggle status -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="toggle_status" value="1">
                                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $u['status']==='active' ? 'inactive' : 'active' ?>">
                                <button type="submit" title="<?= $u['status']==='active' ? 'Deactivate' : 'Activate' ?>"
                                    style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid <?= $u['status']==='active' ? '#BBF7D0' : '#D1FAE5' ?>;background:<?= $u['status']==='active' ? '#F0FDF4' : '#ECFDF5' ?>;color:<?= $u['status']==='active' ? '#16A34A' : '#059669' ?>;cursor:pointer;">
                                    <?php if ($u['status'] === 'active'): ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                    <?php else: ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <!-- Edit -->
                            <button type="button" title="Edit"
                                onclick="openEditModal(<?= (int)$u['user_id'] ?>,<?= htmlspecialchars(json_encode($u['full_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($u['username']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($u['phone']??''),ENT_QUOTES) ?>)"
                                style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #99F6E4;background:#F0FDFA;color:#0F766E;cursor:pointer;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <!-- Delete -->
                            <button type="button" title="Delete"
                                onclick="openDeleteModal(<?= (int)$u['user_id'] ?>,<?= htmlspecialchars(json_encode($u['full_name']),ENT_QUOTES) ?>)"
                                style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #FECACA;background:#FEF2F2;color:#DC2626;cursor:pointer;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Add HR Modal ──────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="addHrModal" style="display:none;">
    <div class="modal" style="width:580px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#EDE8F5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7B5EA7" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;">Add HR Account</div>
                    <div class="modal-subtitle" style="margin-top:2px;">Create a new HR user login</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body" style="padding-top:22px;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Layla Hassan" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" id="add_un" name="username" class="form-control" placeholder="e.g. layla.hr" required data-val-username>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+961 XX XXX XXX">
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>Password <span style="color:#DC2626;">*</span></span>
                        <button type="button" onclick="suggestPwFromUsername('add_un','add_pw','add_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="add_pw" name="password" class="form-control" required placeholder="Min. 8 characters" style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'add_strength')" data-val-required="Password" data-val-minlen="8" data-val-label="Password">
                        <button type="button" onclick="toggleEye('add_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="add_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('addHrModal')">Cancel</button>
                <button type="submit" name="add_hr" value="1" class="btn btn-primary" style="padding:10px 28px;">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit HR Modal ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editHrModal" style="display:none;">
    <div class="modal" style="width:580px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#E0F2FE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0369A1" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;">Edit HR Account</div>
                    <div class="modal-subtitle" style="margin-top:2px;">Leave password blank to keep current</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="edit_hr" value="1">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-body" style="padding-top:22px;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" id="editFullName" class="form-control" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" id="edit_un" name="username" class="form-control" required data-val-username>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="editPhone" class="form-control" placeholder="+961 XX XXX XXX">
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>New Password <span style="font-size:11px;color:#94A3B8;font-weight:400;">(leave blank to keep current)</span></span>
                        <button type="button" onclick="suggestPwFromUsername('edit_un','edit_pw','edit_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="edit_pw" name="password" class="form-control" placeholder="Min. 8 characters" style="padding-right:38px;" oninput="updatePwStrength(this.value,'edit_strength')" data-val-if-filled data-val-minlen="8" data-val-label="Password">
                        <button type="button" onclick="toggleEye('edit_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="edit_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('editHrModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 28px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete HR Modal ───────────────────────────────────────────────────── -->
<div class="modal-overlay" id="deleteHrModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;color:#DC2626;">Delete HR Account</div>
                    <div class="modal-subtitle" style="margin-top:2px;">This action cannot be undone</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="delete_hr" value="1">
            <input type="hidden" name="user_id" id="deleteUserId">
            <div class="modal-body" style="padding-top:20px;">
                <p style="font-size:14px;color:var(--navy);margin:0;">Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
                <p style="font-size:13px;color:#DC2626;margin:10px 0 0;background:#FEF2F2;padding:10px 14px;border-radius:8px;">Their login access will be removed immediately.</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteHrModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;padding:10px 24px;">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, username, phone) {
    document.getElementById('editUserId').value    = id;
    document.getElementById('editFullName').value  = name;
    document.getElementById('edit_un').value       = username;
    document.getElementById('editPhone').value     = phone;
    var pw = document.getElementById('edit_pw');
    pw.value = ''; pw.type = 'password';
    var bar = document.getElementById('edit_strength');
    if (bar) bar.querySelector('div').style.width = '0';
    openModal('editHrModal');
}
function openDeleteModal(id, name) {
    document.getElementById('deleteUserId').value         = id;
    document.getElementById('deleteUserName').textContent = name;
    openModal('deleteHrModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



