<?php
// Unified Admin Sidebar Navigation Component
// Matches the exact styling, order, and badges of the Main Admin Dashboard (Teacher Requests page)
if (!isset($pdo)) {
    if (function_exists('getDBConnection')) {
        $pdo = getDBConnection();
    } else {
        require_once __DIR__ . '/../db/db_connect.php';
        $pdo = getDBConnection();
    }
}

$is_super_admin = isset($is_super_admin) ? $is_super_admin : (($_SESSION['user_role'] ?? $_SESSION['role'] ?? '') === 'super_admin');
$current_page = $active_nav ?? basename($_SERVER['PHP_SELF'], '.php');

// Fetch live pending counts
$pending_cert_count = 0;
$pending_teachers_count = 0;
$pending_courses_count = 0;
$pending_slips_count = 0;

try {
    $pending_cert_count = (int)$pdo->query("SELECT COUNT(*) FROM certificate_requests WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

try {
    $pending_teachers_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

try {
    $pending_courses_count = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

try {
    $pending_slips_count = (int)$pdo->query("SELECT COUNT(*) FROM student_courses WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {}

$sidebar_bg = $is_super_admin ? '#052014' : '#0b192c';
$sidebar_hover = $is_super_admin ? '#0e3b25' : '#162f52';
$sidebar_active = $is_super_admin ? '#0b4528' : '#0f4c81';
$sidebar_text = $is_super_admin ? '#a3cfbb' : '#94a3b8';
?>

<!-- Sidebar Unified Stylesheet -->
<style>
  :root {
    --sidebar-width: 260px;
    --sidebar-bg: <?php echo $sidebar_bg; ?>;
    --sidebar-hover: <?php echo $sidebar_hover; ?>;
    --sidebar-active: <?php echo $sidebar_active; ?>;
    --sidebar-text: <?php echo $sidebar_text; ?>;
  }

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
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(11, 69, 40, 0.4);
  }

  .nav-link-item i {
    font-size: 1.1rem;
  }

  .admin-main-wrapper {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    background-color: #f4f7fb;
  }

  .admin-topbar-nav {
    background-color: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 28px;
    position: sticky;
    top: 0;
    z-index: 1030;
  }

  @media (max-width: 991.98px) {
    .admin-main-wrapper {
      margin-left: 0 !important;
    }

    .admin-sidebar {
      transform: translateX(-100%);
    }

    .admin-sidebar.show-mobile {
      transform: translateX(0);
    }
  }
</style>

<!-- Left Navigation Sidebar Drawer -->
<aside class="admin-sidebar" id="admin-sidebar">
  <!-- Sidebar Header / Logo -->
  <div class="sidebar-header d-flex align-items-center justify-content-between">
    <a href="index.php" class="d-flex align-items-center gap-2 text-decoration-none text-white">
      <img src="../<?php echo function_exists('get_site_logo') ? get_site_logo() : 'assets/images/logo.png'; ?>?v=<?php echo time(); ?>" alt="Logo"
        style="height: 34px; width: auto; object-fit: contain;"
        onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=LMS&background=0f4c81&color=fff';">
      <div>
        <h6 class="fw-bold text-white mb-0 fs-7">Computerscience.lk</h6>
        <small class="text-warning fs-9 fw-bold"><?php echo $is_super_admin ? 'SUPER ADMIN PORTAL' : 'ADMIN PORTAL'; ?></small>
      </div>
    </a>
    <button class="btn btn-sm text-white-50 p-0 d-lg-none" id="close-sidebar-btn" type="button" aria-label="Close sidebar">
      <i class="bi bi-x-lg fs-5"></i>
    </button>
  </div>

  <!-- Sidebar Navigation Menu Items -->
  <div class="sidebar-menu">
    <div class="sidebar-section-title"><?php echo $is_super_admin ? 'Super Admin Main Menu' : 'Main Menu'; ?></div>

    <!-- Teacher Requests -->
    <a href="index.php?tab=teachers" class="nav-link-item <?php echo ($current_page === 'teachers') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-people-fill text-warning"></i>
        <span>Teacher Requests</span>
      </div>
      <?php if ($pending_teachers_count > 0): ?>
        <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo $pending_teachers_count; ?></span>
      <?php endif; ?>
    </a>

    <!-- Registered Students Directory -->
    <a href="students.php" class="nav-link-item <?php echo ($current_page === 'students') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-person-badge-fill text-info"></i>
        <span>Registered Students</span>
      </div>
    </a>

    <!-- Student Progress & Performance Analytics -->
    <a href="student_analytics.php" class="nav-link-item <?php echo ($current_page === 'student_analytics') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-graph-up-arrow text-success"></i>
        <span>Student Analytics</span>
      </div>
    </a>

    <!-- Course Certificate Management & Issuing -->
    <a href="certificates.php" class="nav-link-item <?php echo ($current_page === 'certificates') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-award-fill text-warning"></i>
        <span>Course Certificates</span>
      </div>
      <?php if ($pending_cert_count > 0): ?>
        <span class="badge bg-warning text-dark rounded-pill px-2 py-0.5 fs-9 fw-bold"><?php echo $pending_cert_count; ?></span>
      <?php endif; ?>
    </a>

    <!-- Course Approvals / Requests -->
    <a href="index.php?tab=courses" class="nav-link-item <?php echo ($current_page === 'courses') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-journal-check text-primary"></i>
        <span>Course Requests</span>
      </div>
      <?php if ($pending_courses_count > 0): ?>
        <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo $pending_courses_count; ?></span>
      <?php endif; ?>
    </a>

    <div class="sidebar-section-title mt-3">Financials</div>

    <!-- Bank Slip Approvals -->
    <a href="index.php?tab=bank" class="nav-link-item <?php echo ($current_page === 'bank') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-bank text-success"></i>
        <span>Bank Slip Approvals</span>
      </div>
      <?php if ($pending_slips_count > 0): ?>
        <span class="badge bg-danger rounded-pill px-2 py-0.5 fs-9"><?php echo $pending_slips_count; ?></span>
      <?php endif; ?>
    </a>

    <!-- Manage Bank Accounts -->
    <a href="index.php?tab=manage_bank" class="nav-link-item <?php echo ($current_page === 'manage_bank') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-bank2 text-info"></i>
        <span>Bank Accounts</span>
      </div>
    </a>

    <div class="sidebar-section-title mt-3">Content & Branding</div>

    <!-- Dropdown Options -->
    <a href="index.php?tab=options" class="nav-link-item <?php echo ($current_page === 'options') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-sliders text-primary"></i>
        <span>Dropdown Options</span>
      </div>
    </a>

    <!-- Site Announcements -->
    <a href="index.php?tab=announcements" class="nav-link-item <?php echo ($current_page === 'announcements') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-megaphone text-warning"></i>
        <span>Site Announcements</span>
      </div>
    </a>

    <!-- Hero Banner Settings -->
    <a href="index.php?tab=hero" class="nav-link-item <?php echo ($current_page === 'hero') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-layout-text-window-reverse text-success"></i>
        <span>Hero Banner</span>
      </div>
    </a>

    <!-- Certificate Delivery Note / COD Settings -->
    <a href="index.php?tab=delivery_note" class="nav-link-item <?php echo ($current_page === 'delivery_note') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-truck text-info"></i>
        <span>Certificate Delivery Note</span>
      </div>
    </a>

    <!-- Google OAuth / Sign-In Settings -->
    <a href="index.php?tab=google_auth" class="nav-link-item <?php echo ($current_page === 'google_auth') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-google text-warning"></i>
        <span>Google Sign-In</span>
      </div>
    </a>

    <!-- Site Logo & Favicon Customization -->
    <a href="index.php?tab=logo" class="nav-link-item <?php echo ($current_page === 'logo') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-palette-fill text-danger"></i>
        <span>Site Logo & Favicon</span>
      </div>
    </a>

    <?php if ($is_super_admin): ?>
      <div class="sidebar-section-title mt-3 text-success fw-bold">Super Admin</div>
      <a href="manage_admins.php"
        class="nav-link-item <?php echo ($current_page === 'manage_admins') ? 'active border border-success' : 'border border-success border-opacity-30 bg-success bg-opacity-10 text-white shadow-sm'; ?>">
        <div class="d-flex align-items-center gap-2.5">
          <i class="bi bi-shield-lock-fill text-success fs-5"></i>
          <span class="fw-bold text-white">Admin Management</span>
        </div>
        <span class="badge bg-success rounded-pill fs-9 px-2">PRO</span>
      </a>
    <?php endif; ?>

    <div class="sidebar-section-title mt-3">System & Security</div>

    <!-- Email / SMTP Settings -->
    <a href="email_settings.php" class="nav-link-item <?php echo ($current_page === 'email_settings') ? 'active' : ''; ?>">
      <div class="d-flex align-items-center gap-2.5">
        <i class="bi bi-envelope-gear-fill text-warning"></i>
        <span>Email & SMTP Settings</span>
      </div>
    </a>

    <!-- Change Admin Password -->
    <a href="index.php?tab=password" class="nav-link-item <?php echo ($current_page === 'password') ? 'active' : ''; ?>">
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

<!-- Mobile Sidebar Controller Script -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('admin-sidebar');
    const mobileToggleBtn = document.getElementById('mobile-sidebar-toggle');
    const closeSidebarBtn = document.getElementById('close-sidebar-btn');

    if (mobileToggleBtn && sidebar) {
      mobileToggleBtn.addEventListener('click', function () {
        sidebar.classList.toggle('show-mobile');
      });
    }

    if (closeSidebarBtn && sidebar) {
      closeSidebarBtn.addEventListener('click', function () {
        sidebar.classList.remove('show-mobile');
      });
    }
  });
</script>
