<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../db/db_connect.php';

$pdo = getDBConnection();

$teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher'")->fetchAll(PDO::FETCH_ASSOC);
$admins = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'super_admin')")->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($teachers) . " teachers and " . count($admins) . " admins.\n";

$test_users = array_merge($teachers, $admins);

foreach ($test_users as $u) {
    echo "\n----------------------------------------\n";
    echo "Testing User ID {$u['id']} ({$u['name']}, Role: {$u['role']})\n";
    
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    
    // Case A: Default view
    $_GET = [];
    ob_start();
    include __DIR__ . '/../teacher_assignments.php';
    $html = ob_get_clean();
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning') !== false || strpos($html, 'Parse error') !== false) {
        echo "[ERROR] Default view had PHP errors:\n";
        preg_match_all('/(Fatal error|Warning|Notice|Parse error):.*/', $html, $matches);
        print_r($matches[0]);
    } else {
        echo "[OK] Default view rendered (" . strlen($html) . " bytes)\n";
    }

    // Case B: Filter by status=pending
    $_GET = ['status' => 'pending'];
    ob_start();
    include __DIR__ . '/../teacher_assignments.php';
    $html = ob_get_clean();
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning') !== false) {
        echo "[ERROR] status=pending view had PHP errors\n";
    } else {
        echo "[OK] status=pending view rendered\n";
    }

    // Case C: Filter by non-existent course
    $_GET = ['course_id' => 'nonexistent_course_id_999'];
    ob_start();
    include __DIR__ . '/../teacher_assignments.php';
    $html = ob_get_clean();
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning') !== false) {
        echo "[ERROR] course_id filter view had PHP errors\n";
    } else {
        echo "[OK] course_id filter view rendered\n";
    }

    // Case D: Search query
    $_GET = ['q' => 'test search'];
    ob_start();
    include __DIR__ . '/../teacher_assignments.php';
    $html = ob_get_clean();
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning') !== false) {
        echo "[ERROR] Search query view had PHP errors\n";
    } else {
        echo "[OK] Search query view rendered\n";
    }
}
