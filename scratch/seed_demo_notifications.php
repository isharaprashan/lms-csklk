<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$pdo = getDBConnection();

// Fetch active users (all students & teachers)
$stmt = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('student', 'teacher', 'admin', 'super_admin')");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    $uid = $u['id'];
    
    // Clear old test notifications
    $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$uid]);

    // 1. Course update
    create_user_notification(
        $pdo, 
        $uid, 
        'New lecture module "Building Neural Networks" has been published in your enrolled syllabus.', 
        'course', 
        '🎓 New Lesson Published', 
        'my_courses.php'
    );

    // 2. Certificate issued
    create_user_notification(
        $pdo, 
        $uid, 
        'Congratulations! Your Official Certificate of Completion is now verified and ready for download.', 
        'certificate', 
        '🏆 Certificate Issued', 
        'profile.php#certificates'
    );

    // 3. Payment approved
    create_user_notification(
        $pdo, 
        $uid, 
        'Your bank deposit slip for LKR 4,500 has been verified. Course access unlocked.', 
        'payment', 
        '💰 Payment Verified', 
        'profile.php#payments'
    );

    // 4. Q&A Reply
    create_user_notification(
        $pdo, 
        $uid, 
        'Instructor Dev answered your question on "Optimizing SQL Queries with Indexes".', 
        'qa', 
        '💬 New Answer in Q&A', 
        'dashboard.php'
    );

    // 5. System alert (marked as read)
    $stmtSys = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 2 DAY))");
    $stmtSys->execute([
        $uid,
        '⚠️ Scheduled Maintenance',
        'System upgrade completed successfully. All services are running with enhanced performance.',
        'system',
        'notifications.php'
    ]);

    echo "Seeded 5 categorized notifications for User '{$u['name']}' (ID: {$uid}, Role: {$u['role']})\n";
}
