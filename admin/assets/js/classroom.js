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

  // --- 1. OUTLINE SWITCHER (LESSONS, QUIZ, FORUM) ---

  // Bind Lessons outline clicks
  lessonOutlineItems.forEach(item => {
    item.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show video, hide quiz/forum
      videoView.classList.remove('d-none');
      quizView.classList.add('d-none');
      forumView.classList.add('d-none');

      // Update video player
      const videoUrl = this.getAttribute('data-video');
      const lessonTitle = this.getAttribute('data-lesson-title');
      const lessonContent = this.getAttribute('data-lesson-content');
      const lessonId = this.getAttribute('data-lesson-id');

      if (videoPlayer) {
        const source = videoPlayer.querySelector('source');
        source.src = videoUrl;
        videoPlayer.load();
        videoPlayer.play().catch(e => console.log('Auto-play prevented:', e));
      }

      if (activeHeading) activeHeading.innerText = lessonTitle;
      if (activeSummary) activeSummary.innerText = lessonContent;

      updateCompletionButtonState(lessonId);
    });
  });

  // Bind Quiz outline click
  if (quizOutlineItem) {
    quizOutlineItem.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show quiz, hide video/forum
      videoView.classList.add('d-none');
      quizView.classList.remove('d-none');
      forumView.classList.add('d-none');

      if (videoPlayer) videoPlayer.pause();
    });
  }

  // Bind Forum outline click
  if (forumOutlineItem) {
    forumOutlineItem.addEventListener('click', function () {
      clearAllOutlineActive();
      this.classList.add('active', 'bg-light');

      // Swap views: show forum, hide video/quiz
      videoView.classList.add('d-none');
      quizView.classList.add('d-none');
      forumView.classList.remove('d-none');

      if (videoPlayer) videoPlayer.pause();
      loadQABoard(); // Fetch latest discussion board state
    });
  }

  // Set initial state of first lesson completion check
  if (lessonOutlineItems.length > 0) {
    const firstLessonId = lessonOutlineItems[0].getAttribute('data-lesson-id');
    updateCompletionButtonState(firstLessonId);
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
        
        // Update checkmark box in outline list
        const outlineBox = document.querySelector(`.moodle-checkbox-wrap[data-lesson-id="${lessonId}"]`);
        if (outlineBox) {
          outlineBox.innerHTML = `<div class="moodle-checkbox checked"><i class="bi bi-check"></i></div>`;
        }

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


  // --- 2. INTERACTIVE QUIZ PARSING ---
  const quizForm = document.getElementById('course-quiz-form');
  const quizOptions = document.querySelectorAll('.quiz-option');

  quizOptions.forEach(option => {
    option.addEventListener('click', function () {
      if (isQuizCompleted) return;

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

      if (isQuizCompleted) return;

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
          answers: answers
        })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            isQuizCompleted = true;
            renderQuizResults(data.score, data.total, data.details);
          } else {
            alert('Quiz submission failed: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = `Submit Quiz Attempt`;
          }
        })
        .catch(error => {
          console.error('Quiz submit error:', error);
          alert('Server connection error while grading quiz.');
          submitBtn.disabled = false;
          submitBtn.innerHTML = `Submit Quiz Attempt`;
        });
    });
  }

  function renderQuizResults(score, total, details) {
    // Update badge status
    const badgeContainer = document.getElementById('quiz-status-badge');
    badgeContainer.innerHTML = `
      <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-2 rounded fs-8">
        <i class="bi bi-patch-check-fill me-1"></i> Graded: ${score}/${total} Correct
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
        option.style.cursor = 'default';
        option.classList.remove('selected', 'border-primary');

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
      if (details[qid].is_correct) {
        header.innerHTML += ` <i class="bi bi-check-circle-fill text-success ms-2"></i>`;
      } else {
        header.innerHTML += ` <i class="bi bi-x-circle-fill text-danger ms-2"></i>`;
      }
    });

    // Check off the Quiz item in the course index
    const quizOutlineCheckbox = document.querySelector('#outline-item-quiz .moodle-checkbox-wrap');
    if (quizOutlineCheckbox) {
      quizOutlineCheckbox.innerHTML = `<div class="moodle-checkbox checked"><i class="bi bi-check"></i></div>`;
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
});
