<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to save progress.']);
    exit;
}

$user_id = $_SESSION['user_id'];

$input_data = json_decode(file_get_contents('php://input'), true);
$lesson_id = $input_data['lesson_id'] ?? $_POST['lesson_id'] ?? '';

if (empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Lesson ID is required']);
    exit;
}

try {
    $pdo = getDBConnection();

    // REQUIREMENT 2: Progression Tracking Exemption for Teachers & Admins
    $user_role = $_SESSION['user_role'] ?? '';
    if (empty($user_role) || !in_array($user_role, ['student', 'teacher', 'admin', 'super_admin'])) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_role = $stmt->fetchColumn() ?: 'student';
    }

    if (in_array($user_role, ['teacher', 'admin', 'super_admin'])) {
        echo json_encode([
            'success' => true,
            'review_mode' => true,
            'message' => 'Instructor Review Mode active: progress tracking bypassed.',
            'completed' => false,
            'next_unlocked_lesson' => null
        ]);
        exit;
    }

    // Verify lesson exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Lesson not found in database']);
        exit;
    }

    // Insert completion
    $stmt = $pdo->prepare("INSERT IGNORE INTO completed_lessons (user_id, lesson_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $lesson_id]);

    // Also update lesson_progress table
    $stmt = $pdo->prepare("INSERT INTO lesson_progress (user_id, lesson_id, position_seconds, duration_seconds, progress_percent, completed)
        VALUES (?, ?, 0, 0, 100, 1)
        ON DUPLICATE KEY UPDATE progress_percent = 100, completed = 1");
    $stmt->execute([$user_id, $lesson_id]);

    // Find next lesson in sort_order
    $next_unlocked_lesson = null;
    $stmt = $pdo->prepare("SELECT course_id, sort_order FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $curr_info = $stmt->fetch();
    $c_id = $curr_info['course_id'] ?? '';
    $c_sort = (int)($curr_info['sort_order'] ?? 0);

    if (!empty($c_id)) {
        $stmt = $pdo->prepare("SELECT id, title FROM lessons WHERE course_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1");
        $stmt->execute([$c_id, $c_sort]);
        $next = $stmt->fetch();

        if ($next) {
            $next_unlocked_lesson = [
                'id' => $next['id'],
                'title' => $next['title']
            ];
        }
    }

    // Calculate real-time course progress
    $course_total_lessons = 0;
    $course_completed_lessons = 0;
    $course_progress_percent = 0;
    $is_course_completed = false;

    if (!empty($c_id)) {
        $totStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
        $totStmt->execute([$c_id]);
        $course_total_lessons = (int)$totStmt->fetchColumn();

        $compStmt = $pdo->prepare("SELECT COUNT(DISTINCT cl.lesson_id) FROM completed_lessons cl INNER JOIN lessons l ON cl.lesson_id = l.id WHERE cl.user_id = ? AND l.course_id = ?");
        $compStmt->execute([$user_id, $c_id]);
        $course_completed_lessons = (int)$compStmt->fetchColumn();

        $course_progress_percent = ($course_total_lessons > 0) ? (int)round(($course_completed_lessons / $course_total_lessons) * 100) : 0;
        if ($course_progress_percent > 100) $course_progress_percent = 100;
        $is_course_completed = ($course_completed_lessons >= $course_total_lessons && $course_total_lessons > 0);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Lesson marked as completed!',
        'lesson_id' => $lesson_id,
        'course_id' => $c_id,
        'completed' => true,
        'next_unlocked_lesson' => $next_unlocked_lesson,
        'course_total_lessons' => $course_total_lessons,
        'course_completed_lessons' => $course_completed_lessons,
        'course_progress_percent' => $course_progress_percent,
        'is_course_completed' => $is_course_completed
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>