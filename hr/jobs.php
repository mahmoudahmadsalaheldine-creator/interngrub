<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo   = getDB();
$error = '';
$success = '';

// Add job
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_job'])) {
    $title  = trim($_POST['title'] ?? '');
    $deptId = $_POST['department_id'] ?? '';
    $desc   = trim($_POST['description'] ?? '');
    $req    = trim($_POST['requirements'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $error = vFirst([
        vRequired($title, 'Job title'), vMaxLen($title, 150, 'Job title'),
        vMaxLen($desc, 2000, 'Description'), vMaxLen($req, 2000, 'Requirements'),
    ]);
    if ($error === '') {
        $openings = max(1, (int)($_POST['openings'] ?? 1));
        $pdo->prepare("INSERT INTO job (title, department_id, description, requirements, status, created_by, openings) VALUES (?,?,?,?,?,?,?)")
            ->execute([$title, $deptId !== '' ? $deptId : null, $desc ?: null, $req ?: null, $status, $_SESSION['user_id'], $openings]);
        $success = 'Job posted successfully.';
    }
}

// Edit job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_job'])) {
    $id     = (int)$_POST['job_id'];
    $title  = trim($_POST['title'] ?? '');
    $deptId = $_POST['department_id'] ?? '';
    $desc   = trim($_POST['description'] ?? '');
    $req    = trim($_POST['requirements'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $editJobErr = vFirst([
        vRequired($title, 'Job title'), vMaxLen($title, 150, 'Job title'),
        vMaxLen($desc, 2000, 'Description'), vMaxLen($req, 2000, 'Requirements'),
    ]);
    if ($editJobErr !== '') { $error = $editJobErr; }
    elseif ($title !== '') {
        $openings = max(1, (int)($_POST['openings'] ?? 1));
        $pdo->prepare("UPDATE job SET title=?, department_id=?, description=?, requirements=?, status=?, openings=? WHERE job_id=?")
            ->execute([$title, $deptId !== '' ? $deptId : null, $desc ?: null, $req ?: null, $status, $openings, $id]);
        $success = 'Job updated.';
    }
}

// Toggle status
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $stmt = $pdo->prepare("SELECT status FROM job WHERE job_id=?");
    $stmt->execute([(int)$_GET['toggle']]);
    $cur = $stmt->fetchColumn();
    $new = $cur === 'open' ? 'closed' : 'open';
    $pdo->prepare("UPDATE job SET status=? WHERE job_id=?")->execute([$new, (int)$_GET['toggle']]);
    header('Location: ' . BASE_URL . '/hr/jobs.php');
    exit;
}

$search       = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$deptFilter   = $_GET['department_id'] ?? '';
$sql = "SELECT j.*, d.department_name,
    (SELECT COUNT(*) FROM application a WHERE a.job_id=j.job_id AND a.status='new') AS new_count,
    (SELECT COUNT(*) FROM application a WHERE a.job_id=j.job_id AND a.status='first_interview') AS inprogress_count,
    (SELECT COUNT(*) FROM application a WHERE a.job_id=j.job_id AND a.status='accepted') AS recruited_count,
    (SELECT COUNT(*) FROM application a WHERE a.job_id=j.job_id) AS app_count
FROM job j LEFT JOIN department d ON j.department_id=d.department_id WHERE 1=1";
$params = [];
if ($search !== '')       { $sql .= " AND j.title LIKE ?";        $params[] = "%$search%"; }
if ($statusFilter !== '') { $sql .= " AND j.status=?";            $params[] = $statusFilter; }
if ($deptFilter !== '')   { $sql .= " AND j.department_id=?";     $params[] = $deptFilter; }
$sql .= " ORDER BY j.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$jobs = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

$pageTitle     = 'Job Postings';
$pageSubtitle  = 'Manage open and closed positions';
$pageHeaderBtn = '<button type="button" class="btn btn-primary" onclick="openModal(\'addJobModal\')" style="box-shadow:0 4px 12px rgba(123,94,167,.25);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Post Job</button>';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:12px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <select name="status" class="form-control" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="open" <?= $statusFilter==='open'?'selected':'' ?>>Open</option>
            <option value="closed" <?= $statusFilter==='closed'?'selected':'' ?>>Closed</option>
        </select>
        <select name="department_id" class="form-control" style="width:auto;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter===(string)$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($search || $statusFilter || $deptFilter): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count + toggle + post job -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <form method="GET" style="flex:1;display:flex;gap:0;">
        <?php foreach (['status'=>$statusFilter,'department_id'=>$deptFilter] as $k=>$v): ?>
            <?php if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="search-wrap" style="width:100%;max-width:320px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" class="form-control search-input" placeholder="Search jobs…" value="<?= htmlspecialchars($search) ?>">
        </div>
    </form>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;"><?= count($jobs) ?> job<?= count($jobs) !== 1 ? 's' : '' ?></span>
    <div style="display:flex;gap:0;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;flex-shrink:0;">
        <button id="btnList" type="button" onclick="setView('list')" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:background .15s,color .15s;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            List
        </button>
        <button id="btnCards" type="button" onclick="setView('cards')" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;font-size:13px;font-weight:600;border:none;border-left:1.5px solid var(--border);cursor:pointer;transition:background .15s,color .15s;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Cards
        </button>
    </div>
</div>

<!-- List view -->
<div id="viewList" style="display:none;margin-top:18px;">
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Title</th><th>Department</th><th>New</th><th>In Progress</th><th>Recruited</th><th>To Recruit</th><th>Status</th><th>Posted</th><th style="text-align:center;">Actions</th></tr></thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="9" class="text-muted">No jobs found.</td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $j): ?>
                    <tr>
                        <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($j['title']) ?></td>
                        <td class="cell-muted"><?= htmlspecialchars($j['department_name'] ?? '') ?></td>
                        <td><a href="<?= BASE_URL ?>/hr/applications.php?job_id=<?= (int)$j['job_id'] ?>&status=new" style="font-weight:600;color:#1D4ED8;"><?= (int)$j['new_count'] ?></a></td>
                        <td><a href="<?= BASE_URL ?>/hr/applications.php?job_id=<?= (int)$j['job_id'] ?>&status=first_interview" style="font-weight:600;color:#B45309;"><?= (int)$j['inprogress_count'] ?></a></td>
                        <td style="font-weight:600;color:#15803D;"><?= (int)$j['recruited_count'] ?></td>
                        <td style="font-weight:600;color:var(--navy);"><?= (int)($j['openings'] ?? 1) ?></td>
                        <td><?= statusPillFor($hrJobStat, $j['status']) ?></td>
                        <td class="cell-muted"><?= htmlspecialchars(date('M j, Y', strtotime($j['created_at']))) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                <button type="button" class="btn btn-outline btn-sm" title="Edit job"
                                    onclick='openEditJob(<?= htmlspecialchars(json_encode($j), ENT_QUOTES) ?>)'
                                    style="color:#0F766E;border-color:#99F6E4;display:inline-flex;align-items:center;gap:5px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </button>
                                <a href="?toggle=<?= (int)$j['job_id'] ?>" class="btn btn-outline btn-sm"
                                    style="color:<?= $j['status']==='open' ? '#DC2626' : '#15803D' ?>;border-color:<?= $j['status']==='open' ? '#FECACA' : '#BBF7D0' ?>;display:inline-flex;align-items:center;gap:5px;">
                                    <?php if ($j['status']==='open'): ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Close
                                    <?php else: ?>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.33"/></svg>
                                        Reopen
                                    <?php endif; ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- Cards view -->
