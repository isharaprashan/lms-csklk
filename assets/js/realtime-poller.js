/**
 * LMS Real-Time Polling Engine (Heartbeat)
 * Centralized background poller for Admin, Teacher, and Student panels.
 * Automatically synchronizes notifications, badges, table updates, and toasts without page refreshes.
 */

(function () {
  'use strict';

  // Configuration
  const POLL_INTERVAL = 8000; // 8 seconds
  let pollTimer = null;
  let isFetching = false;
  let isTabVisible = !document.hidden;
  let currentNotifFilter = 'all';
  let cachedNotifications = [];

  // State Tracking
  let lastUnreadCount = -1;
  let lastAdminStats = null;
  let lastTeacherStats = null;
  let lastStudentStats = null;

  // Resolve API Endpoint URL based on current page path
  function getEndpointUrl() {
    const pathname = window.location.pathname.toLowerCase();
    if (pathname.includes('/admin/')) {
      return '../ajax/fetch_realtime_updates.php';
    }
    return 'ajax/fetch_realtime_updates.php';
  }

  function getMarkReadEndpoint() {
    const pathname = window.location.pathname.toLowerCase();
    if (pathname.includes('/admin/')) {
      return '../api/mark_notification_read.php';
    }
    return 'api/mark_notification_read.php';
  }

  // Web Audio API Chime
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
      // Audio autoplay policy handled gracefully
    }
  }

  // Toast Notification UI Component
  function showLiveToast(title, message, iconCls = 'bi-bell-fill', color = '#0f4c81', link = null) {
    let container = document.getElementById('lms-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'lms-toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'lms-live-toast';
    toast.innerHTML = `
      <div style="width: 38px; height: 38px; border-radius: 12px; background-color: ${color}15; color: ${color}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i class="bi ${iconCls} fs-5"></i>
      </div>
      <div style="flex: 1; min-width: 0;">
        <div style="font-size: 0.82rem; font-weight: 700; color: #1e293b; margin-bottom: 2px;">${escapeHtml(title)}</div>
        <div style="font-size: 0.75rem; color: #475569; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${escapeHtml(message)}</div>
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

    // Auto dismiss after 6 seconds
    setTimeout(() => {
      if (toast && toast.parentNode) {
        toast.classList.add('toast-hiding');
        setTimeout(() => toast.remove(), 300);
      }
    }, 6000);
  }

  // Floating Table Update Banner (e.g. for Admin Tables)
  function showFloatingTableAlert(message, onClick) {
    let alertEl = document.getElementById('lms-floating-table-banner');
    if (!alertEl) {
      alertEl = document.createElement('div');
      alertEl.id = 'lms-floating-table-banner';
      alertEl.className = 'lms-floating-banner shadow-lg';
      document.body.appendChild(alertEl);
    }

    alertEl.innerHTML = `
      <div class="d-flex align-items-center justify-content-between gap-3 px-3 py-2">
        <div class="d-flex align-items-center gap-2 text-white fs-8 fw-semibold">
          <i class="bi bi-arrow-repeat spin-slow text-warning fs-6"></i>
          <span>${escapeHtml(message)}</span>
        </div>
        <div class="d-flex align-items-center gap-1.5">
          <button type="button" class="btn btn-warning btn-sm rounded-pill px-3 py-0.5 fw-bold fs-9 text-dark shadow-xs" id="btn-banner-refresh">
            Refresh View
          </button>
          <button type="button" class="btn-close btn-close-white fs-9" id="btn-banner-close" aria-label="Close"></button>
        </div>
      </div>
    `;
    alertEl.style.display = 'block';

    const refreshBtn = alertEl.querySelector('#btn-banner-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        if (typeof onClick === 'function') onClick();
        else window.location.reload();
      });
    }

    const closeBtn = alertEl.querySelector('#btn-banner-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        alertEl.style.display = 'none';
      });
    }
  }

  // Helper: Escape HTML
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  // Synchronize Notification Dropdown UI & Bell Badges
  function syncNotificationBadges(unreadCount) {
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

  // Render Dropdown List Items
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
        <div class="notif-card-item ${isUnread ? 'unread' : ''}" data-id="${n.id}" data-link="${escapeHtml(n.link || '#')}">
          <div class="notif-icon-box" style="background-color: ${n.bg}; color: ${n.color};">
            <i class="bi ${n.icon}"></i>
          </div>
          <div class="notif-content-body">
            <div class="notif-item-title">
              <span>${escapeHtml(n.title)}</span>
              ${isUnread ? '<span class="notif-unread-dot"></span>' : ''}
            </div>
            <div class="notif-item-msg">${escapeHtml(n.message)}</div>
            <div class="notif-item-meta">
              <span><i class="bi bi-clock me-1"></i>${escapeHtml(n.time_ago)}</span>
              <span class="badge" style="background-color: ${n.bg}; color: ${n.color}; font-size: 0.62rem; padding: 2px 6px;">${escapeHtml(n.badge)}</span>
            </div>
          </div>
        </div>
      `;
    });

    listEl.innerHTML = html;

    // Bind individual card clicks
    listEl.querySelectorAll('.notif-card-item').forEach(card => {
      card.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');
        const link = this.getAttribute('data-link');

        // Mark as read via AJAX
        fetch(getMarkReadEndpoint(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: parseInt(id) })
        })
          .then(res => res.json())
          .then(data => {
            if (data && data.success) {
              syncNotificationBadges(data.unread_count);
            }
          })
          .catch(() => {});

        if (link && link !== '#') {
          window.location.href = link;
        }
      });
    });
  }

  // Update Badge Element Helper
  function updateSidebarBadge(elemId, count, badgeClass = 'bg-danger') {
    const el = document.getElementById(elemId);
    if (!el) return;
    if (count > 0) {
      el.textContent = count > 99 ? '99+' : count;
      el.style.display = 'inline-block';
      el.className = `badge ${badgeClass} rounded-pill px-2 py-0.5 fs-9`;
    } else {
      el.textContent = '0';
      el.style.display = 'none';
    }
  }

  // Main Heartbeat Dispatcher
  function performHeartbeatPoll() {
    if (isFetching) return;
    if (!isTabVisible) return; // Tab inactivity optimization

    isFetching = true;
    const url = getEndpointUrl() + '?t=' + Date.now();

    fetch(url, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(res => {
        if (!res.ok) throw new Error('Network status: ' + res.status);
        return res.json();
      })
      .then(data => {
        isFetching = false;
        // Handle immediate forced logout if account was deactivated by admin
        if (data && (data.account_inactive || (data.logged_in === false && lastUnreadCount !== -1 && !window.location.pathname.toLowerCase().includes('login.php')))) {
          if (data.account_inactive) {
            const isInsideAdmin = window.location.pathname.toLowerCase().includes('/admin/');
            window.location.href = (isInsideAdmin ? '../' : '') + 'login.php?error=account_inactive';
            return;
          }
        }

        if (!data || !data.success || !data.logged_in) return;

        // 1. Synchronize Notifications & Play Chime
        const unreadCount = data.unread_notifications_count ?? 0;
        cachedNotifications = data.notifications || [];

        if (lastUnreadCount !== -1 && unreadCount > lastUnreadCount) {
          playNotificationChime();
          const latest = cachedNotifications[0];
          if (latest) {
            showLiveToast(latest.title, latest.message, latest.icon, latest.color, latest.link);
          }
        }

        lastUnreadCount = unreadCount;
        syncNotificationBadges(unreadCount);
        renderDropdownItems(cachedNotifications, currentNotifFilter);

        // 2. Synchronize Admin Stats & Badges
        if (data.admin) {
          const adm = data.admin;
          updateSidebarBadge('badge-pending-teachers', adm.pending_teachers_count, 'bg-danger');
          updateSidebarBadge('badge-pending-courses', adm.pending_courses_count, 'bg-danger');
          updateSidebarBadge('badge-pending-certificates', adm.pending_certificates_count, 'bg-warning text-dark fw-bold');
          updateSidebarBadge('badge-pending-slips', adm.pending_slips_count, 'bg-danger');

          // Check if new pending requests arrived while viewing admin pages
          if (lastAdminStats) {
            if (adm.pending_courses_count > lastAdminStats.pending_courses_count) {
              showFloatingTableAlert('New Course Submission requests waiting for approval.', () => {
                window.location.href = 'index.php?tab=courses';
              });
            } else if (adm.pending_certificates_count > lastAdminStats.pending_certificates_count) {
              showFloatingTableAlert('New Course Certificate requests submitted by students.', () => {
                window.location.href = 'certificates.php';
              });
            } else if (adm.pending_teachers_count > lastAdminStats.pending_teachers_count) {
              showFloatingTableAlert('New Teacher Verification requests received.', () => {
                window.location.href = 'index.php?tab=teachers';
              });
            }
          }
          lastAdminStats = adm;
        }

        // 3. Synchronize Teacher Stats
        if (data.teacher) {
          const tch = data.teacher;
          if (lastTeacherStats && tch.recent_enrollments_count > lastTeacherStats.recent_enrollments_count) {
            showLiveToast('New Student Enrollment', 'A new student has enrolled in your course.', 'bi-person-check-fill', '#059669', 'dashboard.php');
          }
          lastTeacherStats = tch;
        }

        // 4. Synchronize Student Stats & Real-Time Course Progress
        if (data.student) {
          const stu = data.student;
          if (lastStudentStats && stu.approved_certificates_count > lastStudentStats.approved_certificates_count) {
            showLiveToast('Certificate Ready!', 'Your course certificate has been approved and issued.', 'bi-award-fill', '#28a745', 'my_courses.php');
          }

          // Real-time synchronization of course progress bars & cards
          if (stu.courses_progress && typeof stu.courses_progress === 'object') {
            Object.values(stu.courses_progress).forEach(prog => {
              if (!prog || !prog.course_id) return;
              const cId = prog.course_id;

              // Update course card progress bars (e.g. in dashboard.php, my_courses.php)
              const cardBar = document.getElementById(`course-progress-bar-${cId}`);
              const cardPct = document.getElementById(`course-progress-percent-${cId}`);
              const cardCount = document.getElementById(`course-progress-count-${cId}`);

              if (cardBar) {
                cardBar.style.width = `${prog.progress_percent}%`;
                cardBar.setAttribute('aria-valuenow', prog.progress_percent);
              }
              if (cardPct) {
                cardPct.textContent = `${prog.progress_percent}%`;
              }
              if (cardCount) {
                cardCount.textContent = `${prog.completed_lessons} / ${prog.total_lessons}`;
              }

              // Update syllabus progress bar if watching this course
              if (window.COURSE_ID && window.COURSE_ID.toString() === cId.toString()) {
                const sylBar = document.getElementById('syllabus-progress-bar');
                const sylPct = document.getElementById('syllabus-progress-percent');
                const sylText = document.getElementById('syllabus-progress-text');

                if (sylBar) {
                  sylBar.style.width = `${prog.progress_percent}%`;
                  sylBar.setAttribute('aria-valuenow', prog.progress_percent);
                }
                if (sylPct) {
                  sylPct.textContent = `${prog.progress_percent}%`;
                }
                if (sylText) {
                  sylText.textContent = `${prog.completed_lessons} of ${prog.total_lessons} Completed`;
                }
              }
            });
          }

          lastStudentStats = stu;
        }

        // Dispatch custom global event for external scripts
        window.dispatchEvent(new CustomEvent('lms:realtime_update', { detail: data }));
      })
      .catch(err => {
        isFetching = false;
        // Silent catch to prevent console pollution
      });
  }

  // Setup Event Listeners and Timers
  function initPoller() {
    // Initial fetch
    performHeartbeatPoll();

    // Setup recurring polling interval
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(performHeartbeatPoll, POLL_INTERVAL);

    // Page Visibility Optimization
    document.addEventListener('visibilitychange', function () {
      isTabVisible = !document.hidden;
      if (isTabVisible) {
        // Tab became visible: immediately poll for fresh updates
        performHeartbeatPoll();
        if (!pollTimer) {
          pollTimer = setInterval(performHeartbeatPoll, POLL_INTERVAL);
        }
      } else {
        // Tab inactive: stop polling timer to conserve resources
        if (pollTimer) {
          clearInterval(pollTimer);
          pollTimer = null;
        }
      }
    });

    // Bind Dropdown Tab Filters
    const tabAll = document.getElementById('notif-tab-all');
    const tabUnread = document.getElementById('notif-tab-unread');

    if (tabAll && tabUnread) {
      tabAll.addEventListener('click', function (e) {
        e.preventDefault();
        currentNotifFilter = 'all';
        tabAll.classList.add('active');
        tabUnread.classList.remove('active');
        renderDropdownItems(cachedNotifications, 'all');
      });

      tabUnread.addEventListener('click', function (e) {
        e.preventDefault();
        currentNotifFilter = 'unread';
        tabUnread.classList.add('active');
        tabAll.classList.remove('active');
        renderDropdownItems(cachedNotifications, 'unread');
      });
    }

    // Mark All As Read Button
    const markAllBtn = document.getElementById('btn-mark-all-read');
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        markAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch(getMarkReadEndpoint(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'mark_all' })
        })
          .then(res => res.json())
          .then(data => {
            markAllBtn.innerHTML = '<i class="bi bi-check2-all"></i><span>Mark all as read</span>';
            if (data && data.success) {
              cachedNotifications.forEach(n => n.is_read = 1);
              lastUnreadCount = 0;
              syncNotificationBadges(0);
              renderDropdownItems(cachedNotifications, currentNotifFilter);
            }
          })
          .catch(() => {
            markAllBtn.innerHTML = '<i class="bi bi-check2-all"></i><span>Mark all as read</span>';
          });
      });
    }
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPoller);
  } else {
    initPoller();
  }

  // Public Global API
  window.LMSRepoller = {
    pollNow: performHeartbeatPoll,
    pause: function () {
      if (pollTimer) clearInterval(pollTimer);
      pollTimer = null;
    },
    resume: function () {
      if (!pollTimer) {
        performHeartbeatPoll();
        pollTimer = setInterval(performHeartbeatPoll, POLL_INTERVAL);
      }
    },
    showToast: showLiveToast,
    playChime: playNotificationChime
  };

  // Support legacy window helpers
  window.LMSNotifications = window.LMSRepoller;

})();
