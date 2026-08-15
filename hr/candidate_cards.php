<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

// Add candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_candidate'])) {
    $name    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $cv      = trim($_POST['cv_link'] ?? '');
    $jobId   = (int)($_POST['job_id'] ?? 0);
    $appDate = $_POST['application_date'] ?? date('Y-m-d');
    $err = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vEmail($email), vMaxLen($phone, 20, 'Phone'),
        vUrl($cv, 'CV link'), vDate($appDate, 'Application date'),
        $jobId <= 0 ? 'Please select a job position.' : '',
    ]);
    if ($err !== '') { setToast($err, 'error'); }
    elseif ($name !== '' && $jobId > 0) {
        $pdo->prepare("INSERT INTO candidate (full_name, email, phone, cv_link) VALUES (?,?,?,?)")
            ->execute([$name, $email ?: null, $phone ?: null, $cv ?: null]);
        $candidateId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO application (candidate_id, job_id, application_date, status) VALUES (?,?,?,'new')")
            ->execute([$candidateId, $jobId, $appDate]);
        setToast('Candidate added.');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_cards.php?' . http_build_query($_GET)); exit;
}

// Edit candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_candidate'])) {
    $cid = (int)($_POST['candidate_id'] ?? 0);
    $name = trim($_POST['full_name'] ?? '');
    $email2 = trim($_POST['email'] ?? ''); $phone2 = trim($_POST['phone'] ?? '');
    $cv2    = trim($_POST['cv_link'] ?? ''); $li2 = trim($_POST['linkedin_profile'] ?? '');
    $err2 = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vEmail($email2), vMaxLen($phone2, 20, 'Phone'),
        vUrl($cv2, 'CV link'), vUrl($li2, 'LinkedIn URL'),
    ]);
    if ($err2 !== '') { setToast($err2, 'error'); }
    elseif ($cid > 0) {
        $pdo->prepare("UPDATE candidate SET full_name=?,email=?,phone=?,cv_link=?,linkedin_profile=? WHERE candidate_id=?")
            ->execute([$name, $email2 ?: null, $phone2 ?: null, $cv2 ?: null, $li2 ?: null, $cid]);
        setToast('Candidate updated.');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_cards.php?' . http_build_query($_GET)); exit;
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
    header('Location: ' . BASE_URL . '/hr/candidate_cards.php?' . http_build_query($_GET)); exit;
}

$search       = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$jobFilter    = (int)($_GET['job_id'] ?? 0);
$evalFilter   = (int)($_GET['evaluation'] ?? 0);

$sql = "
    SELECT c.*,
           a.application_id, a.status, a.application_date, a.kanban_state,
           a.refuse_reason, a.hire_date,
           j.title AS job_title,
           d.department_name
    FROM candidate c
    LEFT JOIN application a ON a.application_id = (
        SELECT application_id FROM application WHERE candidate_id = c.candidate_id
        ORDER BY created_at DESC LIMIT 1
    )
    LEFT JOIN job j ON a.job_id = j.job_id
    LEFT JOIN department d ON j.department_id = d.department_id
    WHERE 1=1
