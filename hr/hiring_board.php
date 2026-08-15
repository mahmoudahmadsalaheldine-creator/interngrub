<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');

$pdo = getDB();

// Add department
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
    $deptName = trim($_POST['department_name'] ?? '');
    if ($deptName !== '') {
        $pdo->prepare("INSERT INTO department (department_name) VALUES (?)")->execute([$deptName]);
    }
    header('Location: ' . BASE_URL . '/hr/hiring_board.php');
    exit;
}

// AJAX drag-drop status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $appId  = (int)$_POST['application_id'];
    $status = $_POST['status'] ?? 'new';
    $pdo->prepare("UPDATE application SET status=? WHERE application_id=?")->execute([$status, $appId]);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Filters
$jobFilter  = (int)($_GET['job_id'] ?? 0);
$deptFilter = $_GET['department_id'] ?? '';
$search     = trim($_GET['q'] ?? '');

$sql = "
    SELECT a.*, c.full_name AS candidate_name, c.email AS candidate_email,
           c.phone, c.cv_link, c.evaluation, c.linkedin_profile,
           j.title AS job_title, d.department_name
    FROM application a
    JOIN candidate c ON a.candidate_id=c.candidate_id
    JOIN job j ON a.job_id=j.job_id
    LEFT JOIN department d ON j.department_id=d.department_id
    WHERE 1=1
