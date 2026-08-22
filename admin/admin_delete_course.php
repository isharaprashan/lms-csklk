<?php
/**
 * Admin Secure Course Deletion Handler
 * 
 * Verifies admin session role and admin password before performing a cascade hard delete.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('LMS_ADMIN_SESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
    session_start();
}

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

header('Content-Type: application/json');

// Check Admin Authentication
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => __('unauthorized_access', 'Unauthorized access. Administrator privileges required.')
    ]);
    exit;
}

$admin_id = $_SESSION['user_id'];

// Get Request Data (supports JSON body or POST form)
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];

$course_id = trim($_POST['course_id'] ?? $json_data['course_id'] ?? '');
$password = trim($_POST['password'] ?? $json_data['password'] ?? '');
$csrf_token = trim($_POST['csrf_token'] ?? $json_data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

// CSRF Verification
if (!empty($_SESSION['csrf_token']) && !empty($csrf_token)) {
    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => __('csrf_invalid', 'Security token invalid. Please refresh the page.')
        ]);
        exit;
    }
}

if (empty($course_id)) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => __('course_id_required', 'Course ID is required.')
    ]);
    exit;
}

if (empty($password)) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => __('admin_password_required', 'Admin password is required to confirm deletion.')
    ]);
    exit;
}

try {
    $pdo = getDBConnection();

    // 1. Verify Admin Password
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin_user = $stmt->fetch();

    if (!$admin_user || !password_verify($password, $admin_user['password_hash'])) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => __('invalid_admin_password', 'Invalid Admin Password. Deletion aborted.')
        ]);
        exit;
    }

    // 2. Fetch Course Details
    $cStmt = $pdo->prepare("SELECT id, title, tutor_id FROM courses WHERE id = ?");
    $cStmt->execute([$course_id]);
    $course = $cStmt->fetch();

    if (!$course) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => __('course_not_found', 'Course not found in system.')
        ]);
        exit;
    }

    // 3. Execute Cascade Deletion in a Transaction
    $pdo->beginTransaction();

    // Delete lesson progress and completed lessons
    try {
        $pdo->prepare("DELETE lp FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    try {
        $pdo->prepare("DELETE cl FROM completed_lessons cl INNER JOIN lessons l ON l.id = cl.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete lessons
    $stmt = $pdo->prepare("DELETE FROM lessons WHERE course_id = ?");
    $stmt->execute([$course_id]);

    // Delete quizzes and quiz data
    try {
        $pdo->prepare("DELETE FROM quizzes WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    try {
        $pdo->prepare("DELETE FROM course_quiz_settings WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    try {
        $pdo->prepare("DELETE FROM quiz_attempts WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    try {
        $pdo->prepare("DELETE FROM quiz_results WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete forum topics and replies
    try {
        $pdo->prepare("DELETE fr FROM forum_replies fr INNER JOIN forum_topics ft ON ft.qa_id = fr.qa_id WHERE ft.course_id = ?")->execute([$course_id]);
        $pdo->prepare("DELETE FROM forum_topics WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete certificate requests
    try {
        $pdo->prepare("DELETE FROM certificate_requests WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete bank payments
    try {
        $pdo->prepare("DELETE FROM bank_payments WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete student enrollments
    try {
        $pdo->prepare("DELETE FROM enrollments WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete the course record
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);

    // Notify instructor if applicable
    if (!empty($course['tutor_id'])) {
        try {
            $notifMsg = "Your course '{$course['title']}' (ID: {$course_id}) has been deleted by the administrator.";
            $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)")->execute([$course['tutor_id'], $notifMsg]);
        } catch (PDOException $e) {}
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'course_id' => $course_id,
        'message' => __('course_deleted_success', 'Course permanently deleted successfully.')
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
