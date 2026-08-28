<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';

$pdo = getDBConnection();

// Ensure super admin exists with known password 'superadmin20'
$adminHash = password_hash('superadmin20', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET password_hash = ?, status = 'active' WHERE email = 'dev.ishara20@gmail.com'")->execute([$adminHash]);

// Generate a valid test token for dev.ishara20@gmail.com
$token = bin2hex(random_bytes(32));
$pdo->prepare("DELETE FROM admin_password_resets WHERE email = 'dev.ishara20@gmail.com'")->execute();
$pdo->prepare("INSERT INTO admin_password_resets (email, token, role, expires_at, is_used) VALUES ('dev.ishara20@gmail.com', ?, 'super_admin', DATE_ADD(NOW(), INTERVAL 20 MINUTE), 0)")->execute([$token]);

echo "Valid Token for testing: {$token}\n";
echo "Reset URL: http://localhost/lms/admin_reset_password.php?token={$token}&email=" . urlencode('dev.ishara20@gmail.com') . "\n";
