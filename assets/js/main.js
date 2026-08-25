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
      cardCol.style.transform = 'translateY(22px)';
      cardCol.style.transition = `opacity 0.45s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.06}s, transform 0.45s cubic-bezier(0.16, 1, 0.3, 1) ${index * 0.06}s`;

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
        <div class="course-card-pro">
          <!-- Thumbnail & Badges -->
          <div class="course-thumb-wrap">
            <img src="${course.thumbnail}" class="course-thumb-img course-modal-trigger" data-id="${course.id}" alt="${translatedTitle}" loading="lazy">
            <span class="course-badge-cat">${translatedCat}</span>
            <span class="course-badge-level">${translatedLevel}</span>
          </div>

          <!-- Card Body -->
          <div class="p-3.5 d-flex flex-column justify-content-between flex-grow-1">
            <div>
              <!-- Meta Tags Info -->
              <div class="d-flex justify-content-between align-items-center mb-2 text-muted fs-8">
                <span class="d-inline-flex align-items-center gap-1 text-secondary fw-semibold">
                  <i class="bi bi-clock-history text-primary"></i> ${course.duration} ${durationLabel}
                </span>
                <span class="d-inline-flex align-items-center gap-1 text-warning fw-bold fs-8">
                  <i class="bi bi-star-fill"></i> ${Number(course.rating || 5).toFixed(1)} <span class="text-muted fw-normal fs-9">(${course.review_count || 0})</span>
                </span>
              </div>

              <!-- Title -->
              <h5 class="course-title-link line-clamp-2 course-modal-trigger mb-2" data-id="${course.id}">
                ${translatedTitle}
              </h5>

              <!-- Short Description -->
              <p class="text-secondary fs-8 line-clamp-2 mb-3 leading-relaxed" style="min-height: 2.5rem;">
                ${translatedDesc}
              </p>
            </div>
            
            <div>
              <hr class="my-2.5 opacity-10">
              
              <!-- Tutor Meta -->
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                  <img src="${course.tutor && course.tutor.avatar ? course.tutor.avatar : 'https://ui-avatars.com/api/?name=' + encodeURIComponent(course.tutor ? course.tutor.name : 'Educator') + '&background=2b529a&color=fff'}" 
                       onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(course.tutor ? course.tutor.name : 'Educator')}&background=2b529a&color=fff';"
                       class="course-tutor-avatar rounded-circle border border-primary border-opacity-20 shadow-xs" alt="${course.tutor ? course.tutor.name : 'Educator'}" style="width: 32px; height: 32px; object-fit: cover;">
                  <div class="text-truncate" style="max-width: 140px;">
                    <h6 class="text-dark mb-0 fs-8 fw-bold text-truncate">${course.tutor ? course.tutor.name : 'Educator'}</h6>
                    <small class="text-muted fs-9 text-truncate d-block">${course.tutor ? course.tutor.title : 'Instructor'}</small>
                  </div>
                </div>
                
                <span class="badge bg-light text-secondary border rounded-pill px-2 py-1 fs-9 fw-semibold">
                  <i class="bi bi-shield-check text-success me-0.5"></i>Verified
                </span>
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

  // Initialize Promotional Ad Carousel
  initPromoCarousel();
});

// Promotional Ad Banner Auto-Swiper Controller
function initPromoCarousel() {
  const container = document.getElementById('promoCarousel');
  const track = document.getElementById('promoCarouselTrack');
  if (!container || !track) return;

  const slides = track.querySelectorAll('.promo-slide');
  const prevBtn = document.getElementById('promoPrevBtn');
  const nextBtn = document.getElementById('promoNextBtn');
  const dots = document.querySelectorAll('.promo-indicator-dot');
  const totalSlides = slides.length;

  if (totalSlides <= 1) return;

  let currentIndex = 0;
  let autoplayInterval = null;
  const AUTOPLAY_DELAY = 4500; // 4.5 seconds auto-swipe

  function goToSlide(index) {
    if (index < 0) {
      currentIndex = totalSlides - 1;
    } else if (index >= totalSlides) {
      currentIndex = 0;
    } else {
      currentIndex = index;
    }

    track.style.transform = `translateX(-${currentIndex * 100}%)`;

    // Update dots
    dots.forEach((dot, idx) => {
      if (idx === currentIndex) {
        dot.classList.add('active');
      } else {
        dot.classList.remove('active');
      }
    });
  }

  function startAutoplay() {
    stopAutoplay();
    autoplayInterval = setInterval(() => {
      goToSlide(currentIndex + 1);
    }, AUTOPLAY_DELAY);
  }

  function stopAutoplay() {
    if (autoplayInterval) {
      clearInterval(autoplayInterval);
      autoplayInterval = null;
    }
  }

  // Navigation Button Handlers
  if (prevBtn) {
    prevBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      goToSlide(currentIndex - 1);
      startAutoplay();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      goToSlide(currentIndex + 1);
      startAutoplay();
    });
  }

  // Dots click handlers
  dots.forEach(dot => {
    dot.addEventListener('click', (e) => {
      e.stopPropagation();
      const target = parseInt(dot.getAttribute('data-target-slide'), 10);
      goToSlide(target);
      startAutoplay();
    });
  });

  // Pause on mouse hover / resume on mouse leave
  container.addEventListener('mouseenter', stopAutoplay);
  container.addEventListener('mouseleave', startAutoplay);

  // Mobile Touch Swipe Gesture Support
  let touchStartX = 0;
  let touchEndX = 0;
  let isSwiping = false;

  container.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
    isSwiping = true;
    stopAutoplay();
  }, { passive: true });

  container.addEventListener('touchend', (e) => {
    if (!isSwiping) return;
    touchEndX = e.changedTouches[0].screenX;
    const diffX = touchStartX - touchEndX;

    if (Math.abs(diffX) > 40) {
      if (diffX > 0) {
        // Swiped Left -> Next Slide
        goToSlide(currentIndex + 1);
      } else {
        // Swiped Right -> Prev Slide
        goToSlide(currentIndex - 1);
      }
    }
    isSwiping = false;
    startAutoplay();
  }, { passive: true });

  // Start initial autoplay
  startAutoplay();
}

