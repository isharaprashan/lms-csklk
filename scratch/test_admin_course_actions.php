<?php
require_once __DIR__ . '/../db/db_connect.php';

echo "=== STARTING ADMIN COURSE MANAGEMENT TEST SUITE ===\n\n";

$pdo = getDBConnection();

$plain_admin_pwd = 'AdminPassword123!';
$hash = password_hash($plain_admin_pwd, PASSWORD_DEFAULT);

// Clean previous test admin if exists
$pdo->prepare("DELETE FROM users WHERE email = 'testadminrunner@lms.lk'")->execute();

// Insert dedicated test admin
$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Admin Runner', 'testadminrunner@lms.lk', ?, 'admin', 'active')")
    ->execute([$hash]);
$admin_id = $pdo->lastInsertId();

$csrf_token = bin2hex(random_bytes(32));

echo "Using Dedicated Test Admin ID: {$admin_id}\n\n";

// Helper function to run API in isolated subprocess
function run_admin_api($script_rel_path, $post_data = [], $session_data = []) {
    $runner_path = escapeshellarg(__DIR__ . '/api_runner.php');
    $arg_script = escapeshellarg($script_rel_path);
    $arg_post = base64_encode(json_encode($post_data));
    $arg_session = base64_encode(json_encode($session_data));
    
    $cmd = "php {$runner_path} {$arg_script} {$arg_post} {$arg_session}";
    $output = shell_exec($cmd);
    
    $pos = strpos($output, '{');
    if ($pos !== false) {
        $last_pos = strrpos($output, '}');
        if ($last_pos !== false) {
            $json_str = substr($output, $pos, $last_pos - $pos + 1);
            return json_decode($json_str, true);
        }
    }
    return json_decode($output, true);
}

function assert_test($condition, $name) {
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}\n";
        exit(1);
    }
}

// 1. Create a mock course with lessons and student enrollment
$test_course_id = 'test-admin-course-' . time();
$pdo->prepare("INSERT INTO courses (id, title, category, level, duration, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail, status, is_archived) 
               VALUES (?, 'Admin Test Course', 'Computer Science', 'Advanced', 8, 'Lecturer', 'Prof', 'avatar.png', ?, 'Short', 'Long', 'thumb.png', 'approved', 0)")
    ->execute([$test_course_id, $admin_id]);

// Add lesson
$lesson_id = 'les-adm-' . time();
$pdo->prepare("INSERT INTO lessons (id, course_id, title, duration, video_url, content) VALUES (?, ?, 'Lesson 1', '10m', 'url', 'content')")
    ->execute([$lesson_id, $test_course_id]);

// Test 1: Quick Disable Course
echo "Test 1: Quick Disable Course via admin/admin_toggle_course.php...\n";
$res_disable = run_admin_api('admin/admin_toggle_course.php', [
    'course_id' => $test_course_id,
    'status' => 'disabled',
    'csrf_token' => $csrf_token
], [
    'user_id' => $admin_id,
    'user_role' => 'admin',
    'csrf_token' => $csrf_token
]);

assert_test(isset($res_disable['success']) && $res_disable['success'] === true, "Quick Disable returned success: true");
assert_test($res_disable['new_status'] === 'disabled', "Quick Disable returned new_status: 'disabled'");

$chkDisabled = $pdo->prepare("SELECT status, is_archived, deleted_at FROM courses WHERE id = ?");
$chkDisabled->execute([$test_course_id]);
$rowDis = $chkDisabled->fetch();
assert_test($rowDis['status'] === 'disabled', "Database course status updated to 'disabled'");
assert_test((int)$rowDis['is_archived'] === 1, "Database course is_archived set to 1");
assert_test(!empty($rowDis['deleted_at']), "Database course deleted_at is set");

echo "\n";

// Test 2: Quick Enable Course
echo "Test 2: Quick Enable Course via admin/admin_toggle_course.php...\n";
$res_enable = run_admin_api('admin/admin_toggle_course.php', [
    'course_id' => $test_course_id,
    'status' => 'approved',
    'csrf_token' => $csrf_token
], [
    'user_id' => $admin_id,
    'user_role' => 'admin',
    'csrf_token' => $csrf_token
]);

assert_test(isset($res_enable['success']) && $res_enable['success'] === true, "Quick Enable returned success: true");
assert_test($res_enable['new_status'] === 'approved', "Quick Enable returned new_status: 'approved'");

$chkEnabled = $pdo->prepare("SELECT status, is_archived, deleted_at FROM courses WHERE id = ?");
$chkEnabled->execute([$test_course_id]);
$rowEn = $chkEnabled->fetch();
assert_test($rowEn['status'] === 'approved', "Database course status updated to 'approved'");
assert_test((int)$rowEn['is_archived'] === 0, "Database course is_archived set to 0");
assert_test($rowEn['deleted_at'] === null, "Database course deleted_at cleared to NULL");

echo "\n";

// Test 3: Security - Student session rejected from toggle endpoint
echo "Test 3: Security - Student session rejected from toggle endpoint...\n";
$res_unauth = run_admin_api('admin/admin_toggle_course.php', [
    'course_id' => $test_course_id,
    'status' => 'disabled'
], [
    'user_id' => 9999,
    'user_role' => 'student'
]);
assert_test(isset($res_unauth['success']) && $res_unauth['success'] === false, "Student session blocked from toggle endpoint");

echo "\n";

// Test 4: Secure Hard Delete with Wrong Password
echo "Test 4: Secure Hard Delete with INVALID Admin Password...\n";
$res_wrong_pwd = run_admin_api('admin/admin_delete_course.php', [
    'course_id' => $test_course_id,
    'password' => 'WrongPassword123',
    'csrf_token' => $csrf_token
], [
    'user_id' => $admin_id,
    'user_role' => 'admin',
    'csrf_token' => $csrf_token
]);

assert_test(isset($res_wrong_pwd['success']) && $res_wrong_pwd['success'] === false, "Invalid password returned success: false");
assert_test(strpos($res_wrong_pwd['message'] ?? '', 'Invalid Admin Password') !== false, "Error message explicitly indicates 'Invalid Admin Password'");

// Verify course was NOT deleted
$chkStillExists = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
$chkStillExists->execute([$test_course_id]);
assert_test($chkStillExists->fetchColumn() == 1, "Course remains intact in database after failed password");

echo "\n";

// Test 5: Secure Hard Delete with VALID Admin Password
echo "Test 5: Secure Hard Delete with VALID Admin Password...\n";
$res_correct_pwd = run_admin_api('admin/admin_delete_course.php', [
    'course_id' => $test_course_id,
    'password' => $plain_admin_pwd,
    'csrf_token' => $csrf_token
], [
    'user_id' => $admin_id,
    'user_role' => 'admin',
    'csrf_token' => $csrf_token
]);

assert_test(isset($res_correct_pwd['success']) && $res_correct_pwd['success'] === true, "Correct password returned success: true");
assert_test($res_correct_pwd['status'] === 'success', "Delete response status is 'success'");

// Verify complete cascade deletion from DB
$chkDeletedCourse = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
$chkDeletedCourse->execute([$test_course_id]);
assert_test($chkDeletedCourse->fetchColumn() == 0, "Course record was permanently cascade deleted");

$chkDeletedLesson = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE id = ?");
$chkDeletedLesson->execute([$lesson_id]);
assert_test($chkDeletedLesson->fetchColumn() == 0, "Related lessons were permanently cascade deleted");

// Clean up test admin
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$admin_id]);

echo "\n=== ALL ADMIN TEST SUITE CHECKS PASSED! ===\n";
