<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('intern');

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT intern_id FROM intern_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$internId = $stmt->fetchColumn();

$error = '';

// Create task (solo or group)
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    $title      = trim($_POST['title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $dueDate    = $_POST['due_date'] ?? '';
    $weekTitle  = trim($_POST['week_title'] ?? '');
    $isGroup    = !empty($_POST['is_group']) ? 1 : 0;
    $groupPeers = array_filter(array_map('intval', $_POST['group_members'] ?? []));

    $error = vFirst([
        vRequired($title, 'Title'), vMaxLen($title, 150, 'Title'),
        vMaxLen($desc, 2000, 'Description'),
        vMaxLen($weekTitle, 100, 'Week title'),
        vDate($dueDate, 'Due date'),
    ]);
    if ($error !== '') {
        // fall through — $error displayed below
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO task (created_by, title, description, due_date, priority, task_status, week_title, is_group) VALUES (?, ?, ?, ?, 'medium', 'to_do', ?, ?)")
                ->execute([$userId, $title, $desc !== '' ? $desc : null, $dueDate !== '' ? $dueDate : null, $weekTitle !== '' ? $weekTitle : null, $isGroup]);
            $newTaskId = $pdo->lastInsertId();

            // Always assign to self
            $members = [$internId];
            if ($isGroup && !empty($groupPeers)) {
                foreach ($groupPeers as $pid) {
                    if ($pid !== (int)$internId) $members[] = $pid;
                }
            }
            $members = array_unique($members);

            foreach ($members as $mid) {
                $pdo->prepare("INSERT INTO task_assignment (task_id, intern_id, assigned_date, assignment_status) VALUES (?, ?, CURDATE(), 'active')")
                    ->execute([$newTaskId, $mid]);
                // Notify group members (not self)
                if ($mid !== (int)$internId) {
                    $memberUser = $pdo->prepare("SELECT user_id FROM intern_profile WHERE intern_id = ?");
                    $memberUser->execute([$mid]);
                    $muid = $memberUser->fetchColumn();
                    if ($muid) {
                        $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                            ->execute([$muid, $_SESSION['full_name'] . " added you to a group task: \"{$title}\""]);
                    }
                }
            }
            $pdo->commit();
            setToast('Task added.');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to create task.';
        }
    }
    if (!$error) {
        header('Location: ' . BASE_URL . '/intern/tasks.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_task'])) {
    $taskId    = (int)($_POST['task_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $dueDate   = $_POST['due_date'] ?? '';
    $weekTitle = trim($_POST['week_title'] ?? '');
    $editErr = vFirst([
        vRequired($title, 'Title'), vMaxLen($title, 150, 'Title'),
        vMaxLen($desc, 2000, 'Description'),
        vMaxLen($weekTitle, 100, 'Week title'),
        vDate($dueDate, 'Due date'),
    ]);
    if ($taskId > 0 && $title !== '' && $editErr === '') {
        $stmt = $pdo->prepare("SELECT task_id FROM task WHERE task_id = ? AND created_by = ?");
        $stmt->execute([$taskId, $userId]);
        if ($stmt->fetchColumn()) {
            $pdo->prepare("UPDATE task SET title=?, description=?, due_date=?, week_title=? WHERE task_id=?")
                ->execute([$title, $desc !== '' ? $desc : null, $dueDate !== '' ? $dueDate : null, $weekTitle !== '' ? $weekTitle : null, $taskId]);
            setToast('Task updated.');
        }
    }
    header('Location: ' . BASE_URL . '/intern/tasks.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task'])) {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $stmt   = $pdo->prepare("SELECT task_id FROM task WHERE task_id = ? AND created_by = ?");
    $stmt->execute([$taskId, $userId]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("DELETE FROM task_assignment WHERE task_id = ?")->execute([$taskId]);
        $pdo->prepare("DELETE FROM task WHERE task_id = ?")->execute([$taskId]);
        setToast('Task deleted.');
    }
    header('Location: ' . BASE_URL . '/intern/tasks.php');
    exit;
}

// Start / submit_review actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['create_task']) && !isset($_POST['delete_task']) && !isset($_POST['edit_task'])) {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare("
        SELECT t.* FROM task t
        JOIN task_assignment ta ON t.task_id = ta.task_id
        WHERE t.task_id = ? AND ta.intern_id = ?
    ");
    $stmt->execute([$taskId, $internId]);
    $task = $stmt->fetch();

    if (!$task) {
        $error = 'Task not found.';
    } elseif ($action === 'start') {
        $pdo->prepare("UPDATE task SET task_status = 'in_progress' WHERE task_id = ?")->execute([$taskId]);
        setToast('Task started.', 'info');
    } elseif ($action === 'submit_review') {
        $note = trim($_POST['update_text'] ?? '');
        $pdo->prepare("UPDATE task SET task_status = 'pending_review' WHERE task_id = ?")->execute([$taskId]);
        if ($note !== '') {
            $pdo->prepare("INSERT INTO task_update (task_id, user_id, update_text) VALUES (?, ?, ?)")
                ->execute([$taskId, $userId, $note]);
        }
        $admins = $pdo->query("SELECT user_id FROM user WHERE role IN ('admin','manager')")->fetchAll();
        foreach ($admins as $a) {
            $pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'task')")
                ->execute([$a['user_id'], $_SESSION['full_name'] . " submitted \"{$task['title']}\" for review."]);
        }
        setToast('Task submitted for review.');
    }
    if (!$error) {
        header('Location: ' . BASE_URL . '/intern/tasks.php');
        exit;
    }
}

