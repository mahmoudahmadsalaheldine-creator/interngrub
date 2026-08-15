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
    $dErr  = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vEmail($email), vMaxLen($phone, 20, 'Phone'),
        vUrl($cv, 'CV link'), vUrl($li, 'LinkedIn URL'),
    ]);
    if ($dErr !== '') { setToast($dErr, 'error'); }
    elseif ($cid > 0) {
        $pdo->prepare("UPDATE candidate SET full_name=?,email=?,phone=?,cv_link=?,linkedin_profile=? WHERE candidate_id=?")
            ->execute([$name, $email ?: null, $phone ?: null, $cv ?: null, $li ?: null, $cid]);
        setToast('Candidate updated.');
    }
    header('Location: ' . BASE_URL . '/hr/dashboard.php'); exit;
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
    header('Location: ' . BASE_URL . '/hr/dashboard.php'); exit;
}

$openJobs      = (int)$pdo->query("SELECT COUNT(*) FROM job WHERE status='open'")->fetchColumn();
$totalCandidates = (int)$pdo->query("SELECT COUNT(*) FROM candidate")->fetchColumn();
$newApps       = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='new'")->fetchColumn();
$interviewsWeek= (int)$pdo->query("SELECT COUNT(*) FROM interview WHERE interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$accepted      = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='contract_signed'")->fetchColumn();
$rejected      = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='refused'")->fetchColumn();

$recentApps = $pdo->query("
    SELECT a.application_id, a.candidate_id, a.status, a.application_date,
           a.kanban_state, a.hire_date, a.refuse_reason,
           c.full_name AS candidate_name, c.email AS candidate_email,
           c.phone, c.cv_link, c.linkedin_profile,
           j.title AS job_title,
           d.department_name
    FROM application a
    JOIN candidate c ON a.candidate_id = c.candidate_id
    JOIN job j ON a.job_id = j.job_id
    LEFT JOIN department d ON j.department_id = d.department_id
    ORDER BY a.created_at DESC LIMIT 10
")->fetchAll();

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Recruitment overview';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
    <a href="<?= BASE_URL ?>/hr/jobs.php?status=open" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#CCFBF1;color:#0F766E;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></div></div>
        <div class="stat-value" style="color:#0F766E;"><?= $openJobs ?></div>
        <div class="stat-label">Open jobs</div>
    </a>
    <a href="<?= BASE_URL ?>/hr/candidate_cards.php" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#EDE8F5;color:#7B5EA7;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div></div>
        <div class="stat-value"><?= $totalCandidates ?></div>
        <div class="stat-label">Total candidates</div>
    </a>
    <a href="<?= BASE_URL ?>/hr/applications.php?status=new" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#EFF6FF;color:#1D4ED8;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div></div>
        <div class="stat-value" style="color:#1D4ED8;"><?= $newApps ?></div>
        <div class="stat-label">New applications</div>
    </a>
    <a href="<?= BASE_URL ?>/hr/hiring_board.php" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#FFF8E6;color:#B45309;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div></div>
        <div class="stat-value" style="color:#B45309;"><?= $interviewsWeek ?></div>
        <div class="stat-label">Interviews this week</div>
    </a>
    <a href="<?= BASE_URL ?>/hr/applications.php?status=contract_signed" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#F0FDF4;color:#15803D;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div></div>
        <div class="stat-value" style="color:#15803D;"><?= $accepted ?></div>
        <div class="stat-label">Hired</div>
    </a>
    <a href="<?= BASE_URL ?>/hr/applications.php?status=refused" class="stat-card" style="text-decoration:none;cursor:pointer;" onmouseenter="this.style.boxShadow='0 6px 20px rgba(0,0,0,.09)'" onmouseleave="this.style.boxShadow=''">
        <div class="stat-card-top"><div class="icon-box" style="background:#FEF2F2;color:#DC2626;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div></div>
        <div class="stat-value" style="color:#DC2626;"><?= $rejected ?></div>
        <div class="stat-label">Refused</div>
    </a>
</div>

<div class="card" style="margin-top:22px;">
    <div class="card-header">
        <div class="card-header-title">Recent Applications</div>
        <a href="<?= BASE_URL ?>/hr/applications.php" class="btn btn-outline btn-sm">View all</a>
    </div>
    <div class="table-wrap">
        <table style="min-width:1000px;">
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
                <?php if (empty($recentApps)): ?>
                    <tr><td colspan="11" class="text-muted">No applications yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentApps as $a): ?>
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



