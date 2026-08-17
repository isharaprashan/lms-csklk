<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Auth Protection: Only teachers and admins can view student analytics
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();

    if (!$current_user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $is_teacher = ($current_user['role'] === 'teacher');
    $is_admin = in_array($current_user['role'] ?? '', ['admin', 'super_admin']);

    if (!$is_teacher && !$is_admin) {
        // Students are restricted from viewing teacher analytics
        header("Location: dashboard.php");
        exit;
    }

    // Fetch teacher's courses for the filter dropdown
    if ($is_admin) {
        $stmt = $pdo->query("SELECT id, title FROM courses ORDER BY title ASC");
    } else {
        $stmt = $pdo->prepare("SELECT id, title FROM courses WHERE tutor_id = ? ORDER BY title ASC");
        $stmt->execute([$user_id]);
    }
    $teacher_courses = $stmt->fetchAll();
    $teacher_course_ids = array_column($teacher_courses, 'id');

    // KPI 1: Total Enrolled Students
    if (!empty($teacher_course_ids)) {
        $in_clause = implode(',', array_fill(0, count($teacher_course_ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM enrollments WHERE course_id IN ($in_clause)");
        $stmt->execute($teacher_course_ids);
        $total_students = (int)$stmt->fetchColumn();
    } else {
        $total_students = 0;
    }

    // KPI 2: Total 100% Completed Lessons
    if (!empty($teacher_course_ids)) {
        $in_clause = implode(',', array_fill(0, count($teacher_course_ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(cl.user_id, '_', cl.lesson_id)) 
                               FROM completed_lessons cl 
                               JOIN lessons l ON cl.lesson_id = l.id 
                               WHERE l.course_id IN ($in_clause)");
        $stmt->execute($teacher_course_ids);
        $total_completed_lessons = (int)$stmt->fetchColumn();
    } else {
        $total_completed_lessons = 0;
    }

    // KPI 3: Quiz Attempts & Pass Rate
    if (!empty($teacher_course_ids)) {
        $in_clause = implode(',', array_fill(0, count($teacher_course_ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_attempts, 
                                      SUM(CASE WHEN (score >= 1 AND status = 'passed') OR (total_questions > 0 AND (score / total_questions) >= 0.5) THEN 1 ELSE 0 END) as passed_attempts 
                               FROM quiz_results WHERE course_id IN ($in_clause)");
        $stmt->execute($teacher_course_ids);
        $quiz_stats = $stmt->fetch();
        $total_quiz_attempts = (int)($quiz_stats['total_attempts'] ?? 0);
        $passed_quiz_attempts = (int)($quiz_stats['passed_attempts'] ?? 0);
        $quiz_pass_rate = ($total_quiz_attempts > 0) ? round(($passed_quiz_attempts / $total_quiz_attempts) * 100) : 0;
    } else {
        $total_quiz_attempts = 0;
        $quiz_pass_rate = 0;
    }

    // Fetch Detailed Analytics Rows (Students x Lessons)
    $analytics_rows = [];
    if (!empty($teacher_course_ids)) {
        $in_clause = implode(',', array_fill(0, count($teacher_course_ids), '?'));
        $query = "
            SELECT 
                u.id as student_id,
                u.name as student_name,
                u.email as student_email,
                u.academic_id as student_academic_id,
                u.avatar as student_avatar,
                c.id as course_id,
                c.title as course_title,
                l.id as lesson_id,
                l.title as lesson_title,
                COALESCE(lp.progress_percent, 0) as progress_percent,
                COALESCE(lp.position_seconds, 0) as position_seconds,
                COALESCE(lp.duration_seconds, 0) as duration_seconds,
                COALESCE(lp.completed, 0) as lp_completed,
                CASE WHEN cl.user_id IS NOT NULL THEN 1 ELSE 0 END as cl_completed,
                qr.score as quiz_score,
                qr.total_questions as quiz_total_questions,
                qr.status as quiz_status,
                COALESCE(qr.attempts_count, 0) as quiz_attempts
            FROM enrollments e
            JOIN users u ON e.user_id = u.id
            JOIN courses c ON e.course_id = c.id
            JOIN lessons l ON c.id = l.course_id
            LEFT JOIN lesson_progress lp ON (u.id = lp.user_id AND l.id = lp.lesson_id)
            LEFT JOIN completed_lessons cl ON (u.id = cl.user_id AND l.id = cl.lesson_id)
            LEFT JOIN quiz_results qr ON (u.id = qr.user_id AND c.id = qr.course_id)
            WHERE c.id IN ($in_clause)
            ORDER BY c.title ASC, u.name ASC, l.sort_order ASC, l.id ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($teacher_course_ids);
        $analytics_rows = $stmt->fetchAll();
    }

    // Fetch notifications count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Progress & Analytics | Computerscience.lk</title>
  <script src="assets/js/session_manager.js"></script>
  
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  
  <!-- Local Tailwind CSS -->
  <script src="assets/js/tailwind.js"></script>
  <script>
    tailwind.config = {
      corePlugins: { preflight: false },
      theme: {
        extend: {
          colors: {
            moodle: { blue: '#0f4c81', orange: '#f26f21', bg: '#f8f9fa' }
          }
        }
      }
    }
  </script>
  
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">

  <!-- Header Navigation -->
  <header class="navbar navbar-expand-lg navbar-dark sticky-top shadow-sm" style="background-color: #0f4c81;">
    <div class="container-fluid px-3 px-md-4">
      <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-white fs-5" href="dashboard.php">
        <i class="bi bi-mortarboard-fill text-warning fs-4"></i>
        <span>Computerscience.lk LMS</span>
      </a>

      <div class="d-flex align-items-center gap-2.5 ms-auto">
        <!-- Language Switcher Dropdown -->
        <div class="dropdown">
          <button class="btn btn-sm btn-light bg-white bg-opacity-20 text-white border-0 dropdown-toggle d-flex align-items-center gap-1.5 rounded-pill px-2.5 py-1" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-globe text-warning fs-7"></i>
            <span class="fw-semibold fs-8"><?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'සිංහල' : 'English'; ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-1" aria-labelledby="langDropdown">
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'en') ? 'active fw-bold' : ''; ?>" href="#" onclick="switchLanguage('en'); return false;">
                <span>English</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'en'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item fs-8 d-flex align-items-center justify-content-between <?php echo (($_SESSION['lang'] ?? 'en') === 'si') ? 'active fw-bold' : ''; ?>" href="#" onclick="switchLanguage('si'); return false;">
                <span>සිංහල</span>
                <?php if (($_SESSION['lang'] ?? 'en') === 'si'): ?><i class="bi bi-check-lg text-primary ms-2"></i><?php endif; ?>
              </a>
            </li>
          </ul>
        </div>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm px-3 rounded-pill">
          <i class="bi bi-speedometer2 me-1"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
        </a>
        <div class="dropdown">
          <button class="btn btn-link text-white text-decoration-none p-0 d-flex align-items-center gap-2" data-bs-toggle="dropdown">
            <img src="<?php echo htmlspecialchars(get_user_avatar($current_user['avatar'], $current_user['name'])); ?>" class="rounded-circle border border-white" style="width: 32px; height: 32px; object-fit: cover;" alt="User">
            <span class="fw-medium fs-8 d-none d-md-inline-block"><?php echo htmlspecialchars($current_user['name']); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item fs-8" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item fs-8 text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Container -->
  <main class="py-4">
    <div class="container-fluid px-3 px-md-4">
      
      <!-- Page Title & Header -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
          <h2 class="fw-bold text-dark mb-1 fs-4"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Student Progress & Performance Analytics</h2>
          <p class="text-muted fs-8 mb-0">Monitor video watch percentages, lesson completion statuses, and quiz performance for your enrolled students.</p>
        </div>
        <div>
          <a href="dashboard.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill">
            <i class="bi bi-arrow-left me-1"></i> Back to Console
          </a>
        </div>
      </div>

      <!-- KPI Summary Cards Section -->
      <div class="row g-3 mb-4">
        <!-- KPI 1: Enrolled Students -->
        <div class="col-md-4">
          <div class="moodle-card p-3 border-start border-4 border-primary shadow-sm bg-white">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Total Enrolled Students</small>
                <h3 class="fw-bold text-dark mb-0 mt-1"><?php echo number_format($total_students); ?></h3>
              </div>
              <div class="bg-primary bg-opacity-10 p-3 rounded-circle text-primary">
                <i class="bi bi-people-fill fs-4"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 2: Completed Lessons -->
        <div class="col-md-4">
          <div class="moodle-card p-3 border-start border-4 border-success shadow-sm bg-white">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">100% Completed Lessons</small>
                <h3 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($total_completed_lessons); ?></h3>
              </div>
              <div class="bg-success bg-opacity-10 p-3 rounded-circle text-success">
                <i class="bi bi-patch-check-fill fs-4"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 3: Quiz Pass Rate -->
        <div class="col-md-4">
          <div class="moodle-card p-3 border-start border-4 border-warning shadow-sm bg-white">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider">Quiz Pass Rate</small>
                <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo $quiz_pass_rate; ?>%</h3>
                <small class="text-muted fs-9"><?php echo $passed_quiz_attempts; ?> passed out of <?php echo $total_quiz_attempts; ?> attempts</small>
              </div>
              <div class="bg-warning bg-opacity-10 p-3 rounded-circle text-warning">
                <i class="bi bi-trophy-fill fs-4"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Analytics Table Card -->
      <div class="moodle-card p-4 shadow-sm bg-white">
        
        <!-- Filters & Search Bar -->
        <div class="row g-3 align-items-center mb-4 pb-3 border-bottom">
          <div class="col-md-4">
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
              <input type="text" id="table-search-input" class="form-control bg-light border-start-0 fs-8" placeholder="Search student name, email, or lesson title...">
            </div>
          </div>

          <div class="col-md-4">
            <select id="course-filter-select" class="form-select fs-8 bg-light border">
              <option value="">All Courses (<?php echo count($teacher_courses); ?>)</option>
              <?php foreach ($teacher_courses as $c): ?>
                <option value="<?php echo htmlspecialchars($c['title']); ?>"><?php echo htmlspecialchars($c['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <select id="status-filter-select" class="form-select fs-8 bg-light border">
              <option value="">All Completion Statuses</option>
              <option value="100% Completed">100% Completed Only</option>
              <option value="In Progress">In Progress Only</option>
              <option value="Quiz Passed">Quiz Passed Only</option>
            </select>
          </div>
        </div>

        <!-- Table Container -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="analytics-table">
            <thead class="table-light fs-8 text-uppercase text-secondary">
              <tr>
                <th scope="col" style="min-width: 200px;">Student Information</th>
                <th scope="col" style="min-width: 180px;">Course & Lesson</th>
                <th scope="col" style="min-width: 200px;">Video Watch Progress</th>
                <th scope="col" style="min-width: 180px;">Quiz Performance</th>
                <th scope="col" class="text-center" style="min-width: 130px;">Status Badge</th>
              </tr>
            </thead>
            <tbody class="fs-8">
              <?php if (empty($analytics_rows)): ?>
                <tr>
                  <td colspan="5" class="text-center py-5 text-muted">
                    <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                    No student progress records found.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($analytics_rows as $row): 
                  $pct = (float)$row['progress_percent'];
                  $isCompleted = ($row['lp_completed'] == 1 || $row['cl_completed'] == 1 || $pct >= 90);
                  $displayPct = $isCompleted ? 100 : round($pct);
                  
                  $hasQuiz = ($row['quiz_score'] !== null);
                  $qScore = (int)($row['quiz_score'] ?? 0);
                  $qTotal = (int)($row['quiz_total_questions'] ?? 0);
                  $qRatio = ($qTotal > 0) ? ($qScore / $qTotal) : 0;
                  $qPassed = ($qRatio >= 0.5 || ($row['quiz_status'] ?? '') === 'passed');
                ?>
                  <tr class="analytics-row" 
                      data-course="<?php echo htmlspecialchars($row['course_title']); ?>"
                      data-status="<?php echo $isCompleted ? '100% Completed' : 'In Progress'; ?>"
                      data-quiz="<?php echo $qPassed ? 'Quiz Passed' : 'Quiz Pending'; ?>">
                    
                    <!-- Student Info -->
                    <td>
                      <div class="d-flex align-items-center gap-2.5">
                        <img src="<?php echo htmlspecialchars(get_user_avatar($row['student_avatar'], $row['student_name'])); ?>" class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;" alt="Avatar">
                        <div>
                          <div class="fw-bold text-dark text-truncate" style="max-width: 180px;"><?php echo htmlspecialchars($row['student_name']); ?></div>
                          <small class="text-muted fs-9"><?php echo htmlspecialchars($row['student_email']); ?></small>
                          <div><span class="badge bg-light text-secondary border fs-9"><?php echo htmlspecialchars($row['student_academic_id']); ?></span></div>
                        </div>
                      </div>
                    </td>

                    <!-- Course & Lesson -->
                    <td>
                      <span class="badge bg-light text-primary border fs-9 mb-1"><?php echo htmlspecialchars($row['course_title']); ?></span>
                      <div class="fw-semibold text-dark text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($row['lesson_title']); ?></div>
                    </td>

                    <!-- Video Watch Progress -->
                    <td>
                      <div class="d-flex align-items-center gap-2 mb-1">
                        <div class="progress flex-grow-1" style="height: 6px;">
                          <div class="progress-bar rounded bg-<?php echo $isCompleted ? 'success' : 'primary'; ?>" role="progressbar" style="width: <?php echo $displayPct; ?>%;"></div>
                        </div>
                        <span class="fw-bold text-dark fs-8"><?php echo $displayPct; ?>%</span>
                      </div>
                      <small class="text-muted fs-9">
                        <i class="bi bi-clock me-1"></i>Watched <?php echo round($row['position_seconds']); ?>s of <?php echo round($row['duration_seconds']); ?>s
                      </small>
                    </td>

                    <!-- Quiz Performance -->
                    <td>
                      <?php if ($hasQuiz): ?>
                        <div class="d-flex align-items-center gap-1.5 mb-1">
                          <span class="badge bg-<?php echo $qPassed ? 'success' : 'warning'; ?> bg-opacity-10 text-<?php echo $qPassed ? 'success' : 'dark'; ?> border border-<?php echo $qPassed ? 'success' : 'warning'; ?> border-opacity-35 fs-9">
                            <i class="bi bi-patch-check-fill me-1"></i> Score: <?php echo $qScore; ?><?php echo $qTotal > 0 ? '/'.$qTotal : ''; ?>
                          </span>
                        </div>
                        <small class="text-muted fs-9">
                          <i class="bi bi-arrow-repeat me-1"></i>Attempts: <?php echo $row['quiz_attempts']; ?>
                        </small>
                      <?php else: ?>
                        <span class="text-muted italic fs-9"><i class="bi bi-dash-circle me-1"></i>No quiz attempt</span>
                      <?php endif; ?>
                    </td>

                    <!-- Status Badge -->
                    <td class="text-center">
                      <?php if ($isCompleted): ?>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
                          <i class="bi bi-check-circle-fill me-1"></i> 100% Completed
                        </span>
                      <?php else: ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
                          <i class="bi bi-play-circle me-1"></i> In Progress (<?php echo $displayPct; ?>%)
                        </span>
                      <?php endif; ?>
                    </td>

                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>

    </div>
  </main>

  <!-- Local Bootstrap JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>

  <!-- Interactive Filtering & Search Script -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const searchInput = document.getElementById('table-search-input');
      const courseFilter = document.getElementById('course-filter-select');
      const statusFilter = document.getElementById('status-filter-select');
      const tableRows = document.querySelectorAll('.analytics-row');

      function filterTable() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const selectedCourse = courseFilter.value.toLowerCase().trim();
        const selectedStatus = statusFilter.value.trim();

        tableRows.forEach(row => {
          const rowText = row.innerText.toLowerCase();
          const rowCourse = row.getAttribute('data-course').toLowerCase();
          const rowStatus = row.getAttribute('data-status');
          const rowQuiz = row.getAttribute('data-quiz');

          const matchesSearch = !searchTerm || rowText.includes(searchTerm);
          const matchesCourse = !selectedCourse || rowCourse.includes(selectedCourse);
          
          let matchesStatus = true;
          if (selectedStatus === '100% Completed') {
            matchesStatus = (rowStatus === '100% Completed');
          } else if (selectedStatus === 'In Progress') {
            matchesStatus = (rowStatus === 'In Progress');
          } else if (selectedStatus === 'Quiz Passed') {
            matchesStatus = (rowQuiz === 'Quiz Passed');
          }

          if (matchesSearch && matchesCourse && matchesStatus) {
            row.style.display = '';
          } else {
            row.style.display = 'none';
          }
        });
      }

      if (searchInput) searchInput.addEventListener('input', filterTable);
      if (courseFilter) courseFilter.addEventListener('change', filterTable);
      if (statusFilter) statusFilter.addEventListener('change', filterTable);
    });

    function switchLanguage(lang) {
      fetch('api/set_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lang: lang })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          location.reload();
        } else {
          window.location.href = 'api/set_language.php?lang=' + lang;
        }
      })
      .catch(err => {
        window.location.href = 'api/set_language.php?lang=' + lang;
      });
    }
  </script>
</body>
</html>