// Fetch tasks with group members
$stmt = $pdo->prepare("
    SELECT t.*,
           GROUP_CONCAT(u2.full_name ORDER BY u2.full_name SEPARATOR ', ') AS member_names,
           GROUP_CONCAT(ip2.intern_id ORDER BY u2.full_name) AS member_ids
    FROM task_assignment ta
    JOIN task t ON ta.task_id = t.task_id
    LEFT JOIN task_assignment ta2 ON ta2.task_id = t.task_id
    LEFT JOIN intern_profile ip2 ON ta2.intern_id = ip2.intern_id
    LEFT JOIN user u2 ON ip2.user_id = u2.user_id
    WHERE ta.intern_id = ?
    GROUP BY t.task_id
    ORDER BY t.week_title IS NULL ASC, t.week_title ASC, t.created_at DESC
");
$stmt->execute([$internId]);
$tasks = $stmt->fetchAll();

// Other interns for group task selection
$otherInterns = $pdo->prepare("
    SELECT ip.intern_id, u.full_name
    FROM intern_profile ip JOIN user u ON ip.user_id = u.user_id
    WHERE ip.internship_status = 'active' AND ip.user_id != ?
    ORDER BY u.full_name
");
$otherInterns->execute([$userId]);
$otherInterns = $otherInterns->fetchAll();

// Group tasks by week_title
$grouped = [];
foreach ($tasks as $t) {
    $key = $t['week_title'] ?? '';
    $grouped[$key][] = $t;
}

$pageTitle    = 'My Tasks';
$pageSubtitle = 'Work assigned to you';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
    <div class="filter-pills" id="taskFilterPills" style="margin-bottom:0;">
        <div class="filter-pill active" data-filter="all" onclick="filterTasks('all',this)">All</div>
        <div class="filter-pill" data-filter="to_do" onclick="filterTasks('to_do',this)">To Do</div>
        <div class="filter-pill" data-filter="in_progress" onclick="filterTasks('in_progress',this)">In Progress</div>
        <div class="filter-pill" data-filter="pending_review" onclick="filterTasks('pending_review',this)">Pending Review</div>
        <div class="filter-pill" data-filter="done" onclick="filterTasks('done',this)">Done</div>
    </div>
    <button type="button" class="btn btn-primary" onclick="openModal('addTaskModal')" style="white-space:nowrap;">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Task
    </button>
</div>

<?php if (empty($tasks)): ?>
    <p class="text-muted">No tasks assigned.</p>
<?php endif; ?>

<div id="taskGridWrap">
<?php foreach ($grouped as $weekTitle => $weekTasks): ?>
    <?php if ($weekTitle !== ''): ?>
    <div class="week-title-header" data-week="<?= htmlspecialchars($weekTitle) ?>" style="font-size:18px;font-weight:800;color:var(--navy);margin:24px 0 12px;padding-bottom:8px;border-bottom:2px solid var(--border);">
        <?= htmlspecialchars($weekTitle) ?>
    </div>
    <?php endif; ?>
    <div class="task-grid" data-week-grid="<?= htmlspecialchars($weekTitle) ?>">
        <?php foreach ($weekTasks as $t): ?>
        <?php $isSelfAdded = (int)$t['created_by'] === (int)$userId; ?>
        <div class="task-grid-card" data-status="<?= htmlspecialchars($t['task_status']) ?>">
            <div class="task-grid-card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                <div style="display:flex;gap:6px;flex-wrap:wrap;flex:1;min-width:0;">
                    <?= statusPillFor($prioMap, $t['priority']) ?>
                    <?= statusPillFor($taskStat, $t['task_status']) ?>
                    <?php if ($t['is_group']): ?>
                        <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#EFF6FF;color:#1D4ED8;">Group</span>
                    <?php endif; ?>
                </div>
                <?php if ($isSelfAdded): ?>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                    <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;background:#EDE9FA;color:#7B5EA7;">Self-added</span>
                    <div style="display:flex;gap:4px;">
                        <button type="button" onclick="openInternEditModal(<?= (int)$t['task_id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($t['description'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($t['due_date'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($t['week_title'] ?? ''), ENT_QUOTES) ?>)" title="Edit" style="width:26px;height:26px;border-radius:6px;border:1.5px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--muted-dark);padding:0;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button type="button" onclick="openInternDeleteModal(<?= (int)$t['task_id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>)" title="Delete" style="width:26px;height:26px;border-radius:6px;border:1.5px solid #FECACA;background:#FEF2F2;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#DC2626;padding:0;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="task-grid-card-title"><?= htmlspecialchars($t['title']) ?></div>
            <?php if ($t['description']): ?>
                <div class="task-grid-card-desc"><?= htmlspecialchars($t['description']) ?></div>
            <?php endif; ?>
            <?php if ($t['is_group'] && $t['member_names']): ?>
                <div style="margin-top:8px;font-size:11.5px;color:var(--muted-dark);">
                    <span style="font-weight:600;color:var(--navy);">Group:</span> <?= htmlspecialchars($t['member_names']) ?>
                </div>
            <?php endif; ?>
            <div class="task-grid-card-footer">
                <span class="task-due">Due <?= htmlspecialchars($t['due_date'] ?? '') ?></span>
                <?php if ($t['task_status'] === 'to_do'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="task_id" value="<?= (int)$t['task_id'] ?>">
                        <button type="submit" name="action" value="start" class="btn-start-task">Start task</button>
                    </form>
                <?php elseif ($t['task_status'] === 'in_progress'): ?>
                    <button class="btn-submit-task" onclick="openSubmitModal(<?= (int)$t['task_id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>)">Submit for review</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
</div>

<script>
// Remember active filter across page loads
var _savedFilter = sessionStorage.getItem('taskFilter') || 'all';
function filterTasks(status, pillEl) {
    document.querySelectorAll('#taskFilterPills .filter-pill').forEach(function(p){ p.classList.remove('active'); });
    if (pillEl) pillEl.classList.add('active');
    sessionStorage.setItem('taskFilter', status);
    document.querySelectorAll('.task-grid-card').forEach(function(card){
        card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
    });
    // Hide week headers if all their cards are hidden
    document.querySelectorAll('[data-week-grid]').forEach(function(grid){
        var week = grid.dataset.weekGrid;
        if (!week) return;
        var header = document.querySelector('[data-week="' + week + '"]');
        if (!header) return;
        var visible = grid.querySelectorAll('.task-grid-card:not([style*="display: none"])').length;
        header.style.display = visible === 0 ? 'none' : '';
    });
}
document.addEventListener('DOMContentLoaded', function(){
    var pill = document.querySelector('[data-filter="' + _savedFilter + '"]');
    filterTasks(_savedFilter, pill);
});
</script>

<!-- Submit for review modal -->
<div class="modal-overlay" id="submitModal" style="display:none;">
    <div class="modal" style="width:500px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Submit for review</div>
                <div class="modal-subtitle" id="submitTaskSubtitle"></div>
            </div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="task_id" id="submitTaskId">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Summary of work</label>
                    <textarea name="update_text" class="form-control" style="min-height:96px;" rows="4" placeholder="What did you complete? Anything to flag for review?"></textarea>
                </div>
                <div class="info-banner info-banner-blue">Your supervisor will be notified and can approve or send this task back for changes.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('submitModal')">Cancel</button>
                <button type="submit" name="action" value="submit_review" class="btn btn-primary">Submit</button>
            </div>
        </form>
    </div>
</div>
<script>
function openSubmitModal(id, title) {
    document.getElementById('submitTaskId').value = id;
    document.getElementById('submitTaskSubtitle').textContent = 'Send "' + title + '" to your supervisor';
    openModal('submitModal');
}
</script>

<!-- Edit Task Modal -->
<div class="modal-overlay" id="internEditTaskModal" style="display:none;">
    <div class="modal" style="width:500px;">
        <div class="modal-header">
            <div><div class="modal-title">Edit task</div><div class="modal-subtitle">Update your self-added task</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="task_id" id="iet_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Week title <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="text" name="week_title" id="iet_week" class="form-control" placeholder="e.g. Vendors Week" data-val-maxlen="100" data-val-label="Week title">
                </div>
                <div class="form-group">
                    <label class="form-label">Task title <span style="color:#DC2626;">*</span></label>
                    <input type="text" name="title" id="iet_title" class="form-control" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                </div>
                <div class="form-group">
                    <label class="form-label">Description <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <textarea name="description" id="iet_desc" class="form-control" rows="3" style="min-height:80px;" data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Due date <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="date" name="due_date" id="iet_due" class="form-control" data-val-date data-val-label="Due date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('internEditTaskModal')">Cancel</button>
                <button type="submit" name="edit_task" value="1" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</div>
<script>
function openInternEditModal(id, title, desc, due, week) {
    document.getElementById('iet_id').value    = id;
    document.getElementById('iet_title').value = title;
    document.getElementById('iet_desc').value  = desc;
    document.getElementById('iet_due').value   = due;
    document.getElementById('iet_week').value  = week;
    openModal('internEditTaskModal');
}
</script>

<!-- Delete Task Modal -->
<div class="modal-overlay" id="internDeleteTaskModal" style="display:none;">
    <div class="modal" style="width:420px;">
        <div class="modal-header">
            <div><div class="modal-title">Delete task</div><div class="modal-subtitle">This action cannot be undone</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="task_id" id="idt_id">
            <div class="modal-body">
                <p style="font-size:14px;color:var(--muted-dark);">Are you sure you want to delete <strong id="idt_title"></strong>? This task will be permanently removed.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('internDeleteTaskModal')">Cancel</button>
                <button type="submit" name="delete_task" value="1" class="btn btn-destructive">Delete</button>
            </div>
        </form>
    </div>
</div>
<script>
function openInternDeleteModal(id, title) {
    document.getElementById('idt_id').value = id;
    document.getElementById('idt_title').textContent = title;
    openModal('internDeleteTaskModal');
}
</script>

<!-- Add Task Modal -->
<div class="modal-overlay" id="addTaskModal" style="display:none;">
    <div class="modal" style="width:520px;">
        <div class="modal-header">
            <div><div class="modal-title">Add a task</div><div class="modal-subtitle">This task will be added to your list</div></div>
            <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Week title <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="text" name="week_title" class="form-control" placeholder="e.g. Vendors Week (groups tasks under this label)" data-val-maxlen="100" data-val-label="Week title">
                </div>
                <div class="form-group">
                    <label class="form-label">Task title <span style="color:#DC2626;">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="What do you want to work on?" required data-val-required="Title" data-val-maxlen="150" data-val-label="Title">
                </div>
                <div class="form-group">
                    <label class="form-label">Description <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <textarea name="description" class="form-control" rows="3" style="min-height:80px;" placeholder="Add more details..." data-val-maxlen="2000" data-val-label="Description"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Due date <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                    <input type="date" name="due_date" class="form-control" data-val-date data-val-label="Due date">
                </div>
                <?php if (!empty($otherInterns)): ?>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:10px;">
                        <input type="checkbox" name="is_group" value="1" id="isGroupCheck" onchange="toggleGroupMembers(this.checked)" style="width:16px;height:16px;accent-color:var(--purple);">
                        <span style="font-size:13px;font-weight:600;color:var(--navy);">This is a group task</span>
                    </label>
                    <div id="groupMembersSection" style="display:none;background:#F8FAFC;border:1.5px solid var(--border);border-radius:10px;padding:12px;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94A3B8;margin-bottom:8px;">Select group members</div>
                        <div style="display:flex;flex-direction:column;gap:6px;max-height:160px;overflow-y:auto;">
                            <?php foreach ($otherInterns as $oi): ?>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--navy);">
                                <input type="checkbox" name="group_members[]" value="<?= (int)$oi['intern_id'] ?>" style="width:15px;height:15px;accent-color:var(--purple);">
                                <?= htmlspecialchars($oi['full_name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addTaskModal')">Cancel</button>
                <button type="submit" name="create_task" value="1" class="btn btn-primary">Add Task</button>
            </div>
        </form>
    </div>
</div>
<script>
function toggleGroupMembers(show) {
    document.getElementById('groupMembersSection').style.display = show ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>

