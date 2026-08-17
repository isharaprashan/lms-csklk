<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to save progress.']);
    exit;
}

$user_id = $_SESSION['user_id'];

$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true) ?: [];
$lesson_id = trim($input_data['lesson_id'] ?? $_POST['lesson_id'] ?? '');
$current_time = floatval($input_data['current_time'] ?? $_POST['current_time'] ?? 0);
$duration = floatval($input_data['duration'] ?? $_POST['duration'] ?? 0);

if (empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Lesson ID is required']);
    exit;
}

// Sanity checks
if ($current_time < 0)
    $current_time = 0;
if ($duration < 0)
    $duration = 0;
// Don't let position exceed duration (can happen with rounding at video end)
if ($duration > 0 && $current_time > $duration) {
    $current_time = $duration;
}

$progress_percent = ($duration > 0) ? round(($current_time / $duration) * 100, 2) : 0;
if ($progress_percent > 100)
    $progress_percent = 100;

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

    $completed_val = ($progress_percent >= 90) ? 1 : 0;

    // Upsert progress record
    $stmt = $pdo->prepare("INSERT INTO lesson_progress (user_id, lesson_id, position_seconds, duration_seconds, progress_percent, completed)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            position_seconds = VALUES(position_seconds),
            duration_seconds = VALUES(duration_seconds),
            progress_percent = VALUES(progress_percent),
            completed = VALUES(completed)");
    $stmt->execute([$user_id, $lesson_id, $current_time, $duration, $progress_percent, $completed_val]);

    // Auto-complete the lesson once the student has watched 90% or more
    $completed = false;
    $next_unlocked_lesson = null;

    if ($progress_percent >= 90) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM completed_lessons WHERE user_id = ? AND lesson_id = ?");
        $stmt->execute([$user_id, $lesson_id]);
        $alreadyCompleted = $stmt->fetchColumn() > 0;

        if (!$alreadyCompleted) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO completed_lessons (user_id, lesson_id) VALUES (?, ?)");
            $stmt->execute([$user_id, $lesson_id]);
        }
        $completed = true;

        // Find next lesson in sort_order to notify frontend for instant unlock
        $stmt = $pdo->prepare("SELECT course_id, sort_order FROM lessons WHERE id = ?");
        $stmt->execute([$lesson_id]);
        $curr_info = $stmt->fetch();

        if ($curr_info) {
            $c_id = $curr_info['course_id'];
            $c_sort = (int) $curr_info['sort_order'];

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
    }

    echo json_encode([
        'success' => true,
        'lesson_id' => $lesson_id,
        'progress_percent' => $progress_percent,
        'completed' => $completed,
        'next_unlocked_lesson' => $next_unlocked_lesson
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>