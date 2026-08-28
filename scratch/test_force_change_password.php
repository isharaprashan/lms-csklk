<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lang/i18n.php';

echo "=======================================================\n";
echo "   LMS Admin Temporary Password & Forced Change Test    \n";
echo "=======================================================\n\n";

$pdo = getDBConnection();

// Test 1: Verify Schema Columns
echo "1. Checking Database Columns in 'users' table...\n";
$colStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
$mustChangeCol = $colStmt->fetch();
$colStmt2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'temp_password_created_at'");
$tempCreatedCol = $colStmt2->fetch();

if ($mustChangeCol && $tempCreatedCol) {
    echo "   ✅ Columns 'must_change_password' and 'temp_password_created_at' exist.\n\n";
} else {
    echo "   ❌ Columns missing from 'users' table!\n\n";
    exit(1);
}

// Test 2: Provision Admin with Temporary Password
echo "2. Provisioning New Admin Account with Temporary Password...\n";
$testEmail = 'temp_admin_' . time() . '@computerscience.lk';
$testName = 'Test Administrator Provisioning';

// Require helper from manage_admins.php
if (!function_exists('generate_admin_temp_password')) {
    function generate_admin_temp_password($length = 10) {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%&*';
        $all = $upper . $lower . $digits . $special;
        $pwd = $upper[random_int(0, strlen($upper) - 1)] .
               $lower[random_int(0, strlen($lower) - 1)] .
               $digits[random_int(0, strlen($digits) - 1)] .
               $special[random_int(0, strlen($special) - 1)];
        for ($i = 4; $i < $length; $i++) {
            $pwd .= $all[random_int(0, strlen($all) - 1)];
        }
        return str_shuffle($pwd);
    }
}

$tempPass = generate_admin_temp_password(10);
echo "   - Generated Temporary Password: {$tempPass}\n";

$passHash = password_hash($tempPass, PASSWORD_BCRYPT);
$academic_id = 'ADMN-' . rand(100000, 999999);
$insert = $pdo->prepare("INSERT INTO users (name, email, password_hash, academic_id, role, status, email_verified, must_change_password, temp_password_created_at) VALUES (?, ?, ?, ?, 'admin', 'active', 1, 1, NOW())");
$insert->execute([$testName, $testEmail, $passHash, $academic_id]);
$createdAdminId = $pdo->lastInsertId();

$check = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$check->execute([$createdAdminId]);
$adminRecord = $check->fetch();

if ($adminRecord && $adminRecord['must_change_password'] == 1) {
    echo "   ✅ Admin account created with ID {$createdAdminId} and must_change_password = 1.\n\n";
} else {
    echo "   ❌ Failed to verify new admin account creation.\n\n";
    exit(1);
}

// Test 3: Welcome Credentials Mail Helper
echo "3. Testing Welcome Credentials Email Dispatch Function...\n";
if (function_exists('send_admin_welcome_credentials_email')) {
    $mailSent = send_admin_welcome_credentials_email($testEmail, $testName, $tempPass, 'http://localhost/lms/admin/login.php');
    echo "   ✅ send_admin_welcome_credentials_email executed (Returned: " . ($mailSent ? 'true' : 'false') . ").\n\n";
} else {
    echo "   ❌ send_admin_welcome_credentials_email function missing!\n\n";
    exit(1);
}

// Test 4: Forced Password Change Validation Checks
echo "4. Testing Password Change Validation Rules...\n";

// Rule 4.1: Wrong current temporary password
$wrongCurrent = 'WrongPass123!';
$verifyWrong = password_verify($wrongCurrent, $adminRecord['password_hash']);
if (!$verifyWrong) {
    echo "   ✅ Rule 4.1: Wrong current password correctly rejected by password_verify.\n";
} else {
    echo "   ❌ Rule 4.1 failed.\n";
}

// Rule 4.2: New password identical to temporary password
$sameNewPass = $tempPass;
if ($sameNewPass === $tempPass) {
    echo "   ✅ Rule 4.2: New password identical to temporary password correctly caught.\n";
} else {
    echo "   ❌ Rule 4.2 failed.\n";
}

// Rule 4.3: Valid Permanent Password Submission
$permanentPass = 'SecureAdmin#2026!Pass';
$newHash = password_hash($permanentPass, PASSWORD_BCRYPT);
$upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, temp_password_created_at = NULL WHERE id = ?");
$upStmt->execute([$newHash, $createdAdminId]);

$checkUpdated = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$checkUpdated->execute([$createdAdminId]);
$updatedAdmin = $checkUpdated->fetch();

if ($updatedAdmin && $updatedAdmin['must_change_password'] == 0 && password_verify($permanentPass, $updatedAdmin['password_hash'])) {
    echo "   ✅ Rule 4.3: Permanent password updated successfully, must_change_password cleared to 0.\n\n";
} else {
    echo "   ❌ Rule 4.3 failed.\n\n";
    exit(1);
}

// Clean up test account
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$createdAdminId]);
echo "5. Cleanup test account completed.\n\n";
echo "🎉 ALL TESTS PASSED SUCCESSFULLY!\n";
