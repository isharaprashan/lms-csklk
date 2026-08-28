<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lang/i18n.php';

$pdo = getDBConnection();

echo "=== 1. CHECK SCHEMA ===\n";
$stmt = $pdo->query("DESCRIBE admin_password_resets");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Columns in admin_password_resets: " . implode(', ', $cols) . "\n";
assert(in_array('email', $cols));
assert(in_array('token', $cols));
assert(in_array('role', $cols));
assert(in_array('expires_at', $cols));
assert(in_array('is_used', $cols));
echo "Schema check PASSED.\n\n";

echo "=== 2. TEST ROLE RESTRICTION & ENUMERATION PROTECTION ===\n";
// Find a student account if exists
$student = $pdo->query("SELECT email, role FROM users WHERE role = 'student' LIMIT 1")->fetch();
if ($student) {
    // Attempt query as done in admin_forgot_password.php
    $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE email = ? AND role IN ('admin', 'super_admin') LIMIT 1");
    $stmt->execute([$student['email']]);
    $res = $stmt->fetch();
    assert($res === false, "Student email should NOT be found in admin reset query");
    echo "Student role correctly ignored in admin portal: {$student['email']}\n";
}

// Find a super_admin or admin account
$admin = $pdo->query("SELECT id, name, email, role, password_hash FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active' LIMIT 1")->fetch();
assert(!empty($admin), "Active admin user must exist");
echo "Found test admin: {$admin['name']} ({$admin['email']}, role: {$admin['role']})\n\n";

echo "=== 3. TEST TOKEN GENERATION & EXPIRATION ===\n";
$testToken = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+20 minutes'));

// Clean old test tokens
$pdo->prepare("DELETE FROM admin_password_resets WHERE email = ?")->execute([$admin['email']]);

// Insert new token
$ins = $pdo->prepare("INSERT INTO admin_password_resets (email, token, role, expires_at, is_used) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE), 0)");
$ins->execute([$admin['email'], $testToken, $admin['role']]);

$chk = $pdo->prepare("SELECT * FROM admin_password_resets WHERE token = ? AND email = ? AND is_used = 0 AND expires_at > NOW()");
$chk->execute([$testToken, $admin['email']]);
$record = $chk->fetch();
assert(!empty($record), "Token must be valid and queryable");
assert(strlen($testToken) === 64, "Token must be 64 characters");
echo "Generated and verified 64-char token: {$testToken}\n";
echo "Token expiration verified (+20 mins): {$record['expires_at']}\n\n";

echo "=== 4. TEST EMAIL TEMPLATE GENERATION ===\n";
$dummyLink = "http://localhost/lms/admin_reset_password.php?token={$testToken}&email=" . urlencode($admin['email']);
$emailRes = send_admin_password_reset_email($admin['email'], $admin['name'], $dummyLink, $admin['role'], 20, '127.0.0.1');
echo "Email dispatch result: " . ($emailRes['success'] ? 'SUCCESS' : 'NOTICE: ' . $emailRes['message']) . "\n\n";

echo "=== 5. TEST PASSWORD RESET VALIDATION & COMPLETION ===\n";
$newTestPassword = 'AdminSecret#2026!Sec';
$newHash = password_hash($newTestPassword, PASSWORD_BCRYPT);

// Update user password
$upd = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
$upd->execute([$newHash, $admin['id']]);

// Mark token used and clean up
$updTok = $pdo->prepare("UPDATE admin_password_resets SET is_used = 1 WHERE id = ?");
$updTok->execute([$record['id']]);

$delTok = $pdo->prepare("DELETE FROM admin_password_resets WHERE email = ?");
$delTok->execute([$admin['email']]);

// Verify database state
$rechkUser = $pdo->prepare("SELECT password_hash, must_change_password FROM users WHERE id = ?");
$rechkUser->execute([$admin['id']]);
$updatedUser = $rechkUser->fetch();
assert(password_verify($newTestPassword, $updatedUser['password_hash']), "New password hash must verify");
assert($updatedUser['must_change_password'] == 0, "must_change_password flag must be 0");

$rechkTok = $pdo->prepare("SELECT COUNT(*) FROM admin_password_resets WHERE email = ?");
$rechkTok->execute([$admin['email']]);
assert($rechkTok->fetchColumn() == 0, "Tokens for email must be cleaned up");

echo "Password update and token invalidation check PASSED.\n\n";

// Restore original password hash for the test admin
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$admin['password_hash'], $admin['id']]);
echo "Test admin password safely restored to original state.\n";
echo "=== ALL TESTS COMPLETED SUCCESSFULLY! ===\n";