";
$params = [];
if ($search !== '')       { $sql .= " AND (c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statusFilter !== '') { $sql .= " AND a.status = ?";       $params[] = $statusFilter; }
if ($jobFilter > 0)       { $sql .= " AND a.job_id = ?";       $params[] = $jobFilter; }
if ($evalFilter > 0)      { $sql .= " AND c.evaluation = ?";   $params[] = $evalFilter; }
$sql .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$candidates = $stmt->fetchAll();

$jobs    = $pdo->query("SELECT job_id, title FROM job ORDER BY title")->fetchAll();
$openJobs = $pdo->query("SELECT job_id, title FROM job WHERE status='open' ORDER BY title")->fetchAll();

$pipelineStages = [
    'new'             => 'New',
    'first_interview' => 'First Interview',
    'accepted'        => 'Accepted',
    'rejected'        => 'Rejected',
];

$avatarPalette = ['#7B5EA7','#0F766E','#1D4ED8','#B45309','#DC2626','#0EA5E9','#DB2777'];

$pageTitle    = 'Candidate Profiles';
$pageSubtitle = 'All candidates at a glance';
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
        <select name="evaluation" class="form-control" style="width:auto;">
            <option value="0">All Ratings</option>
            <option value="1" <?= $evalFilter===1?'selected':'' ?>>★ 1 Star</option>
            <option value="2" <?= $evalFilter===2?'selected':'' ?>>★★ 2 Stars</option>
            <option value="3" <?= $evalFilter===3?'selected':'' ?>>★★★ 3 Stars</option>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($search || $statusFilter || $jobFilter || $evalFilter): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Toolbar: search + count + view toggle -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <form method="GET" style="flex:1;display:flex;gap:0;">
        <?php foreach (['job_id'=>$jobFilter,'status'=>$statusFilter,'evaluation'=>$evalFilter] as $k=>$v): ?>
            <?php if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="search-wrap" style="width:100%;max-width:320px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" class="form-control search-input" placeholder="Search name, email, phone…" value="<?= htmlspecialchars($search) ?>">
        </div>
    </form>
    <span style="font-size:13px;color:var(--muted-dark);white-space:nowrap;"><?= count($candidates) ?> candidate<?= count($candidates) !== 1 ? 's' : '' ?></span>
    <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addCandidateModal')" style="flex-shrink:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Candidate</button>
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

<?php
$kcMap = [
    'in_progress' => ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'In Progress'],
    'normal'      => ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'In Progress'],
    'ready'       => ['bg'=>'#E3F5EA','fg'=>'#15803D','label'=>'Ready'],
    'terminated'  => ['bg'=>'#FCE4E4','fg'=>'#DC2626','label'=>'Terminated'],
    'blocked'     => ['bg'=>'#FCE4E4','fg'=>'#DC2626','label'=>'Terminated'],
];
?>

<!-- List view -->
<div id="viewList" style="display:none;">
<?php if (empty($candidates)): ?>
    <div class="card" style="padding:60px;text-align:center;color:var(--muted-dark);">No candidates found.</div>
