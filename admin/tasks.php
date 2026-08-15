<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo         = getDB();
$error       = '';
$scopeDeptId = managerScopeDeptId();

// ── Approve / Send back (inline modal submit) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'])) {
    $taskId   = (int)($_POST['task_id'] ?? 0);
    $action   = $_POST['review_action'];
    $feedback = trim($_POST['feedback'] ?? '');

    $taskRow = $pdo->prepare("SELECT * FROM task WHERE task_id = ?");
    $taskRow->execute([$taskId]);
    $taskRow = $taskRow->fetch();

    if ($taskRow && $taskId > 0) {
        // Fetch all assignee user_ids (group tasks may have many)
        $assigneeUsers = $pdo->prepare("
            SELECT u.user_id FROM task_assignment ta
            JOIN intern_profile ip ON ta.intern_id = ip.intern_id
            JOIN user u ON ip.user_id = u.user_id
            WHERE ta.task_id = ?
        ");
        $assigneeUsers->execute([$taskId]);
        $internUserIds = $assigneeUsers->fetchAll(\PDO::FETCH_COLUMN);

        if ($action === 'approve') {
            $pdo->prepare("UPDATE task SET task_status = 'done' WHERE task_id = ?")->execute([$taskId]);
            $pdo->prepare("UPDATE task_assignment SET assignment_status = 'completed' WHERE task_id = ?")->execute([$taskId]);
            foreach ($internUserIds as $iuid) {
                $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                    ->execute([$iuid, "Your task \"{$taskRow['title']}\" was approved and marked done."]);
            }
            setToast('Task approved and marked done.');
        } elseif ($action === 'send_back') {
            $pdo->prepare("UPDATE task SET task_status = 'in_progress' WHERE task_id = ?")->execute([$taskId]);
            if ($feedback !== '') {
                $pdo->prepare("INSERT INTO task_update (task_id, user_id, update_text) VALUES (?, ?, ?)")
                    ->execute([$taskId, $_SESSION['user_id'], "[Feedback] " . $feedback]);
            }
            foreach ($internUserIds as $iuid) {
                $msg = "Your task \"{$taskRow['title']}\" was sent back to In Progress.";
                if ($feedback !== '') $msg .= " Feedback: " . $feedback;
                $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                    ->execute([$iuid, $msg]);
            }
            setToast('Task sent back for revision.', 'info');
        }
    }
    header('Location: ' . BASE_URL . '/admin/tasks.php?' . http_build_query(array_filter(['department_id' => $_GET['department_id'] ?? '', 'intern_id' => $_GET['intern_id'] ?? '', 'task_status' => $_GET['task_status'] ?? '']))); exit;
}

// ── Delete ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task'])) {
    $taskId  = (int)($_POST['task_id'] ?? 0);
    $allowed = true;
    if ($scopeDeptId !== null && $taskId > 0) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM task_assignment ta JOIN intern_profile ip ON ta.intern_id = ip.intern_id WHERE ta.task_id = ? AND ip.department_id = ?");
        $chk->execute([$taskId, $scopeDeptId]);
        $allowed = (int)$chk->fetchColumn() > 0;
    }
    if ($taskId > 0 && $allowed) {
        $pdo->prepare("DELETE FROM task_assignment WHERE task_id = ?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task WHERE task_id = ?")->execute([$taskId]);
        setToast('Task deleted.');
    }
    header('Location: ' . BASE_URL . '/admin/tasks.php?' . http_build_query($_GET)); exit;
}

