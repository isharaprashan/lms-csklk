<?php
/**
 * AJAX Real-Time Polling (Heartbeat) Endpoint
 * Delivers lightweight role-based counters, live notifications, and status updates across Admin, Teacher, and Student panels.
 */

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../includes/notification_helper.php';

// Initialize session across tabs / Admin session
init_lms_session();

// Prevent all local browser and proxy caching
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    header("Content-Type: application/json");
}

// Check user login session
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode([
        'success' => false,
        'logged_in' => false,
        'message' => 'No active user session found.'
    ]);
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch user details
    $userStmt = $pdo->prepare("SELECT id, name, email, avatar, role, status FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch();

    if (!$user || $user['status'] === 'inactive') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        echo json_encode([
            'success' => false,
            'logged_in' => false,
            'account_inactive' => true,
            'redirect' => 'login.php?error=account_inactive',
            'message' => 'Your account has been deactivated by system administrators.'
        ]);
        exit;
    }

    $role = $user['role'] ?? 'student';
    $is_admin = in_array($role, ['admin', 'super_admin']);
    $is_teacher = ($role === 'teacher');
    $is_student = ($role === 'student');

    // 1. GLOBAL: Unread notifications & recent items
    $notifCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $notifCountStmt->execute([$user_id]);
    $unread_notifs_count = (int)$notifCountStmt->fetchColumn();

    $notifListStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
    $notifListStmt->execute([$user_id]);
    $raw_notifs = $notifListStmt->fetchAll();

    $formatted_notifs = [];
    foreach ($raw_notifs as $n) {
        $formatted_notifs[] = format_notification_data($n);
    }

    // Role-specific payload containers
    $student_data = null;
    $teacher_data = null;
    $admin_data = null;

    // 2. STUDENT ROLE UPDATES
    if ($is_student) {
        // Enrolled courses count
        $enrolledStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ?");
        $enrolledStmt->execute([$user_id]);
        $enrolled_count = (int)$enrolledStmt->fetchColumn();

        // Approved & Issued certificates count
        $certStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests WHERE user_id = ? AND status IN ('approved', 'issued', 'dispatched')");
        $certStmt->execute([$user_id]);
        $approved_certs_count = (int)$certStmt->fetchColumn();

        // Pending certificate requests count
        $pendingCertStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_requests WHERE user_id = ? AND status = 'pending'");
        $pendingCertStmt->execute([$user_id]);
        $pending_certs_count = (int)$pendingCertStmt->fetchColumn();

        // Recent certificate updates
        $recentCertsStmt = $pdo->prepare("SELECT id, course_id, course_title, status, certificate_code, updated_at FROM certificate_requests WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5");
        $recentCertsStmt->execute([$user_id]);
        $recent_certs = $recentCertsStmt->fetchAll();

        // Completed lessons count
        $compLessonsStmt = $pdo->prepare("SELECT COUNT(DISTINCT lesson_id) FROM completed_lessons WHERE user_id = ?");
        $compLessonsStmt->execute([$user_id]);
        $completed_lessons_count = (int)$compLessonsStmt->fetchColumn();

        // Enrolled courses progress list
        $enrCourseStmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
        $enrCourseStmt->execute([$user_id]);
        $enrCourseIds = $enrCourseStmt->fetchAll(PDO::FETCH_COLUMN);

        $courses_progress = [];
        foreach ($enrCourseIds as $cId) {
            $totStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
            $totStmt->execute([$cId]);
            $tot = (int)$totStmt->fetchColumn();

            $compStmt = $pdo->prepare("SELECT COUNT(DISTINCT cl.lesson_id) FROM completed_lessons cl INNER JOIN lessons l ON cl.lesson_id = l.id WHERE cl.user_id = ? AND l.course_id = ?");
            $compStmt->execute([$user_id, $cId]);
            $comp = (int)$compStmt->fetchColumn();

            $pct = ($tot > 0) ? (int)min(100, round(($comp / $tot) * 100)) : 0;
            $courses_progress[$cId] = [
                'course_id' => $cId,
                'total_lessons' => $tot,
                'completed_lessons' => $comp,
                'progress_percent' => $pct,
                'is_completed' => ($tot > 0 && $comp >= $tot)
            ];
        }

        $student_data = [
            'enrolled_courses_count' => $enrolled_count,
            'approved_certificates_count' => $approved_certs_count,
            'pending_certificates_count' => $pending_certs_count,
            'completed_lessons_count' => $completed_lessons_count,
            'recent_certificates' => $recent_certs,
            'courses_progress' => $courses_progress
        ];
    }

    // 3. TEACHER ROLE UPDATES
    if ($is_teacher) {
        // Teacher's courses
        $coursesStmt = $pdo->prepare("SELECT id FROM courses WHERE tutor_id = ? AND (is_archived = 0 OR is_archived IS NULL)");
        $coursesStmt->execute([$user_id]);
        $teacher_course_ids = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
        $total_courses = count($teacher_course_ids);

        // Course status counts
        $pendingCoursesStmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE tutor_id = ? AND status = 'pending' AND (is_archived = 0 OR is_archived IS NULL)");
        $pendingCoursesStmt->execute([$user_id]);
        $teacher_pending_courses = (int)$pendingCoursesStmt->fetchColumn();

        $approvedCoursesStmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE tutor_id = ? AND status = 'approved' AND (is_archived = 0 OR is_archived IS NULL)");
        $approvedCoursesStmt->execute([$user_id]);
        $teacher_approved_courses = (int)$approvedCoursesStmt->fetchColumn();

        // Active student enrollments across teacher's courses
        $total_students = 0;
        $recent_enrollments = 0;
        $recent_quiz_attempts = 0;

        if ($total_courses > 0) {
            $in_courses = implode(',', array_fill(0, $total_courses, '?'));
            
            // Total unique students
            $studStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM enrollments WHERE course_id IN ($in_courses)");
            $studStmt->execute($teacher_course_ids);
            $total_students = (int)$studStmt->fetchColumn();

            // Recent enrollments (last 24 hours)
            try {
                $recentEnrStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE course_id IN ($in_courses) AND enrolled_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $recentEnrStmt->execute($teacher_course_ids);
                $recent_enrollments = (int)$recentEnrStmt->fetchColumn();
            } catch (Exception $ex) {}

            // Recent quiz attempts (last 24 hours)
            try {
                $qaStmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE course_id IN ($in_courses) AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $qaStmt->execute($teacher_course_ids);
                $recent_quiz_attempts = (int)$qaStmt->fetchColumn();
            } catch (Exception $ex) {}
        }

        $teacher_data = [
            'total_courses_count' => $total_courses,
            'pending_courses_count' => $teacher_pending_courses,
            'approved_courses_count' => $teacher_approved_courses,
            'total_students_count' => $total_students,
            'recent_enrollments_count' => $recent_enrollments,
            'recent_quiz_attempts_count' => $recent_quiz_attempts
        ];
    }

    // 4. ADMIN & SUPER ADMIN UPDATES
    if ($is_admin) {
        // Pending teacher verification requests
        $pTeachersStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'pending'");
        $pending_teachers = (int)$pTeachersStmt->fetchColumn();

        // Pending course approval requests
        $pCoursesStmt = $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'pending' AND (is_archived = 0 OR is_archived IS NULL)");
        $pending_courses = (int)$pCoursesStmt->fetchColumn();

        // Pending certificate requests
        $pCertsStmt = $pdo->query("SELECT COUNT(*) FROM certificate_requests WHERE status = 'pending'");
        $pending_certs = (int)$pCertsStmt->fetchColumn();

        // Pending bank slip payments
        $pSlips = 0;
        try {
            $pSlipsStmt = $pdo->query("SELECT COUNT(*) FROM bank_payments WHERE status = 'pending'");
            $pSlips = (int)$pSlipsStmt->fetchColumn();
        } catch (Exception $e) {
            try {
                $pSlipsStmt = $pdo->query("SELECT COUNT(*) FROM student_courses WHERE status = 'pending'");
                $pSlips = (int)$pSlipsStmt->fetchColumn();
            } catch (Exception $ex) {}
        }

        // Recent user registrations in last 24h
        $recent_users = 0;
        try {
            $rUsersStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $recent_users = (int)$rUsersStmt->fetchColumn();
        } catch (Exception $e) {}

        // Total registered students
        $total_registered_students = 0;
        try {
            $tStudStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
            $total_registered_students = (int)$tStudStmt->fetchColumn();
        } catch (Exception $e) {}

        $admin_data = [
            'pending_teachers_count' => $pending_teachers,
            'pending_courses_count' => $pending_courses,
            'pending_certificates_count' => $pending_certs,
            'pending_slips_count' => $pSlips,
            'recent_registrations_count' => $recent_users,
            'total_students_count' => $total_registered_students
        ];
    }

    echo json_encode([
        'success' => true,
        'logged_in' => true,
        'timestamp' => time(),
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'role' => $role,
            'email' => $user['email']
        ],
        'unread_notifications_count' => $unread_notifs_count,
        'notifications' => $formatted_notifs,
        'student' => $student_data,
        'teacher' => $teacher_data,
        'admin' => $admin_data
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'logged_in' => true,
        'message' => 'Database error during real-time heartbeat polling: ' . $e->getMessage()
    ]);
}
