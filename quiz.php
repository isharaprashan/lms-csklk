<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$course_id = $_GET['course_id'] ?? '';
$lesson_id = $_GET['lesson_id'] ?? '';

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

    // Fetch course details
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $current_course = $stmt->fetch();

    if (!$current_course) {
        die("Course not found. Go back to <a href='dashboard.php'>Dashboard</a>.");
    }

    // Fetch lessons for this course to support lesson-by-lesson quiz switching
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$course_id]);
    $course_lessons = $stmt->fetchAll();

    // Default to the first lesson if lesson_id is not explicitly specified
    if (empty($lesson_id) && !empty($course_lessons)) {
        $lesson_id = $course_lessons[0]['id'];
    }

    // Find active lesson details
    $active_quiz_lesson = null;
    foreach ($course_lessons as $l) {
        if ($l['id'] === $lesson_id) {
            $active_quiz_lesson = $l;
            break;
        }
    }

    $userRole = $_SESSION['user_role'] ?? ($student['role'] ?? 'student');
    $is_admin = in_array($userRole, ['admin', 'super_admin']);
    $is_teacher = ($userRole === 'teacher');
    $admin_preview_param = ($is_admin || isset($_GET['admin_preview'])) ? '&admin_preview=1' : '';

    // Fetch completed/watched lessons for the student (100% video review)
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

    // Fetch all lessons that have quizzes configured
    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quizzes WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $lessons_with_quizzes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Determine unlocked lessons (Lesson 1 is unlocked; subsequent require previous lesson reviewed to 100% AND previous quiz finalized)
    $unlocked_lessons = [];
    foreach ($course_lessons as $index => $l) {
        if ($is_teacher || $is_admin || $index === 0) {
            $unlocked_lessons[] = $l['id'];
        } else {
            $prev_l = $course_lessons[$index - 1];
            $prev_watched = in_array($prev_l['id'], $watched_lessons);
            $prev_has_quiz = in_array($prev_l['id'], $lessons_with_quizzes);
            $prev_quiz_done = !$prev_has_quiz || in_array($prev_l['id'], $finalized_quiz_lessons);

            if ($prev_watched && $prev_quiz_done) {
                $unlocked_lessons[] = $l['id'];
            }
        }
    }

    // Determine next lesson if available
    $next_lesson = null;
    foreach ($course_lessons as $idx => $l) {
        if ($l['id'] === $lesson_id && isset($course_lessons[$idx + 1])) {
            $next_lesson = $course_lessons[$idx + 1];
            break;
        }
    }

    $is_current_lesson_watched = in_array($lesson_id, $watched_lessons);

    // Check if the requested lesson quiz is locked
    $is_lesson_locked = (!in_array($lesson_id, $unlocked_lessons) && !$is_admin && !$is_teacher);

    if ($is_lesson_locked) {
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo __('quiz_locked', 'Quiz Locked'); ?> - <?php echo htmlspecialchars($current_course['title']); ?></title>
            <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
            <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
            <?php render_i18n_js(); ?>
            <style>
                body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #212529; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
                .locked-quiz-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); background: #ffffff; padding: 3rem 2rem; max-width: 520px; width: 100%; text-align: center; }
                .locked-icon-box { width: 80px; height: 80px; border-radius: 50%; background-color: #fff3bf; color: #f59f00; display: inline-flex; align-items: center; justify-content: center; font-size: 2.75rem; margin-bottom: 1.75rem; }
            </style>
        </head>
        <body>
            <div class="locked-quiz-card">
                <div class="locked-icon-box">
                    <i class="bi bi-lock-fill"></i>
                </div>
                <h2 class="fw-bold mb-2"><?php echo __('quiz_locked', 'Quiz Locked'); ?></h2>
                <p class="text-muted mb-4">
                    This quiz is currently locked. To unlock it, you must complete reviewing the previous lesson (100%) and finalize its quiz score.
                </p>
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <a href="classroom.php?course_id=<?php echo urlencode($course_id); ?>" class="btn btn-primary px-4 py-2.5 rounded-pill fw-semibold border-0" style="background-color: #0f4c81;">
                        <i class="bi bi-arrow-left me-1"></i> <?php echo __('go_back_to_classroom', 'Go back to Classroom'); ?>
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Fetch quiz questions strictly for this lesson
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND lesson_id = ? ORDER BY id ASC");
        $stmt->execute([$course_id, $lesson_id]);
        $all_quiz_questions = $stmt->fetchAll();
    } else {
        $all_quiz_questions = [];
    }
    $quiz_count = count($all_quiz_questions);

    if ($quiz_count === 0) {
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo __('no_quiz_available', 'No Quiz Available'); ?> -
                <?php echo htmlspecialchars($current_course['title']); ?>
            </title>
            <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
            <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">

            <!-- Google Fonts & Bootstrap 5 & Icons -->
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

            <?php render_i18n_js(); ?>

            <style>
                body {
                    font-family: 'Inter', sans-serif;
                    background-color: #f4f6f9;
                    color: #212529;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 2rem 1rem;
                }

                .empty-quiz-card {
                    border: none;
                    border-radius: 20px;
                    box-shadow: 0 15px 35px rgba(15, 76, 129, 0.08);
                    background: #ffffff;
                    overflow: hidden;
                    max-width: 520px;
                    width: 100%;
                    padding: 3rem 2.5rem;
                    text-align: center;
                    animation: fadeInUp 0.4s ease;
                }

                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(20px);
                    }

                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .empty-icon-box {
                    width: 90px;
                    height: 90px;
                    border-radius: 50%;
                    background: linear-gradient(135deg, #e7f5ff 0%, #d0ebff 100%);
                    color: #0f4c81;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 2.75rem;
                    margin-bottom: 1.75rem;
                    box-shadow: 0 8px 20px rgba(15, 76, 129, 0.12);
                }

                .empty-quiz-title {
                    font-weight: 700;
                    font-size: 1.5rem;
                    color: #1e293b;
                    margin-bottom: 0.75rem;
                }

                .empty-quiz-text {
                    color: #64748b;
                    font-size: 1rem;
                    line-height: 1.6;
                    margin-bottom: 2rem;
                }

                .btn-classroom {
                    background-color: #0f4c81;
                    color: #ffffff;
                    font-weight: 600;
                    padding: 0.8rem 1.75rem;
                    border-radius: 12px;
                    transition: all 0.25s ease;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 0.5rem;
                    border: none;
                    box-shadow: 0 4px 12px rgba(15, 76, 129, 0.2);
                }

                .btn-classroom:hover {
                    background-color: #0a355c;
                    color: #ffffff;
                    transform: translateY(-2px);
                    box-shadow: 0 6px 18px rgba(15, 76, 129, 0.3);
                }

                .btn-create-quiz {
                    background-color: #f8fafc;
                    color: #334155;
                    font-weight: 600;
                    padding: 0.8rem 1.5rem;
                    border-radius: 12px;
                    transition: all 0.25s ease;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 0.5rem;
                    border: 1px solid #cbd5e1;
                }

                .btn-create-quiz:hover {
                    background-color: #f1f5f9;
                    color: #0f172a;
                    border-color: #94a3b8;
                    transform: translateY(-2px);
                }
            </style>
        </head>

        <body>
            <div class="empty-quiz-card">
                <div class="empty-icon-box">
                    <i class="bi bi-patch-question"></i>
                </div>
                <h2 class="empty-quiz-title"><?php echo __('no_quiz_available', 'No Quiz Available'); ?></h2>
                <p class="empty-quiz-text">
                    <?php echo __('no_quiz_questions_msg', 'No quiz questions available for this course. Go back to Classroom.'); ?>
                </p>
                <div class="d-flex flex-column flex-sm-row justify-content-center gap-3">
                    <a href="classroom.php?course_id=<?php echo urlencode($course_id) . $admin_preview_param; ?>"
                        class="btn-classroom">
                        <i class="bi bi-arrow-left"></i> <?php echo __('go_back_to_classroom', 'Go back to Classroom'); ?>
                    </a>
                    <?php if ($is_teacher || $is_admin): ?>
                        <a href="create_quiz.php?course_id=<?php echo urlencode($course_id); ?>" class="btn-create-quiz">
                            <i class="bi bi-plus-circle"></i> Add Questions
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </body>

        </html>
        <?php
        exit;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('quiz_title', 'Course Knowledge Quiz'); ?> -
        <?php echo htmlspecialchars($current_course['title']); ?>
    </title>
    <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">

    <!-- Google Fonts & Bootstrap 5 & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/notifications.css">

    <?php render_i18n_js(); ?>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6f9;
            color: #212529;
            min-height: 100vh;
        }

        .quiz-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15, 76, 129, 0.08);
            background: #ffffff;
            overflow: hidden;
        }

        .quiz-header {
            background: #ffffff;
            border-bottom: 1px solid #e9ecef;
            padding: 1.5rem 2rem;
        }

        .timer-badge {
            font-size: 1rem;
            font-weight: 700;
            padding: 0.5rem 1.25rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .timer-badge.normal {
            background-color: #e7f5ff;
            color: #0f4c81;
            border: 1px solid #74c0fc;
        }

        .timer-badge.warning {
            background-color: #fff9db;
            color: #f59f00;
            border: 1px solid #ffe066;
        }

        .timer-badge.danger {
            background-color: #ffe3e3;
            color: #e03131;
            border: 1px solid #ffa8a8;
            animation: pulse-danger 1s infinite alternate;
        }

        @keyframes pulse-danger {
            0% {
                transform: scale(1);
            }

            100% {
                transform: scale(1.05);
            }
        }

        .option-card {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.2rem 1.5rem;
            background-color: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
            user-select: none;
        }

        .option-card:hover {
            border-color: #0f4c81;
            background-color: #f8f9fa;
            transform: translateY(-2px);
        }

        .option-card.selected {
            border-color: #0f4c81;
            background-color: #e7f5ff;
            box-shadow: 0 4px 12px rgba(15, 76, 129, 0.12);
        }

        .option-badge {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #e9ecef;
            color: #495057;
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .option-card.selected .option-badge {
            background-color: #0f4c81;
            color: #ffffff;
        }

        .incorrect-toast-banner {
            background-color: #fff5f5;
            border: 1px solid #ffc9c9;
            color: #e03131;
            border-radius: 10px;
            padding: 0.85rem 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            animation: fadeInDown 0.3s ease;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .question-image-box {
            max-height: 280px;
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid #dee2e6;
            margin-bottom: 1.5rem;
            text-center;
            background: #f8f9fa;
        }

        .question-image-box img {
            max-height: 280px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }

        .review-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            background-color: #ffffff;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .review-header {
            padding: 1rem 1.5rem;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .review-option {
            padding: 0.85rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.95rem;
        }

        .review-option.user-correct {
            background-color: #d3f9d8;
            border: 1px solid #b2f2bb;
            color: #2b8a3e;
            font-weight: 600;
        }

        .review-option.user-incorrect {
            background-color: #ffe3e3;
            border: 1px solid #ffa8a8;
            color: #c92a2a;
        }

        .review-option.actual-correct {
            background-color: #e7f5ff;
            border: 1px solid #a5d8ff;
            color: #1864ab;
            font-weight: 600;
        }

        .explanation-box {
            background-color: #f1f3f5;
            border-left: 4px solid #0f4c81;
            border-radius: 0 8px 8px 0;
            padding: 1rem 1.25rem;
            margin-top: 1rem;
        }
    </style>
</head>

<body>

    <!-- Unified LMS Top Header Bar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <?php
    $userRole = $_SESSION['user_role'] ?? ($student['role'] ?? 'student');
    $is_admin = in_array($userRole, ['admin', 'super_admin']);
    $admin_preview_param = ($is_admin || isset($_GET['admin_preview'])) ? '&admin_preview=1' : '';
    ?>
    <div class="bg-white border-bottom py-2 shadow-xs">
        <div class="container px-4 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?><?php echo !empty($lesson_id) ? '&lesson_id=' . urlencode($lesson_id) : ''; ?><?php echo $admin_preview_param; ?>"
                    class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1">
                    <i class="bi bi-arrow-left me-1"></i> <?php echo __('back', 'Back to Lesson'); ?>
                </a>
            </div>
            <div class="fs-8 text-muted">
                <i class="bi bi-journal-check me-1 text-primary"></i><?php echo htmlspecialchars($current_course['title']); ?>
            </div>
        </div>
    </div>

    <div class="container py-4 px-3 px-md-4" style="max-width: 900px;">

        <!-- Lesson Quiz Selector Pills -->
        <?php if (!empty($course_lessons) && count($course_lessons) > 0): ?>
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="fs-8 fw-bold text-uppercase text-muted tracking-wider"><i
                            class="bi bi-collection-play me-1"></i> Lesson Quiz Navigation:</span>
                    <?php if (!empty($active_quiz_lesson)): ?>
                        <span
                            class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 fs-9 fw-bold">
                            Current: <?php echo htmlspecialchars($active_quiz_lesson['title']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($course_lessons as $idx => $cl): ?>
                        <?php 
                            $is_sel = ($cl['id'] === $lesson_id);
                            $is_unlocked = in_array($cl['id'], $unlocked_lessons ?? []);
                            $is_done = in_array($cl['id'], $finalized_quiz_lessons ?? []) || in_array($cl['id'], $completed_lessons ?? []);
                        ?>
                        <?php if ($is_unlocked): ?>
                            <a href="quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($cl['id']); ?><?php echo $admin_preview_param; ?>"
                                class="btn btn-sm rounded-pill px-3 py-1.5 fs-8 fw-semibold text-nowrap <?php echo $is_sel ? 'btn-primary text-white shadow-sm' : 'btn-outline-secondary border-secondary border-opacity-25'; ?>"
                                style="<?php echo $is_sel ? 'background-color: #0f4c81; border: none;' : ''; ?>">
                                <i class="bi <?php echo $is_done ? 'bi-check-circle-fill text-success' : ($is_sel ? 'bi-patch-check-fill' : 'bi-journal-text'); ?> me-1"></i>
                                Lesson <?php echo $idx + 1; ?> Quiz
                            </a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-light border text-muted rounded-pill px-3 py-1.5 fs-8 text-nowrap opacity-60" style="cursor: not-allowed;" title="Locked - Complete previous quiz first">
                                <i class="bi bi-lock-fill me-1"></i>
                                Lesson <?php echo $idx + 1; ?> Quiz
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- Admin Preview Mode Quiz View List -->
            <div class="alert border-0 rounded-3 mb-4 py-3 px-4 shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-2"
                style="background-color: #0b4528; color: #ffffff;" role="alert">
                <div class="d-flex align-items-center gap-2.5">
                    <span class="badge bg-warning text-dark px-3 py-1.5 fs-8 fw-bold">
                        <i class="bi bi-eye-fill me-1"></i> Admin Quiz Preview Mode
                    </span>
                    <span class="fs-7 fw-semibold">Viewing all syllabus questions in View Mode. Correct answers are
                        highlighted in green.</span>
                </div>
                <a href="admin/index.php"
                    class="btn btn-sm btn-light rounded-pill px-3 py-1 fw-bold text-dark border-0 fs-8">Return to Admin
                    Console</a>
            </div>

            <!-- Quiz Overview Header Card -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <span
                            class="badge bg-primary bg-opacity-10 text-primary px-3 py-1.5 rounded-pill fs-8 fw-bold mb-2">
                            <?php echo htmlspecialchars($current_course['title']); ?>
                        </span>
                        <h3 class="fw-bold text-dark mb-1">Course Quiz Question List</h3>
                        <p class="text-muted fs-7 mb-0">Total <?php echo $quiz_count; ?> Questions in Syllabus Assessment
                        </p>
                    </div>
                    <span
                        class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fs-8 fw-bold">
                        <i class="bi bi-shield-check me-1"></i> Full Review Access
                    </span>
                </div>
            </div>

            <!-- Questions List Cards -->
            <?php foreach ($all_quiz_questions as $q_idx => $q): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <span class="badge bg-primary text-white rounded-pill px-3 py-1.5 fs-8 fw-bold">
                            Question <?php echo $q_idx + 1; ?> of <?php echo $quiz_count; ?>
                        </span>
                        <?php if (!empty($q['time_limit_seconds'])): ?>
                            <span class="badge bg-light text-secondary border rounded-pill px-3 py-1.5 fs-8">
                                <i class="bi bi-clock me-1"></i><?php echo intval($q['time_limit_seconds']); ?>s Limit
                            </span>
                        <?php endif; ?>
                    </div>

                    <h5 class="fw-bold text-dark mb-3 leading-snug"><?php echo htmlspecialchars($q['question']); ?></h5>

                    <?php if (!empty($q['image_path'])): ?>
                        <div class="question-image-box mb-3">
                            <img src="<?php echo htmlspecialchars($q['image_path']); ?>" alt="Question Image"
                                class="img-fluid rounded">
                        </div>
                    <?php endif; ?>

                    <!-- Options Array -->
                    <?php
                    $options = [
                        $q['option_1'] ?? '',
                        $q['option_2'] ?? '',
                        $q['option_3'] ?? '',
                        $q['option_4'] ?? ''
                    ];
                    $correct_index = intval($q['answer_index'] ?? 0);
                    ?>

                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach ($options as $o_idx => $opt_text): ?>
                            <?php if (trim($opt_text) !== ''): ?>
                                <?php $is_correct_option = ($o_idx === $correct_index); ?>
                                <div
                                    class="p-3 rounded-3 d-flex align-items-center justify-content-between border <?php echo $is_correct_option ? 'border-success bg-success bg-opacity-10 text-success fw-bold' : 'bg-light text-secondary'; ?>">
                                    <div class="d-flex align-items-center gap-3">
                                        <span
                                            class="badge rounded-circle <?php echo $is_correct_option ? 'bg-success text-white' : 'bg-white text-muted border'; ?>"
                                            style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                            <?php echo chr(65 + $o_idx); ?>
                                        </span>
                                        <span class="fs-7"><?php echo htmlspecialchars($opt_text); ?></span>
                                    </div>
                                    <?php if ($is_correct_option): ?>
                                        <span class="badge bg-success text-white px-2.5 py-1 rounded-pill fs-9">
                                            <i class="bi bi-check-circle-fill me-1"></i>Correct Answer
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($q['explanation'])): ?>
                        <div
                            class="explanation-box p-3 bg-light border-start border-4 border-primary rounded-end fs-7 text-secondary">
                            <strong class="text-dark d-block mb-1"><i class="bi bi-info-circle me-1"></i>Explanation:</strong>
                            <?php echo nl2br(htmlspecialchars($q['explanation'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="text-center mt-4 mb-3">
                <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?><?php echo $admin_preview_param; ?>"
                    class="btn btn-primary btn-lg rounded-pill px-4 py-2.5 fw-bold shadow-sm"
                    style="background-color: #0f4c81;">
                    <i class="bi bi-arrow-left me-2"></i>Return to Lesson Video
                </a>
            </div>

        <?php else: ?>
            <!-- Main Dynamic Quiz Container for Students -->
            <div id="quiz-app-root">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading Quiz...</span>
                    </div>
                    <p class="mt-3 text-muted fw-semibold">Loading quiz questions...</p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const COURSE_ID = <?php echo json_encode($course_id); ?>;
        const LESSON_ID = <?php echo json_encode($lesson_id); ?>;
        const NEXT_LESSON = <?php echo json_encode($next_lesson); ?>;
        const IS_CURRENT_LESSON_WATCHED = <?php echo json_encode($is_current_lesson_watched); ?>;
        let timerInterval = null;
        let remainingSeconds = 30;
        let maxQuestionTimer = 30;
        let selectedOptionIndex = -1;
        let isSubmitting = false;
        let currentQType = 'mcq';

        document.addEventListener('DOMContentLoaded', () => {
            fetchQuizState();
        });

        async function fetchQuizState() {
            try {
                const response = await fetch('api/quiz_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_state', course_id: COURSE_ID, lesson_id: LESSON_ID })
                });
                const data = await response.json();

                if (!data.success) {
                    renderError(data.message || 'Failed to load quiz state.');
                    return;
                }

                renderQuizState(data);
            } catch (err) {
                console.error(err);
                renderError('Network error while connecting to quiz server.');
            }
        }

        function renderError(message) {
            const root = document.getElementById('quiz-app-root');
            root.innerHTML = `
                <div class="quiz-card p-5 text-center">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-1 mb-3"></i>
                    <h4 class="fw-bold text-dark mb-2">${message}</h4>
                    <a href="classroom.php?course_id=${encodeURIComponent(COURSE_ID)}" class="btn btn-primary mt-3 px-4 py-2" style="background-color: #0f4c81;">
                        Return to Course
                    </a>
                </div>
            `;
        }

        function renderQuizState(data) {
            clearInterval(timerInterval);
            const root = document.getElementById('quiz-app-root');
            const attempt = data.attempt;

            // 1. If Attempt is Finished (Completed or Finalized or Locked)
            if (attempt.status === 'completed' || attempt.status === 'finalized' || attempt.is_locked) {
                renderAttemptResultScreen(data);
                return;
            }

            // 2. Active Single Question Display
            const currQ = data.current_question;
            if (!currQ) {
                renderError('No active question found.');
                return;
            }

            selectedOptionIndex = -1;
            isSubmitting = false;
            currentQType = currQ.question_type || 'mcq';
            maxQuestionTimer = currQ.time_limit_seconds || 30;
            remainingSeconds = data.remaining_seconds ?? maxQuestionTimer;

            const total = attempt.total_questions;
            const qNum = currQ.question_number;
            const maxAttemptsAllowed = attempt.max_allowed_attempts || 3;
            const progressPercent = Math.round(((qNum - 1) / total) * 100);

            const questionProgressText = (window.i18n__ && window.i18n__('question_progress', 'Question %d of %d'))
                .replace('%d', qNum).replace('%d', total);

            root.innerHTML = `
                <div class="quiz-card">
                    <!-- Progress Bar -->
                    <div class="progress rounded-0" style="height: 6px; background-color: #e9ecef;">
                        <div class="progress-bar" style="width: ${progressPercent}%; background-color: #0f4c81; transition: width 0.4s ease;"></div>
                    </div>

                    <!-- Quiz Header -->
                    <div class="quiz-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-1.5 rounded-pill fs-8 mb-1">
                                Attempt ${attempt.attempt_number} of ${maxAttemptsAllowed}
                            </span>
                            <h5 class="fw-bold text-dark mb-0">${questionProgressText}</h5>
                        </div>

                        <!-- Per-Question Countdown Timer -->
                        <div id="timer-badge-container">
                            <div id="timer-badge" class="timer-badge normal">
                                <i class="bi bi-clock-history"></i>
                                <span id="timer-countdown-text">${remainingSeconds}s</span>
                            </div>
                        </div>
                    </div>

                    <!-- Incorrect Instant Feedback Banner Container -->
                    <div id="instant-feedback-container" class="px-4 pt-3 d-none">
                        <div class="incorrect-toast-banner">
                            <i class="bi bi-x-circle-fill fs-5"></i>
                            <span>${window.i18n__ ? window.i18n__('incorrect_feedback', 'Incorrect') : 'Incorrect'}</span>
                        </div>
                    </div>

                    <!-- Single Question Content -->
                    <div class="p-4 p-md-5">
                        <h4 class="fw-bold text-dark mb-3 leading-snug">${escapeHtml(currQ.question)}</h4>

                        <!-- Optional Question Image Attachment -->
                        ${currQ.image_path ? `
                            <div class="question-image-box">
                                <img src="${currQ.image_path}" alt="Question Image">
                            </div>
                        ` : ''}

                        <!-- Answer Input: MCQ vs Text Input -->
                        ${currentQType === 'mcq' ? `
                            <div class="d-flex flex-column gap-3 mb-4" id="options-container">
                                ${currQ.options.map((optText, oIdx) => `
                                    <div class="option-card" data-index="${oIdx}" onclick="selectOption(${oIdx})">
                                        <div class="option-badge">${String.fromCharCode(65 + oIdx)}</div>
                                        <span class="fw-medium text-dark flex-grow-1">${escapeHtml(optText)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        ` : `
                            <div class="mb-4">
                                <label class="form-label fw-semibold text-secondary fs-7 mb-2">
                                    <i class="bi bi-pencil-square me-1 text-primary"></i>Your Answer:
                                </label>
                                <textarea id="text-answer-input" class="form-control bg-light border p-3" rows="3" 
                                    placeholder="${window.i18n__ ? window.i18n__('type_your_answer', 'Type your answer here...') : 'Type your answer here...'}"
                                    oninput="onTextInputChange()"></textarea>
                            </div>
                        `}

                        <!-- Submit / Next Button -->
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <span class="text-muted fs-8">
                                <i class="bi bi-info-circle me-1"></i>Select or type an answer, or wait for auto-advance.
                            </span>
                            <button id="next-question-btn" onclick="submitCurrentQuestion()" class="btn btn-primary btn-lg px-4 py-2.5 border-0 shadow-sm fw-bold" style="background-color: #0f4c81;" disabled>
                                ${qNum === total ? (window.i18n__ ? window.i18n__('finish_quiz', 'Finish Quiz') : 'Finish Quiz') : (window.i18n__ ? window.i18n__('next_question', 'Next Question') : 'Next Question')}
                                <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            startCountdownTimer();
        }

        function selectOption(idx) {
            if (isSubmitting) return;

            selectedOptionIndex = idx;
            const cards = document.querySelectorAll('.option-card');
            cards.forEach(card => {
                const cardIdx = parseInt(card.getAttribute('data-index'));
                if (cardIdx === idx) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });

            const nextBtn = document.getElementById('next-question-btn');
            if (nextBtn) nextBtn.disabled = false;
        }

        function onTextInputChange() {
            if (isSubmitting) return;
            const textVal = document.getElementById('text-answer-input').value.trim();
            const nextBtn = document.getElementById('next-question-btn');
            if (nextBtn) {
                nextBtn.disabled = (textVal.length === 0);
            }
        }

        function startCountdownTimer() {
            updateTimerDisplay();

            timerInterval = setInterval(() => {
                remainingSeconds--;
                updateTimerDisplay();

                if (remainingSeconds <= 0) {
                    clearInterval(timerInterval);
                    handleTimerTimeout();
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const badge = document.getElementById('timer-badge');
            const text = document.getElementById('timer-countdown-text');

            if (!badge || !text) return;

            const remainingText = (window.i18n__ && window.i18n__('timer_remaining', '%ds Remaining'))
                .replace('%d', Math.max(0, remainingSeconds));

            text.innerText = remainingText;

            if (remainingSeconds <= 5) {
                badge.className = 'timer-badge danger';
            } else if (remainingSeconds <= 12) {
                badge.className = 'timer-badge warning';
            } else {
                badge.className = 'timer-badge normal';
            }
        }

        async function handleTimerTimeout() {
            if (isSubmitting) return;
            await submitCurrentQuestion(true);
        }

        async function submitCurrentQuestion(isTimedOut = false) {
            if (isSubmitting) return;
            isSubmitting = true;

            clearInterval(timerInterval);

            const nextBtn = document.getElementById('next-question-btn');
            if (nextBtn) {
                nextBtn.disabled = true;
                nextBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Checking...`;
            }

            const payload = {
                action: 'submit_answer',
                course_id: COURSE_ID,
                lesson_id: LESSON_ID
            };

            if (currentQType === 'mcq') {
                payload.selected_index = isTimedOut ? -1 : selectedOptionIndex;
            } else {
                const textInput = document.getElementById('text-answer-input');
                payload.user_answer_text = isTimedOut ? '' : (textInput ? textInput.value : '');
            }

            try {
                const response = await fetch('api/quiz_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (!data.success) {
                    renderError(data.message || 'Failed to submit answer.');
                    return;
                }

                // Instant Feedback: If incorrect (and not timed out), show quick Incorrect badge
                if (data.is_correct === false) {
                    const feedbackContainer = document.getElementById('instant-feedback-container');
                    if (feedbackContainer) {
                        feedbackContainer.classList.remove('d-none');
                    }
                    await new Promise(res => setTimeout(res, 1200));
                }

                renderQuizState(data);
            } catch (err) {
                console.error(err);
                renderError('Connection lost while submitting question.');
            }
        }

        function renderAttemptResultScreen(data) {
            const root = document.getElementById('quiz-app-root');
            const attempt = data.attempt;
            const review = data.review || [];
            const maxAttemptsAllowed = attempt.max_allowed_attempts || 3;
            const isFinalized = (attempt.status === 'finalized');
            const isMaxAttempts = (attempt.attempt_number >= maxAttemptsAllowed);
            const isLocked = isFinalized || isMaxAttempts;

            const titleText = isLocked
                ? (window.i18n__ ? window.i18n__('quiz_completed_final', 'Quiz Completed - Final Saved Score') : 'Quiz Completed - Final Saved Score')
                : (window.i18n__ ? window.i18n__('attempt_summary_title', 'Attempt %d Completed!').replace('%d', attempt.attempt_number) : `Attempt ${attempt.attempt_number} Completed!`);

            root.innerHTML = `
                <div class="quiz-card p-4 p-md-5 mb-4">
                    <div class="text-center pb-4 border-bottom">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle ${isLocked ? 'bg-success text-white' : 'bg-primary text-white'} mb-3" style="width: 72px; height: 72px;">
                            <i class="bi ${isLocked ? 'bi-patch-check-fill' : 'bi-award-fill'} fs-1"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-1">${titleText}</h3>
                        <p class="text-muted fs-7 mb-4">
                            ${isLocked ? 'Your official quiz result is saved. View full question review and explanations below.' : 'You can choose to finalize this score or retake the quiz to improve your score.'}
                        </p>

                        <!-- Score Card Badge -->
                        <div class="d-inline-block p-3 px-5 rounded-4 bg-light border">
                            <span class="text-uppercase tracking-wider fs-8 text-muted fw-bold d-block mb-1">
                                ${window.i18n__ ? window.i18n__('score_achieved', 'Score Achieved') : 'Score Achieved'}
                            </span>
                            <div class="display-5 fw-extrabold text-primary mb-0" style="color: #0f4c81 !important;">
                                ${attempt.score} <span class="fs-4 text-muted font-normal">/ ${attempt.total_questions}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Post Attempt Choices -->
                    ${!isLocked ? `
                        <div class="pt-4 text-center">
                            <h6 class="fw-bold text-dark mb-3">What would you like to do next?</h6>
                            <div class="d-flex flex-wrap justify-content-center gap-3">
                                <button onclick="finalizeScore()" class="btn btn-success btn-lg px-4 py-2.5 border-0 shadow-sm fw-bold">
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                    ${window.i18n__ ? window.i18n__('finalize_submit_score', 'Finalize & Submit Score') : 'Finalize & Submit Score'}
                                </button>

                                <button onclick="retakeQuiz()" class="btn btn-outline-primary btn-lg px-4 py-2.5 fw-bold">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    ${(window.i18n__ ? window.i18n__('retake_quiz_attempt', 'Retake Quiz (Attempt %d of %d)') : 'Retake Quiz (Attempt %d of %d)').replace('%d', attempt.attempt_number + 1).replace('%d', maxAttemptsAllowed)}
                                </button>
                            </div>
                        </div>
                    ` : `
                        <div class="pt-4 text-center">
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3.5 py-2 rounded-pill fs-7 fw-bold mb-3 d-inline-block">
                                <i class="bi bi-patch-check-fill me-1"></i> Marks Finalized & Saved
                            </span>

                            ${NEXT_LESSON ? `
                                <div class="p-3 bg-light rounded-4 border mt-2">
                                    ${IS_CURRENT_LESSON_WATCHED ? `
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                                            <i class="bi bi-unlock-fill fs-5 text-warning"></i>
                                            <h6 class="fw-bold mb-0 text-dark">Next Lesson Quiz Unlocked!</h6>
                                        </div>
                                        <p class="text-muted fs-8 mb-3">
                                            You have finalized this quiz and reviewed the lesson to 100%. Proceed to <strong>${escapeHtml(NEXT_LESSON.title)}</strong> quiz.
                                        </p>
                                        <a href="quiz.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(NEXT_LESSON.id)}" class="btn btn-primary px-4 py-2.5 rounded-pill fw-bold shadow-sm" style="background-color: #0f4c81;">
                                            Start Next Quiz <i class="bi bi-arrow-right ms-1"></i>
                                        </a>
                                    ` : `
                                        <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                                            <i class="bi bi-play-circle-fill fs-5 text-primary"></i>
                                            <h6 class="fw-bold mb-0 text-dark">Quiz Marks Finalized! Review Lesson Video to 100%</h6>
                                        </div>
                                        <p class="text-muted fs-8 mb-3">
                                            Your quiz marks are saved. To unlock the next quiz, please make sure you have reviewed this lesson video completely (100%).
                                        </p>
                                        <a href="watch_lesson.php?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${encodeURIComponent(LESSON_ID)}" class="btn btn-warning px-4 py-2.5 rounded-pill fw-bold shadow-sm text-dark">
                                            <i class="bi bi-play-fill me-1"></i> Complete Lesson Video (100%)
                                        </a>
                                    `}
                                </div>
                            ` : `
                                <div class="mt-2">
                                    <a href="classroom.php?course_id=${encodeURIComponent(COURSE_ID)}" class="btn btn-outline-secondary px-4 py-2 rounded-pill fs-8 fw-semibold">
                                        <i class="bi bi-arrow-left me-1"></i> Back to Classroom
                                    </a>
                                </div>
                            `}
                        </div>
                    `}
                </div>

                <!-- Comprehensive Review Section -->
                ${isLocked && review.length > 0 ? `
                    <div class="quiz-card p-4 p-md-5">
                        <h4 class="fw-bold text-dark mb-4 border-bottom pb-3 d-flex align-items-center gap-2">
                            <i class="bi bi-journal-check text-success"></i>
                            ${window.i18n__ ? window.i18n__('review_answers_explanations', 'Comprehensive Review & Explanations') : 'Comprehensive Review & Explanations'}
                        </h4>

                        <div class="d-flex flex-column gap-4">
                            ${review.map(item => `
                                <div class="review-card">
                                    <div class="review-header d-flex justify-content-between align-items-center">
                                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                            <span class="badge bg-secondary text-white fs-8">${item.question_number}</span>
                                            <span>${escapeHtml(item.question)}</span>
                                        </h6>
                                        <span class="badge ${item.is_correct ? 'bg-success' : 'bg-danger'} px-3 py-1.5">
                                            ${item.is_correct ? 'Correct' : 'Incorrect'}
                                        </span>
                                    </div>
                                    <div class="p-3.5">
                                        ${item.image_path ? `
                                            <div class="question-image-box my-2">
                                                <img src="${item.image_path}" alt="Question Image">
                                            </div>
                                        ` : ''}

                                        ${item.question_type === 'mcq' ? `
                                            <div class="mb-3">
                                                ${item.options.map((optText, oIdx) => {
                let optClass = 'border bg-light text-secondary';
                let labelBadge = '';

                if (oIdx === item.correct_index) {
                    optClass = 'review-option actual-correct';
                    labelBadge = `<span class="badge bg-success ms-2"><i class="bi bi-check-lg me-1"></i>${window.i18n__ ? window.i18n__('correct_answer', 'Correct Answer') : 'Correct Answer'}</span>`;
                }
                if (oIdx === item.user_selection) {
                    if (item.is_correct) {
                        optClass = 'review-option user-correct';
                        labelBadge = `<span class="badge bg-success ms-2"><i class="bi bi-person-check-fill me-1"></i>${window.i18n__ ? window.i18n__('your_answer', 'Your Answer') : 'Your Answer'}</span>`;
                    } else {
                        optClass = 'review-option user-incorrect';
                        labelBadge = `<span class="badge bg-danger ms-2"><i class="bi bi-x-circle-fill me-1"></i>${window.i18n__ ? window.i18n__('your_answer', 'Your Answer') : 'Your Answer'}</span>`;
                    }
                }

                return `
                                                        <div class="review-option ${optClass}">
                                                            <span><strong>${String.fromCharCode(65 + oIdx)}.</strong> ${escapeHtml(optText)}</span>
                                                            ${labelBadge}
                                                        </div>
                                                    `;
            }).join('')}
                                            </div>
                                        ` : `
                                            <div class="mb-3">
                                                <div class="p-3 rounded border mb-2 ${item.is_correct ? 'bg-success bg-opacity-10 border-success' : 'bg-danger bg-opacity-10 border-danger'}">
                                                    <small class="text-muted fw-bold d-block mb-1">Your Typed Answer:</small>
                                                    <span class="fw-semibold text-dark">${escapeHtml(item.user_text || '(No Answer / Timed Out)')}</span>
                                                </div>
                                                <div class="p-3 rounded border bg-info bg-opacity-10 border-info">
                                                    <small class="text-muted fw-bold d-block mb-1">Expected Pattern / Answer Key:</small>
                                                    <span class="fw-semibold text-dark">${escapeHtml(item.correct_answer)}</span>
                                                </div>
                                            </div>
                                        `}

                                        <div class="explanation-box">
                                            <div class="fw-bold text-dark fs-7 mb-1 d-flex align-items-center gap-1.5">
                                                <i class="bi bi-lightbulb-fill text-warning"></i>
                                                ${window.i18n__ ? window.i18n__('explanation', 'Explanation') : 'Explanation'}:
                                            </div>
                                            <p class="text-secondary fs-7 mb-0 leading-relaxed">${escapeHtml(item.explanation)}</p>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                ` : ''}
            `;
        }

        async function finalizeScore() {
            try {
                const response = await fetch('api/quiz_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'finalize_quiz', course_id: COURSE_ID, lesson_id: LESSON_ID })
                });

                const data = await response.json();
                if (!data.success) {
                    alert(data.message || 'Failed to finalize quiz.');
                    return;
                }
                location.reload();
            } catch (err) {
                console.error(err);
                alert('Network error while finalizing score.');
            }
        }

        async function retakeQuiz() {
            try {
                const response = await fetch('api/quiz_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'retake_quiz', course_id: COURSE_ID, lesson_id: LESSON_ID })
                });

                const data = await response.json();
                if (!data.success) {
                    alert(data.message || 'Failed to retake quiz.');
                    return;
                }
                renderQuizState(data);
            } catch (err) {
                console.error(err);
                alert('Network error while requesting retake.');
            }
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