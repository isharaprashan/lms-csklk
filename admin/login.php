<?php
session_name('LMS_ADMIN_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
session_start();
require_once __DIR__ . '/../db/db_connect.php';

// Redirect if already logged in as admin or super_admin
if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if (isset($_GET['error'])) {
    $errCode = $_GET['error'];
    if ($errCode === 'deactivated') {
        $error = 'Account Deactivated: Your administrator account has been deactivated or suspended by Super Admin.';
    } elseif ($errCode === 'password_changed') {
        $error = 'Security Alert: Your administrator password was updated. Please sign in with your new password.';
    } elseif ($errCode === 'account_not_found') {
        $error = 'Access Denied: Account no longer exists.';
    }
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
                $userRole = $user['role'] ?? 'student';
                $userStatus = strtolower($user['status'] ?? 'active');
                if (!in_array($userRole, ['admin', 'super_admin'])) {
                    $error = 'Access Denied: Administrative privileges required.';
                } elseif ($userStatus !== 'active' && $userRole !== 'super_admin') {
                    $error = 'Account Deactivated: Your administrator account has been deactivated or suspended by Super Admin.';
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_avatar'] = $user['avatar'];
                    $_SESSION['academic_id'] = $user['academic_id'];
                    $_SESSION['user_role'] = $userRole;
                    $_SESSION['session_password_hash'] = $user['password_hash'];

                    header("Location: index.php");
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
  <title>Admin Login | Computerscience.lk</title>
  
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
      background: radial-gradient(circle at 10% 20%, rgb(15, 76, 129) 0%, rgb(15, 30, 80) 90.2%);
      position: relative;
      overflow: hidden;
    }
    
    .login-container::before {
      content: '';
      position: absolute;
      width: 600px;
      height: 600px;
      background: radial-gradient(circle, rgba(242, 111, 33, 0.1) 0%, transparent 70%);
      top: -300px;
      right: -100px;
      z-index: 1;
    }
 
    .login-container::after {
      content: '';
      position: absolute;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(15, 76, 129, 0.2) 0%, transparent 75%);
      bottom: -200px;
      left: -200px;
      z-index: 1;
    }
 
    .login-card {
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.3);
      z-index: 2;
      width: 100%;
      max-width: 460px;
    }
    
    .form-control {
      border-radius: 8px;
    }

    .form-control:focus {
      border-color: #0f4c81;
      box-shadow: 0 0 0 0.25rem rgba(15, 76, 129, 0.25);
    }

    .admin-icon-container {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, #0f4c81 0%, #1e40af 100%);
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.5rem;
      box-shadow: 0 8px 16px rgba(15, 76, 129, 0.2);
    }
  </style>
</head>
<body class="bg-light">
 
  <div class="login-container px-3">
    <div class="login-card p-4 p-md-5">
      

      <!-- Brand Logo & Header -->
      <div class="text-center mb-4">
        <div class="d-inline-block p-2 bg-white rounded-circle shadow-sm mb-3" style="width: 80px; height: 80px;">
          <img src="../<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="img-fluid rounded-circle" style="width: 100%; height: 100%; object-fit: contain;">
        </div>
        <h4 class="fw-bold text-dark mb-1"><?php echo __('admin_console', 'Admin Portal'); ?></h4>
        <p class="text-muted fs-8 mb-0"><?php echo __('site_tagline', 'Computerscience.lk Management Console'); ?></p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Login Form -->
      <form action="login.php" method="POST">
        <div class="mb-3">
          <label for="email" class="form-label fw-semibold text-secondary"><?php echo __('email_address', 'Admin Email'); ?></label>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
            <input type="email" name="email" id="email" class="form-control border-start-0 bg-light" placeholder="admin@computerscience.lk" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
        </div>

        <div class="mb-4">
          <label for="password" class="form-label fw-semibold text-secondary"><?php echo __('password', 'Password'); ?></label>
          <div class="input-group">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
            <input type="password" name="password" id="password" class="form-control border-start-0 bg-light" placeholder="••••••••" required>
          </div>
        </div>

        <button type="submit" class="btn w-100 py-2.5 text-white fw-bold border-0 shadow-sm transition-all hover:brightness-110 mb-3" style="background-color: #0f4c81;">
          <?php echo __('sign_in_btn', 'Access Console'); ?>
        </button>
      </form>

      <!-- Main Site Link -->
      <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
        <a href="../index.php" class="text-decoration-none fs-8 text-secondary">
          <i class="bi bi-arrow-left me-1"></i> <?php echo __('nav_home', 'Back to Main Portal'); ?>
        </a>
      </div>

    </div>
  </div>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script>
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
