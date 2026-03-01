<?php
// db.php - provide $pdo connected to moffat_bay
// Tries project config first (config/db.php or db_config.php). If none found
// attempt a reasonable local default (root, no password) and create the
// `moffat_bay` database if missing.

if (isset($pdo) && $pdo instanceof PDO) {
    return; // already provided
}

$candidates = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/db_config.php',
];

foreach ($candidates as $c) {
    if (file_exists($c)) {
        $cfg = include $c;
        if (is_array($cfg) && isset($cfg['host'])) {
            $host = $cfg['host'] ?? '127.0.0.1';
            $port = $cfg['port'] ?? 3306;
            $dbname = $cfg['dbname'] ?? 'moffat_bay';
            $user = $cfg['user'] ?? 'root';
            $pass = $cfg['pass'] ?? '';
            $charset = $cfg['charset'] ?? 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};charset={$charset}";
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
                $pdo->exec("USE `{$dbname}`");
                break;
            } catch (Throwable $e) {
                // continue to next candidate
            }
        }
    }
}

if (!isset($pdo)) {
    // Default local connection (developer machine)
    $host = '127.0.0.1';
    $port = 3306;
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';
    $dbname = 'moffat_bay';
    $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
        $pdo->exec("USE `{$dbname}`");
    } catch (Throwable $e) {
        throw new RuntimeException('Could not connect to MySQL. Check credentials and that MySQL is running. Original error: ' . $e->getMessage());
    }
}

// Ensure a basic users table exists for authentication pages
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // ignore table creation errors but keep $pdo usable
}

return;