<div id="viewCards" style="display:none;margin-top:18px;">
<?php if (empty($jobs)): ?>
    <div class="card" style="padding:60px;text-align:center;color:var(--muted-dark);">No jobs found.</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
    <?php foreach ($jobs as $j):
        $isOpen = $j['status'] === 'open';
        $jobPalette = ['#7B5EA7','#0F766E','#1D4ED8','#B45309','#DC2626'];
        $cardAccent = $jobPalette[$j['job_id'] % count($jobPalette)];
    ?>
    <div style="background:#fff;border-radius:14px;border:1.5px solid var(--border);overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .15s;"
         onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow='none'">
        <!-- Accent bar -->
        <div style="height:4px;background:<?= $cardAccent ?>;"></div>
        <!-- Header -->
        <div style="padding:18px 18px 14px;border-bottom:1px solid #F1F5F9;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                <div>
                    <div style="font-weight:700;font-size:15px;color:var(--navy);"><?= htmlspecialchars($j['title']) ?></div>
                    <div style="font-size:12px;color:var(--muted-dark);margin-top:3px;">
                        <?= htmlspecialchars($j['department_name'] ?? 'No department') ?>
                    </div>
                </div>
                <?= statusPillFor($hrJobStat, $j['status']) ?>
            </div>
        </div>
        <!-- Stats -->
        <div style="padding:14px 18px;display:flex;gap:16px;flex:1;">
            <div style="text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#1D4ED8;"><?= (int)$j['new_count'] ?></div>
                <div style="font-size:11px;color:var(--muted-dark);">New</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:20px;font-weight:700;color:#B45309;"><?= (int)$j['inprogress_count'] ?></div>
                <div style="font-size:11px;color:var(--muted-dark);">In Progress</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:20px;font-weight:700;color:var(--navy);"><?= (int)($j['openings'] ?? 1) ?></div>
                <div style="font-size:11px;color:var(--muted-dark);">To Recruit</div>
            </div>
            <div style="text-align:center;margin-left:auto;">
                <div style="font-size:11px;color:var(--muted-dark);">Posted</div>
                <div style="font-size:12px;font-weight:600;color:var(--navy);"><?= date('M j, Y', strtotime($j['created_at'])) ?></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 18px;border-top:1px solid #F1F5F9;display:flex;gap:8px;background:#FAFBFC;">
            <a href="<?= BASE_URL ?>/hr/applications.php?job_id=<?= (int)$j['job_id'] ?>"
               style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EFF6FF;color:#1D4ED8;text-decoration:none;border:1.5px solid #BFDBFE;">
                View Applications
            </a>
            <button type="button" class="btn btn-outline btn-sm"
                onclick='openEditJob(<?= htmlspecialchars(json_encode($j), ENT_QUOTES) ?>)'
                style="color:#0F766E;border-color:#99F6E4;display:inline-flex;align-items:center;gap:5px;font-size:12px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
            </button>
            <a href="?toggle=<?= (int)$j['job_id'] ?>" class="btn btn-outline btn-sm"
                style="color:<?= $isOpen ? '#DC2626' : '#15803D' ?>;border-color:<?= $isOpen ? '#FECACA' : '#BBF7D0' ?>;display:inline-flex;align-items:center;gap:5px;font-size:12px;text-decoration:none;">
                <?= $isOpen ? 'Close' : 'Reopen' ?>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- Add Job Modal -->