// ── Edit ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $taskId    = (int)($_POST['task_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $dueDate   = $_POST['due_date'] ?? '';
    $priority  = $_POST['priority'] ?? 'medium';
    $status    = $_POST['task_status'] ?? 'to_do';
    $weekTitle = trim($_POST['week_title'] ?? '');

    $etErr = vFirst([
        vRequired($title, 'Title'), vMaxLen($title, 150, 'Title'),
        vMaxLen($desc, 2000, 'Description'), vMaxLen($weekTitle, 100, 'Week title'),
        vDate($dueDate, 'Due date'),
    ]);
    if ($etErr !== '') { setToast($etErr, 'error'); }
    elseif ($taskId > 0) {
        $allowed = true;
        if ($scopeDeptId !== null) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM task_assignment ta JOIN intern_profile ip ON ta.intern_id = ip.intern_id WHERE ta.task_id = ? AND ip.department_id = ?");
            $chk->execute([$taskId, $scopeDeptId]);
            $allowed = (int)$chk->fetchColumn() > 0;
        }
        if ($allowed) {
            $pdo->prepare("UPDATE task SET title=?, description=?, due_date=?, priority=?, task_status=?, week_title=? WHERE task_id=?")
                ->execute([$title, $desc !== '' ? $desc : null, $dueDate !== '' ? $dueDate : null, $priority, $status, $weekTitle !== '' ? $weekTitle : null, $taskId]);
            setToast('Task updated.');
        }
    }
    header('Location: ' . BASE_URL . '/admin/tasks.php?' . http_build_query($_GET)); exit;
}

// ── Create ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $title      = trim($_POST['title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $dueDate    = $_POST['due_date'] ?? '';
    $priority   = $_POST['priority'] ?? 'medium';
    $weekTitle  = trim($_POST['week_title'] ?? '');
    $isGroup    = !empty($_POST['is_group']) ? 1 : 0;
    $internIds  = array_filter(array_map('intval', $_POST['intern_ids'] ?? []));

    $ctErr = vFirst([
        vRequired($title, 'Title'), vMaxLen($title, 150, 'Title'),
        vMaxLen($desc, 2000, 'Description'), vMaxLen($weekTitle, 100, 'Week title'),
        vDate($dueDate, 'Due date'),
    ]);
    if ($ctErr !== '') {
        $error = $ctErr;
    } elseif ($title === '' || empty($internIds)) {
        $error = 'Title and at least one assignee are required.';
    } else {
        // Scope check for managers
        if ($scopeDeptId !== null) {
            $internIds = array_filter($internIds, function($iid) use ($pdo, $scopeDeptId) {
                $c = $pdo->prepare("SELECT department_id FROM intern_profile WHERE intern_id = ?");
                $c->execute([$iid]);
                return (int)$c->fetchColumn() === $scopeDeptId;
            });
        }
        if (empty($internIds)) {
            $error = 'No valid assignees selected.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO task (created_by, title, description, due_date, priority, task_status, week_title, is_group) VALUES (?, ?, ?, ?, ?, 'to_do', ?, ?)")
                    ->execute([$_SESSION['user_id'], $title, $desc !== '' ? $desc : null, $dueDate !== '' ? $dueDate : null, $priority, $weekTitle !== '' ? $weekTitle : null, count($internIds) > 1 ? 1 : $isGroup]);
                $newTaskId = $pdo->lastInsertId();

                foreach ($internIds as $iid) {
                    $pdo->prepare("INSERT INTO task_assignment (task_id, intern_id, assigned_date, assignment_status) VALUES (?, ?, CURDATE(), 'active')")
                        ->execute([$newTaskId, $iid]);
                    $uStmt = $pdo->prepare("SELECT user_id FROM intern_profile WHERE intern_id = ?");
                    $uStmt->execute([$iid]);
                    $iuid = $uStmt->fetchColumn();
                    if ($iuid) {
                        $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                            ->execute([$iuid, "You have been assigned a new task: \"{$title}\""]);
                    }
                }
                $pdo->commit();
                setToast('Task created and assigned.');
                header('Location: ' . BASE_URL . '/admin/tasks.php?' . http_build_query($_GET)); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to create task.';
            }
        }
    }
}

