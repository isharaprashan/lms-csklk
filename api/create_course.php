<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization & live user status
$user_id = $_SESSION['user_id'];
$pdo = getDBConnection();

$stmt = $pdo->prepare("SELECT role, status, name, avatar FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_db = $stmt->fetch();

if (!$user_db || !in_array($user_db['role'], ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if (strtolower($user_db['status'] ?? 'active') !== 'active' && !in_array($user_db['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Your account is currently inactive or pending approval. Course creation is disabled.']);
    exit;
}

$user_name = $user_db['name'] ?? ($_SESSION['user_name'] ?? 'Educator');
$user_avatar = $user_db['avatar'] ?? ($_SESSION['user_avatar'] ?? '');

// If content type is application/json (fallback), parse raw input.
// Otherwise, use $_POST for multipart/form-data.
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

$course_id = trim($input['course_id'] ?? '');
$title = trim($input['title'] ?? '');
$category = trim($input['category'] ?? '');
$target_audience = trim($input['target_audience'] ?? '');
$price = floatval($input['price'] ?? 0.00);
$level = trim($input['level'] ?? 'Beginner');
$duration = intval($input['duration'] ?? 8);
$long_desc = trim($input['long_description'] ?? '');
$short_desc = substr(strip_tags($long_desc), 0, 150);
if (strlen(strip_tags($long_desc)) > 150) {
    $short_desc .= '...';
}

// Simple validations
if (empty($course_id) || empty($title) || empty($category) || empty($long_desc)) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all required fields (Title, Category, Description).']);
    exit;
}

// Sanitize course ID slug
$course_id = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $course_id)));

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Course ID slug.']);
    exit;
}

// Handle File Upload for Thumbnail
$thumbnail = 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=600&h=400&fit=crop';
if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['thumbnail']['tmp_name'];
    $fileName = $_FILES['thumbnail']['name'];
    $fileNameCmps = explode(".", $fileName);
    $fileExtension = strtolower(end($fileNameCmps));
    
    $allowedfileExtensions = array('jpg', 'gif', 'png', 'jpeg', 'webp');
    if (in_array($fileExtension, $allowedfileExtensions)) {
        $uploadFileDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0777, true);
        }
        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
        $dest_path = $uploadFileDir . $newFileName;
        
        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $thumbnail = 'uploads/' . $newFileName;
        }
    }
} else if (!empty($input['thumbnail'])) {
    $thumbnail = trim($input['thumbnail']);
}

try {
    $pdo = getDBConnection();
    
    // Check if course ID already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'A course with this ID code/slug already exists.']);
        exit;
    }

    $pdo->beginTransaction();

    $course_status = in_array($user_db['role'], ['admin', 'super_admin']) ? 'approved' : 'pending';

    // Insert course
    $stmt = $pdo->prepare("INSERT INTO courses 
        (id, title, category, target_audience, price, level, duration, enrolled_count, rating, review_count, tutor_name, tutor_title, tutor_avatar, tutor_id, short_description, long_description, thumbnail, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 5.00, 0, ?, 'Lecturer', ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $course_id,
        $title,
        $category,
        $target_audience,
        $price,
        $level,
        $duration,
        $user_name,
        $user_avatar,
        $user_id,
        $short_desc,
        $long_desc,
        $thumbnail,
        $course_status
    ]);

    // Send notification to admins if course is pending approval
    if ($course_status === 'pending') {
        $adminsStmt = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin')");
        $adminUsers = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);
        $notifMsg = "New course '" . $title . "' submitted by " . $user_name . " is pending admin approval.";
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        foreach ($adminUsers as $adminId) {
            $notifStmt->execute([$adminId, $notifMsg]);
        }
    }

    // Handle lessons insert
    $lessons_json = $input['lessons'] ?? '';
    $lessons = [];
    if (!empty($lessons_json)) {
        $lessons = json_decode($lessons_json, true);
    }
    
    if (is_array($lessons)) {
        $lessonStmt = $pdo->prepare("INSERT INTO lessons (id, course_id, title, duration, video_url, content, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($lessons as $idx => $lesson) {
            $l_title = trim($lesson['title'] ?? '');
            $l_video = trim($lesson['video_url'] ?? '');
            if (empty($l_video)) {
                $l_video = 'uploads/class.mp4';
            }
            if (empty($l_title)) continue;
            
            $l_id = 'lesson-' . uniqid();
            $l_duration = '10 mins'; // default duration if not specified
            $l_content = 'Video lecture on ' . $l_title; // default content placeholder
            
            $lessonStmt->execute([
                $l_id,
                $course_id,
                $l_title,
                $l_duration,
                $l_video,
                $l_content,
                $idx
            ]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Course and lessons created successfully!',
        'course_id' => $course_id
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
