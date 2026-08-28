<?php
require_once __DIR__ . '/db/db_connect.php';
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

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = trim(filter_var($_GET['email'] ?? $_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

$error = '';
$isTokenValid = false;
$resetRecord = null;
$pdo = null;

// Validate token and email
if (!empty($token) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? LIMIT 1");
        $stmt->execute([$email, $token]);
        $resetRecord = $stmt->fetch();

        if ($resetRecord) {
            $expiresAtTimestamp = strtotime($resetRecord['expires_at']);
            if ($expiresAtTimestamp >= time()) {
                $isTokenValid = true;
            } else {
                // Delete expired token
                $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE id = ?");
                $delStmt->execute([$resetRecord['id']]);
            }
        }
    } catch (Exception $e) {
        error_log("Reset password token validation error: " . $e->getMessage());
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isTokenValid) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Security session expired. Please try submitting again.';
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword) || empty($confirmPassword)) {
            $error = 'Please enter and confirm your new password.';
        } elseif (strlen($newPassword) < 8) {
            $error = __('password_too_short', 'Password must be at least 8 characters long.');
        } elseif ($newPassword !== $confirmPassword) {
            $error = __('password_mismatch', 'New passwords do not match. Please re-enter carefully.');
        } else {
            try {
                if (!$pdo) {
                    $pdo = getDBConnection();
                }

                // 1. Hash the new password securely
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

                // 2. Update user's password
                $upStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                $upStmt->execute([$newHash, $email]);

                // 3. Remove all reset tokens for this email
                $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                $delStmt->execute([$email]);

                // 4. Set session flash notification and redirect to login
                $_SESSION['login_flash_success'] = __('password_reset_success_msg', 'Password reset successfully! Please sign in with your new password.');
                
                header("Location: login.php?reset=success");
                exit;

            } catch (Exception $e) {
                error_log("Password update error: " . $e->getMessage());
                $error = 'Database error: Could not update password. Please try again.';
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
  <title><?php echo __('reset_password_title', 'Reset Your Password'); ?> | Computerscience.lk</title>
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

    /* Password Strength Meter */
    .strength-meter-bar {
      height: 5px;
      border-radius: 4px;
      background: #e2e8f0;
      overflow: hidden;
      display: flex;
      gap: 3px;
      margin-top: 8px;
    }

    .strength-segment {
      flex: 1;
      height: 100%;
      background: transparent;
      border-radius: 2px;
      transition: background-color 0.3s ease;
    }

    .strength-segment.active-weak { background-color: #ef4444; }
    .strength-segment.active-medium { background-color: #f59e0b; }
    .strength-segment.active-strong { background-color: #10b981; }

    .req-item {
      font-size: 0.78rem;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 5px;
      margin-top: 4px;
      transition: color 0.2s ease;
    }

    .req-item.met {
      color: #10b981;
      font-weight: 600;
    }

    .req-item.met i::before {
      content: "\F272"; /* bi-check-circle-fill */
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

    <!-- Right Side: Reset Password Form / Invalid State -->
    <div class="auth-form-col">
      <div class="auth-form-inner">
        
        <!-- Top Actions Bar: Language Selector -->
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
            <i class="bi bi-shield-lock-fill"></i>
          </div>

          <h1 class="fw-extrabold fs-3 text-dark mb-1"><?php echo __('reset_password_title', 'Reset Your Password'); ?></h1>
          <p class="text-secondary fs-7 mb-0"><?php echo __('reset_password_subtitle', 'Choose a strong, secure new password for your account.'); ?></p>
        </div>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!$isTokenValid): ?>
          <!-- Invalid or Expired Token State -->
          <div class="card border-0 bg-danger bg-opacity-10 rounded-4 p-4 text-center mb-4 border border-danger border-opacity-25">
            <div class="d-inline-flex align-items-center justify-content-center bg-danger text-white rounded-circle mx-auto mb-3 shadow-sm" style="width: 48px; height: 48px;">
              <i class="bi bi-exclamation-octagon-fill fs-4"></i>
            </div>
            <h5 class="fw-bold text-danger mb-2"><?php echo __('invalid_or_expired_token', 'This password reset link is invalid or has expired.'); ?></h5>
            <p class="text-secondary fs-8 mb-4 leading-relaxed">
              <?php echo __('invalid_or_expired_token_desc', 'The reset link you followed is no longer valid or has expired after 30 minutes. Please request a fresh reset link below.'); ?>
            </p>
            <a href="forgot_password.php" class="btn btn-primary rounded-pill py-2.5 fw-semibold shadow-xs">
              <i class="bi bi-arrow-repeat me-1.5"></i> <?php echo __('request_new_link', 'Request New Reset Link'); ?>
            </a>
          </div>
        <?php else: ?>
          <!-- Valid Token Form -->
          <div class="alert alert-light border border-secondary border-opacity-25 py-2 px-3 rounded-3 mb-3 fs-8 d-flex align-items-center gap-2">
            <i class="bi bi-person-check-fill text-primary"></i>
            <span class="text-secondary">Resetting password for: <strong class="text-dark"><?php echo htmlspecialchars($email); ?></strong></span>
          </div>

          <form action="reset_password.php" method="POST" id="resetPasswordForm" onsubmit="return validateResetForm()">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

            <!-- New Password -->
            <div class="mb-3">
              <label for="new_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('new_password_label', 'New Password'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" name="new_password" id="new_password" class="auth-input" placeholder="••••••••" required oninput="evaluatePasswordStrength(this.value); checkPasswordMatch();" autofocus>
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('new_password', this)" title="Show/Hide Password">
                  <i class="bi bi-eye text-muted"></i>
                </button>
              </div>

              <!-- Live Strength Meter -->
              <div class="strength-meter-bar" id="strengthMeter">
                <div class="strength-segment" id="seg1"></div>
                <div class="strength-segment" id="seg2"></div>
                <div class="strength-segment" id="seg3"></div>
              </div>

              <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="fs-9 text-muted" id="strengthLabel"><?php echo __('password_strength_title', 'Password Strength'); ?></span>
                <span class="fs-9 fw-bold" id="strengthValue"></span>
              </div>

              <div class="mt-2">
                <div class="req-item" id="reqLength">
                  <i class="bi bi-circle"></i> <span><?php echo __('pw_req_length', 'At least 8 characters'); ?></span>
                </div>
                <div class="req-item" id="reqMix">
                  <i class="bi bi-circle"></i> <span><?php echo __('pw_req_mix', 'Include letters and numbers'); ?></span>
                </div>
              </div>
            </div>

            <!-- Confirm Password -->
            <div class="mb-4">
              <label for="confirm_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('confirm_password_label', 'Confirm New Password'); ?></label>
              <div class="auth-input-group">
                <span class="input-icon"><i class="bi bi-shield-check text-muted"></i></span>
                <input type="password" name="confirm_password" id="confirm_password" class="auth-input" placeholder="••••••••" required oninput="checkPasswordMatch();">
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_password', this)" title="Show/Hide Password">
                  <i class="bi bi-eye text-muted"></i>
                </button>
              </div>
              <div class="fs-9 mt-1.5" id="matchFeedback"></div>
            </div>

            <button type="submit" id="submitBtn" class="btn auth-btn-submit w-100 py-2.5 text-white fw-bold rounded-pill shadow-sm transition-all mb-3">
              <span><?php echo __('reset_password_btn', 'Update Password'); ?></span>
              <i class="bi bi-check-circle-fill ms-1.5"></i>
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

    function evaluatePasswordStrength(password) {
      const seg1 = document.getElementById('seg1');
      const seg2 = document.getElementById('seg2');
      const seg3 = document.getElementById('seg3');
      const strengthValue = document.getElementById('strengthValue');
      const reqLength = document.getElementById('reqLength');
      const reqMix = document.getElementById('reqMix');

      if (!seg1 || !seg2 || !seg3) return;

      // Reset
      seg1.className = 'strength-segment';
      seg2.className = 'strength-segment';
      seg3.className = 'strength-segment';

      // Check checklist
      const hasLength = password.length >= 8;
      const hasLetters = /[a-zA-Z]/.test(password);
      const hasNumbers = /[0-9]/.test(password);
      const hasSpecial = /[^a-zA-Z0-9]/.test(password);

      if (hasLength) {
        reqLength.classList.add('met');
      } else {
        reqLength.classList.remove('met');
      }

      if (hasLetters && hasNumbers) {
        reqMix.classList.add('met');
      } else {
        reqMix.classList.remove('met');
      }

      if (password.length === 0) {
        strengthValue.textContent = '';
        return;
      }

      let score = 0;
      if (hasLength) score++;
      if (hasLetters && hasNumbers) score++;
      if (password.length >= 10 && hasSpecial) score++;

      if (score <= 1) {
        seg1.classList.add('active-weak');
        strengthValue.textContent = '<?php echo __('password_strength_weak', 'Weak'); ?>';
        strengthValue.className = 'fs-9 fw-bold text-danger';
      } else if (score === 2) {
        seg1.classList.add('active-medium');
        seg2.classList.add('active-medium');
        strengthValue.textContent = '<?php echo __('password_strength_medium', 'Medium'); ?>';
        strengthValue.className = 'fs-9 fw-bold text-warning';
      } else {
        seg1.classList.add('active-strong');
        seg2.classList.add('active-strong');
        seg3.classList.add('active-strong');
        strengthValue.textContent = '<?php echo __('password_strength_strong', 'Strong'); ?>';
        strengthValue.className = 'fs-9 fw-bold text-success';
      }
    }

    function checkPasswordMatch() {
      const p1 = document.getElementById('new_password');
      const p2 = document.getElementById('confirm_password');
      const feedback = document.getElementById('matchFeedback');
      if (!p1 || !p2 || !feedback) return;

      if (p2.value.length === 0) {
        feedback.innerHTML = '';
        return;
      }

      if (p1.value === p2.value) {
        feedback.innerHTML = '<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i> <?php echo __('passwords_match', 'Passwords match'); ?></span>';
      } else {
        feedback.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-x-circle-fill me-1"></i> <?php echo __('passwords_dont_match', 'Passwords do not match'); ?></span>';
      }
    }

    function validateResetForm() {
      const p1 = document.getElementById('new_password').value;
      const p2 = document.getElementById('confirm_password').value;

      if (p1.length < 8) {
        alert('<?php echo __('password_too_short', 'Password must be at least 8 characters long.'); ?>');
        return false;
      }

      if (p1 !== p2) {
        alert('<?php echo __('password_mismatch', 'New passwords do not match. Please re-enter carefully.'); ?>');
        return false;
      }

      return true;
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
