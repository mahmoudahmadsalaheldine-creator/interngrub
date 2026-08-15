<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Beirut');

define('AI_API_KEY', ''); // Paste your Groq API key here (from console.groq.com)

define('DB_HOST', 'localhost');
define('DB_NAME', 'attendtrack');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ── BASE_URL ──
// Computed dynamically from the relationship between this file's
// on-disk location and the server document root. This makes the app
// work unmodified whether it's hosted at http://localhost/interngrub/
// (subfolder) or at a domain root on a host like Hostinger.
//
// includes/config.php lives at: <app-root>/includes/config.php
// so the app root on disk is dirname(__DIR__).
$appRoot     = realpath(dirname(__DIR__));
$docRoot     = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

$baseUrl = '';
if ($appRoot !== false && $docRoot !== false) {
    // Normalize both to forward slashes for comparison.
    $appRootNorm = str_replace('\\', '/', $appRoot);
    $docRootNorm = str_replace('\\', '/', $docRoot);

    if (strpos($appRootNorm, $docRootNorm) === 0) {
        $baseUrl = substr($appRootNorm, strlen($docRootNorm));
    }
}

// Normalize: ensure no trailing slash, and use leading slash (or empty string at domain root)
$baseUrl = rtrim($baseUrl, '/');
$baseUrl = str_replace('\\', '/', $baseUrl);

define('BASE_URL', $baseUrl);

// ── Gmail SMTP (for admin forgot-password OTP) ──
// Use a Gmail App Password — NOT your regular Gmail password.
// Generate one at: myaccount.google.com → Security → 2-Step Verification → App passwords
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', '');          // Gmail address e.g. yourname@gmail.com
define('SMTP_PASS', '');          // 16-char App Password of the Gmail address
define('SMTP_FROM', SMTP_USER);   // no changes here

function setToast(string $message, string $type = 'success'): void {
    $_SESSION['_toast'] = ['message' => $message, 'type' => $type];
}
function getToast(): ?array {
    if (!empty($_SESSION['_toast'])) {
        $t = $_SESSION['_toast'];
        unset($_SESSION['_toast']);
        return $t;
    }
    return null;
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Force a single, explicit connection collation so query literals never
                // clash with mismatched table/column collations on the server side
                // (some hosts default new tables to utf8mb4_general_ci instead of
                // utf8mb4_unicode_ci, which this DB's tables actually use).
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}
