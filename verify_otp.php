<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

$email = trim($_GET['email'] ?? ($_SESSION['pending_otp_email'] ?? ''));
$error = '';
$success = '';

if (isset($_SESSION['otp_flash_success'])) {
    $success = $_SESSION['otp_flash_success'];
    unset($_SESSION['otp_flash_success']);
}

// Redirect if already logged in and email is verified
if (isset($_SESSION['user_id']) && isset($_GET['sid'])) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT email_verified FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $verified = $stmt->fetchColumn();
        if ($verified == 1) {
            header("Location: dashboard.php?sid=" . urlencode($_SESSION['sid'] ?? $_GET['sid']));
            exit;
        }
    } catch (Exception $e) {
    }
}

// Handle OTP Verification Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? ($email ?: ''));
    $otp_digits = $_POST['otp_digit'] ?? [];
    
    // Combine 6 individual boxes or single combined input
    if (is_array($otp_digits)) {
        $otp = implode('', array_map('trim', $otp_digits));
    } else {
        $otp = trim($_POST['otp'] ?? '');
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = __('invalid_email_error', 'Please provide a valid email address.');
    } elseif (strlen($otp) !== 6 || !ctype_digit($otp)) {
        $error = __('otp_format_error', 'Please enter the complete 6-digit verification code.');
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = __('user_not_found_error', 'No account found with this email address.');
            } elseif ($user['email_verified'] == 1) {
                $success = __('already_verified_msg', 'Your email is already verified. Redirecting to login...');
                header("Refresh: 2; url=login.php");
            } elseif (empty($user['otp_code']) || $user['otp_code'] !== $otp) {
                $error = __('otp_invalid_msg', 'Invalid verification code. Please check and try again.');
            } elseif (empty($user['otp_expires_at']) || strtotime($user['otp_expires_at']) < time()) {
                $error = __('otp_expired_msg', 'This verification code has expired. Please click "Resend Code" to get a fresh code.');
            } else {
                // OTP is Valid -> Activate user
                $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                $upStmt->execute([$user['id']]);

                // Establish login session
                session_regenerate_id(true);
                $new_sid = session_id();

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_avatar'] = get_user_avatar($user['avatar'], $user['name']);
                $_SESSION['academic_id'] = $user['academic_id'];
                $_SESSION['user_role'] = $user['role'] ?? 'student';
                $_SESSION['sid'] = $new_sid;

                // Clear pending session markers
                unset($_SESSION['pending_otp_email']);
                unset($_SESSION['pending_otp_user_id']);

                if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin') {
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
                    header("Location: dashboard.php?sid=" . urlencode($new_sid) . "&verified=1");
                }
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('verify_email_otp_title', 'Email Verification'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Local Tailwind CSS -->
  <script src="assets/js/tailwind.js"></script>
  
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
  
  <style>
    .verify-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #091527 0%, #0f3d6c 50%, #174b85 100%);
      position: relative;
      overflow: hidden;
      padding: 40px 15px;
    }
    
    .verify-container::before {
      content: '';
      position: absolute;
      width: 500px;
      height: 500px;
      background: rgba(242, 111, 33, 0.12);
      border-radius: 50%;
      top: -200px;
      right: -100px;
      z-index: 1;
    }
 
    .verify-container::after {
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
 
    .verify-card {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: 20px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
      z-index: 2;
      width: 100%;
      max-width: 480px;
    }

    .otp-input-group {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin: 20px 0;
    }

    .otp-digit {
      width: 52px;
      height: 60px;
      font-size: 26px;
      font-weight: 700;
      text-align: center;
      border: 2px solid #cbd5e1;
      border-radius: 12px;
      background: #f8fafc;
      color: #0f4c81;
      transition: all 0.2s ease;
      font-family: Consolas, Monaco, monospace;
    }

    .otp-digit:focus {
      border-color: #0f4c81;
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(15, 76, 129, 0.15);
      outline: none;
      transform: translateY(-2px);
    }

    .otp-digit.filled {
      border-color: #0f4c81;
      background: #f0f7ff;
    }

    @media (max-width: 420px) {
      .otp-digit {
        width: 42px;
        height: 52px;
        font-size: 20px;
        gap: 6px;
      }
    }
  </style>
</head>
<body class="bg-light">

  <div class="verify-container">
    <div class="verify-card p-4 p-md-5">
      
      <!-- Top Language Dropdown Toggle -->
      <div class="d-flex justify-content-end mb-2">
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

      <!-- Icon & Title Header -->
      <div class="text-center mb-4">
        <div class="rounded-circle bg-primary bg-opacity-10 text-primary mx-auto mb-3 d-flex align-items-center justify-content-center shadow-xs" style="width: 64px; height: 64px;">
          <i class="bi bi-shield-lock-fill fs-2" style="color: #0f4c81;"></i>
        </div>
        <h4 class="fw-bold text-dark mb-1 fs-5"><?php echo __('verify_email_otp_title', 'Verify Your Email'); ?></h4>
        <p class="text-muted fs-8 mb-2">
          <?php echo __('enter_6_digit_otp', 'We have dispatched a 6-digit verification code to:'); ?>
        </p>
        <div class="d-inline-flex align-items-center gap-1.5 px-3 py-1 bg-light rounded-pill border">
          <i class="bi bi-envelope-fill text-primary fs-9"></i>
          <span class="fw-bold text-dark fs-8 font-monospace" id="display-email"><?php echo htmlspecialchars($email ?: 'your email'); ?></span>
        </div>
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
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div id="ajax-alert" class="d-none alert fs-8 py-2.5 px-3 rounded-3 mb-3"></div>

      <!-- Verification Form -->
      <form action="verify_otp.php" method="POST" id="otp-form">
        <input type="hidden" name="email" id="form-email-input" value="<?php echo htmlspecialchars($email); ?>">

        <!-- 6 Individual Digit Inputs -->
        <div class="otp-input-group" id="otp-inputs">
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" autocomplete="one-time-code" autofocus required>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" required>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" required>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" required>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" required>
          <input type="text" inputmode="numeric" pattern="[0-9]*" class="otp-digit" name="otp_digit[]" maxlength="1" required>
        </div>

        <button type="submit" id="btn-verify-submit" class="btn w-100 py-2.5 text-white fw-bold border-0 shadow-sm transition-all hover:brightness-110 mb-3 rounded-pill" style="background-color: #0f4c81;">
          <i class="bi bi-check-circle-fill me-1.5"></i>
          <?php echo __('verify_code_btn', 'Verify & Activate Account'); ?>
        </button>
      </form>

      <!-- Resend OTP & Cooldown -->
      <div class="text-center mt-3 pt-3 border-top">
        <p class="text-muted fs-8 mb-2">
          <?php echo __('didnt_receive_code', "Didn't receive the verification code?"); ?>
        </p>
        <button type="button" id="btn-resend-otp" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fs-8 fw-semibold" onclick="resendOTP()">
          <i class="bi bi-arrow-clockwise me-1"></i>
          <span id="resend-btn-text"><?php echo __('resend_otp', 'Resend OTP'); ?></span>
        </button>
        <div id="cooldown-timer" class="text-muted fs-9 mt-1.5 d-none">
          <i class="bi bi-clock-history me-1"></i><?php echo __('resend_in_seconds', 'Resend available in'); ?> <strong id="seconds-counter">60</strong>s
        </div>
      </div>

      <!-- Back to Login / Change Email -->
      <div class="text-center mt-3 pt-2">
        <a href="login.php" class="text-muted text-decoration-none fs-8 hover:text-dark d-inline-flex align-items-center gap-1">
          <i class="bi bi-arrow-left"></i> <?php echo __('back_to_login', 'Back to Sign In'); ?>
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

    // 6-digit Auto-Tabbing & Paste Handler
    document.addEventListener('DOMContentLoaded', function() {
      const inputs = Array.from(document.querySelectorAll('.otp-digit'));
      const form = document.getElementById('otp-form');
      const submitBtn = document.getElementById('btn-verify-submit');

      inputs.forEach((input, index) => {
        // Input listener: auto move forward
        input.addEventListener('input', function(e) {
          const val = this.value.replace(/[^0-9]/g, '');
          this.value = val ? val[0] : '';
          
          if (this.value) {
            this.classList.add('filled');
            if (index < inputs.length - 1) {
              inputs[index + 1].focus();
            }
          } else {
            this.classList.remove('filled');
          }

          checkAllFilled();
        });

        // Keydown listener: handle backspace navigation
        input.addEventListener('keydown', function(e) {
          if (e.key === 'Backspace' && !this.value && index > 0) {
            inputs[index - 1].focus();
            inputs[index - 1].value = '';
            inputs[index - 1].classList.remove('filled');
          }
        });

        // Paste listener: distribute across all 6 inputs
        input.addEventListener('paste', function(e) {
          e.preventDefault();
          const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim();
          const digits = pasteData.replace(/[^0-9]/g, '').slice(0, inputs.length);
          
          if (digits.length > 0) {
            digits.split('').forEach((d, i) => {
              if (inputs[i]) {
                inputs[i].value = d;
                inputs[i].classList.add('filled');
              }
            });
            if (digits.length === inputs.length) {
              submitBtn.focus();
            } else if (inputs[digits.length]) {
              inputs[digits.length].focus();
            }
          }
          checkAllFilled();
        });
      });

      function checkAllFilled() {
        const allFilled = inputs.every(i => i.value.length === 1);
        if (allFilled) {
          submitBtn.classList.remove('opacity-75');
        }
      }
    });

    // Resend OTP Cooldown & Request Logic
    let cooldownTimer = null;

    function startCooldown(seconds) {
      const btn = document.getElementById('btn-resend-otp');
      const timerContainer = document.getElementById('cooldown-timer');
      const counter = document.getElementById('seconds-counter');
      
      btn.disabled = true;
      btn.classList.add('opacity-50');
      timerContainer.classList.remove('d-none');
      
      let remaining = seconds;
      counter.textContent = remaining;

      clearInterval(cooldownTimer);
      cooldownTimer = setInterval(() => {
        remaining--;
        counter.textContent = remaining;
        if (remaining <= 0) {
          clearInterval(cooldownTimer);
          btn.disabled = false;
          btn.classList.remove('opacity-50');
          timerContainer.classList.add('d-none');
        }
      }, 1000);
    }

    function resendOTP() {
      const email = document.getElementById('form-email-input').value;
      const ajaxAlert = document.getElementById('ajax-alert');
      const btn = document.getElementById('btn-resend-otp');
      const btnText = document.getElementById('resend-btn-text');

      if (!email) {
        alert('Please enter your email address.');
        return;
      }

      btn.disabled = true;
      btnText.textContent = 'Sending...';

      fetch('api/resend_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email })
      })
      .then(res => res.json())
      .then(data => {
        btnText.textContent = 'Resend OTP';
        ajaxAlert.className = data.success 
          ? 'alert alert-success fs-8 py-2.5 px-3 rounded-3 mb-3' 
          : 'alert alert-danger fs-8 py-2.5 px-3 rounded-3 mb-3';
        ajaxAlert.innerHTML = (data.success ? '<i class="bi bi-check-circle-fill me-1.5"></i>' : '<i class="bi bi-exclamation-triangle-fill me-1.5"></i>') + data.message;
        ajaxAlert.classList.remove('d-none');

        if (data.success) {
          startCooldown(data.cooldown || 60);
        } else if (data.cooldown_remaining) {
          startCooldown(data.cooldown_remaining);
        } else {
          btn.disabled = false;
        }
      })
      .catch(err => {
        btn.disabled = false;
        btnText.textContent = 'Resend OTP';
        ajaxAlert.className = 'alert alert-danger fs-8 py-2.5 px-3 rounded-3 mb-3';
        ajaxAlert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1.5"></i> Error requesting new OTP. Please try again.';
        ajaxAlert.classList.remove('d-none');
      });
    }
  </script>
</body>
</html>
