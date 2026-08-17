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

  // Core Function: Render course cards to HTML
  function renderCourses(courses) {
    if (courses.length === 0) {
      coursesGrid.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="moodle-card p-5 d-inline-block max-w-md mx-auto">
            <i class="bi bi-search-heart fs-1 text-secondary mb-3"></i>
            <h4 class="fw-bold">No Courses Found</h4>
            <p class="text-muted mb-0">Try adjusting your keywords or category filters.</p>
          </div>
        </div>
      `;
      return;
    }

    coursesGrid.innerHTML = ''; // Clear spinner

    courses.forEach((course, index) => {
      const cardCol = document.createElement('div');
      cardCol.className = 'col-md-6 col-lg-6 d-flex';
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
            <i class="bi bi-pencil-square"></i> Manage Course
          </a>
        `;
      } else if (course.is_enrolled) {
        actionButtonHTML = `
          <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-primary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-play-circle-fill"></i> Enter Classroom
          </a>
        `;
      } else if (window.USER_ROLE === 'teacher') {
        actionButtonHTML = `
          <a href="classroom.php?course_id=${course.id}" class="btn btn-outline-secondary rounded-pill w-100 py-2 d-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-eye"></i> View Course
          </a>
        `;
      } else {
        actionButtonHTML = `
          <button class="btn btn-primary text-white rounded-pill w-100 py-2 enroll-btn" data-id="${course.id}" style="background-color: #0f4c81; border: none;">
            <i class="bi bi-plus-circle me-1"></i> Enroll Course
          </button>
        `;
      }

      cardCol.innerHTML = `
        <div class="card moodle-card border-0 w-100 d-flex flex-column justify-content-between overflow-hidden shadow-sm h-100">
          <div class="position-relative">
            <img src="${course.thumbnail}" class="card-img-top" alt="${course.title}" style="height: 160px; object-fit: cover;">
            <span class="position-absolute top-3 end-3 badge bg-white text-dark border rounded-pill px-3 py-1">
              ${course.level}
            </span>
          </div>
          <div class="card-body p-4 d-flex flex-column justify-content-between">
            <div>
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-primary fs-7 fw-bold"><i class="bi bi-tag-fill me-1"></i>${course.category}</span>
                <span class="text-muted fs-7"><i class="bi bi-clock me-1"></i>${course.duration} Hours</span>
              </div>
              <h5 class="card-title fw-bold text-dark mb-2 line-clamp-2" style="font-size: 1.1rem; min-height: 2.8rem; line-height: 1.3;">
                ${course.title}
              </h5>
              <p class="card-text text-muted text-sm mb-4 line-clamp-3" style="font-size: 0.85rem; min-height: 3.5rem;">
                ${course.short_description}
              </p>
            </div>
            
            <div>
              <hr class="my-3">
              
              <!-- Tutor Meta -->
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                  <img src="${course.tutor.avatar}" class="rounded-circle border border-primary border-opacity-20" alt="${course.tutor.name}" style="width: 32px; height: 32px; object-fit: cover;">
                  <div>
                    <h6 class="text-dark mb-0" style="font-size: 0.8rem; font-weight: 600;">${course.tutor.name}</h6>
                    <small class="text-muted" style="font-size: 0.7rem;">${course.tutor.title}</small>
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

    // Attach Event Listeners to New Enroll Buttons
    document.querySelectorAll('.enroll-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        if (window.LOGGED_IN !== true) {
          window.location.href = 'login.php';
          return;
        }
        const courseId = this.getAttribute('data-id');
        enrollInCourse(courseId, this);
      });
    });
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
