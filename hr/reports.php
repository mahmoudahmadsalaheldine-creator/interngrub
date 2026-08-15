<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

// Edit candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_candidate'])) {
    $cid   = (int)($_POST['candidate_id'] ?? 0);
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? ''); $phone = trim($_POST['phone'] ?? '');
    $cv    = trim($_POST['cv_link'] ?? ''); $li = trim($_POST['linkedin_profile'] ?? '');
    $rErr  = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vEmail($email), vMaxLen($phone, 20, 'Phone'),
        vUrl($cv, 'CV link'), vUrl($li, 'LinkedIn URL'),
    ]);
    if ($rErr !== '') { setToast($rErr, 'error'); }
    elseif ($cid > 0) {
        $pdo->prepare("UPDATE candidate SET full_name=?,email=?,phone=?,cv_link=?,linkedin_profile=? WHERE candidate_id=?")
            ->execute([$name, $email ?: null, $phone ?: null, $cv ?: null, $li ?: null, $cid]);
        setToast('Candidate updated.');
    }
    header('Location: ' . BASE_URL . '/hr/reports.php?' . http_build_query($_GET)); exit;
}

// Delete candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidate'])) {
    $cid = (int)($_POST['candidate_id'] ?? 0);
    if ($cid > 0) {
        $appIds = $pdo->prepare("SELECT application_id FROM application WHERE candidate_id=?");
        $appIds->execute([$cid]);
        foreach ($appIds->fetchAll() as $row) {
            $aid = (int)$row['application_id'];
            $pdo->prepare("DELETE FROM application_activity WHERE application_id=?")->execute([$aid]);
            $pdo->prepare("DELETE FROM application_note WHERE application_id=?")->execute([$aid]);
            $pdo->prepare("DELETE FROM interview WHERE application_id=?")->execute([$aid]);
        }
        $pdo->prepare("DELETE FROM application WHERE candidate_id=?")->execute([$cid]);
        $pdo->prepare("DELETE FROM candidate WHERE candidate_id=?")->execute([$cid]);
        setToast('Candidate deleted.');
    }
    header('Location: ' . BASE_URL . '/hr/reports.php?' . http_build_query($_GET)); exit;
}

