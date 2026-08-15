<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$pdo = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['add_department'])) {
        $name = trim($_POST['department_name'] ?? '');
        if ($name === '') {
            $error = 'Department name is required.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO department (department_name) VALUES (?)");
            $stmt->execute([$name]);
            $success = 'Department added.';
        }
    } elseif (isset($_POST['edit_department'])) {
        $id   = (int)($_POST['department_id'] ?? 0);
        $name = trim($_POST['department_name'] ?? '');
        if ($name === '') {
            $error = 'Department name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE department SET department_name = ? WHERE department_id = ?");
            $stmt->execute([$name, $id]);
            $success = 'Department updated.';
        }
    } elseif (isset($_POST['delete_department'])) {
        $id = (int)($_POST['department_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM department WHERE department_id = ?");
        $stmt->execute([$id]);
        $success = 'Department deleted.';
    } elseif (isset($_POST['delete_manager'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM user WHERE user_id = ? AND role = 'manager'")->execute([$userId]);
        $success = 'Manager deleted.';
    } elseif (isset($_POST['assign_manager_dept'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $deptId = $_POST['department_id'] ?? '';
        $stmt = $pdo->prepare("UPDATE user SET department_id = ? WHERE user_id = ? AND role = 'manager'");
        $stmt->execute([$deptId !== '' ? $deptId : null, $userId]);
        $success = 'Manager department updated.';
    } elseif (isset($_POST['add_manager'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $deptId   = $_POST['department_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $username === '' || $password === '') {
            $error = 'Full name, username, and password are required.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'That username is already taken.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO user (username, full_name, password, phone, role, department_id, status) VALUES (?, ?, ?, ?, 'manager', ?, 'active')");
                $stmt->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT), $phone, $deptId !== '' ? $deptId : null]);
                $success = 'Manager added successfully.';
            }
        }
    } elseif (isset($_POST['edit_manager'])) {
        $userId    = (int)($_POST['user_id'] ?? 0);
        $fullName  = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $username === '') {
            $error = 'Full name and username are required.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ? AND user_id != ?");
            $stmt->execute([$username, $userId]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'That username is already taken by another account.';
            } else {
                if ($newPw !== '' && strlen($newPw) >= 6) {
                    $hashed = password_hash($newPw, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE user SET full_name = ?, username = ?, phone = ?, password = ? WHERE user_id = ? AND role = 'manager'");
                    $stmt->execute([$fullName, $username, $phone, $hashed, $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE user SET full_name = ?, username = ?, phone = ? WHERE user_id = ? AND role = 'manager'");
                    $stmt->execute([$fullName, $username, $phone, $userId]);
                }
                $success = 'Manager updated.';
            }
        }
    }
}

$departments = $pdo->query("
    SELECT d.department_id, d.department_name,
           (SELECT COUNT(*) FROM intern_profile ip WHERE ip.department_id = d.department_id) AS intern_count,
           (SELECT COUNT(*) FROM user u WHERE u.department_id = d.department_id AND u.role = 'manager') AS manager_count
    FROM department d
    ORDER BY d.department_name
")->fetchAll();

$managers = $pdo->query("
    SELECT u.user_id, u.username, u.full_name, u.email, u.phone, u.department_id
    FROM user u
    WHERE u.role = 'manager'
    ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Departments';
$pageSubtitle = 'Manage departments and their assigned managers';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-header-title">All departments</div>
        <div class="flex items-center gap-2">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="deptSearch" class="form-control search-input" placeholder="Search departments..." oninput="filterDepts()">
            </div>
            <select id="deptFilterStatus" class="form-control" style="width:auto;" onchange="filterDepts()">
                <option value="">All</option>
                <option value="has_interns">Has interns</option>
                <option value="has_managers">Has managers</option>
                <option value="empty">Empty</option>
            </select>
            <button type="button" class="btn btn-primary" onclick="openModal('addDeptModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Department</button>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table" id="deptTable">
            <thead><tr><th>Department</th><th>Interns</th><th>Managers</th><th style="width:100px;">Actions</th></tr></thead>
            <tbody>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="4" class="text-muted">No departments yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($departments as $d): ?>
                    <tr data-name="<?= htmlspecialchars(strtolower($d['department_name']), ENT_QUOTES) ?>"
                        data-interns="<?= (int)$d['intern_count'] ?>"
                        data-managers="<?= (int)$d['manager_count'] ?>">
                        <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($d['department_name']) ?></td>
                        <td><?= (int)$d['intern_count'] ?></td>
                        <td><?= (int)$d['manager_count'] ?></td>
                        <td>
                            <div class="flex gap-2">
                                <button type="button" title="Edit"
                                        style="width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--muted-dark);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                        onclick="openEditDept('<?= (int)$d['department_id'] ?>', '<?= htmlspecialchars($d['department_name'], ENT_QUOTES) ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button type="button" title="Delete"
                                        style="width:32px;height:32px;border:1px solid #FECACA;border-radius:8px;background:#FEF2F2;color:#DC2626;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                        onclick="openDeleteDept('<?= (int)$d['department_id'] ?>', '<?= htmlspecialchars($d['department_name'], ENT_QUOTES) ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="deptNoResults" style="display:none;padding:16px 22px;color:var(--muted-light);font-size:13.5px;">No departments match your search.</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-header-title">Managers</div>
        <div class="flex items-center gap-2">
            <div class="search-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="mgrSearch" class="form-control search-input" placeholder="Search managers..." oninput="filterManagers()">
            </div>
            <select id="mgrFilterDept" class="form-control" style="width:auto;" onchange="filterManagers()">
                <option value="">All departments</option>
                <option value="unassigned">Unassigned</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary" onclick="openModal('addManagerModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Manager</button>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table" id="mgrTable">
            <thead><tr><th>Manager</th><th>Username</th><th>Phone</th><th style="width:220px;">Department</th><th style="width:90px;">Actions</th></tr></thead>
            <tbody>
                <?php if (empty($managers)): ?>
                    <tr><td colspan="5" class="text-muted">No manager accounts yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($managers as $m): ?>
                    <tr data-name="<?= htmlspecialchars(strtolower($m['full_name']), ENT_QUOTES) ?>"
                        data-dept="<?= $m['department_id'] !== null ? (int)$m['department_id'] : 'unassigned' ?>">
                        <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($m['full_name']) ?></td>
                        <td style="color:var(--muted-dark);"><?= htmlspecialchars($m['username'] ?? '') ?></td>
                        <td><?= htmlspecialchars($m['phone'] ?? '') ?></td>
                        <td>
                            <form method="POST" action="" class="flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                                <select name="department_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
                                    <option value="">Unassigned (sees everything)</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['department_id'] ?>" <?= (string)$m['department_id'] === (string)$d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="assign_manager_dept" value="1">
                            </form>
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button type="button" title="Edit"
                                        style="width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--muted-dark);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                        onclick="openEditMgr(<?= (int)$m['user_id'] ?>, '<?= htmlspecialchars($m['full_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($m['username'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($m['phone'] ?? '', ENT_QUOTES) ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button type="button" title="Delete"
                                        style="width:32px;height:32px;border:1px solid #FECACA;border-radius:8px;background:#FEF2F2;color:#DC2626;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;"
                                        onclick="openDeleteMgr(<?= (int)$m['user_id'] ?>, '<?= htmlspecialchars($m['full_name'], ENT_QUOTES) ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="mgrNoResults" style="display:none;padding:16px 22px;color:var(--muted-light);font-size:13.5px;">No managers match your search.</div>
    </div>
</div>

<!-- Add Manager Modal -->
<div class="modal-overlay" id="addManagerModal" style="display:none;">
    <div class="modal" style="width:480px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Add manager</div>
                <div class="modal-subtitle">Create a new manager account</div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Daniel Cruz" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" id="am_un" name="username" class="form-control" placeholder="e.g. daniel.c" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;justify-content:space-between;">
                        <span>Password <span style="color:#DC2626;">*</span></span>
                        <button type="button" onclick="suggestPwFromUsername('am_un','am_pw','am_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="am_pw" name="password" class="form-control" required placeholder="Min 8 chars" style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'am_strength')">
                        <button type="button" onclick="toggleEye('am_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="am_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
                <div class="form-row" style="margin-bottom:0;">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="+1 415 555 0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-control">
                            <option value="">Unassigned</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addManagerModal')">Cancel</button>
                <button type="submit" name="add_manager" value="1" class="btn btn-primary">Add Manager</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Manager Modal -->
<div class="modal-overlay" id="editManagerModal" style="display:none;">
    <div class="modal" style="width:440px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Edit manager</div>
                <div class="modal-subtitle">Update profile information</div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="user_id" id="editMgrUserId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" id="editMgrFullName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="username" id="editMgrUsername" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="editMgrPhone" class="form-control">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>New password <span style="font-weight:400;color:var(--muted-dark);font-size:12px;">(leave blank to keep current)</span></span>
                        <button type="button" onclick="suggestPwFromUsername('editMgrUsername','em_pw','em_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="em_pw" name="new_password" class="form-control" placeholder="New password" style="padding-right:38px;" oninput="updatePwStrength(this.value,'em_strength')">
                        <button type="button" onclick="toggleEye('em_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="em_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editManagerModal')">Cancel</button>
                <button type="submit" name="edit_manager" value="1" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Manager Confirm Modal -->
<div class="modal-overlay" id="deleteMgrModal" style="display:none;">
    <div class="modal confirm-modal" style="width:400px;">
        <div class="confirm-icon" style="background:#FCE4E4;color:#DC2626;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
        </div>
        <div class="confirm-title">Delete manager?</div>
        <div class="confirm-body" id="deleteMgrBody">This will permanently remove the manager account.</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="user_id" id="deleteMgrId">
            <div class="confirm-btn-row">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteMgrModal')">Cancel</button>
                <button type="submit" name="delete_manager" value="1" class="btn btn-danger-solid">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal-overlay" id="addDeptModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div class="modal-title">Add department</div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Department name</label>
                    <input type="text" name="department_name" class="form-control" placeholder="e.g. Customer Success" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addDeptModal')">Cancel</button>
                <button type="submit" name="add_department" value="1" class="btn btn-primary">Add Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal-overlay" id="editDeptModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div class="modal-title">Edit department</div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="department_id" id="editDeptId">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Department name</label>
                    <input type="text" name="department_name" id="editDeptName" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editDeptModal')">Cancel</button>
                <button type="submit" name="edit_department" value="1" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Department Modal -->
<div class="modal-overlay" id="deleteDeptModal" style="display:none;">
    <div class="modal confirm-modal" style="width:420px;">
        <div class="confirm-icon" style="background:#FCE4E4;color:#DC2626;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
        </div>
        <div class="confirm-title">Delete department?</div>
        <div class="confirm-body" id="deleteDeptBody">Interns and managers in this department will have their department cleared, not deleted. This can't be undone.</div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="department_id" id="deleteDeptId">
            <div class="confirm-btn-row">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteDeptModal')">Cancel</button>
                <button type="submit" name="delete_department" value="1" class="btn btn-danger-solid">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditDept(id, name) {
    document.getElementById('editDeptId').value = id;
    document.getElementById('editDeptName').value = name;
    openModal('editDeptModal');
}
function openDeleteDept(id, name) {
    document.getElementById('deleteDeptId').value = id;
    document.getElementById('deleteDeptBody').textContent = 'Delete "' + name + '"? Interns and managers in this department will have their department cleared, not deleted. This can\'t be undone.';
    openModal('deleteDeptModal');
}

function filterDepts() {
    var q = document.getElementById('deptSearch').value.toLowerCase();
    var status = document.getElementById('deptFilterStatus').value;
    var rows = document.querySelectorAll('#deptTable tbody tr[data-name]');
    var visible = 0;
    rows.forEach(function(row) {
        var name = row.dataset.name;
        var interns = parseInt(row.dataset.interns);
        var managers = parseInt(row.dataset.managers);
        var matchQ = name.indexOf(q) !== -1;
        var matchStatus = status === '' ||
            (status === 'has_interns' && interns > 0) ||
            (status === 'has_managers' && managers > 0) ||
            (status === 'empty' && interns === 0 && managers === 0);
        row.style.display = (matchQ && matchStatus) ? '' : 'none';
        if (matchQ && matchStatus) visible++;
    });
    document.getElementById('deptNoResults').style.display = visible === 0 ? '' : 'none';
}

function openEditMgr(id, name, username, phone) {
    document.getElementById('editMgrUserId').value = id;
    document.getElementById('editMgrFullName').value = name;
    document.getElementById('editMgrUsername').value = username;
    document.getElementById('editMgrPhone').value = phone;
    openModal('editManagerModal');
}
function openDeleteMgr(id, name) {
    document.getElementById('deleteMgrId').value = id;
    document.getElementById('deleteMgrBody').textContent = 'Delete "' + name + '"? This will permanently remove the manager account and cannot be undone.';
    openModal('deleteMgrModal');
}
function suggestPwFromUsername(unId, pwId, strengthId) {
    var un = (document.getElementById(unId) ? document.getElementById(unId).value.trim() : '') || 'manager';
    var base = un.charAt(0).toUpperCase() + un.slice(1).replace(/[^a-zA-Z0-9]/g, '');
    var suffix = Math.floor(100 + Math.random() * 900);
    var specials = ['!', '@', '#', '$', '&'];
    var pw = base + suffix + specials[Math.floor(Math.random() * specials.length)];
    var f = document.getElementById(pwId);
    f.value = pw; f.type = 'text';
    var eyeSvgOff = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    var btn = f.parentElement && f.parentElement.querySelector('button[onclick^="toggleEye"]');
    if (btn) btn.innerHTML = eyeSvgOff;
    if (strengthId) updatePwStrength(pw, strengthId);
}
function updatePwStrength(pw, barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;
    var fill = bar.querySelector('div');
    if (!fill) return;
    var score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    var colors = ['#DC2626','#F59E0B','#16A34A','#7B5EA7'];
    var widths = ['25%','50%','75%','100%'];
    fill.style.width = pw.length === 0 ? '0' : widths[score - 1] || '25%';
    fill.style.background = pw.length === 0 ? '' : colors[score - 1] || '#DC2626';
}
function toggleEye(inputId, btn) {
    var f = document.getElementById(inputId);
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    btn.innerHTML = show
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
function filterManagers() {
    var q = document.getElementById('mgrSearch').value.toLowerCase();
    var dept = document.getElementById('mgrFilterDept').value;
    var rows = document.querySelectorAll('#mgrTable tbody tr[data-name]');
    var visible = 0;
    rows.forEach(function(row) {
        var matchQ = row.dataset.name.indexOf(q) !== -1;
        var matchDept = dept === '' ||
            (dept === 'unassigned' && row.dataset.dept === 'unassigned') ||
            (dept !== 'unassigned' && row.dataset.dept === dept);
        row.style.display = (matchQ && matchDept) ? '' : 'none';
        if (matchQ && matchDept) visible++;
    });
    document.getElementById('mgrNoResults').style.display = visible === 0 ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



