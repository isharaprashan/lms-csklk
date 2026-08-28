<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

$isInsideAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'admin');
$rootPrefix = $isInsideAdmin ? '../' : '';
$adminPrefix = $isInsideAdmin ? '' : 'admin/';

// Redirect if already logged in as admin or super_admin
if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: " . $adminPrefix . "index.php");
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$isSent = false;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        // 2. Strict Rate Limiting Check for Admin Portal (Max 3 attempts per 15 minutes)
        $now = time();
        $_SESSION['admin_forgot_pw_attempts'] = array_filter(
            $_SESSION['admin_forgot_pw_attempts'] ?? [],
            function ($timestamp) use ($now) {
                return ($now - $timestamp) < 900; // 15 mins window
            }
        );

        if (count($_SESSION['admin_forgot_pw_attempts']) >= 3) {
            $error = __('rate_limit_exceeded', 'Too many reset attempts. Please wait a few minutes before trying again.');
        } else {
            $_SESSION['admin_forgot_pw_attempts'][] = $now;

            $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = __('invalid_email_error', 'Please provide a valid email address.');
            } else {
                try {
                    $pdo = getDBConnection();

                    // Query strictly for accounts with role 'admin' or 'super_admin'
                    $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE email = ? AND role IN ('admin', 'super_admin') LIMIT 1");
                    $stmt->execute([$email]);
                    $admin = $stmt->fetch();

                    // Only generate token and dispatch email if an active admin user exists
                    if ($admin && strtolower($admin['status'] ?? 'active') === 'active') {
                        // Generate cryptographically secure 64-character token
                        $token = bin2hex(random_bytes(32));

                        // Invalidate/delete any prior unused reset tokens for this email
                        $delStmt = $pdo->prepare("DELETE FROM admin_password_resets WHERE email = ?");
                        $delStmt->execute([$admin['email']]);

                        // Store new reset token with role (20 minutes expiration)
                        $insStmt = $pdo->prepare("INSERT INTO admin_password_resets (email, token, role, expires_at, is_used) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE), 0)");
                        $insStmt->execute([$admin['email'], $token, $admin['role']]);

                        // Build dynamic universal reset URL
                        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                        $protocol = $isHttps ? "https://" : "http://";
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                        if ($isInsideAdmin) {
                            $scriptDir = dirname($scriptDir);
                        }
                        $scriptDir = rtrim($scriptDir, '/');
                        
                        $resetUrl = $protocol . $host . ($scriptDir ? $scriptDir : '') . '/admin_reset_password.php?token=' . urlencode($token) . '&email=' . urlencode($admin['email']);

                        // Dispatch Branded Admin Security Email via PHPMailer SMTP
                        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
                        send_admin_password_reset_email($admin['email'], $admin['name'] ?? 'Administrator', $resetUrl, $admin['role'], 20, $clientIp);
                    }

                    // Always return generic neutral success message (Anti-enumeration security)
                    $success = __('admin_reset_link_sent_msg', 'If an authorized administrator account is associated with this email, a secure password reset link has been dispatched. Please inspect your inbox and spam filters.');
                    $isSent = true;

                    // Refresh CSRF token
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                } catch (Exception $e) {
                    error_log("Admin forgot password error: " . $e->getMessage());
                    $error = 'An unexpected error occurred. Please try again later.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('admin_forgot_password_title', 'Admin Password Recovery'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo $rootPrefix; ?><?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo $rootPrefix; ?><?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="<?php echo $rootPrefix; ?>assets/js/session_manager.js"></script>
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="<?php echo $rootPrefix; ?>assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="<?php echo $rootPrefix; ?>assets/css/bootstrap-icons.min.css">
  
  <style>
    body, html {
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: radial-gradient(circle at 10% 20%, rgb(5, 32, 20) 0%, rgb(11, 69, 40) 50%, rgb(8, 24, 16) 100%);
      color: #1e293b;
    }

    .admin-auth-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      position: relative;
      overflow: hidden;
    }

    .admin-auth-container::before {
      content: '';
      position: absolute;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, transparent 70%);
      top: -200px;
      right: -100px;
      z-index: 1;
    }

    .admin-auth-container::after {
      content: '';
      position: absolute;
      width: 450px;
      height: 450px;
      background: radial-gradient(circle, rgba(217, 119, 6, 0.12) 0%, transparent 75%);
      bottom: -150px;
      left: -150px;
      z-index: 1;
    }

    .admin-auth-card {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 24px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
      width: 100%;
      max-width: 480px;
      position: relative;
      z-index: 2;
      padding: 40px 36px;
    }

    .shield-badge-icon {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      background: linear-gradient(135deg, #052014 0%, #0b4528 100%);
      color: #ffffff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      box-shadow: 0 10px 25px rgba(11, 69, 40, 0.3);
      margin-bottom: 20px;
    }

    .auth-input-group {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-icon {
      position: absolute;
      left: 14px;
      color: #64748b;
      font-size: 1.1rem;
      pointer-events: none;
      z-index: 4;
    }

    .auth-input {
      height: 48px;
      border-radius: 12px;
      border: 1.5px solid #e2e8f0;
      padding-left: 44px;
      padding-right: 16px;
      background: #f8fafc;
      font-size: 0.92rem;
      color: #0f172a;
      transition: all 0.2s ease;
      width: 100%;
    }

    .auth-input:focus {
      background: #ffffff;
      border-color: #0b4528;
      box-shadow: 0 0 0 4px rgba(11, 69, 40, 0.15);
      outline: none;
    }

    .btn-admin-submit {
      background: linear-gradient(135deg, #052014 0%, #0b4528 100%);
      color: #ffffff;
      border: none;
      font-weight: 700;
      font-size: 0.95rem;
      border-radius: 50px;
      padding: 12px 24px;
      transition: all 0.25s ease;
      box-shadow: 0 4px 15px rgba(11, 69, 40, 0.25);
    }

    .btn-admin-submit:hover {
      background: linear-gradient(135deg, #0b4528 0%, #125b36 100%);
      color: #ffffff;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(11, 69, 40, 0.35);
    }

    .security-notice-box {
      background-color: #f8fafc;
      border-left: 3px solid #0b4528;
      border-radius: 6px;
      padding: 12px 14px;
      font-size: 0.78rem;
      color: #475569;
    }
  </style>
</head>
<body>

  <div class="admin-auth-container">
    <div class="admin-auth-card">
      
      <!-- Top Crest & Header -->
      <div class="text-center">
        <div class="shield-badge-icon">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div class="d-inline-flex align-items-center gap-1.5 px-3 py-1 rounded-pill bg-dark bg-opacity-10 text-dark border fs-9 fw-bold text-uppercase mb-2" style="letter-spacing: 0.5px;">
          <i class="bi bi-key-fill text-success"></i> <?php echo __('admin_security_portal', 'Enterprise Admin Security'); ?>
        </div>
        <h4 class="fw-bold text-dark mb-1 fs-5"><?php echo __('admin_forgot_password_title', 'Admin Password Recovery'); ?></h4>
        <p class="text-secondary fs-8 mb-4">
          <?php echo __('admin_forgot_password_subtitle', 'Administrative credential recovery for authorized System and Super Administrators.'); ?>
        </p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-danger shadow-xs py-2.5 px-3 fs-8 rounded-3 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if ($isSent): ?>
        <div class="alert alert-success border-success shadow-xs py-3 px-3 rounded-3 mb-4" role="alert">
          <div class="d-flex align-items-start gap-2.5">
            <i class="bi bi-check-circle-fill text-success fs-5 mt-0.5"></i>
            <div>
              <h6 class="fw-bold text-dark mb-1 fs-7"><?php echo __('reset_link_sent_heading', 'Reset Link Dispatched'); ?></h6>
              <p class="fs-8 text-secondary mb-0 leading-normal">
                <?php echo htmlspecialchars($success); ?>
              </p>
            </div>
          </div>
        </div>

        <div class="p-3 bg-light rounded-3 border mb-4 fs-8 text-secondary">
          <div class="fw-semibold text-dark mb-1"><i class="bi bi-shield-check text-success me-1"></i> Security Protocol:</div>
          <ul class="mb-0 ps-3 fs-9" style="line-height: 1.5;">
            <li>Reset links remain active for exactly <strong>20 minutes</strong>.</li>
            <li>Tokens are single-use and automatically invalidated upon password change.</li>
          </ul>
        </div>

        <div class="text-center">
          <a href="<?php echo $adminPrefix; ?>login.php" class="btn btn-admin-submit w-100 py-2.5 d-inline-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-arrow-left"></i>
            <span><?php echo __('back_to_admin_login', 'Back to Admin Login'); ?></span>
          </a>
        </div>
      <?php else: ?>
        <!-- Request Reset Form -->
        <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? ($rootPrefix . 'admin_forgot_password.php')); ?>" method="POST" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

          <div class="mb-3.5">
            <label for="admin_email" class="form-label fw-semibold text-dark fs-8 mb-1.5">
              <?php echo __('admin_email_label', 'Registered Admin Email'); ?> <span class="text-danger">*</span>
            </label>
            <div class="auth-input-group">
              <span class="input-icon"><i class="bi bi-envelope-at-fill"></i></span>
              <input type="email" name="email" id="admin_email" class="auth-input" 
                     placeholder="admin@computerscience.lk" required autofocus
                     value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
          </div>

          <div class="security-notice-box mb-4">
            <i class="bi bi-info-circle-fill text-success me-1"></i>
            <?php echo __('admin_access_restricted_notice', 'Restricted Area: Access is strictly monitored and limited to verified administrative personnel.'); ?>
          </div>

          <button type="submit" class="btn btn-admin-submit w-100 py-2.5 d-inline-flex align-items-center justify-content-center gap-2 mb-3">
            <span><?php echo __('send_admin_reset_link_btn', 'Send Secure Admin Reset Link'); ?></span>
            <i class="bi bi-arrow-right"></i>
          </button>
        </form>

        <!-- Navigation Links -->
        <div class="text-center mt-3 pt-3 border-top">
          <a href="<?php echo $adminPrefix; ?>login.php" class="text-decoration-none fs-8 fw-semibold text-dark hover:text-success d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-arrow-left"></i>
            <span><?php echo __('back_to_admin_login', 'Back to Admin Login'); ?></span>
          </a>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="<?php echo $rootPrefix; ?>assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
