<?php
/**
 * API: Get Real-time Notifications for Logged-in User
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../includes/notification_helper.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$filter = trim($_GET['filter'] ?? 'all'); // 'all', 'unread', 'course', 'certificate', 'payment', 'qa', 'system'
$limit = intval($_GET['limit'] ?? 20);
if ($limit <= 0 || $limit > 100) $limit = 20;

try {
    $pdo = getDBConnection();

    // Query unread count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();

    // Build notification query based on filter
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    $params = [$user_id];

    if ($filter === 'unread') {
        $sql .= " AND is_read = 0";
    } elseif (in_array($filter, ['course', 'certificate', 'payment', 'qa', 'system'])) {
        $sql .= " AND type = ?";
        $params[] = $filter;
    }

    $sql .= " ORDER BY created_at DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_notifs = $stmt->fetchAll();

    $formatted = [];
    foreach ($raw_notifs as $n) {
        $formatted[] = format_notification_data($n);
    }

    echo json_encode([
        'success' => true,
        'unread_count' => $unread_count,
        'count' => count($formatted),
        'notifications' => $formatted
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
