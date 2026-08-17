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
                          
                          <!-- Action Button -->
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
                            <a href="classroom.php?course_id=<?php echo urlencode($course['id']); ?>" class="btn btn-outline-primary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                              <i class="bi bi-play-circle-fill"></i> Enter Classroom
                            </a>
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

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  
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
  });
  </script>
</body>
</html>
