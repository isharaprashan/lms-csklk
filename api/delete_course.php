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

// Parse input (supports JSON or Form POST)
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

    // Check enrolled students count
    $enrolledStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE course_id = ?");
    $enrolledStmt->execute([$course_id]);
    $enrolled_count = (int)$enrolledStmt->fetchColumn();

    // Case B: Has >= 1 enrolled student -> Soft Delete with 14-day grace period
    if ($enrolled_count > 0) {
        $updateStmt = $pdo->prepare("UPDATE courses SET status = 'disabled', is_archived = 1, deleted_at = NOW() WHERE id = ?");
        $updateStmt->execute([$course_id]);

        echo json_encode([
            'success' => true,
            'action' => 'soft_deleted',
            'course_id' => $course_id,
            'enrolled_count' => $enrolled_count,
            'message' => 'This course has active students. It has been unpublished from the catalog. You have 14 days to restore it.'
        ]);
        exit;
    }

    // Case A: 0 Enrolled Students -> Direct Hard Delete
    $pdo->beginTransaction();

    // 1. Delete lesson progress and completed lessons associated with course lessons
    try {
        $pdo->prepare("DELETE lp FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    try {
        $pdo->prepare("DELETE cl FROM completed_lessons cl INNER JOIN lessons l ON l.id = cl.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // 2. Delete lessons
    $stmt = $pdo->prepare("DELETE FROM lessons WHERE course_id = ?");
    $stmt->execute([$course_id]);

    // 3. Delete quizzes
    $stmt = $pdo->prepare("DELETE FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);

    // 4. Delete quiz settings if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM course_quiz_settings WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 5. Delete quiz attempts if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM quiz_attempts WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 6. Delete quiz results if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM quiz_results WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 7. Delete forum replies & topics
    try {
        $pdo->prepare("DELETE fr FROM forum_replies fr INNER JOIN forum_topics ft ON ft.qa_id = fr.qa_id WHERE ft.course_id = ?")->execute([$course_id]);
        $pdo->prepare("DELETE FROM forum_topics WHERE course_id = ?")->execute([$course_id]);
    } catch (PDOException $e) {}

    // 8. Delete certificate requests if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM certificate_requests WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 9. Delete bank payments if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM bank_payments WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 10. Delete enrollments
    try {
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE course_id = ?");
        $stmt->execute([$course_id]);
    } catch (PDOException $e) {}

    // 11. Delete the course record itself
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'action' => 'hard_deleted',
        'course_id' => $course_id,
        'enrolled_count' => 0,
        'message' => 'Course deleted successfully.'
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to delete course: ' . $e->getMessage()]);
}
