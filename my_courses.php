<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$is_teacher = false;

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();

    if (!$student) {
        // Session user not found, clear and redirect
        session_destroy();
        header("Location: login.php");
        exit;
    }

    if ($student['role'] === 'teacher' && strtolower($student['status'] ?? 'active') !== 'active') {
        header("Location: pending_approval.php");
        exit;
    }

    $is_teacher = (($student['role'] ?? 'student') === 'teacher');
    $is_admin = in_array($student['role'] ?? 'student', ['admin', 'super_admin']);

    if ($is_teacher) {
        // Fetch courses taught/uploaded by this teacher
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE tutor_id = ?");
        $stmt->execute([$user_id]);
        $courses = $stmt->fetchAll();
    } else {
        // Fetch enrolled courses for the student
        $stmt = $pdo->prepare("SELECT c.* FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE e.user_id = ?");
        $stmt->execute([$user_id]);
        $courses = $stmt->fetchAll();

        // Fetch existing certificate requests for this student
        $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $cert_requests_data = $stmt->fetchAll();
        $cert_requests_map = [];
        foreach ($cert_requests_data as $cr) {
            $cert_requests_map[$cr['course_id']] = $cr;
        }
    }

    // Fetch all courses for sidebar navigation (approved courses, or pending if owner/admin)
    if ($is_admin) {
        $stmt = $pdo->query("SELECT * FROM courses");
        $all_courses = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE status = 'approved' OR tutor_id = ?");
        $stmt->execute([$user_id]);
        $all_courses = $stmt->fetchAll();
    }

    // Get list of enrolled course IDs for student sidebar
    $enrolled_ids = [];
    if (!$is_teacher && !$is_admin) {
        $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Fetch notifications for all roles (students & teachers)
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $is_teacher ? 'Uploaded Courses' : 'My Courses'; ?> | Computerscience.lk</title>
  <script src="assets/js/session_manager.js"></script>
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
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
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .no-caret::after {
      display: none !important;
    }
  </style>
</head>
<body class="bg-light">

  <!-- Moodle Top Header Bar -->
  <header class="moodle-header px-3 px-md-4 shadow-sm">
    <div class="d-flex align-items-center w-100 justify-content-between">
      
      <!-- Left: Toggle button + Brand -->
      <div class="d-flex align-items-center gap-3">
        <button id="drawer-toggle" class="btn btn-light border-0 rounded-circle p-2 fs-5 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
          <i class="bi bi-list"></i>
        </button>
        <a class="moodle-brand fw-bold text-decoration-none fs-4 d-flex align-items-center" href="index.php" style="color: #0f4c81;">
          <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2" style="height: 32px; width: auto; object-fit: contain;">computerscience.lk
        </a>
      </div>

      <!-- Center: Main Navbar links -->
      <nav class="d-none d-lg-flex align-items-center gap-2">
        <a href="index.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_home', 'Site Home'); ?></a>
        <a href="dashboard.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_dashboard', 'Dashboard'); ?></a>
        <a href="my_courses.php" class="btn btn-light text-primary fw-bold px-3"><?php echo $is_teacher ? __('nav_uploaded_courses', 'Uploaded Courses') : __('nav_my_courses', 'My Courses'); ?></a>
        <?php if (isset($_SESSION['user_id'])): ?>
          <a href="live_classes.php" class="btn btn-light px-3 text-danger fw-semibold d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-broadcast text-danger fs-7"></i>
            <span>Live Classes</span>
          </a>
        <?php endif; ?>
      </nav>

      <!-- Right: Actions, Notifications, Profiles -->
      <div class="d-flex align-items-center gap-2.5">
        <!-- Language Switcher Dropdown -->
        <div class="dropdown">
          <button class="btn btn-sm btn-light border text-secondary dropdown-toggle d-flex align-items-center gap-1.5 rounded-pill px-2.5 py-1" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-globe text-primary fs-7"></i>
            <span class="fw-semibold fs-8"><?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'සිංහල' : 'English'; ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-1" aria-labelledby="langDropdown">
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'en') ? 'active fw-bold' : ''; ?>" href="#" onclick="switchLanguage('en'); return false;">
                <span>English</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'en'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'active fw-bold' : ''; ?>" href="#" onclick="switchLanguage('si'); return false;">
                <span>සිංහල</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'si'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
          </ul>
        </div>

        <!-- Notification Dropdown -->
        <div class="dropdown">
          <button class="text-secondary fs-5 border-0 bg-transparent p-2 position-relative dropdown-toggle no-caret" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotificationsAsRead()">
            <i class="bi bi-bell"></i>
            <?php if ($unread_count > 0): ?>
              <span class="position-absolute top-1 end-1 translate-middle badge rounded-circle bg-danger" id="notification-badge" style="padding: 4px; font-size: 0.5rem;">
                <?php echo $unread_count; ?>
              </span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light py-2" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 400px; overflow-y: auto; z-index: 1050;">
            <li class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2 d-flex justify-content-between align-items-center">
              <span><?php echo __('notifications', 'Notifications'); ?></span>
              <?php if ($unread_count > 0): ?>
                <span class="badge bg-primary text-white fs-9" id="notification-count"><?php echo $unread_count; ?> new</span>
              <?php endif; ?>
            </li>
            <?php if (empty($notifications)): ?>
              <li class="px-3 py-4 text-center text-muted fs-8 italic"><?php echo __('no_notifications', 'No notifications yet.'); ?></li>
            <?php else: ?>
              <?php foreach ($notifications as $notif): ?>
                <li class="px-3 py-2 border-bottom last-border-0 <?php echo $notif['is_read'] ? 'opacity-70' : 'bg-light bg-opacity-50 fw-semibold'; ?>">
                  <div class="fs-8 text-dark mb-1"><?php echo htmlspecialchars($notif['message']); ?></div>
                  <small class="text-muted fs-9"><i class="bi bi-clock me-1"></i><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></small>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </div>
        <div class="dropdown">
          <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <img src="<?php echo htmlspecialchars(get_user_avatar($student['avatar'], $student['name'])); ?>" class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover;" alt="Profile">
            <span class="d-none d-md-inline text-secondary fw-semibold text-sm"><?php echo htmlspecialchars(explode(' ', $student['name'])[0]); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light">
            <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> <?php echo __('nav_profile', 'Profile'); ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> <?php echo __('nav_logout', 'Logout'); ?></a></li>
          </ul>
        </div>
      </div>

    </div>
  </header>

  <!-- Moodle Left Navigation Drawer -->
  <aside id="moodle-drawer" class="moodle-drawer collapsed">
    <div class="d-flex flex-column">
      <a href="index.php" class="drawer-link">
        <i class="bi bi-house-door fs-5"></i> <?php echo __('nav_home', 'Site Home'); ?>
      </a>
      <a href="dashboard.php" class="drawer-link">
        <i class="bi bi-speedometer2 fs-5"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
      </a>
      <hr class="mx-3 my-2 border-secondary border-opacity-20">
      <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider"><?php echo $is_teacher ? __('courses_i_teach', 'Courses I Teach') : __('nav_my_courses', 'My Courses'); ?></div>
      <?php 
      $enrolled_any = false;
      if ($is_teacher) {
          foreach ($all_courses as $cs_course) {
              if (intval($cs_course['tutor_id']) === intval($user_id)) {
                  $enrolled_any = true;
                  echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                        </a>';
              }
          }
          if (!$enrolled_any) {
              echo '<div class="px-4 py-2 fs-8 text-muted italic">' . __('no_courses_created_yet', 'No courses created yet') . '</div>';
          }
      } else {
          foreach ($all_courses as $cs_course) {
              if (in_array($cs_course['id'], $enrolled_ids)) {
                  $enrolled_any = true;
                  echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                        </a>';
              }
          }
          if (!$enrolled_any) {
              echo '<div class="px-4 py-2 fs-8 text-muted italic">' . __('no_enrolled_courses', 'No enrolled courses') . '</div>';
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
          <li class="breadcrumb-item"><a href="index.php"><?php echo __('nav_home', 'Home'); ?></a></li>
          <li class="breadcrumb-item"><a href="dashboard.php"><?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
          <li class="breadcrumb-item active" aria-current="page"><?php echo $is_teacher ? __('uploaded_courses', 'Uploaded Courses') : __('my_enrolled_courses', 'My Courses'); ?></li>
        </ol>
      </nav>

      <!-- Page title -->
      <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="fw-bold text-dark mb-0 fs-3"><?php echo $is_teacher ? __('uploaded_courses', 'Uploaded Courses') : __('my_enrolled_courses', 'My Enrolled Courses'); ?></h1>
        <?php if ($is_teacher): ?>
          <a href="dashboard.php" class="btn btn-primary btn-sm px-3 border-0 d-inline-flex align-items-center gap-1" style="background-color: #0f4c81;">
            <i class="bi bi-plus-circle-fill"></i> <?php echo __('add_new_course', 'Add New Course'); ?>
          </a>
        <?php else: ?>
          <a href="index.php#courses-section" class="btn btn-primary btn-sm px-3 border-0" style="background-color: #0f4c81;">
            <i class="bi bi-search me-1"></i> <?php echo __('nav_courses', 'Course Catalog'); ?>
          </a>
        <?php endif; ?>
      </div>

      <!-- Course Cards Grid -->
      <div class="row g-4">
        <div class="col-12">
          <div class="moodle-card p-4">
            <h4 class="fw-bold text-dark border-bottom pb-2 mb-4 fs-5">
              <i class="bi bi-collection-play me-2 text-primary"></i><?php echo $is_teacher ? __('courses_you_uploaded', 'Courses You Uploaded') : __('your_enrolled_modules', 'Your Enrolled Modules'); ?>
            </h4>
            
            <?php if (empty($courses)): ?>
              <div class="text-center py-5">
                <i class="bi bi-journal-x fs-1 text-muted mb-3"></i>
                <h5 class="fw-bold"><?php echo __('no_courses_found', 'No courses found'); ?></h5>
                <p class="text-muted mb-4"><?php echo $is_teacher ? __('no_uploaded_courses_msg', 'You have not uploaded any courses yet.') : __('no_enrolled_courses_msg', 'You are not enrolled in any academic courses.'); ?></p>
                <?php if ($is_teacher): ?>
                  <a href="dashboard.php" class="btn btn-primary" style="background-color: #0f4c81;"><?php echo __('create_a_course', 'Create a Course'); ?></a>
                <?php else: ?>
                  <a href="index.php#courses-section" class="btn btn-primary" style="background-color: #0f4c81;"><?php echo __('explore_courses', 'Explore Courses'); ?></a>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="row g-4">
                <?php foreach ($courses as $course): 
                  $ratingStars = '';
                  $ratingVal = (float)$course['rating'];
                  for ($i = 0; $i < 5; $i++) {
                      if ($i < floor($ratingVal)) {
                          $ratingStars .= '<i class="bi bi-star-fill text-warning"></i>';
                      } else {
                          $ratingStars .= '<i class="bi bi-star text-secondary"></i>';
                      }
                  }

                  // Progress & Certificate calculation for student
                  $progress_percent = 0;
                  $is_course_100 = false;
                  $comp_date_display = date('M d, Y');
                  $quiz_score_str = "Progress: 100% | No Quiz Required";
                  $existing_cert = null;
                  $cert_json = '{}';

                  if (!$is_teacher) {
                      $lessonsStmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id = ?");
                      $lessonsStmt->execute([$course['id']]);
                      $course_lesson_ids = $lessonsStmt->fetchAll(PDO::FETCH_COLUMN);
                      $total_lessons = count($course_lesson_ids);

                      $completed_in_course = 0;
                      $latest_comp_date = null;
                      if ($total_lessons > 0) {
                          $in_les = implode(',', array_fill(0, $total_lessons, '?'));
                          $compStmt = $pdo->prepare("SELECT lesson_id FROM completed_lessons WHERE user_id = ? AND lesson_id IN ($in_les)");
                          $compStmt->execute(array_merge([$user_id], $course_lesson_ids));
                          $cl_ids = $compStmt->fetchAll(PDO::FETCH_COLUMN);

                          $progStmt = $pdo->prepare("SELECT lesson_id, progress_percent, completed, updated_at FROM lesson_progress WHERE user_id = ? AND lesson_id IN ($in_les)");
                          $progStmt->execute(array_merge([$user_id], $course_lesson_ids));
                          $prog_rows = $progStmt->fetchAll();

                          $watched_ids = [];
                          foreach ($prog_rows as $pr) {
                              if ($pr['completed'] == 1 || (float)$pr['progress_percent'] >= 90) {
                                  $watched_ids[] = $pr['lesson_id'];
                              }
                              if (!empty($pr['updated_at'])) {
                                  if (!$latest_comp_date || strtotime($pr['updated_at']) > strtotime($latest_comp_date)) {
                                      $latest_comp_date = $pr['updated_at'];
                                  }
                              }
                          }
                          $all_done_ids = array_unique(array_merge($cl_ids, $watched_ids));
                          $completed_in_course = count($all_done_ids);
                      }

                      $progress_percent = $total_lessons > 0 ? min(100, round(($completed_in_course / $total_lessons) * 100)) : 0;
                      $is_course_100 = ($total_lessons > 0 && $completed_in_course >= $total_lessons);

                      // Quiz summary
                      $qStmt = $pdo->prepare("SELECT * FROM quiz_results WHERE user_id = ? AND course_id = ?");
                      $qStmt->execute([$user_id, $course['id']]);
                      $c_quiz_res = $qStmt->fetch();

                      $qaStmt = $pdo->prepare("SELECT MAX(score) as best_score, MAX(total_questions) as total_questions, MAX(updated_at) as last_attempt_at FROM quiz_attempts WHERE user_id = ? AND course_id = ?");
                      $qaStmt->execute([$user_id, $course['id']]);
                      $c_quiz_attempt = $qaStmt->fetch();

                      if ($c_quiz_res && (int)($c_quiz_res['total_questions'] ?? 0) > 0) {
                          $qScore = (int)$c_quiz_res['score'];
                          $qTotal = (int)$c_quiz_res['total_questions'];
                          $qPct = round(($qScore / $qTotal) * 100);
                          $quiz_score_str = "Progress: 100% | Final Quiz Marks: {$qScore}/{$qTotal} ({$qPct}%)";
                          if (!empty($c_quiz_res['updated_at']) && (!$latest_comp_date || strtotime($c_quiz_res['updated_at']) > strtotime($latest_comp_date))) {
                              $latest_comp_date = $c_quiz_res['updated_at'];
                          }
                      } elseif ($c_quiz_attempt && (int)($c_quiz_attempt['total_questions'] ?? 0) > 0) {
                          $qScore = (int)$c_quiz_attempt['best_score'];
                          $qTotal = (int)$c_quiz_attempt['total_questions'];
                          $qPct = round(($qScore / $qTotal) * 100);
                          $quiz_score_str = "Progress: 100% | Final Quiz Marks: {$qScore}/{$qTotal} ({$qPct}%)";
                          if (!empty($c_quiz_attempt['last_attempt_at']) && (!$latest_comp_date || strtotime($c_quiz_attempt['last_attempt_at']) > strtotime($latest_comp_date))) {
                              $latest_comp_date = $c_quiz_attempt['last_attempt_at'];
                          }
                      }

                      $comp_date_display = $latest_comp_date ? date('M d, Y', strtotime($latest_comp_date)) : date('M d, Y');
                      $existing_cert = $cert_requests_map[$course['id']] ?? null;

                      $cert_json = htmlspecialchars(json_encode([
                          'course_id' => $course['id'],
                          'course_title' => $course['title'],
                          'registered_email' => $student['email'] ?? '',
                          'student_name' => $student['name'] ?? '',
                          'full_name_on_certificate' => $existing_cert['full_name_on_certificate'] ?? $student['name'] ?? '',
                          'nic_number' => $existing_cert['nic_number'] ?? '',
                          'mobile_number' => $existing_cert['mobile_number'] ?? '',
                          'completion_date' => $comp_date_display,
                          'progress_score_summary' => $quiz_score_str,
                          'delivery_method' => $existing_cert['delivery_method'] ?? 'digital_only',
                          'delivery_address' => $existing_cert['delivery_address'] ?? '',
                          'city' => $existing_cert['city'] ?? '',
                          'postal_code' => $existing_cert['postal_code'] ?? '',
                          'district' => $existing_cert['district'] ?? '',
                          'delivery_notes' => $existing_cert['delivery_notes'] ?? '',
                          'status' => $existing_cert['status'] ?? '',
                          'certificate_code' => $existing_cert['certificate_code'] ?? '',
                          'certificate_image' => $existing_cert['certificate_image'] ?? '',
                          'admin_notes' => $existing_cert['admin_notes'] ?? '',
                          'created_at' => $existing_cert['created_at'] ?? '',
                          'updated_at' => $existing_cert['updated_at'] ?? ''
                      ]), ENT_QUOTES, 'UTF-8');
                  }
                ?>
                  <div class="col-md-6 col-lg-6 d-flex mb-2">
                    <div class="card moodle-card border-0 w-100 d-flex flex-column justify-content-between overflow-hidden shadow-sm h-100 bg-white">
                      <div class="position-relative">
                        <img src="<?php echo htmlspecialchars($course['thumbnail']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($course['title']); ?>" style="height: 160px; object-fit: cover;">
                        <?php if ($is_teacher && ($course['status'] ?? 'approved') === 'pending'): ?>
                          <span class="position-absolute top-3 start-3 badge bg-warning text-dark border border-warning px-3 py-1.5 rounded-pill fw-bold shadow-sm">
                            <i class="bi bi-clock-history me-1"></i> Pending Admin Approval
                          </span>
                        <?php endif; ?>
                        <span class="position-absolute top-3 end-3 badge bg-white text-dark border rounded-pill px-3 py-1">
                          <?php echo htmlspecialchars($course['level']); ?>
                        </span>
                      </div>
                      <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                          <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-primary fs-7 fw-bold"><i class="bi bi-tag-fill me-1"></i><?php echo htmlspecialchars($course['category']); ?></span>
                            <span class="text-muted fs-7"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($course['duration']); ?> Weeks</span>
                          </div>
                          <h5 class="card-title fw-bold text-dark mb-2 line-clamp-2" style="font-size: 1.1rem; min-height: 2.8rem; line-height: 1.3;">
                            <?php echo htmlspecialchars($course['title']); ?>
                          </h5>
                          <p class="card-text text-muted text-sm mb-4 line-clamp-3" style="font-size: 0.85rem; min-height: 3.5rem;">
                            <?php echo htmlspecialchars($course['short_description']); ?>
                          </p>

                          <?php if (!$is_teacher): ?>
                            <!-- Progress Bar -->
                            <div class="d-flex align-items-center gap-2 mb-3">
                              <div class="progress flex-grow-1" style="height: 6px;">
                                <div class="progress-bar rounded <?php echo $is_course_100 ? 'bg-success' : 'bg-primary'; ?>" role="progressbar" style="width: <?php echo $progress_percent; ?>%;"></div>
                              </div>
                              <span class="text-dark fs-8 fw-bold"><?php echo $progress_percent; ?>%</span>
                            </div>
                          <?php endif; ?>
                        </div>
                        
                        <div>
                          <hr class="my-3">
                          
                          <!-- Tutor Meta -->
                          <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="d-flex align-items-center gap-2">
                              <img src="<?php echo htmlspecialchars($course['tutor_avatar']); ?>" class="rounded-circle border border-primary border-opacity-20" alt="<?php echo htmlspecialchars($course['tutor_name']); ?>" style="width: 32px; height: 32px; object-fit: cover;">
                              <div>
                                <h6 class="text-dark mb-0" style="font-size: 0.8rem; font-weight: 600;"><?php echo htmlspecialchars($course['tutor_name']); ?></h6>
                                <small class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($course['tutor_title'] ?? 'Lecturer'); ?></small>
                              </div>
                            </div>
                            
                            <div class="text-end">
                              <div class="text-warning" style="font-size: 0.75rem;">
                                <?php echo $ratingStars; ?>
                              </div>
                              <small class="text-muted" style="font-size: 0.7rem;">(<?php echo htmlspecialchars($course['review_count']); ?>)</small>
                            </div>
                          </div>
                          
                          <!-- Action Buttons -->
                          <?php if ($is_teacher): ?>
                            <div class="d-flex gap-2">
                              <a href="classroom.php?course_id=<?php echo urlencode($course['id']); ?>" class="btn btn-outline-success rounded-pill flex-fill py-2 d-flex align-items-center justify-content-center gap-1.5 fs-7 fw-semibold">
                                <i class="bi bi-pencil-square"></i> Manage
                              </a>
                              <button type="button" class="btn btn-outline-danger rounded-pill px-3 py-2 d-flex align-items-center justify-content-center gap-1.5 fs-7 fw-semibold delete-course-btn" 
                                      data-course-id="<?php echo htmlspecialchars($course['id']); ?>" 
                                      data-course-title="<?php echo htmlspecialchars($course['title']); ?>" 
                                      title="Delete Course">
                                <i class="bi bi-trash3-fill"></i> Delete
                              </button>
                            </div>
                          <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                              <a href="classroom.php?course_id=<?php echo urlencode($course['id']); ?>" class="btn btn-outline-primary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-semibold">
                                <i class="bi bi-play-circle-fill"></i> <?php echo __('continue_learning', 'Enter Classroom'); ?>
                              </a>

                              <?php if ($is_course_100): ?>
                                <?php if ($existing_cert): ?>
                                  <?php if (in_array($existing_cert['status'], ['approved', 'issued'])): ?>
                                    <button type="button" class="btn btn-success rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-bold shadow-sm text-white"
                                      onclick='openCertificateStatusModal(<?php echo $cert_json; ?>)'>
                                      <i class="bi bi-patch-check-fill"></i> <?php echo __('certificate_issued', 'Certificate Issued'); ?>
                                    </button>
                                  <?php elseif ($existing_cert['status'] === 'dispatched'): ?>
                                    <button type="button" class="btn btn-info text-white rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-bold shadow-sm"
                                      onclick='openCertificateStatusModal(<?php echo $cert_json; ?>)'>
                                      <i class="bi bi-truck"></i> <?php echo __('certificate_dispatched', 'Certificate Dispatched'); ?>
                                    </button>
                                  <?php elseif ($existing_cert['status'] === 'processing'): ?>
                                    <button type="button" class="btn btn-primary text-white rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-bold shadow-sm"
                                      onclick='openCertificateStatusModal(<?php echo $cert_json; ?>)'>
                                      <i class="bi bi-gear-wide-connected"></i> <?php echo __('certificate_processing', 'Certificate Processing'); ?>
                                    </button>
                                  <?php else: ?>
                                    <button type="button" class="btn btn-warning bg-opacity-25 text-dark border-warning rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-bold shadow-sm"
                                      onclick='openCertificateStatusModal(<?php echo $cert_json; ?>)'>
                                      <i class="bi bi-clock-history"></i> <?php echo __('certificate_requested', 'Certificate Requested'); ?>
                                    </button>
                                  <?php endif; ?>
                                <?php else: ?>
                                  <button type="button" class="btn btn-success rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7 fw-bold shadow-sm text-white"
                                    style="background-color: #28a745; border-color: #28a745;"
                                    onclick='openCertificateModal(<?php echo $cert_json; ?>)'>
                                    <i class="bi bi-award-fill"></i> <?php echo __('request_certificate', 'Request Certificate'); ?>
                                  </button>
                                <?php endif; ?>
                              <?php else: ?>
                                <button type="button" class="btn btn-light border text-muted rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2 fs-7"
                                  disabled title="<?php echo __('certificate_locked_tip', 'Complete 100% of course lessons & quizzes to unlock your certificate.'); ?>">
                                  <i class="bi bi-lock-fill text-secondary"></i> <?php echo __('request_certificate', 'Request Certificate'); ?>
                                </button>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

    </div>
  </main>

  <!-- Footer -->
  <footer class="py-4 bg-dark text-white-50 border-t mt-5">
    <div class="container text-center">
      <p class="mb-1 text-white">Computerscience.lk LMS</p>
      <p class="fs-7 mb-0">Powered by Moodle Core UI Framework re-design &copy; 2026.</p>
    </div>
  </footer>

  <!-- Local Bootstrap 5 Bundle JS & Libraries -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/html2canvas.min.js"></script>
  <script src="assets/js/jspdf.umd.min.js"></script>
  
  <!-- Switch Language Helper Script -->
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
  </script>
  <!-- Navigation Drawer Toggle script -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const toggleBtn = document.getElementById('drawer-toggle');
      const drawer = document.getElementById('moodle-drawer');
      const wrapper = document.getElementById('moodle-content-wrapper');
      
      toggleBtn.addEventListener('click', function() {
        drawer.classList.toggle('collapsed');
        wrapper.classList.toggle('full-width');
      });
    });

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
        .catch(err => console.error('Error marking notifications read:', err));
    }
  </script>

  <!-- Delete Course Confirmation Modal -->
  <div class="modal fade text-start" id="deleteCourseModal" tabindex="-1" aria-labelledby="deleteCourseModalLabel" aria-hidden="true">
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
            Are you sure you want to permanently delete course <strong id="delete-course-title-display" class="text-dark"></strong>?
          </p>
          <p class="fs-8 text-danger mb-0"><i class="bi bi-info-circle me-1"></i> This action cannot be undone and will permanently remove all associated lessons, quizzes, and enrollments.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" id="confirm-delete-course-btn">
            <i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    let selectedCourseId = null;
    const deleteCourseModal = new bootstrap.Modal(document.getElementById('deleteCourseModal'));
    const titleDisplay = document.getElementById('delete-course-title-display');
    const confirmBtn = document.getElementById('confirm-delete-course-btn');

    document.querySelectorAll('.delete-course-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        selectedCourseId = this.getAttribute('data-course-id');
        const courseTitle = this.getAttribute('data-course-title');
        titleDisplay.textContent = courseTitle;
        deleteCourseModal.show();
      });
    });

    if (confirmBtn) {
      confirmBtn.addEventListener('click', function() {
        if (!selectedCourseId) return;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';

        fetch('api/delete_course.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ course_id: selectedCourseId })
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            window.location.reload();
          } else {
            alert(data.message || 'Error deleting course.');
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course';
          }
        })
        .catch(err => {
          console.error(err);
          alert('An unexpected error occurred while deleting course.');
          confirmBtn.disabled = false;
          confirmBtn.innerHTML = '<i class="bi bi-trash3-fill me-1"></i> Yes, Delete Course';
        });
      });
    }
  <!-- Certificate Request Modal -->
  <div class="modal fade" id="certificateRequestModal" tabindex="-1" aria-labelledby="certificateRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
        <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); color: #ffffff;">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle bg-warning bg-opacity-20 p-2 text-warning d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
              <i class="bi bi-award-fill fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="certificateRequestModalLabel"><?php echo __('certificate_application', 'Official Course Certificate Application'); ?></h5>
              <small class="text-white-50 fs-9">Computerscience.lk Verified Academic Credential</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="certificate-request-form">
          <div class="modal-body p-4 bg-light">
            
            <div id="cert-form-alert" class="d-none alert mb-3 py-2 px-3 fs-8"></div>

            <!-- Auto-Filled Academic Metrics (Read-Only) -->
            <div class="bg-white p-3.5 rounded-4 border mb-3 shadow-xs">
              <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom text-primary fw-bold fs-8">
                <i class="bi bi-patch-check-fill text-success fs-6"></i>
                <span>Verified Academic & Course Credentials (Auto-Filled)</span>
              </div>
              
              <input type="hidden" id="cert-modal-course-id" name="course_id">

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1">Course Title</label>
                  <input type="text" id="cert-modal-course-title" class="form-control form-control-sm bg-light fw-bold text-dark border" readonly>
                </div>
                <div class="col-md-6">
                  <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1">Registered Email Address</label>
                  <input type="email" id="cert-modal-email" class="form-control form-control-sm bg-light text-secondary border" readonly>
                </div>
                <div class="col-md-6">
                  <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1">Completion Date</label>
                  <input type="text" id="cert-modal-completion-date" class="form-control form-control-sm bg-light text-dark border" readonly>
                </div>
                <div class="col-md-6">
                  <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1">Course Progress & Quiz Score</label>
                  <input type="text" id="cert-modal-progress-score" class="form-control form-control-sm bg-light text-success fw-bold border" readonly>
                </div>
              </div>
            </div>

            <!-- Student Identity Information (Editable) -->
            <div class="bg-white p-3.5 rounded-4 border mb-3 shadow-xs">
              <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom text-dark fw-bold fs-8">
                <i class="bi bi-person-badge-fill text-primary fs-6"></i>
                <span>Student Identity Verification</span>
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">
                    <?php echo __('full_name_on_certificate', 'Full Name on Certificate'); ?> <span class="text-danger">*</span>
                  </label>
                  <input type="text" id="cert-modal-fullname" name="full_name_on_certificate" class="form-control form-control-sm" required placeholder="e.g. Johnathan Alexander Perera">
                  <small class="text-muted fs-9 d-block mt-1"><i class="bi bi-info-circle me-1"></i>Ensure your name is spelled exactly as you want it printed on your official certificate.</small>
                </div>

                <div class="col-md-6">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">
                    <?php echo __('nic_number', 'National Identity Card (NIC)'); ?> <span class="text-danger">*</span>
                  </label>
                  <input type="text" id="cert-modal-nic" name="nic_number" class="form-control form-control-sm" required placeholder="e.g. 200012345678 or 987654321V">
                </div>

                <div class="col-md-6">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">
                    <?php echo __('mobile_number', 'Mobile / Contact Number'); ?> <span class="text-danger">*</span>
                  </label>
                  <input type="tel" id="cert-modal-mobile" name="mobile_number" class="form-control form-control-sm" required placeholder="e.g. +94 77 123 4567">
                </div>
              </div>
            </div>

            <!-- Certificate Obtaining & Delivery Options -->
            <div class="bg-white p-3.5 rounded-4 border shadow-xs">
              <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom text-dark fw-bold fs-8">
                <i class="bi bi-truck text-warning fs-6"></i>
                <span><?php echo __('delivery_method', 'Certificate Obtaining Method'); ?></span>
              </div>

              <div class="d-flex flex-column gap-2 mb-3">
                <!-- Option 1: Digital Copy Only -->
                <div class="form-check p-3 border rounded-3 bg-light cursor-pointer hover:bg-white transition-all">
                  <input class="form-check-input ms-0 me-2.5" type="radio" name="delivery_method" id="delivery-method-digital" value="digital_only" checked onchange="toggleDeliveryFields()">
                  <label class="form-check-label fw-semibold text-dark cursor-pointer d-flex flex-column" for="delivery-method-digital">
                    <span class="d-flex align-items-center gap-1.5"><i class="bi bi-file-earmark-pdf-fill text-danger"></i> <?php echo __('digital_copy_only', 'Digital Copy Only (PDF e-Certificate)'); ?></span>
                    <small class="text-muted fw-normal fs-9 mt-0.5">High-resolution PDF certificate with QR verification code emailed immediately upon approval.</small>
                  </label>
                </div>

                <!-- Option 2: Home Delivery -->
                <div class="form-check p-3 border rounded-3 bg-light cursor-pointer hover:bg-white transition-all">
                  <input class="form-check-input ms-0 me-2.5" type="radio" name="delivery_method" id="delivery-method-home" value="home_delivery" onchange="toggleDeliveryFields()">
                  <label class="form-check-label fw-semibold text-dark cursor-pointer d-flex flex-column" for="delivery-method-home">
                    <span class="d-flex align-items-center gap-1.5"><i class="bi bi-box-seam-fill text-success"></i> <?php echo __('printed_home_delivery', 'Printed Hard Copy (Delivered to Home Address)'); ?></span>
                    <small class="text-muted fw-normal fs-9 mt-0.5">Official embossed parchment certificate dispatched to your doorstep via registered courier.</small>
                  </label>
                </div>
              </div>

              <!-- Dynamic Home Delivery Form Fields -->
              <div id="home-delivery-details" style="display: none;" class="p-3 bg-light rounded-3 border mt-3">
                <h6 class="fw-bold text-dark fs-8 mb-3"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Postal Delivery Address Details</h6>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('delivery_address', 'Delivery Address (Street / House)'); ?> <span class="text-danger">*</span></label>
                    <textarea id="cert-modal-address" class="form-control form-control-sm" rows="2" placeholder="No, Street Name, Apartment / Floor..."></textarea>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('city', 'City / Town'); ?> <span class="text-danger">*</span></label>
                    <input type="text" id="cert-modal-city" class="form-control form-control-sm" placeholder="e.g. Colombo">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('postal_code', 'Postal Code'); ?> <span class="text-danger">*</span></label>
                    <input type="text" id="cert-modal-postal" class="form-control form-control-sm" placeholder="e.g. 00500">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('district', 'District / Province'); ?> <span class="text-danger">*</span></label>
                    <input type="text" id="cert-modal-district" class="form-control form-control-sm" placeholder="e.g. Colombo District">
                  </div>
                  <div class="col-12">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('delivery_notes', 'Special Delivery Notes (Optional)'); ?></label>
                    <input type="text" id="cert-modal-notes" class="form-control form-control-sm" placeholder="e.g. Contact before arrival or leave with security">
                  </div>
                </div>
              </div>

            </div>

          </div>
          <div class="modal-footer bg-white border-top py-3 px-4">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal"><?php echo __('cancel', 'Cancel'); ?></button>
            <button type="submit" id="btn-submit-cert-request" class="btn btn-primary rounded-pill px-4 py-2 fw-bold text-white shadow-sm d-flex align-items-center gap-2" style="background-color: #0f4c81;">
              <i class="bi bi-send-fill"></i>
              <span><?php echo __('submit_certificate_request', 'Submit Certificate Request'); ?></span>
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Professional Certificate Application Status & Credential Tracker Modal -->
  <div class="modal fade" id="certificateStatusModal" tabindex="-1" aria-labelledby="certificateStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
        
        <!-- Modal Header -->
        <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); color: #ffffff;">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle bg-warning bg-opacity-20 p-2 text-warning d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
              <i class="bi bi-shield-check fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="certificateStatusModalLabel"><?php echo __('certificate_status_tracker', 'Certificate Application Status & Tracker'); ?></h5>
              <small class="text-white-50 fs-9">Institutional Academic Credential Verification System</small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4 bg-light">
          
          <!-- Reference Code Header Bar -->
          <div class="d-flex flex-wrap justify-content-between align-items-center bg-white p-3 rounded-4 border mb-3 shadow-xs">
            <div>
              <small class="text-muted fs-9 text-uppercase fw-bold d-block">Credential Reference Code</small>
              <span class="font-mono fs-7 fw-bold text-primary" id="tracker-cert-code">CERT-CSLK-00000000</span>
            </div>
            <div class="text-end">
              <small class="text-muted fs-9 text-uppercase fw-bold d-block">Application Date</small>
              <span class="fs-8 fw-semibold text-dark" id="tracker-submitted-at">Aug 18, 2026</span>
            </div>
          </div>

          <!-- Dynamic Status Hero Banner -->
          <div id="tracker-status-hero" class="p-3.5 rounded-4 border mb-3 shadow-xs text-start">
            <!-- Dynamically populated by JS -->
          </div>

          <!-- 4-Stage Visual Progress Stepper -->
          <div class="bg-white p-4 rounded-4 border mb-3 shadow-xs">
            <h6 class="fw-bold text-dark fs-8 mb-3 d-flex align-items-center gap-1.5">
              <i class="bi bi-diagram-3-fill text-primary"></i>
              <span>Credential Processing Timeline</span>
            </h6>

            <div class="d-flex justify-content-between align-items-center position-relative my-2 px-2">
              <!-- Stepper Connecting Line -->
              <div class="position-absolute top-50 start-0 translate-y-middle w-100 bg-secondary bg-opacity-20" style="height: 3px; z-index: 1;"></div>
              <div id="stepper-progress-bar" class="position-absolute top-50 start-0 translate-y-middle bg-success transition-all" style="height: 3px; z-index: 2; width: 33%;"></div>

              <!-- Step 1: Request Placed -->
              <div class="position-relative text-center" style="z-index: 3;">
                <div id="step-1-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1 border-2 border-white shadow-xs" style="width: 32px; height: 32px; background-color: #28a745; color: #fff;">
                  <i class="bi bi-check fs-6"></i>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Request Placed</span>
                <small class="text-muted fs-9" id="step-1-date">Submitted</small>
              </div>

              <!-- Step 2: Academic Audit -->
              <div class="position-relative text-center" style="z-index: 3;">
                <div id="step-2-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1 border-2 border-white shadow-xs" style="width: 32px; height: 32px; background-color: #28a745; color: #fff;">
                  <i class="bi bi-check fs-6"></i>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Academic Review</span>
                <small class="text-muted fs-9">100% Verified</small>
              </div>

              <!-- Step 3: Printing / Processing -->
              <div class="position-relative text-center" style="z-index: 3;">
                <div id="step-3-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1 border-2 border-white shadow-xs" style="width: 32px; height: 32px; background-color: #6c757d; color: #fff;">
                  <span class="fs-9">3</span>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Processing</span>
                <small class="text-muted fs-9">Credential Prep</small>
              </div>

              <!-- Step 4: Dispatched / Issued -->
              <div class="position-relative text-center" style="z-index: 3;">
                <div id="step-4-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1 border-2 border-white shadow-xs" style="width: 32px; height: 32px; background-color: #6c757d; color: #fff;">
                  <span class="fs-9">4</span>
                </div>
                <span class="fs-9 fw-bold text-dark d-block" id="step-4-label">Dispatched / Issued</span>
                <small class="text-muted fs-9">Ready</small>
              </div>
            </div>
          </div>

          <!-- Summary Grid -->
          <div class="row g-3">
            <!-- Academic Metrics -->
            <div class="col-md-6">
              <div class="bg-white p-3.5 rounded-4 border h-100 shadow-xs">
                <div class="d-flex align-items-center gap-2 pb-2 mb-2.5 border-bottom text-primary fw-bold fs-8">
                  <i class="bi bi-mortarboard-fill"></i>
                  <span>Academic Evaluation</span>
                </div>
                <div class="mb-2">
                  <small class="text-muted fs-9 text-uppercase fw-bold d-block">Course Title</small>
                  <span class="fw-bold text-dark fs-8" id="tracker-course-title">Course Title</span>
                </div>
                <div class="mb-2">
                  <small class="text-muted fs-9 text-uppercase fw-bold d-block">Evaluation Summary</small>
                  <span class="text-success fw-bold fs-8" id="tracker-progress-score">Progress: 100%</span>
                </div>
                <div>
                  <small class="text-muted fs-9 text-uppercase fw-bold d-block">Completion Date</small>
                  <span class="text-dark fs-8 fw-semibold" id="tracker-completion-date">Aug 18, 2026</span>
                </div>
              </div>
            </div>

            <!-- Identity & Fulfillment Mode -->
            <div class="col-md-6">
              <div class="bg-white p-3.5 rounded-4 border h-100 shadow-xs">
                <div class="d-flex align-items-center gap-2 pb-2 mb-2.5 border-bottom text-dark fw-bold fs-8">
                  <i class="bi bi-person-badge-fill text-primary"></i>
                  <span>Recipient & Delivery Details</span>
                </div>
                <div class="mb-2">
                  <small class="text-muted fs-9 text-uppercase fw-bold d-block">Full Name on Certificate</small>
                  <span class="fw-bold text-dark fs-8" id="tracker-recipient-name">Student Full Name</span>
                </div>
                <div class="d-flex gap-2 mb-2">
                  <div class="flex-fill">
                    <small class="text-muted fs-9 text-uppercase fw-bold d-block">NIC Number</small>
                    <span class="text-secondary fs-8 font-mono" id="tracker-nic">000000000000</span>
                  </div>
                  <div class="flex-fill">
                    <small class="text-muted fs-9 text-uppercase fw-bold d-block">Contact Phone</small>
                    <span class="text-secondary fs-8" id="tracker-mobile">+94 77 000 0000</span>
                  </div>
                </div>
                <div>
                  <small class="text-muted fs-9 text-uppercase fw-bold d-block">Fulfillment Mode</small>
                  <div id="tracker-delivery-display" class="fs-8 mt-1">
                    <!-- Populated by JS -->
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer bg-white border-top py-3 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal"><?php echo __('cancel', 'Close'); ?></button>
          <div id="tracker-action-buttons">
            <!-- Populated by JS -->
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Student Official Certificate View Modal -->
  <div class="modal fade" id="studentCertificateViewModal" tabindex="-1" aria-labelledby="studentCertificateViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
        <div class="modal-header border-bottom py-3 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <div class="d-flex align-items-center gap-2.5">
            <i class="bi bi-award-fill text-warning fs-4"></i>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6"><?php echo __('view_official_certificate', 'Official Certified Credential'); ?></h5>
              <small class="text-white text-opacity-75 fs-9">Computerscience.lk Verified Academic Credential</small>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2 ms-auto">
            <button type="button" class="btn btn-sm btn-light text-primary rounded-pill px-3 fw-bold shadow-xs" id="btn-stu-download-pdf" onclick="downloadStudentCertificatePDF()">
              <i class="bi bi-file-earmark-pdf-fill text-danger me-1.5"></i> Download PDF
            </button>
            <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" onclick="printStudentCertificateModal()">
              <i class="bi bi-printer-fill me-1.5"></i> Print A4
            </button>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        </div>

        <div class="modal-body p-4 bg-light text-center">
          
          <!-- Saved Generated Image Container (if present) -->
          <div id="stu-cert-image-container" class="mb-4 text-center" style="display: none;">
            <div class="d-inline-block position-relative p-2 bg-white rounded-3 shadow border" style="max-width: 100%;">
              <img id="stu-cert-img" src="" alt="Official Certificate" class="img-fluid rounded-2" style="max-height: 520px; width: auto; object-fit: contain; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            </div>
          </div>

          <!-- Printable Certificate Frame -->
          <div id="printable-certificate-container">
            <div class="certificate-frame text-center" style="width: 100%; max-width: 900px; margin: 0 auto; background: #fffdfa; border: 12px solid #0f4c81; padding: 40px 50px; position: relative; border-radius: 4px; color: #1e293b;">
              <div class="certificate-inner-border" style="border: 2px dashed #b8860b; padding: 30px; position: relative; background: radial-gradient(circle at center, rgba(255,255,255,0.9) 0%, rgba(254,250,240,0.6) 100%);">
                
                <!-- Crest & Institution Header -->
                <div class="d-flex align-items-center justify-content-center gap-3 mb-2">
                  <i class="bi bi-mortarboard-fill text-primary fs-1" style="color: #0f4c81 !important;"></i>
                  <div>
                    <h3 class="fw-bold mb-0 text-uppercase tracking-wider" style="font-family: 'Cinzel', serif, Georgia; color: #0f4c81; font-size: 1.6rem; letter-spacing: 2px;">Computerscience.lk</h3>
                    <small class="text-muted fw-semibold tracking-widest text-uppercase fs-9">Advanced Computer Science & IT Learning Academy</small>
                  </div>
                  <i class="bi bi-award-fill text-warning fs-1"></i>
                </div>

                <div class="my-3">
                  <div style="font-family: 'Cinzel', serif, Georgia; letter-spacing: 4px; color: #0f4c81; font-size: 2.1rem; font-weight: 800; text-transform: uppercase;">Certificate of Completion</div>
                  <p class="text-muted fs-8 text-uppercase tracking-widest mt-1 mb-0">This credential is proudly awarded to</p>
                </div>

                <!-- Recipient Name in Calligraphy Display -->
                <div class="my-2">
                  <div id="stu-preview-recipient-name" style="font-family: 'Playfair Display', Georgia, serif; font-size: 2.6rem; font-weight: 700; color: #0f4c81; border-bottom: 2px solid #b8860b; display: inline-block; padding: 0 40px 6px; margin: 15px 0 10px;">Student Full Name</div>
                </div>

                <p class="text-secondary fs-8 max-w-lg mx-auto mb-1">
                  For successfully demonstrating academic mastery, technical proficiency, and completing all syllabus modules and evaluation assessments for:
                </p>

                <!-- Course Title -->
                <div id="stu-preview-course-title" style="font-family: 'Cinzel', serif, Georgia; font-size: 1.45rem; font-weight: 700; color: #b8860b; margin: 10px 0;">Course Title</div>

                <!-- Academic Metrics Line -->
                <div class="d-flex justify-content-center align-items-center gap-3 text-muted fs-8 mb-4">
                  <span id="stu-preview-quiz-summary" class="fw-semibold text-dark"><i class="bi bi-trophy-fill text-warning me-1"></i>Progress: 100%</span>
                  <span>•</span>
                  <span><i class="bi bi-calendar-check me-1"></i>Date: <strong id="stu-preview-completion-date" class="text-dark">Aug 18, 2026</strong></span>
                </div>

                <hr class="my-3 border-secondary border-opacity-20" style="width: 80%; margin-left: auto; margin-right: auto;">

                <!-- Footer Signatures & QR Verification Serial -->
                <div class="row align-items-center mt-3 pt-2 text-start">
                  
                  <!-- Left: Verification Serial & QR -->
                  <div class="col-4 text-start">
                    <div class="d-flex align-items-center gap-2">
                      <div class="p-1 border rounded bg-white" style="width: 54px; height: 54px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=https://computerscience.lk/verify" id="stu-preview-qr-code" style="width: 100%; height: 100%; object-fit: contain;" alt="QR">
                      </div>
                      <div>
                        <small class="text-muted fs-9 text-uppercase fw-bold d-block">Credential ID</small>
                        <span class="font-mono fs-9 fw-bold text-dark" id="stu-preview-cert-code">CERT-CSLK-00000000</span>
                        <div class="text-muted fs-9">Verify at computerscience.lk</div>
                      </div>
                    </div>
                  </div>

                  <!-- Center: Official Seal -->
                  <div class="col-4 text-center">
                    <div class="d-inline-flex flex-column align-items-center justify-content-center p-2 rounded-circle border border-2 border-warning" style="width: 72px; height: 72px; background: rgba(254, 240, 138, 0.15);">
                      <i class="bi bi-patch-check-fill text-warning fs-3"></i>
                      <span class="fs-9 fw-bold text-uppercase" style="font-size: 0.55rem; color: #b8860b;">VERIFIED</span>
                    </div>
                  </div>

                  <!-- Right: Academic Authority Signature -->
                  <div class="col-4 text-end">
                    <div class="d-inline-block text-center" style="min-width: 150px;">
                      <div class="fw-bold fs-7 mb-0 text-dark" style="font-family: 'Pinyon Script', cursive; font-size: 1.5rem; color: #0f4c81;">Academic Director</div>
                      <div class="border-top border-dark pt-1">
                        <small class="fw-bold text-dark fs-9 text-uppercase d-block">Director of Academic Affairs</small>
                        <small class="text-muted fs-9">Computerscience.lk Academy</small>
                      </div>
                    </div>
                  </div>

                </div>

              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-white border-top py-2.5 px-4">
          <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal"><?php echo __('cancel', 'Close'); ?></button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentActiveCertData = null;

    function openCertificateModal(data) {
      if (!data) return;
      document.getElementById('cert-modal-course-id').value = data.course_id || '';
      document.getElementById('cert-modal-course-title').value = data.course_title || '';
      document.getElementById('cert-modal-email').value = data.registered_email || '';
      document.getElementById('cert-modal-completion-date').value = data.completion_date || '';
      document.getElementById('cert-modal-progress-score').value = data.progress_score_summary || 'Progress: 100%';
      document.getElementById('cert-modal-fullname').value = data.full_name_on_certificate || data.student_name || '';
      document.getElementById('cert-modal-nic').value = data.nic_number || '';
      document.getElementById('cert-modal-mobile').value = data.mobile_number || '';

      const alertBox = document.getElementById('cert-form-alert');
      alertBox.className = 'd-none alert mb-3 py-2 px-3 fs-8';
      alertBox.innerHTML = '';

      if (data.delivery_method === 'home_delivery') {
        document.getElementById('delivery-method-home').checked = true;
        document.getElementById('home-delivery-details').style.display = 'block';
        document.getElementById('cert-modal-address').value = data.delivery_address || '';
        document.getElementById('cert-modal-city').value = data.city || '';
        document.getElementById('cert-modal-postal').value = data.postal_code || '';
        document.getElementById('cert-modal-district').value = data.district || '';
        document.getElementById('cert-modal-notes').value = data.delivery_notes || '';
      } else {
        document.getElementById('delivery-method-digital').checked = true;
        document.getElementById('home-delivery-details').style.display = 'none';
      }

      const modal = new bootstrap.Modal(document.getElementById('certificateRequestModal'));
      modal.show();
    }

    function openCertificateStatusModal(data) {
      if (!data) return;
      currentActiveCertData = data;

      document.getElementById('tracker-cert-code').textContent = data.certificate_code || 'CERT-CSLK-PENDING';
      document.getElementById('tracker-submitted-at').textContent = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : (data.completion_date || '');
      document.getElementById('step-1-date').textContent = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : 'Submitted';

      document.getElementById('tracker-course-title').textContent = data.course_title || '';
      document.getElementById('tracker-progress-score').textContent = data.progress_score_summary || 'Progress: 100%';
      document.getElementById('tracker-completion-date').textContent = data.completion_date || '';

      document.getElementById('tracker-recipient-name').textContent = data.full_name_on_certificate || data.student_name || '';
      document.getElementById('tracker-nic').textContent = data.nic_number || 'N/A';
      document.getElementById('tracker-mobile').textContent = data.mobile_number || 'N/A';

      // Delivery Method Rendering
      const deliveryContainer = document.getElementById('tracker-delivery-display');
      if (data.delivery_method === 'home_delivery') {
        deliveryContainer.innerHTML = `
          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2.5 py-1 rounded-pill fw-bold mb-1 d-inline-flex align-items-center gap-1">
            <i class="bi bi-truck"></i> Home Delivery (Printed Hard Copy via Courier)
          </span>
          <div class="text-dark fs-9 mt-1 fw-medium"><i class="bi bi-geo-alt-fill text-danger me-1"></i>${escapeHtml(data.delivery_address || '')}, ${escapeHtml(data.city || '')}</div>
          <div class="text-muted fs-9">${escapeHtml(data.district || '')} (${escapeHtml(data.postal_code || '')})</div>
          ${data.delivery_notes ? `<div class="text-muted fs-9 italic mt-0.5"><i class="bi bi-chat-left-text me-1"></i>Notes: ${escapeHtml(data.delivery_notes)}</div>` : ''}
        `;
      } else {
        deliveryContainer.innerHTML = `
          <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-2.5 py-1 rounded-pill fw-bold mb-1 d-inline-flex align-items-center gap-1">
            <i class="bi bi-file-earmark-pdf-fill"></i> Digital Copy Only (PDF e-Certificate)
          </span>
          <div class="text-muted fs-9 mt-1"><i class="bi bi-envelope me-1"></i>Emailed to: <strong>${escapeHtml(data.registered_email || '')}</strong></div>
        `;
      }

      // Stepper & Status Hero Logic
      const status = (data.status || 'pending').toLowerCase();
      const heroContainer = document.getElementById('tracker-status-hero');
      const actionBtnContainer = document.getElementById('tracker-action-buttons');
      const progressBar = document.getElementById('stepper-progress-bar');
      const step3Circle = document.getElementById('step-3-circle');
      const step4Circle = document.getElementById('step-4-circle');
      const step4Label = document.getElementById('step-4-label');

      // Reset Stepper
      step3Circle.style.backgroundColor = '#6c757d';
      step3Circle.innerHTML = '<span class="fs-9">3</span>';
      step4Circle.style.backgroundColor = '#6c757d';
      step4Circle.innerHTML = '<span class="fs-9">4</span>';
      step4Label.textContent = data.delivery_method === 'home_delivery' ? 'Dispatched' : 'Issued';

      if (status === 'pending') {
        progressBar.style.width = '33%';
        heroContainer.className = 'p-3.5 rounded-4 border border-warning bg-warning bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-warning bg-opacity-20 text-warning d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-hourglass-split fs-4 text-warning"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0 fs-7">Application Received & Under Review</h6>
                <span class="badge bg-warning text-dark border border-warning px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Pending Review</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1">
                Your certificate request was received and is currently being audited by the academic board. Your 100% course progress and quiz evaluations are verified. Once confirmed by the administrator, your certificate will be generated and dispatched.
              </p>
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'processing') {
        progressBar.style.width = '66%';
        step3Circle.style.backgroundColor = '#0f4c81';
        step3Circle.innerHTML = '<i class="bi bi-gear-fill fs-8"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-primary bg-primary bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-primary bg-opacity-20 text-primary d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-gear-wide-connected fs-4 text-primary"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0 fs-7">Certificate Processing & Printing</h6>
                <span class="badge bg-primary text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Processing</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1">
                Your certificate request has been approved! The institutional document is currently undergoing formal seal stamping, verification registration, and packaging.
              </p>
              ${data.admin_notes ? `<div class="p-2 bg-white rounded border mt-2 fs-9 text-dark"><i class="bi bi-info-circle text-primary me-1"></i><strong>Admin Note:</strong> ${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'dispatched') {
        progressBar.style.width = '100%';
        step3Circle.style.backgroundColor = '#28a745';
        step3Circle.innerHTML = '<i class="bi bi-check fs-6"></i>';
        step4Circle.style.backgroundColor = '#17a2b8';
        step4Circle.innerHTML = '<i class="bi bi-truck fs-7"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-info bg-info bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-info bg-opacity-20 text-info d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-truck fs-4 text-info"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0 fs-7">Dispatched via Courier (Out for Delivery)</h6>
                <span class="badge bg-info text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Dispatched</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1">
                Great news! Your official embossed parchment certificate has been dispatched via courier service to your delivery address.
              </p>
              ${data.admin_notes ? `<div class="p-2 bg-white rounded border border-info mt-2 fs-9 text-dark"><i class="bi bi-box-seam text-info me-1"></i><strong>Courier & Tracking Details:</strong> ${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'approved' || status === 'issued') {
        progressBar.style.width = '100%';
        step3Circle.style.backgroundColor = '#28a745';
        step3Circle.innerHTML = '<i class="bi bi-check fs-6"></i>';
        step4Circle.style.backgroundColor = '#28a745';
        step4Circle.innerHTML = '<i class="bi bi-check-all fs-6"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-success bg-success bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-success bg-opacity-20 text-success d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-patch-check-fill fs-4 text-success"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0 fs-7">Certificate Officially Issued & Verified</h6>
                <span class="badge bg-success text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Issued & Active</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1">
                Congratulations! Your official course certificate has been approved, registered on the institutional verification registry, and issued. You may view and print your verified credential at any time.
              </p>
              ${data.admin_notes ? `<div class="p-2 bg-white rounded border mt-2 fs-9 text-dark"><i class="bi bi-chat-quote me-1 text-success"></i>${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = `
          <button type="button" class="btn btn-success btn-sm px-4 rounded-pill fw-bold text-white shadow-sm d-flex align-items-center gap-1.5" onclick="viewOfficialStudentCertificate()">
            <i class="bi bi-award-fill"></i> View & Print Certificate
          </button>
        `;
      } else if (status === 'rejected') {
        progressBar.style.width = '33%';
        heroContainer.className = 'p-3.5 rounded-4 border border-danger bg-danger bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-danger bg-opacity-20 text-danger d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-x-circle-fill fs-4 text-danger"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0 fs-7">Application Not Approved</h6>
                <span class="badge bg-danger text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Declined</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1">
                Your certificate request was not approved by administration. ${data.admin_notes ? `<strong>Reason:</strong> ${escapeHtml(data.admin_notes)}` : 'Please reach out to support for more details.'}
              </p>
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      }

      const modal = new bootstrap.Modal(document.getElementById('certificateStatusModal'));
      modal.show();
    }

    function viewOfficialStudentCertificate() {
      if (!currentActiveCertData) return;
      const data = currentActiveCertData;

      const certImg = data.certificate_image ? (data.certificate_image.replace(/^\/+/, '')) : null;
      const imgContainer = document.getElementById('stu-cert-image-container');
      const dynamicFrame = document.getElementById('printable-certificate-container');
      const stuImg = document.getElementById('stu-cert-img');

      if (certImg) {
        if (stuImg) stuImg.src = certImg;
        if (imgContainer) imgContainer.style.display = 'block';
        if (dynamicFrame) dynamicFrame.style.display = 'none';
      } else {
        if (imgContainer) imgContainer.style.display = 'none';
        if (dynamicFrame) dynamicFrame.style.display = 'block';

        document.getElementById('stu-preview-recipient-name').textContent = data.full_name_on_certificate || data.student_name;
        document.getElementById('stu-preview-course-title').textContent = data.course_title;
        document.getElementById('stu-preview-quiz-summary').innerHTML = '<i class="bi bi-trophy-fill text-warning me-1"></i>' + escapeHtml(data.progress_score_summary || 'Progress: 100%');
        document.getElementById('stu-preview-completion-date').textContent = data.completion_date;
        document.getElementById('stu-preview-cert-code').textContent = data.certificate_code;

        const verifyUrl = encodeURIComponent('https://computerscience.lk/verify?code=' + data.certificate_code);
        document.getElementById('stu-preview-qr-code').src = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + verifyUrl;
      }

      const modal = new bootstrap.Modal(document.getElementById('studentCertificateViewModal'));
      modal.show();
    }

    function downloadStudentCertificatePDF() {
      if (!currentActiveCertData) return;
      const sName = (currentActiveCertData.full_name_on_certificate || currentActiveCertData.student_name || 'Student').replace(/[^a-z0-9]/gi, '_');
      const cCode = (currentActiveCertData.certificate_code || 'CERT').replace(/[^a-z0-9]/gi, '_');
      const downloadBtn = document.getElementById('btn-stu-download-pdf');
      const origHtml = downloadBtn ? downloadBtn.innerHTML : '';
      if (downloadBtn) {
        downloadBtn.disabled = true;
        downloadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5"></span> Generating PDF...';
      }

      function restoreBtn() {
        if (downloadBtn) {
          downloadBtn.disabled = false;
          downloadBtn.innerHTML = origHtml;
        }
      }

      if (currentActiveCertData.certificate_image) {
        const imgPath = currentActiveCertData.certificate_image.replace(/^\/+/, '');
        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = function () {
          try {
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            pdf.addImage(img, 'PNG', 0, 0, 297, 210, undefined, 'FAST');
            pdf.save(`Official_Certificate_${sName}_${cCode}.pdf`);
          } catch (e) {
            console.error('jsPDF image render error:', e);
            window.print();
          }
          restoreBtn();
        };
        img.onerror = function () {
          restoreBtn();
          window.print();
        };
        img.src = imgPath;
      } else {
        const frame = document.getElementById('printable-certificate-container');
        if (typeof html2canvas === 'function' && frame) {
          html2canvas(frame, { scale: 2, useCORS: true, backgroundColor: '#ffffff' }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            pdf.addImage(imgData, 'PNG', 0, 0, 297, 210, undefined, 'FAST');
            pdf.save(`Official_Certificate_${sName}_${cCode}.pdf`);
            restoreBtn();
          }).catch(err => {
            console.error('html2canvas render error:', err);
            restoreBtn();
            window.print();
          });
        } else {
          restoreBtn();
          window.print();
        }
      }
    }

    function printStudentCertificateModal() {
      if (currentActiveCertData && currentActiveCertData.certificate_image) {
        const certImg = currentActiveCertData.certificate_image.replace(/^\/+/, '');
        const win = window.open('', '', 'height=750,width=1050');
        win.document.write('<!DOCTYPE html><html><head><title>Print Certificate</title>');
        win.document.write('<style>@page { size: A4 landscape; margin: 0; } body { margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; height: 100vh; background: #fff; } img { width: 100vw; height: 100vh; object-fit: contain; }</style>');
        win.document.write('</head><body onload="window.print();window.close();">');
        win.document.write('<img src="' + certImg + '" alt="Certificate">');
        win.document.write('</body></html>');
        win.document.close();
      } else {
        window.print();
      }
    }

    function toggleDeliveryFields() {
      const isHome = document.getElementById('delivery-method-home').checked;
      document.getElementById('home-delivery-details').style.display = isHome ? 'block' : 'none';
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text || '';
      return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
      const certForm = document.getElementById('certificate-request-form');
      if (certForm) {
        certForm.addEventListener('submit', function (e) {
          e.preventDefault();

          const submitBtn = document.getElementById('btn-submit-cert-request');
          const alertBox = document.getElementById('cert-form-alert');
          const courseId = document.getElementById('cert-modal-course-id').value;
          const fullName = document.getElementById('cert-modal-fullname').value.trim();
          const nic = document.getElementById('cert-modal-nic').value.trim();
          const mobile = document.getElementById('cert-modal-mobile').value.trim();
          const isHome = document.getElementById('delivery-method-home').checked;
          const deliveryMethod = isHome ? 'home_delivery' : 'digital_only';

          const payload = {
            course_id: courseId,
            full_name_on_certificate: fullName,
            nic_number: nic,
            mobile_number: mobile,
            delivery_method: deliveryMethod,
            delivery_address: isHome ? document.getElementById('cert-modal-address').value.trim() : '',
            city: isHome ? document.getElementById('cert-modal-city').value.trim() : '',
            postal_code: isHome ? document.getElementById('cert-modal-postal').value.trim() : '',
            district: isHome ? document.getElementById('cert-modal-district').value.trim() : '',
            delivery_notes: isHome ? document.getElementById('cert-modal-notes').value.trim() : ''
          };

          if (isHome && (!payload.delivery_address || !payload.city || !payload.postal_code || !payload.district)) {
            alertBox.className = 'alert alert-danger mb-3 py-2 px-3 fs-8';
            alertBox.textContent = 'Please fill in all delivery address details (Address, City, Postal Code, District).';
            return;
          }

          submitBtn.disabled = true;
          submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1.5"></span> Submitting...`;

          fetch('api/request_certificate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              alertBox.className = 'alert alert-success mb-3 py-2 px-3 fs-8';
              alertBox.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> ${data.message} <br><strong>Reference Code: ${data.certificate_code}</strong>`;
              setTimeout(() => {
                location.reload();
              }, 1800);
            } else {
              alertBox.className = 'alert alert-danger mb-3 py-2 px-3 fs-8';
              alertBox.textContent = data.message || 'An error occurred while submitting your request.';
              submitBtn.disabled = false;
              submitBtn.innerHTML = `<i class="bi bi-send-fill me-1.5"></i> <?php echo __('submit_certificate_request', 'Submit Certificate Request'); ?>`;
            }
          })
          .catch(err => {
            alertBox.className = 'alert alert-danger mb-3 py-2 px-3 fs-8';
            alertBox.textContent = 'Network error. Please try again.';
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<i class="bi bi-send-fill me-1.5"></i> <?php echo __('submit_certificate_request', 'Submit Certificate Request'); ?>`;
          });
        });
      }
    });
  </script>
</body>
</html>
