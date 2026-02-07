<?php
// Bridge: require the project's DB connection file located at the repository root.
// This allows scripts that expect "config/db.php" to work while keeping the
// existing `db.php` location.
$rootDb = __DIR__ . '/../db.php';
if (!file_exists($rootDb)) {
    // Provide a helpful error when missing instead of a generic include error.
    throw new \RuntimeException("Database configuration not found at: $rootDb");
}
require_once $rootDb;
