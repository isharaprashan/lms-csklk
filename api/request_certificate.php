<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a certificate request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Accept JSON or form-encoded POST
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$course_id = trim($input['course_id'] ?? '');
$full_name = trim($input['full_name_on_certificate'] ?? '');
$nic = trim($input['nic_number'] ?? '');
$mobile = trim($input['mobile_number'] ?? '');
$delivery_method = trim($input['delivery_method'] ?? 'digital_only');
$delivery_address = trim($input['delivery_address'] ?? '');
$city = trim($input['city'] ?? '');
$postal_code = trim($input['postal_code'] ?? '');
$district = trim($input['district'] ?? '');
$delivery_notes = trim($input['delivery_notes'] ?? '');
$cod_phone = trim($input['cod_phone'] ?? '');
if (!empty($cod_phone) && $cod_phone !== $mobile) {
    $delivery_notes = (!empty($delivery_notes)) ? "COD Phone: {$cod_phone} | {$delivery_notes}" : "COD Phone: {$cod_phone}";
}

// Basic validations
if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required.']);
    exit;
}

if (empty($full_name) || mb_strlen($full_name) < 2) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid Full Name to be printed on the certificate.']);
    exit;
}

if (empty($nic) || mb_strlen($nic) < 5) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid National Identity Card (NIC) number.']);
    exit;
}

if (empty($mobile) || mb_strlen($mobile) < 8) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid Mobile / Contact Number.']);
    exit;
}

if (!in_array($delivery_method, ['digital_only', 'home_delivery'])) {
    $delivery_method = 'digital_only';
}

if ($delivery_method === 'home_delivery') {
    if (empty($delivery_address) || empty($city) || empty($postal_code) || empty($district)) {
        echo json_encode(['success' => false, 'message' => 'Please complete all required delivery address fields (Street Address, City, Postal Code, District).']);
        exit;
    }
}

