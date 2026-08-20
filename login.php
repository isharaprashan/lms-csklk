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
    .login-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f4c81 0%, #1d4ed8 100%);
      position: relative;
      overflow: hidden;
    }
    
    .login-container::before {
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
 
    .login-container::after {
      content: '';
      position: absolute;
      width: 400px;
      height: 400px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 50%;
      bottom: -150px;
      left: -100px;
      z-index: 1;
    }
 
    .login-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 16px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
      z-index: 2;
      width: 100%;
      max-width: 450px;
    }
    
    .form-control:focus {
      border-color: #0f4c81;
      box-shadow: 0 0 0 0.25rem rgba(15, 76, 129, 0.25);
    }
  </style>
</head>
<body class="bg-light">
 
  <div class="login-container px-3">
    <div class="login-card p-4 p-md-5">
      
      <!-- Top Language Dropdown Toggle -->
      <div class="d-flex justify-content-end mb-3">
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
      </div>

      <!-- Brand Logo -->
      <div class="text-center mb-4">
        <a class="moodle-brand fw-bold text-decoration-none fs-3 d-inline-flex align-items-center justify-content-center" href="index.php" style="color: #0f4c81;">
          <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2" style="height: 38px; width: auto; object-fit: contain;">computerscience.lk
        </a>
        <p class="text-muted mt-2 fs-7"><?php echo __('login_subtitle', 'Access your syllabus modules, video lectures, and quizzes.'); ?></p>
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
      <a href="google_auth.php?role=student" class="btn btn-outline-dark w-100 py-2.5 fw-semibold d-flex align-items-center justify-content-center gap-2 mb-3 bg-white border shadow-xs rounded-3 hover:shadow-sm transition-all text-dark text-decoration-none" style="font-size: 0.88rem;">
        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
          <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z" fill="#4285F4"/>
          <path d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
          <path d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707s.102-1.167.282-1.707V4.961H.957C.347 6.175 0 7.55 0 9s.347 2.825.957 4.039l3.007-2.332z" fill="#FBBC05"/>
          <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z" fill="#EA4335"/>
        </svg>
        <span><?php echo __('continue_with_google', 'Continue with Google'); ?></span>
      </a>

      <!-- Divider -->
      <div class="d-flex align-items-center my-3">
        <div class="flex-grow-1 border-top border-secondary border-opacity-20"></div>
        <span class="px-2.5 text-muted fs-9 text-uppercase fw-semibold" style="letter-spacing: 0.05em;"><?php echo __('or_continue_with_email', 'or continue with email'); ?></span>
        <div class="flex-grow-1 border-top border-secondary border-opacity-20"></div>
      </div>

      <!-- Login Form -->
      <form action="login.php" method="POST">
        <div class="mb-3">
          <label for="email" class="form-label fw-semibold text-secondary"><?php echo __('email_address', 'Email Address'); ?></label>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
            <input type="email" name="email" id="email" class="form-control border-start-0 bg-light" placeholder="e.g. sanduni@computerscience.lk" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
        </div>

        <div class="mb-4">
          <div class="d-flex justify-content-between mb-1">
            <label for="password" class="form-label fw-semibold text-secondary mb-0"><?php echo __('password', 'Password'); ?></label>
          </div>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
            <input type="password" name="password" id="password" class="form-control border-start-0 bg-light" placeholder="••••••••" required>
          </div>
        </div>

        <div class="mb-3 form-check d-flex justify-content-between align-items-center">
          <div>
            <input type="checkbox" class="form-check-input" id="remember">
            <label class="form-check-label text-muted fs-8" for="remember"><?php echo __('remember_me', 'Remember Me'); ?></label>
          </div>
        </div>

        <button type="submit" class="btn w-100 py-2.5 text-white fw-bold border-0 shadow-sm transition-all hover:brightness-110 mb-3" style="background-color: #0f4c81;">
          <?php echo __('sign_in_btn', 'Sign In to Dashboard'); ?>
        </button>
      </form>

      <!-- Registration Link -->
      <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
        <span class="text-muted fs-8"><?php echo __('dont_have_account', "Don't have an account?"); ?></span>
        <a href="register.php" class="fw-bold text-decoration-none ms-1 fs-8" style="color: #f26f21;"><?php echo __('register_here', 'Register here'); ?></a>
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
