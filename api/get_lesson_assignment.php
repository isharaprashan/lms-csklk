<?php
/**
 * API: Get Attached Assignment for a Lesson
 */

require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$lesson_id = trim($_GET['lesson_id'] ?? $_POST['lesson_id'] ?? '');

if (empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Lesson ID is required.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT id, lesson_id, course_id, title, instructions, attachment_path, attachment_name, attachment_size, due_date, created_at 
                           FROM lesson_assignments 
                           WHERE lesson_id = ? LIMIT 1");
    $stmt->execute([$lesson_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($assignment) {
        $bytes = (int)($assignment['attachment_size'] ?? 0);
        if ($bytes >= 1048576) {
            $assignment['formatted_size'] = round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $assignment['formatted_size'] = round($bytes / 1024, 1) . ' KB';
        } else {
            $assignment['formatted_size'] = $bytes . ' B';
        }
        $assignment['has_attachment'] = !empty($assignment['attachment_path']);
        if (!empty($assignment['due_date'])) {
            $assignment['due_date_formatted'] = date('Y-m-d\TH:i', strtotime($assignment['due_date']));
        } else {
            $assignment['due_date_formatted'] = '';
        }
    }

    echo json_encode([
        'success' => true,
        'lesson_id' => $lesson_id,
        'assignment' => $assignment ?: null
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
