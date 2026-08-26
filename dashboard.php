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
    $stmt = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) as live_enrolled_count 
                           FROM courses c 
                           WHERE c.tutor_id = ? 
                           ORDER BY c.created_at DESC");
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
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE ((status = 'approved' OR status = 'active') AND is_archived = 0) OR tutor_id = ?");
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

    // Fetch enrolled courses directly (enrolled students retain full access even if course is disabled/archived)
    if (!empty($enrolled_ids)) {
      $in_clause = implode(',', array_fill(0, count($enrolled_ids), '?'));
      $stmt = $pdo->prepare("SELECT * FROM courses WHERE id IN ($in_clause)");
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

    // Fetch existing certificate requests for this student
    $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cert_requests_data = $stmt->fetchAll();
    $cert_requests_map = [];
    foreach ($cert_requests_data as $cr) {
      $cert_requests_map[$cr['course_id']] = $cr;
    }
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
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .no-caret::after {
      display: none !important;
    }
  </style>
</head>

<body class="bg-light">

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Moodle Left Navigation Drawer -->
  <aside id="moodle-drawer" class="moodle-drawer collapsed">
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
                          <div class="col-12">
                            <label class="form-label fw-semibold text-secondary fs-8 d-flex align-items-center justify-content-between mb-1">
                              <span><i class="bi bi-paperclip text-primary me-1"></i>Lesson Attachments (PDF, Word, Images, ZIP)</span>
                              <small class="text-muted fw-normal">Optional</small>
                            </label>
                            <input type="file" class="form-control form-control-sm lesson-attachments-input" multiple
                              accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.webp,.zip,.rar">
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
                            <?php elseif ($course['status'] === 'disabled' || !empty($course['is_archived']) || !empty($course['deleted_at'])): 
                              $d_time = !empty($course['deleted_at']) ? strtotime($course['deleted_at']) : time();
                              $d_left = max(0, 14 - floor((time() - $d_time) / 86400));
                            ?>
                              <span
                                class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-35 fs-9 py-0.5 px-2 rounded-pill"><i
                                  class="bi bi-clock-history me-1"></i>Disabled (<?php echo $d_left; ?>d left to restore)</span>
                            <?php elseif (($course['status'] ?? 'approved') === 'approved' || ($course['status'] ?? '') === 'active'): ?>
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

                    // Accurate progress metrics from MySQL
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

                    // Quiz completion check & performance summary
                    $c_quiz_check = check_course_quizzes_completed($pdo, $user_id, $c['id']);
                    $all_quizzes_done = $c_quiz_check['all_completed'];
                    $can_request_cert = ($is_course_100 && $all_quizzes_done);

                    $qStmt = $pdo->prepare("SELECT * FROM quiz_results WHERE user_id = ? AND course_id = ?");
                    $qStmt->execute([$user_id, $c['id']]);
                    $c_quiz_res = $qStmt->fetch();

                    $qaStmt = $pdo->prepare("SELECT MAX(score) as best_score, MAX(total_questions) as total_questions, MAX(updated_at) as last_attempt_at FROM quiz_attempts WHERE user_id = ? AND course_id = ?");
                    $qaStmt->execute([$user_id, $c['id']]);
                    $c_quiz_attempt = $qaStmt->fetch();

                    $quiz_score_str = ($c_quiz_check['total_quizzes'] > 0) ? "Progress: 100% | Quizzes: {$c_quiz_check['completed_quizzes']}/{$c_quiz_check['total_quizzes']} Completed" : "Progress: 100% | No Quiz Required";
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
                    $existing_cert = $cert_requests_map[$c['id']] ?? null;

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
                    ?>

                    <!-- Moodle Course Row -->
                    <div class="p-3 border rounded bg-white hover:shadow-sm transition-all">
                      <div class="row align-items-center gy-3">
                        <div class="col-sm-2 col-3">
                          <img src="<?php echo htmlspecialchars($course['thumbnail']); ?>" class="img-fluid rounded"
                            alt="Thumbnail" style="width: 100%; max-height: 60px; object-fit: cover;">
                        </div>
                        <div class="col-sm-6 col-9">
                          <span
                            class="badge bg-light text-primary mb-1 border fs-8"><?php echo htmlspecialchars($course['category']); ?></span>
                          <h6 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($course['title']); ?></h6>

                          <!-- Progress Bar -->
                          <div class="d-flex align-items-center gap-3">
                            <div class="progress flex-grow-1" style="height: 6px;">
                              <div class="progress-bar rounded <?php echo $is_course_100 ? 'bg-success' : 'bg-primary'; ?>" role="progressbar"
                                style="width: <?php echo $progress_percent; ?>%;"
                                aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="text-dark fs-8 fw-bold"><?php echo $progress_percent; ?>%</span>
                          </div>
                        </div>
                        <div class="col-sm-4 text-center text-sm-end d-flex flex-wrap justify-content-end align-items-center gap-1.5">
                          <a href="classroom.php?course_id=<?php echo urlencode($course['id']); ?>"
                            class="btn btn-outline-primary btn-sm px-3 rounded-pill">
                            <i class="bi bi-folder2-open me-1"></i> <?php echo __('access', 'Access'); ?>
                          </a>

                          <?php if (!$is_teacher): ?>
                            <?php if ($existing_cert): ?>
                              <?php if (in_array($existing_cert['status'], ['approved', 'issued'])): ?>
                                <a href="javascript:void(0)" class="btn btn-success btn-sm px-3 rounded-pill fw-bold shadow-sm d-inline-flex align-items-center gap-1 text-white cert-status-btn"
                                  data-cert='<?php echo $cert_json; ?>'
                                  onclick="handleOpenCertStatus(this)">
                                  <i class="bi bi-patch-check-fill"></i> <?php echo __('certificate_issued', 'Certificate Issued'); ?>
                                </a>
                              <?php elseif ($existing_cert['status'] === 'dispatched'): ?>
                                <a href="javascript:void(0)" class="btn btn-info text-white btn-sm px-3 rounded-pill fw-bold shadow-sm d-inline-flex align-items-center gap-1 cert-status-btn"
                                  data-cert='<?php echo $cert_json; ?>'
                                  onclick="handleOpenCertStatus(this)">
                                  <i class="bi bi-truck"></i> <?php echo __('certificate_dispatched', 'Certificate Dispatched'); ?>
                                </a>
                              <?php elseif ($existing_cert['status'] === 'processing'): ?>
                                <a href="javascript:void(0)" class="btn btn-primary btn-sm px-3 rounded-pill fw-bold shadow-sm d-inline-flex align-items-center gap-1 text-white cert-status-btn"
                                  data-cert='<?php echo $cert_json; ?>'
                                  onclick="handleOpenCertStatus(this)">
                                  <i class="bi bi-gear-wide-connected"></i> <?php echo __('certificate_processing', 'Certificate Processing'); ?>
                                </a>
                              <?php else: ?>
                                <a href="javascript:void(0)" class="btn btn-warning bg-opacity-25 text-dark border-warning btn-sm px-3 rounded-pill fw-bold shadow-sm d-inline-flex align-items-center gap-1 cert-status-btn"
                                  data-cert='<?php echo $cert_json; ?>'
                                  onclick="handleOpenCertStatus(this)">
                                  <i class="bi bi-clock-history"></i> <?php echo __('certificate_requested', 'Certificate Requested'); ?>
                                </a>
                              <?php endif; ?>
                            <?php else: ?>
                              <?php if ($can_request_cert): ?>
                                <a href="javascript:void(0)" class="btn btn-success btn-sm px-3.5 py-1.5 rounded-pill fw-bold shadow-sm d-inline-flex align-items-center gap-1 text-white cert-req-btn"
                                  style="background-color: #28a745; border-color: #28a745;"
                                  data-cert='<?php echo $cert_json; ?>'
                                  onclick="handleOpenCertModal(this)">
                                  <i class="bi bi-award-fill"></i> <?php echo __('request_certificate', 'Request Certificate'); ?>
                                </a>
                              <?php else: ?>
                                <a href="javascript:void(0)" class="btn btn-light border text-muted btn-sm px-3 rounded-pill d-inline-flex align-items-center gap-1 cert-locked-btn"
                                  style="cursor: pointer;"
                                  data-course-title="<?php echo htmlspecialchars($c['title']); ?>"
                                  data-progress="<?php echo $progress_percent; ?>"
                                  data-quizzes-done="<?php echo $all_quizzes_done ? '1' : '0'; ?>"
                                  data-quizzes-completed="<?php echo (int)$c_quiz_check['completed_quizzes']; ?>"
                                  data-quizzes-total="<?php echo (int)$c_quiz_check['total_quizzes']; ?>"
                                  data-missing-quizzes="<?php echo htmlspecialchars(implode(', ', $c_quiz_check['missing_quiz_titles'] ?? [])); ?>"
                                  data-classroom-url="classroom.php?course_id=<?php echo urlencode($c['id']); ?>"
                                  data-quiz-url="quiz.php?course_id=<?php echo urlencode($c['id']); ?>"
                                  onclick="handleLockedCertClick(this)"
                                  title="<?php echo __('certificate_locked_tip', 'Complete 100% of course lessons & quizzes to unlock your certificate.'); ?>">
                                  <i class="bi bi-lock-fill text-secondary"></i> <?php echo __('request_certificate', 'Request Certificate'); ?>
                                </a>
                              <?php endif; ?>
                            <?php endif; ?>
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

  <!-- Local Bootstrap 5 Bundle JS & Libraries -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/html2canvas.min.js"></script>
  <script src="assets/js/jspdf.umd.min.js"></script>



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

          // Append files for each lesson row
          lessonRows.forEach((row, index) => {
            const attachInput = row.querySelector('.lesson-attachments-input');
            if (attachInput && attachInput.files) {
              for (let f = 0; f < attachInput.files.length; f++) {
                formData.append(`lesson_files_${index}[]`, attachInput.files[f]);
              }
            }
          });

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
                <i class="bi bi-award text-warning fs-6"></i>
                <span><?php echo __('delivery_method', 'Certificate Format & Delivery Options'); ?></span>
              </div>

              <div class="d-flex flex-column gap-2.5 mb-3">
                <!-- Option 1: Digital Copy (PDF) -->
                <div class="form-check p-3 border rounded-3 bg-light cursor-pointer hover:bg-white transition-all d-flex align-items-start gap-2.5">
                  <input class="form-check-input ms-0 mt-1" type="checkbox" id="cert-option-digital" checked onchange="handleCertOptionChange('digital')">
                  <label class="form-check-label fw-semibold text-dark cursor-pointer flex-grow-1" for="cert-option-digital">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                      <span class="d-flex align-items-center gap-1.5"><i class="bi bi-file-earmark-pdf-fill text-danger fs-6"></i> <?php echo __('digital_copy_title', 'Digital Copy (Verifiable PDF e-Certificate)'); ?></span>
                      <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-2 py-0.5 rounded-pill fs-9 fw-bold">FREE INCLUDED</span>
                    </div>
                    <small class="text-muted fw-normal fs-9 mt-1 d-block">High-resolution PDF certificate with secure QR verification code emailed immediately upon academic approval.</small>
                  </label>
                </div>

                <!-- Option 2: Printed Hard Copy (Cash on Delivery) -->
                <div class="form-check p-3 border rounded-3 bg-light cursor-pointer hover:bg-white transition-all d-flex align-items-start gap-2.5">
                  <input class="form-check-input ms-0 mt-1" type="checkbox" id="cert-option-hardcopy" onchange="handleCertOptionChange('hardcopy')">
                  <label class="form-check-label fw-semibold text-dark cursor-pointer flex-grow-1" for="cert-option-hardcopy">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-1">
                      <span class="d-flex align-items-center gap-1.5"><i class="bi bi-box-seam-fill text-primary fs-6"></i> <?php echo __('printed_hard_copy_title', 'Printed Hard Copy (Doorstep Delivery via Cash on Delivery)'); ?></span>
                      <span class="badge bg-warning bg-opacity-20 text-warning-emphasis border border-warning border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-bold">Cash on Delivery</span>
                    </div>
                    <small class="text-muted fw-normal fs-9 mt-1 d-block">Official embossed parchment certificate dispatched to your doorstep. <em>Selecting hard copy automatically includes the digital copy.</em></small>
                  </label>
                </div>
              </div>

              <!-- Cash on Delivery Details & Delivery Information -->
              <div id="home-delivery-details" style="display: none;" class="p-3 bg-light rounded-3 border mt-3">
                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                  <h6 class="fw-bold text-dark fs-8 mb-0 d-flex align-items-center gap-1.5">
                    <i class="bi bi-cash-coin text-success fs-6"></i>
                    <span><?php echo __('cod_details_title', 'Cash on Delivery (COD) & Postal Details'); ?></span>
                  </h6>
                  <span class="badge bg-secondary bg-opacity-10 text-secondary border fs-9">Courier Service</span>
                </div>

                <!-- Fees and Timeframe Info Note -->
                <?php
                $cert_cod_title = get_site_setting('cert_cod_title', 'Cash on Delivery & Courier Details:');
                $cert_cod_fee_note = get_site_setting('cert_cod_fee_note', 'LKR 1,500 Cash on Delivery fee for embossed certificate printing, security hard-folder, and island-wide registered courier handling (Payable in Cash to the courier delivery rider upon package arrival). The digital e-certificate remains 100% free.');
                $cert_cod_timeframe_note = get_site_setting('cert_cod_timeframe_note', 'Dispatched within 24–48 hours after application approval. Island-wide doorstep delivery takes 2 to 4 working days.');
                $cert_cod_custom_notice = get_site_setting('cert_cod_custom_notice', '');
                ?>
                <div class="alert alert-info border-info border-opacity-25 bg-info bg-opacity-10 py-2.5 px-3 rounded-3 mb-3 fs-9 text-dark">
                  <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-info-circle-fill text-info fs-6 mt-0.5"></i>
                    <div class="flex-grow-1">
                      <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($cert_cod_title); ?></div>
                      <ul class="mb-0 ps-3 text-secondary fs-9" style="line-height: 1.5;">
                        <li><strong>Associated Fee:</strong> <?php echo htmlspecialchars($cert_cod_fee_note); ?></li>
                        <li><strong>Delivery Timeframe:</strong> <?php echo htmlspecialchars($cert_cod_timeframe_note); ?></li>
                        <?php if (!empty($cert_cod_custom_notice)): ?>
                          <li><strong>Important:</strong> <?php echo htmlspecialchars($cert_cod_custom_notice); ?></li>
                        <?php endif; ?>
                      </ul>
                    </div>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('delivery_address', 'Delivery Address (Street / House / Apartment)'); ?> <span class="text-danger">*</span></label>
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
                  <div class="col-md-6">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('cod_phone', 'Cash on Delivery Contact Phone'); ?> <span class="text-danger">*</span></label>
                    <input type="tel" id="cert-modal-cod-phone" class="form-control form-control-sm" placeholder="e.g. +94 77 123 4567 (Phone for courier rider)">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1"><?php echo __('delivery_notes', 'Special Delivery Instructions / Landmarks (Optional)'); ?></label>
                    <input type="text" id="cert-modal-notes" class="form-control form-control-sm" placeholder="e.g. Near clock tower, call before delivery">
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
      <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden; background-color: #f8fafc;">
        
        <!-- Modal Header with Enterprise Academic Gradient -->
        <div class="modal-header py-3.5 px-4 position-relative" style="background: linear-gradient(135deg, #091527 0%, #0f3d6c 50%, #174b85 100%); border-bottom: 1px solid rgba(255,255,255,0.1); color: #ffffff;">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 bg-white bg-opacity-10 p-2.5 text-warning border border-white border-opacity-15 shadow-sm d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; backdrop-filter: blur(4px);">
              <i class="bi bi-patch-check-fill fs-4 text-warning"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="certificateStatusModalLabel"><?php echo __('certificate_status_tracker', 'Certificate Application Status & Tracker'); ?></h5>
                <span class="badge bg-warning text-dark border border-warning px-2.5 py-0.5 rounded-pill fs-9 fw-bold">Official Credential</span>
              </div>
              <small class="text-white text-opacity-75 fs-9 d-flex align-items-center gap-1.5 mt-0.5">
                <i class="bi bi-building-check"></i> Institutional Academic Verification & Delivery Registry
              </small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          
          <!-- Top Reference Code & Meta Bar -->
          <div class="bg-white p-3.5 rounded-4 border border-slate-200 shadow-xs mb-3">
            <div class="row g-2 align-items-center">
              <div class="col-sm-7 d-flex align-items-center gap-2.5">
                <div class="p-2 rounded-3 bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20">
                  <i class="bi bi-upc-scan fs-5"></i>
                </div>
                <div>
                  <small class="text-muted text-uppercase fs-9 fw-bold d-block" style="letter-spacing: 0.06em;">Tracking Reference Code</small>
                  <div class="d-flex align-items-center gap-2 mt-0.5">
                    <span class="font-monospace fw-bold fs-7 text-dark bg-light px-2 py-0.5 rounded border" id="tracker-cert-code">CERT-CSLK-00000000</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2 rounded-pill fs-9 d-inline-flex align-items-center gap-1" onclick="copyTrackerCertCode()" title="Copy Tracking Code">
                      <i class="bi bi-clipboard"></i><span id="copy-btn-text">Copy</span>
                    </button>
                  </div>
                </div>
              </div>
              <div class="col-sm-5 text-sm-end">
                <small class="text-muted text-uppercase fs-9 fw-bold d-block" style="letter-spacing: 0.06em;">Application Registered</small>
                <span class="fs-8 fw-semibold text-dark mt-0.5 d-inline-flex align-items-center gap-1.5">
                  <i class="bi bi-calendar-check text-success"></i> <span id="tracker-submitted-at">Aug 18, 2026</span>
                </span>
              </div>
            </div>
          </div>

          <!-- Dynamic Status Hero Alert Card -->
          <div id="tracker-status-hero" class="p-3.5 rounded-4 border mb-3 shadow-xs text-start">
            <!-- Dynamically populated by JS -->
          </div>

          <!-- 4-Stage Interactive Visual Progress Stepper -->
          <div class="bg-white p-4 rounded-4 border border-slate-200 mb-3 shadow-xs">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
              <h6 class="fw-bold text-dark fs-8 mb-0 d-flex align-items-center gap-1.5">
                <i class="bi bi-diagram-3-fill text-primary"></i>
                <span>Credential Processing Pipeline</span>
              </h6>
              <span class="badge bg-light text-secondary border fs-9 fw-normal" id="tracker-stage-indicator">Stage 1 of 4</span>
            </div>

            <div class="d-flex justify-content-between align-items-start position-relative my-3 px-2">
              <!-- Stepper Connecting Line Background -->
              <div class="position-absolute start-0 w-100 bg-secondary bg-opacity-20" style="top: 18px; height: 3px; z-index: 1;"></div>
              <!-- Stepper Active Progress Fill Line -->
              <div id="stepper-progress-bar" class="position-absolute start-0 bg-success transition-all" style="top: 18px; height: 3px; z-index: 2; width: 25%;"></div>

              <!-- Step 1: Request Lodged -->
              <div class="position-relative text-center flex-fill" style="z-index: 3;">
                <div id="step-1-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1.5 border border-2 border-white shadow-sm" style="width: 36px; height: 36px; background-color: #28a745; color: #fff; font-size: 0.85rem;">
                  <i class="bi bi-check-lg fw-bold"></i>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Request Lodged</span>
                <small class="text-muted fs-9" id="step-1-date">Submitted</small>
              </div>

              <!-- Step 2: Academic Audit -->
              <div class="position-relative text-center flex-fill" style="z-index: 3;">
                <div id="step-2-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1.5 border border-2 border-white shadow-sm" style="width: 36px; height: 36px; background-color: #28a745; color: #fff; font-size: 0.85rem;">
                  <i class="bi bi-check-lg fw-bold"></i>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Academic Review</span>
                <small class="text-muted fs-9">100% Verified</small>
              </div>

              <!-- Step 3: Production / Processing -->
              <div class="position-relative text-center flex-fill" style="z-index: 3;">
                <div id="step-3-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1.5 border border-2 border-white shadow-sm" style="width: 36px; height: 36px; background-color: #94a3b8; color: #fff; font-size: 0.85rem;">
                  <span class="fw-bold">3</span>
                </div>
                <span class="fs-9 fw-bold text-dark d-block">Production</span>
                <small class="text-muted fs-9">Credential Prep</small>
              </div>

              <!-- Step 4: Dispatched / Issued -->
              <div class="position-relative text-center flex-fill" style="z-index: 3;">
                <div id="step-4-circle" class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1.5 border border-2 border-white shadow-sm" style="width: 36px; height: 36px; background-color: #94a3b8; color: #fff; font-size: 0.85rem;">
                  <span class="fw-bold">4</span>
                </div>
                <span class="fs-9 fw-bold text-dark d-block" id="step-4-label">Dispatched / Issued</span>
                <small class="text-muted fs-9">Ready</small>
              </div>
            </div>
          </div>

          <!-- Summary Grid with Elevated Cards -->
          <div class="row g-3">
            <!-- Academic Evaluation Dossier -->
            <div class="col-md-6">
              <div class="bg-white p-3.5 rounded-4 border border-slate-200 h-100 shadow-xs d-flex flex-column justify-content-between">
                <div>
                  <div class="d-flex align-items-center justify-content-between pb-2 mb-2.5 border-bottom">
                    <span class="fw-bold fs-8 text-primary d-flex align-items-center gap-1.5">
                      <i class="bi bi-mortarboard-fill"></i> Academic Evaluation
                    </span>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill fs-9 px-2 py-0.5">
                      <i class="bi bi-check-circle-fill me-1"></i> Passed & Qualified
                    </span>
                  </div>

                  <div class="mb-2.5">
                    <small class="text-muted fs-9 text-uppercase fw-bold d-block" style="letter-spacing: 0.05em;">Enrolled Course</small>
                    <span class="fw-bold text-dark fs-8 d-block text-truncate" id="tracker-course-title" title="Course Title">Course Title</span>
                  </div>

                  <div class="p-2.5 bg-light rounded-3 border mb-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <small class="text-muted fs-9 text-uppercase fw-bold">Performance Summary</small>
                      <span class="text-success fw-bold fs-9" id="tracker-progress-score">Progress: 100%</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                      <div class="progress-bar bg-success" role="progressbar" style="width: 100%;" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                  </div>

                  <div class="d-flex align-items-center justify-content-between text-secondary fs-8">
                    <span><i class="bi bi-calendar-event me-1 text-primary"></i>Completion Date:</span>
                    <strong class="text-dark" id="tracker-completion-date">Aug 18, 2026</strong>
                  </div>
                </div>

                <div class="mt-3 pt-2 border-top text-muted fs-9 d-flex align-items-center gap-1.5">
                  <i class="bi bi-shield-lock-fill text-success"></i> Academic clearance verified by Board of Studies
                </div>
              </div>
            </div>

            <!-- Recipient & Delivery Fulfillment Dossier -->
            <div class="col-md-6">
              <div class="bg-white p-3.5 rounded-4 border border-slate-200 h-100 shadow-xs d-flex flex-column justify-content-between">
                <div>
                  <div class="d-flex align-items-center justify-content-between pb-2 mb-2.5 border-bottom">
                    <span class="fw-bold fs-8 text-dark d-flex align-items-center gap-1.5">
                      <i class="bi bi-person-badge-fill text-primary"></i> Recipient & Fulfillment
                    </span>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill fs-9 px-2 py-0.5">
                      Identity Verified
                    </span>
                  </div>

                  <div class="mb-2">
                    <small class="text-muted fs-9 text-uppercase fw-bold d-block" style="letter-spacing: 0.05em;">Full Name on Certificate</small>
                    <span class="fw-bold text-dark fs-8" id="tracker-recipient-name">Student Full Name</span>
                  </div>

                  <div class="row g-2 mb-2.5">
                    <div class="col-6">
                      <div class="p-2 bg-light rounded-2 border">
                        <small class="text-muted fs-9 text-uppercase fw-bold d-block">NIC / Passport</small>
                        <span class="text-dark fs-8 font-monospace fw-semibold" id="tracker-nic">000000000000</span>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="p-2 bg-light rounded-2 border">
                        <small class="text-muted fs-9 text-uppercase fw-bold d-block">Primary Contact</small>
                        <span class="text-dark fs-8 fw-semibold" id="tracker-mobile">+94 77 000 0000</span>
                      </div>
                    </div>
                  </div>

                  <div>
                    <small class="text-muted fs-9 text-uppercase fw-bold d-block mb-1" style="letter-spacing: 0.05em;">Fulfillment Method & Destination</small>
                    <div id="tracker-delivery-display" class="fs-8">
                      <!-- Populated dynamically by JS -->
                    </div>
                  </div>
                </div>

                <div class="mt-3 pt-2 border-top text-muted fs-9 d-flex align-items-center gap-1.5">
                  <i class="bi bi-qr-code-scan text-primary"></i> Tamper-proof credential security with institutional QR code
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer bg-white border-top py-3 px-4 d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-light rounded-pill px-4 fs-8 fw-semibold text-secondary border" data-bs-dismiss="modal"><?php echo __('cancel', 'Close'); ?></button>
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

    function handleOpenCertModal(btn) {
      try {
        const raw = btn.getAttribute('data-cert');
        const data = raw ? JSON.parse(raw) : null;
        openCertificateModal(data);
      } catch(e) {
        console.error('Error parsing certificate data:', e);
      }
    }

    function handleOpenCertStatus(btn) {
      try {
        const raw = btn.getAttribute('data-cert');
        const data = raw ? JSON.parse(raw) : null;
        openCertificateStatusModal(data);
      } catch(e) {
        console.error('Error parsing certificate status data:', e);
      }
    }

    function handleLockedCertClick(el) {
      const title = el.getAttribute('data-course-title') || 'Course';
      const progress = parseInt(el.getAttribute('data-progress') || '0', 10);
      const quizzesDone = el.getAttribute('data-quizzes-done') === '1';
      const qCompleted = parseInt(el.getAttribute('data-quizzes-completed') || '0', 10);
      const qTotal = parseInt(el.getAttribute('data-quizzes-total') || '0', 10);
      const missingQuizzes = el.getAttribute('data-missing-quizzes') || '';
      const classroomUrl = el.getAttribute('data-classroom-url') || 'classroom.php';
      const quizUrl = el.getAttribute('data-quiz-url') || classroomUrl;

      let lockedModal = document.getElementById('certLockedInfoModal');
      if (!lockedModal) {
        lockedModal = document.createElement('div');
        lockedModal.id = 'certLockedInfoModal';
        lockedModal.className = 'modal fade text-dark';
        lockedModal.tabIndex = -1;
        lockedModal.innerHTML = `
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
              <div class="modal-header border-0 bg-light p-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="rounded-circle bg-warning bg-opacity-25 p-3 text-warning d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-award fs-4 text-dark"></i>
                  </div>
                  <div>
                    <h5 class="modal-title fw-bold text-dark mb-0 fs-6">Certificate Requirements</h5>
                    <small class="text-muted fs-8" id="locked-cert-course-name"></small>
                  </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body p-4 pt-3 text-center">
                <div class="p-3 bg-light rounded-3 border mb-3 text-start">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold text-secondary fs-8">Lesson Videos Progress</span>
                    <span class="fw-bold text-dark fs-8" id="locked-cert-progress-text">0%</span>
                  </div>
                  <div class="progress mb-3" style="height: 8px;">
                    <div class="progress-bar bg-primary" id="locked-cert-progress-bar" style="width: 0%;"></div>
                  </div>

                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold text-secondary fs-8">Course Quizzes</span>
                    <span class="fw-bold text-dark fs-8" id="locked-cert-quiz-text">0 / 0 Completed</span>
                  </div>
                  <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" id="locked-cert-quiz-bar" style="width: 0%;"></div>
                  </div>
                </div>
                <p class="text-muted fs-7 mb-4 text-start" id="locked-cert-desc-text">
                  Official verified certificates are unlocked once you have completed <strong>100%</strong> of the course lessons and all course quizzes.
                </p>
                <div class="d-flex justify-content-center gap-2">
                  <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                  <a id="locked-cert-continue-btn" href="classroom.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #0f4c81;">
                    <i class="bi bi-play-circle-fill me-1"></i> Continue Learning
                  </a>
                </div>
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(lockedModal);
      }

      document.getElementById('locked-cert-course-name').textContent = title;
      document.getElementById('locked-cert-progress-text').textContent = progress + '% Completed';
      document.getElementById('locked-cert-progress-bar').style.width = progress + '%';

      const quizText = document.getElementById('locked-cert-quiz-text');
      const quizBar = document.getElementById('locked-cert-quiz-bar');
      const descText = document.getElementById('locked-cert-desc-text');
      const actionBtn = document.getElementById('locked-cert-continue-btn');

      if (qTotal > 0) {
        const qPct = Math.round((qCompleted / qTotal) * 100);
        quizText.textContent = `${qCompleted} of ${qTotal} Completed (${qPct}%)`;
        quizBar.style.width = `${qPct}%`;
      } else {
        quizText.textContent = 'No Quizzes Required';
        quizBar.style.width = '100%';
      }

      if (progress >= 100 && !quizzesDone && qTotal > 0) {
        descText.innerHTML = `You have watched and completed all lesson videos! <br><br><span class="text-danger fw-bold"><i class="bi bi-patch-question-fill me-1"></i>Action Required:</span> You must complete all course quizzes <strong>(${qCompleted} of ${qTotal} completed)</strong> to unlock and request your official Course Certificate.`;
        actionBtn.href = quizUrl;
        actionBtn.innerHTML = '<i class="bi bi-patch-question-fill me-1"></i> Complete Course Quizzes';
        actionBtn.className = 'btn btn-warning text-dark rounded-pill px-4 fw-bold shadow-sm';
      } else {
        descText.innerHTML = `Official verified certificates are unlocked once you have completed <strong>100%</strong> of the course lessons and all course quizzes. Continue your learning to earn your certificate!`;
        actionBtn.href = classroomUrl;
        actionBtn.innerHTML = '<i class="bi bi-play-circle-fill me-1"></i> Continue Learning';
        actionBtn.className = 'btn btn-primary rounded-pill px-4 fw-bold shadow-sm';
        actionBtn.style.backgroundColor = '#0f4c81';
      }

      const bsModal = new bootstrap.Modal(lockedModal);
      bsModal.show();
    }

    function openCertificateModal(data) {
      if (!data) return;
      if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch(e) {}
      }
      document.getElementById('cert-modal-course-id').value = data.course_id || '';
      document.getElementById('cert-modal-course-title').value = data.course_title || '';
      document.getElementById('cert-modal-email').value = data.registered_email || '';
      document.getElementById('cert-modal-completion-date').value = data.completion_date || '';
      document.getElementById('cert-modal-progress-score').value = data.progress_score_summary || 'Progress: 100%';
      document.getElementById('cert-modal-fullname').value = data.full_name_on_certificate || data.student_name || '';
      document.getElementById('cert-modal-nic').value = data.nic_number || '';
      document.getElementById('cert-modal-mobile').value = data.mobile_number || '';

      const alertBox = document.getElementById('cert-form-alert');
      if (alertBox) {
        alertBox.className = 'd-none alert mb-3 py-2 px-3 fs-8';
        alertBox.innerHTML = '';
      }

      if (data.delivery_method === 'home_delivery') {
        const hardCopyEl = document.getElementById('cert-option-hardcopy');
        if (hardCopyEl) hardCopyEl.checked = true;
        const digEl = document.getElementById('cert-option-digital');
        if (digEl) digEl.checked = true;
        const delivDiv = document.getElementById('home-delivery-details');
        if (delivDiv) delivDiv.style.display = 'block';
        if (document.getElementById('cert-modal-address')) document.getElementById('cert-modal-address').value = data.delivery_address || '';
        if (document.getElementById('cert-modal-city')) document.getElementById('cert-modal-city').value = data.city || '';
        if (document.getElementById('cert-modal-postal')) document.getElementById('cert-modal-postal').value = data.postal_code || '';
        if (document.getElementById('cert-modal-district')) document.getElementById('cert-modal-district').value = data.district || '';
        if (document.getElementById('cert-modal-notes')) document.getElementById('cert-modal-notes').value = data.delivery_notes || '';
        const codPhoneEl = document.getElementById('cert-modal-cod-phone');
        if (codPhoneEl) {
          codPhoneEl.value = data.cod_phone || data.mobile_number || '';
        }
      } else {
        const hardCopyEl = document.getElementById('cert-option-hardcopy');
        if (hardCopyEl) hardCopyEl.checked = false;
        const digEl = document.getElementById('cert-option-digital');
        if (digEl) digEl.checked = true;
        const delivDiv = document.getElementById('home-delivery-details');
        if (delivDiv) delivDiv.style.display = 'none';
      }

      const modalEl = document.getElementById('certificateRequestModal');
      if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
      }
    }

    function copyTrackerCertCode() {
      const codeEl = document.getElementById('tracker-cert-code');
      const text = codeEl ? codeEl.textContent.trim() : '';
      if (text && text !== 'CERT-CSLK-PENDING' && navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
          const btnText = document.getElementById('copy-btn-text');
          if (btnText) {
            btnText.textContent = 'Copied!';
            setTimeout(() => { btnText.textContent = 'Copy'; }, 2000);
          }
        });
      }
    }

    function openCertificateStatusModal(data) {
      if (!data) return;
      currentActiveCertData = data;

      document.getElementById('tracker-cert-code').textContent = data.certificate_code || 'CERT-CSLK-PENDING';
      document.getElementById('tracker-submitted-at').textContent = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : (data.completion_date || '');
      document.getElementById('step-1-date').textContent = data.created_at ? new Date(data.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Submitted';

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
          <div class="p-2.5 bg-light rounded-3 border">
            <div class="d-flex flex-wrap gap-1.5 mb-2">
              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 rounded-pill fs-9 fw-semibold d-inline-flex align-items-center gap-1">
                <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Digital Copy (PDF)
              </span>
              <span class="badge bg-warning bg-opacity-20 text-warning-emphasis border border-warning border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-bold d-inline-flex align-items-center gap-1">
                <i class="bi bi-box-seam-fill text-primary"></i> Printed Hard Copy (COD)
              </span>
            </div>
            <div class="text-dark fs-9 fw-medium mb-1">
              <i class="bi bi-geo-alt-fill text-danger me-1"></i>${escapeHtml(data.delivery_address || '')}, ${escapeHtml(data.city || '')}
            </div>
            <div class="text-muted fs-9 mb-1">
              <i class="bi bi-pin-map text-secondary me-1"></i>${escapeHtml(data.district || '')} (Postal: ${escapeHtml(data.postal_code || 'N/A')})
            </div>
            ${data.cod_phone ? `<div class="text-dark fs-9 mb-1"><i class="bi bi-telephone-fill text-success me-1"></i>COD Contact: <strong>${escapeHtml(data.cod_phone)}</strong></div>` : ''}
            <div class="text-success fs-9 fw-semibold">
              <i class="bi bi-cash-stack me-1"></i>Cash on Delivery Fee: LKR 1,500 <span class="text-muted fw-normal">(Payable upon delivery)</span>
            </div>
            ${data.delivery_notes ? `<div class="text-secondary fs-9 mt-1 pt-1 border-top"><i class="bi bi-chat-left-text me-1 text-primary"></i><strong>Instructions:</strong> ${escapeHtml(data.delivery_notes)}</div>` : ''}
          </div>
        `;
      } else {
        deliveryContainer.innerHTML = `
          <div class="p-2.5 bg-light rounded-3 border">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 rounded-pill fw-bold mb-2 d-inline-flex align-items-center gap-1">
              <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Digital Copy Only (PDF e-Certificate)
            </span>
            <div class="text-dark fs-9 mt-1"><i class="bi bi-envelope-check-fill text-primary me-1"></i>Registered Email: <strong>${escapeHtml(data.registered_email || '')}</strong></div>
            <div class="text-muted fs-9 mt-1"><i class="bi bi-info-circle me-1"></i>Direct PDF dispatch with verifiable QR verification link upon approval.</div>
          </div>
        `;
      }

      // Stepper & Status Hero Logic
      const status = (data.status || 'pending').toLowerCase();
      const heroContainer = document.getElementById('tracker-status-hero');
      const actionBtnContainer = document.getElementById('tracker-action-buttons');
      const progressBar = document.getElementById('stepper-progress-bar');
      const stageIndicator = document.getElementById('tracker-stage-indicator');
      const step1Circle = document.getElementById('step-1-circle');
      const step2Circle = document.getElementById('step-2-circle');
      const step3Circle = document.getElementById('step-3-circle');
      const step4Circle = document.getElementById('step-4-circle');
      const step4Label = document.getElementById('step-4-label');

      // Reset Stepper Styles
      step1Circle.style.backgroundColor = '#28a745';
      step1Circle.innerHTML = '<i class="bi bi-check-lg fw-bold"></i>';
      step2Circle.style.backgroundColor = '#28a745';
      step2Circle.innerHTML = '<i class="bi bi-check-lg fw-bold"></i>';
      step3Circle.style.backgroundColor = '#94a3b8';
      step3Circle.innerHTML = '<span class="fw-bold">3</span>';
      step4Circle.style.backgroundColor = '#94a3b8';
      step4Circle.innerHTML = '<span class="fw-bold">4</span>';
      step4Label.textContent = data.delivery_method === 'home_delivery' ? 'Dispatched' : 'Issued';

      if (status === 'pending') {
        progressBar.style.width = '25%';
        if (stageIndicator) stageIndicator.textContent = 'Stage 1 of 4: Under Review';
        heroContainer.className = 'p-3.5 rounded-4 border border-warning border-opacity-40 bg-warning bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-warning bg-opacity-20 text-warning d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
              <i class="bi bi-hourglass-split fs-4 text-warning"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="fw-bold text-dark mb-0 fs-7">Application Received & Academic Audit in Progress</h6>
                <span class="badge bg-warning text-dark border border-warning border-opacity-40 px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Under Review</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1.5" style="line-height: 1.45;">
                Your certificate request was received and is currently being audited by the academic department. Your 100% course progress and quiz evaluations are verified. Once confirmed, your credential will proceed to production and delivery dispatch.
              </p>
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'processing') {
        progressBar.style.width = '60%';
        if (stageIndicator) stageIndicator.textContent = 'Stage 3 of 4: In Production';
        step3Circle.style.backgroundColor = '#0f4c81';
        step3Circle.innerHTML = '<i class="bi bi-gear-fill fs-8"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-primary border-opacity-40 bg-primary bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-primary bg-opacity-20 text-primary d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
              <i class="bi bi-gear-wide-connected fs-4 text-primary"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="fw-bold text-dark mb-0 fs-7">Certificate Approved & In Production</h6>
                <span class="badge bg-primary text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">In Production</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1.5" style="line-height: 1.45;">
                Your application has passed academic review! The institutional credential is now being generated with security seals, unique QR registration codes, and archival certification.
              </p>
              ${data.admin_notes ? `<div class="p-2.5 bg-white rounded-3 border mt-2 fs-9 text-dark"><i class="bi bi-info-circle text-primary me-1"></i><strong>Admin Note:</strong> ${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'dispatched') {
        progressBar.style.width = '100%';
        if (stageIndicator) stageIndicator.textContent = 'Stage 4 of 4: Dispatched';
        step3Circle.style.backgroundColor = '#28a745';
        step3Circle.innerHTML = '<i class="bi bi-check-lg fw-bold"></i>';
        step4Circle.style.backgroundColor = '#0284c7';
        step4Circle.innerHTML = '<i class="bi bi-truck fs-7"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-info border-opacity-40 bg-info bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-info bg-opacity-20 text-info d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
              <i class="bi bi-truck fs-4 text-info"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="fw-bold text-dark mb-0 fs-7">Dispatched via Registered Courier (Out for Delivery)</h6>
                <span class="badge bg-info text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Dispatched</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1.5" style="line-height: 1.45;">
                Great news! Your official embossed certificate package has been handed over to the courier service and is out for doorstep delivery.
              </p>
              ${data.admin_notes ? `<div class="p-2.5 bg-white rounded-3 border border-info border-opacity-30 mt-2 fs-9 text-dark"><i class="bi bi-box-seam-fill text-info me-1"></i><strong>Courier & Tracking Details:</strong> ${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = '';
      } else if (status === 'approved' || status === 'issued') {
        progressBar.style.width = '100%';
        if (stageIndicator) stageIndicator.textContent = 'Completed & Issued';
        step3Circle.style.backgroundColor = '#28a745';
        step3Circle.innerHTML = '<i class="bi bi-check-lg fw-bold"></i>';
        step4Circle.style.backgroundColor = '#28a745';
        step4Circle.innerHTML = '<i class="bi bi-check-all fs-6"></i>';

        heroContainer.className = 'p-3.5 rounded-4 border border-success border-opacity-40 bg-success bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-success bg-opacity-20 text-success d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
              <i class="bi bi-patch-check-fill fs-4 text-success"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="fw-bold text-dark mb-0 fs-7">Certificate Officially Issued & Digitally Registered</h6>
                <span class="badge bg-success text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Issued & Active</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1.5" style="line-height: 1.45;">
                Congratulations! Your official credential is fully registered on the Computerscience.lk academic database. You can instantly view, print, or download your high-resolution verified certificate.
              </p>
              ${data.admin_notes ? `<div class="p-2.5 bg-white rounded-3 border mt-2 fs-9 text-dark"><i class="bi bi-chat-quote-fill me-1 text-success"></i><strong>Academic Note:</strong> ${escapeHtml(data.admin_notes)}</div>` : ''}
            </div>
          </div>
        `;
        actionBtnContainer.innerHTML = `
          <button type="button" class="btn btn-success rounded-pill px-4 py-2 fw-bold text-white shadow-sm d-flex align-items-center gap-2" style="background-color: #198754;" onclick="viewOfficialStudentCertificate()">
            <i class="bi bi-award-fill fs-6"></i>
            <span>View & Print Certificate</span>
          </button>
        `;
      } else if (status === 'rejected') {
        progressBar.style.width = '25%';
        if (stageIndicator) stageIndicator.textContent = 'Action Required';
        heroContainer.className = 'p-3.5 rounded-4 border border-danger border-opacity-40 bg-danger bg-opacity-10 mb-3 shadow-xs text-start';
        heroContainer.innerHTML = `
          <div class="d-flex align-items-start gap-3">
            <div class="p-2.5 rounded-circle bg-danger bg-opacity-20 text-danger d-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; min-width: 44px;">
              <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="fw-bold text-dark mb-0 fs-7">Application Requires Clarification / Declined</h6>
                <span class="badge bg-danger text-white px-2.5 py-1 rounded-pill fs-9 fw-bold text-uppercase">Declined</span>
              </div>
              <p class="text-secondary fs-8 mb-0 mt-1.5" style="line-height: 1.45;">
                Your certificate request could not be processed at this time. ${data.admin_notes ? `<strong>Reason:</strong> ${escapeHtml(data.admin_notes)}` : 'Please reach out to support for more details.'}
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

    function handleCertOptionChange(changed) {
      const digitalCheck = document.getElementById('cert-option-digital');
      const hardcopyCheck = document.getElementById('cert-option-hardcopy');
      const deliveryDetails = document.getElementById('home-delivery-details');

      if (hardcopyCheck && hardcopyCheck.checked) {
        // If hard copy is selected, digital copy is automatically selected
        if (digitalCheck) digitalCheck.checked = true;
        if (deliveryDetails) deliveryDetails.style.display = 'block';

        const mobileInput = document.getElementById('cert-modal-mobile');
        const codPhoneInput = document.getElementById('cert-modal-cod-phone');
        if (codPhoneInput && !codPhoneInput.value && mobileInput && mobileInput.value) {
          codPhoneInput.value = mobileInput.value;
        }
      } else {
        // Hard copy unselected: student can select just the digital copy
        if (deliveryDetails) deliveryDetails.style.display = 'none';
      }

      // Enforce selection constraint on digital copy
      if (changed === 'digital') {
        if (hardcopyCheck && hardcopyCheck.checked) {
          if (digitalCheck) digitalCheck.checked = true;
        } else if (digitalCheck && !digitalCheck.checked) {
          // Keep at least digital copy selected
          digitalCheck.checked = true;
        }
      }
    }

    function toggleDeliveryFields() {
      handleCertOptionChange('hardcopy');
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
          const isHardCopy = document.getElementById('cert-option-hardcopy') ? document.getElementById('cert-option-hardcopy').checked : false;
          const deliveryMethod = isHardCopy ? 'home_delivery' : 'digital_only';
          const codPhone = document.getElementById('cert-modal-cod-phone') ? document.getElementById('cert-modal-cod-phone').value.trim() : '';

          const payload = {
            course_id: courseId,
            full_name_on_certificate: fullName,
            nic_number: nic,
            mobile_number: mobile,
            delivery_method: deliveryMethod,
            delivery_address: isHardCopy ? document.getElementById('cert-modal-address').value.trim() : '',
            city: isHardCopy ? document.getElementById('cert-modal-city').value.trim() : '',
            postal_code: isHardCopy ? document.getElementById('cert-modal-postal').value.trim() : '',
            district: isHardCopy ? document.getElementById('cert-modal-district').value.trim() : '',
            delivery_notes: isHardCopy ? document.getElementById('cert-modal-notes').value.trim() : '',
            cod_phone: isHardCopy ? (codPhone || mobile) : ''
          };

          if (isHardCopy && (!payload.delivery_address || !payload.city || !payload.postal_code || !payload.district || !payload.cod_phone)) {
            alertBox.className = 'alert alert-danger mb-3 py-2 px-3 fs-8';
            alertBox.textContent = 'Please fill in all Cash on Delivery details (Address, City, Postal Code, District, and COD Contact Phone).';
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
        });
    }
  </script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
</body>

</html>