<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin', 'manager');

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ip.*, u.full_name, u.username, u.email, u.phone, u.status AS user_status
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    WHERE ip.intern_id = ?
");
$stmt->execute([$id]);
$intern = $stmt->fetch();

if (!$intern) {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}

$scopeDeptId = managerScopeDeptId();
if ($scopeDeptId !== null && (int)($intern['department_id'] ?? 0) !== $scopeDeptId) {
    header('Location: ' . BASE_URL . '/admin/interns.php');
    exit;
}

// Pure POST handler — editing now happens in a modal on intern_profile.php.
// A GET request here (e.g. a stale bookmark) just bounces back to the profile.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/intern_profile.php?id=' . $id);
    exit;
}

csrf_verify();
$fullName   = trim($_POST['full_name'] ?? '');
$username   = trim($_POST['username'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$department = $_POST['department_id'] ?? '';
$position   = trim($_POST['position'] ?? '');
$startDate  = $_POST['start_date'] ?? '';
$endDate    = $_POST['end_date'] ?? '';
$status     = $_POST['internship_status'] ?? 'active';
$probDate   = $_POST['probation_end_date'] ?? '';
$newPw      = $_POST['new_password'] ?? '';
$confirmPw  = $_POST['confirm_password'] ?? '';

if ($scopeDeptId !== null) {
    $department = (string)$scopeDeptId;
}

$valErr = vFirst([
    vRequired($fullName, 'Full name'), vMaxLen($fullName, 100, 'Full name'),
    $username !== '' ? vUsername($username) : '',
    $newPw !== '' ? vMinLen($newPw, 8, 'Password') : '',
    vMaxLen($phone, 20, 'Phone'),
    vMaxLen($position, 100, 'Position'),
    vDate($startDate, 'Start date'), vDate($endDate, 'End date'), vDate($probDate, 'Probation end date'),
]);
if ($valErr !== '') {
    setToast($valErr, 'error');
    header('Location: ' . BASE_URL . '/admin/intern_profile.php?id=' . $id);
    exit;
}

if ($fullName !== '') {
    $pdo->beginTransaction();
    try {
        $userStatus = in_array($status, ['active', 'on_leave']) ? 'active' : 'inactive';

        if ($username !== '') {
            // Check uniqueness (exclude self)
            $chk = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ? AND user_id != ?");
            $chk->execute([$username, $intern['user_id']]);
            if ((int)$chk->fetchColumn() > 0) $username = ''; // keep old if taken
        }

        if ($username !== '' && $newPw !== '' && strlen($newPw) >= 6) {
            $hashed = password_hash($newPw, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE user SET full_name = ?, username = ?, phone = ?, status = ?, password = ? WHERE user_id = ?");
            $stmt->execute([$fullName, $username, $phone, $userStatus, $hashed, $intern['user_id']]);
        } elseif ($username !== '') {
            $stmt = $pdo->prepare("UPDATE user SET full_name = ?, username = ?, phone = ?, status = ? WHERE user_id = ?");
            $stmt->execute([$fullName, $username, $phone, $userStatus, $intern['user_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE user SET full_name = ?, phone = ?, status = ? WHERE user_id = ?");
            $stmt->execute([$fullName, $phone, $userStatus, $intern['user_id']]);
        }

        $stmt = $pdo->prepare("UPDATE intern_profile SET department_id = ?, position = ?, start_date = ?, end_date = ?, probation_end_date = ?, internship_status = ? WHERE intern_id = ?");
        $stmt->execute([
            $department !== '' ? $department : null,
            $position !== '' ? $position : null,
            $startDate !== '' ? $startDate : null,
            $endDate !== '' ? $endDate : null,
            $probDate !== '' ? $probDate : null,
            $status,
            $id,
        ]);
        $pdo->commit();
        setToast('Intern updated successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
    }
}

header('Location: ' . BASE_URL . '/admin/intern_profile.php?id=' . $id);
exit;