// Helper to format announcement details with clickable links redirecting to respective websites
window.formatAnnouncementDetails = function(content) {
  if (!content) return '';
  
  let formatted = String(content);
  // If plain text (doesn't contain html tags), convert newlines to <br>
  if (!/<[a-z][\s\S]*>/i.test(formatted)) {
    formatted = formatted.replace(/\r\n|\r|\n/g, '<br>');
  }

  // Create temporary container to safely linkify text nodes without breaking HTML tags
  const container = document.createElement('div');
  container.innerHTML = formatted;

  const urlRegex = /(\b(https?:\/\/|www\.)[^\s<>"']+[^\s<>"'.,!?:;)])/gi;

  function linkifyNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const parent = node.parentNode;
      if (parent && parent.nodeName.toLowerCase() === 'a') {
        parent.setAttribute('target', '_blank');
        parent.setAttribute('rel', 'noopener noreferrer');
        return;
      }

      const text = node.textContent;
      if (urlRegex.test(text)) {
        const fragment = document.createDocumentFragment();
        let lastIndex = 0;
        urlRegex.lastIndex = 0;
        let match;

        while ((match = urlRegex.exec(text)) !== null) {
          const matchIndex = match.index;
          const matchText = match[0];

          if (matchIndex > lastIndex) {
            fragment.appendChild(document.createTextNode(text.substring(lastIndex, matchIndex)));
          }

          const a = document.createElement('a');
          let href = matchText;
          if (!href.startsWith('http://') && !href.startsWith('https://')) {
            href = 'https://' + href;
          }
          a.href = href;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.className = 'text-primary fw-semibold text-decoration-underline link-offset-2 d-inline-flex align-items-center gap-1';
          
          const textSpan = document.createElement('span');
          textSpan.textContent = matchText;
          a.appendChild(textSpan);

          const icon = document.createElement('i');
          icon.className = 'bi bi-box-arrow-up-right fs-9';
          a.appendChild(icon);

          fragment.appendChild(a);
          lastIndex = urlRegex.lastIndex;
        }

        if (lastIndex < text.length) {
          fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
        }

        if (parent) {
          parent.replaceChild(fragment, node);
        }
      }
    } else if (node.nodeType === Node.ELEMENT_NODE) {
      if (node.nodeName.toLowerCase() === 'a') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
        node.classList.add('text-primary', 'fw-semibold', 'text-decoration-underline', 'link-offset-2');
      } else {
        Array.from(node.childNodes).forEach(linkifyNode);
      }
    }
  }

  Array.from(container.childNodes).forEach(linkifyNode);
  return container.innerHTML;
};

// Global Interactive Featured Announcement Details Modal opener
window.openPromoBannerModal = function(banner) {
  if (!banner) return;

  const modalEl = document.getElementById('promoBannerModal');
  if (!modalEl) return;

  const imgEl = document.getElementById('modal-promo-img');
  const titleEl = document.getElementById('modal-promo-title');
  const subtitleEl = document.getElementById('modal-promo-subtitle');
  const detailsEl = document.getElementById('modal-promo-details');

  if (imgEl) {
    imgEl.src = banner.image_path || '';
    imgEl.alt = banner.title || 'Announcement Image';
  }

  if (titleEl) {
    titleEl.innerText = (window.i18n__ ? window.i18n__(banner.title, banner.title) : banner.title) || '';
  }

  if (subtitleEl) {
    if (banner.subtitle) {
      subtitleEl.innerText = (window.i18n__ ? window.i18n__(banner.subtitle, banner.subtitle) : banner.subtitle);
      subtitleEl.style.display = 'block';
    } else {
      subtitleEl.style.display = 'none';
    }
  }

  if (detailsEl) {
    const rawContent = (window.i18n__ ? window.i18n__(banner.details_content, banner.details_content) : banner.details_content) || '';
    detailsEl.innerHTML = window.formatAnnouncementDetails(rawContent);
  }

  const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
  bsModal.show();
};
