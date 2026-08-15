<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM candidate WHERE candidate_id=?");
$stmt->execute([$id]);
$candidate = $stmt->fetch();
if (!$candidate) { header('Location: ' . BASE_URL . '/hr/candidates.php'); exit; }

$stages = ['new','first_interview','accepted'];
$stageLabels = [
    'new'             => 'New',
    'first_interview' => 'First Interview',
    'accepted'        => 'Accepted',
];
$refuseReasons = [
    'Refused by applicant: salary',
    'Refused by applicant: job fit',
    'Does not fit the job requirements',
    'Job already fulfilled',
    'Duplicate',
    'Spam',
];

$appStmt = $pdo->prepare("SELECT a.*, j.title AS job_title FROM application a LEFT JOIN job j ON a.job_id=j.job_id WHERE a.candidate_id=? ORDER BY a.created_at DESC LIMIT 1");
$appStmt->execute([$id]);
$app = $appStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

// Stage change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_stage'])) {
    $newStage    = $_POST['stage'] ?? '';
    $appId       = (int)($_POST['application_id'] ?? 0);
    $oldStage    = $_POST['old_stage'] ?? 'new';
    $oldStageIdx = array_search($oldStage, $stages);
    $newStageIdx = array_search($newStage, $stages);
    if ($appId > 0 && in_array($newStage, $stages, true)) {
        if ($newStage === 'accepted') {
            $pdo->prepare("UPDATE application SET status=?, hire_date=NOW(), kanban_state='ready' WHERE application_id=?")->execute([$newStage, $appId]);
            // Auto-set outreach outcome to Replied when candidate is accepted
            $pdo->prepare("UPDATE candidate SET outreach_outcome='replied' WHERE candidate_id=? AND (outreach_outcome IS NULL OR outreach_outcome != 'replied')")
                ->execute([$id]);
        } else {
            $pdo->prepare("UPDATE application SET status=?, kanban_state='in_progress' WHERE application_id=?")->execute([$newStage, $appId]);
        }
        if ($oldStageIdx !== false && $newStageIdx !== false && $newStageIdx < $oldStageIdx) {
            $pdo->prepare("DELETE FROM application_activity WHERE application_id=? AND activity_type='stage_change' ORDER BY created_at DESC LIMIT 1")
                ->execute([$appId]);
        } else {
            $fromLabel = $stageLabels[$oldStage] ?? ucfirst(str_replace('_', ' ', $oldStage));
            $toLabel   = $stageLabels[$newStage] ?? $newStage;
            $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
                ->execute([$appId, $_SESSION['user_id'], 'stage_change', "Stage changed\n{$fromLabel} → {$toLabel} (Stage)"]);
        }
    }
    setToast('Stage updated.', 'info');
    $extra = ($newStage === 'accepted') ? '&stage_accepted=1' : '';
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id . $extra);
    exit;
}

// Refuse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_refuse'])) {
    $appId      = (int)($_POST['application_id'] ?? 0);
    $reason     = trim($_POST['refuse_reason'] ?? '');
    $sendEmail  = !empty($_POST['send_email']);
    $emailSubj  = trim($_POST['email_subject'] ?? '');
    $emailBody  = trim($_POST['email_body'] ?? '');
    if ($appId > 0) {
        $pdo->prepare("UPDATE application SET status='rejected', kanban_state='terminated', refuse_reason=? WHERE application_id=?")->execute([$reason ?: null, $appId]);
        $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
            ->execute([$appId, $_SESSION['user_id'], 'refused', 'Application rejected' . ($reason ? ': ' . $reason : '')]);
        if ($sendEmail && !empty($candidate['email']) && $emailSubj && $emailBody) {
            require_once __DIR__ . '/../includes/mailer.php';
            sendEmail($candidate['email'], $emailSubj, nl2br(htmlspecialchars($emailBody)));
        }
        setToast('Application refused.', 'info');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Restore from refused
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_restore'])) {
    $appId = (int)($_POST['application_id'] ?? 0);
    if ($appId > 0) {
        $pdo->prepare("UPDATE application SET status='new', refuse_reason=NULL WHERE application_id=?")->execute([$appId]);
        $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
            ->execute([$appId, $_SESSION['user_id'], 'stage_change', "Application restored\nRefused → New (Stage)"]);
        setToast('Application restored.', 'info');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Update candidate info + kanban state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_candidate'])) {
    $name     = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $cv       = trim($_POST['cv_link'] ?? '');
    $linkedin = trim($_POST['linkedin_profile'] ?? '');
    $eval     = min(3, max(0, (int)($_POST['evaluation'] ?? 0)));
    $updErr = vFirst([
        vRequired($name, 'Full name'), vMaxLen($name, 100, 'Full name'),
        vEmail($email), vMaxLen($phone, 20, 'Phone'),
        vUrl($cv, 'CV link'), vUrl($linkedin, 'LinkedIn URL'),
    ]);
    if ($updErr !== '') {
        setToast($updErr, 'error');
        header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id); exit;
    }
    if ($name !== '') {
        $pdo->prepare("UPDATE candidate SET full_name=?,email=?,phone=?,cv_link=?,linkedin_profile=?,evaluation=? WHERE candidate_id=?")
            ->execute([$name, $email ?: null, $phone ?: null, $cv ?: null, $linkedin ?: null, $eval, $id]);
    }
    $appId  = (int)($_POST['application_id'] ?? 0);
    $kanban = $_POST['kanban_state'] ?? 'in_progress';
    if ($appId > 0) {
        $pdo->prepare("UPDATE application SET kanban_state=? WHERE application_id=?")->execute([$kanban, $appId]);
        if ($kanban === 'terminated') {
            $pdo->prepare("UPDATE application SET status='rejected', refuse_reason='Candidate not responsive' WHERE application_id=?")->execute([$appId]);
            $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
                ->execute([$appId, $_SESSION['user_id'], 'refused', "Application rejected: Candidate not responsive (blocked)"]);
        }
    }
    setToast('Candidate updated.');
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id . '&saved=1');
    exit;
}

