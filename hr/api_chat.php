<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('hr', 'admin');
require_once __DIR__ . '/../includes/ai.php';

header('Content-Type: application/json');

$question = trim($_POST['question'] ?? '');
if ($question === '') {
    echo json_encode(['error' => 'No question provided.']); exit;
}
if (!defined('AI_API_KEY') || AI_API_KEY === '') {
    echo json_encode(['error' => 'AI not configured yet. Add your API key to config.php.']); exit;
}

// Task 2: Rate limit — 20 requests per hour per user
$_rateKey = 'ai_chat_count_' . $_SESSION['user_id'];
$_rateTs  = 'ai_chat_ts_'    . $_SESSION['user_id'];
if (empty($_SESSION[$_rateTs]) || (time() - $_SESSION[$_rateTs]) > 3600) {
    $_SESSION[$_rateKey] = 0;
    $_SESSION[$_rateTs]  = time();
}
if ($_SESSION[$_rateKey] >= 20) {
    echo json_encode(['error' => 'You have reached the hourly limit (20 requests). Please try again later.']); exit;
}
$_SESSION[$_rateKey]++;

// Task 3: Prompt injection guard
if (mb_strlen($question) > 500) {
    echo json_encode(['error' => 'Input too long. Please keep your question under 500 characters.']); exit;
}
$_blocked = ['ignore previous instructions','ignore all instructions','forget everything',
             'forget your instructions','you are now','act as','disregard your',
             'jailbreak','dan mode','override instructions','system prompt'];
foreach ($_blocked as $_phrase) {
    if (stripos($question, $_phrase) !== false) {
        echo json_encode(['error' => 'Invalid input detected.']); exit;
    }
}
unset($_rateKey, $_rateTs, $_blocked, $_phrase);

$pdo = getDB();

// Pull a broad set of stats and recent records to give AI context
$stats = [];
$stats['open_jobs']         = (int)$pdo->query("SELECT COUNT(*) FROM job WHERE status='open'")->fetchColumn();
$stats['total_candidates']  = (int)$pdo->query("SELECT COUNT(*) FROM candidate")->fetchColumn();
$stats['new_applications']  = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='new'")->fetchColumn();
$stats['hired']             = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='contract_signed'")->fetchColumn();
$stats['refused']           = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status='refused'")->fetchColumn();
$stats['interviews_7days']  = (int)$pdo->query("SELECT COUNT(*) FROM interview WHERE interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$stats['active_interns']    = (int)$pdo->query("SELECT COUNT(*) FROM intern_profile WHERE internship_status='active'")->fetchColumn();

// Recent applications
$recentApps = $pdo->query("
    SELECT c.full_name, j.title AS job, a.status, a.kanban_state, a.application_date
    FROM application a
    JOIN candidate c ON a.candidate_id = c.candidate_id
    JOIN job j ON a.job_id = j.job_id
    ORDER BY a.created_at DESC LIMIT 8
")->fetchAll();

$appLines = '';
foreach ($recentApps as $a) {
    $appLines .= "- {$a['full_name']} applied for {$a['job']} | status: {$a['status']} | state: {$a['kanban_state']} | date: {$a['application_date']}\n";
}

// Active interns with department
$interns = $pdo->query("
    SELECT u.full_name, d.department_name, ip.internship_status
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    LEFT JOIN department d ON ip.department_id = d.department_id
    WHERE ip.internship_status = 'active'
    ORDER BY u.full_name LIMIT 20
")->fetchAll();

$internLines = '';
foreach ($interns as $i) {
    $internLines .= "- {$i['full_name']} ({$i['department_name']})\n";
}

// Interviews this week
$interviews = $pdo->query("
    SELECT i.interview_date, i.interview_time, c.full_name AS candidate, j.title AS job
    FROM interview i
    JOIN application a ON i.application_id = a.application_id
    JOIN candidate c ON a.candidate_id = c.candidate_id
    JOIN job j ON a.job_id = j.job_id
    WHERE i.interview_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY i.interview_date ASC LIMIT 10
")->fetchAll();

$ivLines = '';
foreach ($interviews as $iv) {
    $ivLines .= "- {$iv['candidate']} for {$iv['job']} on {$iv['interview_date']} at {$iv['interview_time']}\n";
}
if ($ivLines === '') $ivLines = '- None scheduled this week.';

$context = "You are an HR assistant for InternGrub, an internship management platform.
Today is " . date('Y-m-d') . ".

PLATFORM STATS:
- Open jobs: {$stats['open_jobs']}
- Total candidates: {$stats['total_candidates']}
- New applications: {$stats['new_applications']}
- Hired: {$stats['hired']}
- Refused: {$stats['refused']}
- Interviews in next 7 days: {$stats['interviews_7days']}
- Active interns: {$stats['active_interns']}

RECENT APPLICATIONS (latest 8):
{$appLines}
ACTIVE INTERNS:
{$internLines}
UPCOMING INTERVIEWS:
{$ivLines}

Answer the HR's question in plain English, clearly and concisely.
If the data above does not contain enough information to answer, say so honestly.
Do not make up numbers. Keep answers under 5 sentences unless a list is needed.";

$answer = callClaude($question, $context, 512);

if (!$answer) {
    echo json_encode(['error' => 'AI did not respond. Check your API key.']); exit;
}

echo json_encode(['answer' => $answer]);
