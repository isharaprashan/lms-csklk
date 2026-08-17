// Main JS for Computerscience.lk Catalog & Core AJAX Operations

document.addEventListener('DOMContentLoaded', function () {
  let activeCategory = '';
  let searchQuery = '';
  let searchTimeout = null;

  // DOM Elements
  const coursesGrid = document.getElementById('courses-grid');
  const courseSearch = document.getElementById('course-search');
  const categoryPills = document.querySelectorAll('#category-pills .category-btn');
  const enrollToastEl = document.getElementById('enrollToast');
  
  // Initialize Toast
  const enrollToast = new bootstrap.Toast(enrollToastEl, { delay: 4000 });

  // Fetch Courses Initially
  fetchCourses();

  // Search Input Event Listener (Debounced)
  if (courseSearch) {
    courseSearch.addEventListener('input', function (e) {
      clearTimeout(searchTimeout);
      searchQuery = e.target.value;
      searchTimeout = setTimeout(() => {
        fetchCourses();
      }, 300); // 300ms debounce delay
    });
  }

  // Category Pills Event Listeners
  categoryPills.forEach(pill => {
    pill.addEventListener('click', function () {
      // Toggle Active Class
      categoryPills.forEach(p => p.classList.remove('active'));
      this.classList.add('active');

      activeCategory = this.getAttribute('data-category');
      fetchCourses();
    });
  });

  // Core Function: Fetch courses via AJAX
  function fetchCourses() {
    // Show spinner in grid
    coursesGrid.innerHTML = `
      <div class="col-12 text-center py-5">
        <div class="spinner-border text-info" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>
    `;

    // Construct request URL
    const url = new URL('api/courses.php', window.location.href);
    if (activeCategory) url.searchParams.append('category', activeCategory);
    if (searchQuery) url.searchParams.append('query', searchQuery);

    fetch(url)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          renderCourses(data.courses);
        } else {
          coursesGrid.innerHTML = `<div class="col-12 text-center text-danger py-4">Error loading catalog: ${data.message || 'Unknown error'}</div>`;
        }
      })
      .catch(error => {
        console.error('Error fetching courses:', error);
        coursesGrid.innerHTML = `<div class="col-12 text-center text-danger py-4">Failed to communicate with local XAMPP server.</div>`;
      });
  }

  // Helper for client-side i18n
  function t(key, defaultVal) {
    if (typeof window.i18n__ === 'function') {
      return window.i18n__(key, defaultVal);
    }
    return defaultVal !== undefined ? defaultVal : key;
  }

  // Global cache for fetched courses
  window.loadedCoursesMap = {};

  // Core Function: Render course cards to HTML
  function renderCourses(courses) {
    window.loadedCoursesMap = {};
    if (courses.length === 0) {
      coursesGrid.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="moodle-card p-5 d-inline-block max-w-md mx-auto">
            <i class="bi bi-search-heart fs-1 text-secondary mb-3"></i>
            <h4 class="fw-bold">${t('no_courses_found', 'No Courses Found')}</h4>
            <p class="text-muted mb-0">${t('no_courses_found_sub', 'Try adjusting your keywords or category filters.')}</p>
          </div>
        </div>
      `;
      return;
    }

    coursesGrid.innerHTML = ''; // Clear spinner

    courses.forEach((course, index) => {
      window.loadedCoursesMap[course.id] = course;

      const cardCol = document.createElement('div');
      cardCol.className = 'col-12 col-md-6 col-lg-4 col-xl-3 d-flex';
      cardCol.style.opacity = '0';
      cardCol.style.transform = 'translateY(20px)';
      cardCol.style.transition = `all 0.4s ease ${index * 0.08}s`;

      const ratingStars = Array(5).fill(0).map((_, i) => 
        i < Math.floor(course.rating) 
          ? '<i class="bi bi-star-fill text-warning"></i>' 
          : '<i class="bi bi-star text-secondary"></i>'
      ).join('');

      let actionButtonHTML = '';
      if (course.is_tutor) {
        actionButtonHTML = `
          <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-success rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-pencil-square"></i> ${t('manage_course', 'Manage Course')}
          </a>
        `;
      } else if (course.is_enrolled) {
        actionButtonHTML = `
          <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-primary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-play-circle-fill"></i> ${t('enter_classroom', 'Enter Classroom')}
          </a>
        `;
      } else if (window.USER_ROLE === 'teacher') {
        actionButtonHTML = `
          <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-secondary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-eye"></i> ${t('view_course', 'View Course')}
          </a>
        `;
      } else {
        if (course.price > 0) {
          actionButtonHTML = `
            <button class="btn btn-warning text-white rounded-pill w-100 py-2 course-modal-trigger" data-id="${course.id}" style="background-color: #f26f21; border: none;">
              <i class="bi bi-credit-card me-1"></i> ${t('enroll_course', 'Enroll Course')}
            </button>
          `;
        } else {
          actionButtonHTML = `
            <button class="btn btn-primary text-white rounded-pill w-100 py-2 course-modal-trigger" data-id="${course.id}" style="background-color: #0f4c81; border: none;">
              <i class="bi bi-plus-circle me-1"></i> ${t('enroll_course', 'Enroll Course')}
            </button>
          `;
        }
      }

      const translatedTitle = t(course.title, course.title);
      const translatedCat = t(course.category, course.category);
      const translatedLevel = t(course.level, course.level);
      const translatedDesc = t(course.short_description, course.short_description);
      const durationLabel = t('weeks', 'Weeks');

      cardCol.innerHTML = `
        <div class="card moodle-card border-0 w-100 d-flex flex-column justify-content-between overflow-hidden shadow-sm h-100">
          <div class="position-relative">
            <img src="${course.thumbnail}" class="card-img-top course-modal-trigger" data-id="${course.id}" alt="${translatedTitle}" style="height: 160px; object-fit: cover; cursor: pointer;">
            <span class="position-absolute top-3 end-3 badge bg-white text-dark border rounded-pill px-3 py-1">
              ${translatedLevel}
            </span>
          </div>
          <div class="card-body p-4 d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-primary fs-7 fw-bold"><i class="bi bi-tag-fill me-1"></i>${translatedCat}</span>
                <span class="text-muted fs-7"><i class="bi bi-clock me-1"></i>${course.duration} ${durationLabel}</span>
              </div>
              <h5 class="card-title fw-bold text-dark mb-2 line-clamp-2 course-modal-trigger" data-id="${course.id}" style="font-size: 1.1rem; min-height: 2.8rem; line-height: 1.3; cursor: pointer;">
                ${translatedTitle}
              </h5>
              <p class="card-text text-muted text-sm mb-4 line-clamp-3" style="font-size: 0.85rem; min-height: 3.5rem;">
                ${translatedDesc}
              </p>
            </div>
            
            <div>
              <hr class="my-3">
              
              <!-- Tutor Meta -->
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                  <img src="${course.tutor && course.tutor.avatar ? course.tutor.avatar : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(course.tutor ? course.tutor.name : 'Educator') + '&background=0f4c81&color=fff'}" 
                       onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(course.tutor ? course.tutor.name : 'Educator')}&background=0f4c81&color=fff';"
                       class="rounded-circle border border-primary border-opacity-20 shadow-sm" alt="${course.tutor ? course.tutor.name : 'Educator'}" style="width: 32px; height: 32px; object-fit: cover;">
                  <div>
                    <h6 class="text-dark mb-0" style="font-size: 0.8rem; font-weight: 600;">${course.tutor ? course.tutor.name : 'Educator'}</h6>
                    <small class="text-muted" style="font-size: 0.7rem;">${course.tutor ? course.tutor.title : 'Instructor'}</small>
                  </div>
                </div>
                
                <div class="text-end">
                  <div class="text-warning" style="font-size: 0.75rem;">
                    ${ratingStars}
                  </div>
                  <small class="text-muted" style="font-size: 0.7rem;">(${course.review_count})</small>
                </div>
              </div>
              
              <!-- Action Button -->
              ${actionButtonHTML}
            </div>
          </div>
        </div>
      `;

      coursesGrid.appendChild(cardCol);

      // Trigger animation after adding to DOM
      setTimeout(() => {
        cardCol.style.opacity = '1';
        cardCol.style.transform = 'translateY(0)';
      }, 50);
    });

    // Attach Event Listeners to Course Detail Triggers (Image, Title, Enroll Button)
    document.querySelectorAll('.course-modal-trigger').forEach(trigger => {
      trigger.addEventListener('click', function () {
        const courseId = this.getAttribute('data-id');
        if (courseId) {
          openCourseModal(courseId);
        }
      });
    });
  }

  // Core Function: Open Course Detail Modal with full info and price mention
  function openCourseModal(courseId) {
    const course = window.loadedCoursesMap ? window.loadedCoursesMap[courseId] : null;
    if (!course) return;

    const modalEl = document.getElementById('courseDetailModal');
    if (!modalEl) return;

    // Fill Thumbnail & Basic Info
    document.getElementById('modal-course-thumbnail').src = course.thumbnail || '';
    document.getElementById('modal-course-title').innerText = t(course.title, course.title);
    document.getElementById('modal-course-category').innerText = t(course.category, course.category);
    document.getElementById('modal-course-level').innerText = t(course.level, course.level);
    document.getElementById('modal-course-duration').innerText = `${course.duration} ${t('weeks', 'Weeks')}`;
    
    // Stars & Rating & Enrolled Count
    const ratingStars = Array(5).fill(0).map((_, i) => 
      i < Math.floor(course.rating) 
        ? '<i class="bi bi-star-fill text-warning"></i>' 
        : '<i class="bi bi-star text-secondary"></i>'
    ).join('');
    document.getElementById('modal-course-stars').innerHTML = ratingStars;
    document.getElementById('modal-course-rating-val').innerText = `${course.rating.toFixed(1)} (${course.review_count} ${t('reviews', 'reviews')})`;
    document.getElementById('modal-course-enrolled').innerText = `${course.enrolled_count || 0} ${t('enrolled_students', 'Students Enrolled')}`;

    // Description (Long or Short)
    const desc = course.long_description || course.short_description || '';
    document.getElementById('modal-course-description').innerText = t(desc, desc);

    // Target Audience Badges
    const audienceContainer = document.getElementById('modal-course-target-audience');
    if (audienceContainer) {
      if (course.target_audience && course.target_audience.trim()) {
        const audiences = course.target_audience.split(',').map(a => a.trim()).filter(a => a.length > 0);
        audienceContainer.innerHTML = audiences.map(aud => 
          `<span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 px-2.5 py-1 rounded-pill fs-8"><i class="bi bi-person-badge me-1"></i>${aud}</span>`
        ).join('');
      } else {
        audienceContainer.innerHTML = `<span class="badge bg-light text-secondary border px-2.5 py-1 rounded-pill fs-8">General Public / Self-Paced</span>`;
      }
    }

    // Tutor Info
    document.getElementById('modal-tutor-avatar').src = course.tutor ? course.tutor.avatar : '';
    document.getElementById('modal-tutor-name').innerText = course.tutor ? course.tutor.name : '';
    document.getElementById('modal-tutor-title').innerText = course.tutor ? course.tutor.title : '';

    // Pricing Highlight Box
    const pricingBox = document.getElementById('modal-pricing-box');
    const isFree = !(course.price > 0);
    if (isFree) {
      pricingBox.className = 'mt-3 p-3 rounded-3 bg-success bg-opacity-10 border border-success border-opacity-25 d-flex align-items-center justify-content-between';
      pricingBox.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-success fs-7 px-3 py-2 fw-bold">
            <i class="bi bi-gift-fill me-1"></i> ${t('free_course', 'Free Course')}
          </span>
        </div>
        <div class="text-end">
          <small class="text-muted d-block fs-8">${t('course_fee', 'Course Fee')}</small>
          <span class="fw-bold fs-5 text-success">${t('free', 'Free')} (Rs. 0.00)</span>
        </div>
      `;
    } else {
      const formattedPrice = Number(course.price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      pricingBox.className = 'mt-3 p-3 rounded-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 d-flex align-items-center justify-content-between';
      pricingBox.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          <span class="badge text-white fs-7 px-3 py-2 fw-bold" style="background-color: #f26f21;">
            <i class="bi bi-tag-fill me-1"></i> ${t('paid_course', 'Paid Course')}
          </span>
        </div>
        <div class="text-end">
          <small class="text-muted d-block fs-8">${t('course_fee', 'Course Fee')}</small>
          <span class="fw-bold fs-5" style="color: #f26f21;">Rs. ${formattedPrice}</span>
        </div>
      `;
    }

    // Modal Action Container
    const actionContainer = document.getElementById('modal-action-container');
    if (course.is_tutor) {
      actionContainer.innerHTML = `
        <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-success rounded-pill w-100 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2">
          <i class="bi bi-pencil-square"></i> ${t('manage_course', 'Manage Course')}
        </a>
      `;
    } else if (course.is_enrolled) {
      actionContainer.innerHTML = `
        <a href="classroom.php?course_id=${course.id}" class="btn btn-primary text-white rounded-pill w-100 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2" style="background-color: #0f4c81; border: none;">
          <i class="bi bi-play-circle-fill"></i> ${t('enter_classroom', 'Enter Classroom')}
        </a>
      `;
    } else if (window.USER_ROLE === 'teacher') {
      actionContainer.innerHTML = `
        <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-secondary rounded-pill w-100 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2">
          <i class="bi bi-eye"></i> ${t('view_course', 'View Course')}
        </a>
      `;
    } else {
      if (course.price > 0) {
        const formattedPrice = Number(course.price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        actionContainer.innerHTML = `
          <button id="modal-enroll-btn" class="btn btn-warning text-white rounded-pill w-100 py-2.5 fw-bold fs-6" style="background-color: #f26f21; border: none;">
            <i class="bi bi-credit-card me-1"></i> ${t('buy_course', 'Buy Course')} - Rs. ${formattedPrice}
          </button>
        `;
      } else {
        actionContainer.innerHTML = `
          <button id="modal-enroll-btn" class="btn btn-primary text-white rounded-pill w-100 py-2.5 fw-bold fs-6" style="background-color: #0f4c81; border: none;">
            <i class="bi bi-plus-circle me-1"></i> ${t('enroll_course', 'Enroll Course')} (${t('free', 'Free')})
          </button>
        `;
      }

      // Attach click event for modal action button
      setTimeout(() => {
        const modalBtn = document.getElementById('modal-enroll-btn');
        if (modalBtn) {
          modalBtn.addEventListener('click', function () {
            if (window.LOGGED_IN !== true) {
              window.location.href = 'login.php';
              return;
            }
            if (course.price > 0) {
              window.location.href = `payment.php?course_id=${encodeURIComponent(course.id)}`;
            } else {
              const bsModal = bootstrap.Modal.getInstance(modalEl);
              if (bsModal) bsModal.hide();
              enrollInCourse(course.id, this);
            }
          });
        }
      }, 50);
    }

    // Show Bootstrap Modal
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
  }

  // Core Function: Handle course enrollment AJAX POST
  function enrollInCourse(courseId, buttonElement) {
    // Disable button during loading
    buttonElement.disabled = true;
    buttonElement.innerHTML = `
      <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enrolling...
    `;

    fetch('api/enroll.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ course_id: courseId })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Show Toast Message
          document.getElementById('toast-message').innerText = `Enrolled! Welcome to ${courseId.toUpperCase()}.`;
          enrollToast.show();
          
          // Re-fetch courses to update buttons
          fetchCourses();
        } else {
          alert('Enrollment failed: ' + data.message);
          buttonElement.disabled = false;
          buttonElement.innerHTML = `<i class="bi bi-plus-circle me-1"></i> Enroll Now`;
        }
      })
      .catch(error => {
        console.error('Enroll error:', error);
        alert('Server communications error. Please check your XAMPP Apache execution.');
        buttonElement.disabled = false;
        buttonElement.innerHTML = `<i class="bi bi-plus-circle me-1"></i> Enroll Now`;
      });
  }
});
