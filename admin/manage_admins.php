<?php
session_name('LMS_ADMIN_SESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
session_start();

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

// Detect AJAX / JSON Request
$isJsonRequest = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
    (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
    isset($_REQUEST['ajax']) || isset($_REQUEST['is_ajax'])
);

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
    if ($isJsonRequest) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => __('unauthorized_super_admin', 'Unauthorized. Super Admin access required.')]);
        exit;
    }
    header("Location: login.php");
    exit;
}

$pdo = getDBConnection();

// Freshly fetch user details from database to guarantee immediate live session enforcement
$roleCheckStmt = $pdo->prepare("SELECT id, name, email, avatar, role, status, password_hash FROM users WHERE id = ?");
$roleCheckStmt->execute([$_SESSION['user_id']]);
$dbUser = $roleCheckStmt->fetch();
if ($dbUser) {
    $_SESSION['user_role'] = $dbUser['role'];
    $_SESSION['user_name'] = $dbUser['name'];
    $_SESSION['user_email'] = $dbUser['email'];
    $_SESSION['user_avatar'] = $dbUser['avatar'];

    // 1. Enforce Status Check: If account is deactivated, log out immediately
    if (strtolower($dbUser['status'] ?? 'active') !== 'active' && $dbUser['role'] !== 'super_admin') {
        session_destroy();
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => __('account_deactivated', 'Account Deactivated: Access denied.')]);
            exit;
        }
        header("Location: login.php?error=deactivated");
        exit;
    }

    // 2. Enforce Password Change Check: If password was reset by Super Admin, log out immediately
    if (isset($_SESSION['session_password_hash']) && $_SESSION['session_password_hash'] !== $dbUser['password_hash'] && $dbUser['role'] !== 'super_admin') {
        session_destroy();
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => __('password_changed', 'Security Alert: Your password was updated by Super Admin.')]);
            exit;
        }
        header("Location: login.php?error=password_changed");
        exit;
    }

    if (!isset($_SESSION['session_password_hash'])) {
        $_SESSION['session_password_hash'] = $dbUser['password_hash'];
    }
}

$user_role = $_SESSION['user_role'] ?? '';
if ($user_role !== 'super_admin') {
    if ($isJsonRequest) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('unauthorized_super_admin', 'Unauthorized. Super Admin access required.')]);
        exit;
    }
    header("Location: index.php");
    exit;
}

$current_admin_name = $_SESSION['user_name'] ?? ($dbUser['name'] ?? 'Super Admin');
$current_admin_email = $_SESSION['user_email'] ?? ($dbUser['email'] ?? 'dev.ishara20@gmail.com');
$raw_avatar = $_SESSION['user_avatar'] ?? ($dbUser['avatar'] ?? '');

if (empty($raw_avatar)) {
    $current_admin_avatar = 'https://ui-avatars.com/api/?name=' . urlencode($current_admin_name) . '&background=0b4528&color=fff';
} elseif (preg_match('~^https?://~i', $raw_avatar) || strpos($raw_avatar, 'data:') === 0) {
    $current_admin_avatar = $raw_avatar;
} else {
    $current_admin_avatar = '../' . ltrim($raw_avatar, '/');
}

$success_message = '';
$error_message = '';

