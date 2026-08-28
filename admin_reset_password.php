<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

$isInsideAdmin = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'admin');
$rootPrefix = $isInsideAdmin ? '../' : '';
$adminPrefix = $isInsideAdmin ? '' : 'admin/';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$email = trim(filter_var($_GET['email'] ?? $_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

$isValidToken = false;
$resetRecord = null;
$targetAdmin = null;
$error = '';
$success = '';

$pdo = getDBConnection();

// Validate token and email
if (!empty($token) && !empty($email)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_password_resets WHERE token = ? AND email = ? AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$token, $email]);
        $resetRecord = $stmt->fetch();

        if ($resetRecord) {
            // Verify target admin still exists and has administrative privileges
            $uStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE email = ? AND role IN ('admin', 'super_admin') LIMIT 1");
            $uStmt->execute([$email]);
            $targetAdmin = $uStmt->fetch();

            if ($targetAdmin && strtolower($targetAdmin['status'] ?? 'active') === 'active') {
                $isValidToken = true;
            }
        }
    } catch (Exception $e) {
        error_log("Admin token validation error: " . $e->getMessage());
        $isValidToken = false;
    }
}

// Handle New Password Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidToken) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Strict Password Policy Validation
        if (strlen($newPassword) < 8) {
            $error = __('password_too_short', 'Password must be at least 8 characters long.');
        } elseif (!preg_match('/[A-Z]/', $newPassword)) {
            $error = 'Password must contain at least one uppercase letter (A-Z).';
        } elseif (!preg_match('/[a-z]/', $newPassword)) {
            $error = 'Password must contain at least one lowercase letter (a-z).';
        } elseif (!preg_match('/[0-9]/', $newPassword)) {
            $error = 'Password must contain at least one numerical digit (0-9).';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $error = 'Password must contain at least one special character (e.g. !@#$%^&*).';
        } elseif ($newPassword !== $confirmPassword) {
            $error = __('password_mismatch', 'New passwords do not match. Please re-enter carefully.');
        } else {
            try {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);

                // Update administrator password and clear must_change_password flag
                $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
                $upStmt->execute([$hash, $targetAdmin['id']]);

                // Mark current token as used and clear any lingering tokens
                $upTok = $pdo->prepare("UPDATE admin_password_resets SET is_used = 1 WHERE id = ?");
                $upTok->execute([$resetRecord['id']]);

                $delTok = $pdo->prepare("DELETE FROM admin_password_resets WHERE email = ?");
                $delTok->execute([$targetAdmin['email']]);

                // Clear any lingering session credentials
                $_SESSION['login_flash_success'] = __('admin_password_updated_success', 'Administrator password updated successfully. Please sign in with your new credentials.');

                // Redirect to Admin Login portal
                header("Location: " . $adminPrefix . "login.php?reset=success");
                exit;
            } catch (Exception $e) {
                error_log("Admin password reset save error: " . $e->getMessage());
                $error = 'Failed to update administrator password. Please try again.';
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
  <title><?php echo __('admin_reset_password_title', 'Set New Admin Password'); ?> | Computerscience.lk</title>
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
      max-width: 500px;
      position: relative;
      z-index: 2;
      padding: 38px 34px;
    }

    .shield-badge-icon {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      background: linear-gradient(135deg, #052014 0%, #0b4528 100%);
      color: #ffffff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.9rem;
      box-shadow: 0 10px 25px rgba(11, 69, 40, 0.3);
      margin-bottom: 18px;
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
      padding-right: 44px;
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

    .password-toggle-btn {
      position: absolute;
      right: 12px;
      background: none;
      border: none;
      color: #64748b;
      cursor: pointer;
      padding: 6px;
      font-size: 1.1rem;
      z-index: 4;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.2s;
    }

    .password-toggle-btn:hover {
      color: #0b4528;
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

    /* Strength Meter Styles */
    .strength-meter-bar {
      height: 5px;
      border-radius: 4px;
      background-color: #e2e8f0;
      overflow: hidden;
      margin-top: 6px;
    }

    .strength-meter-fill {
      height: 100%;
      width: 0%;
      transition: width 0.3s ease, background-color 0.3s ease;
    }

    .rule-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.76rem;
      color: #64748b;
      transition: color 0.2s ease;
    }

    .rule-item.valid {
      color: #0b4528;
      font-weight: 600;
    }

    .rule-item.valid i::before {
      content: "\F26A";
      color: #198754;
    }
  </style>
</head>
<body>

  <div class="admin-auth-container">
    <div class="admin-auth-card">
      
      <!-- Top Crest & Header -->
      <div class="text-center">
        <div class="shield-badge-icon">
          <i class="bi bi-shield-check"></i>
        </div>
        <div class="d-inline-flex align-items-center gap-1.5 px-3 py-1 rounded-pill bg-dark bg-opacity-10 text-dark border fs-9 fw-bold text-uppercase mb-2" style="letter-spacing: 0.5px;">
          <i class="bi bi-shield-shaded text-success"></i> <?php echo __('admin_security_portal', 'Enterprise Admin Security'); ?>
        </div>
        <h4 class="fw-bold text-dark mb-1 fs-5"><?php echo __('admin_reset_password_title', 'Set New Admin Password'); ?></h4>
        <p class="text-secondary fs-8 mb-4">
          <?php echo __('admin_reset_password_subtitle', 'Configure a strong, cryptographically secure password for your administrative account.'); ?>
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

      <?php if (!$isValidToken): ?>
        <!-- Invalid or Expired Token Error Card -->
        <div class="p-4 rounded-3 border border-danger border-opacity-30 bg-danger bg-opacity-10 mb-4 text-center">
          <i class="bi bi-clock-history text-danger fs-1 mb-2 d-block"></i>
          <h6 class="fw-bold text-danger mb-1 fs-7"><?php echo __('admin_token_invalid_or_expired', 'This password reset link is invalid, has expired (valid for 20 minutes), or has already been used.'); ?></h6>
          <p class="fs-8 text-secondary mb-0">
            <?php echo __('admin_token_invalid_desc', 'For administrative security, reset tokens are strictly limited to single-use and expire within 20 minutes of dispatch. Please generate a new request.'); ?>
          </p>
        </div>

        <div class="d-flex flex-column gap-2">
          <a href="<?php echo $isInsideAdmin ? 'forgot_password.php' : 'admin_forgot_password.php'; ?>" class="btn btn-admin-submit w-100 py-2.5 d-inline-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-arrow-repeat"></i>
            <span><?php echo __('request_new_admin_reset_link', 'Request New Admin Reset Link'); ?></span>
          </a>
          <a href="<?php echo $adminPrefix; ?>login.php" class="btn btn-outline-secondary rounded-pill py-2.5 fs-8 fw-semibold w-100 mt-1">
            <i class="bi bi-arrow-left me-1"></i> <?php echo __('back_to_admin_login', 'Back to Admin Login'); ?>
          </a>
        </div>
      <?php else: ?>
        <!-- Active Administrator Badge -->
        <div class="p-3 bg-light rounded-3 border mb-3.5 d-flex align-items-center gap-3">
          <div class="rounded-circle bg-success bg-opacity-15 text-success p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
            <i class="bi bi-person-gear fs-5"></i>
          </div>
          <div>
            <div class="fw-bold text-dark fs-8"><?php echo htmlspecialchars($targetAdmin['name']); ?></div>
            <div class="fs-9 text-muted"><?php echo htmlspecialchars($targetAdmin['email']); ?> &bull; <strong class="text-success text-uppercase"><?php echo htmlspecialchars($targetAdmin['role']); ?></strong></div>
          </div>
        </div>

        <!-- Password Reset Form -->
        <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? ($rootPrefix . 'admin_reset_password.php')); ?>" method="POST" id="adminResetForm" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
          <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

          <!-- New Password -->
          <div class="mb-3">
            <label for="new_password" class="form-label fw-semibold text-dark fs-8 mb-1">
              <?php echo __('new_password_label', 'New Password'); ?> <span class="text-danger">*</span>
            </label>
            <div class="auth-input-group">
              <span class="input-icon"><i class="bi bi-shield-lock"></i></span>
              <input type="password" name="password" id="new_password" class="auth-input" 
                     placeholder="Enter new admin password" required autofocus>
              <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('new_password', this)" title="Show/Hide">
                <i class="bi bi-eye"></i>
              </button>
            </div>

            <!-- Strength Bar -->
            <div class="strength-meter-bar">
              <div class="strength-meter-fill" id="strengthFill"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-1">
              <span class="fs-9 text-muted"><?php echo __('password_strength_title', 'Password Strength'); ?>:</span>
              <span class="fs-9 fw-bold" id="strengthLabel">-</span>
            </div>
          </div>

          <!-- Confirm Password -->
          <div class="mb-3">
            <label for="confirm_password" class="form-label fw-semibold text-dark fs-8 mb-1">
              <?php echo __('confirm_password_label', 'Confirm New Password'); ?> <span class="text-danger">*</span>
            </label>
            <div class="auth-input-group">
              <span class="input-icon"><i class="bi bi-check-all"></i></span>
              <input type="password" name="confirm_password" id="confirm_password" class="auth-input" 
                     placeholder="Re-enter new admin password" required>
              <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_password', this)" title="Show/Hide">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div id="matchNotice" class="fs-9 mt-1 fw-semibold" style="display: none;"></div>
          </div>

          <!-- Strict Password Requirements Checklist -->
          <div class="p-3 bg-light rounded-3 border mb-4">
            <div class="fw-bold text-dark fs-9 text-uppercase mb-2" style="letter-spacing: 0.5px;">Admin Password Requirements:</div>
            <div class="row g-2">
              <div class="col-6">
                <div class="rule-item" id="ruleLength"><i class="bi bi-circle"></i> 8+ characters</div>
              </div>
              <div class="col-6">
                <div class="rule-item" id="ruleUpper"><i class="bi bi-circle"></i> Uppercase (A-Z)</div>
              </div>
              <div class="col-6">
                <div class="rule-item" id="ruleLower"><i class="bi bi-circle"></i> Lowercase (a-z)</div>
              </div>
              <div class="col-6">
                <div class="rule-item" id="ruleNumber"><i class="bi bi-circle"></i> Number (0-9)</div>
              </div>
              <div class="col-12">
                <div class="rule-item" id="ruleSpecial"><i class="bi bi-circle"></i> Special character (!@#$%^&*)</div>
              </div>
            </div>
          </div>

          <button type="submit" id="submitBtn" class="btn btn-admin-submit w-100 py-2.5 d-inline-flex align-items-center justify-content-center gap-2 mb-3">
            <i class="bi bi-shield-check"></i>
            <span><?php echo __('reset_password_btn', 'Update Password'); ?></span>
          </button>
        </form>

        <div class="text-center pt-2 border-top">
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

  <script>
    function togglePasswordVisibility(fieldId, btn) {
      const input = document.getElementById(fieldId);
      if (!input) return;
      const icon = btn.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
          icon.classList.remove('bi-eye');
          icon.classList.add('bi-eye-slash');
        }
      } else {
        input.type = 'password';
        if (icon) {
          icon.classList.remove('bi-eye-slash');
          icon.classList.add('bi-eye');
        }
      }
    }

    // Live Password Strength & Requirements Validation
    const pwInput = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthFill = document.getElementById('strengthFill');
    const strengthLabel = document.getElementById('strengthLabel');
    const matchNotice = document.getElementById('matchNotice');

    if (pwInput) {
      pwInput.addEventListener('input', function () {
        const val = this.value;
        let score = 0;

        const hasLen = val.length >= 8;
        const hasUp = /[A-Z]/.test(val);
        const hasLow = /[a-z]/.test(val);
        const hasNum = /[0-9]/.test(val);
        const hasSpec = /[^A-Za-z0-9]/.test(val);

        updateRule('ruleLength', hasLen);
        updateRule('ruleUpper', hasUp);
        updateRule('ruleLower', hasLow);
        updateRule('ruleNumber', hasNum);
        updateRule('ruleSpecial', hasSpec);

        if (hasLen) score++;
        if (hasUp) score++;
        if (hasLow) score++;
        if (hasNum) score++;
        if (hasSpec) score++;

        if (val.length === 0) {
          strengthFill.style.width = '0%';
          strengthFill.style.backgroundColor = '#e2e8f0';
          strengthLabel.textContent = '-';
          strengthLabel.className = 'fs-9 fw-bold';
        } else if (score <= 2) {
          strengthFill.style.width = '30%';
          strengthFill.style.backgroundColor = '#ef4444';
          strengthLabel.textContent = 'Weak';
          strengthLabel.className = 'fs-9 fw-bold text-danger';
        } else if (score <= 4) {
          strengthFill.style.width = '70%';
          strengthFill.style.backgroundColor = '#f59e0b';
          strengthLabel.textContent = 'Medium';
          strengthLabel.className = 'fs-9 fw-bold text-warning';
        } else {
          strengthFill.style.width = '100%';
          strengthFill.style.backgroundColor = '#10b981';
          strengthLabel.textContent = 'Strong (Compliant)';
          strengthLabel.className = 'fs-9 fw-bold text-success';
        }

        checkMatch();
      });
    }

    if (confirmInput) {
      confirmInput.addEventListener('input', checkMatch);
    }

    function checkMatch() {
      if (!confirmInput || !pwInput) return;
      const pw = pwInput.value;
      const cpw = confirmInput.value;

      if (!cpw) {
        matchNotice.style.display = 'none';
        return;
      }

      matchNotice.style.display = 'block';
      if (pw === cpw) {
        matchNotice.className = 'fs-9 mt-1 fw-semibold text-success';
        matchNotice.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Passwords match';
      } else {
        matchNotice.className = 'fs-9 mt-1 fw-semibold text-danger';
        matchNotice.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> Passwords do not match';
      }
    }

    function updateRule(elementId, isValid) {
      const el = document.getElementById(elementId);
      if (!el) return;
      if (isValid) {
        el.classList.add('valid');
      } else {
        el.classList.remove('valid');
      }
    }
  </script>
</body>
</html>
