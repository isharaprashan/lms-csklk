<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']);

// Parse input
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$course_id = trim($input['course_id'] ?? '');

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify course exists and user owns it (or is admin)
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    if (!$is_admin && (int)$course['tutor_id'] !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this course.']);
        exit;
    }

    // Begin deletion
    $pdo->beginTransaction();

    // Delete lessons
    $stmt = $pdo->prepare("DELETE FROM lessons WHERE course_id = ?");
    $stmt->execute([$course_id]);

    // Delete quizzes
    $stmt = $pdo->prepare("DELETE FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);

    // Delete quiz settings if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM course_quiz_settings WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete quiz attempts if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM quiz_attempts WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete enrollments
    try {
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete bank payments if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM bank_payments WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // Delete the course record itself
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Course deleted successfully.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to delete course: ' . $e->getMessage()]);
}
