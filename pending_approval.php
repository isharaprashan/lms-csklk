<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// If not logged in, redirect to login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    $u_status = strtolower($user['status'] ?? 'pending');

    // If not a teacher or already active, redirect back to dashboard
    if (!$user || $user['role'] !== 'teacher' || $u_status === 'active') {
        header("Location: dashboard.php");
        exit;
    }
} catch (PDOException $e) {
    // Graceful error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo ($u_status === 'inactive') ? 'Account Inactive' : 'Pending Approval'; ?> | Computerscience.lk</title>
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
    .pending-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f4c81 0%, #1d4ed8 100%);
      position: relative;
      overflow: hidden;
      padding: 20px;
    }
    
    .pending-container::before {
      content: '';
      position: absolute;
      width: 500px;
      height: 500px;
      background: rgba(242, 111, 33, 0.15);
      border-radius: 50%;
      top: -200px;
      right: -100px;
      z-index: 1;
    }

    .pending-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: 20px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
      z-index: 2;
      width: 100%;
      max-width: 550px;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }
  </style>
</head>
<body class="bg-light">

  <div class="pending-container">
    <div class="pending-card p-5 text-center">

      <!-- Brand Logo -->
      <div class="mb-4">
        <div class="d-inline-flex align-items-center justify-content-center mb-3">
          <i class="bi bi-mortarboard-fill text-warning fs-1"></i>
          <span class="fw-bold fs-3 ms-2" style="color: #0f4c81;">computerscience.lk</span>
        </div>
        <h4 class="fw-bold text-dark mt-2">
          <?php echo ($u_status === 'inactive') ? 'Account Currently Inactive' : __('pending_teacher_title', 'Account Pending Approval'); ?>
        </h4>
      </div>

      <?php if ($u_status === 'inactive'): ?>
        <div class="p-4 bg-danger bg-opacity-10 rounded-3 mb-4 text-start border border-danger border-opacity-25">
          <div class="d-flex gap-3">
            <div class="fs-1 text-danger"><i class="bi bi-exclamation-octagon-fill"></i></div>
            <div>
              <h6 class="fw-bold text-dark mb-1">Account Status: Inactive</h6>
              <p class="text-secondary fs-7 mb-0">Your educator account access is currently set to INACTIVE by system administrators. Creating new courses, uploading lessons, and modifying course materials are disabled until your account is reactivated.</p>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="p-4 bg-light rounded-3 mb-4 text-start border border-warning border-opacity-25">
          <div class="d-flex gap-3">
            <div class="fs-1 text-warning"><i class="bi bi-shield-fill-exclamation"></i></div>
            <div>
              <h6 class="fw-bold text-dark mb-1"><?php echo __('status_label', 'Account Status'); ?>: <?php echo __('pending', 'Pending'); ?></h6>
              <p class="text-muted fs-7 mb-0"><?php echo __('pending_teacher_desc', 'Your Teacher account registration has been successfully received and is currently undergoing administrator verification.'); ?></p>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex flex-column gap-2">
        <a href="login.php" class="btn btn-primary py-2.5 text-white fw-bold rounded-pill shadow-sm" style="background-color: #0f4c81; border: none;">
          <i class="bi bi-arrow-clockwise me-1"></i> <?php echo __('back_to_login', 'Back to Login Page'); ?>
        </a>
        <a href="logout.php" class="btn btn-outline-secondary py-2.5 fw-bold rounded-pill">
          <i class="bi bi-box-arrow-right me-1"></i> <?php echo __('nav_logout', 'Log Out'); ?>
        </a>
      </div>

    </div>
  </div>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
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

</body>
</html>
