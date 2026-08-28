<?php
$capturedErrors = [];
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$capturedErrors) {
    $capturedErrors[] = [
        'errno' => $errno,
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline
    ];
    return true;
});

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

$pdo = getDBConnection();

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'student' LIMIT 1");
$student = $stmt->fetch();

if (!$student) {
    $stmt = $pdo->query("SELECT * FROM users LIMIT 1");
    $student = $stmt->fetch();
}

$_SESSION['user_id'] = $student['id'];
$_SESSION['user_name'] = $student['name'];
$_SESSION['user_email'] = $student['email'];
$_SESSION['user_avatar'] = $student['avatar'];
$_SESSION['academic_id'] = $student['academic_id'];
$_SESSION['user_role'] = $student['role'];
$_SESSION['sid'] = 'test_sid_' . time();
$_GET['sid'] = $_SESSION['sid'];

ob_start();
include __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

echo "=== Dashboard PHP Error/Warning Audit ===\n";
echo "Total captured PHP warnings/notices: " . count($capturedErrors) . "\n";

if (!empty($capturedErrors)) {
    foreach ($capturedErrors as $e) {
        echo " - [{$e['errno']}] {$e['message']} in {$e['file']} line {$e['line']}\n";
    }
} else {
    echo "✅ PERFECT: 0 PHP errors, warnings, or notices encountered during dashboard execution!\n";
}
