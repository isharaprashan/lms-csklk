<?php
require_once __DIR__ . '/../db/db_connect.php';

function run_php_api($script_rel_path, $post_data = [], $session_data = [], $get_data = []) {
    $runner_path = escapeshellarg(__DIR__ . '/api_runner.php');
    $arg_script = escapeshellarg($script_rel_path);
    $arg_post = base64_encode(json_encode($post_data));
    $arg_session = base64_encode(json_encode($session_data));
    $arg_get = base64_encode(json_encode($get_data));
    
    $cmd = "php {$runner_path} {$arg_script} {$arg_post} {$arg_session} {$arg_get}";
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

echo "=== STARTING SMART COURSE DELETION TEST SUITE ===\n\n";

$pdo = getDBConnection();

// Test 1: Verify Schema Migrations
echo "Test 1: Checking Schema Columns...\n";
$cols = $pdo->query("DESCRIBE courses")->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array('is_archived', $cols), "Column 'is_archived' exists in courses table");
assert_test(in_array('deleted_at', $cols), "Column 'deleted_at' exists in courses table");

// Setup test teacher & test students
$stmt = $pdo->query("SELECT id FROM users WHERE role = 'teacher' LIMIT 1");
$teacher_id = $stmt->fetchColumn();

if (!$teacher_id) {
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Teacher', 'testteacher@test.com', 'hash', 'teacher', 'active')")->execute();
    $teacher_id = $pdo->lastInsertId();
}

$stmt = $pdo->query("SELECT id FROM users WHERE role = 'student' LIMIT 2");
$student_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($student_ids) < 2) {
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Student 1', 'teststudent1@test.com', 'hash', 'student', 'active')")->execute();
    $student_ids[] = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Student 2', 'teststudent2@test.com', 'hash', 'student', 'active')")->execute();
    $student_ids[] = $pdo->lastInsertId();
}

echo "Using Teacher ID: {$teacher_id}, Student IDs: " . implode(', ', $student_ids) . "\n\n";

