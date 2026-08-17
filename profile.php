<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$is_teacher = false;

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();

    if (!$student) {
        // Session user not found, clear and redirect
        session_destroy();
        header("Location: login.php");
        exit;
    }

    if ($student['role'] === 'teacher' && $student['status'] === 'pending') {
        header("Location: pending_approval.php");
        exit;
    }

    // Redirect admins & super_admins to Admin Panel (Profile changes in review mode are disabled)
    if (in_array($student['role'] ?? '', ['admin', 'super_admin'])) {
        header("Location: admin/index.php");
        exit;
    }

    $is_teacher = (($student['role'] ?? 'student') === 'teacher');

    // Fetch notifications
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();

    // Fetch enrolled/teaching course lists for sidebar
    if ($is_teacher) {
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE tutor_id = ?");
        $stmt->execute([$user_id]);
        $teacher_courses = $stmt->fetchAll();
        $courses_count = count($teacher_courses);

        $stmt = $pdo->prepare("SELECT COUNT(e.user_id) FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE c.tutor_id = ?");
        $stmt->execute([$user_id]);
        $total_students_enrolled = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $enrolled_count = count($enrolled_ids);

        $stmt = $pdo->query("SELECT * FROM courses WHERE status = 'approved'");
        $all_courses = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM completed_lessons WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $completed_lessons_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_results WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $quizzes_completed_count = $stmt->fetchColumn();
    }

} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

$error = '';
$success = '';

