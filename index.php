<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (isLoggedIn()) {
    if (in_array($_SESSION['role'], ['admin', 'manager'], true)) {
        header('Location: ' . BASE_URL . '/dashboard.php');
    } elseif ($_SESSION['role'] === 'hr') {
        header('Location: ' . BASE_URL . '/hr/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/intern/dashboard.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $result = login($username, $password);
        if ($result === 'locked') {
            $error = 'Too many failed attempts. Account is temporarily locked. Please try again later.';
        } elseif ($result) {
            if (in_array($result['role'], ['admin', 'manager'], true)) {
                header('Location: ' . BASE_URL . '/dashboard.php');
            } elseif ($result['role'] === 'hr') {
                header('Location: ' . BASE_URL . '/hr/dashboard.php');
            } else {
                header('Location: ' . BASE_URL . '/intern/dashboard.php');
            }
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-logo">
                <img src="<?= BASE_URL ?>/assets/logo.webp" alt="TeamInternGrub">
            </div>

            <div class="login-title" style="font-size:20px;">Welcome back</div>
            <p class="login-sub">Sign in with your username and password.</p>


            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" autofocus
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="loginPw" class="form-control" style="padding-right:38px;">
                        <button type="button" onclick="toggleLoginEye(this)" tabindex="-1"
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Sign In</button>
            </form>

            <div style="text-align:center;margin-top:14px;font-size:13px;">
                <a href="<?= BASE_URL ?>/admin/forgot_password.php" style="color:var(--purple);">Forgot password?</a>
            </div>


        </div>
    </div>

    <div id="toast-container"></div>
    <script>
    (function () {
        var container = document.getElementById('toast-container');
        var ICONS = {
            error: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        };
        window.showToast = function (message, type, duration) {
            type     = type     || 'error';
            duration = duration || 4500;
            var t = document.createElement('div');
            t.className = 'toast toast-' + type;
            t.innerHTML =
                '<div class="toast-icon">' + (ICONS[type] || ICONS.error) + '</div>' +
                '<div class="toast-body"><div class="toast-message">' + message + '</div></div>' +
                '<button class="toast-close" aria-label="Dismiss"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
            function dismiss() {
                if (t._out) return;
                t._out = true;
                t.classList.add('toast-out');
                setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
            }
            t.querySelector('.toast-close').addEventListener('click', dismiss);
            container.appendChild(t);
            setTimeout(dismiss, duration);
        };
    })();
    </script>
    <?php if ($error): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode(htmlspecialchars($error)) ?>, 'error'); });</script>
    <?php endif; ?>
    <script>
    function toggleLoginEye(btn) {
        var f = document.getElementById('loginPw');
        var show = f.type === 'password';
        f.type = show ? 'text' : 'password';
        btn.innerHTML = show
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    }
    </script>
</body>

</html>