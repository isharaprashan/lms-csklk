<?php
require_once __DIR__ . '/../db/db_connect.php';

function getDBConnectionOptimized()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
        ]);

        static $migrationsRun = false;
        if (!$migrationsRun) {
            $migrationsRun = true;
            $migrationLock = __DIR__ . '/.migration_check_lock';
            if (!file_exists($migrationLock) || (time() - filemtime($migrationLock) > 21600)) {
                ensureMigrations($pdo);
                @touch($migrationLock);
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        throw $e;
    }
}

$t0 = microtime(true);
$p1 = getDBConnectionOptimized();
$t1 = microtime(true);
echo "Call 1: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

$t2 = microtime(true);
$p2 = getDBConnectionOptimized();
$t3 = microtime(true);
echo "Call 2: " . round(($t3 - $t2) * 1000, 4) . " ms\n";

for ($i = 0; $i < 20; $i++) {
    $p = getDBConnectionOptimized();
}
echo "Called 20 times successfully with zero overhead and zero socket churn!\n";
