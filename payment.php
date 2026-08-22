<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$course_id = $_GET['course_id'] ?? '';

if (empty($course_id)) {
    header("Location: dashboard.php");
    exit;
}

$error_msg = '';
$success_msg = '';

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();

    if (!$student) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    // Redirect admins & super_admins directly to classroom in Instructor Review Mode (No payment required)
    if (in_array($student['role'] ?? '', ['admin', 'super_admin'])) {
        header("Location: classroom.php?course_id=" . urlencode($course_id) . "&admin_preview=1");
        exit;
    }

    // Fetch course details
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        die("Course not found.");
    }

    if ($course['status'] === 'disabled' || !empty($course['is_archived']) || !empty($course['deleted_at'])) {
        die("This course is currently unpublished and not accepting new enrollments. Go back to <a href='index.php'>Course Catalog</a>.");
    }

    // Check if student is already enrolled
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?");
    $stmt->execute([$user_id, $course_id]);
    $is_already_enrolled = ($stmt->fetchColumn() > 0);

    if ($is_already_enrolled) {
        header("Location: classroom.php?course_id=" . urlencode($course_id));
        exit;
    }

    // Check for existing bank slip submissions
    $stmt = $pdo->prepare("SELECT * FROM bank_payments WHERE user_id = ? AND course_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id, $course_id]);
    $pending_payment = $stmt->fetch();

    // Handle Bank Slip Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_slip'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        
        if (empty($full_name)) {
            $error_msg = "Please enter your full name.";
        } elseif (!isset($_FILES['slip_file']) || $_FILES['slip_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = "Please upload a valid bank receipt/slip file.";
        } else {
            $file = $_FILES['slip_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!in_array($ext, $allowed)) {
                $error_msg = "Only JPG, JPEG, PNG, and PDF files are allowed.";
            } else {
                $upload_dir = __DIR__ . '/uploads/slips';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'slip_' . $user_id . '_' . time() . '.' . $ext;
                $dest_path = $upload_dir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                    $slip_path = 'uploads/slips/' . $filename;
                    
                    // Insert pending payment record
                    $insertStmt = $pdo->prepare("INSERT INTO bank_payments (user_id, course_id, full_name, slip_path, status) VALUES (?, ?, ?, ?, 'pending')");
                    $insertStmt->execute([$user_id, $course_id, $full_name, $slip_path]);
                    
                    // Insert student notification
                    $notifyStmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $notifyStmt->execute([$user_id, "Your bank slip payment of Rs. " . number_format($course['price'], 2) . " for '" . $course['title'] . "' has been submitted successfully and is pending review."]);

                    header("Location: payment.php?course_id=" . urlencode($course_id) . "&success=1");
                    exit;
                } else {
                    $error_msg = "Failed to save uploaded file. Please try again.";
                }
            }
        }
    }

    // Fetch notifications
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();

    // Fetch active bank account details
    $stmt = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'active' ORDER BY id ASC");
    $bank_accounts = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

