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
  <script src="assets/js/session_manager.js"></script>

  <!-- Local Bootstrap 5 CSS & Icons -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">

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

  <!-- Site Navigation Header -->
  <header class="moodle-header px-4 bg-white border-bottom shadow-sm">
    <div class="container-fluid d-flex align-items-center justify-content-between">
      
      <!-- Brand Logo -->
      <div class="d-flex align-items-center">
        <a class="moodle-brand fw-bold text-decoration-none fs-4 d-flex align-items-center" href="index.php" style="color: #0f4c81;">
          <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2" style="height: 32px; width: auto; object-fit: contain;">computerscience.lk
        </a>
      </div>

      <!-- Center Navbar Links for Registered Users -->
      <nav class="d-none d-lg-flex align-items-center gap-2">
        <a href="index.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_home', 'Site Home'); ?></a>
        <a href="dashboard.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_dashboard', 'Dashboard'); ?></a>
        <a href="my_courses.php" class="btn btn-light px-3 text-secondary"><?php echo $is_teacher ? __('nav_uploaded_courses', 'Uploaded Courses') : __('nav_my_courses', 'My Courses'); ?></a>
        <a href="live_classes.php" class="btn btn-light text-danger fw-bold px-3 d-inline-flex align-items-center gap-1.5 border border-danger border-opacity-25 bg-danger bg-opacity-10">
          <i class="bi bi-broadcast text-danger fs-7"></i>
          <span>Live Classes</span>
        </a>
      </nav>

      <!-- Right Header Actions -->
      <div class="d-flex align-items-center gap-2.5">
        <!-- Notification Dropdown -->
        <div class="dropdown">
          <button class="text-secondary fs-5 border-0 bg-transparent p-2 position-relative dropdown-toggle no-caret" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotificationsAsRead()">
            <i class="bi bi-bell"></i>
            <?php if ($unread_count > 0): ?>
              <span class="position-absolute top-1 end-1 translate-middle badge rounded-circle bg-danger" id="notification-badge" style="padding: 4px; font-size: 0.5rem;"><?php echo $unread_count; ?></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light py-2" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 400px; overflow-y: auto;">
            <li class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2 d-flex justify-content-between align-items-center">
              <span>Notifications</span>
              <?php if ($unread_count > 0): ?>
                <span class="badge bg-primary text-white fs-9" id="notification-count"><?php echo $unread_count; ?> new</span>
              <?php endif; ?>
            </li>
            <?php if (empty($notifications)): ?>
              <li class="px-3 py-4 text-center text-muted fs-8 italic">No notifications yet.</li>
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

        <!-- User Profile Dropdown -->
        <div class="dropdown ms-2">
          <button class="btn p-0 border-0 bg-transparent dropdown-toggle no-caret d-flex align-items-center gap-2" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?php echo htmlspecialchars($avatar_src); ?>" alt="Avatar" class="rounded-circle border shadow-sm" style="width: 38px; height: 38px; object-fit: cover;">
            <span class="fw-semibold text-dark fs-8 d-none d-md-inline"><?php echo htmlspecialchars($user_name); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-2" aria-labelledby="userMenuDropdown">
            <li class="px-3 py-2 border-bottom mb-1">
              <div class="fw-bold text-dark fs-8"><?php echo htmlspecialchars($user_name); ?></div>
              <small class="text-muted text-capitalize fs-9"><?php echo htmlspecialchars($user_role); ?> Account</small>
            </li>
            <li><a class="dropdown-item fs-8" href="profile.php"><i class="bi bi-person me-2 text-primary"></i>Profile</a></li>
            <li><a class="dropdown-item fs-8" href="dashboard.php"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item fs-8 text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Log Out</a></li>
          </ul>
        </div>
      </div>

    </div>
  </header>

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
  <script>
    function markNotificationsAsRead() {
      fetch('api/read_notifications.php', { method: 'POST' })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const badge = document.getElementById('notification-badge');
            const count = document.getElementById('notification-count');
            if (badge) badge.remove();
            if (count) count.innerText = '0 new';
          }
        });
    }
  </script>
</body>
</html>
