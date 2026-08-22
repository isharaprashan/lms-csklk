<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$u = $pdo->query("SELECT id, name, email, password_hash, role FROM users WHERE id = 28")->fetch();
var_dump($u);
var_dump(password_verify('AdminPassword123!', $u['password_hash']));
