<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$stmt = $pdo->query("SELECT id, name, email, role, status FROM users ORDER BY id ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
