<?php
if (session_status() === PHP_SESSION_NONE) {
  session_name('LMS_ADMIN_SESS');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
  session_start();
}

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

// Fetch live admin user details
$roleCheckStmt = $pdo->prepare("SELECT id, name, email, avatar, role, status, password_hash FROM users WHERE id = ?");
$roleCheckStmt->execute([$_SESSION['user_id']]);
$dbUser = $roleCheckStmt->fetch();

if (!$dbUser || !in_array($dbUser['role'], ['admin', 'super_admin'])) {
  header("Location: login.php");
  exit;
}

$user_role = $dbUser['role'];
$is_super_admin = ($user_role === 'super_admin');
$current_admin_name = $dbUser['name'];
$current_admin_email = $dbUser['email'];

$raw_avatar = $dbUser['avatar'] ?? '';
if (empty($raw_avatar)) {
  $current_admin_avatar = 'https://ui-avatars.com/api/?name=' . urlencode($current_admin_name) . '&background=' . ($is_super_admin ? '0b4528' : '0f4c81') . '&color=fff';
} elseif (preg_match('~^https?://~i', $raw_avatar) || strpos($raw_avatar, 'data:') === 0) {
  $current_admin_avatar = $raw_avatar;
} else {
  $current_admin_avatar = '../' . ltrim($raw_avatar, '/');
}