if (isset($_GET['success'])) {
    $success_msg = "Your bank slip has been successfully uploaded and is currently pending review.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Secured Checkout | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Modern Notification System Styles -->
  <link rel="stylesheet" href="assets/css/notifications.css">
  <!-- Local Tailwind CSS -->
  <script src="assets/js/tailwind.js"></script>
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
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .no-caret::after {
      display: none !important;
    }
  </style>
</head>
<body class="bg-light">

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <main class="container" style="margin-top: 100px;">
    <div class="row g-4 justify-content-center">
      
      <!-- Right Side: Order Summary & Owner Bank Details -->
      <div class="col-lg-5 order-lg-2">
        <!-- Order Summary -->
        <div class="moodle-card p-4 bg-white mb-4">
          <h5 class="fw-bold text-dark border-bottom pb-2 mb-3 fs-6"><?php echo __('order_summary', 'Order Summary'); ?></h5>
          <div class="d-flex align-items-start gap-3 mb-3">
            <img src="<?php echo htmlspecialchars($course['thumbnail']); ?>" class="rounded border" alt="Thumbnail" style="width: 80px; height: 50px; object-fit: cover;">
            <div>
              <span class="badge bg-light text-primary border mb-1 fs-9"><?php echo htmlspecialchars($course['category']); ?></span>
              <h6 class="fw-bold text-dark mb-0 fs-7 line-clamp-2"><?php echo htmlspecialchars(__($course['title'], $course['title'])); ?></h6>
              <small class="text-muted fs-8">Lecturer: <?php echo htmlspecialchars($course['tutor_name']); ?></small>
            </div>
          </div>
          
          <hr class="my-3">
          
          <div class="d-flex justify-content-between mb-2 fs-8 text-secondary">
            <span><?php echo __('course_price', 'Course Price'); ?></span>
            <span>Rs. <?php echo number_format($course['price'], 2); ?></span>
          </div>
          <div class="d-flex justify-content-between mb-2 fs-8 text-secondary">
            <span><?php echo __('processing_fee', 'Processing Fee'); ?></span>
            <span class="text-success"><?php echo __('free', 'FREE'); ?></span>
          </div>
          
          <hr class="my-3">
          
          <div class="d-flex justify-content-between align-items-center mb-0">
            <span class="fw-bold text-dark"><?php echo __('total_payment', 'Total Payment'); ?></span>
            <span class="fw-bold text-primary fs-5" style="color: #0f4c81;">Rs. <?php echo number_format($course['price'], 2); ?></span>
          </div>
        </div>

        <!-- Bank Details Section -->
        <div class="moodle-card p-4 bg-white mb-4">
          <h5 class="fw-bold text-dark border-bottom pb-2 mb-3 fs-6">
            <i class="bi bi-bank2 text-primary me-2"></i><?php echo __('owners_bank_details', "Owner's Bank Account Details"); ?>
          </h5>
          <p class="text-muted fs-8 mb-3"><?php echo __('bank_details_desc', 'Deposit the total payment to one of the bank accounts below to complete your transfer.'); ?></p>
          
          <?php if (empty($bank_accounts)): ?>
            <div class="alert alert-info fs-8 py-2 px-3 mb-0"><i class="bi bi-info-circle me-1"></i> No bank account details available at the moment.</div>
          <?php else: ?>
            <?php foreach ($bank_accounts as $index => $acc): ?>
              <div class="border rounded p-3 mb-3 bg-light bg-opacity-50">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="fw-bold fs-7 text-dark"><?php echo htmlspecialchars($acc['bank_name']); ?></span>
                  <?php if (!empty($acc['option_label'])): ?>
                    <span class="badge <?php echo $index === 0 ? 'bg-primary bg-opacity-10 text-primary' : 'bg-secondary bg-opacity-10 text-secondary'; ?> fs-9">
                      <?php echo htmlspecialchars($acc['option_label']); ?>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="fs-8 text-secondary">
                  <div class="mb-1"><strong><?php echo __('branch', 'Branch'); ?>:</strong> <?php echo htmlspecialchars($acc['branch']); ?></div>
                  <div class="mb-1 d-flex align-items-center justify-content-between">
                    <span><strong><?php echo __('account_no', 'Account No'); ?>:</strong> <code class="text-dark fw-bold" id="acc-num-<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['account_number']); ?></code></span>
                    <button type="button" id="copy-btn-<?php echo $acc['id']; ?>" class="btn btn-sm btn-outline-secondary py-0.5 px-2 fs-9 border-0 bg-transparent" onclick="copyToClipboard('<?php echo htmlspecialchars($acc['account_number'], ENT_QUOTES); ?>', 'copy-btn-<?php echo $acc['id']; ?>')">
                      <i class="bi bi-clipboard"></i> <?php echo __('copy', 'Copy'); ?>
                    </button>
                  </div>
                  <div><strong><?php echo __('account_name', 'Account Name'); ?>:</strong> <?php echo htmlspecialchars($acc['account_name']); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Left Side: Bank Slip Upload / Upload Status -->
      <div class="col-lg-7 order-lg-1">
        
        <?php if (!empty($error_msg)): ?>
          <div class="alert alert-danger shadow-sm fs-8 py-2 px-3 mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
          <div class="alert alert-success shadow-sm fs-8 py-2 px-3 mb-4"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>

        <?php if ($pending_payment && $pending_payment['status'] === 'pending'): ?>
          <!-- Pending Review State -->
          <div class="moodle-card p-5 bg-white text-center">
            <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle p-4 mb-4 border border-warning border-opacity-25" style="width: 80px; height: 80px;">
              <i class="bi bi-clock-history" style="font-size: 3rem;"></i>
            </div>
            <h3 class="fw-bold text-warning mb-2"><?php echo __('payment_verification_pending', 'Payment Verification Pending'); ?></h3>
            <p class="text-muted fs-7 mb-4"><?php echo __('payment_verification_desc', 'Your deposit slip is currently pending administrator verification. We usually approve submissions within 2-4 hours.'); ?></p>
            
            <div class="border rounded p-3 mb-4 bg-light text-start mx-auto" style="max-width: 400px;">
              <div class="fs-8 text-secondary mb-1"><strong><?php echo __('submitted_on', 'Submitted On'); ?>:</strong> <?php echo htmlspecialchars($pending_payment['created_at']); ?></div>
              <div class="fs-8 text-secondary mb-1"><strong><?php echo __('status_label', 'Status'); ?>:</strong> <span class="badge bg-warning text-dark fs-9"><?php echo __('pending', 'Pending Review'); ?></span></div>
              <div class="fs-8 text-secondary"><strong><?php echo __('receipt_slip', 'Receipt Slip'); ?>:</strong> <a href="<?php echo htmlspecialchars($pending_payment['slip_path']); ?>" target="_blank" class="text-primary text-decoration-none fw-semibold"><i class="bi bi-file-earmark-image"></i> <?php echo __('view_uploaded_file', 'View Uploaded File'); ?></a></div>
            </div>
            
            <a href="dashboard.php" class="btn btn-primary px-4 py-2 border-0 fw-bold shadow-sm" style="background-color: #0f4c81;"><?php echo __('go_to_dashboard', 'Go to Dashboard'); ?></a>
          </div>

        <?php else: ?>
          
          <?php if ($pending_payment && $pending_payment['status'] === 'rejected'): ?>
            <div class="alert alert-warning shadow-sm fs-8 py-2 px-3 mb-4">
              <h6 class="alert-heading fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill me-2"></i><?php echo __('previous_slip_rejected', 'Previous Slip Rejected'); ?></h6>
              <?php echo __('previous_slip_rejected_msg', 'Your last deposit slip was not approved. Please verify your receipt details and upload a valid, clear bank slip below.'); ?>
            </div>
          <?php endif; ?>

          <div class="moodle-card p-4 bg-white" id="checkout-box">
            <h4 class="fw-bold text-dark border-bottom pb-2 mb-4 fs-5"><i class="bi bi-bank text-primary me-2"></i><?php echo __('upload_receipt_slip', 'Bank Slip Upload'); ?></h4>
            
            <form action="payment.php?course_id=<?php echo urlencode($course_id); ?>" method="POST" enctype="multipart/form-data">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-semibold text-secondary fs-8"><?php echo __('depositors_full_name', "Depositor's Full Name"); ?></label>
                  <input type="text" name="full_name" class="form-control form-control-sm" placeholder="Enter your full name" required>
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold text-secondary fs-8"><?php echo __('upload_receipt_slip', 'Upload Receipt / Slip'); ?></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-upload"></i></span>
                    <input type="file" name="slip_file" class="form-control" accept="image/*,application/pdf" required>
                  </div>
                  <div class="form-text fs-9 text-muted mt-1"><?php echo __('slip_format_note', 'Supported formats: JPG, JPEG, PNG, PDF (Max 5MB).'); ?></div>
                </div>
              </div>

              <div class="d-flex align-items-center gap-2 mt-4 p-2.5 border rounded bg-light fs-9 text-muted">
                <i class="bi bi-shield-check text-success fs-6"></i>
                <span><?php echo __('slip_security_note', 'Your uploaded slip is stored securely and reviewed by administrative coordinators.'); ?></span>
              </div>

              <div class="mt-4 d-flex justify-content-between align-items-center">
                <a href="classroom.php?course_id=<?php echo urlencode($course_id); ?>" class="btn btn-link text-decoration-none text-muted p-0 fs-8"><i class="bi bi-arrow-left"></i> <?php echo __('cancel', 'Cancel'); ?></a>
                <button type="submit" name="submit_slip" class="btn btn-primary px-4 py-2 text-white border-0 fw-bold rounded shadow-sm" style="background-color: #0f4c81;">
                  <?php echo __('submit_bank_slip', 'Submit Bank Slip'); ?>
                </button>
              </div>
            </form>
          </div>

        <?php endif; ?>

      </div>

    </div>
  </main>

  <footer class="py-4 bg-dark text-white-50 mt-5 border-top">
    <div class="container text-center fs-8">
      <p class="mb-1 text-white">Computerscience.lk secured portal</p>
      <p class="mb-0">Powered by Moodle Core Payment Engine &copy; 2026.</p>
    </div>
  </footer>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>

  <!-- Copy Button Script & Notifications Read Handler -->
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

    function copyToClipboard(text, btnId) {
      navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById(btnId);
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg text-success"></i> Copied!';
        setTimeout(() => {
          btn.innerHTML = originalHTML;
        }, 2000);
      }).catch(err => {
        console.error('Failed to copy text:', err);
      });
    }

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
  </script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
</body>
</html>