// Test 2: Case A (0 Enrolled Students -> Direct Hard Delete)
echo "Test 2: Testing Case A (0 Enrolled Students -> Hard Delete)...\n";
$course_id_a = 'test-course-case-a-' . time();
$pdo->prepare("INSERT INTO courses (id, title, category, level, duration, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail, status, is_archived) 
               VALUES (?, 'Test Course Zero Students', 'Computer Science', 'Beginner', 4, 'Teacher', 'Lecturer', 'avatar.png', ?, 'Short desc', 'Long desc', 'thumb.jpg', 'approved', 0)")
    ->execute([$course_id_a, $teacher_id]);

// Add a test lesson
$lesson_id_a = 'les-a-' . time() . '-' . rand(100, 999);
$pdo->prepare("INSERT INTO lessons (id, course_id, title, duration, video_url, content) VALUES (?, ?, 'Lesson 1', '10m', 'url', 'content')")
    ->execute([$lesson_id_a, $course_id_a]);

$response_a = run_php_api('api/delete_course.php', ['course_id' => $course_id_a], ['user_id' => $teacher_id, 'user_role' => 'teacher']);

assert_test(isset($response_a['success']) && $response_a['success'] === true, "Case A API returned success: true");
assert_test($response_a['action'] === 'hard_deleted', "Case A API returned action: 'hard_deleted'");

$chkCourse = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
$chkCourse->execute([$course_id_a]);
assert_test($chkCourse->fetchColumn() == 0, "Case A Course record was hard-deleted from database");

$chkLesson = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
$chkLesson->execute([$course_id_a]);
assert_test($chkLesson->fetchColumn() == 0, "Case A Related lessons were hard-deleted");

echo "\n";

// Test 3: Case B (>= 1 Enrolled Student -> Soft Delete)
echo "Test 3: Testing Case B (>= 1 Enrolled Student -> Soft Delete & Grace Period)...\n";
$course_id_b = 'test-course-case-b-' . time();
$pdo->prepare("INSERT INTO courses (id, title, category, level, duration, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail, status, is_archived) 
               VALUES (?, 'Test Course With Students', 'Programming', 'Intermediate', 6, 'Teacher', 'Lecturer', 'avatar.png', ?, 'Short desc', 'Long desc', 'thumb.jpg', 'approved', 0)")
    ->execute([$course_id_b, $teacher_id]);

// Add lessons & enrollments
$lesson_id_b = 'les-b-' . time() . '-' . rand(100, 999);
$pdo->prepare("INSERT INTO lessons (id, course_id, title, duration, video_url, content) VALUES (?, ?, 'Lesson 1', '10m', 'url', 'content')")
    ->execute([$lesson_id_b, $course_id_b]);

foreach ($student_ids as $sid) {
    $pdo->prepare("INSERT IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)")->execute([$sid, $course_id_b]);
}

$response_b = run_php_api('api/delete_course.php', ['course_id' => $course_id_b], ['user_id' => $teacher_id, 'user_role' => 'teacher']);

assert_test(isset($response_b['success']) && $response_b['success'] === true, "Case B API returned success: true");
assert_test($response_b['action'] === 'soft_deleted', "Case B API returned action: 'soft_deleted'");
assert_test($response_b['enrolled_count'] >= 2, "Case B API reported enrolled count: {$response_b['enrolled_count']}");

$chkCourseB = $pdo->prepare("SELECT status, is_archived, deleted_at FROM courses WHERE id = ?");
$chkCourseB->execute([$course_id_b]);
$rowB = $chkCourseB->fetch();

assert_test($rowB !== false, "Case B Course still exists in database (NOT hard deleted)");
assert_test($rowB['status'] === 'disabled', "Case B Course status is 'disabled'");
assert_test((int)$rowB['is_archived'] === 1, "Case B Course is_archived is 1");
assert_test(!empty($rowB['deleted_at']), "Case B Course deleted_at timestamp is set ({$rowB['deleted_at']})");

echo "\n";

// Test 4: Visibility Rules
echo "Test 4: Testing Visibility Rules...\n";

// 4.1 Check api/courses.php public catalog excludes soft-deleted course
$cat_data = run_php_api('api/courses.php', [], []);
$cat_ids = array_column($cat_data['courses'] ?? [], 'id');
assert_test(!in_array($course_id_b, $cat_ids), "Soft-deleted course is EXCLUDED from public catalog (api/courses.php)");

// 4.2 Check api/enroll.php blocks new enrollments
$enroll_res = run_php_api('api/enroll.php', ['course_id' => $course_id_b], ['user_id' => 999999, 'user_role' => 'student']);
assert_test($enroll_res['success'] === false, "New enrollment into soft-deleted course was BLOCKED");

// 4.3 Check enrolled students retain access
$enrolledCheck = $pdo->prepare("SELECT c.* FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE e.user_id = ? AND c.id = ?");
$enrolledCheck->execute([$student_ids[0], $course_id_b]);
$enrolledCourse = $enrolledCheck->fetch();
assert_test(!empty($enrolledCourse), "Enrolled student still accesses course in enrolled list");

echo "\n";

// Test 5: Restore Feature
echo "Test 5: Testing Restore Feature (api/restore_course.php)...\n";
$restore_res = run_php_api('api/restore_course.php', ['course_id' => $course_id_b], ['user_id' => $teacher_id, 'user_role' => 'teacher']);

assert_test(isset($restore_res['success']) && $restore_res['success'] === true, "Restore API returned success: true");
assert_test($restore_res['action'] === 'restored', "Restore API returned action: 'restored'");

$chkRestored = $pdo->prepare("SELECT status, is_archived, deleted_at FROM courses WHERE id = ?");
$chkRestored->execute([$course_id_b]);
$rowRestored = $chkRestored->fetch();

assert_test($rowRestored['status'] === 'approved', "Restored course status is 'approved'");
assert_test((int)$rowRestored['is_archived'] === 0, "Restored course is_archived is 0");
assert_test($rowRestored['deleted_at'] === null, "Restored course deleted_at is NULL");

// Verify it reappears in catalog
$cat_restored_data = run_php_api('api/courses.php', [], []);
$cat_restored_ids = array_column($cat_restored_data['courses'] ?? [], 'id');
assert_test(in_array($course_id_b, $cat_restored_ids), "Restored course is RE-PUBLISHED in public catalog");

echo "\n";

// Test 6: Security Checks (Teacher cannot delete/restore someone else's course)
echo "Test 6: Testing Security (Tutor Ownership Checks)...\n";
$sec_del = run_php_api('api/delete_course.php', ['course_id' => $course_id_b], ['user_id' => 999998, 'user_role' => 'teacher']);
assert_test($sec_del['success'] === false, "Unauthorized teacher cannot delete another teacher's course");

$sec_res = run_php_api('api/restore_course.php', ['course_id' => $course_id_b], ['user_id' => 999998, 'user_role' => 'teacher']);
assert_test($sec_res['success'] === false, "Unauthorized teacher cannot restore another teacher's course");

echo "\n";

// Test 7: Automated Cleanup Cron Job (cron_cleanup_courses.php)
echo "Test 7: Testing Automated Cleanup Cron Job...\n";

// Soft-delete course_b again and set deleted_at to 15 days ago
$pdo->prepare("UPDATE courses SET status = 'disabled', is_archived = 1, deleted_at = NOW() - INTERVAL 15 DAY WHERE id = ?")
    ->execute([$course_id_b]);

// Execute cron script via CLI
$cron_dry_out = shell_exec("php cron_cleanup_courses.php --dry-run");
assert_test(strpos($cron_dry_out, $course_id_b) !== false, "Cron dry-run identified expired course past 14 days");

$cron_force_out = shell_exec("php cron_cleanup_courses.php --force");
assert_test(strpos($cron_force_out, 'DELETED') !== false || strpos($cron_force_out, 'purged') !== false, "Cron force run purged expired course");

$chkPurged = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
$chkPurged->execute([$course_id_b]);
assert_test($chkPurged->fetchColumn() == 0, "Expired course was permanently purged by cron job");

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
