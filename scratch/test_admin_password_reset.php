<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$stmt = $pdo->query("DESCRIBE admin_password_resets");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
