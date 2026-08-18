<?php
session_name('LMS_ADMIN_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
session_start();

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

// Session recovery check if needed
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Toggle Student Status (Active / Inactive)
    if ($action === 'toggle_student_status') {
        $target_id = intval($_POST['user_id'] ?? 0);
        $new_status = strtolower(trim($_POST['status'] ?? 'active'));
        if (!in_array($new_status, ['active', 'inactive'])) {
            $new_status = 'active';
        }

        if ($target_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'student'");
            $stmt->execute([$new_status, $target_id]);

            $notifMsg = ($new_status === 'active')
                ? "Your Student account status has been activated by system administrators."
                : "Your Student account status has been temporarily set to INACTIVE by system administrators.";

            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
            $notifStmt->execute([$target_id, $notifMsg]);

            $success_message = "Student account status updated to " . strtoupper($new_status) . " successfully!";
        } else {
            $error_message = "Invalid student user ID.";
        }
    }

    // 2. Send Custom Notification Message to Student Bell
    if ($action === 'send_student_notification') {
        $target_id = intval($_POST['user_id'] ?? 0);
        $custom_message = trim($_POST['message'] ?? '');

        if ($target_id > 0 && !empty($custom_message)) {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
            $stmt->execute([$target_id, $custom_message]);

            $sStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $sStmt->execute([$target_id]);
            $sName = $sStmt->fetchColumn() ?: 'Student';

            $success_message = "Notification message sent to student " . htmlspecialchars($sName) . "'s account notification bell successfully!";
        } else {
            $error_message = "Please provide a valid notification message content.";
        }
    }

    // 3. Delete Student Account with Admin Password Confirmation
    if ($action === 'delete_student') {
        $target_id = intval($_POST['user_id'] ?? 0);
        $admin_password = $_POST['admin_password'] ?? '';

        if (empty($admin_password)) {
            $error_message = "Admin account password confirmation is required to delete a student account.";
        } elseif ($target_id > 0) {
            if (password_verify($admin_password, $dbUser['password_hash'])) {
                try {
                    $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$target_id]);
                    $pdo->prepare("DELETE FROM enrollments WHERE user_id = ?")->execute([$target_id]);
                    $pdo->prepare("DELETE FROM quiz_results WHERE user_id = ?")->execute([$target_id]);
                    $pdo->prepare("DELETE FROM bank_payments WHERE user_id = ?")->execute([$target_id]);

                    $delStmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
                    $delStmt->execute([$target_id]);

                    $success_message = "Student account deleted successfully.";
                } catch (PDOException $ex) {
                    $error_message = "Database error while deleting student account: " . $ex->getMessage();
                }
            } else {
                $error_message = "Invalid admin account password. Student deletion cancelled.";
            }
        } else {
            $error_message = "Invalid student user ID.";
        }
    }
}

// Fetch registered students (with optional search filter by name, email, or registration/academic ID)
$search_query = trim($_GET['search'] ?? '');

