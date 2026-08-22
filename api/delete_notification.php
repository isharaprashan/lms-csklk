<?php
/**
 * API: Delete / Clear Notification Records
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];

$id = intval($_POST['id'] ?? $json_data['id'] ?? $_GET['id'] ?? 0);
$action = trim($_POST['action'] ?? $json_data['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = getDBConnection();

    if ($action === 'clear_read') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $stmt->execute([$user_id]);
        $msg = __('read_notifications_cleared', 'Read notifications cleared.');
    } elseif ($action === 'clear_all') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $msg = __('all_notifications_cleared', 'All notifications cleared.');
    } elseif ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $msg = __('notification_deleted', 'Notification removed.');
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action or ID.']);
        exit;
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtCount->execute([$user_id]);
    $unread_count = (int)$stmtCount->fetchColumn();

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'message' => $msg
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