// Normalize action
$rawAction = $_POST['action'] ?? $_GET['action'] ?? '';
$action = strtoupper(trim($rawAction));

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    try {
        // 1. ADD_ADMIN / CREATE_ADMIN
        if ($action === 'ADD_ADMIN' || $action === 'CREATE_ADMIN') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $status = strtolower(trim($_POST['status'] ?? 'active'));
            if (!in_array($status, ['active', 'inactive', 'suspended'])) {
                $status = 'active';
            }

            if (empty($name) || empty($email) || empty($password)) {
                $error_message = __('all_fields_required', 'All required fields must be filled.');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = __('invalid_email', 'Please provide a valid email address.');
            } else {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    $error_message = __('email_already_exists', 'An account with this email address already exists.');
                } else {
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $academic_id = 'ADMN-' . rand(100000, 999999);
                    $avatar = null;
                    $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, avatar, academic_id, role, status) VALUES (?, ?, ?, ?, ?, 'admin', ?)");
                    $insertStmt->execute([$name, $email, $passHash, $avatar, $academic_id, $status]);
                    $success_message = __('admin_created_success', 'Admin account created successfully!');
                }
            }
        }

        // 2. EDIT_ADMIN
        elseif ($action === 'EDIT_ADMIN' || $action === 'EDIT_ADMIN_ACCOUNT') {
            $target_id = intval($_POST['admin_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $status = strtolower(trim($_POST['status'] ?? 'active'));
            if (!in_array($status, ['active', 'inactive', 'suspended'])) {
                $status = 'active';
            }
            $new_password = trim($_POST['new_password'] ?? '');

            // Verify target is a standard admin and not a super admin
            $targetStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
            $targetStmt->execute([$target_id]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser || $targetUser['role'] === 'super_admin') {
                $error_message = __('cannot_modify_super_admin', 'Permission denied: Cannot modify Super Admin accounts.');
            } elseif (empty($name) || empty($email)) {
                $error_message = __('all_fields_required', 'All required fields must be filled.');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = __('invalid_email', 'Please provide a valid email address.');
            } else {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $checkStmt->execute([$email, $target_id]);
                if ($checkStmt->fetch()) {
                    $error_message = __('email_already_exists', 'An account with this email address already exists.');
                } else {
                    if (!empty($new_password)) {
                        $passHash = password_hash($new_password, PASSWORD_BCRYPT);
                        $updateStmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, status = ?, password_hash = ? WHERE id = ? AND role = 'admin'");
                        $updateStmt->execute([$name, $email, $status, $passHash, $target_id]);
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, status = ? WHERE id = ? AND role = 'admin'");
                        $updateStmt->execute([$name, $email, $status, $target_id]);
                    }
                    $success_message = __('admin_updated_success', 'Admin account updated successfully!');
                }
            }
        }

        // 3. TOGGLE_STATUS / UPDATE_STATUS (Active <-> Inactive / Suspended)
        elseif ($action === 'TOGGLE_STATUS' || $action === 'UPDATE_STATUS') {
            $target_id = intval($_POST['admin_id'] ?? 0);
            $requested_status = strtolower(trim($_POST['new_status'] ?? $_POST['status'] ?? ''));

            $targetStmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ?");
            $targetStmt->execute([$target_id]);
            $targetUser = $targetStmt->fetch();

            if (!$targetUser || $targetUser['role'] === 'super_admin') {
                $error_message = __('cannot_modify_super_admin', 'Permission denied: Cannot modify Super Admin accounts.');
            } else {
                if (empty($requested_status)) {
                    $current_status = strtolower($targetUser['status'] ?? 'active');
                    $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                } else {
                    $new_status = $requested_status;
                }

                if (!in_array($new_status, ['active', 'inactive', 'suspended'])) {
                    $new_status = 'active';
                }

                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'admin'");
                $stmt->execute([$new_status, $target_id]);
                $success_message = __('admin_status_updated', 'Admin account status updated successfully!');
            }
        }

        // 4. DELETE_ADMIN
        elseif ($action === 'DELETE_ADMIN' || $action === 'REMOVE_ADMIN') {
            $target_id = intval($_POST['admin_id'] ?? 0);

            if ($target_id === intval($_SESSION['user_id'])) {
                $error_message = __('cannot_delete_self', 'Permission denied: Super Admin accounts cannot be self-deleted.');
            } else {
                $targetStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
                $targetStmt->execute([$target_id]);
                $targetUser = $targetStmt->fetch();

                if (!$targetUser || $targetUser['role'] === 'super_admin') {
                    $error_message = __('cannot_modify_super_admin', 'Permission denied: Cannot modify Super Admin accounts.');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$target_id]);
                    $success_message = __('admin_deleted_success', 'Admin account deleted successfully!');
                }
            }
        }
    } catch (PDOException $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }

    if ($isJsonRequest) {
        header('Content-Type: application/json');
        if (!empty($error_message)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $error_message]);
        } else {
            echo json_encode(['success' => true, 'message' => $success_message]);
        }
        exit;
    }
}

