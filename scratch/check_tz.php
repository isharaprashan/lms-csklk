<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$res = $pdo->query("SELECT NOW() as mysql_now, @@global.time_zone as tz, @@session.time_zone as sess_tz")->fetch(PDO::FETCH_ASSOC);
print_r($res);
echo "PHP date: " . date('Y-m-d H:i:s') . "\n";
