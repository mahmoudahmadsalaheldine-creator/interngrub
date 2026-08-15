<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');
header('Location: ' . BASE_URL . '/hr/candidate_cards.php');
exit;
$error   = '';
$success = '';

// Add candidate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_candidate'])) {
    $name    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $cv      = trim($_POST['cv_link'] ?? '');
    $jobId   = (int)($_POST['job_id'] ?? 0);
    $appDate = $_POST['application_date'] ?? date('Y-m-d');

    if ($name === '') {
        $error = 'Candidate name is required.';
    } elseif ($jobId <= 0) {
        $error = 'Please select a position.';
    } else {
        $pdo->prepare("INSERT INTO candidate (full_name, email, phone, cv_link) VALUES (?,?,?,?)")
            ->execute([$name, $email ?: null, $phone ?: null, $cv ?: null]);
        $candidateId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO application (candidate_id, job_id, application_date, status) VALUES (?,?,?,'new')")
            ->execute([$candidateId, $jobId, $appDate]);
        $success = 'Candidate added successfully.';
    }
}

// Delete candidate
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare("DELETE FROM candidate WHERE candidate_id=?")->execute([(int)$_GET['delete']]);
    header('Location: ' . BASE_URL . '/hr/candidates.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT c.*, COUNT(a.application_id) AS app_count FROM candidate c LEFT JOIN application a ON a.candidate_id=c.candidate_id WHERE 1=1";
$params = [];
if ($search !== '') { $sql .= " AND (c.full_name LIKE ? OR c.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " GROUP BY c.candidate_id ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$candidates = $stmt->fetchAll();

$openJobs = $pdo->query("SELECT job_id, title FROM job WHERE status='open' ORDER BY title")->fetchAll();

$pageTitle    = 'Candidates';
$pageSubtitle = 'Candidate database';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <div class="search-wrap" style="margin-right:auto;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" class="form-control search-input" placeholder="Search candidates…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn btn-outline">Search</button>
        <button type="button" class="btn btn-primary" onclick="openModal('addCandidateModal')" style="margin-left:auto;">+ Add Candidate</button>
    </form>
</div>

<div class="card" style="margin-top:18px;">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>CV</th><th>Applications</th><th>Added</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($candidates)): ?>
                    <tr><td colspan="7" class="text-muted">No candidates yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($candidates as $c): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>"
                               style="font-weight:600;color:var(--navy);text-decoration:none;"><?= htmlspecialchars($c['full_name']) ?></a>
                        </td>
                        <td class="cell-muted"><?= htmlspecialchars($c['email'] ?? '') ?></td>
                        <td class="cell-muted"><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                        <td>
                            <?php if ($c['cv_link']): ?>
                                <a href="<?= htmlspecialchars($c['cv_link']) ?>" target="_blank" class="btn btn-outline btn-sm">View CV</a>
                            <?php else: ?>
                                <span class="cell-muted"></span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?= BASE_URL ?>/hr/applications.php?candidate_id=<?= (int)$c['candidate_id'] ?>" style="font-weight:600;color:#0F766E;"><?= (int)$c['app_count'] ?></a></td>
                        <td class="cell-muted"><?= htmlspecialchars(date('M j, Y', strtotime($c['created_at']))) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$c['candidate_id'] ?>" class="btn btn-outline btn-sm">Profile</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Candidate Modal -->
<div class="modal-overlay" id="addCandidateModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div><div class="modal-title">Add Candidate</div><div class="modal-subtitle">Manually add a candidate to the database</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#DC2626;">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Sara Khoury" required>
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
                        <input type="email" name="email" class="form-control" placeholder="candidate@email.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+961 XX XXX XXX" oninput="this.value=this.value.replace(/[^0-9+\-\s]/g,'')">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">CV Link <span style="font-weight:400;font-size:12px;color:var(--muted-dark);">(OneDrive / Drive)</span></label>
                        <input type="url" name="cv_link" class="form-control" placeholder="https://...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Application Date</label>
                        <input type="date" name="application_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addCandidateModal')">Cancel</button>
                <button type="submit" name="add_candidate" value="1" class="btn btn-primary">Add Candidate</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



