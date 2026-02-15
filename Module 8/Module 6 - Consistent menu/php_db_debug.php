<?php
// php_db_debug.php — diagnostic helper for PHP→MySQL connectivity
// Place in your web root and open in browser (http://localhost/php_db_debug.php)
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "PHP MySQL debug\n";
echo str_repeat('=', 40) . "\n";

// Show which php.ini is loaded (Apache's PHP)
if (function_exists('phpinfo')) {
    $matches = [];
    ob_start();
    phpinfo(INFO_GENERAL);
    $info = ob_get_clean();
    if (preg_match('/Loaded\s+Configuration\s+File</i', $info)) {
        // fallback: use php_ini_loaded_file()
    }
}
echo "Loaded php.ini: " . (php_ini_loaded_file() ?: '(none)') . "\n";

// Attempt to load project DB provider
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
    echo "Included db.php\n";
} else {
    echo "db.php not found in project root.\n";
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "PDO not available (no connection established)\n";
    exit;
}

try {
    $user = $pdo->query('SELECT USER()')->fetchColumn();
} catch (Throwable $e) {
    $user = 'ERROR: ' . $e->getMessage();
}
try {
    $current = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
} catch (Throwable $e) {
    $current = 'ERROR: ' . $e->getMessage();
}
try {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    $db = 'ERROR: ' . $e->getMessage();
}

echo "USER(): " . $user . "\n";
echo "CURRENT_USER(): " . $current . "\n";
echo "SELECT DATABASE(): " . var_export($db, true) . "\n";

// List visible databases (may be limited by privileges)
try {
    $dbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    echo "SHOW DATABASES: " . implode(', ', $dbs) . "\n";
} catch (Throwable $e) {
    echo "SHOW DATABASES error: " . $e->getMessage() . "\n";
}

// Attempt to show grants for CURRENT_USER(); may require higher privileges
try {
    $grants = $pdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_COLUMN);
    echo "GRANTS FOR CURRENT_USER():\n" . implode("\n", $grants) . "\n";
} catch (Throwable $e) {
    echo "SHOW GRANTS error: " . $e->getMessage() . "\n";
}

// Write a small entry to project log for debugging convenience
@file_put_contents(__DIR__ . '/db_errors.log', date('[Y-m-d H:i:s] ') . "php_db_debug run: USER=" . $user . ", CURRENT_USER=" . $current . ", DB=" . $db . "\n", FILE_APPEND);

echo "\nDiagnostic written to db_errors.log (also).\n";

?>