<?php else: ?>
<div class="card" style="margin-top:0;">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Outreach</th>
                    <th>Applied</th>
                    <th>Rating</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($candidates as $c):
                    $ks = $c['kanban_state'] ?? 'in_progress';
                    $km = $kcMap[$ks] ?? $kcMap['in_progress'];
                ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>"
                           style="font-weight:600;color:var(--navy);text-decoration:none;"><?= htmlspecialchars($c['full_name']) ?></a>
                    </td>
                    <td class="cell-muted"><?= htmlspecialchars($c['job_title'] ?? '') ?></td>
                    <td class="cell-muted"><?= htmlspecialchars($c['department_name'] ?? '') ?></td>
                    <td><?php if ($c['status']) echo statusPillFor($hrAppStat, $c['status']); else echo '<span class="cell-muted"></span>'; ?></td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:<?= $km['bg'] ?>;color:<?= $km['fg'] ?>;">
                            <span style="width:6px;height:6px;border-radius:50%;background:<?= $km['fg'] ?>;display:inline-block;"></span>
                            <?= $km['label'] ?>
                        </span>
                    </td>
                    <td><?php
                        $csBadge = '';
                        $ocBadge = '';
                        if (!empty($c['outreach_contact_status'])) {
                            $csColors = ['contacted'=>['#E0F2FE','#0369A1'],'did_not_contact'=>['#F1F5F9','#64748B']];
                            $csLabels = ['contacted'=>'Contacted','did_not_contact'=>'Not Contacted'];
                            $csc = $csColors[$c['outreach_contact_status']] ?? ['#F1F5F9','#64748B'];
                            $csBadge = '<span style="padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;background:'.$csc[0].';color:'.$csc[1].'">'.($csLabels[$c['outreach_contact_status']]??$c['outreach_contact_status']).'</span>';
                        }
                        if (!empty($c['outreach_outcome'])) {
                            $ocColors = ['replied'=>['#F0FDF4','#15803D'],'no_reply'=>['#FFF8E6','#B45309']];
                            $ocLabels = ['replied'=>'Replied','no_reply'=>'No Reply'];
                            $occ = $ocColors[$c['outreach_outcome']] ?? ['#F1F5F9','#64748B'];
                            $ocBadge = '<span style="padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;background:'.$occ[0].';color:'.$occ[1].'">'.($ocLabels[$c['outreach_outcome']]??$c['outreach_outcome']).'</span>';
                        }
                        if ($csBadge || $ocBadge) echo '<div style="display:flex;flex-direction:column;gap:3px;">'.$csBadge.$ocBadge.'</div>';
                        else echo '<span class="cell-muted"></span>';
                    ?></td>
                    <td class="cell-muted"><?= !empty($c['application_date']) ? date('M j, Y', strtotime($c['application_date'])) : '' ?></td>
                    <td style="color:#F59E0B;"><?= ($c['evaluation'] ?? 0) > 0 ? str_repeat('★', (int)$c['evaluation']) : '<span class="cell-muted"></span>' ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;justify-content:center;">
                            <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>"
                               style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EFF6FF;color:#1D4ED8;text-decoration:none;border:1.5px solid #BFDBFE;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Profile
                            </a>
                            <button type="button" title="Edit"
                                onclick="openEditModal(<?= (int)$c['candidate_id'] ?>,<?= htmlspecialchars(json_encode($c['full_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['email']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['phone']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['cv_link']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['linkedin_profile']??''),ENT_QUOTES) ?>)"
                                style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #99F6E4;background:#F0FDFA;color:#0F766E;cursor:pointer;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button type="button" title="Delete"
                                onclick="openDeleteModal(<?= (int)$c['candidate_id'] ?>,<?= htmlspecialchars(json_encode($c['full_name']),ENT_QUOTES) ?>)"
                                style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #FECACA;background:#FEF2F2;color:#DC2626;cursor:pointer;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
</div>

