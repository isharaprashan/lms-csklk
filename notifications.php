<?php
/**
 * Notifications Center
 * Full-width management interface for all user notifications
 */

require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
require_once __DIR__ . '/includes/notification_helper.php';
init_lms_session();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?return=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? 'student';
$user_avatar = $_SESSION['user_avatar'] ?? '';
$is_teacher = ($user_role === 'teacher');
$is_admin = in_array($user_role, ['admin', 'super_admin']);

$pdo = getDBConnection();

// Fetch user info
$stmt = $pdo->prepare("SELECT name, email, avatar, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch() ?: ['name' => $user_name, 'email' => '', 'avatar' => $user_avatar, 'role' => $user_role];

// Fetch stats
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$stmtTotal->execute([$user_id]);
$total_count = (int)$stmtTotal->fetchColumn();

$stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtUnread->execute([$user_id]);
$unread_count = (int)$stmtUnread->fetchColumn();

// Fetch all notifications
$stmtAll = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmtAll->execute([$user_id]);
$raw_notifications = $stmtAll->fetchAll();

$formatted_notifications = [];
foreach ($raw_notifications as $notif) {
    $formatted_notifications[] = format_notification_data($notif);
}

// Fetch enrolled course IDs for sidebar drawer
$stmt = $pdo->prepare("SELECT course_id FROM enrollments WHERE user_id = ?");
$stmt->execute([$user_id]);
$enrolled_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch courses for sidebar
if ($is_admin) {
    $all_courses = $pdo->query("SELECT * FROM courses")->fetchAll();
} else {
    $stmtC = $pdo->prepare("SELECT * FROM courses WHERE ((status = 'approved' OR status = 'active') AND is_archived = 0) OR tutor_id = ?");
    $stmtC->execute([$user_id]);
    $all_courses = $stmtC->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('notifications_center', 'Notifications Center'); ?> | Computerscience.lk</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Local Bootstrap 5 CSS -->
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <!-- Local Bootstrap Icons CSS -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
  <!-- LMS Theme Styles -->
  <link rel="stylesheet" href="assets/css/theme.css">
  <!-- Notification System Styles -->
  <link rel="stylesheet" href="assets/css/notifications.css">

  <style>
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f8fafc;
      color: #1e293b;
    }
    .notif-center-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -2px rgba(0, 0, 0, 0.02);
    }
    .filter-pill {
      border: 1px solid #e2e8f0;
      background: #ffffff;
      color: #64748b;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .filter-pill:hover {
      background: #f1f5f9;
      color: #0f4c81;
    }
    .filter-pill.active {
      background: #0f4c81;
      border-color: #0f4c81;
      color: #ffffff;
      box-shadow: 0 2px 4px rgba(15, 76, 129, 0.2);
    }
    .center-notif-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 16px 20px;
      border-bottom: 1px solid #f1f5f9;
      transition: all 0.2s ease;
      background: #ffffff;
    }
    .center-notif-item:hover {
      background-color: #f8fafc;
    }
    .center-notif-item.unread {
      background-color: #f0f7ff;
      border-left: 4px solid #0f4c81;
    }
    .center-notif-item.unread:hover {
      background-color: #e5f0fc;
    }
  </style>
