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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = trim($_POST['role'] ?? 'student');
    if ($role !== 'teacher') {
        $role = 'student';
    }

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill out all fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id, email_verified FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                if ($existingUser['email_verified'] == 0) {
                    // Account exists but is unverified -> issue fresh OTP and redirect to verification
                    $otp = sprintf('%06d', random_int(100000, 999999));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $upStmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                    $upStmt->execute([$otp, $expiresAt, $existingUser['id']]);

                    send_otp_email($email, $name, $otp);
                    $_SESSION['pending_otp_email'] = $email;
                    $_SESSION['pending_otp_user_id'] = $existingUser['id'];
                    $_SESSION['otp_flash_success'] = 'An account with this email was previously registered but not verified. A new verification code has been dispatched to your email.';
                    header("Location: verify_otp.php?email=" . urlencode($email));
                    exit;
                } else {
                    $error = 'This email is already registered and verified. Please log in.';
                }
            } else {
                // Handle profile picture file upload
                $avatar = null;

                if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['avatar_file']['tmp_name'];
                    $fileName = $_FILES['avatar_file']['name'];
                    $fileNameCmps = explode(".", $fileName);
                    $fileExtension = strtolower(end($fileNameCmps));
                    
                    $allowedfileExtensions = ['jpg', 'gif', 'png', 'jpeg', 'webp'];
                    if (in_array($fileExtension, $allowedfileExtensions)) {
                        $upload_dir = __DIR__ . '/uploads/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                        $dest_path = $upload_dir . $newFileName;
                        
                        if (move_uploaded_file($fileTmpPath, $dest_path)) {
                            $avatar = 'uploads/' . $newFileName;
                        } else {
                            $error = 'Failed to move uploaded file. Check directory write permissions.';
                        }
                    } else {
                        $error = 'Upload failed. Allowed file types: ' . implode(', ', $allowedfileExtensions);
                    }
                }

                if (empty($avatar)) {
                    $avatar = get_user_avatar(null, $name);
                }

                if (empty($error)) {
                    // Generate random 6-digit OTP code and 10-minute expiry
                    $otp = sprintf('%06d', random_int(100000, 999999));
                    $otpExpiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $academic_id = ($role === 'teacher' ? 'TCHR-' : 'ACAD-') . rand(100000, 999999);
                    
                    $bio = ($role === 'teacher') ? trim($_POST['bio'] ?? '') : null;
                    $subject = ($role === 'teacher') ? trim($_POST['subject'] ?? '') : null;
                    $qualifications = ($role === 'teacher') ? trim($_POST['qualifications'] ?? '') : null;
                    $status = ($role === 'teacher') ? 'pending' : 'active';

                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, avatar, academic_id, role, status, bio, subject, qualifications, auth_provider, email_verified, otp_code, otp_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'local', 0, ?, ?)");
                    $stmt->execute([
                        $name,
                        $email,
                        $password_hash,
                        $avatar,
                        $academic_id,
                        $role,
                        $status,
                        $bio,
                        $subject,
                        $qualifications,
                        $otp,
                        $otpExpiresAt
                    ]);

                    $userId = $pdo->lastInsertId();

                    // Dispatch OTP email via PHPMailer
                    send_otp_email($email, $name, $otp);

                    // Set pending session and redirect to OTP Verification Page
                    $_SESSION['pending_otp_email'] = $email;
                    $_SESSION['pending_otp_user_id'] = $userId;
                    $_SESSION['otp_flash_success'] = 'Account registered successfully! A 6-digit verification code has been dispatched to ' . htmlspecialchars($email) . '.';
                    header("Location: verify_otp.php?email=" . urlencode($email));
                    exit;
                }
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
  <title>Register | Computerscience.lk</title>
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
      max-width: 480px;
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

    /* Custom Role Toggle Group */
    .role-toggle-group {
      display: flex;
      gap: 10px;
      background: #f1f5f9;
      padding: 5px;
      border-radius: 14px;
    }

    .role-toggle-group .role-btn {
      flex: 1;
      text-align: center;
      padding: 10px 14px;
      border-radius: 10px;
      font-size: 0.88rem;
      font-weight: 700;
      color: #64748b;
      cursor: pointer;
      transition: all 0.25s ease;
      border: none;
      background: transparent;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    .btn-check:checked + .role-btn {
      background: #ffffff;
      color: #2b529a;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
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
      <?php $register_img = get_register_page_image(); ?>
      <div class="auth-visual-bg" style="background-image: url('<?php echo htmlspecialchars($register_img); ?>');"></div>
      
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
          <h1 class="fw-extrabold fs-3 text-dark mb-1">Create Account</h1>
          <p class="text-secondary fs-7 mb-0"><?php echo __('register_subtitle', 'Join thousands of students and instructors on Computerscience.lk.'); ?></p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3" role="alert">
            <i class="bi bi-check-circle-fill me-1.5"></i>
            <?php echo htmlspecialchars($success); ?>
            <div class="mt-2">
              <a href="login.php" class="btn btn-sm btn-success text-white rounded-pill px-3"><?php echo __('sign_in_instead', 'Sign In instead'); ?></a>
            </div>
          </div>
        <?php endif; ?>

        <!-- Register Form -->
        <?php if (empty($success)): ?>
          <form action="register.php" method="POST" enctype="multipart/form-data" id="register-form">
            
            <!-- Role Selection Pill Toggle -->
            <div class="mb-3">
              <label class="form-label fw-semibold text-secondary fs-8 d-block mb-1.5"><?php echo __('account_type', 'I am registering as'); ?></label>
              <div class="role-toggle-group">
                <input type="radio" class="btn-check" name="role" id="role-student" value="student" checked autocomplete="off">
                <label class="role-btn" for="role-student">
                  <i class="bi bi-mortarboard-fill"></i>
                  <span><?php echo __('student', 'Student'); ?></span>
                </label>
                
                <input type="radio" class="btn-check" name="role" id="role-teacher" value="teacher" autocomplete="off">
                <label class="role-btn" for="role-teacher">
                  <i class="bi bi-person-workspace"></i>
                  <span><?php echo __('teacher', 'Teacher / Instructor'); ?></span>
                </label>
              </div>
            </div>

            <!-- Continue with Google Button -->
            <a href="google_auth.php?role=student" id="google-signup-btn" class="btn btn-outline-secondary w-100 py-2.5 fw-semibold d-flex align-items-center justify-content-center gap-2 mb-3 bg-white border shadow-xs rounded-pill text-dark text-decoration-none" style="font-size: 0.9rem;">
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

            <!-- Full Name -->
            <div class="mb-3">
              <label for="name" class="form-label fw-semibold text-secondary fs-8"><?php echo __('full_name', 'Full Name'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-person text-muted"></i></span>
                <input type="text" name="name" id="name" class="auth-input" placeholder="e.g. Devin Perera" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
              </div>
            </div>

            <!-- Teacher fields (dynamically shown) -->
            <div id="teacher-fields" style="display: none;">
              <div class="mb-3">
                <label for="bio" class="form-label fw-semibold text-secondary fs-8">Biography</label>
                <textarea name="bio" id="bio" class="form-control rounded-3 border bg-light fs-8 p-3" placeholder="Brief background & teaching experience..." rows="3"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
              </div>

              <div class="mb-3">
                <label for="subject" class="form-label fw-semibold text-secondary fs-8"><?php echo __('subject_specialization', 'Subject Specialization'); ?></label>
                <div class="auth-input-group">
                  <span class="input-icon"><i class="bi bi-book text-muted"></i></span>
                  <input type="text" name="subject" id="subject" class="auth-input" placeholder="e.g. Computer Science, Web Development" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                </div>
              </div>

              <div class="mb-3">
                <label for="qualifications" class="form-label fw-semibold text-secondary fs-8"><?php echo __('qualifications', 'Academic Qualifications'); ?></label>
                <div class="auth-input-group">
                  <span class="input-icon"><i class="bi bi-award text-muted"></i></span>
                  <input type="text" name="qualifications" id="qualifications" class="auth-input" placeholder="e.g. BSc in CS, MIT" value="<?php echo htmlspecialchars($_POST['qualifications'] ?? ''); ?>">
                </div>
              </div>
            </div>

            <!-- Email Address -->
            <div class="mb-3">
              <label for="email" class="form-label fw-semibold text-secondary fs-8"><?php echo __('email_address', 'Email Address'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-envelope text-muted"></i></span>
                <input type="email" name="email" id="email" class="auth-input" placeholder="e.g. devin@gmail.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
              </div>
            </div>

            <!-- Password -->
            <div class="mb-3">
              <label for="password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('password', 'Password'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" name="password" id="password" class="auth-input" placeholder="Min 6 characters" required>
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('password', this)" title="Show/Hide Password">
                  <i class="bi bi-eye text-muted"></i>
                </button>
              </div>
            </div>

            <!-- Confirm Password -->
            <div class="mb-3">
              <label for="confirm_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('confirm_password', 'Confirm New Password'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-shield-check text-muted"></i></span>
                <input type="password" name="confirm_password" id="confirm_password" class="auth-input" placeholder="Re-enter password" required>
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_password', this)" title="Show/Hide Password">
                  <i class="bi bi-eye text-muted"></i>
                </button>
              </div>
            </div>

            <!-- Profile Picture Upload -->
            <div class="mb-4">
              <label for="avatar_file" class="form-label fw-semibold text-secondary fs-8 d-block"><?php echo __('upload_profile_picture', 'Profile Picture (Optional)'); ?></label>
              <input type="file" name="avatar_file" id="avatar_file" class="form-control rounded-3 bg-light fs-8" accept="image/*">
            </div>

            <button type="submit" class="btn auth-btn-submit w-100 py-2.5 text-white fw-bold rounded-pill shadow-sm transition-all mb-3">
              <span><?php echo __('create_account_btn', 'Create Free Account'); ?></span>
              <i class="bi bi-arrow-right-circle-fill ms-1.5"></i>
            </button>
          </form>
        <?php endif; ?>

        <!-- Login Link -->
        <div class="text-center mt-3 pt-3 border-top border-secondary border-opacity-15">
          <span class="text-secondary fs-8"><?php echo __('already_have_account', 'Already have an account?'); ?></span>
          <a href="login.php" class="fw-bold text-decoration-none ms-1 fs-8" style="color: #2b529a;"><?php echo __('sign_in_instead', 'Sign In'); ?> &rarr;</a>
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
  
  <!-- Dynamic Role Fields and Password Validation -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const roleStudent = document.getElementById('role-student');
      const roleTeacher = document.getElementById('role-teacher');
      const teacherFields = document.getElementById('teacher-fields');
      const bioInput = document.getElementById('bio');
      const subjectInput = document.getElementById('subject');
      const qualificationsInput = document.getElementById('qualifications');
      const password = document.getElementById('password');
      const confirmPassword = document.getElementById('confirm_password');
      const form = document.getElementById('register-form');

      function toggleRoleFields() {
        const googleBtn = document.getElementById('google-signup-btn');
        if (roleTeacher.checked) {
          teacherFields.style.display = 'block';
          bioInput.required = true;
          subjectInput.required = true;
          qualificationsInput.required = true;
          if (googleBtn) googleBtn.href = 'google_auth.php?role=teacher';
        } else {
          teacherFields.style.display = 'none';
          bioInput.required = false;
          subjectInput.required = false;
          qualificationsInput.required = false;
          if (googleBtn) googleBtn.href = 'google_auth.php?role=student';
          
          // Clear teacher-only inputs so they don't submit stale data if role is switched back
          bioInput.value = '';
          subjectInput.value = '';
          qualificationsInput.value = '';
        }
      }

      roleStudent.addEventListener('change', toggleRoleFields);
      roleTeacher.addEventListener('change', toggleRoleFields);

      // Trigger initial state
      toggleRoleFields();

      // Client-side password match validation
      if (form) {
        form.addEventListener('submit', function(e) {
          if (password.value !== confirmPassword.value) {
            e.preventDefault();
            alert('Passwords do not match.');
            confirmPassword.focus();
          }
        });
      }
    });
  </script>
</body>
</html>
