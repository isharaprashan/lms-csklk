<?php
/**
 * Teacher & Admin Assignment Grading Center
 * Review, assess, and score student coursework across courses
 */

require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

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
    $is_admin = in_array($current_user['role'] ?? '', ['admin', 'super_admin']) || isset($_COOKIE['LMS_ADMIN_SESS']);

    if (!$is_teacher && !$is_admin) {
        header("Location: dashboard.php");
        exit;
    }

    // Fetch courses taught by teacher (or all courses if admin)
    if ($is_admin) {
        $stmt = $pdo->query("SELECT id, title, category, thumbnail FROM courses ORDER BY title ASC");
    } else {
        $stmt = $pdo->prepare("SELECT id, title, category, thumbnail FROM courses WHERE tutor_id = ? ORDER BY title ASC");
        $stmt->execute([$user_id]);
    }
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $course_ids = array_column($courses, 'id');

    // Filter parameters
    $selected_course = trim($_GET['course_id'] ?? '');
    $selected_status = trim($_GET['status'] ?? 'all'); // 'all', 'pending', 'graded'
    $search_query = trim($_GET['q'] ?? '');

    // Overall KPI statistics
    $where_kpi = [];
    $params_kpi = [];
    if (!$is_admin) {
        if (!empty($course_ids)) {
            $in_clause = implode(',', array_fill(0, count($course_ids), '?'));
            $where_kpi[] = "sub.course_id IN ($in_clause)";
            $params_kpi = array_merge($params_kpi, $course_ids);
        } else {
            $where_kpi[] = "1 = 0";
        }
    }

    $kpi_sql_base = "FROM assignment_submissions sub " . (!empty($where_kpi) ? "WHERE " . implode(' AND ', $where_kpi) : "");
    
    // Total submissions
    $stmtKpi1 = $pdo->prepare("SELECT COUNT(*) " . $kpi_sql_base);
    $stmtKpi1->execute($params_kpi);
    $total_submissions_count = (int)$stmtKpi1->fetchColumn();

    // Pending ungraded
    $kpi_pending_sql = "FROM assignment_submissions sub WHERE " . (!empty($where_kpi) ? implode(' AND ', $where_kpi) . " AND " : "") . "(sub.status != 'graded' OR sub.grade IS NULL)";
    $stmtKpi2 = $pdo->prepare("SELECT COUNT(*) " . $kpi_pending_sql);
    $stmtKpi2->execute($params_kpi);
    $pending_submissions_count = (int)$stmtKpi2->fetchColumn();

    // Graded submissions
    $kpi_graded_sql = "FROM assignment_submissions sub WHERE " . (!empty($where_kpi) ? implode(' AND ', $where_kpi) . " AND " : "") . "(sub.status = 'graded' AND sub.grade IS NOT NULL)";
    $stmtKpi3 = $pdo->prepare("SELECT COUNT(*), AVG(sub.grade) " . $kpi_graded_sql);
    $stmtKpi3->execute($params_kpi);
    $graded_stats = $stmtKpi3->fetch(PDO::FETCH_NUM);
    $graded_submissions_count = (int)($graded_stats[0] ?? 0);
    $avg_grade_overall = $graded_stats[1] !== null ? round((float)$graded_stats[1], 1) : 0;

    // Filtered Query for Submissions List
    $where_clauses = [];
    $params = [];

    if (!$is_admin) {
        if (!empty($course_ids)) {
            $in_clause = implode(',', array_fill(0, count($course_ids), '?'));
            $where_clauses[] = "sub.course_id IN ($in_clause)";
            $params = array_merge($params, $course_ids);
        } else {
            $where_clauses[] = "1 = 0";
        }
    }

    if (!empty($selected_course)) {
        $where_clauses[] = "sub.course_id = ?";
        $params[] = $selected_course;
    }

    if ($selected_status === 'pending') {
        $where_clauses[] = "(sub.status != 'graded' OR sub.grade IS NULL)";
    } elseif ($selected_status === 'graded') {
        $where_clauses[] = "(sub.status = 'graded' AND sub.grade IS NOT NULL)";
    }

    if (!empty($search_query)) {
        $where_clauses[] = "(u.name LIKE ? OR u.email LIKE ? OR a.title LIKE ? OR l.title LIKE ?)";
        $search_term = "%{$search_query}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $where_sql = !empty($where_clauses) ? "WHERE " . implode(' AND ', $where_clauses) : "";

    $sql = "SELECT sub.*, 
                   a.title AS assignment_title, 
                   a.due_date AS assignment_due_date,
                   l.title AS lesson_title, 
                   l.id AS lesson_id,
                   c.id AS course_id, 
                   c.title AS course_title,
                   u.name AS student_name, 
                   u.email AS student_email, 
                   u.avatar AS student_avatar,
                   grader.name AS grader_name
            FROM assignment_submissions sub
            LEFT JOIN lesson_assignments a ON a.id = sub.assignment_id
            LEFT JOIN lessons l ON l.id = sub.lesson_id
            LEFT JOIN courses c ON c.id = sub.course_id
            LEFT JOIN users u ON u.id = sub.user_id
            LEFT JOIN users grader ON grader.id = sub.graded_by
            {$where_sql}
            ORDER BY sub.submitted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($submissions as &$s) {
        $bytes = (int)($s['file_size'] ?? 0);
        $s['formatted_size'] = ($bytes >= 1048576) ? round($bytes / 1048576, 2) . ' MB' : (($bytes >= 1024) ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
        $s['resolved_avatar'] = get_user_avatar($s['student_avatar'] ?? null, $s['student_name'] ?? 'Student');
    }
    unset($s);

} catch (PDOException $e) {
    die("Database connection error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('assignment_grading_center', 'Assignment Submissions & Grading'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>

  <!-- Google Fonts: Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Base LMS Styles -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/notifications.css">

  <style>
    /* =========================================================
       Premium Assignment Grading Center UI Design System
       ========================================================= */
    :root {
      --primary-color: #0f4c81;
      --primary-hover: #0b3760;
      --primary-subtle: rgba(15, 76, 129, 0.08);
      --secondary-color: #f26f21;
      --success-green: #10b981;
      --success-subtle: rgba(16, 185, 129, 0.12);
      --warning-amber: #f59e0b;
      --warning-subtle: rgba(245, 158, 11, 0.12);
      --info-cyan: #06b6d4;
      --info-subtle: rgba(6, 182, 212, 0.12);
      --card-border: rgba(226, 232, 240, 0.85);
      --card-shadow: 0 4px 20px rgba(15, 76, 129, 0.04);
      --card-hover-shadow: 0 12px 28px rgba(15, 76, 129, 0.08);
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: #f4f6fa;
      color: #1e293b;
      -webkit-font-smoothing: antialiased;
    }

    /* Typography Utilities */
    .fs-7 { font-size: 0.875rem !important; }
    .fs-8 { font-size: 0.8125rem !important; }
    .fs-9 { font-size: 0.75rem !important; }
    .fs-10 { font-size: 0.6875rem !important; }
    .fw-semibold { font-weight: 600 !important; }
    .tracking-wider { letter-spacing: 0.05em; }

    /* Breadcrumbs */
    .custom-breadcrumb {
      background: transparent;
      padding: 0;
      margin-bottom: 1rem;
    }
    .custom-breadcrumb .breadcrumb-item a {
      color: #64748b;
      text-decoration: none;
      font-weight: 500;
      transition: color 0.15s ease;
    }
    .custom-breadcrumb .breadcrumb-item a:hover {
      color: var(--primary-color);
    }
    .custom-breadcrumb .breadcrumb-item.active {
      color: #0f172a;
      font-weight: 600;
    }

    /* KPI Summary Cards */
    .kpi-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid var(--card-border);
      box-shadow: var(--card-shadow);
      padding: 1.25rem 1.4rem;
      position: relative;
      overflow: hidden;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      height: 100%;
    }
    .kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--card-hover-shadow);
      border-color: rgba(15, 76, 129, 0.2);
    }
    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
      border-radius: 4px 0 0 4px;
    }
    .kpi-card.kpi-primary::before { background: linear-gradient(180deg, #0f4c81, #3b82f6); }
    .kpi-card.kpi-warning::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }
    .kpi-card.kpi-success::before { background: linear-gradient(180deg, #10b981, #34d399); }
    .kpi-card.kpi-info::before { background: linear-gradient(180deg, #06b6d4, #38bdf8); }

    .kpi-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.45rem;
      flex-shrink: 0;
      transition: transform 0.2s ease;
    }
    .kpi-card:hover .kpi-icon-box {
      transform: scale(1.06);
    }
    .kpi-icon-box.primary { background: rgba(15, 76, 129, 0.1); color: #0f4c81; }
    .kpi-icon-box.warning { background: rgba(245, 158, 11, 0.14); color: #d97706; }
    .kpi-icon-box.success { background: rgba(16, 185, 129, 0.14); color: #059669; }
    .kpi-icon-box.info { background: rgba(6, 182, 212, 0.14); color: #0891b2; }

    /* Filter & Search Bar */
    .filter-panel {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid var(--card-border);
      box-shadow: var(--card-shadow);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
    }
    .filter-btn-group .btn {
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 0.45rem 1rem;
      border-radius: 50rem;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }
    .filter-btn-group .btn.active {
      background-color: var(--primary-color) !important;
      color: #ffffff !important;
      border-color: var(--primary-color) !important;
      box-shadow: 0 4px 10px rgba(15, 76, 129, 0.2);
    }

    /* Submissions Table */
    .table-panel {
      background: #ffffff;
      border-radius: 20px;
      border: 1px solid var(--card-border);
      box-shadow: var(--card-shadow);
      overflow: hidden;
      margin-bottom: 2rem;
    }
    .table-panel-header {
      padding: 1.15rem 1.5rem;
      background: #ffffff;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }
    .submissions-table th {
      background-color: #f8fafc;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      font-weight: 700;
      padding: 0.95rem 1.25rem;
      border-bottom: 1px solid #e2e8f0;
      white-space: nowrap;
    }
    .submissions-table td {
      padding: 1rem 1.25rem;
      vertical-align: middle;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
    }
    .submissions-table tbody tr {
      transition: background-color 0.15s ease;
    }
    .submissions-table tbody tr:hover {
      background-color: #f8fafc;
    }

    /* Badges & Pills - High Contrast & Crisp */
    .score-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8125rem;
      font-weight: 700;
      padding: 0.35rem 0.8rem;
      border-radius: 50rem;
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      box-shadow: 0 1px 2px rgba(16, 185, 129, 0.05);
      white-space: nowrap;
    }
    .status-badge-pending {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 0.35rem 0.8rem;
      border-radius: 50rem;
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
      box-shadow: 0 1px 2px rgba(245, 158, 11, 0.05);
      white-space: nowrap;
    }
    .badge-subtle-primary {
      background: rgba(15, 76, 129, 0.08);
      color: var(--primary-color);
      border: 1px solid rgba(15, 76, 129, 0.2);
    }

    /* File Download Chips */
    .file-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.35rem 0.75rem;
      border-radius: 50rem;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #1e293b;
      font-size: 0.8125rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
      max-width: 230px;
    }
    .file-chip:hover {
      background: #e2e8f0;
      border-color: #cbd5e1;
      color: var(--primary-color);
      transform: translateY(-1px);
    }
    .file-chip .file-name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 130px;
    }

    /* Student remarks pill */
    .student-note-snippet {
      font-size: 0.75rem;
      color: #64748b;
      background: #f8fafc;
      padding: 0.2rem 0.5rem;
      border-radius: 6px;
      border: 1px dashed #cbd5e1;
      display: inline-block;
      max-width: 220px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      margin-top: 0.3rem;
    }

    /* Action Buttons */
    .btn-grade-primary {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
      color: #ffffff;
      font-weight: 600;
      font-size: 0.8125rem;
      padding: 0.35rem 0.95rem;
      border-radius: 50rem;
      box-shadow: 0 2px 6px rgba(15, 76, 129, 0.2);
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }
    .btn-grade-primary:hover {
      background-color: var(--primary-hover);
      border-color: var(--primary-hover);
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(15, 76, 129, 0.25);
    }
    .btn-grade-outline {
      background-color: #ffffff;
      border: 1px solid #cbd5e1;
      color: var(--primary-color);
      font-weight: 600;
      font-size: 0.8125rem;
      padding: 0.35rem 0.95rem;
      border-radius: 50rem;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }
    .btn-grade-outline:hover {
      background-color: rgba(15, 76, 129, 0.06);
      border-color: var(--primary-color);
      color: var(--primary-color);
      transform: translateY(-1px);
    }
    .btn-icon-circle {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      border: 1px solid #e2e8f0;
      background: #ffffff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #64748b;
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .btn-icon-circle:hover {
      background: #f1f5f9;
      color: var(--primary-color);
      border-color: #cbd5e1;
    }

    /* Evaluation Modal Styling */
    .modal-grade .modal-content {
      border: none;
      border-radius: 22px;
      box-shadow: 0 25px 60px rgba(15, 76, 129, 0.18);
      overflow: hidden;
    }
    .modal-grade .modal-header {
      background: linear-gradient(135deg, #0f4c81, #1a64a3);
      color: #ffffff;
      padding: 1.25rem 1.5rem;
      border: none;
    }
    .modal-grade .modal-header .btn-close {
      filter: invert(1) grayscale(100%) brightness(200%);
    }
    .student-brief-card {
      background: #f8fafc;
      border-radius: 14px;
      border: 1px solid #e2e8f0;
      padding: 1rem 1.25rem;
    }
    .preset-btn {
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 50rem;
      padding: 0.25rem 0.65rem;
      border: 1px solid #cbd5e1;
      background: #ffffff;
      color: #475569;
      transition: all 0.15s ease;
    }
    .preset-btn:hover, .preset-btn.active {
      background: var(--primary-color);
      border-color: var(--primary-color);
      color: #ffffff;
      transform: scale(1.05);
    }
    .feedback-chip {
      font-size: 0.75rem;
      border-radius: 50rem;
      padding: 0.25rem 0.65rem;
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #e2e8f0;
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-block;
      margin: 0.15rem;
    }
    .feedback-chip:hover {
      background: #e2e8f0;
      color: var(--primary-color);
      border-color: #cbd5e1;
    }

    /* Score Meter Bar */
    .score-meter-container {
      height: 6px;
      background: #e2e8f0;
      border-radius: 50rem;
      overflow: hidden;
      margin-top: 0.5rem;
    }
    .score-meter-fill {
      height: 100%;
      border-radius: 50rem;
      transition: width 0.3s ease, background-color 0.3s ease;
      width: 0%;
      background-color: var(--success-green);
    }

    /* Floating Toast */
    .toast-container-custom {
      position: fixed;
      top: 95px;
      right: 25px;
      z-index: 1090;
      pointer-events: none;
    }
    .custom-toast {
      pointer-events: auto;
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
      border-left: 5px solid var(--primary-color);
      padding: 0.85rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      min-width: 300px;
      opacity: 0;
      transform: translateX(50px);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .custom-toast.show {
      opacity: 1;
      transform: translateX(0);
    }
    .custom-toast.toast-success { border-left-color: var(--success-green); }
    .custom-toast.toast-error { border-left-color: #ef4444; }

    /* Pulse dot */
    .pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background-color: #f59e0b;
      display: inline-block;
      box-shadow: 0 0 0 rgba(245, 158, 11, 0.4);
      animation: pulseAmber 2s infinite;
    }
    @keyframes pulseAmber {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
    }
  </style>
</head>
<body>

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Toast Notification Container -->
  <div class="toast-container-custom" id="toastContainer">
    <div id="gradeActionToast" class="custom-toast" role="alert">
      <div id="toastIconBox" class="fs-4 text-success">
        <i class="bi bi-check-circle-fill"></i>
      </div>
      <div>
        <div class="fw-bold fs-8 text-dark" id="toastTitle">Success</div>
        <div class="text-secondary fs-9" id="toastMessage">Grade saved successfully!</div>
      </div>
    </div>
  </div>

  <!-- Main Container -->
  <main style="padding-top: 86px;" class="pb-5">
    <div class="container-fluid px-3 px-md-4 px-xl-5">

      <!-- Breadcrumbs -->
      <nav aria-label="breadcrumb" class="custom-breadcrumb pt-2">
        <ol class="breadcrumb fs-8 mb-0">
          <li class="breadcrumb-item"><a href="index.php"><i class="bi bi-house-door me-1"></i><?php echo __('nav_home', 'Home'); ?></a></li>
          <li class="breadcrumb-item"><a href="dashboard.php"><?php echo __('nav_dashboard', 'Dashboard'); ?></a></li>
          <li class="breadcrumb-item active" aria-current="page"><?php echo __('grade_assignments_btn', 'Grade Assignments'); ?></li>
        </ol>
      </nav>

      <!-- Page Header -->
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
          <h1 class="fw-bold text-dark mb-1 fs-3 d-flex align-items-center gap-2.5">
            <span class="rounded-circle p-2 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
              <i class="bi bi-journal-check fs-4"></i>
            </span>
            <span><?php echo __('assignment_grading_center', 'Assignment Submissions & Grading'); ?></span>
          </h1>
          <p class="text-secondary fs-8 mb-0">
            Review student submissions, evaluate coursework files, assign scores, and deliver constructive feedback with instant student notifications.
          </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <a href="student_analytics.php" class="btn btn-outline-primary btn-sm px-3.5 py-1.5 rounded-pill fw-semibold shadow-xs d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-graph-up-arrow"></i> <?php echo __('nav_student_analytics', 'Student Analytics'); ?>
          </a>
          <a href="dashboard.php" class="btn btn-outline-secondary btn-sm px-3.5 py-1.5 rounded-pill fw-semibold shadow-xs d-inline-flex align-items-center gap-1.5">
            <i class="bi bi-arrow-left"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
          </a>
        </div>
      </div>

      <!-- KPI Summary Cards (4 Cards) -->
      <div class="row g-2 g-md-3 mb-4 kpi-row-responsive">
        <!-- KPI 1: Total Submissions -->
        <div class="col-6 col-xl-3">
          <div class="kpi-card kpi-primary">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-10 tracking-wider d-block mb-1">Total Submissions</small>
                <h2 class="fw-bold text-dark mb-1 fs-3" id="kpi-total-subs"><?php echo number_format($total_submissions_count); ?></h2>
                <span class="text-muted fs-9 d-flex align-items-center gap-1">
                  <i class="bi bi-stack text-primary"></i> Across all your courses
                </span>
              </div>
              <div class="kpi-icon-box primary">
                <i class="bi bi-folder-check"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 2: Pending Grading -->
        <div class="col-6 col-xl-3">
          <div class="kpi-card kpi-warning">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-10 tracking-wider d-block mb-1">Pending Review</small>
                <h2 class="fw-bold text-dark mb-1 fs-3" style="color: #b45309 !important;" id="kpi-pending-subs"><?php echo number_format($pending_submissions_count); ?></h2>
                <span class="text-warning fw-semibold fs-9 d-flex align-items-center gap-1.5" style="color: #b45309 !important;">
                  <span class="pulse-dot"></span> Requires evaluation
                </span>
              </div>
              <div class="kpi-icon-box warning">
                <i class="bi bi-pencil-square"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 3: Graded Submissions -->
        <div class="col-6 col-xl-3">
          <div class="kpi-card kpi-success">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-10 tracking-wider d-block mb-1">Graded Coursework</small>
                <h2 class="fw-bold mb-1 fs-3" style="color: #047857 !important;" id="kpi-graded-subs"><?php echo number_format($graded_submissions_count); ?></h2>
                <span class="fw-semibold fs-9 d-flex align-items-center gap-1" style="color: #047857 !important;">
                  <i class="bi bi-check2-circle"></i> Feedback dispatched
                </span>
              </div>
              <div class="kpi-icon-box success">
                <i class="bi bi-award-fill"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 4: Average Score -->
        <div class="col-6 col-xl-3">
          <div class="kpi-card kpi-info">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-10 tracking-wider d-block mb-1">Average Grade Score</small>
                <h2 class="fw-bold mb-1 fs-3" style="color: #0891b2 !important;">
                  <span id="kpi-avg-score"><?php echo $avg_grade_overall; ?></span>
                  <span class="fs-7 text-muted fw-normal">/ 100</span>
                </h2>
                <span class="text-muted fs-9 d-flex align-items-center gap-1">
                  <i class="bi bi-graph-up text-info"></i> Overall cohort achievement
                </span>
              </div>
              <div class="kpi-icon-box info">
                <i class="bi bi-pie-chart-fill"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter Controls & Search Panel -->
      <div class="filter-panel">
        <form method="GET" action="teacher_assignments.php" id="filterForm" class="row g-3 align-items-center">
          
          <!-- Status Filter Tabs / Quick Toggle -->
          <div class="col-lg-4 col-md-12">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted tracking-wider mb-1">Filter by Status</label>
            <div class="btn-group w-100 filter-btn-group shadow-xs p-1 bg-light rounded-pill border" role="group">
              <a href="teacher_assignments.php?status=all<?php echo !empty($selected_course) ? '&course_id=' . urlencode($selected_course) : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                 class="btn <?php echo ($selected_status === 'all') ? 'active' : 'btn-light text-secondary'; ?>">
                All (<?php echo $total_submissions_count; ?>)
              </a>
              <a href="teacher_assignments.php?status=pending<?php echo !empty($selected_course) ? '&course_id=' . urlencode($selected_course) : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                 class="btn <?php echo ($selected_status === 'pending') ? 'active' : 'btn-light text-secondary'; ?>">
                Pending (<?php echo $pending_submissions_count; ?>)
              </a>
              <a href="teacher_assignments.php?status=graded<?php echo !empty($selected_course) ? '&course_id=' . urlencode($selected_course) : ''; ?><?php echo !empty($search_query) ? '&q=' . urlencode($search_query) : ''; ?>" 
                 class="btn <?php echo ($selected_status === 'graded') ? 'active' : 'btn-light text-secondary'; ?>">
                Graded (<?php echo $graded_submissions_count; ?>)
              </a>
            </div>
          </div>

          <!-- Course Selector -->
          <div class="col-lg-4 col-md-6">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted tracking-wider mb-1">Course Filter</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-light border-end-0 rounded-start-pill ps-3 text-secondary">
                <i class="bi bi-book"></i>
              </span>
              <select name="course_id" id="courseFilterSelect" class="form-select form-select-sm border-start-0 rounded-end-pill py-2 fs-8" onchange="this.form.submit()">
                <option value="">All Courses (<?php echo count($courses); ?> available)</option>
                <?php foreach ($courses as $c): ?>
                  <option value="<?php echo htmlspecialchars($c['id']); ?>" <?php echo ($selected_course === $c['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['title']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Search Input -->
          <div class="col-lg-4 col-md-6">
            <label class="form-label fs-9 fw-bold text-uppercase text-muted tracking-wider mb-1">Search Submissions</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-light border-end-0 rounded-start-pill ps-3 text-secondary">
                <i class="bi bi-search"></i>
              </span>
              <input type="text" name="q" id="searchInput" class="form-control border-start-0 border-end-0 py-2 fs-8" 
                     placeholder="Search student, email, lesson..." value="<?php echo htmlspecialchars($search_query); ?>"
                     onkeyup="clientFilterTable(this.value)">
              <button class="btn btn-primary rounded-end-pill px-3.5 fw-semibold" type="submit" style="background-color: var(--primary-color);">
                Search
              </button>
            </div>
          </div>

          <!-- Hidden status keeper if needed -->
          <input type="hidden" name="status" value="<?php echo htmlspecialchars($selected_status); ?>">

          <!-- Reset Filter Chips if active -->
          <?php if (!empty($selected_course) || $selected_status !== 'all' || !empty($search_query)): ?>
            <div class="col-12 pt-2 border-top mt-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="d-flex align-items-center gap-1.5 flex-wrap fs-8 text-muted">
                <span>Active Filters:</span>
                <?php if ($selected_status !== 'all'): ?>
                  <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1">Status: <?php echo ucfirst($selected_status); ?></span>
                <?php endif; ?>
                <?php if (!empty($selected_course)): ?>
                  <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1">Course Filtered</span>
                <?php endif; ?>
                <?php if (!empty($search_query)): ?>
                  <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1">Query: "<?php echo htmlspecialchars($search_query); ?>"</span>
                <?php endif; ?>
              </div>
              <a href="teacher_assignments.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fs-9 fw-semibold">
                <i class="bi bi-x-circle me-1"></i> Reset All Filters
              </a>
            </div>
          <?php endif; ?>
        </form>
      </div>

      <!-- Submissions List Table Card -->
      <div class="table-panel">
        <div class="table-panel-header">
          <div class="d-flex align-items-center gap-2">
            <h5 class="fw-bold text-dark mb-0 fs-6 d-flex align-items-center gap-2">
              <i class="bi bi-list-stars text-primary fs-5"></i>
              <span>Student Coursework Submissions</span>
            </h5>
            <span class="badge badge-subtle-primary rounded-pill px-2.5 py-1 fs-9 fw-bold" id="submissions-count-badge">
              <?php echo count($submissions); ?> Records
            </span>
          </div>
          <div class="text-muted fs-8 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle text-primary"></i>
            <span>Click <strong>"Grade"</strong> or <strong>"Edit Grade"</strong> to review work and notify students</span>
          </div>
        </div>

        <div class="p-0">
          <?php if (empty($submissions)): ?>
            <div class="text-center py-5 px-3">
              <div class="rounded-circle bg-light d-inline-flex p-3 mb-3 text-muted align-items-center justify-content-center" style="width: 64px; height: 64px;">
                <i class="bi bi-inbox fs-2 text-secondary"></i>
              </div>
              <h5 class="fw-bold text-dark fs-6 mb-1">No Coursework Submissions Found</h5>
              <p class="text-muted fs-8 mb-3" style="max-width: 440px; margin: 0 auto;">
                No student submissions match your current filter criteria. Check back once students submit their coursework or try clearing your filters.
              </p>
              <?php if (!empty($selected_course) || $selected_status !== 'all' || !empty($search_query)): ?>
                <a href="teacher_assignments.php" class="btn btn-sm btn-primary rounded-pill px-4 py-2 fw-semibold" style="background-color: var(--primary-color);">
                  <i class="bi bi-arrow-repeat me-1"></i> Clear Filters
                </a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table submissions-table align-middle mb-0" id="submissions-table">
                <thead>
                  <tr>
                    <th class="ps-4">Student</th>
                    <th>Course &amp; Lesson</th>
                    <th>Assignment Name</th>
                    <th>Submitted File</th>
                    <th>Submitted Date</th>
                    <th>Grade / Status</th>
                    <th class="text-end pe-4">Actions</th>
                  </tr>
                </thead>
                <tbody id="submissions-table-body">
                  <?php foreach ($submissions as $sub): 
                    $is_graded = ($sub['status'] === 'graded' && $sub['grade'] !== null);
                    $file_ext = strtolower(pathinfo($sub['file_name'], PATHINFO_EXTENSION));
                    $icon_class = 'bi-file-earmark-arrow-down-fill text-primary';
                    if ($file_ext === 'pdf') $icon_class = 'bi-file-earmark-pdf-fill text-danger';
                    elseif (in_array($file_ext, ['doc', 'docx'])) $icon_class = 'bi-file-earmark-word-fill text-primary';
                    elseif (in_array($file_ext, ['zip', 'rar', '7z'])) $icon_class = 'bi-file-earmark-zip-fill text-warning';
                    elseif (in_array($file_ext, ['png', 'jpg', 'jpeg', 'webp'])) $icon_class = 'bi-file-earmark-image-fill text-info';
                  ?>
                    <tr class="submission-row-item" id="sub-row-<?php echo $sub['id']; ?>"
                        data-student="<?php echo htmlspecialchars(strtolower($sub['student_name'] . ' ' . $sub['student_email'])); ?>"
                        data-course="<?php echo htmlspecialchars(strtolower($sub['course_title'] . ' ' . $sub['lesson_title'])); ?>"
                        data-assignment="<?php echo htmlspecialchars(strtolower($sub['assignment_title'])); ?>"
                        data-status="<?php echo $is_graded ? 'graded' : 'pending'; ?>">
                      
                      <!-- Student Info -->
                      <td class="ps-4">
                        <div class="d-flex align-items-center gap-2.5">
                          <img src="<?php echo htmlspecialchars($sub['resolved_avatar']); ?>" 
                               class="rounded-circle border shadow-xs" style="width: 38px; height: 38px; object-fit: cover;" alt="Avatar">
                          <div class="text-truncate" style="max-width: 180px;">
                            <span class="fw-bold text-dark d-block fs-8 text-truncate"><?php echo htmlspecialchars($sub['student_name']); ?></span>
                            <small class="text-muted fs-9 text-truncate d-block"><?php echo htmlspecialchars($sub['student_email']); ?></small>
                          </div>
                        </div>
                      </td>

                      <!-- Course & Lesson -->
                      <td>
                        <span class="fw-bold text-dark d-block text-truncate fs-8" style="max-width: 220px;" title="<?php echo htmlspecialchars($sub['course_title']); ?>">
                          <?php echo htmlspecialchars($sub['course_title']); ?>
                        </span>
                        <small class="text-secondary d-flex align-items-center gap-1 text-truncate fs-9 mt-0.5" style="max-width: 220px;" title="<?php echo htmlspecialchars($sub['lesson_title']); ?>">
                          <i class="bi bi-play-circle-fill text-primary fs-10"></i>
                          <span class="text-truncate"><?php echo htmlspecialchars($sub['lesson_title']); ?></span>
                        </small>
                      </td>

                      <!-- Assignment Name -->
                      <td>
                        <span class="fw-semibold text-dark d-block text-truncate fs-8" style="max-width: 200px;" title="<?php echo htmlspecialchars($sub['assignment_title']); ?>">
                          <?php echo htmlspecialchars($sub['assignment_title']); ?>
                        </span>
                        <?php if (!empty($sub['assignment_due_date'])): ?>
                          <span class="badge bg-light text-secondary border rounded-pill px-2 py-0.5 fs-10 mt-1 d-inline-flex align-items-center gap-1">
                            <i class="bi bi-calendar-event"></i> Due: <?php echo date('M d, Y', strtotime($sub['assignment_due_date'])); ?>
                          </span>
                        <?php endif; ?>
                      </td>

                      <!-- Submitted File -->
                      <td>
                        <a href="download_assignment.php?type=submission&id=<?php echo urlencode($sub['id']); ?>" 
                           class="file-chip shadow-xs"
                           title="Download and inspect <?php echo htmlspecialchars($sub['file_name']); ?>">
                          <i class="bi <?php echo $icon_class; ?> fs-6"></i>
                          <span class="file-name"><?php echo htmlspecialchars($sub['file_name']); ?></span>
                          <small class="text-muted fw-normal fs-10">(<?php echo $sub['formatted_size']; ?>)</small>
                        </a>
                        <?php if (!empty($sub['student_notes'])): ?>
                          <div>
                            <span class="student-note-snippet" title="<?php echo htmlspecialchars($sub['student_notes']); ?>">
                              <i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($sub['student_notes']); ?>
                            </span>
                          </div>
                        <?php endif; ?>
                      </td>

                      <!-- Submitted Date -->
                      <td>
                        <span class="text-dark fw-semibold d-block fs-8"><?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?></span>
                        <small class="text-muted fs-9"><?php echo date('h:i A', strtotime($sub['submitted_at'])); ?></small>
                      </td>

                      <!-- Grade / Status (HIGH CONTRAST, GORGEOUS) -->
                      <td id="sub-status-cell-<?php echo $sub['id']; ?>">
                        <?php if ($is_graded): ?>
                          <div>
                            <span class="score-badge">
                              <i class="bi bi-award-fill"></i> <?php echo (float)$sub['grade']; ?> / 100
                            </span>
                            <?php if (!empty($sub['feedback'])): ?>
                              <small class="d-block text-muted fs-9 text-truncate mt-1" style="max-width: 170px;" title="<?php echo htmlspecialchars($sub['feedback']); ?>">
                                <i class="bi bi-chat-quote-fill text-primary me-1"></i><?php echo htmlspecialchars($sub['feedback']); ?>
                              </small>
                            <?php endif; ?>
                          </div>
                        <?php else: ?>
                          <span class="status-badge-pending">
                            <i class="bi bi-hourglass-split"></i> Pending Review
                          </span>
                        <?php endif; ?>
                      </td>

                      <!-- Actions -->
                      <td class="text-end pe-4 text-nowrap">
                        <div class="d-inline-flex align-items-center gap-1.5">
                          <button type="button" 
                                  class="btn btn-sm <?php echo $is_graded ? 'btn-grade-outline' : 'btn-grade-primary'; ?> btn-open-grade-modal"
                                  data-sub-id="<?php echo $sub['id']; ?>"
                                  data-student-name="<?php echo htmlspecialchars($sub['student_name']); ?>"
                                  data-student-email="<?php echo htmlspecialchars($sub['student_email']); ?>"
                                  data-course-title="<?php echo htmlspecialchars($sub['course_title']); ?>"
                                  data-lesson-title="<?php echo htmlspecialchars($sub['lesson_title']); ?>"
                                  data-assignment-title="<?php echo htmlspecialchars($sub['assignment_title']); ?>"
                                  data-file-name="<?php echo htmlspecialchars($sub['file_name']); ?>"
                                  data-file-size="<?php echo $sub['formatted_size']; ?>"
                                  data-download-url="download_assignment.php?type=submission&id=<?php echo urlencode($sub['id']); ?>"
                                  data-student-notes="<?php echo htmlspecialchars($sub['student_notes'] ?? ''); ?>"
                                  data-current-grade="<?php echo $is_graded ? (float)$sub['grade'] : ''; ?>"
                                  data-current-feedback="<?php echo htmlspecialchars($sub['feedback'] ?? ''); ?>">
                            <i class="bi <?php echo $is_graded ? 'bi-pencil-square' : 'bi-award-fill'; ?>"></i>
                            <span><?php echo $is_graded ? 'Edit Grade' : 'Grade'; ?></span>
                          </button>
                          
                          <a href="watch_lesson.php?course_id=<?php echo urlencode($sub['course_id']); ?>&lesson_id=<?php echo urlencode($sub['lesson_id']); ?>" 
                             target="_blank" 
                             class="btn-icon-circle" 
                             title="Open Lesson Workspace in New Tab">
                            <i class="bi bi-box-arrow-up-right fs-9"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>

  <!-- Grading & Evaluation Modal (Redesigned) -->
  <div class="modal fade modal-grade text-dark" id="gradeAssignmentModal" tabindex="-1" aria-labelledby="gradeAssignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        
        <!-- Modal Header -->
        <div class="modal-header">
          <div class="d-flex align-items-center gap-2.5">
            <span class="rounded-circle bg-white bg-opacity-20 p-2 d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
              <i class="bi bi-award-fill fs-5 text-white"></i>
            </span>
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="gradeAssignmentModalLabel">
                Evaluate &amp; Grade Student Coursework
              </h5>
              <small class="text-white text-opacity-80 fs-9">Review submitted document and assign score</small>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="gradeSubmissionForm" onsubmit="submitAssignmentGrade(event)">
          <input type="hidden" id="modal-sub-id" name="submission_id" value="">

          <div class="modal-body p-4">
            
            <!-- Student & Course Details Brief Card -->
            <div class="student-brief-card mb-3.5">
              <div class="row g-2.5">
                <div class="col-sm-6 border-end-sm">
                  <small class="text-muted fs-10 fw-bold text-uppercase tracking-wider d-block mb-1">Student</small>
                  <strong class="text-dark fs-7 d-block" id="modal-student-name">Student Name</strong>
                  <span class="text-muted fs-8" id="modal-student-email">email@example.com</span>
                </div>
                <div class="col-sm-6 ps-sm-3">
                  <small class="text-muted fs-10 fw-bold text-uppercase tracking-wider d-block mb-1">Course &amp; Lesson</small>
                  <strong class="text-dark fs-7 d-block text-truncate" id="modal-course-title">Course Title</strong>
                  <span class="text-muted fs-8 text-truncate d-block" id="modal-lesson-title">Lesson Title</span>
                </div>
                <div class="col-12 mt-2 pt-2 border-top">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                      <i class="bi bi-file-earmark-check-fill text-primary fs-4"></i>
                      <div>
                        <span class="fw-bold text-dark fs-8 d-block" id="modal-file-name">file.pdf</span>
                        <small class="text-muted fs-9" id="modal-file-size">(1.2 MB)</small>
                      </div>
                    </div>
                    <a href="#" id="modal-download-btn" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1 fs-8 fw-semibold shadow-xs">
                      <i class="bi bi-cloud-arrow-down-fill me-1"></i> Download &amp; Inspect File
                    </a>
                  </div>
                </div>
                <div class="col-12 mt-1" id="modal-notes-container">
                  <small class="text-muted fs-10 fw-bold text-uppercase tracking-wider d-block mb-1">Student's Submission Remarks</small>
                  <p class="fs-8 text-secondary mb-0 p-2.5 bg-white rounded-3 border fst-italic" id="modal-student-notes">None provided</p>
                </div>
              </div>
            </div>

            <!-- Grade Inputs -->
            <div class="row g-3">
              
              <!-- Score Input -->
              <div class="col-md-5">
                <label for="modal-grade-score" class="form-label fw-bold text-dark fs-8 mb-1 d-flex align-items-center justify-content-between">
                  <span>Score / Grade (0 - 100) <span class="text-danger">*</span></span>
                  <span class="badge bg-light text-primary border rounded-pill px-2 py-0.5 fs-10" id="letter-grade-badge">Pending</span>
                </label>
                <div class="input-group">
                  <input type="number" step="0.5" min="0" max="100" 
                         class="form-control form-control-lg fw-bold text-primary rounded-start-3" 
                         id="modal-grade-score" name="grade" placeholder="e.g. 85.0" required
                         oninput="updateScoreMeter(this.value)">
                  <span class="input-group-text fw-bold bg-light text-muted rounded-end-3">/ 100</span>
                </div>

                <!-- Visual Score Progress Meter -->
                <div class="score-meter-container">
                  <div class="score-meter-fill" id="modal-score-meter"></div>
                </div>

                <!-- Score Preset Buttons -->
                <div class="d-flex gap-1 mt-2 flex-wrap align-items-center">
                  <small class="text-muted fs-10 fw-bold text-uppercase me-1">Quick Set:</small>
                  <button type="button" class="preset-btn" onclick="setPresetScore(100)">100</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(95)">95</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(90)">90</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(85)">85</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(80)">80</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(75)">75</button>
                  <button type="button" class="preset-btn" onclick="setPresetScore(60)">60</button>
                </div>
              </div>

              <!-- Feedback Textarea -->
              <div class="col-md-7">
                <label for="modal-feedback-text" class="form-label fw-bold text-dark fs-8 mb-1">
                  Teacher Feedback &amp; Remarks (Optional)
                </label>
                <textarea class="form-control rounded-3 fs-8" id="modal-feedback-text" name="feedback" rows="4" 
                          placeholder="Provide constructive feedback, praise, or areas for improvement..."></textarea>
                
                <!-- Quick Feedback Preset Chips -->
                <div class="mt-1.5">
                  <small class="text-muted fs-10 fw-bold text-uppercase d-block mb-1">Suggestions:</small>
                  <span class="feedback-chip" onclick="insertFeedback('🌟 Outstanding submission! Proofs and solutions are well presented.')">🌟 Outstanding work</span>
                  <span class="feedback-chip" onclick="insertFeedback('✅ Well done! Accurate analysis and clean documentation.')">✅ Clean implementation</span>
                  <span class="feedback-chip" onclick="insertFeedback('💡 Good effort. Review the comments to improve on future tasks.')">💡 Good effort</span>
                  <span class="feedback-chip" onclick="insertFeedback('⚠️ Incomplete. Please address missing requirements and resubmit.')">⚠️ Needs revision</span>
                </div>
              </div>

              <!-- Notification Checkbox -->
              <div class="col-12 mt-3 pt-2 border-top">
                <div class="form-check form-switch d-flex align-items-center gap-2">
                  <input class="form-check-input mt-0" type="checkbox" id="modal-send-notification" name="send_notification" value="1" checked style="width: 2.2em; height: 1.2em; cursor: pointer;">
                  <label class="form-check-label fs-8 text-dark fw-semibold" for="modal-send-notification" style="cursor: pointer;">
                    <i class="bi bi-bell-fill text-warning me-1"></i> Send instant notification to student with their evaluation score &amp; feedback
                  </label>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer border-0 pt-0 px-4 pb-4">
            <button type="button" class="btn btn-light rounded-pill px-4 fw-semibold text-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" id="modal-submit-btn" class="btn btn-primary rounded-pill px-4.5 py-2 fw-bold shadow-sm" style="background-color: var(--primary-color);">
              <i class="bi bi-check-circle-fill me-1"></i> Save Grade &amp; Notify Student
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Local Bootstrap 5 Bundle JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <!-- Modern Notification System Client -->
  <script src="assets/js/notifications.js"></script>

  <script>
    let gradeModalInstance = null;

    document.addEventListener('DOMContentLoaded', function() {
      const modalEl = document.getElementById('gradeAssignmentModal');
      if (modalEl) {
        gradeModalInstance = new bootstrap.Modal(modalEl);
      }

      // Bind open modal buttons
      document.querySelectorAll('.btn-open-grade-modal').forEach(btn => {
        btn.addEventListener('click', function() {
          const subId = this.getAttribute('data-sub-id');
          const studentName = this.getAttribute('data-student-name');
          const studentEmail = this.getAttribute('data-student-email');
          const courseTitle = this.getAttribute('data-course-title');
          const lessonTitle = this.getAttribute('data-lesson-title');
          const fileName = this.getAttribute('data-file-name');
          const fileSize = this.getAttribute('data-file-size');
          const downloadUrl = this.getAttribute('data-download-url');
          const studentNotes = this.getAttribute('data-student-notes');
          const currentGrade = this.getAttribute('data-current-grade');
          const currentFeedback = this.getAttribute('data-current-feedback');

          document.getElementById('modal-sub-id').value = subId;
          document.getElementById('modal-student-name').textContent = studentName;
          document.getElementById('modal-student-email').textContent = studentEmail;
          document.getElementById('modal-course-title').textContent = courseTitle;
          document.getElementById('modal-lesson-title').textContent = lessonTitle;
          document.getElementById('modal-file-name').textContent = fileName;
          document.getElementById('modal-file-size').textContent = `(${fileSize})`;
          document.getElementById('modal-download-btn').setAttribute('href', downloadUrl);

          const notesBox = document.getElementById('modal-student-notes');
          const notesContainer = document.getElementById('modal-notes-container');
          if (studentNotes && studentNotes.trim() !== '') {
            notesBox.textContent = `"${studentNotes}"`;
            notesContainer.classList.remove('d-none');
          } else {
            notesBox.textContent = 'None provided';
          }

          document.getElementById('modal-grade-score').value = currentGrade || '';
          document.getElementById('modal-feedback-text').value = currentFeedback || '';
          document.getElementById('modal-send-notification').checked = true;

          updateScoreMeter(currentGrade || 0);

          const submitBtn = document.getElementById('modal-submit-btn');
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Save Grade & Notify Student';

          if (gradeModalInstance) {
            gradeModalInstance.show();
          }
        });
      });
    });

    // Score meter and letter grade visualizer
    function updateScoreMeter(val) {
      const meter = document.getElementById('modal-score-meter');
      const badge = document.getElementById('letter-grade-badge');
      const num = parseFloat(val);

      if (isNaN(num) || num <= 0) {
        meter.style.width = '0%';
        badge.textContent = 'Pending';
        badge.className = 'badge bg-light text-secondary border rounded-pill px-2 py-0.5 fs-10';
        return;
      }

      const clamped = Math.min(Math.max(num, 0), 100);
      meter.style.width = clamped + '%';

      if (clamped >= 90) {
        meter.style.backgroundColor = '#10b981';
        badge.textContent = 'A+ (Outstanding)';
        badge.className = 'badge bg-success text-white border-0 rounded-pill px-2 py-0.5 fs-10';
      } else if (clamped >= 80) {
        meter.style.backgroundColor = '#10b981';
        badge.textContent = 'A (Excellent)';
        badge.className = 'badge bg-success text-white border-0 rounded-pill px-2 py-0.5 fs-10';
      } else if (clamped >= 70) {
        meter.style.backgroundColor = '#06b6d4';
        badge.textContent = 'B (Good)';
        badge.className = 'badge bg-info text-dark border-0 rounded-pill px-2 py-0.5 fs-10';
      } else if (clamped >= 60) {
        meter.style.backgroundColor = '#f59e0b';
        badge.textContent = 'C (Satisfactory)';
        badge.className = 'badge bg-warning text-dark border-0 rounded-pill px-2 py-0.5 fs-10';
      } else if (clamped >= 50) {
        meter.style.backgroundColor = '#f97316';
        badge.textContent = 'D (Pass)';
        badge.className = 'badge bg-warning text-dark border-0 rounded-pill px-2 py-0.5 fs-10';
      } else {
        meter.style.backgroundColor = '#ef4444';
        badge.textContent = 'F (Revision Required)';
        badge.className = 'badge bg-danger text-white border-0 rounded-pill px-2 py-0.5 fs-10';
      }
    }

    function setPresetScore(val) {
      const scoreInput = document.getElementById('modal-grade-score');
      if (scoreInput) {
        scoreInput.value = val;
        updateScoreMeter(val);
        scoreInput.focus();
      }
    }

    function insertFeedback(text) {
      const fb = document.getElementById('modal-feedback-text');
      if (fb) {
        if (fb.value.trim() === '') {
          fb.value = text;
        } else {
          fb.value += "\n" + text;
        }
        fb.focus();
      }
    }

    // Client-side instant filter for search
    function clientFilterTable(query) {
      const q = query.trim().toLowerCase();
      const rows = document.querySelectorAll('.submission-row-item');
      let visibleCount = 0;

      rows.forEach(row => {
        const student = row.getAttribute('data-student') || '';
        const course = row.getAttribute('data-course') || '';
        const asg = row.getAttribute('data-assignment') || '';
        
        if (q === '' || student.includes(q) || course.includes(q) || asg.includes(q)) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const countBadge = document.getElementById('submissions-count-badge');
      if (countBadge) {
        countBadge.textContent = visibleCount + ' Records';
      }
    }

    // Toast Alert Helper
    function showCustomToast(title, message, isSuccess = true) {
      const toast = document.getElementById('gradeActionToast');
      const iconBox = document.getElementById('toastIconBox');
      const titleEl = document.getElementById('toastTitle');
      const msgEl = document.getElementById('toastMessage');

      titleEl.textContent = title;
      msgEl.textContent = message;

      if (isSuccess) {
        toast.className = 'custom-toast toast-success show';
        iconBox.className = 'fs-4 text-success';
        iconBox.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
      } else {
        toast.className = 'custom-toast toast-error show';
        iconBox.className = 'fs-4 text-danger';
        iconBox.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i>';
      }

      setTimeout(() => {
        toast.classList.remove('show');
      }, 3500);
    }

    // AJAX Grade Submission
    function submitAssignmentGrade(e) {
      e.preventDefault();
      const submitBtn = document.getElementById('modal-submit-btn');
      const subId = document.getElementById('modal-sub-id').value;
      const grade = document.getElementById('modal-grade-score').value;
      const feedback = document.getElementById('modal-feedback-text').value;
      const sendNotif = document.getElementById('modal-send-notification').checked ? 1 : 0;

      if (!grade || isNaN(grade) || parseFloat(grade) < 0 || parseFloat(grade) > 100) {
        showCustomToast('Validation Warning', 'Please enter a valid grade between 0 and 100.', false);
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status"></span> Saving &amp; Notifying...';

      fetch('api/grade_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          submission_id: parseInt(subId),
          grade: parseFloat(grade),
          feedback: feedback,
          send_notification: sendNotif
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          // Update row in table dynamically
          const statusCell = document.getElementById(`sub-status-cell-${subId}`);
          if (statusCell) {
            let feedbackSnippet = '';
            if (data.feedback && data.feedback.trim() !== '') {
              const escFeed = data.feedback.replace(/"/g, '&quot;');
              feedbackSnippet = `<small class="d-block text-muted fs-9 text-truncate mt-1" style="max-width: 170px;" title="${escFeed}"><i class="bi bi-chat-quote-fill text-primary me-1"></i>${escFeed}</small>`;
            }
            statusCell.innerHTML = `
              <div>
                <span class="score-badge">
                  <i class="bi bi-award-fill"></i> ${parseFloat(data.grade)} / 100
                </span>
                ${feedbackSnippet}
              </div>
            `;
          }

          // Update trigger button state
          const row = document.getElementById(`sub-row-${subId}`);
          if (row) {
            row.setAttribute('data-status', 'graded');
            const btn = row.querySelector('.btn-open-grade-modal');
            if (btn) {
              btn.setAttribute('data-current-grade', data.grade);
              btn.setAttribute('data-current-feedback', data.feedback || '');
              btn.className = 'btn btn-sm btn-grade-outline btn-open-grade-modal';
              btn.innerHTML = '<i class="bi bi-pencil-square"></i><span>Edit Grade</span>';
            }
          }

          // Update KPI counters
          const pendingKpi = document.getElementById('kpi-pending-subs');
          if (pendingKpi) {
            const currentPending = parseInt(pendingKpi.textContent) || 0;
            if (currentPending > 0) pendingKpi.textContent = currentPending - 1;
          }
          const gradedKpi = document.getElementById('kpi-graded-subs');
          if (gradedKpi) {
            const currentGraded = parseInt(gradedKpi.textContent) || 0;
            gradedKpi.textContent = currentGraded + 1;
          }

          if (gradeModalInstance) {
            gradeModalInstance.hide();
          }

          showCustomToast('Grade Recorded', `Score ${parseFloat(data.grade)}/100 saved and student notified!`, true);
        } else {
          showCustomToast('Error', data.message || 'Failed to save grade.', false);
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Save Grade & Notify Student';
        }
      })
      .catch(err => {
        console.error(err);
        showCustomToast('Network Error', 'Server communication error. Please try again.', false);
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Save Grade & Notify Student';
      });
    }
  </script>
</body>
</html>
