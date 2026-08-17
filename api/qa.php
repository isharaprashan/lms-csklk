<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to participate in the forum.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_avatar = $_SESSION['user_avatar'];

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getDBConnection();

    if ($method === 'GET') {
        $course_id = $_GET['course_id'] ?? '';
        if (empty($course_id)) {
            echo json_encode(['success' => false, 'message' => 'Course ID is required']);
            exit;
        }

        $qa_list = fetchQAList($pdo, $course_id);
        echo json_encode(['success' => true, 'qa' => $qa_list]);
        exit;
    }

    if ($method === 'POST') {
        $input_data = json_decode(file_get_contents('php://input'), true);
        $course_id = $input_data['course_id'] ?? $_POST['course_id'] ?? '';
        
        if (empty($course_id)) {
            echo json_encode(['success' => false, 'message' => 'Course ID is required']);
            exit;
        }

        $question_text = $input_data['question'] ?? $_POST['question'] ?? '';
        $reply_text = $input_data['reply'] ?? $_POST['reply'] ?? '';
        $qa_id = $input_data['qa_id'] ?? $_POST['qa_id'] ?? '';

        // Handle posting a new question
        if (!empty($question_text)) {
            $new_qa_id = 'qa-' . uniqid();
            $timestamp = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO forum_topics (qa_id, course_id, user_id, student_name, student_avatar, question, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $new_qa_id,
                $course_id,
                $user_id,
                $user_name,
                $user_avatar,
                htmlspecialchars($question_text),
                $timestamp
            ]);

            // Return updated list of QA posts
            $qa_list = fetchQAList($pdo, $course_id);
            echo json_encode([
                'success' => true,
                'message' => 'Question posted!',
                'qa' => $qa_list
            ]);
            exit;
        }

        // Handle replying to a question
        if (!empty($reply_text) && !empty($qa_id)) {
            // Check if topic exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_topics WHERE qa_id = ?");
            $stmt->execute([$qa_id]);
            if ($stmt->fetchColumn() == 0) {
                echo json_encode(['success' => false, 'message' => 'Question thread not found']);
                exit;
            }

            $timestamp = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO forum_replies (qa_id, user_id, replier_name, replier_avatar, content, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $qa_id,
                $user_id,
                $user_name,
                $user_avatar,
                htmlspecialchars($reply_text),
                $timestamp
            ]);

            // Return updated list of QA posts
            $qa_list = fetchQAList($pdo, $course_id);
            echo json_encode([
                'success' => true,
                'message' => 'Reply posted!',
                'qa' => $qa_list
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid parameters: post a question or reply']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// Helper function to load QA list
function fetchQAList($pdo, $course_id) {
    // Join with users table to retrieve role of the poster
    $stmt = $pdo->prepare("SELECT ft.*, u.role as poster_role 
                           FROM forum_topics ft 
                           LEFT JOIN users u ON ft.user_id = u.id 
                           WHERE ft.course_id = ? 
                           ORDER BY ft.timestamp DESC");
    $stmt->execute([$course_id]);
    $topics = $stmt->fetchAll();

    $qa_list = [];
    foreach ($topics as $topic) {
        // Join with users table to retrieve role of the replier
        $replyStmt = $pdo->prepare("SELECT fr.*, u.role as replier_role 
                                    FROM forum_replies fr 
                                    LEFT JOIN users u ON fr.user_id = u.id 
                                    WHERE fr.qa_id = ? 
                                    ORDER BY fr.timestamp ASC");
        $replyStmt->execute([$topic['qa_id']]);
        $replies = $replyStmt->fetchAll();

        $formattedReplies = [];
        foreach ($replies as $reply) {
            $formattedReplies[] = [
                'replier_name' => $reply['replier_name'],
                'replier_avatar' => $reply['replier_avatar'],
                'replier_role' => $reply['replier_role'] ?? 'student',
                'content' => $reply['content'],
                'timestamp' => date('Y-m-d H:i', strtotime($reply['timestamp']))
            ];
        }

        $qa_list[] = [
            'qa_id' => $topic['qa_id'],
            'student_name' => $topic['student_name'],
            'student_avatar' => $topic['student_avatar'],
            'poster_role' => $topic['poster_role'] ?? 'student',
            'timestamp' => date('Y-m-d H:i', strtotime($topic['timestamp'])),
            'question' => $topic['question'],
            'answers' => $formattedReplies
        ];
    }
    return $qa_list;
}
?>
