<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

$logged_in = false;
$user = null;
$unread_count = 0;
$is_admin = false;
$is_teacher = false;
$all_courses_sidebar = [];
$enrolled_courses = [];

try {
  $pdo = getDBConnection();
  if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
      $logged_in = true;
      $is_admin = in_array($user['role'] ?? '', ['admin', 'super_admin']);
      $is_teacher = (($user['role'] ?? '') === 'teacher');

      $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
      $stmt->execute([$user['id']]);
      $enrolled_courses = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
      $stmt->execute([$user['id']]);
      $unread_count = (int) $stmt->fetchColumn();
    }
  }

  // Fetch courses for the drawer navigation
  $stmt = $pdo->query("SELECT id, title, tutor_id FROM courses WHERE (status = 'approved' OR status = 'active') AND (is_archived = 0 OR is_archived IS NULL) AND deleted_at IS NULL LIMIT 20");
  $all_courses_sidebar = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
  error_log("about.php DB error: " . $e->getMessage());
}

// Fetch About Us settings
$about = function_exists('get_about_settings') ? get_about_settings($pdo) : [];

// Resolve background image
$bg_image_url = !empty($about['bg_image']) ? htmlspecialchars($about['bg_image']) : 'uploads/about/default_about_bg.jpg';
$overlay_style = $about['overlay_fade_style'] ?? 'dark_vignette';
$overlay_opacity = floatval($about['overlay_opacity'] ?? 0.65);
if ($overlay_opacity < 0.1) $overlay_opacity = 0.1;
if ($overlay_opacity > 0.95) $overlay_opacity = 0.95;

