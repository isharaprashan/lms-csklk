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

  // Fetch active promotional ad banners for the auto-swiping carousel
  $stmt = $pdo->query("SELECT * FROM promotional_banners WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC");
  $promotional_banners = $stmt->fetchAll();

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
      'bg_image_3' => null,
      'hero_image_path' => null,
      'hero_image_alt' => 'Student with books'
    ];
  }

  // Resolve the hero portrait image (uploaded custom OR fallback to a high-quality default)
  $hero_portrait_path = !empty($hero['hero_image_path']) ? htmlspecialchars($hero['hero_image_path']) : null;
  $hero_portrait_alt = htmlspecialchars(__($hero['hero_image_alt'] ?? 'Student with books', $hero['hero_image_alt'] ?? 'Student with books'));
  // Default fallback — high-quality transparent-BG student image (served as PNG)
  $hero_portrait_default = 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&auto=format&fit=crop&q=85';
  $hero_portrait_src = $hero_portrait_path ?: $hero_portrait_default;

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
  <link rel="icon" type="image/x-icon"
    href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon"
    href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
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
      -webkit-text-stroke: 2px #2b529a;
    }

    /* ===== Hero Left-Side Typography & Badges ===== */
    .hero-badge-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 16px;
      border-radius: 9999px;
      background: rgba(43, 82, 154, 0.08);
      border: 1px solid rgba(43, 82, 154, 0.20);
      color: #2b529a;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .hero-badge-tag .badge-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background-color: #22c55e;
      box-shadow: 0 0 8px #22c55e;
    }

    .hero-main-title {
      font-size: clamp(2.35rem, 3.4vw, 3.25rem);
      font-weight: 800;
      line-height: 1.15;
      letter-spacing: -0.025em;
      color: #1e293b;
    }

    .hero-main-desc {
      font-size: 1.06rem;
      line-height: 1.7;
      color: #475569;
      max-width: 530px;
    }

    /* ===== Hero Action Buttons ===== */
    .hero-btn-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      padding: 13px 32px;
      font-size: 0.96rem;
      font-weight: 700;
      border-radius: 9999px;
      background: linear-gradient(135deg, #2b529a 0%, #1a3c75 100%);
      color: #ffffff !important;
      border: none;
      text-decoration: none;
      box-shadow: 0 8px 22px rgba(43, 82, 154, 0.28);
      transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .hero-btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(43, 82, 154, 0.40);
      color: #ffffff !important;
    }
    .hero-btn-primary i {
      transition: transform 0.25s ease;
    }
    .hero-btn-primary:hover i {
      transform: translateX(3px);
    }

    .hero-btn-secondary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 28px;
      font-size: 0.96rem;
      font-weight: 700;
      border-radius: 9999px;
      background: #ffffff;
      color: #334155;
      border: 1.6px solid #cbd5e1;
      text-decoration: none;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .hero-btn-secondary:hover {
      transform: translateY(-2px);
      background: #f8fafc;
      border-color: #94a3b8;
      color: #0f172a;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
    }

    /* ===== Professional Hero Social Media Strip & Phone Badge ===== */
    .hero-social-wrap {
      display: inline-flex;
      align-items: center;
      gap: 9px;
    }

    .hero-social-btn {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #ffffff;
      color: #475569;
      border: 1.5px solid #e2e8f0;
      font-size: 0.98rem;
      text-decoration: none;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
    }

    .hero-social-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 18px rgba(43, 82, 154, 0.22);
    }

    .hero-social-btn.social-fb:hover {
      background: #1877F2;
      border-color: #1877F2;
      color: #ffffff !important;
    }

    .hero-social-btn.social-tw:hover {
      background: #0f1419;
      border-color: #0f1419;
      color: #ffffff !important;
    }

    .hero-social-btn.social-tg:hover {
      background: #229ED9;
      border-color: #229ED9;
      color: #ffffff !important;
    }

    .hero-social-btn.social-ig:hover {
      background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%);
      border-color: #d6249f;
      color: #ffffff !important;
    }

    .hero-social-btn.social-yt:hover {
      background: #FF0000;
      border-color: #FF0000;
      color: #ffffff !important;
    }

    .hero-social-btn.social-wa:hover {
      background: #25D366;
      border-color: #25D366;
      color: #ffffff !important;
    }

    .hero-social-btn.social-li:hover {
      background: #0A66C2;
      border-color: #0A66C2;
      color: #ffffff !important;
    }

    .hero-phone-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 16px;
      border-radius: 9999px;
      background: rgba(43, 82, 154, 0.06);
      border: 1px solid rgba(43, 82, 154, 0.14);
      color: #2b529a;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.88rem;
      transition: all 0.2s ease;
    }

    .hero-phone-badge:hover {
      background: rgba(43, 82, 154, 0.12);
      color: #1e3a6d;
      transform: translateY(-1px);
    }

    /* Trust signals bar */
    .hero-trust-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 16px;
      padding-top: 18px;
      border-top: 1px solid #e2e8f0;
      color: #64748b;
      font-size: 0.82rem;
      font-weight: 600;
    }
    .hero-trust-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    /* ===== Hero Portrait & Decorative Elements (Large Character Portrait) ===== */
    .hero-portrait-wrap {
      position: relative;
      width: 100%;
      max-width: 600px;
      min-height: 660px;
      margin: 0 auto;
      margin-bottom: -3rem; /* Sits flush with card bottom edge */
      display: flex;
      align-items: flex-end;
      justify-content: center;
    }

    /* Soft curved background circle behind student */
    .hero-backdrop-circle {
      position: absolute;
      width: 440px;
      height: 440px;
      border-radius: 50%;
      background: radial-gradient(circle, #f5f2fd 0%, #ece5fa 70%, #dfd5f6 100%);
      top: 52%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 1;
      animation: heroBackdropBreathe 7s ease-in-out infinite;
      box-shadow: 0 10px 40px rgba(139, 92, 246, 0.08);
    }

    @keyframes heroBackdropBreathe {
      0%, 100% { transform: translate(-50%, -50%) scale(1); }
      50% { transform: translate(-50%, -50%) scale(1.03); }
    }

    /* Left Tilted Yellow Outline Box (Compact Accent Shape) */
    .hero-shape-yellow-card {
      position: absolute;
      width: 135px;
      height: 155px;
      border: 3px solid #ffb800;
      border-radius: 24px;
      background: rgba(245, 243, 255, 0.65);
      backdrop-filter: blur(4px);
      top: 16%;
      left: 6%;
      transform: rotate(-22deg);
      z-index: 2;
      animation: heroSwayCardLeft 6s ease-in-out infinite;
    }

    @keyframes heroSwayCardLeft {
      0%, 100% { transform: rotate(-22deg) translateY(0); }
      50% { transform: rotate(-18deg) translateY(-8px); }
    }

    /* Right Tilted Purple Outline Diamond (Compact Accent Shape) */
    .hero-shape-purple-diamond {
      position: absolute;
      width: 145px;
      height: 145px;
      border: 3px solid #6366f1;
      border-radius: 30px;
      background: rgba(245, 243, 255, 0.45);
      backdrop-filter: blur(4px);
      top: 22%;
      right: 4%;
      transform: rotate(45deg);
      z-index: 2;
      animation: heroSwayDiamondRight 6.5s ease-in-out infinite;
    }

    @keyframes heroSwayDiamondRight {
      0%, 100% { transform: rotate(45deg) translateY(0); }
      50% { transform: rotate(49deg) translateY(-9px); }
    }

    /* Top-Left Solid Hot Pink Circle */
    .hero-shape-pink-dot {
      position: absolute;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: #f43f5e;
      top: 8%;
      left: 15%;
      z-index: 4;
      box-shadow: 0 4px 14px rgba(244, 63, 94, 0.4);
      animation: heroPulsePink 3.8s ease-in-out infinite;
    }

    @keyframes heroPulsePink {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.2); }
    }

    /* Top-Right 5-Point Purple Star */
    .hero-shape-purple-star {
      position: absolute;
      top: 7%;
      right: 16%;
      z-index: 4;
      filter: drop-shadow(0 4px 10px rgba(99, 102, 241, 0.35));
      animation: heroFloatPurpleStar 4.2s ease-in-out infinite;
      pointer-events: none;
    }

    @keyframes heroFloatPurpleStar {
      0%, 100% { transform: translateY(0) rotate(0deg); }
      50% { transform: translateY(-10px) rotate(14deg); }
    }

    /* Bottom-Left 5-Point Golden Yellow Star */
    .hero-shape-yellow-star {
      position: absolute;
      bottom: 18%;
      left: 5%;
      z-index: 6;
      filter: drop-shadow(0 4px 12px rgba(255, 184, 0, 0.4));
      animation: heroFloatYellowStar 4.8s ease-in-out infinite;
      pointer-events: none;
    }

    @keyframes heroFloatYellowStar {
      0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
      50% { transform: translateY(-12px) rotate(-12deg) scale(1.06); }
    }

    /* Middle-Left Accent Geometric Mark */
    .hero-shape-accent-mark {
      position: absolute;
      top: 38%;
      left: 1%;
      width: 10px;
      height: 10px;
      background: #334155;
      border-radius: 2px;
      z-index: 3;
      opacity: 0.85;
      animation: heroFloatTick 5s ease-in-out infinite;
    }

    @keyframes heroFloatTick {
      0%, 100% { transform: translateY(0); opacity: 0.85; }
      50% { transform: translateY(-6px); opacity: 1; }
    }

    /* BIG MAIN CHARACTER IMAGE (Dominant, Full Hero Height like Uploaded Image) */
    .hero-portrait-img {
      position: relative;
      z-index: 5;
      width: 100%;
      max-width: 560px;
      height: 670px;
      object-fit: contain;
      object-position: bottom center;
      display: block;
      margin: 0 auto;
      filter: drop-shadow(0 20px 48px rgba(43, 82, 154, 0.22));
      transition: transform 0.4s ease;
    }

    .hero-portrait-img:hover {
      transform: translateY(-5px) scale(1.015);
    }

    /* Responsive: stack portrait below text on small screens */
    @media (max-width: 991.98px) {
      .hero-portrait-wrap {
        max-width: 440px;
        min-height: 480px;
        margin-top: 28px;
        margin-bottom: 0;
      }

      .hero-backdrop-circle {
        width: 360px;
        height: 360px;
      }

      .hero-portrait-img {
        height: 480px;
        max-width: 400px;
      }
    }

    @media (max-width: 575.98px) {
      .hero-portrait-wrap {
        max-width: 310px;
        min-height: 360px;
      }

      .hero-backdrop-circle {
        width: 280px;
        height: 280px;
      }

      .hero-portrait-img {
        height: 360px;
        max-width: 300px;
      }
    }

    /* Legacy arch classes kept for backward-compat (hidden now) */
    .hero-arch-bg {
      display: none !important;
    }

    .hero-arch-outline {
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
      <div
        class="px-3 py-2.5 mb-2 d-flex align-items-center justify-content-between border-bottom bg-light bg-opacity-50">
        <span class="fs-8 fw-bold text-uppercase tracking-wider text-muted d-flex align-items-center gap-1.5">
          <i class="bi bi-compass-fill text-primary"></i>
          <span><?php echo __('navigation', 'Navigation'); ?></span>
        </span>
        <button type="button"
          class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center drawer-close-trigger text-secondary"
          style="width: 32px; height: 32px;" title="<?php echo __('close', 'Close'); ?>">
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
        <?php echo $is_teacher ? 'Courses I Teach' : 'My Courses'; ?>
      </div>
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

            <!-- Category / Tag Pill -->
            <div class="hero-badge-tag mb-3">
              <span class="badge-dot"></span>
              <span><?php echo __('site_tagline', 'Enhance Your Skills With Our Online Courses'); ?></span>
            </div>

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
            <h1 class="hero-main-title mb-3">
              <?php echo $styled_title; ?>
            </h1>

            <!-- Subtitle Description -->
            <p class="hero-main-desc mb-4">
              <?php echo nl2br(htmlspecialchars(__($hero['description'] ?? '', $hero['description'] ?? ''))); ?>
            </p>

            <!-- CTA Action Buttons Row -->
            <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
              <a href="<?php echo htmlspecialchars($hero['button_url'] ?? '#courses-section'); ?>"
                class="hero-btn-primary">
                <span><?php echo htmlspecialchars(__($hero['button_text'] ?? 'Apply Now', $hero['button_text'] ?? 'Apply Now')); ?></span>
                <i class="bi bi-arrow-right-circle-fill"></i>
              </a>
              <?php if (!empty($hero['secondary_button_text'])): ?>
                <a href="<?php echo htmlspecialchars($hero['secondary_button_url'] ?? '#courses-section'); ?>"
                  class="hero-btn-secondary">
                  <span><?php echo htmlspecialchars(__($hero['secondary_button_text'], $hero['secondary_button_text'])); ?></span>
                  <i class="bi bi-compass"></i>
                </a>
              <?php endif; ?>
            </div>

            <!-- Social Icons & Phone Contact Line -->
            <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
              <div class="hero-social-wrap">
                <?php if (!empty($hero['facebook_url']) && $hero['facebook_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['facebook_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-fb" title="Facebook" aria-label="Facebook">
                    <i class="bi bi-facebook"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['twitter_url']) && $hero['twitter_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['twitter_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-tw" title="Twitter / X" aria-label="Twitter / X">
                    <i class="bi bi-twitter-x"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['telegram_url']) && $hero['telegram_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['telegram_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-tg" title="Telegram" aria-label="Telegram">
                    <i class="bi bi-telegram"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['instagram_url']) && $hero['instagram_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['instagram_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-ig" title="Instagram" aria-label="Instagram">
                    <i class="bi bi-instagram"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['youtube_url']) && $hero['youtube_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['youtube_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-yt" title="YouTube" aria-label="YouTube">
                    <i class="bi bi-youtube"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['whatsapp_url']) && $hero['whatsapp_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-wa" title="WhatsApp" aria-label="WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                  </a>
                <?php endif; ?>

                <?php if (!empty($hero['linkedin_url']) && $hero['linkedin_url'] !== ''): ?>
                  <a href="<?php echo htmlspecialchars($hero['linkedin_url']); ?>" target="_blank" rel="noopener noreferrer"
                    class="hero-social-btn social-li" title="LinkedIn" aria-label="LinkedIn">
                    <i class="bi bi-linkedin"></i>
                  </a>
                <?php endif; ?>
              </div>

              <?php if (!empty($hero['phone_number'])): ?>
                <div class="hero-phone-badge">
                  <i class="bi bi-telephone-fill fs-9"></i>
                  <span><?php echo htmlspecialchars(__($hero['phone_number'], $hero['phone_number'])); ?></span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Trust Signals Mini-Bar -->
            <div class="hero-trust-bar">
              <span class="hero-trust-item"><i class="bi bi-shield-check text-success"></i> <?php echo __('verified_courses', 'Verified Courses'); ?></span>
              <span class="hero-trust-item"><i class="bi bi-award-fill text-warning"></i> <?php echo __('certified_lms', 'Certified LMS'); ?></span>
              <span class="hero-trust-item"><i class="bi bi-person-check-fill text-primary"></i> <?php echo __('expert_tutors', 'Expert Tutors'); ?></span>
            </div>

          </div>

          <!-- Right Graphic Column — Student Portrait with Refined Decorative Background Shapes -->
          <div class="col-lg-6 d-flex justify-content-center align-items-end position-relative"
            style="overflow: visible;">

            <div class="hero-portrait-wrap">

              <!-- 1. Large Soft Backdrop Circle / Bubble -->
              <div class="hero-backdrop-circle"></div>

              <!-- 2. Left Tilted Yellow Outline Card -->
              <div class="hero-shape-yellow-card"></div>

              <!-- 3. Right Tilted Purple Outline Diamond -->
              <div class="hero-shape-purple-diamond"></div>

              <!-- 4. Top-Left Solid Hot Pink Circle -->
              <div class="hero-shape-pink-dot"></div>

              <!-- 5. Top-Right Solid Purple 5-Point Star -->
              <div class="hero-shape-purple-star">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="#6366F1" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 2l2.8 6.2 6.7.7-5 4.5 1.4 6.6-5.9-3.4-5.9 3.4 1.4-6.6-5-4.5 6.7-.7L12 2z" />
                </svg>
              </div>

              <!-- 6. Bottom-Left Solid Golden Yellow 5-Point Star -->
              <div class="hero-shape-yellow-star">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="#F59E0B" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 2l2.8 6.2 6.7.7-5 4.5 1.4 6.6-5.9-3.4-5.9 3.4 1.4-6.6-5-4.5 6.7-.7L12 2z" />
                </svg>
              </div>

              <!-- 7. Middle-Left Accent Geometric Mark -->
              <div class="hero-shape-accent-mark"></div>

              <!-- 8. Main Hero Portrait Image (Enlarged Display Area) -->
              <img src="<?php echo $hero_portrait_src; ?>" alt="<?php echo $hero_portrait_alt; ?>"
                class="hero-portrait-img" id="hero-portrait-main" loading="eager"
                onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=800&auto=format&fit=crop&q=85';">

            </div><!-- /.hero-portrait-wrap -->

          </div><!-- /.col-lg-6 -->

        </div>
      </div>

      <!-- Top Layout Row: Modernized Site Announcements and Auto-Swiping Promotional Banner Slider -->
      <div class="row g-4 mb-4 align-items-stretch">

        <!-- Left 7 columns: Modernized Site Announcements -->
        <div class="col-lg-7">
          <div class="moodle-card p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
              <h3 class="fw-bold fs-5 mb-0 d-flex align-items-center gap-2">
                <i class="bi bi-megaphone-fill text-warning"></i>
                <span><?php echo __('site_announcements', 'Site Announcements'); ?></span>
              </h3>
              <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-2.5 py-1 fs-8 fw-semibold">
                <?php echo count($site_announcements); ?> <?php echo __('updates', 'Updates'); ?>
              </span>
            </div>

            <?php if (empty($site_announcements)): ?>
              <div class="text-center py-5 my-auto text-muted">
                <i class="bi bi-bell-slash fs-2 d-block mb-2 text-secondary opacity-50"></i>
                <span
                  class="fs-7 italic"><?php echo __('no_announcements', 'No site announcements at this time.'); ?></span>
              </div>
            <?php else: ?>
              <div class="d-flex flex-column gap-3 overflow-auto pe-1" style="max-height: 420px;">
                <?php foreach ($site_announcements as $idx => $ann): ?>
                  <?php
                  $cat = $ann['category'] ?? 'notice';
                  $cat_class = 'cat-notice';
                  $cat_icon = 'bi-megaphone';
                  $cat_label = __('notice', 'Notice');
                  $cat_badge_class = 'bg-primary bg-opacity-10 text-primary border-primary border-opacity-25';

                  if ($cat === 'offer') {
                    $cat_class = 'cat-offer';
                    $cat_icon = 'bi-gift-fill';
                    $cat_label = __('special_offer', 'Special Offer');
                    $cat_badge_class = 'bg-success bg-opacity-10 text-success border-success border-opacity-25';
                  } elseif ($cat === 'launch') {
                    $cat_class = 'cat-launch';
                    $cat_icon = 'bi-rocket-takeoff-fill';
                    $cat_label = __('course_launch', 'Course Launch');
                    $cat_badge_class = 'bg-purple bg-opacity-10 text-purple border-purple border-opacity-25';
                  } elseif ($cat === 'alert') {
                    $cat_class = 'cat-alert';
                    $cat_icon = 'bi-exclamation-triangle-fill';
                    $cat_label = __('urgent_alert', 'Urgent Alert');
                    $cat_badge_class = 'bg-danger bg-opacity-10 text-danger border-danger border-opacity-25';
                  } elseif ($cat === 'event') {
                    $cat_class = 'cat-event';
                    $cat_icon = 'bi-calendar-event-fill';
                    $cat_label = __('event', 'Event');
                    $cat_badge_class = 'bg-info bg-opacity-10 text-info border-info border-opacity-25';
                  }
                  ?>
                  <div class="announcement-card-modern <?php echo $cat_class; ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                      <div class="d-flex align-items-center gap-1.5">
                        <span
                          class="badge border rounded-pill px-2.5 py-1 fs-8 fw-semibold <?php echo $cat_badge_class; ?>">
                          <i class="bi <?php echo $cat_icon; ?> me-1"></i><?php echo $cat_label; ?>
                        </span>
                        <?php if (!empty($ann['badge_text'])): ?>
                          <span
                            class="badge bg-light text-muted border fs-8"><?php echo htmlspecialchars(__($ann['badge_text'], $ann['badge_text'])); ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="text-muted fs-8 d-flex align-items-center gap-1">
                        <i class="bi bi-clock"></i>
                        <span><?php echo format_time_ago_lms($ann['created_at']); ?></span>
                      </div>
                    </div>

                    <h6 class="fw-bold mb-1 text-dark fs-7">
                      <?php echo htmlspecialchars(__($ann['title'], $ann['title'])); ?>
                    </h6>
                    <p class="text-muted fs-7 mb-0 leading-relaxed">
                      <?php echo nl2br(htmlspecialchars(__($ann['content'], $ann['content']))); ?>
                    </p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right 5 columns: Auto-Swiping Promotional Announcements Slider (Replacing Online Users) -->
        <div class="col-lg-5">
          <div class="moodle-card p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
              <h5 class="fw-bold fs-6 mb-0 d-flex align-items-center gap-2">
                <i class="bi bi-stars text-warning"></i>
                <span><?php echo __('featured_promotions', 'Featured Announcements'); ?></span>
              </h5>
              <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 fs-8">
                <i class="bi bi-play-circle-fill me-1"></i>Live Slider
              </span>
            </div>

            <?php if (empty($promotional_banners)): ?>
              <div class="text-center py-5 my-auto text-muted">
                <i class="bi bi-images fs-2 d-block mb-2 text-secondary opacity-50"></i>
                <span
                  class="fs-7"><?php echo __('no_banners_found', 'No active promotional banners at this time.'); ?></span>
              </div>
            <?php else: ?>
              <!-- Auto-Swiping Ad Carousel Container -->
              <div class="promo-carousel-container flex-grow-1 position-relative" id="promoCarousel">
                <div class="promo-carousel-track" id="promoCarouselTrack">
                  <?php foreach ($promotional_banners as $bIdx => $banner): ?>
                    <div class="promo-slide" data-slide-index="<?php echo $bIdx; ?>">
                      <img src="<?php echo htmlspecialchars($banner['image_path']); ?>"
                        alt="<?php echo htmlspecialchars($banner['title']); ?>" class="promo-slide-img" loading="lazy">
                      <div class="promo-slide-overlay">
                        <!-- Top Pill -->
                        <div class="d-flex justify-content-between align-items-start">
                          <span
                            class="badge bg-dark bg-opacity-60 backdrop-blur border border-white border-opacity-25 rounded-pill px-3 py-1.5 fs-8 text-white fw-semibold d-inline-flex align-items-center gap-1.5">
                            <i class="bi bi-megaphone-fill text-warning"></i>
                            <span><?php echo __('featured_promotions', 'Featured Announcement'); ?></span>
                          </span>
                        </div>

                        <!-- Bottom Content and Action Triggers -->
                        <div>
                          <h5 class="fw-bold text-white mb-1 fs-6 text-shadow" style="letter-spacing: -0.3px;">
                            <?php echo htmlspecialchars(__($banner['title'], $banner['title'])); ?>
                          </h5>
                          <?php if (!empty($banner['subtitle'])): ?>
                            <p class="text-white-50 fs-8 mb-2.5 text-truncate" style="max-width: 90%;">
                              <?php echo htmlspecialchars(__($banner['subtitle'], $banner['subtitle'])); ?>
                            </p>
                          <?php endif; ?>

                          <div class="d-flex align-items-center gap-2 pt-1">
                            <button type="button"
                              class="btn btn-sm btn-primary rounded-pill px-3.5 py-1.5 fs-8 fw-semibold shadow-sm d-inline-flex align-items-center gap-1.5"
                              onclick='openPromoBannerModal(<?php echo json_encode($banner, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                              <i class="bi bi-info-circle-fill"></i>
                              <span><?php echo __('view_details', 'View Details'); ?></span>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <!-- Navigation Controls -->
                <?php if (count($promotional_banners) > 1): ?>
                  <button type="button" class="promo-nav-btn promo-nav-prev" id="promoPrevBtn" aria-label="Previous Slide">
                    <i class="bi bi-chevron-left"></i>
                  </button>
                  <button type="button" class="promo-nav-btn promo-nav-next" id="promoNextBtn" aria-label="Next Slide">
                    <i class="bi bi-chevron-right"></i>
                  </button>

                  <!-- Pagination Dots -->
                  <div class="promo-indicators" id="promoIndicators">
                    <?php for ($d = 0; $d < count($promotional_banners); $d++): ?>
                      <div class="promo-indicator-dot <?php echo $d === 0 ? 'active' : ''; ?>"
                        data-target-slide="<?php echo $d; ?>"></div>
                    <?php endfor; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Available Courses Full-Width Section -->
      <section id="courses-section" class="mb-5">
        <div class="courses-section-wrapper p-4 p-lg-5">

          <!-- Section Header Area -->
          <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4 pb-3 border-bottom">
            <div>
              <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-primary bg-opacity-10 text-primary fs-8 fw-bold mb-2">
                <i class="bi bi-collection-play-fill"></i>
                <span>Curriculum &amp; Programs</span>
              </div>
              <h2 class="fw-bold text-dark fs-3 mb-1 d-flex align-items-center gap-2">
                <span><?php echo __('available_courses', 'Available Courses'); ?></span>
              </h2>
              <p class="text-secondary fs-8 mb-0">Browse active course programs, review syllabi, and enroll in academic and professional tech courses.</p>
            </div>

            <!-- Search bar -->
            <div style="min-width: 280px; max-width: 360px; width: 100%;">
              <div class="courses-search-box">
                <i class="bi bi-search text-muted fs-8"></i>
                <input type="text" id="course-search"
                  placeholder="<?php echo __('search_courses_placeholder', 'Search by title, tutor, or topic...'); ?>">
              </div>
            </div>
          </div>

          <!-- Category Pills Filter Bar -->
          <div class="mb-4 overflow-auto pb-1">
            <div class="d-flex flex-wrap gap-2 align-items-center" id="category-pills">
              <button class="category-btn category-btn-pro active" data-category="">
                <i class="bi bi-grid-fill"></i>
                <span><?php echo __('all', 'All Courses'); ?></span>
              </button>
              <button class="category-btn category-btn-pro" data-category="Computer Science">
                <i class="bi bi-cpu-fill text-primary"></i>
                <span><?php echo __('cat_cs', 'Computer Science'); ?></span>
              </button>
              <button class="category-btn category-btn-pro" data-category="Programming">
                <i class="bi bi-code-slash text-success"></i>
                <span><?php echo __('cat_coding', 'Programming & Software'); ?></span>
              </button>
              <button class="category-btn category-btn-pro" data-category="Web Development">
                <i class="bi bi-globe text-info"></i>
                <span><?php echo __('cat_web', 'Web Development'); ?></span>
              </button>
              <button class="category-btn category-btn-pro" data-category="Artificial Intelligence">
                <i class="bi bi-robot text-purple"></i>
                <span><?php echo __('cat_ai', 'AI & Data Science'); ?></span>
              </button>
              <button class="category-btn category-btn-pro" data-category="Cyber Security">
                <i class="bi bi-shield-lock-fill text-danger"></i>
                <span><?php echo __('cat_cyber', 'Cyber Security'); ?></span>
              </button>
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

  <!-- Interactive Featured Announcement Details Modal -->
  <div class="modal fade" id="promoBannerModal" tabindex="-1" aria-labelledby="promoBannerModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content moodle-card border-0 overflow-hidden shadow-lg" style="border-radius: 20px;">
        <div class="modal-header border-0 pb-0 px-4 pt-4 d-flex justify-content-between align-items-center">
          <span
            class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1.5 fs-8 fw-semibold d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-megaphone-fill"></i> <?php echo __('featured_promotions', 'Featured Announcement'); ?>
          </span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4 p-md-4">
          <!-- Full Uncropped Announcement Image Display -->
          <div id="modal-promo-img-container" class="position-relative rounded-3 overflow-hidden mb-3 border shadow-sm"
            style="background-color: #0b1120; min-height: 200px; max-height: 460px; display: flex; align-items: center; justify-content: center;">
            <img id="modal-promo-img" src="" class="img-fluid w-100"
              style="max-height: 460px; object-fit: contain; display: block;" alt="Announcement Banner">
          </div>

          <!-- Announcement Title & Subtitle -->
          <div class="mb-3">
            <h4 id="modal-promo-title" class="fw-bold text-dark mb-1 fs-5"></h4>
            <p id="modal-promo-subtitle" class="text-muted fs-7 mb-0"></p>
          </div>

          <!-- Description / Rich Details with Clickable Website Links -->
          <div class="mb-2">
            <h6 class="fw-bold text-dark fs-7 mb-2 d-flex align-items-center gap-2">
              <i class="bi bi-info-circle text-primary"></i>
              <span><?php echo __('banner_details', 'Announcement Details'); ?></span>
            </h6>
            <div id="modal-promo-details"
              class="promo-details-content p-3 bg-light rounded-3 border fs-7 text-secondary leading-relaxed"
              style="max-height: 260px; overflow-y: auto; word-break: break-word; line-height: 1.65;"></div>
          </div>
        </div>

        <div class="modal-footer bg-light border-top px-4 py-3 d-flex justify-content-end">
          <button type="button" class="btn btn-secondary rounded-pill px-4 py-1.5 fs-7 fw-semibold"
            data-bs-dismiss="modal">
            <?php echo __('close', 'Close'); ?>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Course Detail Popup Modal -->
  <div class="modal fade" id="courseDetailModal" tabindex="-1" aria-labelledby="courseDetailModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content moodle-card border-0 overflow-hidden shadow-lg" style="border-radius: 20px;">
        <div class="modal-header border-0 pb-0 position-relative px-4 pt-4">
          <h5 class="modal-title fw-bold text-dark fs-5" id="courseDetailModalLabel">
            <?php echo __('course_details', 'Course Details'); ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4 p-md-5 pt-3">
          <div class="row g-4">
            <div class="col-md-5">
              <img id="modal-course-thumbnail" src="" class="w-100 rounded-3 shadow-sm"
                style="height: 200px; object-fit: cover;" alt="Course Thumbnail">

              <!-- Pricing Highlight Box -->
              <div id="modal-pricing-box"
                class="mt-3 p-3 rounded-3 border d-flex align-items-center justify-content-between">
                <!-- Dynamically populated -->
              </div>

              <!-- Tutor Info -->
              <div class="mt-3 p-3 bg-light rounded-3 d-flex align-items-center gap-3">
                <img id="modal-tutor-avatar" src="" class="rounded-circle border border-primary border-opacity-20"
                  style="width: 44px; height: 44px; object-fit: cover;" alt="Tutor">
                <div>
                  <h6 id="modal-tutor-name" class="fw-bold mb-0 text-dark fs-7"></h6>
                  <small id="modal-tutor-title" class="text-muted fs-8 d-block"></small>
                </div>
              </div>
            </div>

            <div class="col-md-7 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                  <span id="modal-course-category"
                    class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-3 py-1.5 rounded-pill fs-8"></span>
                  <span id="modal-course-level"
                    class="badge bg-secondary bg-opacity-10 text-secondary fw-semibold px-3 py-1.5 rounded-pill fs-8"></span>
                  <span id="modal-course-duration"
                    class="badge bg-light text-dark border px-3 py-1.5 rounded-pill fs-8"></span>
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
                  <h6 class="fw-bold text-dark mb-1 fs-7"><i
                      class="bi bi-people text-primary me-1"></i><?php echo __('target_audience', 'Target Audience'); ?>
                  </h6>
                  <div id="modal-course-target-audience" class="d-flex flex-wrap gap-1"></div>
                </div>

                <h6 class="fw-bold text-dark mb-1 fs-7"><?php echo __('course_overview', 'Course Overview'); ?></h6>
                <p id="modal-course-description" class="text-muted fs-7 leading-relaxed mb-3"
                  style="max-height: 180px; overflow-y: auto;"></p>
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