<!-- Cards view -->
<div id="viewCards" style="display:none;">
<!-- Cards grid -->
<?php if (empty($candidates)): ?>
    <div class="card" style="padding:60px;text-align:center;color:var(--muted-dark);">No candidates found.</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
    <?php foreach ($candidates as $c):
        $avatarColor = $avatarPalette[$c['candidate_id'] % count($avatarPalette)];
        $initials = '';
        foreach (explode(' ', $c['full_name']) as $part) $initials .= strtoupper(substr($part,0,1));
        $initials = substr($initials, 0, 2);
        $isHired   = ($c['status'] === 'accepted');
        $isRefused = ($c['status'] === 'rejected');
        $ks = $c['kanban_state'] ?? 'normal';
        $km = $kcMap[$ks] ?? $kcMap['normal'];
    ?>
    <div style="background:#fff;border-radius:14px;border:1.5px solid var(--border);overflow:hidden;position:relative;display:flex;flex-direction:column;transition:box-shadow .15s;"
         onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow='none'">

        <?php if ($isHired): ?>
        <div style="position:absolute;top:18px;right:-26px;background:#16A34A;color:#fff;font-size:9px;font-weight:800;letter-spacing:.1em;padding:4px 38px;transform:rotate(35deg);z-index:2;">HIRED</div>
        <?php elseif ($isRefused): ?>
        <div style="position:absolute;top:18px;right:-26px;background:#DC2626;color:#fff;font-size:9px;font-weight:800;letter-spacing:.1em;padding:4px 38px;transform:rotate(35deg);z-index:2;">REFUSED</div>
        <?php endif; ?>

        <!-- Card header -->
        <div style="padding:20px 20px 16px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #F1F5F9;">
            <div style="width:46px;height:46px;border-radius:12px;background:<?= $avatarColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex-shrink:0;letter-spacing:.02em;">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div style="flex:1;min-width:0;">
                <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>"
                   style="font-weight:700;font-size:15px;color:var(--navy);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars($c['full_name']) ?>
                </a>
                <div style="font-size:12px;color:var(--muted-dark);margin-top:2px;">
                    <?= htmlspecialchars($c['job_title'] ?? 'No position') ?><?= !empty($c['department_name']) ? ' · ' . htmlspecialchars($c['department_name']) : '' ?>
                </div>
            </div>
        </div>

        <!-- Card body -->
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px;flex:1;">

            <!-- Status + Progress State -->
            <div style="display:flex;gap:7px;flex-wrap:wrap;">
                <?php if ($c['status']): ?>
                    <?= statusPillFor($hrAppStat, $c['status']) ?>
                <?php endif; ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:<?= $km['bg'] ?>;color:<?= $km['fg'] ?>;">
                    <span style="width:6px;height:6px;border-radius:50%;background:<?= $km['fg'] ?>;display:inline-block;"></span>
                    <?= $km['label'] ?>
                </span>
                <?php if (!empty($c['outreach_contact_status'])):
                    $csColors = ['contacted'=>['#E0F2FE','#0369A1'],'did_not_contact'=>['#F1F5F9','#64748B']];
                    $csLabels = ['contacted'=>'Contacted','did_not_contact'=>'Not Contacted'];
                    $csc = $csColors[$c['outreach_contact_status']] ?? ['#F1F5F9','#64748B'];
                ?>
                <span style="padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:<?= $csc[0] ?>;color:<?= $csc[1] ?>;">
                    <?= $csLabels[$c['outreach_contact_status']] ?? htmlspecialchars($c['outreach_contact_status']) ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($c['outreach_outcome'])):
                    $ocColors = ['replied'=>['#F0FDF4','#15803D'],'no_reply'=>['#FFF8E6','#B45309'],'not_interested'=>['#FEF2F2','#DC2626']];
                    $ocLabels = ['replied'=>'Replied','no_reply'=>'No Reply','not_interested'=>'Not Interested'];
                    $occ = $ocColors[$c['outreach_outcome']] ?? ['#F1F5F9','#64748B'];
                ?>
                <span style="padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:<?= $occ[0] ?>;color:<?= $occ[1] ?>;">
                    <?= $ocLabels[$c['outreach_outcome']] ?? htmlspecialchars($c['outreach_outcome']) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Contact -->
            <div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--muted-dark);">
                <?php if (!empty($c['email'])): ?>
                <div style="display:flex;align-items:center;gap:7px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,4 12,13 2,4"/></svg>
                    <a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--muted-dark);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($c['email']) ?></a>
                </div>
                <?php endif; ?>
                <?php if (!empty($c['phone'])): ?>
                <div style="display:flex;align-items:center;gap:7px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l.77-.77a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.73 16z"/></svg>
                    <?= htmlspecialchars($c['phone']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($c['linkedin_profile'])): ?>
                <div style="display:flex;align-items:center;gap:7px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                    <a href="<?= htmlspecialchars($c['linkedin_profile']) ?>" target="_blank" style="color:#0A66C2;text-decoration:none;">LinkedIn</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Applied + Hire Date -->
            <div style="display:flex;gap:14px;font-size:11px;color:var(--muted-dark);flex-wrap:wrap;">
                <span>Applied: <strong style="color:var(--navy);"><?= !empty($c['application_date']) ? date('M j, Y', strtotime($c['application_date'])) : '' ?></strong></span>
                <?php if (!empty($c['hire_date'])): ?>
                <span>Hired: <strong style="color:#15803D;"><?= date('M j, Y', strtotime($c['hire_date'])) ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- Evaluation stars -->
            <?php if (($c['evaluation'] ?? 0) > 0): ?>
            <div style="font-size:16px;color:#F59E0B;letter-spacing:-1px;">
                <?= str_repeat('★', (int)$c['evaluation']) ?><?= str_repeat('☆', 3-(int)$c['evaluation']) ?>
            </div>
            <?php endif; ?>

        </div>

        <!-- Card footer -->
        <div style="padding:12px 20px;border-top:1px solid #F1F5F9;display:flex;align-items:center;gap:8px;background:#FAFBFC;">
            <?php if (!empty($c['cv_link'])): ?>
            <a href="<?= htmlspecialchars($c['cv_link']) ?>" target="_blank"
               style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EDE8F5;color:#7B5EA7;text-decoration:none;border:1.5px solid #D8C9F4;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                CV
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>"
               style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;background:#EFF6FF;color:#1D4ED8;text-decoration:none;border:1.5px solid #BFDBFE;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <div style="margin-left:auto;display:flex;gap:6px;">
                <button type="button" title="Edit"
                    onclick="openEditModal(<?= (int)$c['candidate_id'] ?>,<?= htmlspecialchars(json_encode($c['full_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['email']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['phone']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['cv_link']??''),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($c['linkedin_profile']??''),ENT_QUOTES) ?>)"
                    style="width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #99F6E4;background:#F0FDFA;color:#0F766E;cursor:pointer;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button type="button" title="Delete"
                    onclick="openDeleteModal(<?= (int)$c['candidate_id'] ?>,<?= htmlspecialchars(json_encode($c['full_name']),ENT_QUOTES) ?>)"
                    style="width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;border:1.5px solid #FECACA;background:#FEF2F2;color:#DC2626;cursor:pointer;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- end viewCards -->

