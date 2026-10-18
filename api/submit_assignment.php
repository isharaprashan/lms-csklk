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

$assignment_id = (int)($_POST['assignment_id'] ?? 0);
$student_notes = trim($_POST['student_notes'] ?? '');

if ($assignment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch assignment details
    $stmt = $pdo->prepare("SELECT a.*, l.title as lesson_title, c.title as course_title, c.tutor_id 
                           FROM lesson_assignments a 
                           INNER JOIN lessons l ON l.id = a.lesson_id 
                           INNER JOIN courses c ON c.id = a.course_id 
                           WHERE a.id = ?");
    $stmt->execute([$assignment_id]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
        exit;
    }

    $course_id = $assignment['course_id'];
    $lesson_id = $assignment['lesson_id'];
    $tutor_id = (int)($assignment['tutor_id'] ?? 0);

    // Check enrollment for students
    if (!$is_admin && $user_role !== 'teacher') {
        $enrStmt = $pdo->prepare("SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1");
        $enrStmt->execute([$user_id, $course_id]);
        if ($enrStmt->fetchColumn() === false) {
            echo json_encode(['success' => false, 'message' => 'You must be enrolled in this course to submit assignments.']);
            exit;
        }
    }

    // Check if an existing submission file is present
    $subStmt = $pdo->prepare("SELECT * FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?");
    $subStmt->execute([$assignment_id, $user_id]);
    $existing = $subStmt->fetch();

    if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Please select a file to upload for your assignment submission.']);
            exit;
        }
    }

    $uploadDir = root_path('uploads/assignments/submissions/');
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $submission_file_path = $existing['submission_file'] ?? '';
    $orig_file_name = $existing['file_name'] ?? '';
    $file_size = (int)($existing['file_size'] ?? 0);

    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['submission_file'];
        $orig_file_name = basename($file['name']);
        $ext = strtolower(pathinfo($orig_file_name, PATHINFO_EXTENSION));
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

        // Remove old physical file if updating
        if (!empty($existing['submission_file'])) {
            $oldPath = root_path(ltrim($existing['submission_file'], '/'));
            if (file_exists($oldPath) && is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $new_filename = 'asg_sub_' . $assignment_id . '_' . $user_id . '_' . uniqid() . '.' . $ext;
        $dest_path = $uploadDir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            $submission_file_path = 'uploads/assignments/submissions/' . $new_filename;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save submission file on server.']);
            exit;
        }
    }

    if ($existing) {
        $upStmt = $pdo->prepare("UPDATE assignment_submissions 
                                 SET submission_file = ?, file_name = ?, file_size = ?, student_notes = ?, status = 'submitted', updated_at = NOW() 
                                 WHERE id = ?");
        $upStmt->execute([
            $submission_file_path,
            $orig_file_name,
            $file_size,
            $student_notes,
            $existing['id']
        ]);
        $submission_id = $existing['id'];
        $message = 'Your assignment submission has been updated successfully!';
    } else {
        $insStmt = $pdo->prepare("INSERT INTO assignment_submissions 
                                  (assignment_id, lesson_id, course_id, user_id, submission_file, file_name, file_size, student_notes, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted')");
        $insStmt->execute([
            $assignment_id,
            $lesson_id,
            $course_id,
            $user_id,
            $submission_file_path,
            $orig_file_name,
            $file_size,
            $student_notes
        ]);
        $submission_id = (int)$pdo->lastInsertId();
        $message = 'Assignment submitted successfully! Your submission is recorded for course completion.';
    }

    // Optional notification to tutor
    if ($tutor_id > 0 && $tutor_id !== $user_id && function_exists('create_user_notification')) {
        $studentName = $_SESSION['user_name'] ?? 'A student';
        create_user_notification(
            $pdo,
            $tutor_id,
            "{$studentName} submitted an assignment for {$assignment['lesson_title']}.",
            'course',
            'New Assignment Submission',
            "watch_lesson.php?course_id=" . urlencode($course_id) . "&lesson_id=" . urlencode($lesson_id)
        );
    }

    $formatted_size = ($file_size >= 1048576) ? round($file_size / 1048576, 2) . ' MB' : (($file_size >= 1024) ? round($file_size / 1024, 1) . ' KB' : $file_size . ' B');

    echo json_encode([
        'success' => true,
        'message' => $message,
        'submission_id' => $submission_id,
        'file_name' => $orig_file_name,
        'file_size_formatted' => $formatted_size,
        'submitted_at' => date('M d, Y, h:i A')
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
