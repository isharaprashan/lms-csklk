<?php
/**
 * Admin Quick Disable / Enable Course Status Handler
 * 
 * Instantly toggles course status between 'active' / 'approved' and 'disabled' via AJAX.
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

// Get Request Data
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];

$course_id = trim($_POST['course_id'] ?? $json_data['course_id'] ?? '');
$target_status = trim($_POST['status'] ?? $json_data['status'] ?? '');
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

try {
    $pdo = getDBConnection();

    // Fetch current course
    $stmt = $pdo->prepare("SELECT id, title, status, is_archived, tutor_id FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => __('course_not_found', 'Course not found.')
        ]);
        exit;
    }

    $current_status = $course['status'];
    $is_currently_disabled = ($current_status === 'disabled' || !empty($course['is_archived']));

    // Determine new status
    if (!empty($target_status)) {
        $new_status = ($target_status === 'disabled') ? 'disabled' : 'approved';
    } else {
        $new_status = $is_currently_disabled ? 'approved' : 'disabled';
    }

    if ($new_status === 'disabled') {
        $updateStmt = $pdo->prepare("UPDATE courses SET status = 'disabled', is_archived = 1, deleted_at = NOW() WHERE id = ?");
        $updateStmt->execute([$course_id]);
        $message = __('course_disabled_success', 'Course has been disabled and unpublished from catalog.');
    } else {
        $updateStmt = $pdo->prepare("UPDATE courses SET status = 'approved', is_archived = 0, deleted_at = NULL WHERE id = ?");
        $updateStmt->execute([$course_id]);
        $message = __('course_enabled_success', 'Course has been enabled and published to catalog.');
    }

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'course_id' => $course_id,
        'new_status' => $new_status,
        'is_archived' => ($new_status === 'disabled' ? 1 : 0),
        'message' => $message
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
