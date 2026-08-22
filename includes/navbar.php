<?php
/**
 * Unified Modern Navigation Bar Component
 * Used across all pages on Computerscience.lk LMS
 */

if (!defined('LMS_NAVBAR_INCLUDED')) {
    define('LMS_NAVBAR_INCLUDED', true);
}

// Resolve current session state
$nav_is_logged_in = isset($_SESSION['user_id']);
$nav_user_id = $_SESSION['user_id'] ?? null;
$nav_user_name = $_SESSION['user_name'] ?? ($student['name'] ?? ($user['name'] ?? ($current_user['name'] ?? 'User')));
$nav_user_email = $_SESSION['user_email'] ?? ($student['email'] ?? ($user['email'] ?? ($current_user['email'] ?? '')));
$nav_user_role = $_SESSION['user_role'] ?? ($student['role'] ?? ($user['role'] ?? ($current_user['role'] ?? 'student')));
$nav_user_avatar = $_SESSION['user_avatar'] ?? ($student['avatar'] ?? ($user['avatar'] ?? ($current_user['avatar'] ?? '')));

$nav_is_teacher = in_array($nav_user_role, ['teacher', 'admin', 'super_admin']);
$nav_is_admin = in_array($nav_user_role, ['admin', 'super_admin']);

// Resolve avatar URL
$nav_avatar_src = function_exists('get_user_avatar') ? get_user_avatar($nav_user_avatar, $nav_user_name) : 'assets/avatar.png';

// Active page detection
$nav_current_page = basename($_SERVER['PHP_SELF'] ?? '');

// Unread notifications count
$nav_unread_count = isset($unread_count) ? (int)$unread_count : 0;
if ($nav_is_logged_in && !isset($unread_count)) {
    try {
        if (function_exists('getDBConnection')) {
            $nav_pdo = getDBConnection();
            $stmtNotif = $nav_pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmtNotif->execute([$nav_user_id]);
            $nav_unread_count = (int)$stmtNotif->fetchColumn();
        }
    } catch (Exception $e) {
        $nav_unread_count = 0;
    }
}
?>

