<?php
// Підключення до PostgreSQL.
$localFile = __DIR__ . '/db.local.php';
if (!file_exists($localFile)) {
    die('Створіть файл db.local.php (див. db.local.example.php)');
}
$cfg = require $localFile;

try {
    $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Помилка підключення до PostgreSQL: ' . $e->getMessage());
}