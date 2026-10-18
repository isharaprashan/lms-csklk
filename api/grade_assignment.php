<?php
/**
 * API: Grade Student Assignment Submission & Notify Student
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
require_once __DIR__ . '/../includes/notification_helper.php';
init_lms_session();

if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Authentication & Role verification
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode([
        'success' => false,
        'message' => __('unauthorized_access', 'Unauthorized access. Only teachers or administrators can grade assignments.')
    ]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'teacher';
$is_admin = in_array($user_role, ['admin', 'super_admin']);

// Parse JSON or form POST input
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$submission_id = (int)($input['submission_id'] ?? 0);
$grade_raw = $input['grade'] ?? ($input['score'] ?? null);
$feedback = trim($input['feedback'] ?? '');
$send_notification = !isset($input['send_notification']) || ($input['send_notification'] !== false && $input['send_notification'] !== '0' && $input['send_notification'] !== 0);

if ($submission_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid submission ID is required.']);
    exit;
}

if ($grade_raw === null || $grade_raw === '' || !is_numeric($grade_raw)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid numeric grade/score (0 - 100).']);
    exit;
}

$grade = round((float)$grade_raw, 2);
if ($grade < 0 || $grade > 100) {
    echo json_encode(['success' => false, 'message' => 'Grade score must be between 0 and 100.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch submission with lesson, course, and student details
    $stmt = $pdo->prepare("SELECT sub.*, 
                                  a.title AS assignment_title, 
                                  l.title AS lesson_title, 
                                  c.id AS course_id, 
                                  c.title AS course_title, 
                                  c.tutor_id, 
                                  u.name AS student_name, 
                                  u.email AS student_email
                           FROM assignment_submissions sub
                           INNER JOIN lesson_assignments a ON a.id = sub.assignment_id
                           INNER JOIN lessons l ON l.id = sub.lesson_id
                           INNER JOIN courses c ON c.id = sub.course_id
                           INNER JOIN users u ON u.id = sub.user_id
                           WHERE sub.id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        echo json_encode(['success' => false, 'message' => 'Assignment submission record not found.']);
        exit;
    }

    // Permission check for instructors
    if (!$is_admin && !empty($submission['tutor_id']) && (int)$submission['tutor_id'] !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. You can only grade assignments for your own courses.']);
        exit;
    }

    // Update assignment submission record
    $updateStmt = $pdo->prepare("UPDATE assignment_submissions 
                                 SET grade = ?, 
                                     feedback = ?, 
                                     status = 'graded', 
                                     graded_at = NOW(), 
                                     graded_by = ? 
                                 WHERE id = ?");
    $updateStmt->execute([
        $grade,
        $feedback,
        $user_id,
        $submission_id
    ]);

    // Send instant notification to the student
    $notification_sent = false;
    $student_id = (int)$submission['user_id'];
    if ($send_notification && $student_id > 0 && function_exists('create_user_notification')) {
        $lesson_title = $submission['lesson_title'];
        $course_id = $submission['course_id'];
        $lesson_id = $submission['lesson_id'];
        
        $notif_title = "Assignment Graded: {$lesson_title}";
        $notif_msg = "Your coursework for '{$lesson_title}' has been evaluated. Score: " . (float)$grade . "/100.";
        if (!empty($feedback)) {
            $short_feedback = mb_strlen($feedback) > 100 ? mb_substr($feedback, 0, 97) . '...' : $feedback;
            $notif_msg .= " Remarks: \"{$short_feedback}\"";
        }

        $notif_link = "watch_lesson.php?course_id=" . urlencode($course_id) . "&lesson_id=" . urlencode($lesson_id);

        $notification_sent = (bool)create_user_notification(
            $pdo,
            $student_id,
            $notif_msg,
            'assignment',
            $notif_title,
            $notif_link
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Assignment graded successfully and student notified!',
        'submission_id' => $submission_id,
        'grade' => (float)$grade,
        'feedback' => $feedback,
        'status' => 'graded',
        'graded_at' => date('M d, Y h:i A'),
        'student_id' => $student_id,
        'student_name' => $submission['student_name'],
        'notification_sent' => $notification_sent
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
