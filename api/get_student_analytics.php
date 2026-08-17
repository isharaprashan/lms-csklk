<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to view analytics.']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Check user role
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $role = $stmt->fetchColumn();

    if (!in_array($role, ['teacher', 'admin', 'super_admin'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Teacher/Admin access required.']);
        exit;
    }

    if (in_array($role, ['admin', 'super_admin'])) {
        $stmt = $pdo->query("SELECT id FROM courses");
        $course_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE tutor_id = ?");
        $stmt->execute([$user_id]);
        $course_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($course_ids)) {
        echo json_encode([
            'success' => true,
            'summary' => [
                'total_students' => 0,
                'completed_lessons' => 0,
                'quiz_pass_rate' => 0
            ],
            'rows' => []
        ]);
        exit;
    }

    $in_clause = implode(',', array_fill(0, count($course_ids), '?'));

    // Detailed analytics rows
    $query = "
        SELECT 
            u.id as student_id,
            u.name as student_name,
            u.email as student_email,
            u.academic_id as student_academic_id,
            c.id as course_id,
            c.title as course_title,
            l.id as lesson_id,
            l.title as lesson_title,
            COALESCE(lp.progress_percent, 0) as progress_percent,
            COALESCE(lp.completed, 0) as lp_completed,
            CASE WHEN cl.user_id IS NOT NULL THEN 1 ELSE 0 END as cl_completed,
            qr.score as quiz_score,
            qr.total_questions as quiz_total_questions,
            qr.status as quiz_status,
            COALESCE(qr.attempts_count, 0) as quiz_attempts
        FROM enrollments e
        JOIN users u ON e.user_id = u.id
        JOIN courses c ON e.course_id = c.id
        JOIN lessons l ON c.id = l.course_id
        LEFT JOIN lesson_progress lp ON (u.id = lp.user_id AND l.id = lp.lesson_id)
        LEFT JOIN completed_lessons cl ON (u.id = cl.user_id AND l.id = cl.lesson_id)
        LEFT JOIN quiz_results qr ON (u.id = qr.user_id AND c.id = qr.course_id)
        WHERE c.id IN ($in_clause)
        ORDER BY c.title ASC, u.name ASC, l.sort_order ASC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($course_ids);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($rows),
        'rows' => $rows
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
