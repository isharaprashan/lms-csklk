<?php
/**
 * Automated Course Cleanup Cron Job
 * 
 * Cleans up soft-deleted courses whose 14-day grace period has expired:
 * WHERE status = 'disabled' AND deleted_at <= NOW() - INTERVAL 14 DAY
 * 
 * Usage:
 *   CLI: php cron_cleanup_courses.php [--dry-run] [--force]
 *   Web: https://your-domain.com/cron_cleanup_courses.php?dry_run=1 (Requires Admin session or cron secret key)
 */

require_once __DIR__ . '/db/db_connect.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    init_lms_session();
    
    // Security check for Web HTTP access
    $cron_secret = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'computerscience_lms_cron_2026';
    $provided_key = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    
    $is_admin = isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin']);
    $is_valid_key = (!empty($provided_key) && hash_equals($cron_secret, $provided_key));
    
    if (!$is_admin && !$is_valid_key) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Valid administrator session or cron secret key required.'
        ]);
        exit;
    }
    
    header('Content-Type: application/json');
}

// Parse CLI or HTTP flags
$dry_run = false;
$force = false;

if ($is_cli) {
    global $argv;
    $args = $argv ?? [];
    if (in_array('--dry-run', $args) || in_array('-d', $args)) {
        $dry_run = true;
    }
    if (in_array('--force', $args) || in_array('-f', $args)) {
        $force = true;
    }
} else {
    $dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';
    $force = isset($_GET['force']) && $_GET['force'] == '1';
}

$start_time = microtime(true);
$log = [];
$processed_courses = [];

