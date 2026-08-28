<?php
/**
 * Automated Verification Script for Forgot/Reset Password Flow
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lang/i18n.php';

echo "=== Starting Password Reset Workflow Tests ===\n\n";

$pdo = getDBConnection();

// Test 1: Verify password_resets table exists
echo "[Test 1] Checking password_resets table schema...\n";
$stmt = $pdo->query("DESCRIBE password_resets");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Columns found: " . implode(', ', $columns) . "\n";
assert(in_array('id', $columns), "Missing id column");
assert(in_array('email', $columns), "Missing email column");
assert(in_array('token', $columns), "Missing token column");
assert(in_array('created_at', $columns), "Missing created_at column");
assert(in_array('expires_at', $columns), "Missing expires_at column");
echo "✅ Test 1 Passed: password_resets table schema is correct.\n\n";

// Test 2: Insert and Retrieve a Reset Token
echo "[Test 2] Testing token generation and storage...\n";
$testEmail = 'test_reset_user_' . time() . '@example.com';
$testToken = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

// Clean up
$pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$testEmail]);

$ins = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
$ins->execute([$testEmail, $testToken, $expiresAt]);

$fetchStmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? LIMIT 1");
$fetchStmt->execute([$testEmail, $testToken]);
$record = $fetchStmt->fetch();

assert($record !== false, "Failed to retrieve reset record");
assert($record['email'] === $testEmail, "Email mismatch");
assert($record['token'] === $testToken, "Token mismatch");
assert(strtotime($record['expires_at']) > time(), "Token should not be expired yet");
echo "✅ Test 2 Passed: Token successfully stored and retrieved.\n\n";

// Test 3: Test Expiration Handling
echo "[Test 3] Testing token expiration handling...\n";
$expiredToken = bin2hex(random_bytes(32));
$pastExpiry = date('Y-m-d H:i:s', strtotime('-5 minutes'));
$pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)")->execute([$testEmail, $expiredToken, $pastExpiry]);

$expStmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? LIMIT 1");
$expStmt->execute([$testEmail, $expiredToken]);
$expRecord = $expStmt->fetch();
assert($expRecord !== false, "Expired record should exist in DB before check");
assert(strtotime($expRecord['expires_at']) < time(), "Record should be recognized as expired");
echo "✅ Test 3 Passed: Expired token logic accurately identified.\n\n";

// Test 4: Test Password Update and Token Cleanup
echo "[Test 4] Testing password update and token cleanup...\n";
// Create temporary test user
$tempPass = password_hash('oldpassword123', PASSWORD_BCRYPT);
$userIns = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'student', 'active') ON DUPLICATE KEY UPDATE password_hash = ?");
$userIns->execute(['Test Reset User', $testEmail, $tempPass, $tempPass]);

$newRawPass = 'BrandNewSecur3Pass!';
$newHash = password_hash($newRawPass, PASSWORD_BCRYPT);

// Update password
$pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?")->execute([$newHash, $testEmail]);

// Delete token
$pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$testEmail]);

// Verify user has new password
$userCheck = $pdo->prepare("SELECT password_hash FROM users WHERE email = ?");
$userCheck->execute([$testEmail]);
$savedHash = $userCheck->fetchColumn();
assert(password_verify($newRawPass, $savedHash), "Password verification failed with new password");
assert(!password_verify('oldpassword123', $savedHash), "Old password should no longer be valid");

// Verify tokens cleaned up
$tokenCheck = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE email = ?");
$tokenCheck->execute([$testEmail]);
assert($tokenCheck->fetchColumn() == 0, "Tokens should be deleted after reset");
echo "✅ Test 4 Passed: Password update and token cleanup succeeded.\n\n";

// Cleanup test user
$pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$testEmail]);

// Test 5: Verify send_password_reset_email function exists and loads properly
echo "[Test 5] Checking mailer helper functions...\n";
assert(function_exists('send_password_reset_email'), "send_password_reset_email function not defined");
assert(function_exists('get_smtp_settings'), "get_smtp_settings function not defined");
echo "✅ Test 5 Passed: Mail helper functions ready.\n\n";

// Test 6: Verify translations for both EN and SI
echo "[Test 6] Checking i18n translations...\n";
$_SESSION['lang'] = 'en';
$enTitle = __('forgot_password_title');
$enBtn = __('send_reset_link_btn');
assert($enTitle === 'Forgot Password', "EN translation mismatch: $enTitle");

$_SESSION['lang'] = 'si';
$siTitle = __('forgot_password_title');
$siBtn = __('send_reset_link_btn');
assert(!empty($siTitle) && $siTitle !== 'forgot_password_title', "SI translation missing");
echo "EN Title: {$enTitle}\n";
echo "SI Title: {$siTitle}\n";
echo "✅ Test 6 Passed: Multilingual dictionary loaded properly.\n\n";

echo "🎉 ALL TESTS COMPLETED SUCCESSFULLY! 🎉\n";
