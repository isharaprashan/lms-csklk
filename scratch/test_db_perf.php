<?php
$dsn = "mysql:host=127.0.0.1;dbname=computerscience_lms;charset=utf8mb4";
$t0 = microtime(true);
$pdo1 = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => true
]);
$t1 = microtime(true);
echo "Persistent connect 1: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

$t2 = microtime(true);
$pdo2 = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => true
]);
$t3 = microtime(true);
echo "Persistent connect 2: " . round(($t3 - $t2) * 1000, 2) . " ms\n";
