<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../includes/notification_helper.php';

echo "=== STARTING NOTIFICATION SYSTEM TEST SUITE ===\n\n";

$pdo = getDBConnection();

function assert_test($condition, $name) {
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}\n";
        exit(1);
    }
}

// Test 1: Schema Check
echo "Test 1: Verifying Database Schema for notifications table...\n";
$stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
assert_test($stmt->rowCount() > 0, "Table 'notifications' exists in database");

$colsStmt = $pdo->query("DESCRIBE notifications");
$cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
assert_test(in_array('id', $cols), "Column 'id' exists");
assert_test(in_array('user_id', $cols), "Column 'user_id' exists");
assert_test(in_array('title', $cols), "Column 'title' exists");
assert_test(in_array('message', $cols), "Column 'message' exists");
assert_test(in_array('type', $cols), "Column 'type' exists");
assert_test(in_array('link', $cols), "Column 'link' exists");
assert_test(in_array('is_read', $cols), "Column 'is_read' exists");
assert_test(in_array('created_at', $cols), "Column 'created_at' exists");

echo "\n";

// Set up Test Users
$test_user_email1 = 'test_notif_user1_' . time() . '@lms.lk';
$test_user_email2 = 'test_notif_user2_' . time() . '@lms.lk';
$pwdHash = password_hash('Pass123!', PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Notif Test User 1', ?, ?, 'student', 'active')")
    ->execute([$test_user_email1, $pwdHash]);
$user_id_1 = $pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Notif Test User 2', ?, ?, 'student', 'active')")
    ->execute([$test_user_email2, $pwdHash]);
$user_id_2 = $pdo->lastInsertId();

echo "Created Test User 1 (ID: {$user_id_1}) and Test User 2 (ID: {$user_id_2})\n\n";

// Helper to run isolated API scripts
function run_api_script($script_rel_path, $get_params = [], $post_params = [], $session_data = []) {
    $runner_code = "<?php
    require_once __DIR__ . '/../db/db_connect.php';
    require_once __DIR__ . '/../includes/notification_helper.php';
    init_lms_session();
    
    // Inject session
    \$session = json_decode(base64_decode(\$argv[1]), true);
    foreach (\$session as \$k => \$v) {
        \$_SESSION[\$k] = \$v;
    }
    
    // Inject GET & POST
    \$_GET = json_decode(base64_decode(\$argv[2]), true);
    \$_POST = json_decode(base64_decode(\$argv[3]), true);
    
    // Run target script
    require __DIR__ . '/../' . \$argv[4];
    ";

    $runner_temp = __DIR__ . '/temp_runner_' . uniqid() . '.php';
    file_put_contents($runner_temp, $runner_code);

    $arg_session = base64_encode(json_encode($session_data));
    $arg_get = base64_encode(json_encode($get_params));
    $arg_post = base64_encode(json_encode($post_params));
    $arg_script = escapeshellarg($script_rel_path);

    $cmd = "php " . escapeshellarg($runner_temp) . " {$arg_session} {$arg_get} {$arg_post} {$arg_script}";
    $output = shell_exec($cmd);
    @unlink($runner_temp);

    $pos = strpos($output, '{');
    if ($pos !== false) {
        $last_pos = strrpos($output, '}');
        if ($last_pos !== false) {
            $json_str = substr($output, $pos, $last_pos - $pos + 1);
            return json_decode($json_str, true);
        }
    }
    return json_decode($output, true);
}

// Test 2: Helper Functions & Creation of Categorized Notifications
echo "Test 2: Creating categorized notifications via create_user_notification helper...\n";

// Create 1: Course Notification
$ok1 = create_user_notification($pdo, $user_id_1, 'New lecture published in Advanced AI.', 'course', 'New Lecture Added', 'classroom.php?course_id=test');
assert_test($ok1, "Course notification created");

// Create 2: Certificate Notification
$ok2 = create_user_notification($pdo, $user_id_1, 'Your official completion Certificate is ready!', 'certificate', 'Certificate Issued', 'profile.php#certificates');
assert_test($ok2, "Certificate notification created");

// Create 3: Payment Notification
$ok3 = create_user_notification($pdo, $user_id_1, 'Bank transfer of LKR 4,500 approved.', 'payment', 'Payment Approved', 'profile.php#payments');
assert_test($ok3, "Payment notification created");

// Create 4: User 2 Notification (Isolation check)
$ok4 = create_user_notification($pdo, $user_id_2, 'Welcome to Computerscience.lk!', 'system', 'Welcome', 'dashboard.php');
assert_test($ok4, "User 2 notification created");

echo "\n";

// Test 3: Fetching Notifications via api/get_notifications.php
echo "Test 3: Fetching User 1 notifications via api/get_notifications.php...\n";
$get_res = run_api_script('api/get_notifications.php', ['limit' => 10], [], [
    'user_id' => $user_id_1,
    'user_role' => 'student',
    'user_name' => 'Notif Test User 1'
]);

assert_test(isset($get_res['success']) && $get_res['success'] === true, "get_notifications API returned success: true");
assert_test($get_res['unread_count'] === 3, "Unread count is 3 for User 1");
assert_test(count($get_res['notifications']) === 3, "Returned exactly 3 notification items");
assert_test($get_res['notifications'][0]['badge'] !== '', "Formatted item contains valid category badge");
assert_test(isset($get_res['notifications'][0]['icon']), "Formatted item contains icon class");
assert_test(isset($get_res['notifications'][0]['time_ago']), "Formatted item contains relative time_ago");

// Test 3b: Filter by Type (certificate)
$get_cert_res = run_api_script('api/get_notifications.php', ['filter' => 'certificate'], [], [
    'user_id' => $user_id_1,
    'user_role' => 'student'
]);
assert_test($get_cert_res['count'] === 1, "Filter by 'certificate' returned exactly 1 notification");
assert_test($get_cert_res['notifications'][0]['type'] === 'certificate', "Filtered notification type is 'certificate'");

echo "\n";

// Test 4: Marking Single Notification as Read
echo "Test 4: Marking single notification as read via api/mark_notification_read.php...\n";
$first_notif_id = $get_res['notifications'][0]['id'];

$mark_single = run_api_script('api/mark_notification_read.php', [], ['id' => $first_notif_id], [
    'user_id' => $user_id_1,
    'user_role' => 'student'
]);

assert_test(isset($mark_single['success']) && $mark_single['success'] === true, "mark_notification_read returned success: true");
assert_test($mark_single['unread_count'] === 2, "Unread count decremented to 2");

// Verify in DB
$chkRead = $pdo->prepare("SELECT is_read FROM notifications WHERE id = ?");
$chkRead->execute([$first_notif_id]);
assert_test($chkRead->fetchColumn() == 1, "Database record is_read updated to 1");

echo "\n";

// Test 5: Marking All Notifications as Read
echo "Test 5: Marking all notifications as read via api/mark_notification_read.php (action=mark_all)...\n";
$mark_all = run_api_script('api/mark_notification_read.php', [], ['action' => 'mark_all'], [
    'user_id' => $user_id_1,
    'user_role' => 'student'
]);

assert_test(isset($mark_all['success']) && $mark_all['success'] === true, "mark_all returned success: true");
assert_test($mark_all['unread_count'] === 0, "Unread count is now 0");

$chkAllRead = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$chkAllRead->execute([$user_id_1]);
assert_test($chkAllRead->fetchColumn() == 0, "All notifications for User 1 are marked is_read = 1 in database");

echo "\n";

// Test 6: Security & Cross-User Isolation
echo "Test 6: Testing cross-user isolation and security...\n";

// User 1 cannot delete User 2's notification
$user2_notif_stmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? LIMIT 1");
$user2_notif_stmt->execute([$user_id_2]);
$user2_notif_id = $user2_notif_stmt->fetchColumn();

$del_cross = run_api_script('api/delete_notification.php', [], ['id' => $user2_notif_id], [
    'user_id' => $user_id_1,
    'user_role' => 'student'
]);

// User 2 notification should remain intact
$chkU2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE id = ?");
$chkU2->execute([$user2_notif_id]);
assert_test($chkU2->fetchColumn() == 1, "User 1 CANNOT delete User 2's notification record");

echo "\n";

// Test 7: Deleting Notifications (Single and Clear Read)
echo "Test 7: Deleting single notification & clearing read notifications...\n";

// Clear read notifications for User 1
$clear_read = run_api_script('api/delete_notification.php', [], ['action' => 'clear_read'], [
    'user_id' => $user_id_1,
    'user_role' => 'student'
]);

assert_test(isset($clear_read['success']) && $clear_read['success'] === true, "clear_read returned success: true");

$chkU1Count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$chkU1Count->execute([$user_id_1]);
assert_test($chkU1Count->fetchColumn() == 0, "All read notifications for User 1 cleared from database");

// Clean up test users
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$user_id_1, $user_id_2]);

echo "\n=== ALL NOTIFICATION SYSTEM TESTS PASSED SUCCESSFULLY! ===\n";