try {
    $pdo = getDBConnection();

    // 1. Fetch user
    $uStmt = $pdo->prepare("SELECT id, name, email, academic_id FROM users WHERE id = ?");
    $uStmt->execute([$user_id]);
    $user = $uStmt->fetch();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User account not found.']);
        exit;
    }

    // 2. Fetch course & enrollment
    $cStmt = $pdo->prepare("SELECT c.* FROM courses c JOIN enrollments e ON c.id = e.course_id WHERE c.id = ? AND e.user_id = ?");
    $cStmt->execute([$course_id, $user_id]);
    $course = $cStmt->fetch();
    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'You are not enrolled in this course.']);
        exit;
    }

    // 3. Verify 100% course completion
    $lStmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id = ?");
    $lStmt->execute([$course_id]);
    $course_lessons = $lStmt->fetchAll(PDO::FETCH_COLUMN);
    $total_lessons = count($course_lessons);

    if ($total_lessons === 0) {
        echo json_encode(['success' => false, 'message' => 'This course has no lessons configured yet.']);
        exit;
    }

    $in_lessons = implode(',', array_fill(0, count($course_lessons), '?'));

    // Check completed lessons count
    $compStmt = $pdo->prepare("SELECT lesson_id FROM completed_lessons WHERE user_id = ? AND lesson_id IN ($in_lessons)");
    $compStmt->execute(array_merge([$user_id], $course_lessons));
    $completed_cl_ids = $compStmt->fetchAll(PDO::FETCH_COLUMN);

    $progStmt = $pdo->prepare("SELECT lesson_id, progress_percent, completed, updated_at FROM lesson_progress WHERE user_id = ? AND lesson_id IN ($in_lessons)");
    $progStmt->execute(array_merge([$user_id], $course_lessons));
    $progress_rows = $progStmt->fetchAll();

    $watched_lessons = [];
    $latest_activity_date = null;
    foreach ($progress_rows as $pr) {
        if ($pr['completed'] == 1 || (float)$pr['progress_percent'] >= 90) {
            $watched_lessons[] = $pr['lesson_id'];
        }
        if (!empty($pr['updated_at'])) {
            if (!$latest_activity_date || strtotime($pr['updated_at']) > strtotime($latest_activity_date)) {
                $latest_activity_date = $pr['updated_at'];
            }
        }
    }

    $all_completed_ids = array_unique(array_merge($completed_cl_ids, $watched_lessons));
    $completed_lessons_count = count($all_completed_ids);

    if ($completed_lessons_count < $total_lessons) {
        echo json_encode([
            'success' => false, 
            'message' => "Course is not yet 100% completed ($completed_lessons_count / $total_lessons lessons completed). Please complete all lessons to request your certificate."
        ]);
        exit;
    }

    // 4. Strict Quiz Completion Verification: Student must complete ALL quizzes for this course
    $quiz_check = check_course_quizzes_completed($pdo, $user_id, $course_id);
    if (!$quiz_check['all_completed']) {
        $missingList = !empty($quiz_check['missing_quiz_titles']) ? ' (' . implode(', ', array_slice($quiz_check['missing_quiz_titles'], 0, 3)) . ')' : '';
        echo json_encode([
            'success' => false,
            'message' => "You must complete all quizzes for this course before requesting your certificate ({$quiz_check['completed_quizzes']} of {$quiz_check['total_quizzes']} quizzes completed). Please complete the remaining quizzes{$missingList} to unlock your certificate."
        ]);
        exit;
    }

    // 5. Gather Quiz Performance Summary
    $qStmt = $pdo->prepare("SELECT * FROM quiz_results WHERE user_id = ? AND course_id = ?");
    $qStmt->execute([$user_id, $course_id]);
    $quiz_res = $qStmt->fetch();

    $qaStmt = $pdo->prepare("SELECT MAX(score) as best_score, MAX(total_questions) as total_questions, MAX(updated_at) as last_attempt_at FROM quiz_attempts WHERE user_id = ? AND course_id = ?");
    $qaStmt->execute([$user_id, $course_id]);
    $quiz_attempt = $qaStmt->fetch();

    $quiz_score_summary = ($quiz_check['total_quizzes'] > 0) ? "Progress: 100% | Quizzes: {$quiz_check['completed_quizzes']}/{$quiz_check['total_quizzes']} Completed" : "Progress: 100% | No Quiz Required";
    if ($quiz_res && (int)($quiz_res['total_questions'] ?? 0) > 0) {
        $qScore = (int)$quiz_res['score'];
        $qTotal = (int)$quiz_res['total_questions'];
        $qPct = round(($qScore / $qTotal) * 100);
        $quiz_score_summary = "Progress: 100% | Final Quiz Marks: {$qScore}/{$qTotal} ({$qPct}%)";
        if (!empty($quiz_res['updated_at']) && (!$latest_activity_date || strtotime($quiz_res['updated_at']) > strtotime($latest_activity_date))) {
            $latest_activity_date = $quiz_res['updated_at'];
        }
    } elseif ($quiz_attempt && (int)($quiz_attempt['total_questions'] ?? 0) > 0) {
        $qScore = (int)$quiz_attempt['best_score'];
        $qTotal = (int)$quiz_attempt['total_questions'];
        $qPct = round(($qScore / $qTotal) * 100);
        $quiz_score_summary = "Progress: 100% | Final Quiz Marks: {$qScore}/{$qTotal} ({$qPct}%)";
        if (!empty($quiz_attempt['last_attempt_at']) && (!$latest_activity_date || strtotime($quiz_attempt['last_attempt_at']) > strtotime($latest_activity_date))) {
            $latest_activity_date = $quiz_attempt['last_attempt_at'];
        }
    }

    $completion_date = $latest_activity_date ? date('Y-m-d', strtotime($latest_activity_date)) : date('Y-m-d');
    $registered_email = $user['email'];
    $course_title = $course['title'];

    // Check if an existing request exists
    $existingStmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE user_id = ? AND course_id = ?");
    $existingStmt->execute([$user_id, $course_id]);
    $existing = $existingStmt->fetch();

    $certificate_code = $existing['certificate_code'] ?? ('CERT-CSLK-' . strtoupper(substr(md5(uniqid($user_id . '_' . $course_id, true)), 0, 8)));

    if ($existing) {
        // Update existing certificate request
        $updateStmt = $pdo->prepare("
            UPDATE certificate_requests SET
                full_name_on_certificate = ?,
                nic_number = ?,
                mobile_number = ?,
                registered_email = ?,
                course_title = ?,
                completion_date = ?,
                course_progress = '100%',
                quiz_score_summary = ?,
                delivery_method = ?,
                delivery_address = ?,
                city = ?,
                postal_code = ?,
                district = ?,
                delivery_notes = ?,
                status = 'pending',
                certificate_code = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([
            $full_name,
            $nic,
            $mobile,
            $registered_email,
            $course_title,
            $completion_date,
            $quiz_score_summary,
            $delivery_method,
            $delivery_method === 'home_delivery' ? $delivery_address : null,
            $delivery_method === 'home_delivery' ? $city : null,
            $delivery_method === 'home_delivery' ? $postal_code : null,
            $delivery_method === 'home_delivery' ? $district : null,
            $delivery_method === 'home_delivery' ? $delivery_notes : null,
            $certificate_code,
            $existing['id']
        ]);
        $request_id = $existing['id'];
    } else {
        // Insert new certificate request
        $insertStmt = $pdo->prepare("
            INSERT INTO certificate_requests (
                user_id, course_id, full_name_on_certificate, nic_number, mobile_number,
                registered_email, course_title, completion_date, course_progress, quiz_score_summary,
                delivery_method, delivery_address, city, postal_code, district, delivery_notes,
                status, certificate_code
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, '100%', ?,
                ?, ?, ?, ?, ?, ?,
                'pending', ?
            )
        ");
        $insertStmt->execute([
            $user_id,
            $course_id,
            $full_name,
            $nic,
            $mobile,
            $registered_email,
            $course_title,
            $completion_date,
            $quiz_score_summary,
            $delivery_method,
            $delivery_method === 'home_delivery' ? $delivery_address : null,
            $delivery_method === 'home_delivery' ? $city : null,
            $delivery_method === 'home_delivery' ? $postal_code : null,
            $delivery_method === 'home_delivery' ? $district : null,
            $delivery_method === 'home_delivery' ? $delivery_notes : null,
            $certificate_code
        ]);
        $request_id = $pdo->lastInsertId();
    }

    // Insert user notification
    $notifMsg = "Your official certificate request for '" . $course_title . "' has been submitted successfully! (Reference Code: " . $certificate_code . ")";
    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $nStmt->execute([$user_id, $notifMsg]);

    echo json_encode([
        'success' => true,
        'message' => 'Your certificate request has been submitted successfully!',
        'certificate_code' => $certificate_code,
        'request_id' => $request_id,
        'delivery_method' => $delivery_method
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
