<?php
/**
 * API: Delete / Remove an attached Lesson Resource
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
init_lms_session();

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('unauthorized_access', 'Unauthorized access. Only teachers or admins can manage attachments.')
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']);

// Parse input
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];

$resource_id = intval($_POST['resource_id'] ?? $json_data['resource_id'] ?? 0);

if ($resource_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid Resource ID is required.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify resource exists and check course ownership
    $stmt = $pdo->prepare("SELECT lr.*, l.course_id, c.tutor_id 
                           FROM lesson_resources lr 
                           INNER JOIN lessons l ON l.id = lr.lesson_id 
                           INNER JOIN courses c ON c.id = l.course_id 
                           WHERE lr.id = ?");
    $stmt->execute([$resource_id]);
    $resource = $stmt->fetch();

    if (!$resource) {
        echo json_encode(['success' => false, 'message' => 'Resource not found.']);
        exit;
    }

    if (!$is_admin && !empty($resource['tutor_id']) && intval($resource['tutor_id']) !== intval($user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to delete this attachment.']);
        exit;
    }

    // Delete file from disk
    $file_path = $resource['file_path'];
    $full_path = __DIR__ . '/../' . ltrim($file_path, '/');
    if (file_exists($full_path) && is_file($full_path)) {
        @unlink($full_path);
    }

    // Delete record from database
    $delStmt = $pdo->prepare("DELETE FROM lesson_resources WHERE id = ?");
    $delStmt->execute([$resource_id]);

    echo json_encode([
        'success' => true,
        'resource_id' => $resource_id,
        'lesson_id' => $resource['lesson_id'],
        'message' => __('resource_deleted_success', 'Attachment removed successfully.')
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
