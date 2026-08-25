<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/config/google_oauth.php';
init_lms_session();

// Redirect if already logged in for this specific tab
if (isset($_SESSION['user_id']) && isset($_GET['sid'])) {
    header("Location: dashboard.php?sid=" . urlencode($_SESSION['sid'] ?? $_GET['sid']));
    exit;
}

$error = '';
$success = '';

if (isset($_SESSION['auth_error'])) {
    $error = $_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}

if (isset($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Check if account email is verified
                if (isset($user['email_verified']) && $user['email_verified'] == 0) {
                    // Generate fresh OTP code and update expiry
                    $otp = sprintf('%06d', random_int(100000, 999999));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $upStmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                    $upStmt->execute([$otp, $expiresAt, $user['id']]);

                    send_otp_email($user['email'], $user['name'], $otp);
                    $_SESSION['pending_otp_email'] = $user['email'];
                    $_SESSION['pending_otp_user_id'] = $user['id'];

                    $verifyUrl = 'verify_otp.php?email=' . urlencode($user['email']);
                    $error = __('email_unverified_login_warning', 'Your account email is not verified yet. We have dispatched a 6-digit verification code to your email.') . ' <a href="' . $verifyUrl . '" class="fw-bold text-decoration-underline text-danger">' . __('click_here_to_verify', 'Click here to enter your OTP.') . '</a>';
                } else {
                    // Regenerate session ID for a fresh, tab-isolated session
                    session_regenerate_id(true);
                    $new_sid = session_id();

                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_avatar'] = get_user_avatar($user['avatar'], $user['name']);
                    $_SESSION['academic_id'] = $user['academic_id'];
                    $_SESSION['user_role'] = $user['role'] ?? 'student';
                    $_SESSION['sid'] = $new_sid;

                    if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin') {
                        // Populate LMS_ADMIN_SESS cookie as well for seamless admin portal access
                        $admin_uid = $_SESSION['user_id'];
                        $admin_name = $_SESSION['user_name'];
                        $admin_email = $_SESSION['user_email'];
                        $admin_avatar = $_SESSION['user_avatar'];
                        $admin_academic_id = $_SESSION['academic_id'];
                        $admin_role = $_SESSION['user_role'];

                        session_write_close();
                        session_name('LMS_ADMIN_SESS');
                        session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
                        session_start();
                        $_SESSION['user_id'] = $admin_uid;
                        $_SESSION['user_name'] = $admin_name;
                        $_SESSION['user_email'] = $admin_email;
                        $_SESSION['user_avatar'] = $admin_avatar;
                        $_SESSION['academic_id'] = $admin_academic_id;
                        $_SESSION['user_role'] = $admin_role;

                        header("Location: admin/index.php");
                    } else {
                        header("Location: dashboard.php?sid=" . urlencode($new_sid));
                    }
                    exit;
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Computerscience.lk</title>
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

    .auth-visual-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(160deg, rgba(15, 76, 129, 0.90) 0%, rgba(15, 23, 42, 0.92) 100%);
      backdrop-filter: blur(2px);
    }

    .auth-visual-card {
      background: rgba(255, 255, 255, 0.10);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid rgba(255, 255, 255, 0.20);
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

    .auth-input-group .password-toggle-btn {
      position: absolute;
      right: 14px;
      background: none;
      border: none;
      z-index: 4;
      cursor: pointer;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .auth-input {
      height: 48px;
      border-radius: 12px;
      border: 1.5px solid #e2e8f0;
      padding-left: 44px;
      padding-right: 44px;
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
    
    <!-- Left Side: Pure Visual Image (Clean Display, No Text Overlay) -->
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

    <!-- Right Side: Clean Authentication Form -->
    <div class="auth-form-col">
      <div class="auth-form-inner">
        
        <!-- Top Actions Bar: Mobile Home Button + Language Selector -->
        <div class="d-flex justify-content-between align-items-center mb-4">
          <a href="index.php" class="d-inline-flex d-lg-none align-items-center gap-1.5 text-secondary text-decoration-none fs-8 fw-semibold">
            <i class="bi bi-arrow-left"></i> Home
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
          <h1 class="fw-extrabold fs-3 text-dark mb-1">Welcome back</h1>
          <p class="text-secondary fs-7 mb-0"><?php echo __('login_subtitle', 'Access your syllabus modules, video lectures, and quizzes.'); ?></p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3" role="alert">
            <i class="bi bi-check-circle-fill me-1.5"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Continue with Google Button -->
        <a href="google_auth.php?role=student" class="btn btn-outline-secondary w-100 py-2.5 fw-semibold d-flex align-items-center justify-content-center gap-2 mb-3 bg-white border shadow-xs rounded-pill text-dark text-decoration-none" style="font-size: 0.9rem;">
          <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
            <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z" fill="#4285F4"/>
            <path d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
            <path d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z" fill="#FBBC05"/>
            <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z" fill="#EA4335"/>
          </svg>
          <span><?php echo __('continue_with_google', 'Continue with Google'); ?></span>
        </a>

        <!-- Divider -->
        <div class="d-flex align-items-center my-3.5">
          <div class="flex-grow-1 border-top border-secondary border-opacity-20"></div>
          <span class="px-3 text-muted fs-9 text-uppercase fw-bold" style="letter-spacing: 0.05em;"><?php echo __('or_continue_with_email', 'or continue with email'); ?></span>
          <div class="flex-grow-1 border-top border-secondary border-opacity-20"></div>
        </div>

        <!-- Login Form -->
        <form action="login.php" method="POST">
          <div class="mb-3">
            <label for="email" class="form-label fw-semibold text-secondary fs-8"><?php echo __('email_address', 'Email Address'); ?></label>
            <div class="auth-input-group">
              <span class="input-icon"><i class="bi bi-envelope text-muted"></i></span>
              <input type="email" name="email" id="email" class="auth-input" placeholder="e.g. name@computerscience.lk" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label for="password" class="form-label fw-semibold text-secondary fs-8 mb-0"><?php echo __('password', 'Password'); ?></label>
            </div>
            <div class="auth-input-group">
              <span class="input-icon"><i class="bi bi-lock text-muted"></i></span>
              <input type="password" name="password" id="password" class="auth-input" placeholder="••••••••" required>
              <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('password', this)" title="Show/Hide Password">
                <i class="bi bi-eye text-muted"></i>
              </button>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="remember">
              <label class="form-check-label text-secondary fs-8" for="remember"><?php echo __('remember_me', 'Remember Me'); ?></label>
            </div>
          </div>

          <button type="submit" class="btn auth-btn-submit w-100 py-2.5 text-white fw-bold rounded-pill shadow-sm transition-all mb-3">
            <span><?php echo __('sign_in_btn', 'Sign In to Dashboard'); ?></span>
            <i class="bi bi-arrow-right-circle-fill ms-1.5"></i>
          </button>
        </form>

        <!-- Registration Link -->
        <div class="text-center mt-3 pt-3 border-top border-secondary border-opacity-15">
          <span class="text-secondary fs-8"><?php echo __('dont_have_account', "Don't have an account?"); ?></span>
          <a href="register.php" class="fw-bold text-decoration-none ms-1 fs-8" style="color: #2b529a;"><?php echo __('register_here', 'Create Free Account'); ?> &rarr;</a>
        </div>

      </div>
    </div>
  </div>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script>
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      const icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
      }
    }

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
