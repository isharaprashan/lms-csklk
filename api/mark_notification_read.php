<?php
/**
 * API: Mark Single or All Notifications as Read
 */

require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Support JSON or POST
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];

$id = intval($_POST['id'] ?? $json_data['id'] ?? $_GET['id'] ?? 0);
$action = trim($_POST['action'] ?? $json_data['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = getDBConnection();

    if ($action === 'mark_all' || $id === 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
    }

    // Return new unread count
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtCount->execute([$user_id]);
    $unread_count = (int)$stmtCount->fetchColumn();

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'message' => 'Notification status updated.'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