try {
    $pdo = getDBConnection();

    // Find expired soft-deleted courses (deleted_at is older than 14 days)
    $query = "SELECT c.*, 
                     u.name as tutor_name, 
                     u.email as tutor_email,
                     (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) as live_enrolled_count 
              FROM courses c 
              LEFT JOIN users u ON c.tutor_id = u.id 
              WHERE (c.status = 'disabled' OR c.is_archived = 1) 
                AND c.deleted_at IS NOT NULL 
                AND c.deleted_at <= (NOW() - INTERVAL 14 DAY)
              ORDER BY c.deleted_at ASC";

    $stmt = $pdo->query($query);
    $expired_courses = $stmt->fetchAll();
    $total_found = count($expired_courses);

    if ($total_found === 0) {
        $summary = [
            'success' => true,
            'message' => 'No expired soft-deleted courses found. Database is clean.',
            'total_expired' => 0,
            'processed_count' => 0,
            'dry_run' => $dry_run,
            'execution_time_seconds' => round(microtime(true) - $start_time, 4),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($is_cli) {
            echo "[" . date('Y-m-d H:i:s') . "] No expired courses found (0 courses pending cleanup).\n";
        } else {
            echo json_encode($summary, JSON_PRETTY_PRINT);
        }
        exit(0);
    }

    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] Found {$total_found} expired soft-deleted course(s) past the 14-day grace period.\n";
        if ($dry_run) {
            echo "[DRY-RUN MODE] No records will be deleted from the database.\n";
        }
    }

    foreach ($expired_courses as $course) {
        $course_id = $course['id'];
        $course_title = $course['title'];
        $deleted_at = $course['deleted_at'];
        $enrolled_count = (int)$course['live_enrolled_count'];
        $tutor_info = $course['tutor_name'] ? "{$course['tutor_name']} ({$course['tutor_email']})" : "Tutor ID {$course['tutor_id']}";

        if ($dry_run) {
            $processed_courses[] = [
                'course_id' => $course_id,
                'title' => $course_title,
                'tutor' => $tutor_info,
                'enrolled_students' => $enrolled_count,
                'deleted_at' => $deleted_at,
                'status' => 'Dry-run: Flagged for permanent cascade deletion'
            ];
            if ($is_cli) {
                echo "  - [DRY-RUN] Course '{$course_title}' (ID: {$course_id}) deleted at {$deleted_at} ({$enrolled_count} students)\n";
            }
            continue;
        }

        // Execute permanent cascade delete in a transaction
        $pdo->beginTransaction();
        try {
            // 1. Delete lesson progress and completed lessons associated with course lessons
            try {
                $pdo->prepare("DELETE lp FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
            } catch (PDOException $e) {}

            try {
                $pdo->prepare("DELETE cl FROM completed_lessons cl INNER JOIN lessons l ON l.id = cl.lesson_id WHERE l.course_id = ?")->execute([$course_id]);
            } catch (PDOException $e) {}

            // 2. Delete lessons
            $stmt = $pdo->prepare("DELETE FROM lessons WHERE course_id = ?");
            $stmt->execute([$course_id]);

            // 3. Delete quizzes
            $stmt = $pdo->prepare("DELETE FROM quizzes WHERE course_id = ?");
            $stmt->execute([$course_id]);

            // 4. Delete quiz settings
            try {
                $stmt = $pdo->prepare("DELETE FROM course_quiz_settings WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 5. Delete quiz attempts
            try {
                $stmt = $pdo->prepare("DELETE FROM quiz_attempts WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 6. Delete quiz results
            try {
                $stmt = $pdo->prepare("DELETE FROM quiz_results WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 7. Delete forum replies & topics
            try {
                $pdo->prepare("DELETE fr FROM forum_replies fr INNER JOIN forum_topics ft ON ft.qa_id = fr.qa_id WHERE ft.course_id = ?")->execute([$course_id]);
                $pdo->prepare("DELETE FROM forum_topics WHERE course_id = ?")->execute([$course_id]);
            } catch (PDOException $e) {}

            // 8. Delete certificate requests
            try {
                $stmt = $pdo->prepare("DELETE FROM certificate_requests WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 9. Delete bank payments
            try {
                $stmt = $pdo->prepare("DELETE FROM bank_payments WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 10. Delete enrollments
            try {
                $stmt = $pdo->prepare("DELETE FROM enrollments WHERE course_id = ?");
                $stmt->execute([$course_id]);
            } catch (PDOException $e) {}

            // 11. Delete the course record itself
            $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$course_id]);

            // 12. Send a notification to the teacher if tutor_id exists
            if (!empty($course['tutor_id'])) {
                try {
                    $notifMsg = "Your course '{$course_title}' (ID: {$course_id}) has completed the 14-day grace period and has been permanently purged from the system.";
                    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $notifStmt->execute([$course['tutor_id'], $notifMsg]);
                } catch (PDOException $e) {}
            }

            $pdo->commit();

            $processed_courses[] = [
                'course_id' => $course_id,
                'title' => $course_title,
                'tutor' => $tutor_info,
                'enrolled_students' => $enrolled_count,
                'deleted_at' => $deleted_at,
                'status' => 'Permanently Deleted'
            ];

            if ($is_cli) {
                echo "  + [DELETED] Course '{$course_title}' (ID: {$course_id}) permanently purged.\n";
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $processed_courses[] = [
                'course_id' => $course_id,
                'title' => $course_title,
                'status' => 'Error: ' . $ex->getMessage()
            ];
            if ($is_cli) {
                echo "  ! [ERROR] Failed to purge course '{$course_id}': " . $ex->getMessage() . "\n";
            }
        }
    }

    $summary = [
        'success' => true,
        'message' => ($dry_run ? 'Dry-run completed.' : 'Expired soft-deleted courses cleanup completed.'),
        'total_found' => $total_found,
        'processed_count' => count($processed_courses),
        'dry_run' => $dry_run,
        'courses' => $processed_courses,
        'execution_time_seconds' => round(microtime(true) - $start_time, 4),
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($is_cli) {
        echo "[" . date('Y-m-d H:i:s') . "] Cleanup job finished in {$summary['execution_time_seconds']}s.\n";
    } else {
        echo json_encode($summary, JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    $err_response = [
        'success' => false,
        'message' => 'Cron execution error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    if ($is_cli) {
        echo "[CRON ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode($err_response, JSON_PRETTY_PRINT);
        exit;
    }
}
