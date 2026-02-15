<?php
// BlueTeam_LoginHandler.php
// Clean login handler: ensures `users` table exists, seeds a demo user when empty,
// validates POSTed credentials and sets session on success.

session_start();

// Helper redirect
function redirect_with_error($msg) {
    header('Location: BlueTeam_LoginPage.php?error=' . urlencode($msg));
    exit;
}

// Load DB provider (prefer db.php)
foreach ([__DIR__ . '/config/db.php', __DIR__ . '/db.php', __DIR__ . '/../db.php'] as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

// Fallback to db_config.php if $pdo not provided
if ((!isset($pdo) || !($pdo instanceof PDO)) && file_exists(__DIR__ . '/db_config.php')) {
    $cfg = require __DIR__ . '/db_config.php';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 3306, $cfg['dbname'] ?? '', $cfg['charset'] ?? 'utf8mb4'
    );
    try {
        $pdo = new PDO($dsn, $cfg['user'] ?? 'root', $cfg['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        redirect_with_error('Database connection failed');
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    redirect_with_error('Database not available');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: BlueTeam_LoginPage.php');
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($email === '' || $password === '') {
    redirect_with_error('Please enter both email and password.');
}

// Create users table if missing
$createTableSql = "CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
// Diagnostic: log the currently selected database before attempting CREATE TABLE
try {
    try {
        $logFile = __DIR__ . '/db_errors.log';
        $currentDb = isset($pdo) && $pdo instanceof PDO ? (string)$pdo->query('SELECT DATABASE()')->fetchColumn() : '(none)';
        error_log(date('[Y-m-d H:i:s] ') . "LoginHandler current DB: " . $currentDb . PHP_EOL, 3, $logFile);
    } catch (Throwable $diag) {
        error_log(date('[Y-m-d H:i:s] ') . "LoginHandler diag error: " . $diag->getMessage() . PHP_EOL, 3, __DIR__ . '/db_errors.log');
    }

    // If no default database is selected, attempt to select the expected database
    try {
        $cur = isset($pdo) && $pdo instanceof PDO ? (string)$pdo->query('SELECT DATABASE()')->fetchColumn() : '';
        if (!$cur) {
            // attempt to use the expected database name `moffat_bay`
            try {
                $pdo->exec("USE `moffat_bay`");
                error_log(date('[Y-m-d H:i:s] ') . "LoginHandler: executed USE `moffat_bay`" . PHP_EOL, 3, $logFile);
            } catch (Throwable $useErr) {
                error_log(date('[Y-m-d H:i:s] ') . "LoginHandler: USE failed: " . $useErr->getMessage() . PHP_EOL, 3, $logFile);
            }
        }
    } catch (Throwable $e) {
        // ignore diagnostic failure
    }

    $pdo->exec($createTableSql);
} catch (Throwable $e) {
    // Log the detailed error to a local file for debugging, then show a safe message.
    $errFile = __DIR__ . '/db_errors.log';
    error_log(date('[Y-m-d H:i:s] ') . "DB prepare error: " . $e->getMessage() . PHP_EOL, 3, $errFile);
    redirect_with_error('Failed to prepare database (check db_errors.log)');
}

// Seed demo user when no users exist (development convenience)
try {
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
    if ($cnt === 0) {
        $hash = password_hash('Passw0rd!', PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO `users` (username, email, password_hash) VALUES (:u, :e, :p)');
        $ins->execute([':u' => 'admin', ':e' => 'demo@moffatbay.local', ':p' => $hash]);
    }
} catch (Throwable $e) {
    // non-fatal; continue to attempt login
}

// Authenticate (select all to handle variant schemas, then normalize fields)
$stmt = $pdo->prepare('SELECT * FROM `users` WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

if (!$user) {
    redirect_with_error('Invalid email or password.');
}

// Normalize common column names
$passwordField = $user['password_hash'] ?? $user['password'] ?? $user['passwd'] ?? null;
if (!$passwordField || !password_verify($password, $passwordField)) {
    redirect_with_error('Invalid email or password.');
}

$uid = $user['id'] ?? $user['user_id'] ?? $user['uid'] ?? $user['ID'] ?? $email;
$username = $user['username'] ?? $user['name'] ?? $user['user'] ?? $user['email'] ?? $email;

session_regenerate_id(true);
$_SESSION['user_id'] = $uid;
$_SESSION['username'] = $username;

// Determine safe redirect target (prefer POSTed redirect, then GET)
$requested = '';
if (!empty($_POST['redirect'])) $requested = trim($_POST['redirect']);
elseif (!empty($_GET['redirect'])) $requested = trim($_GET['redirect']);

// Basic safety: allow only internal paths (no scheme/host). If invalid, fallback to MyAccount.
$target = 'MyAccount.php';
if ($requested) {
    $parts = parse_url($requested);
    if (!isset($parts['scheme']) && !isset($parts['host'])) {
        // allow absolute-path or relative path
        $target = $requested;
    }
}

header('Location: ' . $target);
exit;
