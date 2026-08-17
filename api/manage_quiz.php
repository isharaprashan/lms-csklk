<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admins cannot create or edit quizzes as admin mode is preview only.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$course_id = trim($input['course_id'] ?? '');
$question = trim($input['question'] ?? '');
$option_1 = trim($input['option_1'] ?? '');
$option_2 = trim($input['option_2'] ?? '');
$option_3 = trim($input['option_3'] ?? '');
$option_4 = trim($input['option_4'] ?? '');
$answer_index = isset($input['answer_index']) ? intval($input['answer_index']) : -1;

if (empty($course_id) || empty($question) || empty($option_1) || empty($option_2) || empty($option_3) || empty($option_4) || $answer_index < 0 || $answer_index > 3) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all fields and select a correct option.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify course exists and belongs to this teacher
    $stmt = $pdo->prepare("SELECT tutor_id FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found.']);
        exit;
    }

    if (intval($course['tutor_id']) !== intval($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this course.']);
        exit;
    }

    $question_id = 'q-' . uniqid();

    // Insert quiz question
    $stmt = $pdo->prepare("INSERT INTO quizzes (question_id, course_id, question, option_1, option_2, option_3, option_4, answer_index) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $question_id,
        $course_id,
        $question,
        $option_1,
        $option_2,
        $option_3,
        $option_4,
        $answer_index
    ]);

    // Set parent course status to pending for admin approval
    $updateCourseStmt = $pdo->prepare("UPDATE courses SET status = 'pending' WHERE id = ?");
    $updateCourseStmt->execute([$course_id]);

    // Retrieve updated list of quiz questions
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $quizzes = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'message' => 'Quiz question added successfully! Course submitted for admin review.',
        'quizzes' => $quizzes
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
