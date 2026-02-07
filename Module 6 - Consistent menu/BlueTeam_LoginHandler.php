<?php
// BlueTeam_LoginHandler.php
// DB-backed login handler using the project's PDO connection.
session_start();

// Load DB connection which should set $pdo. Prefer config/db.php then db.php
$dbPath = __DIR__ . '/config/db.php';
if (file_exists($dbPath)) {
    require_once $dbPath;
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    header('Location: BlueTeam_LoginPage.php?error=' . urlencode('Missing DB configuration'));
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    header('Location: BlueTeam_LoginPage.php?error=' . urlencode('Database not available'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: BlueTeam_LoginPage.php');
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($email === '' || $password === '') {
    header('Location: BlueTeam_LoginPage.php?error=' . urlencode('Please enter both email and password.'));
    exit;
}

// Ensure users table exists
$create = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$pdo->exec($create);

// Seed demo user if table empty
$cnt = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($cnt === 0) {
    $demoUser = 'admin';
    $demoEmail = 'demo@moffatbay.local';
    $demoPass = 'Passw0rd!';
    $hash = password_hash($demoPass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
    $ins->execute([':u' => $demoUser, ':e' => $demoEmail, ':p' => $hash]);
}

// Lookup user by email
$sel = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :e LIMIT 1');
$sel->execute([':e' => $email]);
$user = $sel->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    header('Location: BlueTeam_LoginPage.php?error=' . urlencode('Invalid email or password.'));
    exit;
}

// Success
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'] ?: $user['email'];

header('Location: BlueTeam_LoginPage.php?logged=1');
exit;

?>
<?php
// BlueTeam_LoginHandler.php
// Authenticate users against MySQL `moffat_bay` using PDO.
// Place this file next to `BlueTeam_LoginPage.html` and `db_config.php`.

session_start();

// Load DB config
$cfgPath = __DIR__ . DIRECTORY_SEPARATOR . 'db_config.php';
if (!file_exists($cfgPath)) {
    // helpful message for developers — do not reveal in production
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Missing db_config.php'));
    exit;
}
$db = require $cfgPath;

// Connect
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], $db['port'], $db['dbname'], $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Database connection failed'));
    exit;
}

// Ensure users table exists (no-op if present)
$create = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$pdo->exec($create);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Invalid request method'));
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($email === '' || $password === '') {
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Please enter both email and password.'));
    exit;
}

// Lookup user
$stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Invalid email or password.'));
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    header('Location: BlueTeam_LoginPage.html?error=' . urlencode('Invalid email or password.'));
    exit;
}

// Success
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'] ?: $user['email'];

header('Location: BlueTeam_LoginPage.html?logged=1');
exit;

?>
<?php
// BlueTeam_LoginHandler.php
// Backend PHP for authenticating users against a MySQL database using PDO.
// Place this file next to `BlueTeam_LoginPage.html` and `db_config.php`.
// This script assumes the database already exists. It will create the `users`
// table only if missing (CREATE TABLE IF NOT EXISTS) so it is safe to run when
// tables already exist. It does NOT create the database itself or insert demo users.

session_start();

// Load DB config
$db_config_path = __DIR__ . DIRECTORY_SEPARATOR . 'db_config.php';
if (!file_exists($db_config_path)) {
    die('Missing db_config.php - please create and configure database credentials.');
}
$db_config = require $db_config_path;

// Helper to redirect back with an error
function redirect_with_error($msg) {
    $msg = urlencode($msg);
    header("Location: BlueTeam_LoginPage.html?error=$msg");
    exit;
}

// Connect to the database
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db_config['host'],
        $db_config['port'],
        $db_config['dbname'],
        $db_config['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    // Fail quietly for end user; in dev you can echo $e->getMessage();
    die('Database connection failed. Please check configuration.');
}

// Ensure users table exists (no-op if it already does)
$createTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($createTableSql);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_error('Invalid request method');
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if ($email === '' || $password === '') {
    redirect_with_error('Please enter both email and password.');
}