// Handle Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $nic = trim($_POST['nic'] ?? '');
        $dob = trim($_POST['dob'] ?? '');
        $subject = $is_teacher ? trim($_POST['subject'] ?? '') : ($student['subject'] ?? '');
        $qualifications = $is_teacher ? trim($_POST['qualifications'] ?? '') : ($student['qualifications'] ?? '');

        if (empty($name) || empty($email)) {
            $error = 'Name and Email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check email uniqueness
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetchColumn() > 0) {
                    $error = 'This email is already registered by another user.';
                } else {
                    $avatar = $student['avatar'];
                    // Handle file upload
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
                                $error = 'Failed to move uploaded file.';
                            }
                        } else {
                            $error = 'Allowed image formats: ' . implode(', ', $allowedfileExtensions);
                        }
                    }

                    if (empty($error)) {
                        $update_stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, avatar = ?, bio = ?, nic = ?, dob = ?, subject = ?, qualifications = ? WHERE id = ?");
                        $update_stmt->execute([
                            $name,
                            $email,
                            $avatar,
                            empty($bio) ? null : $bio,
                            empty($nic) ? null : $nic,
                            empty($dob) ? null : $dob,
                            empty($subject) ? null : $subject,
                            empty($qualifications) ? null : $qualifications,
                            $user_id
                        ]);

                        // Refresh student data
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $student = $stmt->fetch();

                        $success = 'Profile details updated successfully!';
                    }
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill out all fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif (!password_verify($current_password, $student['password_hash'])) {
            $error = 'Incorrect current password.';
        } else {
            try {
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update_stmt->execute([$new_hash, $user_id]);

                $success = 'Password changed successfully!';
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_account') {
        $delete_email = trim($_POST['delete_email'] ?? '');
        $delete_password = $_POST['delete_password'] ?? '';

        if (empty($delete_email) || empty($delete_password)) {
            $error = 'Please fill out all verification fields.';
        } elseif (strtolower($delete_email) !== strtolower($student['email'])) {
            $error = 'Verification email does not match your account email.';
        } elseif (!password_verify($delete_password, $student['password_hash'])) {
            $error = 'Incorrect password verification.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt1 = $pdo->prepare("DELETE FROM enrollments WHERE user_id = ?");
                $stmt1->execute([$user_id]);

                $stmt2 = $pdo->prepare("DELETE FROM completed_lessons WHERE user_id = ?");
                $stmt2->execute([$user_id]);

                $stmt3 = $pdo->prepare("DELETE FROM quiz_results WHERE user_id = ?");
                $stmt3->execute([$user_id]);

                $stmt4 = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt4->execute([$user_id]);

                $stmt5 = $pdo->prepare("DELETE FROM bank_payments WHERE user_id = ?");
                $stmt5->execute([$user_id]);

                $stmt6 = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt6->execute([$user_id]);

                $pdo->commit();

                // Clear session and redirect
                session_destroy();
                init_lms_session();
                $_SESSION['registration_success'] = 'Your account has been deleted successfully.';
                header("Location: login.php");
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to delete account: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Profile | Computerscience.lk</title>
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
    .no-caret::after {
      display: none !important;
    }
    .profile-avatar-wrapper {
      position: relative;
      display: inline-block;
    }
    .profile-avatar-edit-badge {
      position: absolute;
      bottom: 0;
      right: 0;
      background: var(--moodle-primary);
      color: white;
      border-radius: 50%;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      border: 2px solid white;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      transition: all 0.2s ease;
    }
    .profile-avatar-edit-badge:hover {
      background: var(--moodle-accent);
      transform: scale(1.1);
    }
  </style>
</head>
<body class="bg-light">

  <!-- Moodle Top Header Bar -->
  <header class="moodle-header px-3 px-md-4 shadow-sm">
    <div class="d-flex align-items-center w-100 justify-content-between">
      
      <!-- Left: Toggle button + Brand -->
      <div class="d-flex align-items-center gap-3">
        <button id="drawer-toggle" class="btn btn-light border-0 rounded-circle p-2 fs-5 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
          <i class="bi bi-list"></i>
        </button>
        <a class="moodle-brand fw-bold text-decoration-none fs-4 d-flex align-items-center" href="index.php" style="color: #0f4c81;">
          <img src="<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2" style="height: 32px; width: auto; object-fit: contain;">computerscience.lk
        </a>
      </div>

      <!-- Center: Main Navbar links -->
      <nav class="d-none d-lg-flex align-items-center gap-2">
        <a href="index.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_home', 'Site Home'); ?></a>
        <a href="dashboard.php" class="btn btn-light px-3 text-secondary"><?php echo __('nav_dashboard', 'Dashboard'); ?></a>
        <a href="my_courses.php" class="btn btn-light px-3 text-secondary"><?php echo $is_teacher ? __('nav_uploaded_courses', 'Uploaded Courses') : __('nav_my_courses', 'My Courses'); ?></a>
        <?php if (isset($_SESSION['user_id'])): ?>
          <a href="live_classes.php" class="btn btn-light px-3 text-danger fw-semibold d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-broadcast text-danger fs-7"></i>
            <span>Live Classes</span>
          </a>
        <?php endif; ?>
      </nav>

      <!-- Right: Actions, Notifications, Profiles -->
      <div class="d-flex align-items-center gap-2.5">
        <!-- Language Switcher Dropdown -->
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

        <!-- Notification Dropdown -->
        <div class="dropdown">
          <button class="text-secondary fs-5 border-0 bg-transparent p-2 position-relative dropdown-toggle no-caret" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotificationsAsRead()">
            <i class="bi bi-bell"></i>
            <?php if ($unread_count > 0): ?>
              <span class="position-absolute top-1 end-1 translate-middle badge rounded-circle bg-danger" id="notification-badge" style="padding: 4px; font-size: 0.5rem;">
                <?php echo $unread_count; ?>
              </span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light py-2" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 400px; overflow-y: auto; z-index: 1050;">
            <li class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2 d-flex justify-content-between align-items-center">
              <span><?php echo __('notifications', 'Notifications'); ?></span>
              <?php if ($unread_count > 0): ?>
                <span class="badge bg-primary text-white fs-9" id="notification-count"><?php echo $unread_count; ?> new</span>
              <?php endif; ?>
            </li>
            <?php if (empty($notifications)): ?>
              <li class="px-3 py-4 text-center text-muted fs-8 italic"><?php echo __('no_notifications', 'No notifications yet.'); ?></li>
            <?php else: ?>
              <?php foreach ($notifications as $notif): ?>
                <li class="px-3 py-2 border-bottom last-border-0 <?php echo $notif['is_read'] ? 'opacity-70' : 'bg-light bg-opacity-50 fw-semibold'; ?>">
                  <div class="fs-8 text-dark mb-1"><?php echo htmlspecialchars($notif['message']); ?></div>
                  <small class="text-muted fs-9"><i class="bi bi-clock me-1"></i><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></small>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </div>
        <div class="dropdown">
          <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <img src="<?php echo htmlspecialchars(get_user_avatar($student['avatar'], $student['name'])); ?>" class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover;" alt="Profile">
            <span class="d-none d-md-inline text-secondary fw-semibold text-sm"><?php echo htmlspecialchars(explode(' ', $student['name'])[0]); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light">
            <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
            <li><a class="dropdown-item active" href="profile.php"><i class="bi bi-person me-2"></i> <?php echo __('nav_profile', 'Profile'); ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> <?php echo __('nav_logout', 'Logout'); ?></a></li>
          </ul>
        </div>
      </div>

    </div>
  </header>

  <!-- Moodle Left Navigation Drawer -->
  <aside id="moodle-drawer" class="moodle-drawer collapsed">
    <div class="d-flex flex-column">
      <a href="index.php" class="drawer-link">
        <i class="bi bi-house-door fs-5"></i> <?php echo __('nav_home', 'Site Home'); ?>
      </a>
      <a href="dashboard.php" class="drawer-link">
        <i class="bi bi-speedometer2 fs-5"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
      </a>
      <hr class="mx-3 my-2 border-secondary border-opacity-20">
      <div class="px-4 py-2 fs-7 fw-bold text-uppercase text-muted tracking-wider"><?php echo $is_teacher ? __('courses_i_teach', 'Courses I Teach') : __('nav_my_courses', 'My Courses'); ?></div>
      <?php 
      $enrolled_any = false;
      if ($is_teacher) {
          foreach ($teacher_courses as $cs_course) {
              $enrolled_any = true;
              echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                      <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                    </a>';
          }
          if (!$enrolled_any) {
              echo '<div class="px-4 py-2 fs-8 text-muted italic">' . __('no_courses_created_yet', 'No courses created yet') . '</div>';
          }
      } else {
          foreach ($all_courses as $cs_course) {
              if (in_array($cs_course['id'], $enrolled_ids)) {
                  $enrolled_any = true;
                  echo '<a href="classroom.php?course_id=' . htmlspecialchars($cs_course['id']) . '" class="drawer-link py-2 fs-7 text-truncate">
                          <i class="bi bi-book me-2"></i> ' . htmlspecialchars(__($cs_course['title'], $cs_course['title'])) . '
                        </a>';
              }
          }
          if (!$enrolled_any) {
              echo '<div class="px-4 py-2 fs-8 text-muted italic">' . __('no_enrolled_courses', 'No enrolled courses') . '</div>';
          }
      }
      ?>
    </div>
  </aside>

  <!-- Moodle Main Content Area wrapper -->
  <main id="moodle-content-wrapper" class="moodle-content-wrapper full-width">
    <div class="container py-4 px-md-5">

      <!-- Breadcrumbs -->
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb moodle-breadcrumb">
          <li class="breadcrumb-item"><a href="index.php"><?php echo __('nav_home', 'Home'); ?></a></li>
          <li class="breadcrumb-item"><a href="dashboard.php"><?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
          <li class="breadcrumb-item active" aria-current="page"><?php echo __('nav_profile', 'Profile'); ?></li>
        </ol>
      </nav>

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
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Main Profile Row -->
      <div class="row g-4">
        
        <!-- Left Column: Avatar & Summary Stats -->
        <div class="col-lg-4">
          <div class="moodle-card p-4 text-center">
            <!-- Profile Photo Upload trigger -->
            <form action="profile.php" method="POST" enctype="multipart/form-data" id="avatarForm">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="name" value="<?php echo htmlspecialchars($student['name']); ?>">
              <input type="hidden" name="email" value="<?php echo htmlspecialchars($student['email']); ?>">
              <input type="hidden" name="bio" value="<?php echo htmlspecialchars($student['bio'] ?? ''); ?>">
              <input type="hidden" name="nic" value="<?php echo htmlspecialchars($student['nic'] ?? ''); ?>">
              <input type="hidden" name="dob" value="<?php echo htmlspecialchars($student['dob'] ?? ''); ?>">
              <input type="hidden" name="subject" value="<?php echo htmlspecialchars($student['subject'] ?? ''); ?>">
              <input type="hidden" name="qualifications" value="<?php echo htmlspecialchars($student['qualifications'] ?? ''); ?>">

              <div class="profile-avatar-wrapper mb-3">
                <?php
                $display_avatar = get_user_avatar($student['avatar'], $student['name']);
                ?>
                <img src="<?php echo htmlspecialchars($display_avatar); ?>" class="rounded-circle border border-primary border-opacity-20 mx-auto" style="width: 140px; height: 140px; object-fit: cover;" alt="Avatar" id="avatarPreview">
                <label for="avatar_file_input" class="profile-avatar-edit-badge">
                  <i class="bi bi-camera-fill"></i>
                </label>
                <input type="file" name="avatar_file" id="avatar_file_input" class="d-none" accept="image/*" onchange="previewAndSubmitAvatar()">
              </div>
            </form>

            <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($student['name']); ?></h4>
            <p class="text-muted fs-8 mb-3"><?php echo htmlspecialchars($student['email']); ?></p>

            <div class="d-flex gap-2 justify-content-center mb-4">
              <span class="badge bg-light text-secondary border px-2 py-1 fs-8">
                <?php echo $is_teacher ? __('lecturer_id', 'Lecturer ID: ') : __('academic_id_label', 'Academic ID: '); ?><?php echo htmlspecialchars($student['academic_id']); ?>
              </span>
              <span class="badge bg-<?php echo $is_teacher ? 'primary' : 'success'; ?> bg-opacity-10 text-<?php echo $is_teacher ? 'primary' : 'success'; ?> border border-<?php echo $is_teacher ? 'primary' : 'success'; ?> border-opacity-30 px-2 py-1 fs-8">
                <?php echo $is_teacher ? __('teacher', 'Teacher') : __('student', 'Student'); ?>
              </span>
            </div>

            <!-- Professional Profile Section -->
            <div class="text-start border-top pt-3 mt-3">
              <h6 class="fw-bold text-dark mb-2 fs-7"><i class="bi bi-person-badge text-primary me-2"></i><?php echo __('profile_credentials', 'Profile Credentials'); ?></h6>
              <div class="mb-2 fs-8">
                <span class="text-secondary fw-semibold"><?php echo __('nic', 'NIC'); ?>:</span>
                <span class="text-dark float-end"><?php echo htmlspecialchars($student['nic'] ?? __('not_provided', 'Not provided')); ?></span>
              </div>
              <div class="mb-2 fs-8">
                <span class="text-secondary fw-semibold"><?php echo __('date_of_birth', 'Date of Birth'); ?>:</span>
                <span class="text-dark float-end"><?php echo $student['dob'] ? date('M d, Y', strtotime($student['dob'])) : __('not_provided', 'Not provided'); ?></span>
              </div>
              <div class="mb-2 fs-8">
                <span class="text-secondary fw-semibold"><?php echo __('member_since', 'Member Since'); ?>:</span>
                <span class="text-dark float-end"><?php echo date('M Y', strtotime($student['created_at'])); ?></span>
              </div>
            </div>

            <!-- Dashboard Stats Overview -->
            <div class="mt-4 pt-3 border-top border-secondary border-opacity-10 row text-center">
              <?php if ($is_teacher): ?>
                <div class="col-6 border-end">
                  <h4 class="fw-bold mb-0 text-primary fs-5"><?php echo $courses_count; ?></h4>
                  <small class="text-muted fs-9 text-uppercase"><?php echo __('modules_taught', 'Modules Taught'); ?></small>
                </div>
                <div class="col-6">
                  <h4 class="fw-bold mb-0 text-orange fs-5"><?php echo $total_students_enrolled; ?></h4>
                  <small class="text-muted fs-9 text-uppercase"><?php echo __('total_students', 'Total Students'); ?></small>
                </div>
              <?php else: ?>
                <div class="col-4 border-end px-1">
                  <h4 class="fw-bold mb-0 text-primary fs-5"><?php echo $enrolled_count; ?></h4>
                  <small class="text-muted fs-9 text-uppercase"><?php echo __('enrolled', 'Enrolled'); ?></small>
                </div>
                <div class="col-4 border-end px-1">
                  <h4 class="fw-bold mb-0 text-success fs-5"><?php echo $completed_lessons_count; ?></h4>
                  <small class="text-muted fs-9 text-uppercase"><?php echo __('completed', 'Completed'); ?></small>
                </div>
                <div class="col-4 px-1">
                  <h4 class="fw-bold mb-0 text-orange fs-5"><?php echo $quizzes_completed_count; ?></h4>
                  <small class="text-muted fs-9 text-uppercase"><?php echo __('quizzes', 'Quizzes'); ?></small>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Right Column: Settings Forms -->
        <div class="col-lg-8">
          <div class="moodle-card p-4">
            
            <!-- Quick Tab Headers -->
            <div class="d-flex border-bottom mb-4">
              <button class="moodle-tab-btn active" id="tab-profile-details" onclick="switchTab('profile-details')">
                <i class="bi bi-person-gear me-2"></i><?php echo __('edit_profile_details', 'Edit Profile Details'); ?>
              </button>
              <button class="moodle-tab-btn" id="tab-security" onclick="switchTab('security')">
                <i class="bi bi-shield-lock me-2"></i><?php echo __('security_and_password', 'Security & Password'); ?>
              </button>
            </div>

            <!-- Tab Content 1: Update Details -->
            <div class="tab-pane" id="pane-profile-details">
              <form action="profile.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                
                <h5 class="fw-bold text-dark mb-3 fs-6"><i class="bi bi-card-text text-primary me-2"></i><?php echo __('personal_information', 'Personal Information'); ?></h5>
                <div class="row g-3 mb-4">
                  <div class="col-md-6">
                    <label for="profile-name" class="form-label fw-semibold text-secondary fs-8"><?php echo __('full_name', 'Full Name'); ?></label>
                    <input type="text" name="name" id="profile-name" class="form-control" placeholder="Your Name" value="<?php echo htmlspecialchars($student['name']); ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label for="profile-email" class="form-label fw-semibold text-secondary fs-8"><?php echo __('email_address', 'Email Address'); ?></label>
                    <input type="email" name="email" id="profile-email" class="form-control" placeholder="name@domain.com" value="<?php echo htmlspecialchars($student['email']); ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label for="profile-nic" class="form-label fw-semibold text-secondary fs-8"><?php echo __('nic_number', 'National Identity Card (NIC)'); ?></label>
                    <input type="text" name="nic" id="profile-nic" class="form-control" placeholder="e.g. 199912345678 or 991234567V" value="<?php echo htmlspecialchars($student['nic'] ?? ''); ?>">
                  </div>
                  <div class="col-md-6">
                    <label for="profile-dob" class="form-label fw-semibold text-secondary fs-8"><?php echo __('date_of_birth', 'Date of Birth'); ?></label>
                    <input type="date" name="dob" id="profile-dob" class="form-control" value="<?php echo htmlspecialchars($student['dob'] ?? ''); ?>">
                  </div>
                </div>

                <h5 class="fw-bold text-dark mb-3 fs-6 border-top pt-3"><i class="bi bi-file-person text-primary me-2"></i><?php echo __('professional_info_bio', 'Professional Info & Bio'); ?></h5>
                <div class="row g-3 mb-4">
                  <?php if ($is_teacher): ?>
                    <div class="col-md-6">
                      <label for="profile-subject" class="form-label fw-semibold text-secondary fs-8"><?php echo __('subject_specialization_dept', 'Subject Specialization / Department'); ?></label>
                      <input type="text" name="subject" id="profile-subject" class="form-control" placeholder="e.g. Software Engineering" value="<?php echo htmlspecialchars($student['subject'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                      <label for="profile-qualifications" class="form-label fw-semibold text-secondary fs-8"><?php echo __('qualifications_batch_info', 'Qualifications / Batch Info'); ?></label>
                      <input type="text" name="qualifications" id="profile-qualifications" class="form-control" placeholder="e.g. UCSC BIT Year 2 student" value="<?php echo htmlspecialchars($student['qualifications'] ?? ''); ?>">
                    </div>
                  <?php endif; ?>
                  <div class="col-12">
                    <label for="profile-bio" class="form-label fw-semibold text-secondary fs-8"><?php echo $is_teacher ? __('biography_summary', 'Biography / Professional Summary') : __('biography_about_me', 'Biography / About Me'); ?></label>
                    <textarea name="bio" id="profile-bio" class="form-control" rows="4" placeholder="<?php echo $is_teacher ? 'Brief details about your academic interests, career goals or teaching experience...' : 'Tell us a bit about yourself...'; ?>"><?php echo htmlspecialchars($student['bio'] ?? ''); ?></textarea>
                  </div>
                </div>

                <div class="text-end pt-2 border-top">
                  <button type="submit" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--moodle-primary); border: none;">
                    <?php echo __('save_profile_changes', 'Save Profile Changes'); ?>
                  </button>
                </div>
              </form>
            </div>

            <!-- Tab Content 2: Change Password -->
            <div class="tab-pane" id="pane-security" style="display: none;">
              <form action="profile.php" method="POST" id="passwordForm">
                <input type="hidden" name="action" value="change_password">
                
                <h5 class="fw-bold text-dark mb-3 fs-6"><i class="bi bi-key-fill text-primary me-2"></i><?php echo __('change_system_password', 'Change System Password'); ?></h5>
                <div class="row g-3 mb-4">
                  <div class="col-12">
                    <label for="current-password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('current_password', 'Current Password'); ?></label>
                    <input type="password" name="current_password" id="current-password" class="form-control" placeholder="Enter your current password" required>
                  </div>
                  <div class="col-md-6">
                    <label for="new-password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('new_password', 'New Password'); ?></label>
                    <input type="password" name="new_password" id="new-password" class="form-control" placeholder="Minimum 6 characters" required>
                  </div>
                  <div class="col-md-6">
                    <label for="confirm-password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('confirm_new_password', 'Confirm New Password'); ?></label>
                    <input type="password" name="confirm_password" id="confirm-password" class="form-control" placeholder="Re-type new password" required>
                  </div>
                </div>

                <div class="text-end pt-2 border-top">
                  <button type="submit" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--moodle-primary); border: none;">
                    <?php echo __('update_password', 'Update Password'); ?>
                  </button>
                </div>
              </form>

              <!-- Delete Account Section -->
              <div class="border-top mt-5 pt-4">
                <h5 class="fw-bold text-danger mb-2 fs-6"><i class="bi bi-person-x-fill me-2"></i><?php echo __('delete_account', 'Delete Account'); ?></h5>
                <p class="text-muted fs-8 mb-3"><?php echo __('delete_account_warning', 'Permanently delete your account. This action is irreversible and all your data (enrolments, completions, settings) will be deleted permanently.'); ?></p>
                
                <form action="profile.php" method="POST" onsubmit="return confirmDeleteAccount(event)">
                  <input type="hidden" name="action" value="delete_account">
                  
                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label for="delete-email" class="form-label fw-semibold text-secondary fs-8"><?php echo __('email_address', 'Confirm Email Address'); ?></label>
                      <input type="email" name="delete_email" id="delete-email" class="form-control" placeholder="Type your email to confirm" required>
                    </div>
                    <div class="col-md-6">
                      <label for="delete-password" class="form-label fw-semibold text-secondary fs-8"><?php echo __('password', 'Verify Password'); ?></label>
                      <input type="password" name="delete_password" id="delete-password" class="form-control" placeholder="Enter your password" required>
                    </div>
                  </div>
                  
                  <div class="text-end">
                    <button type="submit" class="btn btn-danger px-4 fw-semibold border-0">
                      <?php echo __('confirm_delete_account_btn', 'Permanently Delete Account'); ?>
                    </button>
                  </div>
                </form>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>
  </main>

  <!-- Footer -->
  <footer class="py-4 bg-dark text-white-50 border-t mt-5">
    <div class="container text-center">
      <p class="mb-1 text-white">Computerscience.lk LMS</p>
      <p class="fs-7 mb-0">Powered by Moodle Core UI Framework re-design &copy; 2026.</p>
    </div>
  </footer>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  
  <!-- Navigation Drawer Toggle and Profile tabs scripts -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Toggle Navigation Drawer
      const toggleBtn = document.getElementById('drawer-toggle');
      const drawer = document.getElementById('moodle-drawer');
      const wrapper = document.getElementById('moodle-content-wrapper');
      
      if (toggleBtn && drawer && wrapper) {
        toggleBtn.addEventListener('click', function() {
          drawer.classList.toggle('collapsed');
          wrapper.classList.toggle('full-width');
        });
      }

      // Password matching validation
      const passForm = document.getElementById('passwordForm');
      const newPass = document.getElementById('new-password');
      const confirmPass = document.getElementById('confirm-password');
      if (passForm) {
        passForm.addEventListener('submit', function(e) {
          if (newPass.value !== confirmPass.value) {
            e.preventDefault();
            alert('New passwords do not match!');
            confirmPass.focus();
          }
        });
      }
    });

    // Delete account double confirmation prompt
    function confirmDeleteAccount(event) {
      const confirmFirst = confirm("WARNING: Deleting your account is permanent and cannot be undone. Are you absolutely sure you want to proceed?");
      if (!confirmFirst) {
        event.preventDefault();
        return false;
      }
      return true;
    }

    // Tab Switcher
    function switchTab(tabId) {
      // Hide all panes
      document.getElementById('pane-profile-details').style.display = 'none';
      document.getElementById('pane-security').style.display = 'none';
      
      // Deactivate all tab buttons
      document.getElementById('tab-profile-details').classList.remove('active');
      document.getElementById('tab-security').classList.remove('active');
      
      // Show targeted pane and active button
      if (tabId === 'profile-details') {
        document.getElementById('pane-profile-details').style.display = 'block';
        document.getElementById('tab-profile-details').classList.add('active');
      } else if (tabId === 'security') {
        document.getElementById('pane-security').style.display = 'block';
        document.getElementById('tab-security').classList.add('active');
      }
    }

    // Preview and auto submit avatar
    function previewAndSubmitAvatar() {
      const fileInput = document.getElementById('avatar_file_input');
      const avatarPreview = document.getElementById('avatarPreview');
      const avatarForm = document.getElementById('avatarForm');

      if (fileInput.files && fileInput.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
          avatarPreview.src = e.target.result;
          // Auto submit the form to save the uploaded image
          avatarForm.submit();
        };
        reader.readAsDataURL(fileInput.files[0]);
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

    // Mark notifications read function
    function markNotificationsAsRead() {
      fetch('api/read_notifications.php')
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const badge = document.getElementById('notification-badge');
            if (badge) badge.remove();
            const count = document.getElementById('notification-count');
            if (count) count.remove();
          }
        })
        .catch(err => console.error('Error marking notifications read:', err));
    }
  </script>
</body>
</html>
