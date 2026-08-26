<?php
session_name('LMS_ADMIN_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
session_start();
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/google_oauth.php';

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

try {
  $pdo = getDBConnection();
  $userStmt = $pdo->prepare("SELECT id, name, email, avatar, role, status, password_hash FROM users WHERE id = ?");
  $userStmt->execute([$_SESSION['user_id']]);
  $logged_in_user = $userStmt->fetch();
  if ($logged_in_user) {
    $_SESSION['user_role'] = $logged_in_user['role'];
    $_SESSION['user_name'] = $logged_in_user['name'];
    $_SESSION['user_email'] = $logged_in_user['email'];
    $_SESSION['user_avatar'] = $logged_in_user['avatar'];

    // 1. Enforce Status Check: If account is deactivated, log out immediately
    if (strtolower($logged_in_user['status'] ?? 'active') !== 'active' && $logged_in_user['role'] !== 'super_admin') {
      session_destroy();
      header("Location: login.php?error=deactivated");
      exit;
    }

    // 2. Enforce Password Change Check: If password was reset by Super Admin, log out immediately
    if (isset($_SESSION['session_password_hash']) && $_SESSION['session_password_hash'] !== $logged_in_user['password_hash'] && $logged_in_user['role'] !== 'super_admin') {
      session_destroy();
      header("Location: login.php?error=password_changed");
      exit;
    }

    if (!isset($_SESSION['session_password_hash'])) {
      $_SESSION['session_password_hash'] = $logged_in_user['password_hash'];
    }
  } else {
    session_destroy();
    header("Location: login.php?error=account_not_found");
    exit;
  }
} catch (PDOException $e) {
  // Continue
}

$user_role = $_SESSION['user_role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'super_admin') {
  header("Location: login.php");
  exit;
}
$is_super_admin = ($user_role === 'super_admin');

$current_admin_name = $_SESSION['user_name'] ?? ($logged_in_user['name'] ?? 'System Admin');
$current_admin_email = $_SESSION['user_email'] ?? ($logged_in_user['email'] ?? 'admin@computerscience.lk');
$raw_avatar = $_SESSION['user_avatar'] ?? ($logged_in_user['avatar'] ?? '');

if (empty($raw_avatar)) {
  $current_admin_avatar = 'https://ui-avatars.com/api/?name=' . urlencode($current_admin_name) . '&background=0f4c81&color=fff';
} elseif (preg_match('~^https?://~i', $raw_avatar) || strpos($raw_avatar, 'data:') === 0) {
  $current_admin_avatar = $raw_avatar;
} else {
  $current_admin_avatar = '../' . ltrim($raw_avatar, '/');
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success_message = '';
$error_message = '';

if (!empty($_SESSION['flash_success'])) {
  $success_message = $_SESSION['flash_success'];
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
  $error_message = $_SESSION['flash_error'];
  unset($_SESSION['flash_error']);
}

try {
  $pdo = getDBConnection();

  // Handle teacher approval / status toggle action (Active / Inactive)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'approve' || $_POST['action'] === 'toggle_teacher_status')) {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? 'active'));
    if (!in_array($new_status, ['active', 'inactive'])) {
      $new_status = 'active';
    }
    if ($target_user_id > 0) {
      $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'teacher'");
      $stmt->execute([$new_status, $target_user_id]);

      $notifMsg = ($new_status === 'active')
        ? "Your Teacher account registration / status has been set to ACTIVE by the administrator."
        : "Your Teacher account status has been set to INACTIVE by the administrator.";

      $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
      $notifStmt->execute([$target_user_id, $notifMsg]);

      $success_message = 'Teacher account status updated to ' . strtoupper($new_status) . ' successfully!';
    } else {
      $error_message = 'Invalid user ID.';
    }
  }

  // Handle teacher deletion with Admin Account Password Confirmation
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_teacher') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $admin_password = $_POST['admin_password'] ?? '';

    if (empty($admin_password)) {
      $error_message = 'Admin account password confirmation is required to delete a teacher account.';
    } elseif ($target_user_id > 0) {
      // Verify logged-in admin password
      $adminStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
      $adminStmt->execute([$_SESSION['user_id']]);
      $adminData = $adminStmt->fetch();

      if ($adminData && password_verify($admin_password, $adminData['password_hash'])) {
        try {
          // Delete related notifications first
          $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$target_user_id]);
          // Delete teacher account
          $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
          $deleteStmt->execute([$target_user_id]);
          $success_message = 'Teacher account deleted successfully.';
        } catch (PDOException $ex) {
          $error_message = 'Unable to delete teacher account because they have active courses or transactions attached.';
        }
      } else {
        $error_message = 'Invalid admin account password. Teacher account deletion cancelled.';
      }
    } else {
      $error_message = 'Invalid teacher user ID.';
    }
  }

  // Handle sending custom notification message to teacher
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_teacher_notification') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $custom_message = trim($_POST['message'] ?? '');

    if ($target_user_id > 0 && !empty($custom_message)) {
      $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
      $stmt->execute([$target_user_id, $custom_message]);

      // Retrieve teacher name for alert
      $tStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
      $tStmt->execute([$target_user_id]);
      $teacherName = $tStmt->fetchColumn() ?: 'Teacher';

      $success_message = 'Notification message sent to ' . htmlspecialchars($teacherName) . '\'s account notification bell successfully!';
    } else {
      $error_message = 'Please provide a valid notification message content.';
    }
  }

  // Handle student status toggle action (Active / Inactive)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_student_status') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? 'active'));
    if (!in_array($new_status, ['active', 'inactive'])) {
      $new_status = 'active';
    }
    if ($target_user_id > 0) {
      $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'student'");
      $stmt->execute([$new_status, $target_user_id]);

      $notifMsg = ($new_status === 'active')
        ? "Your Student account status has been set to ACTIVE by the administrator."
        : "Your Student account status has been set to INACTIVE by the administrator.";

      $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
      $notifStmt->execute([$target_user_id, $notifMsg]);

      $success_message = 'Student account status updated to ' . strtoupper($new_status) . ' successfully!';
    } else {
      $error_message = 'Invalid student user ID.';
    }
  }

  // Handle sending custom notification message to student
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_student_notification') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $custom_message = trim($_POST['message'] ?? '');

    if ($target_user_id > 0 && !empty($custom_message)) {
      $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
      $stmt->execute([$target_user_id, $custom_message]);

      $sStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
      $sStmt->execute([$target_user_id]);
      $studentName = $sStmt->fetchColumn() ?: 'Student';

      $success_message = 'Notification message sent to student ' . htmlspecialchars($studentName) . '\'s notification bell successfully!';
    } else {
      $error_message = 'Please provide a valid notification message content.';
    }
  }

  // Handle student account deletion with Admin Account Password Confirmation
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_student') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $admin_password = $_POST['admin_password'] ?? '';

    if (empty($admin_password)) {
      $error_message = 'Admin account password confirmation is required to delete a student account.';
    } elseif ($target_user_id > 0) {
      // Verify logged-in admin password
      $adminStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
      $adminStmt->execute([$_SESSION['user_id']]);
      $adminData = $adminStmt->fetch();

      if ($adminData && password_verify($admin_password, $adminData['password_hash'])) {
        try {
          $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$target_user_id]);
          $pdo->prepare("DELETE FROM enrollments WHERE user_id = ?")->execute([$target_user_id]);
          $pdo->prepare("DELETE FROM quiz_results WHERE user_id = ?")->execute([$target_user_id]);
          $pdo->prepare("DELETE FROM bank_payments WHERE user_id = ?")->execute([$target_user_id]);

          $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
          $deleteStmt->execute([$target_user_id]);
          $success_message = 'Student account deleted successfully.';
        } catch (PDOException $ex) {
          $error_message = 'Unable to delete student account: ' . $ex->getMessage();
        }
      } else {
        $error_message = 'Invalid admin account password. Student account deletion cancelled.';
      }
    } else {
      $error_message = 'Invalid student user ID.';
    }
  }

  // Handle course approval/rejection action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'approve_course' || $_POST['action'] === 'reject_course')) {
    $target_course_id = trim($_POST['course_id'] ?? '');
    if (!empty($target_course_id)) {
      $status = ($_POST['action'] === 'approve_course') ? 'approved' : 'rejected';

      // Retrieve course info to notify the instructor
      $courseStmt = $pdo->prepare("SELECT tutor_id, title FROM courses WHERE id = ?");
      $courseStmt->execute([$target_course_id]);
      $courseData = $courseStmt->fetch();

      $stmt = $pdo->prepare("UPDATE courses SET status = ? WHERE id = ?");
      $stmt->execute([$status, $target_course_id]);

      if ($courseData && !empty($courseData['tutor_id'])) {
        if ($status === 'approved') {
          $notifMsg = "Your course / syllabus update for '" . $courseData['title'] . "' has been APPROVED by the administrator and is now live on the platform.";
        } else {
          $notifMsg = "Your course / syllabus update for '" . $courseData['title'] . "' has been REJECTED by the administrator. Please review your course materials.";
        }
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $notifStmt->execute([$courseData['tutor_id'], $notifMsg]);
      }

      $success_message = ($status === 'approved')
        ? 'Course has been successfully approved and published!'
        : 'Course has been rejected.';
    } else {
      $error_message = 'Invalid course ID.';
    }
  }

  // Handle bank slip payment approval/rejection action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'approve_slip' || $_POST['action'] === 'reject_slip')) {
    $slip_id = intval($_POST['slip_id'] ?? 0);
    if ($slip_id > 0) {
      $status = ($_POST['action'] === 'approve_slip') ? 'approved' : 'rejected';

      $pdo->beginTransaction();

      $stmt = $pdo->prepare("UPDATE bank_payments SET status = ? WHERE id = ?");
      $stmt->execute([$status, $slip_id]);

      // Get detailed slip info including course title
      $stmt = $pdo->prepare("SELECT bp.*, c.title as course_title FROM bank_payments bp JOIN courses c ON bp.course_id = c.id WHERE bp.id = ?");
      $stmt->execute([$slip_id]);
      $slip = $stmt->fetch();

      if ($slip) {
        if ($status === 'approved') {
          $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?");
          $stmt->execute([$slip['user_id'], $slip['course_id']]);
          $already_enrolled = ($stmt->fetchColumn() > 0);

          if (!$already_enrolled) {
            $enrollStmt = $pdo->prepare("INSERT INTO enrollments (user_id, course_id) VALUES (?, ?)");
            $enrollStmt->execute([$slip['user_id'], $slip['course_id']]);
          }

          // Insert approval notification
          $notifyStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
          $notifyStmt->execute([$slip['user_id'], "Your bank slip payment for '" . $slip['course_title'] . "' has been APPROVED! You now have full access to the classroom."]);
        } else {
          // Insert rejection notification
          $notifyStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
          $notifyStmt->execute([$slip['user_id'], "Your bank slip payment for '" . $slip['course_title'] . "' has been REJECTED. Please check your bank slip details and upload a valid copy."]);
        }
      }

      $pdo->commit();

      $success_message = ($status === 'approved')
        ? 'Bank slip approved and student successfully enrolled!'
        : 'Bank slip has been rejected.';
    } else {
      $error_message = 'Invalid slip ID.';
    }
  }

  // Handle Add Bank Account action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_bank_account') {
    $bank_name = trim($_POST['bank_name'] ?? '');
    $branch = trim($_POST['branch'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $option_label = trim($_POST['option_label'] ?? 'Option');
    $status = trim($_POST['status'] ?? 'active');

    if (!empty($bank_name) && !empty($account_number) && !empty($account_name)) {
      $stmt = $pdo->prepare("INSERT INTO bank_accounts (bank_name, branch, account_number, account_name, option_label, status) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->execute([$bank_name, $branch, $account_number, $account_name, $option_label, $status]);
      $success_message = 'Bank account details successfully added!';
    } else {
      $error_message = 'Please fill in all required bank detail fields.';
    }
  }

  // Handle Edit Bank Account action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_bank_account') {
    $account_id = intval($_POST['account_id'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    $branch = trim($_POST['branch'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $option_label = trim($_POST['option_label'] ?? 'Option');
    $status = trim($_POST['status'] ?? 'active');

    if ($account_id > 0 && !empty($bank_name) && !empty($account_number) && !empty($account_name)) {
      $stmt = $pdo->prepare("UPDATE bank_accounts SET bank_name = ?, branch = ?, account_number = ?, account_name = ?, option_label = ?, status = ? WHERE id = ?");
      $stmt->execute([$bank_name, $branch, $account_number, $account_name, $option_label, $status, $account_id]);
      $success_message = 'Bank account details updated successfully!';
    } else {
      $error_message = 'Invalid bank account parameters.';
    }
  }

  // Handle Delete Bank Account action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_bank_account') {
    $account_id = intval($_POST['account_id'] ?? 0);
    if ($account_id > 0) {
      $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?");
      $stmt->execute([$account_id]);
      $success_message = 'Bank account details deleted successfully!';
    } else {
      $error_message = 'Invalid bank account ID.';
    }
  }

  // Handle Add Site Announcement action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_announcement') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $badge_text = trim($_POST['badge_text'] ?? '');
    $category = trim($_POST['category'] ?? 'notice');
    $status = trim($_POST['status'] ?? 'active');

    if (!empty($title) && !empty($content)) {
      $stmt = $pdo->prepare("INSERT INTO site_announcements (title, content, badge_text, category, status) VALUES (?, ?, ?, ?, ?)");
      $stmt->execute([$title, $content, $badge_text, $category, $status]);
      $success_message = 'Site announcement added successfully!';
    } else {
      $error_message = 'Please provide both title and content for the announcement.';
    }
  }

  // Handle Edit Site Announcement action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_announcement') {
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $badge_text = trim($_POST['badge_text'] ?? '');
    $category = trim($_POST['category'] ?? 'notice');
    $status = trim($_POST['status'] ?? 'active');

    if ($announcement_id > 0 && !empty($title) && !empty($content)) {
      $stmt = $pdo->prepare("UPDATE site_announcements SET title = ?, content = ?, badge_text = ?, category = ?, status = ? WHERE id = ?");
      $stmt->execute([$title, $content, $badge_text, $category, $status, $announcement_id]);
      $success_message = 'Site announcement updated successfully!';
    } else {
      $error_message = 'Invalid parameters for editing announcement.';
    }
  }

  // Handle Delete Site Announcement action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    if ($announcement_id > 0) {
      $stmt = $pdo->prepare("DELETE FROM site_announcements WHERE id = ?");
      $stmt->execute([$announcement_id]);
      $success_message = 'Site announcement deleted successfully!';
    } else {
      $error_message = 'Invalid announcement ID.';
    }
  }

  // Handle Add Promotional / Featured Announcement Banner action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_banner') {
    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $details_content = trim($_POST['details_content'] ?? '');
    $cta_button_text = trim($_POST['cta_button_text'] ?? '');
    $cta_button_url = trim($_POST['cta_button_url'] ?? '');
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $image_url_input = trim($_POST['image_url'] ?? '');

    $image_path = '';
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
    $upload_dir = __DIR__ . '/../uploads/banners';

    if (isset($_FILES['banner_image_file']) && $_FILES['banner_image_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['banner_image_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

      if (in_array($ext, $allowed)) {
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }
        $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          $image_path = 'uploads/banners/' . $filename;
        }
      } else {
        $error_message = 'Only JPG, JPEG, PNG, WEBP, SVG, and GIF image files are allowed.';
      }
    } elseif (!empty($image_url_input)) {
      $image_path = $image_url_input;
    }

    if (empty($error_message)) {
      if (!empty($title) && !empty($details_content) && !empty($image_path)) {
        // Auto-assign display order if 0
        if ($display_order === 0) {
          $stmt = $pdo->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM promotional_banners");
          $display_order = (int)$stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("INSERT INTO promotional_banners (title, subtitle, image_path, details_content, cta_button_text, cta_button_url, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $subtitle, $image_path, $details_content, $cta_button_text, $cta_button_url, $display_order, $is_active]);
        $success_message = 'Featured announcement added successfully!';
      } else {
        $error_message = 'Please provide a title, full details, and upload or provide an announcement image.';
      }
    }
  }

  // Handle Edit Promotional / Featured Announcement Banner action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_banner') {
    $banner_id = intval($_POST['banner_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $details_content = trim($_POST['details_content'] ?? '');
    $cta_button_text = trim($_POST['cta_button_text'] ?? '');
    $cta_button_url = trim($_POST['cta_button_url'] ?? '');
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $image_url_input = trim($_POST['image_url'] ?? '');

    if ($banner_id > 0 && !empty($title) && !empty($details_content)) {
      $stmt = $pdo->prepare("SELECT image_path FROM promotional_banners WHERE id = ?");
      $stmt->execute([$banner_id]);
      $existing_image = $stmt->fetchColumn();
      $image_path = $existing_image;

      $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
      $upload_dir = __DIR__ . '/../uploads/banners';

      if (isset($_FILES['banner_image_file']) && $_FILES['banner_image_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['banner_image_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
          if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
          }
          $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
          $dest_path = $upload_dir . '/' . $filename;
          if (move_uploaded_file($file['tmp_name'], $dest_path)) {
            // Unlink old local image
            if (!empty($existing_image) && strpos($existing_image, 'uploads/banners/') !== false && file_exists(__DIR__ . '/../' . $existing_image)) {
              @unlink(__DIR__ . '/../' . $existing_image);
            }
            $image_path = 'uploads/banners/' . $filename;
          }
        } else {
          $error_message = 'Only JPG, JPEG, PNG, WEBP, SVG, and GIF image files are allowed.';
        }
      } elseif (!empty($image_url_input)) {
        $image_path = $image_url_input;
      }

      if (empty($error_message)) {
        $stmt = $pdo->prepare("UPDATE promotional_banners SET title = ?, subtitle = ?, image_path = ?, details_content = ?, cta_button_text = ?, cta_button_url = ?, display_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$title, $subtitle, $image_path, $details_content, $cta_button_text, $cta_button_url, $display_order, $is_active, $banner_id]);
        $success_message = 'Featured announcement updated successfully!';
      }
    } else {
      $error_message = 'Invalid parameters for updating featured announcement.';
    }
  }

  // Handle Delete Promotional / Featured Announcement action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_banner') {
    $banner_id = intval($_POST['banner_id'] ?? 0);
    if ($banner_id > 0) {
      $stmt = $pdo->prepare("SELECT image_path FROM promotional_banners WHERE id = ?");
      $stmt->execute([$banner_id]);
      $img = $stmt->fetchColumn();

      if (!empty($img) && strpos($img, 'uploads/banners/') !== false && file_exists(__DIR__ . '/../' . $img)) {
        @unlink(__DIR__ . '/../' . $img);
      }

      $stmt = $pdo->prepare("DELETE FROM promotional_banners WHERE id = ?");
      $stmt->execute([$banner_id]);
      $success_message = 'Featured announcement deleted successfully!';
    } else {
      $error_message = 'Invalid banner ID for deletion.';
    }
  }

  // Handle Quick Toggle Banner Status action (supports AJAX and form post)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_banner_status') {
    $banner_id = intval($_POST['banner_id'] ?? 0);
    if ($banner_id > 0) {
      $stmt = $pdo->prepare("UPDATE promotional_banners SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
      $stmt->execute([$banner_id]);
      
      $stmt = $pdo->prepare("SELECT is_active FROM promotional_banners WHERE id = ?");
      $stmt->execute([$banner_id]);
      $new_status = (int)$stmt->fetchColumn();

      if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'new_status' => $new_status]);
        exit;
      }
      $success_message = 'Banner status updated successfully!';
    }
  }

  // Handle Move Banner Order (Up/Down) action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_banner_order') {
    $banner_id = intval($_POST['banner_id'] ?? 0);
    $direction = trim($_POST['direction'] ?? 'up');

    if ($banner_id > 0) {
      $stmt = $pdo->prepare("SELECT id, display_order FROM promotional_banners WHERE id = ?");
      $stmt->execute([$banner_id]);
      $current = $stmt->fetch();

      if ($current) {
        $cur_order = (int)$current['display_order'];
        if ($direction === 'up') {
          $stmt = $pdo->prepare("SELECT id, display_order FROM promotional_banners WHERE display_order < ? ORDER BY display_order DESC LIMIT 1");
          $stmt->execute([$cur_order]);
          $swap = $stmt->fetch();
        } else {
          $stmt = $pdo->prepare("SELECT id, display_order FROM promotional_banners WHERE display_order > ? ORDER BY display_order ASC LIMIT 1");
          $stmt->execute([$cur_order]);
          $swap = $stmt->fetch();
        }

        if ($swap) {
          $swap_order = (int)$swap['display_order'];
          $pdo->prepare("UPDATE promotional_banners SET display_order = ? WHERE id = ?")->execute([$swap_order, $current['id']]);
          $pdo->prepare("UPDATE promotional_banners SET display_order = ? WHERE id = ?")->execute([$cur_order, $swap['id']]);
          $success_message = 'Banner position shifted successfully!';
        }
      }
    }
  }

  // Handle Hero Settings Update action (hero banner text, links, and social channels)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_hero_settings') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $button_text = trim($_POST['button_text'] ?? '');
    $button_url = trim($_POST['button_url'] ?? '');
    $secondary_button_text = trim($_POST['secondary_button_text'] ?? '');
    $secondary_button_url = trim($_POST['secondary_button_url'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $enrolled_students_count = trim($_POST['enrolled_students_count'] ?? '');
    $facebook_url = trim($_POST['facebook_url'] ?? '#');
    $twitter_url = trim($_POST['twitter_url'] ?? '#');
    $telegram_url = trim($_POST['telegram_url'] ?? '#');
    $instagram_url = trim($_POST['instagram_url'] ?? '#');
    $youtube_url = trim($_POST['youtube_url'] ?? '');
    $linkedin_url = trim($_POST['linkedin_url'] ?? '');
    $whatsapp_url = trim($_POST['whatsapp_url'] ?? '');

    if (!empty($title) && !empty($description) && !empty($button_text) && !empty($button_url)) {
      $stmt = $pdo->prepare("INSERT INTO hero_settings (id, title, description, button_text, button_url, secondary_button_text, secondary_button_url, phone_number, enrolled_students_count, facebook_url, twitter_url, telegram_url, instagram_url, youtube_url, linkedin_url, whatsapp_url) 
                             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), button_text = VALUES(button_text), button_url = VALUES(button_url), secondary_button_text = VALUES(secondary_button_text), secondary_button_url = VALUES(secondary_button_url), phone_number = VALUES(phone_number), enrolled_students_count = VALUES(enrolled_students_count), facebook_url = VALUES(facebook_url), twitter_url = VALUES(twitter_url), telegram_url = VALUES(telegram_url), instagram_url = VALUES(instagram_url), youtube_url = VALUES(youtube_url), linkedin_url = VALUES(linkedin_url), whatsapp_url = VALUES(whatsapp_url)");
      $stmt->execute([$title, $description, $button_text, $button_url, $secondary_button_text, $secondary_button_url, $phone_number, $enrolled_students_count, $facebook_url, $twitter_url, $telegram_url, $instagram_url, $youtube_url, $linkedin_url, $whatsapp_url]);
      $success_message = 'Hero section settings & social media links updated successfully!';
    } else {
      $error_message = 'Please fill in all required Hero section text fields.';
    }
  }

  // Handle Hero Portrait Image Upload (update_hero_portrait_image)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_hero_portrait_image') {
    $hero_image_alt_input = trim($_POST['hero_image_alt'] ?? 'Student with books');
    $upload_dir = __DIR__ . '/../uploads/hero';
    $allowed_exts  = ['png', 'jpg', 'jpeg', 'webp'];
    $allowed_mimes = ['image/png', 'image/jpeg', 'image/webp'];

    if (isset($_POST['remove_hero_portrait']) && $_POST['remove_hero_portrait'] === '1') {
      // Revert to default preset by unlinking custom image and clearing database record
      $stmt_old = $pdo->query("SELECT hero_image_path FROM hero_settings WHERE id = 1 LIMIT 1");
      $old_row  = $stmt_old->fetch();
      if ($old_row && !empty($old_row['hero_image_path'])) {
        $old_local = __DIR__ . '/../' . $old_row['hero_image_path'];
        if (strpos($old_row['hero_image_path'], 'uploads/hero/') !== false && file_exists($old_local)) {
          @unlink($old_local);
        }
      }
      $stmt = $pdo->prepare("UPDATE hero_settings SET hero_image_path = NULL, hero_image_alt = ? WHERE id = 1");
      $stmt->execute([$hero_image_alt_input]);
      $success_message = 'Custom hero portrait removed. Reverted to default preset successfully!';
    } elseif (isset($_FILES['hero_portrait_file']) && $_FILES['hero_portrait_file']['error'] === UPLOAD_ERR_OK) {
      $file    = $_FILES['hero_portrait_file'];
      $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

      // MIME type validation using finfo
      $finfo     = finfo_open(FILEINFO_MIME_TYPE);
      $mime_type = finfo_file($finfo, $file['tmp_name']);
      finfo_close($finfo);

      if (in_array($ext, $allowed_exts) && in_array($mime_type, $allowed_mimes)) {
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }

        // Sanitized filename — no user input in name
        $filename  = 'hero_portrait_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          // Delete old portrait file if it was a local upload
          $stmt_old = $pdo->query("SELECT hero_image_path FROM hero_settings WHERE id = 1 LIMIT 1");
          $old_row  = $stmt_old->fetch();
          if ($old_row && !empty($old_row['hero_image_path'])) {
            $old_local = __DIR__ . '/../' . $old_row['hero_image_path'];
            if (strpos($old_row['hero_image_path'], 'uploads/hero/') !== false && file_exists($old_local)) {
              @unlink($old_local);
            }
          }

          $new_path = 'uploads/hero/' . $filename;
          $stmt = $pdo->prepare("INSERT INTO hero_settings (id, title, description, button_text, button_url, hero_image_path, hero_image_alt)
                                 VALUES (1, 'Enhance Your Skills With Our Online Courses', 'Dive into a World of Knowledge', 'Apply Now', '#courses-section', ?, ?)
                                 ON DUPLICATE KEY UPDATE hero_image_path = VALUES(hero_image_path), hero_image_alt = VALUES(hero_image_alt)");
          $stmt->execute([$new_path, $hero_image_alt_input]);
          $success_message = __('hero_portrait_saved', 'Hero portrait image updated successfully!');
        } else {
          $error_message = 'Failed to move uploaded hero portrait file. Please check folder permissions.';
        }
      } else {
        $error_message = 'Invalid file type. Only PNG, JPG, JPEG, and WebP images are allowed for the hero portrait.';
      }
    } elseif (!empty($hero_image_alt_input)) {
      // Only alt text updated — no new file
      $stmt = $pdo->prepare("UPDATE hero_settings SET hero_image_alt = ? WHERE id = 1");
      $stmt->execute([$hero_image_alt_input]);
      $success_message = 'Hero portrait alt text updated successfully!';
    } else {
      $error_message = 'Please select a valid image file to upload as the hero portrait.';
    }
  }

  // Handle Add Category action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $cat_name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    if (!empty($cat_name)) {
      try {
        $stmt = $pdo->prepare("INSERT INTO course_categories (name, status) VALUES (?, ?)");
        $stmt->execute([$cat_name, $status]);
        $success_message = 'Course category added successfully!';
      } catch (PDOException $ex) {
        $error_message = 'Category name already exists or invalid.';
      }
    } else {
      $error_message = 'Please enter a category name.';
    }
  }

  // Handle Edit Category action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_category') {
    $cat_id = intval($_POST['id'] ?? 0);
    $cat_name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    if ($cat_id > 0 && !empty($cat_name)) {
      $stmt = $pdo->prepare("UPDATE course_categories SET name = ?, status = ? WHERE id = ?");
      $stmt->execute([$cat_name, $status, $cat_id]);
      $success_message = 'Course category updated successfully!';
    } else {
      $error_message = 'Invalid category parameters.';
    }
  }

  // Handle Delete Category action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    $cat_id = intval($_POST['id'] ?? 0);
    if ($cat_id > 0) {
      $stmt = $pdo->prepare("DELETE FROM course_categories WHERE id = ?");
      $stmt->execute([$cat_id]);
      $success_message = 'Course category deleted successfully!';
    }
  }

  // Handle Add Target Audience action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_target_audience') {
    $aud_name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    if (!empty($aud_name)) {
      try {
        $stmt = $pdo->prepare("INSERT INTO target_audiences (name, status) VALUES (?, ?)");
        $stmt->execute([$aud_name, $status]);
        $success_message = 'Target audience / Batch added successfully!';
      } catch (PDOException $ex) {
        $error_message = 'Target audience / Batch already exists or invalid.';
      }
    } else {
      $error_message = 'Please enter a target audience name.';
    }
  }

  // Handle Edit Target Audience action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_target_audience') {
    $aud_id = intval($_POST['id'] ?? 0);
    $aud_name = trim($_POST['name'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    if ($aud_id > 0 && !empty($aud_name)) {
      $stmt = $pdo->prepare("UPDATE target_audiences SET name = ?, status = ? WHERE id = ?");
      $stmt->execute([$aud_name, $status, $aud_id]);
      $success_message = 'Target audience / Batch updated successfully!';
    } else {
      $error_message = 'Invalid target audience parameters.';
    }
  }

  // Handle Delete Target Audience action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_target_audience') {
    $aud_id = intval($_POST['id'] ?? 0);
    if ($aud_id > 0) {
      $stmt = $pdo->prepare("DELETE FROM target_audiences WHERE id = ?");
      $stmt->execute([$aud_id]);
      $success_message = 'Target audience / Batch deleted successfully!';
    }
  }

  // Handle Admin Password Change action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_admin_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
      $error_message = 'Please fill in all password fields.';
    } elseif ($new_password !== $confirm_password) {
      $error_message = 'New password and confirmation password do not match.';
    } elseif (strlen($new_password) < 6) {
      $error_message = 'New password must be at least 6 characters long.';
    } else {
      $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->execute([$_SESSION['user_id']]);
      $admin_user = $stmt->fetch();

      $passVerify = false;
      if ($admin_user) {
        $hash = !empty($admin_user['password_hash']) ? $admin_user['password_hash'] : ($admin_user['password'] ?? '');
        if (password_verify($current_password, $hash) || $current_password === $hash) {
          $passVerify = true;
        }
      }

      if ($passVerify) {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $update_stmt->execute([$hashed_password, $_SESSION['user_id']]);
        $_SESSION['session_password_hash'] = $hashed_password;
        $success_message = 'Admin password updated successfully!';
      } else {
        $error_message = 'Current password is incorrect.';
      }
    }
  }

  // Handle Admin Profile Picture Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_admin_profile_picture') {
    $avatar_url = trim($_POST['profile_avatar_url'] ?? '');
    $new_avatar_path = null;

    if (isset($_FILES['profile_avatar_file']) && $_FILES['profile_avatar_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['profile_avatar_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

      if (in_array($ext, $allowed)) {
        $upload_dir = __DIR__ . '/uploads/avatars';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $filename = 'admin_avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        $relative_path = 'admin/uploads/avatars/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          $new_avatar_path = $relative_path;
        } else {
          $error_message = 'Failed to save uploaded profile picture file.';
        }
      } else {
        $error_message = 'Only PNG, JPG, JPEG, WEBP, and GIF image files are allowed for profile pictures.';
      }
    } elseif (!empty($avatar_url)) {
      if (filter_var($avatar_url, FILTER_VALIDATE_URL) || strpos($avatar_url, 'data:image') === 0 || strpos($avatar_url, 'uploads/') === 0) {
        $new_avatar_path = $avatar_url;
      } else {
        $error_message = 'Please provide a valid image URL or upload an image file.';
      }
    } else {
      $error_message = 'Please select an image file to upload or enter an image URL.';
    }

    if (!empty($new_avatar_path)) {
      $updateStmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
      $updateStmt->execute([$new_avatar_path, $_SESSION['user_id']]);

      $_SESSION['user_avatar'] = $new_avatar_path;
      $current_admin_avatar = (preg_match('~^https?://~i', $new_avatar_path) || strpos($new_avatar_path, 'data:') === 0) ? $new_avatar_path : '../' . ltrim($new_avatar_path, '/');
      $success_message = 'Admin profile picture updated successfully!';
    }
  }

  // Handle Site Logo Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site_logo') {
    if (isset($_FILES['site_logo_file']) && $_FILES['site_logo_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['site_logo_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

      if (in_array($ext, $allowed)) {
        $upload_dir = __DIR__ . '/../uploads/logo';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $filename = 'site_logo_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/logo/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          // Update site_settings DB entry
          $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
          $stmt->execute([$relative_path]);

          // Overwrite default assets/logo.png for static image compatibility
          $assets_logo = __DIR__ . '/../assets/logo.png';
          @copy($dest_path, $assets_logo);

          $success_message = 'Site Logo updated successfully and synchronized across all pages!';
        } else {
          $error_message = 'Failed to save uploaded logo file.';
        }
      } else {
        $error_message = 'Only PNG, JPG, JPEG, SVG, and WEBP image files are allowed for the logo.';
      }
    } else {
      $error_message = 'Please select a valid image file to upload as site logo.';
    }
  }

  // Handle Site Favicon Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_site_favicon') {
    if (isset($_FILES['site_favicon_file']) && $_FILES['site_favicon_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['site_favicon_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['ico', 'png', 'svg', 'webp', 'jpg', 'jpeg'];

      if (in_array($ext, $allowed)) {
        $upload_dir = __DIR__ . '/../uploads/favicon';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $filename = 'site_favicon_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/favicon/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          // Update site_settings DB entry
          $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_favicon', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
          $stmt->execute([$relative_path]);

          // Also synchronize with fallback files for static and native browser requests
          if ($ext === 'ico') {
            @copy($dest_path, __DIR__ . '/../assets/favicon.ico');
            @copy($dest_path, __DIR__ . '/../favicon.ico');
          } else {
            @copy($dest_path, __DIR__ . '/../assets/favicon.' . $ext);
            @copy($dest_path, __DIR__ . '/../assets/favicon.ico');
            @copy($dest_path, __DIR__ . '/../favicon.ico');
          }

          $success_message = 'Website Favicon updated successfully and synchronized across all pages!';
        } else {
          $error_message = 'Failed to save uploaded favicon file.';
        }
      } else {
        $error_message = 'Only ICO, PNG, SVG, WEBP, JPG, and JPEG image files are allowed for the favicon.';
      }
    } else {
      $error_message = 'Please select a valid icon or image file to upload as website favicon.';
    }
  }

  // Handle Login Page Visual Image Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_login_image') {
    $custom_url = trim($_POST['login_image_url'] ?? '');
    
    if (isset($_FILES['login_image_file']) && $_FILES['login_image_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['login_image_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

      if (in_array($ext, $allowed)) {
        $upload_dir = __DIR__ . '/../uploads/auth';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $filename = 'login_bg_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/auth/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('login_page_image', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
          $stmt->execute([$relative_path]);
          $success_message = 'Login page background image updated successfully!';
        } else {
          $error_message = 'Failed to save uploaded image file.';
        }
      } else {
        $error_message = 'Only PNG, JPG, JPEG, SVG, and WEBP image files are allowed.';
      }
    } elseif (!empty($custom_url)) {
      $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('login_page_image', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
      $stmt->execute([$custom_url]);
      $success_message = 'Login page image URL updated successfully!';
    } else {
      $error_message = 'Please select an image file or provide a valid image URL.';
    }
  }

  // Handle Reset Login Page Image
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_login_image') {
    $stmt = $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'login_page_image'");
    $stmt->execute();
    $success_message = 'Login page background image reset to default successfully!';
  }

  // Handle Register Page Visual Image Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_register_image') {
    $custom_url = trim($_POST['register_image_url'] ?? '');
    
    if (isset($_FILES['register_image_file']) && $_FILES['register_image_file']['error'] === UPLOAD_ERR_OK) {
      $file = $_FILES['register_image_file'];
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

      if (in_array($ext, $allowed)) {
        $upload_dir = __DIR__ . '/../uploads/auth';
        if (!file_exists($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $filename = 'register_bg_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;
        $relative_path = 'uploads/auth/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest_path)) {
          $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('register_page_image', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
          $stmt->execute([$relative_path]);
          $success_message = 'Register page background image updated successfully!';
        } else {
          $error_message = 'Failed to save uploaded image file.';
        }
      } else {
        $error_message = 'Only PNG, JPG, JPEG, SVG, and WEBP image files are allowed.';
      }
    } elseif (!empty($custom_url)) {
      $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('register_page_image', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
      $stmt->execute([$custom_url]);
      $success_message = 'Register page image URL updated successfully!';
    } else {
      $error_message = 'Please select an image file or provide a valid image URL.';
    }
  }

  // Handle Reset Register Page Image
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_register_image') {
    $stmt = $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'register_page_image'");
    $stmt->execute();
    $success_message = 'Register page background image reset to default successfully!';
  }

  // Handle Certificate Delivery Note Update action
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_delivery_note') {
    $cert_cod_title = trim($_POST['cert_cod_title'] ?? 'Cash on Delivery & Courier Details:');
    $cert_cod_fee_note = trim($_POST['cert_cod_fee_note'] ?? '');
    $cert_cod_timeframe_note = trim($_POST['cert_cod_timeframe_note'] ?? '');
    $cert_cod_custom_notice = trim($_POST['cert_cod_custom_notice'] ?? '');

    if (empty($cert_cod_fee_note) || empty($cert_cod_timeframe_note)) {
      $error_message = 'Please provide both the Associated Fee note and the Delivery Timeframe note.';
    } else {
      $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
      $stmt->execute(['cert_cod_title', $cert_cod_title]);
      $stmt->execute(['cert_cod_fee_note', $cert_cod_fee_note]);
      $stmt->execute(['cert_cod_timeframe_note', $cert_cod_timeframe_note]);
      $stmt->execute(['cert_cod_custom_notice', $cert_cod_custom_notice]);

      $success_message = 'Cash on Delivery & Courier Details note updated successfully and synchronized across all student certificate application modals!';
    }
  }

  // Handle Google OAuth Settings Update
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_google_oauth') {
    $google_client_id = trim($_POST['google_client_id'] ?? '');
    $google_client_secret = trim($_POST['google_client_secret'] ?? '');
    $google_oauth_enabled = isset($_POST['google_oauth_enabled']) ? '1' : '0';
    $google_redirect_uri = trim($_POST['google_redirect_uri'] ?? '');

    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['google_client_id', $google_client_id]);
    $stmt->execute(['google_client_secret', $google_client_secret]);
    $stmt->execute(['google_oauth_enabled', $google_oauth_enabled]);
    if (!empty($google_redirect_uri)) {
      $stmt->execute(['google_redirect_uri', $google_redirect_uri]);
    }

    $success_message = 'Google Sign-In & OAuth settings updated successfully!';
  }

  // Post-Redirect-Get (PRG) pattern: Redirect non-AJAX POST requests to prevent form resubmission on page refresh
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax_req = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                   || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    if (!$is_ajax_req) {
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

  // Fetch all teachers
  $stmt = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM courses c WHERE c.tutor_id = u.id) as teacher_courses_count FROM users u WHERE u.role = 'teacher' ORDER BY CASE WHEN u.status = 'pending' THEN 1 ELSE 2 END, u.created_at DESC");
  $teachers = $stmt->fetchAll();

  // Fetch all registered students
  $stmt = $pdo->query("SELECT u.*, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) as enrolled_count FROM users u WHERE u.role = 'student' ORDER BY u.created_at DESC");
  $all_students = $stmt->fetchAll();

  // Fetch all courses (JOIN users to retrieve instructor name & avatar, plus live enrolled student count)
  $stmt = $pdo->query("SELECT c.*, u.name as tutor_name, u.avatar as tutor_avatar, 
                              (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) as live_enrolled_count 
                       FROM courses c 
                       LEFT JOIN users u ON c.tutor_id = u.id 
                       ORDER BY CASE WHEN c.status = 'pending' THEN 1 WHEN c.status = 'rejected' THEN 2 ELSE 3 END, c.created_at DESC");
  $courses = $stmt->fetchAll();

  // Fetch all bank payments
  $stmt = $pdo->query("SELECT bp.*, u.name as student_name, u.email as student_email, c.title as course_title, c.price as course_price 
                         FROM bank_payments bp 
                         JOIN users u ON bp.user_id = u.id 
                         JOIN courses c ON bp.course_id = c.id 
                         ORDER BY CASE WHEN bp.status = 'pending' THEN 1 WHEN bp.status = 'rejected' THEN 2 ELSE 3 END, bp.created_at DESC");
  $bank_payments = $stmt->fetchAll();

  // Fetch all managed bank accounts
  $stmt = $pdo->query("SELECT * FROM bank_accounts ORDER BY id ASC");
  $managed_bank_accounts = $stmt->fetchAll();

  // Fetch all site announcements
  $stmt = $pdo->query("SELECT * FROM site_announcements ORDER BY created_at DESC");
  $all_site_announcements = $stmt->fetchAll();

  // Fetch all promotional banners
  $stmt = $pdo->query("SELECT * FROM promotional_banners ORDER BY display_order ASC, created_at DESC");
  $all_promotional_banners = $stmt->fetchAll();

  // Fetch all course categories
  $stmt = $pdo->query("SELECT * FROM course_categories ORDER BY id ASC");
  $admin_categories = $stmt->fetchAll();

  // Fetch all target audiences
  $stmt = $pdo->query("SELECT * FROM target_audiences ORDER BY id ASC");
  $admin_target_audiences = $stmt->fetchAll();

  // Fetch hero settings for admin panel
  $stmt = $pdo->query("SELECT * FROM hero_settings WHERE id = 1 LIMIT 1");
  $hero_settings = $stmt->fetch();
  if (!$hero_settings) {
    $hero_settings = [
      'title' => 'Enhance Your Skills With Our Online Courses',
      'description' => 'Dive into a World of Knowledge with Our Comprehensive and Engaging Online Courses Designed for Skill Enhancement',
      'button_text' => 'Apply Now',
      'button_url' => '#courses-section',
      'secondary_button_text' => 'Know More',
      'secondary_button_url' => '#courses-section',
      'phone_number' => 'Call Us : 011 234 5678',
      'enrolled_students_count' => '30K Enrolled Students',
      'bg_image_1' => null,
      'bg_image_2' => null,
      'bg_image_3' => null,
      'hero_image_path' => null,
      'hero_image_alt' => 'Student with books',
      'facebook_url' => '#',
      'twitter_url' => '#',
      'telegram_url' => '#',
      'instagram_url' => '#',
      'youtube_url' => '',
      'linkedin_url' => '',
      'whatsapp_url' => ''
    ];
  }
  // Resolve portrait preview path for the admin card
  $admin_hero_portrait_preview = !empty($hero_settings['hero_image_path'])
    ? '../' . htmlspecialchars($hero_settings['hero_image_path'])
    : null;

} catch (PDOException $e) {
  $error_message = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Portal | Computerscience.lk</title>

  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">

  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Local Tailwind CSS -->
  <script src="assets/js/tailwind.js"></script>
  <script>
    tailwind.config = {
      corePlugins: { preflight: false },
      theme: { extend: { colors: { moodle: { blue: '<?php echo $is_super_admin ? "#0b4528" : "#0f4c81"; ?>', orange: '#f26f21', bg: '#f8f9fa' } } } }
    }
  </script>

  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    :root {
      --sidebar-width: 270px;
      --sidebar-bg:
        <?php echo $is_super_admin ? '#052014' : '#0b1e36'; ?>
      ;
      --sidebar-hover:
        <?php echo $is_super_admin ? '#0e3b25' : '#162f52'; ?>
      ;
      --sidebar-active:
        <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>
      ;
      --sidebar-text:
        <?php echo $is_super_admin ? '#a3cfbb' : '#94a3b8'; ?>
      ;
    }

    body {
      background-color: #f3f4f6;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      overflow-x: hidden;
    }

    .teacher-avatar {
      width: 44px !important;
      height: 44px !important;
      object-fit: cover !important;
      border-radius: 50% !important;
      flex-shrink: 0 !important;
      border: 2px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    /* Left Sidebar Navigation Panel */
    .admin-sidebar {
      width: var(--sidebar-width);
      height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      background-color: var(--sidebar-bg);
      color: #ffffff;
      z-index: 1040;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      flex-direction: column;
      box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
    }

    .sidebar-header {
      padding: 20px 24px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .sidebar-menu {
      padding: 16px 12px;
      flex-grow: 1;
      overflow-y: auto;
    }

    .sidebar-section-title {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #64748b;
      font-weight: 700;
      padding: 12px 12px 6px 12px;
    }

    .nav-link-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 11px 16px;
      color: var(--sidebar-text);
      border-radius: 10px;
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 500;
      margin-bottom: 4px;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .nav-link-item:hover {
      background-color: var(--sidebar-hover);
      color: #ffffff;
    }

    .nav-link-item.active {
      background-color: var(--sidebar-active);
      color: #ffffff !important;
      font-weight: 600;
      box-shadow: 0 4px 12px rgba(11, 69, 40, 0.4);
    }
    .nav-link-item.active * {
      color: #ffffff !important;
    }

    .nav-link-item i {
      font-size: 1.1rem;
    }

    /* Main Content Wrapper */
    .admin-main-wrapper {
      margin-left: var(--sidebar-width);
      min-height: 100vh;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      flex-direction: column;
    }

    @media (max-width: 991.98px) {
      .admin-main-wrapper {
        margin-left: 0;
      }

      .admin-sidebar {
        transform: translateX(-100%);
      }

      .admin-sidebar.show-mobile {
        transform: translateX(0);
      }
    }

    .admin-topbar {
      background-color: #ffffff;
      border-bottom: 1px solid #e5e7eb;
      padding: 14px 28px;
      position: sticky;
      top: 0;
      z-index: 1030;
    }

    .glass-card {
      background: white;
      border-radius: 16px;
      border: 1px solid rgba(229, 231, 235, 1);
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    }

    .stat-card-widget {
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .stat-card-widget:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
    }

    /* Contrast Enforcements */
    .form-label {
      color: #1e293b !important;
      font-weight: 600;
    }

    .nav-pills .nav-link {
      color: #334155 !important;
      background-color: #f8fafc;
      border: 1px solid #e2e8f0;
    }
    .nav-pills .nav-link:hover {
      background-color: #f1f5f9;
      color: #0f172a !important;
    }
    .nav-pills .nav-link.active {
      background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?> !important;
      color: #ffffff !important;
      border-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?> !important;
    }
    .nav-pills .nav-link.active i,
    .nav-pills .nav-link.active span:not(.badge) {
      color: #ffffff !important;
    }
    .nav-pills .nav-link.active .badge {
      background-color: rgba(255, 255, 255, 0.25) !important;
      color: #ffffff !important;
      border: 1px solid rgba(255, 255, 255, 0.4) !important;
    }

    .btn-primary, .btn-success, .btn-danger, .btn-dark {
      color: #ffffff !important;
    }
    .btn-primary *, .btn-success *, .btn-danger *, .btn-dark * {
      color: #ffffff !important;
    }
  </style>
</head>

<body>

  <!-- Left Navigation Sidebar Drawer -->
  <aside class="admin-sidebar" id="admin-sidebar">
    <!-- Sidebar Header / Logo -->
    <div class="sidebar-header d-flex align-items-center justify-content-between">
      <a href="index.php" class="d-flex align-items-center gap-2 text-decoration-none text-white">
        <img src="../<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Logo"
          style="height: 34px; width: auto; object-fit: contain;">
        <div>
          <h6 class="fw-bold text-white mb-0 fs-7">Computerscience.lk</h6>
          <small
            class="text-warning fs-9 fw-bold"><?php echo $is_super_admin ? 'SUPER ADMIN PORTAL' : 'ADMIN PORTAL'; ?></small>
        </div>
      </a>
      <button class="btn btn-sm text-white-50 p-0 d-lg-none" id="close-sidebar-btn">
        <i class="bi bi-x-lg fs-5"></i>
      </button>
    </div>

    <!-- Sidebar Navigation Menu Items -->
    <div class="sidebar-menu">
      <div class="sidebar-section-title"><?php echo $is_super_admin ? 'Super Admin Main Menu' : 'Main Menu'; ?></div>

      <!-- Teacher Requests -->
      <?php $pending_teachers = array_filter($teachers, function ($t) {
        return ($t['status'] ?? '') === 'pending';
      }); ?>
      <a class="nav-link-item active" id="btn-teachers-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-people-fill text-warning"></i>
          <span>Teacher Requests</span>
        </div>
        <?php if (count($pending_teachers) > 0): ?>
          <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo count($pending_teachers); ?></span>
        <?php endif; ?>
      </a>

      <!-- Registered Students Directory -->
      <a href="students.php" class="nav-link-item">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-person-badge-fill text-info"></i>
          <span>Registered Students</span>
        </div>
      </a>

      <!-- Student Progress & Performance Analytics -->
      <a href="student_analytics.php" class="nav-link-item">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-graph-up-arrow text-success"></i>
          <span>Student Analytics</span>
        </div>
      </a>

      <!-- Course Certificate Management & Issuing -->
      <?php
      $pending_cert_count = 0;
      try {
        $pCertStmt = $pdo->query("SELECT COUNT(*) FROM certificate_requests WHERE status = 'pending'");
        $pending_cert_count = (int)$pCertStmt->fetchColumn();
      } catch (Exception $e) {}
      ?>
      <a href="certificates.php" class="nav-link-item">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-award-fill text-warning"></i>
          <span>Course Certificates</span>
        </div>
        <?php if ($pending_cert_count > 0): ?>
          <span class="badge bg-warning text-dark rounded-pill px-2 py-0.5 fs-9 fw-bold"><?php echo $pending_cert_count; ?></span>
        <?php endif; ?>
      </a>

      <!-- Course Approvals -->
      <?php $pending_courses = array_filter($courses, function ($c) {
        return ($c['status'] ?? '') === 'pending';
      }); ?>
      <a class="nav-link-item" id="btn-courses-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-journal-check text-primary"></i>
          <span>Course Requests</span>
        </div>
        <?php if (count($pending_courses) > 0): ?>
          <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo count($pending_courses); ?></span>
        <?php endif; ?>
      </a>

      <div class="sidebar-section-title mt-3">Financials</div>

      <!-- Bank Slip Approvals -->
      <?php $pending_slips = array_filter($bank_payments, function ($bp) {
        return ($bp['status'] ?? '') === 'pending';
      }); ?>
      <a class="nav-link-item" id="btn-bank-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-bank text-success"></i>
          <span>Bank Slip Approvals</span>
        </div>
        <?php if (count($pending_slips) > 0): ?>
          <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo count($pending_slips); ?></span>
        <?php endif; ?>
      </a>

      <!-- Manage Bank Accounts -->
      <a class="nav-link-item" id="btn-manage-bank-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-bank2 text-info"></i>
          <span>Bank Accounts</span>
        </div>
      </a>

      <div class="sidebar-section-title mt-3">Content & Branding</div>

      <!-- Category & Batch Dropdown Options -->
      <a class="nav-link-item" id="btn-options-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-sliders text-primary"></i>
          <span>Dropdown Options</span>
        </div>
      </a>

      <!-- Site Announcements -->
      <a class="nav-link-item" id="btn-announcements-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-megaphone text-warning"></i>
          <span>Site Announcements</span>
        </div>
      </a>

      <!-- Hero Banner Settings -->
      <a class="nav-link-item" id="btn-hero-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-layout-text-window-reverse text-success"></i>
          <span>Hero Banner</span>
        </div>
      </a>

      <!-- Certificate Delivery Note / COD Settings -->
      <a class="nav-link-item" id="btn-delivery-note-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-truck text-info"></i>
          <span>Certificate Delivery Note</span>
        </div>
      </a>

      <!-- Google OAuth / Sign-In Settings -->
      <a class="nav-link-item" id="btn-google-auth-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-google text-warning"></i>
          <span>Google Sign-In</span>
        </div>
      </a>

      <!-- Site Logo, Favicon & Auth Page Visuals Customization -->
      <a class="nav-link-item" id="btn-logo-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-palette-fill text-danger"></i>
          <span>Branding &amp; Auth Images</span>
        </div>
      </a>

      <?php if ($is_super_admin): ?>
        <div class="sidebar-section-title mt-3 text-success fw-bold">Super Admin</div>
        <a href="manage_admins.php"
          class="nav-link-item border border-success border-opacity-30 bg-success bg-opacity-10 text-white shadow-sm">
          <div class="d-flex align-items-center gap-2.5">
            <i class="bi bi-shield-lock-fill text-success fs-5"></i>
            <span class="fw-bold text-white">Admin Management</span>
          </div>
          <span class="badge bg-success rounded-pill fs-9 px-2">PRO</span>
        </a>
      <?php endif; ?>

      <div class="sidebar-section-title mt-3">System & Security</div>

      <!-- Email / SMTP Settings -->
      <a href="email_settings.php" class="nav-link-item">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-envelope-gear-fill text-warning"></i>
          <span>Email & SMTP Settings</span>
        </div>
      </a>

      <!-- Change Admin Password -->
      <a class="nav-link-item" id="btn-password-tab">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-shield-lock-fill text-secondary"></i>
          <span>Change Password</span>
        </div>
      </a>

      <a href="logout.php" class="nav-link-item mt-2 text-danger opacity-80 hover:opacity-100">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-box-arrow-right"></i>
          <span>Logout</span>
        </div>
      </a>
    </div>
  </aside>

  <!-- Main Content Wrapper -->
  <div class="admin-main-wrapper" id="admin-main-wrapper">

    <!-- Top Administrative Header Bar -->
    <header class="admin-topbar d-flex align-items-center justify-content-between shadow-sm px-4">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm text-secondary p-0 d-lg-none me-2" id="open-sidebar-btn">
          <i class="bi bi-list fs-4"></i>
        </button>
        <span
          class="badge <?php echo $is_super_admin ? 'bg-success text-white' : 'bg-primary text-white'; ?> px-3 py-1.5 rounded-pill fs-8 fw-bold shadow-sm">
          <i class="bi bi-shield-lock-fill me-1.5"></i><?php echo $is_super_admin ? 'Super Admin' : 'Admin Console'; ?>
        </span>
      </div>

      <div class="d-flex align-items-center gap-3">
        <!-- Live Admin Notification Bell Dropdown -->
        <?php 
          $unread_count = 0;
          try {
            $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmtNotif->execute([$_SESSION['user_id']]);
            $unread_count = (int)$stmtNotif->fetchColumn();
          } catch(Exception $e) {}
          include __DIR__ . '/../includes/notification_dropdown.php';
        ?>

        <!-- User Profile Badge -->
        <div class="d-flex align-items-center gap-2.5">
          <img src="<?php echo htmlspecialchars($current_admin_avatar); ?>"
            onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($current_admin_name); ?>&background=0b4528&color=fff';"
            alt="<?php echo htmlspecialchars($current_admin_name); ?>" class="rounded-circle border shadow-sm"
            style="width: 38px; height: 38px; object-fit: cover;">
          <div class="d-none d-sm-block text-end">
            <div class="fw-bold fs-8 text-dark mb-0"><?php echo htmlspecialchars($current_admin_name); ?></div>
            <div class="fs-9 text-muted"><?php echo htmlspecialchars($current_admin_email); ?> &bull; <strong
                class="<?php echo $is_super_admin ? 'text-success' : 'text-primary'; ?>"><?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?></strong>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- Main Section Workspace Container -->
    <div class="p-4 p-md-5">

      <!-- Toast/Alert notifications -->
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
          <i class="bi bi-check-circle-fill me-2"></i>
          <?php echo htmlspecialchars($success_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <?php echo htmlspecialchars($error_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Teacher Registration Requests Section -->
      <div id="teachers-section">
        <div class="mb-4">
          <h2 class="fw-bold text-dark">Teacher Registration Requests</h2>
          <p class="text-secondary">Approve or view registrations for computer science educators requesting lecturer
            access.</p>
        </div>

        <div class="glass-card p-4">
          <?php if (count($teachers) === 0): ?>
            <div class="text-center py-5">
              <i class="bi bi-people text-muted fs-1"></i>
              <p class="text-muted mt-3 mb-0">No teacher registration requests found in the system.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="py-3 border-0">Teacher Details</th>
                    <th scope="col" class="py-3 border-0">Subject & Qualifications</th>
                    <th scope="col" class="py-3 border-0">Academic ID & Email</th>
                    <th scope="col" class="py-3 border-0">Biography</th>
                    <th scope="col" class="py-3 border-0">Status</th>
                    <th scope="col" class="py-3 border-0 text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($teachers as $teacher): ?>
                    <?php
                    $t_name = !empty($teacher['name']) ? $teacher['name'] : 'Teacher';
                    $raw_teacher_avatar = trim($teacher['avatar'] ?? '');
                    if (empty($raw_teacher_avatar)) {
                      $teacher_avatar_src = 'https://ui-avatars.com/api/?name=' . urlencode($t_name) . '&background=0f4c81&color=fff';
                    } elseif (preg_match('~^https?://~i', $raw_teacher_avatar) || strpos($raw_teacher_avatar, 'data:') === 0) {
                      $teacher_avatar_src = $raw_teacher_avatar;
                    } else {
                      $teacher_avatar_src = '../' . ltrim($raw_teacher_avatar, '/');
                    }
                    ?>
                    <tr>
                      <td class="py-3">
                        <div class="d-flex align-items-center gap-3" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#teacherDetailModal_<?php echo $teacher['id']; ?>" title="Click to view teacher full details">
                          <img src="<?php echo htmlspecialchars($teacher_avatar_src); ?>"
                            onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($t_name); ?>&background=0f4c81&color=fff';"
                            alt="<?php echo htmlspecialchars($t_name); ?>"
                            class="teacher-avatar rounded-circle border shadow-sm"
                            style="width: 44px; height: 44px; object-fit: cover; flex-shrink: 0;">
                          <div>
                            <div class="fw-bold text-dark text-primary-hover d-flex align-items-center gap-1">
                              <?php echo htmlspecialchars($t_name); ?>
                              <i class="bi bi-info-circle text-primary fs-9" title="View details"></i>
                            </div>
                            <div class="fs-8 text-muted">Registered
                              <?php echo date('Y-m-d H:i', strtotime($teacher['created_at'])); ?>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td class="py-3">
                        <div class="fw-semibold text-secondary fs-7"><i class="bi bi-book me-1"></i>
                          <?php echo htmlspecialchars($teacher['subject'] ?? 'Not specified'); ?></div>
                        <div class="text-muted fs-8"><i class="bi bi-award me-1"></i>
                          <?php echo htmlspecialchars($teacher['qualifications'] ?? 'Not specified'); ?></div>
                      </td>
                      <td class="py-3">
                        <div class="font-monospace fs-8 text-dark"><?php echo htmlspecialchars($teacher['academic_id']); ?>
                        </div>
                        <div class="fs-8 text-muted"><?php echo htmlspecialchars($teacher['email']); ?></div>
                      </td>
                      <td class="py-3">
                        <div class="text-truncate fs-8 text-secondary" style="max-width: 200px;"
                          title="<?php echo htmlspecialchars($teacher['bio'] ?? ''); ?>">
                          <?php echo htmlspecialchars($teacher['bio'] ?? 'No bio provided.'); ?>
                        </div>
                      </td>
                      <td class="py-3">
                        <?php $t_status = strtolower($teacher['status'] ?? 'active'); ?>
                        <?php if ($t_status === 'pending'): ?>
                          <span class="status-badge-pending">
                            <i class="bi bi-clock-history"></i> Pending Approval
                          </span>
                        <?php elseif ($t_status === 'active'): ?>
                          <span class="status-badge-active">
                            <i class="bi bi-check-circle-fill"></i> Active
                          </span>
                        <?php else: ?>
                          <span class="status-badge-inactive">
                            <i class="bi bi-pause-circle-fill"></i> Inactive
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-end">
                        <div class="d-inline-flex align-items-center gap-1.5">
                          <!-- View Details Button -->
                          <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-semibold shadow-sm"
                            data-bs-toggle="modal" data-bs-target="#teacherDetailModal_<?php echo $teacher['id']; ?>"
                            title="View Full Profile Details">
                            <i class="bi bi-eye-fill me-1"></i> View Details
                          </button>

                          <?php if ($t_status === 'pending'): ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_teacher_status">
                              <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                              <input type="hidden" name="status" value="active">
                              <button type="submit" class="btn btn-sm btn-success px-3 rounded-pill fw-semibold shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> Approve & Activate
                              </button>
                            </form>
                          <?php elseif ($t_status === 'active'): ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_teacher_status">
                              <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                              <input type="hidden" name="status" value="inactive">
                              <button type="submit" class="btn btn-sm btn-outline-warning px-3 rounded-pill fw-semibold"
                                title="Deactivate Teacher">
                                <i class="bi bi-pause-circle me-1"></i> Set Inactive
                              </button>
                            </form>
                          <?php else: ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_teacher_status">
                              <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                              <input type="hidden" name="status" value="active">
                              <button type="submit" class="btn btn-sm btn-outline-success px-3 rounded-pill fw-semibold"
                                title="Activate Teacher">
                                <i class="bi bi-play-circle me-1"></i> Set Active
                              </button>
                            </form>
                          <?php endif; ?>

                          <!-- Send Notification Message Button -->
                          <button type="button" class="btn btn-sm btn-outline-info px-3 rounded-pill fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#sendMessageModal_<?php echo $teacher['id']; ?>"
                            title="Send Notification Message to Teacher">
                            <i class="bi bi-chat-dots-fill me-1"></i> Message
                          </button>

                          <!-- Delete Teacher Button -->
                          <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-pill fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#deleteTeacherModal_<?php echo $teacher['id']; ?>"
                            title="Delete Teacher Account">
                            <i class="bi bi-trash3-fill me-1"></i> Delete
                          </button>
                        </div>

                        <!-- Teacher Full Detail Popup Modal -->
                        <div class="modal fade text-start" id="teacherDetailModal_<?php echo $teacher['id']; ?>" tabindex="-1" aria-labelledby="teacherDetailModalLabel_<?php echo $teacher['id']; ?>" aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                              
                              <div class="modal-header border-bottom bg-light py-3 px-4">
                                <div class="d-flex align-items-center gap-2">
                                  <i class="bi bi-person-lines-fill text-primary fs-4"></i>
                                  <h5 class="modal-title fw-bold text-dark mb-0" id="teacherDetailModalLabel_<?php echo $teacher['id']; ?>">Teacher Registration Details</h5>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>

                              <div class="modal-body p-4">
                                <!-- Profile Hero Banner -->
                                <div class="p-3 p-md-4 rounded-4 bg-white border shadow-sm mb-4">
                                  <div class="d-flex flex-column flex-sm-row align-items-center gap-3 gap-md-4 text-center text-sm-start">
                                    <img src="<?php echo htmlspecialchars($teacher_avatar_src); ?>"
                                         onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($t_name); ?>&background=0f4c81&color=fff';"
                                         alt="<?php echo htmlspecialchars($t_name); ?>"
                                         class="rounded-circle border shadow-sm"
                                         style="width: 80px; height: 80px; object-fit: cover; flex-shrink: 0;">
                                    
                                    <div class="flex-grow-1">
                                      <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-sm-start gap-2 mb-1">
                                        <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($t_name); ?></h4>
                                        <?php if ($t_status === 'pending'): ?>
                                          <span class="status-badge-pending fs-9">
                                            <i class="bi bi-clock-history"></i> Pending Approval
                                          </span>
                                        <?php elseif ($t_status === 'active'): ?>
                                          <span class="status-badge-active fs-9">
                                            <i class="bi bi-check-circle-fill"></i> Active
                                          </span>
                                        <?php else: ?>
                                          <span class="status-badge-inactive fs-9">
                                            <i class="bi bi-pause-circle-fill"></i> Inactive
                                          </span>
                                        <?php endif; ?>
                                      </div>

                                      <div class="text-secondary fs-7 mb-1">
                                        <i class="bi bi-person-badge text-primary me-1"></i> Academic ID: 
                                        <span class="font-monospace text-dark fw-bold"><?php echo htmlspecialchars($teacher['academic_id']); ?></span>
                                      </div>

                                      <div class="text-muted fs-8">
                                        <i class="bi bi-calendar-event me-1"></i> Registered On: 
                                        <span class="fw-medium text-dark"><?php echo date('F j, Y, g:i A', strtotime($teacher['created_at'])); ?></span>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                                <!-- Key Statistics Row -->
                                <div class="row g-3 mb-4">
                                  <div class="col-6 col-md-4">
                                    <div class="p-3 rounded-3 bg-light border text-center">
                                      <div class="fs-8 text-secondary fw-semibold text-uppercase mb-1"><i class="bi bi-journal-album text-primary me-1"></i> Courses</div>
                                      <div class="fs-5 fw-bold text-dark"><?php echo (int)($teacher['teacher_courses_count'] ?? 0); ?></div>
                                    </div>
                                  </div>
                                  <div class="col-6 col-md-4">
                                    <div class="p-3 rounded-3 bg-light border text-center">
                                      <div class="fs-8 text-secondary fw-semibold text-uppercase mb-1"><i class="bi bi-shield-check text-primary me-1"></i> Account Role</div>
                                      <div class="fs-7 fw-bold text-primary">Instructor</div>
                                    </div>
                                  </div>
                                  <div class="col-12 col-md-4">
                                    <div class="p-3 rounded-3 bg-light border text-center">
                                      <div class="fs-8 text-secondary fw-semibold text-uppercase mb-1"><i class="bi bi-patch-check-fill text-success me-1"></i> Status</div>
                                      <div class="fs-7 fw-bold text-capitalize <?php echo $t_status === 'active' ? 'text-success' : ($t_status === 'pending' ? 'text-warning' : 'text-danger'); ?>"><?php echo htmlspecialchars($t_status); ?></div>
                                    </div>
                                  </div>
                                </div>

                                <!-- Info Grid -->
                                <div class="row g-3 mb-4">
                                  <div class="col-md-6">
                                    <div class="card h-100 border-0 shadow-sm bg-light p-3 rounded-3">
                                      <div class="text-uppercase text-secondary fs-9 fw-bold mb-1">
                                        <i class="bi bi-envelope-at text-primary me-1"></i> Email Address
                                      </div>
                                      <div class="fw-bold text-dark text-break fs-7"><?php echo htmlspecialchars($teacher['email']); ?></div>
                                    </div>
                                  </div>

                                  <div class="col-md-6">
                                    <div class="card h-100 border-0 shadow-sm bg-light p-3 rounded-3">
                                      <div class="text-uppercase text-secondary fs-9 fw-bold mb-1">
                                        <i class="bi bi-credit-card-2-front text-primary me-1"></i> Academic / NIC ID
                                      </div>
                                      <div class="fw-bold text-dark font-monospace fs-7"><?php echo htmlspecialchars($teacher['academic_id']); ?></div>
                                    </div>
                                  </div>

                                  <div class="col-md-6">
                                    <div class="card h-100 border-0 shadow-sm bg-light p-3 rounded-3">
                                      <div class="text-uppercase text-secondary fs-9 fw-bold mb-1">
                                        <i class="bi bi-book text-primary me-1"></i> Teaching Subject / Specialty
                                      </div>
                                      <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($teacher['subject'] ?? 'Not specified'); ?></div>
                                    </div>
                                  </div>

                                  <div class="col-md-6">
                                    <div class="card h-100 border-0 shadow-sm bg-light p-3 rounded-3">
                                      <div class="text-uppercase text-secondary fs-9 fw-bold mb-1">
                                        <i class="bi bi-award text-primary me-1"></i> Qualifications
                                      </div>
                                      <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($teacher['qualifications'] ?? 'Not specified'); ?></div>
                                    </div>
                                  </div>
                                </div>

                                <!-- Biography -->
                                <div class="card border-0 shadow-sm bg-light p-3 rounded-3">
                                  <div class="text-uppercase text-secondary fs-9 fw-bold mb-1">
                                    <i class="bi bi-card-text text-primary me-1"></i> Full Biography / Profile Description
                                  </div>
                                  <div class="text-dark fs-7 lh-base pt-1">
                                    <?php echo !empty($teacher['bio']) ? nl2br(htmlspecialchars($teacher['bio'])) : '<span class="text-muted fst-italic">No biography details provided during registration.</span>'; ?>
                                  </div>
                                </div>

                              </div>

                              <div class="modal-footer border-top bg-light py-3 px-4 d-flex justify-content-between flex-wrap gap-2">
                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                <div class="d-flex align-items-center gap-2">
                                  <?php if ($t_status === 'pending'): ?>
                                    <form action="index.php" method="POST" class="d-inline">
                                      <input type="hidden" name="action" value="toggle_teacher_status">
                                      <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                                      <input type="hidden" name="status" value="active">
                                      <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold shadow-sm">
                                        <i class="bi bi-check2-circle me-1"></i> Approve & Activate
                                      </button>
                                    </form>
                                  <?php endif; ?>
                                  <button type="button" class="btn btn-outline-info px-4 rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#sendMessageModal_<?php echo $teacher['id']; ?>">
                                    <i class="bi bi-chat-dots-fill me-1"></i> Send Message
                                  </button>
                                </div>
                              </div>

                            </div>
                          </div>
                        </div>

                        <!-- Send Notification Message Modal -->
                        <div class="modal fade text-start" id="sendMessageModal_<?php echo $teacher['id']; ?>" tabindex="-1"
                          aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                              <form action="index.php" method="POST">
                                <input type="hidden" name="action" value="send_teacher_notification">
                                <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                                <div class="modal-header border-0 pb-0">
                                  <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                                    <i class="bi bi-chat-dots-fill text-info fs-5"></i> Send Notification Message
                                  </h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                                </div>
                                <div class="modal-body py-3">
                                  <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                                    <img src="<?php echo htmlspecialchars($teacher_avatar_src); ?>"
                                      alt="<?php echo htmlspecialchars($t_name); ?>" class="rounded-circle border"
                                      style="width: 40px; height: 40px; object-fit: cover;">
                                    <div>
                                      <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($t_name); ?></div>
                                      <div class="fs-8 text-muted"><?php echo htmlspecialchars($teacher['email']); ?></div>
                                    </div>
                                  </div>
                                  <div class="mb-2">
                                    <label class="form-label fw-bold text-dark fs-8">Notification Message Content:</label>
                                    <textarea name="message" class="form-control rounded-3" rows="4"
                                      placeholder="Type your message here... This will be delivered directly to the teacher's account notification bell."
                                      required></textarea>
                                  </div>
                                  <small class="text-muted fs-8"><i class="bi bi-bell-fill me-1 text-warning"></i>This
                                    notification will trigger a bell badge alert on the teacher's header.</small>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                  <button type="button" class="btn btn-light rounded-pill px-4"
                                    data-bs-dismiss="modal">Cancel</button>
                                  <button type="submit" class="btn btn-info text-white rounded-pill px-4 fw-bold shadow-sm">
                                    <i class="bi bi-send-fill me-1"></i> Send Notification
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- Delete Confirmation Modal -->
                        <div class="modal fade text-start" id="deleteTeacherModal_<?php echo $teacher['id']; ?>"
                          tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                              <form action="index.php" method="POST">
                                <input type="hidden" name="action" value="delete_teacher">
                                <input type="hidden" name="user_id" value="<?php echo $teacher['id']; ?>">
                                <div class="modal-header border-0 pb-0">
                                  <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                                    <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i> Confirm Teacher
                                    Deletion
                                  </h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                                </div>
                                <div class="modal-body py-3">
                                  <p class="fs-7 text-secondary mb-3">
                                    Are you sure you want to permanently delete teacher account
                                    <strong><?php echo htmlspecialchars($t_name); ?></strong>
                                    (<?php echo htmlspecialchars($teacher['email']); ?>)? This action cannot be undone.
                                  </p>
                                  <div class="mb-2">
                                    <label class="form-label fw-bold text-dark fs-8">Enter Admin Password to Confirm
                                      Deletion:</label>
                                    <input type="password" name="admin_password" class="form-control rounded-3"
                                      placeholder="Enter your admin account password" required>
                                  </div>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                  <button type="button" class="btn btn-light rounded-pill px-4"
                                    data-bs-dismiss="modal">Cancel</button>
                                  <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Delete
                                    Teacher</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Registered Students Directory Section -->
      <div id="students-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark">Registered Students Directory</h2>
          <p class="text-secondary">View full student profiles in admin preview mode, toggle active/inactive status,
            send bell notifications, and delete student accounts securely.</p>
        </div>

        <div class="glass-card p-4">
          <?php if (count($all_students) === 0): ?>
            <div class="text-center py-5">
              <i class="bi bi-person-x text-muted fs-1"></i>
              <p class="text-muted mt-3 mb-0">No registered student accounts found in the system.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="py-3 border-0">Student Details</th>
                    <th scope="col" class="py-3 border-0">Academic ID & Email</th>
                    <th scope="col" class="py-3 border-0">Enrollments</th>
                    <th scope="col" class="py-3 border-0">Status</th>
                    <th scope="col" class="py-3 border-0 text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($all_students as $student): ?>
                    <?php
                    $s_name = !empty($student['name']) ? $student['name'] : 'Student';
                    $raw_student_avatar = trim($student['avatar'] ?? '');
                    if (empty($raw_student_avatar)) {
                      $student_avatar_src = 'https://ui-avatars.com/api/?name=' . urlencode($s_name) . '&background=0f4c81&color=fff';
                    } elseif (preg_match('~^https?://~i', $raw_student_avatar) || strpos($raw_student_avatar, 'data:') === 0) {
                      $student_avatar_src = $raw_student_avatar;
                    } else {
                      $student_avatar_src = '../' . ltrim($raw_student_avatar, '/');
                    }
                    $s_status = strtolower($student['status'] ?? 'active');

                    // Fetch student details for preview modal
                    $enrolledStmt = $pdo->prepare("SELECT c.id, c.title, c.price, c.category FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE e.user_id = ?");
                    $enrolledStmt->execute([$student['id']]);
                    $student_courses = $enrolledStmt->fetchAll();

                    $quizStmt = $pdo->prepare("SELECT qr.*, c.title as course_title FROM quiz_results qr JOIN courses c ON qr.course_id = c.id WHERE qr.user_id = ? ORDER BY qr.updated_at DESC");
                    $quizStmt->execute([$student['id']]);
                    $student_quizzes = $quizStmt->fetchAll();
                    ?>
                    <tr>
                      <td class="py-3">
                        <div class="d-flex align-items-center gap-3">
                          <img src="<?php echo htmlspecialchars($student_avatar_src); ?>"
                            onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($s_name); ?>&background=0f4c81&color=fff';"
                            alt="<?php echo htmlspecialchars($s_name); ?>" class="rounded-circle border shadow-sm"
                            style="width: 44px; height: 44px; object-fit: cover; flex-shrink: 0;">
                          <div>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($s_name); ?></div>
                            <div class="fs-8 text-muted"><i class="bi bi-calendar3 me-1"></i>Joined
                              <?php echo date('M d, Y', strtotime($student['created_at'])); ?></div>
                          </div>
                        </div>
                      </td>
                      <td class="py-3">
                        <div class="font-monospace fs-8 text-dark fw-bold">
                          <?php echo htmlspecialchars($student['academic_id'] ?? 'N/A'); ?></div>
                        <div class="fs-8 text-muted"><?php echo htmlspecialchars($student['email']); ?></div>
                      </td>
                      <td class="py-3">
                        <span
                          class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5 rounded-pill fs-8">
                          <i class="bi bi-book-half me-1"></i> <?php echo intval($student['enrolled_count']); ?> Enrolled
                        </span>
                      </td>
                      <td class="py-3">
                        <?php if ($s_status === 'active'): ?>
                          <span class="status-badge-active">
                            <i class="bi bi-check-circle-fill"></i> Active
                          </span>
                        <?php else: ?>
                          <span class="status-badge-inactive">
                            <i class="bi bi-pause-circle-fill"></i> Inactive
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-end">
                        <div class="d-inline-flex align-items-center gap-1.5">
                          <!-- Profile Admin Preview Modal Button -->
                          <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#viewStudentModal_<?php echo $student['id']; ?>"
                            title="Admin Preview Profile">
                            <i class="bi bi-person-bounding-box me-1"></i> Profile
                          </button>

                          <!-- Workable Active / Inactive Status Toggle Button -->
                          <?php if ($s_status === 'active'): ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_student_status">
                              <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                              <input type="hidden" name="status" value="inactive">
                              <button type="submit" class="btn btn-sm btn-outline-warning px-3 rounded-pill fw-semibold"
                                title="Deactivate Student Account">
                                <i class="bi bi-pause-circle me-1"></i> Set Inactive
                              </button>
                            </form>
                          <?php else: ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_student_status">
                              <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                              <input type="hidden" name="status" value="active">
                              <button type="submit" class="btn btn-sm btn-outline-success px-3 rounded-pill fw-semibold"
                                title="Activate Student Account">
                                <i class="bi bi-play-circle me-1"></i> Set Active
                              </button>
                            </form>
                          <?php endif; ?>

                          <!-- Send Notification Message Button -->
                          <button type="button" class="btn btn-sm btn-outline-info px-3 rounded-pill fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#sendStudentMessageModal_<?php echo $student['id']; ?>"
                            title="Send Notification to Student Bell">
                            <i class="bi bi-chat-dots-fill me-1"></i> Message
                          </button>

                          <!-- Delete Student Button -->
                          <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-pill fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#deleteStudentModal_<?php echo $student['id']; ?>"
                            title="Delete Student Account">
                            <i class="bi bi-trash3-fill me-1"></i> Delete
                          </button>
                        </div>

                        <!-- 1. VIEW PROFILE (ADMIN PREVIEW MODAL) -->
                        <div class="modal fade text-start" id="viewStudentModal_<?php echo $student['id']; ?>" tabindex="-1"
                          aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                              <div class="modal-header border-0 pb-0">
                                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                                  <i class="bi bi-person-badge text-primary fs-4"></i> Admin Preview: Student Profile
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body p-4">
                                <div class="d-flex flex-wrap align-items-center gap-3 p-3 bg-light rounded-3 mb-4 border">
                                  <img src="<?php echo htmlspecialchars($student_avatar_src); ?>" alt="Avatar"
                                    class="rounded-circle border" style="width: 64px; height: 64px; object-fit: cover;">
                                  <div>
                                    <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($s_name); ?></h4>
                                    <div class="d-flex flex-wrap align-items-center gap-2 fs-8">
                                      <span class="badge bg-secondary rounded-pill">Role: Student</span>
                                      <span
                                        class="badge <?php echo $s_status === 'active' ? 'bg-success' : 'bg-danger'; ?> rounded-pill">Status:
                                        <?php echo ucfirst($s_status); ?></span>
                                      <span class="text-muted"><i class="bi bi-hash"></i> Academic ID:
                                        <strong><?php echo htmlspecialchars($student['academic_id'] ?? 'N/A'); ?></strong></span>
                                    </div>
                                  </div>
                                </div>

                                <!-- Metadata Grid -->
                                <div class="row g-3 mb-4 fs-8">
                                  <div class="col-md-6">
                                    <div class="p-3 border rounded-3 bg-white">
                                      <strong class="text-dark d-block mb-1"><i
                                          class="bi bi-envelope me-1 text-primary"></i> Email Address:</strong>
                                      <span class="text-secondary"><?php echo htmlspecialchars($student['email']); ?></span>
                                    </div>
                                  </div>
                                  <div class="col-md-6">
                                    <div class="p-3 border rounded-3 bg-white">
                                      <strong class="text-dark d-block mb-1"><i
                                          class="bi bi-telephone me-1 text-primary"></i> Phone Number:</strong>
                                      <span
                                        class="text-secondary"><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                                    </div>
                                  </div>
                                  <div class="col-md-12">
                                    <div class="p-3 border rounded-3 bg-white">
                                      <strong class="text-dark d-block mb-1"><i class="bi bi-geo-alt me-1 text-primary"></i>
                                        Address:</strong>
                                      <span
                                        class="text-secondary"><?php echo htmlspecialchars($student['address'] ?? 'No address listed.'); ?></span>
                                    </div>
                                  </div>
                                  <div class="col-md-12">
                                    <div class="p-3 border rounded-3 bg-white">
                                      <strong class="text-dark d-block mb-1"><i
                                          class="bi bi-card-text me-1 text-primary"></i> Student Bio:</strong>
                                      <span
                                        class="text-secondary"><?php echo nl2br(htmlspecialchars($student['bio'] ?? 'No bio provided.')); ?></span>
                                    </div>
                                  </div>
                                </div>

                                <!-- Enrolled Courses -->
                                <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                                  <i class="bi bi-journal-bookmark-fill text-primary"></i> Enrolled Courses
                                  (<?php echo count($student_courses); ?>)
                                </h6>
                                <?php if (empty($student_courses)): ?>
                                  <div class="p-3 bg-light border rounded-3 text-center text-muted fs-8 italic mb-3">
                                    This student is not enrolled in any courses yet.
                                  </div>
                                <?php else: ?>
                                  <div class="list-group mb-3 fs-8">
                                    <?php foreach ($student_courses as $sc): ?>
                                      <div class="list-group-item d-flex align-items-center justify-content-between py-2.5">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($sc['title']); ?></div>
                                        <div class="d-flex align-items-center gap-2">
                                          <span
                                            class="badge bg-light text-secondary border"><?php echo htmlspecialchars($sc['category'] ?? 'Course'); ?></span>
                                          <span class="badge bg-success bg-opacity-10 text-success border border-success">Rs.
                                            <?php echo number_format($sc['price'], 2); ?></span>
                                        </div>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php endif; ?>

                                <!-- Quiz Performance History -->
                                <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                                  <i class="bi bi-patch-check-fill text-success"></i> Quiz Attempt History
                                  (<?php echo count($student_quizzes); ?>)
                                </h6>
                                <?php if (empty($student_quizzes)): ?>
                                  <div class="p-3 bg-light border rounded-3 text-center text-muted fs-8 italic">
                                    No quiz attempts recorded yet.
                                  </div>
                                <?php else: ?>
                                  <div class="list-group fs-8">
                                    <?php foreach ($student_quizzes as $sq): ?>
                                      <div class="list-group-item d-flex align-items-center justify-content-between py-2">
                                        <span><?php echo htmlspecialchars($sq['course_title']); ?></span>
                                        <span class="badge bg-success text-white px-3 py-1.5 rounded-pill fw-bold fs-8 shadow-sm">
                                          <i class="bi bi-award-fill me-1"></i> Score: <?php echo intval($sq['score']); ?>/<?php echo intval($sq['total_questions'] ?? 0); ?>
                                        </span>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                              <div class="modal-footer border-0 pt-0">
                                <button type="button" class="btn btn-secondary rounded-pill px-4"
                                  data-bs-dismiss="modal">Close Profile</button>
                              </div>
                            </div>
                          </div>
                        </div>

                        <!-- 2. SEND NOTIFICATION MESSAGE MODAL -->
                        <div class="modal fade text-start" id="sendStudentMessageModal_<?php echo $student['id']; ?>"
                          tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                              <form action="index.php" method="POST">
                                <input type="hidden" name="action" value="send_student_notification">
                                <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                <div class="modal-header border-0 pb-0">
                                  <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                                    <i class="bi bi-chat-dots-fill text-info fs-5"></i> Send Notification Message
                                  </h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                                </div>
                                <div class="modal-body py-3">
                                  <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                                    <img src="<?php echo htmlspecialchars($student_avatar_src); ?>" alt="Avatar"
                                      class="rounded-circle border" style="width: 40px; height: 40px; object-fit: cover;">
                                    <div>
                                      <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($s_name); ?></div>
                                      <div class="fs-8 text-muted"><?php echo htmlspecialchars($student['email']); ?></div>
                                    </div>
                                  </div>
                                  <div class="mb-2">
                                    <label class="form-label fw-bold text-dark fs-8">Notification Message Content:</label>
                                    <textarea name="message" class="form-control rounded-3" rows="4"
                                      placeholder="Type your message here... This will trigger a notification bell badge alert on the student's header."
                                      required></textarea>
                                  </div>
                                  <small class="text-muted fs-8"><i class="bi bi-bell-fill me-1 text-warning"></i>Delivered
                                    directly to student's notification bell.</small>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                  <button type="button" class="btn btn-light rounded-pill px-4"
                                    data-bs-dismiss="modal">Cancel</button>
                                  <button type="submit" class="btn btn-info text-white rounded-pill px-4 fw-bold shadow-sm">
                                    <i class="bi bi-send-fill me-1"></i> Send Notification
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>

                        <!-- 3. DELETE STUDENT CONFIRMATION MODAL -->
                        <div class="modal fade text-start" id="deleteStudentModal_<?php echo $student['id']; ?>"
                          tabindex="-1" aria-hidden="true">
                          <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                              <form action="index.php" method="POST">
                                <input type="hidden" name="action" value="delete_student">
                                <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                <div class="modal-header border-0 pb-0">
                                  <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                                    <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i> Confirm Student
                                    Deletion
                                  </h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                                </div>
                                <div class="modal-body py-3">
                                  <p class="fs-7 text-secondary mb-3">
                                    Are you sure you want to permanently delete student account
                                    <strong><?php echo htmlspecialchars($s_name); ?></strong>
                                    (<?php echo htmlspecialchars($student['email']); ?>)? All enrollments and quiz records
                                    for this student will be removed.
                                  </p>
                                  <div class="mb-2">
                                    <label class="form-label fw-bold text-dark fs-8">Enter Admin Password to Confirm
                                      Deletion:</label>
                                    <input type="password" name="admin_password" class="form-control rounded-3"
                                      placeholder="Enter your admin account password" required>
                                  </div>
                                </div>
                                <div class="modal-footer border-0 pt-0">
                                  <button type="button" class="btn btn-light rounded-pill px-4"
                                    data-bs-dismiss="modal">Cancel</button>
                                  <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Delete
                                    Student</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>

                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Course Requests Section -->
      <div id="courses-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark">Course Publication Requests</h2>
          <p class="text-secondary">Review and approve new course modules, updated syllabus details, and lesson
            materials submitted by educators.</p>
        </div>

        <div class="glass-card p-4">
          <?php if (count($courses) === 0): ?>
            <div class="text-center py-5">
              <i class="bi bi-journal-x text-muted fs-1"></i>
              <p class="text-muted mt-3 mb-0">No course publication requests found in the system.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="py-3 border-0">Course Details</th>
                    <th scope="col" class="py-3 border-0">Instructor</th>
                    <th scope="col" class="py-3 border-0">Category & Level</th>
                    <th scope="col" class="py-3 border-0">Price</th>
                    <th scope="col" class="py-3 border-0">Status</th>
                    <th scope="col" class="py-3 border-0 text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($courses as $course): ?>
                    <?php
                    $tutor_name = !empty($course['tutor_name']) ? $course['tutor_name'] : 'Educator / Instructor';
                    $raw_thumb = trim($course['thumbnail'] ?? '');
                    $raw_avatar = trim($course['tutor_avatar'] ?? '');

                    // Normalize relative paths for admin subfolder
                    $thumb_src = empty($raw_thumb) ? '../assets/images/course-1.jpg' : ((preg_match('~^https?://~i', $raw_thumb) || strpos($raw_thumb, 'data:') === 0) ? $raw_thumb : '../' . ltrim($raw_thumb, '/'));
                    $avatar_src = empty($raw_avatar) ? 'https://ui-avatars.com/api/?name=' . urlencode($tutor_name) . '&background=0f4c81&color=fff' : ((preg_match('~^https?://~i', $raw_avatar) || strpos($raw_avatar, 'data:') === 0) ? $raw_avatar : '../' . ltrim($raw_avatar, '/'));
                    ?>
                    <tr id="course-row-<?php echo htmlspecialchars($course['id']); ?>">
                      <td class="py-3">
                        <div class="d-flex align-items-center gap-3">
                          <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                            onerror="this.onerror=null; this.src='../assets/images/course-1.jpg';"
                            alt="<?php echo htmlspecialchars($course['title']); ?>" class="rounded shadow-sm"
                            style="width: 70px; height: 45px; object-fit: cover; border: 1px solid #e5e7eb;">
                          <div>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($course['title']); ?></div>
                            <div class="fs-8 text-muted"><i
                                class="bi bi-clock me-1"></i><?php echo htmlspecialchars($course['duration']); ?> Weeks |
                              Slug: <?php echo htmlspecialchars($course['id']); ?></div>
                            <div class="mt-1">
                              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 rounded-pill fs-9">
                                <i class="bi bi-people-fill me-1"></i><?php echo (int)($course['live_enrolled_count'] ?? 0); ?> <?php echo __('enrolled_students', 'Students'); ?>
                              </span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td class="py-3">
                        <div class="d-flex align-items-center gap-2">
                          <img src="<?php echo htmlspecialchars($avatar_src); ?>"
                            onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($tutor_name); ?>&background=0f4c81&color=fff';"
                            alt="<?php echo htmlspecialchars($tutor_name); ?>" class="rounded-circle border shadow-sm"
                            style="width: 28px; height: 28px; object-fit: cover;">
                          <span class="fw-semibold text-secondary fs-7"><?php echo htmlspecialchars($tutor_name); ?></span>
                        </div>
                      </td>
                      <td class="py-3">
                        <div class="fw-semibold text-primary fs-7"><?php echo htmlspecialchars($course['category']); ?>
                        </div>
                        <span
                          class="badge bg-light text-dark border fs-9"><?php echo htmlspecialchars($course['level']); ?></span>
                      </td>
                      <td class="py-3 fw-bold text-dark fs-7">
                        <?php echo floatval($course['price']) > 0 ? 'Rs. ' . number_format($course['price'], 2) : 'Free'; ?>
                      </td>
                      <td class="py-3" id="course-status-badge-<?php echo htmlspecialchars($course['id']); ?>">
                        <?php if (($course['status'] ?? 'approved') === 'pending'): ?>
                          <span class="status-badge-pending"><i class="bi bi-clock me-1"></i> Pending Review</span>
                        <?php elseif ($course['status'] === 'disabled' || !empty($course['is_archived'])): 
                          $d_time = !empty($course['deleted_at']) ? strtotime($course['deleted_at']) : time();
                          $d_left = max(0, 14 - floor((time() - $d_time) / 86400));
                        ?>
                          <span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-2 py-1 fs-9 rounded-pill"><i class="bi bi-clock-history me-1 text-danger"></i> Disabled (<?php echo $d_left; ?>d left)</span>
                        <?php elseif (($course['status'] ?? 'approved') === 'approved' || ($course['status'] ?? '') === 'active'): ?>
                          <span class="status-badge-active"><i class="bi bi-check-circle me-1"></i> Approved</span>
                        <?php else: ?>
                          <span
                            class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-35 px-2 py-1 fs-9 rounded-pill"><i
                              class="bi bi-x-circle me-1"></i> Rejected</span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-end">
                        <div class="d-flex justify-content-end align-items-center gap-1.5 flex-wrap">
                          <!-- Course Preview in Admin Preview Mode -->
                          <a href="../classroom.php?course_id=<?php echo htmlspecialchars($course['id']); ?>&admin_preview=1"
                            target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 shadow-sm"
                            title="Preview Course in Admin Mode">
                            <i class="bi bi-eye"></i> Preview
                          </a>

                          <!-- Quick Disable / Enable Toggle Button -->
                          <?php if (($course['status'] ?? 'approved') === 'disabled' || !empty($course['is_archived'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2.5 btn-toggle-course-status shadow-sm"
                              id="toggle-btn-<?php echo htmlspecialchars($course['id']); ?>"
                              data-course-id="<?php echo htmlspecialchars($course['id']); ?>"
                              data-current-status="disabled"
                              data-course-title="<?php echo htmlspecialchars($course['title']); ?>"
                              title="<?php echo __('quick_enable', 'Quick Enable'); ?>">
                              <i class="bi bi-play-circle-fill me-1"></i> <?php echo __('quick_enable', 'Enable'); ?>
                            </button>
                          <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2.5 btn-toggle-course-status shadow-sm"
                              id="toggle-btn-<?php echo htmlspecialchars($course['id']); ?>"
                              data-course-id="<?php echo htmlspecialchars($course['id']); ?>"
                              data-current-status="<?php echo htmlspecialchars($course['status'] ?? 'approved'); ?>"
                              data-course-title="<?php echo htmlspecialchars($course['title']); ?>"
                              title="<?php echo __('quick_disable', 'Quick Disable'); ?>">
                              <i class="bi bi-pause-circle-fill me-1"></i> <?php echo __('quick_disable', 'Disable'); ?>
                            </button>
                          <?php endif; ?>

                          <!-- Secure Hard Delete Course Button (Red Trash Icon) -->
                          <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2.5 btn-admin-delete-course shadow-sm"
                            data-course-id="<?php echo htmlspecialchars($course['id']); ?>"
                            data-course-title="<?php echo htmlspecialchars($course['title']); ?>"
                            data-enrolled-count="<?php echo (int)($course['live_enrolled_count'] ?? 0); ?>"
                            title="<?php echo __('secure_delete_course', 'Delete Course'); ?>">
                            <i class="bi bi-trash3-fill"></i>
                          </button>

                          <?php if (($course['status'] ?? 'approved') === 'pending'): ?>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="approve_course">
                              <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course['id']); ?>">
                              <button type="submit" class="btn btn-sm btn-success rounded-pill px-2.5 fw-semibold shadow-sm">
                                <i class="bi bi-check2"></i> Approve
                              </button>
                            </form>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="reject_course">
                              <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course['id']); ?>">
                              <button type="submit" class="btn btn-sm btn-danger rounded-pill px-2.5 fw-semibold shadow-sm">
                                <i class="bi bi-x-lg"></i> Reject
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Bank Slip Approvals Section -->
      <div id="bank-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark">Bank Slip Approvals</h2>
          <p class="text-secondary">Review uploaded student bank receipts and approve course access.</p>
        </div>

        <div class="glass-card p-4">
          <?php if (count($bank_payments) === 0): ?>
            <div class="text-center py-5">
              <i class="bi bi-bank text-muted fs-1"></i>
              <p class="text-muted mt-3 mb-0">No bank slip payment submissions found in the system.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="py-3 border-0">Student Details</th>
                    <th scope="col" class="py-3 border-0">Course Details</th>
                    <th scope="col" class="py-3 border-0">Receipt Slip</th>
                    <th scope="col" class="py-3 border-0">Submitted On</th>
                    <th scope="col" class="py-3 border-0">Status</th>
                    <th scope="col" class="py-3 border-0 text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bank_payments as $slip): ?>
                    <tr>
                      <td class="py-3">
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($slip['full_name']); ?></div>
                        <div class="fs-8 text-muted"><?php echo htmlspecialchars($slip['student_email']); ?> (ID:
                          <?php echo htmlspecialchars($slip['user_id']); ?>)
                        </div>
                      </td>
                      <td class="py-3">
                        <div class="fw-semibold text-secondary fs-7"><?php echo htmlspecialchars($slip['course_title']); ?>
                        </div>
                        <div class="text-muted fs-8">Price: Rs. <?php echo number_format($slip['course_price'], 2); ?></div>
                      </td>
                      <td class="py-3">
                        <div class="d-flex align-items-center gap-2">
                          <?php
                          $is_pdf = (strtolower(pathinfo($slip['slip_path'], PATHINFO_EXTENSION)) === 'pdf');
                          if ($is_pdf):
                            ?>
                            <div
                              class="d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger rounded border"
                              style="width: 40px; height: 40px;">
                              <i class="bi bi-file-pdf fs-5"></i>
                            </div>
                          <?php else: ?>
                            <img src="../<?php echo htmlspecialchars($slip['slip_path']); ?>" alt="Receipt Slip"
                              class="rounded border cursor-pointer" style="width: 40px; height: 40px; object-fit: cover;"
                              onclick="window.open('../<?php echo htmlspecialchars($slip['slip_path']); ?>', '_blank')">
                          <?php endif; ?>
                          <a href="../<?php echo htmlspecialchars($slip['slip_path']); ?>" target="_blank"
                            class="fs-8 text-primary text-decoration-none fw-semibold">
                            <i class="bi bi-box-arrow-up-right me-1"></i>View
                          </a>
                        </div>
                      </td>
                      <td class="py-3 fs-8 text-secondary">
                        <?php echo date('Y-m-d H:i', strtotime($slip['created_at'])); ?>
                      </td>
                      <td class="py-3">
                        <?php if (($slip['status'] ?? 'pending') === 'pending'): ?>
                          <span class="status-badge-pending">Pending Review</span>
                        <?php elseif (($slip['status'] ?? '') === 'approved'): ?>
                          <span class="status-badge-active">Approved</span>
                        <?php else: ?>
                          <span
                            class="badge bg-danger bg-opacity-10 text-danger px-2.5 py-1.5 rounded-pill fs-8 fw-bold">Rejected</span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-end">
                        <?php if (($slip['status'] ?? 'pending') === 'pending'): ?>
                          <div class="d-flex justify-content-end gap-2">
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="approve_slip">
                              <input type="hidden" name="slip_id" value="<?php echo htmlspecialchars($slip['id']); ?>">
                              <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold">
                                <i class="bi bi-check2"></i> Approve
                              </button>
                            </form>
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="reject_slip">
                              <input type="hidden" name="slip_id" value="<?php echo htmlspecialchars($slip['id']); ?>">
                              <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold">
                                <i class="bi bi-x-lg"></i> Reject
                              </button>
                            </form>
                          </div>
                        <?php else: ?>
                          <span class="text-muted fs-8">Reviewed</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Manage Bank Details Section -->
      <div id="manage-bank-section" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 class="fw-bold text-dark mb-1">Bank Account Management</h2>
            <p class="text-secondary mb-0">Configure and update the owner bank accounts displayed to students on the
              checkout payment page.</p>
          </div>
          <button type="button"
            class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm d-flex align-items-center gap-2"
            style="background-color: #0f4c81; border: none;" data-bs-toggle="modal" data-bs-target="#addBankModal">
            <i class="bi bi-plus-circle"></i> Add New Bank Account
          </button>
        </div>

        <div class="glass-card p-4">
          <?php if (count($managed_bank_accounts) === 0): ?>
            <div class="text-center py-5">
              <i class="bi bi-bank2 text-muted fs-1"></i>
              <p class="text-muted mt-3 mb-0">No bank account details configured yet. Click "Add New Bank Account" to
                create one.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="py-3 border-0">Option / Badge</th>
                    <th scope="col" class="py-3 border-0">Bank Name</th>
                    <th scope="col" class="py-3 border-0">Branch</th>
                    <th scope="col" class="py-3 border-0">Account Number</th>
                    <th scope="col" class="py-3 border-0">Account Name</th>
                    <th scope="col" class="py-3 border-0">Status</th>
                    <th scope="col" class="py-3 border-0 text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($managed_bank_accounts as $acc): ?>
                    <tr>
                      <td class="py-3">
                        <span
                          class="badge bg-primary bg-opacity-10 text-primary fs-8"><?php echo htmlspecialchars($acc['option_label'] ?: 'Option'); ?></span>
                      </td>
                      <td class="py-3 fw-bold text-dark">
                        <?php echo htmlspecialchars($acc['bank_name']); ?>
                      </td>
                      <td class="py-3 text-secondary fs-7">
                        <?php echo htmlspecialchars($acc['branch']); ?>
                      </td>
                      <td class="py-3 font-monospace fw-bold text-dark fs-7">
                        <code><?php echo htmlspecialchars($acc['account_number']); ?></code>
                      </td>
                      <td class="py-3 text-secondary fs-7">
                        <?php echo htmlspecialchars($acc['account_name']); ?>
                      </td>
                      <td class="py-3">
                        <?php if ($acc['status'] === 'active'): ?>
                          <span class="status-badge-active"><i class="bi bi-check-circle me-1"></i> Active</span>
                        <?php else: ?>
                          <span
                            class="badge bg-secondary bg-opacity-10 text-secondary px-2.5 py-1 rounded-pill fs-8">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="py-3 text-end">
                        <div class="d-flex justify-content-end gap-2">
                          <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold"
                            onclick='openEditBankModal(<?php echo json_encode($acc, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="bi bi-pencil me-1"></i> Edit
                          </button>
                          <form action="index.php" method="POST" class="d-inline"
                            onsubmit="return confirm('Are you sure you want to delete this bank account?');">
                            <input type="hidden" name="action" value="delete_bank_account">
                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                              <i class="bi bi-trash me-1"></i> Delete
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Site Announcements & Promotional Banners Section -->
      <div id="announcements-section" class="d-none">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
          <div>
            <h2 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
              <i class="bi bi-megaphone-fill text-warning"></i> Announcements & Featured Slider
            </h2>
            <p class="text-secondary mb-0">Manage auto-swiping featured announcements hero slider and standard site notices.</p>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm d-flex align-items-center gap-2"
              style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;"
              data-bs-toggle="modal" data-bs-target="#addBannerModal">
              <i class="bi bi-plus-circle"></i> Add Announcement
            </button>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 fw-semibold shadow-sm d-flex align-items-center gap-2"
              data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
              <i class="bi bi-card-text"></i> Add Text Notice
            </button>
          </div>
        </div>

        <!-- Sub-Navigation Pills -->
        <ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-4 shadow-sm border" id="announcementsSubTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill px-4 py-2 fw-semibold d-flex align-items-center gap-2"
              id="subtab-banners-btn" data-bs-toggle="pill" data-bs-target="#subtab-banners-content" type="button" role="tab">
              <i class="bi bi-images text-primary"></i>
              <span>Featured Announcements</span>
              <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill ms-1"><?php echo count($all_promotional_banners); ?></span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 py-2 fw-semibold d-flex align-items-center gap-2"
              id="subtab-text-btn" data-bs-toggle="pill" data-bs-target="#subtab-text-content" type="button" role="tab">
              <i class="bi bi-bell-fill text-warning"></i>
              <span>Standard Text Notices</span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill ms-1"><?php echo count($all_site_announcements); ?></span>
            </button>
          </li>
        </ul>

        <!-- Tab Content Containers -->
        <div class="tab-content" id="announcementsSubTabContent">
          
          <!-- TAB 1: Featured Announcements (Slider) -->
          <div class="tab-pane fade show active" id="subtab-banners-content" role="tabpanel">
            <div class="glass-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                  <h5 class="fw-bold mb-0 text-dark">Visual Featured Carousel Slider</h5>
                  <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 fs-8">
                    <?php 
                      $active_count = count(array_filter($all_promotional_banners, function($b) { return $b['is_active'] == 1; }));
                      echo $active_count . ' Active on Homepage'; 
                    ?>
                  </span>
                </div>
                <div class="text-muted fs-8">
                  <i class="bi bi-info-circle me-1"></i> Auto-swipes every 4.5s on Homepage (Pause on Hover)
                </div>
              </div>

              <?php if (count($all_promotional_banners) === 0): ?>
                <div class="text-center py-5">
                  <div class="bg-light rounded-circle d-inline-flex p-4 mb-3">
                    <i class="bi bi-images text-muted fs-1"></i>
                  </div>
                  <h6 class="fw-bold text-dark mb-1">No Featured Announcements Created</h6>
                  <p class="text-muted fs-7 mb-3">Add eye-catching announcements with uncropped images and clickable website links in description modals.</p>
                  <button type="button" class="btn btn-sm btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addBannerModal">
                    <i class="bi bi-plus-circle me-1"></i> Create First Announcement
                  </button>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col" class="py-3 border-0 text-center" style="width: 90px;">Order</th>
                        <th scope="col" class="py-3 border-0" style="width: 140px;">Image</th>
                        <th scope="col" class="py-3 border-0" style="width: 240px;">Title & Subtitle</th>
                        <th scope="col" class="py-3 border-0">Announcement Details (with Links)</th>
                        <th scope="col" class="py-3 border-0 text-center" style="width: 120px;">Display State</th>
                        <th scope="col" class="py-3 border-0 text-end" style="width: 140px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($all_promotional_banners as $banner): ?>
                        <tr>
                          <!-- Order & Quick Move -->
                          <td class="py-3 text-center">
                            <div class="d-flex flex-column align-items-center gap-1">
                              <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1 fs-8 fw-bold">#<?php echo $banner['display_order']; ?></span>
                              <div class="btn-group btn-group-sm">
                                <form action="index.php" method="POST" class="d-inline">
                                  <input type="hidden" name="action" value="move_banner_order">
                                  <input type="hidden" name="banner_id" value="<?php echo $banner['id']; ?>">
                                  <input type="hidden" name="direction" value="up">
                                  <button type="submit" class="btn btn-xs btn-outline-secondary py-0 px-1" title="Move Up"><i class="bi bi-chevron-up"></i></button>
                                </form>
                                <form action="index.php" method="POST" class="d-inline">
                                  <input type="hidden" name="action" value="move_banner_order">
                                  <input type="hidden" name="banner_id" value="<?php echo $banner['id']; ?>">
                                  <input type="hidden" name="direction" value="down">
                                  <button type="submit" class="btn btn-xs btn-outline-secondary py-0 px-1" title="Move Down"><i class="bi bi-chevron-down"></i></button>
                                </form>
                              </div>
                            </div>
                          </td>

                          <!-- Thumbnail Image -->
                          <td class="py-3">
                            <div class="position-relative rounded-3 overflow-hidden shadow-sm border bg-dark" style="width: 130px; height: 75px;">
                              <img src="<?php echo htmlspecialchars((strpos($banner['image_path'], 'http') === 0) ? $banner['image_path'] : '../' . $banner['image_path']); ?>" alt="Announcement" class="w-100 h-100 object-fit-cover">
                              <span class="position-absolute bottom-0 end-0 bg-dark bg-opacity-75 text-white fs-9 px-1.5 py-0.5 rounded-top-start">
                                <i class="bi bi-aspect-ratio"></i>
                              </span>
                            </div>
                          </td>

                          <!-- Title & Subtitle -->
                          <td class="py-3">
                            <div class="fw-bold text-dark mb-0.5 fs-7"><?php echo htmlspecialchars($banner['title']); ?></div>
                            <?php if (!empty($banner['subtitle'])): ?>
                              <div class="text-secondary fs-8"><?php echo htmlspecialchars($banner['subtitle']); ?></div>
                            <?php endif; ?>
                          </td>

                          <!-- Announcement Details snippet with links -->
                          <td class="py-3">
                            <div class="text-muted fs-8 leading-normal" style="max-width: 420px; max-height: 80px; overflow-y: auto; word-break: break-word;">
                              <?php
                                // Show preview of content with auto-link detection
                                $content_preview = strip_tags($banner['details_content']);
                                $linked_preview = preg_replace('~(https?://[^\s<]+|www\.[^\s<]+)~i', '<a href="$1" target="_blank" class="text-primary text-decoration-underline">$1</a>', htmlspecialchars($content_preview));
                                echo $linked_preview;
                              ?>
                            </div>
                          </td>

                          <!-- Display Status Active Toggle -->
                          <td class="py-3 text-center">
                            <form action="index.php" method="POST" class="d-inline">
                              <input type="hidden" name="action" value="toggle_banner_status">
                              <input type="hidden" name="banner_id" value="<?php echo $banner['id']; ?>">
                              <button type="submit" class="btn btn-sm border-0 rounded-pill px-3 py-1 fw-semibold fs-8 <?php echo $banner['is_active'] ? 'btn-success bg-success text-white' : 'btn-light text-muted border'; ?>" title="Click to toggle status">
                                <?php if ($banner['is_active']): ?>
                                  <i class="bi bi-check-circle-fill me-1"></i> Active
                                <?php else: ?>
                                  <i class="bi bi-pause-circle me-1"></i> Hidden
                                <?php endif; ?>
                              </button>
                            </form>
                          </td>

                          <!-- Actions -->
                          <td class="py-3 text-end">
                            <div class="d-flex justify-content-end gap-1.5">
                              <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold"
                                onclick='openEditBannerModal(<?php echo json_encode($banner, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil me-1"></i> Edit
                              </button>
                              <form action="index.php" method="POST" class="d-inline"
                                onsubmit="return confirm('Are you sure you want to delete this featured announcement?');">
                                <input type="hidden" name="action" value="delete_banner">
                                <input type="hidden" name="banner_id" value="<?php echo $banner['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2.5" title="Delete">
                                  <i class="bi bi-trash"></i>
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- TAB 2: Standard Text Announcements -->
          <div class="tab-pane fade" id="subtab-text-content" role="tabpanel">
            <div class="glass-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">Standard Site Notices</h5>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold"
                  data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                  <i class="bi bi-plus-circle me-1"></i> New Notice
                </button>
              </div>

              <?php if (count($all_site_announcements) === 0): ?>
                <div class="text-center py-5">
                  <i class="bi bi-megaphone text-muted fs-1"></i>
                  <p class="text-muted mt-3 mb-0">No text announcements created yet.</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col" class="py-3 border-0" style="width: 140px;">Category</th>
                        <th scope="col" class="py-3 border-0">Title & Content</th>
                        <th scope="col" class="py-3 border-0" style="width: 140px;">Badge / Date</th>
                        <th scope="col" class="py-3 border-0" style="width: 100px;">Status</th>
                        <th scope="col" class="py-3 border-0 text-end" style="width: 140px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($all_site_announcements as $ann): ?>
                        <?php 
                          $cat = $ann['category'] ?? 'notice';
                          $cat_badge = '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-8"><i class="bi bi-megaphone me-1"></i> Notice</span>';
                          if ($cat === 'offer') {
                            $cat_badge = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fs-8">🎉 Special Offer</span>';
                          } elseif ($cat === 'launch') {
                            $cat_badge = '<span class="badge bg-purple bg-opacity-10 text-purple border border-purple border-opacity-25 rounded-pill px-2.5 py-1 fs-8" style="color: #6f42c1; background-color: rgba(111, 66, 193, 0.1);">🚀 Course Launch</span>';
                          } elseif ($cat === 'alert') {
                            $cat_badge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1 fs-8">⚡ Urgent Alert</span>';
                          } elseif ($cat === 'event') {
                            $cat_badge = '<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-2.5 py-1 fs-8">📅 Event</span>';
                          }
                        ?>
                        <tr>
                          <td class="py-3"><?php echo $cat_badge; ?></td>
                          <td class="py-3">
                            <div class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($ann['title']); ?></div>
                            <div class="text-muted fs-8 text-truncate" style="max-width: 380px;" title="<?php echo htmlspecialchars($ann['content']); ?>">
                              <?php echo htmlspecialchars($ann['content']); ?>
                            </div>
                          </td>
                          <td class="py-3">
                            <span class="badge bg-light text-dark border fs-8"><?php echo htmlspecialchars($ann['badge_text'] ?: date('M d, Y', strtotime($ann['created_at']))); ?></span>
                          </td>
                          <td class="py-3">
                            <?php if ($ann['status'] === 'active'): ?>
                              <span class="status-badge-active"><i class="bi bi-check-circle me-1"></i> Active</span>
                            <?php else: ?>
                              <span class="badge bg-secondary bg-opacity-10 text-secondary px-2.5 py-1 rounded-pill fs-8">Inactive</span>
                            <?php endif; ?>
                          </td>
                          <td class="py-3 text-end">
                            <div class="d-flex justify-content-end gap-1.5">
                              <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold"
                                onclick='openEditAnnouncementModal(<?php echo json_encode($ann, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil me-1"></i> Edit
                              </button>
                              <form action="index.php" method="POST" class="d-inline"
                                onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                <input type="hidden" name="action" value="delete_announcement">
                                <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2.5">
                                  <i class="bi bi-trash"></i>
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Hero Banner Customization Section -->
      <div id="hero-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-layout-text-window-reverse text-success"></i>
            <span>Homepage Hero Section Management</span>
          </h2>
          <p class="text-secondary mb-0">Customize the hero banner title, subtitle description, call-to-action buttons, and manage the featured student portrait image.</p>
        </div>

        <div class="row g-4">
          <!-- Card 1: Hero Banner Content -->
          <div class="col-lg-7">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <form action="index.php" method="POST">
                <input type="hidden" name="action" value="update_hero_settings">

                <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                  <h5 class="fw-bold text-dark mb-0 fs-6 d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square text-primary"></i>
                    <span>Hero Banner Content &amp; Buttons</span>
                  </h5>
                  <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    Text &amp; Links
                  </span>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-bold text-dark fs-8">Hero Title <span class="text-danger">*</span></label>
                  <input type="text" name="title" class="form-control"
                    value="<?php echo htmlspecialchars($hero_settings['title'] ?? ''); ?>" required>
                  <small class="text-muted fs-9">Headline displayed at the top of the homepage hero banner.</small>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-bold text-dark fs-8">Subtitle / Description <span class="text-danger">*</span></label>
                  <textarea name="description" class="form-control" rows="3"
                    required><?php echo htmlspecialchars($hero_settings['description'] ?? ''); ?></textarea>
                  <small class="text-muted fs-9">Supporting text explaining the platform and offerings.</small>
                </div>

                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Primary Button Text <span class="text-danger">*</span></label>
                    <input type="text" name="button_text" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['button_text'] ?? 'Apply Now'); ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Primary Button Link URL <span class="text-danger">*</span></label>
                    <input type="text" name="button_url" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['button_url'] ?? '#courses-section'); ?>" required>
                  </div>
                </div>

                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Secondary Button Text</label>
                    <input type="text" name="secondary_button_text" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['secondary_button_text'] ?? 'Know More'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Secondary Button Link URL</label>
                    <input type="text" name="secondary_button_url" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['secondary_button_url'] ?? '#courses-section'); ?>">
                  </div>
                </div>

                <div class="row g-3 mb-4">
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Phone Contact Text</label>
                    <input type="text" name="phone_number" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['phone_number'] ?? 'Call Us : 011 234 5678'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-bold text-dark fs-8">Enrolled Students Pill Badge</label>
                    <input type="text" name="enrolled_students_count" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['enrolled_students_count'] ?? '30K Enrolled Students'); ?>">
                  </div>
                </div>

                <!-- Social Media Channels Section -->
                <div class="border-top pt-3 mt-4">
                  <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                    <h6 class="fw-bold text-dark mb-0 fs-7 d-flex align-items-center gap-2">
                      <i class="bi bi-share text-primary"></i>
                      <span><?php echo __('hero_social_links_title', 'Social Media Links & Contact Channels'); ?></span>
                    </h6>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                      Hero Icons
                    </span>
                  </div>
                  <p class="text-secondary fs-9 mb-3">
                    <?php echo __('hero_social_links_desc', 'Customize the direct links for the social media icons shown in the homepage hero banner.'); ?>
                  </p>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-facebook text-primary"></i>
                        <span>Facebook URL</span>
                      </label>
                      <input type="text" name="facebook_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['facebook_url'] ?? '#'); ?>"
                        placeholder="https://facebook.com/yourpage">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-twitter-x text-dark"></i>
                        <span>Twitter / X URL</span>
                      </label>
                      <input type="text" name="twitter_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['twitter_url'] ?? '#'); ?>"
                        placeholder="https://x.com/yourhandle">
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-telegram text-info"></i>
                        <span>Telegram Channel / Group URL</span>
                      </label>
                      <input type="text" name="telegram_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['telegram_url'] ?? '#'); ?>"
                        placeholder="https://t.me/yourchannel">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-instagram text-danger"></i>
                        <span>Instagram URL</span>
                      </label>
                      <input type="text" name="instagram_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['instagram_url'] ?? '#'); ?>"
                        placeholder="https://instagram.com/yourprofile">
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-youtube text-danger"></i>
                        <span>YouTube Channel URL</span>
                      </label>
                      <input type="text" name="youtube_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['youtube_url'] ?? ''); ?>"
                        placeholder="https://youtube.com/@yourchannel (Optional)">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-whatsapp text-success"></i>
                        <span>WhatsApp Link / Number</span>
                      </label>
                      <input type="text" name="whatsapp_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['whatsapp_url'] ?? ''); ?>"
                        placeholder="https://wa.me/94771234567 (Optional)">
                    </div>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-12">
                      <label class="form-label fw-bold text-dark fs-9 d-flex align-items-center gap-1.5 mb-1">
                        <i class="bi bi-linkedin text-primary"></i>
                        <span>LinkedIn Page URL</span>
                      </label>
                      <input type="text" name="linkedin_url" class="form-control form-control-sm"
                        value="<?php echo htmlspecialchars($hero_settings['linkedin_url'] ?? ''); ?>"
                        placeholder="https://linkedin.com/company/yourorg (Optional)">
                    </div>
                  </div>
                </div>

                <div class="pt-3 border-top d-flex justify-content-end">
                  <button type="submit" class="btn btn-primary text-white px-4 py-2 rounded-pill fw-semibold shadow-sm"
                    style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                    <i class="bi bi-check-circle me-1"></i> Save Hero Banner &amp; Social Links
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Card 2: Hero Section Image Manager -->
          <div class="col-lg-5">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                  <h5 class="fw-bold text-dark mb-0 fs-6 d-flex align-items-center gap-2">
                    <i class="bi bi-person-bounding-box" style="color:<?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;"></i>
                    <span><?php echo __('hero_image_manager', 'Hero Section Image Manager'); ?></span>
                  </h5>
                  <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    <i class="bi bi-stars me-1"></i>Portrait Image
                  </span>
                </div>

                <p class="text-secondary fs-8 mb-3">
                  Upload a student portrait cutout for the homepage hero right-side column. Layered decorative background graphics are applied automatically.
                </p>

                <!-- Current Hero Image Live Preview -->
                <div class="mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-bold text-dark fs-8 mb-0"><?php echo __('hero_current_preview', 'Current Hero Image Preview'); ?></label>
                    <span class="badge <?php echo $admin_hero_portrait_preview ? 'bg-success' : 'bg-secondary'; ?> text-white rounded-pill fs-9 px-2">
                      <?php echo $admin_hero_portrait_preview ? 'Custom Uploaded' : 'Default Preset'; ?>
                    </span>
                  </div>
                  <div class="rounded-3 border overflow-hidden d-flex align-items-center justify-content-center p-3"
                    style="min-height: 180px; max-height: 220px; background: repeating-conic-gradient(#f8fafc 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;">
                    <img id="admin-hero-portrait-preview"
                      src="<?php echo $admin_hero_portrait_preview ? ($admin_hero_portrait_preview . '?v=' . time()) : 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=400&auto=format&fit=crop&q=85'; ?>"
                      alt="<?php echo htmlspecialchars($hero_settings['hero_image_alt'] ?? 'Hero Portrait'); ?>"
                      class="img-fluid" style="max-height: 190px; object-fit: contain; display: block;"
                      onerror="this.src='https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=400&auto=format&fit=crop&q=85';">
                  </div>
                </div>

                <!-- Form -->
                <form action="index.php" method="POST" enctype="multipart/form-data" id="heroPortraitUploadForm">
                  <input type="hidden" name="action" value="update_hero_portrait_image">

                  <div class="mb-3">
                    <label for="hero_portrait_file" class="form-label fw-bold text-dark fs-8 mb-1">
                      <?php echo __('hero_portrait_upload_label', 'Upload Hero Portrait Image'); ?>
                    </label>
                    <input type="file" name="hero_portrait_file" id="hero_portrait_file" class="form-control form-control-sm"
                      accept=".png,.webp,.jpg,.jpeg,image/png,image/webp,image/jpeg"
                      onchange="previewHeroPortrait(this)">
                    <small class="text-dark d-block fs-9 mt-1 fw-medium">
                      <i class="bi bi-info-circle text-primary me-1"></i><strong>Recommended:</strong> Transparent PNG or WebP (800&times;900px or larger).
                    </small>
                  </div>

                  <div class="mb-3">
                    <label for="hero_image_alt_input" class="form-label fw-bold text-dark fs-8 mb-1">
                      <?php echo __('hero_image_alt_label', 'Image Alt Text (for accessibility)'); ?>
                    </label>
                    <input type="text" name="hero_image_alt" id="hero_image_alt_input" class="form-control form-control-sm"
                      value="<?php echo htmlspecialchars($hero_settings['hero_image_alt'] ?? 'Student with books'); ?>"
                      placeholder="e.g. Student holding books">
                  </div>

                  <?php if ($admin_hero_portrait_preview): ?>
                    <div class="form-check mb-3 p-2 bg-danger bg-opacity-10 rounded-3 border border-danger border-opacity-25">
                      <input class="form-check-input ms-0 me-2" type="checkbox" name="remove_hero_portrait" value="1" id="remove_hero_portrait_chk">
                      <label class="form-check-label text-danger fs-8 fw-bold" for="remove_hero_portrait_chk">
                        <i class="bi bi-trash me-1"></i> Revert to default preset portrait
                      </label>
                    </div>
                  <?php endif; ?>

                  <button type="submit" class="btn btn-primary text-white w-100 py-2 rounded-pill fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2"
                    style="background-color:<?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;border:none;">
                    <i class="bi bi-cloud-upload-fill"></i>
                    <span><?php echo __('hero_upload_save', 'Upload &amp; Save Hero Image'); ?></span>
                  </button>
                </form>
              </div>

              <div class="mt-3 pt-2 border-top">
                <p class="text-muted fs-9 mb-0">
                  <i class="bi bi-shield-check text-success me-1"></i> Supported: <strong>PNG, WebP, JPG, JPEG</strong>. Validated securely by MIME type.
                </p>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /#hero-section -->

      <!-- Course Dropdown Options Customization Section -->
      <div id="options-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark">Course Dropdown Options</h2>
          <p class="text-secondary">Customize Category/Subject and Target Audience/University Batch options available in
            the Lecturer Console.</p>
        </div>

        <div class="row g-4">
          <!-- Card 1: Course Categories / Subjects -->
          <div class="col-lg-6">
            <div class="glass-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-tag-fill text-primary me-2"></i>Course Categories /
                  Subjects</h5>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3"
                  style="background-color: #0f4c81;" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                  <i class="bi bi-plus-lg me-1"></i> Add Category
                </button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 fs-8">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" class="py-2 border-0">ID</th>
                      <th scope="col" class="py-2 border-0">Category Name</th>
                      <th scope="col" class="py-2 border-0">Status</th>
                      <th scope="col" class="py-2 border-0 text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($admin_categories)): ?>
                      <tr>
                        <td colspan="4" class="text-center text-muted py-3">No categories found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($admin_categories as $cat): ?>
                        <tr>
                          <td class="fw-semibold">#<?php echo $cat['id']; ?></td>
                          <td class="fw-bold text-dark"><?php echo htmlspecialchars($cat['name']); ?></td>
                          <td>
                            <span
                              class="badge bg-<?php echo $cat['status'] === 'active' ? 'success' : 'secondary'; ?> bg-opacity-10 text-<?php echo $cat['status'] === 'active' ? 'success' : 'secondary'; ?> border border-<?php echo $cat['status'] === 'active' ? 'success' : 'secondary'; ?> border-opacity-30 rounded-pill px-2.5 py-1 fs-9">
                              <?php echo ucfirst($cat['status']); ?>
                            </span>
                          </td>
                          <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                              onclick='openEditCategoryModal(<?php echo json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                              <i class="bi bi-pencil"></i>
                            </button>
                            <form action="index.php" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this category?');">
                              <input type="hidden" name="action" value="delete_category">
                              <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Card 2: Target Audience / University Batches -->
          <div class="col-lg-6">
            <div class="glass-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-mortarboard-fill text-success me-2"></i>Target
                  Audience / Batches</h5>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3"
                  style="background-color: #0f4c81;" data-bs-toggle="modal" data-bs-target="#addTargetAudienceModal">
                  <i class="bi bi-plus-lg me-1"></i> Add Batch / Target
                </button>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 fs-8">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" class="py-2 border-0">ID</th>
                      <th scope="col" class="py-2 border-0">Target / Batch Name</th>
                      <th scope="col" class="py-2 border-0">Status</th>
                      <th scope="col" class="py-2 border-0 text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($admin_target_audiences)): ?>
                      <tr>
                        <td colspan="4" class="text-center text-muted py-3">No target audiences found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($admin_target_audiences as $aud): ?>
                        <tr>
                          <td class="fw-semibold">#<?php echo $aud['id']; ?></td>
                          <td class="fw-bold text-dark"><?php echo htmlspecialchars($aud['name']); ?></td>
                          <td>
                            <span
                              class="badge bg-<?php echo $aud['status'] === 'active' ? 'success' : 'secondary'; ?> bg-opacity-10 text-<?php echo $aud['status'] === 'active' ? 'success' : 'secondary'; ?> border border-<?php echo $aud['status'] === 'active' ? 'success' : 'secondary'; ?> border-opacity-30 rounded-pill px-2.5 py-1 fs-9">
                              <?php echo ucfirst($aud['status']); ?>
                            </span>
                          </td>
                          <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                              onclick='openEditTargetAudienceModal(<?php echo json_encode($aud, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                              <i class="bi bi-pencil"></i>
                            </button>
                            <form action="index.php" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this target audience?');">
                              <input type="hidden" name="action" value="delete_target_audience">
                              <input type="hidden" name="id" value="<?php echo $aud['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- Section: Site Logo & Favicon Management -->
      <div id="logo-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark mb-1">Site Logo & Favicon Customization</h2>
          <p class="text-secondary fs-7">Manage your official website branding assets. Update the website's main logo and browser tab favicon synchronized across all student, teacher, and admin portals.</p>
        </div>

        <div class="row g-4">
          <!-- Card 1: Site Logo Customization (Left Column) -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                  <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-image-fill text-primary me-2"></i>Website Main Logo
                  </h5>
                  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    <i class="bi bi-layout-sidebar-inset me-1"></i>Navbar & Headers
                  </span>
                </div>

                <p class="text-secondary fs-8 mb-3">
                  This logo appears in navigation bars, student portals, login/registration screens, and PDF certificates.
                </p>

                <!-- Current Active Logo Live Preview -->
                <div class="mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold text-secondary fs-8 mb-0">Active Logo Preview</label>
                    <span class="text-muted fs-9" id="logo-status-tag">Current Active</span>
                  </div>
                  <div class="p-3 bg-light rounded-3 text-center border d-flex align-items-center justify-content-center"
                    style="min-height: 100px; background: repeating-conic-gradient(#f1f5f9 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;">
                    <img id="logo-live-preview" src="../<?php echo get_site_logo(); ?>?v=<?php echo time(); ?>" alt="Active Site Logo"
                      class="img-fluid mx-auto" style="max-height: 70px; max-width: 100%; object-fit: contain;">
                  </div>
                </div>

                <!-- Upload New Logo Form -->
                <form action="index.php?tab=logo" method="POST" enctype="multipart/form-data" id="logoUploadForm">
                  <input type="hidden" name="action" value="update_site_logo">

                  <div class="mb-3">
                    <label for="site_logo_file" class="form-label fw-semibold text-secondary fs-8">Select New Logo Image</label>
                    <input type="file" name="site_logo_file" id="site_logo_file" class="form-control"
                      accept="image/png, image/jpeg, image/webp, image/svg+xml" required onchange="previewLogoFile(this)">
                    <small class="text-muted fs-9 mt-1 d-block">Supported formats: <strong>PNG, JPG, JPEG, SVG, WEBP</strong> (Transparent PNG or SVG recommended).</small>
                  </div>

                  <div class="pt-2">
                    <button type="submit" class="btn btn-primary px-4 py-2.5 rounded-pill fw-semibold shadow-sm w-100"
                      style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                      <i class="bi bi-cloud-upload me-1"></i> Upload & Apply New Logo
                    </button>
                  </div>
                </form>
              </div>

              <div class="mt-4 pt-3 border-top">
                <p class="text-muted fs-9 mb-0">
                  <i class="bi bi-info-circle text-primary me-1"></i> Changes take effect immediately across all student, teacher, and administrator pages.
                </p>
              </div>
            </div>
          </div>

          <!-- Card 2: Website Favicon Customization (Right Column - Placed Right Next to Logo!) -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                  <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-globe-americas text-success me-2"></i>Website Favicon
                  </h5>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    <i class="bi bi-window me-1"></i>Browser Tab & Bookmarks
                  </span>
                </div>

                <p class="text-secondary fs-8 mb-3">
                  The favicon is the small identity icon displayed in web browser tabs, bookmark bars, and mobile home screen shortcuts.
                </p>

                <!-- Current Active Favicon & Realistic Browser Tab Mockup -->
                <div class="mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold text-secondary fs-8 mb-0">Browser Tab & Icon Preview</label>
                    <span class="text-muted fs-9" id="favicon-status-tag">Current Active</span>
                  </div>

                  <!-- Realistic Browser Tab Mockup -->
                  <div class="rounded-3 border overflow-hidden shadow-sm mb-3" style="background: #e2e8f0;">
                    <!-- Browser Window Header -->
                    <div class="d-flex align-items-center px-3 pt-2 pb-0" style="background: #cbd5e1; gap: 8px;">
                      <div class="d-flex gap-1.5 me-2">
                        <span class="rounded-circle d-inline-block" style="width: 10px; height: 10px; background: #ef4444;"></span>
                        <span class="rounded-circle d-inline-block" style="width: 10px; height: 10px; background: #f59e0b;"></span>
                        <span class="rounded-circle d-inline-block" style="width: 10px; height: 10px; background: #10b981;"></span>
                      </div>

                      <!-- Simulated Browser Tab -->
                      <div class="d-flex align-items-center px-3 py-1.5 bg-white rounded-top text-dark shadow-xs"
                        style="max-width: 220px; font-size: 11px; font-weight: 600; border-top: 2px solid #0f4c81;">
                        <img id="favicon-tab-preview" src="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>"
                          alt="Favicon" class="me-2 rounded-1" style="width: 16px; height: 16px; object-fit: contain;">
                        <span class="text-truncate" style="max-width: 140px;">Computerscience.lk | LMS</span>
                        <i class="bi bi-x ms-auto text-muted fs-8"></i>
                      </div>
                      <i class="bi bi-plus-lg text-secondary fs-9 ms-1"></i>
                    </div>

                    <!-- Multi-Size Icon Preview Grid -->
                    <div class="p-3 bg-light text-center d-flex align-items-center justify-content-center gap-3">
                      <!-- 32x32 Desktop Display -->
                      <div class="text-center">
                        <div class="p-2 bg-white rounded-3 border d-inline-flex align-items-center justify-content-center shadow-xs"
                          style="width: 48px; height: 48px; background: repeating-conic-gradient(#f8fafc 0% 25%, #ffffff 0% 50%) 50% / 12px 12px;">
                          <img id="favicon-preview-48" src="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>"
                            alt="Favicon 32px" style="max-width: 32px; max-height: 32px; object-fit: contain;">
                        </div>
                        <div class="text-muted fs-9 mt-1 fw-semibold">32x32 px</div>
                      </div>

                      <!-- 16x16 Tab Display -->
                      <div class="text-center">
                        <div class="p-2 bg-white rounded-3 border d-inline-flex align-items-center justify-content-center shadow-xs"
                          style="width: 40px; height: 40px; background: repeating-conic-gradient(#f8fafc 0% 25%, #ffffff 0% 50%) 50% / 12px 12px;">
                          <img id="favicon-preview-24" src="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>"
                            alt="Favicon 16px" style="max-width: 20px; max-height: 20px; object-fit: contain;">
                        </div>
                        <div class="text-muted fs-9 mt-1 fw-semibold">16x16 px</div>
                      </div>

                      <!-- Dark Mode Display -->
                      <div class="text-center">
                        <div class="p-2 rounded-3 border d-inline-flex align-items-center justify-content-center shadow-xs"
                          style="width: 48px; height: 48px; background: #0f172a; border-color: #334155 !important;">
                          <img id="favicon-preview-dark" src="../<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>"
                            alt="Favicon Dark" style="max-width: 30px; max-height: 30px; object-fit: contain;">
                        </div>
                        <div class="text-muted fs-9 mt-1 fw-semibold">Dark Mode</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Upload New Favicon Form -->
                <form action="index.php?tab=logo" method="POST" enctype="multipart/form-data" id="faviconUploadForm">
                  <input type="hidden" name="action" value="update_site_favicon">

                  <div class="mb-3">
                    <label for="site_favicon_file" class="form-label fw-semibold text-secondary fs-8">Select New Favicon (.ico, .png, .svg, .webp)</label>
                    <input type="file" name="site_favicon_file" id="site_favicon_file" class="form-control"
                      accept=".ico, image/x-icon, image/png, image/svg+xml, image/webp, image/jpeg" required onchange="previewFaviconFile(this)">
                    <small class="text-muted fs-9 mt-1 d-block">Supported formats: <strong>ICO, PNG, SVG, WEBP</strong> (Square 1:1 ratio recommended e.g. 32x32, 64x64, 128x128).</small>
                  </div>

                  <div class="pt-2">
                    <button type="submit" class="btn btn-success px-4 py-2.5 rounded-pill fw-semibold shadow-sm w-100"
                      style="background-color: <?php echo $is_super_admin ? '#0e6237' : '#198754'; ?>; border: none;">
                      <i class="bi bi-cloud-arrow-up-fill me-1"></i> Upload & Apply New Favicon
                    </button>
                  </div>
                </form>
              </div>

              <div class="mt-4 pt-3 border-top">
                <p class="text-muted fs-9 mb-0">
                  <i class="bi bi-info-circle text-success me-1"></i> Updates immediately for browser tabs, bookmark bars, and mobile home screen icons.
                </p>
              </div>
            </div>
          </div>

          <!-- Divider / Section Title: Authentication Pages Background Images -->
          <div class="col-12 mt-4 pt-2">
            <div class="d-flex align-items-center justify-content-between pb-2 border-bottom">
              <div>
                <h4 class="fw-bold text-dark mb-1">
                  <i class="bi bi-shield-lock-fill text-primary me-2"></i>Login &amp; Register Page Visual Images
                </h4>
                <p class="text-secondary fs-7 mb-0">Customize the left-side full visual banner images displayed on the Student and Teacher Login and Registration portals.</p>
              </div>
            </div>
          </div>

          <!-- Card 3: Login Page Image Customizer -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                  <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-box-arrow-in-right text-primary me-2"></i>Login Page Left Visual Image
                  </h5>
                  <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    <i class="bi bi-person-fill me-1"></i>login.php
                  </span>
                </div>

                <p class="text-secondary fs-8 mb-3">
                  This image occupies the left 50% split column on the student and teacher login screen.
                </p>

                <!-- Current Active Login Image Preview -->
                <?php
                $admin_login_img = get_login_page_image();
                $admin_login_preview_src = (preg_match('~^https?://~i', $admin_login_img) || strpos($admin_login_img, 'data:') === 0) ? $admin_login_img : '../' . ltrim($admin_login_img, '/');
                ?>
                <div class="mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold text-secondary fs-8 mb-0">Active Login Image Preview</label>
                    <span class="text-muted fs-9">Split-Screen View</span>
                  </div>
                  <div class="rounded-3 border overflow-hidden position-relative shadow-sm" style="height: 180px; background-color: #0b1329;">
                    <img id="login-img-live-preview" src="<?php echo htmlspecialchars($admin_login_preview_src); ?>?v=<?php echo time(); ?>" alt="Login Page Image"
                      class="w-100 h-100" style="object-fit: cover;">
                  </div>
                </div>

                <!-- Upload / URL Form -->
                <form action="index.php?tab=logo" method="POST" enctype="multipart/form-data" id="loginImgUploadForm">
                  <input type="hidden" name="action" value="update_login_image">

                  <div class="mb-3">
                    <label for="login_image_file" class="form-label fw-semibold text-secondary fs-8">Upload New Image File</label>
                    <input type="file" name="login_image_file" id="login_image_file" class="form-control"
                      accept="image/png, image/jpeg, image/webp, image/svg+xml" onchange="previewAuthFile(this, 'login-img-live-preview')">
                    <small class="text-muted fs-9 mt-1 d-block">Supported formats: <strong>PNG, JPG, JPEG, WEBP</strong> (Recommended: 1200x1600 or 1600x1200 high-res).</small>
                  </div>

                  <div class="mb-3">
                    <label for="login_image_url" class="form-label fw-semibold text-secondary fs-8">Or Paste Image Web URL</label>
                    <input type="url" name="login_image_url" id="login_image_url" class="form-control"
                      placeholder="https://images.unsplash.com/..." value="<?php echo preg_match('~^https?://~i', $admin_login_img) ? htmlspecialchars($admin_login_img) : ''; ?>">
                  </div>

                  <div class="d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary px-4 py-2.5 rounded-pill fw-semibold shadow-sm flex-grow-1"
                      style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                      <i class="bi bi-cloud-upload me-1"></i> Apply Login Image
                    </button>
                  </div>
                </form>

                <form action="index.php?tab=logo" method="POST" class="mt-2">
                  <input type="hidden" name="action" value="reset_login_image">
                  <button type="submit" class="btn btn-outline-secondary btn-sm rounded-pill w-100" onclick="return confirm('Reset login page image to default educational photo?');">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Default Image
                  </button>
                </form>
              </div>

              <div class="mt-4 pt-3 border-top">
                <p class="text-muted fs-9 mb-0">
                  <i class="bi bi-info-circle text-primary me-1"></i> Changes appear instantly on the <a href="../login.php" target="_blank" class="text-decoration-none fw-semibold">Login Page <i class="bi bi-box-arrow-up-right fs-9"></i></a>.
                </p>
              </div>
            </div>
          </div>

          <!-- Card 4: Register Page Image Customizer -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
              <div>
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                  <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-person-plus-fill text-success me-2"></i>Register Page Left Visual Image
                  </h5>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1 fs-9 fw-semibold">
                    <i class="bi bi-mortarboard-fill me-1"></i>register.php
                  </span>
                </div>

                <p class="text-secondary fs-8 mb-3">
                  This image occupies the left 50% split column on the student and teacher registration screen.
                </p>

                <!-- Current Active Register Image Preview -->
                <?php
                $admin_reg_img = get_register_page_image();
                $admin_reg_preview_src = (preg_match('~^https?://~i', $admin_reg_img) || strpos($admin_reg_img, 'data:') === 0) ? $admin_reg_img : '../' . ltrim($admin_reg_img, '/');
                ?>
                <div class="mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold text-secondary fs-8 mb-0">Active Register Image Preview</label>
                    <span class="text-muted fs-9">Split-Screen View</span>
                  </div>
                  <div class="rounded-3 border overflow-hidden position-relative shadow-sm" style="height: 180px; background-color: #0b1329;">
                    <img id="register-img-live-preview" src="<?php echo htmlspecialchars($admin_reg_preview_src); ?>?v=<?php echo time(); ?>" alt="Register Page Image"
                      class="w-100 h-100" style="object-fit: cover;">
                  </div>
                </div>

                <!-- Upload / URL Form -->
                <form action="index.php?tab=logo" method="POST" enctype="multipart/form-data" id="registerImgUploadForm">
                  <input type="hidden" name="action" value="update_register_image">

                  <div class="mb-3">
                    <label for="register_image_file" class="form-label fw-semibold text-secondary fs-8">Upload New Image File</label>
                    <input type="file" name="register_image_file" id="register_image_file" class="form-control"
                      accept="image/png, image/jpeg, image/webp, image/svg+xml" onchange="previewAuthFile(this, 'register-img-live-preview')">
                    <small class="text-muted fs-9 mt-1 d-block">Supported formats: <strong>PNG, JPG, JPEG, WEBP</strong> (Recommended: 1200x1600 or 1600x1200 high-res).</small>
                  </div>

                  <div class="mb-3">
                    <label for="register_image_url" class="form-label fw-semibold text-secondary fs-8">Or Paste Image Web URL</label>
                    <input type="url" name="register_image_url" id="register_image_url" class="form-control"
                      placeholder="https://images.unsplash.com/..." value="<?php echo preg_match('~^https?://~i', $admin_reg_img) ? htmlspecialchars($admin_reg_img) : ''; ?>">
                  </div>

                  <div class="d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-success px-4 py-2.5 rounded-pill fw-semibold shadow-sm flex-grow-1"
                      style="background-color: <?php echo $is_super_admin ? '#0e6237' : '#198754'; ?>; border: none;">
                      <i class="bi bi-cloud-upload me-1"></i> Apply Register Image
                    </button>
                  </div>
                </form>

                <form action="index.php?tab=logo" method="POST" class="mt-2">
                  <input type="hidden" name="action" value="reset_register_image">
                  <button type="submit" class="btn btn-outline-secondary btn-sm rounded-pill w-100" onclick="return confirm('Reset register page image to default educational photo?');">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Default Image
                  </button>
                </form>
              </div>

              <div class="mt-4 pt-3 border-top">
                <p class="text-muted fs-9 mb-0">
                  <i class="bi bi-info-circle text-success me-1"></i> Changes appear instantly on the <a href="../register.php" target="_blank" class="text-decoration-none fw-semibold">Register Page <i class="bi bi-box-arrow-up-right fs-9"></i></a>.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section: Certificate Delivery Note & COD Customization -->
      <div id="delivery-note-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark mb-1">Certificate Delivery Note & COD Customization</h2>
          <p class="text-secondary fs-7">Customize the Cash on Delivery (COD) information, associated fee breakdown, and delivery timeframe notes displayed to students when requesting official course certificates.</p>
        </div>

        <div class="row g-4">
          <!-- Card 1: Customization Form -->
          <div class="col-lg-7">
            <div class="glass-card p-4">
              <h5 class="fw-bold text-dark mb-3"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Delivery & Courier Note</h5>

              <form action="index.php" method="POST">
                <input type="hidden" name="action" value="update_delivery_note">

                <div class="mb-3">
                  <label for="cert_cod_title_input" class="form-label fw-semibold text-secondary fs-8">Section Header Title</label>
                  <input type="text" name="cert_cod_title" id="cert_cod_title_input" class="form-control form-control-sm"
                    value="<?php echo htmlspecialchars(get_site_setting('cert_cod_title', 'Cash on Delivery & Courier Details:')); ?>" required>
                  <small class="text-muted fs-9">Headline displayed at the top of the information alert box in the student modal.</small>
                </div>

                <div class="mb-3">
                  <label for="cert_cod_fee_input" class="form-label fw-semibold text-secondary fs-8">Associated Fees Note <span class="text-danger">*</span></label>
                  <textarea name="cert_cod_fee_note" id="cert_cod_fee_input" class="form-control form-control-sm" rows="3" required><?php echo htmlspecialchars(get_site_setting('cert_cod_fee_note', 'LKR 1,500 Cash on Delivery fee for embossed certificate printing, security hard-folder, and island-wide registered courier handling (Payable in Cash to the courier delivery rider upon package arrival). The digital e-certificate remains 100% free.')); ?></textarea>
                  <small class="text-muted fs-9">Explain the printing/courier fee and payment terms (e.g. Cash on Delivery to courier driver).</small>
                </div>

                <div class="mb-3">
                  <label for="cert_cod_timeframe_input" class="form-label fw-semibold text-secondary fs-8">Delivery Timeframe Note <span class="text-danger">*</span></label>
                  <textarea name="cert_cod_timeframe_note" id="cert_cod_timeframe_input" class="form-control form-control-sm" rows="2" required><?php echo htmlspecialchars(get_site_setting('cert_cod_timeframe_note', 'Dispatched within 24–48 hours after application approval. Island-wide doorstep delivery takes 2 to 4 working days.')); ?></textarea>
                  <small class="text-muted fs-9">Specify processing turnaround and expected courier transit duration.</small>
                </div>

                <div class="mb-4">
                  <label for="cert_cod_custom_input" class="form-label fw-semibold text-secondary fs-8">Additional Instructions / Advisory (Optional)</label>
                  <textarea name="cert_cod_custom_notice" id="cert_cod_custom_input" class="form-control form-control-sm" rows="2" placeholder="e.g. Please ensure a valid contact number is provided for courier dispatch coordination."><?php echo htmlspecialchars(get_site_setting('cert_cod_custom_notice', '')); ?></textarea>
                  <small class="text-muted fs-9">Optional special note or student guidance.</small>
                </div>

                <div class="pt-2">
                  <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm"
                    style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                    <i class="bi bi-check-circle me-1"></i> Save & Publish Delivery Note
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Card 2: Real-time Live Preview -->
          <div class="col-lg-5">
            <div class="glass-card p-4 h-100">
              <h5 class="fw-bold text-dark mb-3"><i class="bi bi-eye-fill text-success me-2"></i>Live Student Modal Preview</h5>
              <p class="text-muted fs-8 mb-3">This is exactly how students will see the note inside the Official Course Certificate Application modal:</p>

              <div class="p-3 bg-light rounded-4 border">
                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                  <span class="fw-bold text-dark fs-8 d-flex align-items-center gap-1.5">
                    <i class="bi bi-cash-coin text-success"></i> Cash on Delivery (COD) & Postal Details
                  </span>
                  <span class="badge bg-secondary bg-opacity-10 text-secondary border fs-9">Courier Service</span>
                </div>

                <div class="alert alert-info border-info border-opacity-25 bg-info bg-opacity-10 py-2.5 px-3 rounded-3 mb-0 fs-9 text-dark" id="preview-alert-box">
                  <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-info-circle-fill text-info fs-6 mt-0.5"></i>
                    <div class="flex-grow-1">
                      <div class="fw-bold text-dark mb-1" id="preview-title"><?php echo htmlspecialchars(get_site_setting('cert_cod_title', 'Cash on Delivery & Courier Details:')); ?></div>
                      <ul class="mb-0 ps-3 text-secondary fs-9" style="line-height: 1.5;">
                        <li><strong>Associated Fee:</strong> <span id="preview-fee"><?php echo htmlspecialchars(get_site_setting('cert_cod_fee_note', 'LKR 1,500 Cash on Delivery fee for embossed certificate printing, security hard-folder, and island-wide registered courier handling (Payable in Cash to the courier delivery rider upon package arrival). The digital e-certificate remains 100% free.')); ?></span></li>
                        <li><strong>Delivery Timeframe:</strong> <span id="preview-timeframe"><?php echo htmlspecialchars(get_site_setting('cert_cod_timeframe_note', 'Dispatched within 24–48 hours after application approval. Island-wide doorstep delivery takes 2 to 4 working days.')); ?></span></li>
                        <li id="preview-extra-item" style="<?php echo empty(get_site_setting('cert_cod_custom_notice', '')) ? 'display:none;' : ''; ?>"><strong>Important:</strong> <span id="preview-extra"><?php echo htmlspecialchars(get_site_setting('cert_cod_custom_notice', '')); ?></span></li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>

              <div class="p-3 bg-success bg-opacity-10 text-success rounded-3 border border-success border-opacity-25 mt-3 fs-9">
                <i class="bi bi-shield-check me-1"></i> Changes saved here immediately update both the <strong>My Courses</strong> and <strong>Student Dashboard</strong> certificate request modals.
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Section: Google OAuth & Sign-In Settings -->
      <div id="google-auth-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark mb-1">Google Sign-In (OAuth 2.0) Settings</h2>
          <p class="text-secondary fs-7">Configure Google OAuth 2.0 client credentials to allow one-click "Continue with Google" sign-in for students and teachers.</p>
        </div>

        <div class="row g-4">
          <!-- Settings Form Column -->
          <div class="col-lg-7">
            <div class="glass-card p-4 h-100">
              <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-google text-warning"></i>
                <span>OAuth 2.0 Credentials</span>
              </h5>

              <form action="index.php" method="POST">
                <input type="hidden" name="action" value="update_google_oauth">

                <div class="form-check form-switch mb-3 p-3 bg-light rounded-3 border">
                  <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="google_oauth_enabled" name="google_oauth_enabled" value="1" <?php echo is_google_oauth_enabled() ? 'checked' : ''; ?>>
                  <label class="form-check-label fw-bold text-dark fs-8" for="google_oauth_enabled">
                    Enable "Continue with Google" on Login & Registration
                  </label>
                  <small class="text-muted d-block fs-9 mt-0.5">When enabled, students and teachers will see the official Google Sign-In button.</small>
                </div>

                <div class="mb-3">
                  <label for="google_client_id" class="form-label fw-semibold text-secondary fs-8">Google Client ID <span class="text-danger">*</span></label>
                  <input type="text" name="google_client_id" id="google_client_id" class="form-control font-monospace fs-8" placeholder="e.g. 1234567890-abcdefg.apps.googleusercontent.com" value="<?php echo htmlspecialchars(get_site_setting('google_client_id', '')); ?>">
                  <small class="text-muted fs-9">Obtained from Google Cloud Console &rarr; APIs & Services &rarr; Credentials.</small>
                </div>

                <div class="mb-3">
                  <label for="google_client_secret" class="form-label fw-semibold text-secondary fs-8">Google Client Secret <span class="text-danger">*</span></label>
                  <input type="password" name="google_client_secret" id="google_client_secret" class="form-control font-monospace fs-8" placeholder="e.g. GOCSPX-xxxxxxxxxxxxxxxx" value="<?php echo htmlspecialchars(get_site_setting('google_client_secret', '')); ?>">
                  <small class="text-muted fs-9">Keep your Client Secret private. Never share it publicly.</small>
                </div>

                <div class="mb-3">
                  <label for="google_redirect_uri" class="form-label fw-semibold text-secondary fs-8">Custom Redirect URI (Optional Override)</label>
                  <input type="url" name="google_redirect_uri" id="google_redirect_uri" class="form-control font-monospace fs-8" placeholder="Leave empty for auto-detected URI" value="<?php echo htmlspecialchars(get_site_setting('google_redirect_uri', '')); ?>">
                  <small class="text-muted fs-9">Leave blank to use the auto-detected URI displayed on the right.</small>
                </div>

                <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-xs" style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
                  <i class="bi bi-save me-1.5"></i> Save Google OAuth Settings
                </button>
              </form>
            </div>
          </div>

          <!-- Setup Instructions & Callback URI Box -->
          <!-- <div class="col-lg-5">
            <div class="glass-card p-4 h-100">
              <h5 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-shield-check text-success"></i>
                <span>Google Cloud Configuration</span>
              </h5>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-9 text-uppercase">Authorized Redirect URI</label>
                <div class="input-group mb-1">
                  <input type="text" class="form-control font-monospace fs-9 bg-light" id="auto-detected-redirect-uri" value="<?php echo htmlspecialchars(get_google_redirect_uri()); ?>" readonly>
                  <button type="button" class="btn btn-outline-secondary px-3" onclick="copyRedirectUri()">
                    <i class="bi bi-clipboard me-1"></i> <span id="copy-uri-btn-text">Copy</span>
                  </button>
                </div>
                <small class="text-muted fs-9">Paste this exact URI into Google Cloud Console under <strong>"Authorized redirect URIs"</strong>.</small>
              </div>

              <div class="p-3 bg-light rounded-3 border mb-3">
                <h6 class="fw-bold text-dark fs-8 mb-2"><i class="bi bi-info-circle text-primary me-1"></i> Quick Setup Steps:</h6>
                <ol class="ps-3 mb-0 text-secondary fs-9" style="line-height: 1.6;">
                  <li>Visit <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="text-primary fw-semibold">Google Cloud Console</a>.</li>
                  <li>Create a new Project or select an existing one.</li>
                  <li>Configure the <strong>OAuth Consent Screen</strong> (User Type: External, Scopes: email, profile, openid).</li>
                  <li>Create <strong>OAuth Client ID</strong> credentials (Application type: <em>Web application</em>).</li>
                  <li>Add the <strong>Authorized Redirect URI</strong> above and save.</li>
                  <li>Copy your Client ID and Client Secret into the form on the left.</li>
                </ol>
              </div>

              <div class="p-2.5 bg-success bg-opacity-10 text-success rounded-3 border border-success border-opacity-25 fs-9">
                <i class="bi bi-check-circle-fill me-1"></i> Native cURL authentication engine active. No Composer dependencies required.
              </div>
            </div>
          </div> -->
        </div>
      </div>

      <!-- Section: Admin Account Security -->
      <div id="password-section" class="d-none">
        <div class="mb-4">
          <h2 class="fw-bold text-dark mb-1">Admin Account Security</h2>
          <p class="text-secondary fs-7">Manage your administrator profile picture and security credentials.</p>
        </div>

        <div class="row g-4">
          <!-- Card 1: Change Profile Picture -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100">
              <h5 class="fw-bold text-dark mb-3"><i class="bi bi-person-bounding-box text-primary me-2"></i>Change
                Profile Picture</h5>

              <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-light rounded-3 border">
                <img src="<?php echo htmlspecialchars($current_admin_avatar); ?>"
                  onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($current_admin_name); ?>&background=0f4c81&color=fff';"
                  alt="<?php echo htmlspecialchars($current_admin_name); ?>" class="rounded-circle border shadow-sm"
                  style="width: 72px; height: 72px; object-fit: cover;">
                <div>
                  <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($current_admin_name); ?></div>
                  <div class="fs-8 text-muted"><?php echo htmlspecialchars($current_admin_email); ?></div>
                  <span
                    class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fs-9 px-2 py-0.5 rounded-pill mt-1">
                    <?php echo ($is_super_admin) ? 'Super Admin' : 'Administrator'; ?>
                  </span>
                </div>
              </div>

              <form action="index.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_admin_profile_picture">

                <div class="mb-3">
                  <label for="profile_avatar_file" class="form-label fw-semibold text-secondary fs-8">Upload Avatar
                    Image File</label>
                  <input type="file" name="profile_avatar_file" id="profile_avatar_file"
                    class="form-control form-control-sm" accept="image/*">
                  <div class="form-text fs-9 text-muted">Supported formats: JPG, PNG, WEBP, GIF (Max 5MB).</div>
                </div>

                <div class="mb-3">
                  <div class="text-center my-2 position-relative">
                    <hr class="my-0">
                    <span
                      class="position-absolute top-50 start-50 translate-middle bg-white px-2 text-muted fs-9 fw-semibold">OR</span>
                  </div>
                </div>

                <div class="mb-4">
                  <label for="profile_avatar_url" class="form-label fw-semibold text-secondary fs-8">Profile Picture
                    Image URL</label>
                  <input type="url" name="profile_avatar_url" id="profile_avatar_url"
                    class="form-control form-control-sm" placeholder="https://example.com/my-photo.jpg">
                </div>

                <div class="pt-2">
                  <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold"
                    style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                    <i class="bi bi-cloud-upload me-1"></i> Update Profile Picture
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Card 2: Change Admin Password -->
          <div class="col-lg-6">
            <div class="glass-card p-4 h-100">
              <h5 class="fw-bold text-dark mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Change Admin Password
              </h5>

              <form action="index.php" method="POST">
                <input type="hidden" name="action" value="change_admin_password">

                <div class="mb-3">
                  <label for="current_password" class="form-label fw-semibold text-secondary fs-8">Current
                    Password</label>
                  <input type="password" name="current_password" id="current_password" class="form-control"
                    placeholder="Enter current admin password" required>
                </div>

                <div class="mb-3">
                  <label for="new_password" class="form-label fw-semibold text-secondary fs-8">New Password</label>
                  <input type="password" name="new_password" id="new_password" class="form-control"
                    placeholder="Minimum 6 characters" required>
                </div>

                <div class="mb-4">
                  <label for="confirm_password" class="form-label fw-semibold text-secondary fs-8">Confirm New
                    Password</label>
                  <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                    placeholder="Re-type new password" required>
                </div>

                <div class="pt-2">
                  <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold"
                    style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>; border: none;">
                    <i class="bi bi-shield-check me-1"></i> Update Admin Password
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

    </div> <!-- End Main Content Container -->

    <!-- Add Bank Account Modal -->
    <div class="modal fade" id="addBankModal" tabindex="-1" aria-labelledby="addBankModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white"
            style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="addBankModalLabel"><i class="bi bi-bank2 me-2"></i>Add New
              Bank Account</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <div class="modal-body p-4">
              <input type="hidden" name="action" value="add_bank_account">

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Bank Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="bank_name" class="form-control form-control-sm"
                  placeholder="e.g. Commercial Bank" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Branch Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="branch" class="form-control form-control-sm" placeholder="e.g. Colombo Fort"
                  required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Account Number <span
                    class="text-danger">*</span></label>
                <input type="text" name="account_number" class="form-control form-control-sm"
                  placeholder="e.g. 8012993041" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Account Holder Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="account_name" class="form-control form-control-sm"
                  placeholder="e.g. Computerscience.lk (Pvt) Ltd" required>
              </div>

              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Badge / Option Label</label>
                  <input type="text" name="option_label" class="form-control form-control-sm"
                    placeholder="e.g. Option 1">
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Status</label>
                  <select name="status" class="form-select form-select-sm">
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Save Bank
                Details</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Bank Account Modal -->
    <div class="modal fade" id="editBankModal" tabindex="-1" aria-labelledby="editBankModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white"
            style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="editBankModalLabel"><i
                class="bi bi-pencil-square me-2"></i>Edit Bank Account</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <div class="modal-body p-4">
              <input type="hidden" name="action" value="edit_bank_account">
              <input type="hidden" name="account_id" id="edit_account_id">

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Bank Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="bank_name" id="edit_bank_name" class="form-control form-control-sm" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Branch Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="branch" id="edit_branch" class="form-control form-control-sm" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Account Number <span
                    class="text-danger">*</span></label>
                <input type="text" name="account_number" id="edit_account_number" class="form-control form-control-sm"
                  required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Account Holder Name <span
                    class="text-danger">*</span></label>
                <input type="text" name="account_name" id="edit_account_name" class="form-control form-control-sm"
                  required>
              </div>

              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Badge / Option Label</label>
                  <input type="text" name="option_label" id="edit_option_label" class="form-control form-control-sm">
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Status</label>
                  <select name="status" id="edit_status" class="form-select form-select-sm">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Update Bank
                Details</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Featured Announcement Modal -->
    <div class="modal fade" id="addBannerModal" tabindex="-1" aria-labelledby="addBannerModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white" style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="addBannerModalLabel">
              <i class="bi bi-plus-circle-fill me-2"></i>Create New Featured Announcement
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_banner">
            <div class="modal-body p-4">
              <div class="row g-3">
                <div class="col-md-7">
                  <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary fs-8">Announcement Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control form-control-sm" placeholder="e.g. Full-Stack Software Engineering 2026 Batch" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary fs-8">Subtitle / Tagline</label>
                    <input type="text" name="subtitle" class="form-control form-control-sm" placeholder="e.g. Master Modern Web Dev, Cloud & DevOps with Mentors">
                  </div>
                </div>

                <div class="col-md-5">
                  <label class="form-label fw-semibold text-secondary fs-8">Announcement Image <span class="text-danger">*</span></label>
                  <div class="border border-dashed rounded-3 p-2 text-center bg-light mb-2">
                    <div id="add_banner_preview_box" class="position-relative rounded-2 overflow-hidden bg-dark d-flex align-items-center justify-content-center" style="height: 110px;">
                      <span id="add_banner_placeholder" class="text-muted fs-8 d-flex flex-column align-items-center">
                        <i class="bi bi-image fs-3 mb-1"></i> Live Image Preview
                      </span>
                      <img id="add_banner_preview_img" src="" alt="Preview" class="w-100 h-100 object-fit-cover d-none">
                    </div>
                  </div>
                  <input type="file" name="banner_image_file" id="add_banner_file_input" class="form-control form-control-sm mb-1.5" accept="image/*">
                  <input type="url" name="image_url" id="add_banner_url_input" class="form-control form-control-sm" placeholder="Or enter Image URL (https://...)">
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Full Announcement Details / Description (Supports Clickable Website Links) <span class="text-danger">*</span></label>
                <textarea name="details_content" class="form-control form-control-sm" rows="5" placeholder="Enter comprehensive announcement content, key highlights, dates, and website URLs (e.g. https://example.com/register)..." required></textarea>
                <div class="form-text fs-9 text-muted mt-1.5"><i class="bi bi-link-45deg text-primary"></i> <strong>Tip:</strong> Any website URLs (e.g. <code>https://example.com</code> or <code>www.example.com</code>) will automatically be converted to clickable links in the popup window.</div>
              </div>

              <div class="row g-3 align-items-center pt-2 border-top">
                <div class="col-md-4">
                  <label class="form-label fw-semibold text-secondary fs-8 mb-1">Display Order</label>
                  <input type="number" name="display_order" class="form-control form-control-sm" value="1" min="1">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-semibold text-secondary fs-8 mb-1">Visibility State</label>
                  <div class="form-check form-switch pt-1">
                    <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" checked>
                    <label class="form-check-label fs-8 fw-semibold text-dark" for="add_is_active">Active on Slider</label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
                <i class="bi bi-check2-circle me-1"></i> Publish Announcement
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Featured Announcement Modal -->
    <div class="modal fade" id="editBannerModal" tabindex="-1" aria-labelledby="editBannerModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white" style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="editBannerModalLabel">
              <i class="bi bi-pencil-square me-2"></i>Edit Featured Announcement
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_banner">
            <input type="hidden" name="banner_id" id="edit_banner_id">
            <div class="modal-body p-4">
              <div class="row g-3">
                <div class="col-md-7">
                  <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary fs-8">Announcement Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" id="edit_banner_title" class="form-control form-control-sm" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary fs-8">Subtitle / Tagline</label>
                    <input type="text" name="subtitle" id="edit_banner_subtitle" class="form-control form-control-sm">
                  </div>
                </div>

                <div class="col-md-5">
                  <label class="form-label fw-semibold text-secondary fs-8">Announcement Image (Leave empty to keep current)</label>
                  <div class="border border-dashed rounded-3 p-2 text-center bg-light mb-2">
                    <div id="edit_banner_preview_box" class="position-relative rounded-2 overflow-hidden bg-dark d-flex align-items-center justify-content-center" style="height: 110px;">
                      <img id="edit_banner_preview_img" src="" alt="Preview" class="w-100 h-100 object-fit-cover">
                    </div>
                  </div>
                  <input type="file" name="banner_image_file" id="edit_banner_file_input" class="form-control form-control-sm mb-1.5" accept="image/*">
                  <input type="url" name="image_url" id="edit_banner_url_input" class="form-control form-control-sm" placeholder="Or update Image URL">
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Full Announcement Details / Description (Supports Clickable Website Links) <span class="text-danger">*</span></label>
                <textarea name="details_content" id="edit_banner_details_content" class="form-control form-control-sm" rows="5" required></textarea>
                <div class="form-text fs-9 text-muted mt-1.5"><i class="bi bi-link-45deg text-primary"></i> <strong>Tip:</strong> Any website URLs (e.g. <code>https://example.com</code> or <code>www.example.com</code>) will automatically be converted to clickable links in the popup window.</div>
              </div>

              <div class="row g-3 align-items-center pt-2 border-top">
                <div class="col-md-4">
                  <label class="form-label fw-semibold text-secondary fs-8 mb-1">Display Order</label>
                  <input type="number" name="display_order" id="edit_banner_display_order" class="form-control form-control-sm" min="1">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-semibold text-secondary fs-8 mb-1">Visibility State</label>
                  <div class="form-check form-switch pt-1">
                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                    <label class="form-check-label fs-8 fw-semibold text-dark" for="edit_is_active">Active on Slider</label>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold" style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
                <i class="bi bi-check2-circle me-1"></i> Update Announcement
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Announcement Modal -->
    <div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-labelledby="addAnnouncementModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white"
            style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="addAnnouncementModalLabel"><i
                class="bi bi-megaphone me-2"></i>Add New Text Notice</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <div class="modal-body p-4">
              <input type="hidden" name="action" value="add_announcement">

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Announcement Title <span
                    class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control form-control-sm"
                  placeholder="e.g. Registration open for Algorithms 2026 Batch" required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Announcement Details / Content <span
                    class="text-danger">*</span></label>
                <textarea name="content" class="form-control form-control-sm" rows="4"
                  placeholder="Enter detailed announcement message..." required></textarea>
              </div>

              <div class="row g-2">
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Category Badge</label>
                  <select name="category" class="form-select form-select-sm">
                    <option value="notice" selected>📢 Notice</option>
                    <option value="offer">🎉 Special Offer</option>
                    <option value="launch">🚀 Course Launch</option>
                    <option value="alert">⚡ Urgent Alert</option>
                    <option value="event">📅 Event</option>
                  </select>
                </div>
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Badge / Date Label</label>
                  <input type="text" name="badge_text" class="form-control form-control-sm"
                    placeholder="e.g. July 15, 2026">
                </div>
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Status</label>
                  <select name="status" class="form-select form-select-sm">
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Publish Notice</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Announcement Modal -->
    <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-labelledby="editAnnouncementModalLabel"
      aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header text-white"
            style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">
            <h5 class="modal-title fw-bold fs-6 mb-0" id="editAnnouncementModalLabel"><i
                class="bi bi-pencil-square me-2"></i>Edit Text Notice</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <div class="modal-body p-4">
              <input type="hidden" name="action" value="edit_announcement">
              <input type="hidden" name="announcement_id" id="edit_announcement_id">

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Announcement Title <span
                    class="text-danger">*</span></label>
                <input type="text" name="title" id="edit_announcement_title" class="form-control form-control-sm"
                  required>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold text-secondary fs-8">Announcement Details / Content <span
                    class="text-danger">*</span></label>
                <textarea name="content" id="edit_announcement_content" class="form-control form-control-sm" rows="4"
                  required></textarea>
              </div>

              <div class="row g-2">
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Category Badge</label>
                  <select name="category" id="edit_announcement_category" class="form-select form-select-sm">
                    <option value="notice">📢 Notice</option>
                    <option value="offer">🎉 Special Offer</option>
                    <option value="launch">🚀 Course Launch</option>
                    <option value="alert">⚡ Urgent Alert</option>
                    <option value="event">📅 Event</option>
                  </select>
                </div>
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Badge / Date Label</label>
                  <input type="text" name="badge_text" id="edit_announcement_badge_text"
                    class="form-control form-control-sm">
                </div>
                <div class="col-4 mb-3">
                  <label class="form-label fw-semibold text-secondary fs-8">Status</label>
                  <select name="status" id="edit_announcement_status" class="form-select form-select-sm">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Update Notice</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header bg-light">
            <h5 class="modal-title fw-bold text-dark"><i class="bi bi-plus-circle text-primary me-2"></i>Add New Course
              Category</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <input type="hidden" name="action" value="add_category">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label for="cat_name" class="form-label fw-semibold text-secondary fs-8">Category Name</label>
                <input type="text" name="name" id="cat_name" class="form-control"
                  placeholder="e.g. Data Science & Analytics" required>
              </div>
              <div class="mb-3">
                <label for="cat_status" class="form-label fw-semibold text-secondary fs-8">Status</label>
                <select name="status" id="cat_status" class="form-select">
                  <option value="active" selected>Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Save Category</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header bg-light">
            <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Course
              Category</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="id" id="edit_cat_id">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label for="edit_cat_name" class="form-label fw-semibold text-secondary fs-8">Category Name</label>
                <input type="text" name="name" id="edit_cat_name" class="form-control" required>
              </div>
              <div class="mb-3">
                <label for="edit_cat_status" class="form-label fw-semibold text-secondary fs-8">Status</label>
                <select name="status" id="edit_cat_status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Update
                Category</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Target Audience Modal -->
    <div class="modal fade" id="addTargetAudienceModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header bg-light">
            <h5 class="modal-title fw-bold text-dark"><i class="bi bi-plus-circle text-success me-2"></i>Add Target
              Audience / Batch</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <input type="hidden" name="action" value="add_target_audience">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label for="aud_name" class="form-label fw-semibold text-secondary fs-8">Batch / Target Name</label>
                <input type="text" name="name" id="aud_name" class="form-control"
                  placeholder="e.g. B.Sc. in Software Engineering - Batch 2027" required>
              </div>
              <div class="mb-3">
                <label for="aud_status" class="form-label fw-semibold text-secondary fs-8">Status</label>
                <select name="status" id="aud_status" class="form-select">
                  <option value="active" selected>Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Save Target
                Audience</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Target Audience Modal -->
    <div class="modal fade" id="editTargetAudienceModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header bg-light">
            <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-success me-2"></i>Edit Target
              Audience / Batch</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="index.php" method="POST">
            <input type="hidden" name="action" value="edit_target_audience">
            <input type="hidden" name="id" id="edit_aud_id">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label for="edit_aud_name" class="form-label fw-semibold text-secondary fs-8">Batch / Target
                  Name</label>
                <input type="text" name="name" id="edit_aud_name" class="form-control" required>
              </div>
              <div class="mb-3">
                <label for="edit_aud_status" class="form-label fw-semibold text-secondary fs-8">Status</label>
                <select name="status" id="edit_aud_status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill"
                data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold"
                style="background-color: <?php echo $is_super_admin ? '#0b4528' : '#0f4c81'; ?>;">Update Target
                Audience</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Local Bootstrap 5 Bundle JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>

    <!-- Navigation Drawer/Tabs Persistent Toggle script -->
    <script>
      function openEditBankModal(acc) {
        document.getElementById('edit_account_id').value = acc.id;
        document.getElementById('edit_bank_name').value = acc.bank_name;
        document.getElementById('edit_branch').value = acc.branch;
        document.getElementById('edit_account_number').value = acc.account_number;
        document.getElementById('edit_account_name').value = acc.account_name;
        document.getElementById('edit_option_label').value = acc.option_label || '';
        document.getElementById('edit_status').value = acc.status || 'active';

        const modal = new bootstrap.Modal(document.getElementById('editBankModal'));
        modal.show();
      }

      // Hero portrait image live preview on file select
      function previewHeroPortrait(input) {
        if (input.files && input.files[0]) {
          const reader = new FileReader();
          reader.onload = function(e) {
            const preview = document.getElementById('admin-hero-portrait-preview');
            if (preview) {
              preview.src = e.target.result;
              preview.style.display = 'block';
            }
          };
          reader.readAsDataURL(input.files[0]);
        }
      }


      function previewAuthFile(input, targetId) {
        if (input.files && input.files[0]) {
          const reader = new FileReader();
          reader.onload = function(e) {
            const target = document.getElementById(targetId);
            if (target) target.src = e.target.result;
          };
          reader.readAsDataURL(input.files[0]);
        }
      }

      function openEditBannerModal(banner) {
        document.getElementById('edit_banner_id').value = banner.id;
        document.getElementById('edit_banner_title').value = banner.title;
        document.getElementById('edit_banner_subtitle').value = banner.subtitle || '';
        document.getElementById('edit_banner_details_content').value = banner.details_content;
        document.getElementById('edit_banner_display_order').value = banner.display_order || 1;
        document.getElementById('edit_is_active').checked = (banner.is_active == 1);
        
        // Show current image preview
        const previewImg = document.getElementById('edit_banner_preview_img');
        if (banner.image_path) {
          previewImg.src = banner.image_path.startsWith('http') ? banner.image_path : '../' + banner.image_path;
        }
        document.getElementById('edit_banner_url_input').value = (banner.image_path && banner.image_path.startsWith('http')) ? banner.image_path : '';
        document.getElementById('edit_banner_file_input').value = '';

        const modal = new bootstrap.Modal(document.getElementById('editBannerModal'));
        modal.show();
      }

      function openEditAnnouncementModal(ann) {
        document.getElementById('edit_announcement_id').value = ann.id;
        document.getElementById('edit_announcement_title').value = ann.title;
        document.getElementById('edit_announcement_content').value = ann.content;
        document.getElementById('edit_announcement_badge_text').value = ann.badge_text || '';
        document.getElementById('edit_announcement_category').value = ann.category || 'notice';
        document.getElementById('edit_announcement_status').value = ann.status || 'active';

        const modal = new bootstrap.Modal(document.getElementById('editAnnouncementModal'));
        modal.show();
      }

      function openEditCategoryModal(cat) {
        document.getElementById('edit_cat_id').value = cat.id;
        document.getElementById('edit_cat_name').value = cat.name;
        document.getElementById('edit_cat_status').value = cat.status || 'active';

        const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
        modal.show();
      }

      function openEditTargetAudienceModal(aud) {
        document.getElementById('edit_aud_id').value = aud.id;
        document.getElementById('edit_aud_name').value = aud.name;
        document.getElementById('edit_aud_status').value = aud.status || 'active';

        const modal = new bootstrap.Modal(document.getElementById('editTargetAudienceModal'));
        modal.show();
      }

      document.addEventListener('DOMContentLoaded', function () {
        // Sidebar Tab Buttons
        const btnDashboard = document.getElementById('btn-dashboard-tab');
        const btnTeachers = document.getElementById('btn-teachers-tab');
        const btnStudents = document.getElementById('btn-students-tab');
        const btnCourses = document.getElementById('btn-courses-tab');
        const btnBank = document.getElementById('btn-bank-tab');
        const btnManageBank = document.getElementById('btn-manage-bank-tab');
        const btnOptions = document.getElementById('btn-options-tab');
        const btnAnnouncements = document.getElementById('btn-announcements-tab');
        const btnHero = document.getElementById('btn-hero-tab');
        const btnDeliveryNote = document.getElementById('btn-delivery-note-tab');
        const btnGoogleAuth = document.getElementById('btn-google-auth-tab');
        const btnLogo = document.getElementById('btn-logo-tab');
        const btnPassword = document.getElementById('btn-password-tab');

        // Sections
        const secDashboard = document.getElementById('dashboard-section');
        const secTeachers = document.getElementById('teachers-section');
        const secStudents = document.getElementById('students-section');
        const secCourses = document.getElementById('courses-section');
        const secBank = document.getElementById('bank-section');
        const secManageBank = document.getElementById('manage-bank-section');
        const secOptions = document.getElementById('options-section');
        const secAnnouncements = document.getElementById('announcements-section');
        const secHero = document.getElementById('hero-section');
        const secDeliveryNote = document.getElementById('delivery-note-section');
        const secGoogleAuth = document.getElementById('google-auth-section');
        const secLogo = document.getElementById('logo-section');
        const secPassword = document.getElementById('password-section');

        const activeTabTitle = document.getElementById('active-tab-title');
        const sidebar = document.getElementById('admin-sidebar');
        const mobileToggleBtn = document.getElementById('mobile-sidebar-toggle');
        const closeSidebarBtn = document.getElementById('close-sidebar-btn');

        // Mobile Sidebar Toggle
        if (mobileToggleBtn) {
          mobileToggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show-mobile');
          });
        }
        if (closeSidebarBtn) {
          closeSidebarBtn.addEventListener('click', function () {
            sidebar.classList.remove('show-mobile');
          });
        }

        // Live Preview Event Listeners for Delivery Note
        const codTitleInput = document.getElementById('cert_cod_title_input');
        const codFeeInput = document.getElementById('cert_cod_fee_input');
        const codTimeframeInput = document.getElementById('cert_cod_timeframe_input');
        const codCustomInput = document.getElementById('cert_cod_custom_input');

        const previewTitle = document.getElementById('preview-title');
        const previewFee = document.getElementById('preview-fee');
        const previewTimeframe = document.getElementById('preview-timeframe');
        const previewExtra = document.getElementById('preview-extra');
        const previewExtraItem = document.getElementById('preview-extra-item');

        if (codTitleInput && previewTitle) {
          codTitleInput.addEventListener('input', function () {
            previewTitle.textContent = this.value || 'Cash on Delivery & Courier Details:';
          });
        }
        if (codFeeInput && previewFee) {
          codFeeInput.addEventListener('input', function () {
            previewFee.textContent = this.value || '';
          });
        }
        if (codTimeframeInput && previewTimeframe) {
          codTimeframeInput.addEventListener('input', function () {
            previewTimeframe.textContent = this.value || '';
          });
        }
        if (codCustomInput && previewExtra && previewExtraItem) {
          codCustomInput.addEventListener('input', function () {
            previewExtra.textContent = this.value;
            previewExtraItem.style.display = this.value.trim() ? 'list-item' : 'none';
          });
        }

        // Check URL parameter first (e.g. index.php?tab=courses), then persistent active tab from localStorage
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        const activeTab = tabParam || localStorage.getItem('adminActiveTab') || 'teachers';
        switch (activeTab) {
          case 'teachers': switchToTeachers(); break;
          case 'students': switchToStudents(); break;
          case 'courses': switchToCourses(); break;
          case 'bank': switchToBank(); break;
          case 'manage_bank': switchToManageBank(); break;
          case 'options': switchToOptions(); break;
          case 'announcements': switchToAnnouncements(); break;
          case 'hero': switchToHero(); break;
          case 'delivery_note': switchToDeliveryNote(); break;
          case 'google_auth': switchToGoogleAuth(); break;
          case 'logo': switchToLogo(); break;
          case 'password': switchToPassword(); break;
          default: switchToTeachers(); break;
        }

        // Attach Click Handlers
        if (btnTeachers) btnTeachers.addEventListener('click', switchToTeachers);
        if (btnStudents) btnStudents.addEventListener('click', switchToStudents);
        if (btnCourses) btnCourses.addEventListener('click', switchToCourses);
        if (btnBank) btnBank.addEventListener('click', switchToBank);
        if (btnManageBank) btnManageBank.addEventListener('click', switchToManageBank);
        if (btnOptions) btnOptions.addEventListener('click', switchToOptions);
        if (btnAnnouncements) btnAnnouncements.addEventListener('click', switchToAnnouncements);
        if (btnHero) btnHero.addEventListener('click', switchToHero);
        if (btnDeliveryNote) btnDeliveryNote.addEventListener('click', switchToDeliveryNote);
        if (btnGoogleAuth) btnGoogleAuth.addEventListener('click', switchToGoogleAuth);
        if (btnLogo) btnLogo.addEventListener('click', switchToLogo);
        if (btnPassword) btnPassword.addEventListener('click', switchToPassword);

        function resetTabStyles() {
          const allBtns = [btnTeachers, btnStudents, btnCourses, btnBank, btnManageBank, btnOptions, btnAnnouncements, btnHero, btnDeliveryNote, btnGoogleAuth, btnLogo, btnPassword];
          const allSecs = [secTeachers, secStudents, secCourses, secBank, secManageBank, secOptions, secAnnouncements, secHero, secDeliveryNote, secGoogleAuth, secLogo, secPassword];

          allBtns.forEach(btn => {
            if (btn) btn.classList.remove('active');
          });
          allSecs.forEach(sec => {
            if (sec) sec.classList.add('d-none');
          });

          if (window.innerWidth < 992) {
            sidebar.classList.remove('show-mobile');
          }
        }

        function setActiveTab(btn, sec, key, titleText) {
          resetTabStyles();
          if (sec) sec.classList.remove('d-none');
          if (btn) btn.classList.add('active');
          if (activeTabTitle) activeTabTitle.innerText = titleText;
          localStorage.setItem('adminActiveTab', key);
        }

        function switchToTeachers() { setActiveTab(btnTeachers, secTeachers, 'teachers', 'Teacher Registration Requests'); }
        function switchToStudents() { setActiveTab(btnStudents, secStudents, 'students', 'Registered Students Directory'); }
        function switchToCourses() { setActiveTab(btnCourses, secCourses, 'courses', 'Course Approvals'); }
        function switchToBank() { setActiveTab(btnBank, secBank, 'bank', 'Bank Slip Reviews'); }
        function switchToManageBank() { setActiveTab(btnManageBank, secManageBank, 'manage_bank', 'Manage Bank Accounts'); }
        function switchToOptions() { setActiveTab(btnOptions, secOptions, 'options', 'Category & Batch Options'); }
        function switchToAnnouncements() { setActiveTab(btnAnnouncements, secAnnouncements, 'announcements', 'Site Announcements & Promotional Banners'); }
        function switchToHero() { setActiveTab(btnHero, secHero, 'hero', 'Hero Banner Settings'); }
        function switchToDeliveryNote() { setActiveTab(btnDeliveryNote, secDeliveryNote, 'delivery_note', 'Certificate Delivery Note & COD Settings'); }
        function switchToGoogleAuth() { setActiveTab(btnGoogleAuth, secGoogleAuth, 'google_auth', 'Google Sign-In & OAuth Settings'); }
        function switchToLogo() { setActiveTab(btnLogo, secLogo, 'logo', 'Site Logo & Favicon Customization'); }
        function switchToPassword() { setActiveTab(btnPassword, secPassword, 'password', 'Change Admin Password'); }

        // Live Image Preview for Add Banner Modal
        const addBannerFileInput = document.getElementById('add_banner_file_input');
        const addBannerUrlInput = document.getElementById('add_banner_url_input');
        const addBannerPreviewImg = document.getElementById('add_banner_preview_img');
        const addBannerPlaceholder = document.getElementById('add_banner_placeholder');

        if (addBannerFileInput) {
          addBannerFileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
              const reader = new FileReader();
              reader.onload = function (e) {
                if (addBannerPreviewImg) {
                  addBannerPreviewImg.src = e.target.result;
                  addBannerPreviewImg.classList.remove('d-none');
                }
                if (addBannerPlaceholder) {
                  addBannerPlaceholder.classList.add('d-none');
                }
              };
              reader.readAsDataURL(this.files[0]);
            }
          });
        }

        if (addBannerUrlInput) {
          addBannerUrlInput.addEventListener('input', function () {
            if (this.value.trim().length > 5) {
              if (addBannerPreviewImg) {
                addBannerPreviewImg.src = this.value.trim();
                addBannerPreviewImg.classList.remove('d-none');
              }
              if (addBannerPlaceholder) {
                addBannerPlaceholder.classList.add('d-none');
              }
            }
          });
        }

        // Live Image Preview for Edit Banner Modal
        const editBannerFileInput = document.getElementById('edit_banner_file_input');
        const editBannerUrlInput = document.getElementById('edit_banner_url_input');
        const editBannerPreviewImg = document.getElementById('edit_banner_preview_img');

        if (editBannerFileInput) {
          editBannerFileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
              const reader = new FileReader();
              reader.onload = function (e) {
                if (editBannerPreviewImg) {
                  editBannerPreviewImg.src = e.target.result;
                }
              };
              reader.readAsDataURL(this.files[0]);
            }
          });
        }

        if (editBannerUrlInput) {
          editBannerUrlInput.addEventListener('input', function () {
            if (this.value.trim().length > 5) {
              if (editBannerPreviewImg) {
                editBannerPreviewImg.src = this.value.trim();
              }
            }
          });
        }
      });

      // Live File Previews for Site Logo & Favicon
      function previewLogoFile(input) {
        if (input.files && input.files[0]) {
          const file = input.files[0];
          const reader = new FileReader();
          reader.onload = function (e) {
            const logoPreview = document.getElementById('logo-live-preview');
            const statusTag = document.getElementById('logo-status-tag');
            if (logoPreview) {
              logoPreview.src = e.target.result;
            }
            if (statusTag) {
              statusTag.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-eye me-1"></i>New Preview (Pending Upload)</span>';
            }
          };
          reader.readAsDataURL(file);
        }
      }

      function previewFaviconFile(input) {
        if (input.files && input.files[0]) {
          const file = input.files[0];
          const reader = new FileReader();
          reader.onload = function (e) {
            const tabPreview = document.getElementById('favicon-tab-preview');
            const p48 = document.getElementById('favicon-preview-48');
            const p24 = document.getElementById('favicon-preview-24');
            const pDark = document.getElementById('favicon-preview-dark');
            const statusTag = document.getElementById('favicon-status-tag');

            if (tabPreview) tabPreview.src = e.target.result;
            if (p48) p48.src = e.target.result;
            if (p24) p24.src = e.target.result;
            if (pDark) pDark.src = e.target.result;

            if (statusTag) {
              statusTag.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-eye me-1"></i>New Preview (Pending Upload)</span>';
            }
          };
          reader.readAsDataURL(file);
        }
      }

      function copyRedirectUri() {
        const uriInput = document.getElementById('auto-detected-redirect-uri');
        if (uriInput && navigator.clipboard) {
          navigator.clipboard.writeText(uriInput.value).then(() => {
            const btnText = document.getElementById('copy-uri-btn-text');
            if (btnText) {
              btnText.textContent = 'Copied!';
              setTimeout(() => { btnText.textContent = 'Copy'; }, 2000);
            }
          });
        }
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

    <!-- Bootstrap 5 Bundle JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>

    <!-- Secure Course Deletion Modal with Admin Password Verification -->
    <div class="modal fade" id="adminDeleteCourseModal" tabindex="-1" aria-labelledby="adminDeleteCourseModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
          <div class="modal-header bg-danger text-white border-0 py-3">
            <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="adminDeleteCourseModalLabel">
              <i class="bi bi-exclamation-triangle-fill fs-5"></i>
              <?php echo __('delete_course_modal_title', '⚠️ Secure Course Deletion'); ?>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <!-- Target Course Info Card -->
            <div class="p-3 bg-light rounded-3 border mb-3">
              <div class="fw-bold text-dark fs-6 mb-1" id="admin-delete-course-title"></div>
              <div class="text-muted fs-8">
                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-1 rounded-pill" id="admin-delete-course-students-badge">
                  <i class="bi bi-people-fill me-1"></i>0 Students
                </span>
              </div>
            </div>

            <!-- Strong Warning Alert Box -->
            <div class="alert alert-danger d-flex align-items-start gap-2.5 mb-3 py-2.5 px-3 fs-7 border-danger border-opacity-30">
              <i class="bi bi-shield-slash-fill fs-5 text-danger flex-shrink-0 mt-0.5"></i>
              <div id="admin-delete-warning-text">
                <?php echo __('delete_course_modal_warning', '⚠️ Warning: This will permanently delete this course and all associated lessons, quizzes, and progress for enrolled students. This action cannot be undone.'); ?>
              </div>
            </div>

            <!-- Password Verification Form -->
            <form id="admin-delete-course-form" onsubmit="return false;">
              <input type="hidden" id="admin-delete-course-id" value="">
              <input type="hidden" id="admin-delete-csrf-token" value="<?php echo htmlspecialchars($csrf_token); ?>">

              <div class="mb-3">
                <label for="admin-delete-password-input" class="form-label fw-bold text-dark fs-7">
                  <i class="bi bi-key-fill text-warning me-1"></i>
                  <?php echo __('admin_password_confirm_label', 'Enter Your Admin Password to Confirm:'); ?>
                  <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                  <input type="password" id="admin-delete-password-input" class="form-control"
                    placeholder="Your current admin password"
                    autocomplete="current-password" required>
                  <button class="btn btn-outline-secondary" type="button" id="toggle-delete-pwd-vis" title="Toggle password visibility">
                    <i class="bi bi-eye" id="toggle-pwd-icon"></i>
                  </button>
                </div>
              </div>

              <div id="admin-delete-alert-container" class="mb-3"></div>

              <div class="d-flex justify-content-end gap-2 mt-4 pt-2 border-top">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-semibold border" data-bs-dismiss="modal">
                  <?php echo __('cancel', 'Cancel'); ?>
                </button>
                <button type="submit" id="confirm-admin-delete-btn" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1.5">
                  <i class="bi bi-trash3-fill"></i>
                  <span><?php echo __('confirm_permanent_delete', 'Confirm Permanent Delete'); ?></span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Admin Dynamic Notification Toast -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
      <div id="adminActionToast" class="toast align-items-center text-white border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body d-flex align-items-center gap-2" id="adminToastBody">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <span id="adminToastMessage">Action completed successfully.</span>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    </div>

    <!-- Course Management AJAX Controller -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';

        // Toast Helper
        function showAdminToast(message, isError = false) {
          const toastEl = document.getElementById('adminActionToast');
          const toastBody = document.getElementById('adminToastBody');
          const toastMsg = document.getElementById('adminToastMessage');
          if (!toastEl) return;

          toastEl.className = 'toast align-items-center text-white border-0 shadow-lg ' + (isError ? 'bg-danger' : 'bg-success');
          if (toastBody) {
            toastBody.innerHTML = (isError ? '<i class="bi bi-x-circle-fill fs-5"></i>' : '<i class="bi bi-check-circle-fill fs-5"></i>') + ' <span>' + message + '</span>';
          }
          const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 });
          toast.show();
        }

        // Quick Toggle Course Status Handler (Active / Disabled)
        document.querySelectorAll('.btn-toggle-course-status').forEach(btn => {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            const courseId = this.getAttribute('data-course-id');
            const currentStatus = this.getAttribute('data-current-status');
            const targetStatus = (currentStatus === 'disabled') ? 'approved' : 'disabled';
            const courseTitle = this.getAttribute('data-course-title') || courseId;

            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';

            fetch('admin_toggle_course.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
              },
              body: JSON.stringify({
                course_id: courseId,
                status: targetStatus,
                csrf_token: csrfToken
              })
            })
              .then(res => res.json())
              .then(data => {
                this.disabled = false;
                if (data.success) {
                  const newStatus = data.new_status;
                  this.setAttribute('data-current-status', newStatus);

                  const statusBadgeCell = document.getElementById('course-status-badge-' + courseId);

                  if (newStatus === 'disabled') {
                    this.className = 'btn btn-sm btn-outline-success rounded-pill px-2.5 btn-toggle-course-status shadow-sm';
                    this.innerHTML = '<i class="bi bi-play-circle-fill me-1"></i> Enable';
                    this.title = 'Quick Enable';
                    if (statusBadgeCell) {
                      statusBadgeCell.innerHTML = '<span class="badge bg-warning bg-opacity-10 text-dark border border-warning px-2 py-1 fs-9 rounded-pill"><i class="bi bi-clock-history me-1 text-danger"></i> Disabled (14d left)</span>';
                    }
                  } else {
                    this.className = 'btn btn-sm btn-outline-warning rounded-pill px-2.5 btn-toggle-course-status shadow-sm';
                    this.innerHTML = '<i class="bi bi-pause-circle-fill me-1"></i> Disable';
                    this.title = 'Quick Disable';
                    if (statusBadgeCell) {
                      statusBadgeCell.innerHTML = '<span class="status-badge-active"><i class="bi bi-check-circle me-1"></i> Approved</span>';
                    }
                  }

                  showAdminToast(data.message || 'Course status updated successfully.');
                } else {
                  this.innerHTML = originalHtml;
                  alert(data.message || 'Failed to update course status.');
                }
              })
              .catch(err => {
                this.disabled = false;
                this.innerHTML = originalHtml;
                alert('Connection error occurred while updating course status.');
              });
          });
        });

        // Secure Delete Course Modal Controller
        const deleteModalEl = document.getElementById('adminDeleteCourseModal');
        const deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
        const deleteForm = document.getElementById('admin-delete-course-form');
        const deleteCourseIdInput = document.getElementById('admin-delete-course-id');
        const deleteCourseTitleDisplay = document.getElementById('admin-delete-course-title');
        const deleteStudentsBadge = document.getElementById('admin-delete-course-students-badge');
        const deleteWarningText = document.getElementById('admin-delete-warning-text');
        const deletePasswordInput = document.getElementById('admin-delete-password-input');
        const deleteAlertContainer = document.getElementById('admin-delete-alert-container');
        const confirmDeleteBtn = document.getElementById('confirm-admin-delete-btn');
        const togglePwdVisBtn = document.getElementById('toggle-delete-pwd-vis');
        const togglePwdIcon = document.getElementById('toggle-pwd-icon');

        // Toggle password visibility
        if (togglePwdVisBtn && deletePasswordInput) {
          togglePwdVisBtn.addEventListener('click', function () {
            const isPassword = (deletePasswordInput.type === 'password');
            deletePasswordInput.type = isPassword ? 'text' : 'password';
            if (togglePwdIcon) {
              togglePwdIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
          });
        }

        // Open Delete Modal Trigger
        document.querySelectorAll('.btn-admin-delete-course').forEach(btn => {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            const courseId = this.getAttribute('data-course-id');
            const courseTitle = this.getAttribute('data-course-title') || courseId;
            const enrolledCount = parseInt(this.getAttribute('data-enrolled-count') || '0', 10);

            if (deleteCourseIdInput) deleteCourseIdInput.value = courseId;
            if (deleteCourseTitleDisplay) deleteCourseTitleDisplay.textContent = courseTitle + ' (Slug: ' + courseId + ')';
            if (deleteStudentsBadge) {
              deleteStudentsBadge.innerHTML = '<i class="bi bi-people-fill me-1"></i>' + enrolledCount + ' Enrolled Students';
            }
            if (deleteWarningText) {
              deleteWarningText.textContent = '⚠️ Warning: This will permanently delete this course and all associated lessons, quizzes, and progress for ' + enrolledCount + ' enrolled students. This action cannot be undone.';
            }
            if (deletePasswordInput) {
              deletePasswordInput.value = '';
              deletePasswordInput.type = 'password';
            }
            if (togglePwdIcon) togglePwdIcon.className = 'bi bi-eye';
            if (deleteAlertContainer) deleteAlertContainer.innerHTML = '';

            if (deleteModal) deleteModal.show();
          });
        });

        // Submit Delete Form
        if (deleteForm) {
          deleteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const courseId = deleteCourseIdInput.value.trim();
            const password = deletePasswordInput.value.trim();

            if (!courseId) {
              alert('Course ID is missing.');
              return;
            }
            if (!password) {
              if (deleteAlertContainer) {
                deleteAlertContainer.innerHTML = '<div class="alert alert-danger py-2 px-3 fs-8 mb-0"><i class="bi bi-exclamation-circle me-1"></i> Please enter your admin account password.</div>';
              }
              deletePasswordInput.focus();
              return;
            }

            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';
            if (deleteAlertContainer) deleteAlertContainer.innerHTML = '';

            fetch('admin_delete_course.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
              },
              body: JSON.stringify({
                course_id: courseId,
                password: password,
                csrf_token: csrfToken
              })
            })
              .then(res => res.json())
              .then(data => {
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.innerHTML = '<i class="bi bi-trash3-fill"></i> <span>Confirm Permanent Delete</span>';

                if (data.success) {
                  if (deleteModal) deleteModal.hide();

                  // Remove row from table
                  const row = document.getElementById('course-row-' + courseId);
                  if (row) {
                    row.style.transition = 'all 0.5s ease';
                    row.style.backgroundColor = '#fee2e2';
                    row.style.opacity = '0';
                    setTimeout(() => {
                      row.remove();
                    }, 500);
                  }

                  showAdminToast(data.message || 'Course permanently deleted successfully.');
                } else {
                  if (deleteAlertContainer) {
                    deleteAlertContainer.innerHTML = '<div class="alert alert-danger py-2 px-3 fs-8 mb-0 d-flex align-items-center gap-1.5"><i class="bi bi-x-circle-fill flex-shrink-0"></i> <span>' + (data.message || 'Invalid Admin Password. Deletion aborted.') + '</span></div>';
                  }
                  deletePasswordInput.focus();
                }
              })
              .catch(err => {
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.innerHTML = '<i class="bi bi-trash3-fill"></i> <span>Confirm Permanent Delete</span>';
                if (deleteAlertContainer) {
                  deleteAlertContainer.innerHTML = '<div class="alert alert-danger py-2 px-3 fs-8 mb-0"><i class="bi bi-wifi-off me-1"></i> Connection error occurred. Please try again.</div>';
                }
              });
          });
        }
      });
    </script>
</body>

</html>