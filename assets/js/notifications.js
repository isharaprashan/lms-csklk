/**
 * LMS Modern Notification System JavaScript Client
 * Real-time AJAX updates, interactive dropdown, audio feedback, and Toast alerts
 */

(function () {
  'use strict';

  let currentTab = 'all';
  let cachedNotifications = [];
  let pollTimer = null;
  let lastKnownUnreadCount = -1;

  // Gentle audio chime using Web Audio API
  function playNotificationChime() {
    try {
      if (localStorage.getItem('lms_mute_notifications') === 'true') return;

      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();

      const now = ctx.currentTime;
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, now); // D5
      osc.frequency.exponentialRampToValueAtTime(880, now + 0.12); // A5

      gain.gain.setValueAtTime(0.08, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.35);
    } catch (e) {
      // Audio context might be restricted before user gesture
    }
  }

  // Toast Notification Engine
  function showNotificationToast(title, message, iconCls = 'bi-bell-fill', color = '#0f4c81', link = null) {
    let container = document.getElementById('lms-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'lms-toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'lms-live-toast';
    toast.innerHTML = `
      <div style="width: 36px; height: 36px; border-radius: 10px; background-color: ${color}15; color: ${color}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i class="bi ${iconCls} fs-5"></i>
      </div>
      <div style="flex: 1; min-width: 0;">
        <div style="font-size: 0.8rem; font-weight: 700; color: #1e293b; margin-bottom: 2px;">${title}</div>
        <div style="font-size: 0.75rem; color: #475569; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${message}</div>
      </div>
      <button type="button" class="btn-close" style="font-size: 0.7rem; margin-left: 4px;" aria-label="Close"></button>
    `;

    if (link && link !== '#') {
      toast.style.cursor = 'pointer';
      toast.addEventListener('click', function (e) {
        if (!e.target.closest('.btn-close')) {
          window.location.href = link;
        }
      });
    }

    const closeBtn = toast.querySelector('.btn-close');
    closeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toast.classList.add('toast-hiding');
      setTimeout(() => toast.remove(), 300);
    });

    container.appendChild(toast);

    // Auto dismiss after 5 seconds
    setTimeout(() => {
      if (toast && toast.parentNode) {
        toast.classList.add('toast-hiding');
        setTimeout(() => toast.remove(), 300);
      }
    }, 5000);
  }

  // Update Navbar Bell Badge
  function updateBadgeDisplay(unreadCount) {
    const badge = document.getElementById('notification-badge');
    const headerCount = document.getElementById('dropdown-unread-count');
    const unreadTabBtn = document.getElementById('notif-tab-unread');

    if (badge) {
      if (unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.style.display = 'inline-flex';
      } else {
        badge.style.display = 'none';
      }
    }

    if (headerCount) {
      headerCount.textContent = unreadCount;
      headerCount.style.display = unreadCount > 0 ? 'inline-block' : 'none';
    }

    if (unreadTabBtn) {
      unreadTabBtn.textContent = `Unread (${unreadCount})`;
    }
  }

  // Render Notifications inside Dropdown
  function renderDropdownItems(notifications, filter = 'all') {
    const listEl = document.getElementById('dropdown-notif-list');
    if (!listEl) return;

    let items = notifications;
    if (filter === 'unread') {
      items = notifications.filter(n => n.is_read == 0);
    }

    if (!items || items.length === 0) {
      const emptyMsg = filter === 'unread' ? "You're all caught up! No unread notifications." : "No notifications yet.";
      listEl.innerHTML = `
        <div class="text-center py-4 px-3">
          <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
            <i class="bi bi-bell-slash text-muted fs-4"></i>
          </div>
          <p class="text-muted fs-8 mb-0">${emptyMsg}</p>
        </div>
      `;
      return;
    }

    let html = '';
    items.forEach(n => {
      const isUnread = (n.is_read == 0);
      html += `
        <div class="notif-card-item ${isUnread ? 'unread' : ''}" data-id="${n.id}" data-link="${n.link || '#'}">
          <div class="notif-icon-box" style="background-color: ${n.bg}; color: ${n.color};">
            <i class="bi ${n.icon}"></i>
          </div>
          <div class="notif-content-body">
            <div class="notif-item-title">
              <span>${n.title}</span>
              ${isUnread ? '<span class="notif-unread-dot"></span>' : ''}
            </div>
            <div class="notif-item-msg">${n.message}</div>
            <div class="notif-item-meta">
              <span><i class="bi bi-clock me-1"></i>${n.time_ago}</span>
              <span class="badge" style="background-color: ${n.bg}; color: ${n.color}; font-size: 0.62rem; padding: 2px 6px;">${n.badge}</span>
            </div>
          </div>
        </div>
      `;
    });

    listEl.innerHTML = html;

    // Bind item clicks
    listEl.querySelectorAll('.notif-card-item').forEach(card => {
      card.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');
        const link = this.getAttribute('data-link');

        // Mark as read via AJAX
        fetch('api/mark_notification_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: parseInt(id) })
        })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              updateBadgeDisplay(data.unread_count);
            }
          })
          .catch(() => {});

        // Redirect if link provided
        if (link && link !== '#') {
          window.location.href = link;
        }
      });
    });
  }

  // Fetch Live Notifications from Server
  function fetchNotifications(notifyIfNew = false) {
    fetch('api/get_notifications.php?limit=15')
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          cachedNotifications = data.notifications || [];
          const newUnread = data.unread_count;

          // Detect new notification arrival
          if (notifyIfNew && lastKnownUnreadCount !== -1 && newUnread > lastKnownUnreadCount) {
            playNotificationChime();
            const latest = cachedNotifications[0];
            if (latest) {
              showNotificationToast(latest.title, latest.message, latest.icon, latest.color, latest.link);
            }
          }

          lastKnownUnreadCount = newUnread;
          updateBadgeDisplay(newUnread);
          renderDropdownItems(cachedNotifications, currentTab);
        }
      })
      .catch(err => console.debug('Notifications sync error:', err));
  }

  // Mark all notifications as read
  function markAllNotificationsRead() {
    const btn = document.getElementById('btn-mark-all-read');
    if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch('api/mark_notification_read.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_all' })
    })
      .then(res => res.json())
      .then(data => {
        if (btn) btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Mark all as read';
        if (data.success) {
          cachedNotifications.forEach(n => n.is_read = 1);
          updateBadgeDisplay(0);
          renderDropdownItems(cachedNotifications, currentTab);
        }
      })
      .catch(() => {
        if (btn) btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Mark all as read';
      });
  }

  // Initialization
  document.addEventListener('DOMContentLoaded', function () {
    // Initial fetch
    fetchNotifications(false);

    // Setup polling every 25 seconds
    pollTimer = setInterval(() => {
      fetchNotifications(true);
    }, 25000);

    // Filter tabs in dropdown
    const tabAll = document.getElementById('notif-tab-all');
    const tabUnread = document.getElementById('notif-tab-unread');

    if (tabAll && tabUnread) {
      tabAll.addEventListener('click', function (e) {
        e.preventDefault();
        currentTab = 'all';
        tabAll.classList.add('active');
        tabUnread.classList.remove('active');
        renderDropdownItems(cachedNotifications, 'all');
      });

      tabUnread.addEventListener('click', function (e) {
        e.preventDefault();
        currentTab = 'unread';
        tabUnread.classList.add('active');
        tabAll.classList.remove('active');
        renderDropdownItems(cachedNotifications, 'unread');
      });
    }

    // Mark all as read button
    const markAllBtn = document.getElementById('btn-mark-all-read');
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        markAllNotificationsRead();
      });
    }
  });

  // Global window helpers
  window.LMSNotifications = {
    fetch: fetchNotifications,
    markAllRead: markAllNotificationsRead,
    showToast: showNotificationToast,
    playChime: playNotificationChime
  };

  // Legacy fallback support
  window.markNotificationsAsRead = markAllNotificationsRead;

})();
