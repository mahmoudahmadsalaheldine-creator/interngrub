<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/style_helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validate.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    // Session timeout: 2 hours of inactivity
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
        logout();
    }
    $_SESSION['last_activity'] = time();
}

function requireRole(...$roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles, true)) {
        if (in_array($_SESSION['role'], ['admin', 'manager'], true)) {
            header('Location: ' . BASE_URL . '/dashboard.php');
        } elseif (($_SESSION['role'] ?? '') === 'hr') {
            header('Location: ' . BASE_URL . '/hr/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/intern/dashboard.php');
        }
        exit;
    }
}

function currentUser() {
    return $_SESSION ?? [];
}

function login($username, $password) {
    $pdo = getDB();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt = $pdo->prepare("SELECT * FROM user WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Point 1 & 2: check lockout
    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        // Extend lock on each attempt while locked
        $pdo->prepare("UPDATE user SET locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE user_id = ?")
            ->execute([$user['user_id']]);
        logLoginAttempt($pdo, $username, $ip, false);
        return 'locked';
    }

    if ($user && password_verify($password, $user['password'])) {
        // Reset brute-force counters on success
        $pdo->prepare("UPDATE user SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")
            ->execute([$user['user_id']]);

        session_regenerate_id(true);

        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['last_activity'] = time();

        logLoginAttempt($pdo, $username, $ip, true);
        return $user;
    }

    // Failed — increment attempts, lock if threshold reached
    if ($user) {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= 5) {
            $pdo->prepare("UPDATE user SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE user_id = ?")
                ->execute([$attempts, $user['user_id']]);
        } else {
            $pdo->prepare("UPDATE user SET failed_attempts = ? WHERE user_id = ?")
                ->execute([$attempts, $user['user_id']]);
        }
    }

    logLoginAttempt($pdo, $username, $ip, false);
    return false;
}

// Point 5: log every login attempt
function logLoginAttempt($pdo, $username, $ip, $success) {
    $pdo->prepare("INSERT INTO login_log (username_tried, ip_address, success) VALUES (?, ?, ?)")
        ->execute([$username, $ip, $success ? 1 : 0]);
}

function autoDeactivateExpiredInterns() {
    $pdo = getDB();
    // Expire interns whose end_date passed — mark completed + block login
    $pdo->exec("
        UPDATE user u
        JOIN intern_profile ip ON u.user_id = ip.user_id
        SET u.status = 'inactive', ip.internship_status = 'completed'
        WHERE ip.end_date IS NOT NULL
          AND ip.end_date < CURDATE()
          AND ip.internship_status = 'active'
    ");
}

/**
 * Returns the department_id a manager is scoped to, or null for admin
 * (unrestricted) / a manager with no department assigned (also unrestricted,
 * since there's nothing to scope them to).
 */
function managerScopeDeptId() {
    if (($_SESSION['role'] ?? '') === 'manager' && !empty($_SESSION['department_id'])) {
        return (int)$_SESSION['department_id'];
    }
    return null;
}

function logout() {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function getUnreadNotifCount($user_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}

function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $w) {
        if ($w !== '') {
            $initials .= strtoupper($w[0]);
        }
    }
    return $initials;
}

