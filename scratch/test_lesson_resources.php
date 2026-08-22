<?php
require_once __DIR__ . '/../db/db_connect.php';

echo "=== STARTING LESSON RESOURCE ATTACHMENTS TEST SUITE ===\n\n";

$pdo = getDBConnection();

function assert_test($condition, $name) {
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}\n";
        exit(1);
    }
}

// Test 1: Schema Check
echo "Test 1: Verifying Database Schema for lesson_resources...\n";
$stmt = $pdo->query("SHOW TABLES LIKE 'lesson_resources'");
assert_test($stmt->rowCount() > 0, "Table 'lesson_resources' exists in database");

$colsStmt = $pdo->query("DESCRIBE lesson_resources");
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array('id', $cols), "Column 'id' exists");
assert_test(in_array('lesson_id', $cols), "Column 'lesson_id' exists");
assert_test(in_array('file_name', $cols), "Column 'file_name' exists");
assert_test(in_array('file_path', $cols), "Column 'file_path' exists");
assert_test(in_array('file_type', $cols), "Column 'file_type' exists");
assert_test(in_array('file_size', $cols), "Column 'file_size' exists");
assert_test(in_array('uploaded_at', $cols), "Column 'uploaded_at' exists");

echo "\n";

// Set up Test Teacher, Student, and Course
$test_teacher_email = 'test_teacher_res_' . time() . '@lms.lk';
$test_student_email = 'test_student_res_' . time() . '@lms.lk';
$test_non_enrolled_email = 'test_non_enr_' . time() . '@lms.lk';
$pwdHash = password_hash('TestPass123!', PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Teacher Res', ?, ?, 'teacher', 'active')")
    ->execute([$test_teacher_email, $pwdHash]);
$teacher_id = $pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test Student Res', ?, ?, 'student', 'active')")
    ->execute([$test_student_email, $pwdHash]);
$student_id = $pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Test NonEnrolled Res', ?, ?, 'student', 'active')")
    ->execute([$test_non_enrolled_email, $pwdHash]);
$non_enrolled_id = $pdo->lastInsertId();

$test_course_id = 'test-res-course-' . time();
$pdo->prepare("INSERT INTO courses (id, title, category, level, duration, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail, status, is_archived) 
               VALUES (?, 'Resource Testing Course', 'Computer Science', 'Beginner', 10, 'Tutor', 'Lecturer', 'avatar.png', ?, 'Short', 'Long', 'thumb.png', 'approved', 0)")
    ->execute([$test_course_id, $teacher_id]);

// Enroll student
$pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)")->execute([$student_id, $test_course_id]);

echo "Created Teacher ID: {$teacher_id}, Student ID: {$student_id}, Course ID: {$test_course_id}\n\n";

// Helper to simulate multipart file upload in isolated process
function run_multipart_api($script_rel_path, $post_fields = [], $files = [], $session_data = []) {
    $runner_code = "<?php
    require_once __DIR__ . '/../db/db_connect.php';
    init_lms_session();
    
    // Inject session
    \$session = json_decode(base64_decode(\$argv[1]), true);
    foreach (\$session as \$k => \$v) {
        \$_SESSION[\$k] = \$v;
    }
    
    // Inject POST
    \$_POST = json_decode(base64_decode(\$argv[2]), true);
    
    // Inject FILES
    \$files_meta = json_decode(base64_decode(\$argv[3]), true);
    \$_FILES = \$files_meta;
    
    // Run target script
    require __DIR__ . '/../' . \$argv[4];
    ";

    $runner_temp = __DIR__ . '/temp_runner_' . uniqid() . '.php';
    file_put_contents($runner_temp, $runner_code);

    $arg_session = base64_encode(json_encode($session_data));
    $arg_post = base64_encode(json_encode($post_fields));
    $arg_files = base64_encode(json_encode($files));
    $arg_script = escapeshellarg($script_rel_path);

    $cmd = "php " . escapeshellarg($runner_temp) . " {$arg_session} {$arg_post} {$arg_files} {$arg_script}";
    $output = shell_exec($cmd);
    @unlink($runner_temp);

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

// Prepare sample dummy files
$tmp_pdf = sys_get_temp_dir() . '/test_lecture_notes.pdf';
file_put_contents($tmp_pdf, "%PDF-1.4 sample pdf content for testing");

$tmp_img = sys_get_temp_dir() . '/test_diagram.png';
file_put_contents($tmp_img, "\x89PNG\r\n\x1a\n sample png content");

$tmp_doc = sys_get_temp_dir() . '/test_assignment.docx';
file_put_contents($tmp_doc, "PK sample docx zip content");

// Test 2: Create Lesson with Attachments
echo "Test 2: Teacher adding Lesson with multiple resource attachments...\n";
$create_res = run_multipart_api('api/create_lesson.php', [
    'course_id' => $test_course_id,
    'title' => 'Lesson 1: Introduction to Data Structures',
    'duration' => '20 mins',
    'video_url' => 'uploads/class.mp4',
    'content' => 'Overview of arrays, linked lists, and memory layouts.'
], [
    'attachments' => [
        'name' => ['Lecture_Notes.pdf', 'Architecture_Diagram.png'],
        'type' => ['application/pdf', 'image/png'],
        'tmp_name' => [$tmp_pdf, $tmp_img],
        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        'size' => [filesize($tmp_pdf), filesize($tmp_img)]
    ]
], [
    'user_id' => $teacher_id,
    'user_role' => 'teacher',
    'user_name' => 'Test Teacher Res'
]);

assert_test(isset($create_res['success']) && $create_res['success'] === true, "Lesson created successfully");
$created_lesson_id = $create_res['lesson_id'] ?? '';
assert_test(!empty($created_lesson_id), "Valid lesson_id returned: {$created_lesson_id}");

// Check resources in database
$resQuery = $pdo->prepare("SELECT * FROM lesson_resources WHERE lesson_id = ? ORDER BY uploaded_at ASC");
$resQuery->execute([$created_lesson_id]);
$saved_res = $resQuery->fetchAll();
assert_test(count($saved_res) === 2, "2 resource records inserted in database");
assert_test($saved_res[0]['file_name'] === 'Lecture_Notes.pdf', "Resource 1 original filename is 'Lecture_Notes.pdf'");
assert_test($saved_res[0]['file_type'] === 'pdf', "Resource 1 type is 'pdf'");
assert_test(file_exists(__DIR__ . '/../' . $saved_res[0]['file_path']), "Resource 1 physical file exists on disk: {$saved_res[0]['file_path']}");

assert_test($saved_res[1]['file_name'] === 'Architecture_Diagram.png', "Resource 2 original filename is 'Architecture_Diagram.png'");
assert_test($saved_res[1]['file_type'] === 'png', "Resource 2 type is 'png'");
assert_test(file_exists(__DIR__ . '/../' . $saved_res[1]['file_path']), "Resource 2 physical file exists on disk: {$saved_res[1]['file_path']}");

echo "\n";

// Test 3: Get Lesson Resources API
echo "Test 3: Fetching Lesson Resources via api/get_lesson_resources.php...\n";
$get_runner_code = "<?php
\$_GET['lesson_id'] = '{$created_lesson_id}';
require __DIR__ . '/../api/get_lesson_resources.php';
";
$temp_get_runner = __DIR__ . '/temp_get_' . uniqid() . '.php';
file_put_contents($temp_get_runner, $get_runner_code);
$get_output = shell_exec("php " . escapeshellarg($temp_get_runner));
@unlink($temp_get_runner);
$get_data = json_decode($get_output, true);

assert_test(isset($get_data['success']) && $get_data['success'] === true, "get_lesson_resources returned success: true");
assert_test($get_data['count'] === 2, "get_lesson_resources returned count: 2");
assert_test(isset($get_data['resources'][0]['formatted_size']), "Formatted size is generated for resources");

echo "\n";

// Test 4: Edit Lesson and Upload Additional Attachment
echo "Test 4: Teacher editing Lesson and attaching additional .docx file...\n";
$edit_res = run_multipart_api('api/edit_lesson.php', [
    'lesson_id' => $created_lesson_id,
    'course_id' => $test_course_id,
    'title' => 'Lesson 1: Introduction to Data Structures (Updated)',
    'duration' => '25 mins',
    'video_url' => 'uploads/class.mp4',
    'content' => 'Updated notes.'
], [
    'attachments' => [
        'name' => ['Homework_Assignment.docx'],
        'type' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'tmp_name' => [$tmp_doc],
        'error' => [UPLOAD_ERR_OK],
        'size' => [filesize($tmp_doc)]
    ]
], [
    'user_id' => $teacher_id,
    'user_role' => 'teacher',
    'user_name' => 'Test Teacher Res'
]);

assert_test(isset($edit_res['success']) && $edit_res['success'] === true, "Lesson updated successfully");

$resQuery->execute([$created_lesson_id]);
$all_res = $resQuery->fetchAll();
assert_test(count($all_res) === 3, "Total attached resources is now 3");
$pdf_res_id = $all_res[0]['id'];
$doc_res_id = $all_res[2]['id'];

echo "\n";

// Test 5: Authorization & Download Handler
echo "Test 5: Testing Secure Download Handler permissions...\n";

// 5a. Non-enrolled student trying to download
function test_download($res_id, $session_user_id, $session_role) {
    $dl_runner = "<?php
    require_once __DIR__ . '/../db/db_connect.php';
    init_lms_session();
    \$_SESSION['user_id'] = {$session_user_id};
    \$_SESSION['user_role'] = '{$session_role}';
    \$_GET['id'] = {$res_id};
    require __DIR__ . '/../download_resource.php';
    ";
    $temp_dl = __DIR__ . '/temp_dl_' . uniqid() . '.php';
    file_put_contents($temp_dl, $dl_runner);
    $out = shell_exec("php " . escapeshellarg($temp_dl));
    @unlink($temp_dl);
    return $out;
}

$non_enr_out = test_download($pdf_res_id, $non_enrolled_id, 'student');
assert_test(strpos($non_enr_out, 'Access Denied') !== false, "Non-enrolled student is BLOCKED with Access Denied");

// 5b. Enrolled student downloading
$enr_out = test_download($pdf_res_id, $student_id, 'student');
assert_test(strpos($enr_out, '%PDF-1.4') !== false, "Enrolled student successfully downloads file content");

// 5c. Course Teacher downloading
$teacher_out = test_download($pdf_res_id, $teacher_id, 'teacher');
assert_test(strpos($teacher_out, '%PDF-1.4') !== false, "Course tutor successfully accesses file content");

echo "\n";

// Test 6: Delete Resource Attachment
echo "Test 6: Teacher deleting a single attachment (api/delete_resource.php)...\n";
$del_runner = "<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();
\$_SESSION['user_id'] = {$teacher_id};
\$_SESSION['user_role'] = 'teacher';
\$_POST['resource_id'] = {$doc_res_id};
require __DIR__ . '/../api/delete_resource.php';
";
$temp_del = __DIR__ . '/temp_del_' . uniqid() . '.php';
file_put_contents($temp_del, $del_runner);
$del_out = shell_exec("php " . escapeshellarg($temp_del));
@unlink($temp_del);
$del_data = json_decode($del_out, true);

assert_test(isset($del_data['success']) && $del_data['success'] === true, "delete_resource returned success: true");

// Verify record deleted from database
$chkDoc = $pdo->prepare("SELECT COUNT(*) FROM lesson_resources WHERE id = ?");
$chkDoc->execute([$doc_res_id]);
assert_test($chkDoc->fetchColumn() == 0, "Resource record removed from database");

echo "\n";

// Test 7: Cascade Clean-up on Course Deletion
echo "Test 7: Cascade Clean-up when Course/Lesson is deleted...\n";
$pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$test_course_id]);

$chkResCascade = $pdo->prepare("SELECT COUNT(*) FROM lesson_resources WHERE lesson_id = ?");
$chkResCascade->execute([$created_lesson_id]);
assert_test($chkResCascade->fetchColumn() == 0, "All remaining attached resources cascade deleted with course/lesson");

// Clean up test users
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?, ?)")->execute([$teacher_id, $student_id, $non_enrolled_id]);
@unlink($tmp_pdf);
@unlink($tmp_img);
@unlink($tmp_doc);

echo "\n=== ALL LESSON RESOURCE TESTS PASSED SUCCESSFULLY! ===\n";
