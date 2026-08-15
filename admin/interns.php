<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_intern'])) {
    $fullName   = trim($_POST['full_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $department = $_POST['department_id'] ?? '';
    $position   = trim($_POST['position'] ?? '');
    $startDate    = $_POST['start_date'] ?? '';
    $endDate      = $_POST['end_date'] ?? '';
    $probationDate = $_POST['probation_end_date'] ?? '';
    $password     = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    if (managerScopeDeptId() !== null) {
        $department = (string)managerScopeDeptId();
    }

    $error = vFirst([
        vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
        vUsername($username),
        vRequired($password, 'Password'), vMinLen($password, 8, 'Password'),
        vEmail($email),
        vMaxLen($phone, 20, 'Phone'),
        vMaxLen($position, 100, 'Position'),
        vDate($startDate, 'Start date'),
        vRequired($endDate, 'End date'), vDate($endDate, 'End date'),
        vDate($probationDate, 'Probation end date'),
    ]);
    if ($error === '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'That username is already taken.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO user (username, full_name, email, password, phone, role, status) VALUES (?, ?, ?, ?, ?, 'intern', 'active')");
                $stmt->execute([$username, $fullName, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $phone]);
                $userId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO intern_profile (user_id, department_id, position, start_date, end_date, probation_end_date, internship_status, supervisor_id) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([
                    $userId,
                    $department !== '' ? $department : null,
                    $position !== '' ? $position : null,
                    $startDate !== '' ? $startDate : null,
                    $endDate !== '' ? $endDate : null,
                    $probationDate !== '' ? $probationDate : null,
                    $_SESSION['user_id'],
                ]);
                $pdo->commit();
                $success = 'Intern added successfully.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to add intern.';
            }
        }
    }
}

// Edit intern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_intern'])) {
    $internId  = (int)($_POST['intern_id'] ?? 0);
    $userId    = (int)($_POST['user_id'] ?? 0);
    $name      = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $deptId    = $_POST['department_id'] ?? '';
    $position  = trim($_POST['position'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';
    $probDate  = $_POST['probation_end_date'] ?? '';
    $status    = $_POST['internship_status'] ?? 'active';
    $editErr = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vDate($startDate, 'Start date'),
        vDate($endDate, 'End date'),
        vDate($probDate, 'Probation end date'),
    ]);
    if ($editErr !== '') {
        setToast($editErr, 'error');
        header('Location: ' . BASE_URL . '/admin/interns.php?' . http_build_query($_GET)); exit;
    }
    if ($internId > 0 && $userId > 0) {
        $pdo->prepare("UPDATE user SET full_name=?,email=?,phone=? WHERE user_id=?")
            ->execute([$name, $email ?: null, $phone ?: null, $userId]);
        $validStat = ['active','completed','terminated','on_leave'];
        $status = in_array($status, $validStat) ? $status : 'active';
        $pdo->prepare("UPDATE intern_profile SET department_id=?,position=?,start_date=?,end_date=?,probation_end_date=?,internship_status=? WHERE intern_id=?")
            ->execute([
                $deptId !== '' ? $deptId : null,
                $position !== '' ? $position : null,
                $startDate !== '' ? $startDate : null,
                $endDate !== '' ? $endDate : null,
                $probDate !== '' ? $probDate : null,
                $status,
                $internId
            ]);
        setToast('Intern updated.', 'success');
    }
    header('Location: ' . BASE_URL . '/admin/interns.php?' . http_build_query($_GET)); exit;
}