<div class="modal-overlay" id="addJobModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div><div class="modal-title">Post a Job</div><div class="modal-subtitle">Create a new open position</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Job Title <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Software Engineer" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" style="min-height:70px;" placeholder="Role overview…" data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Requirements</label>
                    <textarea name="requirements" class="form-control" rows="3" style="min-height:70px;" placeholder="Skills, experience, education…" data-val-maxlen="2000" data-val-label="Requirements"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Openings (To Recruit)</label>
                        <input type="number" name="openings" class="form-control" value="1" min="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addJobModal')">Cancel</button>
                <button type="submit" name="add_job" value="1" class="btn btn-primary">Post Job</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Job Modal -->
<div class="modal-overlay" id="editJobModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div><div class="modal-title">Edit Job</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="job_id" id="ej_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Job Title <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="title" id="ej_title" class="form-control" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="ej_dept" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="ej_desc" class="form-control" rows="3" style="min-height:70px;" data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Requirements</label>
                    <textarea name="requirements" id="ej_req" class="form-control" rows="3" style="min-height:70px;" data-val-maxlen="2000" data-val-label="Requirements"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="ej_status" class="form-control">
                            <option value="open">Open</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Openings (To Recruit)</label>
                        <input type="number" name="openings" id="ej_openings" class="form-control" value="1" min="1">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editJobModal')">Cancel</button>
                <button type="submit" name="edit_job" value="1" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
var JOBS_VIEW_KEY = 'hrJobsView';
function setView(v) { localStorage.setItem(JOBS_VIEW_KEY, v); applyView(v); }
function applyView(v) {
    document.getElementById('viewList').style.display  = v === 'list'  ? 'block' : 'none';
    document.getElementById('viewCards').style.display = v === 'cards' ? 'block' : 'none';
    ['list','cards'].forEach(function(id) {
        var btn = document.getElementById('btn' + id.charAt(0).toUpperCase() + id.slice(1));
        btn.style.background = (v === id) ? 'var(--purple)' : '#fff';
        btn.style.color      = (v === id) ? '#fff' : 'var(--navy)';
    });
}
var _jv = localStorage.getItem(JOBS_VIEW_KEY);
applyView((_jv === 'list' || _jv === 'cards') ? _jv : 'list');

function openEditJob(j) {
    document.getElementById('ej_id').value    = j.job_id;
    document.getElementById('ej_title').value = j.title;
    document.getElementById('ej_dept').value  = j.department_id || '';
    document.getElementById('ej_desc').value  = j.description || '';
    document.getElementById('ej_req').value   = j.requirements || '';
    document.getElementById('ej_status').value  = j.status;
    document.getElementById('ej_openings').value = j.openings || 1;
    openModal('editJobModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



