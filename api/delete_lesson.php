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
$lesson_id = trim($input['lesson_id'] ?? '');

if (empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Lesson ID is required.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify lesson exists and check course tutor ownership
    $stmt = $pdo->prepare("SELECT l.*, c.tutor_id FROM lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch();

    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'Lesson not found.']);
        exit;
    }

    if (!$is_admin && !empty($lesson['tutor_id']) && (int)$lesson['tutor_id'] !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this lesson.']);
        exit;
    }

    $pdo->beginTransaction();

    // Delete lesson completions if table exists
    try {
        $stmt = $pdo->prepare("DELETE FROM lesson_completions WHERE lesson_id = ?");
        $stmt->execute([$lesson_id]);
    } catch (PDOException $e) {}

    // Delete lesson
    $stmt = $pdo->prepare("DELETE FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Lesson deleted successfully.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Failed to delete lesson: ' . $e->getMessage()]);
}