$scopeDeptId = managerScopeDeptId();
$search = trim($_GET['q'] ?? '');
$deptFilter = $_GET['department_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
if ($scopeDeptId !== null) {
    $deptFilter = (string)$scopeDeptId;
}

$sql = "
    SELECT ip.intern_id, u.user_id, u.username, u.full_name, u.email, u.phone, u.status, ip.position, ip.internship_status,
           ip.end_date, ip.probation_end_date,
           d.department_id, d.department_name, ip.start_date,
           sup.full_name AS supervisor_name
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    LEFT JOIN user sup ON ip.supervisor_id = sup.user_id
    WHERE 1=1
";
$params = [];
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR d.department_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($deptFilter !== '') {
    $sql .= " AND ip.department_id = ?";
    $params[] = $deptFilter;
}
if ($statusFilter === 'active') {
    $sql .= " AND ip.internship_status = 'active' AND u.status = 'active'";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND (ip.internship_status != 'active' OR u.status = 'inactive')";
}
// '' = All — no extra WHERE
$sql .= " ORDER BY u.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$interns = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

// Attendance rate per intern (for progress bar) — % of present/late out of total records this month
$attRates = [];
$attRows = $pdo->query("
    SELECT intern_id,
           ROUND(100 * SUM(status IN ('present','late')) / COUNT(*), 0) AS rate
    FROM attendance
    WHERE DATE_FORMAT(attendance_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    GROUP BY intern_id
")->fetchAll();
foreach ($attRows as $row) {
    $attRates[$row['intern_id']] = (int)$row['rate'];
}

$totalInternsCount = (int)$pdo->query("SELECT COUNT(*) FROM intern_profile")->fetchColumn();

$pageTitle = 'Interns';
$pageSubtitle = 'Manage every intern in the program';
$showAddInternBtn = true;
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;">
    <!-- Left: filters -->
    <div class="interns-filter-pills" style="display:flex;flex-direction:column;gap:10px;">
        <div class="filter-pills">
            <a href="?department_id=<?= htmlspecialchars($deptFilter) ?>&q=<?= urlencode($search) ?>" class="filter-pill <?= $statusFilter === '' ? 'active' : '' ?>">All Interns</a>
            <a href="?department_id=<?= htmlspecialchars($deptFilter) ?>&q=<?= urlencode($search) ?>&status=active" class="filter-pill <?= $statusFilter === 'active' ? 'active' : '' ?>">Active</a>
            <a href="?department_id=<?= htmlspecialchars($deptFilter) ?>&q=<?= urlencode($search) ?>&status=inactive" class="filter-pill <?= $statusFilter === 'inactive' ? 'active' : '' ?>">Inactive</a>
        </div>
        <?php if ($scopeDeptId === null): ?>
        <div class="filter-pills">
            <a href="?status=<?= htmlspecialchars($statusFilter) ?>&q=<?= urlencode($search) ?>&department_id=" class="filter-pill <?= $deptFilter === '' ? 'active' : '' ?>">All Departments</a>
            <?php foreach ($departments as $d): ?>
                <a href="?status=<?= htmlspecialchars($statusFilter) ?>&q=<?= urlencode($search) ?>&department_id=<?= (int)$d['department_id'] ?>" class="filter-pill <?= (string)$deptFilter === (string)$d['department_id'] ? 'active' : '' ?>"><?= htmlspecialchars($d['department_name']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <!-- Right: search + view toggle -->
    <div style="display:flex;align-items:center;gap:10px;">
        <form method="GET" class="search-wrap">
            <input type="hidden" name="department_id" value="<?= htmlspecialchars($deptFilter) ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="liveSearch" name="q" class="form-control search-input" placeholder="Search interns..." value="<?= htmlspecialchars($search) ?>" data-target="internsTable" data-cards="viewCards" autocomplete="off">
        </form>
        <!-- View toggle — centered icons -->
        <div style="display:flex;align-items:center;border:1px solid var(--border);border-radius:10px;overflow:hidden;">
            <button id="btnCards" onclick="setView('cards')" title="Card view"
                style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border:none;background:#fff;cursor:pointer;color:var(--navy);border-right:1px solid var(--border);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            </button>
            <button id="btnTable" onclick="setView('table')" title="Table view"
                style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;border:none;background:#fff;cursor:pointer;color:var(--navy);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>

<?php if (empty($interns)): ?>
    <p class="text-muted">No interns found.</p>
<?php endif; ?>

<!-- CARD VIEW -->
<div id="viewCards" class="intern-grid">
    <?php foreach ($interns as $i): ?>
        <?php $rate = $attRates[$i['intern_id']] ?? 0; ?>
        <a href="<?= BASE_URL ?>/admin/intern_profile.php?id=<?= (int)$i['intern_id'] ?>" class="intern-card">
            <div class="intern-card-header">
                <div class="avatar" style="<?= avatarStyle(internColor($i['intern_id']), 36, 13) ?>"><?= htmlspecialchars(getInitials($i['full_name'])) ?></div>
                <div class="intern-card-name-block">
                    <div class="intern-card-name"><?= htmlspecialchars($i['full_name']) ?></div>
                    <div class="intern-card-meta"><?= htmlspecialchars($i['position'] ?? 'No position') ?> &middot; <?= htmlspecialchars($i['department_name'] ?? 'No department') ?></div>
                </div>
                <?= statusPillFor($internStat, $i['internship_status']) ?>
            </div>
            <div class="intern-card-progress-row">
                <span class="label">Attendance this month</span>
                <span class="value"><?= $rate ?>%</span>
            </div>
            <div class="progress-track"><div class="progress-fill" style="width:<?= $rate ?>%;background:<?= progFill($rate) ?>;"></div></div>
            <div class="intern-card-footer">
                <?php if (!empty($i['supervisor_name'])): ?>
                    <span class="intern-card-mentor">Mentor &middot; <span><?= htmlspecialchars($i['supervisor_name']) ?></span></span>
                <?php else: ?>
                    <span class="intern-card-mentor">Since <?= htmlspecialchars($i['start_date'] ?? '') ?></span>
                <?php endif; ?>
                <span class="intern-card-since"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15 14"></polyline></svg><?= $rate ?>%</span>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- TABLE VIEW -->
<div id="viewTable" class="card" style="display:none;margin-bottom:0;">
    <div class="table-wrap">
        <table id="internsTable">
            <thead><tr><th>Intern</th><th>Username</th><th>Department</th><th>Position</th><th>Status</th><th>Attendance</th><th>Start Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($interns)): ?>
                    <tr><td colspan="8" class="text-muted">No interns found.</td></tr>
                <?php endif; ?>
                <?php foreach ($interns as $i): ?>
                    <?php $rate = $attRates[$i['intern_id']] ?? 0; ?>
                    <tr>
                        <td>
                            <div class="intern-cell">
                                <div class="avatar" style="<?= avatarStyle(internColor($i['intern_id']), 32, 12) ?>"><?= htmlspecialchars(getInitials($i['full_name'])) ?></div>
                                <span style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($i['full_name']) ?></span>
                            </div>
                        </td>
                        <td style="color:var(--muted-dark);"><?= htmlspecialchars($i['username'] ?? '') ?></td>
                        <td><?= htmlspecialchars($i['department_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($i['position'] ?? '') ?></td>
                        <td><?= statusPillFor($internStat, $i['internship_status']) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-track" style="width:60px;"><div class="progress-fill" style="width:<?= $rate ?>%;background:<?= progFill($rate) ?>;"></div></div>
                                <span style="font-size:12px;color:var(--muted-dark);"><?= $rate ?>%</span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($i['start_date'] ?? '') ?></td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <a href="<?= BASE_URL ?>/admin/intern_profile.php?id=<?= (int)$i['intern_id'] ?>" class="btn btn-outline btn-sm">View</a>
                                <button type="button" class="btn btn-outline btn-sm"
                                    onclick="openEditInternModal(<?= (int)$i['intern_id'] ?>,<?= (int)$i['user_id'] ?>,<?= htmlspecialchars(json_encode($i['full_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['email']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['phone']??''),ENT_QUOTES) ?>,<?= (int)($i['department_id']??0) ?>,<?= htmlspecialchars(json_encode($i['position']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['start_date']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['end_date']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['probation_end_date']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($i['internship_status']),ENT_QUOTES) ?>)"
                                    style="color:#0F766E;border-color:#99F6E4;background:#F0FDFA;">Edit</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var saved = localStorage.getItem('internsView') || 'cards';
    setView(saved, true);
})();
function setView(v, init) {
    document.getElementById('viewCards').style.display = v === 'cards' ? '' : 'none';
    document.getElementById('viewTable').style.display = v === 'table' ? '' : 'none';
    document.getElementById('btnCards').style.background = v === 'cards' ? '#EDE8F5' : '#fff';
    document.getElementById('btnTable').style.background = v === 'table' ? '#EDE8F5' : '#fff';
    if (!init) localStorage.setItem('internsView', v);
}
function suggestPw(pwId, cpwId) {
    var c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%&*';
    var pw = '';
    for (var i = 0; i < 12; i++) pw += c[Math.floor(Math.random() * c.length)];
    var f = document.getElementById(pwId), s = document.getElementById(cpwId);
    f.value = pw; f.type = 'text';
    if (s) s.value = pw;
    setTimeout(function(){ f.type = 'password'; }, 2500);
}
</script>

<div class="modal-overlay" id="addInternModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Add intern</div>
                <div class="modal-subtitle">Create a new intern profile and account</div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Layla Haddad" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" id="ai_un" name="username" class="form-control" placeholder="e.g. layla.h" required data-val-username>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;justify-content:space-between;">
                        <span>Password <span style="color:#DC2626;">*</span></span>
                        <button type="button" onclick="suggestPwFromUsername('ai_un','ai_pw','ai_strength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                    </label>
                    <div style="position:relative;">
                        <input type="password" id="ai_pw" name="password" class="form-control" required placeholder="Min 8 chars" style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'ai_strength')" data-val-required="Password" data-val-minlen="8" data-val-label="Password">
                        <button type="button" onclick="toggleEye('ai_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div id="ai_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                        <input type="email" name="email" class="form-control" placeholder="name@interngrub.co" data-val-email data-val-label="Email">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="+961 XX XXX XXX">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="position" class="form-control" placeholder="e.g. SWE Intern">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start date</label>
                        <input type="date" name="start_date" class="form-control" data-val-date data-val-label="Start date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End date <span style="color:#DC2626;">*</span></label>
                        <input type="date" name="end_date" class="form-control" required data-val-required="End date" data-val-date data-val-label="End date">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Probation end date <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label>
                    <input type="date" name="probation_end_date" class="form-control" data-val-date data-val-label="Probation end date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addInternModal')">Cancel</button>
                <button type="submit" name="add_intern" value="1" class="btn btn-primary">Create intern</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Intern Modal -->
<div class="modal-overlay" id="editInternModal" style="display:none;">
    <div class="modal" style="width:580px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:#E0F2FE;display:flex;align-items:center;justify-content:center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0369A1" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <div class="modal-title">Edit Intern</div>
                    <div class="modal-subtitle">Update intern profile and settings</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="edit_intern" value="1">
            <input type="hidden" name="intern_id" id="ei_intern_id">
            <input type="hidden" name="user_id" id="ei_user_id">
            <div class="modal-body">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:12px;">Personal Info</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" id="ei_name" class="form-control" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="ei_email" class="form-control" data-val-email data-val-label="Email">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="ei_phone" class="form-control" placeholder="+961 XX XXX XXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="position" id="ei_position" class="form-control" placeholder="e.g. SWE Intern">
                    </div>
                </div>
                <div style="border-top:1px solid #F1F5F9;margin:18px 0 16px;"></div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:12px;">Internship Details</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="ei_dept" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="internship_status" id="ei_status" class="form-control">
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="terminated">Terminated</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" id="ei_start" class="form-control" data-val-date data-val-label="Start date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" id="ei_end" class="form-control" data-val-date data-val-label="End date">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Probation End Date <span style="font-size:11px;color:#94A3B8;font-weight:400;">(HR notified when this date passes)</span></label>
                    <input type="date" name="probation_end_date" id="ei_probation" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editInternModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 28px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditInternModal(internId, userId, name, email, phone, deptId, position, startDate, endDate, probDate, status) {
    document.getElementById('ei_intern_id').value = internId;
    document.getElementById('ei_user_id').value   = userId;
    document.getElementById('ei_name').value      = name;
    document.getElementById('ei_email').value     = email;
    document.getElementById('ei_phone').value     = phone;
    document.getElementById('ei_position').value  = position;
    document.getElementById('ei_start').value     = startDate;
    document.getElementById('ei_end').value       = endDate;
    document.getElementById('ei_probation').value = probDate || '';
    document.getElementById('ei_status').value    = status || 'active';
    var deptSel = document.getElementById('ei_dept');
    deptSel.value = deptId ? String(deptId) : '';
    openModal('editInternModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