$jobFilter    = (int)($_GET['job_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$deptFilter   = $_GET['department_id'] ?? '';
$dateFrom     = $_GET['date_from'] ?? '';
$dateTo       = $_GET['date_to'] ?? '';
$nameFilter   = trim($_GET['name'] ?? '');

$pipelineStages = [
    'new'             => 'New',
    'first_interview' => 'First Interview',
    'accepted'        => 'Accepted',
    'rejected'        => 'Rejected',
];

$where  = "WHERE 1=1";
$params = [];
if ($jobFilter > 0)       { $where .= " AND a.job_id=?";              $params[] = $jobFilter; }
if ($statusFilter !== '')  { $where .= " AND a.status=?";              $params[] = $statusFilter; }
if ($deptFilter !== '')    { $where .= " AND j.department_id=?";       $params[] = $deptFilter; }
if ($dateFrom !== '')      { $where .= " AND a.application_date>=?";   $params[] = $dateFrom; }
if ($dateTo !== '')        { $where .= " AND a.application_date<=?";   $params[] = $dateTo; }
if ($nameFilter !== '')    { $where .= " AND c.full_name LIKE ?";       $params[] = "%$nameFilter%"; }

$baseSql = "FROM application a JOIN job j ON a.job_id=j.job_id LEFT JOIN department d ON j.department_id=d.department_id JOIN candidate c ON a.candidate_id=c.candidate_id $where";

// Total
$stmt = $pdo->prepare("SELECT COUNT(*) $baseSql"); $stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Count per stage
$counts = [];
foreach ($pipelineStages as $sv => $sl) {
    $st = $pdo->prepare("SELECT COUNT(*) $baseSql AND a.status=?");
    $st->execute(array_merge($params, [$sv]));
    $counts[$sv] = (int)$st->fetchColumn();
}

// Full list — same fields as applications.php
$rows = $pdo->prepare("
    SELECT a.*, c.full_name AS candidate_name, c.email AS candidate_email,
           c.phone, c.cv_link, c.linkedin_profile, c.evaluation,
           j.title AS job_title, d.department_name,
           i.interview_date, i.interview_time, i.result AS interview_result
    FROM application a
    JOIN candidate c ON a.candidate_id=c.candidate_id
    JOIN job j ON a.job_id=j.job_id
    LEFT JOIN department d ON j.department_id=d.department_id
    LEFT JOIN interview i ON i.application_id=a.application_id
    $where
    ORDER BY a.application_date DESC
");
$rows->execute($params);
$applications = $rows->fetchAll();

// Per-job breakdown
$byJob = $pdo->query("
    SELECT j.title,
           COUNT(a.application_id) AS total,
           SUM(a.status='new') AS new_count,
           SUM(a.status='first_interview') AS inprogress,
           SUM(a.status='accepted') AS hired,
           SUM(a.status='rejected') AS refused
    FROM job j
    LEFT JOIN application a ON a.job_id=j.job_id
    GROUP BY j.job_id ORDER BY total DESC
")->fetchAll();

$jobs        = $pdo->query("SELECT job_id, title FROM job ORDER BY title")->fetchAll();
$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

// ── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'hr_report_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['Name','Email','Phone','Position','Department','Applied Date','Status','Progress State','Hire Date','CV Link','LinkedIn']);
    $stageLabels = [
        'new'=>'New','first_interview'=>'First Interview',
        'accepted'=>'Accepted','rejected'=>'Rejected',
    ];
    $stateLabels = ['in_progress'=>'In Progress','normal'=>'In Progress','ready'=>'Ready','terminated'=>'Terminated','blocked'=>'Terminated'];
    foreach ($applications as $a) {
        fputcsv($out, [
            $a['candidate_name'],
            $a['candidate_email'] ?? '',
            $a['phone'] ?? '',
            $a['job_title'],
            $a['department_name'] ?? '',
            $a['application_date'],
            $stageLabels[$a['status']] ?? $a['status'],
            $stateLabels[$a['kanban_state'] ?? 'in_progress'] ?? 'In Progress',
            $a['hire_date'] ?? '',
            $a['cv_link'] ?? '',
            $a['linkedin_profile'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle    = 'Reports';
$pageSubtitle = 'Recruitment analytics';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<!-- Filters -->
<div class="card" style="margin-bottom:12px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <select name="job_id" class="form-control" style="width:auto;">
            <option value="0">All Jobs</option>
            <?php foreach ($jobs as $j): ?>
                <option value="<?= (int)$j['job_id'] ?>" <?= $jobFilter===$j['job_id']?'selected':'' ?>><?= htmlspecialchars($j['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control" style="width:auto;">
            <option value="">All Statuses</option>
            <?php foreach ($pipelineStages as $sv => $sl): ?>
                <option value="<?= $sv ?>" <?= $statusFilter===$sv?'selected':'' ?>><?= htmlspecialchars($sl) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department_id" class="form-control" style="width:auto;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter===(string)$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" class="form-control" style="width:auto;" value="<?= htmlspecialchars($dateFrom) ?>">
        <input type="date" name="date_to"   class="form-control" style="width:auto;" value="<?= htmlspecialchars($dateTo) ?>">
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($jobFilter || $statusFilter || $deptFilter || $dateFrom || $dateTo || $nameFilter): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count + export -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <form method="GET" style="flex:1;display:flex;gap:0;">
        <?php foreach (['job_id'=>$jobFilter,'status'=>$statusFilter,'department_id'=>$deptFilter,'date_from'=>$dateFrom,'date_to'=>$dateTo] as $k=>$v): ?>
            <?php if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="search-wrap" style="width:100%;max-width:320px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="name" class="form-control search-input" placeholder="Search by candidate name…" value="<?= htmlspecialchars($nameFilter) ?>">
        </div>
    </form>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;"><?= count($applications) ?> result<?= count($applications) !== 1 ? 's' : '' ?></span>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
       style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;background:#E3F5EA;color:#15803D;border:1.5px solid #86EFAC;text-decoration:none;flex-shrink:0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
    </a>
</div>

<!-- Summary stat cards -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-top:18px;">
    <div class="stat-card" style="background:#F1F5F9;border:none;">
        <div class="stat-value" style="color:#334155;"><?= $total ?></div>
        <div class="stat-label" style="color:#64748B;">Total Applications</div>
    </div>
    <div class="stat-card" style="background:#EFF6FF;border:none;">
        <div class="stat-value" style="color:#1D4ED8;"><?= $counts['new'] ?></div>
        <div class="stat-label" style="color:#1D4ED8;opacity:.8;">New</div>
    </div>
    <div class="stat-card" style="background:#E3F5EA;border:none;">
        <div class="stat-value" style="color:#15803D;"><?= $counts['accepted'] ?></div>
        <div class="stat-label" style="color:#15803D;opacity:.8;">Accepted</div>
    </div>
    <div class="stat-card" style="background:#FCE4E4;border:none;">
        <div class="stat-value" style="color:#DC2626;"><?= $counts['rejected'] ?></div>
        <div class="stat-label" style="color:#DC2626;opacity:.8;">Rejected</div>
    </div>
</div>

<div class="two-col-grid" style="margin-top:18px;">
    <!-- Per-job breakdown -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Applications by Job</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Position</th><th>Total</th><th>New</th><th>First Interview</th><th>Accepted</th><th>Rejected</th></tr></thead>
                <tbody>
                    <?php if (empty($byJob)): ?>
                        <tr><td colspan="6" class="text-muted">No data.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($byJob as $jb): ?>
                        <tr>
                            <td style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($jb['title']) ?></td>
                            <td style="font-weight:600;"><?= (int)$jb['total'] ?></td>
                            <td style="color:#1D4ED8;font-weight:600;"><?= (int)$jb['new_count'] ?></td>
                            <td style="color:#B45309;font-weight:600;"><?= (int)$jb['inprogress'] ?></td>
                            <td style="color:#15803D;font-weight:600;"><?= (int)$jb['hired'] ?></td>
                            <td style="color:#DC2626;font-weight:600;"><?= (int)$jb['refused'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pipeline breakdown bars -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header"><div class="card-header-title">Pipeline Breakdown</div></div>
        <div class="card-body">
            <?php
            $barColors = [
                'new'             => '#1D4ED8',
                'first_interview' => '#7B5EA7',
                'accepted'        => '#15803D',
                'rejected'        => '#DC2626',
            ];
            foreach ($pipelineStages as $sv => $sl):
                $pct = $total > 0 ? round($counts[$sv] / $total * 100) : 0;
            ?>
            <div style="margin-bottom:12px;">
                <div class="flex justify-between" style="margin-bottom:5px;">
                    <span style="font-size:12px;color:var(--muted-dark);"><?= htmlspecialchars($sl) ?></span>
                    <span style="font-size:12px;font-weight:600;color:var(--navy);"><?= $counts[$sv] ?> <span style="color:var(--muted-lightest);font-weight:400;">(<?= $pct ?>%)</span></span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $barColors[$sv] ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- All Applications table — same style as applications.php -->
<div class="card" style="margin-top:18px;">
    <div class="card-header">
        <div class="card-header-title">All Applications <span style="font-weight:400;color:var(--muted-dark);font-size:13px;">(<?= count($applications) ?>)</span></div>
    </div>
    <div class="table-wrap">
        <table style="min-width:1100px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Position</th>
                    <th>Department</th>
                    <th>Applied</th>
                    <th>Status</th>
                    <th>Progress State</th>
                    <th>Hire Date</th>
                    <th>CV</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                    <tr><td colspan="11" class="text-muted">No results.</td></tr>
                <?php endif; ?>
                <?php foreach ($applications as $a): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$a['candidate_id'] ?>"
                               style="font-weight:600;color:var(--navy);text-decoration:none;"><?= htmlspecialchars($a['candidate_name']) ?></a>
                        </td>
                        <td class="cell-muted"><?= htmlspecialchars($a['candidate_email'] ?? '') ?></td>
                        <td class="cell-muted"><?= htmlspecialchars($a['phone'] ?? '') ?></td>
                        <td style="font-weight:500;"><?= htmlspecialchars($a['job_title']) ?></td>
                        <td class="cell-muted"><?= htmlspecialchars($a['department_name'] ?? '') ?></td>
                        <td class="cell-muted"><?= date('M j, Y', strtotime($a['application_date'])) ?></td>
                        <td><?= statusPillFor($hrAppStat, $a['status']) ?></td>
                        <td>
                            <?php
                            $kc = [
                                'in_progress' => ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'In Progress'],
                                'normal'      => ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'In Progress'],
                                'ready'       => ['bg'=>'#E3F5EA','fg'=>'#15803D','label'=>'Ready'],
                                'terminated'  => ['bg'=>'#FCE4E4','fg'=>'#DC2626','label'=>'Terminated'],
                                'blocked'     => ['bg'=>'#FCE4E4','fg'=>'#DC2626','label'=>'Terminated'],
                            ];
                            $ks = $a['kanban_state'] ?? 'in_progress';
                            $km = $kc[$ks] ?? $kc['in_progress'];
                            ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;background:<?= $km['bg'] ?>;color:<?= $km['fg'] ?>;">
                                <span style="width:6px;height:6px;border-radius:50%;background:<?= $km['fg'] ?>;flex-shrink:0;display:inline-block;"></span>
                                <?= $km['label'] ?>
                            </span>
                        </td>
                        <td>
                            <?= !empty($a['hire_date'])
                                ? '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#E3F5EA;color:#15803D;white-space:nowrap;">' . date('M j, Y', strtotime($a['hire_date'])) . '</span>'
                                : '<span class="cell-muted"></span>' ?>
                        </td>
                        <td>
                            <?php if ($a['cv_link']): ?>
                                <a href="<?= htmlspecialchars($a['cv_link']) ?>" target="_blank" title="Open CV"
                                   style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EDE8F5;color:#7B5EA7;text-decoration:none;border:1.5px solid #D8C9F4;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                    CV
                                </a>
                            <?php else: ?>
                                <span class="cell-muted"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;justify-content:center;">
                                <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$a['candidate_id'] ?>" title="View Profile"
                                   style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EFF6FF;color:#1D4ED8;text-decoration:none;border:1.5px solid #BFDBFE;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    Profile
                                </a>
                                <button type="button" title="Edit candidate"
                                    onclick="openEditModal(<?= (int)$a['candidate_id'] ?>,<?= htmlspecialchars(json_encode($a['candidate_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($a['candidate_email']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($a['phone']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($a['cv_link']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($a['linkedin_profile']??''),ENT_QUOTES) ?>)"
                                    style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #99F6E4;background:#F0FDFA;color:#0F766E;cursor:pointer;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button type="button" title="Delete candidate"
                                    onclick="openDeleteModal(<?= (int)$a['candidate_id'] ?>,<?= htmlspecialchars(json_encode($a['candidate_name']),ENT_QUOTES) ?>)"
                                    style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #FECACA;background:#FEF2F2;color:#DC2626;cursor:pointer;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Candidate Modal -->
<div class="modal-overlay" id="editCandidateModal" style="display:none;">
    <div class="modal" style="width:640px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#E0F2FE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0369A1" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div><div class="modal-title" style="font-size:17px;">Edit Candidate</div><div class="modal-subtitle" style="margin-top:2px;">Update candidate information</div></div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="edit_candidate" value="1">
            <input type="hidden" name="candidate_id" id="editCandidateId">
            <div class="modal-body" style="padding-top:22px;">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" id="editFullName" class="form-control" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control" data-val-email data-val-label="Email">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" id="editPhone" class="form-control" placeholder="+961 XX XXX XXX" oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')">
                    </div>
                    <div class="form-group">
                        <label class="form-label">LinkedIn <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label>
                        <input type="url" name="linkedin_profile" id="editLinkedin" class="form-control" data-val-url data-val-label="LinkedIn URL">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">CV Link</label>
                    <input type="url" name="cv_link" id="editCvLink" class="form-control" data-val-url data-val-label="CV link">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('editCandidateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 28px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Candidate Modal -->
<div class="modal-overlay" id="deleteCandidateModal" style="display:none;">
    <div class="modal" style="width:440px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </div>
                <div><div class="modal-title" style="font-size:17px;color:#DC2626;">Delete Candidate</div><div class="modal-subtitle" style="margin-top:2px;">This action cannot be undone</div></div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="delete_candidate" value="1">
            <input type="hidden" name="candidate_id" id="deleteCandidateId">
            <div class="modal-body" style="padding-top:20px;">
                <p style="font-size:14px;color:var(--navy);margin:0;">Are you sure you want to delete <strong id="deleteCandidateName"></strong>?</p>
                <p style="font-size:13px;color:#DC2626;margin:10px 0 0;background:#FEF2F2;padding:10px 14px;border-radius:8px;">All applications, notes, activities, and interviews will be permanently removed.</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteCandidateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;padding:10px 24px;">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditModal(id,name,email,phone,cv,linkedin){
    document.getElementById('editCandidateId').value=id;
    document.getElementById('editFullName').value=name;
    document.getElementById('editEmail').value=email;
    document.getElementById('editPhone').value=phone;
    document.getElementById('editCvLink').value=cv;
    document.getElementById('editLinkedin').value=linkedin;
    openModal('editCandidateModal');
}
function openDeleteModal(id,name){
    document.getElementById('deleteCandidateId').value=id;
    document.getElementById('deleteCandidateName').textContent=name;
    openModal('deleteCandidateModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