// ── Fetch tasks ───────────────────────────────────────────────────────────────
$internIdFilter = (int)($_GET['intern_id'] ?? 0);
$deptFilter     = $_GET['department_id'] ?? '';
$statusFilter   = $_GET['task_status'] ?? '';
if ($scopeDeptId !== null) $deptFilter = (string)$scopeDeptId;

$tasksSql = "
    SELECT t.*,
           GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS assignees,
           GROUP_CONCAT(ta.intern_id ORDER BY u.full_name) AS intern_ids,
           MIN(ta.intern_id) AS first_intern_id,
           creator.role AS creator_role
    FROM task t
    LEFT JOIN task_assignment ta ON t.task_id = ta.task_id
    LEFT JOIN intern_profile ip ON ta.intern_id = ip.intern_id
    LEFT JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN user creator ON t.created_by = creator.user_id
";
$tasksParams = [];
$wheres      = [];
if ($scopeDeptId !== null) {
    $wheres[]      = "t.task_id IN (SELECT ta2.task_id FROM task_assignment ta2 JOIN intern_profile ip2 ON ta2.intern_id = ip2.intern_id WHERE ip2.department_id = ?)";
    $tasksParams[] = $scopeDeptId;
} elseif ($deptFilter !== '') {
    $wheres[]      = "t.task_id IN (SELECT ta2.task_id FROM task_assignment ta2 JOIN intern_profile ip2 ON ta2.intern_id = ip2.intern_id WHERE ip2.department_id = ?)";
    $tasksParams[] = $deptFilter;
}
if ($internIdFilter > 0) {
    $wheres[]      = "t.task_id IN (SELECT task_id FROM task_assignment WHERE intern_id = ?)";
    $tasksParams[] = $internIdFilter;
}
if ($statusFilter !== '') {
    $wheres[]      = "t.task_status = ?";
    $tasksParams[] = $statusFilter;
}
if ($wheres) $tasksSql .= " WHERE " . implode(' AND ', $wheres);
$tasksSql .= " GROUP BY t.task_id ORDER BY t.week_title IS NULL ASC, t.week_title ASC, t.created_at DESC";

$tasksStmt = $pdo->prepare($tasksSql);
$tasksStmt->execute($tasksParams);
$tasks = $tasksStmt->fetchAll();

// Group into kanban columns, then sub-group by week_title
$columns = ['to_do' => [], 'in_progress' => [], 'pending_review' => [], 'done' => []];
foreach ($tasks as $t) {
    if (isset($columns[$t['task_status']])) {
        $columns[$t['task_status']][] = $t;
    }
}

// Sub-group each column by week_title
function groupByWeek(array $col): array {
    $g = [];
    foreach ($col as $t) {
        $k = $t['week_title'] ?? '';
        $g[$k][] = $t;
    }
    return $g;
}

$internsSql    = "SELECT ip.intern_id, u.full_name FROM intern_profile ip JOIN user u ON ip.user_id = u.user_id WHERE ip.internship_status = 'active'";
$internsParams = [];
if ($scopeDeptId !== null) { $internsSql .= " AND ip.department_id = ?"; $internsParams[] = $scopeDeptId; }
$internsSql .= " ORDER BY u.full_name";
$internsStmt = $pdo->prepare($internsSql);
$internsStmt->execute($internsParams);
$interns = $internsStmt->fetchAll();

$allDepts  = $pdo->query("SELECT * FROM department ORDER BY department_name")->fetchAll();
$colLabels = ['to_do' => 'To do', 'in_progress' => 'In progress', 'pending_review' => 'Pending review', 'done' => 'Done'];
$colDots   = ['to_do' => '#94A3B8', 'in_progress' => '#F59E0B', 'pending_review' => '#2563EB', 'done' => '#7B5EA7'];
$colColors = ['to_do' => ['bg' => '#F1F5F9', 'val' => '#334155'], 'in_progress' => ['bg' => '#FFF8E6', 'val' => '#B45309'], 'pending_review' => ['bg' => '#EFF6FF', 'val' => '#1D4ED8'], 'done' => ['bg' => '#F0FDF4', 'val' => '#15803D']];

