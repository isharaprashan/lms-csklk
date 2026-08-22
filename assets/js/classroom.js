// Moodle Classroom JS: Handles activity swaps, outline selections, checkbox statuses, AJAX Quiz grading, and AJAX Q&A forums

document.addEventListener('DOMContentLoaded', function () {
  const courseId = window.COURSE_ID;
  let completedLessonsList = window.COMPLETED_LESSONS || [];
  let isQuizCompleted = window.QUIZ_SCORE !== null;

  // DOM Elements
  const lessonOutlineItems = document.querySelectorAll('#moodle-syllabus-lessons .lesson-outline-item');
  const quizOutlineItem = document.getElementById('outline-item-quiz');
  const forumOutlineItem = document.getElementById('outline-item-forum');
  
  const videoView = document.getElementById('view-video');
  const quizView = document.getElementById('view-quiz');
  const forumView = document.getElementById('view-forum');

  const videoPlayer = document.getElementById('classroom-player');
  const activeHeading = document.getElementById('active-lesson-heading');
  const activeSummary = document.getElementById('active-lesson-summary');
  const markCompleteBtn = document.getElementById('mark-complete-btn');
  const overallProgressText = document.getElementById('overall-progress-text');
  const progressBar = document.querySelector('.progress-bar');
  const lessonProgressBar = document.getElementById('lesson-progress-bar');

  // --- Local Testing & Video Player Settings ---
  // Set USE_LOCAL_TESTING_VIDEO to true to bypass YouTube Iframe API restrictions during local testing on localhost.
  // Set to false when deploying to production to re-enable YouTube Iframe API player.
  const USE_LOCAL_TESTING_VIDEO = true; 
  const LOCAL_TESTING_VIDEO_PATH = 'uploads/class.mp4';

  // --- Video Progress Tracking State ---
  let lessonProgressData = window.LESSON_PROGRESS || {};
  let ytPlayer = null;              // Active YT.Player instance (YouTube lessons only)
  let progressSaveTimer = null;     // Interval that periodically saves progress while playing
  let activeLessonId = null;        // Lesson currently loaded in the player
  let maxWatchedTime = 0;           // Highest time point watched in the current video (for skip-blocking)
  const PROGRESS_SAVE_INTERVAL_MS = 5000; // Save progress every 5 seconds while playing
  const AUTO_COMPLETE_THRESHOLD = 90; // Percent watched to auto mark-complete

  // Helper notice when user attempts to skip forward past watched progress
  function showSeekWarning() {
    let warningEl = document.getElementById('seek-warning-toast');
    if (!warningEl) {
      warningEl = document.createElement('div');
      warningEl.id = 'seek-warning-toast';
      warningEl.className = 'alert alert-warning border-0 shadow-sm position-absolute start-50 translate-middle-x py-2 px-3 text-center';
      warningEl.style.zIndex = '1050';
      warningEl.style.top = '15px';
      warningEl.style.fontSize = '0.8rem';
      const msg = (typeof window.i18n__ === 'function') ? window.i18n__('forward_seeking_restricted', 'Forward seeking is restricted until you watch this section.') : 'Forward seeking is restricted until you watch this section.';
      warningEl.innerHTML = `<i class="bi bi-exclamation-circle-fill me-1"></i> ${msg}`;
      
      const container = document.getElementById('player-container');
      if (container) {
        container.style.position = 'relative';
        container.appendChild(warningEl);
      }
    }
    warningEl.style.display = 'inline-block';
    clearTimeout(window._seekWarningTimer);
    window._seekWarningTimer = setTimeout(() => {
      if (warningEl) warningEl.style.display = 'none';
    }, 2500);
  }

  // --- Helper: Clear all active outline indicators ---
  function clearAllOutlineActive() {
    lessonOutlineItems.forEach(i => i.classList.remove('active', 'bg-light'));
    if (quizOutlineItem) quizOutlineItem.classList.remove('active', 'bg-light');
    if (forumOutlineItem) forumOutlineItem.classList.remove('active', 'bg-light');
  }

  // --- Helper: Calculate and update header progress bar ---
  function updateOverallProgressBar() {
    const totalLessons = lessonOutlineItems.length;
    const completedCount = completedLessonsList.length;
    const progressPercent = totalLessons > 0 ? Math.round((completedCount / totalLessons) * 100) : 0;
    
    if (overallProgressText) overallProgressText.innerText = progressPercent + '%';
    if (progressBar) progressBar.style.width = progressPercent + '%';
  }

  // Helper to update quiz links and in-page quiz panel filter for active lesson
  function updateQuizForLesson(lessonId) {
    if (!lessonId) return;

    const unlockedLessons = window.UNLOCKED_LESSONS || [];
    const isReview = (window.IS_ADMIN || window.IS_TEACHER);
    const isUnlocked = isReview || unlockedLessons.includes(lessonId);

    // 1. Update Enter Quiz links (banner & sidebar)
    const enterQuizBtns = document.querySelectorAll('.enter-quiz-btn, #enter-quiz-banner-btn, #sidebar-enter-quiz-btn');
    enterQuizBtns.forEach(btn => {
      let currentHref = btn.getAttribute('href') || '';
      if (currentHref) {
        try {
          const url = new URL(currentHref, window.location.href);
          url.searchParams.set('lesson_id', lessonId);
          btn.setAttribute('href', url.pathname + url.search);
        } catch (e) {
          if (currentHref.includes('lesson_id=')) {
            currentHref = currentHref.replace(/lesson_id=[^&]*/, 'lesson_id=' + encodeURIComponent(lessonId));
          } else {
            const sep = currentHref.includes('?') ? '&' : '?';
            currentHref = currentHref + sep + 'lesson_id=' + encodeURIComponent(lessonId);
          }
          btn.setAttribute('href', currentHref);
        }
      }
      if (!isUnlocked) {
        btn.classList.add('disabled', 'opacity-50');
        btn.setAttribute('title', 'Locked - Complete previous lesson and quiz first');
      } else {
        btn.classList.remove('disabled', 'opacity-50');
        btn.removeAttribute('title');
      }
    });

    // 2. Filter quiz questions strictly for this lesson in #view-quiz panel
    const questionBlocks = document.querySelectorAll('#view-quiz .quiz-question-block');
    let totalForThisLesson = 0;
    questionBlocks.forEach(block => {
      const qLessonId = block.getAttribute('data-lesson-id');
      if (isUnlocked && qLessonId === lessonId) {
        totalForThisLesson++;
      }
    });

    let visibleCount = 0;
    questionBlocks.forEach(block => {
      const qLessonId = block.getAttribute('data-lesson-id');
      // Show ONLY if question belongs strictly to this lesson and lesson is unlocked
      if (isUnlocked && qLessonId === lessonId) {
        block.classList.remove('d-none');
        visibleCount++;
        // Update question number badge inside block
        const numberBadge = block.querySelector('.badge.bg-primary');
        if (numberBadge) {
          if (numberBadge.innerText.includes('Question') || numberBadge.innerText.includes('of')) {
            numberBadge.innerText = `Question ${visibleCount} of ${totalForThisLesson}`;
          } else {
            numberBadge.innerText = visibleCount;
          }
        }
      } else {
        block.classList.add('d-none');
      }
    });

    // Submit button in #view-quiz
    const submitBtn = document.getElementById('quiz-submit-btn');
    if (submitBtn) {
      submitBtn.style.display = (isUnlocked && visibleCount > 0) ? 'inline-block' : 'none';
    }

    // Dynamic Notices for locked state or empty questions state
    let noQuizNotice = document.getElementById('no-lesson-quiz-notice');
    let lockedQuizNotice = document.getElementById('locked-lesson-quiz-notice');

    if (!isUnlocked) {
      if (!lockedQuizNotice) {
        lockedQuizNotice = document.createElement('div');
        lockedQuizNotice.id = 'locked-lesson-quiz-notice';
        lockedQuizNotice.className = 'text-center py-5 border rounded-3 bg-white mb-4';
        lockedQuizNotice.innerHTML = `
          <i class="bi bi-lock-fill fs-1 text-warning mb-2 d-block"></i>
          <h6 class="fw-bold text-dark">Quiz Locked</h6>
          <p class="text-muted fs-8 mb-0">Complete the previous lesson and its quiz to unlock this evaluation.</p>
        `;
        const viewQuizContainer = document.getElementById('view-quiz');
        if (viewQuizContainer) viewQuizContainer.appendChild(lockedQuizNotice);
      }
      lockedQuizNotice.classList.remove('d-none');
      if (noQuizNotice) noQuizNotice.classList.add('d-none');
    } else {
      if (lockedQuizNotice) lockedQuizNotice.classList.add('d-none');

      if (visibleCount === 0 && questionBlocks.length > 0) {
        if (!noQuizNotice) {
          noQuizNotice = document.createElement('div');
          noQuizNotice.id = 'no-lesson-quiz-notice';
          noQuizNotice.className = 'text-center py-5 border rounded-3 bg-white mb-4';
          noQuizNotice.innerHTML = `
            <i class="bi bi-patch-question fs-1 text-muted mb-2 d-block"></i>
            <h6 class="fw-bold text-dark">No quiz questions added for this specific lesson</h6>
            <p class="text-muted fs-8 mb-0">Select another syllabus module or ask your tutor to add questions.</p>
          `;
          const viewQuizContainer = document.getElementById('view-quiz');
          if (viewQuizContainer) viewQuizContainer.appendChild(noQuizNotice);
        }
        noQuizNotice.classList.remove('d-none');
      } else if (noQuizNotice) {
        noQuizNotice.classList.add('d-none');
      }
    }
  }

  // --- 1. OUTLINE SWITCHER (LESSONS, QUIZ, FORUM) ---

  // Bind Lessons outline clicks
  lessonOutlineItems.forEach(item => {
    item.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show video, hide quiz/forum
      if (videoView) videoView.classList.remove('d-none');
      if (quizView) quizView.classList.add('d-none');
      if (forumView) forumView.classList.add('d-none');

      // Update video player
      const videoUrl = this.getAttribute('data-lesson-video');
      const lessonTitle = this.getAttribute('data-lesson-title');
      const lessonContent = this.getAttribute('data-lesson-content');
      const lessonId = this.getAttribute('data-lesson-id');
      const lessonDuration = this.getAttribute('data-lesson-duration');

      if (activeHeading) activeHeading.innerText = lessonTitle;
      if (activeSummary) activeSummary.innerText = lessonContent;

      const activeDurationEl = document.getElementById('active-lesson-duration');
      if (activeDurationEl && lessonDuration) {
        activeDurationEl.innerHTML = `<i class="bi bi-clock me-1"></i>Duration: ${lessonDuration}`;
      }

      if (window.HAS_ACCESS) {
        if (videoView) checkAndRenderAccess(videoView);
        renderPlayer(videoUrl, lessonId);
      } else {
        if (videoView) checkAndRenderAccess(videoView, "Premium Lesson Locked", "This video lecture is part of a premium syllabus module.");
      }

      updateCompletionButtonState(lessonId);
      updateQuizForLesson(lessonId);
      if (typeof window.renderActiveLessonResources === 'function') {
        window.renderActiveLessonResources(lessonId);
      }
    });
  });

  // Bind Quiz outline click
  if (quizOutlineItem) {
    quizOutlineItem.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show quiz, hide video/forum
      if (videoView) videoView.classList.add('d-none');
      if (quizView) quizView.classList.remove('d-none');
      if (forumView) forumView.classList.add('d-none');

      if (quizView) checkAndRenderAccess(quizView, "Quiz Locked", "Evaluations and quizzes are restricted to enrolled students.");
    });
  }

  // Bind Forum outline click
  if (forumOutlineItem) {
    forumOutlineItem.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show forum, hide video/quiz
      if (videoView) videoView.classList.add('d-none');
      if (quizView) quizView.classList.add('d-none');
      if (forumView) forumView.classList.remove('d-none');

      if (forumView && checkAndRenderAccess(forumView, "Discussion Board Locked", "Syllabus discussion threads and academic forums are restricted to enrolled students.")) {
        loadQABoard(); // Fetch latest discussion board state
      }
    });
  }

  // Set initial state of first lesson completion check
  if (lessonOutlineItems.length > 0) {
    const firstLessonId = lessonOutlineItems[0].getAttribute('data-lesson-id');
    const firstVideoUrl = lessonOutlineItems[0].getAttribute('data-lesson-video');
    updateCompletionButtonState(firstLessonId);
    updateQuizForLesson(firstLessonId);
    
    if (window.HAS_ACCESS) {
      checkAndRenderAccess(videoView);
      renderPlayer(firstVideoUrl, firstLessonId);
    } else {
      checkAndRenderAccess(videoView, "Premium Course Content Locked", "This course is paid. You must purchase this syllabus module to unlock the full lectures, video streams, quizzes, and tutor support forums.");
    }
  }

  // Helper to render media player (YouTube via IFrame API, or native HTML5 video)
  function renderPlayer(videoUrl, lessonId) {
    const playerContainer = document.getElementById('player-container');
    if (!playerContainer) return;

    // Stop tracking whatever was playing before and tear down the old YT player instance
    stopProgressTimer();
    if (ytPlayer && typeof ytPlayer.destroy === 'function') {
      ytPlayer.destroy();
    }
    ytPlayer = null;
    activeLessonId = lessonId || null;

    // Pull any previously saved position for this lesson (for "resume where you left off")
    const saved = lessonProgressData[lessonId] || { position: 0, duration: 0, percent: 0 };
    maxWatchedTime = saved.position || 0;
    updateProgressBarUI(saved.percent || 0);

    const completedBadge = document.getElementById('lesson-completed-badge');
    if (completedBadge) {
      if (completedLessonsList.includes(lessonId) || (saved && saved.percent >= 90)) {
        completedBadge.classList.remove('d-none');
      } else {
        completedBadge.classList.add('d-none');
      }
    }

    const youtubeVideoId = getYouTubeVideoId(videoUrl);

    // Render HTML5 <video> if local testing mode is active OR video URL is non-YouTube MP4
    if (USE_LOCAL_TESTING_VIDEO || !youtubeVideoId) {
      const activeVideoSource = USE_LOCAL_TESTING_VIDEO ? LOCAL_TESTING_VIDEO_PATH : videoUrl;

      if (!activeVideoSource && !USE_LOCAL_TESTING_VIDEO) {
        playerContainer.innerHTML = `
          <div class="text-center p-5 text-muted">
            <i class="bi bi-play-btn fs-1"></i>
            <p class="mt-2 mb-0">No video selected or uploaded for this lesson.</p>
          </div>
        `;
        return;
      }

      playerContainer.innerHTML = `
        <video id="classroom-player" controls controlsList="nodownload" oncontextmenu="return false;" disablePictureInPicture class="rounded w-100 h-100" style="min-height: 380px; background-color: #000;">
          <source src="${activeVideoSource}" type="video/mp4">
          Your browser does not support HTML5 video player.
        </video>
      `;

      const videoEl = document.getElementById('classroom-player');
      if (videoEl) {
        let isSeekingLock = false;
        const isReviewMode = (typeof window.IS_REVIEW_MODE !== 'undefined' && window.IS_REVIEW_MODE) ||
                             (typeof window.USER_ROLE !== 'undefined' && (window.USER_ROLE === 'admin' || window.USER_ROLE === 'super_admin' || window.USER_ROLE === 'teacher'));

        // Restore saved position on metadata loaded
        videoEl.addEventListener('loadedmetadata', function () {
          if (saved.position > 0 && saved.position < videoEl.duration) {
            videoEl.currentTime = saved.position;
            maxWatchedTime = Math.max(maxWatchedTime, saved.position);
          }
        });

        // HTML5 Video Event: Continuous progress tracking & skip-blocking enforcement
        videoEl.addEventListener('timeupdate', function () {
          if (!videoEl.duration) return;

          // 1. Skip-blocking logic: prevent seeking past highest watched time point for students ONLY
          if (!isReviewMode && !isSeekingLock) {
            if (videoEl.currentTime <= maxWatchedTime + 1.5) {
              if (videoEl.currentTime > maxWatchedTime) {
                maxWatchedTime = videoEl.currentTime;
              }
            } else {
              // User attempted to seek forward beyond highest watched point
              isSeekingLock = true;
              videoEl.currentTime = maxWatchedTime;
              showSeekWarning();
              setTimeout(() => { isSeekingLock = false; }, 300);
            }
          }

          // 2. Real-time progress bar UI update
          const percent = Math.min(100, Math.round((videoEl.currentTime / videoEl.duration) * 100));
          updateProgressBarUI(percent);

          // 3. Trigger completion when video reaches threshold (90%) for students
          if (!isReviewMode && percent >= AUTO_COMPLETE_THRESHOLD && activeLessonId) {
            if (!completedLessonsList.includes(activeLessonId)) {
              completedLessonsList.push(activeLessonId);
              applyLessonCompletedUI(activeLessonId);
              updateOverallProgressBar();
            }
          }
        });

        // HTML5 Video Event: Block seeking forward beyond watched progress (Students ONLY)
        videoEl.addEventListener('seeking', function () {
          if (!isReviewMode && videoEl.currentTime > maxWatchedTime + 1.5) {
            isSeekingLock = true;
            videoEl.currentTime = maxWatchedTime;
            showSeekWarning();
            setTimeout(() => { isSeekingLock = false; }, 300);
          }
        });

        // HTML5 Video Playback state events
        videoEl.addEventListener('play', function () {
          startProgressTimer(function () {
            saveProgress(activeLessonId, videoEl.currentTime, videoEl.duration || 0);
          });
        });

        videoEl.addEventListener('pause', function () {
          stopProgressTimer();
          saveProgress(activeLessonId, videoEl.currentTime, videoEl.duration || 0);
        });

        // HTML5 Video Event: Completion trigger on video end
        videoEl.addEventListener('ended', function () {
          stopProgressTimer();
          maxWatchedTime = videoEl.duration || maxWatchedTime;
          saveProgress(activeLessonId, videoEl.duration || 0, videoEl.duration || 0);
          
          if (activeLessonId && !completedLessonsList.includes(activeLessonId)) {
            completedLessonsList.push(activeLessonId);
            applyLessonCompletedUI(activeLessonId);
            updateOverallProgressBar();
          }
        });
      }
    } else {
      // YouTube lessons: use IFrame Player API so we can read/seek playback time
      playerContainer.innerHTML = `<div id="yt-player-target" class="w-100 h-100" style="min-height: 380px;"></div>`;
      loadYouTubeIframeAPI(function () {
        ytPlayer = new YT.Player('yt-player-target', {
          videoId: youtubeVideoId,
          playerVars: { rel: 0, modestbranding: 1, enablejsapi: 1 },
          events: {
            onReady: function (event) {
              if (saved.position > 0) {
                event.target.seekTo(saved.position, true);
              }
            },
            onStateChange: function (event) {
              if (event.data === YT.PlayerState.PLAYING) {
                startProgressTimer(function () {
                  const current = ytPlayer.getCurrentTime();
                  const dur = ytPlayer.getDuration();

                  // YouTube Skip-blocking check
                  if (current > maxWatchedTime + 1.5) {
                    ytPlayer.seekTo(maxWatchedTime, true);
                    showSeekWarning();
                  } else if (current > maxWatchedTime) {
                    maxWatchedTime = current;
                  }

                  saveProgress(lessonId, current, dur);
                });
              } else if (event.data === YT.PlayerState.PAUSED || event.data === YT.PlayerState.ENDED) {
                stopProgressTimer();
                const current = ytPlayer.getCurrentTime();
                const dur = ytPlayer.getDuration();
                if (event.data === YT.PlayerState.ENDED) {
                  maxWatchedTime = dur || maxWatchedTime;
                }
                saveProgress(lessonId, current, dur);
              }
            }
          }
        });
      });
    }
  }

  // Lazily injects the YouTube IFrame API script (only once) and runs the callback once it's ready
  function loadYouTubeIframeAPI(onReadyCallback) {
    if (window.YT && window.YT.Player) {
      onReadyCallback();
      return;
    }

    // Queue callbacks in case this is called multiple times before the API finishes loading
    window._ytApiCallbacks = window._ytApiCallbacks || [];
    window._ytApiCallbacks.push(onReadyCallback);

    if (!window._ytApiScriptInjected) {
      window._ytApiScriptInjected = true;
      const tag = document.createElement('script');
      tag.src = 'https://www.youtube.com/iframe_api';
      document.head.appendChild(tag);

      window.onYouTubeIframeAPIReady = function () {
        (window._ytApiCallbacks || []).forEach(cb => cb());
        window._ytApiCallbacks = [];
      };
    }
  }

  // Starts a periodic timer that reports playback progress while the video is playing
  function startProgressTimer(tickFn) {
    stopProgressTimer();
    progressSaveTimer = setInterval(tickFn, PROGRESS_SAVE_INTERVAL_MS);
  }

  function stopProgressTimer() {
    if (progressSaveTimer) {
      clearInterval(progressSaveTimer);
      progressSaveTimer = null;
    }
  }

  // Updates the visual progress bar under the video
  function updateProgressBarUI(percent) {
    if (!lessonProgressBar) return;
    const clamped = Math.max(0, Math.min(100, percent || 0));
    lessonProgressBar.style.width = clamped + '%';
  }

  // Sends the current watch position to the server via AJAX (update_progress.php / api/save_progress.php)
  function saveProgress(lessonId, currentTime, duration) {
    if (!lessonId || !duration || duration <= 0) return;
    if (lessonId !== activeLessonId) return; // Guard against stale timers firing after a lesson switch

    const payload = JSON.stringify({
      lesson_id: lessonId,
      current_time: currentTime,
      duration: duration
    });

    fetch('update_progress.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload
    })
      .then(response => {
        if (!response.ok) {
          return fetch('api/save_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: payload
          }).then(r => r.json());
        }
        return response.json();
      })
      .then(data => {
        if (!data.success) return;

        lessonProgressData[lessonId] = {
          position: currentTime,
          duration: duration,
          percent: data.progress_percent
        };

        if (lessonId === activeLessonId) {
          updateProgressBarUI(data.progress_percent);
        }

        if (data.completed && !completedLessonsList.includes(lessonId)) {
          completedLessonsList.push(lessonId);
          applyLessonCompletedUI(lessonId);
          updateOverallProgressBar();
        }
      })
      .catch(error => {
        console.error('Save progress error:', error);
      });
  }

  // Reflects a completed lesson in the outline checkbox + Mark Complete button (used by both
  // the manual "Mark as Completed" button and automatic 90%-watched completion)
  function applyLessonCompletedUI(lessonId) {
    const outlineBox = document.querySelector(`.moodle-checkbox-wrap[data-lesson-id="${lessonId}"]`);
    if (outlineBox) {
      outlineBox.innerHTML = `<div class="moodle-checkbox checked"><i class="bi bi-check"></i></div>`;
    }
    if (lessonId === activeLessonId) {
      updateCompletionButtonState(lessonId);
      const completedBadge = document.getElementById('lesson-completed-badge');
      if (completedBadge) completedBadge.classList.remove('d-none');
    }
  }

  // Helper to parse a YouTube URL/embed URL into a bare 11-character video ID
  function getYouTubeVideoId(url) {
    if (!url) return '';
    url = url.trim();

    // Check if it's already an embed URL
    if (url.includes('youtube.com/embed/') || url.includes('youtube-nocookie.com/embed/')) {
      const parts = url.split('/embed/');
      if (parts.length > 1) {
        const idPart = parts[1].split('?')[0].split('&')[0];
        if (idPart.length === 11) {
          return idPart;
        }
      }
    }

    // Support standard watch, youtu.be, shorts, live streams, v, etc.
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=|shorts\/|live\/)([^#\&\?]*).*/;
    const match = url.match(regExp);
    if (match && match[2].length === 11) {
      return match[2];
    }
    return '';
  }

  // Access Wall and premium lock utility
  function checkAndRenderAccess(viewElement, title, subtitle) {
    if (window.HAS_ACCESS) {
      const lockedWall = viewElement.querySelector('.moodle-locked-wall');
      if (lockedWall) lockedWall.remove();
      
      Array.from(viewElement.children).forEach(child => {
        child.classList.remove('d-none');
      });
      return true;
    } else {
      Array.from(viewElement.children).forEach(child => {
        if (!child.classList.contains('moodle-locked-wall')) {
          child.classList.add('d-none');
        }
      });

      let lockedWall = viewElement.querySelector('.moodle-locked-wall');
      if (!lockedWall) {
        lockedWall = document.createElement('div');
        lockedWall.className = 'moodle-locked-wall';
        viewElement.appendChild(lockedWall);
      }

      if (window.COURSE_PRICE > 0) {
        lockedWall.innerHTML = `
          <div class="d-flex flex-column align-items-center justify-content-center text-center p-5 text-white w-100 h-100" style="background: linear-gradient(135deg, #0f4c81 0%, #1d3557 100%); min-height: 380px; border-radius: 8px;">
            <div class="bg-white bg-opacity-10 p-3.5 rounded-circle mb-3 border border-white border-opacity-25" style="backdrop-filter: blur(10px);">
              <i class="bi bi-lock-fill text-warning" style="font-size: 2.5rem;"></i>
            </div>
            <h5 class="fw-bold mb-2">${title}</h5>
            <p class="text-white-50 px-4 mb-3.5 fs-8" style="max-width: 450px;">
              ${subtitle} Unlock full access to all lectures, videos, quizzes, and teacher forums.
            </p>
            <div class="badge bg-warning text-dark px-3 py-2 fs-7 fw-bold mb-4 shadow-sm">
              Course Price: Rs. ${Number(window.COURSE_PRICE).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
            </div>
            <a href="payment.php?course_id=${encodeURIComponent(window.COURSE_ID)}" class="btn btn-light btn-sm px-4 py-2 fw-bold text-primary shadow-sm" style="font-size: 0.85rem; border-radius: 4px;">
              <i class="bi bi-credit-card-2-back-fill me-1.5"></i> Enroll & Proceed to Payment
            </a>
          </div>
        `;
      } else {
        lockedWall.innerHTML = `
          <div class="d-flex flex-column align-items-center justify-content-center text-center p-5 text-white w-100 h-100" style="background: linear-gradient(135deg, #0f4c81 0%, #2a9d8f 100%); min-height: 380px; border-radius: 8px;">
            <div class="bg-white bg-opacity-10 p-3.5 rounded-circle mb-3 border border-white border-opacity-25" style="backdrop-filter: blur(10px);">
              <i class="bi bi-unlock-fill text-success" style="font-size: 2.5rem;"></i>
            </div>
            <h5 class="fw-bold mb-2">Enroll to Start Learning</h5>
            <p class="text-white-50 px-4 mb-4 fs-8" style="max-width: 450px;">
              This course is free! Enroll now to watch the video lectures, complete interactive quizzes, and participate in discussion forums.
            </p>
            <button id="direct-enroll-btn" class="btn btn-light btn-sm px-4 py-2 fw-bold text-success shadow-sm" style="font-size: 0.85rem; border-radius: 4px;">
              <i class="bi bi-plus-circle-fill me-1.5"></i> Enroll Directly
            </button>
          </div>
        `;

        const directBtn = lockedWall.querySelector('#direct-enroll-btn');
        if (directBtn) {
          directBtn.addEventListener('click', function() {
            directEnrollment();
          });
        }
      }
      return false;
    }
  }

  function directEnrollment() {
    const directBtn = document.getElementById('direct-enroll-btn');
    if (directBtn) {
      directBtn.disabled = true;
      directBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enrolling...`;
    }

    fetch('api/enroll.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ course_id: window.COURSE_ID })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          window.location.reload();
        } else {
          alert('Enrollment failed: ' + data.message);
          if (directBtn) {
            directBtn.disabled = false;
            directBtn.innerHTML = `<i class="bi bi-plus-circle-fill me-1.5"></i> Enroll Directly`;
          }
        }
      })
      .catch(error => {
        console.error('Enroll error:', error);
        alert('Server communications error.');
        if (directBtn) {
          directBtn.disabled = false;
          directBtn.innerHTML = `<i class="bi bi-plus-circle-fill me-1.5"></i> Enroll Directly`;
        }
      });
  }

  function updateCompletionButtonState(lessonId) {
    if (!markCompleteBtn) return;
    if (completedLessonsList.includes(lessonId)) {
      markCompleteBtn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Activity Completed`;
      markCompleteBtn.className = "btn btn-success text-white btn-sm px-3 rounded";
      markCompleteBtn.disabled = true;
    } else {
      markCompleteBtn.innerHTML = `<i class="bi bi-circle"></i> Mark as Completed`;
      markCompleteBtn.className = "btn btn-outline-success btn-sm px-3 rounded";
      markCompleteBtn.disabled = false;
      
      markCompleteBtn.onclick = function() {
        markLessonCompleted(lessonId);
      };
    }
  }

  // Mark lesson activity completed via AJAX call
  function markLessonCompleted(lessonId) {
    if (markCompleteBtn) {
      markCompleteBtn.disabled = true;
      markCompleteBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Saving...`;
    }

    fetch('api/complete_lesson.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ lesson_id: lessonId })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        if (!completedLessonsList.includes(lessonId)) {
          completedLessonsList.push(lessonId);
        }

        applyLessonCompletedUI(lessonId);
        updateCompletionButtonState(lessonId);
        updateOverallProgressBar();
      } else {
        alert('Failed to save progress: ' + data.message);
        updateCompletionButtonState(lessonId);
      }
    })
    .catch(error => {
      console.error('Save progress error:', error);
      alert('Failed to sync progress with the server.');
      updateCompletionButtonState(lessonId);
    });
  }


  // Best-effort save of progress when the student navigates away or closes the tab
  window.addEventListener('beforeunload', function () {
    if (!activeLessonId) return;

    let currentTime = 0;
    let duration = 0;

    if (ytPlayer && typeof ytPlayer.getCurrentTime === 'function') {
      currentTime = ytPlayer.getCurrentTime();
      duration = ytPlayer.getDuration();
    } else {
      const videoEl = document.getElementById('classroom-player');
      if (videoEl) {
        currentTime = videoEl.currentTime;
        duration = videoEl.duration || 0;
      }
    }

    if (duration > 0 && navigator.sendBeacon) {
      const payload = JSON.stringify({ lesson_id: activeLessonId, current_time: currentTime, duration: duration });
      navigator.sendBeacon('update_progress.php', new Blob([payload], { type: 'application/json' }));
    }
  });

  // --- 2. INTERACTIVE QUIZ PARSING ---
  // --- 2. INTERACTIVE QUIZ PARSING ---
  const quizForm = document.getElementById('course-quiz-form');
  const quizOptions = document.querySelectorAll('.quiz-option');

  quizOptions.forEach(option => {
    option.addEventListener('click', function () {
      if (isQuizCompleted && !isReviewMode) return;

      const container = this.closest('.options-container');
      
      // Clear sibling selections
      container.querySelectorAll('.quiz-option').forEach(opt => {
        opt.classList.remove('selected', 'border-primary');
        opt.querySelector('.badge').classList.replace('bg-primary', 'bg-light');
        opt.querySelector('.badge').classList.replace('text-white', 'text-muted');
      });
      
      // Select clicked
      this.classList.add('selected', 'border-primary');
      this.querySelector('.badge').classList.replace('bg-light', 'bg-primary');
      this.querySelector('.badge').classList.add('text-white');

      // Write hidden index
      const index = this.getAttribute('data-index');
      container.querySelector('.selected-option-input').value = index;
    });
  });

  if (quizForm) {
    quizForm.addEventListener('submit', function (e) {
      e.preventDefault();

      if (isQuizCompleted && !isReviewMode) return;

      const submitBtn = document.getElementById('quiz-submit-btn');
      
      // Check answers
      const inputs = quizForm.querySelectorAll('.selected-option-input');
      let allAnswered = true;
      inputs.forEach(input => {
        if (input.value === "-1") allAnswered = false;
      });

      if (!allAnswered) {
        alert('Please answer all quiz questions before submitting.');
        return;
      }

      const formData = new FormData(quizForm);
      const answers = {};
      
      formData.forEach((value, key) => {
        const match = key.match(/answers\[(.*?)\]/);
        if (match && match[1]) {
          answers[match[1]] = parseInt(value);
        }
      });

      submitBtn.disabled = true;
      submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Submitting...`;

      fetch('api/quiz.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          course_id: courseId,
          lesson_id: activeLessonId,
          answers: answers
        })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            if (!isReviewMode) {
              isQuizCompleted = true;
              if (activeLessonId && window.COMPLETED_LESSONS && !window.COMPLETED_LESSONS.includes(activeLessonId)) {
                window.COMPLETED_LESSONS.push(activeLessonId);
              }
              // Unlock next lesson
              const lessonItems = Array.from(document.querySelectorAll('#moodle-syllabus-lessons .lesson-outline-item'));
              const currentIdx = lessonItems.findIndex(el => el.getAttribute('data-lesson-id') === activeLessonId);
              if (currentIdx !== -1 && currentIdx < lessonItems.length - 1) {
                const nextItem = lessonItems[currentIdx + 1];
                const nextLessonId = nextItem.getAttribute('data-lesson-id');
                if (nextLessonId && window.UNLOCKED_LESSONS && !window.UNLOCKED_LESSONS.includes(nextLessonId)) {
                  window.UNLOCKED_LESSONS.push(nextLessonId);
                }
              }
            } else {
              submitBtn.disabled = false;
              submitBtn.innerHTML = `Submit Quiz (Review Mode)`;
            }
            renderQuizResults(data.score, data.total, data.details, data.is_review_mode, data.message);
          } else {
            alert('Quiz submission failed: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = isReviewMode ? `Submit Quiz (Review Mode)` : `Submit Quiz Attempt`;
          }
        })
        .catch(error => {
          console.error('Quiz submit error:', error);
          alert('Server connection error while grading quiz.');
          submitBtn.disabled = false;
          submitBtn.innerHTML = isReviewMode ? `Submit Quiz (Review Mode)` : `Submit Quiz Attempt`;
        });
    });
  }

  function renderQuizResults(score, total, details, isReview = false, message = '') {
    // Update badge status
    const badgeContainer = document.getElementById('quiz-status-badge');
    const reviewNote = isReview ? `<div class="mt-1 text-warning fs-9 fw-normal"><i class="bi bi-info-circle me-1"></i>Score not saved to DB (Review Mode)</div>` : '';
    badgeContainer.innerHTML = `
      <span class="badge ${isReview ? 'bg-info bg-opacity-10 text-dark border-info' : 'bg-success bg-opacity-10 text-success border-success'} border px-3 py-2 rounded-pill fs-8">
        <i class="bi bi-patch-check-fill me-1"></i> ${isReview ? 'Review Result' : 'Graded'}: ${score}/${total} Correct
        ${reviewNote}
      </span>
    `;

    // Highlight options
    Object.keys(details).forEach(qid => {
      const qBlock = document.querySelector(`.quiz-question-block[data-question-id="${qid}"]`);
      if (!qBlock) return;

      const userSel = details[qid].user_selection;
      const correctIdx = details[qid].correct_index;

      qBlock.querySelectorAll('.quiz-option').forEach(option => {
        const optionIdx = parseInt(option.getAttribute('data-index'));
        option.style.cursor = isReview ? 'pointer' : 'default';
        option.classList.remove('selected', 'border-primary', 'bg-success', 'bg-opacity-10', 'border-success', 'text-success', 'bg-danger', 'border-danger', 'text-danger');

        if (optionIdx === correctIdx) {
          option.classList.add('bg-success', 'bg-opacity-10', 'border-success', 'text-success');
          option.querySelector('.badge').className = "badge rounded-circle bg-success text-white d-flex align-items-center justify-content-center";
        } else if (optionIdx === userSel) {
          option.classList.add('bg-danger', 'bg-opacity-10', 'border-danger', 'text-danger');
          option.querySelector('.badge').className = "badge rounded-circle bg-danger text-white d-flex align-items-center justify-content-center";
        }
      });

      // Update question status header
      const header = qBlock.querySelector('h6');
      const existingCheck = header.querySelector('.bi-check-circle-fill, .bi-x-circle-fill');
      if (existingCheck) existingCheck.remove();

      if (details[qid].is_correct) {
        header.innerHTML += ` <i class="bi bi-check-circle-fill text-success ms-2"></i>`;
      } else {
        header.innerHTML += ` <i class="bi bi-x-circle-fill text-danger ms-2"></i>`;
      }
    });

    if (!isReview) {
      const quizOutlineCheckbox = document.querySelector('#outline-item-quiz .moodle-checkbox-wrap');
      if (quizOutlineCheckbox) {
        quizOutlineCheckbox.innerHTML = `<div class="moodle-checkbox checked"><i class="bi bi-check"></i></div>`;
      }
    }

    document.getElementById('quiz-status-badge').scrollIntoView({ behavior: 'smooth' });
  }


  // --- 3. DYNAMIC FORUM BOARD BOARD ---
  const qaContainer = document.getElementById('qa-feed-container');
  const newQuestionForm = document.getElementById('new-question-form');

  function loadQABoard() {
    fetch(`api/qa.php?course_id=${courseId}`)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          renderQAPosts(data.qa);
        } else {
          qaContainer.innerHTML = `<div class="text-center py-4 text-danger">Error: ${data.message}</div>`;
        }
      })
      .catch(error => {
        console.error('QA Board Fetch Error:', error);
        qaContainer.innerHTML = `<div class="text-center py-4 text-danger">Failed to fetch forum messages.</div>`;
      });
  }

  function renderQAPosts(qaList) {
    if (qaList.length === 0) {
      qaContainer.innerHTML = `
        <div class="text-center py-5 border rounded bg-white">
          <i class="bi bi-chat-square-quote fs-2 text-muted mb-2 d-block"></i>
          <h6 class="fw-bold text-dark">No forum threads</h6>
          <p class="text-muted fs-8 mb-0">Ask the first academic query for this module.</p>
        </div>
      `;
      return;
    }

    qaContainer.innerHTML = '';

    qaList.forEach(item => {
      const threadCard = document.createElement('div');
      threadCard.className = 'border rounded p-3 bg-white mb-3 shadow-sm';

      let repliesHTML = '';
      if (item.answers && item.answers.length > 0) {
        repliesHTML = `
          <div class="mt-3 pl-3 border-start border-3 flex flex-column gap-2" style="border-color: #0f4c81 !important;">
            ${item.answers.map(reply => {
              const roleBadge = (reply.replier_role === 'teacher') 
                ? `<span class="badge bg-primary fs-9 py-0.5 px-1.5 ms-1">Tutor</span>` 
                : `<span class="badge bg-secondary bg-opacity-10 text-secondary border fs-9 py-0.5 px-1.5 ms-1">Student</span>`;
              return `
                <div class="p-2.5 rounded bg-light border-0">
                  <div class="d-flex align-items-center gap-2 mb-2">
                    <img src="${reply.replier_avatar}" class="rounded-circle border border-primary border-opacity-30" alt="${reply.replier_name}" style="width: 24px; height: 24px; object-fit: cover;">
                    <div>
                      <h6 class="text-dark mb-0 fw-bold" style="font-size: 0.75rem;">${reply.replier_name} ${roleBadge}</h6>
                      <small class="text-muted" style="font-size: 0.65rem;">${reply.timestamp}</small>
                    </div>
                  </div>
                  <p class="text-secondary mb-0 fs-7 leading-relaxed">${reply.content}</p>
                </div>
              `;
            }).join('')}
          </div>
        `;
      }

      threadCard.innerHTML = `
        <div class="d-flex align-items-start gap-3">
          <img src="${item.student_avatar}" class="rounded-circle border" alt="${item.student_name}" style="width: 36px; height: 36px; object-fit: cover;">
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <h6 class="text-dark mb-0 fw-bold fs-7">
                ${item.student_name}
                ${(item.poster_role === 'teacher') 
                  ? `<span class="badge bg-primary fs-9 py-0.5 px-1.5 ms-1">Tutor</span>` 
                  : `<span class="badge bg-secondary bg-opacity-10 text-secondary border fs-9 py-0.5 px-1.5 ms-1">Student</span>`}
              </h6>
              <small class="text-muted" style="font-size: 0.7rem;">${item.timestamp}</small>
            </div>
            <p class="text-secondary mb-3 leading-relaxed" style="font-size: 0.85rem;">${item.question}</p>
            
            <div class="d-flex align-items-center gap-3">
              <button class="btn btn-link p-0 text-primary text-decoration-none fs-8 reply-toggle-btn" data-qa-id="${item.qa_id}">
                <i class="bi bi-reply-fill"></i> Reply comment
              </button>
              <span class="text-muted fs-8"><i class="bi bi-chat-left-text me-1"></i>${item.answers ? item.answers.length : 0} comments</span>
            </div>
          </div>
        </div>

        <!-- Hidden Reply Form -->
        <div class="reply-form-wrapper mt-3 d-none" id="reply-form-${item.qa_id}">
          <form class="qa-reply-form" data-qa-id="${item.qa_id}">
            <div class="d-flex gap-2 align-items-end">
              <div class="flex-grow-1">
                <textarea class="form-control bg-light border text-dark fs-8" rows="1" placeholder="Write a response..." required></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-sm px-3 border-0" style="background-color: #0f4c81; height: 32px;">
                Send
              </button>
            </div>
          </form>
        </div>

        ${repliesHTML}
      `;

      qaContainer.appendChild(threadCard);
    });

    // Bind toggles
    document.querySelectorAll('.reply-toggle-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const qid = this.getAttribute('data-qa-id');
        const formWrap = document.getElementById(`reply-form-${qid}`);
        formWrap.classList.toggle('d-none');
        if (!formWrap.classList.contains('d-none')) {
          formWrap.querySelector('textarea').focus();
        }
      });
    });

    // Bind submissions
    document.querySelectorAll('.qa-reply-form').forEach(form => {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        const qid = this.getAttribute('data-qa-id');
        const replyText = this.querySelector('textarea').value;
        submitQAReply(qid, replyText, this);
      });
    });
  }

  // Post Question
  if (newQuestionForm) {
    newQuestionForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const textarea = document.getElementById('question-textarea');
      const questionText = textarea.value.trim();
      const submitBtn = this.querySelector('button[type="submit"]');

      if (!questionText) return;

      submitBtn.disabled = true;
      submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Posting...`;

      fetch('api/qa.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          course_id: courseId,
          question: questionText
        })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            textarea.value = '';
            renderQAPosts(data.qa);
          } else {
            alert('Error posting to forum: ' + data.message);
          }
          submitBtn.disabled = false;
          submitBtn.innerHTML = `Post to Forum`;
        })
        .catch(error => {
          console.error('Forum post error:', error);
          alert('Failed to connect to discussions server.');
          submitBtn.disabled = false;
          submitBtn.innerHTML = `Post to Forum`;
        });
    });
  }

  // Post Reply
  function submitQAReply(qaId, replyText, formElement) {
    const submitBtn = formElement.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" style="width: 10px; height: 10px;" role="status"></span>`;

    fetch('api/qa.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        course_id: courseId,
        qa_id: qaId,
        reply: replyText
      })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          renderQAPosts(data.qa);
        } else {
          alert('Failed to post reply: ' + data.message);
          submitBtn.disabled = false;
          submitBtn.innerHTML = `Send`;
        }
      })
      .catch(error => {
        console.error('Reply submission error:', error);
        alert('Server communication error.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = `Send`;
      });
  }

  // --- Teacher Section: Edit Course & Edit Lesson Handlers ---
  if (window.IS_TEACHER || window.IS_ADMIN) {
    // 1. Edit Course Toggle Logic
    const editPriceToggle = document.getElementById('edit-price-toggle');
    const editPriceToggleLabel = document.getElementById('edit-price-toggle-label');
    const editPriceInputContainer = document.getElementById('edit-price-input-container');
    const editCoursePriceInput = document.getElementById('edit-course-price');

    if (editPriceToggle) {
      editPriceToggle.addEventListener('change', function () {
        if (this.checked) {
          editPriceToggleLabel.textContent = 'Free Course';
          editPriceInputContainer.style.display = 'none';
          editCoursePriceInput.value = '0.00';
          editCoursePriceInput.required = false;
        } else {
          editPriceToggleLabel.textContent = 'Paid Course';
          editPriceInputContainer.style.display = 'flex';
          editCoursePriceInput.required = true;
          editCoursePriceInput.focus();
        }
      });
    }

    // 2. Submit Edit Course Form
    const editCourseForm = document.getElementById('edit-course-form');
    if (editCourseForm) {
      editCourseForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const submitBtn = editCourseForm.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Saving...`;

        const formData = new FormData(editCourseForm);
        // Ensure price is read correctly depending on toggle status
        if (editPriceToggle && editPriceToggle.checked) {
          formData.set('price', '0.00');
        }

        fetch('api/edit_course.php', {
          method: 'POST',
          body: formData
        })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              alert('Course details updated successfully!');
              location.reload();
            } else {
              alert('Failed to update course: ' + data.message);
              submitBtn.disabled = false;
              submitBtn.innerHTML = originalBtnText;
            }
          })
          .catch(err => {
            console.error(err);
            alert('Server connection error.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
          });
      });
    }

    // 3. Edit Lesson Populate Modal Event
    const editLessonTriggers = document.querySelectorAll('.edit-lesson-btn-trigger');
    editLessonTriggers.forEach(btn => {
      btn.addEventListener('click', function (e) {
        e.stopPropagation(); // Prevent switching to the clicked lesson in workspace
        
        // Retrieve data attributes
        const lid = this.getAttribute('data-lesson-id');
        const ltitle = this.getAttribute('data-lesson-title');
        const lduration = this.getAttribute('data-lesson-duration');
        const lvideo = this.getAttribute('data-lesson-video');
        const lcontent = this.getAttribute('data-lesson-content');

        // Populate Modal inputs
        document.getElementById('edit-lesson-id').value = lid;
        document.getElementById('edit-lesson-title').value = ltitle;
        document.getElementById('edit-lesson-duration').value = lduration;
        document.getElementById('edit-lesson-video').value = lvideo;
        document.getElementById('edit-lesson-content').value = lcontent;
      });
    });

    // 4. Submit Edit Lesson Form
    const editLessonForm = document.getElementById('edit-lesson-form');
    if (editLessonForm) {
      editLessonForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const submitBtn = editLessonForm.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status"></span> Saving...`;

        fetch('api/edit_lesson.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            lesson_id: document.getElementById('edit-lesson-id').value,
            course_id: courseId,
            title: document.getElementById('edit-lesson-title').value,
            duration: document.getElementById('edit-lesson-duration').value,
            video_url: document.getElementById('edit-lesson-video').value,
            content: document.getElementById('edit-lesson-content').value
          })
        })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              alert('Lesson details updated successfully!');
              location.reload();
            } else {
              alert('Failed to update lesson: ' + data.message);
              submitBtn.disabled = false;
              submitBtn.innerHTML = originalBtnText;
            }
          })
          .catch(err => {
            console.error(err);
            alert('Server connection error.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
          });
      });
    }
  }
});