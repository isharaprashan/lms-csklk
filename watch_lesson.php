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
$course_id = $_GET['course_id'] ?? '';
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

    // Fetch lessons with finalized quizzes for the student
    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND status = 'finalized'");
    $stmt->execute([$user_id, $course_id]);
    $finalized_quiz_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch all lessons that actually have quizzes configured
    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $lessons_with_quizzes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Determine unlocked lessons (Sequential dependency: 100% video review AND finalized quiz)
    $unlocked_lessons = [];
    foreach ($lessons as $idx => $l) {
        if ($is_teacher || $is_admin || $idx === 0) {
            $unlocked_lessons[] = $l['id'];
        } else {
            $prev_l = $lessons[$idx - 1];
            $prev_watched = in_array($prev_l['id'], $watched_lessons);
            $prev_has_quiz = in_array($prev_l['id'], $lessons_with_quizzes);
            $prev_quiz_done = !$prev_has_quiz || in_array($prev_l['id'], $finalized_quiz_lessons);

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

    $admin_preview_param = ($is_admin || isset($_GET['admin_preview'])) ? '&admin_preview=1' : '';

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

        .video-container video {
            display: block;
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
                            <div class="progress-bar" role="progressbar"
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
                <div class="alert alert-warning alert-dismissible fade show border-warning shadow-sm rounded-3 mb-4 d-flex align-items-center gap-3"
                    role="alert">
                    <i class="bi bi-lock-fill fs-4 text-warning"></i>
                    <div>
                        <h6 class="fw-bold mb-0">
                            <?php echo __('locked_lesson_warning', 'Access Locked: Please complete the previous lesson first.'); ?>
                        </h6>
                        <small class="text-secondary">Lessons must be completed in sequential order to unlock subsequent
                            syllabus modules.</small>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
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
                                        class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold"
                                        style="background-color: #0f4c81; border: none;">
                                        Next <i class="bi bi-chevron-right ms-1"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm rounded-pill px-3 fw-semibold" disabled
                                        title="Complete current lesson to unlock next">
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
                                <video id="lesson-video-player" class="w-100 h-100 rounded-4" controls
                                    controlsList="nodownload" oncontextmenu="return false;" disablePictureInPicture
                                    poster="<?php echo htmlspecialchars($current_course['thumbnail'] ?? ''); ?>">
                                    <source
                                        src="<?php echo htmlspecialchars(!empty($active_video_url) ? $active_video_url : 'uploads/class.mp4'); ?>"
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
                                    <h5 class="fw-bold mb-1 text-white">
                                        <?php echo __('quiz_title', 'Course Knowledge Quiz'); ?>
                                    </h5>
                                    <p class="mb-0 text-white-50 fs-7">
                                        <?php echo __('quiz_subtitle', 'Test your understanding of this course chapter with single-question timed challenges.'); ?>
                                    </p>
                                </div>
                            </div>
                            <a href="quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($active_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                class="btn btn-light btn-lg px-4 py-2 fw-bold text-primary border-0 shadow-sm text-nowrap rounded-pill">
                                <i class="bi bi-play-circle-fill me-2"></i><?php echo __('enter_quiz', 'Enter Quiz'); ?>
                            </a>
                        </div>

                        <!-- Lesson Content & Topic Notes -->
                        <div class="border rounded-3 p-4 bg-light mb-3">
                            <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                                <i class="bi bi-file-earmark-text-fill text-primary fs-5"></i>
                                <span><?php echo __('topic_resources_notes', 'Topic Resources & Notes'); ?></span>
                            </h6>
                            <p class="text-secondary fs-7 mb-0 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($active_lesson['content'])); ?>
                            </p>
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
                            <div class="d-flex align-items-center justify-content-between text-muted fs-8 mb-2">
                                <span>Progress: <?php echo $completed_count; ?> of <?php echo $total_lessons_count; ?>
                                    Completed</span>
                                <span class="fw-bold text-success"><?php echo $completed_percent; ?>%</span>
                            </div>
                            <div class="progress" style="height: 6px; border-radius: 10px; background-color: #e2e8f0;">
                                <div class="progress-bar bg-success rounded-pill" role="progressbar"
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
                                        title="<?php echo __('locked_previous_required', 'Locked - Complete previous lesson first'); ?>"
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
                            <a href="quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($active_lesson['id']); ?><?php echo $admin_preview_param; ?>"
                                class="btn btn-primary w-100 rounded-pill border-0 py-2 fs-8 fw-semibold shadow-sm"
                                style="background-color: #0f4c81;">
                                <i
                                    class="bi bi-patch-question-fill me-1.5"></i><?php echo __('enter_quiz', 'Enter Quiz'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Container for Next Lesson Unlocked Instant Toast -->
    <div id="unlocked-toast-container"></div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Dynamic JS Seeking Control & Progress Script -->
    <script>
        const ACTIVE_LESSON_ID = <?php echo json_encode($active_lesson['id']); ?>;
        const COURSE_ID = <?php echo json_encode($course_id); ?>;
        const USER_ROLE = <?php echo json_encode($_SESSION['user_role'] ?? ($student['role'] ?? 'student')); ?>;
        const IS_REVIEW_MODE = (USER_ROLE === 'admin' || USER_ROLE === 'super_admin' || USER_ROLE === 'teacher');
        const YOUTUBE_VIDEO_ID = <?php echo json_encode($yt_id); ?>;
        let hasTriggeredUnlock = false;
        let ytPlayer = null;
        let progressInterval = null;

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
            const tag = document.createElement('script');
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        }

        function initYouTubePlayer() {
            let maxWatchedTime = 0;
            ytPlayer = new YT.Player('yt-player-target', {
                videoId: YOUTUBE_VIDEO_ID,
                playerVars: { rel: 0, modestbranding: 1, enablejsapi: 1, autoplay: 0 },
                events: {
                    onStateChange: function (event) {
                        if (event.data === YT.PlayerState.PLAYING) {
                            if (!progressInterval) {
                                progressInterval = setInterval(() => {
                                    if (!ytPlayer || typeof ytPlayer.getCurrentTime !== 'function') return;
                                    const current = ytPlayer.getCurrentTime();
                                    const dur = ytPlayer.getDuration();

                                    if (!IS_REVIEW_MODE) {
                                        if (current > maxWatchedTime + 1.5) {
                                            ytPlayer.seekTo(maxWatchedTime, true);
                                            showSeekWarning();
                                        } else if (current > maxWatchedTime) {
                                            maxWatchedTime = current;
                                        }
                                        sendWatchProgress(ACTIVE_LESSON_ID, current, dur);
                                    }
                                }, 5000);
                            }
                        } else if (event.data === YT.PlayerState.PAUSED || event.data === YT.PlayerState.ENDED) {
                            if (progressInterval) {
                                clearInterval(progressInterval);
                                progressInterval = null;
                            }
                            if (ytPlayer && typeof ytPlayer.getCurrentTime === 'function') {
                                const current = ytPlayer.getCurrentTime();
                                const dur = ytPlayer.getDuration();
                                if (event.data === YT.PlayerState.ENDED) {
                                    maxWatchedTime = dur;
                                }
                                sendWatchProgress(ACTIVE_LESSON_ID, current, dur);
                            }
                        }
                    }
                }
            });
        }

        function initHtml5Player() {
            const videoElement = document.getElementById('lesson-video-player');
            if (!videoElement) return;

            if (IS_REVIEW_MODE) {
                videoElement.controls = true;
            } else {
                let maxWatchedTime = 0;
                let isSeekingLock = false;
                let lastSaveTime = 0;

                videoElement.addEventListener('timeupdate', () => {
                    if (!videoElement.duration) return;

                    if (!isSeekingLock) {
                        if (videoElement.currentTime <= maxWatchedTime + 1.5) {
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
                });

                videoElement.addEventListener('seeking', () => {
                    if (videoElement.currentTime > maxWatchedTime + 1.5) {
                        isSeekingLock = true;
                        videoElement.currentTime = maxWatchedTime;
                        showSeekWarning();
                        setTimeout(() => { isSeekingLock = false; }, 300);
                    }
                });

                videoElement.addEventListener('ended', () => {
                    sendWatchProgress(ACTIVE_LESSON_ID, videoElement.duration || 1, videoElement.duration || 1);
                });
            }
        }

        function showSeekWarning() {
            const msg = (typeof window.i18n__ === 'function')
                ? window.i18n__('forward_seeking_restricted', 'Forward seeking is restricted until you watch this section.')
                : 'Forward seeking is restricted until you watch this section.';

            let toast = document.getElementById('seek-warning-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'seek-warning-toast';
                toast.className = 'alert alert-warning border-warning shadow-sm position-fixed top-0 start-50 translate-middle-x mt-3 z-3';
                toast.style.zIndex = '2000';
                toast.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i><span>${msg}</span>`;
                document.body.appendChild(toast);
            }
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 3000);
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

                if (data.success && data.completed && data.next_unlocked_lesson && !hasTriggeredUnlock) {
                    hasTriggeredUnlock = true;
                    handleInstantNextLessonUnlock(data.next_unlocked_lesson);
                }
            } catch (err) {
                console.error('Progress sync error:', err);
            }
        }

        function handleInstantNextLessonUnlock(nextLesson) {
            const sidebarItem = document.getElementById(`sidebar-item-${nextLesson.id}`);
            if (sidebarItem && sidebarItem.classList.contains('locked')) {
                sidebarItem.classList.remove('locked');
                sidebarItem.style.cursor = 'pointer';
                sidebarItem.style.pointerEvents = 'auto';
                sidebarItem.style.opacity = '1';

                const targetUrl = `watch_lesson.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(nextLesson.id)}`;

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
                    <a href="watch_lesson.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(nextLesson.id)}" class="btn btn-success border-0 px-3 py-1.5 fw-semibold fs-8 text-nowrap ms-2 rounded-pill">
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
    </script>
    <!-- Modern Notification System JS Client -->
    <script src="assets/js/notifications.js"></script>
</body>

</html>