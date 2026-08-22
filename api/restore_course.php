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
        echo json_encode(['success' => false, 'message' => 'You do not have permission to restore this course.']);
        exit;
    }

    // Check if course is actually disabled / archived
    $is_soft_deleted = ($course['status'] === 'disabled' || !empty($course['is_archived']) || !empty($course['deleted_at']));
    if (!$is_soft_deleted) {
        echo json_encode(['success' => false, 'message' => 'Course is not in a soft-deleted or disabled state.']);
        exit;
    }

    // Restore course to active/approved state, reset archive flag and deleted_at timestamp
    $restoreStmt = $pdo->prepare("UPDATE courses SET status = 'approved', is_archived = 0, deleted_at = NULL WHERE id = ?");
    $restoreStmt->execute([$course_id]);

    echo json_encode([
        'success' => true,
        'action' => 'restored',
        'course_id' => $course_id,
        'message' => 'Course has been successfully restored and republished to the catalog.'
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to restore course: ' . $e->getMessage()]);
}
