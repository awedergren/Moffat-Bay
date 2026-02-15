<?php
/**
 * cron_update_reservations.php
 *
 * Safe, idempotent script to mark past reservations as `completed`.
 * Place this file in your webroot and run via CLI or a scheduled task.
 */
// Try several common DB include locations (matches pattern used by other pages)
$dbPaths = [
  __DIR__ . '/config/db.php',
  __DIR__ . '/db.php',
  __DIR__ . '/../db.php'
];
$dbIncluded = false;
foreach ($dbPaths as $dbPath) {
  if (file_exists($dbPath)) {
    require_once $dbPath;
    $dbIncluded = true;
    break;
  }
}

if (!isset($pdo) || !$dbIncluded) {
  // Attempt a fallback using common XAMPP defaults. Edit if your credentials differ.
  $host = '127.0.0.1';
  $dbname = 'moffat_bay';
  $user = 'root';
  $pass = '';
  try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbIncluded = true;
  } catch (Exception $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
  }
}

try {
  // Use a transactional, idempotent update. Exclude rows already marked completed or canceled.
  $pdo->beginTransaction();

  $updateSql = <<<'SQL'
UPDATE reservations
SET reservation_status = 'completed'
WHERE DATE(end_date) < CURDATE()
  AND (reservation_status IS NULL OR TRIM(reservation_status) = '' OR LOWER(reservation_status) NOT IN ('completed','canceled','cancelled'))
SQL;

  $stmt = $pdo->prepare($updateSql);
  $stmt->execute();
  $affected = $stmt->rowCount();

  $pdo->commit();

  $msg = sprintf("Marked %d reservations as completed (end_date before %s).\n", $affected, date('Y-m-d'));
  // If running in CLI, print; otherwise write to error log
  if (php_sapi_name() === 'cli') {
    echo $msg;
  } else {
    error_log($msg);
  }
} catch (Exception $e) {
  if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  $err = "Error updating reservations: " . $e->getMessage();
  if (php_sapi_name() === 'cli') {
    fwrite(STDERR, $err . "\n");
  } else {
    error_log($err);
  }
  exit(1);
}

return 0;