// Compute overlay gradient based on chosen fade style
$overlay_css = "";
switch ($overlay_style) {
  case 'navy_gradient':
    $overlay_css = "linear-gradient(135deg, rgba(11, 25, 44, {$overlay_opacity}) 0%, rgba(15, 76, 129, " . ($overlay_opacity * 0.9) . ") 55%, rgba(6, 40, 75, {$overlay_opacity}) 100%)";
    break;
  case 'soft_black':
    $overlay_css = "linear-gradient(180deg, rgba(15, 23, 42, {$overlay_opacity}) 0%, rgba(15, 23, 42, " . ($overlay_opacity * 0.85) . ") 50%, rgba(15, 23, 42, {$overlay_opacity}) 100%)";
    break;
  case 'glass_blur':
    $overlay_css = "linear-gradient(180deg, rgba(15, 76, 129, " . ($overlay_opacity * 0.75) . ") 0%, rgba(11, 25, 44, {$overlay_opacity}) 100%)";
    break;
  case 'dark_vignette':
  default:
    $overlay_css = "linear-gradient(180deg, rgba(11, 25, 44, {$overlay_opacity}) 0%, rgba(15, 76, 129, " . ($overlay_opacity * 0.85) . ") 50%, rgba(11, 25, 44, " . ($overlay_opacity * 0.98) . ") 100%), radial-gradient(circle at center, transparent 35%, rgba(5, 15, 30, " . ($overlay_opacity * 0.9) . ") 100%)";
    break;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo function_exists('__') ? __('about_page_title', 'About Us | Computerscience.lk') : 'About Us | Computerscience.lk'; ?></title>
  <link rel="icon" type="image/x-icon"
    href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon"
    href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>

  <!-- Google Fonts: Inter & Outfit -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

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
  <link rel="stylesheet" href="assets/css/notifications.css">

  <style>
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f4f7fb;
      color: #334155;
      overflow-x: hidden;
    }

    h1, h2, h3, h4, .font-heading {
      font-family: 'Outfit', sans-serif;
    }

    #moodle-content-wrapper,
    .moodle-content-wrapper {
      margin-left: 0 !important;
      padding-top: 70px;
      width: 100% !important;
    }

    /* Hero Banner with Dynamic Background & Slight Fade Effect */
    .about-hero-wrapper {
      position: relative;
      background-image: url('<?php echo $bg_image_url; ?>');
      background-position: center center;
      background-size: cover;
      background-repeat: no-repeat;
      background-attachment: scroll;
      min-height: 520px;
      display: flex;
      align-items: center;
      overflow: hidden;
    }

    /* Main Overlay Fade Layer */
    .about-hero-overlay {
      position: absolute;
      inset: 0;
      background: <?php echo $overlay_css; ?>;
      z-index: 1;
      <?php if ($overlay_style === 'glass_blur'): ?>
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
      <?php endif; ?>
    }

    /* Subtle Bottom Edge Fade Dissolving into the Page Body */
    .about-hero-bottom-fade {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 120px;
      background: linear-gradient(to bottom, rgba(244, 247, 251, 0) 0%, rgba(244, 247, 251, 0.6) 50%, #f4f7fb 100%);
      z-index: 2;
      pointer-events: none;
    }

    /* Hero Foreground Content */
    .about-hero-content {
      position: relative;
      z-index: 3;
      padding-top: 5rem;
      padding-bottom: 6rem;
    }

    /* Glassmorphism Cards */
    .glass-card-premium {
      background: rgba(255, 255, 255, 0.92);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(226, 232, 240, 0.8);
      border-radius: 20px;
      box-shadow: 0 10px 30px -5px rgba(15, 76, 129, 0.07);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .glass-card-premium:hover {
      transform: translateY(-4px);
      box-shadow: 0 20px 40px -10px rgba(15, 76, 129, 0.12);
      border-color: rgba(15, 76, 129, 0.25);
    }

    /* Hero Floating Badge */
    .hero-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1.15rem;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.3);
      backdrop-filter: blur(10px);
      color: #ffffff;
      font-size: 0.85rem;
      font-weight: 600;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    /* Stat Cards Overlapping Hero */
    .stats-overlap-container {
      margin-top: -3.5rem;
      position: relative;
      z-index: 4;
    }

    .stat-counter-box {
      background: #ffffff;
      border-radius: 18px;
      padding: 1.75rem 1.25rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 12px 35px -8px rgba(15, 76, 129, 0.1);
      transition: all 0.3s ease;
      text-align: center;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
    }

    .stat-counter-box:hover {
      transform: translateY(-5px);
      border-color: #0f4c81;
      box-shadow: 0 20px 40px -10px rgba(15, 76, 129, 0.18);
    }

    .stat-number {
      font-family: 'Outfit', sans-serif;
      font-size: 2.4rem;
      font-weight: 800;
      line-height: 1.1;
      background: linear-gradient(135deg, #0f4c81 0%, #1e88e5 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    /* Icon Box Accents */
    .icon-accent-box {
      width: 54px;
      height: 54px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      flex-shrink: 0;
      transition: all 0.3s ease;
    }

    .pillar-card:hover .icon-accent-box {
      transform: scale(1.1) rotate(-4deg);
    }

    /* Story Editorial Styling */
    .story-paragraph {
      font-size: 1.05rem;
      line-height: 1.85;
      color: #475569;
    }

    /* Floating Admin Edit Button */
    .admin-quick-edit-pill {
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 1050;
      background: linear-gradient(135deg, #0b192c 0%, #0f4c81 100%);
      color: #ffffff;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 50px;
      padding: 10px 20px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
      font-size: 0.85rem;
      font-weight: 600;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.3s ease;
    }

    .admin-quick-edit-pill:hover {
      transform: translateY(-3px) scale(1.03);
      color: #ffffff;
      box-shadow: 0 15px 30px rgba(15, 76, 129, 0.4);
    }
  </style>
</head>

<body>

  <!-- Unified LMS Top Navigation Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Mobile Navigation Drawer -->
  <aside id="moodle-drawer" class="moodle-drawer collapsed">
    <div class="d-flex flex-column">
      <div class="px-3 py-2.5 mb-2 d-flex align-items-center justify-content-between border-bottom bg-light bg-opacity-50">
        <span class="fs-8 fw-bold text-uppercase tracking-wider text-muted d-flex align-items-center gap-1.5">
          <i class="bi bi-compass-fill text-primary"></i>
          <span><?php echo function_exists('__') ? __('navigation', 'Navigation') : 'Navigation'; ?></span>
        </span>
        <button type="button"
          class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center drawer-close-trigger text-secondary"
          style="width: 32px; height: 32px;" title="Close">
          <i class="bi bi-x-lg fs-6"></i>
        </button>
      </div>

      <a href="index.php" class="drawer-link">
        <i class="bi bi-house-door fs-5 text-secondary"></i> Site Home
      </a>
      <?php if ($logged_in): ?>
        <a href="dashboard.php" class="drawer-link">
          <i class="bi bi-speedometer2 fs-5 text-secondary"></i> Dashboard
        </a>
        <a href="my_courses.php" class="drawer-link">
          <i class="bi bi-journal-bookmark fs-5 text-secondary"></i> My Courses
        </a>
      <?php endif; ?>
      <a href="about.php" class="drawer-link active">
        <i class="bi bi-info-circle fs-5 text-primary"></i> About Us
      </a>

      <?php if (!empty($all_courses_sidebar)): ?>
        <hr class="mx-3 my-2 border-secondary border-opacity-20">
        <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider">
          Available Courses
        </div>
        <?php foreach (array_slice($all_courses_sidebar, 0, 8) as $cs_course): ?>
          <a href="<?php echo $logged_in ? 'classroom.php?course_id=' . htmlspecialchars($cs_course['id']) : 'index.php#courses-section'; ?>" class="drawer-link py-2 fs-8 text-truncate">
            <i class="bi bi-book me-2"></i> <?php echo htmlspecialchars($cs_course['title']); ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Main Content Wrapper -->
  <main id="moodle-content-wrapper" class="moodle-content-wrapper full-width">

    <!-- HERO SECTION with Customizable Background Image & Slight Fade Effect -->
    <section class="about-hero-wrapper" id="about-hero">
      <!-- Background Fade Overlay -->
      <div class="about-hero-overlay"></div>
      <!-- Bottom Edge Gradient Fade to Blend Smoothly with the Page Content -->
      <div class="about-hero-bottom-fade"></div>

      <div class="container about-hero-content">
        <div class="row align-items-center justify-content-center text-center">
          <div class="col-lg-10 col-xl-9">

            <!-- Floating Tag / Badge -->
            <div class="d-inline-block mb-3">
              <span class="hero-badge-pill">
                <i class="bi bi-stars text-warning"></i>
                <span><?php echo htmlspecialchars($about['badge_text'] ?? 'About Computerscience.lk'); ?></span>
              </span>
            </div>

            <!-- Main Headline -->
            <h1 class="display-4 fw-bold text-white mb-3 text-shadow" style="letter-spacing: -0.02em;">
              <?php echo htmlspecialchars($about['title'] ?? 'Empowering the Next Generation of Tech Leaders & Innovators'); ?>
            </h1>

            <!-- Subtitle / Introductory Paragraph -->
            <p class="lead text-white text-opacity-90 fs-5 mb-4 mx-auto" style="max-width: 820px; line-height: 1.7;">
              <?php echo nl2br(htmlspecialchars($about['subtitle'] ?? 'Computerscience.lk is Sri Lanka’s dedicated online academic portal designed to impart university-grade computer science education, practical software engineering skills, and globally accredited certifications.')); ?>
            </p>

            <!-- Call to Action Buttons -->
            <div class="d-flex flex-wrap align-items-center justify-content-center gap-3">
              <a href="<?php echo htmlspecialchars($about['cta_btn_url'] ?? 'index.php#courses-section'); ?>"
                class="btn btn-primary btn-lg px-4 py-2.5 rounded-pill fw-bold shadow-lg d-inline-flex align-items-center gap-2 text-white border-0"
                style="background-color: #f26f21;">
                <i class="bi bi-collection-play-fill"></i>
                <span><?php echo htmlspecialchars($about['cta_btn_text'] ?? 'Explore Courses'); ?></span>
              </a>

              <a href="#contact-section"
                class="btn btn-outline-light btn-lg px-4 py-2.5 rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
                <i class="bi bi-chat-dots-fill"></i>
                <span><?php echo function_exists('__') ? __('contact_faculty', 'Contact Faculty & Support') : 'Contact Faculty & Support'; ?></span>
              </a>
            </div>

          </div>
        </div>
      </div>
    </section>

    <!-- KEY MILESTONES & NUMBERS (Overlapping Cards) -->
    <section class="container stats-overlap-container mb-5">
      <div class="row g-3 g-md-4">
        <!-- Stat 1 -->
        <div class="col-6 col-md-3">
          <div class="stat-counter-box">
            <div class="stat-number mb-1"><?php echo htmlspecialchars($about['stat_1_number'] ?? '30,000+'); ?></div>
            <div class="fw-semibold text-secondary fs-8"><?php echo htmlspecialchars($about['stat_1_label'] ?? 'Enrolled Students'); ?></div>
          </div>
        </div>

        <!-- Stat 2 -->
        <div class="col-6 col-md-3">
          <div class="stat-counter-box">
            <div class="stat-number mb-1"><?php echo htmlspecialchars($about['stat_2_number'] ?? '120+'); ?></div>
            <div class="fw-semibold text-secondary fs-8"><?php echo htmlspecialchars($about['stat_2_label'] ?? 'Certified Courses'); ?></div>
          </div>
        </div>

        <!-- Stat 3 -->
        <div class="col-6 col-md-3">
          <div class="stat-counter-box">
            <div class="stat-number mb-1"><?php echo htmlspecialchars($about['stat_3_number'] ?? '50+'); ?></div>
            <div class="fw-semibold text-secondary fs-8"><?php echo htmlspecialchars($about['stat_3_label'] ?? 'Expert Mentors'); ?></div>
          </div>
        </div>

        <!-- Stat 4 -->
        <div class="col-6 col-md-3">
          <div class="stat-counter-box">
            <div class="stat-number mb-1"><?php echo htmlspecialchars($about['stat_4_number'] ?? '98.5%'); ?></div>
            <div class="fw-semibold text-secondary fs-8"><?php echo htmlspecialchars($about['stat_4_label'] ?? 'Student Satisfaction'); ?></div>
          </div>
        </div>
      </div>
    </section>

    <!-- OUR STORY & MISSION/VISION SECTION -->
    <section class="container my-5 py-3">
      <div class="row g-4 align-items-stretch">

        <!-- Left Column: The Narrative Story -->
        <div class="col-lg-7">
          <div class="glass-card-premium p-4 p-md-5 h-100 d-flex flex-column justify-content-between">
            <div>
              <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-primary bg-opacity-10 text-primary fw-bold fs-9 mb-3">
                <i class="bi bi-clock-history"></i>
                <span><?php echo htmlspecialchars($about['story_badge'] ?? 'Our Heritage & Journey'); ?></span>
              </div>

              <h2 class="display-6 fw-bold text-dark mb-4" style="line-height: 1.25;">
                <?php echo htmlspecialchars($about['story_title'] ?? 'Bridging Higher Academics with Real-World Software Engineering'); ?>
              </h2>

              <div class="story-paragraph">
                <?php
                $story_paragraphs = preg_split("/\r\n|\n|\r/", $about['story_content'] ?? '');
                foreach ($story_paragraphs as $para) {
                  $para = trim($para);
                  if (!empty($para)) {
                    echo '<p class="mb-3">' . htmlspecialchars($para) . '</p>';
                  }
                }
                ?>
              </div>
            </div>

            <!-- Highlight Quote Box -->
            <div class="p-3 p-md-4 mt-4 rounded-3 border-start border-4 border-primary bg-light bg-opacity-75">
              <div class="d-flex align-items-center gap-3">
                <i class="bi bi-quote fs-1 text-primary opacity-50"></i>
                <div>
                  <p class="mb-1 text-dark fw-semibold fst-italic fs-7">
                    "Democratizing technological education is not just an aspiration; it is the cornerstone of South Asia's digital advancement."
                  </p>
                  <small class="text-secondary fw-bold fs-9 text-uppercase tracking-wider">— Academic Directorate, Computerscience.lk</small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column: Mission & Vision Cards -->
        <div class="col-lg-5 d-flex flex-column gap-4">

          <!-- Mission Card -->
          <div class="glass-card-premium p-4 p-md-4 flex-fill border-start border-4" style="border-left-color: #0f4c81 !important;">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="icon-accent-box bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-compass-fill"></i>
              </div>
              <div>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-2.5 py-0.5 fs-9 fw-bold text-uppercase">Pillar of Purpose</span>
                <h3 class="fw-bold text-dark fs-5 mb-0 mt-1"><?php echo htmlspecialchars($about['mission_title'] ?? 'Our Mission'); ?></h3>
              </div>
            </div>
            <p class="text-secondary fs-7 mb-0" style="line-height: 1.7;">
              <?php echo nl2br(htmlspecialchars($about['mission_content'] ?? 'To democratize access to world-class computational education, offering students and professionals rigorous, practical courses, hands-on development experience, and industry mentorship that accelerates their technical careers.')); ?>
            </p>
          </div>

          <!-- Vision Card -->
          <div class="glass-card-premium p-4 p-md-4 flex-fill border-start border-4" style="border-left-color: #10b981 !important;">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="icon-accent-box bg-success bg-opacity-10 text-success">
                <i class="bi bi-eye-fill"></i>
              </div>
              <div>
                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-0.5 fs-9 fw-bold text-uppercase">Future Horizon</span>
                <h3 class="fw-bold text-dark fs-5 mb-0 mt-1"><?php echo htmlspecialchars($about['vision_title'] ?? 'Our Vision'); ?></h3>
              </div>
            </div>
            <p class="text-secondary fs-7 mb-0" style="line-height: 1.7;">
              <?php echo nl2br(htmlspecialchars($about['vision_content'] ?? 'To be the preeminent technology learning hub in South Asia, fostering a culture of innovation, critical computational problem-solving, and continuous academic advancement.')); ?>
            </p>
          </div>

        </div>

      </div>
    </section>

    <!-- CORE PILLARS / WHY CHOOSE US -->
    <section class="container my-5 py-4">
      <div class="text-center mb-5">
        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1 fs-8 fw-bold text-uppercase">
          <i class="bi bi-shield-check me-1"></i><?php echo function_exists('__') ? __('why_choose_us', 'Why Choose Computerscience.lk') : 'Why Choose Computerscience.lk'; ?>
        </span>
        <h2 class="display-6 fw-bold text-dark mt-2 mb-2">Built for Real-World Competence & Rigor</h2>
        <p class="text-secondary fs-7 mx-auto" style="max-width: 650px;">
          Explore how our platform structure and pedagogic approach equip learners to thrive in modern software engineering and tech industries.
        </p>
      </div>

      <div class="row g-4">
        <!-- Pillar 1 -->
        <div class="col-md-6 col-lg-3">
          <div class="glass-card-premium p-4 h-100 pillar-card">
            <div class="icon-accent-box bg-primary bg-opacity-10 text-primary mb-3">
              <i class="<?php echo htmlspecialchars($about['pillar_1_icon'] ?? 'bi-cpu'); ?>"></i>
            </div>
            <h4 class="fw-bold text-dark fs-6 mb-2"><?php echo htmlspecialchars($about['pillar_1_title'] ?? 'Industry-Aligned Curricula'); ?></h4>
            <p class="text-secondary fs-8 mb-0"><?php echo htmlspecialchars($about['pillar_1_desc'] ?? 'Engineered alongside software architects to match cutting-edge industry demands.'); ?></p>
          </div>
        </div>

        <!-- Pillar 2 -->
        <div class="col-md-6 col-lg-3">
          <div class="glass-card-premium p-4 h-100 pillar-card">
            <div class="icon-accent-box bg-warning bg-opacity-10 text-warning mb-3">
              <i class="<?php echo htmlspecialchars($about['pillar_2_icon'] ?? 'bi-award-fill'); ?>"></i>
            </div>
            <h4 class="fw-bold text-dark fs-6 mb-2"><?php echo htmlspecialchars($about['pillar_2_title'] ?? 'Recognized Certificates'); ?></h4>
            <p class="text-secondary fs-8 mb-0"><?php echo htmlspecialchars($about['pillar_2_desc'] ?? 'Earn verifiable digital credentials and embossed hard-copy diplomas delivered island-wide.'); ?></p>
          </div>
        </div>

        <!-- Pillar 3 -->
        <div class="col-md-6 col-lg-3">
          <div class="glass-card-premium p-4 h-100 pillar-card">
            <div class="icon-accent-box bg-danger bg-opacity-10 text-danger mb-3">
              <i class="<?php echo htmlspecialchars($about['pillar_3_icon'] ?? 'bi-camera-video-fill'); ?>"></i>
            </div>
            <h4 class="fw-bold text-dark fs-6 mb-2"><?php echo htmlspecialchars($about['pillar_3_title'] ?? 'Live & Interactive Classes'); ?></h4>
            <p class="text-secondary fs-8 mb-0"><?php echo htmlspecialchars($about['pillar_3_desc'] ?? 'Real-time lecture streams with hands-on coding demonstrations and instant tutor Q&A.'); ?></p>
          </div>
        </div>

        <!-- Pillar 4 -->
        <div class="col-md-6 col-lg-3">
          <div class="glass-card-premium p-4 h-100 pillar-card">
            <div class="icon-accent-box bg-success bg-opacity-10 text-success mb-3">
              <i class="<?php echo htmlspecialchars($about['pillar_4_icon'] ?? 'bi-people-fill'); ?>"></i>
            </div>
            <h4 class="fw-bold text-dark fs-6 mb-2"><?php echo htmlspecialchars($about['pillar_4_title'] ?? 'Supportive Community'); ?></h4>
            <p class="text-secondary fs-8 mb-0"><?php echo htmlspecialchars($about['pillar_4_desc'] ?? 'Join peer collaborative study groups, assignment code reviews, and career guidance.'); ?></p>
          </div>
        </div>
      </div>
    </section>

    <!-- CAMPUS & SUPPORT CONTACT DETAILS -->
    <section class="container my-5" id="contact-section">
      <div class="glass-card-premium p-4 p-md-5">
        <div class="row g-4 align-items-center">

          <div class="col-lg-5">
            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1 fs-8 fw-bold text-uppercase mb-2">
              <i class="bi bi-geo-alt-fill me-1"></i>Campus & Faculty Connect
            </span>
            <h3 class="display-6 fw-bold text-dark mb-3">Get in Touch with Our Academic Team</h3>
            <p class="text-secondary fs-7 mb-4">
              Have questions regarding module prerequisites, enrollment procedures, or technical certification? Our dedicated administrative coordinators are here to assist.
            </p>

            <div class="d-flex flex-column gap-3">
              <!-- Email -->
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                  <i class="bi bi-envelope-at-fill fs-5"></i>
                </div>
                <div>
                  <small class="text-muted d-block fs-9 text-uppercase fw-bold">Official Email</small>
                  <a href="mailto:<?php echo htmlspecialchars($about['contact_email'] ?? 'info@computerscience.lk'); ?>" class="fw-bold text-dark text-decoration-none hover-underline">
                    <?php echo htmlspecialchars($about['contact_email'] ?? 'info@computerscience.lk'); ?>
                  </a>
                </div>
              </div>

              <!-- Phone -->
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                  <i class="bi bi-telephone-fill fs-5"></i>
                </div>
                <div>
                  <small class="text-muted d-block fs-9 text-uppercase fw-bold">Hotline & Inquiries</small>
                  <a href="tel:<?php echo htmlspecialchars($about['contact_phone'] ?? '0112345678'); ?>" class="fw-bold text-dark text-decoration-none hover-underline">
                    <?php echo htmlspecialchars($about['contact_phone'] ?? '011 234 5678'); ?>
                  </a>
                </div>
              </div>

              <!-- Address -->
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                  <i class="bi bi-building fs-5"></i>
                </div>
                <div>
                  <small class="text-muted d-block fs-9 text-uppercase fw-bold">Academic Headquarters</small>
                  <span class="fw-bold text-dark">
                    <?php echo htmlspecialchars($about['contact_address'] ?? 'Colombo, Western Province, Sri Lanka'); ?>
                  </span>
                </div>
              </div>

              <!-- Working Hours -->
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                  <i class="bi bi-clock-fill fs-5"></i>
                </div>
                <div>
                  <small class="text-muted d-block fs-9 text-uppercase fw-bold">Operational Hours</small>
                  <span class="fw-bold text-dark">
                    <?php echo htmlspecialchars($about['contact_hours'] ?? 'Mon - Sat: 8.30 AM - 6.00 PM'); ?>
                  </span>
                </div>
              </div>
            </div>

          </div>

          <!-- Right Column: Interactive Quick Inquiries / Callout -->
          <div class="col-lg-7">
            <div class="p-4 p-md-5 rounded-4 text-white shadow-lg position-relative overflow-hidden"
              style="background: linear-gradient(135deg, #0b192c 0%, #0f4c81 60%, #1e88e5 100%);">
              <div class="position-relative" style="z-index: 2;">
                <span class="badge bg-warning text-dark fw-bold rounded-pill px-3 py-1 fs-9 text-uppercase mb-3">
                  <i class="bi bi-rocket-takeoff-fill me-1"></i>Accelerate Your Career
                </span>
                <h3 class="fw-bold text-white display-6 mb-3"><?php echo htmlspecialchars($about['cta_title'] ?? 'Start Your Computing Journey Today'); ?></h3>
                <p class="text-white text-opacity-90 fs-7 mb-4" style="line-height: 1.7;">
                  <?php echo nl2br(htmlspecialchars($about['cta_desc'] ?? 'Explore our comprehensive course catalog, build production-ready projects, and achieve recognized certification.')); ?>
                </p>

                <div class="d-flex flex-wrap align-items-center gap-3">
                  <a href="<?php echo htmlspecialchars($about['cta_btn_url'] ?? 'index.php#courses-section'); ?>"
                    class="btn btn-warning btn-lg px-4 py-2.5 rounded-pill fw-bold text-dark shadow-sm">
                    <i class="bi bi-mortarboard-fill me-1"></i><?php echo htmlspecialchars($about['cta_btn_text'] ?? 'Browse Courses'); ?>
                  </a>
                  <?php if (!$logged_in): ?>
                    <a href="register.php" class="btn btn-outline-light btn-lg px-4 py-2.5 rounded-pill fw-semibold">
                      Create Free Account
                    </a>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Decorative ambient glow circle -->
              <div style="position: absolute; right: -40px; bottom: -40px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%); pointer-events: none;"></div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- Floating Quick Admin Link Button (Visible only to Admins) -->
    <?php if ($is_admin): ?>
      <a href="admin/index.php?tab=about" class="admin-quick-edit-pill" title="Customize this page in Admin Panel">
        <i class="bi bi-pencil-square text-warning fs-6"></i>
        <span><?php echo function_exists('__') ? __('customize_about_page', 'Customize This Page (Admin)') : 'Customize This Page (Admin)'; ?></span>
      </a>
    <?php endif; ?>

    <!-- Unified Site Footer -->
    <footer class="py-4 bg-dark text-white-50 border-t mt-5">
      <div class="container text-center">
        <p class="mb-1 text-white fw-semibold">Computerscience.lk LMS</p>
        <p class="fs-7 mb-0">Powered by Computerscience.lk &copy; <?php echo date('Y'); ?>. All Rights Reserved.</p>
      </div>
    </footer>

  </main>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>

</html>
