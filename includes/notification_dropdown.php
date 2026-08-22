<?php
/**
 * Modern Interactive Notification Dropdown Component
 * Reusable across all navigation headers in Student, Teacher & Admin dashboards
 */
$dd_unread = isset($unread_count) ? (int)$unread_count : 0;
?>
<div class="dropdown">
  <button class="notif-bell-btn border-0 bg-transparent p-0 position-relative dropdown-toggle no-caret"
    type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('notifications', 'Notifications'); ?>">
    <i class="bi bi-bell fs-5"></i>
    <span class="notif-badge-count" id="notification-badge" style="<?php echo ($dd_unread > 0) ? 'display: inline-flex;' : 'display: none;'; ?>">
      <?php echo ($dd_unread > 99) ? '99+' : $dd_unread; ?>
    </span>
  </button>
  
  <div class="dropdown-menu dropdown-menu-end notif-dropdown-menu" aria-labelledby="notificationDropdown">
    <!-- Dropdown Header -->
    <div class="notif-dropdown-header d-flex flex-column gap-2">
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-1.5">
          <span class="fw-bold text-dark fs-7"><?php echo __('notifications', 'Notifications'); ?></span>
          <span class="badge bg-danger rounded-pill px-2 py-0.5" id="dropdown-unread-count" style="font-size: 0.65rem; <?php echo ($dd_unread > 0) ? '' : 'display: none;'; ?>">
            <?php echo $dd_unread; ?>
          </span>
        </div>
        <button type="button" class="btn btn-link p-0 text-primary fs-9 fw-semibold text-decoration-none d-flex align-items-center gap-1" id="btn-mark-all-read">
          <i class="bi bi-check2-all"></i>
          <span><?php echo __('mark_all_read', 'Mark all as read'); ?></span>
        </button>
      </div>

      <!-- Filter Tabs -->
      <div class="d-flex align-items-center gap-1 p-1 bg-light rounded-pill">
        <button type="button" class="notif-tab-btn flex-grow-1 active" id="notif-tab-all">All</button>
        <button type="button" class="notif-tab-btn flex-grow-1" id="notif-tab-unread">Unread (<?php echo $dd_unread; ?>)</button>
      </div>
    </div>

    <!-- Scrollable Notification Items List -->
    <div class="notif-list-container" id="dropdown-notif-list">
      <div class="text-center py-4 text-muted fs-8">
        <span class="spinner-border spinner-border-sm me-1"></span> Loading notifications...
      </div>
    </div>

    <!-- Dropdown Footer -->
    <div class="notif-dropdown-footer">
      <a href="notifications.php" class="btn btn-sm btn-light border w-100 rounded-pill py-1.5 fs-8 fw-semibold text-secondary d-flex align-items-center justify-content-center gap-1.5">
        <span><?php echo __('view_all_notifications', 'View All in Notifications Center'); ?></span>
        <i class="bi bi-arrow-right fs-9"></i>
      </a>
    </div>
  </div>
</div>