// Lookup user by email
$sel = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :e LIMIT 1');
$sel->execute([':e' => $email]);
$user = $sel->fetch();

if (!$user) {
    redirect_with_error('Invalid email or password.');
}

if (!password_verify($password, $user['password_hash'])) {
    redirect_with_error('Invalid email or password.');
}

// Successful login
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'] ?: $user['email'];

// Redirect back (could be to dashboard)
header('Location: BlueTeam_LoginPage.html?logged=1');
exit;

/*
Notes / Sample SQL (for manual setup if desired):

-- Manually create database (this script assumes the DB exists already):
-- CREATE DATABASE moffat_bay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE moffat_bay;

-- Sample users table (this script already runs the IF NOT EXISTS version above):
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100),
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

Security notes:
- This script intentionally does not seed demo users. Create users manually or via a separate registration endpoint.
- Ensure `db_config.php` contains restricted DB user credentials and do not expose them publicly.
- Use HTTPS and appropriate session cookie flags in production.
*/

<?php
// BlueTeam_LoginHandler.php
// Backend PHP for authenticating users against a MySQL database using PDO.
// Place this file next to `BlueTeam_LoginPage.html`.
// Configure the DB connection below before testing.

session_start();

// CONFIG — update these values to match your MySQL server
$db_config = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'dbname' => 'moffat_bay',
    // local MySQL (no password) — update if your setup differs
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4'
];

// Basic helper to return to the HTML page with an error message
function redirect_with_error($msg) {
    session_start();
    header("Location: BlueTeam_LoginPage.html?error=$msg");
    // Load DB config from db_config.php
    $db_config = require __DIR__ . DIRECTORY_SEPARATOR . 'db_config.php';
} catch (Exception $e) {
    // Do not expose raw DB errors in production
    die('Database connection failed. Please check configuration.');
}

// Ensure users table exists (simple schema)
$createTableSql = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($createTableSql);

// If no users exist, create a demo admin user (Passw0rd!)
$stmt = $pdo->query('SELECT COUNT(*) AS c FROM users');
    // Create users table if missing. Use email as primary login field.
    $createTableSql = <<<SQL
    CREATE TABLE IF NOT EXISTS `users` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(100) DEFAULT NULL,
      `email` VARCHAR(255) NOT NULL UNIQUE,
      `password_hash` VARCHAR(255) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL;
$sel->execute([':u' => $username]);
    $pdo->exec($createTableSql);
$user = $sel->fetch();
    // If no users exist, create a demo admin user (Passw0rd!) with demo email
    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM users');
    $cnt = $stmt->fetchColumn();
    if ($cnt == 0) {
        $demoUser = 'admin';
        $demoEmail = 'demo@moffatbay.local';
        $demoPass = 'Passw0rd!';
        $hash = password_hash($demoPass, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)');
        $ins->execute([':u' => $demoUser, ':e' => $demoEmail, ':p' => $hash]);
    }

if (!$user) {
    redirect_with_error('Invalid username or password.');
}

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    redirect_with_error('Invalid username or password.');
}
    if ($email === '' || $password === '') {
        redirect_with_error('Please enter both email and password.');
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
    $sel = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :e LIMIT 1');
    $sel->execute([':e' => $email]);
exit;

/*
SQL Schema (for manual setup if preferred):

CREATE DATABASE moffat_bay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE moffatbay;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- To insert a user manually run (generate hash in PHP or use this handler first to create demo user):
-- INSERT INTO users (username, password_hash) VALUES ('admin', '<php password_hash here>');

Notes:
- Update $db_config user/pass/dbname to match your MySQL setup.
- For local testing using the built-in PHP server, run from the folder containing these files:
    php -S localhost:8000
  then point your browser at http://localhost:8000/BlueTeam_LoginPage.html
- Place `logo.png` and optionally `hero.jpg` in the same folder for the full look.
- This handler will create the `users` table and insert a demo `admin` user if the table is empty.
*/
