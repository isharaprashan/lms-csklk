<?php
/**
 * API: Get Attached Resources for a Lesson
 */

require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

$lesson_id = trim($_GET['lesson_id'] ?? $_POST['lesson_id'] ?? '');

if (empty($lesson_id)) {
    echo json_encode(['success' => false, 'message' => 'Lesson ID is required.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT id, lesson_id, file_name, file_type, file_size, uploaded_at 
                           FROM lesson_resources 
                           WHERE lesson_id = ? 
                           ORDER BY uploaded_at ASC");
    $stmt->execute([$lesson_id]);
    $resources = $stmt->fetchAll();

    // Format human-readable size for each resource
    foreach ($resources as &$res) {
        $bytes = (int)$res['file_size'];
        if ($bytes >= 1048576) {
            $res['formatted_size'] = round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $res['formatted_size'] = round($bytes / 1024, 1) . ' KB';
        } else {
            $res['formatted_size'] = $bytes . ' B';
        }
    }
    unset($res);

    echo json_encode([
        'success' => true,
        'lesson_id' => $lesson_id,
        'resources' => $resources,
        'count' => count($resources)
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
