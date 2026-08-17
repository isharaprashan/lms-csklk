<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a quiz.']);
    exit;
}

$user_id = $_SESSION['user_id'];

$input_data = json_decode(file_get_contents('php://input'), true);
$course_id = $input_data['course_id'] ?? $_POST['course_id'] ?? '';
$lesson_id = $input_data['lesson_id'] ?? $_POST['lesson_id'] ?? '';
$user_answers = $input_data['answers'] ?? $_POST['answers'] ?? []; // Map of question_id -> selected_index

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch quiz questions strictly for this course and lesson
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND lesson_id = ? ORDER BY id ASC");
        $stmt->execute([$course_id, $lesson_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND (lesson_id IS NULL OR lesson_id = '') ORDER BY id ASC");
        $stmt->execute([$course_id]);
    }
    $quiz_questions = $stmt->fetchAll();

    if (empty($quiz_questions)) {
        echo json_encode(['success' => false, 'message' => 'No quiz found for this lesson']);
        exit;
    }

    $score = 0;
    $total = count($quiz_questions);
    $details = [];

    foreach ($quiz_questions as $question) {
        $qid = $question['question_id'];
        $correct_index = (int) $question['answer_index'];

        // Match user's answer
        $user_selection = isset($user_answers[$qid]) ? (int) $user_answers[$qid] : -1;
        $is_correct = ($user_selection === $correct_index);

        if ($is_correct) {
            $score++;
        }

        $details[$qid] = [
            'is_correct' => $is_correct,
            'correct_index' => $correct_index,
            'user_selection' => $user_selection
        ];
    }

    // Determine status (e.g. passed if >= 50% score)
    $pass_ratio = ($total > 0) ? ($score / $total) : 0;
    $status = ($pass_ratio >= 0.5) ? 'passed' : 'failed';

    // Persist score ONLY for regular students (Instructors & Admins review quizzes without saving progress)
    $userRole = $_SESSION['user_role'] ?? '';
    $is_review_mode = in_array($userRole, ['admin', 'super_admin', 'teacher']);

    if (!$is_review_mode) {
        $stmt = $pdo->prepare("INSERT INTO quiz_results (user_id, course_id, score, total_questions, status, attempts_count) 
                               VALUES (?, ?, ?, ?, ?, 1) 
                               ON DUPLICATE KEY UPDATE 
                                 score = GREATEST(score, VALUES(score)),
                                 total_questions = VALUES(total_questions),
                                 status = VALUES(status),
                                 attempts_count = attempts_count + 1");
        $stmt->execute([$user_id, $course_id, $score, $total, $status]);

        if (!empty($lesson_id)) {
            $now = time();
            $stmt = $pdo->prepare("INSERT INTO quiz_attempts (user_id, course_id, lesson_id, attempt_number, score, total_questions, status, current_question_index, question_started_at, answers_json) 
                                   VALUES (?, ?, ?, 1, ?, ?, 'finalized', ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE 
                                     score = GREATEST(score, VALUES(score)),
                                     status = 'finalized'");
            $stmt->execute([$user_id, $course_id, $lesson_id, $score, $total, $total, $now, json_encode($user_answers)]);
        }
    }

    echo json_encode([
        'success' => true,
        'score' => $score,
        'total' => $total,
        'details' => $details,
        'is_review_mode' => $is_review_mode,
        'message' => "Quiz submitted successfully! You scored {$score}/{$total}."
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>