if (!empty($search_query)) {
    $search_param = '%' . $search_query . '%';
    $stmt = $pdo->prepare("SELECT u.*, 
                                (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) as enrolled_count 
                         FROM users u 
                         WHERE u.role = 'student' 
                           AND (u.name LIKE ? OR u.email LIKE ? OR u.academic_id LIKE ?)
                         ORDER BY u.created_at DESC");
    $stmt->execute([$search_param, $search_param, $search_param]);
} else {
    $stmt = $pdo->query("SELECT u.*, 
                                (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) as enrolled_count 
                         FROM users u 
                         WHERE u.role = 'student' 
                         ORDER BY u.created_at DESC");
}
$students = $stmt->fetchAll();

$total_students = count($students);
$active_students = count(array_filter($students, function($s) { return strtolower($s['status'] ?? 'active') === 'active'; }));
$inactive_students = $total_students - $active_students;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered Students Directory - Admin Console</title>

    <!-- Local Bootstrap 5 CSS & Icons -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">

    <style>
        body {
            background-color: #f4f7f6;
            font-family: system-ui, -apple-system, sans-serif;
            color: #1f2937;
        }
        .header-gradient {
            background: linear-gradient(135deg, <?php echo $primary_theme_color; ?> 0%, #1e3a8a 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        }
        .glass-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }
        .student-avatar {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
        }
        .stat-widget {
            background: #ffffff;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            transition: transform 0.2s ease;
        }
        .stat-widget:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

    <?php
      $active_nav = 'students';
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
                        <i class="bi bi-person-badge-fill text-info"></i>
                        <span>Registered Students Directory</span>
                    </span>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2.5">
                <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                </a>
                <a href="student_analytics.php" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold">
                    <i class="bi bi-graph-up-arrow me-1"></i> Analytics
                </a>
                <a href="certificates.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold">
                    <i class="bi bi-award-fill text-warning me-1"></i> Certificates
                </a>
                <span class="badge bg-white text-dark border px-3 py-1.5 rounded-pill shadow-xs fs-8 fw-semibold d-flex align-items-center gap-2">
                    <img src="<?php echo htmlspecialchars($current_admin_avatar); ?>"
                         onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($current_admin_name); ?>&background=0f4c81&color=fff';"
                         class="rounded-circle" style="width: 24px; height: 24px; object-fit: cover;">
                    <span class="d-none d-md-inline"><?php echo $is_super_admin ? 'Super Admin' : 'Admin'; ?>: <?php echo htmlspecialchars($current_admin_name); ?></span>
                </span>
                <a href="logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </header>

        <main class="py-4 flex-grow-1">
            <div class="container-fluid px-3 px-md-4" style="max-width: 1400px;">

                <!-- Header Hero Card -->
                <div class="header-gradient mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <span class="badge bg-white text-dark fw-bold px-3 py-1 rounded-pill mb-2 fs-9 text-uppercase tracking-wider">
                            <i class="bi bi-person-badge-fill me-1 text-primary"></i> Student Management
                        </span>
                        <h2 class="fw-bold mb-1 text-white">Registered Students Directory</h2>
                        <p class="text-white-50 fs-7 mb-0">View full student profiles in preview mode, toggle active/inactive status, dispatch bell notifications, and delete student accounts securely.</p>
                    </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="certificates.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold shadow-sm text-dark border-0">
                    <i class="bi bi-award-fill me-2"></i> Certificates
                </a>
                <a href="student_analytics.php" class="btn btn-outline-light btn-lg rounded-pill px-4 fw-bold shadow-sm border-2">
                    <i class="bi bi-graph-up-arrow me-2"></i> Student Analytics
                </a>
                <a href="index.php" class="btn btn-light btn-lg rounded-pill px-4 fw-bold shadow-sm text-dark border-0">
                    <i class="bi bi-speedometer2 me-2 text-primary"></i> Main Dashboard
                </a>
            </div>
        </div>

        <!-- Toast Notifications -->
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

        <!-- Summary Stat Widgets -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-widget d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary">
                        <i class="bi bi-people-fill fs-3"></i>
                    </div>
                    <div>
                        <div class="fs-9 text-muted fw-bold text-uppercase">Total Students</div>
                        <div class="fs-3 fw-bold text-dark"><?php echo number_format($total_students); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-widget d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-10 p-3 text-success">
                        <i class="bi bi-check-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <div class="fs-9 text-muted fw-bold text-uppercase">Active Accounts</div>
                        <div class="fs-3 fw-bold text-success"><?php echo number_format($active_students); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-widget d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3 text-danger">
                        <i class="bi bi-pause-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <div class="fs-9 text-muted fw-bold text-uppercase">Inactive Accounts</div>
                        <div class="fs-3 fw-bold text-danger"><?php echo number_format($inactive_students); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students Directory Table Card -->
        <div class="glass-card p-4">
            <!-- Search & Directory Header -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 border-bottom pb-3">
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-people-fill text-primary"></i> Registered Students
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill fs-8" id="student-count-badge">
                        <?php echo count($students); ?>
                    </span>
                </h5>

                <!-- Search Bar Form -->
                <form action="students.php" method="GET" class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" style="max-width: 440px; width: 100%;">
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border">
                        <span class="input-group-text bg-white border-0 ps-3">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" 
                               id="student-search-input" 
                               name="search" 
                               class="form-control border-0 ps-1 fs-8 shadow-none" 
                               placeholder="Search by name, email or reg number..." 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               autocomplete="off">
                        <?php if (!empty($search_query)): ?>
                            <a href="students.php" class="btn btn-white border-0 text-muted px-2.5 d-flex align-items-center" title="Clear search">
                                <i class="bi bi-x-circle-fill"></i>
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary px-3.5 fs-8 fw-semibold" style="background-color: <?php echo $primary_theme_color; ?>; border: none;">
                            Search
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($total_students === 0 && empty($search_query)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-x-fill text-muted fs-1 mb-2"></i>
                    <h5 class="fw-bold text-dark mb-1">No Registered Students Found</h5>
                    <p class="text-muted fs-7">No student accounts are currently registered in the database.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="students-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="py-3 border-0">Student Info</th>
                                <th scope="col" class="py-3 border-0">Academic ID & Email</th>
                                <th scope="col" class="py-3 border-0">Enrollments</th>
                                <th scope="col" class="py-3 border-0">Status</th>
                                <th scope="col" class="py-3 border-0 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Row shown when live client-side or backend search finds zero matches -->
                            <tr id="no-match-row" style="<?php echo ($total_students === 0 && !empty($search_query)) ? '' : 'display: none;'; ?>">
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-search-heart text-secondary fs-1 mb-2 d-block"></i>
                                    <h6 class="fw-bold text-dark mb-1">No Matching Students Found</h6>
                                    <p class="fs-8 mb-0">No student matched your search query "<strong><?php echo htmlspecialchars($search_query); ?></strong>". Try searching with another name, email address, or registration number.</p>
                                </td>
                            </tr>
                            <?php foreach ($students as $student): ?>
                                <?php
                                $s_name = !empty($student['name']) ? $student['name'] : 'Student';
                                $raw_s_avatar = trim($student['avatar'] ?? '');
                                if (empty($raw_s_avatar)) {
                                    $s_avatar_src = 'https://ui-avatars.com/api/?name=' . urlencode($s_name) . '&background=0f4c81&color=fff';
                                } elseif (preg_match('~^https?://~i', $raw_s_avatar) || strpos($raw_s_avatar, 'data:') === 0) {
                                    $s_avatar_src = $raw_s_avatar;
                                } else {
                                    $s_avatar_src = '../' . ltrim($raw_s_avatar, '/');
                                }
                                $s_status = strtolower($student['status'] ?? 'active');

                                // Fetch student details for preview modal
                                $enrolledStmt = $pdo->prepare("SELECT c.id, c.title, c.price, c.category 
                                                              FROM enrollments e 
                                                              JOIN courses c ON e.course_id = c.id 
                                                              WHERE e.user_id = ?");
                                $enrolledStmt->execute([$student['id']]);
                                $student_courses = $enrolledStmt->fetchAll();

                                $quizStmt = $pdo->prepare("SELECT qr.*, c.title as course_title 
                                                            FROM quiz_results qr 
                                                            JOIN courses c ON qr.course_id = c.id 
                                                            WHERE qr.user_id = ? ORDER BY qr.updated_at DESC");
                                $quizStmt->execute([$student['id']]);
                                $student_quizzes = $quizStmt->fetchAll();
                                ?>
                                <tr class="student-row" 
                                    data-name="<?php echo htmlspecialchars(strtolower($s_name)); ?>"
                                    data-email="<?php echo htmlspecialchars(strtolower($student['email'] ?? '')); ?>"
                                    data-academic-id="<?php echo htmlspecialchars(strtolower($student['academic_id'] ?? '')); ?>">
                                    <td class="py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="<?php echo htmlspecialchars($s_avatar_src); ?>"
                                                 onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($s_name); ?>&background=0f4c81&color=fff';"
                                                 alt="<?php echo htmlspecialchars($s_name); ?>"
                                                 class="student-avatar shadow-sm">
                                            <div>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($s_name); ?></div>
                                                <div class="fs-8 text-muted"><i class="bi bi-calendar3 me-1"></i>Joined <?php echo date('M d, Y', strtotime($student['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <div class="font-monospace fs-8 text-dark fw-bold"><?php echo htmlspecialchars($student['academic_id'] ?? 'N/A'); ?></div>
                                        <div class="fs-8 text-muted"><?php echo htmlspecialchars($student['email']); ?></div>
                                    </td>
                                    <td class="py-3">
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5 rounded-pill fs-8">
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
                                            <!-- View Profile Modal Button -->
                                            <button type="button" class="btn btn-sm btn-outline-primary px-3 rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#viewStudentModal_<?php echo $student['id']; ?>" title="Admin Preview Profile">
                                                <i class="bi bi-person-bounding-box me-1"></i> Profile
                                            </button>

                                            <!-- Workable Active / Inactive Status Toggle Button -->
                                            <?php if ($s_status === 'active'): ?>
                                                <form action="students.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_student_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="status" value="inactive">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning px-3 rounded-pill fw-semibold" title="Deactivate Student Account">
                                                        <i class="bi bi-pause-circle me-1"></i> Set Inactive
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form action="students.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_student_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="btn btn-sm btn-outline-success px-3 rounded-pill fw-semibold" title="Activate Student Account">
                                                        <i class="bi bi-play-circle me-1"></i> Set Active
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Send Notification Message Button -->
                                            <button type="button" class="btn btn-sm btn-outline-info px-3 rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#sendStudentMessageModal_<?php echo $student['id']; ?>" title="Send Notification to Student Bell">
                                                <i class="bi bi-chat-dots-fill me-1"></i> Message
                                            </button>

                                            <!-- Delete Student Button -->
                                            <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#deleteStudentModal_<?php echo $student['id']; ?>" title="Delete Student Account">
                                                <i class="bi bi-trash3-fill me-1"></i> Delete
                                            </button>
                                        </div>

                                        <!-- 1. VIEW PROFILE (ADMIN PREVIEW MODAL) -->
                                        <div class="modal fade text-start" id="viewStudentModal_<?php echo $student['id']; ?>" tabindex="-1" aria-hidden="true">
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
                                                            <img src="<?php echo htmlspecialchars($s_avatar_src); ?>" alt="Avatar" class="rounded-circle border" style="width: 64px; height: 64px; object-fit: cover;">
                                                            <div>
                                                                <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($s_name); ?></h4>
                                                                <div class="d-flex flex-wrap align-items-center gap-2 fs-8">
                                                                    <span class="badge bg-secondary rounded-pill">Role: Student</span>
                                                                    <span class="badge <?php echo $s_status === 'active' ? 'bg-success' : 'bg-danger'; ?> rounded-pill">Status: <?php echo ucfirst($s_status); ?></span>
                                                                    <span class="text-muted"><i class="bi bi-hash"></i> Academic ID: <strong><?php echo htmlspecialchars($student['academic_id'] ?? 'N/A'); ?></strong></span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Profile Metadata Grid -->
                                                        <div class="row g-3 mb-4 fs-8">
                                                            <div class="col-md-6">
                                                                <div class="p-3 border rounded-3 bg-white">
                                                                    <strong class="text-dark d-block mb-1"><i class="bi bi-envelope me-1 text-primary"></i> Email Address:</strong>
                                                                    <span class="text-secondary"><?php echo htmlspecialchars($student['email']); ?></span>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="p-3 border rounded-3 bg-white">
                                                                    <strong class="text-dark d-block mb-1"><i class="bi bi-telephone me-1 text-primary"></i> Phone Number:</strong>
                                                                    <span class="text-secondary"><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-12">
                                                                <div class="p-3 border rounded-3 bg-white">
                                                                    <strong class="text-dark d-block mb-1"><i class="bi bi-geo-alt me-1 text-primary"></i> Address:</strong>
                                                                    <span class="text-secondary"><?php echo htmlspecialchars($student['address'] ?? 'No address listed.'); ?></span>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-12">
                                                                <div class="p-3 border rounded-3 bg-white">
                                                                    <strong class="text-dark d-block mb-1"><i class="bi bi-card-text me-1 text-primary"></i> Student Bio:</strong>
                                                                    <span class="text-secondary"><?php echo nl2br(htmlspecialchars($student['bio'] ?? 'No bio provided.')); ?></span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Enrolled Courses Tabulated Accordion -->
                                                        <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                                                            <i class="bi bi-journal-bookmark-fill text-primary"></i> Enrolled Courses (<?php echo count($student_courses); ?>)
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
                                                                            <span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($sc['category'] ?? 'Course'); ?></span>
                                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success">Rs. <?php echo number_format($sc['price'], 2); ?></span>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <!-- Quiz Performance History -->
                                                        <h6 class="fw-bold text-dark mb-2.5 d-flex align-items-center gap-2">
                                                            <i class="bi bi-patch-check-fill text-success"></i> Quiz Attempt History (<?php echo count($student_quizzes); ?>)
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
                                                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close Profile</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 2. SEND NOTIFICATION MESSAGE MODAL -->
                                        <div class="modal fade text-start" id="sendStudentMessageModal_<?php echo $student['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg rounded-4">
                                                    <form action="students.php" method="POST">
                                                        <input type="hidden" name="action" value="send_student_notification">
                                                        <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                        <div class="modal-header border-0 pb-0">
                                                            <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                                                                <i class="bi bi-chat-dots-fill text-info fs-5"></i> Send Notification Message
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body py-3">
                                                            <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                                                                <img src="<?php echo htmlspecialchars($s_avatar_src); ?>" alt="Avatar" class="rounded-circle border" style="width: 40px; height: 40px; object-fit: cover;">
                                                                <div>
                                                                    <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($s_name); ?></div>
                                                                    <div class="fs-8 text-muted"><?php echo htmlspecialchars($student['email']); ?></div>
                                                                </div>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label fw-bold text-dark fs-8">Notification Message Content:</label>
                                                                <textarea name="message" class="form-control rounded-3" rows="4" placeholder="Type your message here... This will trigger a notification bell badge alert on the student's header." required></textarea>
                                                            </div>
                                                            <small class="text-muted fs-8"><i class="bi bi-bell-fill me-1 text-warning"></i>Delivered directly to student's notification bell.</small>
                                                        </div>
                                                        <div class="modal-footer border-0 pt-0">
                                                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-info text-white rounded-pill px-4 fw-bold shadow-sm">
                                                                <i class="bi bi-send-fill me-1"></i> Send Notification
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 3. DELETE STUDENT CONFIRMATION MODAL -->
                                        <div class="modal fade text-start" id="deleteStudentModal_<?php echo $student['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg rounded-4">
                                                    <form action="students.php" method="POST">
                                                        <input type="hidden" name="action" value="delete_student">
                                                        <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                        <div class="modal-header border-0 pb-0">
                                                            <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
                                                                <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i> Confirm Student Deletion
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body py-3">
                                                            <p class="fs-7 text-secondary mb-3">
                                                                Are you sure you want to permanently delete student account <strong><?php echo htmlspecialchars($s_name); ?></strong> (<?php echo htmlspecialchars($student['email']); ?>)? All enrollments and quiz records for this student will be removed.
                                                            </p>
                                                            <div class="mb-2">
                                                                <label class="form-label fw-bold text-dark fs-8">Enter Admin Password to Confirm Deletion:</label>
                                                                <input type="password" name="admin_password" class="form-control rounded-3" placeholder="Enter your admin password" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0 pt-0">
                                                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Delete Student</button>
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
        </main>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>

    <!-- Live Instant Search Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('student-search-input');
            const tableRows = document.querySelectorAll('tbody tr.student-row');
            const noMatchRow = document.getElementById('no-match-row');
            const countBadge = document.getElementById('student-count-badge');

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    let visibleCount = 0;

                    tableRows.forEach(row => {
                        const name = row.getAttribute('data-name') || '';
                        const email = row.getAttribute('data-email') || '';
                        const academicId = row.getAttribute('data-academic-id') || '';

                        if (name.includes(query) || email.includes(query) || academicId.includes(query)) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    if (noMatchRow) {
                        noMatchRow.style.display = (visibleCount === 0 && tableRows.length > 0) ? '' : 'none';
                    }
                    if (countBadge) {
                        countBadge.innerText = visibleCount;
                    }
                });
            }
        });
    </script>
</body>
</html>
