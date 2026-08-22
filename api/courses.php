<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

try {
    $pdo = getDBConnection();
    
    // Fetch enrolled course IDs if user is logged in
    $enrolled_ids = [];
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Apply search filter if query is present
    $query = isset($_GET['query']) ? trim($_GET['query']) : '';
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';

    // Build SQL query to fetch active/approved courses (excluding disabled or archived courses)
    $sql = "SELECT c.*, u.name as u_name, u.avatar as u_avatar 
            FROM courses c 
            LEFT JOIN users u ON c.tutor_id = u.id 
            WHERE (c.status = 'approved' OR c.status = 'active') 
              AND (c.is_archived = 0 OR c.is_archived IS NULL) 
              AND (c.deleted_at IS NULL)";
    $params = [];

    if ($category !== '') {
        if ($category === 'Programming' || $category === 'Coding') {
            $sql .= " AND (c.category = 'Programming' OR c.category = 'Coding')";
        } else {
            $sql .= " AND c.category = ?";
            $params[] = $category;
        }
    }

    if ($query !== '') {
        $sql .= " AND (c.title LIKE ? OR c.tutor_name LIKE ? OR c.short_description LIKE ? OR c.category LIKE ? OR u.name LIKE ?)";
        $likeQuery = "%" . $query . "%";
        $params[] = $likeQuery;
        $params[] = $likeQuery;
        $params[] = $likeQuery;
        $params[] = $likeQuery;
        $params[] = $likeQuery;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $courses = $stmt->fetchAll();

    $filtered_courses = [];
    foreach ($courses as $course) {
        // Resolve tutor name & avatar reliably
        $t_name = !empty($course['tutor_name']) ? $course['tutor_name'] : (!empty($course['u_name']) ? $course['u_name'] : 'Educator');
        $raw_avatar = !empty($course['tutor_avatar']) ? $course['tutor_avatar'] : (!empty($course['u_avatar']) ? $course['u_avatar'] : '');

        if (empty($raw_avatar)) {
            $t_avatar = 'https://ui-avatars.com/api/?name=' . urlencode($t_name) . '&background=0f4c81&color=fff';
        } else {
            $t_avatar = $raw_avatar;
        }

        // Format to match the expected structure
        $formattedCourse = [
            'id' => $course['id'],
            'title' => $course['title'],
            'category' => $course['category'],
            'target_audience' => $course['target_audience'] ?? '',
            'level' => $course['level'],
            'duration' => $course['duration'],
            'enrolled_count' => $course['enrolled_count'],
            'rating' => (float)$course['rating'],
            'review_count' => $course['review_count'],
            'tutor' => [
                'name' => $t_name,
                'title' => $course['tutor_title'] ?? 'Instructor',
                'avatar' => $t_avatar
            ],
            'short_description' => $course['short_description'],
            'long_description' => $course['long_description'],
            'thumbnail' => !empty($course['thumbnail']) ? $course['thumbnail'] : 'assets/images/course-1.jpg',
            'price' => (float)$course['price'],
            'is_enrolled' => in_array($course['id'], $enrolled_ids),
            'is_tutor' => (isset($_SESSION['user_id']) && intval($course['tutor_id']) === intval($_SESSION['user_id']))
        ];
        
        $filtered_courses[] = $formattedCourse;
    }

    echo json_encode([
        'success' => true,
        'courses' => $filtered_courses,
        'enrolled_ids' => $enrolled_ids
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
