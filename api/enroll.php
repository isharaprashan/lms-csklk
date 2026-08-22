<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

// Check authorization
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to enroll.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Support raw JSON input or post parameter
$input_data = json_decode(file_get_contents('php://input'), true);
$course_id = $input_data['course_id'] ?? $_POST['course_id'] ?? '';

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required']);
    exit;
}

// Block enrollment database records for admin/super_admin (They use Instructor Review Mode)
$userRole = $_SESSION['user_role'] ?? '';
if (in_array($userRole, ['admin', 'super_admin'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Instructor Review Mode Active: Administrator accounts have full access for course review.',
        'is_review_mode' => true,
        'course_id' => $course_id
    ]);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Check if course exists and fetch status/price
    $stmt = $pdo->prepare("SELECT price, status, is_archived, deleted_at FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();
    
    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found in database']);
        exit;
    }

    // Disallow new enrollments for disabled or archived courses
    if ($course['status'] === 'disabled' || !empty($course['is_archived']) || !empty($course['deleted_at'])) {
        echo json_encode(['success' => false, 'message' => 'This course is currently unpublished and not accepting new enrollments.']);
        exit;
    }

    // Enforce payment check
    $price = floatval($course['price']);
    if ($price > 0) {
        $is_checkout = isset($input_data['is_checkout']) && $input_data['is_checkout'] === true;
        if (!$is_checkout) {
            echo json_encode(['success' => false, 'message' => 'Direct enrollment not allowed for paid courses. Please proceed to payment.']);
            exit;
        }
    }

    // Check if already enrolled
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?");
    $stmt->execute([$user_id, $course_id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => true, 'message' => 'Already enrolled', 'course_id' => $course_id]);
        exit;
    }

    // Begin Transaction to ensure integrity
    $pdo->beginTransaction();

    // Enroll user
    $stmt = $pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $course_id]);

    // Increment enrolled count
    $stmt = $pdo->prepare("UPDATE courses SET enrolled_count = enrolled_count + 1 WHERE id = ?");
    $stmt->execute([$course_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Enrolled successfully!', 'course_id' => $course_id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
