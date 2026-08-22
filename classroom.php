<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];
$course_id = $_GET['course_id'] ?? '';

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

  if ($student['role'] === 'teacher' && $student['status'] === 'pending') {
    header("Location: pending_approval.php");
    exit;
  }

  $is_teacher = (($student['role'] ?? 'student') === 'teacher');
  $is_admin = in_array($student['role'] ?? 'student', ['admin', 'super_admin']);

  // If a student navigates to classroom.php, route them to their learning watch page
  if (!$is_teacher && !$is_admin) {
    $lesson_param = isset($_GET['lesson_id']) ? '&lesson_id=' . urlencode($_GET['lesson_id']) : '';
    header("Location: watch_lesson.php?course_id=" . urlencode($course_id) . $lesson_param);
    exit;
  }

  // Fetch course details
  $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
  $stmt->execute([$course_id]);
  $current_course = $stmt->fetch();

  if (!$current_course) {
    die("Course not found. Go back to <a href='dashboard.php'>Dashboard</a>.");
  }

  // Fetch active target audiences
  $stmt = $pdo->query("SELECT * FROM target_audiences WHERE status = 'active' ORDER BY name ASC");
  $active_target_audiences = $stmt->fetchAll();

  // Determine course management permission (course owner tutor or admin)
  $is_course_owner = ($is_teacher && (empty($current_course['tutor_id']) || intval($current_course['tutor_id']) === intval($user_id)));
  $can_manage = ($is_course_owner || $is_admin || $is_teacher);

  // Check student enrollment status
  $is_enrolled = false;
  if (!$is_teacher && !$is_admin) {
    $enrollCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?");
    $enrollCheckStmt->execute([$user_id, $course_id]);
    $is_enrolled = ($enrollCheckStmt->fetchColumn() > 0);
  }

  // Access control for pending/rejected/disabled courses
  $course_status = $current_course['status'] ?? 'approved';
  if ($course_status !== 'approved' && $course_status !== 'active') {
    // If course is disabled/soft-deleted, enrolled students retain access
    if ($course_status === 'disabled' && $is_enrolled) {
      // Allow access for enrolled students
    } elseif (!$can_manage) {
      if ($course_status === 'disabled') {
        die("This course is currently unpublished. Go back to <a href='dashboard.php'>Dashboard</a>.");
      } else {
        die("This course is currently pending admin review. Go back to <a href='dashboard.php'>Dashboard</a>.");
      }
    }
  }

  // Fetch lessons for this course
  $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC");
  $stmt->execute([$course_id]);
  $lessons = $stmt->fetchAll();

  // Fetch quiz questions for this course
  $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ?");
  $stmt->execute([$course_id]);
  $raw_quizzes = $stmt->fetchAll();

  $quiz_questions = [];
  foreach ($raw_quizzes as $q) {
    $quiz_questions[] = [
      'question_id' => $q['question_id'],
      'lesson_id' => $q['lesson_id'] ?? '',
      'question' => $q['question'],
      'options' => [
        $q['option_1'],
        $q['option_2'],
        $q['option_3'],
        $q['option_4']
      ],
      'answer_index' => intval($q['answer_index'])
    ];
  }

  // Fetch completed lessons for the student
  $stmt = $pdo->prepare("SELECT lesson_id FROM completed_lessons WHERE user_id = ?");
  $stmt->execute([$user_id]);
  $completed_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);

  // Fetch saved video watch progress (position/duration) for every lesson in this course
  $stmt = $pdo->prepare("SELECT lp.lesson_id, lp.position_seconds, lp.duration_seconds, lp.progress_percent
                          FROM lesson_progress lp
                          INNER JOIN lessons l ON l.id = lp.lesson_id
                          WHERE lp.user_id = ? AND l.course_id = ?");
  $stmt->execute([$user_id, $course_id]);
  $progress_rows = $stmt->fetchAll();

  $lesson_progress = [];
  foreach ($progress_rows as $row) {
    $lesson_progress[$row['lesson_id']] = [
      'position' => (float) $row['position_seconds'],
      'duration' => (float) $row['duration_seconds'],
      'percent' => (float) $row['progress_percent']
    ];
  }

  // Fetch quiz score if attempted
  $stmt = $pdo->prepare("SELECT score FROM quiz_results WHERE user_id = ? AND course_id = ?");
  $stmt->execute([$user_id, $course_id]);
  $quiz_score_row = $stmt->fetch();
  $quiz_score = $quiz_score_row ? intval($quiz_score_row['score']) : null;

  // Fetch all supplementary resources attached to course lessons
  $stmt = $pdo->prepare("SELECT lr.* FROM lesson_resources lr INNER JOIN lessons l ON l.id = lr.lesson_id WHERE l.course_id = ? ORDER BY lr.uploaded_at ASC");
  $stmt->execute([$course_id]);
  $all_course_resources = $stmt->fetchAll();
  $resources_by_lesson = [];
  foreach ($all_course_resources as $r) {
    $bytes = (int)$r['file_size'];
    $r['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
    $resources_by_lesson[$r['lesson_id']][] = $r;
  }

  // Fetch all courses for sidebar navigation (approved courses, or pending if owner/admin)
  if ($is_admin) {
    $stmt = $pdo->query("SELECT * FROM courses");
  } else {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE ((status = 'approved' OR status = 'active') AND is_archived = 0) OR tutor_id = ?");
    $stmt->execute([$user_id]);
  }
  $all_courses = $stmt->fetchAll();

  // Fetch enrolled course IDs if student
  if (!$is_teacher && !$is_admin) {
    $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
  } else {
    $enrolled_ids = [];
  }

  $is_enrolled = !$is_teacher && !$is_admin && in_array($course_id, $enrolled_ids);

  // If not enrolled and it is a paid course, redirect to payment page
  if (!$is_teacher && !$is_admin && !$is_enrolled && floatval($current_course['price']) > 0) {
    header("Location: payment.php?course_id=" . urlencode($course_id));
    exit;
  }

  $has_access = $is_teacher || $is_admin || $is_enrolled;
  $admin_preview_param = ($is_admin || isset($_GET['admin_preview'])) ? '&admin_preview=1' : '';

  // Fetch notifications for all roles (students & teachers)
  $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
  $stmt->execute([$user_id]);
  $notifications = $stmt->fetchAll();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
  $stmt->execute([$user_id]);
  $unread_count = (int) $stmt->fetchColumn();

} catch (PDOException $e) {
  die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($current_course['title']); ?> | Course Classroom</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>

  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Modern Notification System Styles -->
  <link rel="stylesheet" href="assets/css/notifications.css">

  <!-- Local Tailwind CSS -->
  <script src="assets/js/tailwind.js"></script>
  <script>
    tailwind.config = {
      corePlugins: {
        preflight: false,
      },
      theme: {
        extend: {
          colors: {
            moodle: {
              blue: '#0f4c81',
              orange: '#f26f21',
              bg: '#f8f9fa'
            }
          }
        }
      }
    }
  </script>

  <!-- Custom CSS -->
  <link class="moodle-style" rel="stylesheet" href="assets/css/style.css">
  <style>
    .no-caret::after {
      display: none !important;
    }

    .video-wrapper {
      background: #000000;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(0, 0, 0, 0.1);
      position: relative;
    }

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

    .lesson-outline-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.65rem 0.75rem;
      border-radius: 12px;
      margin-bottom: 0.5rem;
      border: 1px solid #e2e8f0;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      background-color: #ffffff;
      gap: 0.5rem;
      min-width: 0;
    }

    .lesson-outline-item:hover:not(.locked) {
      background-color: #f8fafc;
      border-color: #cbd5e1;
    }

    .lesson-outline-item.active {
      background: linear-gradient(135deg, rgba(15, 76, 129, 0.06) 0%, rgba(15, 76, 129, 0.12) 100%);
      border: 1px solid rgba(15, 76, 129, 0.3);
      border-left: 5px solid #0f4c81;
      box-shadow: 0 4px 12px rgba(15, 76, 129, 0.1);
    }

    .lesson-outline-item.completed:not(.active) {
      background-color: rgba(25, 135, 84, 0.03);
      border-color: rgba(25, 135, 84, 0.15);
    }

    /* Teacher action buttons inside lesson items */
    .lesson-actions {
      display: flex;
      align-items: center;
      gap: 4px;
      flex-shrink: 0;
    }

    .lesson-actions .btn {
      padding: 2px 7px;
      font-size: 0.72rem;
      line-height: 1.4;
      white-space: nowrap;
    }

    .now-playing-badge {
      background-color: #198754;
      color: #ffffff;
      font-size: 0.65rem;
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
  </style>
  <script>
    window.USER_ROLE = <?php echo json_encode($_SESSION['user_role'] ?? ($student['role'] ?? 'student')); ?>;
    window.IS_REVIEW_MODE = (window.USER_ROLE === 'admin' || window.USER_ROLE === 'super_admin' || window.USER_ROLE === 'teacher');
  </script>
</head>

<body class="bg-light text-dark">

  <?php if ($is_admin): ?>
    <div
      class="alert border-0 rounded-0 mb-0 py-2.5 px-4 text-center fs-7 shadow-sm d-flex align-items-center justify-content-center gap-2"
      style="background-color: #0b4528; color: #ffffff;">
      <i class="bi bi-shield-check fs-6 text-warning"></i>
      <span><strong>Instructor Review Mode Active:</strong> Previewing course content with full Administrator privileges.
        Profile editing, course payment, and enrollment features are disabled during review.</span>
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
            echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate ' . $is_active . '">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                        </a>';
          }
        }
        if (!$enrolled_any) {
          echo '<div class="px-4 py-2 fs-8 text-muted italic">No courses created yet</div>';
        }
      } else {
        foreach ($all_courses as $cs_course) {
          if (in_array($cs_course['id'], $enrolled_ids)) {
            $enrolled_any = true;
            $is_active = ($cs_course['id'] === $course_id) ? 'active' : '';
            echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate ' . $is_active . '">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                        </a>';
          }
        }
        if (!$enrolled_any) {
          echo '<div class="px-4 py-2 fs-8 text-muted italic">No enrolled courses</div>';
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
            <?php echo htmlspecialchars($current_course['title']); ?>
          </li>
        </ol>
      </nav>

      <!-- Course Main Header Card -->
      <div class="moodle-card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gy-3">
          <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <span class="badge bg-light text-primary border"><?php echo htmlspecialchars($current_course['category']); ?></span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border"><?php echo htmlspecialchars($current_course['level'] ?? 'Beginner'); ?></span>
              <span class="badge bg-info bg-opacity-10 text-dark border"><i class="bi bi-calendar3 me-1"></i><?php echo intval($current_course['duration'] ?? 8); ?> Weeks</span>
              <span class="badge <?php echo floatval($current_course['price']) == 0 ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-primary bg-opacity-10 text-primary border border-primary'; ?>">
                <?php echo floatval($current_course['price']) == 0 ? 'Free Course' : 'Rs. ' . number_format($current_course['price'], 2); ?>
              </span>
              <?php if (($current_course['status'] ?? 'approved') === 'pending'): ?>
                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning"><i class="bi bi-clock-history me-1"></i>Pending Review</span>
              <?php else: ?>
                <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-check-circle-fill me-1"></i>Published</span>
              <?php endif; ?>
            </div>
            <h1 class="fw-bold text-dark mb-2 fs-3"><?php echo htmlspecialchars($current_course['title']); ?></h1>
            <p class="text-muted fs-7 mb-0"><i class="bi bi-person me-1"></i> Lecturer: <strong
                class="text-secondary"><?php echo htmlspecialchars($current_course['tutor_name']); ?></strong></p>
            <?php if (!empty($current_course['target_audience'])): ?>
              <div class="mt-2 d-flex flex-wrap align-items-center gap-1.5">
                <small class="text-muted fw-semibold me-1"><i class="bi bi-people me-1"></i>Target Audience:</small>
                <?php foreach (array_filter(array_map('trim', explode(',', $current_course['target_audience']))) as $aud): ?>
                  <span
                    class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 px-2.5 py-1 rounded-pill fs-8">
                    <?php echo htmlspecialchars($aud); ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($can_manage): ?>
              <button type="button"
                class="btn btn-primary btn-sm rounded-pill px-3 py-1.5 shadow-sm fw-bold d-inline-flex align-items-center gap-1.5"
                data-bs-toggle="modal" data-bs-target="#editCourseModal" style="background-color: #0f4c81;">
                <i class="bi bi-pencil-square"></i> Edit Course Details
              </button>
              <button type="button"
                class="btn btn-success btn-sm rounded-pill px-3 py-1.5 shadow-sm fw-bold d-inline-flex align-items-center gap-1.5"
                data-bs-toggle="modal" data-bs-target="#addLessonModal" style="background-color: #198754;">
                <i class="bi bi-plus-circle-fill"></i> Add Lesson
              </button>
              <a href="watch_lesson.php?course_id=<?php echo urlencode($course_id); ?>&sid=<?php echo urlencode(session_id()); ?>"
                class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1.5 fw-bold d-inline-flex align-items-center gap-1.5"
                title="Preview course as a student in the dedicated player">
                <i class="bi bi-play-circle-fill text-primary"></i> Preview as Student
              </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Moodle Secondary Navigation Tabs -->
        <div class="d-flex border-top mt-4 pt-2 gap-1 overflow-auto">
          <button class="moodle-tab-btn active"><i class="bi bi-journal-richtext me-1"></i> Course Management & Syllabus</button>
        </div>
      </div>

      <!-- Core Moodle Two-Column Layout -->
      <div class="row g-4">

        <!-- Left: Active Activity Focus Workspace Card (8 Columns) -->
        <div class="col-lg-8">

          <!-- Focus Activity Card Wrapper -->
          <div class="moodle-card p-4 mb-4" id="activity-focus-card">

            <!-- Video Player Content Block (Default Active View) -->
            <div id="view-video" class="activity-view">

              <?php if ($is_admin): ?>
                <!-- Instructor Review Mode Visual Indicator Banner -->
                <div
                  class="alert border-0 rounded-3 mb-4 py-2.5 px-4 shadow-sm d-flex flex-wrap align-items-center justify-content-between gap-2"
                  style="background-color: #0b4528; color: #ffffff;" role="alert">
                  <div class="d-flex align-items-center gap-2.5">
                    <span class="badge bg-warning text-dark px-2.5 py-1.5 fs-8 fw-bold">
                      <i class="bi bi-shield-check me-1"></i> Instructor Review Mode Active
                    </span>
                    <span
                      class="fs-7 fw-semibold"><?php echo __('instructor_review_mode', 'Instructor / Admin Review Mode - Seeking & Syllabus Access Unlocked.'); ?></span>
                  </div>
                  <a href="admin/index.php"
                    class="btn btn-sm btn-light rounded-pill px-3 py-1 fw-bold text-dark border-0 fs-8">Return to Admin
                    Console</a>
                </div>
              <?php endif; ?>

              <!-- Video Player Header Title & Info -->
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-3 border-bottom">
                <div>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1.5 rounded-pill fs-8 fw-bold"
                      id="active-lesson-badge">
                      Lesson Activity
                    </span>
                    <small class="text-muted fs-8" id="active-lesson-duration"><i class="bi bi-clock me-1"></i>Duration:
                      <?php echo htmlspecialchars($lessons[0]['duration'] ?? ''); ?></small>
                  </div>
                  <h4 class="fw-bold text-dark mb-0 fs-4" id="active-lesson-heading">
                    <?php echo htmlspecialchars($lessons[0]['title'] ?? 'Welcome to this Course'); ?>
                  </h4>
                </div>

                <div class="d-flex align-items-center gap-2">
                  <span id="lesson-completed-badge"
                    class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-1.5 rounded-pill fs-8 d-none">
                    <i class="bi bi-patch-check-fill me-1"></i> 100% Completed
                  </span>
                </div>
              </div>

              <!-- Lesson Video Player Container -->
              <div class="video-wrapper mb-3"
                style="min-height: 420px; background-color: #000; border-radius: 16px; position: relative;">
                <div id="player-container" class="w-100 h-100 d-flex align-items-center justify-content-center">
                  <!-- Loaded dynamically via JS -->
                </div>
              </div>

              <!-- Video Watch Progress Bar -->
              <div class="lesson-progress-track mb-4"
                style="height: 6px; background-color: #e2e8f0; border-radius: 10px; overflow: hidden;">
                <div id="lesson-progress-bar"
                  style="height: 100%; width: 0%; background-color: #0f4c81; transition: width 0.25s ease;"></div>
              </div>

              <!-- Quiz Banner -->
              <div class="enter-quiz-banner mb-4">
                <div class="d-flex align-items-center gap-3">
                  <div
                    class="rounded-circle bg-white bg-opacity-20 p-3 d-flex align-items-center justify-content-center"
                    style="width: 48px; height: 48px;">
                    <i class="bi bi-patch-question-fill fs-4 text-white"></i>
                  </div>
                  <div>
                    <h5 class="fw-bold mb-1 text-white"><?php echo __('quiz_title', 'Course Knowledge Quiz'); ?></h5>
                    <p class="mb-0 text-white-50 fs-7">
                      <?php echo __('quiz_subtitle', 'Evaluate your conceptual understanding with single-question timed challenges.'); ?>
                    </p>
                  </div>
                </div>
                <?php
                  $quiz_btn_label = $is_teacher ? __('manage_quiz', 'Manage Quiz') : __('enter_quiz', 'Enter Quiz');
                  $quiz_btn_icon  = $is_teacher ? 'bi-pencil-square' : 'bi-play-circle-fill';
                  $first_lesson_param = !empty($lessons[0]['id']) ? '&lesson_id=' . urlencode($lessons[0]['id']) : '';
                  $sid_param = '&sid=' . urlencode(session_id());
                  if ($is_teacher) {
                    $quiz_btn_href = 'create_quiz.php?course_id=' . urlencode($course_id) . $first_lesson_param . $sid_param;
                  } else {
                    $quiz_btn_href = 'quiz.php?course_id=' . urlencode($course_id) . $first_lesson_param . $admin_preview_param . $sid_param;
                  }
                ?>
                <a href="<?php echo $quiz_btn_href; ?>"
                  class="btn btn-light btn-lg px-4 py-2 fw-bold text-primary border-0 shadow-sm text-nowrap rounded-pill enter-quiz-btn"
                  id="enter-quiz-banner-btn">
                  <i class="bi <?php echo $quiz_btn_icon; ?> me-2"></i><?php echo $quiz_btn_label; ?>
                </a>
              </div>

              <!-- Lesson Topic Resources & Notes -->
              <div class="border rounded-3 p-4 bg-light mb-3">
                <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                  <i class="bi bi-file-earmark-text-fill text-primary fs-5"></i>
                  <span><?php echo __('topic_resources_notes', 'Topic Resources & Notes'); ?></span>
                </h6>
                <p class="text-secondary fs-7 mb-0 leading-relaxed" id="active-lesson-summary">
                  <?php echo htmlspecialchars($lessons[0]['content'] ?? 'Select a lesson activity from the syllabus to begin learning.'); ?>
                </p>
              </div>

              <!-- Supplementary Lesson Resources & Files Card -->
              <div class="border rounded-3 p-4 bg-white shadow-sm" id="active-lesson-resources-card">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                  <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-paperclip text-primary fs-5"></i>
                    <span><?php echo __('lesson_resources', 'Lesson Resources & Attachments'); ?></span>
                  </h6>
                  <span class="badge bg-light text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fs-9 fw-bold" id="active-resources-count-badge">
                    0 Files
                  </span>
                </div>
                <div id="active-lesson-resources-list" class="d-flex flex-column gap-2">
                  <!-- Rendered dynamically by renderActiveLessonResources() -->
                </div>
              </div>
            </div>

            <!-- Graded Quiz Content Block (Hidden by default) -->
            <div id="view-quiz" class="activity-view d-none">
              <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
                <div>
                  <h4 class="fw-bold text-dark mb-1">
                    <?php echo __('knowledge_assessment_quiz', 'Knowledge Assessment: Topic Quiz'); ?>
                  </h4>
                  <p class="text-muted fs-8 mb-0">
                    <?php echo __('quiz_subtitle', 'Evaluate your conceptual understanding of this syllabus chapter.'); ?>
                  </p>
                </div>
                <div id="quiz-status-badge">
                  <?php if ($quiz_score !== null): ?>
                    <span
                      class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-2 rounded-pill fs-8">
                      <i class="bi bi-patch-check-fill me-1"></i> <?php echo __('passed', 'Passed'); ?>:
                      <?php echo $quiz_score; ?>/<?php echo count($quiz_questions); ?>
                    </span>
                  <?php else: ?>
                    <span
                      class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-35 px-3 py-2 rounded-pill fs-8">
                      <i class="bi bi-exclamation-circle-fill me-1"></i>
                      <?php echo __('no_quiz_attempt', 'Not Attempted'); ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($is_admin || $is_teacher): ?>
                <!-- Admin / Teacher Quiz View Mode List -->
                <div class="d-flex flex-column gap-3">
                  <?php if (empty($quiz_questions)): ?>
                    <div class="text-center py-5">
                      <i class="bi bi-patch-question fs-1 text-muted mb-2"></i>
                      <h6 class="fw-bold">No quiz questions added yet</h6>
                    </div>
                  <?php else: ?>
                    <?php foreach ($quiz_questions as $q_idx => $question): ?>
                      <div class="card border-0 shadow-sm rounded-4 bg-white p-4 quiz-question-block"
                        data-question-id="<?php echo htmlspecialchars($question['question_id']); ?>"
                        data-lesson-id="<?php echo htmlspecialchars($question['lesson_id'] ?? ''); ?>">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                          <span class="badge bg-primary text-white rounded-pill px-3 py-1.5 fs-8 fw-bold">
                            Question <?php echo $q_idx + 1; ?> of <?php echo count($quiz_questions); ?>
                          </span>
                          <span
                            class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1 rounded-pill fs-9">
                            <i class="bi bi-shield-check me-1"></i>Answer Key Unlocked
                          </span>
                        </div>

                        <h6 class="fw-bold text-dark mb-3 leading-snug"><?php echo htmlspecialchars($question['question']); ?>
                        </h6>

                        <div class="d-flex flex-column gap-2">
                          <?php foreach ($question['options'] as $o_idx => $option): ?>
                            <?php if (trim($option) !== ''): ?>
                              <?php $is_correct_option = ($o_idx === intval($question['answer_index'])); ?>
                              <div
                                class="p-3 rounded-3 d-flex align-items-center justify-content-between border <?php echo $is_correct_option ? 'border-success bg-success bg-opacity-10 text-success fw-bold' : 'bg-light text-secondary'; ?>">
                                <div class="d-flex align-items-center gap-3">
                                  <span
                                    class="badge rounded-circle <?php echo $is_correct_option ? 'bg-success text-white' : 'bg-white text-muted border'; ?>"
                                    style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem;">
                                    <?php echo chr(65 + $o_idx); ?>
                                  </span>
                                  <span class="fs-7"><?php echo htmlspecialchars($option); ?></span>
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
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <!-- Quiz Form for Students -->
                <form id="course-quiz-form" data-course-id="<?php echo htmlspecialchars($course_id); ?>">
                  <?php if (empty($quiz_questions)): ?>
                    <div class="text-center py-5">
                      <i class="bi bi-patch-question fs-1 text-muted mb-2"></i>
                      <h6 class="fw-bold"><?php echo __('no_quiz_attempt', 'No questions added yet'); ?></h6>
                    </div>
                  <?php else: ?>
                    <?php foreach ($quiz_questions as $q_idx => $question): ?>
                      <div class="quiz-question-block mb-4 p-3 border rounded-3 bg-light"
                        data-question-id="<?php echo htmlspecialchars($question['question_id']); ?>"
                        data-lesson-id="<?php echo htmlspecialchars($question['lesson_id'] ?? ''); ?>">
                        <h6 class="fw-bold text-dark mb-3 d-flex gap-2 align-items-start">
                          <span
                            class="badge bg-primary text-white rounded-circle p-2 fs-8 me-1"><?php echo $q_idx + 1; ?></span>
                          <span><?php echo htmlspecialchars($question['question']); ?></span>
                        </h6>
                        <div class="options-container">
                          <?php foreach ($question['options'] as $o_idx => $option): ?>
                            <div
                              class="quiz-option d-flex align-items-center gap-3 border bg-white p-3 rounded-3 mb-2 cursor-pointer transition-all"
                              data-index="<?php echo $o_idx; ?>">
                              <span
                                class="badge rounded-circle bg-light border text-muted d-flex align-items-center justify-content-center"
                                style="width: 24px; height: 24px; font-size: 0.75rem;">
                                <?php echo chr(65 + $o_idx); ?>
                              </span>
                              <span class="text-secondary"><?php echo htmlspecialchars($option); ?></span>
                            </div>
                          <?php endforeach; ?>
                          <input type="hidden" name="answers[<?php echo htmlspecialchars($question['question_id']); ?>]"
                            class="selected-option-input" value="-1">
                        </div>
                      </div>
                    <?php endforeach; ?>

                    <div class="text-end">
                      <button type="submit" id="quiz-submit-btn"
                        class="btn btn-primary px-4 py-2 border-0 rounded-pill fw-semibold"
                        style="background-color: #0f4c81;" <?php echo ($quiz_score !== null) ? 'disabled' : ''; ?>>
                        <?php echo __('submit_quiz_attempt', 'Submit Quiz Attempt'); ?>
                      </button>
                    </div>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <!-- Right: Professional Course Syllabus Index (4 Columns) -->
        <div class="col-lg-4">
          <div class="moodle-card p-4 rounded-4 border shadow-sm bg-white">
            <!-- Sidebar Header: Title + action buttons -->
            <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
              <div>
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                  <i class="bi bi-collection-play-fill text-primary"></i> Course Syllabus
                </h5>
                <small class="text-muted fs-8"><?php echo count($lessons); ?> Modules Available</small>
              </div>
              <?php if ($can_manage): ?>
                <div class="d-flex align-items-center gap-1 ms-2">
                  <button
                    class="btn btn-outline-secondary py-1 px-2.5 fs-8 rounded-pill d-inline-flex align-items-center gap-1"
                    data-bs-toggle="modal" data-bs-target="#editCourseModal" title="Edit Course Details">
                    <i class="bi bi-pencil-square"></i> Edit
                  </button>
                  <button class="btn btn-outline-primary py-1 px-2.5 fs-8 rounded-pill d-inline-flex align-items-center gap-1 fw-semibold"
                    data-bs-toggle="modal" data-bs-target="#addLessonModal" title="Add Lesson">
                    <i class="bi bi-plus-circle-fill"></i> Add
                  </button>
                </div>
              <?php endif; ?>
            </div>

            <!-- Outline Sections -->
            <div class="d-flex flex-column gap-3">

              <!-- Topic 1: Lessons -->
              <div>
                <div class="mb-3">
                  <span class="fw-bold text-uppercase fs-8 text-primary tracking-wider">
                    <i class="bi bi-journal-bookmark me-1"></i>Syllabus Modules
                  </span>
                </div>

                <div class="d-flex flex-column gap-2" id="moodle-syllabus-lessons">
                  <?php if (empty($lessons)): ?>
                    <div class="p-4 border rounded-3 text-center text-muted fs-8 italic bg-light">
                      No lessons uploaded yet for this syllabus.
                    </div>
                  <?php else: ?>
                    <?php
                    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND status = 'finalized'");
                    $stmt->execute([$user_id, $course_id]);
                    $finalized_quiz_lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    $stmt = $pdo->prepare("SELECT lp.lesson_id FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE lp.user_id = ? AND l.course_id = ? AND (lp.completed = 1 OR lp.progress_percent >= 90)");
                    $stmt->execute([$user_id, $course_id]);
                    $watched_lessons = array_unique(array_merge($completed_lessons ?? [], $stmt->fetchAll(PDO::FETCH_COLUMN)));

                    $stmt = $pdo->prepare("SELECT DISTINCT lesson_id FROM quizzes WHERE course_id = ?");
                    $stmt->execute([$course_id]);
                    $lessons_with_quizzes = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    $unlocked_lessons = [];
                    foreach ($lessons as $index => $lesson) {
                      if ($is_teacher || $is_admin || $index === 0) {
                        $unlocked_lessons[] = $lesson['id'];
                      } else {
                        $prev_l = $lessons[$index - 1];
                        $prev_watched = in_array($prev_l['id'], $watched_lessons);
                        $prev_has_quiz = in_array($prev_l['id'], $lessons_with_quizzes);
                        $prev_quiz_done = !$prev_has_quiz || in_array($prev_l['id'], $finalized_quiz_lessons);

                        if ($prev_watched && $prev_quiz_done) {
                          $unlocked_lessons[] = $lesson['id'];
                        }
                      }
                    }
                    ?>
                    <?php foreach ($lessons as $index => $lesson):
                      $is_active = ($index === 0) ? 'active' : '';
                      $is_completed = in_array($lesson['id'], $completed_lessons);
                      $is_unlocked = in_array($lesson['id'], $unlocked_lessons);
                      ?>
                      <?php if ($is_unlocked): ?>
                        <div
                          class="lesson-outline-item cursor-pointer <?php echo $is_active; ?> <?php echo $is_completed ? 'completed' : ''; ?>"
                          data-lesson-id="<?php echo htmlspecialchars($lesson['id']); ?>"
                          data-lesson-title="<?php echo htmlspecialchars($lesson['title']); ?>"
                          data-lesson-content="<?php echo htmlspecialchars($lesson['content']); ?>"
                          data-lesson-video="<?php echo htmlspecialchars($lesson['video_url']); ?>"
                          data-lesson-duration="<?php echo htmlspecialchars($lesson['duration']); ?>">

                          <!-- Lesson info: number badge + title + duration -->
                          <div class="d-flex align-items-center gap-2 text-truncate" style="min-width:0; flex: 1 1 auto;">
                            <span
                              class="badge rounded-circle flex-shrink-0 <?php echo $is_active ? 'bg-primary text-white shadow-sm' : ($is_completed ? 'bg-success text-white' : 'bg-light text-secondary border'); ?>"
                              style="width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:700;">
                              <?php if ($is_completed && !$is_active): ?>
                                <i class="bi bi-check-lg"></i>
                              <?php else: ?>
                                <?php echo $index + 1; ?>
                              <?php endif; ?>
                            </span>
                            <div class="d-flex flex-column text-truncate" style="min-width:0;">
                              <span class="fs-8 text-dark fw-semibold text-truncate lesson-title-text">
                                <?php echo htmlspecialchars($lesson['title']); ?>
                              </span>
                              <div class="d-flex align-items-center gap-1 mt-0.5 flex-wrap">
                                <span class="text-muted" style="font-size:0.7rem;">
                                  <i class="bi bi-clock"></i> <?php echo htmlspecialchars($lesson['duration']); ?>
                                </span>
                                <?php 
                                $l_res_count = count($resources_by_lesson[$lesson['id']] ?? []);
                                if ($l_res_count > 0): 
                                ?>
                                  <span class="badge bg-light text-primary border border-primary border-opacity-25 py-0.5 px-1.5 rounded-pill" style="font-size: 0.65rem;" title="<?php echo $l_res_count; ?> Resources attached">
                                    <i class="bi bi-paperclip"></i> <?php echo $l_res_count; ?>
                                  </span>
                                <?php endif; ?>
                                <?php if ($index === 0): ?>
                                  <span class="now-playing-badge">PLAYING</span>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>

                          <!-- Right side: management actions or completion badge -->
                          <?php if ($can_manage): ?>
                            <div class="lesson-actions">
                              <a href="create_quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($lesson['id']); ?>&sid=<?php echo urlencode(session_id()); ?>"
                                class="btn btn-outline-warning rounded-pill text-dark"
                                title="Add / Edit Quiz" onclick="event.stopPropagation();">
                                <i class="bi bi-patch-question-fill text-primary"></i>
                              </a>
                              <button class="btn btn-outline-primary rounded-pill edit-lesson-btn-trigger"
                                data-lesson-id="<?php echo htmlspecialchars($lesson['id']); ?>"
                                data-lesson-title="<?php echo htmlspecialchars($lesson['title']); ?>"
                                data-lesson-duration="<?php echo htmlspecialchars($lesson['duration']); ?>"
                                data-lesson-video="<?php echo htmlspecialchars($lesson['video_url']); ?>"
                                data-lesson-content="<?php echo htmlspecialchars($lesson['content']); ?>"
                                data-bs-toggle="modal" data-bs-target="#editLessonModal"
                                onclick="event.stopPropagation();" title="Edit Lesson">
                                <i class="bi bi-pencil-square"></i>
                              </button>
                              <button class="btn btn-outline-danger rounded-pill delete-lesson-btn-trigger"
                                data-lesson-id="<?php echo htmlspecialchars($lesson['id']); ?>"
                                data-lesson-title="<?php echo htmlspecialchars($lesson['title']); ?>"
                                onclick="event.stopPropagation();" title="Delete Lesson">
                                <i class="bi bi-trash3"></i>
                              </button>
                            </div>
                          <?php elseif ($is_completed): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill flex-shrink-0" style="font-size:0.7rem;padding:3px 8px;">
                              <i class="bi bi-check-circle-fill"></i> Done
                            </span>
                          <?php endif; ?>
                        </div>

                      <?php else: ?>
                        <!-- Locked Lesson in Syllabus Outline -->
                        <div
                          class="lesson-outline-item locked opacity-60 transition-all d-flex align-items-center justify-content-between text-muted bg-light"
                          style="cursor: not-allowed;"
                          title="<?php echo __('locked_previous_required', 'Locked - Complete previous lesson first'); ?>"
                          data-bs-toggle="tooltip">
                          <div class="d-flex align-items-center gap-3 text-truncate">
                            <span class="badge rounded-circle bg-light text-muted border"
                              style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.82rem; font-weight: 700; flex-shrink: 0;">
                              <i class="bi bi-lock-fill fs-8"></i>
                            </span>
                            <div class="d-flex flex-column text-truncate">
                              <span
                                class="fs-7 text-muted fw-semibold text-truncate"><?php echo htmlspecialchars($lesson['title']); ?></span>
                              <span class="text-muted fs-8"><i
                                  class="bi bi-clock me-1"></i><?php echo htmlspecialchars($lesson['duration']); ?></span>
                            </div>
                          </div>
                          <span
                            class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1 fs-9 rounded-pill text-nowrap ms-2">
                            <i class="bi bi-lock-fill me-1"></i>Locked
                          </span>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Direct Quiz Card in Sidebar -->
              <div class="p-3.5 bg-light rounded-3 border text-center mt-3">
                <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
                  <i class="bi bi-award-fill fs-5 text-warning"></i>
                  <h6 class="fw-bold mb-0 text-dark"><?php echo __('ready_for_quiz', 'Ready for the Quiz?'); ?></h6>
                </div>
                <p class="text-muted fs-8 mb-3">
                  <?php echo __('quiz_sidebar_desc', 'Test your knowledge across all syllabus modules.'); ?>
                </p>
                <?php
                  $first_lesson_param_sb = !empty($lessons[0]['id']) ? '&lesson_id=' . urlencode($lessons[0]['id']) : '';
                  $sid_param_sb = '&sid=' . urlencode(session_id());
                  if ($is_teacher) {
                    $sidebar_quiz_href = 'create_quiz.php?course_id=' . urlencode($course_id) . $first_lesson_param_sb . $sid_param_sb;
                    $sidebar_quiz_label = __('manage_quiz', 'Manage Quiz');
                  } else {
                    $sidebar_quiz_href = 'quiz.php?course_id=' . urlencode($course_id) . $first_lesson_param_sb . $admin_preview_param . $sid_param_sb;
                    $sidebar_quiz_label = __('enter_quiz', 'Enter Quiz');
                  }
                ?>
                <a href="<?php echo $sidebar_quiz_href; ?>"
                  class="btn btn-primary w-100 rounded-pill border-0 py-2 fs-8 fw-semibold shadow-sm enter-quiz-btn"
                  id="sidebar-enter-quiz-btn"
                  style="background-color: #0f4c81;">
                  <i class="bi bi-patch-question-fill me-1.5"></i><?php echo $sidebar_quiz_label; ?>
                </a>
              </div>

            </div>
          </div>
        </div>

      </div>

    </div>
  </main>

  <!-- Modals for Course Management -->
  <?php if ($can_manage): ?>
    <!-- Edit Course Modal -->
    <div class="modal fade text-dark" id="editCourseModal" tabindex="-1" aria-labelledby="editCourseModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title fw-bold" id="editCourseModalLabel"><i
                class="bi bi-pencil-square text-primary me-2"></i>Edit Course Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="edit-course-form" enctype="multipart/form-data">
            <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id); ?>">
            <div class="modal-body">
              <div class="mb-3">
                <label for="edit-course-title" class="form-label fw-semibold text-secondary">Course Title</label>
                <input type="text" class="form-control" id="edit-course-title" name="title"
                  value="<?php echo htmlspecialchars($current_course['title']); ?>" required>
              </div>

              <div class="mb-3">
                <label for="edit-course-category" class="form-label fw-semibold text-secondary">Category</label>
                <select id="edit-course-category" class="form-select" name="category" required>
                  <option value="Computer Science" <?php echo $current_course['category'] === 'Computer Science' ? 'selected' : ''; ?>>Computer Science</option>
                  <option value="Programming" <?php echo ($current_course['category'] === 'Programming' || $current_course['category'] === 'Coding') ? 'selected' : ''; ?>>Programming</option>
                  <option value="Software Engineering" <?php echo $current_course['category'] === 'Software Engineering' ? 'selected' : ''; ?>>Software Engineering</option>
                  <option value="Web Development" <?php echo $current_course['category'] === 'Web Development' ? 'selected' : ''; ?>>Web Development</option>
                  <option value="Artificial Intelligence" <?php echo $current_course['category'] === 'Artificial Intelligence' ? 'selected' : ''; ?>>Artificial Intelligence</option>
                  <option value="Cyber Security" <?php echo $current_course['category'] === 'Cyber Security' ? 'selected' : ''; ?>>Cyber Security</option>
                </select>
              </div>

              <?php
              $selected_auds = array_map('trim', explode(',', $current_course['target_audience'] ?? ''));
              ?>
              <div class="mb-3">
                <label
                  class="form-label fw-semibold text-secondary d-flex justify-content-between align-items-center mb-1">
                  <span>Target Audience</span>
                  <small class="text-muted fw-normal">Select all that apply</small>
                </label>
                <div id="edit-audience-checkbox-container" class="border rounded p-2.5 bg-white mb-2"
                  style="max-height: 140px; overflow-y: auto;">
                  <?php foreach ($active_target_audiences as $aud): ?>
                    <?php
                    $aud_id = 'aud-edit-' . substr(md5($aud['name']), 0, 8);
                    $is_checked = in_array(trim($aud['name']), $selected_auds);
                    ?>
                    <div class="form-check mb-1">
                      <input class="form-check-input edit-course-audience-checkbox" type="checkbox"
                        value="<?php echo htmlspecialchars($aud['name']); ?>" id="<?php echo $aud_id; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                      <label class="form-check-label fs-7 cursor-pointer" for="<?php echo $aud_id; ?>">
                        <?php echo htmlspecialchars(__($aud['name'], $aud['name'])); ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="input-group input-group-sm">
                  <input type="text" id="edit-add-new-audience-input" class="form-control"
                    placeholder="Add new target audience...">
                  <button class="btn btn-outline-primary fw-semibold" type="button" id="edit-btn-add-new-audience">
                    <i class="bi bi-plus-lg me-0.5"></i> Add
                  </button>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary d-block">Course Price</label>
                <div class="d-flex align-items-center gap-3">
                  <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="edit-price-toggle" <?php echo floatval($current_course['price']) == 0 ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-medium text-dark" for="edit-price-toggle"
                      id="edit-price-toggle-label"><?php echo floatval($current_course['price']) == 0 ? 'Free Course' : 'Paid Course'; ?></label>
                  </div>
                  <div class="input-group flex-grow-1" id="edit-price-input-container"
                    style="<?php echo floatval($current_course['price']) == 0 ? 'display: none;' : 'display: flex;'; ?>">
                    <span class="input-group-text">Rs.</span>
                    <input type="number" id="edit-course-price" class="form-control" name="price" placeholder="0.00"
                      min="0" step="0.01" value="<?php echo floatval($current_course['price']); ?>">
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-6 mb-3">
                  <label for="edit-course-level" class="form-label fw-semibold text-secondary">Level</label>
                  <select id="edit-course-level" class="form-select" name="level" required>
                    <option value="Beginner" <?php echo $current_course['level'] === 'Beginner' ? 'selected' : ''; ?>>
                      Beginner</option>
                    <option value="Intermediate" <?php echo $current_course['level'] === 'Intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                    <option value="Advanced" <?php echo $current_course['level'] === 'Advanced' ? 'selected' : ''; ?>>
                      Advanced</option>
                  </select>
                </div>
                <div class="col-6 mb-3">
                  <label for="edit-course-duration" class="form-label fw-semibold text-secondary">Duration (Weeks)</label>
                  <input type="number" id="edit-course-duration" class="form-control" name="duration"
                    value="<?php echo intval($current_course['duration']); ?>" min="1" required>
                </div>
              </div>

              <div class="mb-3">
                <label for="edit-course-long-desc" class="form-label fw-semibold text-secondary">Course
                  Description</label>
                <textarea id="edit-course-long-desc" class="form-control" name="long_description" rows="4"
                  required><?php echo htmlspecialchars($current_course['long_description']); ?></textarea>
              </div>

              <div class="mb-3">
                <label for="edit-course-thumbnail-file" class="form-label fw-semibold text-secondary">Course Thumbnail Image</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                  <img src="<?php echo htmlspecialchars($current_course['thumbnail'] ?? 'assets/placeholder.jpg'); ?>" class="rounded-3 border shadow-sm" style="width: 100px; height: 60px; object-fit: cover;" alt="Current Thumbnail">
                  <small class="text-muted">Current thumbnail shown above. Upload a new image below to change it.</small>
                </div>
                <input type="file" id="edit-course-thumbnail-file" class="form-control" name="thumbnail" accept="image/*">
                <small class="text-muted">Accepted formats: JPG, PNG, WebP (Leave empty to keep current)</small>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" style="background-color: #0f4c81;">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Lesson Modal -->
    <div class="modal fade text-dark" id="editLessonModal" tabindex="-1" aria-labelledby="editLessonModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="editLessonModalLabel">
              <i class="bi bi-pencil-square text-primary me-1"></i><?php echo __('edit_lesson', 'Edit Lesson'); ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="edit-lesson-form" enctype="multipart/form-data">
            <input type="hidden" id="edit-lesson-id" name="lesson_id">
            <div class="modal-body py-3">
              <div class="row g-3">
                <div class="col-md-8">
                  <label for="edit-lesson-title" class="form-label fw-semibold text-secondary">Lesson Title</label>
                  <input type="text" class="form-control" id="edit-lesson-title" name="title" required>
                </div>
                <div class="col-md-4">
                  <label for="edit-lesson-duration" class="form-label fw-semibold text-secondary">Duration</label>
                  <input type="text" class="form-control" id="edit-lesson-duration" name="duration" required>
                </div>
                <div class="col-12">
                  <label for="edit-lesson-video" class="form-label fw-semibold text-secondary">Video URL (YouTube or MP4)</label>
                  <input type="text" class="form-control" id="edit-lesson-video" name="video_url" required>
                </div>
                <div class="col-12">
                  <label for="edit-lesson-content" class="form-label fw-semibold text-secondary">Lesson Content / Notes</label>
                  <textarea class="form-control" id="edit-lesson-content" name="content" rows="3" required></textarea>
                </div>
                
                <!-- Currently Attached Resources Section -->
                <div class="col-12">
                  <label class="form-label fw-semibold text-secondary d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-paperclip text-primary me-1"></i><?php echo __('attached_files', 'Attached Supplementary Files'); ?></span>
                    <span class="badge bg-light text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5 fs-9" id="edit-lesson-resources-count">0 Files</span>
                  </label>
                  <div id="edit-lesson-resources-container" class="border rounded-3 p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                    <div class="text-center py-2 text-muted fs-8 fst-italic">Loading attached files...</div>
                  </div>
                </div>

                <!-- Upload Additional Files -->
                <div class="col-12">
                  <label for="edit-lesson-attachments" class="form-label fw-semibold text-secondary d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-cloud-arrow-up text-primary me-1"></i><?php echo __('upload_additional_resources', 'Upload Additional Files'); ?></span>
                    <small class="text-muted fw-normal">Optional</small>
                  </label>
                  <input type="file" class="form-control" id="edit-lesson-attachments" name="attachments[]" multiple
                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.webp,.zip,.rar">
                  <div class="form-text fs-9 text-muted mt-1">
                    <?php echo __('supported_file_types_hint', 'Accepted formats: PDF, Word, PPT, Excel, Images, ZIP up to 50MB per file.'); ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer border-0 pt-0">
              <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #0f4c81;">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Lesson Modal -->
    <div class="modal fade text-dark" id="addLessonModal" tabindex="-1" aria-labelledby="addLessonModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="addLessonModalLabel">
              <i class="bi bi-journal-plus text-primary me-1"></i><?php echo __('add_lesson', 'Add New Lesson'); ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="add-lesson-form" enctype="multipart/form-data">
            <div class="modal-body py-3">
              <div class="row g-3">
                <div class="col-md-8">
                  <label for="lesson-title" class="form-label fw-semibold text-secondary">Lesson Title</label>
                  <input type="text" class="form-control" id="lesson-title" name="title" placeholder="e.g. Lesson 5: Advanced Loops" required>
                </div>
                <div class="col-md-4">
                  <label for="lesson-duration" class="form-label fw-semibold text-secondary">Duration</label>
                  <input type="text" class="form-control" id="lesson-duration" name="duration" placeholder="e.g. 15 mins" required>
                </div>
                <div class="col-12">
                  <label for="lesson-video" class="form-label fw-semibold text-secondary">Video URL (YouTube or MP4)</label>
                  <input type="text" class="form-control" id="lesson-video" name="video_url" placeholder="e.g. uploads/class.mp4 or https://www.youtube.com/watch?v=...">
                </div>
                <div class="col-12">
                  <label for="lesson-content" class="form-label fw-semibold text-secondary">Lesson Content / Notes</label>
                  <textarea class="form-control" id="lesson-content" name="content" rows="3"
                    placeholder="Enter lesson transcription or textual learning notes..." required></textarea>
                </div>
                
                <!-- Lesson Attachments Field -->
                <div class="col-12">
                  <label for="lesson-attachments" class="form-label fw-semibold text-secondary d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-paperclip text-primary me-1"></i><?php echo __('upload_resources', 'Lesson Attachments / Resources'); ?></span>
                    <small class="text-muted fw-normal">Optional</small>
                  </label>
                  <input type="file" class="form-control" id="lesson-attachments" name="attachments[]" multiple
                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.webp,.zip,.rar">
                  <div class="form-text fs-9 text-muted mt-1">
                    <?php echo __('supported_file_types_hint', 'Accepted formats: PDF, Word, PPT, Excel, Images, ZIP up to 50MB per file.'); ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer border-0 pt-0">
              <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #0f4c81;">Save Lesson</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Quiz Question Modal -->
    <div class="modal fade text-dark" id="addQuizQuestionModal" tabindex="-1" aria-labelledby="addQuizQuestionModalLabel"
      aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title fw-bold" id="addQuizQuestionModalLabel"><i
                class="bi bi-patch-question text-primary me-2"></i>Add Quiz Question</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form id="add-quiz-form">
            <div class="modal-body">
              <div class="mb-3">
                <label for="quiz-question" class="form-label fw-semibold text-secondary">Question Text</label>
                <input type="text" class="form-control" id="quiz-question" placeholder="e.g. What does PDO stand for?"
                  required>
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold text-secondary">Options (Provide 4 options)</label>
                <div class="input-group mb-2">
                  <span class="input-group-text">A</span>
                  <input type="text" class="form-control" id="quiz-opt1" placeholder="Option A" required>
                </div>
                <div class="input-group mb-2">
                  <span class="input-group-text">B</span>
                  <input type="text" class="form-control" id="quiz-opt2" placeholder="Option B" required>
                </div>
                <div class="input-group mb-2">
                  <span class="input-group-text">C</span>
                  <input type="text" class="form-control" id="quiz-opt3" placeholder="Option C" required>
                </div>
                <div class="input-group mb-2">
                  <span class="input-group-text">D</span>
                  <input type="text" class="form-control" id="quiz-opt4" placeholder="Option D" required>
                </div>
              </div>
              <div class="mb-3">
                <label for="quiz-answer" class="form-label fw-semibold text-secondary">Correct Answer</label>
                <select class="form-select" id="quiz-answer" required>
                  <option value="0">Option A</option>
                  <option value="1">Option B</option>
                  <option value="2">Option C</option>
                  <option value="3">Option D</option>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" style="background-color: #0f4c81;">Save Question</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Delete Course Confirmation Modal -->
  <div class="modal fade text-start" id="deleteCourseModal" tabindex="-1" aria-labelledby="deleteCourseModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2" id="deleteCourseModalLabel">
            <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i> Delete Course Confirmation
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body py-3">
          <p class="fs-7 text-secondary mb-2">
            Are you sure you want to permanently delete course <strong id="delete-course-title-display"
              class="text-dark"></strong>?
          </p>
          <p class="fs-8 text-danger mb-0"><i class="bi bi-info-circle me-1"></i> This action cannot be undone and will
            permanently remove all associated lessons, quizzes, and student enrollments.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm"
            id="confirm-delete-course-btn">
            <i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete Lesson Confirmation Modal -->
  <div class="modal fade text-start" id="deleteLessonModal" tabindex="-1" aria-labelledby="deleteLessonModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2" id="deleteLessonModalLabel">
            <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i> Delete Lesson Confirmation
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body py-3">
          <p class="fs-7 text-secondary mb-2">
            Are you sure you want to delete lesson <strong id="delete-lesson-title-display" class="text-dark"></strong>?
          </p>
          <p class="fs-8 text-muted mb-0"><i class="bi bi-info-circle me-1"></i> This action cannot be undone.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm"
            id="confirm-delete-lesson-btn">
            <i class="bi bi-trash3-fill me-1"></i> Yes, Delete Lesson
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Setup JS variables for script referencing -->
  <script>
    window.COURSE_ID = "<?php echo htmlspecialchars($course_id); ?>";
    window.COMPLETED_LESSONS = <?php echo json_encode($completed_lessons); ?>;
    window.UNLOCKED_LESSONS = <?php echo json_encode($unlocked_lessons ?? []); ?>;
    window.LESSON_PROGRESS = <?php echo json_encode($lesson_progress); ?>;
    window.QUIZ_SCORE = <?php echo json_encode($quiz_score); ?>;
    window.HAS_ACCESS = <?php echo json_encode($has_access); ?>;
    window.COURSE_PRICE = <?php echo json_encode(floatval($current_course['price'])); ?>;
    window.IS_TEACHER = <?php echo json_encode($is_teacher); ?>;
    window.IS_ADMIN = <?php echo json_encode($is_admin); ?>;
    window.LESSON_RESOURCES = <?php echo json_encode($resources_by_lesson ?? []); ?>;
  </script>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>



  <!-- Render JS Translation Dictionary -->
  <?php render_i18n_js(); ?>

  <!-- Classroom specific JS -->
  <script src="assets/js/classroom.js"></script>

  <?php if ($can_manage): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        // Target Audience helper for Edit Course form
        function getSelectedEditTargetAudiences() {
          const checkboxes = document.querySelectorAll('.edit-course-audience-checkbox:checked');
          return Array.from(checkboxes).map(cb => cb.value.trim()).filter(val => val.length > 0).join(', ');
        }

        // Add New Target Audience button in Edit Course modal
        const btnEditAddAudience = document.getElementById('edit-btn-add-new-audience');
        const inputEditNewAudience = document.getElementById('edit-add-new-audience-input');
        if (btnEditAddAudience && inputEditNewAudience) {
          btnEditAddAudience.addEventListener('click', function () {
            const newAudName = inputEditNewAudience.value.trim();
            if (!newAudName) {
              alert('Please enter a target audience name.');
              inputEditNewAudience.focus();
              return;
            }

            btnEditAddAudience.disabled = true;
            fetch('api/add_target_audience.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ name: newAudName })
            })
              .then(res => res.json())
              .then(data => {
                btnEditAddAudience.disabled = false;
                if (data.success) {
                  const container = document.getElementById('edit-audience-checkbox-container');
                  const audId = 'aud-edit-' + Math.random().toString(36).substring(2, 10);

                  // Create new checkbox element
                  const div = document.createElement('div');
                  div.className = 'form-check mb-1';
                  div.innerHTML = `
                    <input class="form-check-input edit-course-audience-checkbox" type="checkbox" value="${data.name}" id="${audId}" checked>
                    <label class="form-check-label fs-7 cursor-pointer" for="${audId}">
                      ${data.name}
                    </label>
                  `;
                  container.appendChild(div);
                  container.scrollTop = container.scrollHeight;
                  inputEditNewAudience.value = '';
                } else {
                  alert(data.message || 'Failed to add target audience.');
                }
              })
              .catch(err => {
                btnEditAddAudience.disabled = false;
                console.error('Error adding target audience:', err);
                alert('Server error adding target audience.');
              });
          });
        }

        // Price Toggle Logic in Edit Course modal
        const editPriceToggle = document.getElementById('edit-price-toggle');
        const editPriceToggleLabel = document.getElementById('edit-price-toggle-label');
        const editPriceInputContainer = document.getElementById('edit-price-input-container');
        const editCoursePriceInput = document.getElementById('edit-course-price');

        if (editPriceToggle) {
          editPriceToggle.addEventListener('change', function () {
            if (this.checked) {
              editPriceToggleLabel.textContent = 'Free Course';
              editPriceInputContainer.style.display = 'none';
              editCoursePriceInput.value = '0.00';
              editCoursePriceInput.required = false;
            } else {
              editPriceToggleLabel.textContent = 'Paid Course';
              editPriceInputContainer.style.display = 'flex';
              editCoursePriceInput.required = true;
              editCoursePriceInput.focus();
            }
          });
        }

        // Edit Course form submit AJAX handler
        const editCourseForm = document.getElementById('edit-course-form');
        if (editCourseForm) {
          editCourseForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = editCourseForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Saving...';

            const formData = new FormData(editCourseForm);
            formData.set('target_audience', getSelectedEditTargetAudiences());
            if (editPriceToggle && editPriceToggle.checked) {
              formData.set('price', '0.00');
            }

            fetch('api/edit_course.php', {
              method: 'POST',
              body: formData
            })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  alert('Course details updated successfully!');
                  location.reload();
                } else {
                  alert('Failed to update course: ' + data.message);
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = 'Save Changes';
                }
              })
              .catch(err => {
                console.error(err);
                alert('Server connection error.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save Changes';
              });
          });
        }

        // Helper: File Icon Class by extension
        function getResourceIconClass(ext) {
          ext = (ext || '').toLowerCase();
          if (ext === 'pdf') return 'bi-file-earmark-pdf-fill text-danger';
          if (['doc', 'docx'].includes(ext)) return 'bi-file-earmark-word-fill text-primary';
          if (['ppt', 'pptx'].includes(ext)) return 'bi-file-earmark-slides-fill text-warning';
          if (['xls', 'xlsx'].includes(ext)) return 'bi-file-earmark-excel-fill text-success';
          if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) return 'bi-file-earmark-image-fill text-info';
          if (['zip', 'rar', '7z'].includes(ext)) return 'bi-file-earmark-zip-fill text-secondary';
          return 'bi-file-earmark-arrow-down-fill text-primary';
        }

        // Global Helper: Render Active Lesson Supplementary Resources
        window.renderActiveLessonResources = function(lessonId) {
          const listContainer = document.getElementById('active-lesson-resources-list');
          const countBadge = document.getElementById('active-resources-count-badge');
          if (!listContainer) return;

          const resources = (window.LESSON_RESOURCES && window.LESSON_RESOURCES[lessonId]) ? window.LESSON_RESOURCES[lessonId] : [];
          if (countBadge) {
            countBadge.textContent = resources.length + (resources.length === 1 ? ' File' : ' Files');
          }

          if (resources.length === 0) {
            const noMsg = (typeof window.i18n__ === 'function') ? window.i18n__('no_resources_attached', 'No supplementary files attached to this lesson.') : 'No supplementary files attached to this lesson.';
            listContainer.innerHTML = `<div class="text-muted fs-8 py-2 text-center fst-italic"><i class="bi bi-info-circle me-1"></i>${noMsg}</div>`;
            return;
          }

          const viewLabel = (typeof window.i18n__ === 'function') ? window.i18n__('view_file', 'View') : 'View';
          const dlLabel = (typeof window.i18n__ === 'function') ? window.i18n__('download_file', 'Download') : 'Download';

          let html = '';
          resources.forEach(r => {
            const iconCls = getResourceIconClass(r.file_type);
            const isViewable = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt'].includes((r.file_type || '').toLowerCase());
            
            html += `
              <div class="d-flex align-items-center justify-content-between p-2.5 bg-light rounded-3 border transition-all hover-shadow-sm">
                <div class="d-flex align-items-center gap-3 text-truncate me-2">
                  <i class="bi ${iconCls} fs-3 flex-shrink-0"></i>
                  <div class="d-flex flex-column text-truncate">
                    <span class="fw-semibold text-dark fs-8 text-truncate" title="${r.file_name}">${r.file_name}</span>
                    <small class="text-muted fs-9">${r.formatted_size || (r.file_size + ' B')} &bull; <span class="text-uppercase">${r.file_type}</span></small>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                  ${isViewable ? `
                    <a href="download_resource.php?id=${r.id}&view=1" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                      <i class="bi bi-eye me-1"></i>${viewLabel}
                    </a>
                  ` : ''}
                  <a href="download_resource.php?id=${r.id}&download=1" class="btn btn-sm btn-primary rounded-pill px-2.5 py-1 fs-9 fw-bold text-white shadow-sm" style="background-color: #0f4c81;">
                    <i class="bi bi-cloud-arrow-down-fill me-1"></i>${dlLabel}
                  </a>
                </div>
              </div>
            `;
          });

          listContainer.innerHTML = html;
        };

        // Initialize active lesson resources on load
        <?php if (!empty($lessons[0]['id'])): ?>
          window.renderActiveLessonResources("<?php echo htmlspecialchars($lessons[0]['id']); ?>");
        <?php endif; ?>

        // Helper: Load existing attachments for Edit Lesson modal
        function loadLessonResourcesForEdit(lessonId) {
          const container = document.getElementById('edit-lesson-resources-container');
          const countBadge = document.getElementById('edit-lesson-resources-count');
          if (!container) return;

          container.innerHTML = '<div class="text-center py-2 text-muted fs-8 fst-italic"><span class="spinner-border spinner-border-sm me-1"></span> Loading attached files...</div>';

          fetch(`api/get_lesson_resources.php?lesson_id=${encodeURIComponent(lessonId)}`)
            .then(res => res.json())
            .then(data => {
              if (data.success && data.resources) {
                const resources = data.resources;
                if (countBadge) countBadge.textContent = resources.length + (resources.length === 1 ? ' File' : ' Files');

                // Update client-side cache
                if (!window.LESSON_RESOURCES) window.LESSON_RESOURCES = {};
                window.LESSON_RESOURCES[lessonId] = resources;

                if (resources.length === 0) {
                  container.innerHTML = '<div class="text-center py-2 text-muted fs-8 fst-italic">No attached files currently for this lesson.</div>';
                  return;
                }

                let html = '<div class="d-flex flex-column gap-2">';
                resources.forEach(r => {
                  const iconCls = getResourceIconClass(r.file_type);
                  html += `
                    <div class="d-flex align-items-center justify-content-between p-2 bg-white rounded-2 border" id="edit-res-item-${r.id}">
                      <div class="d-flex align-items-center gap-2 text-truncate me-2">
                        <i class="bi ${iconCls} fs-4 flex-shrink-0"></i>
                        <div class="text-truncate">
                          <div class="fs-8 fw-semibold text-dark text-truncate">${r.file_name}</div>
                          <small class="text-muted fs-9">${r.formatted_size} &bull; <span class="text-uppercase">${r.file_type}</span></small>
                        </div>
                      </div>
                      <button type="button" class="btn btn-outline-danger btn-sm rounded-circle p-1 d-flex align-items-center justify-content-center btn-delete-attached-resource" 
                        data-resource-id="${r.id}" data-lesson-id="${lessonId}" style="width: 28px; height: 28px;" title="Remove Attachment">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </div>
                  `;
                });
                html += '</div>';
                container.innerHTML = html;

                // Bind delete resource buttons
                container.querySelectorAll('.btn-delete-attached-resource').forEach(delBtn => {
                  delBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const resId = this.getAttribute('data-resource-id');
                    const lesId = this.getAttribute('data-lesson-id');
                    const confirmMsg = (typeof window.i18n__ === 'function') ? window.i18n__('delete_resource_confirm', 'Are you sure you want to remove this attachment?') : 'Are you sure you want to remove this attachment?';
                    
                    if (!confirm(confirmMsg)) return;

                    delBtn.disabled = true;
                    delBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                    fetch('api/delete_resource.php', {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json' },
                      body: JSON.stringify({ resource_id: resId })
                    })
                    .then(res => res.json())
                    .then(delData => {
                      if (delData.success) {
                        const itemEl = document.getElementById(`edit-res-item-${resId}`);
                        if (itemEl) itemEl.remove();
                        
                        // Reload resources for edit modal and active card
                        loadLessonResourcesForEdit(lesId);
                        window.renderActiveLessonResources(lesId);
                      } else {
                        alert(delData.message || 'Failed to remove attachment.');
                        delBtn.disabled = false;
                        delBtn.innerHTML = '<i class="bi bi-trash3"></i>';
                      }
                    })
                    .catch(err => {
                      console.error(err);
                      alert('Server error removing attachment.');
                      delBtn.disabled = false;
                      delBtn.innerHTML = '<i class="bi bi-trash3"></i>';
                    });
                  });
                });

              } else {
                container.innerHTML = '<div class="text-center py-2 text-danger fs-8">Failed to load attachments.</div>';
              }
            })
            .catch(err => {
              console.error(err);
              container.innerHTML = '<div class="text-center py-2 text-danger fs-8">Error loading attachments.</div>';
            });
        }

        // Edit Lesson Modal Populate
        document.querySelectorAll('.edit-lesson-btn-trigger').forEach(btn => {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const lid = this.getAttribute('data-lesson-id');
            const ltitle = this.getAttribute('data-lesson-title');
            const lduration = this.getAttribute('data-lesson-duration');
            const lvideo = this.getAttribute('data-lesson-video');
            const lcontent = this.getAttribute('data-lesson-content');

            const idInput = document.getElementById('edit-lesson-id');
            const titleInput = document.getElementById('edit-lesson-title');
            const durInput = document.getElementById('edit-lesson-duration');
            const vidInput = document.getElementById('edit-lesson-video');
            const contInput = document.getElementById('edit-lesson-content');
            const attachInput = document.getElementById('edit-lesson-attachments');

            if (idInput) idInput.value = lid || '';
            if (titleInput) titleInput.value = ltitle || '';
            if (durInput) durInput.value = lduration || '';
            if (vidInput) vidInput.value = lvideo || '';
            if (contInput) contInput.value = lcontent || '';
            if (attachInput) attachInput.value = '';

            if (lid) {
              loadLessonResourcesForEdit(lid);
            }
          });
        });

        // Edit Lesson Form Submit (Multipart / FormData)
        const editLessonForm = document.getElementById('edit-lesson-form');
        if (editLessonForm) {
          editLessonForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = editLessonForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Saving...';

            const formData = new FormData(editLessonForm);
            formData.set('course_id', "<?php echo htmlspecialchars($course_id); ?>");

            fetch('api/edit_lesson.php', {
              method: 'POST',
              body: formData
            })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  alert('Lesson updated successfully!');
                  location.reload();
                } else {
                  alert('Failed to update lesson: ' + data.message);
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = 'Save Changes';
                }
              })
              .catch(err => {
                console.error(err);
                alert('Server connection error.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save Changes';
              });
          });
        }

        // Add Lesson form submit (Multipart / FormData)
        const addLessonForm = document.getElementById('add-lesson-form');
        if (addLessonForm) {
          addLessonForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = addLessonForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Saving...';

            const formData = new FormData(addLessonForm);
            formData.set('course_id', "<?php echo htmlspecialchars($course_id); ?>");

            fetch('api/create_lesson.php', {
              method: 'POST',
              body: formData
            })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  alert('Lesson added successfully!');
                  location.reload();
                } else {
                  alert('Failed to add lesson: ' + data.message);
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = 'Save Lesson';
                }
              })
              .catch(err => {
                console.error(err);
                alert('Server connection error.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save Lesson';
              });
          });
        }

        // Add Quiz form submit
        const addQuizForm = document.getElementById('add-quiz-form');
        if (addQuizForm) {
          addQuizForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = addQuizForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Saving...';

            fetch('api/manage_quiz.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                course_id: "<?php echo htmlspecialchars($course_id); ?>",
                question: document.getElementById('quiz-question').value,
                option_1: document.getElementById('quiz-opt1').value,
                option_2: document.getElementById('quiz-opt2').value,
                option_3: document.getElementById('quiz-opt3').value,
                option_4: document.getElementById('quiz-opt4').value,
                answer_index: document.getElementById('quiz-answer').value
              })
            })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  alert('Quiz question added successfully!');
                  location.reload();
                } else {
                  alert('Failed to add question: ' + data.message);
                  submitBtn.disabled = false;
                  submitBtn.innerHTML = 'Save Question';
                }
              })
              .catch(err => {
                console.error(err);
                alert('Server connection error.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save Question';
              });
          });
        }
      });

      // Delete Course Handler
      let courseToDeleteId = null;
      const deleteCourseModal = document.getElementById('deleteCourseModal') ? new bootstrap.Modal(document.getElementById('deleteCourseModal')) : null;
      const courseTitleDisplay = document.getElementById('delete-course-title-display');
      const confirmDeleteCourseBtn = document.getElementById('confirm-delete-course-btn');

      document.querySelectorAll('.delete-course-btn-trigger').forEach(btn => {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          courseToDeleteId = this.getAttribute('data-course-id');
          const title = this.getAttribute('data-course-title');
          if (courseTitleDisplay) courseTitleDisplay.textContent = title;
          if (deleteCourseModal) deleteCourseModal.show();
        });
      });

      if (confirmDeleteCourseBtn) {
        confirmDeleteCourseBtn.addEventListener('click', function () {
          if (!courseToDeleteId) return;
          confirmDeleteCourseBtn.disabled = true;
          confirmDeleteCourseBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';

          fetch('api/delete_course.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ course_id: courseToDeleteId })
          })
            .then(res => res.json())
            .then(data => {
              if (data.success) {
                if (data.action === 'soft_deleted') {
                  alert(data.message || 'This course has active students. It has been unpublished from the catalog. You have 14 days to restore it.');
                }
                window.location.href = 'my_courses.php';
              } else {
                alert(data.message || 'Error deleting course.');
                confirmDeleteCourseBtn.disabled = false;
                confirmDeleteCourseBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course';
              }
            })
            .catch(err => {
              console.error(err);
              alert('Server connection error.');
              confirmDeleteCourseBtn.disabled = false;
              confirmDeleteCourseBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course';
            });
        });
      }

      // Delete Lesson Handler
      let lessonToDeleteId = null;
      const deleteLessonModal = document.getElementById('deleteLessonModal') ? new bootstrap.Modal(document.getElementById('deleteLessonModal')) : null;
      const lessonTitleDisplay = document.getElementById('delete-lesson-title-display');
      const confirmDeleteLessonBtn = document.getElementById('confirm-delete-lesson-btn');

      document.querySelectorAll('.delete-lesson-btn-trigger').forEach(btn => {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          lessonToDeleteId = this.getAttribute('data-lesson-id');
          const title = this.getAttribute('data-lesson-title');
          if (lessonTitleDisplay) lessonTitleDisplay.textContent = title;
          if (deleteLessonModal) deleteLessonModal.show();
        });
      });

      if (confirmDeleteLessonBtn) {
        confirmDeleteLessonBtn.addEventListener('click', function () {
          if (!lessonToDeleteId) return;
          confirmDeleteLessonBtn.disabled = true;
          confirmDeleteLessonBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';

          fetch('api/delete_lesson.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: lessonToDeleteId })
          })
            .then(res => res.json())
            .then(data => {
              if (data.success) {
                window.location.reload();
              } else {
                alert(data.message || 'Error deleting lesson.');
                confirmDeleteLessonBtn.disabled = false;
                confirmDeleteLessonBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Lesson';
              }
            })
            .catch(err => {
              console.error(err);
              alert('Server connection error.');
              confirmDeleteLessonBtn.disabled = false;
              confirmDeleteLessonBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Lesson';
            });
        });
      }
    </script>
  <?php endif; ?>
  <script>
    function switchLanguage(lang) {
      fetch('api/set_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lang: lang })
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            location.reload();
          } else {
            window.location.href = 'api/set_language.php?lang=' + lang;
          }
        })
        .catch(err => {
          window.location.href = 'api/set_language.php?lang=' + lang;
        });
    }

    function markNotificationsAsRead() {
      fetch('api/read_notifications.php')
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const badge = document.getElementById('notification-badge');
            if (badge) badge.remove();
            const count = document.getElementById('notification-count');
            if (count) count.remove();
          }
        })
  </script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
</body>

</html>