<?php
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['otp_user_id'])) {
    header('Location: ' . BASE_URL . '/admin/forgot_password.php');
    exit;
}

$error = '';
$success = '';
$userId = (int)$_SESSION['otp_user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp     = trim($_POST['otp'] ?? '');
    $newPw   = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($otp === '' || $newPw === '') {
        $error = 'All fields are required.';
    } elseif (strlen($newPw) < 4) {
        $error = 'Password must be at least 4 characters.';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT id FROM password_reset
            WHERE user_id = ? AND otp = ? AND used = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $otp]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'Invalid or expired OTP.';
        } else {
            $pdo->prepare("UPDATE password_reset SET used = 1 WHERE id = ?")->execute([$row['id']]);
            $pdo->prepare("UPDATE user SET password = ? WHERE user_id = ?")
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId]);
            unset($_SESSION['otp_user_id']);
            $success = 'Password reset successfully. You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <img src="<?= BASE_URL ?>/assets/logo.webp" alt="TeamInternGrub">
        </div>
        <div class="login-title">Enter OTP</div>
        <p class="login-sub">Check your registered email for the 6-digit code.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <div style="text-align:center;margin-top:14px;">
                <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="display:inline-block;">Go to Login</a>
            </div>
        <?php else: ?>
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">OTP Code</label>
                <input type="text" name="otp" class="form-control" maxlength="6" placeholder="6-digit code" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">New Password</label>
                <div style="position:relative;">
                    <input type="password" id="otp_pw" name="new_password" class="form-control" required style="padding-right:38px;" autocomplete="new-password" oninput="updatePwStrength(this.value,'otp_strength')">
                    <button type="button" onclick="toggleOtpEye(this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#7B5EA7;padding:0;line-height:1;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div id="otp_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Reset Password</button>
        </form>
        <div style="text-align:center;margin-top:18px;font-size:13px;">
            <a href="<?= BASE_URL ?>/admin/forgot_password.php" style="color:var(--purple);">Resend OTP</a>
            &nbsp;&middot;&nbsp;
            <a href="<?= BASE_URL ?>/index.php" style="color:var(--purple);">Back to login</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
function toggleOtpEye(btn) {
    var f = document.getElementById('otp_pw');
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    btn.innerHTML = show
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
function updatePwStrength(pw, barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;
    var fill = bar.querySelector('div');
    if (!fill) return;
    var score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    var colors = ['#DC2626','#F59E0B','#16A34A','#7B5EA7'];
    var widths = ['25%','50%','75%','100%'];
    fill.style.width = pw.length === 0 ? '0' : widths[score - 1] || '25%';
    fill.style.background = pw.length === 0 ? '' : colors[score - 1] || '#DC2626';
}
</script>
</body>
</html>