$primary_theme_color = $is_super_admin ? '#0b4528' : '#0f4c81';
$success_message = '';
$error_message = '';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];

  // 1. Update Certificate Status (Approve, Processing, Dispatched, Issued, Rejected)
  if ($action === 'update_status') {
    $request_id = intval($_POST['request_id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? 'pending'));
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    $valid_statuses = ['pending', 'approved', 'processing', 'dispatched', 'issued', 'rejected'];
    if ($request_id > 0 && in_array($new_status, $valid_statuses)) {
      $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE id = ?");
      $stmt->execute([$request_id]);
      $req = $stmt->fetch();

      if ($req) {
        $updateStmt = $pdo->prepare("UPDATE certificate_requests SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$new_status, $admin_notes, $request_id]);

        // Create student notification based on status
        $student_id = $req['user_id'];
        $course_title = $req['course_title'];
        $code = $req['certificate_code'];

        $notifMsg = "";
        if ($new_status === 'issued' || $new_status === 'approved') {
          $notifMsg = "Congratulations! Your official certificate for '{$course_title}' has been ISSUED and APPROVED. (Ref: {$code})";
        } elseif ($new_status === 'dispatched') {
          $notifMsg = "Your printed hard-copy certificate for '{$course_title}' has been DISPATCHED via courier. (Ref: {$code})" . ($admin_notes ? " Courier Notes: {$admin_notes}" : "");
        } elseif ($new_status === 'processing') {
          $notifMsg = "Your certificate request for '{$course_title}' is currently being printed and processed. (Ref: {$code})";
        } elseif ($new_status === 'rejected') {
          $notifMsg = "Your certificate request for '{$course_title}' was declined. Reason: " . ($admin_notes ?: 'Please contact administrative support.');
        } else {
          $notifMsg = "Your certificate request for '{$course_title}' status has been updated to " . strtoupper($new_status) . ".";
        }

        if ($notifMsg) {
          $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
          $nStmt->execute([$student_id, $notifMsg]);
        }

        $success_message = "Certificate Request #" . $request_id . " status updated to " . strtoupper($new_status) . " successfully!";
      } else {
        $error_message = "Certificate request record not found.";
      }
    } else {
      $error_message = "Invalid status or request ID provided.";
    }
  }

  // 2. Delete Certificate Request
  if ($action === 'delete_request') {
    $request_id = intval($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
      $delStmt = $pdo->prepare("DELETE FROM certificate_requests WHERE id = ?");
      $delStmt->execute([$request_id]);
      $success_message = "Certificate request record deleted successfully.";
    } else {
      $error_message = "Invalid request ID.";
    }
  }

  // 3. Upload Custom Certificate Design (JPG, PNG, SVG)
  if ($action === 'upload_template') {
    $tpl_name = trim($_POST['name'] ?? '');
    $tpl_desc = trim($_POST['description'] ?? '');
    $tpl_is_default = isset($_POST['is_default']) ? 1 : 0;

    if (empty($tpl_name)) {
      $error_message = "Please provide a name for your custom certificate design.";
    } elseif (!isset($_FILES['template_image']) || $_FILES['template_image']['error'] !== UPLOAD_ERR_OK) {
      $error_message = "Please select a custom design image file (supported formats: JPG, PNG, SVG).";
    } else {
      $fileTmp = $_FILES['template_image']['tmp_name'];
      $fileName = $_FILES['template_image']['name'];
      $fileSize = $_FILES['template_image']['size'];
      $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

      $allowed_exts = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
      if (!in_array($ext, $allowed_exts)) {
        $error_message = "Invalid image format (" . htmlspecialchars($ext) . "). Supported formats: JPG, JPEG, PNG, SVG, WEBP.";
      } elseif ($fileSize > 15 * 1024 * 1024) {
        $error_message = "Uploaded image file is too large (maximum allowed: 15MB).";
      } else {
        $destDir = __DIR__ . '/../uploads/certificates/';
        if (!is_dir($destDir)) {
          mkdir($destDir, 0777, true);
        }
        $uniqueName = 'cert_design_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $destDir . $uniqueName;

        if (move_uploaded_file($fileTmp, $destPath)) {
          $bg_image_path = 'uploads/certificates/' . $uniqueName;

          if ($tpl_is_default === 1) {
            $pdo->exec("UPDATE certificate_templates SET is_default = 0");
          }

          $stmt = $pdo->prepare("
                        INSERT INTO certificate_templates 
                        (name, description, type, background_image, theme_color, border_style, font_family, is_default)
                        VALUES (?, ?, 'custom', ?, '#0f4c81', 'custom_bg', 'Cinzel', ?)
                    ");
          $stmt->execute([
            $tpl_name,
            $tpl_desc,
            $bg_image_path,
            $tpl_is_default
          ]);

          $success_message = "Custom certificate design '" . htmlspecialchars($tpl_name) . "' uploaded and ready for generation!";
        } else {
          $error_message = "Failed to save uploaded custom design file to disk.";
        }
      }
    }
  }

  // 4. Delete Custom Template
  if ($action === 'delete_template') {
    $tpl_id = intval($_POST['template_id'] ?? 0);
    if ($tpl_id > 0) {
      $stmt = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?");
      $stmt->execute([$tpl_id]);
      $tpl = $stmt->fetch();

      if ($tpl) {
        if (!empty($tpl['background_image'])) {
          $fullFile = __DIR__ . '/../' . ltrim($tpl['background_image'], '/');
          if (file_exists($fullFile)) {
            @unlink($fullFile);
          }
        }
        $delStmt = $pdo->prepare("DELETE FROM certificate_templates WHERE id = ?");
        $delStmt->execute([$tpl_id]);
        $success_message = "Custom certificate design deleted successfully.";
      } else {
        $error_message = "Design template record not found.";
      }
    }
  }

  // 5. Set Default Template
  if ($action === 'set_default_template') {
    $tpl_id = intval($_POST['template_id'] ?? 0);
    if ($tpl_id > 0) {
      $pdo->exec("UPDATE certificate_templates SET is_default = 0");
      $stmt = $pdo->prepare("UPDATE certificate_templates SET is_default = 1 WHERE id = ?");
      $stmt->execute([$tpl_id]);
      $success_message = "Default certificate design updated successfully!";
    }
  }

  // 6. Generate & Save Certificate Design (from Generator Studio)
  if ($action === 'generate_and_issue') {
    $request_id = intval($_POST['request_id'] ?? 0);
    $student_name = trim($_POST['student_name'] ?? '');
    $course_title = trim($_POST['course_title'] ?? '');
    $completion_date = trim($_POST['completion_date'] ?? date('Y-m-d'));
    $quiz_summary = trim($_POST['quiz_summary'] ?? 'Progress: 100% | Verified');
    $cert_code = trim($_POST['cert_code'] ?? '');
    $template_id = intval($_POST['template_id'] ?? 0);
    $admin_notes = trim($_POST['admin_notes'] ?? 'Custom designed credential issued by Academic Director.');

    if (empty($cert_code)) {
      $cert_code = 'CERT-CSLK-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
    }

    // Process generated certificate image if uploaded (base64 PNG from html2canvas)
    $saved_image_path = null;
    $cert_img_data = $_POST['certificate_image_data'] ?? '';
    if (!empty($cert_img_data) && preg_match('/^data:image\/(\w+);base64,/', $cert_img_data)) {
      $raw_data = substr($cert_img_data, strpos($cert_img_data, ',') + 1);
      $decoded = base64_decode($raw_data);
      if ($decoded !== false) {
        $destDir = __DIR__ . '/../uploads/certificates/';
        if (!is_dir($destDir)) {
          mkdir($destDir, 0777, true);
        }
        $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '', $cert_code);
        $uniqueImgName = 'issued_cert_' . strtolower($safe_code) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
        $fullDestPath = $destDir . $uniqueImgName;
        if (file_put_contents($fullDestPath, $decoded)) {
          $saved_image_path = 'uploads/certificates/' . $uniqueImgName;
        }
      }
    }

    if ($request_id > 0) {
      // Update existing certificate request with generated design
      $stmt = $pdo->prepare("SELECT * FROM certificate_requests WHERE id = ?");
      $stmt->execute([$request_id]);
      $req = $stmt->fetch();

      if ($req) {
        $uStmt = $pdo->prepare("
                    UPDATE certificate_requests 
                    SET full_name_on_certificate = ?,
                        course_title = ?,
                        completion_date = ?,
                        quiz_score_summary = ?,
                        certificate_code = ?,
                        template_id = ?,
                        certificate_image = COALESCE(?, certificate_image),
                        admin_notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
        $uStmt->execute([
          $student_name,
          $course_title,
          $completion_date,
          $quiz_summary,
          $cert_code,
          $template_id > 0 ? $template_id : null,
          $saved_image_path,
          $admin_notes,
          $request_id
        ]);

        $success_message = "Certificate image and design for '" . htmlspecialchars($student_name) . "' generated and saved into database successfully! Click 'View' to preview it, and release it to the student via the 'Status' button.";
      }
    } else {
      // Standalone creation from Search / Quick Generator
      $user_id = intval($_POST['user_id'] ?? 0);
      $course_id = trim($_POST['course_id'] ?? '');

      if ($user_id > 0 && !empty($course_id)) {
        $uInfoStmt = $pdo->prepare("SELECT email, academic_id, nic FROM users WHERE id = ?");
        $uInfoStmt->execute([$user_id]);
        $uInfo = $uInfoStmt->fetch();

        $reg_email = $uInfo['email'] ?? 'student@computerscience.lk';
        $nic_num = $uInfo['nic'] ?? 'N/A';

        $insStmt = $pdo->prepare("
                    INSERT INTO certificate_requests 
                    (user_id, course_id, full_name_on_certificate, nic_number, mobile_number, registered_email, course_title, completion_date, course_progress, quiz_score_summary, delivery_method, status, certificate_code, template_id, certificate_image, admin_notes)
                    VALUES (?, ?, ?, ?, 'N/A', ?, ?, ?, '100%', ?, 'digital_only', 'pending', ?, ?, ?, ?)
                ");
        $insStmt->execute([
          $user_id,
          $course_id,
          $student_name,
          $nic_num,
          $reg_email,
          $course_title,
          $completion_date,
          $quiz_summary,
          $cert_code,
          $template_id > 0 ? $template_id : null,
          $saved_image_path,
          $admin_notes
        ]);

        $success_message = "New certificate design for '" . htmlspecialchars($student_name) . "' generated and saved! Click 'View' on the table to preview it, and use the 'Status' button to release it to the student.";
      } else {
        $error_message = "Please select a valid student and course to generate a certificate.";
      }
    }
  }
}

try {
  // Fetch all certificate requests with user details and course details
  $stmt = $pdo->query("
        SELECT cr.*, u.name as user_account_name, u.email as user_email, u.academic_id as user_academic_id, u.avatar as user_avatar, c.thumbnail as course_thumbnail, c.category as course_category
        FROM certificate_requests cr
        JOIN users u ON cr.user_id = u.id
        JOIN courses c ON cr.course_id = c.id
        ORDER BY cr.created_at DESC
    ");
  $all_requests = $stmt->fetchAll();

  // Fetch list of courses for filtering & generator dropdowns
  $cStmt = $pdo->query("SELECT DISTINCT id, title FROM courses ORDER BY title ASC");
  $courses_list = $cStmt->fetchAll();

  // Fetch all custom certificate templates (custom uploaded JPG, PNG, SVG)
  $tStmt = $pdo->query("SELECT * FROM certificate_templates WHERE background_image IS NOT NULL ORDER BY is_default DESC, id DESC");
  $templates_list = $tStmt->fetchAll();

  // Compute KPI metrics
  $total_count = count($all_requests);
  $pending_count = 0;
  $home_delivery_count = 0;
  $issued_count = 0;

  foreach ($all_requests as $r) {
    if ($r['status'] === 'pending')
      $pending_count++;
    if ($r['delivery_method'] === 'home_delivery')
      $home_delivery_count++;
    if (in_array($r['status'], ['approved', 'issued']))
      $issued_count++;
  }

  $templates_count = count($templates_list);

} catch (PDOException $e) {
  die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Certificate Creation & Custom Design Studio | Computerscience.lk Admin</title>

  <!-- Google Fonts: Official Certificate Typography Suite -->
  <link
    href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800;900&family=Cormorant+Garamond:ital,wght@0,600;0,700;1,400;1,600&family=Great+Vibes&family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@400;500;600;700;800&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,600;0,700;1,400;1,600&display=swap"
    rel="stylesheet">

  <!-- Local Bootstrap 5 CSS -->
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="../assets/css/bootstrap-icons.min.css">

  <!-- Local Tailwind CSS -->
  <script src="../assets/js/tailwind.js"></script>
  <script>
    tailwind.config = {
      corePlugins: { preflight: false },
      theme: {
        extend: {
          colors: {
            moodle: { blue: '#0f4c81', orange: '#f26f21', bg: '#f8f9fa' }
          }
        }
      }
    }
  </script>

  <link rel="stylesheet" href="../assets/css/style.css">
  <?php render_i18n_js(); ?>

  <style>
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f4f6f9;
      color: #1e293b;
    }

    .kpi-card {
      border: none;
      border-radius: 16px;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      transition: all 0.25s ease;
      position: relative;
      overflow: hidden;
    }

    .kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    }

    .kpi-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
    }

    .table-container,
    .studio-card {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
      border: 1px solid rgba(226, 232, 240, 0.8);
      overflow: hidden;
    }

    .custom-table th {
      background-color: #f8fafc;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #475569;
      font-weight: 700;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #e2e8f0;
    }

    .custom-table td {
      padding: 1rem 1.25rem;
      vertical-align: middle;
      border-bottom: 1px solid #f1f5f9;
    }

    .custom-table tr:hover td {
      background-color: #f8fafc;
    }

    .modal-content {
      border-radius: 20px;
      border: none;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    /* Template Cards in Generation Controls & Gallery */
    .template-thumb-card {
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      transition: all 0.2s ease;
      cursor: pointer;
      background: #ffffff;
      position: relative;
      overflow: hidden;
    }

    .template-thumb-card:hover {
      border-color: #0f4c81;
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(15, 76, 129, 0.15);
    }

    .template-thumb-card.active-tpl {
      border-color: #0f4c81;
      background-color: #f0f7ff;
      box-shadow: 0 0 0 3px rgba(15, 76, 129, 0.25);
    }

    /* Drag and drop upload zone */
    .upload-dropzone {
      border: 2px dashed #cbd5e1;
      border-radius: 14px;
      padding: 28px 20px;
      text-align: center;
      background: #f8fafc;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .upload-dropzone:hover,
    .upload-dropzone.dragover {
      border-color: #0f4c81;
      background: #f0f7ff;
    }

    /* Student Search Dropdown Results Box */
    .student-search-results {
      max-height: 260px;
      overflow-y: auto;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #ffffff;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
      position: absolute;
      width: 100%;
      z-index: 1050;
    }

    .student-search-item {
      padding: 9px 12px;
      cursor: pointer;
      border-bottom: 1px solid #f1f5f9;
      transition: background 0.15s ease;
    }

    .student-search-item:hover {
      background-color: #f0f7ff;
    }

    .student-search-item:last-child {
      border-bottom: none;
    }

    /* Live Certificate Canvas Viewport */
    .cert-preview-viewport {
      background: #1e293b;
      padding: 20px;
      border-radius: 16px;
      display: flex;
      justify-content: center;
      align-items: center;
      overflow: auto;
      min-height: 520px;
      max-height: 82vh;
    }

    .certificate-canvas {
      width: 1000px;
      min-width: 1000px;
      height: 707px;
      /* Exact A4 Landscape Proportion (1 : 1.414) */
      background: #ffffff;
      position: relative;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
      border-radius: 4px;
      box-sizing: border-box;
      background-size: 100% 100%;
      background-position: center;
      background-repeat: no-repeat;
      overflow: hidden;
      user-select: none;
    }

    /* Draggable Canvas Overlay Elements */
    .draggable-element {
      position: absolute;
      cursor: move;
      padding: 4px 8px;
      border: 1px dashed transparent;
      border-radius: 4px;
      transition: border-color 0.15s ease;
      white-space: nowrap;
      user-select: none;
      touch-action: none;
      z-index: 10;
    }

    .draggable-element:hover {
      border-color: rgba(15, 76, 129, 0.6);
      background-color: rgba(255, 255, 255, 0.3);
    }

    .draggable-element.selected-el {
      border: 2px solid #0f4c81 !important;
      background-color: rgba(255, 255, 255, 0.4) !important;
      box-shadow: 0 0 0 2px rgba(15, 76, 129, 0.2);
      z-index: 20;
    }

    .draggable-element.selected-el::after {
      content: attr(data-label);
      position: absolute;
      top: -20px;
      left: 0;
      background: #0f4c81;
      color: #ffffff;
      font-size: 10px;
      font-weight: 700;
      padding: 1px 6px;
      border-radius: 3px;
      pointer-events: none;
      font-family: 'Inter', sans-serif;
    }

    /* A4 Landscape High-Resolution Print Stylesheet */
    @media print {
      body * {
        visibility: hidden;
      }

      #printable-certificate-container,
      #printable-certificate-container * {
        visibility: visible;
      }

      #printable-certificate-container {
        position: fixed;
        left: 0;
        top: 0;
        width: 100vw;
        height: 100vh;
        margin: 0;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff;
      }

      .certificate-canvas {
        width: 100% !important;
        height: 100% !important;
        min-width: 100% !important;
        box-shadow: none !important;
        border-radius: 0 !important;
      }

      .draggable-element {
        border: none !important;
        background: transparent !important;
      }

      .draggable-element.selected-el::after {
        display: none !important;
      }

      @page {
        size: A4 landscape;
        margin: 0;
      }
    }
  </style>
</head>

<body>

  <?php
    $active_nav = 'certificates';
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
            <i class="bi bi-award-fill text-warning"></i>
            <span>Course Certificate Management & Custom Studio</span>
          </span>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2.5">
        <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
          <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
        <a href="students.php" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold text-dark border">
          <i class="bi bi-person-badge-fill me-1"></i> Students
        </a>
        <a href="student_analytics.php" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold text-dark border">
          <i class="bi bi-graph-up-arrow me-1"></i> Analytics
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

    <!-- Main Content -->
    <main class="py-4 flex-grow-1">
      <div class="container-fluid px-3 px-md-4">

      <!-- Page Title & Header Actions -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
          <h2 class="fw-bold text-dark mb-1 fs-4 d-flex align-items-center gap-2">
            <i class="bi bi-award-fill text-warning"></i>
            <span>Course Certificate Management & Custom Studio</span>
          </h2>
          <p class="text-muted fs-8 mb-0">Upload custom designs (JPG, PNG, SVG), search student applications, and
            customize draggable credential elements directly on the template.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <!-- Primary Certificate Generation Trigger Button -->
          <button onclick="openCertificateGenerator(null)"
            class="btn btn-primary btn-sm px-3.5 py-2 rounded-pill shadow-sm fw-bold text-white d-flex align-items-center gap-2"
            style="background-color: #0f4c81;">
            <i class="bi bi-magic fs-6"></i>
            <span>⚡ Generate Certificate</span>
          </button>

          <button onclick="scrollToDesignStudio()"
            class="btn btn-outline-primary btn-sm px-3 py-2 rounded-pill fw-semibold d-flex align-items-center gap-1.5">
            <i class="bi bi-cloud-arrow-up-fill text-warning"></i>
            <span>Custom Designs (<?php echo $templates_count; ?>)</span>
          </button>

          <button onclick="exportCertificatesCSV()"
            class="btn btn-success btn-sm px-3 py-2 rounded-pill shadow-sm fw-semibold text-white d-flex align-items-center gap-1.5">
            <i class="bi bi-file-earmark-spreadsheet-fill"></i>
            <span>Export CSV</span>
          </button>
        </div>
      </div>

      <!-- Toast Alerts -->
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
          <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- 5 High-Impact KPI Metric Cards -->
      <div class="row g-3 mb-4">
        <!-- KPI 1: Total Requests -->
        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card p-3.5 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Total Certificate Requests</small>
                <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo number_format($total_count); ?></h3>
                <small class="text-muted fs-9">Lifetime Submissions</small>
              </div>
              <div class="kpi-icon-box bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-files"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 2: Pending Review -->
        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card p-3.5 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Pending Review & Approval</small>
                <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo number_format($pending_count); ?></h3>
                <small class="text-warning fs-9 fw-semibold"><i class="bi bi-clock-history me-1"></i>Action
                  Required</small>
              </div>
              <div class="kpi-icon-box bg-warning bg-opacity-10 text-warning">
                <i class="bi bi-hourglass-split"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 3: Issued & Approved -->
        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card p-3.5 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Issued & Verified</small>
                <h3 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($issued_count); ?></h3>
                <small class="text-success fs-9 fw-semibold"><i class="bi bi-check-all me-1"></i>Active
                  Credentials</small>
              </div>
              <div class="kpi-icon-box bg-success bg-opacity-10 text-success">
                <i class="bi bi-patch-check-fill"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 4: Custom Design Templates -->
        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card p-3.5 border-start border-4" style="border-left-color: #8b5cf6 !important;">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Custom Uploaded Designs</small>
                <h3 class="fw-bold mb-0 mt-1" style="color: #8b5cf6;"><?php echo number_format($templates_count); ?>
                </h3>
                <small class="text-muted fs-9"><i class="bi bi-image me-1"></i>JPG, PNG, SVG</small>
              </div>
              <div class="kpi-icon-box" style="background-color: rgba(139,92,246,0.1); color: #8b5cf6;">
                <i class="bi bi-palette2"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Student Requests Table Section -->
      <div class="table-container p-4 bg-white mb-5">

        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
          <div>
            <h5 class="fw-bold text-dark mb-0 fs-6 d-flex align-items-center gap-2">
              <i class="bi bi-people-fill text-primary"></i>
              <span>Student Certificate Applications</span>
            </h5>
            <small class="text-muted fs-9">Click 'Generate' on any row to open the custom template studio with that
              student's academic information.</small>
          </div>
          <div>
            <span class="badge bg-light text-secondary border fs-8">
              Showing <?php echo count($all_requests); ?> records
            </span>
          </div>
        </div>

        <!-- Filters Bar -->
        <div class="row g-3 align-items-center mb-4 pb-3 border-bottom">
          <!-- Filter 1: Status -->
          <div class="col-md-3">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1"><i
                class="bi bi-funnel me-1 text-primary"></i>Filter Status:</label>
            <select id="cert-status-filter" class="form-select fs-8 bg-light border">
              <option value="">All Statuses (<?php echo $total_count; ?>)</option>
              <option value="pending">Pending Review (<?php echo $pending_count; ?>)</option>
              <option value="approved">Approved</option>
              <option value="processing">Processing & Printing</option>
              <option value="dispatched">Dispatched (Courier)</option>
              <option value="issued">Issued</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>

          <!-- Filter 2: Delivery Method -->
          <div class="col-md-3">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1"><i
                class="bi bi-truck me-1 text-primary"></i>Delivery Method:</label>
            <select id="cert-delivery-filter" class="form-select fs-8 bg-light border">
              <option value="">All Methods</option>
              <option value="digital_only">Digital Copy Only (PDF)</option>
              <option value="home_delivery">Home Delivery (Printed Hard Copy)</option>
            </select>
          </div>

          <!-- Filter 3: Course -->
          <div class="col-md-3">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1"><i
                class="bi bi-journal-bookmark me-1 text-primary"></i>Course:</label>
            <select id="cert-course-filter" class="form-select fs-8 bg-light border">
              <option value="">All Courses (<?php echo count($courses_list); ?>)</option>
              <?php foreach ($courses_list as $cl): ?>
                <option value="<?php echo htmlspecialchars($cl['title']); ?>">
                  <?php echo htmlspecialchars($cl['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Filter 4: Text Search -->
          <div class="col-md-3">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted mb-1"><i
                class="bi bi-search me-1 text-primary"></i>Search Table:</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" id="cert-search-input" class="form-control bg-light border-start-0 fs-8"
                placeholder="Search name, NIC, code...">
            </div>
          </div>
        </div>

        <!-- Requests Table -->
        <div class="table-responsive">
          <table class="table custom-table align-middle mb-0" id="certificates-table">
            <thead>
              <tr>
                <th scope="col" style="min-width: 140px;">Reference Code</th>
                <th scope="col" style="min-width: 220px;">Student & Identity</th>
                <th scope="col" style="min-width: 200px;">Course & Evaluation</th>
                <th scope="col" style="min-width: 200px;">Delivery Mode & Address</th>
                <th scope="col" class="text-center" style="min-width: 120px;">Status</th>
                <th scope="col" class="text-center" style="min-width: 200px;">Actions</th>
              </tr>
            </thead>
            <tbody class="fs-8">
              <?php if (empty($all_requests)): ?>
                <tr>
                  <td colspan="6" class="text-center py-5 text-muted">
                    <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                    No certificate requests found in the system.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($all_requests as $idx => $r):
                  $status = strtolower($r['status']);
                  $isHome = ($r['delivery_method'] === 'home_delivery');
                  $statusBadgeClass = 'bg-secondary text-white';
                  if ($status === 'pending')
                    $statusBadgeClass = 'bg-warning text-dark border border-warning';
                  elseif (in_array($status, ['approved', 'issued']))
                    $statusBadgeClass = 'bg-success text-white';
                  elseif (in_array($status, ['processing', 'dispatched']))
                    $statusBadgeClass = 'bg-info text-white';
                  elseif ($status === 'rejected')
                    $statusBadgeClass = 'bg-danger text-white';

                  $r_json = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                  ?>
                  <tr class="cert-row" data-status="<?php echo $status; ?>"
                    data-delivery="<?php echo $r['delivery_method']; ?>"
                    data-course="<?php echo htmlspecialchars($r['course_title']); ?>"
                    data-fullname="<?php echo htmlspecialchars($r['full_name_on_certificate']); ?>"
                    data-account-name="<?php echo htmlspecialchars($r['user_account_name']); ?>"
                    data-email="<?php echo htmlspecialchars($r['registered_email']); ?>"
                    data-nic="<?php echo htmlspecialchars($r['nic_number']); ?>"
                    data-mobile="<?php echo htmlspecialchars($r['mobile_number']); ?>"
                    data-code="<?php echo htmlspecialchars($r['certificate_code']); ?>"
                    data-city="<?php echo htmlspecialchars($r['city'] ?? ''); ?>"
                    data-district="<?php echo htmlspecialchars($r['district'] ?? ''); ?>">

                    <!-- Reference Code & Date -->
                    <td>
                      <span class="badge bg-light text-primary border font-mono fs-8 fw-bold px-2 py-1 mb-1 d-inline-block">
                        <?php echo htmlspecialchars($r['certificate_code']); ?>
                      </span>
                      <div class="text-muted fs-9"><i
                          class="bi bi-calendar-event me-1"></i><?php echo date('M d, Y H:i', strtotime($r['created_at'])); ?>
                      </div>
                    </td>

                    <!-- Student & Identity -->
                    <td>
                      <div class="d-flex align-items-center gap-2.5">
                        <img
                          src="<?php echo htmlspecialchars(get_user_avatar($r['user_avatar'], $r['full_name_on_certificate'])); ?>"
                          class="rounded-circle border" style="width: 38px; height: 38px; object-fit: cover;" alt="Avatar">
                        <div>
                          <div class="fw-bold text-dark fs-8 text-truncate" style="max-width: 180px;">
                            <?php echo htmlspecialchars($r['full_name_on_certificate']); ?></div>
                          <div class="text-muted fs-9 text-truncate" style="max-width: 180px;">Account:
                            <?php echo htmlspecialchars($r['user_account_name']); ?>
                            (<?php echo htmlspecialchars($r['user_academic_id']); ?>)</div>
                          <div class="d-flex align-items-center gap-1.5 mt-0.5">
                            <span class="badge bg-light text-secondary border fs-9"><i
                                class="bi bi-card-heading me-1"></i>NIC:
                              <?php echo htmlspecialchars($r['nic_number']); ?></span>
                            <span class="badge bg-light text-secondary border fs-9"><i
                                class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($r['mobile_number']); ?></span>
                          </div>
                        </div>
                      </div>
                    </td>

                    <!-- Course & Evaluation -->
                    <td>
                      <span class="badge bg-light text-primary border fs-9 fw-semibold text-wrap text-start lh-base mb-1"
                        style="max-width: 220px;">
                        <?php echo htmlspecialchars($r['course_title']); ?>
                      </span>
                      <div class="text-success fs-9 fw-semibold"><i
                          class="bi bi-check2-circle me-1"></i><?php echo htmlspecialchars($r['quiz_score_summary']); ?>
                      </div>
                      <small class="text-muted fs-9"><i class="bi bi-flag me-1"></i>Completed:
                        <?php echo date('M d, Y', strtotime($r['completion_date'])); ?></small>
                    </td>

                    <!-- Delivery Mode & Address -->
                    <td>
                      <?php if ($isHome): ?>
                        <span
                          class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2.5 py-1 fs-9 fw-bold d-inline-flex align-items-center gap-1 mb-1">
                          <i class="bi bi-truck"></i> Home Delivery (Courier)
                        </span>
                        <div class="text-dark fs-9 fw-medium text-truncate" style="max-width: 220px;"
                          title="<?php echo htmlspecialchars($r['delivery_address'] . ', ' . $r['city'] . ', ' . $r['district'] . ' (' . $r['postal_code'] . ')'); ?>">
                          <i
                            class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($r['delivery_address']); ?>,
                          <?php echo htmlspecialchars($r['city']); ?>
                        </div>
                        <small class="text-muted fs-9"><?php echo htmlspecialchars($r['district']); ?>
                          (<?php echo htmlspecialchars($r['postal_code']); ?>)</small>
                      <?php else: ?>
                        <span
                          class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-2.5 py-1 fs-9 fw-bold d-inline-flex align-items-center gap-1 mb-1">
                          <i class="bi bi-file-earmark-pdf"></i> Digital Copy (PDF)
                        </span>
                        <div class="text-muted fs-9 text-truncate" style="max-width: 200px;"><i
                            class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($r['registered_email']); ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td class="text-center">
                      <span
                        class="badge <?php echo $statusBadgeClass; ?> px-3 py-1.5 rounded-pill fs-8 fw-semibold text-uppercase">
                        <?php echo htmlspecialchars($status); ?>
                      </span>

                      <!-- Email Sent Badge Container -->
                      <div id="email-sent-badge-container-<?php echo $r['id']; ?>" class="mt-1">
                        <?php if (!empty($r['email_sent_at'])): ?>
                          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-semibold d-inline-flex align-items-center gap-1" title="Emailed on <?php echo date('Y-m-d H:i', strtotime($r['email_sent_at'])); ?>">
                            <i class="bi bi-envelope-check-fill"></i>
                            <span>Email Sent</span>
                            <span class="text-muted fw-normal" style="font-size: 0.65rem;">(<?php echo date('M d, H:i', strtotime($r['email_sent_at'])); ?>)</span>
                          </span>
                        <?php endif; ?>
                      </div>

                      <?php if (!empty($r['admin_notes'])): ?>
                        <div class="text-muted fs-9 mt-1 text-truncate" style="max-width: 130px;"
                          title="<?php echo htmlspecialchars($r['admin_notes']); ?>">
                          <i class="bi bi-chat-left-dots me-1"></i><?php echo htmlspecialchars($r['admin_notes']); ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <!-- Action Buttons -->
                    <td class="text-center">
                      <div class="d-flex align-items-center justify-content-center gap-1.5 flex-wrap">
                        <!-- View Generated Certificate Modal Button -->
                        <button type="button"
                          class="btn btn-sm <?php echo !empty($r['certificate_image']) ? 'btn-info text-white' : 'btn-outline-info'; ?> rounded-pill px-2.5 py-1 fs-8 fw-bold d-flex align-items-center gap-1 shadow-xs"
                          onclick='openCertificateViewerModal(<?php echo $r_json; ?>)' title="View Generated Certificate">
                          <i class="bi bi-eye-fill"></i>
                          <span>View</span>
                        </button>

                        <!-- Email Certificate Action Button with Mail Icon -->
                        <button type="button"
                          class="btn btn-sm btn-outline-warning rounded-pill px-2.5 py-1 fs-8 fw-bold d-flex align-items-center gap-1 shadow-xs email-btn-<?php echo $r['id']; ?>"
                          onclick="sendCertificateEmail(this, <?php echo $r['id']; ?>)"
                          title="<?php echo !empty($r['email_sent_at']) ? 'Re-send Certificate Email (Sent: ' . date('M d, H:i', strtotime($r['email_sent_at'])) . ')' : 'Email Certificate PDF Attachment to Student'; ?>">
                          <i class="bi bi-envelope-at-fill"></i>
                          <span>Email</span>
                        </button>

                        <!-- Primary 'Generate' Button for this student -->
                        <button type="button"
                          class="btn btn-sm btn-primary rounded-pill px-3 py-1 fs-8 fw-bold text-white shadow-xs d-flex align-items-center gap-1"
                          style="background-color: #0f4c81;" onclick='openCertificateGenerator(<?php echo $r_json; ?>)'>
                          <i class="bi bi-award-fill text-warning"></i>
                          <span>Generate</span>
                        </button>

                        <!-- Update Status Modal -->
                        <button type="button"
                          class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 fs-8 fw-semibold"
                          onclick='openStatusModal(<?php echo $r_json; ?>)' title="Update Request Status">
                          <i class="bi bi-pencil-square"></i> Status
                        </button>

                        <?php if ($isHome): ?>
                          <!-- Shipping Slip Print Modal -->
                          <button type="button"
                            class="btn btn-sm btn-outline-success rounded-pill px-2.5 py-1 fs-8 fw-semibold"
                            title="Print Shipping Address Slip" onclick='openShippingSlipModal(<?php echo $r_json; ?>)'>
                            <i class="bi bi-printer-fill"></i> Slip
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>

      <!-- Section: Upload Custom Certificate Designs (JPG, PNG, SVG) -->
      <div class="studio-card p-4 bg-white mb-5" id="design-templates-section">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom gap-2">
          <div>
            <h4 class="fw-bold text-dark mb-1 fs-5 d-flex align-items-center gap-2">
              <i class="bi bi-cloud-arrow-up-fill text-warning"></i>
              <span>Custom Certificate Designs Management</span>
            </h4>
            <p class="text-muted fs-8 mb-0">Upload custom certificate backgrounds in JPG, PNG, or SVG formats. Uploaded
              templates will automatically appear under 'Generation Controls' in the certificate creator.</p>
          </div>
          <div>
            <span
              class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-1.5 rounded-pill fs-8 fw-bold">
              <?php echo $templates_count; ?> Custom Designs Available
            </span>
          </div>
        </div>

        <div class="row g-4">
          <!-- Left Column: Upload New Custom Design Form -->
          <div class="col-lg-5">
            <div class="p-3.5 bg-light rounded-4 border h-100">
              <h6 class="fw-bold text-dark fs-7 mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-plus-circle-fill text-primary"></i>
                <span>Upload New Custom Design</span>
              </h6>

              <form action="certificates.php" method="POST" enctype="multipart/form-data"
                id="upload-custom-design-form">
                <input type="hidden" name="action" value="upload_template">

                <div class="mb-3">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">Design / Template Name <span
                      class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control form-control-sm bg-white"
                    placeholder="e.g. Modern Dark Emerald Convocation" required>
                </div>

                <div class="mb-3">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">Description (Optional)</label>
                  <input type="text" name="description" class="form-control form-control-sm bg-white"
                    placeholder="e.g. For Advanced Algorithms & Data Structures graduates">
                </div>

                <!-- Dropzone Image File Input -->
                <div class="mb-3">
                  <label class="form-label fs-8 fw-semibold text-dark mb-1">Certificate Artwork Image File (JPG, PNG,
                    SVG) <span class="text-danger">*</span></label>
                  <div class="upload-dropzone" id="main-tpl-dropzone"
                    onclick="document.getElementById('main-tpl-image-input').click()">
                    <input type="file" name="template_image" id="main-tpl-image-input" class="d-none"
                      accept=".png, .jpg, .jpeg, .svg, .webp" required onchange="handleMainImageSelected(this)">
                    <div id="main-dropzone-prompt">
                      <i class="bi bi-cloud-arrow-up text-primary fs-1 d-block mb-1"></i>
                      <h6 class="fw-bold text-dark fs-8 mb-1">Click to select or drag & drop design file</h6>
                      <small class="text-muted fs-9 d-block">Supported formats: <strong>JPG, PNG, SVG, WEBP</strong> (up
                        to 15MB)</small>
                      <small class="text-muted fs-9">A4 Landscape ratio recommended</small>
                    </div>
                    <div id="main-dropzone-preview" style="display: none;">
                      <img id="main-preview-upload-img" src="" class="img-fluid rounded-3 border mb-2"
                        style="max-height: 140px; object-fit: contain;">
                      <div>
                        <span class="badge bg-success px-2.5 py-1 rounded-pill fs-9 fw-bold"
                          id="main-upload-filename-badge">filename.png</span>
                        <button type="button" class="btn btn-sm btn-link text-danger fs-9 p-0 ms-2"
                          onclick="clearMainUploadImage(event)">Remove</button>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-check form-switch mb-3">
                  <input class="form-check-input" type="checkbox" name="is_default" id="main-upload-is-default-switch">
                  <label class="form-check-label fs-8 fw-semibold text-dark" for="main-upload-is-default-switch">
                    Set as default template
                  </label>
                </div>

                <button type="submit"
                  class="btn btn-primary btn-sm w-100 py-2 rounded-pill fw-bold text-white shadow-sm d-flex align-items-center justify-content-center gap-1.5"
                  style="background-color: #0f4c81;">
                  <i class="bi bi-cloud-arrow-up-fill"></i>
                  <span>Upload & Save Custom Design</span>
                </button>

              </form>
            </div>
          </div>

          <!-- Right Column: Gallery of Uploaded Designs -->
          <div class="col-lg-7">
            <h6 class="fw-bold text-dark fs-7 mb-3 d-flex align-items-center gap-2">
              <i class="bi bi-grid-fill text-primary"></i>
              <span>Uploaded Custom Designs Gallery</span>
            </h6>

            <?php if (empty($templates_list)): ?>
              <div class="text-center py-5 bg-light rounded-4 border">
                <i class="bi bi-image fs-1 d-block mb-2 text-muted"></i>
                <h6 class="fw-bold text-dark fs-8 mb-1">No custom designs uploaded yet</h6>
                <p class="text-muted fs-9 max-w-sm mx-auto mb-3">Upload your first custom certificate template in JPG,
                  PNG, or SVG format to start generating customized certificates.</p>
              </div>
            <?php else: ?>
              <div class="row g-3">
                <?php foreach ($templates_list as $tpl):
                  $isDef = ($tpl['is_default'] == 1);
                  ?>
                  <div class="col-md-6">
                    <div class="template-thumb-card p-2.5 h-100 d-flex flex-column justify-content-between">
                      <div>
                        <!-- Thumbnail Preview -->
                        <div
                          class="rounded-3 border overflow-hidden position-relative mb-2 bg-dark d-flex align-items-center justify-content-center"
                          style="height: 120px;">
                          <img src="../<?php echo htmlspecialchars($tpl['background_image']); ?>"
                            style="width: 100%; height: 100%; object-fit: contain;" alt="Design Thumbnail">
                          <?php if ($isDef): ?>
                            <span
                              class="badge bg-warning text-dark fs-9 fw-bold position-absolute top-0 end-0 m-1.5 shadow-xs">
                              <i class="bi bi-star-fill me-1"></i> Default
                            </span>
                          <?php endif; ?>
                        </div>

                        <h6 class="fw-bold text-dark mb-0.5 fs-8 text-truncate">
                          <?php echo htmlspecialchars($tpl['name']); ?></h6>
                        <small
                          class="text-muted fs-9 d-block mb-2 text-truncate"><?php echo htmlspecialchars($tpl['description'] ?: 'Custom template format'); ?></small>
                      </div>

                      <div class="d-flex align-items-center justify-content-between pt-2 border-top gap-1">
                        <button type="button"
                          class="btn btn-sm btn-primary rounded-pill px-2.5 py-1 fs-9 fw-semibold text-white"
                          onclick='openGeneratorWithTemplate(<?php echo $tpl['id']; ?>)'>
                          <i class="bi bi-magic me-1"></i> Use Design
                        </button>

                        <?php if (!$isDef): ?>
                          <form action="certificates.php" method="POST" class="d-inline">
                            <input type="hidden" name="action" value="set_default_template">
                            <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-warning text-dark rounded-pill px-2 py-1 fs-9"
                              title="Set as default">
                              <i class="bi bi-star"></i>
                            </button>
                          </form>
                        <?php endif; ?>

                        <form action="certificates.php" method="POST" class="d-inline"
                          onsubmit="return confirm('Delete this custom design template?');">
                          <input type="hidden" name="action" value="delete_template">
                          <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 fs-9"
                            title="Delete design">
                            <i class="bi bi-trash3-fill"></i>
                          </button>
                        </form>
                      </div>

                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

          </div>
        </div>

      </div>

    </div>
  </main>

  <!-- Interactive Official Certificate Generator & Canvas Studio Modal -->
  <div class="modal fade" id="certificateGeneratorModal" tabindex="-1" aria-labelledby="certificateGeneratorModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-fullscreen modal-dialog-centered">
      <div class="modal-content bg-light">

        <!-- Studio Header Bar -->
        <div class="modal-header border-bottom py-2.5 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle bg-warning p-1.5 text-dark d-flex align-items-center justify-content-center"
              style="width: 36px; height: 36px;">
              <i class="bi bi-magic fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6">Certificate Creator & Draggable Design Studio</h5>
              <small class="text-white text-opacity-75 fs-9">Custom design template editor with draggable & customizable
                certificate elements</small>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2 ms-auto">
            <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold"
              onclick="printOfficialCertificate()">
              <i class="bi bi-printer-fill me-1.5"></i> Print A4 Landscape
            </button>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"
              aria-label="Close"></button>
          </div>
        </div>

        <!-- Studio Body: Two Columns (Generation Controls on Left, Live Canvas on Right) -->
        <div class="modal-body p-3 p-lg-4">
          <div class="row g-3 h-100">

            <!-- Left Column: Generation Controls (col-lg-5 col-xl-4) -->
            <div class="col-lg-5 col-xl-4">
              <div class="bg-white p-3.5 rounded-4 border shadow-xs h-100 overflow-auto"
                style="max-height: calc(100vh - 110px);">

                <h6
                  class="fw-bold text-dark fs-8 text-uppercase tracking-wider mb-3 pb-2 border-bottom d-flex align-items-center justify-content-between">
                  <span class="d-flex align-items-center gap-1.5">
                    <i class="bi bi-sliders text-primary"></i>
                    <span>Generation Controls</span>
                  </span>
                  <span class="badge bg-light text-muted border fs-9">Interactive Canvas</span>
                </h6>

                <!-- 1. Uploaded Custom Templates Selector -->
                <div class="mb-3 pb-2.5 border-bottom">
                  <label
                    class="form-label fs-8 fw-bold text-dark mb-1.5 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-image text-warning me-1"></i> Uploaded Custom Design:</span>
                    <small class="text-muted fs-9">(JPG, PNG, SVG)</small>
                  </label>

                  <?php if (empty($templates_list)): ?>
                    <div class="alert alert-warning p-2 fs-9 mb-2">
                      <i class="bi bi-exclamation-circle me-1"></i> No custom design uploaded yet. Please upload a
                      certificate background below.
                    </div>
                  <?php else: ?>
                    <div class="row g-2 mb-2" id="gen-tpl-cards-container">
                      <?php foreach ($templates_list as $tpl): ?>
                        <div class="col-4">
                          <div class="template-thumb-card p-1 text-center" id="tpl-card-<?php echo $tpl['id']; ?>"
                            onclick="selectTemplateById(<?php echo $tpl['id']; ?>)">
                            <div class="bg-dark rounded-2 overflow-hidden mb-1" style="height: 52px;">
                              <img src="../<?php echo htmlspecialchars($tpl['background_image']); ?>"
                                style="width: 100%; height: 100%; object-fit: contain;" alt="Tpl">
                            </div>
                            <small class="d-block text-truncate fw-bold fs-9 text-dark" style="font-size: 0.65rem;"
                              title="<?php echo htmlspecialchars($tpl['name']); ?>"><?php echo htmlspecialchars($tpl['name']); ?></small>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- 2. Search Bar to Select Requested Students -->
                <div class="mb-3 pb-2.5 border-bottom position-relative">
                  <label
                    class="form-label fs-8 fw-bold text-dark mb-1 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-search text-primary me-1"></i> Search Requested Student:</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary fs-9"><?php echo count($all_requests); ?>
                      requested</span>
                  </label>

                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-person-search"></i></span>
                    <input type="text" id="gen-student-search-input" class="form-control bg-light fs-8"
                      placeholder="Type student name, NIC, course, ref code..."
                      oninput="onStudentSearchInput(this.value)" autocomplete="off">
                  </div>

                  <!-- Dropdown Search Results Box -->
                  <div id="gen-student-search-results" class="student-search-results" style="display: none;">
                    <!-- Dynamically populated by JS -->
                  </div>

                  <!-- Selected Student Active Chip -->
                  <div id="selected-student-chip"
                    class="p-2 bg-light rounded-3 border mt-2 d-flex align-items-center justify-content-between"
                    style="display: none;">
                    <div class="d-flex align-items-center gap-2 overflow-hidden">
                      <i class="bi bi-person-check-fill text-success fs-5"></i>
                      <div class="overflow-hidden">
                        <div class="fw-bold text-dark fs-8 text-truncate" id="chip-student-name">Student Name</div>
                        <div class="text-muted fs-9 text-truncate" id="chip-course-title">Course Title</div>
                      </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2 fs-9"
                      onclick="clearSelectedStudent()">Clear</button>
                  </div>
                </div>

                <!-- Form for Submitting / Issuing Certificate -->
                <form id="live-cert-form" action="certificates.php" method="POST">
                  <input type="hidden" name="action" value="generate_and_issue">
                  <input type="hidden" name="request_id" id="gen-request-id" value="">
                  <input type="hidden" name="user_id" id="gen-user-id" value="">
                  <input type="hidden" name="course_id" id="gen-course-id" value="">
                  <input type="hidden" name="template_id" id="gen-selected-template-id"
                    value="<?php echo !empty($templates_list) ? $templates_list[0]['id'] : '0'; ?>">

                  <!-- 3. Element Inspector & Customizer -->
                  <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <label class="form-label fs-8 fw-bold text-dark mb-0">
                        <i class="bi bi-cursor text-info me-1"></i> Element Customizer:
                      </label>
                      <small class="text-muted fs-9">Click element on canvas to customize</small>
                    </div>

                    <!-- Element Selector Tabs / Dropdown -->
                    <select id="element-picker-select"
                      class="form-select form-select-sm bg-light border fw-semibold mb-2"
                      onchange="selectElementById(this.value)">
                      <option value="el-student-name">Student Full Name</option>
                      <option value="el-course-title">Course Title</option>
                      <option value="el-completion-date">Date of Completion / Issue</option>
                      <option value="el-quiz-score">Academic Evaluation / Score</option>
                      <option value="el-cert-code">Credential Reference ID</option>
                      <option value="el-qr-code">Dynamic Verification QR Code</option>
                      <option value="el-sig1">Signatory 1 (Academic Director)</option>
                      <option value="el-sig2">Signatory 2 (Faculty Dean)</option>
                      <option value="el-subtitle">Subtitle / Custom Honors Note</option>
                    </select>

                    <!-- Active Element Properties Inspector Panel -->
                    <div class="p-3 bg-light rounded-3 border" id="element-inspector-panel">

                      <!-- Text content input -->
                      <div class="mb-2" id="inspector-text-container">
                        <label class="form-label fs-9 fw-bold text-dark mb-0.5" id="inspector-text-label">Text
                          Content:</label>
                        <input type="text" id="inspector-text-input" class="form-control form-control-sm bg-white"
                          oninput="updateActiveElementText(this.value)">
                      </div>

                      <!-- Font Family & Font Size -->
                      <div class="row g-2 mb-2" id="inspector-font-container">
                        <div class="col-7">
                          <label class="form-label fs-9 text-muted mb-0.5">Font Family:</label>
                          <select id="inspector-font-family" class="form-select form-select-sm bg-white"
                            onchange="updateActiveElementStyle('fontFamily', this.value)">
                            <option value="Cinzel">Cinzel (Royal Serif)</option>
                            <option value="Playfair Display">Playfair Display</option>
                            <option value="Great Vibes">Great Vibes (Script)</option>
                            <option value="Pinyon Script">Pinyon Script (Calligraphy)</option>
                            <option value="Inter">Inter (Clean Modern)</option>
                            <option value="Montserrat">Montserrat</option>
                            <option value="Cormorant Garamond">Cormorant Garamond</option>
                            <option value="Arial">Arial</option>
                            <option value="Georgia">Georgia</option>
                          </select>
                        </div>
                        <div class="col-5">
                          <label class="form-label fs-9 text-muted mb-0.5">Size: <span
                              id="font-size-val">28</span>px</label>
                          <input type="range" id="inspector-font-size" class="form-range" min="10" max="80" value="28"
                            oninput="updateActiveElementStyle('fontSize', this.value + 'px')">
                        </div>
                      </div>

                      <!-- Color & Weight & Align -->
                      <div class="row g-2 mb-2" id="inspector-style-container">
                        <div class="col-4">
                          <label class="form-label fs-9 text-muted mb-0.5">Color:</label>
                          <input type="color" id="inspector-text-color" class="form-control form-control-color w-100"
                            value="#0f4c81" onchange="updateActiveElementStyle('color', this.value)">
                        </div>
                        <div class="col-4">
                          <label class="form-label fs-9 text-muted mb-0.5">Weight:</label>
                          <select id="inspector-font-weight" class="form-select form-select-sm bg-white"
                            onchange="updateActiveElementStyle('fontWeight', this.value)">
                            <option value="400">Normal</option>
                            <option value="600">Semibold</option>
                            <option value="700" selected>Bold</option>
                            <option value="800">Extra Bold</option>
                          </select>
                        </div>
                        <div class="col-4">
                          <label class="form-label fs-9 text-muted mb-0.5">Align:</label>
                          <select id="inspector-text-align" class="form-select form-select-sm bg-white"
                            onchange="updateActiveElementStyle('textAlign', this.value)">
                            <option value="center" selected>Center</option>
                            <option value="left">Left</option>
                            <option value="right">Right</option>
                          </select>
                        </div>
                      </div>

                      <!-- Position & Controls -->
                      <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <div class="form-check form-switch mb-0">
                          <input class="form-check-input" type="checkbox" id="inspector-visibility-toggle" checked
                            onchange="toggleActiveElementVisibility(this.checked)">
                          <label class="form-check-label fs-9 fw-semibold"
                            for="inspector-visibility-toggle">Visible</label>
                        </div>

                        <div class="d-flex gap-1">
                          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0.5 fs-9"
                            onclick="centerActiveElementHorizontally()" title="Center horizontally on template">
                            <i class="bi bi-align-center"></i> Center
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-0.5 fs-9"
                            onclick="resetActiveElementPosition()" title="Reset to default position">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                          </button>
                        </div>
                      </div>

                    </div>
                  </div>

                  <!-- Hidden Inputs to store form data for submission -->
                  <input type="hidden" name="student_name" id="submit-student-name" value="">
                  <input type="hidden" name="course_title" id="submit-course-title" value="">
                  <input type="hidden" name="completion_date" id="submit-completion-date" value="">
                  <input type="hidden" name="quiz_summary" id="submit-quiz-summary" value="">
                  <input type="hidden" name="cert_code" id="submit-cert-code" value="">
                  <input type="hidden" name="certificate_image_data" id="submit-cert-image-data" value="">

                  <!-- Admin Notes -->
                  <div class="mb-3">
                    <label class="form-label fs-8 fw-semibold text-dark mb-1">Administrative Notes</label>
                    <textarea name="admin_notes" id="gen-admin-notes" class="form-control form-control-sm" rows="2"
                      placeholder="Verification note sent to student bell..."></textarea>
                  </div>

                  <!-- Submit Action Button -->
                  <div class="d-grid pt-1">
                    <button type="submit"
                      class="btn btn-success btn-sm rounded-pill py-2 fw-bold text-white shadow-sm d-flex align-items-center justify-content-center gap-1.5">
                      <i class="bi bi-save2-fill"></i>
                      <span id="gen-submit-btn-text">Save & Generate Certificate</span>
                    </button>
                  </div>

                </form>

              </div>
            </div>

            <!-- Right Column: Interactive Draggable Certificate Canvas (col-lg-7 col-xl-8) -->
            <div class="col-lg-7 col-xl-8">

              <!-- Canvas Viewport -->
              <div class="cert-preview-viewport h-100">

                <div id="printable-certificate-container">

                  <!-- Certificate Canvas with Custom Design Background -->
                  <div class="certificate-canvas" id="live-certificate-canvas">

                    <!-- 1. Recipient Full Name (Draggable Layer) -->
                    <div class="draggable-element selected-el" id="el-student-name" data-label="Student Name"
                      style="top: 40%; left: 50%; transform: translateX(-50%); font-family: 'Playfair Display', Georgia, serif; font-size: 38px; font-weight: 700; color: #0f4c81; text-align: center;">
                      <span id="text-student-name">Saduni Anupama Perera</span>
                    </div>

                    <!-- 2. Course Title (Draggable Layer) -->
                    <div class="draggable-element" id="el-course-title" data-label="Course Title"
                      style="top: 56%; left: 50%; transform: translateX(-50%); font-family: 'Cinzel', serif; font-size: 24px; font-weight: 700; color: #b8860b; text-align: center;">
                      <span id="text-course-title">Artificial Intelligence & Workflow Automation</span>
                    </div>

                    <!-- 3. Subtitle / Honors Text (Draggable Layer) -->
                    <div class="draggable-element" id="el-subtitle" data-label="Honors / Subtitle"
                      style="top: 49%; left: 50%; transform: translateX(-50%); font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 400; color: #475569; text-align: center;">
                      <span id="text-subtitle">For successfully demonstrating academic mastery, technical proficiency,
                        and completing all syllabus modules for:</span>
                    </div>

                    <!-- 4. Date of Completion (Draggable Layer) -->
                    <div class="draggable-element" id="el-completion-date" data-label="Date"
                      style="top: 64%; left: 50%; transform: translateX(-50%); font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; color: #334155; text-align: center;">
                      <span>Date of Issue: <strong id="text-completion-date">Aug 18, 2026</strong></span>
                    </div>

                    <!-- 5. Academic Evaluation / Score Summary (Draggable Layer) -->
                    <div class="draggable-element" id="el-quiz-score" data-label="Quiz Score"
                      style="top: 68%; left: 50%; transform: translateX(-50%); font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; color: #059669; text-align: center;">
                      <span id="text-quiz-score">Progress: 100% | Final Evaluation: Verified Distinction</span>
                    </div>

                    <!-- 6. QR Code & Credential ID (Draggable Layer) -->
                    <div class="draggable-element" id="el-qr-code" data-label="QR Code"
                      style="top: 76%; left: 8%; font-family: 'Inter', sans-serif; font-size: 11px; color: #1e293b;">
                      <div class="d-flex align-items-center gap-2">
                        <div class="p-1 bg-white border rounded" style="width: 52px; height: 52px;">
                          <img
                            src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=https://computerscience.lk/verify"
                            id="canvas-qr-img" style="width: 100%; height: 100%; object-fit: contain;" alt="QR">
                        </div>
                        <div>
                          <small class="d-block text-muted text-uppercase fw-bold" style="font-size: 9px;">Credential
                            ID</small>
                          <span class="font-mono fw-bold text-dark" style="font-size: 11px;"
                            id="text-cert-code">CERT-CSLK-938EF1B5</span>
                          <div class="text-muted" style="font-size: 9px;">Verify at computerscience.lk</div>
                        </div>
                      </div>
                    </div>

                    <!-- 7. Signatory 1 (Draggable Layer) -->
                    <div class="draggable-element" id="el-sig1" data-label="Signatory 1"
                      style="top: 77%; left: 74%; font-family: 'Inter', sans-serif; text-align: center;">
                      <div class="text-center" style="min-width: 150px;">
                        <div id="text-sig1-name"
                          style="font-family: 'Pinyon Script', cursive; font-size: 26px; color: #0f4c81; font-weight: 700;">
                          Academic Director</div>
                        <div class="border-top border-dark pt-0.5">
                          <small class="fw-bold text-dark text-uppercase d-block" style="font-size: 10px;"
                            id="text-sig1-title">Director of Academic Affairs</small>
                          <small class="text-muted" style="font-size: 9px;">Computerscience.lk Academy</small>
                        </div>
                      </div>
                    </div>

                    <!-- 8. Signatory 2 (Draggable Layer - Default Hidden or Customizable) -->
                    <div class="draggable-element" id="el-sig2" data-label="Signatory 2"
                      style="top: 77%; left: 45%; font-family: 'Inter', sans-serif; text-align: center; display: none;">
                      <div class="text-center" style="min-width: 140px;">
                        <div id="text-sig2-name"
                          style="font-family: 'Pinyon Script', cursive; font-size: 24px; color: #0f4c81; font-weight: 700;">
                          Senior Faculty Lead</div>
                        <div class="border-top border-dark pt-0.5">
                          <small class="fw-bold text-dark text-uppercase d-block" style="font-size: 10px;"
                            id="text-sig2-title">Dean of Computer Science</small>
                          <small class="text-muted" style="font-size: 9px;">Evaluation Board</small>
                        </div>
                      </div>
                    </div>

                  </div>

                </div>

              </div>

            </div>

          </div>
        </div>

      </div>
    </div>
  </main>
</div>

  <!-- Status Update Modal -->
  <div class="modal fade" id="statusUpdateModal" tabindex="-1" aria-labelledby="statusUpdateModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-bottom py-3 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="statusUpdateModalLabel">
            <i class="bi bi-pencil-square me-2"></i> Update Certificate Request Status
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form action="certificates.php" method="POST">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="request_id" id="modal-status-request-id">

          <div class="modal-body p-4 bg-light">
            <div class="mb-3">
              <label class="form-label fs-8 fw-semibold text-dark mb-1">Student & Course</label>
              <input type="text" id="modal-status-student-info" class="form-control form-control-sm bg-white border"
                readonly>
            </div>

            <div class="mb-3">
              <label class="form-label fs-8 fw-semibold text-dark mb-1">Select Status <span
                  class="text-danger">*</span></label>
              <select name="status" id="modal-status-select"
                class="form-select form-select-sm bg-white border fw-semibold" required>
                <option value="pending">Pending Review</option>
                <option value="approved">Approved</option>
                <option value="processing">Processing & Printing</option>
                <option value="dispatched">Dispatched (via Courier)</option>
                <option value="issued">Issued</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fs-8 fw-semibold text-dark mb-1">Admin / Courier Tracking Notes</label>
              <textarea name="admin_notes" id="modal-status-notes" class="form-control form-control-sm" rows="3"
                placeholder="e.g. Courier tracking #CR-99214 or verification note for student..."></textarea>
              <small class="text-muted fs-9"><i class="bi bi-info-circle me-1"></i>Notes will be included in the
                automatic notification sent to the student's notification bell.</small>
            </div>
          </div>

          <div class="modal-footer bg-white border-top py-2.5 px-4">
            <button type="button" class="btn btn-light btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold text-white"
              style="background-color: #0f4c81;">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Shipping Address Slip Print Modal -->
  <div class="modal fade" id="shippingSlipModal" tabindex="-1" aria-labelledby="shippingSlipModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-bottom py-3 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="shippingSlipModalLabel">
            <i class="bi bi-truck me-2"></i> Courier Delivery Shipping Slip
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4 bg-light text-start">
          <div id="printable-shipping-slip" class="p-4 bg-white border-2 border-dashed rounded-3 border-dark">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
              <h6 class="fw-bold mb-0 text-dark">DELIVERY PACKAGE: CERTIFICATE</h6>
              <span class="badge bg-dark font-mono fs-8" id="slip-cert-code">CERT-CSLK-00000000</span>
            </div>

            <div class="mb-3">
              <small class="text-muted fs-9 text-uppercase fw-bold">DELIVER TO (RECIPIENT):</small>
              <h5 class="fw-bold text-dark mb-1" id="slip-recipient-name">Recipient Name</h5>
              <div class="fs-8 text-dark mb-1" id="slip-address">No. 00, Street Name</div>
              <div class="fs-8 text-dark"><strong id="slip-city">City</strong>, <span id="slip-district">District</span>
                (<span id="slip-postal">00000</span>)</div>
            </div>

            <div class="mb-3 border-top pt-2">
              <small class="text-muted fs-9 text-uppercase fw-bold">CONTACT PHONE:</small>
              <div class="fw-bold text-dark fs-7" id="slip-mobile">+94 77 000 0000</div>
              <div class="text-muted fs-9">NIC: <span id="slip-nic">000000000000</span></div>
            </div>

            <div class="border-top pt-2">
              <small class="text-muted fs-9 text-uppercase fw-bold">COURSE & SPECIAL INSTRUCTIONS:</small>
              <div class="fs-8 text-dark" id="slip-course">Course Title</div>
              <div class="text-muted fs-9 italic" id="slip-notes">Special notes...</div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-white border-top py-2.5 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill"
            data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-success btn-sm px-4 rounded-pill fw-bold text-white"
            onclick="printShippingSlip()">
            <i class="bi bi-printer-fill me-1.5"></i> Print Shipping Slip
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- View Generated Certificate Modal -->
  <div class="modal fade" id="viewGeneratedCertificateModal" tabindex="-1" aria-labelledby="viewGeneratedCertificateModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
        
        <!-- Modal Header -->
        <div class="modal-header border-bottom py-3 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <div class="d-flex align-items-center gap-2.5">
            <div class="rounded-circle bg-warning p-1.5 text-dark d-flex align-items-center justify-content-center"
              style="width: 36px; height: 36px;">
              <i class="bi bi-award-fill fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="viewGeneratedCertificateModalLabel">Official Issued Certificate</h5>
              <small class="text-white text-opacity-75 fs-9" id="modal-view-student-subtitle">Verified Institutional Credential</small>
            </div>
          </div>
          
          <div class="d-flex align-items-center gap-2 ms-auto">
            <button type="button" class="btn btn-sm btn-warning text-dark rounded-pill px-3 fw-bold shadow-xs d-flex align-items-center gap-1" id="btn-modal-email-cert"
              onclick="emailCertificateFromModal()">
              <i class="bi bi-envelope-at-fill"></i> Email to Student
            </button>
            <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" id="btn-print-view-cert"
              onclick="printGeneratedCertificateFromModal()">
              <i class="bi bi-printer-fill me-1.5"></i> Print A4
            </button>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"
              aria-label="Close"></button>
          </div>
        </div>

        <!-- Modal Body -->
        <div class="modal-body p-4 bg-light text-center">
          
          <!-- State 1: Certificate Image Present -->
          <div id="view-cert-image-container" class="mb-4 text-center" style="display: none;">
            <div class="d-inline-block position-relative p-2 bg-white rounded-3 shadow border" style="max-width: 100%;">
              <img id="view-cert-img" src="" alt="Certificate" class="img-fluid rounded-2" style="max-height: 520px; width: auto; object-fit: contain; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            </div>
          </div>

          <!-- State 2: Certificate Image NOT yet generated -->
          <div id="view-cert-empty-container" class="py-5 text-center" style="display: none;">
            <div class="p-4 bg-white rounded-4 border shadow-xs max-w-md mx-auto" style="max-width: 500px;">
              <i class="bi bi-file-earmark-x text-warning fs-1 d-block mb-2"></i>
              <h6 class="fw-bold text-dark mb-1">Certificate Image Not Generated Yet</h6>
              <p class="text-muted fs-8 mb-3">This student application has not been generated and saved into the database yet. You can open the Design Studio right now to design, approve, and officially issue this certificate.</p>
              <button type="button" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" style="background-color: #0f4c81;" onclick="openStudioFromViewer()">
                <i class="bi bi-magic me-1.5 text-warning"></i> Open Studio & Generate Certificate
              </button>
            </div>
          </div>

          <!-- Certificate Metadata Card -->
          <div class="row g-3 text-start mt-1">
            <div class="col-md-7">
              <div class="p-3 bg-white rounded-3 border shadow-xs h-100">
                <h6 class="fw-bold text-dark fs-8 text-uppercase tracking-wider mb-2 pb-1 border-bottom d-flex align-items-center justify-content-between">
                  <span><i class="bi bi-person-badge text-primary me-1.5"></i>Student & Credential Details</span>
                  <span class="badge bg-success fs-9" id="modal-view-status-badge">ISSUED</span>
                </h6>
                <div class="row g-2 fs-8">
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Recipient Full Name</small>
                    <strong class="text-dark" id="modal-view-student-name">-</strong>
                  </div>
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Course Title</small>
                    <strong class="text-dark" id="modal-view-course-title">-</strong>
                  </div>
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Certificate Code</small>
                    <span class="badge bg-light text-primary border font-mono fs-8" id="modal-view-cert-code">-</span>
                  </div>
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Completion / Issue Date</small>
                    <span class="text-dark fw-semibold" id="modal-view-date">-</span>
                  </div>
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Student NIC</small>
                    <span class="text-dark" id="modal-view-nic">-</span>
                  </div>
                  <div class="col-sm-6">
                    <small class="text-muted fs-9 d-block">Evaluation Summary</small>
                    <span class="text-success fw-semibold" id="modal-view-score">-</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-5">
              <div class="p-3 bg-white rounded-3 border shadow-xs h-100 d-flex flex-column justify-content-between">
                <div>
                  <h6 class="fw-bold text-dark fs-8 text-uppercase tracking-wider mb-2 pb-1 border-bottom">
                    <i class="bi bi-truck text-primary me-1.5"></i>Delivery & Verification
                  </h6>
                  <div class="fs-8 mb-2">
                    <small class="text-muted fs-9 d-block">Obtaining Method</small>
                    <div id="modal-view-delivery-method">-</div>
                  </div>
                  <div class="fs-8 mb-2">
                    <small class="text-muted fs-9 d-block">Registered Email</small>
                    <div class="text-dark" id="modal-view-email">-</div>
                  </div>
                  <div class="fs-8" id="modal-view-notes-box">
                    <small class="text-muted fs-9 d-block">Admin Notes</small>
                    <div class="text-muted fs-9 italic" id="modal-view-notes">No notes provided.</div>
                  </div>
                </div>

                <div class="pt-3 border-top mt-3 text-end">
                  <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fs-9" onclick="openStudioFromViewer()">
                    <i class="bi bi-pencil-square me-1"></i> Edit in Design Studio
                  </button>
                </div>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer bg-white border-top py-2.5 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-warning btn-sm px-3 rounded-pill fw-semibold" onclick="emailCertificateFromModal()">
              <i class="bi bi-envelope-at-fill me-1"></i> Email to Student
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill" onclick="openStudioFromViewer()">
              <i class="bi bi-magic me-1 text-warning"></i> Open in Design Studio
            </button>
            <a href="#" target="_blank" id="btn-view-fullscreen" class="btn btn-primary btn-sm px-3 rounded-pill fw-semibold text-white" style="background-color: #0f4c81;">
              <i class="bi bi-box-arrow-up-right me-1"></i> Full Resolution
            </a>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Local Bootstrap JS -->
  <script src="../assets/js/bootstrap.bundle.min.js"></script>
  <!-- Local html2canvas for high quality certificate PNG generation -->
  <script src="../assets/js/html2canvas.min.js"></script>

  <!-- Script for Draggable Studio Engine, Real-time Student Search & Customizer -->
  <script>
    const ALL_REQUESTS = <?php echo json_encode($all_requests); ?>;
    const ALL_TEMPLATES = <?php echo json_encode($templates_list); ?>;
    const TEMPLATES_MAP = {};
    ALL_TEMPLATES.forEach(t => { TEMPLATES_MAP[t.id] = t; });

    let currentSelectedStudentData = null;
    let activeTemplateId = ALL_TEMPLATES.length > 0 ? ALL_TEMPLATES[0].id : null;
    let activeElementId = 'el-student-name';

    // Default positions for reset
    const DEFAULT_POSITIONS = {
      'el-student-name': { top: '40%', left: '50%', transform: 'translateX(-50%)' },
      'el-course-title': { top: '56%', left: '50%', transform: 'translateX(-50%)' },
      'el-subtitle': { top: '49%', left: '50%', transform: 'translateX(-50%)' },
      'el-completion-date': { top: '64%', left: '50%', transform: 'translateX(-50%)' },
      'el-quiz-score': { top: '68%', left: '50%', transform: 'translateX(-50%)' },
      'el-qr-code': { top: '76%', left: '8%', transform: 'none' },
      'el-sig1': { top: '77%', left: '74%', transform: 'none' },
      'el-sig2': { top: '77%', left: '45%', transform: 'none' }
    };

    // Filter Logic for Requests Table & Form Submit Interception
    document.addEventListener('DOMContentLoaded', function () {
      const searchInput = document.getElementById('cert-search-input');
      const statusFilter = document.getElementById('cert-status-filter');
      const deliveryFilter = document.getElementById('cert-delivery-filter');
      const courseFilter = document.getElementById('cert-course-filter');

      function filterTable() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const selStatus = (statusFilter ? statusFilter.value : '').trim().toLowerCase();
        const selDelivery = (deliveryFilter ? deliveryFilter.value : '').trim().toLowerCase();
        const selCourse = (courseFilter ? courseFilter.value : '').trim().toLowerCase();

        document.querySelectorAll('.cert-row').forEach(row => {
          const status = (row.getAttribute('data-status') || '').toLowerCase();
          const delivery = (row.getAttribute('data-delivery') || '').toLowerCase();
          const course = (row.getAttribute('data-course') || '').toLowerCase();
          const fullName = (row.getAttribute('data-fullname') || '').toLowerCase();
          const accountName = (row.getAttribute('data-account-name') || '').toLowerCase();
          const email = (row.getAttribute('data-email') || '').toLowerCase();
          const nic = (row.getAttribute('data-nic') || '').toLowerCase();
          const mobile = (row.getAttribute('data-mobile') || '').toLowerCase();
          const code = (row.getAttribute('data-code') || '').toLowerCase();

          const matchesStatus = !selStatus || status === selStatus;
          const matchesDelivery = !selDelivery || delivery === selDelivery;
          const matchesCourse = !selCourse || course === selCourse;
          const matchesSearch = !query || fullName.includes(query) || accountName.includes(query) || email.includes(query) || nic.includes(query) || mobile.includes(query) || code.includes(query) || course.includes(query);

          row.style.display = (matchesStatus && matchesDelivery && matchesCourse && matchesSearch) ? '' : 'none';
        });
      }

      if (searchInput) searchInput.addEventListener('input', filterTable);
      if (statusFilter) statusFilter.addEventListener('change', filterTable);
      if (deliveryFilter) deliveryFilter.addEventListener('change', filterTable);
      if (courseFilter) courseFilter.addEventListener('change', filterTable);

      // Form submission handler: Capture Canvas as Image before saving to Database
      const liveCertForm = document.getElementById('live-cert-form');
      if (liveCertForm) {
        liveCertForm.addEventListener('submit', function (e) {
          const imgDataInput = document.getElementById('submit-cert-image-data');
          if (imgDataInput && imgDataInput.value) {
            return true;
          }

          e.preventDefault();
          const submitBtn = liveCertForm.querySelector('button[type="submit"]');
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5"></span> Rendering & Saving Certificate Image...';
          }

          // Temporarily remove selection box on canvas
          document.querySelectorAll('.draggable-element').forEach(el => el.classList.remove('selected-el'));

          const certCanvas = document.getElementById('live-certificate-canvas');
          if (certCanvas && typeof html2canvas === 'function') {
            html2canvas(certCanvas, {
              scale: 2,
              useCORS: true,
              allowTaint: true,
              backgroundColor: '#ffffff'
            }).then(canvas => {
              const dataUrl = canvas.toDataURL('image/png', 0.95);
              if (imgDataInput) {
                imgDataInput.value = dataUrl;
              }
              liveCertForm.submit();
            }).catch(err => {
              console.error('html2canvas capture error:', err);
              liveCertForm.submit();
            });
          } else {
            liveCertForm.submit();
          }
        });
      }

      // Initialize draggable elements on the canvas
      initDraggableElements();
    });

    // Scroll helper
    function scrollToDesignStudio() {
      const el = document.getElementById('design-templates-section');
      if (el) {
        el.scrollIntoView({ behavior: 'smooth' });
      }
    }

    // Open Certificate Generator Studio
    function openCertificateGenerator(studentData) {
      if (studentData) {
        loadStudentIntoStudio(studentData);
      } else {
        // If no student specified, check if requests exist and load the first one or prompt
        if (ALL_REQUESTS.length > 0) {
          loadStudentIntoStudio(ALL_REQUESTS[0]);
        }
      }

      // Ensure template background is set
      if (activeTemplateId && TEMPLATES_MAP[activeTemplateId]) {
        selectTemplateById(activeTemplateId);
      } else if (ALL_TEMPLATES.length > 0) {
        selectTemplateById(ALL_TEMPLATES[0].id);
      }

      selectElementById('el-student-name');
      const modal = new bootstrap.Modal(document.getElementById('certificateGeneratorModal'));
      modal.show();
    }

    function openGeneratorWithTemplate(tplId) {
      activeTemplateId = tplId;
      openCertificateGenerator(currentSelectedStudentData || (ALL_REQUESTS.length > 0 ? ALL_REQUESTS[0] : null));
    }

    // Load Student into Generator
    function loadStudentIntoStudio(data) {
      if (!data) return;
      currentSelectedStudentData = data;

      // Reset image data input and submit button
      const imgDataInput = document.getElementById('submit-cert-image-data');
      if (imgDataInput) imgDataInput.value = '';
      const liveCertForm = document.getElementById('live-cert-form');
      if (liveCertForm) {
        const submitBtn = liveCertForm.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="bi bi-save2-fill"></i> <span id="gen-submit-btn-text">Save & Generate Certificate</span>';
        }
      }

      // Update Hidden Form Fields
      document.getElementById('gen-request-id').value = data.id || '';
      document.getElementById('gen-user-id').value = data.user_id || '';
      document.getElementById('gen-course-id').value = data.course_id || '';

      const sName = data.full_name_on_certificate || data.user_account_name || 'Student Name';
      const cTitle = data.course_title || 'Course Title';
      const cDate = data.completion_date ? new Date(data.completion_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'Aug 18, 2026';
      const qScore = data.quiz_score_summary || 'Progress: 100% | Verified';
      const cCode = data.certificate_code || generateRandomCode();

      // Update Canvas Elements Text
      document.getElementById('text-student-name').textContent = sName;
      document.getElementById('text-course-title').textContent = cTitle;
      document.getElementById('text-completion-date').textContent = cDate;
      document.getElementById('text-quiz-score').textContent = qScore;
      document.getElementById('text-cert-code').textContent = cCode;

      // Update QR Code
      const verifyUrl = encodeURIComponent('https://computerscience.lk/verify?code=' + cCode);
      document.getElementById('canvas-qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + verifyUrl;

      // Update Form Submit Fields
      document.getElementById('submit-student-name').value = sName;
      document.getElementById('submit-course-title').value = cTitle;
      document.getElementById('submit-completion-date').value = data.completion_date ? data.completion_date.substring(0, 10) : new Date().toISOString().substring(0, 10);
      document.getElementById('submit-quiz-summary').value = qScore;
      document.getElementById('submit-cert-code').value = cCode;

      // Show Selected Chip
      const chip = document.getElementById('selected-student-chip');
      chip.style.display = 'flex';
      document.getElementById('chip-student-name').textContent = sName;
      document.getElementById('chip-course-title').textContent = cTitle + ' (' + cCode + ')';

      // Hide Search Results Dropdown
      document.getElementById('gen-student-search-results').style.display = 'none';
      document.getElementById('gen-student-search-input').value = '';

      // Update active inspector if student name is selected
      if (activeElementId === 'el-student-name') {
        document.getElementById('inspector-text-input').value = sName;
      }
    }

    function clearSelectedStudent() {
      currentSelectedStudentData = null;
      document.getElementById('gen-request-id').value = '';
      document.getElementById('selected-student-chip').style.display = 'none';
    }

    // Student Search Bar Logic
    function onStudentSearchInput(query) {
      const resultsContainer = document.getElementById('gen-student-search-results');
      const q = query.toLowerCase().trim();

      if (!q) {
        resultsContainer.style.display = 'none';
        return;
      }

      const filtered = ALL_REQUESTS.filter(r => {
        return (r.full_name_on_certificate && r.full_name_on_certificate.toLowerCase().includes(q)) ||
          (r.user_account_name && r.user_account_name.toLowerCase().includes(q)) ||
          (r.course_title && r.course_title.toLowerCase().includes(q)) ||
          (r.certificate_code && r.certificate_code.toLowerCase().includes(q)) ||
          (r.nic_number && r.nic_number.toLowerCase().includes(q)) ||
          (r.registered_email && r.registered_email.toLowerCase().includes(q));
      });

      if (filtered.length === 0) {
        resultsContainer.innerHTML = '<div class="p-2.5 text-muted fs-9 text-center">No matching student certificate requests found.</div>';
        resultsContainer.style.display = 'block';
        return;
      }

      let html = '';
      filtered.slice(0, 8).forEach(r => {
        const isIssued = (r.status === 'issued' || r.status === 'approved');
        const badgeClass = isIssued ? 'bg-success' : 'bg-warning text-dark';
        html += `
          <div class="student-search-item d-flex align-items-center justify-content-between" onclick='loadStudentFromSearch(${JSON.stringify(r).replace(/'/g, "&#39;")})'>
            <div class="overflow-hidden me-2">
              <div class="fw-bold text-dark fs-8 text-truncate">${escapeHtml(r.full_name_on_certificate)}</div>
              <small class="text-muted fs-9 text-truncate d-block">${escapeHtml(r.course_title)}</small>
            </div>
            <div class="text-end">
              <span class="badge ${badgeClass} fs-9 text-uppercase">${escapeHtml(r.status)}</span>
              <small class="text-muted font-mono d-block fs-9">${escapeHtml(r.certificate_code)}</small>
            </div>
          </div>
        `;
      });

      resultsContainer.innerHTML = html;
      resultsContainer.style.display = 'block';
    }

    function loadStudentFromSearch(studentObj) {
      loadStudentIntoStudio(studentObj);
    }

    // Template Selection
    function selectTemplateById(tplId) {
      activeTemplateId = tplId;
      document.getElementById('gen-selected-template-id').value = tplId;

      // Update card visual state
      document.querySelectorAll('.template-thumb-card').forEach(c => c.classList.remove('active-tpl'));
      const activeCard = document.getElementById('tpl-card-' + tplId);
      if (activeCard) activeCard.classList.add('active-tpl');

      const tpl = TEMPLATES_MAP[tplId];
      const canvas = document.getElementById('live-certificate-canvas');
      if (tpl && tpl.background_image) {
        canvas.style.backgroundImage = `url('../${tpl.background_image}')`;
      }
    }

    // Element Selection & Inspector System
    function selectElementById(elId) {
      activeElementId = elId;
      document.getElementById('element-picker-select').value = elId;

      // Update selected bounding box on canvas
      document.querySelectorAll('.draggable-element').forEach(el => el.classList.remove('selected-el'));
      const activeEl = document.getElementById(elId);
      if (activeEl) {
        activeEl.classList.add('selected-el');

        // Populate Inspector Properties
        const textSpan = activeEl.querySelector('span:not(.text-muted)');
        const textVal = textSpan ? textSpan.textContent.trim() : activeEl.textContent.trim();
        document.getElementById('inspector-text-input').value = textVal;

        // Font Family
        const curFont = activeEl.style.fontFamily.replace(/['",]/g, '').split(' ')[0] || 'Cinzel';
        const fontSelect = document.getElementById('inspector-font-family');
        for (let i = 0; i < fontSelect.options.length; i++) {
          if (fontSelect.options[i].value.toLowerCase() === curFont.toLowerCase()) {
            fontSelect.selectedIndex = i;
            break;
          }
        }

        // Font Size
        const curSize = parseInt(activeEl.style.fontSize) || 24;
        document.getElementById('inspector-font-size').value = curSize;
        document.getElementById('font-size-val').textContent = curSize;

        // Color
        const curColor = rgbToHex(activeEl.style.color) || '#0f4c81';
        document.getElementById('inspector-text-color').value = curColor;

        // Weight
        const curWeight = activeEl.style.fontWeight || '700';
        document.getElementById('inspector-font-weight').value = curWeight;

        // Alignment
        const curAlign = activeEl.style.textAlign || 'center';
        document.getElementById('inspector-text-align').value = curAlign;

        // Visibility
        document.getElementById('inspector-visibility-toggle').checked = (activeEl.style.display !== 'none');
      }
    }

    function updateActiveElementText(newText) {
      const activeEl = document.getElementById(activeElementId);
      if (!activeEl) return;

      if (activeElementId === 'el-student-name') {
        document.getElementById('text-student-name').textContent = newText;
        document.getElementById('submit-student-name').value = newText;
      } else if (activeElementId === 'el-course-title') {
        document.getElementById('text-course-title').textContent = newText;
        document.getElementById('submit-course-title').value = newText;
      } else if (activeElementId === 'el-completion-date') {
        document.getElementById('text-completion-date').textContent = newText;
      } else if (activeElementId === 'el-quiz-score') {
        document.getElementById('text-quiz-score').textContent = newText;
        document.getElementById('submit-quiz-summary').value = newText;
      } else if (activeElementId === 'el-cert-code') {
        document.getElementById('text-cert-code').textContent = newText;
        document.getElementById('submit-cert-code').value = newText;
      } else if (activeElementId === 'el-subtitle') {
        document.getElementById('text-subtitle').textContent = newText;
      } else if (activeElementId === 'el-sig1') {
        document.getElementById('text-sig1-name').textContent = newText;
      } else if (activeElementId === 'el-sig2') {
        document.getElementById('text-sig2-name').textContent = newText;
      } else {
        const textSpan = activeEl.querySelector('span') || activeEl;
        textSpan.textContent = newText;
      }
    }

    function updateActiveElementStyle(prop, value) {
      const activeEl = document.getElementById(activeElementId);
      if (!activeEl) return;

      if (prop === 'fontSize') {
        document.getElementById('font-size-val').textContent = parseInt(value);
      }

      activeEl.style[prop] = value;
    }

    function toggleActiveElementVisibility(isVisible) {
      const activeEl = document.getElementById(activeElementId);
      if (activeEl) {
        activeEl.style.display = isVisible ? '' : 'none';
      }
    }

    function centerActiveElementHorizontally() {
      const activeEl = document.getElementById(activeElementId);
      if (activeEl) {
        activeEl.style.left = '50%';
        activeEl.style.transform = 'translateX(-50%)';
      }
    }

    function resetActiveElementPosition() {
      const activeEl = document.getElementById(activeElementId);
      const def = DEFAULT_POSITIONS[activeElementId];
      if (activeEl && def) {
        activeEl.style.top = def.top;
        activeEl.style.left = def.left;
        activeEl.style.transform = def.transform;
      }
    }

    // Draggable Elements Interaction Engine
    function initDraggableElements() {
      const canvas = document.getElementById('live-certificate-canvas');
      const draggables = document.querySelectorAll('.draggable-element');

      draggables.forEach(el => {
        let isDragging = false;
        let startX, startY;
        let initialLeftPct, initialTopPct;

        el.addEventListener('mousedown', function (e) {
          selectElementById(el.id);
          isDragging = true;
          startX = e.clientX;
          startY = e.clientY;

          const canvasRect = canvas.getBoundingClientRect();
          const elRect = el.getBoundingClientRect();

          // Calculate current percentage offsets
          const currentLeftPx = elRect.left - canvasRect.left;
          const currentTopPx = elRect.top - canvasRect.top;

          initialLeftPct = (currentLeftPx / canvasRect.width) * 100;
          initialTopPct = (currentTopPx / canvasRect.height) * 100;

          // Remove center transform during drag for absolute coordinate mapping
          el.style.transform = 'none';
          el.style.left = initialLeftPct + '%';
          el.style.top = initialTopPct + '%';

          e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
          if (!isDragging || el.id !== activeElementId) return;

          const canvasRect = canvas.getBoundingClientRect();
          const deltaX = e.clientX - startX;
          const deltaY = e.clientY - startY;

          const deltaXPct = (deltaX / canvasRect.width) * 100;
          const deltaYPct = (deltaY / canvasRect.height) * 100;

          let newLeftPct = initialLeftPct + deltaXPct;
          let newTopPct = initialTopPct + deltaYPct;

          // Constrain within canvas bounds
          newLeftPct = Math.max(0, Math.min(95, newLeftPct));
          newTopPct = Math.max(0, Math.min(95, newTopPct));

          el.style.left = newLeftPct.toFixed(2) + '%';
          el.style.top = newTopPct.toFixed(2) + '%';
        });

        document.addEventListener('mouseup', function () {
          if (isDragging) {
            isDragging = false;
          }
        });
      });

      // Keyboard nudge navigation
      document.addEventListener('keydown', function (e) {
        if (!activeElementId || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
        const activeEl = document.getElementById(activeElementId);
        if (!activeEl) return;

        const step = e.shiftKey ? 2 : 0.5;
        let left = parseFloat(activeEl.style.left) || 50;
        let top = parseFloat(activeEl.style.top) || 50;

        if (e.key === 'ArrowLeft') {
          activeEl.style.transform = 'none';
          activeEl.style.left = (left - step) + '%';
          e.preventDefault();
        } else if (e.key === 'ArrowRight') {
          activeEl.style.transform = 'none';
          activeEl.style.left = (left + step) + '%';
          e.preventDefault();
        } else if (e.key === 'ArrowUp') {
          activeEl.style.top = (top - step) + '%';
          e.preventDefault();
        } else if (e.key === 'ArrowDown') {
          activeEl.style.top = (top + step) + '%';
          e.preventDefault();
        }
      });
    }

    // Print A4 Landscape directly
    function printOfficialCertificate() {
      window.print();
    }

    // Download High-Resolution PNG via html2canvas
    function downloadCertificatePNG() {
      const certNode = document.getElementById('live-certificate-canvas');
      if (!certNode) return;

      const studentName = (document.getElementById('text-student-name').textContent.trim() || 'Certificate').replace(/[^a-z0-9]/gi, '_');
      const certCode = (document.getElementById('text-cert-code').textContent.trim() || 'CERT').replace(/[^a-z0-9]/gi, '_');

      // Temporarily remove selection box for export
      document.querySelectorAll('.draggable-element').forEach(el => el.classList.remove('selected-el'));

      if (typeof html2canvas === 'function') {
        html2canvas(certNode, {
          scale: 2, // 2x high resolution
          useCORS: true,
          allowTaint: true,
          backgroundColor: '#ffffff'
        }).then(canvas => {
          const link = document.createElement('a');
          link.download = `Certificate_${studentName}_${certCode}.png`;
          link.href = canvas.toDataURL('image/png');
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);

          // Restore selection
          selectElementById(activeElementId);
        }).catch(err => {
          alert('Rendering complete. Please use Print to export as PDF/Image if download was blocked.');
          selectElementById(activeElementId);
        });
      } else {
        window.print();
      }
    }

    // View Generated Certificate Modal System
    let currentViewerStudentData = null;

    function openCertificateViewerModal(data) {
      if (!data) return;
      currentViewerStudentData = data;

      const sName = data.full_name_on_certificate || data.user_account_name || 'Student';
      const cTitle = data.course_title || 'Course';
      const cCode = data.certificate_code || 'N/A';
      const cDate = data.completion_date ? new Date(data.completion_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
      const qScore = data.quiz_score_summary || 'Progress: 100% | Verified';
      const certImg = data.certificate_image ? ('../' + data.certificate_image.replace(/^\/+/, '')) : null;

      document.getElementById('modal-view-student-subtitle').textContent = sName + ' — ' + cTitle;
      document.getElementById('modal-view-student-name').textContent = sName;
      document.getElementById('modal-view-course-title').textContent = cTitle;
      document.getElementById('modal-view-cert-code').textContent = cCode;
      document.getElementById('modal-view-date').textContent = cDate;
      document.getElementById('modal-view-nic').textContent = data.nic_number || 'N/A';
      document.getElementById('modal-view-score').textContent = qScore;
      document.getElementById('modal-view-email').textContent = data.registered_email || 'N/A';
      document.getElementById('modal-view-notes').textContent = data.admin_notes || 'No administrative notes recorded.';

      const statusBadge = document.getElementById('modal-view-status-badge');
      const status = (data.status || 'pending').toLowerCase();
      statusBadge.textContent = status.toUpperCase();
      if (status === 'issued' || status === 'approved') {
        statusBadge.className = 'badge bg-success fs-9';
      } else if (status === 'pending') {
        statusBadge.className = 'badge bg-warning text-dark fs-9';
      } else {
        statusBadge.className = 'badge bg-info text-white fs-9';
      }

      const deliveryDiv = document.getElementById('modal-view-delivery-method');
      if (data.delivery_method === 'home_delivery') {
        deliveryDiv.innerHTML = `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-bold"><i class="bi bi-truck me-1"></i>Home Delivery</span> <div class="text-dark fs-9 mt-1">${escapeHtml(data.delivery_address || '')}, ${escapeHtml(data.city || '')} (${escapeHtml(data.postal_code || '')})</div>`;
      } else {
        deliveryDiv.innerHTML = `<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-bold"><i class="bi bi-file-earmark-pdf me-1"></i>Digital Copy Only (PDF/Image)</span>`;
      }

      const imgContainer = document.getElementById('view-cert-image-container');
      const emptyContainer = document.getElementById('view-cert-empty-container');
      const viewImg = document.getElementById('view-cert-img');
      const printBtn = document.getElementById('btn-print-view-cert');
      const fullscreenBtn = document.getElementById('btn-view-fullscreen');

      if (certImg) {
        viewImg.src = certImg;
        imgContainer.style.display = 'block';
        emptyContainer.style.display = 'none';
        printBtn.style.display = 'inline-flex';
        fullscreenBtn.style.display = 'inline-flex';
        fullscreenBtn.href = certImg;
      } else {
        viewImg.src = '';
        imgContainer.style.display = 'none';
        emptyContainer.style.display = 'block';
        printBtn.style.display = 'none';
        fullscreenBtn.style.display = 'none';
      }

      const modal = new bootstrap.Modal(document.getElementById('viewGeneratedCertificateModal'));
      modal.show();
    }

    function openStudioFromViewer() {
      const modalEl = document.getElementById('viewGeneratedCertificateModal');
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();
      if (currentViewerStudentData) {
        openCertificateGenerator(currentViewerStudentData);
      } else {
        openCertificateGenerator(null);
      }
    }

    function printGeneratedCertificateFromModal() {
      if (!currentViewerStudentData) return;
      const certImg = currentViewerStudentData.certificate_image ? ('../' + currentViewerStudentData.certificate_image.replace(/^\/+/, '')) : null;
      if (!certImg) {
        window.print();
        return;
      }
      const win = window.open('', '', 'height=750,width=1050');
      win.document.write('<!DOCTYPE html><html><head><title>Print Certificate</title>');
      win.document.write('<style>@page { size: A4 landscape; margin: 0; } body { margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; height: 100vh; background: #fff; } img { width: 100vw; height: 100vh; object-fit: contain; }</style>');
      win.document.write('</head><body onload="window.print();window.close();">');
      win.document.write('<img src="' + certImg + '" alt="Certificate">');
      win.document.write('</body></html>');
      win.document.close();
    }

    function emailCertificateFromModal() {
      if (!currentViewerStudentData || !currentViewerStudentData.id) return;
      const btn = document.getElementById('btn-modal-email-cert');
      sendCertificateEmail(btn, currentViewerStudentData.id);
    }

    function sendCertificateEmail(btn, requestId) {
      if (!requestId || (btn && btn.disabled)) return;

      const allMatchingBtns = document.querySelectorAll('.email-btn-' + requestId);
      const modalBtn = document.getElementById('btn-modal-email-cert');
      const origHtmlMap = new Map();

      const buttonsToUpdate = [];
      if (btn) buttonsToUpdate.push(btn);
      allMatchingBtns.forEach(b => { if (!buttonsToUpdate.includes(b)) buttonsToUpdate.push(b); });
      if (modalBtn && !buttonsToUpdate.includes(modalBtn)) buttonsToUpdate.push(modalBtn);

      buttonsToUpdate.forEach(b => {
        origHtmlMap.set(b, b.innerHTML);
        b.disabled = true;
        b.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Sending...';
      });

      fetch('../api/send_certificate_mail.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ request_id: requestId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          // Update Email Sent Badge Container on table
          const badgeContainer = document.getElementById('email-sent-badge-container-' + requestId);
          if (badgeContainer) {
            badgeContainer.innerHTML = `
              <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-2 py-0.5 rounded-pill fs-9 fw-semibold d-inline-flex align-items-center gap-1" title="Emailed on ${escapeHtml(data.email_sent_at)}">
                <i class="bi bi-envelope-check-fill"></i>
                <span>Email Sent</span>
                <span class="text-muted fw-normal" style="font-size: 0.65rem;">(${escapeHtml(data.email_sent_at_formatted || 'Just now')})</span>
              </span>
            `;
          }

          buttonsToUpdate.forEach(b => {
            b.disabled = false;
            b.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Sent!';
            b.classList.remove('btn-outline-warning');
            b.classList.add('btn-success', 'text-white');
            setTimeout(() => {
              b.innerHTML = '<i class="bi bi-envelope-at-fill me-1"></i> Resend';
              b.classList.remove('btn-success', 'text-white');
              b.classList.add('btn-outline-warning');
            }, 4000);
          });

          showToastAlert(data.message || `Certificate successfully emailed to ${data.recipient}!`, 'success');
        } else {
          buttonsToUpdate.forEach(b => {
            b.disabled = false;
            b.innerHTML = origHtmlMap.get(b) || '<i class="bi bi-envelope-at-fill"></i> Email';
          });
          showToastAlert(data.message || 'Failed to dispatch certificate email.', 'danger');
        }
      })
      .catch(err => {
        console.error('Error sending certificate email:', err);
        buttonsToUpdate.forEach(b => {
          b.disabled = false;
          b.innerHTML = origHtmlMap.get(b) || '<i class="bi bi-envelope-at-fill"></i> Email';
        });
        showToastAlert('Network error occurred while emailing certificate. Please try again.', 'danger');
      });
    }

    function showToastAlert(msg, type) {
      const alertDiv = document.createElement('div');
      alertDiv.className = `alert alert-${type || 'info'} alert-dismissible fade show position-fixed shadow-lg d-flex align-items-center gap-2`;
      alertDiv.style.cssText = 'top: 25px; right: 25px; z-index: 99999; max-width: 440px; border-radius: 12px; font-size: 0.9rem;';
      alertDiv.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle-fill fs-5 text-success' : 'exclamation-triangle-fill fs-5 text-danger'}"></i>
        <div class="flex-grow-1">${escapeHtml(msg)}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      `;
      document.body.appendChild(alertDiv);
      setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 400);
      }, 5000);
    }

    // Status Modal
    function openStatusModal(data) {
      if (!data) return;
      document.getElementById('modal-status-request-id').value = data.id;
      document.getElementById('modal-status-student-info').value = data.full_name_on_certificate + ' - ' + data.course_title;
      document.getElementById('modal-status-select').value = data.status || 'pending';
      document.getElementById('modal-status-notes').value = data.admin_notes || '';

      const modal = new bootstrap.Modal(document.getElementById('statusUpdateModal'));
      modal.show();
    }

    // Shipping Slip Modal
    function openShippingSlipModal(data) {
      if (!data) return;
      document.getElementById('slip-cert-code').textContent = data.certificate_code;
      document.getElementById('slip-recipient-name').textContent = data.full_name_on_certificate;
      document.getElementById('slip-address').textContent = data.delivery_address || 'N/A';
      document.getElementById('slip-city').textContent = data.city || '';
      document.getElementById('slip-district').textContent = data.district || '';
      document.getElementById('slip-postal').textContent = data.postal_code || '';
      document.getElementById('slip-mobile').textContent = data.mobile_number;
      document.getElementById('slip-nic').textContent = data.nic_number;
      document.getElementById('slip-course').textContent = data.course_title;
      document.getElementById('slip-notes').textContent = data.delivery_notes ? 'Notes: ' + data.delivery_notes : 'No special notes.';

      const modal = new bootstrap.Modal(document.getElementById('shippingSlipModal'));
      modal.show();
    }

    function printShippingSlip() {
      const slipContent = document.getElementById('printable-shipping-slip').innerHTML;
      const win = window.open('', '', 'height=600,width=800');
      win.document.write('<html><head><title>Courier Shipping Label</title>');
      win.document.write('<link href="../assets/css/bootstrap.min.css" rel="stylesheet">');
      win.document.write('</head><body style="padding: 30px;" onload="window.print();window.close();">');
      win.document.write(slipContent);
      win.document.write('</body></html>');
      win.document.close();
    }

    // Main Upload Image Dropzone Handler
    function handleMainImageSelected(input) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function (e) {
          document.getElementById('main-dropzone-prompt').style.display = 'none';
          const previewDiv = document.getElementById('main-dropzone-preview');
          previewDiv.style.display = 'block';
          document.getElementById('main-preview-upload-img').src = e.target.result;
          document.getElementById('main-upload-filename-badge').textContent = file.name;
        };
        reader.readAsDataURL(file);
      }
    }

    function clearMainUploadImage(e) {
      if (e) e.stopPropagation();
      document.getElementById('main-tpl-image-input').value = '';
      document.getElementById('main-dropzone-prompt').style.display = 'block';
      document.getElementById('main-dropzone-preview').style.display = 'none';
    }

    // Export Table to CSV
    function exportCertificatesCSV() {
      if (!ALL_REQUESTS || ALL_REQUESTS.length === 0) {
        alert('No certificate records available to export.');
        return;
      }

      const rows = [
        ['Request ID', 'Certificate Code', 'Student Full Name', 'NIC Number', 'Mobile Number', 'Registered Email', 'Course Title', 'Completion Date', 'Course Progress', 'Quiz Marks Summary', 'Delivery Method', 'Delivery Address', 'City', 'Postal Code', 'District', 'Delivery Notes', 'Status', 'Admin Notes', 'Submitted At']
      ];

      ALL_REQUESTS.forEach(r => {
        rows.push([
          r.id,
          r.certificate_code,
          r.full_name_on_certificate,
          r.nic_number,
          r.mobile_number,
          r.registered_email,
          r.course_title,
          r.completion_date,
          r.course_progress,
          r.quiz_score_summary,
          r.delivery_method,
          r.delivery_address || '',
          r.city || '',
          r.postal_code || '',
          r.district || '',
          r.delivery_notes || '',
          r.status,
          r.admin_notes || '',
          r.created_at
        ]);
      });

      let csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + rows.map(e => e.map(item => `"${String(item).replace(/"/g, '""')}"`).join(',')).join('\n');
      const encodedUri = encodeURI(csvContent);
      const link = document.createElement('a');
      link.setAttribute('href', encodedUri);
      link.setAttribute('download', `Certificates_Export_${new Date().toISOString().slice(0, 10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }

    // Utilities
    function generateRandomCode() {
      const chars = '0123456789ABCDEF';
      let hex = '';
      for (let i = 0; i < 8; i++) {
        hex += chars[Math.floor(Math.random() * chars.length)];
      }
      return 'CERT-CSLK-' + hex;
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text || '';
      return div.innerHTML;
    }

    function rgbToHex(rgb) {
      if (!rgb || !rgb.startsWith('rgb')) return rgb;
      const parts = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
      if (!parts) return '#0f4c81';
      delete parts[0];
      for (let i = 1; i <= 3; ++i) {
        parts[i] = parseInt(parts[i]).toString(16);
        if (parts[i].length == 1) parts[i] = '0' + parts[i];
      }
      return '#' + parts.join('');
    }
  </script>
</body>

</html>