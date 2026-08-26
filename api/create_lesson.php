<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only teachers or admins can manage course lessons.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']);

// Parse JSON or POST input
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

$course_id = trim($input['course_id'] ?? '');
$title = trim($input['title'] ?? '');
$duration = trim($input['duration'] ?? '');
$video_url = trim($input['video_url'] ?? '');
$content = trim($input['content'] ?? '');

if (empty($course_id) || empty($title) || empty($duration)) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all required fields (Course ID, Title, Duration).']);
    exit;
}

if (empty($video_url)) {
    // Default video placeholder if none is provided
    $video_url = 'uploads/class.mp4';
}

try {
    $pdo = getDBConnection();

    // Verify course exists and belongs to this teacher or admin
    $stmt = $pdo->prepare("SELECT tutor_id FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    if (!$is_admin && !empty($course['tutor_id']) && intval($course['tutor_id']) !== intval($user_id)) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this course.']);
        exit;
    }

    // Determine sort order
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $sort_order = intval($stmt->fetchColumn());

    $lesson_id = 'lesson-' . uniqid();

    // Handle local video upload if provided
    if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
        $v_upload_dir = __DIR__ . '/../uploads/lesson_videos/';
        if (!is_dir($v_upload_dir)) {
            @mkdir($v_upload_dir, 0777, true);
        }
        $v_orig = $_FILES['video_file']['name'];
        $v_ext = strtolower(pathinfo($v_orig, PATHINFO_EXTENSION));
        $allowed_v_exts = ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi'];
        if (in_array($v_ext, $allowed_v_exts)) {
            $v_safe_name = 'video_' . preg_replace('/[^a-z0-9]/', '', strtolower($lesson_id)) . '_' . time() . '.' . $v_ext;
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $v_upload_dir . $v_safe_name)) {
                $video_url = 'uploads/lesson_videos/' . $v_safe_name;
            }
        }
    }

    // Insert lesson
    $stmt = $pdo->prepare("INSERT INTO lessons (id, course_id, title, duration, video_url, content, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $lesson_id,
        $course_id,
        $title,
        $duration,
        $video_url,
        $content,
        $sort_order
    ]);

    // Handle supplementary file attachments upload
    $saved_resources = [];
    $files_input = $_FILES['attachments'] ?? $_FILES['resources'] ?? null;
    if ($files_input && !empty($files_input['name'])) {
        $upload_dir = __DIR__ . '/../uploads/lesson_resources/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }

        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip', 'rar'];
        $names = is_array($files_input['name']) ? $files_input['name'] : [$files_input['name']];
        $tmp_names = is_array($files_input['tmp_name']) ? $files_input['tmp_name'] : [$files_input['tmp_name']];
        $errors = is_array($files_input['error']) ? $files_input['error'] : [$files_input['error']];
        $sizes = is_array($files_input['size']) ? $files_input['size'] : [$files_input['size']];

        $insertResStmt = $pdo->prepare("INSERT INTO lesson_resources (lesson_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");

        foreach ($names as $idx => $orig_name) {
            if ($errors[$idx] !== UPLOAD_ERR_OK || empty($orig_name)) {
                continue;
            }

            $tmp_path = $tmp_names[$idx];
            $file_size = (int)$sizes[$idx];

            if ($file_size > 52428800) { // Max 50MB
                continue;
            }

            $clean_orig_name = basename($orig_name);
            $ext = strtolower(pathinfo($clean_orig_name, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_extensions)) {
                continue;
            }

            $safe_filename = 'res_' . preg_replace('/[^a-z0-9]/', '', strtolower($lesson_id)) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest_path = $upload_dir . $safe_filename;

            $moved = is_uploaded_file($tmp_path) ? move_uploaded_file($tmp_path, $dest_path) : @copy($tmp_path, $dest_path);
            if ($moved) {
                $db_file_path = 'uploads/lesson_resources/' . $safe_filename;
                $insertResStmt->execute([
                    $lesson_id,
                    $clean_orig_name,
                    $db_file_path,
                    $ext,
                    $file_size
                ]);
                $saved_resources[] = [
                    'id' => $pdo->lastInsertId(),
                    'file_name' => $clean_orig_name,
                    'file_type' => $ext,
                    'file_size' => $file_size
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Lesson added successfully!',
        'lesson_id' => $lesson_id,
        'resources_count' => count($saved_resources),
        'resources' => $saved_resources
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
