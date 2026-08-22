<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

$logged_in = false;
$user = null;
$enrolled_courses = [];

try {
  $pdo = getDBConnection();
  if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
      $logged_in = true;
      // Fetch user's enrolled course IDs
      $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
      $stmt->execute([$user['id']]);
      $enrolled_courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

      // Fetch notifications
      $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
      $stmt->execute([$user['id']]);
      $notifications = $stmt->fetchAll();

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
      $stmt->execute([$user['id']]);
      $unread_count = (int) $stmt->fetchColumn();
    }
  }

  // Fetch courses for the sidebar (active/approved only)
  $stmt = $pdo->query("SELECT id, title, tutor_id FROM courses WHERE (status = 'approved' OR status = 'active') AND (is_archived = 0 OR is_archived IS NULL) AND deleted_at IS NULL");
  $all_courses_sidebar = $stmt->fetchAll();

  // Fetch active site announcements
  $stmt = $pdo->query("SELECT * FROM site_announcements WHERE status = 'active' ORDER BY created_at DESC");
  $site_announcements = $stmt->fetchAll();

  // Fetch hero settings
  $stmt = $pdo->query("SELECT * FROM hero_settings WHERE id = 1 LIMIT 1");
  $hero = $stmt->fetch();
  if (!$hero) {
    $hero = [
      'title' => 'Welcome to Computerscience.lk',
      'description' => 'The central hub for academic software engineering and computational studies. Browse active course resources, track submissions, and collaborate with academic colleagues.',
      'button_text' => 'Browse Course Catalog',
      'button_url' => '#courses-section',
      'bg_image_1' => null,
      'bg_image_2' => null,
      'bg_image_3' => null
    ];
  }

  $hero_images = [];
  if (!empty($hero['bg_image_1']))
    $hero_images[] = $hero['bg_image_1'];
  if (!empty($hero['bg_image_2']))
    $hero_images[] = $hero['bg_image_2'];
  if (!empty($hero['bg_image_3']))
    $hero_images[] = $hero['bg_image_3'];
  if (empty($hero_images) && !empty($hero['bg_image'])) {
    $hero_images[] = $hero['bg_image'];
  }
  if (empty($hero_images)) {
    $hero_images = [
      'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=1600&auto=format&fit=crop&q=80',
      'https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=1600&auto=format&fit=crop&q=80',
      'https://images.unsplash.com/photo-1531482615713-2afd69097998?w=1600&auto=format&fit=crop&q=80'
    ];
  }
} catch (PDOException $e) {
  // Handle database error gracefully
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Computerscience.lk | Site Home</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
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
  <!-- Modern Notification System Styles -->
  <link rel="stylesheet" href="assets/css/notifications.css">
  <style>
    .no-caret::after {
      display: none !important;
    }

    .hero-outline-text {
      color: transparent;
      -webkit-text-stroke: 1.8px #2b529a;
    }

    .hero-arch-bg {
      background-color: #2b529a;
      border-top-left-radius: 200px;
      border-top-right-radius: 200px;
      border-bottom-left-radius: 40px;
      border-bottom-right-radius: 40px;
      position: relative;
      overflow: hidden;
    }

    .hero-arch-outline {
      border: 1.5px solid #2b529a;
      border-top-left-radius: 210px;
      border-top-right-radius: 210px;
      border-bottom-left-radius: 48px;
      border-bottom-right-radius: 48px;
      position: absolute;
      inset: -12px;
      pointer-events: none;
    }

    .avatar-stack-item {
      width: 34px;
      height: 34px;
      object-fit: cover;
      border-radius: 50%;
      border: 2px solid #ffffff;
      margin-left: -10px;
    }

    .avatar-stack-item:first-child {
      margin-left: 0;
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

      <a href="index.php" class="drawer-link active">
        <i class="bi bi-house-door fs-5 text-primary"></i> Site Home
      </a>
      <a href="dashboard.php" class="drawer-link">
        <i class="bi bi-speedometer2 fs-5"></i> Dashboard
      </a>
      <hr class="mx-3 my-2 border-secondary border-opacity-20">
      <?php
      $is_teacher = ($logged_in && ($user['role'] ?? '') === 'teacher');
      ?>
      <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider">
        <?php echo $is_teacher ? 'Courses I Teach' : 'My Courses'; ?></div>
      <?php
      $enrolled_any = false;
      if ($logged_in) {
        if ($is_teacher) {
          foreach ($all_courses_sidebar as $cs_course) {
            if (intval($cs_course['tutor_id']) === intval($user['id'])) {
              $enrolled_any = true;
              echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                              <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                            </a>';
            }
          }
          if (!$enrolled_any) {
            echo '<div class="px-4 py-2 fs-8 text-muted italic">No courses created yet</div>';
          }
        } else {
          foreach ($all_courses_sidebar as $cs_course) {
            if (in_array($cs_course['id'], $enrolled_courses)) {
              $enrolled_any = true;
              echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                              <i class="bi bi-book me-2"></i> ' . htmlspecialchars($cs_course['title']) . '
                            </a>';
            }
          }
          if (!$enrolled_any) {
            echo '<div class="px-4 py-2 fs-8 text-muted italic">No enrolled courses</div>';
          }
        }
      } else {
        echo '<div class="px-4 py-2 fs-8 text-muted italic">Log in to view courses</div>';
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
          <li class="breadcrumb-item active" aria-current="page">Site Home</li>
        </ol>
      </nav>

      <!-- Target Mockup Redesigned Hero Section -->
      <div class="moodle-card p-4 p-lg-5 mb-5 bg-white border-0 shadow-sm position-relative overflow-hidden"
        style="border-radius: 24px;">

        <!-- Decorative Sparkle / Star Icons in background -->
        <i class="bi bi-star-fill text-primary opacity-25 position-absolute fs-4 d-none d-md-block"
          style="top: 25px; left: 220px;"></i>
        <i class="bi bi-stars text-primary opacity-30 position-absolute fs-3 d-none d-md-block"
          style="top: 50px; left: 480px;"></i>
        <i class="bi bi-star-fill text-primary opacity-20 position-absolute fs-5 d-none d-md-block"
          style="bottom: 80px; left: 440px;"></i>
        <i class="bi bi-stars text-primary opacity-25 position-absolute fs-4 d-none d-md-block"
          style="bottom: 20px; left: 160px;"></i>

        <div class="row align-items-center g-5">

          <!-- Left Content Column -->
          <div class="col-lg-6">

            <!-- Styled Main Title -->
            <?php
            $raw_title_val = $hero['title'] ?? 'Enhance Your Skills With Our Online Courses';
            $translated_title = __($raw_title_val, $raw_title_val);
            $raw_title = htmlspecialchars($translated_title);
            $styled_title = str_replace('Our Online', '<span class="hero-outline-text">Our Online</span>', $raw_title);
            if ($styled_title === $raw_title) {
              $styled_title = str_replace('Online', '<span class="hero-outline-text">Online</span>', $raw_title);
            }
            ?>
            <h1 class="fw-extrabold display-5 text-dark mb-4"
              style="line-height: 1.15; font-weight: 800; color: #2d3748;">
              <?php echo $styled_title; ?>
            </h1>

            <!-- Subtitle Description -->
            <p class="text-secondary fs-6 mb-4 leading-relaxed" style="max-width: 520px; color: #64748b;">
              <?php echo nl2br(htmlspecialchars(__($hero['description'] ?? '', $hero['description'] ?? ''))); ?>
            </p>

            <!-- CTA Action Buttons Row -->
            <div class="d-flex flex-wrap align-items-center gap-3 mb-5">
              <a href="<?php echo htmlspecialchars($hero['button_url'] ?? '#courses-section'); ?>"
                class="btn px-4 py-2.5 text-white fw-bold rounded-pill shadow-sm fs-7"
                style="background-color: #2b529a; border: none;">
                <?php echo htmlspecialchars(__($hero['button_text'] ?? 'Apply Now', $hero['button_text'] ?? 'Apply Now')); ?>
              </a>
              <?php if (!empty($hero['secondary_button_text'])): ?>
                <a href="<?php echo htmlspecialchars($hero['secondary_button_url'] ?? '#courses-section'); ?>"
                  class="btn btn-outline-secondary px-4 py-2.5 fw-bold rounded-pill fs-7"
                  style="border-color: #94a3b8; color: #475569;">
                  <?php echo htmlspecialchars(__($hero['secondary_button_text'], $hero['secondary_button_text'])); ?>
                </a>
              <?php endif; ?>
            </div>

            <!-- Social Icons & Phone Contact Line -->
            <div class="d-flex align-items-center gap-3 mb-4">
              <div class="d-flex align-items-center gap-2">
                <a href="#" class="d-inline-flex align-items-center justify-content-center text-white rounded-circle"
                  style="width: 28px; height: 28px; background-color: #2b529a;"><i class="bi bi-facebook fs-9"></i></a>
                <a href="#" class="d-inline-flex align-items-center justify-content-center text-white rounded-circle"
                  style="width: 28px; height: 28px; background-color: #2b529a;"><i class="bi bi-twitter-x fs-9"></i></a>
                <a href="#" class="d-inline-flex align-items-center justify-content-center text-white rounded-circle"
                  style="width: 28px; height: 28px; background-color: #2b529a;"><i class="bi bi-telegram fs-9"></i></a>
                <a href="#" class="d-inline-flex align-items-center justify-content-center text-white rounded-circle"
                  style="width: 28px; height: 28px; background-color: #2b529a;"><i class="bi bi-instagram fs-9"></i></a>
              </div>
              <?php if (!empty($hero['phone_number'])): ?>
                <span class="fw-bold fs-7 text-dark"
                  style="color: #2b529a !important;"><?php echo htmlspecialchars(__($hero['phone_number'], $hero['phone_number'])); ?></span>
              <?php endif; ?>
            </div>

            <!-- Carousel Slider Dots Indicator -->
            <div class="d-flex align-items-center gap-2">
              <span class="rounded-circle"
                style="width: 12px; height: 12px; background-color: #64748b; opacity: 0.5;"></span>
              <span class="rounded-circle" style="width: 14px; height: 14px; background-color: #2b529a;"></span>
              <span class="rounded-circle"
                style="width: 12px; height: 12px; background-color: #64748b; opacity: 0.5;"></span>
            </div>

          </div>

          <!-- Right Graphic Column (Arch Frame + 3 Image Carousel + Avatar Pill Card) -->
          <div class="col-lg-6 position-relative text-center d-flex justify-content-center">

            <div class="position-relative" style="width: 100%; max-width: 400px;">

              <!-- Arch Outer Outline stroke -->
              <div class="hero-arch-outline"></div>

              <!-- Arch Solid Blue Background Container with Fading Carousel -->
              <div class="hero-arch-bg" style="min-height: 460px;">
                <div id="archHeroCarousel" class="carousel slide carousel-fade h-100 w-100" data-bs-ride="carousel"
                  data-bs-interval="3500">
                  <div class="carousel-inner h-100 w-100" style="min-height: 460px;">
                    <?php foreach ($hero_images as $idx => $img_url): ?>
                      <div class="carousel-item h-100 w-100 <?php echo $idx === 0 ? 'active' : ''; ?>"
                        style="min-height: 460px;">
                        <img src="<?php echo htmlspecialchars($img_url); ?>" alt="Student Hero" class="w-100 h-100"
                          style="object-fit: cover; min-height: 460px;">
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <!-- Floating Enrolled Students Avatar Pill Badge Card -->
              <div class="position-absolute shadow-lg d-flex align-items-center gap-2 text-white"
                style="bottom: 25px; left: -25px; z-index: 10; background-color: #2b529a; border-radius: 9999px; padding: 8px 18px; border: 2px solid #ffffff;">
                <div class="d-flex align-items-center">
                  <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=80&h=80&fit=crop"
                    class="avatar-stack-item" alt="Student 1">
                  <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=80&h=80&fit=crop"
                    class="avatar-stack-item" alt="Student 2">
                  <img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=80&h=80&fit=crop"
                    class="avatar-stack-item" alt="Student 3">
                </div>
                <span
                  class="fw-bold fs-8 text-nowrap ms-1"><?php echo htmlspecialchars(__($hero['enrolled_students_count'] ?? '30K Enrolled Students', $hero['enrolled_students_count'] ?? '30K Enrolled Students')); ?></span>
              </div>

            </div>

          </div>

        </div>
      </div>

      <!-- Top Layout Row: Site Announcements and Side Blocks -->
      <div class="row g-4 mb-4">

        <!-- Left 8 columns: Announcements -->
        <div class="col-lg-8">
          <div class="moodle-card p-4 h-100">
            <h3 class="fw-bold mb-3 border-bottom pb-2 fs-5"><i
                class="bi bi-megaphone me-2 text-warning"></i><?php echo __('site_announcements', 'Site Announcements'); ?>
            </h3>

            <?php if (empty($site_announcements)): ?>
              <div class="text-muted fs-7 italic py-2">
                <?php echo __('no_announcements', 'No site announcements at this time.'); ?></div>
            <?php else: ?>
              <div class="d-flex flex-column gap-3">
                <?php foreach ($site_announcements as $idx => $ann): ?>
                  <div
                    class="<?php echo $idx < count($site_announcements) - 1 ? 'border-bottom border-light pb-3' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <h6 class="fw-bold mb-0 text-primary">
                        <?php echo htmlspecialchars(__($ann['title'], $ann['title'])); ?></h6>
                      <?php if (!empty($ann['badge_text'])): ?>
                        <span
                          class="badge bg-light text-muted border"><?php echo htmlspecialchars(__($ann['badge_text'], $ann['badge_text'])); ?></span>
                      <?php else: ?>
                        <span
                          class="badge bg-light text-muted border"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></span>
                      <?php endif; ?>
                    </div>
                    <p class="text-muted fs-7 mb-0">
                      <?php echo nl2br(htmlspecialchars(__($ann['content'], $ann['content']))); ?></p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right 4 columns: Moodle Side Blocks -->
        <div class="col-lg-4">
          <!-- Block: Online Users -->
          <div class="moodle-card p-4 h-100">
            <h5 class="fw-bold mb-3 border-bottom pb-2 text-sm"><i
                class="bi bi-people me-2 text-primary"></i><?php echo __('online_users', 'Online Users (Last 5 mins)'); ?>
            </h5>
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-items-center gap-2">
                <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=100&h=100&fit=crop"
                  class="rounded-circle" style="width: 28px; height: 28px; object-fit: cover;" alt="Tutor">
                <div>
                  <h6 class="mb-0 fs-7 fw-bold">Dr. Sanduni Perera <span
                      class="badge bg-primary fs-8 py-0.5"><?php echo __('lecturer', 'Lecturer'); ?></span></h6>
                </div>
              </div>
              <?php if ($logged_in): ?>
                <div class="d-flex align-items-center gap-2">
                  <img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="rounded-circle"
                    style="width: 28px; height: 28px; object-fit: cover;" alt="You">
                  <div>
                    <h6 class="mb-0 fs-7 fw-bold"><?php echo htmlspecialchars($user['name']); ?> <span
                        class="text-muted fs-8"><?php echo __('you', '(You)'); ?></span></h6>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>

      <!-- Available Courses Full-Width Section -->
      <section id="courses-section" class="mb-4">
        <div class="moodle-card p-4 p-md-5">
          <h3 class="fw-bold mb-4 border-bottom pb-3 fs-5"><i
              class="bi bi-collection-play me-2 text-primary"></i><?php echo __('available_courses', 'Available Courses'); ?>
          </h3>

          <!-- Filter Controls & Search -->
          <div class="row g-3 mb-4 align-items-center">
            <!-- Search bar -->
            <div class="col-lg-5 col-md-6">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="course-search" class="form-control border-start-0"
                  placeholder="<?php echo __('search_courses_placeholder', 'Search courses...'); ?>">
              </div>
            </div>
            <!-- Categories Selector -->
            <div class="col-lg-7 col-md-6">
              <div class="d-flex flex-wrap gap-1.5 justify-content-md-end" id="category-pills">
                <button class="category-btn active py-1.5 px-3 text-xs"
                  data-category=""><?php echo __('all', 'All'); ?></button>
                <button class="category-btn py-1.5 px-3 text-xs"
                  data-category="Computer Science"><?php echo __('cat_cs', 'CS'); ?></button>
                <button class="category-btn py-1.5 px-3 text-xs"
                  data-category="Programming"><?php echo __('cat_coding', 'Coding'); ?></button>
                <button class="category-btn py-1.5 px-3 text-xs"
                  data-category="Web Development"><?php echo __('cat_web', 'Web'); ?></button>
                <button class="category-btn py-1.5 px-3 text-xs"
                  data-category="Artificial Intelligence"><?php echo __('cat_ai', 'AI'); ?></button>
                <button class="category-btn py-1.5 px-3 text-xs"
                  data-category="Cyber Security"><?php echo __('cat_cyber', 'Cyber'); ?></button>
              </div>
            </div>
          </div>

          <!-- Courses Grid Container -->
          <div class="row g-4" id="courses-grid">
            <!-- Dynamically rendered via AJAX -->
            <div class="col-12 text-center py-5">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
            </div>
          </div>

        </div>
      </section>

    </div>
  </main>

  <!-- Notification Toast Container -->
  <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100;">
    <div id="enrollToast" class="toast align-items-center text-dark bg-white border-0 moodle-card" role="alert"
      aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body d-flex align-items-center gap-2">
          <i class="bi bi-check-circle-fill text-success fs-5"></i>
          <span id="toast-message"
            class="fw-semibold"><?php echo __('enrolled_success', 'Successfully enrolled in course!'); ?></span>
        </div>
        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <!-- Course Detail Popup Modal -->
  <div class="modal fade" id="courseDetailModal" tabindex="-1" aria-labelledby="courseDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content moodle-card border-0 overflow-hidden shadow-lg" style="border-radius: 20px;">
        <div class="modal-header border-0 pb-0 position-relative px-4 pt-4">
          <h5 class="modal-title fw-bold text-dark fs-5" id="courseDetailModalLabel"><?php echo __('course_details', 'Course Details'); ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4 p-md-5 pt-3">
          <div class="row g-4">
            <div class="col-md-5">
              <img id="modal-course-thumbnail" src="" class="w-100 rounded-3 shadow-sm" style="height: 200px; object-fit: cover;" alt="Course Thumbnail">
              
              <!-- Pricing Highlight Box -->
              <div id="modal-pricing-box" class="mt-3 p-3 rounded-3 border d-flex align-items-center justify-content-between">
                <!-- Dynamically populated -->
              </div>

              <!-- Tutor Info -->
              <div class="mt-3 p-3 bg-light rounded-3 d-flex align-items-center gap-3">
                <img id="modal-tutor-avatar" src="" class="rounded-circle border border-primary border-opacity-20" style="width: 44px; height: 44px; object-fit: cover;" alt="Tutor">
                <div>
                  <h6 id="modal-tutor-name" class="fw-bold mb-0 text-dark fs-7"></h6>
                  <small id="modal-tutor-title" class="text-muted fs-8 d-block"></small>
                </div>
              </div>
            </div>

            <div class="col-md-7 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                  <span id="modal-course-category" class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-1.5 rounded-pill fs-8"></span>
                  <span id="modal-course-level" class="badge bg-secondary bg-opacity-10 text-secondary fw-semibold px-3 py-1.5 rounded-pill fs-8"></span>
                  <span id="modal-course-duration" class="badge bg-light text-dark border px-3 py-1.5 rounded-pill fs-8"></span>
                </div>

                <h4 id="modal-course-title" class="fw-bold text-dark mb-2"></h4>

                <div class="d-flex align-items-center gap-2 mb-3 text-sm text-muted">
                  <div class="d-flex align-items-center text-warning gap-1" id="modal-course-stars"></div>
                  <span id="modal-course-rating-val" class="fw-semibold text-dark fs-8"></span>
                  <span>•</span>
                  <span id="modal-course-enrolled" class="fs-8"></span>
                </div>

                <!-- Target Audience Section -->
                <div class="mb-3" id="modal-target-audience-wrapper">
                  <h6 class="fw-bold text-dark mb-1 fs-7"><i class="bi bi-people text-primary me-1"></i><?php echo __('target_audience', 'Target Audience'); ?></h6>
                  <div id="modal-course-target-audience" class="d-flex flex-wrap gap-1"></div>
                </div>

                <h6 class="fw-bold text-dark mb-1 fs-7"><?php echo __('course_overview', 'Course Overview'); ?></h6>
                <p id="modal-course-description" class="text-muted fs-7 leading-relaxed mb-3" style="max-height: 180px; overflow-y: auto;"></p>
              </div>

              <div id="modal-action-container" class="pt-3 border-top">
                <!-- Dynamically filled action button -->
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="py-4 bg-dark text-white-50 border-t mt-5">
    <div class="container text-center">
      <p class="mb-1 text-white">Computerscience.lk LMS</p>
      <p class="fs-7 mb-0">Powered by Computerscience.lk &copy; 2026.</p>
    </div>
  </footer>

  <!-- Setup JS variables for script referencing -->
  <script>
    window.LOGGED_IN = <?php echo $logged_in ? 'true' : 'false'; ?>;
    window.USER_ROLE = "<?php echo $_SESSION['user_role'] ?? ''; ?>";
  </script>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>

  <!-- Render JS Translation Dictionary -->
  <?php render_i18n_js(); ?>

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


  <!-- Main JS file handling AJAX Catalog and Interactions -->
  <script src="assets/js/main.js"></script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
</body>

</html>