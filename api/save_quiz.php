<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
init_lms_session();

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('login_required', 'Please log in to continue.')]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';

if ($user_role !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admins cannot create or edit quizzes as admin mode is preview only.']);
    exit;
}

$course_id = trim($_POST['course_id'] ?? '');
$lesson_id = trim($_POST['lesson_id'] ?? '');
$max_attempts = max(1, min(3, (int)($_POST['max_attempts'] ?? 3)));
$pass_percentage = max(1, min(100, (int)($_POST['pass_percentage'] ?? 50)));

$questions_raw = $_POST['questions_json'] ?? '[]';
$questions_data = json_decode($questions_raw, true);

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required.']);
    exit;
}

if (!is_array($questions_data) || empty($questions_data)) {
    echo json_encode(['success' => false, 'message' => 'At least one valid question is required.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Check course ownership
    $stmt = $pdo->prepare("SELECT tutor_id FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    if ((int)$course['tutor_id'] !== $user_id) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this course quiz.']);
        exit;
    }

    if (empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute([$course_id]);
        $first_lesson = $stmt->fetch();
        if ($first_lesson) {
            $lesson_id = $first_lesson['id'];
        }
    }

    // Ensure upload directory exists
    $uploadDir = __DIR__ . '/../uploads/quiz/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Save Course Quiz Settings
    $stmt = $pdo->prepare("INSERT INTO course_quiz_settings (course_id, max_attempts, pass_percentage)
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                             max_attempts = VALUES(max_attempts),
                             pass_percentage = VALUES(pass_percentage)");
    $stmt->execute([$course_id, $max_attempts, $pass_percentage]);

    // Fetch existing questions to keep image paths if unchanged for target lesson/course scope
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT question_id, image_path FROM quizzes WHERE course_id = ? AND lesson_id = ?");
        $stmt->execute([$course_id, $lesson_id]);
    } else {
        $stmt = $pdo->prepare("SELECT question_id, image_path FROM quizzes WHERE course_id = ? AND (lesson_id IS NULL OR lesson_id = '')");
        $stmt->execute([$course_id]);
    }
    $existing_images = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Delete current quizzes ONLY for this lesson_id (or global) to re-insert updated list
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE course_id = ? AND lesson_id = ?");
        $stmt->execute([$course_id, $lesson_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE course_id = ? AND (lesson_id IS NULL OR lesson_id = '')");
        $stmt->execute([$course_id]);
    }

    $insertStmt = $pdo->prepare("INSERT INTO quizzes 
        (question_id, course_id, lesson_id, question, question_type, image_path, time_limit_seconds, option_1, option_2, option_3, option_4, answer_index, correct_answer, explanation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($questions_data as $q_idx => $q) {
        $q_id = !empty($q['question_id']) ? $q['question_id'] : 'q-' . uniqid();
        $q_text = trim($q['question_text'] ?? '');
        $q_type = in_array($q['question_type'] ?? '', ['mcq', 'text']) ? $q['question_type'] : 'mcq';
        $time_sec = max(5, min(600, (int)($q['time_limit_seconds'] ?? 30)));
        $explanation = trim($q['explanation'] ?? 'Refer to course materials.');

        if (empty($q_text)) {
            continue;
        }

        // Handle uploaded image for this question if present in $_FILES
        $image_path = $existing_images[$q_id] ?? null;
        $file_key = "question_image_" . $q_idx;

        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES[$file_key]['tmp_name'];
            $ext = strtolower(pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowed_exts)) {
                $new_filename = 'quiz_' . $course_id . '_' . uniqid() . '.' . $ext;
                $target_path = $uploadDir . $new_filename;
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $image_path = 'uploads/quiz/' . $new_filename;
                }
            }
        }

        if ($q_type === 'mcq') {
            $opt1 = trim($q['option_1'] ?? '');
            $opt2 = trim($q['option_2'] ?? '');
            $opt3 = trim($q['option_3'] ?? '');
            $opt4 = trim($q['option_4'] ?? '');
            $answer_idx = max(0, min(3, (int)($q['answer_index'] ?? 0)));
            $correct_ans = (string)$answer_idx;

            $insertStmt->execute([
                $q_id, $course_id, $lesson_id ?: null, $q_text, 'mcq', $image_path, $time_sec,
                $opt1, $opt2, $opt3, $opt4, $answer_idx, $correct_ans, $explanation
            ]);
        } else {
            // Text Input Question
            $correct_ans = trim($q['correct_answer'] ?? '');

            $insertStmt->execute([
                $q_id, $course_id, $lesson_id ?: null, $q_text, 'text', $image_path, $time_sec,
                null, null, null, null, -1, $correct_ans, $explanation
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Quiz saved and published successfully!'
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
