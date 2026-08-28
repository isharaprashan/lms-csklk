<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$hash = password_hash('superadmin20', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, status = 'active' WHERE email = 'dev.ishara20@gmail.com'")->execute([$hash]);

$admin = $pdo->query("SELECT id, email, role, status FROM users WHERE email = 'dev.ishara20@gmail.com'")->fetch();
print_r($admin);
echo "Super Admin ready for login.\n";
