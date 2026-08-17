<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Redirect if already logged in for this specific tab
if (isset($_SESSION['user_id']) && isset($_GET['sid'])) {
    header("Location: dashboard.php?sid=" . urlencode($_SESSION['sid'] ?? $_GET['sid']));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
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
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'This email is already registered.';
            } else {
                // Handle profile picture file upload (No auto picture applied)
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
                    // Insert new user
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $academic_id = ($role === 'teacher' ? 'TCHR-' : 'ACAD-') . rand(100000, 999999);
                    
                    $bio = ($role === 'teacher') ? trim($_POST['bio'] ?? '') : null;
                    $subject = ($role === 'teacher') ? trim($_POST['subject'] ?? '') : null;
                    $qualifications = ($role === 'teacher') ? trim($_POST['qualifications'] ?? '') : null;
                    $status = ($role === 'teacher') ? 'pending' : 'active';

                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, avatar, academic_id, role, status, bio, subject, qualifications) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
                        $qualifications
                    ]);

                    $userId = $pdo->lastInsertId();
                    
                    // Set success message
                    $success = 'Account created successfully! You can now log in.';
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
    .register-container {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f4c81 0%, #1d4ed8 100%);
      position: relative;
      overflow: hidden;
      padding-top: 50px;
      padding-bottom: 50px;
    }
    
    .register-container::before {
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

    .register-container::after {
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

    .register-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 16px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
      z-index: 2;
      width: 100%;
      max-width: 500px;
    }
    
    .form-control:focus {
      border-color: #0f4c81;
      box-shadow: 0 0 0 0.25rem rgba(15, 76, 129, 0.25);
    }
    
    .avatar-option {
      width: 55px;
      height: 55px;
      object-fit: cover;
      cursor: pointer;
      border: 3px solid transparent;
      transition: all 0.2s ease;
    }
    
    .avatar-option:hover {
      transform: scale(1.1);
    }
    
    .avatar-option.selected {
      border-color: #f26f21;
      box-shadow: 0 0 10px rgba(242, 111, 33, 0.5);
    }
  </style>
</head>
<body class="bg-light">

  <div class="register-container px-3">
    <div class="register-card p-4 p-md-5">
      
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
        <p class="text-muted mt-2 fs-7"><?php echo __('register_subtitle', 'Join thousands of students and instructors on Computerscience.lk.'); ?></p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="bi bi-check-circle-fill me-2"></i>
          <?php echo htmlspecialchars($success); ?>
          <div class="mt-2">
            <a href="login.php" class="btn btn-sm btn-success text-white"><?php echo __('sign_in_instead', 'Sign In instead'); ?></a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Register Form -->
      <?php if (empty($success)): ?>
        <form action="register.php" method="POST" enctype="multipart/form-data" id="register-form">
          <!-- Role Selection Toggle -->
          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary d-block"><?php echo __('account_type', 'I am registering as'); ?></label>
            <div class="btn-group w-100" role="group" aria-label="Role selection button group">
              <input type="radio" class="btn-check" name="role" id="role-student" value="student" checked autocomplete="off">
              <label class="btn btn-outline-primary py-2 fw-semibold" for="role-student" style="font-size: 0.85rem;">
                <i class="bi bi-mortarboard me-1"></i> <?php echo __('student', 'Student'); ?>
              </label>
              
              <input type="radio" class="btn-check" name="role" id="role-teacher" value="teacher" autocomplete="off">
              <label class="btn btn-outline-primary py-2 fw-semibold" for="role-teacher" style="font-size: 0.85rem;">
                <i class="bi bi-person-workspace me-1"></i> <?php echo __('teacher', 'Teacher / Instructor'); ?>
              </label>
            </div>
          </div>

          <div class="mb-3">
            <label for="name" class="form-label fw-semibold text-secondary"><?php echo __('full_name', 'Full Name'); ?></label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
              <input type="text" name="name" id="name" class="form-control border-start-0 bg-light" placeholder="e.g. Devin Perera" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>
          </div>

          <!-- Teacher fields (dynamically shown) -->
          <div id="teacher-fields" style="display: none;">
            <div class="mb-3">
              <label for="bio" class="form-label fw-semibold text-secondary">Bio</label>
              <textarea name="bio" id="bio" class="form-control bg-light" placeholder="Brief biography..." rows="3"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
            </div>

            <div class="mb-3">
              <label for="subject" class="form-label fw-semibold text-secondary"><?php echo __('subject_specialization', 'Subject Specialization'); ?></label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-book text-muted"></i></span>
                <input type="text" name="subject" id="subject" class="form-control border-start-0 bg-light" placeholder="e.g. Computer Science, Web Development" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
              </div>
            </div>

            <div class="mb-3">
              <label for="qualifications" class="form-label fw-semibold text-secondary"><?php echo __('qualifications', 'Academic Qualifications'); ?></label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-award text-muted"></i></span>
                <input type="text" name="qualifications" id="qualifications" class="form-control border-start-0 bg-light" placeholder="e.g. BSc in CS" value="<?php echo htmlspecialchars($_POST['qualifications'] ?? ''); ?>">
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label for="email" class="form-label fw-semibold text-secondary"><?php echo __('email_address', 'Email Address'); ?></label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
              <input type="email" name="email" id="email" class="form-control border-start-0 bg-light" placeholder="e.g. devin@gmail.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
          </div>

          <div class="mb-3">
            <label for="password" class="form-label fw-semibold text-secondary"><?php echo __('password', 'Password'); ?></label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
              <input type="password" name="password" id="password" class="form-control border-start-0 bg-light" placeholder="Min 6 characters" required>
            </div>
          </div>

          <div class="mb-3">
            <label for="confirm_password" class="form-label fw-semibold text-secondary"><?php echo __('confirm_password', 'Confirm New Password'); ?></label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-shield-check text-muted"></i></span>
              <input type="password" name="confirm_password" id="confirm_password" class="form-control border-start-0 bg-light" placeholder="Re-enter password" required>
            </div>
          </div>

          <!-- Profile Picture File Upload -->
          <div class="mb-4">
            <label for="avatar_file" class="form-label fw-semibold text-secondary d-block"><?php echo __('upload_slip_file', 'Upload Profile Picture'); ?></label>
            <input type="file" name="avatar_file" id="avatar_file" class="form-control bg-light" accept="image/*">
          </div>

          <button type="submit" class="btn w-100 py-2.5 text-white fw-bold border-0 shadow-sm transition-all hover:brightness-110" style="background-color: #0f4c81;">
            <?php echo __('create_account_btn', 'Create Free Account'); ?>
          </button>
        </form>
      <?php endif; ?>

      <!-- Login Link -->
      <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
        <span class="text-muted fs-8"><?php echo __('already_have_account', 'Already have an account?'); ?></span>
        <a href="login.php" class="fw-bold text-decoration-none ms-1 fs-8" style="color: #f26f21;"><?php echo __('sign_in_instead', 'Sign In'); ?></a>
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
        if (roleTeacher.checked) {
          teacherFields.style.display = 'block';
          bioInput.required = true;
          subjectInput.required = true;
          qualificationsInput.required = true;
        } else {
          teacherFields.style.display = 'none';
          bioInput.required = false;
          subjectInput.required = false;
          qualificationsInput.required = false;
          
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
