<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only teachers or admins can manage lessons.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

$lesson_id = trim($input['lesson_id'] ?? '');
$course_id = trim($input['course_id'] ?? '');
$title = trim($input['title'] ?? '');
$duration = trim($input['duration'] ?? '');
$video_url = trim($input['video_url'] ?? '');
$content = trim($input['content'] ?? '');

if (empty($lesson_id) || empty($course_id) || empty($title) || empty($duration) || empty($video_url)) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all required fields (Lesson ID, Title, Duration, Video URL).']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Verify lesson exists and belongs to a course owned by this teacher
    $stmt = $pdo->prepare("SELECT c.tutor_id FROM lessons l JOIN courses c ON l.course_id = c.id WHERE l.id = ? AND c.id = ?");
    $stmt->execute([$lesson_id, $course_id]);
    $course = $stmt->fetch();
    
    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Lesson not found in the specified course.']);
        exit;
    }
    
    if (intval($course['tutor_id']) !== intval($user_id)) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this lesson.']);
        exit;
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

    // Set parent course status to pending for admin approval
    $updateCourseStmt = $pdo->prepare("UPDATE courses SET status = 'pending' WHERE id = ?");
    $updateCourseStmt->execute([$course_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Lesson updated successfully! Course submitted for admin review.',
        'lesson_id' => $lesson_id
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
