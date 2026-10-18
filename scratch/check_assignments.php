<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

echo "--- Tables related to assignment ---\n";
$tables = $pdo->query("SHOW TABLES LIKE '%assign%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

foreach ($tables as $t) {
    echo "\n--- Schema of $t ---\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} ({$col['Type']}) Null:{$col['Null']} Key:{$col['Key']} Default:{$col['Default']}\n";
    }
    
    echo "\n--- Row count in $t ---\n";
    $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "Count: $count\n";
    
    if ($count > 0) {
        echo "--- Sample rows in $t ---\n";
        $rows = $pdo->query("SELECT * FROM $t LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        print_r($rows);
    }
}
