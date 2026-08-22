<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Access Control: Registered users (Students & Teachers / Admins) only!
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? 'student';
$is_teacher = ($user_role === 'teacher' || in_array($user_role, ['admin', 'super_admin']));

$pdo = getDBConnection();
$unread_count = 0;
$notifications = [];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    // Graceful fallback
}

$raw_avatar = $_SESSION['user_avatar'] ?? '';
if (empty($raw_avatar)) {
    $avatar_src = 'https://ui-avatars.com/api/?name=' . urlencode($user_name) . '&background=0f4c81&color=fff';
} elseif (preg_match('~^https?://~i', $raw_avatar) || strpos($raw_avatar, 'data:') === 0) {
    $avatar_src = $raw_avatar;
} else {
    $avatar_src = ltrim($raw_avatar, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Live Classes - Coming Soon | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>

  <!-- Local Bootstrap 5 CSS & Icons -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
  <!-- Modern Notification System Styles -->
  <link rel="stylesheet" href="assets/css/notifications.css">

  <style>
    body {
      background-color: #f8fafc;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      color: #1e293b;
    }
    .live-hero-card {
      background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 50%, #0284c7 100%);
      color: #ffffff;
      border-radius: 24px;
      padding: 3.5rem 2rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 20px 40px rgba(15, 76, 129, 0.2);
    }
    .live-hero-card::before {
      content: '';
      position: absolute;
      top: -100px;
      right: -100px;
      width: 350px;
      height: 350px;
      background: rgba(239, 68, 68, 0.15);
      border-radius: 50%;
      filter: blur(50px);
    }
    .live-pulse-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #f87171;
      padding: 6px 16px;
      border-radius: 50rem;
      font-weight: 700;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .pulse-dot {
      width: 10px;
      height: 10px;
      background-color: #ef4444;
      border-radius: 50%;
      animation: pulseAnimation 1.5s infinite;
    }
    @keyframes pulseAnimation {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    .feature-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid #e2e8f0;
      padding: 1.75rem;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03);
      transition: all 0.25s ease;
      height: 100%;
    }
    .feature-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 25px rgba(0,0,0,0.08);
      border-color: #cbd5e1;
    }
    .feature-icon-box {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin-bottom: 1.25rem;
    }
  </style>
</head>
<body>

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Main Hero & Feature Showcase -->
  <main class="container py-5" style="max-width: 1140px; margin-top: 30px;">

    <!-- Hero Card -->
    <div class="live-hero-card mb-5 text-center text-md-start">
      <div class="row align-items-center g-4">
        <div class="col-lg-8">
          <div class="live-pulse-badge mb-3">
            <div class="pulse-dot"></div>
            <span>LIVE CLASSES PLATFORM</span>
          </div>
          <h1 class="display-5 fw-extrabold mb-3 text-white">Interactive Live Online Classes</h1>
          <h4 class="fw-bold text-warning mb-3">Feature Coming Soon!</h4>
          <p class="fs-6 text-white-50 mb-4" style="max-width: 680px; line-height: 1.6;">
            We are engineering a state-of-the-art live stream lecture environment for computer science students and educators. Experience real-time HD video streaming, live code walkthroughs, interactive Q&A, and automated recording archives.
          </p>
          <div class="d-flex flex-wrap align-items-center gap-3">
            <a href="dashboard.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold shadow-sm text-dark">
              <i class="bi bi-arrow-left me-2"></i> Return to Dashboard
            </a>
            <a href="my_courses.php" class="btn btn-outline-light btn-lg rounded-pill px-4 fw-bold">
              <i class="bi bi-book-half me-2"></i> Browse Courses
            </a>
          </div>
        </div>
        <div class="col-lg-4 text-center">
          <div class="p-4 bg-white bg-opacity-10 rounded-4 border border-white border-opacity-20 shadow-lg backdrop-blur">
            <i class="bi bi-broadcast text-warning display-1 mb-2 d-block"></i>
            <div class="fw-bold text-white fs-6">Real-Time HD Lectures</div>
            <div class="fs-8 text-white-50 mt-1">Launching Soon for Enrolled Students & Instructors</div>
          </div>
        </div>
      </div>
    </div>

    <!-- User Role Info Alert -->
    <div class="p-4 bg-white border rounded-4 shadow-sm mb-5">
      <div class="d-flex align-items-center gap-3">
        <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary fs-3">
          <i class="bi bi-info-circle-fill"></i>
        </div>
        <div>
          <h6 class="fw-bold text-dark mb-1">
            <?php echo $is_teacher ? 'Educator Information Notice' : 'Registered Student Access Notice'; ?>
          </h6>
          <p class="text-secondary fs-8 mb-0">
            <?php if ($is_teacher): ?>
              As an educator, you will soon be able to schedule, host, and broadcast live lectures directly from your instructor portal with live attendance tracking and automated recordings.
            <?php else: ?>
              As a registered student, you will receive automatic notification bell alerts whenever your instructors schedule upcoming live sessions for your enrolled courses.
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>

    

  </main>

  <!-- Bootstrap 5 JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
</body>
</html>
