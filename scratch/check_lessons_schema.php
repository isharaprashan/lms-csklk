<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$stmt = $pdo->query("DESCRIBE lessons");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
