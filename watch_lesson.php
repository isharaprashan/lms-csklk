<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$course_id = $_GET['course_id'] ?? $_GET['id'] ?? '';
$lesson_id = $_GET['lesson_id'] ?? '';
$locked_warning = isset($_GET['locked_warning']);

if (empty($course_id)) {
    header("Location: dashboard.php");
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();

    if (!$student) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $userRole = $student['role'] ?? 'student';
    $is_teacher = ($userRole === 'teacher');
    $is_admin = in_array($userRole, ['admin', 'super_admin']);

    // Fetch course details
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $current_course = $stmt->fetch();

    if (!$current_course) {
        die("Course not found. Go back to <a href='dashboard.php'>Dashboard</a>.");
    }

    // Check student enrollment status
    $is_enrolled = false;
    if (!$is_teacher && !$is_admin) {
        $enrollCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?");
        $enrollCheckStmt->execute([$user_id, $course_id]);
        $is_enrolled = ($enrollCheckStmt->fetchColumn() > 0);
    }

    // Access control for non-approved / disabled courses
    $course_status = $current_course['status'] ?? 'approved';
    if ($course_status !== 'approved' && $course_status !== 'active' && !$is_admin && intval($current_course['tutor_id']) !== intval($user_id)) {
        if ($course_status === 'disabled' && $is_enrolled) {
            // Enrolled students retain access to continue their lessons
        } else {
            die("This course is currently unavailable. Go back to <a href='dashboard.php'>Dashboard</a>.");
        }
    }

    // Fetch lessons for this course
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$course_id]);
    $lessons = $stmt->fetchAll();

    if (empty($lessons)) {
        die("No lessons available for this course. Go back to <a href='classroom.php?course_id=" . urlencode($course_id) . "'>Classroom</a>.");
    }

    // Fetch completed lessons
    $stmt = $pdo->prepare("SELECT lesson_id FROM completed_lessons WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $completed_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT lp.lesson_id FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE lp.user_id = ? AND l.course_id = ? AND (lp.completed = 1 OR lp.progress_percent >= 90)");
    $stmt->execute([$user_id, $course_id]);
    $watched_lessons = array_unique(array_merge($completed_lessons ?? [], $stmt->fetchAll(PDO::FETCH_COLUMN)));

    // Fetch lessons with completed or finalized quizzes for the student
    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND status IN ('completed', 'finalized')");
    $stmt->execute([$user_id, $course_id]);
    $completed_quiz_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quiz_results WHERE user_id = ? AND course_id = ? AND (status IN ('completed', 'finalized') OR score > 0)");
    $stmt->execute([$user_id, $course_id]);
    $completed_quiz_lessons = array_unique(array_filter(array_merge($completed_quiz_lessons, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    $finalized_quiz_lessons = $completed_quiz_lessons;

    // Fetch question counts for all lessons that have quizzes configured
    $stmt = $pdo->prepare("SELECT lesson_id, COUNT(*) as q_count FROM quizzes WHERE course_id = ? AND lesson_id IS NOT NULL AND lesson_id != '' GROUP BY lesson_id");
    $stmt->execute([$course_id]);
    $quiz_counts_by_lesson = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $lessons_with_quizzes = array_keys(array_filter($quiz_counts_by_lesson, fn($cnt) => $cnt > 0));

    // Determine unlocked lessons (Lesson 1 is unlocked; subsequent require previous lesson watched AND its quiz completed if quiz exists)
    $unlocked_lessons = [];
    foreach ($lessons as $idx => $l) {
        if ($is_teacher || $is_admin || $idx === 0) {
            $unlocked_lessons[] = $l['id'];
        } else {
            $prev_l = $lessons[$idx - 1];
            $prev_watched = in_array($prev_l['id'], $watched_lessons) || in_array($prev_l['id'], $completed_lessons);
            $prev_has_quiz = in_array($prev_l['id'], $lessons_with_quizzes);
            $prev_quiz_done = !$prev_has_quiz || in_array($prev_l['id'], $completed_quiz_lessons);

            if ($prev_watched && $prev_quiz_done) {
                $unlocked_lessons[] = $l['id'];
            }
        }
    }

    // Determine active lesson
    $active_lesson = $lessons[0];
    $active_index = 0;
    if (!empty($lesson_id)) {
        foreach ($lessons as $idx => $l) {
            if ($l['id'] === $lesson_id) {
                $active_lesson = $l;
                $active_index = $idx;
                break;
            }
        }
    }

    $admin_preview_param = ($is_admin || isset($_GET['admin_preview'])) ? '&admin_preview=1' : '';

    // Active lesson quiz details
    $active_has_quiz = in_array($active_lesson['id'], $lessons_with_quizzes);
    $active_quiz_completed = in_array($active_lesson['id'], $completed_quiz_lessons);
    $active_quiz_count = $quiz_counts_by_lesson[$active_lesson['id']] ?? 0;
    $quiz_url = "quiz.php?course_id=" . urlencode($course_id) . "&lesson_id=" . urlencode($active_lesson['id']) . $admin_preview_param;

    // Prev / Next lesson navigation
    $total_lessons_count = count($lessons);
    $prev_lesson = ($active_index > 0) ? $lessons[$active_index - 1] : null;
    $next_lesson = ($active_index < $total_lessons_count - 1) ? $lessons[$active_index + 1] : null;

    // Calculate overall completion metrics
    $completed_count = 0;
    foreach ($lessons as $l) {
        if (in_array($l['id'], $completed_lessons)) {
            $completed_count++;
        }
    }
    $completed_percent = ($total_lessons_count > 0) ? round(($completed_count / $total_lessons_count) * 100) : 0;

    // Fetch saved progress for active lesson
    $saved_position = 0;
    $is_active_completed = in_array($active_lesson['id'], $completed_lessons);
    try {
        $stmt = $pdo->prepare("SELECT position_seconds, progress_percent, completed FROM lesson_progress WHERE user_id = ? AND lesson_id = ?");
        $stmt->execute([$user_id, $active_lesson['id']]);
        $active_lesson_progress = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($active_lesson_progress) {
            $saved_position = floatval($active_lesson_progress['position_seconds'] ?? 0);
            if (!empty($active_lesson_progress['completed']) || floatval($active_lesson_progress['progress_percent'] ?? 0) >= 90) {
                $is_active_completed = true;
            }
        }
    } catch (PDOException $e) {
        $saved_position = 0;
    }

    // Backend direct URL access protection
    if (!$is_teacher && !$is_admin && !in_array($active_lesson['id'], $unlocked_lessons)) {
        $target_lesson = $lessons[0];
        foreach ($lessons as $l) {
            if (in_array($l['id'], $unlocked_lessons)) {
                $target_lesson = $l;
            }
        }
        header("Location: watch_lesson.php?course_id=" . urlencode($course_id) . "&lesson_id=" . urlencode($target_lesson['id']) . "&locked_warning=1" . $admin_preview_param);
        exit;
    }

    // Fetch quiz questions count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $quiz_count = (int) $stmt->fetchColumn();

    // Detect YouTube Video ID
    $active_video_url = trim($active_lesson['video_url'] ?? '');
    $yt_id = '';
    if (preg_match('~youtube\.com/embed/([^?&]+)~i', $active_video_url, $m)) {
        $yt_id = $m[1];
    } elseif (preg_match('~^(?:https?://)?(?:www\.)?(?:youtu\.be/|youtube\.com/(?:embed/|v/|watch\?v=|watch\?.+&v=|shorts/|live/))([\w-]{11})~i', $active_video_url, $m)) {
        $yt_id = $m[1];
    }

    // Fetch attached supplementary resources for all lessons in this course
    $stmt = $pdo->prepare("SELECT lr.* FROM lesson_resources lr INNER JOIN lessons l ON l.id = lr.lesson_id WHERE l.course_id = ? ORDER BY lr.uploaded_at ASC");
    $stmt->execute([$course_id]);
    $course_resources_all = $stmt->fetchAll();
    $resources_by_lesson = [];
    foreach ($course_resources_all as $res) {
        $bytes = (int)$res['file_size'];
        $res['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
        $resources_by_lesson[$res['lesson_id']][] = $res;
    }
    $active_lesson_resources = $resources_by_lesson[$active_lesson['id']] ?? [];

    // Fetch assignment for active lesson
    $active_assignment = null;
    $stmt = $pdo->prepare("SELECT * FROM lesson_assignments WHERE lesson_id = ?");
    $stmt->execute([$active_lesson['id']]);
    $active_assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($active_assignment && !empty($active_assignment['attachment_size'])) {
        $bytes = (int)$active_assignment['attachment_size'];
        $active_assignment['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
    }

    // Fetch student's submission or all student submissions if teacher/admin
    $active_submission = null;
    $assignment_submissions_list = [];
    if ($active_assignment) {
        if ($is_teacher || $is_admin) {
            $stmt = $pdo->prepare("SELECT sub.*, u.name, u.email, u.avatar 
                                   FROM assignment_submissions sub 
                                   INNER JOIN users u ON u.id = sub.user_id 
                                   WHERE sub.assignment_id = ? 
                                   ORDER BY sub.submitted_at DESC");
            $stmt->execute([$active_assignment['id']]);
            $assignment_submissions_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($assignment_submissions_list as &$sub) {
                $bytes = (int)($sub['file_size'] ?? 0);
                $sub['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
            }
            unset($sub);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?");
            $stmt->execute([$active_assignment['id'], $user_id]);
            $active_submission = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($active_submission && !empty($active_submission['file_size'])) {
                $bytes = (int)$active_submission['file_size'];
                $active_submission['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
            }
        }
    }

    // Fetch all course assignments and student submissions for syllabus badging
    $stmt = $pdo->prepare("SELECT a.id, a.lesson_id, a.title FROM lesson_assignments a INNER JOIN lessons l ON l.id = a.lesson_id WHERE l.course_id = ?");
    $stmt->execute([$course_id]);
    $course_assignments_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $assignments_by_lesson = [];
    foreach ($course_assignments_raw as $cas) {
        $assignments_by_lesson[$cas['lesson_id']] = $cas;
    }

    $user_submitted_assignment_ids = [];
    if (!$is_teacher && !$is_admin) {
        $stmt = $pdo->prepare("SELECT sub.assignment_id FROM assignment_submissions sub INNER JOIN lesson_assignments a ON a.id = sub.assignment_id INNER JOIN lessons l ON l.id = a.lesson_id WHERE l.course_id = ? AND sub.user_id = ?");
        $stmt->execute([$course_id, $user_id]);
        $user_submitted_assignment_ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // Verify course-wide assignment completion for student
    $course_assignments_status = check_course_assignments_completed($pdo, $user_id, $course_id);
    if (!$is_teacher && !$is_admin && !$course_assignments_status['all_completed']) {
        $completed_percent = min(99, $completed_percent);
    }

    // Fetch unread notifications for header
    $unread_count = 0;
    $notifications = [];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_count = intval($stmt->fetchColumn());

        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
    }

    // Fetch enrolled course IDs for sidebar drawer
    $enrolled_ids = [];
    $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->query("SELECT id, title, tutor_id FROM courses WHERE (status = 'approved' OR status = 'active') AND (is_archived = 0 OR is_archived IS NULL) AND deleted_at IS NULL ORDER BY created_at DESC");
    $all_courses = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($active_lesson['title']); ?> -
        <?php echo htmlspecialchars($current_course['title']); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
    <script src="assets/js/session_manager.js"></script>

    <!-- Local Bootstrap 5 CSS & Icons -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
    <!-- Modern Notification System Styles -->
    <link rel="stylesheet" href="assets/css/notifications.css">

    <!-- Local Tailwind CSS -->
    <script src="assets/js/tailwind.js"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false },
            theme: {
                extend: {
                    colors: { moodle: { blue: '#0f4c81', orange: '#f26f21', bg: '#f8f9fa' } }
                }
            }
        }
    </script>

    <!-- Custom Style CSS -->
    <link class="moodle-style" rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/classroom.css">

    <?php render_i18n_js(); ?>
    <style>
        .no-caret::after {
            display: none !important;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8f9fa;
            color: #1e293b;
        }

        /* Video Container */
        .video-container {
            background: #000000;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .video-container iframe,
        .video-container video,
        #player-container iframe,
        #player-container video,
        #yt-player-target {
            display: block;
            width: 100% !important;
            height: 100% !important;
            min-height: 420px;
            border: 0;
            outline: none;
        }

        /* Quiz Banner */
        .enter-quiz-banner {
            background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%);
            border-radius: 14px;
            color: #ffffff;
            padding: 1.25rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            box-shadow: 0 6px 20px rgba(15, 76, 129, 0.25);
            transition: all 0.25s ease;
        }

        .enter-quiz-banner:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(15, 76, 129, 0.35);
        }

        /* Syllabus Sidebar Container */
        .syllabus-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .syllabus-header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem;
        }

        .syllabus-playlist {
            max-height: calc(100vh - 240px);
            overflow-y: auto;
            padding: 0.75rem;
        }

        /* Syllabus Item Cards */
        .syllabus-item {
            display: block;
            padding: 0.9rem 1.1rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
            position: relative;
        }

        .syllabus-item:hover:not(.locked) {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            transform: translateX(3px);
        }

        .syllabus-item.active {
            background: linear-gradient(135deg, rgba(15, 76, 129, 0.06) 0%, rgba(15, 76, 129, 0.12) 100%);
            border: 1px solid rgba(15, 76, 129, 0.25);
            border-left: 5px solid #0f4c81;
            box-shadow: 0 4px 12px rgba(15, 76, 129, 0.08);
        }

        .syllabus-item.completed:not(.active) {
            background-color: rgba(25, 135, 84, 0.03);
            border-color: rgba(25, 135, 84, 0.15);
        }

        .syllabus-item.locked {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #f1f5f9;
            border-color: #e2e8f0;
        }

        .badge-index {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .now-playing-pill {
            background-color: #198754;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
            letter-spacing: 0.04em;
            animation: pulsePill 2s infinite;
        }

        @keyframes pulsePill {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }

        .unlocked-toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1050;
            background: #ffffff;
            border-left: 5px solid #198754;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.18);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 767.98px) {
            .video-container,
            #player-container,
            #yt-player-target {
                min-height: auto !important;
                height: auto !important;
                aspect-ratio: 16 / 9 !important;
            }
            .enter-quiz-banner {
                flex-direction: column !important;
                align-items: stretch !important;
                padding: 1rem 1.25rem !important;
                gap: 0.85rem !important;
            }
            .enter-quiz-banner a.enter-quiz-btn,
            .enter-quiz-banner #enter-quiz-banner-btn {
                width: 100% !important;
                text-align: center !important;
                justify-content: center !important;
            }
        }
    </style>
</head>

<body class="bg-light text-dark">

    <?php if ($is_admin): ?>
        <div class="alert border-0 rounded-0 mb-0 py-2.5 px-4 text-center fs-7 shadow-sm d-flex align-items-center justify-content-center gap-2"
            style="background-color: #0b4528; color: #ffffff;">
            <i class="bi bi-shield-check fs-6 text-warning"></i>
            <span><strong>Instructor Review Mode Active:</strong> Previewing course content with full Administrator
                privileges.</span>
            <a href="admin/index.php"
                class="btn btn-sm btn-light py-0.5 px-3 ms-2 fs-8 rounded-pill text-dark fw-bold border-0">Return to Admin
                Panel</a>
        </div>
    <?php endif; ?>

    <!-- Unified LMS Top Header Bar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <!-- Moodle Left Navigation Drawer -->
    <aside id="moodle-drawer" class="moodle-drawer bg-white collapsed">
        <div class="d-flex flex-column">
            <!-- Drawer Header with Prominent Close Button -->
            <div class="px-3 py-2.5 mb-2 d-flex align-items-center justify-content-between border-bottom bg-light bg-opacity-50">
                <span class="fs-8 fw-bold text-uppercase tracking-wider text-muted d-flex align-items-center gap-1.5">
                    <i class="bi bi-compass-fill text-primary"></i>
                    <span><?php echo __('navigation', 'Navigation'); ?></span>
                </span>
                <button type="button" class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center drawer-close-trigger text-secondary" style="width: 32px; height: 32px;" title="<?php echo __('close', 'Close'); ?>">
                    <i class="bi bi-x-lg fs-6"></i>
                </button>
            </div>

            <a href="index.php" class="drawer-link">
                <i class="bi bi-house-door fs-5"></i> Site Home
            </a>
            <a href="dashboard.php" class="drawer-link">
                <i class="bi bi-speedometer2 fs-5"></i> Dashboard
            </a>
            <hr class="mx-3 my-2 border-secondary border-opacity-20">

            <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider">
                <?php echo $is_teacher ? 'Courses I Teach' : 'My Courses'; ?>
            </div>
            <?php
            $enrolled_any = false;
            if ($is_teacher) {
                foreach ($all_courses as $cs_course) {
                    if (intval($cs_course['tutor_id']) === intval($user_id)) {
                        $enrolled_any = true;
                        $is_active = ($cs_course['id'] === $course_id) ? 'active' : '';
                        echo '<a href="watch_lesson.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate ' . $is_active . '">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                        </a>';
                    }
                }
            } else {
                foreach ($all_courses as $cs_course) {
                    if (in_array($cs_course['id'], $enrolled_ids)) {
                        $enrolled_any = true;
                        $is_active = ($cs_course['id'] === $course_id) ? 'active' : '';
                        echo '<a href="watch_lesson.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate ' . $is_active . '">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                        </a>';
                    }
                }
            }
            ?>
        </div>
    </aside>

    <!-- Moodle Main Content Area wrapper -->
    <main id="moodle-content-wrapper" class="moodle-content-wrapper full-width">
        <div class="container py-4 px-md-5">

            <!-- Breadcrumbs -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb moodle-breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                        <?php echo htmlspecialchars($current_course['title']); ?></li>
                </ol>
            </nav>

            <!-- Course Main Header Card -->
            <div class="moodle-card p-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gy-3">
                    <div>
                        <span
                            class="badge bg-light text-primary mb-1 border"><?php echo htmlspecialchars($current_course['category']); ?></span>
                        <h1 class="fw-bold text-dark mb-2 fs-3">
                            <?php echo htmlspecialchars($current_course['title']); ?></h1>
                        <p class="text-muted fs-7 mb-0"><i class="bi bi-person me-1"></i> Lecturer: <strong
                                class="text-secondary"><?php echo htmlspecialchars($current_course['tutor_name']); ?></strong>
                        </p>
                        <?php if (!empty($current_course['target_audience'])): ?>
                            <div class="mt-2 d-flex flex-wrap align-items-center gap-1.5">
                                <small class="text-muted fw-semibold me-1"><i class="bi bi-people me-1"></i>Target
                                    Audience:</small>
                                <?php foreach (array_filter(array_map('trim', explode(',', $current_course['target_audience']))) as $aud): ?>
                                    <span
                                        class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 px-2.5 py-1 rounded-pill fs-8">
                                        <?php echo htmlspecialchars($aud); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <span class="fs-7 text-muted">Course Progress:</span>
                        <div class="progress" style="height: 8px; width: 120px;">
                            <div class="progress-bar" id="overall-progress-bar" role="progressbar"
                                style="width: <?php echo $completed_percent; ?>%; background-color: #0f4c81;"
                                aria-valuenow="<?php echo $completed_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                        <span class="fw-bold fs-7" id="overall-progress-text"><?php echo $completed_percent; ?>%</span>
                    </div>
                </div>

                <!-- Moodle Secondary Navigation Tabs -->
                <div class="d-flex border-top mt-4 pt-2 gap-1 overflow-auto">
                    <button class="moodle-tab-btn active"><i class="bi bi-journal-richtext me-1"></i> Course</button>
                </div>
            </div>

            <!-- Locked Direct URL Warning Toast Alert -->
            <?php if ($locked_warning): ?>
                <div class="alert alert-warning alert-dismissible fade show border-warning shadow-sm rounded-4 mb-4 d-flex align-items-center gap-3 p-3"
                    role="alert">
                    <div class="rounded-circle bg-warning bg-opacity-20 text-warning p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                        <i class="bi bi-lock-fill fs-4 text-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-0 text-dark">
                            <?php echo __('locked_lesson_warning', 'Access Locked: Sequential progression required.'); ?>
                        </h6>
                        <small class="text-secondary">
                            <?php if ($active_has_quiz && !$active_quiz_completed): ?>
                                You must complete the quiz for this lesson before you can view the next lesson.
                            <?php else: ?>
                                Lessons must be completed in sequential order to unlock subsequent syllabus modules.
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php if ($active_has_quiz && !$active_quiz_completed): ?>
                        <a href="<?php echo htmlspecialchars($quiz_url); ?>" class="btn btn-warning btn-sm rounded-pill fw-bold text-dark px-3 py-1.5 shadow-sm text-nowrap flex-shrink-0">
                            <i class="bi bi-play-circle-fill me-1"></i><?php echo __('start_quiz', 'Start Quiz'); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Left Main Column: Video Player & Lesson Details -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 bg-white">

                        <!-- Lesson Header & Top Navigation Bar -->
                        <div
                            class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-3 border-bottom">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span
                                        class="badge bg-primary bg-opacity-10 text-primary px-3 py-1.5 rounded-pill fs-8 fw-bold">
                                        Lesson <?php echo $active_index + 1; ?> of <?php echo $total_lessons_count; ?>
                                    </span>
                                    <span class="text-muted fs-8"><i
                                            class="bi bi-clock me-1"></i><?php echo htmlspecialchars($active_lesson['duration']); ?></span>
                                </div>
                                <h2 class="fw-bold text-dark fs-4 mb-0">
                                    <?php echo htmlspecialchars($active_lesson['title']); ?>
                                </h2>
                            </div>

                            <!-- Previous / Next Lesson Navigation Buttons -->
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($prev_lesson): ?>
                                    <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($prev_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                        class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold">
                                        <i class="bi bi-chevron-left me-1"></i> Previous
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" disabled>
                                        <i class="bi bi-chevron-left me-1"></i> Previous
                                    </button>
                                <?php endif; ?>

                                <?php if ($next_lesson && in_array($next_lesson['id'], $unlocked_lessons)): ?>
                                    <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($next_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                        id="lesson-nav-next-btn"
                                        class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold"
                                        style="background-color: #0f4c81; border: none;">
                                        Next <i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                <?php else: ?>
                                    <button id="lesson-nav-next-btn" class="btn btn-secondary btn-sm rounded-pill px-3 fw-semibold"
                                        <?php echo ($active_has_quiz && !$active_quiz_completed) ? 'onclick="showLessonQuizModal()"' : 'disabled'; ?>
                                        title="<?php echo ($active_has_quiz && !$active_quiz_completed) ? 'Complete the lesson quiz to unlock the next lesson' : 'Complete current lesson to unlock next'; ?>">
                                        Next <i class="bi bi-lock-fill ms-1"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Lesson Video Player Container -->
                        <div class="video-container mb-4" id="player-container"
                            style="min-height: 420px; background-color: #000; border-radius: 16px; position: relative;">
                            <?php if (!empty($yt_id)): ?>
                                <div id="yt-player-target" class="w-100 h-100 rounded-4" style="min-height: 420px;"></div>
                            <?php else: ?>
                                <?php
                                $video_src = !empty($active_video_url) ? $active_video_url : 'uploads/class.mp4';
                                ?>
                                <video id="lesson-video-player" class="w-100 h-100 rounded-4" controls
                                    controlsList="nodownload" oncontextmenu="return false;" disablePictureInPicture
                                    poster="<?php echo htmlspecialchars($current_course['thumbnail'] ?? ''); ?>"
                                    preload="auto">
                                    <source
                                        src="<?php echo htmlspecialchars($video_src); ?>"
                                        type="video/mp4">
                                    Your browser does not support HTML5 video player.
                                </video>
                            <?php endif; ?>
                        </div>

                        <!-- Enter Quiz Banner -->
                        <div class="enter-quiz-banner mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-white bg-opacity-20 p-3 d-flex align-items-center justify-content-center"
                                    style="width: 48px; height: 48px;">
                                    <i class="bi bi-patch-question-fill fs-4 text-white"></i>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <h5 class="fw-bold mb-0 text-white">
                                            <?php echo __('quiz_title', 'Course Knowledge Quiz'); ?>
                                        </h5>
                                        <?php if ($active_has_quiz): ?>
                                            <?php if ($active_quiz_completed): ?>
                                                <span class="badge bg-success text-white rounded-pill px-2 py-0.5 fs-9">
                                                    <i class="bi bi-check-circle-fill me-1"></i>Completed
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark rounded-pill px-2 py-0.5 fs-9 fw-bold">
                                                    <i class="bi bi-exclamation-circle-fill me-1"></i>Required to Unlock Next
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-0 text-white-50 fs-7">
                                        <?php echo __('quiz_subtitle', 'Test your understanding of this course chapter with single-question timed challenges.'); ?>
                                    </p>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars($quiz_url); ?>"
                                class="btn btn-light btn-lg px-4 py-2 fw-bold text-primary border-0 shadow-sm text-nowrap rounded-pill">
                                <i class="bi bi-play-circle-fill me-2"></i><?php echo $active_quiz_completed ? __('retake_quiz', 'Retake Quiz') : __('start_quiz', 'Start Quiz'); ?>
                            </a>
                        </div>

                        <!-- Lesson Content & Topic Resources & Notes Section -->
                        <div class="border rounded-3 p-4 bg-light mb-3" id="topic-resources-notes-container">
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-file-earmark-text-fill text-primary fs-5"></i>
                                    <span><?php echo __('topic_resources_notes', 'Topic Resources & Notes'); ?></span>
                                </h6>
                                <?php if ($active_assignment): ?>
                                    <span class="badge <?php echo ($is_teacher || $is_admin) ? 'bg-primary' : ($active_submission ? 'bg-success' : 'bg-warning text-dark'); ?> rounded-pill px-3 py-1 fs-9 fw-bold">
                                        <i class="bi <?php echo ($active_submission || $is_teacher) ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?> me-1"></i>
                                        <?php echo ($is_teacher || $is_admin) ? 'Assignment Attached' : ($active_submission ? __('assignment_completed_badge', 'Submitted & Completed') : __('assignment_pending_badge', 'Mandatory - Submission Pending')); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Topic Notes Text -->
                            <div class="bg-white p-3.5 rounded-3 border mb-3">
                                <div class="text-secondary fs-7 leading-relaxed">
                                    <?php echo !empty(trim($active_lesson['content'] ?? '')) ? nl2br(htmlspecialchars($active_lesson['content'])) : '<span class="text-muted fst-italic">No additional lecture notes for this topic.</span>'; ?>
                                </div>
                            </div>

                            <!-- Topic Assignment & Coursework Card (Mandatory Submission for 100% Course Completion) -->
                            <div class="card border rounded-3 shadow-sm overflow-hidden mb-1" id="topic-assignment-card" style="border-color: #d0d7de !important;">
                                <div class="card-header bg-white py-3 px-3.5 d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="rounded-circle bg-primary bg-opacity-10 p-2 text-primary d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                            <i class="bi bi-journal-check fs-5"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0 fs-7 d-flex align-items-center gap-2">
                                                <span><?php echo __('topic_assignment_coursework', 'Topic Assignment & Coursework'); ?></span>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill fs-9 fw-semibold">Mandatory</span>
                                            </h6>
                                            <small class="text-muted fs-8">Required to reach 100% course completion and request official certificate</small>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($is_teacher || $is_admin): ?>
                                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm" onclick="openTeacherAssignmentModal()">
                                                <i class="bi <?php echo $active_assignment ? 'bi-pencil-square' : 'bi-plus-circle-fill'; ?> me-1"></i>
                                                <?php echo $active_assignment ? __('edit_assignment_btn', 'Edit / Replace Assignment') : __('upload_assignment_btn', 'Upload Assignment Attachment'); ?>
                                            </button>
                                            <?php if ($active_assignment): ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-2.5 fw-semibold" onclick="deleteTeacherAssignment(<?php echo (int)$active_assignment['id']; ?>)" title="Delete Assignment">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-body p-3.5 bg-light">
                                    <?php if ($active_assignment): ?>
                                        <!-- Assignment Details Banner -->
                                        <div class="p-3.5 bg-white rounded-3 border mb-3">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                                <h6 class="fw-bold text-dark mb-0 fs-6"><?php echo htmlspecialchars($active_assignment['title']); ?></h6>
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if (!empty($active_assignment['due_date'])): ?>
                                                        <span class="badge bg-light text-secondary border rounded-pill fs-8">
                                                            <i class="bi bi-calendar-event me-1"></i>Deadline: <?php echo date('M d, Y h:i A', strtotime($active_assignment['due_date'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($active_assignment['instructions'])): ?>
                                                <p class="text-secondary fs-7 mb-3 leading-relaxed">
                                                    <?php echo nl2br(htmlspecialchars($active_assignment['instructions'])); ?>
                                                </p>
                                            <?php endif; ?>

                                            <!-- Teacher's Attached Assignment Brief File -->
                                            <?php if (!empty($active_assignment['attachment_path'])): 
                                                $task_ext = strtolower(pathinfo($active_assignment['attachment_name'] ?: $active_assignment['attachment_path'], PATHINFO_EXTENSION));
                                                $task_icon = 'bi-file-earmark-arrow-down-fill text-primary';
                                                if ($task_ext === 'pdf') $task_icon = 'bi-file-earmark-pdf-fill text-danger';
                                                elseif (in_array($task_ext, ['doc', 'docx'])) $task_icon = 'bi-file-earmark-word-fill text-primary';
                                                elseif (in_array($task_ext, ['zip', 'rar', '7z'])) $task_icon = 'bi-file-earmark-zip-fill text-secondary';
                                            ?>
                                                <div class="d-flex flex-wrap align-items-center justify-content-between p-2.5 px-3 bg-light rounded-3 border gap-2">
                                                    <div class="d-flex align-items-center gap-2.5 text-truncate me-2">
                                                        <i class="bi <?php echo $task_icon; ?> fs-3 flex-shrink-0"></i>
                                                        <div class="text-truncate">
                                                            <span class="fw-semibold text-dark fs-7 d-block text-truncate"><?php echo htmlspecialchars($active_assignment['attachment_name']); ?></span>
                                                            <small class="text-muted fs-8"><?php echo $active_assignment['formatted_size'] ?? ''; ?> &bull; Assignment Brief Attachment</small>
                                                        </div>
                                                    </div>
                                                    <a href="download_assignment.php?type=task&id=<?php echo urlencode($active_assignment['id']); ?>" 
                                                       class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold shadow-sm text-nowrap">
                                                        <i class="bi bi-download me-1"></i> <?php echo __('download_assignment_brief', 'Download Assignment File'); ?>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($is_teacher || $is_admin): ?>
                                            <!-- Teacher View: Student Submissions Panel -->
                                            <div class="border rounded-3 bg-white p-3">
                                                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                                                    <h6 class="fw-bold text-dark mb-0 fs-7 d-flex align-items-center gap-2">
                                                        <i class="bi bi-people-fill text-primary"></i>
                                                        <span><?php echo __('student_submissions', 'Student Submissions'); ?></span>
                                                    </h6>
                                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-bold">
                                                        <?php echo count($assignment_submissions_list); ?> Submitted
                                                    </span>
                                                </div>

                                                <?php if (empty($assignment_submissions_list)): ?>
                                                    <div class="text-center py-3 text-muted fs-8 fst-italic">
                                                        <i class="bi bi-clock me-1"></i>No student has submitted this assignment yet.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-hover align-middle mb-0 fs-8">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>Student</th>
                                                                    <th>Submitted File</th>
                                                                    <th>Student Notes</th>
                                                                    <th>Submitted At</th>
                                                                    <th>Status</th>
                                                                    <th>Grade / Score</th>
                                                                    <th class="text-end">Actions</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($assignment_submissions_list as $sub): 
                                                                    $is_sub_graded = ($sub['status'] === 'graded' && $sub['grade'] !== null);
                                                                ?>
                                                                    <tr id="watch-sub-row-<?php echo $sub['id']; ?>">
                                                                        <td>
                                                                            <div class="d-flex align-items-center gap-2">
                                                                                <img src="<?php echo htmlspecialchars($sub['avatar'] ?: 'assets/default-avatar.png'); ?>" class="rounded-circle border" style="width: 26px; height: 26px; object-fit: cover;" alt="">
                                                                                <div>
                                                                                    <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($sub['name']); ?></span>
                                                                                    <small class="text-muted fs-9"><?php echo htmlspecialchars($sub['email']); ?></small>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                        <td>
                                                                            <a href="download_assignment.php?type=submission&id=<?php echo urlencode($sub['id']); ?>" class="btn btn-sm btn-light border py-1 px-2.5 rounded-pill text-primary fw-semibold text-nowrap shadow-xs">
                                                                                <i class="bi bi-file-earmark-arrow-down-fill me-1"></i><?php echo htmlspecialchars($sub['file_name']); ?>
                                                                                <small class="text-muted ms-1">(<?php echo $sub['formatted_size']; ?>)</small>
                                                                            </a>
                                                                        </td>
                                                                        <td>
                                                                            <span class="text-secondary"><?php echo !empty($sub['student_notes']) ? htmlspecialchars($sub['student_notes']) : '<span class="text-muted fst-italic">None</span>'; ?></span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="text-muted"><?php echo date('M d, Y h:i A', strtotime($sub['submitted_at'])); ?></span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="badge <?php echo $is_sub_graded ? 'bg-success' : 'bg-primary'; ?> bg-opacity-10 text-<?php echo $is_sub_graded ? 'success' : 'primary'; ?> border border-<?php echo $is_sub_graded ? 'success' : 'primary'; ?> border-opacity-25 rounded-pill px-2 py-0.5">
                                                                                <i class="bi <?php echo $is_sub_graded ? 'bi-check-circle-fill' : 'bi-check2'; ?> me-1"></i><?php echo $is_sub_graded ? 'Graded' : 'Submitted'; ?>
                                                                            </span>
                                                                        </td>
                                                                        <td id="watch-sub-score-<?php echo $sub['id']; ?>">
                                                                            <?php if ($is_sub_graded): ?>
                                                                                <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-35 rounded-pill px-2.5 py-1 fw-bold">
                                                                                    <i class="bi bi-award-fill me-1"></i><?php echo (float)$sub['grade']; ?> / 100
                                                                                </span>
                                                                                <?php if (!empty($sub['feedback'])): ?>
                                                                                    <small class="d-block text-muted fs-9 text-truncate mt-0.5" style="max-width: 130px;" title="<?php echo htmlspecialchars($sub['feedback']); ?>">
                                                                                        "<?php echo htmlspecialchars($sub['feedback']); ?>"
                                                                                    </small>
                                                                                <?php endif; ?>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-warning bg-opacity-15 text-dark border border-warning border-opacity-35 rounded-pill px-2 py-0.5">
                                                                                    Pending
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-end text-nowrap">
                                                                            <button type="button" class="btn btn-sm <?php echo $is_sub_graded ? 'btn-outline-primary' : 'btn-primary'; ?> rounded-pill px-2.5 py-0.5 fs-9 fw-semibold shadow-xs"
                                                                                onclick="openWatchLessonGradeModal(<?php echo $sub['id']; ?>, '<?php echo htmlspecialchars(addslashes($sub['name'])); ?>', '<?php echo $is_sub_graded ? (float)$sub['grade'] : ''; ?>', '<?php echo htmlspecialchars(addslashes($sub['feedback'] ?? '')); ?>')">
                                                                                <i class="bi <?php echo $is_sub_graded ? 'bi-pencil-square' : 'bi-award'; ?> me-0.5"></i><?php echo $is_sub_graded ? 'Edit Grade' : 'Grade'; ?>
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                        <?php else: ?>
                                            <!-- Student View: Mandatory Notice + Submission Status + Form -->
                                            <div class="mb-1">
                                                <?php if ($active_submission): ?>
                                                    <!-- Already Submitted Banner -->
                                                    <div class="alert alert-success border-success border-opacity-25 rounded-3 d-flex align-items-start gap-2.5 mb-3 p-3 shadow-sm">
                                                        <i class="bi bi-check-circle-fill text-success fs-4 flex-shrink-0 mt-0.5"></i>
                                                        <div class="w-100">
                                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
                                                                <strong class="text-success fs-7">Assignment Successfully Submitted!</strong>
                                                                <span class="text-muted fs-8"><i class="bi bi-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($active_submission['submitted_at'])); ?></span>
                                                            </div>
                                                            <p class="fs-8 text-secondary mb-2">
                                                                Your assignment submission has been recorded. This satisfies the mandatory assignment requirement for this lesson.
                                                            </p>

                                                            <?php if ($active_submission['grade'] !== null || $active_submission['status'] === 'graded'): ?>
                                                                <!-- Evaluated & Graded Score Card at exact student submission location -->
                                                                <div class="p-3 bg-white rounded-3 border border-success border-opacity-40 mb-2.5 shadow-xs">
                                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                                                        <div class="d-flex align-items-center gap-2">
                                                                            <span class="badge bg-success text-white px-3 py-1.5 rounded-pill fs-7 fw-bold d-inline-flex align-items-center gap-1.5 shadow-xs">
                                                                                <i class="bi bi-award-fill"></i> Score: <?php echo (float)$active_submission['grade']; ?> / 100
                                                                            </span>
                                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                                                                                <i class="bi bi-check2-all me-1"></i>Graded &amp; Evaluated
                                                                            </span>
                                                                        </div>
                                                                        <?php if (!empty($active_submission['graded_at'])): ?>
                                                                            <small class="text-muted fs-9">
                                                                                <i class="bi bi-calendar-check me-1"></i>Graded on <?php echo date('M d, Y h:i A', strtotime($active_submission['graded_at'])); ?>
                                                                            </small>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <?php if (!empty($active_submission['feedback'])): ?>
                                                                        <div class="p-2.5 bg-light rounded-2 border">
                                                                            <div class="fw-bold fs-8 text-primary mb-1 d-flex align-items-center gap-1">
                                                                                <i class="bi bi-chat-quote-fill"></i> Instructor Feedback &amp; Evaluation Remarks:
                                                                            </div>
                                                                            <div class="fs-8 text-dark leading-relaxed">
                                                                                <?php echo nl2br(htmlspecialchars($active_submission['feedback'])); ?>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <!-- Awaiting Grading Indicator -->
                                                                <div class="mb-2.5">
                                                                    <span class="badge bg-warning bg-opacity-15 text-dark border border-warning border-opacity-35 rounded-pill px-2.5 py-1 fs-8 fw-semibold d-inline-flex align-items-center gap-1">
                                                                        <i class="bi bi-hourglass-split text-warning"></i> Awaiting Teacher Grading
                                                                    </span>
                                                                    <small class="text-muted fs-9 d-block mt-1.5"><i class="bi bi-info-circle text-primary me-1"></i>Note: Course certificate can be requested once marks have been assigned for this coursework by your instructor.</small>
                                                                </div>
                                                            <?php endif; ?>

                                                            <div class="d-flex flex-wrap align-items-center justify-content-between bg-white p-2.5 px-3 rounded-2 border gap-2">
                                                                <div class="d-flex align-items-center gap-2 text-truncate">
                                                                    <i class="bi bi-file-earmark-check-fill text-success fs-4"></i>
                                                                    <div class="text-truncate">
                                                                        <span class="fw-semibold text-dark fs-8 d-block text-truncate"><?php echo htmlspecialchars($active_submission['file_name']); ?></span>
                                                                        <small class="text-muted fs-9"><?php echo $active_submission['formatted_size']; ?></small>
                                                                    </div>
                                                                </div>
                                                                <div class="d-flex align-items-center gap-2">
                                                                    <a href="download_assignment.php?type=submission&id=<?php echo urlencode($active_submission['id']); ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 fs-8 fw-semibold">
                                                                        <i class="bi bi-download me-1"></i> View Submitted File
                                                                    </a>
                                                                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 py-1 fs-8 fw-semibold" onclick="document.getElementById('student-resubmit-box').classList.toggle('d-none')">
                                                                        <i class="bi bi-arrow-repeat me-1"></i> <?php echo __('resubmit_assignment', 'Resubmit'); ?>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <?php if (!empty($active_submission['student_notes'])): ?>
                                                                <div class="mt-2 text-muted fs-8">
                                                                    <strong>Your Note:</strong> <?php echo htmlspecialchars($active_submission['student_notes']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- Mandatory Requirement Alert -->
                                                    <div class="alert alert-warning border-warning border-opacity-50 rounded-3 d-flex align-items-center gap-3 mb-3 p-3 shadow-sm bg-warning bg-opacity-10">
                                                        <div class="rounded-circle bg-warning bg-opacity-25 p-2 text-dark d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;">
                                                            <i class="bi bi-exclamation-triangle-fill fs-5 text-warning"></i>
                                                        </div>
                                                        <div class="fs-7 text-dark leading-tight">
                                                            <strong>Mandatory Requirement:</strong> You must complete and submit your coursework file below to achieve <strong>100% course completion</strong> and request your verified certificate.
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Student Upload / Submission Box -->
                                                <div id="student-resubmit-box" class="<?php echo $active_submission ? 'd-none' : ''; ?> p-3.5 bg-white rounded-3 border">
                                                    <h6 class="fw-bold text-dark fs-7 mb-3 d-flex align-items-center gap-2">
                                                        <i class="bi bi-cloud-arrow-up-fill text-primary"></i>
                                                        <span><?php echo $active_submission ? __('resubmit_assignment', 'Resubmit Completed Work') : __('submit_assignment', 'Submit Completed Assignment'); ?></span>
                                                    </h6>
                                                    <form id="studentAssignmentForm" onsubmit="handleStudentAssignmentSubmit(event)">
                                                        <input type="hidden" name="assignment_id" value="<?php echo (int)$active_assignment['id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                                                <?php echo __('upload_work_file_label', 'Attach Completed Assignment File'); ?> <span class="text-danger">*</span>
                                                            </label>
                                                            <input type="file" name="submission_file" class="form-control form-control-sm rounded-2" required
                                                                   accept=".pdf,.doc,.docx,.zip,.rar,.7z,.txt,.png,.jpg,.jpeg">
                                                            <div class="form-text fs-9 text-muted">
                                                                Accepted formats: PDF, DOC, DOCX, ZIP, RAR, TXT, Images (Max 25MB)
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                                                <?php echo __('submission_notes_label', 'Submission Notes / Student Comments (Optional)'); ?>
                                                            </label>
                                                            <textarea name="student_notes" class="form-control form-control-sm rounded-2" rows="2" placeholder="Provide any comments or explanations regarding your submission..."></textarea>
                                                        </div>
                                                        <div class="d-flex justify-content-end">
                                                            <button type="submit" id="studentSubmitBtn" class="btn btn-primary rounded-pill px-4 py-2 fs-7 fw-bold shadow-sm" style="background-color: #0f4c81; border: none;">
                                                                <i class="bi bi-send-fill me-1.5"></i> <?php echo $active_submission ? __('resubmit_assignment', 'Upload & Resubmit') : __('submit_assignment', 'Submit Assignment'); ?>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <!-- No Assignment Attached Yet -->
                                        <div class="p-4 text-center rounded-3 bg-white border border-dashed">
                                            <div class="rounded-circle bg-light p-3 text-muted d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                                                <i class="bi bi-journal-x fs-4 text-secondary"></i>
                                            </div>
                                            <h6 class="fw-bold text-dark fs-7 mb-1"><?php echo __('no_assignment_for_lesson', 'No assignment attached to this topic.'); ?></h6>
                                            <p class="text-muted fs-8 mb-3">
                                                <?php if ($is_teacher || $is_admin): ?>
                                                    Upload an assignment brief with instructions and attachment files for your students to complete and submit.
                                                <?php else: ?>
                                                    Complete the lesson video and chapter quizzes to maintain course progress.
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($is_teacher || $is_admin): ?>
                                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3.5 py-1.5 fw-semibold shadow-sm" onclick="openTeacherAssignmentModal()">
                                                    <i class="bi bi-cloud-arrow-up-fill me-1"></i> <?php echo __('upload_assignment_btn', 'Upload Assignment Attachment'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Supplementary Lesson Resources & Files -->
                        <div class="border rounded-3 p-4 bg-white shadow-sm" id="watch-lesson-resources-card">
                            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-paperclip text-primary fs-5"></i>
                                    <span><?php echo __('lesson_resources', 'Lesson Resources & Attachments'); ?></span>
                                </h6>
                                <span class="badge bg-light text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fs-9 fw-bold">
                                    <?php echo count($active_lesson_resources); ?> <?php echo (count($active_lesson_resources) === 1) ? 'File' : 'Files'; ?>
                                </span>
                            </div>

                            <?php if (empty($active_lesson_resources)): ?>
                                <div class="text-muted fs-8 py-2 text-center fst-italic">
                                    <i class="bi bi-info-circle me-1"></i><?php echo __('no_resources_attached', 'No supplementary files attached to this lesson.'); ?>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($active_lesson_resources as $res): 
                                        $ext = strtolower($res['file_type']);
                                        $icon_cls = 'bi-file-earmark-arrow-down-fill text-primary';
                                        if ($ext === 'pdf') $icon_cls = 'bi-file-earmark-pdf-fill text-danger';
                                        elseif (in_array($ext, ['doc', 'docx'])) $icon_cls = 'bi-file-earmark-word-fill text-primary';
                                        elseif (in_array($ext, ['ppt', 'pptx'])) $icon_cls = 'bi-file-earmark-slides-fill text-warning';
                                        elseif (in_array($ext, ['xls', 'xlsx'])) $icon_cls = 'bi-file-earmark-excel-fill text-success';
                                        elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) $icon_cls = 'bi-file-earmark-image-fill text-info';
                                        elseif (in_array($ext, ['zip', 'rar', '7z'])) $icon_cls = 'bi-file-earmark-zip-fill text-secondary';
                                        $is_viewable = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt']);
                                    ?>
                                        <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded-3 border transition-all hover-shadow-sm">
                                            <div class="d-flex align-items-center gap-3 text-truncate me-2">
                                                <i class="bi <?php echo $icon_cls; ?> fs-2 flex-shrink-0"></i>
                                                <div class="d-flex flex-column text-truncate">
                                                    <span class="fw-semibold text-dark fs-7 text-truncate" title="<?php echo htmlspecialchars($res['file_name']); ?>">
                                                        <?php echo htmlspecialchars($res['file_name']); ?>
                                                    </span>
                                                    <small class="text-muted fs-8">
                                                        <?php echo $res['formatted_size']; ?> &bull; <span class="text-uppercase fw-semibold"><?php echo htmlspecialchars($res['file_type']); ?></span>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                <?php if ($is_viewable): ?>
                                                    <a href="download_resource.php?id=<?php echo urlencode($res['id']); ?>&view=1<?php echo $admin_preview_param; ?>"
                                                        target="_blank"
                                                        class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 fs-8 fw-semibold">
                                                        <i class="bi bi-eye me-1"></i><?php echo __('view_file', 'View'); ?>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="download_resource.php?id=<?php echo urlencode($res['id']); ?>&download=1<?php echo $admin_preview_param; ?>"
                                                    class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fs-8 fw-bold text-white shadow-sm"
                                                    style="background-color: #0f4c81;">
                                                    <i class="bi bi-cloud-arrow-down-fill me-1"></i><?php echo __('download_file', 'Download'); ?>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar Column: Professional Course Syllabus Navigation -->
                <div class="col-lg-4">
                    <div class="syllabus-card">
                        <!-- Syllabus Header -->
                        <div class="syllabus-header">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="bi bi-collection-play-fill text-primary"></i> Course Syllabus
                                </h5>
                                <div class="d-flex align-items-center gap-1.5">
                                    <?php if ($is_teacher || $is_admin): ?>
                                        <a href="classroom.php?course_id=<?php echo urlencode($course_id); ?>"
                                            class="btn btn-sm btn-outline-primary rounded-pill px-2 py-0.5 fs-9 fw-bold">
                                            <i class="bi bi-pencil-square me-1"></i>Edit
                                        </a>
                                    <?php endif; ?>
                                    <span
                                        class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-bold">
                                        <?php echo $total_lessons_count; ?> <?php echo __('lessons', 'Lessons'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center justify-content-between text-muted fs-8 mb-1">
                                <span>Progress: <span id="syllabus-progress-text"><?php echo $completed_count; ?> of <?php echo $total_lessons_count; ?> Completed</span></span>
                                <span class="fw-bold text-success" id="syllabus-progress-percent"><?php echo $completed_percent; ?>%</span>
                            </div>
                            <?php if (!empty($course_assignments_status['total_assignments'])): ?>
                                <div class="d-flex align-items-center justify-content-between text-muted fs-9 mb-2">
                                    <span><i class="bi bi-journal-check me-1 text-primary"></i>Assignments: <?php echo $course_assignments_status['completed_assignments']; ?> of <?php echo $course_assignments_status['total_assignments']; ?> Submitted</span>
                                    <?php if (!$course_assignments_status['all_completed'] && !$is_teacher && !$is_admin): ?>
                                        <span class="text-danger fw-semibold">Mandatory</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="progress" style="height: 6px; border-radius: 10px; background-color: #e2e8f0;">
                                <div class="progress-bar bg-success rounded-pill" id="syllabus-progress-bar" role="progressbar"
                                    style="width: <?php echo $completed_percent; ?>%; transition: width 0.4s ease;"
                                    aria-valuenow="<?php echo $completed_percent; ?>" aria-valuemin="0"
                                    aria-valuemax="100">
                                </div>
                            </div>
                        </div>

                        <!-- Syllabus Playlist Container -->
                        <div class="syllabus-playlist" id="playlist-sidebar-container">
                            <?php foreach ($lessons as $idx => $l): ?>
                                <?php
                                $is_current = ($l['id'] === $active_lesson['id']);
                                $is_completed = in_array($l['id'], $completed_lessons);
                                $is_unlocked = in_array($l['id'], $unlocked_lessons);
                                ?>
                                <?php if ($is_unlocked): ?>
                                    <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($l['id']); ?><?php echo $admin_preview_param; ?>"
                                        id="sidebar-item-<?php echo htmlspecialchars($l['id']); ?>"
                                        class="syllabus-item <?php echo $is_current ? 'active' : ($is_completed ? 'completed' : ''); ?>">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-3">
                                                <span
                                                    class="badge-index <?php echo $is_current ? 'bg-primary text-white shadow-sm' : ($is_completed ? 'bg-success text-white' : 'bg-light text-secondary border'); ?>">
                                                    <?php if ($is_completed && !$is_current): ?>
                                                        <i class="bi bi-check-lg fs-7"></i>
                                                    <?php else: ?>
                                                        <?php echo $idx + 1; ?>
                                                    <?php endif; ?>
                                                </span>
                                                <div>
                                                    <div
                                                        class="fw-bold fs-7 <?php echo $is_current ? 'text-primary' : 'text-dark'; ?>">
                                                        <?php if ($is_current): ?>
                                                            <i class="bi bi-play-circle-fill text-primary me-1"></i>
                                                        <?php else: ?>
                                                            <i class="bi bi-play-circle text-secondary me-1"></i>
                                                        <?php endif; ?>
                                                        <?php echo htmlspecialchars($l['title']); ?>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2 mt-0.5 flex-wrap">
                                                        <small class="text-muted fs-8"><i
                                                                class="bi bi-clock me-1"></i><?php echo htmlspecialchars($l['duration']); ?></small>
                                                        <?php 
                                                        $l_res_count = count($resources_by_lesson[$l['id']] ?? []);
                                                        if ($l_res_count > 0): 
                                                        ?>
                                                            <span class="badge bg-light text-primary border border-primary border-opacity-25 py-0.5 px-1.5 rounded-pill" style="font-size: 0.65rem;" title="<?php echo $l_res_count; ?> Resources attached">
                                                                <i class="bi bi-paperclip"></i> <?php echo $l_res_count; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (isset($assignments_by_lesson[$l['id']])): 
                                                            $has_sub = in_array($assignments_by_lesson[$l['id']]['id'], $user_submitted_assignment_ids);
                                                        ?>
                                                            <span class="badge <?php echo ($is_teacher || $is_admin || $has_sub) ? 'bg-success bg-opacity-10 text-success border border-success border-opacity-25' : 'bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25'; ?> py-0.5 px-1.5 rounded-pill" style="font-size: 0.65rem;" title="<?php echo ($has_sub || $is_teacher || $is_admin) ? 'Assignment Submitted' : 'Mandatory Assignment Required'; ?>">
                                                                <i class="bi <?php echo ($has_sub || $is_teacher || $is_admin) ? 'bi-clipboard-check-fill text-success' : 'bi-clipboard-pulse text-warning'; ?>"></i> Assignment
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($is_current): ?>
                                                            <span class="now-playing-pill">NOW PLAYING</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex align-items-center gap-1.5 ms-2">
                                                <?php if ($is_teacher && !$is_admin): ?>
                                                    <a href="create_quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($l['id']); ?>"
                                                        class="btn btn-sm btn-outline-warning text-dark fw-bold border-warning py-0.5 px-2 fs-9 rounded-pill text-nowrap shadow-sm"
                                                        title="Customize Quiz for this Lesson" onclick="event.stopPropagation();">
                                                        <i class="bi bi-patch-question-fill text-primary me-1"></i>Quiz
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($is_completed): ?>
                                                    <span
                                                        class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fs-9 rounded-pill text-nowrap">
                                                        <i
                                                            class="bi bi-check-circle-fill me-1"></i><?php echo __('completed', 'Done'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <!-- Locked Lesson Item -->
                                    <div id="sidebar-item-<?php echo htmlspecialchars($l['id']); ?>"
                                        class="syllabus-item locked"
                                        title="<?php echo ($active_has_quiz && !$active_quiz_completed) ? 'Locked - Complete current lesson quiz first' : __('locked_previous_required', 'Locked - Complete previous lesson first'); ?>"
                                        <?php echo ($active_has_quiz && !$active_quiz_completed) ? 'onclick="showLessonQuizModal()"' : ''; ?>
                                        style="<?php echo ($active_has_quiz && !$active_quiz_completed) ? 'cursor: pointer;' : ''; ?>"
                                        data-bs-toggle="tooltip">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="badge-index bg-light text-muted border">
                                                    <i class="bi bi-lock-fill fs-8"></i>
                                                </span>
                                                <div>
                                                    <div class="fw-semibold fs-7 text-muted">
                                                        <?php echo htmlspecialchars($l['title']); ?>
                                                    </div>
                                                    <small class="text-muted fs-8"><i
                                                            class="bi bi-clock me-1"></i><?php echo htmlspecialchars($l['duration']); ?></small>
                                                </div>
                                            </div>
                                            <span
                                                class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1 fs-9 rounded-pill text-nowrap ms-2">
                                                <i class="bi bi-lock-fill me-1"></i><?php echo __('locked', 'Locked'); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Direct Quiz Card in Sidebar -->
                        <div class="p-3.5 bg-light border-top text-center">
                            <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                                <i class="bi bi-award-fill fs-5 text-warning"></i>
                                <h6 class="fw-bold mb-0 text-dark">
                                    <?php echo __('ready_for_quiz', 'Ready for the Quiz?'); ?>
                                </h6>
                            </div>
                            <p class="text-muted fs-8 mb-3">
                                <?php echo __('quiz_sidebar_desc', 'Test your knowledge across all syllabus modules.'); ?>
                            </p>
                            <?php 
                                $is_active_lesson_completed = in_array($active_lesson['id'], $watched_lessons) || in_array($active_lesson['id'], $completed_lessons) || $is_admin || $is_teacher;
                            ?>
                            <?php if ($is_active_lesson_completed): ?>
                                <a href="quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($active_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                    id="sidebar-enter-quiz-btn"
                                    class="btn btn-primary w-100 rounded-pill border-0 py-2 fs-8 fw-semibold shadow-sm"
                                    style="background-color: #0f4c81;">
                                    <i class="bi bi-patch-question-fill me-1.5"></i><?php echo __('enter_quiz', 'Enter Quiz'); ?>
                                </a>
                            <?php else: ?>
                                <button type="button" id="sidebar-enter-quiz-btn" class="btn btn-light border text-muted w-100 rounded-pill py-2 fs-8 fw-semibold opacity-75" disabled style="cursor: not-allowed;" title="Watch this lesson video in its entirety (100%) to unlock the quiz">
                                    <i class="bi bi-lock-fill me-1.5 text-secondary"></i><?php echo __('quiz_locked', 'Quiz Locked'); ?> (Watch 100% First)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Lesson Finished Pop-Up with "Start Quiz" Button -->
    <div class="modal fade" id="lessonQuizModal" tabindex="-1" aria-labelledby="lessonQuizModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden" style="background: #ffffff;">
                <!-- Header with vibrant gradient and celebratory icon -->
                <div class="modal-header border-0 text-white p-4 position-relative" style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-white bg-opacity-20 d-flex align-items-center justify-content-center p-3 shadow-sm flex-shrink-0" style="width: 56px; height: 56px;">
                            <i class="bi bi-award-fill fs-2 text-warning"></i>
                        </div>
                        <div>
                            <span class="badge bg-warning text-dark px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase tracking-wider mb-1">
                                <i class="bi bi-check2-circle me-1"></i><?php echo __('lesson_completed', 'Lesson Finished!'); ?>
                            </span>
                            <h4 class="modal-title fw-bold text-white mb-0" id="lessonQuizModalLabel">
                                <?php echo $active_has_quiz ? ($active_quiz_completed ? __('quiz_already_completed', 'Quiz Already Completed!') : __('ready_for_quiz', 'Ready for the Quiz?')) : __('lesson_completed', 'Lesson Finished!'); ?>
                            </h4>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Modal Body -->
                <div class="modal-body p-4 text-center">
                    <?php if ($active_has_quiz): ?>
                        <?php if (!$active_quiz_completed): ?>
                            <div class="mb-3">
                                <div class="d-inline-flex p-3 rounded-circle bg-primary bg-opacity-10 text-primary mb-2">
                                    <i class="bi bi-patch-question-fill fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($active_lesson['title']); ?>
                                </h5>
                                <p class="text-secondary fs-7 mb-0">
                                    <?php echo __('quiz_required_instruction', 'You have finished watching this lesson. To unlock the next lesson, you must complete the quiz associated with this lesson.'); ?>
                                </p>
                            </div>

                            <div class="p-3 bg-light rounded-3 border mb-4 d-flex justify-content-around align-items-center text-center">
                                <div>
                                    <small class="text-muted d-block fs-8 fw-semibold text-uppercase tracking-wider">Assessment</small>
                                    <span class="fw-bold text-primary fs-7">
                                        <i class="bi bi-patch-question me-1"></i><?php echo $active_quiz_count; ?> <?php echo ($active_quiz_count === 1) ? 'Question' : 'Questions'; ?>
                                    </span>
                                </div>
                                <div class="vr" style="height: 32px;"></div>
                                <div>
                                    <small class="text-muted d-block fs-8 fw-semibold text-uppercase tracking-wider">Next Lesson</small>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-1 rounded-pill fs-8 fw-bold">
                                        <i class="bi bi-lock-fill me-1"></i>Locked Until Quiz Done
                                    </span>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <a href="<?php echo htmlspecialchars($quiz_url); ?>" id="start-quiz-modal-btn"
                                    class="btn btn-primary btn-lg rounded-pill fw-bold py-2.5 shadow-sm d-flex align-items-center justify-content-center gap-2"
                                    style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); border: none;">
                                    <i class="bi bi-play-circle-fill fs-5"></i>
                                    <span><?php echo __('start_quiz', 'Start Quiz'); ?></span>
                                </a>
                                <button type="button" class="btn btn-light rounded-pill py-2 text-secondary fs-8 fw-semibold border" data-bs-dismiss="modal">
                                    <?php echo __('review_lesson_notes', 'Review Lesson / Stay on Page'); ?>
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Quiz Already Completed -->
                            <div class="mb-3">
                                <div class="d-inline-flex p-3 rounded-circle bg-success bg-opacity-10 text-success mb-2">
                                    <i class="bi bi-check-circle-fill fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">
                                    <?php echo htmlspecialchars($active_lesson['title']); ?>
                                </h5>
                                <p class="text-secondary fs-7 mb-0">
                                    <?php echo __('quiz_completed_proceed', 'You have finished watching this lesson and your quiz has already been completed.'); ?>
                                </p>
                            </div>

                            <div class="d-grid gap-2">
                                <?php if ($next_lesson && in_array($next_lesson['id'], $unlocked_lessons)): ?>
                                    <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($next_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                        class="btn btn-primary btn-lg rounded-pill fw-bold py-2.5 shadow-sm d-flex align-items-center justify-content-center gap-2"
                                        style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); border: none;">
                                        <span><?php echo __('go_to_next_lesson', 'Go to Next Lesson'); ?></span>
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo htmlspecialchars($quiz_url); ?>" class="btn btn-outline-primary rounded-pill py-2 fw-semibold fs-8">
                                    <i class="bi bi-arrow-repeat me-1"></i><?php echo __('retake_quiz', 'Retake Quiz'); ?>
                                </a>
                                <button type="button" class="btn btn-light rounded-pill py-2 text-secondary fs-8 fw-semibold border" data-bs-dismiss="modal">
                                    <?php echo __('close', 'Close'); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Lesson without quiz -->
                        <div class="mb-3">
                            <div class="d-inline-flex p-3 rounded-circle bg-success bg-opacity-10 text-success mb-2">
                                <i class="bi bi-check-circle-fill fs-1"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1">
                                <?php echo htmlspecialchars($active_lesson['title']); ?>
                            </h5>
                            <p class="text-secondary fs-7 mb-0">
                                <?php echo __('no_quiz_for_lesson', 'You have finished watching this lesson. You can now proceed to the next lesson.'); ?>
                            </p>
                        </div>

                        <div class="d-grid gap-2">
                            <?php if ($next_lesson): ?>
                                <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($next_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                    class="btn btn-primary btn-lg rounded-pill fw-bold py-2.5 shadow-sm d-flex align-items-center justify-content-center gap-2"
                                    style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); border: none;">
                                    <span><?php echo __('go_to_next_lesson', 'Go to Next Lesson'); ?></span>
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-light rounded-pill py-2 text-secondary fs-8 fw-semibold border" data-bs-dismiss="modal">
                                <?php echo __('close', 'Close'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Container for Next Lesson Unlocked Instant Toast -->
    <div id="unlocked-toast-container"></div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Dynamic JS Seeking Control & Progress Script -->
    <script>
        const ACTIVE_LESSON_ID = <?php echo json_encode($active_lesson['id']); ?>;
        const ACTIVE_LESSON_TITLE = <?php echo json_encode($active_lesson['title']); ?>;
        const COURSE_ID = <?php echo json_encode($course_id); ?>;
        const USER_ROLE = <?php echo json_encode($_SESSION['user_role'] ?? ($student['role'] ?? 'student')); ?>;
        const IS_REVIEW_MODE = (USER_ROLE === 'admin' || USER_ROLE === 'super_admin' || USER_ROLE === 'teacher');
        const YOUTUBE_VIDEO_ID = <?php echo json_encode($yt_id); ?>;
        const SAVED_PROGRESS_SECONDS = <?php echo json_encode($saved_position); ?>;
        const IS_LESSON_COMPLETED = <?php echo json_encode($is_active_completed); ?>;
        const HAS_LESSON_QUIZ = <?php echo json_encode($active_has_quiz); ?>;
        const IS_QUIZ_COMPLETED = <?php echo json_encode($active_quiz_completed); ?>;
        const QUIZ_QUESTION_COUNT = <?php echo json_encode($active_quiz_count); ?>;
        const QUIZ_URL = <?php echo json_encode($quiz_url); ?>;
        let hasShownQuizModal = false;

        let player = null;
        let ytPlayer = null; // Alias for consistency
        let maxWatchedTime = parseFloat(SAVED_PROGRESS_SECONDS) || 0;
        let timeCheckInterval = null;
        let lastProgressSaveTime = 0;
        let hasTriggeredUnlock = false;
        let seekWarningTimer = null;

        document.addEventListener('DOMContentLoaded', () => {
            // Enable Bootstrap tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            if (YOUTUBE_VIDEO_ID) {
                loadYouTubeAPI();
            } else {
                initHtml5Player();
            }
        });

        function loadYouTubeAPI() {
            if (window.YT && window.YT.Player) {
                initYouTubePlayer();
                return;
            }
            window.onYouTubeIframeAPIReady = function () {
                initYouTubePlayer();
            };
            if (!document.getElementById('youtube-iframe-api-script')) {
                const tag = document.createElement('script');
                tag.id = 'youtube-iframe-api-script';
                tag.src = 'https://www.youtube.com/iframe_api';
                document.head.appendChild(tag);
            }
        }

        function initYouTubePlayer() {
            stopTimeTracking();
            player = new YT.Player('yt-player-target', {
                width: '100%',
                height: '100%',
                videoId: YOUTUBE_VIDEO_ID,
                playerVars: {
                    'playsinline': 1,
                    'rel': 0,
                    'modestbranding': 1,
                    'enablejsapi': 1,
                    'autoplay': 0
                },
                events: {
                    'onReady': onPlayerReady,
                    'onStateChange': onPlayerStateChange
                }
            });
            ytPlayer = player;
        }

        function onPlayerReady(event) {
            // Player is ready; maxWatchedTime is initialized from SAVED_PROGRESS_SECONDS
            if (maxWatchedTime > 0 && !IS_REVIEW_MODE) {
                // If student has saved position, maxWatchedTime retains that point
            }
        }

        function showLessonQuizModal() {
            const modalEl = document.getElementById('lessonQuizModal');
            if (modalEl && window.bootstrap && bootstrap.Modal) {
                let modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (!modalInstance) {
                    modalInstance = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
                }
                modalInstance.show();
            }
        }

        function onLessonVideoFinished() {
            markLessonAsCompleted();
            if (!hasShownQuizModal) {
                hasShownQuizModal = true;
                setTimeout(() => {
                    showLessonQuizModal();
                }, 400);
            }
        }

        function onPlayerStateChange(event) {
            if (event.data === YT.PlayerState.PLAYING) {
                startTimeTracking();
            } else {
                stopTimeTracking();
            }

            // Video Finished -> Trigger AJAX Completion and Quiz Pop-up
            if (event.data === YT.PlayerState.ENDED) {
                onLessonVideoFinished();
            } else if (event.data === YT.PlayerState.PAUSED) {
                if (player && typeof player.getCurrentTime === 'function') {
                    const current = player.getCurrentTime();
                    const dur = (typeof player.getDuration === 'function') ? player.getDuration() : 0;
                    sendWatchProgress(ACTIVE_LESSON_ID, current, dur);
                }
            }
        }

        function startTimeTracking() {
            stopTimeTracking();
            timeCheckInterval = setInterval(() => {
                if (!player || typeof player.getCurrentTime !== 'function') return;

                // Player State Guard: pause/ignore checks while BUFFERING, UNSTARTED, or CUED
                if (typeof player.getPlayerState === 'function') {
                    const state = player.getPlayerState();
                    if (state === YT.PlayerState.BUFFERING || state === YT.PlayerState.UNSTARTED || state === YT.PlayerState.CUED || state !== YT.PlayerState.PLAYING) {
                        return;
                    }
                }

                const currentTime = player.getCurrentTime();
                const duration = (typeof player.getDuration === 'function') ? player.getDuration() : 0;

                if (IS_REVIEW_MODE) {
                    if (currentTime > maxWatchedTime) {
                        maxWatchedTime = currentTime;
                    }
                    return;
                }

                // Normal playback progression with 2.5s tolerance
                if (currentTime <= maxWatchedTime + 2.5) {
                    if (currentTime > maxWatchedTime) {
                        maxWatchedTime = currentTime;
                    }
                } else {
                    // Illegal forward scrub detected -> clamp back to max watched point (DO NOT reset to 0)
                    player.seekTo(maxWatchedTime, true);
                    showSeekWarning();
                }

                // Throttled periodic progress sync to server every 5 seconds or near end (>= 90%)
                if (duration > 0 && (currentTime - lastProgressSaveTime >= 5 || currentTime >= duration * 0.9)) {
                    lastProgressSaveTime = currentTime;
                    sendWatchProgress(ACTIVE_LESSON_ID, currentTime, duration);
                }

                // End of video reached via playback
                if (duration > 0 && currentTime >= duration - 0.5 && !hasShownQuizModal) {
                    onLessonVideoFinished();
                }
            }, 500);
        }

        function stopTimeTracking() {
            if (timeCheckInterval) {
                clearInterval(timeCheckInterval);
                timeCheckInterval = null;
            }
        }

        function markLessonAsCompleted() {
            let duration = 0;
            if (player && typeof player.getDuration === 'function') {
                duration = player.getDuration() || 0;
            } else {
                const videoEl = document.getElementById('lesson-video-player');
                if (videoEl && videoEl.duration) {
                    duration = videoEl.duration;
                }
            }
            if (duration > 0) {
                maxWatchedTime = duration;
            }
            sendWatchProgress(ACTIVE_LESSON_ID, duration || 1, duration || 1);
        }

        function initHtml5Player() {
            const videoElement = document.getElementById('lesson-video-player');
            if (!videoElement) return;

            videoElement.controls = true;

            if (IS_REVIEW_MODE) {
                // Review mode: full seeking unlocked
            } else {
                let isSeekingLock = false;
                let lastSaveTime = 0;

                // Restore saved position if available
                videoElement.addEventListener('loadedmetadata', () => {
                    if (maxWatchedTime > 0 && maxWatchedTime < videoElement.duration) {
                        videoElement.currentTime = maxWatchedTime;
                    }
                });

                videoElement.addEventListener('timeupdate', () => {
                    if (!videoElement.duration) return;

                    if (!isSeekingLock) {
                        if (videoElement.currentTime <= maxWatchedTime + 2.5) {
                            if (videoElement.currentTime > maxWatchedTime) {
                                maxWatchedTime = videoElement.currentTime;
                            }
                        } else {
                            isSeekingLock = true;
                            videoElement.currentTime = maxWatchedTime;
                            showSeekWarning();
                            setTimeout(() => { isSeekingLock = false; }, 300);
                        }
                    }

                    const currentTime = videoElement.currentTime;
                    const duration = videoElement.duration;
                    if (duration > 0 && (currentTime - lastSaveTime > 5 || currentTime >= duration * 0.9)) {
                        lastSaveTime = currentTime;
                        sendWatchProgress(ACTIVE_LESSON_ID, currentTime, duration);
                    }

                    if (duration > 0 && currentTime >= duration - 0.5 && !hasShownQuizModal) {
                        onLessonVideoFinished();
                    }
                });

                videoElement.addEventListener('seeking', () => {
                    if (videoElement.currentTime > maxWatchedTime + 2.5) {
                        isSeekingLock = true;
                        videoElement.currentTime = maxWatchedTime;
                        showSeekWarning();
                        setTimeout(() => { isSeekingLock = false; }, 300);
                    }
                });

                videoElement.addEventListener('ended', () => {
                    maxWatchedTime = videoElement.duration || maxWatchedTime;
                    onLessonVideoFinished();
                });
            }
        }

        function showSeekWarning() {
            const msg = (typeof window.i18n__ === 'function')
                ? window.i18n__('forward_seeking_restricted', '⚠️ Fast-forwarding is restricted. Please complete the video sequentially.')
                : '⚠️ Fast-forwarding is restricted. Please complete the video sequentially.';

            let toast = document.getElementById('seek-warning-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'seek-warning-toast';
                toast.className = 'alert alert-warning border-warning shadow-sm position-fixed top-0 start-50 translate-middle-x mt-3 z-3';
                toast.style.zIndex = '2000';
                toast.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i><span>${msg}</span>`;
                document.body.appendChild(toast);
            } else {
                const textSpan = toast.querySelector('span');
                if (textSpan) textSpan.textContent = msg;
            }
            toast.style.display = 'block';
            if (seekWarningTimer) {
                clearTimeout(seekWarningTimer);
            }
            seekWarningTimer = setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }

        async function sendWatchProgress(lessonId, currentTime, duration) {
            if (IS_REVIEW_MODE) return;

            try {
                const response = await fetch('api/save_progress.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        lesson_id: lessonId,
                        current_time: currentTime,
                        duration: duration
                    })
                });

                const data = await response.json();

                if (data.success) {
                    updateCourseProgressUI(data);
                }
            } catch (err) {
                console.error('Progress sync error:', err);
            }
        }

        function updateCourseProgressUI(data) {
            if (!data) return;

            // 1. Update Course Progress Bar and Text in Real-Time
            if (data.course_progress_percent !== undefined) {
                const pct = Math.min(100, Math.max(0, parseInt(data.course_progress_percent, 10)));
                const bar = document.getElementById('syllabus-progress-bar');
                const pctText = document.getElementById('syllabus-progress-percent');
                const progText = document.getElementById('syllabus-progress-text');

                if (bar) {
                    bar.style.width = pct + '%';
                    bar.setAttribute('aria-valuenow', pct);
                }
                const overallBar = document.getElementById('overall-progress-bar');
                if (overallBar) {
                    overallBar.style.width = pct + '%';
                    overallBar.setAttribute('aria-valuenow', pct);
                }
                if (pctText) {
                    pctText.textContent = pct + '%';
                }
                const overallText = document.getElementById('overall-progress-text');
                if (overallText) {
                    overallText.textContent = pct + '%';
                }
                if (progText && data.course_completed_lessons !== undefined && data.course_total_lessons !== undefined) {
                    let text = `${data.course_completed_lessons} of ${data.course_total_lessons} Completed`;
                    if (data.total_assignments > 0 && !data.assignments_completed) {
                        text += ` (Assignments required for 100%)`;
                    }
                    progText.textContent = text;
                }

            }

            // 2. Update Completed Lesson Sidebar Item Badge
            if (data.completed && data.lesson_id) {
                const currentSidebarItem = document.getElementById(`sidebar-item-${data.lesson_id}`);
                if (currentSidebarItem) {
                    currentSidebarItem.classList.add('completed');
                    
                    let compBadge = currentSidebarItem.querySelector('.badge.bg-success');
                    if (!compBadge) {
                        const rightContainer = currentSidebarItem.querySelector('.d-flex.align-items-center.justify-content-between > .d-flex:last-child');
                        if (rightContainer) {
                            const doneSpan = document.createElement('span');
                            doneSpan.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fs-9 rounded-pill text-nowrap';
                            doneSpan.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Done';
                            rightContainer.appendChild(doneSpan);
                        }
                    }
                }
            }

            // 2.1 Dynamically Unlock Sidebar Enter Quiz Button upon 100% video completion
            if (data.completed && data.lesson_id === ACTIVE_LESSON_ID) {
                const sidebarQuizBtn = document.getElementById('sidebar-enter-quiz-btn');
                if (sidebarQuizBtn && sidebarQuizBtn.tagName.toLowerCase() === 'button') {
                    const quizUrl = `quiz.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(ACTIVE_LESSON_ID)}${ADMIN_PREVIEW_PARAM || ''}`;
                    const newAnchor = document.createElement('a');
                    newAnchor.href = quizUrl;
                    newAnchor.id = 'sidebar-enter-quiz-btn';
                    newAnchor.className = 'btn btn-primary w-100 rounded-pill border-0 py-2 fs-8 fw-semibold shadow-sm';
                    newAnchor.style.backgroundColor = '#0f4c81';
                    newAnchor.innerHTML = '<i class="bi bi-patch-question-fill me-1.5"></i>Enter Quiz';
                    sidebarQuizBtn.parentNode.replaceChild(newAnchor, sidebarQuizBtn);
                }
            }

            // 3. Unlock Next Lesson ONLY IF server returned next_unlocked_lesson (meaning quiz is completed or not required)
            if (data.completed && data.next_unlocked_lesson && !hasTriggeredUnlock) {
                hasTriggeredUnlock = true;
                handleInstantNextLessonUnlock(data.next_unlocked_lesson);
            }

            // If lesson is completed and video playback finished, ensure pop-up modal is displayed
            if (data.completed && !hasShownQuizModal) {
                const videoEl = document.getElementById('lesson-video-player');
                const currTime = (player && typeof player.getCurrentTime === 'function') ? player.getCurrentTime() : (videoEl ? videoEl.currentTime : 0);
                const dur = (player && typeof player.getDuration === 'function') ? player.getDuration() : (videoEl ? videoEl.duration : 0);
                if (dur > 0 && currTime >= dur - 1) {
                    onLessonVideoFinished();
                }
            }

            // Broadcast custom event for other widgets/listeners
            window.dispatchEvent(new CustomEvent('lms:course_progress_updated', { detail: data }));
        }

        function handleInstantNextLessonUnlock(nextLesson) {
            const targetUrl = `watch_lesson.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(nextLesson.id)}`;

            // 1. Update Right Sidebar Playlist item
            const sidebarItem = document.getElementById(`sidebar-item-${nextLesson.id}`);
            if (sidebarItem && sidebarItem.classList.contains('locked')) {
                if (window.bootstrap && bootstrap.Tooltip) {
                    const tip = bootstrap.Tooltip.getInstance(sidebarItem);
                    if (tip) tip.dispose();
                }
                sidebarItem.classList.remove('locked');
                sidebarItem.removeAttribute('data-bs-toggle');
                sidebarItem.removeAttribute('title');

                const newAnchor = document.createElement('a');
                newAnchor.href = targetUrl;
                newAnchor.id = `sidebar-item-${nextLesson.id}`;
                newAnchor.className = 'syllabus-item';
                newAnchor.innerHTML = sidebarItem.innerHTML;

                const lockIcon = newAnchor.querySelector('.bi-lock-fill');
                if (lockIcon) {
                    lockIcon.className = 'bi bi-play-circle text-secondary me-1';
                }

                const badge = newAnchor.querySelector('.badge.bg-secondary');
                if (badge) {
                    badge.className = 'badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fs-9 rounded-pill';
                    badge.innerHTML = `<i class="bi bi-unlock-fill me-1"></i>Unlocked`;
                }

                sidebarItem.parentNode.replaceChild(newAnchor, sidebarItem);
            }

            // 2. Update Top Next Button
            const nextBtn = document.getElementById('lesson-nav-next-btn');
            if (nextBtn && nextBtn.tagName.toLowerCase() === 'button') {
                const nextAnchor = document.createElement('a');
                nextAnchor.href = targetUrl;
                nextAnchor.id = 'lesson-nav-next-btn';
                nextAnchor.className = 'btn btn-primary btn-sm rounded-pill px-3 fw-semibold';
                nextAnchor.style.backgroundColor = '#0f4c81';
                nextAnchor.style.border = 'none';
                nextAnchor.innerHTML = 'Next <i class="bi bi-chevron-right ms-1"></i>';
                nextBtn.parentNode.replaceChild(nextAnchor, nextBtn);
            }

            // 3. Show Bottom-Right Notification Toast
            const toastContainer = document.getElementById('unlocked-toast-container');
            const toastTitle = window.i18n__ ? window.i18n__('next_lesson_unlocked', 'Next Lesson Unlocked!') : 'Next Lesson Unlocked!';
            const btnText = window.i18n__ ? window.i18n__('go_to_next_lesson', 'Go to Next Lesson') : 'Go to Next Lesson';

            toastContainer.innerHTML = `
                <div class="unlocked-toast">
                    <div class="rounded-circle bg-success text-white p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="bi bi-unlock-fill fs-5"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold text-dark mb-0">${toastTitle}</h6>
                        <small class="text-muted fs-8">${escapeHtml(nextLesson.title)}</small>
                    </div>
                    <a href="${targetUrl}" class="btn btn-success border-0 px-3 py-1.5 fw-semibold fs-8 text-nowrap ms-2 rounded-pill">
                        ${btnText} <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            `;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // --- Topic Assignment & Submission Handlers ---
        function openTeacherAssignmentModal() {
            const modalEl = document.getElementById('teacherAssignmentModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }

        async function handleTeacherAssignmentSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = document.getElementById('teacherSubmitBtn');
            const origHtml = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving...';

            try {
                const formData = new FormData(form);
                const resp = await fetch('api/upload_assignment.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await resp.json();

                if (res.success) {
                    if (window.showToastNotification) {
                        showToastNotification(res.message || 'Assignment saved successfully!', 'success');
                    } else {
                        alert(res.message || 'Assignment saved successfully!');
                    }
                    setTimeout(() => location.reload(), 700);
                } else {
                    alert(res.message || 'Error saving assignment.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origHtml;
                }
            } catch (err) {
                console.error(err);
                alert('A network or server error occurred. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = origHtml;
            }
        }

        async function deleteTeacherAssignment(assignmentId) {
            if (!confirm('Are you sure you want to delete this assignment attachment? Any student submissions will also be removed.')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('course_id', COURSE_ID);
                formData.append('lesson_id', ACTIVE_LESSON_ID);

                const resp = await fetch('api/upload_assignment.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await resp.json();

                if (res.success) {
                    if (window.showToastNotification) {
                        showToastNotification(res.message || 'Assignment deleted.', 'success');
                    }
                    setTimeout(() => location.reload(), 600);
                } else {
                    alert(res.message || 'Failed to delete assignment.');
                }
            } catch (err) {
                console.error(err);
                alert('Error connecting to server.');
            }
        }

        async function handleStudentAssignmentSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = document.getElementById('studentSubmitBtn');
            const origHtml = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Submitting...';

            try {
                const formData = new FormData(form);
                const resp = await fetch('api/submit_assignment.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await resp.json();

                if (res.success) {
                    if (window.showToastNotification) {
                        showToastNotification(res.message || 'Assignment submitted successfully!', 'success');
                    } else {
                        alert(res.message || 'Assignment submitted successfully!');
                    }
                    setTimeout(() => location.reload(), 800);
                } else {
                    alert(res.message || 'Error submitting assignment.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origHtml;
                }
            } catch (err) {
                console.error(err);
                alert('A network or server error occurred while uploading your submission.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = origHtml;
            }
        }

        function openWatchLessonGradeModal(subId, studentName, currentGrade, currentFeedback) {
            document.getElementById('watch-grade-sub-id').value = subId;
            document.getElementById('watch-grade-student-name').textContent = studentName;
            document.getElementById('watch-grade-score-input').value = currentGrade || '';
            document.getElementById('watch-grade-feedback-input').value = currentFeedback || '';
            document.getElementById('watch-grade-notify-student').checked = true;

            const modalEl = document.getElementById('watchLessonGradeModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        }

        async function submitWatchLessonGrade(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('watch-grade-submit-btn');
            const subId = document.getElementById('watch-grade-sub-id').value;
            const grade = document.getElementById('watch-grade-score-input').value;
            const feedback = document.getElementById('watch-grade-feedback-input').value;
            const sendNotif = document.getElementById('watch-grade-notify-student').checked ? 1 : 0;

            if (!grade || isNaN(grade) || parseFloat(grade) < 0 || parseFloat(grade) > 100) {
                alert('Please enter a valid grade between 0 and 100.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Saving...';

            try {
                const resp = await fetch('api/grade_assignment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        submission_id: parseInt(subId),
                        grade: parseFloat(grade),
                        feedback: feedback,
                        send_notification: sendNotif
                    })
                });
                const res = await resp.json();

                if (res.success) {
                    const scoreCell = document.getElementById(`watch-sub-score-${subId}`);
                    if (scoreCell) {
                        scoreCell.innerHTML = `
                            <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-35 rounded-pill px-2.5 py-1 fw-bold">
                                <i class="bi bi-award-fill me-1"></i>${parseFloat(res.grade)} / 100
                            </span>
                        `;
                    }
                    const row = document.getElementById(`watch-sub-row-${subId}`);
                    if (row) {
                        const btn = row.querySelector('.btn-quick-grade');
                        if (btn) {
                            btn.classList.remove('btn-primary');
                            btn.classList.add('btn-outline-primary');
                            btn.innerHTML = '<i class="bi bi-pencil-square me-0.5"></i>Edit Grade';
                        }
                    }
                    const modalEl = document.getElementById('watchLessonGradeModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                    if (window.showToastNotification) {
                        showToastNotification('Grade saved & student notified!', 'success');
                    } else {
                        alert('Grade saved successfully and student notified!');
                    }
                } else {
                    alert(res.message || 'Failed to grade submission.');
                }
            } catch (err) {
                console.error(err);
                alert('Server error saving grade.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save Grade & Notify';
            }
        }
    </script>

    <?php if ($is_teacher || $is_admin): ?>
    <!-- Teacher Assignment Upload / Edit Modal -->
    <div class="modal fade" id="teacherAssignmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header border-0 bg-light p-4 pb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="bi bi-journal-plus fs-4"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0 fs-6">
                                <?php echo $active_assignment ? __('edit_assignment_btn', 'Edit / Replace Assignment') : __('upload_assignment_btn', 'Upload Assignment Attachment'); ?>
                            </h5>
                            <small class="text-muted fs-8"><?php echo htmlspecialchars($active_lesson['title']); ?></small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="teacherAssignmentForm" onsubmit="handleTeacherAssignmentSubmit(event)" enctype="multipart/form-data">
                    <div class="modal-body p-4 pt-3">
                        <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id); ?>">
                        <input type="hidden" name="lesson_id" value="<?php echo htmlspecialchars($active_lesson['id']); ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                Assignment Title <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="title" class="form-control rounded-2 fs-7" required 
                                   placeholder="e.g., Chapter Practical Task: Binary Search Trees"
                                   value="<?php echo htmlspecialchars($active_assignment['title'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                Instructions / Assignment Brief
                            </label>
                            <textarea name="instructions" class="form-control rounded-2 fs-8" rows="3" 
                                      placeholder="Provide instructions or submission guidelines for students..."><?php echo htmlspecialchars($active_assignment['instructions'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                Deadline / Due Date (Optional)
                            </label>
                            <input type="datetime-local" name="due_date" class="form-control rounded-2 fs-8"
                                   value="<?php echo !empty($active_assignment['due_date']) ? date('Y-m-d\TH:i', strtotime($active_assignment['due_date'])) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold fs-8 text-dark mb-1">
                                <?php echo __('assignment_attachment', 'Assignment Brief & Attachment'); ?>
                                <?php if (!$active_assignment): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <input type="file" name="attachment" class="form-control rounded-2 fs-8" 
                                   <?php echo (!$active_assignment) ? 'required' : ''; ?>
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.rar,.7z,.png,.jpg,.jpeg">
                            <div class="form-text fs-9 text-muted">
                                Supported formats: PDF, Word, PowerPoint, Excel, ZIP, RAR, TXT, Images (Max 50MB)
                            </div>
                            <?php if ($active_assignment && !empty($active_assignment['attachment_name'])): ?>
                                <div class="mt-2 text-muted fs-8">
                                    <i class="bi bi-paperclip me-1"></i>Current attachment: <strong><?php echo htmlspecialchars($active_assignment['attachment_name']); ?></strong> (Leave blank to keep existing file)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light p-3 px-4 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="teacherSubmitBtn" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #0f4c81;">
                            <i class="bi bi-cloud-arrow-up-fill me-1"></i> Save Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Grade Modal for Instructor in Watch Lesson -->
    <div class="modal fade text-dark" id="watchLessonGradeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header border-0 bg-light p-4 pb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2.5 text-primary d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="bi bi-award-fill fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0 fs-6">
                                Grade Student Submission
                            </h5>
                            <small class="text-muted fs-8" id="watch-grade-student-name">Student</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="watchLessonGradeForm" onsubmit="submitWatchLessonGrade(event)">
                    <input type="hidden" id="watch-grade-sub-id" name="submission_id" value="">
                    <div class="modal-body p-4 pt-3">
                        <div class="mb-3">
                            <label for="watch-grade-score-input" class="form-label fw-semibold text-dark fs-8 mb-1">
                                Score / Grade (0 - 100) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.5" min="0" max="100" class="form-control form-control-lg fw-bold text-primary rounded-start-2" 
                                       id="watch-grade-score-input" name="grade" placeholder="e.g. 90" required>
                                <span class="input-group-text fw-semibold bg-light text-muted">/ 100</span>
                            </div>
                            <div class="d-flex gap-1.5 mt-1.5 flex-wrap">
                                <small class="text-muted fs-9 align-self-center me-1">Quick Set:</small>
                                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0.5 fs-9" onclick="document.getElementById('watch-grade-score-input').value = 100">100</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0.5 fs-9" onclick="document.getElementById('watch-grade-score-input').value = 90">90</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0.5 fs-9" onclick="document.getElementById('watch-grade-score-input').value = 80">80</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2 py-0.5 fs-9" onclick="document.getElementById('watch-grade-score-input').value = 75">75</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="watch-grade-feedback-input" class="form-label fw-semibold text-dark fs-8 mb-1">
                                Feedback & Evaluation Remarks (Optional)
                            </label>
                            <textarea class="form-control form-control-sm rounded-2" id="watch-grade-feedback-input" name="feedback" rows="3" 
                                      placeholder="Provide constructive feedback or commendations..."></textarea>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="watch-grade-notify-student" checked>
                            <label class="form-check-label fs-8 text-secondary fw-semibold cursor-pointer" for="watch-grade-notify-student">
                                <i class="bi bi-bell-fill text-warning me-1"></i>Send instant notification to student with score & feedback
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light p-3 px-4 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="watch-grade-submit-btn" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #0f4c81;">
                            <i class="bi bi-check-circle-fill me-1"></i> Save Grade & Notify
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modern Notification System JS Client -->
    <script src="assets/js/notifications.js"></script>
</body>

</html>