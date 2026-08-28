<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$email = 'dev.ishara20@gmail.com';
$token = bin2hex(random_bytes(32));
$exp = date('Y-m-d H:i:s', strtotime('+30 minutes'));
$pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);
$pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')->execute([$email, $token, $exp]);
echo $token;
