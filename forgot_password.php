<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_GET['sid'])) {
    header("Location: dashboard.php?sid=" . urlencode($_SESSION['sid'] ?? $_GET['sid']));
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
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        // 2. Throttling / Rate Limiting Check (Max 5 attempts per 15 minutes)
        $now = time();
        $_SESSION['forgot_pw_attempts'] = array_filter(
            $_SESSION['forgot_pw_attempts'] ?? [],
            function ($timestamp) use ($now) {
                return ($now - $timestamp) < 900; // 15 mins window
            }
        );

        if (count($_SESSION['forgot_pw_attempts']) >= 5) {
            $error = __('rate_limit_exceeded', 'Too many reset attempts. Please wait a few minutes before trying again.');
        } else {
            $_SESSION['forgot_pw_attempts'][] = $now;

            $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = __('invalid_email_error', 'Please provide a valid email address.');
            } else {
                try {
                    $pdo = getDBConnection();

                    // Check if email exists in users table
                    $stmt = $pdo->prepare("SELECT id, name, email, status FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();

                    // Only send email if active user exists (anti-enumeration security)
                    if ($user && ($user['status'] ?? 'active') !== 'inactive') {
                        // Generate secure 64-char token (32 random bytes)
                        $token = bin2hex(random_bytes(32));
                        // Delete any prior unused reset tokens for this email
                        $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                        $delStmt->execute([$user['email']]);

                        // Store new token (30 minutes expiration)
                        $insStmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
                        $insStmt->execute([$user['email'], $token]);

                        // Build dynamic absolute reset URL
                        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
                        $protocol = $isHttps ? "https://" : "http://";
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                        $scriptDir = rtrim($scriptDir, '/');
                        
                        $resetUrl = $protocol . $host . ($scriptDir ? $scriptDir : '') . '/reset_password.php?token=' . urlencode($token) . '&email=' . urlencode($user['email']);

                        // Dispatch HTML reset email via database-configured PHPMailer SMTP
                        send_password_reset_email($user['email'], $user['name'] ?? 'Student', $resetUrl, 30);
                    }

                    // Return generic friendly success response
                    $success = __('reset_link_sent_msg', 'If this email is registered in our system, a password reset link has been dispatched to your inbox. Please check your email (and spam folder).');
                    $isSent = true;

                    // Refresh CSRF token after successful submission
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                } catch (Exception $e) {
                    error_log("Forgot password error: " . $e->getMessage());
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
  <title><?php echo __('forgot_password_title', 'Forgot Password'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
  
  <style>
    body, html {
      height: 100%;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background-color: #ffffff;
    }

    .auth-split-wrapper {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }

    /* Left Visual Column */
    .auth-visual-col {
      flex: 1 1 50%;
      max-width: 50%;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 48px;
      background-color: #0b1329;
    }

    .auth-visual-bg {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center;
      transform: scale(1.03);
      transition: transform 10s ease;
    }

    .auth-visual-col:hover .auth-visual-bg {
      transform: scale(1.08);
    }

    /* Right Form Column */
    .auth-form-col {
      flex: 1 1 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: #ffffff;
      padding: 40px 24px;
      overflow-y: auto;
    }

    .auth-form-inner {
      width: 100%;
      max-width: 440px;
    }

    .auth-input-group {
      position: relative;
      display: flex;
      align-items: center;
    }

    .auth-input-group .input-icon {
      position: absolute;
      left: 15px;
      z-index: 4;
      font-size: 1rem;
      pointer-events: none;
    }

    .auth-input {
      height: 48px;
      border-radius: 12px;
      border: 1.5px solid #e2e8f0;
      padding-left: 44px;
      padding-right: 16px;
      background: #f8fafc;
      font-size: 0.92rem;
      color: #1e293b;
      transition: all 0.2s ease;
      width: 100%;
    }

    .auth-input:focus {
      background: #ffffff;
      border-color: #2b529a;
      box-shadow: 0 0 0 4px rgba(43, 82, 154, 0.12);
      outline: none;
    }

    .auth-btn-submit {
      background: linear-gradient(135deg, #2b529a 0%, #1e3a6d 100%);
      border: none;
      font-size: 0.96rem;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .auth-btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 24px rgba(43, 82, 154, 0.35);
      color: #ffffff;
    }

    .icon-badge-wrap {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: rgba(43, 82, 154, 0.08);
      color: #2b529a;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.6rem;
      margin-bottom: 1.25rem;
    }

    @media (max-width: 991.98px) {
      .auth-visual-col {
        display: none !important;
      }
      .auth-form-col {
        flex: 1 1 100%;
        padding: 30px 20px;
      }
    }
  </style>
</head>
<body>
 
  <div class="auth-split-wrapper">
    
    <!-- Left Side: Visual Image -->
    <div class="auth-visual-col d-none d-lg-flex">
      <?php $login_img = get_login_page_image(); ?>
      <div class="auth-visual-bg" style="background-image: url('<?php echo htmlspecialchars($login_img); ?>');"></div>
      
      <!-- Top Home Link -->
      <div class="auth-visual-header position-relative z-2">
        <a href="index.php" class="d-inline-flex align-items-center gap-2 text-white text-decoration-none bg-dark bg-opacity-50 px-3.5 py-1.5 rounded-pill border border-white border-opacity-25 fs-8 fw-semibold shadow-sm">
          <i class="bi bi-arrow-left"></i>
          <span><?php echo __('back_to_home', 'Back to Home'); ?></span>
        </a>
      </div>
    </div>

    <!-- Right Side: Reset Form -->
    <div class="auth-form-col">
      <div class="auth-form-inner">
        
        <!-- Top Actions Bar: Mobile Home Button + Language Selector -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <a href="login.php" class="d-inline-flex align-items-center gap-1.5 text-secondary text-decoration-none fs-8 fw-semibold">
            <i class="bi bi-arrow-left"></i> <?php echo __('back_to_login', 'Back to Login'); ?>
          </a>
          <div class="ms-auto dropdown">
            <button class="btn btn-sm btn-light border text-secondary dropdown-toggle d-flex align-items-center gap-1.5 rounded-pill px-3 py-1.5 shadow-xs" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
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
        </div>

        <!-- Brand Logo & Header -->
        <div class="mb-4">
          <a class="d-inline-flex align-items-center text-decoration-none mb-3" href="index.php">
            <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2" style="height: 38px; width: auto; object-fit: contain;">
            <span class="fw-bold fs-4" style="color: #2b529a; letter-spacing: -0.02em;">computerscience.lk</span>
          </a>
          
          <div class="icon-badge-wrap">
            <i class="bi bi-key-fill"></i>
          </div>

          <h1 class="fw-extrabold fs-3 text-dark mb-1"><?php echo __('forgot_password_title', 'Forgot Password'); ?></h1>
          <p class="text-secondary fs-7 mb-0"><?php echo __('forgot_password_subtitle', 'Enter your registered email address to receive a secure password reset link.'); ?></p>
        </div>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Success View or Form -->
        <?php if ($isSent): ?>
          <div class="card border-0 bg-success bg-opacity-10 rounded-4 p-4 text-center mb-4 border border-success border-opacity-25">
            <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mx-auto mb-3 shadow-sm" style="width: 48px; height: 48px;">
              <i class="bi bi-envelope-check-fill fs-4"></i>
            </div>
            <h5 class="fw-bold text-success mb-2"><?php echo __('reset_link_sent_heading', 'Reset Link Dispatched'); ?></h5>
            <p class="text-secondary fs-8 mb-3 leading-relaxed">
              <?php echo htmlspecialchars($success); ?>
            </p>
            <div class="d-flex flex-column gap-2">
              <a href="login.php" class="btn btn-sm btn-primary rounded-pill py-2 fw-semibold shadow-xs">
                <i class="bi bi-box-arrow-in-right me-1.5"></i> <?php echo __('back_to_login', 'Back to Login'); ?>
              </a>
              <a href="forgot_password.php" class="btn btn-sm btn-outline-secondary rounded-pill py-2 fs-8 fw-semibold">
                <?php echo __('send_reset_link_btn', 'Try Another Email'); ?>
              </a>
            </div>
          </div>
        <?php else: ?>
          <!-- Reset Request Form -->
          <form action="forgot_password.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

            <div class="mb-4">
              <label for="email" class="form-label fw-semibold text-secondary fs-8"><?php echo __('email_address', 'Email Address'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-envelope text-muted"></i></span>
                <input type="email" name="email" id="email" class="auth-input" placeholder="e.g. name@computerscience.lk" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
              </div>
              <div class="form-text fs-9 text-muted mt-1.5">
                <i class="bi bi-shield-check me-1 text-primary"></i>
                <?php echo __('check_email_instructions', 'We have sent password reset instructions to your email address. The link will remain active for 30 minutes.'); ?>
              </div>
            </div>

            <button type="submit" class="btn auth-btn-submit w-100 py-2.5 text-white fw-bold rounded-pill shadow-sm transition-all mb-3">
              <span><?php echo __('send_reset_link_btn', 'Send Password Reset Link'); ?></span>
              <i class="bi bi-arrow-right-circle-fill ms-1.5"></i>
            </button>
          </form>
        <?php endif; ?>

        <!-- Footer Links -->
        <div class="text-center mt-3 pt-3 border-top border-secondary border-opacity-15">
          <a href="login.php" class="fw-bold text-decoration-none fs-8" style="color: #2b529a;">
            <i class="bi bi-arrow-left me-1"></i> <?php echo __('back_to_login', 'Back to Login'); ?>
          </a>
        </div>

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