";
$params = [];
if ($jobFilter > 0)    { $sql .= " AND a.job_id=?";        $params[] = $jobFilter; }
if ($deptFilter !== '') { $sql .= " AND j.department_id=?"; $params[] = $deptFilter; }
if ($search !== '')    { $sql .= " AND (c.full_name LIKE ? OR c.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " ORDER BY a.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$applications = $stmt->fetchAll();

$pipelineStages = [
    'new'             => 'New',
    'first_interview' => 'First Interview',
    'accepted'        => 'Accepted',
    'rejected'        => 'Rejected',
];

$kanbanColors = [
    'new'             => ['bg'=>'#EFF6FF','bar'=>'#1D4ED8'],
    'first_interview' => ['bg'=>'#EDE8F5','bar'=>'#7B5EA7'],
    'accepted'        => ['bg'=>'#E3F5EA','bar'=>'#15803D'],
    'rejected'        => ['bg'=>'#FCE4E4','bar'=>'#DC2626'],
];

// Group by stage
$byStage = [];
foreach ($pipelineStages as $sv => $sl) $byStage[$sv] = [];
foreach ($applications as $a) {
    $s = $a['status'] ?? 'new';
    if (!isset($byStage[$s])) $byStage[$s] = [];
    $byStage[$s][] = $a;
}

$jobs        = $pdo->query("SELECT job_id, title FROM job ORDER BY title")->fetchAll();
$departments = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();

$pageTitle    = 'Hiring Board';
$pageSubtitle = 'Move candidates through the pipeline';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<!-- Filters -->
<div class="card" style="margin-bottom:18px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" class="form-control search-input" placeholder="Search candidate…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="job_id" class="form-control" style="width:auto;">
            <option value="0">All Jobs</option>
            <?php foreach ($jobs as $j): ?>
                <option value="<?= (int)$j['job_id'] ?>" <?= $jobFilter===$j['job_id']?'selected':'' ?>><?= htmlspecialchars($j['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department_id" class="form-control" style="width:auto;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter===(string)$d['department_id']?'selected':'' ?>><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
        <?php if ($jobFilter || $deptFilter || $search): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:var(--muted-dark);"><?= count($applications) ?> candidate<?= count($applications) !== 1 ? 's' : '' ?></span>
        <button type="button" class="btn btn-outline btn-sm" onclick="openModal('addDeptModal')" style="flex-shrink:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Department</button>
    </form>
</div>

<!-- Board -->
<div class="hiring-board-scroll" style="display:flex;gap:14px;overflow-x:auto;padding-bottom:20px;align-items:flex-start;-webkit-overflow-scrolling:touch;">
    <?php foreach ($pipelineStages as $sv => $sl):
        $cards = $byStage[$sv] ?? [];
        $col   = $kanbanColors[$sv];
    ?>
    <div class="kanban-col" data-stage="<?= htmlspecialchars($sv) ?>"
        style="flex:0 0 230px;background:#F8FAFC;border-radius:14px;border:1.5px solid var(--border);min-height:300px;"
        ondragover="event.preventDefault();this.style.outline='2px dashed var(--purple)';"
        ondragleave="this.style.outline='none';"
        ondrop="onDrop(event,this)">

        <!-- Column header -->
        <div style="padding:14px 16px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;position:sticky;top:0;background:#F8FAFC;border-radius:14px 14px 0 0;z-index:1;">
            <span style="width:10px;height:10px;border-radius:50%;background:<?= $col['bar'] ?>;flex-shrink:0;display:inline-block;"></span>
            <span style="font-size:12px;font-weight:700;color:var(--navy);"><?= htmlspecialchars($sl) ?></span>
            <span style="margin-left:auto;font-size:11px;font-weight:600;color:var(--muted-dark);background:#E2E8F0;border-radius:999px;padding:2px 9px;min-width:20px;text-align:center;"><?= count($cards) ?></span>
        </div>

        <!-- Cards -->
        <div class="kanban-cards" style="padding:10px;display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($cards as $a): ?>
            <div class="kanban-card" draggable="true"
                data-app-id="<?= (int)$a['application_id'] ?>"
                data-candidate-id="<?= (int)$a['candidate_id'] ?>"
                ondragstart="onDragStart(event,this)"
                style="background:#fff;border-radius:10px;border:1.5px solid var(--border);padding:12px 14px;cursor:grab;transition:box-shadow .15s;border-left:4px solid <?= $col['bar'] ?>;position:relative;"
                onmouseenter="this.style.boxShadow='0 4px 14px rgba(0,0,0,.1)'"
                onmouseleave="this.style.boxShadow='none'">

                <!-- Info icon -->
                <button type="button" onclick="event.stopPropagation();openCandInfo(<?= (int)$a['application_id'] ?>)" title="View info"
                    style="position:absolute;top:8px;right:8px;width:22px;height:22px;border-radius:6px;background:#F1F5F9;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748B;padding:0;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                </button>

                <!-- Name + job -->
                <a href="<?= BASE_URL ?>/hr/candidate_profile.php?id=<?= (int)$a['candidate_id'] ?>"
                   style="font-size:13px;font-weight:700;color:var(--navy);text-decoration:none;display:block;margin-bottom:2px;line-height:1.3;"
                   onclick="event.stopPropagation()"><?= htmlspecialchars($a['candidate_name']) ?></a>
                <div style="font-size:11px;color:var(--muted-dark);margin-bottom:10px;"><?= htmlspecialchars($a['job_title']) ?><?= !empty($a['department_name']) ? ' · ' . htmlspecialchars($a['department_name']) : '' ?></div>

                <!-- Email -->
                <?php if (!empty($a['candidate_email'])): ?>
                <div style="font-size:11px;color:var(--muted-dark);margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($a['candidate_email']) ?>
                </div>
                <?php endif; ?>

                <!-- Bottom row -->
                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                    <?php if (($a['evaluation'] ?? 0) > 0): ?>
                        <span style="font-size:11px;color:#F59E0B;letter-spacing:-1px;"><?= str_repeat('★',(int)$a['evaluation']) ?><?= str_repeat('☆',3-(int)$a['evaluation']) ?></span>
                    <?php endif; ?>
                    <?php $ksVal = $a['kanban_state'] ?? 'in_progress'; $dotColor=['in_progress'=>'#94A3B8','normal'=>'#94A3B8','ready'=>'#16A34A','terminated'=>'#DC2626','blocked'=>'#DC2626'][$ksVal] ?? '#94A3B8'; ?>
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $dotColor ?>;display:inline-block;flex-shrink:0;" title="<?= htmlspecialchars($a['kanban_state']??'in_progress') ?>"></span>
                    <span style="font-size:10px;color:var(--muted-dark);margin-left:auto;"><?= date('M j', strtotime($a['application_date'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="kanban-empty" style="font-size:12px;color:var(--muted-dark);text-align:center;padding:24px 0;opacity:.55;<?= !empty($cards) ? 'display:none;' : '' ?>">No candidates</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Candidate Info Modal -->
<div class="modal-overlay" id="candInfoModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div class="modal-title" id="ciName"></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body" style="padding-top:16px;">
            <div style="font-size:12px;color:var(--muted-dark);margin-bottom:16px;" id="ciJob"></div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--muted-dark);"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,4 12,13 2,4"/></svg>
                    <span id="ciEmail" style="color:var(--navy);"></span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--muted-dark);"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 8.91a16 16 0 0 0 6 6l.77-.77a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.73 16z"/></svg>
                    <span id="ciPhone" style="color:var(--navy);"></span>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:18px;flex-wrap:wrap;">
                <a id="ciCv" href="#" target="_blank" style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;background:#EDE8F5;color:#7B5EA7;text-decoration:none;border:1.5px solid #D8C9F4;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    View CV
                </a>
                <a id="ciLinkedin" href="#" target="_blank" style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;background:#EFF6FF;color:#0A66C2;text-decoration:none;border:1.5px solid #BFDBFE;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                    LinkedIn
                </a>
                <a id="ciProfile" href="#" style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;background:#F0FDF4;color:#15803D;text-decoration:none;border:1.5px solid #BBF7D0;margin-left:auto;">
                    Full Profile →
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal-overlay" id="addDeptModal" style="display:none;">
    <div class="modal" style="width:400px;">
        <div class="modal-header">
            <div class="modal-title">Add Department</div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="add_department" value="1">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Department Name <span style="color:#DC2626;">*</span></label>
                    <input type="text" name="department_name" class="form-control" placeholder="e.g. Marketing" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addDeptModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden drag-drop form -->
<form id="dragDropForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="update_status" value="1">
    <input type="hidden" name="application_id" id="ddAppId">
    <input type="hidden" name="status" id="ddStatus">
</form>

<script>
var __candMap = <?= json_encode(array_column($applications, null, 'application_id'), JSON_HEX_TAG) ?>;
function openCandInfo(appId) {
    var d = __candMap[appId];
    if (!d) return;
    document.getElementById('ciName').textContent    = d.candidate_name;
    document.getElementById('ciJob').textContent     = d.job_title + (d.department_name ? ' · ' + d.department_name : '');
    document.getElementById('ciEmail').textContent   = d.candidate_email || '';
    document.getElementById('ciPhone').textContent   = d.phone || '';
    var cvEl = document.getElementById('ciCv');
    cvEl.href = d.cv_link || '#';
    cvEl.style.display = d.cv_link ? '' : 'none';
    var liEl = document.getElementById('ciLinkedin');
    liEl.href = d.linkedin_profile || '#';
    liEl.style.display = d.linkedin_profile ? '' : 'none';
    document.getElementById('ciProfile').href = '<?= BASE_URL ?>/hr/candidate_profile.php?id=' + d.candidate_id;
    openModal('candInfoModal');
}

var dragCard = null;

function onDragStart(e, el) {
    dragCard = el;
    e.dataTransfer.effectAllowed = 'move';
    setTimeout(function(){ el.style.opacity = '.4'; }, 0);
}
document.addEventListener('dragend', function() {
    if (dragCard) { dragCard.style.opacity = '1'; dragCard = null; }
});

function onDrop(e, col) {
    e.preventDefault();
    col.style.outline = 'none';
    if (!dragCard) return;
    var srcCol    = dragCard.closest('.kanban-col');
    var newStage  = col.getAttribute('data-stage');
    var appId     = dragCard.getAttribute('data-app-id');
    var cardsWrap = col.querySelector('.kanban-cards');

    var destEmpty = col.querySelector('.kanban-empty');
    if (destEmpty) destEmpty.style.display = 'none';

    cardsWrap.appendChild(dragCard);

    if (srcCol && srcCol !== col) {
        var srcEmpty = srcCol.querySelector('.kanban-empty');
        if (srcEmpty && srcCol.querySelectorAll('.kanban-card').length === 0) {
            srcEmpty.style.display = '';
        }
    }

    var colors = <?= json_encode($kanbanColors) ?>;
    dragCard.style.borderLeft = '4px solid ' + (colors[newStage] ? colors[newStage].bar : '#ccc');
    updateCounts();

    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: 'update_status=1&application_id=' + encodeURIComponent(appId) + '&status=' + encodeURIComponent(newStage)
    });
}

function updateCounts() {
    document.querySelectorAll('.kanban-col').forEach(function(col) {
        var n = col.querySelectorAll('.kanban-card').length;
        col.querySelector('span[style*="border-radius:999px"]').textContent = n;
    });
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>



