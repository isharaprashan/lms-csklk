<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('login_required', 'Please log in to continue.')]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$is_admin = in_array($user_role, ['admin', 'super_admin']);

if ($user_role !== 'teacher' && !$is_admin) {
    echo json_encode(['success' => false, 'message' => __('unauthorized_action', 'Only instructors can manage lesson assignments.')]);
    exit;
}

$action = trim($_POST['action'] ?? 'save');
$course_id = trim($_POST['course_id'] ?? '');
$lesson_id = trim($_POST['lesson_id'] ?? '');
$title = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? ($_POST['description'] ?? ''));
$due_date = !empty($_POST['due_date']) ? date('Y-m-d H:i:s', strtotime($_POST['due_date'])) : null;

if (empty($course_id) || empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID and Lesson ID are required.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify course & tutor permission
    $stmt = $pdo->prepare("SELECT tutor_id FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    if (!$is_admin && (int)($course['tutor_id'] ?? 0) !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to manage assignments for this course.']);
        exit;
    }

    // Verify lesson belongs to this course
    $stmt = $pdo->prepare("SELECT id, title FROM lessons WHERE id = ? AND course_id = ?");
    $stmt->execute([$lesson_id, $course_id]);
    $lesson = $stmt->fetch();

    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'Lesson not found for this course.']);
        exit;
    }

    // Fetch existing assignment if present
    $stmt = $pdo->prepare("SELECT * FROM lesson_assignments WHERE lesson_id = ?");
    $stmt->execute([$lesson_id]);
    $existing = $stmt->fetch();

    if ($action === 'delete') {
        if ($existing) {
            if (!empty($existing['attachment_path'])) {
                $fullPath = root_path(ltrim($existing['attachment_path'], '/'));
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
            $delStmt = $pdo->prepare("DELETE FROM lesson_assignments WHERE id = ?");
            $delStmt->execute([$existing['id']]);
        }
        echo json_encode([
            'success' => true,
            'message' => 'Assignment deleted successfully.'
        ]);
        exit;
    }

    if (empty($title)) {
        echo json_encode(['success' => false, 'message' => 'Assignment title is required.']);
        exit;
    }

    $uploadDir = root_path('uploads/assignments/tasks/');
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $attachment_path = $existing['attachment_path'] ?? null;
    $attachment_name = $existing['attachment_name'] ?? null;
    $attachment_size = $existing['attachment_size'] ?? 0;

    // Handle uploaded file
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $orig_name = basename($file['name']);
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $file_size = (int)$file['size'];

        $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', '7z', 'png', 'jpg', 'jpeg', 'webp'];
        if (!in_array($ext, $allowed_exts)) {
            echo json_encode([
                'success' => false,
                'message' => "Invalid file format. Allowed formats: " . implode(', ', $allowed_exts)
            ]);
            exit;
        }

        if ($file_size > 50 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File size exceeds maximum allowed limit (50 MB).']);
            exit;
        }

        // Remove old file if updating
        if (!empty($existing['attachment_path'])) {
            $oldPath = root_path(ltrim($existing['attachment_path'], '/'));
            if (file_exists($oldPath) && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $new_filename = 'asg_task_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $course_id) . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $lesson_id) . '_' . uniqid() . '.' . $ext;
        $dest_path = $uploadDir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            $attachment_path = 'uploads/assignments/tasks/' . $new_filename;
            $attachment_name = $orig_name;
            $attachment_size = $file_size;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save attached file on server.']);
            exit;
        }
    }

    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE lesson_assignments 
                                     SET title = ?, instructions = ?, attachment_path = ?, attachment_name = ?, attachment_size = ?, due_date = ?, updated_at = NOW() 
                                     WHERE id = ?");
        $updateStmt->execute([
            $title,
            $instructions,
            $attachment_path,
            $attachment_name,
            $attachment_size,
            $due_date,
            $existing['id']
        ]);
        $assignment_id = $existing['id'];
        $message = 'Lesson assignment updated successfully!';
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO lesson_assignments 
                                     (lesson_id, course_id, title, instructions, attachment_path, attachment_name, attachment_size, due_date, created_by) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([
            $lesson_id,
            $course_id,
            $title,
            $instructions,
            $attachment_path,
            $attachment_name,
            $attachment_size,
            $due_date,
            $user_id
        ]);
        $assignment_id = (int)$pdo->lastInsertId();
        $message = 'Lesson assignment attached successfully!';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'assignment_id' => $assignment_id,
        'title' => $title,
        'has_attachment' => !empty($attachment_path),
        'attachment_name' => $attachment_name
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
