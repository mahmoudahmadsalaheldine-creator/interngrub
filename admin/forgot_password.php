<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if ($username === '') {
        $error = 'Please enter your username.';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT user_id, email, role FROM user WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || $user['role'] !== 'admin') {
            $error = 'No admin account found with that username.';
        } elseif (empty($user['email'])) {
            $error = 'No recovery email is set for this account. Contact your system administrator.';
        } else {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Invalidate any previous OTPs for this user
            $pdo->prepare("UPDATE password_reset SET used = 1 WHERE user_id = ?")->execute([$user['user_id']]);
            $pdo->prepare("INSERT INTO password_reset (user_id, otp, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['user_id'], $otp, $expires]);

            $sent = sendOtpEmail($user['email'], $otp);
            if ($sent) {
                $_SESSION['otp_user_id'] = $user['user_id'];
                header('Location: ' . BASE_URL . '/admin/verify_otp.php');
                exit;
            } else {
                $error = 'Failed to send OTP email. Check SMTP settings in includes/config.php.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <img src="<?= BASE_URL ?>/assets/logo.webp" alt="TeamInternGrub">
        </div>
        <div class="login-title">Reset your password</div>
        <p class="login-sub">Enter your admin username. We'll send an OTP to the email on file.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Send OTP</button>
        </form>

        <div style="text-align:center;margin-top:18px;font-size:13px;">
            <a href="<?= BASE_URL ?>/index.php" style="color:var(--purple);">Back to login</a>
        </div>
    </div>
</div>
</body>
</html>
