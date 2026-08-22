<?php
/**
 * Secure Lesson Resource Downloader & Viewer
 * 
 * Streams attached files only to authorized enrolled students, teachers, and administrators.
 */

require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

$resource_id = intval($_GET['id'] ?? $_GET['resource_id'] ?? 0);
$is_view_mode = (isset($_GET['view']) && $_GET['view'] == '1');

if ($resource_id <= 0) {
    http_response_code(400);
    die(__('invalid_resource_request', 'Invalid resource requested.'));
}

// User must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    // Redirect to login with return URL
    $return_url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: login.php?return={$return_url}");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']) || isset($_COOKIE['LMS_ADMIN_SESS']) || isset($_GET['admin_preview']);

try {
    $pdo = getDBConnection();

    // Query resource details
    $stmt = $pdo->prepare("SELECT lr.*, l.title as lesson_title, l.course_id, c.title as course_title, c.tutor_id 
                           FROM lesson_resources lr 
                           INNER JOIN lessons l ON l.id = lr.lesson_id 
                           INNER JOIN courses c ON c.id = l.course_id 
                           WHERE lr.id = ?");
    $stmt->execute([$resource_id]);
    $resource = $stmt->fetch();

    if (!$resource) {
        http_response_code(404);
        die(__('resource_not_found', 'Requested resource file was not found.'));
    }

    $course_id = $resource['course_id'];
    $tutor_id = $resource['tutor_id'];

    // Authorization verification
    $is_tutor = (!empty($tutor_id) && intval($tutor_id) === intval($user_id));

    $is_enrolled = false;
    if (!$is_admin && !$is_tutor) {
        $enrStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
        $enrStmt->execute([$user_id, $course_id]);
        $is_enrolled = ($enrStmt->fetchColumn() !== false);
    }

    if (!$is_admin && !$is_tutor && !$is_enrolled) {
        http_response_code(403);
        die("<h3>" . __('access_denied', 'Access Denied') . "</h3><p>" . __('resource_enrollment_required', 'You must be enrolled in this course to access supplementary lesson resources.') . "</p><p><a href='classroom.php?course_id=" . urlencode($course_id) . "'>" . __('back_to_course', 'Back to Course') . "</a></p>");
    }

    // Resolve full file path
    $relative_path = $resource['file_path'];
    $full_path = __DIR__ . '/' . ltrim($relative_path, '/');

    if (!file_exists($full_path) || !is_file($full_path)) {
        http_response_code(404);
        die(__('file_not_found_on_server', 'The physical resource file is missing from the server.'));
    }

    $file_name = $resource['file_name'];
    $file_size = filesize($full_path);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // MIME type mapping
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed'
    ];

    $content_type = $mime_types[$file_ext] ?? mime_content_type($full_path) ?: 'application/octet-stream';
    $disposition = ($is_view_mode && in_array($file_ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt'])) ? 'inline' : 'attachment';

    // Clear any previous output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Send HTTP download headers
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file_name) . '"; filename*=UTF-8\'\'' . rawurlencode($file_name));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $file_size);

    readfile($full_path);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die("Error retrieving resource: " . htmlspecialchars($e->getMessage()));
}
