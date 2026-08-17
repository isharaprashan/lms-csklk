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

  if ($is_teacher) {
    // Fetch courses taught by this teacher
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE tutor_id = ?");
    $stmt->execute([$user_id]);
    $teacher_courses = $stmt->fetchAll();
    $courses_count = count($teacher_courses);

    // Fetch total student enrollments in teacher's courses
    $stmt = $pdo->prepare("SELECT COUNT(e.user_id) FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE c.tutor_id = ?");
    $stmt->execute([$user_id]);
    $total_students_enrolled = $stmt->fetchColumn();

    // Fetch recent student quiz submissions in teacher's courses
    $stmt = $pdo->prepare("SELECT qr.*, u.name as student_name, u.avatar as student_avatar, c.title as course_title 
                               FROM quiz_results qr 
                               JOIN users u ON qr.user_id = u.id 
                               JOIN courses c ON qr.course_id = c.id 
                               WHERE c.tutor_id = ? 
                               ORDER BY qr.score DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $recent_submissions = $stmt->fetchAll();

    // Fetch all courses for sidebar (approved or taught by them)
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE status = 'approved' OR tutor_id = ?");
    $stmt->execute([$user_id]);
    $all_courses = $stmt->fetchAll();

    $enrolled_ids = [];
    $enrolled_count = 0;
  } else {
    // Fetch enrolled course IDs
    $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $enrolled_count = count($enrolled_ids);

    // Fetch enrolled courses directly (approved only)
    if (!empty($enrolled_ids)) {
      $in_clause = implode(',', array_fill(0, count($enrolled_ids), '?'));
      $stmt = $pdo->prepare("SELECT * FROM courses WHERE id IN ($in_clause) AND status = 'approved'");
      $stmt->execute($enrolled_ids);
      $all_courses = $stmt->fetchAll();
    } else {
      $all_courses = [];
    }

    // Fetch total completed lessons for the student
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM completed_lessons WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $completed_lessons_count = $stmt->fetchColumn();

    // Fetch total quiz results completed
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $quizzes_completed_count = $stmt->fetchColumn();
  }

  // Fetch notifications for all roles (students & teachers)
  $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
  $stmt->execute([$user_id]);
  $notifications = $stmt->fetchAll();

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
  $stmt->execute([$user_id]);
  $unread_count = (int) $stmt->fetchColumn();

  // Fetch active course categories and target audiences for dynamic drop-down options
  $stmt = $pdo->query("SELECT * FROM course_categories WHERE status = 'active' ORDER BY name ASC");
  $active_categories = $stmt->fetchAll();

  $stmt = $pdo->query("SELECT * FROM target_audiences WHERE status = 'active' ORDER BY name ASC");
  $active_target_audiences = $stmt->fetchAll();

} catch (PDOException $e) {
  die("Database connection error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $is_teacher ? 'Lecturer Console' : 'Dashboard'; ?> | Computerscience.lk</title>
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
        <button id="drawer-toggle"
          class="btn btn-light border-0 rounded-circle p-2 fs-5 d-flex align-items-center justify-content-center"
          style="width: 42px; height: 42px;">
          <i class="bi bi-list"></i>
        </button>
        <a class="moodle-brand fw-bold text-decoration-none fs-4 d-flex align-items-center" href="index.php"
          style="color: #0f4c81;">
          <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2"
            style="height: 32px; width: auto; object-fit: contain;">computerscience.lk
        </a>
      </div>

      <!-- Center: Main Navbar links -->
      <nav class="d-none d-lg-flex align-items-center gap-2">
        <a href="index.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_home', 'Site Home'); ?></a>
        <a href="dashboard.php"
          class="btn btn-light text-primary fw-bold px-3"><?php echo __('nav_dashboard', 'Dashboard'); ?></a>
        <a href="my_courses.php"
          class="btn btn-light px-3 text-secondary"><?php echo $is_teacher ? __('nav_uploaded_courses', 'Uploaded Courses') : __('nav_my_courses', 'My Courses'); ?></a>
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
          <button
            class="btn btn-sm btn-light border text-secondary dropdown-toggle d-flex align-items-center gap-1.5 rounded-pill px-2.5 py-1"
            type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-globe text-primary fs-7"></i>
            <span
              class="fw-semibold fs-8"><?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'සිංහල' : 'English'; ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-1" aria-labelledby="langDropdown">
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'en') ? 'active fw-bold' : ''; ?>"
                href="#" onclick="switchLanguage('en'); return false;">
                <span>English</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'en'): ?><i
                    class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'active fw-bold' : ''; ?>"
                href="#" onclick="switchLanguage('si'); return false;">
                <span>සිංහල</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'si'): ?><i
                    class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
          </ul>
        </div>

        <!-- Notification Dropdown -->
        <div class="dropdown">
          <button class="text-secondary fs-5 border-0 bg-transparent p-2 position-relative dropdown-toggle no-caret"
            type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false"
            onclick="markNotificationsAsRead()">
            <i class="bi bi-bell"></i>
            <?php if ($unread_count > 0): ?>
              <span class="position-absolute top-1 end-1 translate-middle badge rounded-circle bg-danger"
                id="notification-badge" style="padding: 4px; font-size: 0.5rem;">
                <?php echo $unread_count; ?>
              </span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light py-2" aria-labelledby="notificationDropdown"
            style="width: 320px; max-height: 400px; overflow-y: auto; z-index: 1050;">
            <li
              class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2 d-flex justify-content-between align-items-center">
              <span><?php echo __('notifications', 'Notifications'); ?></span>
              <?php if ($unread_count > 0): ?>
                <span class="badge bg-primary text-white fs-9" id="notification-count"><?php echo $unread_count; ?>
                  new</span>
              <?php endif; ?>
            </li>
            <?php if (empty($notifications)): ?>
              <li class="px-3 py-4 text-center text-muted fs-8 italic">
                <?php echo __('no_notifications', 'No notifications yet.'); ?></li>
            <?php else: ?>
              <?php foreach ($notifications as $notif): ?>
                <li
                  class="px-3 py-2 border-bottom last-border-0 <?php echo $notif['is_read'] ? 'opacity-70' : 'bg-light bg-opacity-50 fw-semibold'; ?>">
                  <div class="fs-8 text-dark mb-1"><?php echo htmlspecialchars($notif['message']); ?></div>
                  <small class="text-muted fs-9"><i
                      class="bi bi-clock me-1"></i><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></small>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </div>
        <div class="dropdown">
          <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <img src="<?php echo htmlspecialchars(get_user_avatar($student['avatar'], $student['name'])); ?>" class="rounded-circle"
              style="width: 32px; height: 32px; object-fit: cover;" alt="Profile">
            <span
              class="d-none d-md-inline text-secondary fw-semibold text-sm"><?php echo htmlspecialchars(explode(' ', $student['name'])[0]); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light">
            <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>
                <?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>
                <?php echo __('nav_profile', 'Profile'); ?></a></li>
            <li>
              <hr class="dropdown-divider">
            </li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>
                <?php echo __('nav_logout', 'Logout'); ?></a></li>
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
      <a href="dashboard.php" class="drawer-link active">
        <i class="bi bi-speedometer2 fs-5 text-primary"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
      </a>
      <hr class="mx-3 my-2 border-secondary border-opacity-20">
      <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider">
        <?php echo $is_teacher ? __('courses_i_teach', 'Courses I Teach') : __('nav_my_courses', 'My Courses'); ?></div>
      <?php
      $enrolled_any = false;
      if ($is_teacher) {
        foreach ($teacher_courses as $cs_course) {
          $enrolled_any = true;
          echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                      <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                    </a>';
        }
        if (!$enrolled_any) {
          echo '<div class="px-4 py-2 fs-8 text-muted italic">' . __('no_courses_created_yet', 'No courses created yet') . '</div>';
        }
      } else {
        foreach ($all_courses as $cs_course) {
          $enrolled_any = true;
          echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                      <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                    </a>';
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
          <li class="breadcrumb-item active" aria-current="page"><?php echo __('nav_dashboard', 'Dashboard'); ?></li>
        </ol>
      </nav>

      <!-- Page title -->
      <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="fw-bold text-dark mb-0 fs-3">
          <?php echo $is_teacher ? __('lecturer_console', 'Lecturer Console') : __('student_dashboard', 'Dashboard'); ?>
        </h1>
        <?php if ($is_teacher): ?>
          <div class="d-flex gap-2">
            <a href="student_analytics.php"
              class="btn btn-outline-primary btn-sm px-3 rounded-pill d-inline-flex align-items-center gap-1">
              <i class="bi bi-graph-up-arrow"></i> <?php echo __('nav_student_analytics', 'Student Analytics'); ?>
            </a>
            <button type="button" class="btn btn-primary btn-sm px-3 border-0 d-inline-flex align-items-center gap-1"
              style="background-color: #0f4c81;" data-bs-toggle="collapse" data-bs-target="#createCourseCollapse">
              <i class="bi bi-plus-circle-fill"></i> <?php echo __('add_new_course', 'Add New Course'); ?>
            </button>
          </div>
        <?php else: ?>
          <a href="index.php#courses-section" class="btn btn-primary btn-sm px-3 border-0"
            style="background-color: #0f4c81;">
            <i class="bi bi-search me-1"></i> <?php echo __('nav_courses', 'Course Catalog'); ?>
          </a>
        <?php endif; ?>
      </div>

      <!-- Dashboard Layout Columns -->
      <div class="row g-4">

        <!-- Left Column -->
        <div class="col-lg-8">

          <?php if ($is_teacher): ?>
            <!-- Create Course Form (Collapse block) -->
            <div class="collapse mb-4" id="createCourseCollapse">
              <div class="moodle-card p-4 bg-white border border-primary border-opacity-20 shadow-sm">
                <h4 class="fw-bold text-dark border-bottom pb-2 mb-3 fs-5"><i
                    class="bi bi-journal-plus text-primary me-2"></i>Add a New Course Syllabus</h4>

                <!-- Step Indicator Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                  <div class="d-flex align-items-center gap-2 pb-2 wizard-step-header active-step" id="step-1-indicator"
                    style="border-bottom: 2px solid #0f4c81;">
                    <span
                      class="step-num bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold"
                      style="width: 28px; height: 28px; background-color: #0f4c81 !important;">1</span>
                    <span class="fw-bold text-dark fs-7">Course Details</span>
                  </div>
                  <div class="flex-grow-1 mx-3 border-top border-secondary border-opacity-25"
                    style="border-top-style: dashed !important;"></div>
                  <div class="d-flex align-items-center gap-2 pb-2 wizard-step-header text-muted" id="step-2-indicator">
                    <span
                      class="step-num bg-light text-secondary border rounded-circle d-flex align-items-center justify-content-center fw-bold"
                      style="width: 28px; height: 28px;">2</span>
                    <span class="fw-bold fs-7">Lessons & Videos</span>
                  </div>
                </div>

                <form id="create-course-form" enctype="multipart/form-data">
                  <!-- Step 1 Content -->
                  <div id="wizard-step-1">
                    <div class="row g-3">
                      <!-- Course Title (Text) -->
                      <div class="col-md-6">
                        <label for="new-course-title" class="form-label fw-semibold text-secondary">Course Title</label>
                        <input type="text" id="new-course-title" class="form-control"
                          placeholder="e.g. Intro to Programming" required>
                      </div>

                      <!-- Course Code / Slug (Unique) -->
                      <div class="col-md-6">
                        <label for="new-course-id" class="form-label fw-semibold text-secondary">Course Code / Slug
                          (Unique)</label>
                        <input type="text" id="new-course-id" class="form-control" placeholder="e.g. intro-programming"
                          required>
                        <small class="text-muted fs-9">Unique ID. Lowercase letters, numbers, and hyphens only.</small>
                      </div>

                      <!-- Category/Subject (Dropdown) -->
                      <div class="col-md-6">
                        <label for="new-course-category"
                          class="form-label fw-semibold text-secondary"><?php echo __('subject_specialization_dept', 'Category / Subject'); ?></label>
                        <select id="new-course-category" class="form-select" required>
                          <option value="" disabled selected><?php echo __('select_category', 'Select Category'); ?>
                          </option>
                          <?php foreach ($active_categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['name']); ?>">
                              <?php echo htmlspecialchars(__($cat['name'], $cat['name'])); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <!-- Target Audience / University Batch (Checkbox list with Add feature) -->
                      <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary d-flex justify-content-between align-items-center mb-1">
                          <span><?php echo __('target_audience_batch', 'Target Audience / University Batch'); ?> <span class="text-danger">*</span></span>
                          <small class="text-muted fw-normal">Select all that apply</small>
                        </label>
                        <div id="target-audience-checkbox-container" class="border rounded p-2.5 bg-white mb-2" style="max-height: 140px; overflow-y: auto;">
                          <?php foreach ($active_target_audiences as $aud): ?>
                            <?php $aud_id = 'aud-add-' . substr(md5($aud['name']), 0, 8); ?>
                            <div class="form-check mb-1">
                              <input class="form-check-input new-course-audience-checkbox" type="checkbox" value="<?php echo htmlspecialchars($aud['name']); ?>" id="<?php echo $aud_id; ?>">
                              <label class="form-check-label fs-7 cursor-pointer" for="<?php echo $aud_id; ?>">
                                <?php echo htmlspecialchars(__($aud['name'], $aud['name'])); ?>
                              </label>
                            </div>
                          <?php endforeach; ?>
                        </div>
                        <div class="input-group input-group-sm">
                          <input type="text" id="add-new-audience-input" class="form-control" placeholder="Add new target audience...">
                          <button class="btn btn-outline-primary fw-semibold" type="button" id="btn-add-new-audience">
                            <i class="bi bi-plus-lg me-0.5"></i> Add
                          </button>
                        </div>
                      </div>

                      <!-- Price (Number / Free Toggle) -->
                      <div class="col-md-6">
                        <label class="form-label fw-semibold text-secondary d-block">Course Price</label>
                        <div class="d-flex align-items-center gap-3">
                          <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="price-toggle" checked>
                            <label class="form-check-label fw-medium text-dark" for="price-toggle"
                              id="price-toggle-label">Free Course</label>
                          </div>
                          <div class="input-group flex-grow-1" id="price-input-container" style="display: none;">
                            <span class="input-group-text">Rs.</span>
                            <input type="number" id="new-course-price" class="form-control" placeholder="0.00" min="0"
                              step="0.01" value="0.00">
                          </div>
                        </div>
                      </div>

                      <!-- Level & Duration (Internal DB requirements) -->
                      <div class="col-md-6">
                        <div class="row">
                          <div class="col-6">
                            <label for="new-course-level" class="form-label fw-semibold text-secondary">Level</label>
                            <select id="new-course-level" class="form-select" required>
                              <option value="Beginner" selected>Beginner</option>
                              <option value="Intermediate">Intermediate</option>
                              <option value="Advanced">Advanced</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label for="new-course-duration" class="form-label fw-semibold text-secondary">Duration
                              (Weeks)</label>
                            <input type="number" id="new-course-duration" class="form-control" value="8" min="1" required>
                          </div>
                        </div>
                      </div>

                      <!-- Course Description (Textarea / Rich text placeholder) -->
                      <div class="col-12">
                        <label for="new-course-long-desc" class="form-label fw-semibold text-secondary">Course
                          Description</label>
                        <div class="border rounded bg-white">
                          <div class="bg-light border-bottom p-2 d-flex gap-2 align-items-center fs-8 text-secondary">
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-type-bold"></i></button>
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-type-italic"></i></button>
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-type-underline"></i></button>
                            <span class="vr"></span>
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-list-ul"></i></button>
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-list-ol"></i></button>
                            <span class="vr"></span>
                            <button type="button" class="btn btn-sm btn-light border p-1" style="line-height: 1;"><i
                                class="bi bi-link-45deg"></i></button>
                            <span class="ms-auto text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">Rich Text
                              Editor</span>
                          </div>
                          <textarea id="new-course-long-desc" class="form-control border-0 rounded-0" rows="4"
                            placeholder="Describe the comprehensive learning paths, prerequisites, and skills gained..."
                            style="resize: vertical; outline: none; box-shadow: none;" required></textarea>
                        </div>
                      </div>

                      <!-- Course Thumbnail (File Upload) -->
                      <div class="col-12">
                        <label class="form-label fw-semibold text-secondary">Course Thumbnail</label>
                        <div
                          class="border border-2 border-dashed rounded p-4 text-center cursor-pointer hover:bg-light transition-all"
                          id="thumbnail-dropzone" style="border-color: #0f4c81 !important;">
                          <i class="bi bi-cloud-arrow-up fs-2" style="color: #0f4c81;"></i>
                          <h6 class="fw-bold mt-2 mb-1">Click to upload or drag & drop thumbnail</h6>
                          <p class="text-muted fs-8 mb-3">PNG, JPG, JPEG, WEBP up to 5MB</p>
                          <input type="file" id="new-course-thumbnail-file" class="d-none" accept="image/*">

                          <!-- Preview Container -->
                          <div id="thumbnail-preview-container" class="mt-2 d-none">
                            <div class="position-relative d-inline-block">
                              <img id="thumbnail-preview" src="#" alt="Preview" class="img-fluid rounded border shadow-sm"
                                style="max-height: 150px; object-fit: cover;">
                              <button type="button" id="remove-thumbnail-btn"
                                class="btn btn-danger btn-sm rounded-circle position-absolute top-0 end-0 translate-middle p-1 d-flex align-items-center justify-content-center"
                                style="width: 24px; height: 24px;">
                                <i class="bi bi-x fs-8"></i>
                              </button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="mt-4 text-end">
                      <button type="button" class="btn btn-light border me-2" data-bs-toggle="collapse"
                        data-bs-target="#createCourseCollapse">Cancel</button>
                      <button type="button" class="btn btn-primary" id="btn-next-step"
                        style="background-color: #0f4c81;">Next to Step 2 <i
                          class="bi bi-arrow-right-short ms-1"></i></button>
                    </div>
                  </div>

                  <!-- Step 2 Content -->
                  <div id="wizard-step-2" style="display: none;">
                    <div class="mb-4">
                      <h5 class="fw-bold text-dark fs-6 mb-2"><i
                          class="bi bi-collection-play text-primary me-2"></i>Lessons & Videos</h5>
                      <p class="text-muted fs-8">Configure your syllabus chapter lectures. You must specify a title and
                        YouTube video URL for each lesson.</p>
                    </div>

                    <!-- Lessons Rows Container -->
                    <div id="lessons-container" class="d-flex flex-column gap-3 mb-4">
                      <!-- Default Row (Lesson #1) -->
                      <div class="lesson-row border rounded p-3 bg-light position-relative">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                          <h6 class="fw-bold text-secondary fs-8 mb-0 lesson-index-label"><i
                              class="bi bi-play-circle-fill text-primary me-1"></i>Lesson #1</h6>
                          <button type="button"
                            class="btn btn-link text-danger p-0 fs-8 text-decoration-none btn-remove-lesson"
                            style="display: none;">
                            <i class="bi bi-trash-fill me-1"></i>Remove
                          </button>
                        </div>
                        <div class="row g-3">
                          <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary fs-8">Lesson Title</label>
                            <input type="text" class="form-control form-control-sm lesson-title-input"
                              placeholder="e.g. Lesson 1: Introduction to Web Architectures" required>
                          </div>
                          <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary fs-8">YouTube Video URL</label>
                            <input type="url" class="form-control form-control-sm lesson-video-input"
                              placeholder="e.g. https://www.youtube.com/watch?v=..." required>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Add More Lesson Button -->
                    <div class="mb-4">
                      <button type="button"
                        class="btn btn-outline-primary btn-sm px-3 rounded d-inline-flex align-items-center gap-1"
                        id="btn-add-lesson">
                        <i class="bi bi-plus-circle"></i> Add More Lesson
                      </button>
                    </div>

                    <div class="mt-4 border-top pt-3 d-flex justify-content-between">
                      <button type="button" class="btn btn-light border" id="btn-prev-step"><i
                          class="bi bi-arrow-left-short me-1"></i> Back to Step 1</button>
                      <button type="submit" class="btn btn-success px-4" id="btn-submit-course"
                        style="background-color: #28a745; border-color: #28a745;">Submit Course</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- Courses Taught Grid -->
            <div class="moodle-card p-4 mb-4">
              <h4 class="fw-bold text-dark border-bottom pb-2 mb-4 fs-5"><i
                  class="bi bi-collection-play me-2 text-primary"></i>My Syllabus Modules</h4>

              <?php if (empty($teacher_courses)): ?>
                <div class="text-center py-5">
                  <i class="bi bi-journal-plus fs-1 text-muted mb-3"></i>
                  <h5 class="fw-bold">No active courses</h5>
                  <p class="text-muted mb-4">You have not created any syllabus modules yet.</p>
                  <button type="button" class="btn btn-primary" style="background-color: #0f4c81;" data-bs-toggle="collapse"
                    data-bs-target="#createCourseCollapse">Create Your First Course</button>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-3">
                  <?php foreach ($teacher_courses as $course): ?>
                    <!-- Teacher Course Row -->
                    <div class="p-3 border rounded bg-white hover:shadow-sm transition-all">
                      <div class="row align-items-center gy-3">
                        <div class="col-sm-2 col-3">
                          <img src="<?php echo htmlspecialchars($course['thumbnail']); ?>" class="img-fluid rounded"
                            alt="Thumbnail" style="width: 100%; max-height: 60px; object-fit: cover;">
                        </div>
                        <div class="col-sm-7 col-9">
                          <span
                            class="badge bg-light text-primary mb-1 border fs-8"><?php echo htmlspecialchars($course['category']); ?></span>
                          <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($course['title']); ?></h6>
                          <div class="mb-2">
                            <?php if (($course['status'] ?? 'approved') === 'pending'): ?>
                              <span
                                class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-35 fs-9 py-0.5 px-2 rounded-pill"><i
                                  class="bi bi-clock me-1"></i>Pending Review</span>
                            <?php elseif (($course['status'] ?? 'approved') === 'approved'): ?>
                              <span
                                class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 fs-9 py-0.5 px-2 rounded-pill"><i
                                  class="bi bi-check-circle me-1"></i>Approved & Published</span>
                            <?php else: ?>
                              <span
                                class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-35 fs-9 py-0.5 px-2 rounded-pill"><i
                                  class="bi bi-x-circle me-1"></i>Rejected</span>
                            <?php endif; ?>
                          </div>
                          <div class="d-flex align-items-center gap-3 text-muted fs-8">
                            <span><i class="bi bi-people me-1"></i>Enrolled:
                              <strong><?php echo $course['enrolled_count']; ?></strong></span>
                            <span><i class="bi bi-clock me-1"></i>Duration: <strong><?php echo $course['duration']; ?>
                                Weeks</strong></span>
                            <span><i class="bi bi-star-fill text-warning me-1"></i>Rating:
                              <strong><?php echo $course['rating']; ?></strong></span>
                          </div>
                        </div>
                        <div class="col-sm-3 text-center text-sm-end">
                          <a href="classroom.php?course_id=<?php echo $course['id']; ?>"
                            class="btn btn-outline-primary btn-sm px-3 rounded-pill">
                            <i class="bi bi-pencil-square me-1"></i> Manage Course
                          </a>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

          <?php else: ?>
            <!-- Student Course Overview Block -->
            <div class="moodle-card p-4 mb-4">
              <h4 class="fw-bold text-dark border-bottom pb-2 mb-4 fs-5"><i
                  class="bi bi-mortarboard me-2 text-primary"></i>Course Overview</h4>

              <?php if ($enrolled_count === 0): ?>
                <div class="text-center py-5">
                  <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px;">
                    <i class="bi bi-journal-x fs-2"></i>
                  </div>
                  <h5 class="fw-bold text-dark mb-1"><?php echo __('no_active_courses', 'No Enrolled Courses Yet'); ?></h5>
                  <p class="text-muted fs-7 mb-4 max-w-md mx-auto"><?php echo __('no_active_courses_desc', 'You are not enrolled in any academic courses yet. Explore our catalog and start learning today!'); ?></p>
                  <a href="index.php#courses-section" class="btn btn-primary rounded-pill px-4 py-2.5 fw-semibold border-0 shadow-sm" style="background-color: #0f4c81;">
                    <i class="bi bi-compass me-1.5"></i><?php echo __('browse_available_courses', 'Browse Available Courses'); ?>
                  </a>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-3">
                  <?php
                  foreach ($all_courses as $course) {

                    // Progress metrics from MySQL
                    $lessonsStmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id = ?");
                    $lessonsStmt->execute([$course['id']]);
                    $total_lessons = $lessonsStmt->fetchColumn();

                    $completedStmt = $pdo->prepare("SELECT COUNT(*) FROM completed_lessons cl 
                                                     JOIN lessons l ON cl.lesson_id = l.id 
                                                     WHERE cl.user_id = ? AND l.course_id = ?");
                    $completedStmt->execute([$user_id, $course['id']]);
                    $completed_in_course = $completedStmt->fetchColumn();

                    $progress_percent = $total_lessons > 0 ? round(($completed_in_course / $total_lessons) * 100) : 0;
                    ?>

                    <!-- Moodle Course Row -->
                    <div class="p-3 border rounded bg-white hover:shadow-sm transition-all">
                      <div class="row align-items-center gy-3">
                        <div class="col-sm-2 col-3">
                          <img src="<?php echo htmlspecialchars($course['thumbnail']); ?>" class="img-fluid rounded"
                            alt="Thumbnail" style="width: 100%; max-height: 60px; object-fit: cover;">
                        </div>
                        <div class="col-sm-7 col-9">
                          <span
                            class="badge bg-light text-primary mb-1 border fs-8"><?php echo htmlspecialchars($course['category']); ?></span>
                          <h6 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($course['title']); ?></h6>

                          <!-- Progress Bar -->
                          <div class="d-flex align-items-center gap-3">
                            <div class="progress flex-grow-1" style="height: 6px;">
                              <div class="progress-bar rounded" role="progressbar"
                                style="width: <?php echo $progress_percent; ?>%; background-color: #0f4c81;"
                                aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="text-dark fs-8 fw-bold"><?php echo $progress_percent; ?>%</span>
                          </div>
                        </div>
                        <div class="col-sm-3 text-center text-sm-end d-flex flex-wrap justify-content-end gap-1">
                          <a href="classroom.php?course_id=<?php echo urlencode($course['id']); ?>"
                            class="btn btn-outline-primary btn-sm px-3 rounded-pill">
                            <i class="bi bi-folder2-open me-1"></i> Access
                          </a>
                          <?php if ($is_teacher): ?>
                            <a href="create_quiz.php?course_id=<?php echo urlencode($course['id']); ?>"
                              class="btn btn-outline-warning btn-sm px-3 rounded-pill text-dark fw-bold border-warning">
                              <i class="bi bi-patch-question-fill me-1 text-primary"></i> Quiz Builder
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- My Detailed Progress & Quiz Performance Block -->
            <div class="moodle-card p-4 mb-4">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-4">
                <h4 class="fw-bold text-dark mb-0 fs-5">
                  <i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>My Progress & Performance
                </h4>
                <span
                  class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-3 py-1 rounded-pill fs-8">
                  Real-Time Analytics
                </span>
              </div>

              <?php if (empty($all_courses)): ?>
                <div class="text-center py-4 text-muted fs-7">
                  <i class="bi bi-info-circle me-1 text-primary"></i><?php echo __('enroll_to_view_analytics', 'Enroll in a course to view detailed lesson progress and quiz scores.'); ?>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-4">
                  <?php foreach ($all_courses as $course):
                    // Fetch all lessons for this course
                    $lStmt = $pdo->prepare("SELECT l.*, lp.progress_percent, lp.completed as is_lp_completed, cl.user_id as is_cl_completed 
                                            FROM lessons l 
                                            LEFT JOIN lesson_progress lp ON l.id = lp.lesson_id AND lp.user_id = ? 
                                            LEFT JOIN completed_lessons cl ON l.id = cl.lesson_id AND cl.user_id = ?
                                            WHERE l.course_id = ? ORDER BY l.sort_order ASC, l.id ASC");
                    $lStmt->execute([$user_id, $user_id, $course['id']]);
                    $course_lessons = $lStmt->fetchAll();

                    // Fetch quiz result for this course
                    $qStmt = $pdo->prepare("SELECT * FROM quiz_results WHERE user_id = ? AND course_id = ?");
                    $qStmt->execute([$user_id, $course['id']]);
                    $quiz_res = $qStmt->fetch();
                    ?>
                    <div class="border rounded p-3 bg-white shadow-sm">
                      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
                        <div>
                          <span
                            class="badge bg-light text-primary border fs-9 mb-1"><?php echo htmlspecialchars($course['category']); ?></span>
                          <h6 class="fw-bold text-dark mb-0 fs-6"><?php echo htmlspecialchars($course['title']); ?></h6>
                        </div>
                        <div>
                          <?php if ($quiz_res):
                            $qScore = (int) $quiz_res['score'];
                            $qTotal = (int) ($quiz_res['total_questions'] ?: 0);
                            $qPct = ($qTotal > 0) ? round(($qScore / $qTotal) * 100) : 0;
                            $qStatus = $quiz_res['status'] ?? 'completed';
                            ?>
                            <span
                              class="badge bg-<?php echo ($qPct >= 50 || $qStatus === 'passed') ? 'success' : 'warning'; ?> bg-opacity-10 text-<?php echo ($qPct >= 50 || $qStatus === 'passed') ? 'success' : 'dark'; ?> border border-<?php echo ($qPct >= 50 || $qStatus === 'passed') ? 'success' : 'warning'; ?> border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
                              <i class="bi bi-patch-check-fill me-1"></i> Quiz Completed: Score
                              <?php echo $qScore; ?>        <?php echo $qTotal > 0 ? '/' . $qTotal : ''; ?> (<?php echo $qPct; ?>%)
                            </span>
                          <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-10 text-muted border px-2.5 py-1 rounded-pill fs-8">
                              <i class="bi bi-clock me-1"></i> Quiz Pending
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>

                      <!-- Lessons Progress List -->
                      <div class="d-flex flex-column gap-2">
                        <?php if (empty($course_lessons)): ?>
                          <small class="text-muted italic">No lessons uploaded yet for this module.</small>
                        <?php else: ?>
                          <?php foreach ($course_lessons as $lIndex => $les):
                            $lesPct = (float) ($les['progress_percent'] ?? 0);
                            $isDone = ($les['is_cl_completed'] || $les['is_lp_completed'] || $lesPct >= 90);
                            $displayPct = $isDone ? 100 : round($lesPct);
                            ?>
                            <div class="d-flex align-items-center justify-content-between p-2.5 rounded bg-light border-0 fs-8">
                              <div class="d-flex align-items-center gap-2 text-truncate me-2" style="max-width: 60%;">
                                <div class="activity-icon-box <?php echo $isDone ? 'icon-green' : 'icon-blue'; ?> text-center"
                                  style="width: 24px; height: 24px; font-size: 0.75rem;">
                                  <i class="bi bi-<?php echo $isDone ? 'check-circle-fill' : 'play-circle-fill'; ?>"></i>
                                </div>
                                <span
                                  class="fw-semibold text-dark text-truncate"><?php echo htmlspecialchars($les['title']); ?></span>
                              </div>

                              <div class="d-flex align-items-center gap-3">
                                <div class="d-none d-sm-flex align-items-center gap-2" style="width: 110px;">
                                  <div class="progress flex-grow-1" style="height: 5px;">
                                    <div class="progress-bar rounded bg-<?php echo $isDone ? 'success' : 'primary'; ?>"
                                      style="width: <?php echo $displayPct; ?>%;"></div>
                                  </div>
                                  <span class="text-muted fs-9 fw-bold"><?php echo $displayPct; ?>%</span>
                                </div>

                                <?php if ($isDone): ?>
                                  <span
                                    class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2.5 py-1 rounded-pill fs-8">
                                    <i class="bi bi-check-all me-1"></i> 100% Completed
                                  </span>
                                <?php else: ?>
                                  <span
                                    class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-2.5 py-1 rounded-pill fs-8">
                                    In Progress (<?php echo $displayPct; ?>%)
                                  </span>
                                <?php endif; ?>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

          <!-- Block: User Details -->
          <div class="moodle-card p-4 mb-4">
            <div class="text-center">
              <img src="<?php echo htmlspecialchars($student['avatar']); ?>"
                class="rounded-circle border border-primary border-opacity-20 mb-3 mx-auto"
                style="width: 80px; height: 80px; object-fit: cover;" alt="You">
              <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($student['name']); ?></h5>
              <p class="text-muted fs-8 mb-3"><?php echo htmlspecialchars($student['email']); ?></p>

              <div class="d-flex gap-2 justify-content-center">
                <span class="badge bg-light text-secondary border px-2 py-1 fs-8">
                  <?php echo $is_teacher ? 'Lecturer ID: ' : 'Academic ID: '; ?><?php echo htmlspecialchars($student['academic_id']); ?>
                </span>
                <span
                  class="badge bg-<?php echo $is_teacher ? 'primary' : 'success'; ?> bg-opacity-10 text-<?php echo $is_teacher ? 'primary' : 'success'; ?> border border-<?php echo $is_teacher ? 'primary' : 'success'; ?> border-opacity-30 px-2 py-1 fs-8">
                  <?php echo $is_teacher ? 'Teacher' : 'Student'; ?>
                </span>
              </div>

              <div class="mt-4 pt-3 border-top border-secondary border-opacity-10 row text-center">
                <?php if ($is_teacher): ?>
                  <div class="col-6 border-end">
                    <h4 class="fw-bold mb-0 text-primary fs-5"><?php echo $courses_count; ?></h4>
                    <small
                      class="text-muted fs-9 text-uppercase"><?php echo __('modules_taught', 'Modules Taught'); ?></small>
                  </div>
                  <div class="col-6">
                    <h4 class="fw-bold mb-0 text-orange fs-5"><?php echo $total_students_enrolled; ?></h4>
                    <small
                      class="text-muted fs-9 text-uppercase"><?php echo __('total_enrolled', 'Total Enrolled'); ?></small>
                  </div>
                <?php else: ?>
                  <div class="col-6 border-end">
                    <h4 class="fw-bold mb-0 text-primary fs-5"><?php echo $completed_lessons_count; ?></h4>
                    <small
                      class="text-muted fs-9 text-uppercase"><?php echo __('completed_tasks', 'Completed Tasks'); ?></small>
                  </div>
                  <div class="col-6">
                    <h4 class="fw-bold mb-0 text-orange fs-5"><?php echo $quizzes_completed_count; ?></h4>
                    <small
                      class="text-muted fs-9 text-uppercase"><?php echo __('quizzes_passed', 'Quizzes Passed'); ?></small>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if ($is_teacher): ?>
            <!-- Recent Quiz Submissions -->
            <div class="moodle-card p-4 mb-4">
              <h5 class="fw-bold mb-3 border-bottom pb-2 text-sm"><i
                  class="bi bi-patch-check me-2 text-success"></i>Recent Quiz Grades</h5>
              <?php if (empty($recent_submissions)): ?>
                <p class="text-muted fs-8 mb-0 italic text-center py-3">No student attempts recorded.</p>
              <?php else: ?>
                <div class="d-flex flex-column gap-3">
                  <?php foreach ($recent_submissions as $sub): ?>
                    <div class="p-2.5 border rounded bg-light fs-8">
                      <div class="d-flex align-items-center gap-2 mb-1.5">
                        <img src="<?php echo htmlspecialchars($sub['student_avatar']); ?>" class="rounded-circle"
                          style="width: 20px; height: 20px; object-fit: cover;" alt="Student">
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($sub['student_name']); ?></span>
                      </div>
                      <div class="d-flex justify-content-between text-muted fs-9">
                        <span class="text-truncate"
                          style="max-width: 170px;"><?php echo htmlspecialchars($sub['course_title']); ?></span>
                        <span
                          class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35">Correct:
                          <?php echo $sub['score']; ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

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

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>

  <!-- Navigation Drawer Toggle script -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const toggleBtn = document.getElementById('drawer-toggle');
      const drawer = document.getElementById('moodle-drawer');
      const wrapper = document.getElementById('moodle-content-wrapper');

      toggleBtn.addEventListener('click', function () {
        drawer.classList.toggle('collapsed');
        wrapper.classList.toggle('full-width');
      });
    });
  </script>

  <?php if ($is_teacher): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        // Elements
        const form = document.getElementById('create-course-form');
        const step1Div = document.getElementById('wizard-step-1');
        const step2Div = document.getElementById('wizard-step-2');
        const btnNextStep = document.getElementById('btn-next-step');
        const btnPrevStep = document.getElementById('btn-prev-step');
        const step1Indicator = document.getElementById('step-1-indicator');
        const step2Indicator = document.getElementById('step-2-indicator');

        const priceToggle = document.getElementById('price-toggle');
        const priceToggleLabel = document.getElementById('price-toggle-label');
        const priceInputContainer = document.getElementById('price-input-container');
        const coursePriceInput = document.getElementById('new-course-price');

        const dropzone = document.getElementById('thumbnail-dropzone');
        const fileInput = document.getElementById('new-course-thumbnail-file');
        const previewContainer = document.getElementById('thumbnail-preview-container');
        const previewImage = document.getElementById('thumbnail-preview');
        const removeThumbnailBtn = document.getElementById('remove-thumbnail-btn');

        const lessonsContainer = document.getElementById('lessons-container');
        const btnAddLesson = document.getElementById('btn-add-lesson');

        const courseTitleInput = document.getElementById('new-course-title');
        const courseIdInput = document.getElementById('new-course-id');

        // Auto-generate Course Code/Slug from Title
        let userEditedSlug = false;
        courseIdInput.addEventListener('input', function () {
          userEditedSlug = true;
        });

        courseTitleInput.addEventListener('input', function () {
          if (!userEditedSlug) {
            const slug = this.value
              .toLowerCase()
              .replace(/[^a-z0-9\s-]/g, '')
              .replace(/\s+/g, '-')
              .replace(/-+/g, '-');
            courseIdInput.value = slug;
          }
        });

        // Price toggle
        priceToggle.addEventListener('change', function () {
          if (this.checked) {
            priceToggleLabel.textContent = 'Free Course';
            priceInputContainer.style.display = 'none';
            coursePriceInput.value = '0.00';
            coursePriceInput.required = false;
          } else {
            priceToggleLabel.textContent = 'Paid Course';
            priceInputContainer.style.display = 'flex';
            coursePriceInput.required = true;
            coursePriceInput.focus();
          }
        });

        // Drag & Drop / File Upload handlers
        dropzone.addEventListener('click', function (e) {
          if (e.target.closest('#remove-thumbnail-btn')) return;
          fileInput.click();
        });

        dropzone.addEventListener('dragover', function (e) {
          e.preventDefault();
          dropzone.style.backgroundColor = 'rgba(15, 76, 129, 0.05)';
        });

        dropzone.addEventListener('dragleave', function () {
          dropzone.style.backgroundColor = '';
        });

        dropzone.addEventListener('drop', function (e) {
          e.preventDefault();
          dropzone.style.backgroundColor = '';
          if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files[0]);
          }
        });

        fileInput.addEventListener('change', function () {
          if (this.files && this.files[0]) {
            handleFileSelect(this.files[0]);
          }
        });

        function handleFileSelect(file) {
          if (!file.type.match('image.*')) {
            alert('Please select a valid image file (PNG, JPG, JPEG, WEBP).');
            return;
          }
          const reader = new FileReader();
          reader.onload = function (e) {
            previewImage.src = e.target.result;
            previewContainer.classList.remove('d-none');
          }
          reader.readAsDataURL(file);
        }

        removeThumbnailBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          fileInput.value = '';
          previewImage.src = '#';
          previewContainer.classList.add('d-none');
        });

        // Get all checked Target Audiences as comma-separated string
        function getSelectedTargetAudiences() {
          const checkboxes = document.querySelectorAll('.new-course-audience-checkbox:checked');
          return Array.from(checkboxes).map(cb => cb.value.trim()).filter(val => val.length > 0).join(', ');
        }

        // Handle Add New Target Audience button click
        const btnAddAudience = document.getElementById('btn-add-new-audience');
        const inputNewAudience = document.getElementById('add-new-audience-input');
        if (btnAddAudience && inputNewAudience) {
          btnAddAudience.addEventListener('click', function () {
            const newAudName = inputNewAudience.value.trim();
            if (!newAudName) {
              alert('Please enter a target audience name.');
              inputNewAudience.focus();
              return;
            }

            btnAddAudience.disabled = true;
            fetch('api/add_target_audience.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ name: newAudName })
            })
              .then(res => res.json())
              .then(data => {
                btnAddAudience.disabled = false;
                if (data.success) {
                  const container = document.getElementById('target-audience-checkbox-container');
                  const audId = 'aud-add-' + Math.random().toString(36).substring(2, 10);
                  
                  // Create new checkbox element
                  const div = document.createElement('div');
                  div.className = 'form-check mb-1';
                  div.innerHTML = `
                    <input class="form-check-input new-course-audience-checkbox" type="checkbox" value="${data.name}" id="${audId}" checked>
                    <label class="form-check-label fs-7 cursor-pointer" for="${audId}">
                      ${data.name}
                    </label>
                  `;
                  container.appendChild(div);
                  container.scrollTop = container.scrollHeight;
                  inputNewAudience.value = '';
                } else {
                  alert(data.message || 'Failed to add target audience.');
                }
              })
              .catch(err => {
                btnAddAudience.disabled = false;
                console.error('Error adding target audience:', err);
                alert('Server error adding target audience.');
              });
          });
        }

        // Step Navigation Validation
        function validateStep1() {
          const title = courseTitleInput.value.trim();
          const courseId = courseIdInput.value.trim();
          const category = document.getElementById('new-course-category').value;
          const targetAudience = getSelectedTargetAudiences();
          const description = document.getElementById('new-course-long-desc').value.trim();

          if (!title) {
            alert('Please enter a course title.');
            courseTitleInput.focus();
            return false;
          }
          if (!courseId) {
            alert('Please enter a unique course code/slug.');
            courseIdInput.focus();
            return false;
          }
          if (!category) {
            alert('Please select a category.');
            document.getElementById('new-course-category').focus();
            return false;
          }
          if (!targetAudience) {
            alert('Please select at least one target audience.');
            return false;
          }
          if (!description) {
            alert('Please enter a course description.');
            document.getElementById('new-course-long-desc').focus();
            return false;
          }
          return true;
        }

        // Next / Back buttons
        btnNextStep.addEventListener('click', function () {
          if (validateStep1()) {
            step1Div.style.display = 'none';
            step2Div.style.display = 'block';
            step1Indicator.classList.remove('active-step');
            step1Indicator.style.borderBottom = '';
            step1Indicator.classList.add('text-muted');

            step2Indicator.classList.add('active-step');
            step2Indicator.style.borderBottom = '2px solid #0f4c81';
            step2Indicator.classList.remove('text-muted');
          }
        });

        btnPrevStep.addEventListener('click', function () {
          step2Div.style.display = 'none';
          step1Div.style.display = 'block';

          step2Indicator.classList.remove('active-step');
          step2Indicator.style.borderBottom = '';
          step2Indicator.classList.add('text-muted');

          step1Indicator.classList.add('active-step');
          step1Indicator.style.borderBottom = '2px solid #0f4c81';
          step1Indicator.classList.remove('text-muted');
        });

        // Add More Lesson cloning
        btnAddLesson.addEventListener('click', function () {
          const rows = lessonsContainer.querySelectorAll('.lesson-row');
          const firstRow = rows[0];

          // Clone row
          const newRow = firstRow.cloneNode(true);

          // Clear cloned inputs
          newRow.querySelectorAll('input').forEach(input => {
            input.value = '';
          });

          // Add to container
          lessonsContainer.appendChild(newRow);

          // Refresh label indexes and show remove buttons
          refreshLessonIndexes();
        });

        // Remove lesson row delegate
        lessonsContainer.addEventListener('click', function (e) {
          const removeBtn = e.target.closest('.btn-remove-lesson');
          if (removeBtn) {
            const row = removeBtn.closest('.lesson-row');
            const rows = lessonsContainer.querySelectorAll('.lesson-row');
            if (rows.length > 1) {
              row.remove();
              refreshLessonIndexes();
            }
          }
        });

        function refreshLessonIndexes() {
          const rows = lessonsContainer.querySelectorAll('.lesson-row');
          rows.forEach((row, index) => {
            // Label
            const label = row.querySelector('.lesson-index-label');
            label.innerHTML = `<i class="bi bi-play-circle-fill text-primary me-1"></i>Lesson #${index + 1}`;

            // Remove button visibility
            const removeBtn = row.querySelector('.btn-remove-lesson');
            if (index === 0) {
              removeBtn.style.display = 'none';
            } else {
              removeBtn.style.display = 'inline-block';
            }
          });
        }

        // Final submit
        form.addEventListener('submit', function (e) {
          e.preventDefault();

          // Validate lessons count & entries
          const lessonRows = lessonsContainer.querySelectorAll('.lesson-row');
          const lessons = [];
          let hasErrors = false;

          lessonRows.forEach((row, index) => {
            const title = row.querySelector('.lesson-title-input').value.trim();
            const videoUrl = row.querySelector('.lesson-video-input').value.trim();

            if (!title || !videoUrl) {
              alert(`Please fill in all details for Lesson #${index + 1}.`);
              hasErrors = true;
              return;
            }
            lessons.push({ title: title, video_url: videoUrl });
          });

          if (hasErrors) return;

          const submitBtn = document.getElementById('btn-submit-course');
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Submitting...';

          // Construct Form Data
          const formData = new FormData();
          formData.append('title', courseTitleInput.value.trim());
          formData.append('course_id', courseIdInput.value.trim());
          formData.append('category', document.getElementById('new-course-category').value);
          formData.append('target_audience', getSelectedTargetAudiences());
          formData.append('price', coursePriceInput.value);
          formData.append('level', document.getElementById('new-course-level').value);
          formData.append('duration', document.getElementById('new-course-duration').value);
          formData.append('price', coursePriceInput.value);
          formData.append('level', document.getElementById('new-course-level').value);
          formData.append('duration', document.getElementById('new-course-duration').value);
          formData.append('long_description', document.getElementById('new-course-long-desc').value.trim());

          if (fileInput.files[0]) {
            formData.append('thumbnail', fileInput.files[0]);
          }
          formData.append('lessons', JSON.stringify(lessons));

          fetch('api/create_course.php', {
            method: 'POST',
            body: formData
          })
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                alert('Course & lessons created successfully!');
                location.reload();
              } else {
                alert('Failed to submit course: ' + data.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Submit Course';
              }
            })
            .catch(error => {
              console.error('Error submitting course:', error);
              alert('An error occurred while connecting to the server.');
              submitBtn.disabled = false;
              submitBtn.innerHTML = 'Submit Course';
            });
        });
      });
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
        .catch(err => console.error('Error marking notifications read:', err));
    }
  </script>
</body>

</html>