// Delete candidate entirely
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidate'])) {
    $appIds = $pdo->prepare("SELECT application_id FROM application WHERE candidate_id=?");
    $appIds->execute([$id]);
    foreach ($appIds->fetchAll() as $row) {
        $aid = (int)$row['application_id'];
        $pdo->prepare("DELETE FROM application_activity WHERE application_id=?")->execute([$aid]);
        $pdo->prepare("DELETE FROM application_note WHERE application_id=?")->execute([$aid]);
        $pdo->prepare("DELETE FROM interview WHERE application_id=?")->execute([$aid]);
    }
    $pdo->prepare("DELETE FROM application WHERE candidate_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM candidate WHERE candidate_id=?")->execute([$id]);
    setToast('Candidate deleted.');
    header('Location: ' . BASE_URL . '/hr/candidate_cards.php');
    exit;
}

// Create intern account from candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_intern'])) {
    $appId      = (int)($_POST['application_id'] ?? 0);
    $username   = trim($_POST['intern_username'] ?? '');
    $password   = trim($_POST['intern_password'] ?? '');
    $startDate  = $_POST['intern_start_date'] ?? date('Y-m-d');
    $endDate    = $_POST['intern_end_date'] ?? null;
    $deptId     = (int)($_POST['intern_department_id'] ?? 0) ?: null;
    $position   = trim($_POST['intern_position'] ?? '');
    $supId      = (int)($_POST['intern_supervisor_id'] ?? 0) ?: null;

    $ciErr = vFirst([
        vUsername($username),
        vRequired($password, 'Password'), vMinLen($password, 8, 'Password'),
        vMaxLen($position, 100, 'Position'),
        vDate($startDate, 'Start date'), vDate($endDate ?: '', 'End date'),
    ]);
    if ($ciErr !== '') {
        setToast($ciErr, 'error');
        header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id); exit;
    }
    if ($username && $password && $appId) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // Create user
        $pdo->prepare("INSERT INTO user (username, full_name, email, password, phone, role, department_id, status)
                       VALUES (?,?,?,?,?,  'intern',?,  'active')")
            ->execute([$username, $candidate['full_name'], $candidate['email'] ?: null,
                       $hash, $candidate['phone'] ?: null, $deptId]);
        $newUserId = (int)$pdo->lastInsertId();

        // Create intern_profile
        $pdo->prepare("INSERT INTO intern_profile (user_id, department_id, position, start_date, end_date, internship_status, supervisor_id)
                       VALUES (?,?,?,?,?,  'active',?)")
            ->execute([$newUserId, $deptId, $position ?: null, $startDate, $endDate ?: null, $supId]);

        // Link candidate → user
        $pdo->prepare("UPDATE candidate SET user_id=? WHERE candidate_id=?")->execute([$newUserId, $id]);

        // Log activity
        $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
            ->execute([$appId, $_SESSION['user_id'], 'stage_change', 'Intern account created (username: ' . $username . ')']);
    }
    setToast('Intern account created successfully.');
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id . '&intern_created=1');
    exit;
}

