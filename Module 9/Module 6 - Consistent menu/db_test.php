<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: db_test.php
Purpose: Simple CLI/HTTP script to verify DB connectivity and table counts.
Non-executing comment header only.
*/
// db_test.php — quick DB connectivity and table existence check
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/db.php';

$out = [];
try {
    $tables = ['users','boats','slips','reservations'];
    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `" . $t . "`");
            $row = $stmt->fetch();
            $out[$t] = isset($row['c']) ? (int)$row['c'] : 'unknown';
        } catch (Throwable $e) {
            $out[$t] = 'missing or inaccessible';
        }
    }
    echo "DB OK — connected to: " . ($pdo->query('select database()')->fetchColumn() ?: '(unknown)') . "\n";
    foreach ($out as $k=>$v) echo sprintf("%s: %s\n", $k, $v);
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

echo "\nRecommended next steps:\n - If tables are missing, run the provided schema or create tables.\n - If connection fails, check MySQL service and credentials in config/db.php.\n";