</head>
<body class="d-flex flex-column min-vh-100">

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Main Content Container -->
  <main class="flex-grow-1 py-4">
    <div class="container" style="max-width: 1000px;">
      
      <!-- Top Title & Stats Banner -->
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fs-9 fw-bold">
              <?php echo __('notifications_hub', 'Notifications Hub'); ?>
            </span>
            <span class="badge bg-danger rounded-pill px-2.5 py-1 fs-9 fw-bold" id="center-unread-badge" style="<?php echo $unread_count > 0 ? '' : 'display: none;'; ?>">
              <?php echo $unread_count; ?> <?php echo __('unread', 'Unread'); ?>
            </span>
          </div>
          <h2 class="fw-bold text-dark mb-0 fs-3 d-flex align-items-center gap-2">
            <i class="bi bi-bell-fill text-primary"></i>
            <span><?php echo __('notifications_center', 'Notifications Center'); ?></span>
          </h2>
        </div>

        <!-- Quick Bulk Actions -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1.5 fw-semibold d-flex align-items-center gap-1.5 shadow-sm" id="btn-center-mark-all">
            <i class="bi bi-check2-all"></i>
            <span><?php echo __('mark_all_read', 'Mark all as read'); ?></span>
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1.5 fw-semibold d-flex align-items-center gap-1.5" id="btn-center-clear-read">
            <i class="bi bi-trash3"></i>
            <span><?php echo __('clear_read_notifications', 'Clear Read'); ?></span>
          </button>
        </div>
      </div>

      <!-- Filter Controls & Search Box -->
      <div class="notif-center-card p-3 mb-3">
        <div class="row g-2 align-items-center">
          <!-- Search Input -->
          <div class="col-md-5">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control border-start-0 ps-0" id="notif-search-input" placeholder="<?php echo __('search_notifications', 'Search notifications by keyword...'); ?>">
            </div>
          </div>

          <!-- Category Filter Pills -->
          <div class="col-md-7">
            <div class="d-flex align-items-center gap-1.5 flex-wrap justify-content-md-end">
              <button type="button" class="filter-pill active" data-type="all">All</button>
              <button type="button" class="filter-pill" data-type="course"><i class="bi bi-mortarboard-fill text-primary"></i>Courses</button>
              <button type="button" class="filter-pill" data-type="certificate"><i class="bi bi-award-fill text-warning"></i>Certificates</button>
              <button type="button" class="filter-pill" data-type="payment"><i class="bi bi-credit-card-fill text-success"></i>Payments</button>
              <button type="button" class="filter-pill" data-type="qa"><i class="bi bi-chat-fill text-indigo"></i>Q&A</button>
            </div>
          </div>
        </div>

        <!-- Read Status Filter Row -->
        <div class="d-flex align-items-center gap-2 mt-3 pt-2 border-top fs-8 text-secondary">
          <span class="fw-semibold me-1">Status:</span>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input status-filter-radio" type="radio" name="statusFilter" id="filterStatusAll" value="all" checked>
            <label class="form-check-label cursor-pointer" for="filterStatusAll">All</label>
          </div>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input status-filter-radio" type="radio" name="statusFilter" id="filterStatusUnread" value="unread">
            <label class="form-check-label cursor-pointer" for="filterStatusUnread">Unread Only</label>
          </div>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input status-filter-radio" type="radio" name="statusFilter" id="filterStatusRead" value="read">
            <label class="form-check-label cursor-pointer" for="filterStatusRead">Read Only</label>
          </div>
        </div>
      </div>

      <!-- Notifications List Container -->
      <div class="notif-center-card overflow-hidden" id="center-notif-card-container">
        <div id="center-notif-items-list">
          <?php if (empty($formatted_notifications)): ?>
            <div class="text-center py-5 px-3">
              <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px;">
                <i class="bi bi-bell-slash text-muted fs-2"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1"><?php echo __('all_caught_up', "You're all caught up! 🎉"); ?></h5>
              <p class="text-muted fs-8 mb-0"><?php echo __('no_notifications_center', 'No notifications recorded in your hub.'); ?></p>
            </div>
          <?php else: ?>
            <?php foreach ($formatted_notifications as $notif): 
              $is_unread = ($notif['is_read'] == 0);
            ?>
              <div class="center-notif-item <?php echo $is_unread ? 'unread' : ''; ?>" 
                   data-id="<?php echo $notif['id']; ?>" 
                   data-type="<?php echo htmlspecialchars($notif['type']); ?>" 
                   data-read="<?php echo $notif['is_read']; ?>"
                   data-link="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>">
                
                <!-- Left: Icon + Content -->
                <div class="d-flex align-items-center gap-3 text-truncate me-2" style="min-width: 0;">
                  <div class="notif-icon-box" style="background-color: <?php echo $notif['bg']; ?>; color: <?php echo $notif['color']; ?>; width: 44px; height: 44px; font-size: 1.25rem;">
                    <i class="bi <?php echo $notif['icon']; ?>"></i>
                  </div>
                  <div class="d-flex flex-column text-truncate" style="min-width: 0;">
                    <div class="d-flex align-items-center gap-2 mb-0.5">
                      <span class="fw-bold text-dark fs-7 text-truncate notif-title-text"><?php echo htmlspecialchars($notif['title']); ?></span>
                      <?php if ($is_unread): ?>
                        <span class="badge bg-primary text-white rounded-pill px-2 py-0.5" style="font-size: 0.62rem;">New</span>
                      <?php endif; ?>
                    </div>
                    <div class="fs-8 text-secondary mb-1 text-truncate notif-msg-text"><?php echo htmlspecialchars($notif['message']); ?></div>
                    <div class="d-flex align-items-center gap-2 text-muted fs-9">
                      <span><i class="bi bi-clock me-1"></i><?php echo $notif['time_ago']; ?> (<?php echo date('M d, H:i', strtotime($notif['created_at'])); ?>)</span>
                      <span>&bull;</span>
                      <span class="badge" style="background-color: <?php echo $notif['bg']; ?>; color: <?php echo $notif['color']; ?>;"><?php echo htmlspecialchars($notif['badge']); ?></span>
                    </div>
                  </div>
                </div>

                <!-- Right: Action Buttons -->
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                  <?php if (!empty($notif['link']) && $notif['link'] !== '#'): ?>
                    <a href="<?php echo htmlspecialchars($notif['link']); ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fs-8 fw-semibold btn-visit-notif-link" data-id="<?php echo $notif['id']; ?>">
                      <i class="bi bi-box-arrow-up-right me-1"></i>View
                    </a>
                  <?php endif; ?>
                  <?php if ($is_unread): ?>
                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-2.5 py-1 fs-8 btn-mark-single-read" data-id="<?php echo $notif['id']; ?>" title="Mark as read">
                      <i class="bi bi-check2"></i>
                    </button>
                  <?php endif; ?>
                  <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-1 d-flex align-items-center justify-content-center btn-delete-single-notif" data-id="<?php echo $notif['id']; ?>" style="width: 32px; height: 32px;" title="Delete notification">
                    <i class="bi bi-trash3"></i>
                  </button>
                </div>

              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Filter Empty State (Hidden by default) -->
        <div id="filter-empty-state" class="text-center py-5 px-3 d-none">
          <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
            <i class="bi bi-funnel text-muted fs-3"></i>
          </div>
          <h6 class="fw-bold text-dark mb-1">No matching notifications found</h6>
          <p class="text-muted fs-8 mb-0">Try adjusting your keyword search or category filters.</p>
        </div>
      </div>

    </div>
  </main>

  <!-- Local Bootstrap Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <!-- Render JS Translation Dictionary -->
  <?php render_i18n_js(); ?>
  <!-- Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      let activeCategory = 'all';
      let activeStatus = 'all';
      let searchKeyword = '';

      const items = document.querySelectorAll('.center-notif-item');
      const emptyState = document.getElementById('filter-empty-state');
      const searchInput = document.getElementById('notif-search-input');
      const unreadBadge = document.getElementById('center-unread-badge');

      function filterItems() {
        let visibleCount = 0;
        items.forEach(item => {
          const type = item.getAttribute('data-type');
          const isRead = item.getAttribute('data-read');
          const title = (item.querySelector('.notif-title-text')?.textContent || '').toLowerCase();
          const msg = (item.querySelector('.notif-msg-text')?.textContent || '').toLowerCase();

          const matchesCat = (activeCategory === 'all' || type === activeCategory);
          const matchesStatus = (activeStatus === 'all' || (activeStatus === 'unread' && isRead === '0') || (activeStatus === 'read' && isRead === '1'));
          const matchesSearch = (!searchKeyword || title.includes(searchKeyword) || msg.includes(searchKeyword));

          if (matchesCat && matchesStatus && matchesSearch) {
            item.style.display = 'flex';
            visibleCount++;
          } else {
            item.style.display = 'none';
          }
        });

        if (emptyState) {
          emptyState.classList.toggle('d-none', visibleCount > 0 || items.length === 0);
        }
      }

      // Bind category filter pills
      document.querySelectorAll('.filter-pill').forEach(pill => {
        pill.addEventListener('click', function () {
          document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
          this.classList.add('active');
          activeCategory = this.getAttribute('data-type');
          filterItems();
        });
      });

      // Bind status radio filters
      document.querySelectorAll('.status-filter-radio').forEach(radio => {
        radio.addEventListener('change', function () {
          activeStatus = this.value;
          filterItems();
        });
      });

      // Bind search input
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          searchKeyword = this.value.trim().toLowerCase();
          filterItems();
        });
      }

      // Mark single notification read
      document.querySelectorAll('.btn-mark-single-read').forEach(btn => {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          const id = this.getAttribute('data-id');
          btn.disabled = true;

          fetch('api/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(id) })
          })
            .then(res => res.json())
            .then(data => {
              if (data.success) {
                const item = document.querySelector(`.center-notif-item[data-id="${id}"]`);
                if (item) {
                  item.classList.remove('unread');
                  item.setAttribute('data-read', '1');
                  const newBadge = item.querySelector('.badge.bg-primary');
                  if (newBadge) newBadge.remove();
                  btn.remove();
                }
                if (unreadBadge) {
                  unreadBadge.textContent = `${data.unread_count} Unread`;
                  unreadBadge.style.display = data.unread_count > 0 ? 'inline-block' : 'none';
                }
              }
            });
        });
      });

      // Delete single notification
      document.querySelectorAll('.btn-delete-single-notif').forEach(btn => {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          const id = this.getAttribute('data-id');
          if (!confirm('Are you sure you want to remove this notification?')) return;

          btn.disabled = true;
          fetch('api/delete_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(id) })
          })
            .then(res => res.json())
            .then(data => {
              if (data.success) {
                const item = document.querySelector(`.center-notif-item[data-id="${id}"]`);
                if (item) {
                  item.remove();
                  filterItems();
                }
                if (unreadBadge) {
                  unreadBadge.textContent = `${data.unread_count} Unread`;
                  unreadBadge.style.display = data.unread_count > 0 ? 'inline-block' : 'none';
                }
              }
            });
        });
      });

      // Mark All as Read button
      const btnMarkAll = document.getElementById('btn-center-mark-all');
      if (btnMarkAll) {
        btnMarkAll.addEventListener('click', function () {
          btnMarkAll.disabled = true;
          btnMarkAll.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';

          fetch('api/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_all' })
          })
            .then(res => res.json())
            .then(data => {
              btnMarkAll.disabled = false;
              btnMarkAll.innerHTML = '<i class="bi bi-check2-all me-1"></i>Mark all as read';
              if (data.success) {
                document.querySelectorAll('.center-notif-item').forEach(item => {
                  item.classList.remove('unread');
                  item.setAttribute('data-read', '1');
                  const newBadge = item.querySelector('.badge.bg-primary');
                  if (newBadge) newBadge.remove();
                });
                document.querySelectorAll('.btn-mark-single-read').forEach(b => b.remove());
                if (unreadBadge) unreadBadge.style.display = 'none';
              }
            });
        });
      }

      // Clear Read button
      const btnClearRead = document.getElementById('btn-center-clear-read');
      if (btnClearRead) {
        btnClearRead.addEventListener('click', function () {
          if (!confirm('Clear all read notifications from your center?')) return;
          btnClearRead.disabled = true;

          fetch('api/delete_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clear_read' })
          })
            .then(res => res.json())
            .then(data => {
              btnClearRead.disabled = false;
              if (data.success) {
                document.querySelectorAll('.center-notif-item[data-read="1"]').forEach(item => item.remove());
                filterItems();
              }
            });
        });
      }

    });
  </script>
</body>
</html>
