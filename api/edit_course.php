<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only teachers or admins can manage courses.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// If content type is application/json (fallback), parse raw input.
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

if (empty($course_id) || empty($title) || empty($category) || empty($long_desc)) {
    echo json_encode(['success' => false, 'message' => 'Please fill out all required fields (Title, Category, Description).']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Verify course exists and belongs to this teacher
    $stmt = $pdo->prepare("SELECT thumbnail FROM courses WHERE id = ? AND tutor_id = ?");
    $stmt->execute([$course_id, $user_id]);
    $course = $stmt->fetch();
    
    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found or unauthorized to edit.']);
        exit;
    }
    
    $thumbnail = $course['thumbnail'];
    
    // Handle File Upload for Thumbnail if a new one is uploaded
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
    }
    
    // Update course details and set status to pending for admin approval
    $stmt = $pdo->prepare("UPDATE courses SET 
        title = ?, 
        category = ?, 
        target_audience = ?, 
        price = ?, 
        level = ?, 
        duration = ?, 
        short_description = ?, 
        long_description = ?, 
        thumbnail = ?, 
        status = 'pending' 
        WHERE id = ? AND tutor_id = ?");
        
    $stmt->execute([
        $title,
        $category,
        $target_audience,
        $price,
        $level,
        $duration,
        $short_desc,
        $long_desc,
        $thumbnail,
        $course_id,
        $user_id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Course updated successfully and submitted for admin review!',
        'course_id' => $course_id
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
