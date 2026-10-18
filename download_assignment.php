<?php
/**
 * Secure Assignment Task Brief & Student Submission File Downloader / Viewer
 */

require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $return_url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: login.php?return={$return_url}");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']) || isset($_COOKIE['LMS_ADMIN_SESS']) || isset($_GET['admin_preview']);

$type = trim($_GET['type'] ?? 'task'); // 'task' or 'submission'
$id = (int)($_GET['id'] ?? 0);
$is_view_mode = (isset($_GET['view']) && $_GET['view'] == '1');

if ($id <= 0) {
    http_response_code(400);
    die(__('invalid_resource_request', 'Invalid assignment file requested.'));
}

try {
    $pdo = getDBConnection();

    if ($type === 'task') {
        // Teacher uploaded assignment brief
        $stmt = $pdo->prepare("SELECT a.*, c.tutor_id 
                               FROM lesson_assignments a 
                               INNER JOIN courses c ON c.id = a.course_id 
                               WHERE a.id = ?");
        $stmt->execute([$id]);
        $assignment = $stmt->fetch();

        if (!$assignment || empty($assignment['attachment_path'])) {
            http_response_code(404);
            die('Assignment attachment file not found.');
        }

        $course_id = $assignment['course_id'];
        $tutor_id = (int)($assignment['tutor_id'] ?? 0);
        $is_tutor = ($tutor_id === $user_id);

        $is_enrolled = false;
        if (!$is_admin && !$is_tutor) {
            $enrStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
            $enrStmt->execute([$user_id, $course_id]);
            $is_enrolled = ($enrStmt->fetchColumn() !== false);
        }

        if (!$is_admin && !$is_tutor && !$is_enrolled) {
            http_response_code(403);
            die('Access Denied. You must be enrolled in this course to download assignment attachments.');
        }

        $relative_path = $assignment['attachment_path'];
        $file_name = $assignment['attachment_name'] ?: basename($relative_path);

    } else {
        // Student submission
        $stmt = $pdo->prepare("SELECT s.*, c.tutor_id 
                               FROM assignment_submissions s 
                               INNER JOIN courses c ON c.id = s.course_id 
                               WHERE s.id = ?");
        $stmt->execute([$id]);
        $submission = $stmt->fetch();

        if (!$submission || empty($submission['submission_file'])) {
            http_response_code(404);
            die('Submission file not found.');
        }

        $tutor_id = (int)($submission['tutor_id'] ?? 0);
        $is_tutor = ($tutor_id === $user_id);
        $is_owner = ((int)$submission['user_id'] === $user_id);

        if (!$is_admin && !$is_tutor && !$is_owner) {
            http_response_code(403);
            die('Access Denied. You are not authorized to access this assignment submission.');
        }

        $relative_path = $submission['submission_file'];
        $file_name = $submission['file_name'] ?: basename($relative_path);
    }

    $full_path = root_path(ltrim($relative_path, '/'));
    if (!file_exists($full_path) || !is_file($full_path)) {
        http_response_code(404);
        die('The physical file is missing from the server.');
    }

    $file_size = filesize($full_path);
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

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

    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, max-age=3600, must-revalidate');
    header('Pragma: public');

    $disposition = ($is_view_mode && in_array($file_ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt'])) ? 'inline' : 'attachment';
    $safe_filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file_name);
    header("Content-Disposition: {$disposition}; filename=\"{$safe_filename}\"");

    readfile($full_path);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    die('Database error: ' . $e->getMessage());
}