// Delete all activities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_activities'])) {
    $appId = (int)($_POST['application_id'] ?? 0);
    if ($appId > 0) {
        $pdo->prepare("DELETE FROM application_activity WHERE application_id=?")->execute([$appId]);
        setToast('Activity log cleared.', 'info');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Delete single activity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_activity'])) {
    $actId = (int)($_POST['activity_id'] ?? 0);
    if ($actId > 0) {
        $pdo->prepare("DELETE FROM application_activity WHERE activity_id=?")->execute([$actId]);
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Save note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notes'])) {
    $appId = (int)($_POST['application_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    if ($appId > 0) {
        $pdo->prepare("INSERT INTO application_note (application_id,screening_notes) VALUES (?,?) ON DUPLICATE KEY UPDATE screening_notes=VALUES(screening_notes)")
            ->execute([$appId, $notes ?: null]);
        setToast('Notes saved.');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Save stage note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stage_note'])) {
    $appId      = (int)($_POST['application_id'] ?? 0);
    $stage      = $_POST['stage'] ?? '';
    $stageNote  = trim($_POST['stage_note'] ?? '');
    if ($appId > 0 && in_array($stage, $stages, true)) {
        $pdo->prepare("INSERT INTO application_stage_note (application_id, stage, note, updated_by) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE note=VALUES(note), updated_by=VALUES(updated_by), updated_at=NOW()")
            ->execute([$appId, $stage, $stageNote ?: null, $_SESSION['user_id']]);
        setToast('Note saved.');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Save outreach
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_outreach'])) {
    $internshipTitle = trim($_POST['internship_title'] ?? '');
    $outreachDate    = $_POST['outreach_date'] ?? '';
    $contactStatus   = $_POST['outreach_contact_status'] ?? '';
    $outreachOutcome = $_POST['outreach_outcome'] ?? '';
    $outreachNotes   = trim($_POST['outreach_notes'] ?? '');
    $pdo->prepare("UPDATE candidate SET internship_title=?, outreach_date=?, outreach_contact_status=?, outreach_outcome=?, outreach_notes=? WHERE candidate_id=?")
        ->execute([
            $internshipTitle !== '' ? $internshipTitle : null,
            $outreachDate !== '' ? $outreachDate : null,
            $contactStatus !== '' ? $contactStatus : null,
            $outreachOutcome !== '' ? $outreachOutcome : null,
            $outreachNotes !== '' ? $outreachNotes : null,
            $id
        ]);
    // Auto-reject on No Reply / Auto-restore on Replied
    $appRow = $pdo->prepare("SELECT application_id, status, kanban_state FROM application WHERE candidate_id=? ORDER BY created_at DESC LIMIT 1");
    $appRow->execute([$id]);
    $autoApp = $appRow->fetch();
    if ($autoApp) {
        $autoAppId    = $autoApp['application_id'];
        $currentState = $autoApp['kanban_state'];
        if ($outreachOutcome === 'no_reply') {
            $pdo->prepare("UPDATE application SET status='rejected', kanban_state='terminated', refuse_reason='No reply from applicant' WHERE application_id=?")
                ->execute([$autoAppId]);
            $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
                ->execute([$autoAppId, $_SESSION['user_id'], 'refused', 'Auto-rejected: No reply from applicant']);
            setToast('Outreach saved. Applicant auto-rejected due to no reply.', 'info');
        } elseif ($outreachOutcome === 'replied' && in_array($currentState, ['terminated', 'blocked'], true)) {
            // Restore to In Progress only if currently terminated — don't downgrade Ready
            $pdo->prepare("UPDATE application SET status='new', kanban_state='in_progress', refuse_reason=NULL WHERE application_id=?")
                ->execute([$autoAppId]);
            $pdo->prepare("INSERT INTO application_activity (application_id,user_id,activity_type,description) VALUES (?,?,?,?)")
                ->execute([$autoAppId, $_SESSION['user_id'], 'note', 'Candidate restored to In Progress after reply']);
            setToast('Outreach saved. Candidate restored to In Progress.');
        } else {
            setToast('Outreach saved.');
        }
    } else {
        setToast('Outreach saved.');
    }
    header('Location: ' . BASE_URL . '/hr/candidate_profile.php?id=' . $id);
    exit;
}

// Reload fresh data (fresh queries — do not reuse prepared statements)
$candidate = $pdo->prepare("SELECT * FROM candidate WHERE candidate_id=?");
$candidate->execute([$id]);
$candidate = $candidate->fetch();
$freshApp = $pdo->prepare("SELECT a.*, j.title AS job_title FROM application a LEFT JOIN job j ON a.job_id=j.job_id WHERE a.candidate_id=? ORDER BY a.created_at DESC LIMIT 1");
$freshApp->execute([$id]);
$app = $freshApp->fetch();

// Data for intern modal
$departments = $pdo->query("SELECT department_id, department_name FROM department ORDER BY department_name")->fetchAll();
$supervisors = $pdo->query("SELECT user_id, full_name FROM user WHERE role IN ('admin','manager') AND status='active' ORDER BY full_name")->fetchAll();

// If already converted, load intern profile
$internProfile = null;
if (!empty($candidate['user_id'])) {
    $ip = $pdo->prepare("SELECT ip.*, u.username FROM intern_profile ip JOIN user u ON ip.user_id=u.user_id WHERE ip.user_id=?");
    $ip->execute([$candidate['user_id']]);
    $internProfile = $ip->fetch();
}

// Auto-generate username suggestion from name
function suggestUsername($fullName) {
    $parts = explode(' ', strtolower(trim($fullName)));
    if (count($parts) >= 2) return $parts[0] . '.' . end($parts);
    return $parts[0];
}
function generateTempPassword($len = 10) {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#';
    $pass  = '';
    for ($i = 0; $i < $len; $i++) $pass .= $chars[random_int(0, strlen($chars)-1)];
    return $pass;
}
$suggestedUsername = suggestUsername($candidate['full_name']);
$suggestedPassword = generateTempPassword();

$noteRow    = null;
$activities = [];
$stageNotes = [];
if ($app) {
    $ns = $pdo->prepare("SELECT * FROM application_note WHERE application_id=?");
    $ns->execute([$app['application_id']]);
    $noteRow = $ns->fetch();

    $ac = $pdo->prepare("SELECT ac.*, u.full_name AS actor FROM application_activity ac LEFT JOIN user u ON ac.user_id=u.user_id WHERE ac.application_id=? ORDER BY ac.created_at DESC");
    $ac->execute([$app['application_id']]);
    $activities = $ac->fetchAll();

    $sn = $pdo->prepare("SELECT stage, note FROM application_stage_note WHERE application_id=?");
    $sn->execute([$app['application_id']]);
    foreach ($sn->fetchAll() as $row) $stageNotes[$row['stage']] = $row['note'];
}

// Direct DB check for status — immune to any JOIN or caching issues
$_dbStatus = $pdo->prepare("SELECT status FROM application WHERE candidate_id=? ORDER BY created_at DESC LIMIT 1");
$_dbStatus->execute([$id]);
$_dbStatusRow = $_dbStatus->fetch();
$curStatus   = $_dbStatusRow['status'] ?? ($app['status'] ?? 'new');
$isHired     = ($curStatus === 'accepted');
$isRefused   = ($curStatus === 'rejected');
$curStageIdx = array_search($curStatus, $stages);
if ($curStageIdx === false) $curStageIdx = -1;

// Time since entering current stage
$stageEnteredAt = $app['created_at'] ?? date('Y-m-d H:i:s');
foreach ($activities as $ac) {
    if ($ac['activity_type'] === 'stage_change') {
        $stageEnteredAt = $ac['created_at'];
        break;
    }
}
function stageTimeSince($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)   return max(1, (int)($diff / 60)) . 'm';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'd';
    return (int)($diff / 604800) . 'w';
}

$pageTitle    = htmlspecialchars($candidate['full_name']);
$pageSubtitle = 'Candidate profile';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="alert alert-success" style="margin-bottom:16px;">Candidate saved.</div>
<?php endif; ?>
<?php if (!empty($_GET['intern_created'])): ?>
<div class="alert alert-success" style="margin-bottom:16px;">Intern account created successfully.</div>
<?php endif; ?>

<div style="margin-bottom:14px;">
    <a href="<?= BASE_URL ?>/hr/candidate_cards.php" style="font-size:13px;color:var(--muted-dark);text-decoration:none;">← Back to Candidate Profiles</a>
</div>

<!-- Action buttons -->
<div class="flex items-center gap-3" style="margin-bottom:16px;flex-wrap:wrap;">
    <?php if ($app && !$isRefused): ?>
        <button type="button" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;" onclick="openModal('refuseModal')">Refuse</button>
    <?php endif; ?>
    <?php if ($isRefused): ?>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="do_restore" value="1">
            <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
            <button type="submit" class="btn btn-outline" style="color:#15803D;border-color:#BBF7D0;">↩ Restore</button>
        </form>
    <?php endif; ?>
    <?php if ($isHired): ?>
        <?php if ($internProfile): ?>
            <span style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;background:#E3F5EA;border-radius:8px;font-size:13px;font-weight:600;color:#15803D;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Intern Account Active
                <a href="<?= BASE_URL ?>/admin/interns.php" style="font-size:11px;color:#0F766E;margin-left:4px;font-weight:500;">View →</a>
            </span>
        <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm" style="background:#7B5EA7;border-color:#7B5EA7;" onclick="openModal('createInternModal')">
                + Create Intern Account
            </button>
        <?php endif; ?>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/hr/applications.php" class="btn btn-outline btn-sm" style="margin-left:auto;">All Applications</a>
    <button type="button" class="btn btn-outline btn-sm" style="color:#DC2626;border-color:#FECACA;" onclick="openModal('deleteProfileModal')">Delete Candidate</button>
</div>
<?php if ($isHired && empty($internProfile) && !empty($_GET['stage_accepted'])): ?>
<script>document.addEventListener('DOMContentLoaded', function(){ openModal('createInternModal'); });</script>
<?php endif; ?>

<!-- Create Intern Account Modal -->
<div class="modal-overlay" id="createInternModal" style="display:none;">
    <div class="modal" style="width:660px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#EDE8F5;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7B5EA7" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;">Create Intern Account</div>
                    <div class="modal-subtitle" style="margin-top:2px;">for <strong><?= htmlspecialchars($candidate['full_name']) ?></strong></div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="create_intern" value="1">
            <input type="hidden" name="application_id" value="<?= (int)($app['application_id'] ?? 0) ?>">
            <div class="modal-body" style="padding-top:22px;">

                <!-- Section: Login credentials -->
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:12px;">Login Credentials</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="intern_username" id="internUsernameInput" class="form-control" value="<?= htmlspecialchars($suggestedUsername) ?>" required data-val-username>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                            Temporary Password <span style="color:#DC2626;">*</span>
                            <button type="button" onclick="suggestPwFromUsername('internUsernameInput','internPassInput','internPwStrength')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                        </label>
                        <div style="position:relative;">
                            <input type="password" name="intern_password" id="internPassInput" class="form-control" required style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'internPwStrength')" data-val-required="Password" data-val-minlen="8" data-val-label="Password">
                            <button type="button" onclick="toggleEye('internPassInput',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                        <div id="internPwStrength" style="height:4px;background:#E2E8F0;border-radius:2px;margin-top:6px;"><div style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;"></div></div>
                        <div style="font-size:11px;color:#94A3B8;margin-top:4px;">Share this with the intern — it won't be shown again.</div>
                    </div>
                </div>

                <div style="border-top:1px solid #F1F5F9;margin:18px 0 16px;"></div>

                <!-- Section: Internship details -->
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:12px;">Internship Details</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="intern_department_id" class="form-control">
                            <option value="">Select department</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"
                                    <?= (isset($app['department_id']) && (int)$app['department_id'] === (int)$d['department_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position / Role</label>
                        <input type="text" name="intern_position" class="form-control" value="<?= htmlspecialchars($app['job_title'] ?? '') ?>" placeholder="e.g. Software Developer Intern">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date <span style="color:#DC2626;">*</span></label>
                        <input type="date" name="intern_start_date" class="form-control" value="<?= date('Y-m-d') ?>" required data-val-required="Start date" data-val-date data-val-label="Start date">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Date <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label>
                        <input type="date" name="intern_end_date" class="form-control" data-val-date data-val-label="End date">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Supervisor <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label>
                    <select name="intern_supervisor_id" class="form-control">
                        <option value="">No supervisor</option>
                        <?php foreach ($supervisors as $s): ?>
                            <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('createInternModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#7B5EA7;border-color:#7B5EA7;padding:10px 28px;">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Candidate Modal -->
<div class="modal-overlay" id="deleteProfileModal" style="display:none;">
    <div class="modal" style="width:440px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;color:#DC2626;">Delete Candidate</div>
                    <div class="modal-subtitle" style="margin-top:2px;">This action cannot be undone</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="delete_candidate" value="1">
            <div class="modal-body" style="padding-top:20px;">
                <p style="font-size:14px;color:var(--navy);margin:0;">Are you sure you want to delete <strong><?= htmlspecialchars($candidate['full_name']) ?></strong>?</p>
                <p style="font-size:13px;color:#DC2626;margin:10px 0 0;background:#FEF2F2;padding:10px 14px;border-radius:8px;">All applications, notes, activities, and interviews will be permanently removed.</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteProfileModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;padding:10px 24px;">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Pipeline stage bar (hidden when refused) -->
<?php if ($app && !$isRefused): ?>
<div class="card" style="margin-bottom:18px;padding:0;overflow:hidden;">
    <div style="display:flex;overflow-x:auto;">
        <?php foreach ($stages as $i => $stage):
            $isActive = ($curStatus === $stage);
            $isPast   = ($curStageIdx !== false && $curStageIdx > $i);
        ?>
        <form method="POST" style="flex:1;min-width:110px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="change_stage" value="1">
            <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
            <input type="hidden" name="stage" value="<?= htmlspecialchars($stage) ?>">
            <input type="hidden" name="old_stage" value="<?= htmlspecialchars($curStatus) ?>">
            <button type="<?= $isActive ? 'button' : 'submit' ?>" style="
                width:100%;border:none;
                cursor:<?= $isActive ? 'default' : 'pointer' ?>;
                background:<?= $isActive ? 'var(--purple)' : ($isPast ? '#F0FDF4' : '#FAFBFC') ?>;
                color:<?= $isActive ? '#fff' : ($isPast ? '#15803D' : 'var(--muted-dark)') ?>;
                padding:12px 8px;font-size:12px;
                font-weight:<?= $isActive ? '700' : '500' ?>;
                text-align:center;white-space:nowrap;
                border-right:1px solid var(--border);
                transition:background .15s;
            "><?= $isPast ? '✓ ' : '' ?><?= htmlspecialchars($stageLabels[$stage]) ?><?php if ($isActive): ?> <span style="font-size:10px;font-weight:400;opacity:.75;"><?= stageTimeSince($stageEnteredAt) ?></span><?php endif; ?></button>
        </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Main 2-col grid -->
<div class="candidate-profile-grid" style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">

    <!-- Left -->
    <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- Candidate info card -->
        <div class="card" style="margin-bottom:0;position:relative;overflow:hidden;">
            <?php if ($isHired): ?>
            <div style="position:absolute;top:22px;right:-28px;background:#16A34A;color:#fff;font-size:10px;font-weight:800;letter-spacing:.12em;padding:5px 42px;transform:rotate(35deg);z-index:2;box-shadow:0 2px 8px rgba(0,0,0,.15);">HIRED</div>
            <?php endif; ?>
            <?php if ($isRefused): ?>
            <div style="position:absolute;top:22px;right:-28px;background:#DC2626;color:#fff;font-size:10px;font-weight:800;letter-spacing:.12em;padding:5px 42px;transform:rotate(35deg);z-index:2;box-shadow:0 2px 8px rgba(0,0,0,.15);">REJECTED</div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <?php if ($app): ?>
                <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
                <?php endif; ?>
                <div class="card-body">
                    <div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Applicant</div>
                    <input type="text" name="full_name" class="form-control" required
                        value="<?= htmlspecialchars($candidate['full_name']) ?>"
                        style="font-size:24px;font-weight:700;color:var(--navy);border:none;border-bottom:2px solid var(--border);border-radius:0;padding:4px 0 8px;margin-bottom:22px;background:transparent;box-shadow:none;">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 28px;">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($candidate['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Evaluation</label>
                            <div class="flex items-center gap-1" style="margin-top:8px;" id="evalWrap">
                                <?php for ($s = 1; $s <= 3; $s++): ?>
                                <span onclick="setEval(<?= $s ?>)" data-star="<?= $s ?>"
                                    style="cursor:pointer;font-size:26px;line-height:1;color:<?= ($candidate['evaluation'] ?? 0) >= $s ? '#F59E0B' : '#E2E8F0' ?>;">★</span>
                                <?php endfor; ?>
                                <input type="hidden" name="evaluation" id="evalInput" value="<?= (int)($candidate['evaluation'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($candidate['phone'] ?? '') ?>" placeholder="+961 XX XXX XXX" oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Job Position</label>
                            <div class="form-control" style="background:#F8FAFC;pointer-events:none;color:var(--navy);font-weight:600;"><?= htmlspecialchars($app['job_title'] ?? '') ?></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">LinkedIn Profile</label>
                            <input type="url" name="linkedin_profile" class="form-control"
                                value="<?= htmlspecialchars($candidate['linkedin_profile'] ?? '') ?>"
                                placeholder="https://linkedin.com/in/...">
                        </div>
                        <?php if ($app):
                            $curKanban = $app['kanban_state'] ?? 'in_progress';
                            $ksMap = [
                                'in_progress' => ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'In Progress'],
                                'ready'       => ['bg'=>'#E3F5EA','fg'=>'#15803D','label'=>'Ready'],
                                'terminated'  => ['bg'=>'#FCE4E4','fg'=>'#DC2626','label'=>'Terminated'],
                            ];
                        ?>
                        <div class="form-group">
                            <label class="form-label">Progress State</label>
                            <input type="hidden" name="kanban_state" id="kanbanStateInput" value="<?= htmlspecialchars($curKanban) ?>">
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                <?php foreach ($ksMap as $val => $meta): ?>
                                <button type="button"
                                    data-ks="<?= $val ?>"
                                    onclick="setKanban('<?= $val ?>')"
                                    id="ks-<?= $val ?>"
                                    style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;border:2px solid transparent;cursor:pointer;transition:all .15s;
                                        background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;
                                        <?= $curKanban === $val ? 'border-color:' . $meta['fg'] . ';box-shadow:0 0 0 3px ' . $meta['bg'] . ';' : 'opacity:.55;' ?>">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $meta['fg'] ?>;flex-shrink:0;display:inline-block;"></span>
                                    <?= $meta['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <script>
                        var ksMap = {
                            normal:  {bg:'#F1F5F9',fg:'#64748B'},
                            ready:   {bg:'#E3F5EA',fg:'#15803D'},
                            blocked: {bg:'#FCE4E4',fg:'#DC2626'}
                        };
                        function setKanban(val) {
                            document.getElementById('kanbanStateInput').value = val;
                            ['normal','ready','blocked'].forEach(function(k) {
                                var btn = document.getElementById('ks-' + k);
                                var m = ksMap[k];
                                if (k === val) {
                                    btn.style.opacity = '1';
                                    btn.style.borderColor = m.fg;
                                    btn.style.boxShadow = '0 0 0 3px ' + m.bg;
                                } else {
                                    btn.style.opacity = '.55';
                                    btn.style.borderColor = 'transparent';
                                    btn.style.boxShadow = 'none';
                                }
                            });
                        }
                        </script>
                        <?php endif; ?>
                        <?php if (!empty($candidate['cv_link'])): ?>
                        <div class="form-group">
                            <label class="form-label">CV</label>
                            <a href="<?= htmlspecialchars($candidate['cv_link']) ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-block;margin-top:4px;">Open CV</a>
                        </div>
                        <?php endif; ?>
                        <?php if ($isHired && !empty($app['hire_date'])): ?>
                        <div class="form-group">
                            <label class="form-label">Hire Date</label>
                            <div class="form-control" style="background:#F8FAFC;color:#15803D;font-weight:600;"><?= date('M j, Y g:i A', strtotime($app['hire_date'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($isRefused && !empty($app['refuse_reason'])): ?>
                        <div class="form-group" style="grid-column:1/-1;">
                            <label class="form-label">Refuse Reason</label>
                            <div class="form-control" style="background:#FEF2F2;color:#DC2626;font-weight:500;pointer-events:none;"><?= htmlspecialchars($app['refuse_reason']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="update_candidate" value="1" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>

        <!-- Outreach -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-body" style="padding-bottom:0;">
                <div style="display:flex;border-bottom:2px solid var(--border);margin-bottom:16px;">
                    <span style="padding:8px 18px 10px;font-size:13px;font-weight:600;color:var(--purple);border-bottom:2px solid var(--purple);margin-bottom:-2px;">Outreach</span>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Internship Title</label>
                            <input type="text" name="internship_title" class="form-control" value="<?= htmlspecialchars($candidate['internship_title'] ?: ($app['job_title'] ?? '')) ?>" placeholder="e.g. Software Developer Intern">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="outreach_date" class="form-control" value="<?= htmlspecialchars($candidate['outreach_date'] ?? '') ?>">
                        </div>
                    </div>
                    <?php
                        $curContactStatus = $candidate['outreach_contact_status'] ?? '';
                        $curOutcome       = $candidate['outreach_outcome'] ?? '';
                        $csMap = [
                            'contacted'      => ['bg'=>'#E0F2FE','fg'=>'#0369A1','label'=>'Contacted'],
                            'did_not_contact'=> ['bg'=>'#F1F5F9','fg'=>'#64748B','label'=>'Did Not Contact'],
                        ];
                        $ocMap = [
                            'replied'  => ['bg'=>'#F0FDF4','fg'=>'#15803D','label'=>'Replied'],
                            'no_reply' => ['bg'=>'#FFF8E6','fg'=>'#B45309','label'=>'No Reply'],
                        ];
                    ?>
                    <input type="hidden" name="outreach_contact_status" id="outreachContactStatusInput" value="<?= htmlspecialchars($curContactStatus) ?>">
                    <input type="hidden" name="outreach_outcome" id="outreachOutcomeInput" value="<?= htmlspecialchars($curOutcome) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                <?php foreach ($csMap as $val => $meta): $isActive = ($curContactStatus === $val); ?>
                                <button type="button" data-cs="<?= $val ?>" onclick="setContactStatus('<?= $val ?>')"
                                    id="cs-<?= $val ?>"
                                    style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:2px solid <?= $isActive ? $meta['fg'] : 'transparent' ?>;background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;<?= $isActive ? 'box-shadow:0 0 0 3px '.$meta['bg'].';' : 'opacity:.45;' ?>">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $meta['fg'] ?>;flex-shrink:0;display:inline-block;"></span>
                                    <?= $meta['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Outcome</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                <?php foreach ($ocMap as $val => $meta): $isActive = ($curOutcome === $val); ?>
                                <button type="button" data-oc="<?= $val ?>" onclick="setOutcome('<?= $val ?>')"
                                    id="oc-<?= $val ?>"
                                    style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;border:2px solid <?= $isActive ? $meta['fg'] : 'transparent' ?>;background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;<?= $isActive ? 'box-shadow:0 0 0 3px '.$meta['bg'].';' : 'opacity:.45;' ?>">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $meta['fg'] ?>;flex-shrink:0;display:inline-block;"></span>
                                    <?= $meta['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <script>
                    var csMap = <?= json_encode($csMap) ?>;
                    var ocMap = <?= json_encode($ocMap) ?>;
                    function setContactStatus(val) {
                        var cur = document.getElementById('outreachContactStatusInput').value;
                        var next = (cur === val) ? '' : val; // click active = deselect
                        document.getElementById('outreachContactStatusInput').value = next;
                        Object.keys(csMap).forEach(function(k) {
                            var btn = document.getElementById('cs-' + k);
                            var m = csMap[k];
                            if (k === next) {
                                btn.style.opacity = '1';
                                btn.style.borderColor = m.fg;
                                btn.style.boxShadow = '0 0 0 3px ' + m.bg;
                            } else {
                                btn.style.opacity = '.45';
                                btn.style.borderColor = 'transparent';
                                btn.style.boxShadow = 'none';
                            }
                        });
                    }
                    function setOutcome(val) {
                        var cur = document.getElementById('outreachOutcomeInput').value;
                        var next = (cur === val) ? '' : val;
                        document.getElementById('outreachOutcomeInput').value = next;
                        Object.keys(ocMap).forEach(function(k) {
                            var btn = document.getElementById('oc-' + k);
                            var m = ocMap[k];
                            if (k === next) {
                                btn.style.opacity = '1';
                                btn.style.borderColor = m.fg;
                                btn.style.boxShadow = '0 0 0 3px ' + m.bg;
                            } else {
                                btn.style.opacity = '.45';
                                btn.style.borderColor = 'transparent';
                                btn.style.boxShadow = 'none';
                            }
                        });
                    }
                    </script>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="outreach_notes" class="form-control" rows="3"
                            style="border:none;box-shadow:none;resize:vertical;min-height:80px;"
                            placeholder="Add notes about this outreach..."><?= htmlspecialchars($candidate['outreach_notes'] ?? '') ?></textarea>
                    </div>
                    <div style="padding:10px 0 16px;">
                        <button type="submit" name="save_outreach" value="1" class="btn btn-outline btn-sm">Save Outreach</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Per-stage Notes (tabbed) -->
        <?php if ($app && !$isRefused): ?>
        <div class="card" style="margin-bottom:0;">
            <div class="card-body" style="padding-bottom:0;">
                <!-- Tab headers -->
                <div style="display:flex;border-bottom:2px solid var(--border);margin-bottom:16px;">
                    <?php foreach ($stages as $i => $stage): ?>
                    <button type="button" id="sntab-<?= $stage ?>" onclick="switchStageNote('<?= $stage ?>')"
                        style="padding:8px 18px 10px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;
                            color:<?= $i === 0 ? 'var(--purple)' : 'var(--muted-dark)' ?>;
                            border-bottom:2px solid <?= $i === 0 ? 'var(--purple)' : 'transparent' ?>;
                            margin-bottom:-2px;transition:all .15s;">
                        <?= htmlspecialchars($stageLabels[$stage]) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <!-- Tab panels -->
                <?php foreach ($stages as $i => $stage): ?>
                <div id="snpanel-<?= $stage ?>" style="<?= $i !== 0 ? 'display:none;' : '' ?>">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
                        <input type="hidden" name="stage" value="<?= htmlspecialchars($stage) ?>">
                        <textarea name="stage_note" class="form-control" rows="4"
                            style="border:none;box-shadow:none;resize:vertical;min-height:80px;"
                            placeholder="Notes for <?= htmlspecialchars($stageLabels[$stage]) ?> stage..."><?= htmlspecialchars($stageNotes[$stage] ?? '') ?></textarea>
                        <div style="padding:10px 0 16px;">
                            <button type="submit" name="save_stage_note" value="1" class="btn btn-outline btn-sm">Save Note</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        var snStages = <?= json_encode($stages) ?>;
        function switchStageNote(active) {
            snStages.forEach(function(s) {
                var tab   = document.getElementById('sntab-' + s);
                var panel = document.getElementById('snpanel-' + s);
                if (s === active) {
                    tab.style.color       = 'var(--purple)';
                    tab.style.borderColor = 'var(--purple)';
                    panel.style.display   = '';
                } else {
                    tab.style.color       = 'var(--muted-dark)';
                    tab.style.borderColor = 'transparent';
                    panel.style.display   = 'none';
                }
            });
        }
        // Open to the current active stage on load
        switchStageNote(<?= json_encode($isRefused ? 'new' : ($curStatus === 'accepted' || $curStatus === 'first_interview' ? $curStatus : 'new')) ?>);
        </script>
        <?php endif; ?>

        <!-- General Note -->
        <?php if ($app): ?>
        <div class="card" style="margin-bottom:0;">
            <div class="card-body" style="padding-bottom:0;">
                <div style="display:flex;border-bottom:2px solid var(--border);margin-bottom:16px;">
                    <span style="padding:8px 18px 10px;font-size:13px;font-weight:600;color:var(--purple);border-bottom:2px solid var(--purple);margin-bottom:-2px;">Note</span>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
                    <textarea name="notes" class="form-control" rows="5"
                        style="border:none;box-shadow:none;resize:vertical;min-height:90px;"
                        placeholder="Add private notes about this applicant..."><?= htmlspecialchars($noteRow['screening_notes'] ?? '') ?></textarea>
                    <div style="padding:10px 0 16px;">
                        <button type="submit" name="save_notes" value="1" class="btn btn-outline btn-sm">Save Note</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right — Activity log -->
    <div class="card" style="margin-bottom:0;max-height:640px;overflow-y:auto;">
        <div class="card-header">
            <div class="card-header-title">Activity</div>
            <?php if ($app && !empty($activities)): ?>
                <button type="button" title="Clear all activity" onclick="openModal('deleteAllActivitiesModal')"
                    style="background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:4px;line-height:1;display:flex;align-items:center;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding-top:6px;">
            <?php if (empty($activities)): ?>
                <p style="font-size:13px;color:var(--muted-dark);">No activity yet.</p>
            <?php else:
                $lastDate = null;
                foreach ($activities as $ac):
                    $acDate = date('M j, Y', strtotime($ac['created_at']));
                    if ($acDate !== $lastDate): $lastDate = $acDate; ?>
                        <div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 8px;"><?= htmlspecialchars($acDate) ?></div>
                    <?php endif; ?>
                    <div class="flex gap-2" style="margin-bottom:14px;align-items:flex-start;position:relative;" onmouseenter="this.querySelector('.act-del').style.opacity='1'" onmouseleave="this.querySelector('.act-del').style.opacity='0'">
                        <div class="avatar" style="<?= avatarStyle('#0F766E', 28, 10) ?>;flex-shrink:0;"><?= htmlspecialchars(getInitials($ac['actor'] ?? 'HR')) ?></div>
                        <div style="flex:1;">
                            <div style="font-size:12px;font-weight:600;color:var(--navy);">
                                <?= htmlspecialchars($ac['actor'] ?? 'System') ?>
                                <span style="font-weight:400;color:var(--muted-dark);"><?= date('g:i A', strtotime($ac['created_at'])) ?></span>
                            </div>
                            <?php foreach (explode("\n", $ac['description']) as $line): ?>
                                <div style="font-size:12px;color:var(--muted-dark);"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="act-del" title="Delete"
                            onclick="openDeleteActivityModal(<?= (int)$ac['activity_id'] ?>)"
                            style="opacity:0;transition:opacity .15s;flex-shrink:0;background:none;border:none;cursor:pointer;color:#DC2626;font-size:16px;line-height:1;padding:2px 4px;">×</button>
                    </div>
                <?php endforeach;
            endif; ?>
        </div>
    </div>
</div>

<!-- Refuse Modal -->
<?php if ($app && !$isRefused): ?>
<div class="modal-overlay" id="refuseModal" style="display:none;">
    <div class="modal" style="width:600px;">
        <div class="modal-header">
            <div><div class="modal-title">Refuse Reason</div><div class="modal-subtitle">Select a reason or type a custom one</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="do_refuse" value="1">
            <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
            <input type="hidden" name="refuse_reason" id="refuseReasonInput">
            <input type="hidden" name="send_email" id="sendEmailInput" value="0">
            <input type="hidden" name="email_subject" id="emailSubjectHidden">
            <input type="hidden" name="email_body" id="emailBodyHidden">
            <div class="modal-body">
                <!-- Reason chips -->
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                    <?php foreach ($refuseReasons as $r): ?>
                    <button type="button" class="refuse-chip"
                        onclick="selectRefuseChip(this,'<?= htmlspecialchars($r, ENT_QUOTES) ?>')"
                        style="padding:6px 14px;border-radius:999px;border:1.5px solid var(--border);background:#fff;font-size:12px;font-weight:500;cursor:pointer;color:var(--navy);transition:all .15s;">
                        <?= htmlspecialchars($r) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label">Custom reason</label>
                    <input type="text" id="refuseCustom" class="form-control" placeholder="Or type a reason…"
                        oninput="document.getElementById('refuseReasonInput').value=this.value;">
                </div>

                <!-- Send Email toggle -->
                <div style="display:flex;align-items:center;gap:12px;padding-top:16px;border-top:1px solid var(--border);margin-bottom:14px;">
                    <span style="font-size:13px;font-weight:600;color:var(--navy);">Send Email</span>
                    <button type="button" id="emailToggleBtn" onclick="toggleEmail()"
                        style="width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;background:#CBD5E1;position:relative;flex-shrink:0;transition:background .2s;"
                        data-on="0">
                        <span id="emailToggleDot" style="position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .2s;"></span>
                    </button>
                    <span id="emailToggleLabel" style="font-size:12px;color:var(--muted-dark);">OFF</span>
                </div>

                <!-- Email template (shown when toggle ON) -->
                <div id="emailSection" style="display:none;">
                    <div style="background:#F8FAFC;border:1.5px solid var(--border);border-radius:8px;padding:14px;">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label">To</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($candidate['email'] ?? '') ?>" readonly style="background:#fff;color:var(--muted-dark);">
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label">Subject</label>
                            <input type="text" id="emailSubject" class="form-control" style="background:#fff;"
                                value="Your application for <?= htmlspecialchars($app['job_title'] ?? 'the position') ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Message</label>
                            <textarea id="emailBody" class="form-control" rows="6" style="background:#fff;resize:vertical;">Dear <?= htmlspecialchars($candidate['full_name']) ?>,

Thank you for your interest in the <?= htmlspecialchars($app['job_title'] ?? 'position') ?> role. After careful consideration, we regret to inform you that we will not be moving forward with your application at this time.

We appreciate the time you invested and wish you the best in your search.

Best regards,
HR Team</textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('refuseModal')">Discard</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;"
                    onclick="document.getElementById('emailSubjectHidden').value=document.getElementById('emailSubject').value;document.getElementById('emailBodyHidden').value=document.getElementById('emailBody').value;">Refuse</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function setEval(n) {
    document.getElementById('evalInput').value = n;
    document.querySelectorAll('#evalWrap [data-star]').forEach(function(el) {
        el.style.color = parseInt(el.dataset.star) <= n ? '#F59E0B' : '#E2E8F0';
    });
}
function selectRefuseChip(btn, reason) {
    document.querySelectorAll('.refuse-chip').forEach(function(b) {
        b.style.background = '#fff';
        b.style.borderColor = 'var(--border)';
        b.style.color = 'var(--navy)';
    });
    btn.style.background = '#FCE4E4';
    btn.style.borderColor = '#DC2626';
    btn.style.color = '#DC2626';
    document.getElementById('refuseReasonInput').value = reason;
    document.getElementById('refuseCustom').value = reason;
}
function toggleEmail() {
    var btn  = document.getElementById('emailToggleBtn');
    var dot  = document.getElementById('emailToggleDot');
    var lbl  = document.getElementById('emailToggleLabel');
    var sec  = document.getElementById('emailSection');
    var isOn = btn.getAttribute('data-on') === '1';
    if (isOn) {
        btn.setAttribute('data-on', '0');
        btn.style.background = '#CBD5E1';
        dot.style.left = '3px';
        lbl.textContent = 'OFF';
        sec.style.display = 'none';
        document.getElementById('sendEmailInput').value = '0';
    } else {
        btn.setAttribute('data-on', '1');
        btn.style.background = '#0F766E';
        dot.style.left = '23px';
        lbl.textContent = 'ON';
        sec.style.display = 'block';
        document.getElementById('sendEmailInput').value = '1';
    }
}
</script>

<!-- Delete All Activities Modal -->
<?php if ($app): ?>
<div class="modal-overlay" id="deleteAllActivitiesModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:18px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#FEF2F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <div class="modal-title" style="font-size:17px;color:#DC2626;">Clear Activity Log</div>
                    <div class="modal-subtitle" style="margin-top:2px;">This cannot be undone</div>
                </div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="delete_all_activities" value="1">
            <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
            <div class="modal-body" style="padding-top:18px;">
                <p style="font-size:14px;color:var(--navy);margin:0;">Are you sure you want to delete all activity entries for this candidate?</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteAllActivitiesModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;padding:10px 24px;">Yes, Clear All</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Delete Single Activity Modal -->
<div class="modal-overlay" id="deleteActivityModal" style="display:none;">
    <div class="modal" style="width:380px;">
        <div class="modal-header" style="border-bottom:1px solid #F1F5F9;padding-bottom:16px;">
            <div class="modal-title" style="color:#DC2626;">Delete Entry</div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" id="deleteActivityForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="delete_activity" value="1">
            <input type="hidden" name="activity_id" id="deleteActivityIdInput" value="">
            <div class="modal-body" style="padding-top:16px;">
                <p style="font-size:14px;color:var(--navy);margin:0;">Delete this activity entry?</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #F1F5F9;padding-top:14px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#DC2626;border-color:#DC2626;padding:8px 20px;">Delete</button>
            </div>
        </form>
    </div>
</div>
<script>
function openDeleteActivityModal(actId) {
    document.getElementById('deleteActivityIdInput').value = actId;
    openModal('deleteActivityModal');
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



