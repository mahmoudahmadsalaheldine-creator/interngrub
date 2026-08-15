<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');
require_once __DIR__ . '/../includes/ai.php';

header('Content-Type: application/json');

$internId = (int)($_POST['intern_id'] ?? 0);
if ($internId <= 0) {
    echo json_encode(['error' => 'No intern selected.']); exit;
}
if (!defined('AI_API_KEY') || AI_API_KEY === '') {
    echo json_encode(['error' => 'AI not configured yet. Add your API key to config.php.']); exit;
}

// Task 2: Rate limit — 20 requests per hour per user
$_rateKey = 'ai_task_count_' . $_SESSION['user_id'];
$_rateTs  = 'ai_task_ts_'    . $_SESSION['user_id'];
if (empty($_SESSION[$_rateTs]) || (time() - $_SESSION[$_rateTs]) > 3600) {
    $_SESSION[$_rateKey] = 0;
    $_SESSION[$_rateTs]  = time();
}
if ($_SESSION[$_rateKey] >= 20) {
    echo json_encode(['error' => 'You have reached the hourly limit (20 requests). Please try again later.']); exit;
}
$_SESSION[$_rateKey]++;
unset($_rateKey, $_rateTs);

$pdo = getDB();

$internRow = $pdo->prepare("
    SELECT ip.intern_id, u.full_name, d.department_name
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE ip.intern_id = ?
");
$internRow->execute([$internId]);
$intern = $internRow->fetch();

if (!$intern) {
    echo json_encode(['error' => 'Intern not found.']); exit;
}

$recentTasks = $pdo->prepare("
    SELECT t.title, t.description, t.task_status
    FROM task t
    JOIN task_assignment ta ON t.task_id = ta.task_id
    WHERE ta.intern_id = ?
    ORDER BY t.created_at DESC LIMIT 5
");
$recentTasks->execute([$internId]);
$tasks = $recentTasks->fetchAll();

$taskLines = '';
foreach ($tasks as $t) {
    $taskLines .= '- ' . $t['title'] . ' (' . $t['task_status'] . ")\n";
}
if ($taskLines === '') $taskLines = '- No previous tasks found.';

$dept    = $intern['department_name'] ?? 'General';
$name    = $intern['full_name'];
$dueDate = date('Y-m-d', strtotime('+7 days'));

$prompt = "You are a task manager assistant for an internship platform called InternGrub.
Suggest one task for the following intern:

Name: {$name}
Department: {$dept}

Their recent tasks:
{$taskLines}

Return a JSON object with exactly these 3 keys:
- title: short task title (max 60 chars)
- description: 2-3 sentence task description
- due_date: suggested due date in YYYY-MM-DD format (about 7 days from today, which is " . date('Y-m-d') . ")

Return only valid JSON. No explanation, no markdown, no extra text.";

$result = callClaude($prompt, '', 300);

if (!$result) {
    echo json_encode(['error' => 'AI did not respond. Check your API key.']); exit;
}

$result = trim(preg_replace('/^```json\s*|\s*```$/i', '', trim($result)));
$parsed = json_decode($result, true);

if (!$parsed || !isset($parsed['title'])) {
    echo json_encode(['error' => 'AI returned unexpected format. Try again.']); exit;
}

echo json_encode([
    'title'       => $parsed['title']       ?? '',
    'description' => $parsed['description'] ?? '',
    'due_date'    => $parsed['due_date']    ?? $dueDate,
]);
