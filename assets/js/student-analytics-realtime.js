/**
 * Real-Time Student Analytics Engine
 * Automatically synchronizes student watch progress, quiz attempts, 
 * milestone completions, and KPI metrics without page reloads.
 */

(function(window) {
  'use strict';

  let config = {
    endpoint: 'api/get_student_analytics.php',
    isAdmin: false,
    pollInterval: 8000, // 8 seconds
    searchInputId: 'analytics-search-input',
    teacherFilterId: 'teacher-filter-select',
    courseFilterId: 'course-filter-select',
    statusFilterId: 'status-filter-select',
    rosterTbodyId: 'roster-tbody',
    matrixTbodyId: 'matrix-tbody',
    insightsTbodyId: 'insights-tbody',
    lastSyncId: 'last-sync-time',
    syncIconId: 'sync-icon'
  };

  let isFetching = false;
  let pollTimer = null;
  let lastSyncTimestamp = Date.now();

  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }

  function getInitialsAvatar(name, bg = '0f4c81', color = 'fff') {
    const displayName = (name && name.trim()) ? name.trim() : 'Student';
    return 'https://ui-avatars.com/api/?name=' + encodeURIComponent(displayName) + '&background=' + bg + '&color=' + color;
  }

  function renderRosterTable(summaries, isAdmin) {
    const tbody = document.getElementById(config.rosterTbodyId);
    if (!tbody) return;

    if (!summaries || summaries.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="${isAdmin ? 9 : 8}" class="text-center py-5 text-muted">
            <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
            No student enrollment records found.
          </td>
        </tr>
      `;
      return;
    }

    let html = '';
    summaries.forEach((s, idx) => {
      const avgScoreBadge = (s.avg_quiz_score !== null)
        ? `<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 fs-8 fw-bold">${s.avg_quiz_score}%</span>`
        : `<span class="text-muted fs-9 italic">N/A</span>`;

      const tutorColumn = isAdmin ? `
        <td>
          <span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 px-2.5 py-1 fs-9 fw-bold d-inline-flex align-items-center gap-1">
            <i class="bi bi-person-video3 text-primary"></i>
            ${escapeHtml(s.tutor_name || 'Lecturer')}
          </span>
        </td>
      ` : '';

      const fallbackAvatar = getInitialsAvatar(s.student_name);
      const studentAvatarSrc = s.student_avatar || fallbackAvatar;

      html += `
        <tr class="roster-row"
            data-tutor-id="${s.tutor_id || 0}"
            data-tutor-name="${escapeHtml(s.tutor_name || '')}"
            data-course="${escapeHtml(s.course_title)}"
            data-status="${escapeHtml(s.learning_status)}"
            data-student-name="${escapeHtml(s.student_name)}"
            data-student-email="${escapeHtml(s.student_email)}"
            data-academic-id="${escapeHtml(s.student_academic_id)}">
          
          <!-- Student Information -->
          <td>
            <div class="d-flex align-items-center gap-2.5">
              <img src="${escapeHtml(studentAvatarSrc)}"
                   onerror="this.onerror=null; this.src='${fallbackAvatar}';"
                   class="rounded-circle border" style="width: 38px; height: 38px; object-fit: cover;" alt="Avatar">
              <div>
                <div class="fw-bold text-dark text-truncate" style="max-width: 170px;">${escapeHtml(s.student_name)}</div>
                <div class="text-muted fs-9 text-truncate" style="max-width: 170px;">${escapeHtml(s.student_email)}</div>
                <span class="badge bg-light text-secondary border fs-9 mt-0.5">${escapeHtml(s.student_academic_id)}</span>
              </div>
            </div>
          </td>

          ${tutorColumn}

          <!-- Course Title -->
          <td>
            <span class="badge bg-light text-primary border fs-9 fw-semibold text-wrap text-start lh-base mb-1" style="max-width: 200px;">
              ${escapeHtml(s.course_title)}
            </span>
            <div class="text-muted fs-9"><i class="bi bi-clock-history me-1"></i>Last: ${escapeHtml(s.last_active)}</div>
          </td>

          <!-- Overall Progress -->
          <td>
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="progress flex-grow-1" style="height: 7px; border-radius: 10px; background-color: #e2e8f0;">
                <div class="progress-bar rounded-pill ${s.status_badge_class}" role="progressbar" style="width: ${s.overall_progress}%;"></div>
              </div>
              <span class="fw-bold text-dark fs-8">${s.overall_progress}%</span>
            </div>
            <small class="text-muted fs-9">
              Watched ${Math.round(s.total_watch_seconds / 60)}m video content
            </small>
          </td>

          <!-- Lessons Completed -->
          <td>
            <span class="badge bg-light text-dark border fs-8 fw-semibold">
              <i class="bi bi-check-circle-fill text-success me-1"></i>${s.completed_lessons} / ${s.total_lessons}
            </span>
          </td>

          <!-- Quizzes Passed -->
          <td>
            <span class="badge bg-light text-dark border fs-8 fw-semibold">
              <i class="bi bi-trophy-fill text-warning me-1"></i>${s.passed_quizzes} / ${s.total_quizzes}
            </span>
          </td>

          <!-- Avg Quiz Score -->
          <td>${avgScoreBadge}</td>

          <!-- Learning Status -->
          <td class="text-center">
            <span class="badge ${s.status_badge_class} px-3 py-1.5 rounded-pill fs-8 fw-semibold">
              ${escapeHtml(s.learning_status)}
            </span>
          </td>

          <!-- Actions -->
          <td class="text-center">
            <button onclick="openStudentDossier(${idx})" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fs-8 fw-semibold">
              <i class="bi bi-eye-fill me-1"></i>View Record
            </button>
          </td>

        </tr>
      `;
    });

    tbody.innerHTML = html;
  }

  function renderMatrixTable(rows, isAdmin) {
    const tbody = document.getElementById(config.matrixTbodyId);
    if (!tbody) return;

    if (!rows || rows.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="${isAdmin ? 6 : 5}" class="text-center py-5 text-muted">
            <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
            No granular progress records found.
          </td>
        </tr>
      `;
      return;
    }

    let html = '';
    rows.forEach(row => {
      const pct = parseFloat(row.progress_percent || 0);
      const isComp = Boolean(row.is_completed);
      const dispPct = isComp ? 100 : Math.round(pct);
      const hasQ = Boolean(row.has_quiz);
      const qScore = parseInt(row.quiz_score || 0, 10);
      const qTotal = parseInt(row.quiz_total || 0, 10);
      const qFinalized = Boolean(row.quiz_finalized);
      const qPassed = Boolean(row.quiz_passed);
      const isFullyDone = Boolean(row.is_fully_completed);

      const tutorColumn = isAdmin ? `
        <td>
          <span class="badge bg-light text-secondary border fs-9 fw-semibold">
            ${escapeHtml(row.tutor_name || 'Lecturer')}
          </span>
        </td>
      ` : '';

      const fallbackAvatar = getInitialsAvatar(row.student_name);
      const studentAvatarSrc = row.student_avatar || fallbackAvatar;

      let quizDisplay = '<span class="text-muted fs-9"><i class="bi bi-slash-circle me-1"></i>No quiz for lesson</span>';
      if (hasQ) {
        if (row.quiz_attempts > 0) {
          const passClass = qPassed ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-warning bg-opacity-10 text-dark border border-warning';
          const finalBadge = qFinalized ? '<span class="badge bg-success text-white fs-9 ms-1" title="Finalized"><i class="bi bi-check-circle-fill"></i> Final</span>' : '<span class="badge bg-light text-secondary border fs-9 ms-1">In Progress</span>';
          quizDisplay = `
            <div class="d-flex align-items-center gap-2 mb-0.5">
              <span class="badge ${passClass} fs-9 fw-bold">
                ${qScore} / ${qTotal} (${row.quiz_pct}%)
              </span>
              ${finalBadge}
            </div>
            <small class="text-muted fs-9"><i class="bi bi-arrow-repeat me-1"></i>Attempts: ${row.quiz_attempts}</small>
          `;
        } else {
          quizDisplay = '<span class="text-muted italic fs-9"><i class="bi bi-dash-circle me-1"></i>Not attempted</span>';
        }
      }

      let statusBadge = `
        <span class="badge bg-light text-secondary border px-3 py-1.5 rounded-pill fs-8">
          <i class="bi bi-lock-fill me-1"></i> Not Started
        </span>
      `;
      if (isFullyDone) {
        statusBadge = `
          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
            <i class="bi bi-check-circle-fill me-1"></i> 100% Completed
          </span>
        `;
      } else if (dispPct > 0 || row.quiz_attempts > 0) {
        statusBadge = `
          <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
            <i class="bi bi-play-circle me-1"></i> In Progress (${dispPct}%)
          </span>
        `;
      }

      html += `
        <tr class="matrix-row"
            data-tutor-id="${row.tutor_id || 0}"
            data-tutor-name="${escapeHtml(row.tutor_name || '')}"
            data-course="${escapeHtml(row.course_title)}"
            data-status="${isFullyDone ? '100% Completed' : (dispPct > 0 ? 'On Track' : 'Not Started')}"
            data-student-name="${escapeHtml(row.student_name)}"
            data-student-email="${escapeHtml(row.student_email)}"
            data-academic-id="${escapeHtml(row.student_academic_id)}"
            data-lesson="${escapeHtml(row.lesson_title)}">
          
          <!-- Student Info -->
          <td>
            <div class="d-flex align-items-center gap-2.5">
              <img src="${escapeHtml(studentAvatarSrc)}"
                   onerror="this.onerror=null; this.src='${fallbackAvatar}';"
                   class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;" alt="Avatar">
              <div>
                <div class="fw-bold text-dark text-truncate" style="max-width: 160px;">${escapeHtml(row.student_name)}</div>
                <small class="text-muted fs-9">${escapeHtml(row.student_academic_id)}</small>
              </div>
            </div>
          </td>

          ${tutorColumn}

          <!-- Course & Lesson -->
          <td>
            <span class="badge bg-light text-primary border fs-9 mb-1">${escapeHtml(row.course_title)}</span>
            <div class="fw-semibold text-dark text-truncate" style="max-width: 200px;">
              <span class="badge bg-secondary bg-opacity-10 text-secondary border me-1">#${row.lesson_order}</span>
              ${escapeHtml(row.lesson_title)}
            </div>
          </td>

          <!-- Video Watch Progress -->
          <td>
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                <div class="progress-bar rounded-pill ${isComp ? 'bg-success' : 'bg-primary'}" role="progressbar" style="width: ${dispPct}%;"></div>
              </div>
              <span class="fw-bold text-dark fs-8">${dispPct}%</span>
            </div>
            <small class="text-muted fs-9">
              ${isComp ? '<i class="bi bi-check-circle-fill text-success me-1"></i>Completed Lesson' : '<i class="bi bi-play-circle me-1"></i>' + Math.round(row.position_seconds) + 's / ' + Math.round(row.duration_seconds) + 's'}
            </small>
          </td>

          <!-- Quiz Performance -->
          <td>${quizDisplay}</td>

          <!-- Module Status -->
          <td class="text-center">${statusBadge}</td>

        </tr>
      `;
    });

    tbody.innerHTML = html;
  }

  function renderInsightsTable(insights, isAdmin) {
    const tbody = document.getElementById(config.insightsTbodyId);
    if (!tbody) return;

    if (!insights || insights.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="${isAdmin ? 7 : 6}" class="text-center py-5 text-muted">
            No lesson insights available.
          </td>
        </tr>
      `;
      return;
    }

    let html = '';
    insights.forEach(li => {
      const tutorColumn = isAdmin ? `
        <td>
          <span class="badge bg-light text-secondary border fs-9 fw-semibold">
            ${escapeHtml(li.tutor_name || 'Lecturer')}
          </span>
        </td>
      ` : '';

      const quizAttemptsDisplay = li.has_quiz
        ? (li.quiz_attempted_students > 0 ? `<span class="fw-bold text-dark fs-8">${li.quiz_attempted_students} students</span><div class="text-muted fs-9"><i class="bi bi-arrow-repeat me-1"></i>Avg ${li.avg_attempts} attempts</div>` : '<span class="text-muted fs-9">0 attempts</span>')
        : '<span class="text-muted fs-9 italic">No quiz</span>';

      const avgScoreDisplay = li.avg_quiz_score !== null
        ? `<span class="badge bg-light text-dark border fs-8 fw-bold">${li.avg_quiz_score}%</span>`
        : '<span class="text-muted fs-9">N/A</span>';

      const passRateDisplay = li.quiz_pass_rate !== null
        ? `
          <div class="d-flex align-items-center gap-2 mb-1">
            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
              <div class="progress-bar ${li.quiz_pass_rate >= 70 ? 'bg-success' : 'bg-warning'} rounded-pill" style="width: ${li.quiz_pass_rate}%;"></div>
            </div>
            <span class="fw-bold text-dark fs-8">${li.quiz_pass_rate}%</span>
          </div>
        `
        : '<span class="text-muted fs-9">N/A</span>';

      html += `
        <tr class="insights-row" 
            data-tutor-id="${li.tutor_id || 0}"
            data-tutor-name="${escapeHtml(li.tutor_name || '')}"
            data-course="${escapeHtml(li.course_title)}" 
            data-lesson="${escapeHtml(li.lesson_title)}">
          
          <!-- Course & Lesson -->
          <td>
            <span class="badge bg-light text-primary border fs-9 mb-1">${escapeHtml(li.course_title)}</span>
            <div class="fw-bold text-dark">${escapeHtml(li.lesson_title)}</div>
            <small class="text-muted fs-9"><i class="bi bi-clock me-1"></i>Duration: ${escapeHtml(li.lesson_duration || '')}</small>
          </td>

          ${tutorColumn}

          <!-- Video Completion Rate -->
          <td>
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                <div class="progress-bar bg-success rounded-pill" style="width: ${li.video_completion_rate}%;"></div>
              </div>
              <span class="fw-bold text-dark fs-8">${li.video_completion_rate}%</span>
            </div>
            <small class="text-muted fs-9">${li.watched_100_count} of ${li.total_enrolled} students finished</small>
          </td>

          <!-- Quiz Attempts -->
          <td>${quizAttemptsDisplay}</td>

          <!-- Avg Quiz Score -->
          <td>${avgScoreDisplay}</td>

          <!-- Pass Rate -->
          <td>${passRateDisplay}</td>

          <!-- Difficulty Insight -->
          <td class="text-center">
            <span class="badge ${li.difficulty_class} px-3 py-1.5 rounded-pill fs-8 fw-semibold">
              ${escapeHtml(li.difficulty_badge)}
            </span>
          </td>

        </tr>
      `;
    });

    tbody.innerHTML = html;
  }

  function fetchLiveAnalytics(isManual = false) {
    if (isFetching) return;
    isFetching = true;

    const syncIcon = document.getElementById(config.syncIconId);
    if (syncIcon) {
      syncIcon.classList.add('spin-anim');
    }

    const url = config.endpoint + (config.endpoint.includes('?') ? '&' : '?') + '_t=' + Date.now();

    fetch(url, {
      method: 'GET',
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      }
    })
    .then(res => {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(data => {
      if (!data || !data.success) {
        console.warn('[Analytics Live Sync] Server returned unsuccessful status:', data);
        return;
      }

      // Update global datasets
      window.STUDENT_SUMMARIES = data.student_summaries || [];
      window.MATRIX_ROWS = data.matrix_rows || [];
      window.LESSON_INSIGHTS = data.lesson_insights || [];

      // Re-render table bodies
      renderRosterTable(window.STUDENT_SUMMARIES, config.isAdmin);
      renderMatrixTable(window.MATRIX_ROWS, config.isAdmin);
      renderInsightsTable(window.LESSON_INSIGHTS, config.isAdmin);

      // Re-apply client-side search/filter state (which also updates dynamic KPI cards & tab counters)
      if (typeof window.filterAllTables === 'function') {
        window.filterAllTables();
      }

      lastSyncTimestamp = Date.now();
      const lastSyncEl = document.getElementById(config.lastSyncId);
      if (lastSyncEl) {
        lastSyncEl.textContent = '(Just now)';
      }
    })
    .catch(err => {
      console.warn('[Analytics Live Sync] Sync error:', err);
    })
    .finally(() => {
      isFetching = false;
      if (syncIcon) {
        setTimeout(() => {
          syncIcon.classList.remove('spin-anim');
        }, 400);
      }
    });
  }

  function startRealtimePoller() {
    if (pollTimer) clearInterval(pollTimer);

    pollTimer = setInterval(() => {
      // Only poll when page tab is visible
      if (!document.hidden) {
        fetchLiveAnalytics(false);
      }
    }, config.pollInterval);

    // Refresh immediately when returning to the tab
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        const elapsed = Date.now() - lastSyncTimestamp;
        if (elapsed > 4000) {
          fetchLiveAnalytics(false);
        }
      }
    });

    // Update the "Just now / Xm ago" label every 30 seconds
    setInterval(() => {
      const lastSyncEl = document.getElementById(config.lastSyncId);
      if (!lastSyncEl) return;
      const elapsedSec = Math.floor((Date.now() - lastSyncTimestamp) / 1000);
      if (elapsedSec < 15) {
        lastSyncEl.textContent = '(Just now)';
      } else if (elapsedSec < 60) {
        lastSyncEl.textContent = `(${elapsedSec}s ago)`;
      } else {
        const mins = Math.floor(elapsedSec / 60);
        lastSyncEl.textContent = `(${mins}m ago)`;
      }
    }, 15000);
  }

  window.initStudentAnalyticsRealtime = function(userConfig) {
    config = Object.assign({}, config, userConfig);
    startRealtimePoller();
  };

  window.fetchLiveAnalytics = fetchLiveAnalytics;

})(window);