$pageTitle    = 'Task Board';
$pageSubtitle = 'Track work in progress across every team';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:22px;">
    <?php foreach ($colColors as $key => $c): ?>
    <div class="stat-card" style="background:<?= $c['bg'] ?>;border:none;">
        <div class="stat-value" style="color:<?= $c['val'] ?>;font-size:32px;"><?= count($columns[$key]) ?></div>
        <div class="stat-label" style="color:<?= $c['val'] ?>;opacity:.75;"><?= $colLabels[$key] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" class="card-body flex items-center gap-3" style="flex-wrap:wrap;padding:14px 18px;">
        <?php if ($scopeDeptId === null): ?>
        <select name="department_id" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">All Departments</option>
            <?php foreach ($allDepts as $d): ?>
                <option value="<?= (int)$d['department_id'] ?>" <?= $deptFilter === (string)$d['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="intern_id" class="form-control" style="width:auto;">
            <option value="0">All Interns</option>
            <?php foreach ($interns as $i): ?>
                <option value="<?= (int)$i['intern_id'] ?>" <?= $internIdFilter === (int)$i['intern_id'] ? 'selected' : '' ?>><?= htmlspecialchars($i['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="task_status" class="form-control" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="to_do" <?= $statusFilter === 'to_do' ? 'selected' : '' ?>>To do</option>
            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In progress</option>
            <option value="pending_review" <?= $statusFilter === 'pending_review' ? 'selected' : '' ?>>Pending review</option>
            <option value="done" <?= $statusFilter === 'done' ? 'selected' : '' ?>>Done</option>
        </select>
        <button type="submit" class="btn btn-outline">Apply</button>
        <?php if ($internIdFilter || ($deptFilter && $scopeDeptId === null) || $statusFilter): ?>
            <a href="?" class="btn btn-outline" style="color:#DC2626;border-color:#FECACA;">Clear filters</a>
        <?php endif; ?>
        <div style="margin-left:auto;">
            <button type="button" class="btn btn-primary" onclick="openModal('createTaskModal')" style="box-shadow:0 6px 14px rgba(123,94,167,.28);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Create Task
            </button>
        </div>
    </form>
</div>

<div class="kanban-board">
    <?php foreach (['to_do', 'in_progress', 'pending_review', 'done'] as $col): ?>
        <div class="kanban-col">
            <div class="kanban-col-header">
                <span class="kanban-dot" style="background:<?= $colDots[$col] ?>;"></span>
                <span class="kanban-col-label"><?= $colLabels[$col] ?></span>
                <span class="kanban-count-badge"><?= count($columns[$col]) ?></span>
            </div>
            <?php $weekGroups = groupByWeek($columns[$col]); ?>
            <?php foreach ($weekGroups as $weekTitle => $weekCards): ?>
                <?php if ($weekTitle !== ''): ?>
                    <div style="font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#94A3B8;padding:6px 2px 4px;margin-top:10px;border-bottom:1.5px solid var(--border);">
                        <?= htmlspecialchars($weekTitle) ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($weekCards as $t):
                    $proxyPct = null;
                    if ($col === 'in_progress' && !empty($t['due_date']) && !empty($t['created_at'])) {
                        $cTs = strtotime($t['created_at']); $dTs = strtotime($t['due_date']); $nTs = time();
                        if ($dTs > $cTs) $proxyPct = max(0, min(100, round(($nTs - $cTs) / ($dTs - $cTs) * 100)));
                    }
                    $isPending = ($col === 'pending_review');
                ?>
                <div class="task-card <?= $col === 'done' ? 'task-card-done' : '' ?>" style="cursor:pointer;"
                     onclick="<?= $isPending ? "openReviewModal(" . (int)$t['task_id'] . ")" : "showTaskDetail(" . (int)$t['task_id'] . ")" ?>">
                    <div class="task-card-header" style="position:relative;">
                        <div style="display:flex;gap:6px;flex-wrap:wrap;flex:1;">
                            <?= statusPillFor($prioMap, $t['priority']) ?>
                            <?php if ($t['is_group']): ?>
                                <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#EFF6FF;color:#1D4ED8;">Group</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;" onclick="event.stopPropagation();">
                            <?php if ($t['creator_role'] === 'intern'): ?>
                                <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#EDE9FA;color:#7B5EA7;">Self-added</span>
                            <?php endif; ?>
                            <div style="display:flex;gap:4px;">
                                <button onclick="openEditTask(<?= (int)$t['task_id'] ?>)" title="Edit" style="width:26px;height:26px;border-radius:6px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--muted-dark);padding:0;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button onclick="openDeleteTask(<?= (int)$t['task_id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>)" title="Delete" style="width:26px;height:26px;border-radius:6px;border:1.5px solid #FECACA;background:#FEF2F2;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#DC2626;padding:0;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="task-card-title <?= $col === 'done' ? 'task-card-title-done' : '' ?>"><?= htmlspecialchars($t['title']) ?></div>
                    <?php if ($t['description']): ?>
                        <div class="task-card-desc"><?= htmlspecialchars(mb_strimwidth($t['description'], 0, 90, '…')) ?></div>
                    <?php endif; ?>

                    <?php if ($t['is_group'] && $t['assignees']): ?>
                        <div style="margin-top:6px;font-size:11.5px;color:var(--muted-dark);">
                            <span style="font-weight:600;color:var(--navy);">Members:</span> <?= htmlspecialchars($t['assignees']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($col === 'in_progress' && $proxyPct !== null): ?>
                        <div class="progress-track" style="height:6px;margin-bottom:12px;">
                            <div class="progress-fill" style="width:<?= $proxyPct ?>%;background:<?= progFill(100 - $proxyPct) ?>;"></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isPending): ?>
                        <button onclick="event.stopPropagation();openReviewModal(<?= (int)$t['task_id'] ?>)" class="task-card-review-btn">Review</button>
                    <?php endif; ?>

                    <div class="task-card-footer">
                        <div class="task-card-assignee">
                            <?php if ($t['first_intern_id']): ?>
                                <div class="avatar" style="<?= avatarStyle(internColor($t['first_intern_id']), 24, 10.5) ?>"><?= htmlspecialchars(getInitials(explode(', ', $t['assignees'] ?? '')[0] ?? '?')) ?></div>
                            <?php endif; ?>
                            <span class="task-card-assignee-name"><?= htmlspecialchars($t['assignees'] ?? 'Unassigned') ?></span>
                        </div>
                        <?php if ($t['due_date']): ?>
                            <span class="task-card-due">Due <?= htmlspecialchars($t['due_date']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (empty($columns[$col])): ?>
                <p class="text-sm text-muted" style="padding:4px;">No tasks.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
var __tasks = <?= json_encode(array_values($tasks), JSON_HEX_TAG) ?>;
var __taskMap = {};
__tasks.forEach(function(t){ __taskMap[t.task_id] = t; });

var __prioLabel = {low:'Low',medium:'Medium',high:'High'};
var __prioColor = {low:'#16A34A',medium:'#B45309',high:'#DC2626'};
var __prioFill  = {low:'#F0FDF4',medium:'#FFF8E6',high:'#FEF2F2'};
var __statLabel = {to_do:'To do',in_progress:'In progress',pending_review:'Pending review',done:'Done'};
var __statColor = {to_do:'#334155',in_progress:'#B45309',pending_review:'#1D4ED8',done:'#15803D'};
var __statFill  = {to_do:'#F1F5F9',in_progress:'#FFF8E6',pending_review:'#EFF6FF',done:'#F0FDF4'};

function pill(label,color,bg){
    return '<span style="font-size:11.5px;font-weight:700;padding:2px 10px;border-radius:999px;background:'+bg+';color:'+color+';">'+label+'</span>';
}
function showTaskDetail(id) {
    var t = __taskMap[id];
    if (!t) return;
    document.getElementById('tdTitle').textContent    = t.title || '';
    document.getElementById('tdSubtitle').textContent = 'Created ' + (t.created_at ? t.created_at.substring(0,10) : '');
    document.getElementById('tdDesc').textContent     = t.description || 'No description.';
    document.getElementById('tdAssignee').textContent = t.assignees || 'Unassigned';
    document.getElementById('tdPriority').innerHTML   = pill(__prioLabel[t.priority]||t.priority, __prioColor[t.priority]||'#333', __prioFill[t.priority]||'#eee');
    document.getElementById('tdStatus').innerHTML     = pill(__statLabel[t.task_status]||t.task_status, __statColor[t.task_status]||'#333', __statFill[t.task_status]||'#eee');
    document.getElementById('tdDue').textContent      = t.due_date || '';
    openModal('taskDetailModal');
}
function openEditTask(id) {
    var t = __taskMap[id];
    if (!t) return;
    document.getElementById('et_id').value       = t.task_id;
    document.getElementById('et_title').value    = t.title || '';
    document.getElementById('et_desc').value     = t.description || '';
    document.getElementById('et_priority').value = t.priority || 'medium';
    document.getElementById('et_status').value   = t.task_status || 'to_do';
    document.getElementById('et_due').value      = t.due_date || '';
    document.getElementById('et_week').value     = t.week_title || '';
    closeModal('taskDetailModal');
    openModal('editTaskModal');
}
function openDeleteTask(id, title) {
    document.getElementById('dt_id').value            = id;
    document.getElementById('dt_title').textContent   = title;
    openModal('deleteTaskModal');
}

// ── Review modal ─────────────────────────────────────────────────────────────
function openReviewModal(id) {
    var t = __taskMap[id];
    if (!t) return;
    document.getElementById('rv_task_id').value        = id;
    document.getElementById('rvTitle').textContent     = t.title || '';
    document.getElementById('rvAssignee').textContent  = t.assignees || 'Unassigned';
    document.getElementById('rvDue').textContent       = t.due_date || '';
    document.getElementById('rvDesc').textContent      = t.description || '';
    document.getElementById('rvFeedback').value        = '';

    // Load submitted notes for this task via fetch
    var noteWrap = document.getElementById('rvNotes');
    noteWrap.innerHTML = '<span style="color:var(--muted-lightest);font-size:13px;">Loading...</span>';
    fetch('<?= BASE_URL ?>/admin/api_task_updates.php?task_id=' + id)
        .then(function(r){ return r.json(); })
        .catch(function(){ return []; })
        .then(function(updates){
            if (!updates.length) {
                noteWrap.innerHTML = '<span style="color:var(--muted-lightest);font-size:13px;">No notes submitted.</span>';
                return;
            }
            noteWrap.innerHTML = updates.map(function(u){
                return '<div style="padding:10px 12px;background:#F8FAFC;border-radius:8px;border:1.5px solid var(--border);margin-bottom:6px;font-size:13px;">'
                    + '<span style="font-weight:700;color:var(--navy);">' + (u.full_name||'') + '</span>'
                    + '<span style="color:var(--muted-lightest);font-size:11px;margin-left:8px;">' + (u.updated_at||'').substring(0,10) + '</span>'
                    + '<div style="margin-top:6px;color:var(--muted-dark);white-space:pre-wrap;">' + escHtml(u.update_text||'') + '</div>'
                    + '</div>';
            }).join('');
        });
    openModal('reviewTaskModal');
}
function escHtml(s) {
    var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML;
}

function aiSuggestTask() {
    var checkboxes = document.querySelectorAll('#createTaskModal input[name="intern_ids[]"]:checked');
    if (checkboxes.length === 0) {
        showAiMsg('Please select at least one intern first.', '#B45309', '#FFF8E6');
        return;
    }
    var internId = checkboxes[0].value;
    var btn = document.getElementById('aiSuggestBtn');
    btn.disabled = true;
    btn.textContent = 'Thinking...';
    hideAiMsg();

    var fd = new FormData();
    fd.append('intern_id', internId);

    fetch('<?= BASE_URL ?>/admin/api_ai_task.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Suggest with AI';
            if (data.error) {
                showAiMsg(data.error, '#DC2626', '#FEF2F2');
                return;
            }
            document.getElementById('ct_title').value = data.title || '';
            document.getElementById('ct_desc').value  = data.description || '';
            document.getElementById('ct_due').value   = data.due_date || '';
            showAiMsg('AI suggestion applied. Review and edit before submitting.', '#15803D', '#E3F5EA');
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Suggest with AI';
            showAiMsg('Request failed. Check your connection.', '#DC2626', '#FEF2F2');
        });
}
function showAiMsg(msg, color, bg) {
    var el = document.getElementById('aiSuggestMsg');
    el.style.display = 'block';
    el.style.color = color;
    el.style.background = bg;
    el.style.border = '1px solid ' + color + '33';
    el.textContent = msg;
}
function hideAiMsg() {
    document.getElementById('aiSuggestMsg').style.display = 'none';
}
</script>

<!-- Task detail modal -->
<div class="modal-overlay" id="taskDetailModal" style="display:none;">
    <div class="modal" style="width:520px;">
        <div class="modal-header">
            <div><div class="modal-title" id="tdTitle"></div><div class="modal-subtitle" id="tdSubtitle"></div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <div id="tdDesc" style="font-size:14px;color:var(--muted-dark);margin-bottom:18px;white-space:pre-wrap;"></div>
            <div class="form-row" style="margin-bottom:0;">
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Assignee</div><div id="tdAssignee" style="font-size:14px;font-weight:600;color:var(--navy);"></div></div>
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Priority</div><div id="tdPriority"></div></div>
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Status</div><div id="tdStatus"></div></div>
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Due date</div><div id="tdDue" style="font-size:14px;color:var(--navy);"></div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('taskDetailModal')">Close</button>
        </div>
    </div>
</div>

<!-- Review Task Modal -->
<div class="modal-overlay" id="reviewTaskModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Review task</div>
                <div class="modal-subtitle" id="rvTitle" style="font-size:13px;font-weight:600;color:var(--navy);margin-top:2px;"></div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <div class="form-row" style="margin-bottom:14px;">
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Assigned to</div><div id="rvAssignee" style="font-size:13px;font-weight:600;color:var(--navy);"></div></div>
                <div><div style="font-size:11px;font-weight:700;color:var(--muted-lightest);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">Due date</div><div id="rvDue" style="font-size:13px;color:var(--navy);"></div></div>
            </div>
            <div id="rvDesc" style="font-size:13px;color:var(--muted-dark);margin-bottom:14px;padding:10px 12px;background:#F8FAFC;border-radius:8px;border:1.5px solid var(--border);white-space:pre-wrap;"></div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:8px;">Submitted notes</div>
            <div id="rvNotes" style="margin-bottom:14px;"></div>
            <form method="POST" id="reviewForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="task_id" id="rv_task_id">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Feedback <span style="color:var(--muted-lightest);font-weight:400;">(optional, shown to intern)</span></label>
                    <textarea name="feedback" id="rvFeedback" class="form-control" rows="3" style="min-height:74px;" placeholder="Leave a note if sending back..."></textarea>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin:0;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('reviewTaskModal')">Cancel</button>
                    <button type="submit" name="review_action" value="send_back" class="btn btn-outline" style="color:#B45309;border-color:#FCD34D;">Send back</button>
                    <button type="submit" name="review_action" value="approve" class="btn btn-primary" style="background:#15803D;">Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Task Modal -->
<div class="modal-overlay" id="createTaskModal" style="display:none;">
    <div class="modal" style="width:580px;">
        <div class="modal-header">
            <div><div class="modal-title">Create task</div><div class="modal-subtitle">Assign a new task to interns</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Week title <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="text" name="week_title" class="form-control" placeholder="e.g. Vendors Week (groups cards in the board)" data-val-maxlen="100" data-val-label="Week title">
                </div>
                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;">
                        Task title <span style="color:#DC2626;">*</span>
                        <button type="button" id="aiSuggestBtn" onclick="aiSuggestTask()"
                            style="display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:7px;border:1.5px solid #D8C9F4;background:#EDE8F5;color:#7B5EA7;cursor:pointer;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            Suggest with AI
                        </button>
                    </label>
                    <input type="text" name="title" id="ct_title" class="form-control" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                </div>
                <div class="form-group">
                    <label class="form-label">Description <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <textarea name="description" id="ct_desc" class="form-control" style="min-height:74px;" rows="3" data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Assign to <span style="color:#DC2626;">*</span> <span style="color:var(--muted-lightest);font-weight:400;">(select multiple for a group task)</span></label>
                    <div style="background:#F8FAFC;border:1.5px solid var(--border);border-radius:10px;padding:12px;max-height:180px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">
                        <?php foreach ($interns as $i): ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--navy);">
                            <input type="checkbox" name="intern_ids[]" value="<?= (int)$i['intern_id'] ?>" style="width:15px;height:15px;accent-color:var(--purple);">
                            <?= htmlspecialchars($i['full_name']) ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($interns)): ?><span style="font-size:13px;color:var(--muted-lightest);">No active interns.</span><?php endif; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due date</label>
                        <input type="date" name="due_date" id="ct_due" class="form-control" data-val-date data-val-label="Due date">
                    </div>
                </div>
                <div id="aiSuggestMsg" style="display:none;font-size:12px;padding:8px 12px;border-radius:8px;margin-top:-6px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('createTaskModal')">Cancel</button>
                <button type="submit" name="create_task" value="1" class="btn btn-primary">Create task</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Task Modal -->
<div class="modal-overlay" id="editTaskModal" style="display:none;">
    <div class="modal" style="width:560px;">
        <div class="modal-header">
            <div><div class="modal-title">Edit task</div><div class="modal-subtitle">Update task details</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="task_id" id="et_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Week title <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="text" name="week_title" id="et_week" class="form-control" placeholder="e.g. Vendors Week" data-val-maxlen="100" data-val-label="Week title">
                </div>
                <div class="form-group">
                    <label class="form-label">Task title</label>
                    <input type="text" name="title" id="et_title" class="form-control" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="et_desc" class="form-control" style="min-height:74px;" rows="3" data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" id="et_priority" class="form-control">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="task_status" id="et_status" class="form-control">
                            <option value="to_do">To do</option>
                            <option value="in_progress">In progress</option>
                            <option value="pending_review">Pending review</option>
                            <option value="done">Done</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Due date</label>
                    <input type="date" name="due_date" id="et_due" class="form-control" data-val-date data-val-label="Due date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editTaskModal')">Cancel</button>
                <button type="submit" name="edit_task" value="1" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Task Modal -->
<div class="modal-overlay" id="deleteTaskModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div><div class="modal-title">Delete task</div><div class="modal-subtitle">This action cannot be undone</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="task_id" id="dt_id">
            <div class="modal-body">
                <p style="font-size:14px;color:var(--muted-dark);">Are you sure you want to delete <strong id="dt_title"></strong>? All assignments will also be removed.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteTaskModal')">Cancel</button>
                <button type="submit" name="delete_task" value="1" class="btn btn-destructive">Delete</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

