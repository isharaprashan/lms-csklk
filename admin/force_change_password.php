<?php
session_name('LMS_ADMIN_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
session_start();

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

// Session recovery check
if (!isset($_SESSION['user_id'])) {
    $sid = $_GET['sid'] ?? $_POST['sid'] ?? ($_COOKIE['PHPSESSID'] ?? null);
    if ($sid) {
        session_write_close();
        session_name('PHPSESSID');
        if ($sid !== ($_COOKIE['PHPSESSID'] ?? null)) {
            session_id($sid);
        }
        session_start();
    }
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT id, name, email, avatar, role, status, password_hash, must_change_password FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || !in_array($user['role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: login.php");
    exit;
}

// If password change is not required, redirect to index
if (empty($user['must_change_password']) || $user['must_change_password'] == 0) {
    $_SESSION['must_change_password'] = false;
    header("Location: index.php");
    exit;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Handle Password Change Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $current_temp_password = $_POST['current_temp_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_temp_password) || empty($new_password) || empty($confirm_password)) {
            $error = __('all_fields_required', 'All fields are required.');
        } elseif (!password_verify($current_temp_password, $user['password_hash'])) {
            $error = __('incorrect_temp_password', 'The current temporary password you entered is incorrect.');
        } elseif ($new_password === $current_temp_password) {
            $error = __('password_must_not_match_temp', 'Your new password cannot be the same as your temporary password.');
        } elseif (strlen($new_password) < 8) {
            $error = __('password_too_short', 'Password must be at least 8 characters long.');
        } elseif ($new_password !== $confirm_password) {
            $error = __('password_mismatch', 'New passwords do not match. Please re-enter carefully.');
        } else {
            try {
                $newHash = password_hash($new_password, PASSWORD_BCRYPT);
                $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, temp_password_created_at = NULL WHERE id = ?");
                $upStmt->execute([$newHash, $user['id']]);

                // Update session
                $_SESSION['must_change_password'] = false;
                $_SESSION['session_password_hash'] = $newHash;
                $_SESSION['flash_success'] = __('password_change_success', 'Password updated successfully! Welcome to your administrator dashboard.');

                header("Location: index.php");
                exit;
            } catch (Exception $e) {
                error_log("Force change password error: " . $e->getMessage());
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
  <title><?php echo __('force_change_password_title', 'Set Permanent Password'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
  
  <style>
    body {
      background: radial-gradient(circle at 10% 20%, rgb(5, 32, 20) 0%, rgb(11, 69, 40) 90.2%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: system-ui, -apple-system, sans-serif;
      padding: 24px 16px;
    }

    .force-card {
      background: #ffffff;
      border-radius: 20px;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.2);
      width: 100%;
      max-width: 480px;
      overflow: hidden;
    }

    .card-top-accent {
      background: linear-gradient(135deg, #052014 0%, #0b4528 50%, #125b36 100%);
      padding: 28px 24px 22px;
      color: #ffffff;
      text-align: center;
      position: relative;
    }

    .icon-badge {
      width: 58px;
      height: 58px;
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(8px);
      border-radius: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.75rem;
      color: #a3cfbb;
      margin-bottom: 12px;
      border: 1px solid rgba(255, 255, 255, 0.25);
    }

    .auth-input-group {
      position: relative;
      display: flex;
      align-items: center;
    }

    .auth-input-group .input-icon {
      position: absolute;
      left: 14px;
      z-index: 4;
      font-size: 1rem;
      pointer-events: none;
    }

    .auth-input-group .password-toggle-btn {
      position: absolute;
      right: 12px;
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
      height: 46px;
      border-radius: 10px;
      border: 1.5px solid #e2e8f0;
      padding-left: 42px;
      padding-right: 42px;
      background: #f8fafc;
      font-size: 0.9rem;
      color: #1e293b;
      transition: all 0.2s ease;
      width: 100%;
    }

    .auth-input:focus {
      background: #ffffff;
      border-color: #0b4528;
      box-shadow: 0 0 0 4px rgba(11, 69, 40, 0.12);
      outline: none;
    }

    .btn-super-green {
      background: linear-gradient(135deg, #0b4528 0%, #125b36 100%);
      color: #ffffff;
      border: none;
      font-weight: 700;
      transition: all 0.2s ease;
    }

    .btn-super-green:hover {
      background: linear-gradient(135deg, #125b36 0%, #177143 100%);
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 8px 20px rgba(11, 69, 40, 0.3);
    }

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
    }

    .req-item.met {
      color: #10b981;
      font-weight: 600;
    }

    .req-item.met i::before {
      content: "\F272"; /* bi-check-circle-fill */
    }
  </style>
</head>
<body>

  <div class="force-card">
    
    <!-- Top Header -->
    <div class="card-top-accent">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="badge bg-white text-success px-2.5 py-1 rounded-pill fs-9 fw-bold">
          <i class="bi bi-shield-lock-fill me-1"></i> Admin Security Gate
        </span>
        
        <!-- Language Switcher -->
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-light text-white dropdown-toggle rounded-pill px-2.5 py-0.5 fs-9" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-globe me-1"></i><?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'සිංහල' : 'English'; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-1" aria-labelledby="langDropdown">
            <li>
              <a class="dropdown-item fs-8" href="#" onclick="switchLanguage('en'); return false;">English</a>
            </li>
            <li>
              <a class="dropdown-item fs-8" href="#" onclick="switchLanguage('si'); return false;">සිංහල</a>
            </li>
          </ul>
        </div>
      </div>

      <div class="icon-badge">
        <i class="bi bi-key-fill"></i>
      </div>
      <h4 class="fw-bold mb-1 text-white"><?php echo __('force_change_password_title', 'Set Permanent Password'); ?></h4>
      <p class="text-white-50 fs-8 mb-0"><?php echo __('force_change_password_subtitle', 'For security compliance, you must replace your temporary password before accessing the Administrator Dashboard.'); ?></p>
    </div>

    <!-- Form Body -->
    <div class="p-4">
      
      <!-- User Info Pill -->
      <div class="d-flex align-items-center gap-2.5 p-2.5 bg-light rounded-3 border mb-3 fs-8">
        <i class="bi bi-person-badge-fill text-success fs-5"></i>
        <div class="overflow-hidden">
          <div class="fw-bold text-dark text-truncate"><?php echo htmlspecialchars($user['name']); ?></div>
          <div class="text-muted fs-9 text-truncate"><?php echo htmlspecialchars($user['email']); ?> &bull; System Administrator</div>
        </div>
      </div>

      <!-- Alerts -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show fs-8 py-2.5 px-3 rounded-3 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-1.5"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <form action="force_change_password.php" method="POST" id="forceChangeForm" onsubmit="return validateForm()">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

        <!-- Current Temporary Password -->
        <div class="mb-3">
          <label for="current_temp_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('current_temp_password', 'Current Temporary Password'); ?></label>
          <div class="auth-input-group">
            <span class="input-icon"><i class="bi bi-lock text-muted"></i></span>
            <input type="password" name="current_temp_password" id="current_temp_password" class="auth-input" placeholder="••••••••" required autofocus>
            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('current_temp_password', this)" title="Show/Hide Password">
              <i class="bi bi-eye text-muted"></i>
            </button>
          </div>
        </div>

        <!-- New Permanent Password -->
        <div class="mb-3">
          <label for="new_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('new_permanent_password', 'New Permanent Password'); ?></label>
          <div class="auth-input-group">
            <span class="input-icon"><i class="bi bi-shield-lock text-muted"></i></span>
            <input type="password" name="new_password" id="new_password" class="auth-input" placeholder="••••••••" required oninput="evaluatePasswordStrength(this.value); checkPasswordMatch();">
            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('new_password', this)" title="Show/Hide Password">
              <i class="bi bi-eye text-muted"></i>
            </button>
          </div>

          <!-- Strength Meter -->
          <div class="strength-meter-bar" id="strengthMeter">
            <div class="strength-segment" id="seg1"></div>
            <div class="strength-segment" id="seg2"></div>
            <div class="strength-segment" id="seg3"></div>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-1">
            <span class="fs-9 text-muted"><?php echo __('password_strength_title', 'Password Strength'); ?></span>
            <span class="fs-9 fw-bold" id="strengthValue"></span>
          </div>

          <div class="mt-2">
            <div class="req-item" id="reqLength">
              <i class="bi bi-circle"></i> <span><?php echo __('pw_req_length', 'At least 8 characters'); ?></span>
            </div>
            <div class="req-item" id="reqMix">
              <i class="bi bi-circle"></i> <span><?php echo __('pw_req_mix', 'Letters and numbers'); ?></span>
            </div>
          </div>
        </div>

        <!-- Confirm New Permanent Password -->
        <div class="mb-4">
          <label for="confirm_password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('confirm_permanent_password', 'Confirm New Permanent Password'); ?></label>
          <div class="auth-input-group">
            <span class="input-icon"><i class="bi bi-shield-check text-muted"></i></span>
            <input type="password" name="confirm_password" id="confirm_password" class="auth-input" placeholder="••••••••" required oninput="checkPasswordMatch();">
            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_password', this)" title="Show/Hide Password">
              <i class="bi bi-eye text-muted"></i>
            </button>
          </div>
          <div class="fs-9 mt-1.5" id="matchFeedback"></div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-super-green w-100 py-2.5 rounded-pill shadow-sm mb-3">
          <span><?php echo __('update_permanent_password_btn', 'Set Permanent Password & Enter Dashboard'); ?></span>
          <i class="bi bi-arrow-right-circle-fill ms-1.5"></i>
        </button>

      </form>

      <!-- Logout Option -->
      <div class="text-center pt-2 border-top">
        <a href="logout.php" class="text-muted text-decoration-none fs-8">
          <i class="bi bi-box-arrow-left me-1"></i> <?php echo __('nav_logout', 'Sign Out & Return Later'); ?>
        </a>
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

      seg1.className = 'strength-segment';
      seg2.className = 'strength-segment';
      seg3.className = 'strength-segment';

      const hasLength = password.length >= 8;
      const hasLetters = /[a-zA-Z]/.test(password);
      const hasNumbers = /[0-9]/.test(password);
      const hasSpecial = /[^a-zA-Z0-9]/.test(password);

      if (hasLength) reqLength.classList.add('met');
      else reqLength.classList.remove('met');

      if (hasLetters && hasNumbers) reqMix.classList.add('met');
      else reqMix.classList.remove('met');

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

    function validateForm() {
      const current = document.getElementById('current_temp_password').value;
      const p1 = document.getElementById('new_password').value;
      const p2 = document.getElementById('confirm_password').value;

      if (p1.length < 8) {
        alert('<?php echo __('password_too_short', 'Password must be at least 8 characters long.'); ?>');
        return false;
      }

      if (p1 === current) {
        alert('<?php echo __('password_must_not_match_temp', 'Your new password cannot be the same as your temporary password.'); ?>');
        return false;
      }

      if (p1 !== p2) {
        alert('<?php echo __('password_mismatch', 'New passwords do not match. Please re-enter carefully.'); ?>');
        return false;
      }

      return true;
    }

    function switchLanguage(lang) {
      fetch('../api/set_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lang: lang })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          location.reload();
        } else {
          window.location.href = '../api/set_language.php?lang=' + lang;
        }
      })
      .catch(err => {
        window.location.href = '../api/set_language.php?lang=' + lang;
      });
    }
  </script>
</body>
</html>