<!-- Add Candidate Modal -->
<div class="modal-overlay" id="addCandidateModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div><div class="modal-title">Add Candidate</div><div class="modal-subtitle">Manually add a candidate to the database</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="add_candidate" value="1">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Sara Khoury" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Applied Position <span style="color:#DC2626;">*</span></label>
                        <select name="job_id" class="form-control" required>
                            <option value="">Select job</option>
                            <?php foreach ($openJobs as $j): ?>
                                <option value="<?= (int)$j['job_id'] ?>"><?= htmlspecialchars($j['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="candidate@email.com" data-val-email data-val-label="Email">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+961 XX XXX XXX" oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">CV Link <span style="font-weight:400;font-size:12px;color:var(--muted-dark);">(optional)</span></label>
                        <input type="text" name="cv_link" class="form-control" placeholder="https://..." data-val-url data-val-label="CV link">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Application Date</label>
                        <input type="date" name="application_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addCandidateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Candidate</button>
            </div>
        </form>
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
                    <div class="form-group"><label class="form-label">Full Name <span style="color:#DC2626;">*</span></label><input type="text" name="full_name" id="editFullName" class="form-control" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="editEmail" class="form-control" data-val-email data-val-label="Email"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Phone</label><input type="tel" name="phone" id="editPhone" class="form-control" placeholder="+961 XX XXX XXX" oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')"></div>
                    <div class="form-group"><label class="form-label">LinkedIn <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label><input type="url" name="linkedin_profile" id="editLinkedin" class="form-control" data-val-url data-val-label="LinkedIn URL"></div>
                </div>
                <div class="form-group"><label class="form-label">CV Link <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label><input type="text" name="cv_link" id="editCvLink" class="form-control" placeholder="https://..." data-val-url data-val-label="CV link"></div>
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
var CAND_VIEW_KEY = 'hrCandidatesView';
function setView(v) { localStorage.setItem(CAND_VIEW_KEY, v); applyView(v); }
function applyView(v) {
    document.getElementById('viewList').style.display  = v === 'list'  ? 'block' : 'none';
    document.getElementById('viewCards').style.display = v === 'cards' ? 'block' : 'none';
    ['list','cards'].forEach(function(id) {
        var btn = document.getElementById('btn' + id.charAt(0).toUpperCase() + id.slice(1));
        btn.style.background = (v === id) ? 'var(--purple)' : '#fff';
        btn.style.color      = (v === id) ? '#fff' : 'var(--navy)';
    });
}
var _cv = localStorage.getItem(CAND_VIEW_KEY);
applyView((_cv === 'list' || _cv === 'cards') ? _cv : 'cards');

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



