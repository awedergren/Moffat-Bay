<?php
// add_employee.php
// Creates or ensures Employees table exists and inserts a new employee with a bcrypt-hashed password.
// Run with PHP CLI: php add_employee.php

$targetEmail = 'delilah.jacobs@example.com';
$plainPassword = 'Password@1';
$first = 'Delilah';
$last = 'Jacobs';
$role = '';

// Try common DB include locations
$dbPaths = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php'
];
$pdo = null;
foreach ($dbPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        if (isset($pdo) && $pdo instanceof PDO) break;
        if (isset($conn) && $conn instanceof PDO) { $pdo = $conn; break; }
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not found. Please adjust the include path in this script.\n");
    exit(1);
}

try {
        // Ensure Employees table exists (non-destructive if already present)
        // Use column names matching the existing schema: `password` and `position`.
        $create = "CREATE TABLE IF NOT EXISTS `Employees` (
            `employee_ID` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `first_name` VARCHAR(100) DEFAULT NULL,
            `last_name` VARCHAR(100) DEFAULT NULL,
            `position` VARCHAR(100) DEFAULT NULL,
            `access_tier` TINYINT NOT NULL DEFAULT 1,
            `date_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($create);

    // Ensure access_tier column exists for older schemas (MySQL 8+ supports IF NOT EXISTS; be defensive)
    try {
        $col = $pdo->query("SHOW COLUMNS FROM `Employees` LIKE 'access_tier'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE `Employees` ADD COLUMN `access_tier` TINYINT NOT NULL DEFAULT 1 AFTER `position`");
        }
        // backfill any NULLs just in case
        $pdo->exec("UPDATE `Employees` SET access_tier = 1 WHERE access_tier IS NULL");
    } catch (Exception $ex) {
        // ignore non-fatal
    }

    // Check existing
    $chk = $pdo->prepare('SELECT employee_ID FROM `Employees` WHERE email = ? LIMIT 1');
    $chk->execute([$targetEmail]);
    $found = $chk->fetchColumn();
    if ($found) {
        echo "Employee with email {$targetEmail} already exists (employee_ID={$found}).\n";
        exit(0);
    }

    // Hash the password using PHP's password_hash (bcrypt/argon2 depending on PHP)
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        fwrite(STDERR, "Failed to generate password hash.\n");
        exit(1);
    }

    $accessTier = 1;
    // infer basic mapping from role string (adjust as needed)
    if (stripos($role, 'manager') !== false || stripos($role, 'mgr') !== false) $accessTier = 3;
    else if (stripos($role, 'front') !== false || stripos($role, 'desk') !== false) $accessTier = 2;

    $ins = $pdo->prepare('INSERT INTO `Employees` (email, password, first_name, last_name, position, access_tier, date_created) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $ins->execute([$targetEmail, $hash, $first, $last, $role, $accessTier]);
    $id = $pdo->lastInsertId();
    echo "Inserted employee {$targetEmail} with employee_ID={$id}.\n";
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