<!-- LMS Unified Top Header Bar -->
<header class="moodle-header px-3 px-md-4 shadow-sm bg-white sticky-top border-bottom" style="z-index: 1040;">
  <div class="d-flex align-items-center w-100 justify-content-between">

    <!-- Left: Drawer Toggle (if on dashboard/classroom) + Site Brand -->
    <div class="d-flex align-items-center gap-2 gap-md-3">
      <?php if (in_array($nav_current_page, ['index.php', 'dashboard.php', 'classroom.php', 'watch_lesson.php', 'my_courses.php', 'profile.php'])): ?>
        <button id="drawer-toggle"
          class="btn btn-light border-0 rounded-circle p-2 fs-5 d-flex align-items-center justify-content-center transition-all hover-shadow"
          style="width: 40px; height: 40px;" title="Toggle Navigation Sidebar" aria-label="Toggle Navigation Sidebar">
          <i class="bi bi-list fs-4 text-secondary" id="drawer-toggle-icon"></i>
        </button>
      <?php endif; ?>
      <a class="moodle-brand fw-bold text-decoration-none fs-4 d-flex align-items-center" href="<?php echo $nav_is_logged_in ? 'dashboard.php' : 'index.php'; ?>"
        style="color: #0f4c81;">
        <img src="<?php echo function_exists('get_site_logo') ? get_site_logo() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>" alt="Logo" class="me-2"
          style="height: 32px; width: auto; object-fit: contain;">
        <span class="d-none d-sm-inline">computerscience<span class="text-secondary">.lk</span></span>
      </a>
    </div>

    <!-- Center: Main Navigation Links -->
    <nav class="d-none d-lg-flex align-items-center gap-1.5">
      <a href="index.php"
        class="btn btn-sm px-3 rounded-pill transition-all <?php echo ($nav_current_page === 'index.php') ? 'btn-primary text-white fw-bold shadow-sm' : 'btn-light text-secondary fw-semibold'; ?>"
        style="<?php echo ($nav_current_page === 'index.php') ? 'background-color: #0f4c81; border-color: #0f4c81;' : ''; ?>">
        <i class="bi bi-house-door me-1"></i><?php echo function_exists('__') ? __('nav_home', 'Site Home') : 'Site Home'; ?>
      </a>
      
      <?php if ($nav_is_logged_in): ?>
        <a href="dashboard.php"
          class="btn btn-sm px-3 rounded-pill transition-all <?php echo ($nav_current_page === 'dashboard.php') ? 'btn-primary text-white fw-bold shadow-sm' : 'btn-light text-secondary fw-semibold'; ?>"
          style="<?php echo ($nav_current_page === 'dashboard.php') ? 'background-color: #0f4c81; border-color: #0f4c81;' : ''; ?>">
          <i class="bi bi-speedometer2 me-1"></i><?php echo function_exists('__') ? __('nav_dashboard', 'Dashboard') : 'Dashboard'; ?>
        </a>

        <a href="my_courses.php"
          class="btn btn-sm px-3 rounded-pill transition-all <?php echo ($nav_current_page === 'my_courses.php') ? 'btn-primary text-white fw-bold shadow-sm' : 'btn-light text-secondary fw-semibold'; ?>"
          style="<?php echo ($nav_current_page === 'my_courses.php') ? 'background-color: #0f4c81; border-color: #0f4c81;' : ''; ?>">
          <i class="bi bi-journal-bookmark me-1"></i><?php echo $nav_is_teacher ? (function_exists('__') ? __('nav_uploaded_courses', 'Uploaded Courses') : 'Uploaded Courses') : (function_exists('__') ? __('nav_my_courses', 'My Courses') : 'My Courses'); ?>
        </a>

        <a href="live_classes.php"
          class="btn btn-sm px-3 rounded-pill transition-all <?php echo ($nav_current_page === 'live_classes.php') ? 'btn-danger text-white fw-bold shadow-sm' : 'btn-light text-danger fw-semibold'; ?> d-inline-flex align-items-center gap-1.5 border border-danger border-opacity-25 bg-danger bg-opacity-10">
          <i class="bi bi-broadcast text-danger fs-7"></i>
          <span>Live Classes</span>
        </a>
      <?php else: ?>
        <a href="index.php#courses-section"
          class="btn btn-sm btn-light text-secondary fw-semibold px-3 rounded-pill">
          <i class="bi bi-collection-play me-1"></i><?php echo function_exists('__') ? __('nav_courses', 'Course Catalog') : 'Course Catalog'; ?>
        </a>
      <?php endif; ?>
    </nav>

    <!-- Right: Actions, Language Switcher, Notifications, Profile Menu -->
    <div class="d-flex align-items-center gap-2">
      <!-- Language Switcher Dropdown -->
      <div class="dropdown">
        <button
          class="btn btn-sm btn-light border text-secondary dropdown-toggle d-flex align-items-center gap-1.5 rounded-pill px-2.5 py-1"
          type="button" id="navLangDropdown" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-globe text-primary fs-7"></i>
          <span class="fw-semibold fs-8"><?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'සිංහල' : 'English'; ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-1" aria-labelledby="navLangDropdown">
          <li>
            <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'en') ? 'active fw-bold' : ''; ?>"
              href="#" onclick="if(typeof switchLanguage === 'function'){switchLanguage('en');}else{window.location.href='api/set_language.php?lang=en';} return false;">
              <span>English</span>
              <?php if (($_SESSION['lang'] ?? 'en') === 'en'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
            </a>
          </li>
          <li>
            <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'active fw-bold' : ''; ?>"
              href="#" onclick="if(typeof switchLanguage === 'function'){switchLanguage('si');}else{window.location.href='api/set_language.php?lang=si';} return false;">
              <span>සිංහල</span>
              <?php if (($_SESSION['lang'] ?? 'en') === 'si'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
            </a>
          </li>
        </ul>
      </div>

      <?php if ($nav_is_logged_in): ?>
        <!-- Modern Notification Dropdown Component -->
        <?php 
          $unread_count = $nav_unread_count;
          include __DIR__ . '/notification_dropdown.php'; 
        ?>

        <!-- User Profile Dropdown Menu -->
        <div class="dropdown">
          <button class="btn btn-light rounded-pill p-1 border dropdown-toggle d-flex align-items-center gap-2 transition-all hover-shadow-sm"
            type="button" id="navUserMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?php echo htmlspecialchars($nav_avatar_src); ?>" class="rounded-circle border"
              style="width: 32px; height: 32px; object-fit: cover;" alt="Profile Avatar">
            <span class="d-none d-md-inline text-secondary fw-semibold fs-8 pe-1"><?php echo htmlspecialchars(explode(' ', $nav_user_name)[0]); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-light py-2" aria-labelledby="navUserMenuDropdown" style="min-width: 220px; border-radius: 14px;">
            <li class="px-3 py-2 border-bottom mb-1 bg-light bg-opacity-50">
              <div class="fw-bold text-dark fs-8 text-truncate"><?php echo htmlspecialchars($nav_user_name); ?></div>
              <div class="d-flex align-items-center justify-content-between mt-0.5">
                <small class="text-muted fs-9 text-truncate"><?php echo htmlspecialchars($nav_user_email); ?></small>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5 text-uppercase" style="font-size: 0.6rem;">
                  <?php echo htmlspecialchars($nav_user_role); ?>
                </span>
              </div>
            </li>
            <li><a class="dropdown-item fs-8 py-2" href="dashboard.php"><i class="bi bi-speedometer2 me-2 text-primary"></i><?php echo function_exists('__') ? __('nav_dashboard', 'Dashboard') : 'Dashboard'; ?></a></li>
            <li><a class="dropdown-item fs-8 py-2" href="profile.php"><i class="bi bi-person-circle me-2 text-primary"></i><?php echo function_exists('__') ? __('nav_profile', 'Profile & Settings') : 'Profile & Settings'; ?></a></li>
            <li><a class="dropdown-item fs-8 py-2" href="my_courses.php"><i class="bi bi-journal-bookmark me-2 text-primary"></i><?php echo $nav_is_teacher ? 'Uploaded Syllabus Modules' : (function_exists('__') ? __('nav_my_courses', 'My Courses') : 'My Courses'); ?></a></li>
            <li><a class="dropdown-item fs-8 py-2" href="notifications.php"><i class="bi bi-bell me-2 text-primary"></i><?php echo function_exists('__') ? __('notifications_center', 'Notifications Hub') : 'Notifications Hub'; ?></a></li>
            
            <?php if ($nav_is_admin): ?>
              <li><hr class="dropdown-divider my-1"></li>
              <li><a class="dropdown-item fs-8 py-2 text-danger fw-semibold" href="admin/index.php"><i class="bi bi-shield-lock-fill me-2"></i>Admin Console</a></li>
            <?php endif; ?>

            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item text-danger fs-8 py-2 fw-semibold" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i><?php echo function_exists('__') ? __('nav_logout', 'Log Out') : 'Log Out'; ?></a></li>
          </ul>
        </div>
      <?php else: ?>
        <!-- Guest Login / Register Buttons -->
        <a href="login.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1 fw-semibold fs-8">
          <i class="bi bi-box-arrow-in-right me-1"></i><?php echo function_exists('__') ? __('login', 'Log In') : 'Log In'; ?>
        </a>
        <a href="register.php" class="btn btn-primary btn-sm rounded-pill px-3 py-1 fw-bold fs-8 text-white shadow-sm" style="background-color: #0f4c81; border-color: #0f4c81;">
          <?php echo function_exists('__') ? __('register', 'Register') : 'Register'; ?>
        </a>
      <?php endif; ?>
    </div>

  </div>
</header>

<!-- Moodle Drawer Mobile Overlay Backdrop -->
<div id="moodle-drawer-backdrop" class="moodle-drawer-backdrop"></div>

<script>
(function() {
  function setupDrawer() {
    const toggleBtn = document.getElementById('drawer-toggle');
    const toggleIcon = document.getElementById('drawer-toggle-icon');
    const drawer = document.getElementById('moodle-drawer');
    const wrapper = document.getElementById('moodle-content-wrapper');
    const backdrop = document.getElementById('moodle-drawer-backdrop');

    if (!drawer) return;

    function setDrawerState(isOpen) {
      if (isOpen) {
        drawer.classList.remove('collapsed');
        if (wrapper) wrapper.classList.remove('full-width');
        if (backdrop) backdrop.classList.add('show');
        if (toggleIcon) {
          toggleIcon.className = 'bi bi-x-lg fs-5 text-danger';
        }
        if (toggleBtn) {
          toggleBtn.classList.add('bg-danger', 'bg-opacity-10', 'text-danger');
          toggleBtn.classList.remove('btn-light');
          toggleBtn.setAttribute('title', 'Close Navigation Sidebar');
        }
      } else {
        drawer.classList.add('collapsed');
        if (wrapper) wrapper.classList.add('full-width');
        if (backdrop) backdrop.classList.remove('show');
        if (toggleIcon) {
          toggleIcon.className = 'bi bi-list fs-4 text-secondary';
        }
        if (toggleBtn) {
          toggleBtn.classList.remove('bg-danger', 'bg-opacity-10', 'text-danger');
          toggleBtn.classList.add('btn-light');
          toggleBtn.setAttribute('title', 'Open Navigation Sidebar');
        }
      }
    }

    if (toggleBtn) {
      toggleBtn.onclick = function(e) {
        e.preventDefault();
        e.stopPropagation();
        const willOpen = drawer.classList.contains('collapsed');
        setDrawerState(willOpen);
      };
    }

    // Delegate close buttons inside drawer
    document.addEventListener('click', function(e) {
      const closeBtn = e.target.closest('.drawer-close-trigger') || e.target.closest('#drawer-close-btn');
      if (closeBtn) {
        e.preventDefault();
        setDrawerState(false);
      }
    });

    if (backdrop) {
      backdrop.onclick = function() {
        setDrawerState(false);
      };
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupDrawer);
  } else {
    setupDrawer();
  }
})();
</script>
