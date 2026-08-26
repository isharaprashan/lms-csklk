<?php
// Comprehensive Email & SMTP Settings Management Dashboard
if (session_status() === PHP_SESSION_NONE) {
    session_name('LMS_ADMIN_SESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
    session_start();
}

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../lang/i18n.php';

$current_lang = init_lms_language();

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

// Fetch live admin user details
$roleCheckStmt = $pdo->prepare("SELECT id, name, email, avatar, role, status, password_hash FROM users WHERE id = ?");
$roleCheckStmt->execute([$_SESSION['user_id']]);
$dbUser = $roleCheckStmt->fetch();

if (!$dbUser || !in_array($dbUser['role'], ['admin', 'super_admin'])) {
    header("Location: login.php");
    exit;
}

$current_admin_name = $dbUser['name'] ?? ($_SESSION['name'] ?? 'Administrator');
$current_admin_email = $dbUser['email'] ?? ($_SESSION['email'] ?? '');
$current_admin_avatar = !empty($dbUser['avatar']) ? $dbUser['avatar'] : 'https://ui-avatars.com/api/?name=' . urlencode($current_admin_name) . '&background=0f4c81&color=fff';
$is_super_admin = ($dbUser['role'] === 'super_admin');

// CSRF Token Management
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_message = '';
$error_message = '';
$test_result = null;

if (!empty($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error_message = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// 1. Handle Form Actions (Save Settings & Send Test Email)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $posted_csrf = trim($_POST['csrf_token'] ?? '');
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
               || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
               || isset($_POST['ajax']);

    if (!hash_equals($csrf_token, $posted_csrf)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => __('csrf_invalid', 'Security token invalid. Please refresh the page.')]);
            exit;
        }
        $error_message = __('csrf_invalid', 'Security token invalid. Please refresh the page.');
    } else {

        // Action A: Save SMTP Configuration
        if ($action === 'save_settings') {
            $smtp_host = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
            $smtp_port = intval($_POST['smtp_port'] ?? 587);
            $smtp_user = trim($_POST['smtp_user'] ?? '');
            $smtp_pass = trim($_POST['smtp_pass'] ?? '');
            $smtp_secure = strtolower(trim($_POST['smtp_secure'] ?? 'tls'));
            $from_email = trim($_POST['from_email'] ?? '');
            $from_name = trim($_POST['from_name'] ?? 'Computerscience.lk Academy');

            if (!in_array($smtp_secure, ['tls', 'ssl'])) {
                $smtp_secure = 'tls';
            }

            if (empty($from_email) && !empty($smtp_user) && filter_var($smtp_user, FILTER_VALIDATE_EMAIL)) {
                $from_email = $smtp_user;
            } elseif (empty($from_email)) {
                $from_email = 'noreply@computerscience.lk';
            }

            try {
                $chk = $pdo->query("SELECT id FROM smtp_settings ORDER BY id DESC LIMIT 1")->fetch();
                if ($chk) {
                    $uStmt = $pdo->prepare("
                        UPDATE smtp_settings 
                        SET smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, smtp_secure = ?, from_email = ?, from_name = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $uStmt->execute([$smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_secure, $from_email, $from_name, $chk['id']]);
                } else {
                    $iStmt = $pdo->prepare("
                        INSERT INTO smtp_settings (smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $iStmt->execute([$smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_secure, $from_email, $from_name]);
                }

                // Synchronize legacy smtp_configs table for backwards compatibility
                try {
                    $pdo->prepare("
                        UPDATE smtp_configs 
                        SET host = ?, port = ?, username = ?, password = ?, encryption = ?, from_email = ?, from_name = ?, updated_at = NOW() 
                        WHERE id = 1
                    ")->execute([$smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_secure, $from_email, $from_name]);
                } catch (Exception $ex) {}

                $msg = __('smtp_save_success', 'Email & SMTP settings saved successfully!');
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $msg]);
                    exit;
                }
                $success_message = $msg;
            } catch (Exception $e) {
                $msg = __('smtp_save_failed', 'Failed to save settings: ') . $e->getMessage();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $msg]);
                    exit;
                }
                $error_message = $msg;
            }
        }

        // Action B: Test SMTP Connection & Send Live Test Email
        if ($action === 'test_connection') {
            $test_email = trim($_POST['test_email'] ?? '');
            
            // Allow testing either submitted temporary values or saved DB values
            $test_config = [
                'smtp_host' => trim($_POST['smtp_host'] ?? ''),
                'smtp_port' => intval($_POST['smtp_port'] ?? 587),
                'smtp_user' => trim($_POST['smtp_user'] ?? ''),
                'smtp_pass' => trim($_POST['smtp_pass'] ?? ''),
                'smtp_secure' => strtolower(trim($_POST['smtp_secure'] ?? 'tls')),
                'from_email' => trim($_POST['from_email'] ?? ''),
                'from_name' => trim($_POST['from_name'] ?? 'Computerscience.lk Academy')
            ];

            if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                $msg = __('invalid_test_email', 'Please enter a valid test recipient email address.');
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $msg]);
                    exit;
                }
                $error_message = $msg;
            } else {
                $test_result = test_smtp_connection($test_email, $test_config);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => $test_result['success'],
                        'message' => $test_result['message'],
                        'debug' => $test_result['debug']
                    ]);
                    exit;
                }

                if ($test_result['success']) {
                    $success_message = $test_result['message'];
                } else {
                    $error_message = $test_result['message'];
                }
            }
        }
    }

    if (!$is_ajax) {
        if (!empty($success_message)) {
            $_SESSION['flash_success'] = $success_message;
        }
        if (!empty($error_message)) {
            $_SESSION['flash_error'] = $error_message;
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// 2. Fetch Active Settings from Database
$current_settings = get_smtp_settings($pdo);
$is_configured = !empty($current_settings['smtp_host']) && !empty($current_settings['smtp_user']) && !empty($current_settings['smtp_pass']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('email_smtp_settings', 'Email & SMTP Settings'); ?> - <?php echo __('admin_panel', 'Admin Panel'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">

  <!-- Local Bootstrap 5 CSS & Icons -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">

  <!-- Google Fonts: Inter & Outfit -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">

  <?php render_i18n_js(); ?>

  <style>
    :root {
      --primary-navy: #0f4c81;
      --primary-dark: #0a2f52;
      --accent-gold: #b8860b;
      --accent-emerald: #10b981;
      --bg-light: #f4f7fb;
      --border-subtle: #e2e8f0;
      --text-muted-dark: #64748b;
    }

    body {
      background-color: var(--bg-light);
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      color: #1e293b;
    }

    .brand-font {
      font-family: 'Outfit', 'Inter', sans-serif;
    }

    .admin-header-bg {
      background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 50%, #0a2540 100%);
      color: #ffffff;
      border-radius: 20px;
      padding: 2.2rem;
      box-shadow: 0 12px 35px rgba(15, 76, 129, 0.18);
      position: relative;
      overflow: hidden;
    }

    .admin-header-bg::after {
      content: '';
      position: absolute;
      top: -50px;
      right: -50px;
      width: 200px;
      height: 200px;
      background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
      border-radius: 50%;
      pointer-events: none;
    }

    .glass-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid var(--border-subtle);
      box-shadow: 0 4px 20px rgba(15, 76, 129, 0.04);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .glass-card:hover {
      box-shadow: 0 8px 25px rgba(15, 76, 129, 0.08);
    }

    .card-header-styled {
      background: #ffffff;
      border-bottom: 1px solid var(--border-subtle);
      padding: 1.25rem 1.75rem;
      border-top-left-radius: 18px !important;
      border-top-right-radius: 18px !important;
    }

    .form-control, .form-select {
      border-radius: 10px;
      border: 1.5px solid #cbd5e1;
      padding: 0.65rem 0.95rem;
      font-size: 0.9rem;
      transition: all 0.2s ease;
    }

    .form-control:focus, .form-select:focus {
      border-color: #0f4c81;
      box-shadow: 0 0 0 4px rgba(15, 76, 129, 0.12);
    }

    .form-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: #334155;
      margin-bottom: 0.35rem;
    }

    .preset-pill {
      cursor: pointer;
      border-radius: 50px;
      padding: 0.45rem 1rem;
      font-size: 0.82rem;
      font-weight: 600;
      border: 1.5px solid var(--border-subtle);
      background: #ffffff;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .preset-pill:hover {
      border-color: #0f4c81;
      background: rgba(15, 76, 129, 0.06);
      color: #0f4c81;
      transform: translateY(-1px);
    }

    .btn-navy {
      background-color: var(--primary-navy);
      color: #ffffff;
      border: none;
      font-weight: 700;
      border-radius: 50px;
      padding: 0.65rem 1.6rem;
      transition: all 0.2s ease;
    }

    .btn-navy:hover {
      background-color: var(--primary-dark);
      color: #ffffff;
      box-shadow: 0 6px 20px rgba(15, 76, 129, 0.25);
      transform: translateY(-1px);
    }

    .debug-terminal {
      background-color: #0b1120;
      color: #38bdf8;
      font-family: 'Consolas', 'Courier New', monospace;
      font-size: 0.8rem;
      border-radius: 12px;
      padding: 1rem;
      max-height: 260px;
      overflow-y: auto;
      white-space: pre-wrap;
      border: 1px solid #1e293b;
    }
  </style>
</head>
<body>

  <?php
    $active_nav = 'email_settings';
    require __DIR__ . '/sidebar.php';
  ?>

  <!-- Main Content Wrapper -->
  <div class="admin-main-wrapper" id="admin-main-wrapper">

    <!-- Top Navigation Bar -->
    <header class="admin-topbar-nav d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-3">
        <button class="btn btn-outline-secondary btn-sm d-lg-none rounded-pill px-2.5" id="mobile-sidebar-toggle" type="button" aria-label="Toggle sidebar">
          <i class="bi bi-list fs-5"></i>
        </button>
        <div>
          <span class="fs-7 fw-bold text-dark brand-font d-flex align-items-center gap-2">
            <i class="bi bi-envelope-gear-fill text-warning"></i>
            <span><?php echo __('email_smtp_settings', 'Email & SMTP Settings'); ?></span>
          </span>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2.5">
        <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
          <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
        <a href="certificates.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold">
          <i class="bi bi-award-fill text-warning me-1"></i> Certificates
        </a>
        <span class="badge bg-white text-dark border px-3 py-1.5 rounded-pill shadow-xs fs-8 fw-semibold d-flex align-items-center gap-2">
          <img src="<?php echo htmlspecialchars($current_admin_avatar); ?>" class="rounded-circle" style="width: 24px; height: 24px; object-fit: cover;">
          <span class="d-none d-md-inline"><?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?>: <?php echo htmlspecialchars($current_admin_name); ?></span>
        </span>
        <a href="logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold" title="Logout">
          <i class="bi bi-box-arrow-right"></i>
        </a>
      </div>
    </header>

    <main class="py-4 flex-grow-1">
      <div class="container-fluid px-3 px-md-4" style="max-width: 1400px;">

        <!-- Header Banner -->
        <div class="admin-header-bg mb-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <div class="d-inline-flex align-items-center gap-2 px-3 py-1 bg-white bg-opacity-15 rounded-pill text-white fs-8 fw-semibold mb-2 backdrop-blur">
            <i class="bi bi-envelope-gear-fill text-warning"></i>
            <span><?php echo __('mail_subsystem', 'System Mailer Subsystem'); ?></span>
          </div>
          <h2 class="fw-bold mb-1 text-white brand-font fs-3"><?php echo __('email_smtp_settings', 'Email & SMTP Settings Management'); ?></h2>
          <p class="text-white text-opacity-80 fs-7 mb-0">
            <?php echo __('smtp_settings_desc', 'Configure active SMTP credentials for student certificate deliveries, notifications, and automated alerts.'); ?>
          </p>
        </div>

        <div>
          <?php if ($is_configured): ?>
            <span class="badge bg-success bg-opacity-25 border border-success border-opacity-50 text-white px-3 py-2 rounded-pill fw-bold fs-7 d-flex align-items-center gap-1.5">
              <i class="bi bi-check-circle-fill text-success bg-white rounded-circle"></i>
              <span><?php echo __('smtp_status_active', 'SMTP Configured & Active'); ?></span>
            </span>
          <?php else: ?>
            <span class="badge bg-warning bg-opacity-25 border border-warning border-opacity-50 text-white px-3 py-2 rounded-pill fw-bold fs-7 d-flex align-items-center gap-1.5">
              <i class="bi bi-exclamation-triangle-fill text-warning"></i>
              <span><?php echo __('smtp_status_unconfigured', 'SMTP Not Configured (Using Mail Fallback)'); ?></span>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <div id="ajax-alert-container">
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-xs d-flex align-items-center gap-2" role="alert">
          <i class="bi bi-check-circle-fill fs-5 text-success"></i>
          <div><?php echo htmlspecialchars($success_message); ?></div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-xs d-flex align-items-center gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
          <div><?php echo htmlspecialchars($error_message); ?></div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
    </div>

    <div class="row g-4">

      <!-- Left Column: Main SMTP Configuration Form (col-lg-7) -->
      <div class="col-lg-7">
        <div class="glass-card mb-4">
          <div class="card-header-styled d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark fs-6 d-flex align-items-center gap-2 brand-font">
              <i class="bi bi-sliders text-primary"></i>
              <span><?php echo __('smtp_server_config', 'SMTP Server Configuration'); ?></span>
            </h5>
            <small class="text-muted fs-9">
              <i class="bi bi-clock-history me-1"></i><?php echo __('last_updated', 'Updated'); ?>: <?php echo date('M d, Y H:i', strtotime($current_settings['updated_at'])); ?>
            </small>
          </div>

          <div class="p-4">

            <!-- Provider Presets -->
            <div class="mb-4 pb-3 border-bottom">
              <label class="form-label d-block text-muted fs-8 text-uppercase tracking-wider fw-bold mb-2">
                <i class="bi bi-magic me-1 text-warning"></i> <?php echo __('quick_fill_presets', 'Quick Fill Provider Presets:'); ?>
              </label>
              <div class="d-flex flex-wrap gap-2">
                <button type="button" class="preset-pill" onclick="applyPreset('gmail')">
                  <i class="bi bi-google text-danger"></i> Gmail / Google Workspace
                </button>
                <button type="button" class="preset-pill" onclick="applyPreset('office365')">
                  <i class="bi bi-microsoft text-primary"></i> Outlook / Office 365
                </button>
                <button type="button" class="preset-pill" onclick="applyPreset('zoho')">
                  <i class="bi bi-envelope-fill text-warning"></i> Zoho Mail
                </button>
                <button type="button" class="preset-pill" onclick="applyPreset('custom')">
                  <i class="bi bi-hdd-network text-secondary"></i> Custom SMTP
                </button>
              </div>
            </div>

            <!-- Settings Form -->
            <form id="smtpSettingsForm" action="email_settings.php" method="POST">
              <input type="hidden" name="action" value="save_settings">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

              <div class="row g-3">
                <!-- Host -->
                <div class="col-md-8">
                  <label class="form-label" for="input_smtp_host">
                    <?php echo __('smtp_host', 'SMTP Host Server'); ?> <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-hdd-network"></i></span>
                    <input type="text" class="form-control" id="input_smtp_host" name="smtp_host" 
                           placeholder="e.g. smtp.gmail.com" value="<?php echo htmlspecialchars($current_settings['smtp_host']); ?>" required>
                  </div>
                </div>

                <!-- Port -->
                <div class="col-md-4">
                  <label class="form-label" for="input_smtp_port">
                    <?php echo __('smtp_port', 'Port'); ?> <span class="text-danger">*</span>
                  </label>
                  <input type="number" class="form-control" id="input_smtp_port" name="smtp_port" 
                         placeholder="587" value="<?php echo htmlspecialchars($current_settings['smtp_port']); ?>" required>
                </div>

                <!-- Security / Encryption -->
                <div class="col-md-6">
                  <label class="form-label" for="select_smtp_secure">
                    <?php echo __('smtp_encryption', 'Encryption Security'); ?> <span class="text-danger">*</span>
                  </label>
                  <select class="form-select" id="select_smtp_secure" name="smtp_secure">
                    <option value="tls" <?php echo $current_settings['smtp_secure'] === 'tls' ? 'selected' : ''; ?>>TLS / STARTTLS (Port 587 - Recommended)</option>
                    <option value="ssl" <?php echo $current_settings['smtp_secure'] === 'ssl' ? 'selected' : ''; ?>>SSL / SMTPS (Port 465)</option>
                  </select>
                </div>

                <!-- Username / Sender Account -->
                <div class="col-md-6">
                  <label class="form-label" for="input_smtp_user">
                    <?php echo __('smtp_username', 'SMTP Username / Account Email'); ?> <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="input_smtp_user" name="smtp_user" 
                           placeholder="your-email@gmail.com" value="<?php echo htmlspecialchars($current_settings['smtp_user']); ?>" required>
                  </div>
                </div>

                <!-- Password with Show/Hide Toggle -->
                <div class="col-12">
                  <label class="form-label d-flex justify-content-between align-items-center" for="input_smtp_pass">
                    <span><?php echo __('smtp_password', 'SMTP Password / App Secret'); ?> <span class="text-danger">*</span></span>
                    <small class="text-muted fw-normal fs-9">
                      <i class="bi bi-shield-check text-success"></i> <?php echo __('gmail_app_pass_tip', 'For Gmail, use a 16-character App Password'); ?>
                    </small>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-key"></i></span>
                    <input type="password" class="form-control" id="input_smtp_pass" name="smtp_pass" 
                           placeholder="Enter SMTP password or App Password" value="<?php echo htmlspecialchars($current_settings['smtp_pass']); ?>" required>
                    <button class="btn btn-outline-secondary" type="button" id="btnTogglePass" onclick="togglePasswordVisibility()">
                      <i class="bi bi-eye-slash" id="eyeIcon"></i>
                    </button>
                  </div>
                </div>

                <div class="col-12"><hr class="my-2 border-secondary border-opacity-15"></div>

                <!-- From Email -->
                <div class="col-md-6">
                  <label class="form-label" for="input_from_email">
                    <?php echo __('from_email', 'Sender Email (From)'); ?> <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope-at"></i></span>
                    <input type="email" class="form-control" id="input_from_email" name="from_email" 
                           placeholder="certificates@computerscience.lk" value="<?php echo htmlspecialchars($current_settings['from_email']); ?>" required>
                  </div>
                </div>

                <!-- From Name -->
                <div class="col-md-6">
                  <label class="form-label" for="input_from_name">
                    <?php echo __('from_name', 'Sender Display Name'); ?> <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-building"></i></span>
                    <input type="text" class="form-control" id="input_from_name" name="from_name" 
                           placeholder="Computerscience.lk Academy" value="<?php echo htmlspecialchars($current_settings['from_name']); ?>" required>
                  </div>
                </div>
              </div>

              <!-- Submit Button -->
              <div class="d-flex justify-content-end align-items-center gap-2 mt-4 pt-2">
                <button type="submit" class="btn btn-navy d-flex align-items-center gap-2" id="btnSaveSettings">
                  <i class="bi bi-check-circle-fill"></i>
                  <span><?php echo __('save_smtp_settings', 'Save SMTP Settings'); ?></span>
                </button>
              </div>
            </form>

          </div>
        </div>
      </div>

      <!-- Right Column: Live Connection Test Tool & Diagnostic Logs (col-lg-5) -->
      <div class="col-lg-5">

        <!-- Live Connection Test Tool -->
        <div class="glass-card mb-4">
          <div class="card-header-styled bg-white">
            <h5 class="fw-bold mb-0 text-dark fs-6 d-flex align-items-center gap-2 brand-font">
              <i class="bi bi-broadcast text-success"></i>
              <span><?php echo __('live_connection_test', 'Live Connection & Test Email'); ?></span>
            </h5>
          </div>

          <div class="p-4">
            <p class="text-muted fs-8 mb-3">
              <?php echo __('test_connection_desc', 'Send an instant verification email using your current configuration to test SMTP handshakes, port availability, and authentication.'); ?>
            </p>

            <form id="testEmailForm" action="email_settings.php" method="POST">
              <input type="hidden" name="action" value="test_connection">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

              <div class="mb-3">
                <label class="form-label" for="input_test_email">
                  <?php echo __('recipient_test_email', 'Test Recipient Email Address'); ?> <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-muted"><i class="bi bi-envelope-paper"></i></span>
                  <input type="email" class="form-control" id="input_test_email" name="test_email" 
                         placeholder="admin@example.com" value="<?php echo htmlspecialchars($_SESSION['email'] ?? $current_settings['smtp_user'] ?? ''); ?>" required>
                </div>
              </div>

              <div class="d-grid">
                <button type="button" class="btn btn-outline-success fw-bold py-2 rounded-pill d-flex align-items-center justify-content-center gap-2" id="btnSendTestEmail" onclick="executeConnectionTest()">
                  <i class="bi bi-send-fill text-success"></i>
                  <span><?php echo __('send_test_email', 'Send Test Email Now'); ?></span>
                </button>
              </div>
            </form>

            <!-- Test Feedback Result Box -->
            <div id="test-feedback-box" class="mt-3" style="display: none;"></div>

            <!-- Live Diagnostic Terminal / Debug Logs -->
            <div class="mt-3">
              <div class="d-flex justify-content-between align-items-center mb-1.5">
                <span class="fs-9 text-uppercase tracking-wider fw-bold text-muted">
                  <i class="bi bi-terminal-fill me-1"></i> <?php echo __('smtp_debug_console', 'SMTP Diagnostic Log'); ?>
                </span>
                <button type="button" class="btn btn-link btn-sm p-0 fs-9 text-muted text-decoration-none" onclick="clearDebugConsole()">
                  <?php echo __('clear', 'Clear'); ?>
                </button>
              </div>
              <div class="debug-terminal" id="smtp-debug-terminal">Ready. Click "Send Test Email Now" to initiate live SMTP diagnostic handshake.</div>
            </div>

          </div>
        </div>

        <!-- Setup Guide & Security Notice -->
        <div class="glass-card p-4">
          <h6 class="fw-bold text-dark fs-7 d-flex align-items-center gap-2 mb-2 brand-font">
            <i class="bi bi-lightbulb-fill text-warning"></i>
            <span><?php echo __('smtp_quick_guide_title', 'Configuration Best Practices'); ?></span>
          </h6>
          <ul class="text-muted fs-8 ps-3 mb-0" style="line-height: 1.6;">
            <li><strong>Gmail / Google:</strong> Use <code>smtp.gmail.com</code> on Port <code>587</code> (TLS). Generate an <em>App Password</em> from your Google Account &rarr; Security.</li>
            <li><strong>Outlook / Office 365:</strong> Use <code>smtp.office365.com</code> on Port <code>587</code> (TLS). Ensure Authenticated SMTP is enabled on the Microsoft mailbox.</li>
            <li><strong>Zoho Mail:</strong> Use <code>smtppro.zoho.com</code> on Port <code>465</code> (SSL).</li>
            <li><strong>Certificate Deliveries:</strong> All issued certificate PDFs will automatically be dispatched using the credentials configured on this page.</li>
          </ul>
        </div>

      </div>
    </div>
  </main>
</div>

  <!-- Local Bootstrap JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>

  <!-- Interactive Client-side Engine -->
  <script>
    function togglePasswordVisibility() {
      const passInput = document.getElementById('input_smtp_pass');
      const eyeIcon = document.getElementById('eyeIcon');
      if (passInput.type === 'password') {
        passInput.type = 'text';
        eyeIcon.className = 'bi bi-eye';
      } else {
        passInput.type = 'password';
        eyeIcon.className = 'bi bi-eye-slash';
      }
    }

    function applyPreset(provider) {
      const hostInput = document.getElementById('input_smtp_host');
      const portInput = document.getElementById('input_smtp_port');
      const secureSelect = document.getElementById('select_smtp_secure');
      const userInput = document.getElementById('input_smtp_user');

      if (provider === 'gmail') {
        hostInput.value = 'smtp.gmail.com';
        portInput.value = '587';
        secureSelect.value = 'tls';
        userInput.placeholder = 'your-account@gmail.com';
      } else if (provider === 'office365') {
        hostInput.value = 'smtp.office365.com';
        portInput.value = '587';
        secureSelect.value = 'tls';
        userInput.placeholder = 'your-name@organization.com';
      } else if (provider === 'zoho') {
        hostInput.value = 'smtppro.zoho.com';
        portInput.value = '465';
        secureSelect.value = 'ssl';
        userInput.placeholder = 'admin@yourdomain.com';
      } else if (provider === 'custom') {
        hostInput.value = 'mail.yourdomain.com';
        portInput.value = '587';
        secureSelect.value = 'tls';
        userInput.placeholder = 'smtp@yourdomain.com';
      }
    }

    function clearDebugConsole() {
      document.getElementById('smtp-debug-terminal').textContent = 'Console cleared. Ready.';
    }

    // AJAX Save Handler
    document.getElementById('smtpSettingsForm').addEventListener('submit', function (e) {
      e.preventDefault();
      const form = this;
      const saveBtn = document.getElementById('btnSaveSettings');
      const origHtml = saveBtn.innerHTML;

      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status"></span> Saving Settings...';

      const formData = new FormData(form);
      formData.append('ajax', '1');

      fetch('email_settings.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origHtml;

        const alertContainer = document.getElementById('ajax-alert-container');
        if (data.success) {
          alertContainer.innerHTML = `
            <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-xs d-flex align-items-center gap-2" role="alert">
              <i class="bi bi-check-circle-fill fs-5 text-success"></i>
              <div>${escapeHtml(data.message)}</div>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          `;
        } else {
          alertContainer.innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-xs d-flex align-items-center gap-2" role="alert">
              <i class="bi bi-exclamation-triangle-fill fs-5 text-danger"></i>
              <div>${escapeHtml(data.message)}</div>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          `;
        }
      })
      .catch(err => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origHtml;
        console.error('Save error:', err);
      });
    });

    // AJAX Live Connection Test Handler
    function executeConnectionTest() {
      const testEmail = document.getElementById('input_test_email').value.trim();
      const feedbackBox = document.getElementById('test-feedback-box');
      const terminal = document.getElementById('smtp-debug-terminal');
      const testBtn = document.getElementById('btnSendTestEmail');

      if (!testEmail) {
        alert('Please enter a valid recipient email address for testing.');
        return;
      }

      const origHtml = testBtn.innerHTML;
      testBtn.disabled = true;
      testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status"></span> Testing Connection...';

      feedbackBox.style.display = 'block';
      feedbackBox.innerHTML = '<div class="alert alert-info py-2 px-3 fs-8 mb-0 d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm"></span> Handshaking with SMTP server...</div>';
      terminal.textContent = 'Connecting to ' + document.getElementById('input_smtp_host').value + ':' + document.getElementById('input_smtp_port').value + '...\n';

      const formData = new FormData(document.getElementById('smtpSettingsForm'));
      formData.set('action', 'test_connection');
      formData.set('test_email', testEmail);
      formData.set('ajax', '1');

      fetch('email_settings.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        testBtn.disabled = false;
        testBtn.innerHTML = origHtml;

        if (data.success) {
          feedbackBox.innerHTML = `
            <div class="alert alert-success py-2.5 px-3 fs-8 mb-0 rounded-3 d-flex align-items-center gap-2">
              <i class="bi bi-check-circle-fill text-success fs-5"></i>
              <div><strong>Success!</strong> ${escapeHtml(data.message)}</div>
            </div>
          `;
        } else {
          feedbackBox.innerHTML = `
            <div class="alert alert-danger py-2.5 px-3 fs-8 mb-0 rounded-3 d-flex align-items-center gap-2">
              <i class="bi bi-x-circle-fill text-danger fs-5"></i>
              <div><strong>Failed:</strong> ${escapeHtml(data.message)}</div>
            </div>
          `;
        }

        if (data.debug) {
          terminal.textContent = data.debug;
        }
      })
      .catch(err => {
        testBtn.disabled = false;
        testBtn.innerHTML = origHtml;
        feedbackBox.innerHTML = '<div class="alert alert-danger py-2 px-3 fs-8 mb-0">Network error during connection test.</div>';
        terminal.textContent += '\n[ERROR]: ' + err;
      });
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text || '';
      return div.innerHTML;
    }
  </script>
</body>
</html>
