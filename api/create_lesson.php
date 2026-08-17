<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only teachers or admins can manage course lessons.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

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

    // Determine sort order
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $sort_order = intval($stmt->fetchColumn());

    $lesson_id = 'lesson-' . uniqid();

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

    // Set parent course status to pending for admin approval
    $updateCourseStmt = $pdo->prepare("UPDATE courses SET status = 'pending' WHERE id = ?");
    $updateCourseStmt->execute([$course_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Lesson added successfully! Course submitted for admin review.',
        'lesson_id' => $lesson_id
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