// Fetch all current standard Admin accounts (excluding Super Admins)
try {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at DESC");
    $admins = $stmt->fetchAll();

    $superAdminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('manage_admins', 'Admin Management'); ?> | <?php echo __('super_admin_panel', 'Super Admin Panel'); ?></title>
    
    <!-- Local Bootstrap 5 CSS & Icons -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">

    <?php render_i18n_js(); ?>

    <style>
        body {
            background-color: #f4f7f5;
            font-family: system-ui, -apple-system, sans-serif;
        }
        .admin-header-bg {
            background: linear-gradient(135deg, #052014 0%, #0b4528 50%, #125b36 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(11, 69, 40, 0.25);
        }
        .glass-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 15px rgba(11, 69, 40, 0.04);
        }
        .admin-avatar {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #a3cfbb;
        }
        .badge-active { background-color: rgba(25, 135, 84, 0.1); color: #198754; border: 1px solid rgba(25, 135, 84, 0.3); }
        .badge-inactive { background-color: rgba(239, 68, 68, 0.1); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-suspended { background-color: rgba(234, 179, 8, 0.1); color: #ca8a04; border: 1px solid rgba(234, 179, 8, 0.3); }
        
        .btn-super-green {
            background-color: #0b4528;
            color: #ffffff;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-super-green:hover {
            background-color: #125b36;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(11, 69, 40, 0.3);
        }
    </style>
</head>
<body>

    <!-- Main Container -->
    <div class="container py-4" style="max-width: 1140px;">
        
        <!-- Navigation Back Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-2">
                <a href="index.php" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-semibold">
                    <i class="bi bi-arrow-left me-1.5"></i> <?php echo __('back_to_dashboard', 'Back to Admin Dashboard'); ?>
                </a>
                <a href="email_settings.php" class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-semibold">
                    <i class="bi bi-envelope-gear-fill me-1 text-warning"></i> Email Settings
                </a>
                <a href="students.php" class="btn btn-outline-info btn-sm rounded-pill px-3 fw-semibold">
                    <i class="bi bi-person-badge-fill me-1"></i> Registered Students
                </a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-success text-white px-3 py-1.5 rounded-pill fs-8 fw-bold shadow-sm">
                    <i class="bi bi-shield-lock-fill me-1.5"></i> Super Admin
                </span>
                <div class="d-flex align-items-center gap-2.5 border-start ps-3">
                    <img src="<?php echo htmlspecialchars($current_admin_avatar); ?>"
                         onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($current_admin_name); ?>&background=0b4528&color=fff';"
                         alt="<?php echo htmlspecialchars($current_admin_name); ?>"
                         class="rounded-circle border shadow-sm"
                         style="width: 36px; height: 36px; object-fit: cover;">
                    <div class="d-none d-sm-block text-end">
                        <div class="fw-bold fs-8 text-dark mb-0"><?php echo htmlspecialchars($current_admin_name); ?></div>
                        <div class="fs-9 text-muted"><?php echo htmlspecialchars($current_admin_email); ?> &bull; <strong class="text-success">Super Admin</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Header Card -->
        <div class="admin-header-bg mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="badge bg-white text-dark fw-bold px-3 py-1 rounded-pill mb-2 fs-9 text-uppercase tracking-wider">
                    <i class="bi bi-person-badge-fill me-1 text-success"></i> <?php echo __('super_admin_control_panel', 'Super Admin Control Panel'); ?>
                </span>
                <h2 class="fw-bold mb-1 text-white"><?php echo __('manage_admins', 'Admin Management'); ?></h2>
                <p class="text-white-50 fs-7 mb-0"><?php echo __('manage_admins_subtitle', 'Provision, manage status, reset passwords, and control access for system administrators.'); ?></p>
            </div>
            <div>
                <button type="button" class="btn btn-light btn-lg rounded-pill px-4 fw-bold shadow-sm text-success border-0" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                    <i class="bi bi-person-plus-fill me-2 text-success"></i> <?php echo __('add_new_admin', 'Add New Admin'); ?>
                </button>
            </div>
        </div>

        <!-- System Alerts Container -->
        <div id="systemAlertsContainer">
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show border-success shadow-sm rounded-3 mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show border-danger shadow-sm rounded-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Statistics Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="glass-card p-3 d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="bi bi-person-gear fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted fs-8 fw-semibold"><?php echo __('total_admins', 'Total System Admins'); ?></div>
                        <h4 class="fw-bold text-dark mb-0"><?php echo count($admins); ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="glass-card p-3 d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-15 text-success p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="bi bi-check-circle-fill fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted fs-8 fw-semibold"><?php echo __('active_admins', 'Active Admins'); ?></div>
                        <h4 class="fw-bold text-dark mb-0">
                            <?php echo count(array_filter($admins, function($a) { return strtolower($a['status'] ?? '') === 'active'; })); ?>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="glass-card p-3 d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-20 text-success p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="bi bi-shield-check fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted fs-8 fw-semibold"><?php echo __('super_admin_accounts', 'Super Admin Accounts'); ?></div>
                        <h4 class="fw-bold text-dark mb-0"><?php echo $superAdminCount; ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Table Card -->
        <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-shield-shaded me-2 text-success"></i><?php echo __('system_administrators', 'System Administrators'); ?>
                </h5>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 fs-8"><?php echo __('role_admin_badge', 'Role: admin'); ?></span>
            </div>

            <?php if (count($admins) === 0): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-x text-muted fs-1 mb-2 d-block"></i>
                    <h6 class="fw-bold text-dark mb-1"><?php echo __('no_admins_found', 'No Standard Admins Found'); ?></h6>
                    <p class="text-muted fs-8 mb-3"><?php echo __('no_admins_found_desc', 'Click "Add New Admin" above to create an administrator account.'); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="py-3 border-0"><?php echo __('administrator', 'Administrator'); ?></th>
                                <th scope="col" class="py-3 border-0"><?php echo __('academic_id_and_email', 'Academic ID & Email'); ?></th>
                                <th scope="col" class="py-3 border-0"><?php echo __('status_label', 'Status'); ?></th>
                                <th scope="col" class="py-3 border-0"><?php echo __('created_date', 'Created Date'); ?></th>
                                <th scope="col" class="py-3 border-0 text-end"><?php echo __('actions', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): 
                                $adminStatus = strtolower($admin['status'] ?? 'active');
                            ?>
                                <tr>
                                    <td class="py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="<?php echo htmlspecialchars(!empty($admin['avatar']) ? (preg_match('~^https?://~i', $admin['avatar']) ? $admin['avatar'] : '../' . ltrim($admin['avatar'], '/')) : 'https://ui-avatars.com/api/?name=' . urlencode($admin['name']) . '&background=0b4528&color=fff'); ?>"
                                                 onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin['name']); ?>&background=0b4528&color=fff';"
                                                 alt="<?php echo htmlspecialchars($admin['name']); ?>" class="admin-avatar shadow-sm">
                                            <div>
                                                <div class="fw-bold text-dark fs-7"><?php echo htmlspecialchars($admin['name']); ?></div>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fs-9 px-2 py-0.5 rounded-pill"><?php echo __('administrator_role', 'Administrator'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <div class="font-monospace fs-8 text-dark fw-semibold"><?php echo htmlspecialchars($admin['academic_id'] ?? 'ADMN-000000'); ?></div>
                                        <div class="fs-8 text-muted"><?php echo htmlspecialchars($admin['email']); ?></div>
                                    </td>
                                    <td class="py-3">
                                        <?php if ($adminStatus === 'active'): ?>
                                            <span class="badge badge-active px-2.5 py-1 rounded-pill fs-8"><i class="bi bi-check-circle-fill me-1"></i> <?php echo __('active', 'Active'); ?></span>
                                        <?php elseif ($adminStatus === 'suspended'): ?>
                                            <span class="badge badge-suspended px-2.5 py-1 rounded-pill fs-8"><i class="bi bi-dash-circle-fill me-1"></i> <?php echo __('suspended', 'Suspended'); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive px-2.5 py-1 rounded-pill fs-8"><i class="bi bi-x-circle-fill me-1"></i> <?php echo __('inactive', 'Inactive'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 fs-8 text-secondary">
                                        <?php echo date('Y-m-d H:i', strtotime($admin['created_at'])); ?>
                                    </td>
                                    <td class="py-3 text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <!-- Edit Button Trigger Modal -->
                                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editAdminModal<?php echo $admin['id']; ?>">
                                                <i class="bi bi-pencil-square me-1"></i> <?php echo __('edit_reset_password', 'Edit / Reset Password'); ?>
                                            </button>

                                            <!-- Toggle Status Form -->
                                            <form action="manage_admins.php" method="POST" class="d-inline admin-action-form">
                                                <input type="hidden" name="action" value="TOGGLE_STATUS">
                                                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo ($adminStatus === 'active') ? 'inactive' : 'active'; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-<?php echo ($adminStatus === 'active') ? 'warning' : 'success'; ?> rounded-pill px-3">
                                                    <i class="bi bi-power me-1"></i> <?php echo ($adminStatus === 'active') ? __('deactivate', 'Deactivate') : __('activate', 'Activate'); ?>
                                                </button>
                                            </form>

                                            <!-- Delete Form -->
                                            <form action="manage_admins.php" method="POST" class="d-inline admin-action-form" onsubmit="return confirm('<?php echo htmlspecialchars(__('confirm_delete_admin', 'Are you sure you want to delete this admin account?'), ENT_QUOTES); ?>');">
                                                <input type="hidden" name="action" value="DELETE_ADMIN">
                                                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2.5" title="Delete Admin">
                                                    <i class="bi bi-trash-fill"></i>
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

    <!-- Create Admin Modal -->
    <div class="modal fade" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="manage_admins.php" method="POST" id="createAdminForm" class="admin-action-form">
                    <input type="hidden" name="action" value="ADD_ADMIN">
                    <div class="modal-header text-white" style="background-color: #0b4528;">
                        <h5 class="modal-title fw-bold" id="createAdminModalLabel">
                            <i class="bi bi-person-plus-fill me-2"></i><?php echo __('add_new_admin', 'Add New Admin'); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark fs-7"><?php echo __('full_name', 'Full Name'); ?></label>
                            <input type="text" name="name" class="form-control" placeholder="<?php echo __('placeholder_fullname', 'e.g. John Administrator'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark fs-7"><?php echo __('email_address', 'Email Address'); ?></label>
                            <input type="email" name="email" class="form-control" placeholder="<?php echo __('placeholder_email', 'e.g. admin@computerscience.lk'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark fs-7"><?php echo __('password', 'Password'); ?></label>
                            <input type="password" name="password" class="form-control" placeholder="<?php echo __('placeholder_password', 'Minimum 6 characters'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-dark fs-7"><?php echo __('account_status', 'Account Status'); ?></label>
                            <select name="status" class="form-select">
                                <option value="active"><?php echo __('active', 'Active'); ?></option>
                                <option value="inactive"><?php echo __('inactive', 'Inactive'); ?></option>
                                <option value="suspended"><?php echo __('suspended', 'Suspended'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal"><?php echo __('cancel', 'Cancel'); ?></button>
                        <button type="submit" class="btn btn-super-green btn-sm rounded-pill px-4"><?php echo __('create_admin_btn', 'Create Admin'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Admin Modals Container (Rendered outside table for valid DOM structure) -->
    <?php foreach ($admins as $admin): 
        $adminStatus = strtolower($admin['status'] ?? 'active');
    ?>
        <div class="modal fade" id="editAdminModal<?php echo $admin['id']; ?>" tabindex="-1" aria-labelledby="editAdminModalLabel<?php echo $admin['id']; ?>" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="manage_admins.php" method="POST" class="admin-action-form">
                        <input type="hidden" name="action" value="EDIT_ADMIN">
                        <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                        <div class="modal-header text-white" style="background-color: #0b4528;">
                            <h5 class="modal-title fw-bold" id="editAdminModalLabel<?php echo $admin['id']; ?>">
                                <i class="bi bi-pencil-square me-2"></i><?php echo __('edit_admin_account', 'Edit Admin Account'); ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body text-start">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark fs-7"><?php echo __('full_name', 'Full Name'); ?></label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark fs-7"><?php echo __('email_address', 'Email Address'); ?></label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark fs-7"><?php echo __('account_status', 'Account Status'); ?></label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo ($adminStatus === 'active') ? 'selected' : ''; ?>><?php echo __('active', 'Active'); ?></option>
                                    <option value="inactive" <?php echo ($adminStatus === 'inactive') ? 'selected' : ''; ?>><?php echo __('inactive', 'Inactive'); ?></option>
                                    <option value="suspended" <?php echo ($adminStatus === 'suspended') ? 'selected' : ''; ?>><?php echo __('suspended', 'Suspended'); ?></option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-dark fs-7"><?php echo __('reset_password_optional', 'Reset Password (Optional)'); ?></label>
                                <input type="password" name="new_password" class="form-control" placeholder="<?php echo __('placeholder_leave_blank', 'Leave blank to keep existing password'); ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm rounded-pill" data-bs-dismiss="modal"><?php echo __('cancel', 'Cancel'); ?></button>
                            <button type="submit" class="btn btn-super-green btn-sm rounded-pill px-4"><?php echo __('save_changes', 'Save Changes'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Local Bootstrap 5 Bundle JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>