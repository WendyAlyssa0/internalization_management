
<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = db();
    $pdo->query("SELECT 1");

    echo "Database connection successful!";
} catch (Throwable $e) {
    echo "Database connection failed.";
    error_log($e->getMessage());
}
