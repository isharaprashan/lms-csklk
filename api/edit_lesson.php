<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only teachers or admins can manage lessons.']);
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

$lesson_id = trim($input['lesson_id'] ?? '');
$course_id = trim($input['course_id'] ?? '');
$title = trim($input['title'] ?? '');
$duration = trim($input['duration'] ?? '');
$video_url = trim($input['video_url'] ?? '');
$content = trim($input['content'] ?? '');

if (empty($lesson_id) || empty($title) || empty($duration)) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all required fields (Lesson ID, Title, Duration).']);
    exit;
}

if (empty($video_url)) {
    $video_url = 'uploads/class.mp4';
}

try {
    $pdo = getDBConnection();
    
    // Verify lesson exists and check course tutor ownership
    if (!empty($course_id)) {
        $stmt = $pdo->prepare("SELECT l.*, c.tutor_id FROM lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ? AND c.id = ?");
        $stmt->execute([$lesson_id, $course_id]);
    } else {
        $stmt = $pdo->prepare("SELECT l.*, c.tutor_id FROM lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ?");
        $stmt->execute([$lesson_id]);
    }
    $lesson = $stmt->fetch();
    
    if (!$lesson) {
        echo json_encode(['success' => false, 'message' => 'Lesson not found.']);
        exit;
    }
    
    if (!$is_admin && !empty($lesson['tutor_id']) && intval($lesson['tutor_id']) !== intval($user_id)) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this lesson.']);
        exit;
    }
    
    // Handle local video upload if provided
    if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
        $v_upload_dir = root_path('uploads/lesson_videos/');
        if (!is_dir($v_upload_dir)) {
            @mkdir($v_upload_dir, 0755, true);
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

    // Update lesson details
    $stmt = $pdo->prepare("UPDATE lessons SET 
        title = ?, 
        duration = ?, 
        video_url = ?, 
        content = ? 
        WHERE id = ?");
        
    $stmt->execute([
        $title,
        $duration,
        $video_url,
        $content,
        $lesson_id
    ]);
    
    // Handle additional supplementary file attachments upload
    $saved_resources = [];
    $files_input = $_FILES['attachments'] ?? $_FILES['resources'] ?? null;
    if ($files_input && !empty($files_input['name'])) {
        $upload_dir = root_path('uploads/lesson_resources/');
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
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
    
    // Handle Assignment upload, update, or deletion
    $delete_assignment = !empty($input['delete_assignment']) && ($input['delete_assignment'] === '1' || $input['delete_assignment'] === 'true');
    $asg_title = trim($input['assignment_title'] ?? ($input['asg_title'] ?? ''));
    $asg_instructions = trim($input['assignment_instructions'] ?? ($input['asg_instructions'] ?? ''));
    $asg_due_date = !empty($input['assignment_due_date']) ? date('Y-m-d H:i:s', strtotime($input['assignment_due_date'])) : null;

    $asgStmt = $pdo->prepare("SELECT * FROM lesson_assignments WHERE lesson_id = ?");
    $asgStmt->execute([$lesson_id]);
    $existing_asg = $asgStmt->fetch(PDO::FETCH_ASSOC);

    $assignment_saved = false;

    if ($delete_assignment) {
        if ($existing_asg) {
            if (!empty($existing_asg['attachment_path'])) {
                $fullPath = root_path(ltrim($existing_asg['attachment_path'], '/'));
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
            $pdo->prepare("DELETE FROM lesson_assignments WHERE id = ?")->execute([$existing_asg['id']]);
        }
    } elseif (!empty($asg_title) || (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) || (isset($_FILES['assignment']) && $_FILES['assignment']['error'] === UPLOAD_ERR_OK) || ($existing_asg && (!empty($asg_instructions) || !empty($asg_due_date)))) {
        if (empty($asg_title)) {
            $asg_title = ($existing_asg && !empty($existing_asg['title'])) ? $existing_asg['title'] : ($title . ' Assignment');
        }

        $upload_dir = root_path('uploads/assignments/tasks/');
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        $asg_path = $existing_asg['attachment_path'] ?? null;
        $asg_name = $existing_asg['attachment_name'] ?? null;
        $asg_size = (int)($existing_asg['attachment_size'] ?? 0);

        $asg_file_input = $_FILES['assignment_file'] ?? ($_FILES['assignment'] ?? null);
        if ($asg_file_input && $asg_file_input['error'] === UPLOAD_ERR_OK) {
            $orig_name = basename($asg_file_input['name']);
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $file_size = (int)$asg_file_input['size'];

            $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar', '7z', 'png', 'jpg', 'jpeg', 'webp'];
            if (in_array($ext, $allowed_exts) && $file_size <= 50 * 1024 * 1024) {
                // Remove old physical file if replacing
                if (!empty($existing_asg['attachment_path'])) {
                    $oldPath = root_path(ltrim($existing_asg['attachment_path'], '/'));
                    if (file_exists($oldPath) && is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $new_filename = 'asg_task_' . preg_replace('/[^a-z0-9]/', '', strtolower($lesson_id)) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest_path = $upload_dir . $new_filename;

                $moved = is_uploaded_file($asg_file_input['tmp_name']) ? move_uploaded_file($asg_file_input['tmp_name'], $dest_path) : @copy($asg_file_input['tmp_name'], $dest_path);
                if ($moved) {
                    $asg_path = 'uploads/assignments/tasks/' . $new_filename;
                    $asg_name = $orig_name;
                    $asg_size = $file_size;
                }
            }
        }

        $target_course_id = !empty($course_id) ? $course_id : ($lesson['course_id'] ?? '');

        if ($existing_asg) {
            $updateAsgStmt = $pdo->prepare("UPDATE lesson_assignments 
                                           SET title = ?, instructions = ?, attachment_path = ?, attachment_name = ?, attachment_size = ?, due_date = ?, updated_at = NOW() 
                                           WHERE id = ?");
            $updateAsgStmt->execute([
                $asg_title,
                $asg_instructions,
                $asg_path,
                $asg_name,
                $asg_size,
                $asg_due_date,
                $existing_asg['id']
            ]);
            $assignment_saved = true;
        } else {
            $insAsgStmt = $pdo->prepare("INSERT INTO lesson_assignments 
                                         (lesson_id, course_id, title, instructions, attachment_path, attachment_name, attachment_size, due_date, created_by) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insAsgStmt->execute([
                $lesson_id,
                $target_course_id,
                $asg_title,
                $asg_instructions,
                $asg_path,
                $asg_name,
                $asg_size,
                $asg_due_date,
                $user_id
            ]);
            $assignment_saved = true;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Lesson updated successfully!',
        'lesson_id' => $lesson_id,
        'new_resources_count' => count($saved_resources),
        'new_resources' => $saved_resources,
        'assignment_saved' => $assignment_saved
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
