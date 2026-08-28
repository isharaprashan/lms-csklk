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

    // Fetch current user details
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
        header("Location: dashboard.php");
        exit;
    }

    // Fetch instructor's courses (or all courses if admin)
    if ($is_admin) {
        $stmt = $pdo->query("SELECT id, title, category, thumbnail, created_at FROM courses ORDER BY title ASC");
    } else {
        $stmt = $pdo->prepare("SELECT id, title, category, thumbnail, created_at FROM courses WHERE tutor_id = ? ORDER BY title ASC");
        $stmt->execute([$user_id]);
    }
    $teacher_courses = $stmt->fetchAll();
    $teacher_course_ids = array_column($teacher_courses, 'id');

    // Initialize all data structures
    $total_students = 0;
    $active_learners_count = 0;
    $avg_course_completion = 0;
    $avg_quiz_score_overall = 0;
    $quiz_pass_rate_overall = 0;
    $total_quiz_attempts_count = 0;
    $total_study_seconds = 0;
    $student_summaries = [];
    $matrix_rows = [];
    $lesson_insights = [];

    if (!empty($teacher_course_ids)) {
        $in_clause = implode(',', array_fill(0, count($teacher_course_ids), '?'));

        // 1. Fetch all lessons for these courses
        $stmt = $pdo->prepare("SELECT id, course_id, title, duration, sort_order FROM lessons WHERE course_id IN ($in_clause) ORDER BY course_id, sort_order ASC, id ASC");
        $stmt->execute($teacher_course_ids);
        $all_lessons = $stmt->fetchAll();

        // 2. Fetch all quizzes info (lessons with quizzes)
        $stmt = $pdo->prepare("SELECT lesson_id, course_id, COUNT(*) as question_count FROM quizzes WHERE course_id IN ($in_clause) GROUP BY lesson_id, course_id");
        $stmt->execute($teacher_course_ids);
        $quizzes_info = $stmt->fetchAll();
        $lessons_with_quizzes = [];
        foreach ($quizzes_info as $qi) {
            $lessons_with_quizzes[$qi['lesson_id']] = (int) $qi['question_count'];
        }

        // 3. Fetch all enrollments
        $stmt = $pdo->prepare("SELECT e.user_id, e.course_id, u.name as student_name, u.email as student_email, u.academic_id, u.avatar as student_avatar, u.status as user_status, c.title as course_title
                               FROM enrollments e
                               JOIN users u ON e.user_id = u.id
                               JOIN courses c ON e.course_id = c.id
                               WHERE e.course_id IN ($in_clause)
                               ORDER BY c.title ASC, u.name ASC");
        $stmt->execute($teacher_course_ids);
        $enrollments = $stmt->fetchAll();

        // 4. Fetch all video watch progress
        $stmt = $pdo->prepare("SELECT lp.user_id, lp.lesson_id, lp.position_seconds, lp.duration_seconds, lp.progress_percent, lp.completed, lp.updated_at, l.course_id
                               FROM lesson_progress lp
                               JOIN lessons l ON lp.lesson_id = l.id
                               WHERE l.course_id IN ($in_clause)");
        $stmt->execute($teacher_course_ids);
        $progress_data = $stmt->fetchAll();

        // 5. Fetch all completed lessons
        $stmt = $pdo->prepare("SELECT cl.user_id, cl.lesson_id, l.course_id
                               FROM completed_lessons cl
                               JOIN lessons l ON cl.lesson_id = l.id
                               WHERE l.course_id IN ($in_clause)");
        $stmt->execute($teacher_course_ids);
        $completed_lessons_data = $stmt->fetchAll();

        // 6. Fetch all quiz attempts (best score, finalized state, attempt counts)
        $stmt = $pdo->prepare("SELECT user_id, course_id, lesson_id, MAX(score) as best_score, MAX(total_questions) as total_questions, MAX(attempt_number) as attempts_count,
                                      MAX(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) as is_finalized,
                                      MAX(updated_at) as last_attempt_at
                               FROM quiz_attempts
                               WHERE course_id IN ($in_clause)
                               GROUP BY user_id, course_id, lesson_id");
        $stmt->execute($teacher_course_ids);
        $quiz_attempts_data = $stmt->fetchAll();

        // Build indexing maps
        $course_lessons_map = [];
        foreach ($all_lessons as $l) {
            $course_lessons_map[$l['course_id']][] = $l;
        }

        $progress_map = [];
        foreach ($progress_data as $p) {
            $progress_map[$p['user_id'] . '_' . $p['lesson_id']] = $p;
            $total_study_seconds += (float) $p['position_seconds'];
        }

        $completed_lessons_map = [];
        foreach ($completed_lessons_data as $cl) {
            $completed_lessons_map[$cl['user_id'] . '_' . $cl['lesson_id']] = true;
        }

        $quiz_attempts_map = [];
        foreach ($quiz_attempts_data as $qa) {
            $key = $qa['user_id'] . '_' . $qa['course_id'] . '_' . ($qa['lesson_id'] ?: '');
            $quiz_attempts_map[$key] = $qa;
        }

        // Compute Student Aggregates
        $active_learners_set = [];
        $unique_students_set = [];
        $total_progress_accum = 0;
        $all_finalized_quiz_scores = [];
        $all_quiz_pass_count = 0;
        $all_quiz_attempt_count = 0;

        foreach ($enrollments as $enr) {
            $uid = $enr['user_id'];
            $cid = $enr['course_id'];
            $unique_students_set[$uid] = true;

            $c_lessons = $course_lessons_map[$cid] ?? [];
            $total_lessons_count = count($c_lessons);
            $completed_lessons_count = 0;
            $total_quizzes_count = 0;
            $passed_quizzes_count = 0;
            $finalized_quizzes_count = 0;
            $student_quiz_scores_pct = [];
            $student_watch_seconds = 0;
            $student_lesson_details = [];
            $last_active_time = null;

            foreach ($c_lessons as $idx => $les) {
                $lid = $les['id'];
                $prog_key = $uid . '_' . $lid;
                $quiz_key = $uid . '_' . $cid . '_' . $lid;
                $quiz_fallback_key = $uid . '_' . $cid . '_';

                $prog = $progress_map[$prog_key] ?? null;
                $is_completed_lesson = isset($completed_lessons_map[$prog_key]) || ($prog && ($prog['completed'] == 1 || (float) $prog['progress_percent'] >= 90));
                $pct_watched = $prog ? (float) $prog['progress_percent'] : 0;
                if ($is_completed_lesson && $pct_watched < 100)
                    $pct_watched = 100;
                $pos_sec = $prog ? (float) $prog['position_seconds'] : 0;
                $dur_sec = $prog ? (float) $prog['duration_seconds'] : 0;
                $student_watch_seconds += $pos_sec;

                if ($prog && !empty($prog['updated_at'])) {
                    if (!$last_active_time || strtotime($prog['updated_at']) > strtotime($last_active_time)) {
                        $last_active_time = $prog['updated_at'];
                    }
                }

                if ($is_completed_lesson) {
                    $completed_lessons_count++;
                }

                // Check quiz for this lesson
                $has_quiz = isset($lessons_with_quizzes[$lid]);
                $quiz_data = $quiz_attempts_map[$quiz_key] ?? ($idx === 0 ? ($quiz_attempts_map[$quiz_fallback_key] ?? null) : null);

                $q_score = null;
                $q_total = 0;
                $q_attempts = 0;
                $q_is_finalized = false;
                $q_passed = false;
                $q_pct = 0;

                if ($has_quiz) {
                    $total_quizzes_count++;
                    if ($quiz_data) {
                        $q_score = (int) $quiz_data['best_score'];
                        $q_total = (int) $quiz_data['total_questions'] ?: ($lessons_with_quizzes[$lid] ?? 0);
                        $q_attempts = (int) $quiz_data['attempts_count'];
                        $q_is_finalized = ((int) $quiz_data['is_finalized'] === 1);
                        $q_pct = ($q_total > 0) ? round(($q_score / $q_total) * 100) : 0;
                        $q_passed = ($q_pct >= 50);

                        $all_quiz_attempt_count += $q_attempts;

                        if ($q_is_finalized) {
                            $finalized_quizzes_count++;
                            $student_quiz_scores_pct[] = $q_pct;
                            $all_finalized_quiz_scores[] = $q_pct;
                        }
                        if ($q_passed) {
                            $passed_quizzes_count++;
                            $all_quiz_pass_count++;
                        }
                        if (!empty($quiz_data['last_attempt_at'])) {
                            if (!$last_active_time || strtotime($quiz_data['last_attempt_at']) > strtotime($last_active_time)) {
                                $last_active_time = $quiz_data['last_attempt_at'];
                            }
                        }
                    }
                }

                if ($pos_sec > 0 || $q_attempts > 0) {
                    $active_learners_set[$uid] = true;
                }

                $lesson_fully_completed = $is_completed_lesson && (!$has_quiz || $q_is_finalized);

                $lesson_detail = [
                    'lesson_id' => $lid,
                    'lesson_title' => $les['title'],
                    'lesson_order' => $idx + 1,
                    'lesson_duration' => $les['duration'],
                    'progress_percent' => $pct_watched,
                    'position_seconds' => $pos_sec,
                    'duration_seconds' => $dur_sec,
                    'is_completed' => $is_completed_lesson,
                    'has_quiz' => $has_quiz,
                    'quiz_score' => $q_score,
                    'quiz_total' => $q_total,
                    'quiz_attempts' => $q_attempts,
                    'quiz_finalized' => $q_is_finalized,
                    'quiz_passed' => $q_passed,
                    'quiz_pct' => $q_pct,
                    'is_fully_completed' => $lesson_fully_completed
                ];

                $student_lesson_details[] = $lesson_detail;

                $resolved_student_avatar = get_user_avatar($enr['student_avatar'], $enr['student_name'], '0f4c81', 'fff');

                $matrix_rows[] = [
                    'student_id' => $uid,
                    'student_name' => $enr['student_name'],
                    'student_email' => $enr['student_email'],
                    'student_academic_id' => $enr['academic_id'],
                    'student_avatar' => $resolved_student_avatar,
                    'course_id' => $cid,
                    'course_title' => $enr['course_title'],
                    'lesson_id' => $lid,
                    'lesson_title' => $les['title'],
                    'lesson_order' => $idx + 1,
                    'progress_percent' => $pct_watched,
                    'position_seconds' => $pos_sec,
                    'duration_seconds' => $dur_sec,
                    'is_completed' => $is_completed_lesson,
                    'has_quiz' => $has_quiz,
                    'quiz_score' => $q_score,
                    'quiz_total' => $q_total,
                    'quiz_attempts' => $q_attempts,
                    'quiz_finalized' => $q_is_finalized,
                    'quiz_passed' => $q_passed,
                    'is_fully_completed' => $lesson_fully_completed
                ];
            }

            $total_milestones = $total_lessons_count + $total_quizzes_count;
            $completed_milestones = $completed_lessons_count + $finalized_quizzes_count;
            $overall_progress = ($total_milestones > 0) ? round(($completed_milestones / $total_milestones) * 100) : 0;
            $total_progress_accum += $overall_progress;

            $avg_quiz_score = count($student_quiz_scores_pct) > 0 ? round(array_sum($student_quiz_scores_pct) / count($student_quiz_scores_pct)) : null;

            if ($overall_progress >= 100) {
                $learning_status = '100% Completed';
                $status_badge_class = 'bg-success text-white';
            } elseif ($overall_progress >= 40) {
                $learning_status = 'On Track';
                $status_badge_class = 'bg-primary text-white';
            } elseif ($overall_progress > 0) {
                $learning_status = 'Needs Attention';
                $status_badge_class = 'bg-warning text-dark';
            } else {
                $learning_status = 'Not Started';
                $status_badge_class = 'bg-secondary text-white';
            }

            $resolved_student_avatar = get_user_avatar($enr['student_avatar'], $enr['student_name'], '0f4c81', 'fff');

            $student_summaries[] = [
                'student_id' => $uid,
                'student_name' => $enr['student_name'],
                'student_email' => $enr['student_email'],
                'student_academic_id' => $enr['academic_id'] ?: 'N/A',
                'student_avatar' => $resolved_student_avatar,
                'course_id' => $cid,
                'course_title' => $enr['course_title'],
                'total_lessons' => $total_lessons_count,
                'completed_lessons' => $completed_lessons_count,
                'total_quizzes' => $total_quizzes_count,
                'passed_quizzes' => $passed_quizzes_count,
                'finalized_quizzes' => $finalized_quizzes_count,
                'avg_quiz_score' => $avg_quiz_score,
                'overall_progress' => $overall_progress,
                'learning_status' => $learning_status,
                'status_badge_class' => $status_badge_class,
                'total_watch_seconds' => $student_watch_seconds,
                'last_active' => $last_active_time ? date('M d, Y H:i', strtotime($last_active_time)) : 'Never',
                'lessons' => $student_lesson_details
            ];
        }

        // Global KPI Metrics
        $total_students = count($unique_students_set);
        $active_learners_count = count($active_learners_set);
        $enrollments_count = count($enrollments);
        $avg_course_completion = ($enrollments_count > 0) ? round($total_progress_accum / $enrollments_count) : 0;
        $avg_quiz_score_overall = (count($all_finalized_quiz_scores) > 0) ? round(array_sum($all_finalized_quiz_scores) / count($all_finalized_quiz_scores)) : 0;
        $total_quiz_attempts_count = $all_quiz_attempt_count;
        $quiz_pass_rate_overall = (count($all_finalized_quiz_scores) > 0) ? round(($all_quiz_pass_count / count($all_finalized_quiz_scores)) * 100) : 0;

        // 7. Compute Lesson Insights & Drop-off Analytics (Tab 3)
        foreach ($all_lessons as $l) {
            $cid = $l['course_id'];
            $lid = $l['id'];

            $enrolled_in_course = array_filter($enrollments, fn($e) => $e['course_id'] === $cid);
            $c_total_enrolled = count($enrolled_in_course);

            $watched_100_count = 0;
            $quiz_attempted_students = 0;
            $quiz_passed_count = 0;
            $quiz_scores_list = [];
            $total_retakes = 0;

            foreach ($enrolled_in_course as $enr) {
                $uid = $enr['user_id'];
                $prog_key = $uid . '_' . $lid;
                $prog = $progress_map[$prog_key] ?? null;
                if (isset($completed_lessons_map[$prog_key]) || ($prog && ($prog['completed'] == 1 || (float) $prog['progress_percent'] >= 90))) {
                    $watched_100_count++;
                }

                $quiz_key = $uid . '_' . $cid . '_' . $lid;
                $qa = $quiz_attempts_map[$quiz_key] ?? null;
                if ($qa) {
                    $quiz_attempted_students++;
                    $total_retakes += (int) $qa['attempts_count'];
                    $s = (int) $qa['best_score'];
                    $t = (int) $qa['total_questions'] ?: ($lessons_with_quizzes[$lid] ?? 0);
                    if ($t > 0) {
                        $pct = ($s / $t) * 100;
                        $quiz_scores_list[] = $pct;
                        if ($pct >= 50)
                            $quiz_passed_count++;
                    }
                }
            }

            $video_completion_rate = ($c_total_enrolled > 0) ? round(($watched_100_count / $c_total_enrolled) * 100) : 0;
            $avg_quiz_score_l = count($quiz_scores_list) > 0 ? round(array_sum($quiz_scores_list) / count($quiz_scores_list)) : null;
            $quiz_pass_rate_l = ($quiz_attempted_students > 0) ? round(($quiz_passed_count / $quiz_attempted_students) * 100) : null;
            $avg_attempts = ($quiz_attempted_students > 0) ? round($total_retakes / $quiz_attempted_students, 1) : 0;

            $diff_badge = __('normal', 'Standard');
            $diff_class = 'bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25';
            if ($avg_quiz_score_l !== null && $avg_quiz_score_l < 50) {
                $diff_badge = __('challenging', 'Challenging');
                $diff_class = 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25';
            } elseif ($avg_attempts > 2.0) {
                $diff_badge = 'High Retakes';
                $diff_class = 'bg-warning bg-opacity-15 text-dark border border-warning border-opacity-35';
            } elseif ($avg_quiz_score_l !== null && $avg_quiz_score_l >= 80) {
                $diff_badge = 'High Mastery';
                $diff_class = 'bg-success bg-opacity-10 text-success border border-success border-opacity-25';
            }

            $c_title = '';
            foreach ($teacher_courses as $tc) {
                if ($tc['id'] === $cid) {
                    $c_title = $tc['title'];
                    break;
                }
            }

            $lesson_insights[] = [
                'course_id' => $cid,
                'course_title' => $c_title ?: $cid,
                'lesson_id' => $lid,
                'lesson_title' => $l['title'],
                'lesson_order' => $l['sort_order'],
                'lesson_duration' => $l['duration'],
                'total_enrolled' => $c_total_enrolled,
                'watched_100_count' => $watched_100_count,
                'video_completion_rate' => $video_completion_rate,
                'has_quiz' => isset($lessons_with_quizzes[$lid]),
                'quiz_attempted_students' => $quiz_attempted_students,
                'avg_quiz_score' => $avg_quiz_score_l,
                'quiz_pass_rate' => $quiz_pass_rate_l,
                'avg_attempts' => $avg_attempts,
                'difficulty_badge' => $diff_badge,
                'difficulty_class' => $diff_class
            ];
        }
    }

    // Format total study hours
    $study_hours = floor($total_study_seconds / 3600);
    $study_minutes = round(($total_study_seconds % 3600) / 60);
    $study_time_display = ($study_hours > 0) ? "{$study_hours}h {$study_minutes}m" : "{$study_minutes}m";

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo __('analytics_title', 'Student Progress & Performance Analytics'); ?> | Computerscience.lk</title>
  <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
  <script src="assets/js/session_manager.js"></script>
  
  <!-- Google Fonts Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- Local Bootstrap 5 CSS -->
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">
  <!-- Local Bootstrap Icons -->
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <!-- Custom Style & Modern Notification System Styles -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/notifications.css">
  
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
  <?php render_i18n_js(); ?>

  <style>
    body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #1e293b; }
    .analytics-kpi-card {
      border: none;
      border-radius: 16px;
      background: #ffffff;
      box-shadow: 0 4px 20px rgba(0,0,0,0.04);
      transition: all 0.25s ease;
      position: relative;
      overflow: hidden;
    }
    .analytics-kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 25px rgba(0,0,0,0.08);
    }
    .kpi-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    .nav-tabs .nav-link {
      font-weight: 600;
      font-size: 0.9rem;
      color: #64748b;
      border: none;
      border-bottom: 3px solid transparent;
      padding: 0.85rem 1.25rem;
      transition: all 0.2s;
    }
    .nav-tabs .nav-link:hover {
      color: #0f4c81;
    }
    .nav-tabs .nav-link.active {
      color: #0f4c81;
      background: transparent;
      border-bottom: 3px solid #0f4c81;
    }
    .table-container {
      background: #ffffff;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.04);
      border: 1px solid rgba(226, 232, 240, 0.8);
      overflow: hidden;
    }
    .custom-table th {
      background-color: #f8fafc;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #475569;
      font-weight: 700;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #e2e8f0;
    }
    .custom-table td {
      padding: 1rem 1.25rem;
      vertical-align: middle;
      border-bottom: 1px solid #f1f5f9;
    .custom-table tr:hover td {
      background-color: #f8fafc;
    }
    .pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background-color: #10b981;
      display: inline-block;
      box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
      animation: pulseLive 2s infinite;
    }
    @keyframes pulseLive {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .spin-anim {
      display: inline-block;
      animation: spinSync 0.75s linear infinite;
    }
    @keyframes spinSync {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    .modal-content {
      border-radius: 20px;
      border: none;
      box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    }
  </style>
</head>
<body>

  <!-- Unified LMS Top Header Bar -->
  <?php include __DIR__ . '/includes/navbar.php'; ?>

  <!-- Main Container -->
  <main style="padding-top: 86px;" class="pb-4">
    <div class="container-fluid px-3 px-md-4">
      
      <!-- Page Title & Header Actions -->
      <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
        <div style="min-width: 0; flex: 1 1 0;">
          <h2 class="fw-bold text-dark mb-1 fs-4 d-flex align-items-center gap-2">
            <i class="bi bi-graph-up-arrow text-primary"></i>
            <span><?php echo __('analytics_title', 'Student Progress & Performance Analytics'); ?></span>
          </h2>
          <p class="text-muted fs-8 mb-0"><?php echo __('analytics_subtitle', 'Monitor real-time video watch completion, lesson progress, quiz results, and student learning records.'); ?></p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap flex-shrink-0">
          <!-- Real-Time Live Sync Status Badge -->
          <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1.5 rounded-pill fs-8 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-xs" title="Auto-synchronizing student learning progress and quiz submissions">
            <span class="pulse-dot"></span> Live Sync <span class="d-none d-sm-inline text-muted" id="last-sync-time">(Just now)</span>
          </span>
          <button id="btn-manual-sync" onclick="fetchLiveAnalytics(true)" class="btn btn-outline-primary btn-sm px-3 py-1.5 rounded-pill fw-semibold shadow-xs d-flex align-items-center gap-1.5" title="Force instant real-time data sync">
            <i class="bi bi-arrow-repeat" id="sync-icon"></i> <span>Sync Now</span>
          </button>
          <button onclick="exportGradebookCSV()" class="btn btn-success btn-sm px-3.5 py-1.5 rounded-pill shadow-sm fw-semibold text-white d-flex align-items-center gap-1.5">
            <i class="bi bi-file-earmark-spreadsheet-fill"></i>
            <span><?php echo __('export_csv', 'Export Gradebook (CSV)'); ?></span>
          </button>
          <a href="dashboard.php" class="btn btn-outline-secondary btn-sm px-3.5 py-1.5 rounded-pill fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> <?php echo __('nav_dashboard', 'Dashboard'); ?>
          </a>
        </div>
      </div>

      <!-- 4 High-Impact KPI Summary Cards -->
      <div class="row g-3 mb-4">
        <!-- KPI 1: Enrolled Students -->
        <div class="col-sm-6 col-xl-3">
          <div class="analytics-kpi-card p-3.5 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider"><?php echo __('kpi_total_enrolled', 'Total Enrolled Students'); ?></small>
                <h3 class="fw-bold text-dark mb-0 mt-1" id="kpi-total-students"><?php echo number_format($total_students); ?></h3>
                <small class="text-success fs-9 fw-semibold"><i class="bi bi-person-check-fill me-1"></i><span id="kpi-active-learners"><?php echo $active_learners_count; ?></span> <?php echo __('kpi_active_students', 'Active Learners'); ?></small>
              </div>
              <div class="kpi-icon-box bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-people-fill"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 2: Average Course Completion -->
        <div class="col-sm-6 col-xl-3">
          <div class="analytics-kpi-card p-3.5 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider"><?php echo __('kpi_course_completion', 'Avg Course Completion'); ?></small>
                <h3 class="fw-bold text-success mb-0 mt-1" id="kpi-course-completion"><?php echo $avg_course_completion; ?>%</h3>
                <div class="progress mt-1.5" style="height: 4px; width: 100px;">
                  <div class="progress-bar bg-success rounded-pill" id="kpi-completion-bar" style="width: <?php echo $avg_course_completion; ?>%;"></div>
                </div>
              </div>
              <div class="kpi-icon-box bg-success bg-opacity-10 text-success">
                <i class="bi bi-pie-chart-fill"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 3: Quiz Pass Rate & Average Score -->
        <div class="col-sm-6 col-xl-3">
          <div class="analytics-kpi-card p-3.5 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider"><?php echo __('kpi_avg_quiz_score', 'Avg Quiz Score'); ?></small>
                <h3 class="fw-bold text-warning mb-0 mt-1" id="kpi-avg-score"><?php echo $avg_quiz_score_overall; ?>%</h3>
                <small class="text-muted fs-9 fw-semibold"><i class="bi bi-award-fill text-warning me-1"></i><span id="kpi-pass-rate"><?php echo $quiz_pass_rate_overall; ?></span>% <?php echo __('kpi_quiz_pass_rate', 'Pass Rate'); ?></small>
              </div>
              <div class="kpi-icon-box bg-warning bg-opacity-10 text-warning">
                <i class="bi bi-trophy-fill"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI 4: Total Study Hours -->
        <div class="col-sm-6 col-xl-3">
          <div class="analytics-kpi-card p-3.5 border-start border-4 border-info" style="border-left-color: #6f42c1 !important;">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted fw-bold text-uppercase fs-9 tracking-wider"><?php echo __('kpi_total_study_time', 'Total Video Watch Time'); ?></small>
                <h3 class="fw-bold text-dark mb-0 mt-1" id="kpi-study-time" style="color: #6f42c1 !important;"><?php echo $study_time_display; ?></h3>
                <small class="text-muted fs-9"><i class="bi bi-clock-history me-1"></i>Logged Study Progress</small>
              </div>
              <div class="kpi-icon-box bg-opacity-10" style="background-color: rgba(111, 66, 193, 0.1); color: #6f42c1;">
                <i class="bi bi-play-circle-fill"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Analytics Container with Tabs -->
      <div class="table-container p-4 bg-white mb-4">
        
        <!-- Filter Controls Bar -->
        <div class="row g-3 align-items-center mb-4 pb-3 border-bottom">
          <div class="col-md-5">
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
              <input type="text" id="analytics-search-input" class="form-control bg-light border-start-0 fs-8" placeholder="<?php echo __('search_students_placeholder', 'Search student name, email, academic ID, or lesson...'); ?>">
            </div>
          </div>

          <div class="col-md-4">
            <select id="course-filter-select" class="form-select fs-8 bg-light border">
              <option value=""><?php echo __('all_courses', 'All Courses'); ?> (<?php echo count($teacher_courses); ?>)</option>
              <?php foreach ($teacher_courses as $c): ?>
                <option value="<?php echo htmlspecialchars($c['title']); ?>"><?php echo htmlspecialchars($c['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <select id="status-filter-select" class="form-select fs-8 bg-light border">
              <option value=""><?php echo __('all_statuses', 'All Statuses'); ?></option>
              <option value="100% Completed"><?php echo __('status_completed', '100% Completed'); ?></option>
              <option value="On Track"><?php echo __('status_on_track', 'On Track'); ?></option>
              <option value="Needs Attention"><?php echo __('status_needs_attention', 'Needs Attention'); ?></option>
              <option value="Not Started"><?php echo __('status_not_started', 'Not Started'); ?></option>
            </select>
          </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="analyticsTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active d-flex align-items-center gap-2" id="roster-tab" data-bs-toggle="tab" data-bs-target="#roster-pane" type="button" role="tab">
              <i class="bi bi-person-lines-fill"></i>
              <span><?php echo __('tab_student_roster', 'Student Gradebook'); ?> (<span id="tab-roster-count"><?php echo count($student_summaries); ?></span>)</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link d-flex align-items-center gap-2" id="matrix-tab" data-bs-toggle="tab" data-bs-target="#matrix-pane" type="button" role="tab">
              <i class="bi bi-grid-3x3-gap-fill"></i>
              <span><?php echo __('tab_lesson_matrix', 'Granular Lesson Matrix'); ?> (<span id="tab-matrix-count"><?php echo count($matrix_rows); ?></span>)</span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link d-flex align-items-center gap-2" id="insights-tab" data-bs-toggle="tab" data-bs-target="#insights-pane" type="button" role="tab">
              <i class="bi bi-bar-chart-steps"></i>
              <span><?php echo __('tab_course_insights', 'Course Insights & Drop-off'); ?> (<span id="tab-insights-count"><?php echo count($lesson_insights); ?></span>)</span>
            </button>
          </li>
        </ul>

        <!-- Tab Contents -->
        <div class="tab-content" id="analyticsTabContent">

          <!-- TAB 1: Student Gradebook (Aggregated Student Roster) -->
          <div class="tab-pane fade show active" id="roster-pane" role="tabpanel">
            <div class="table-responsive">
              <table class="table custom-table align-middle mb-0" id="roster-table">
                <thead>
                  <tr>
                    <th scope="col" style="min-width: 220px;"><?php echo __('student_info', 'Student Information'); ?></th>
                    <th scope="col" style="min-width: 180px;"><?php echo __('enrolled_course', 'Enrolled Course'); ?></th>
                    <th scope="col" style="min-width: 180px;"><?php echo __('overall_progress', 'Overall Progress'); ?></th>
                    <th scope="col" style="min-width: 130px;"><?php echo __('lessons_completed', 'Lessons'); ?></th>
                    <th scope="col" style="min-width: 130px;"><?php echo __('quizzes_passed', 'Quizzes Passed'); ?></th>
                    <th scope="col" style="min-width: 110px;"><?php echo __('avg_score', 'Avg Score'); ?></th>
                    <th scope="col" class="text-center" style="min-width: 140px;"><?php echo __('learning_status', 'Learning Status'); ?></th>
                    <th scope="col" class="text-center" style="min-width: 110px;"><?php echo __('actions', 'Actions'); ?></th>
                  </tr>
                </thead>
                <tbody class="fs-8" id="roster-tbody">
                  <?php if (empty($student_summaries)): ?>
                    <tr>
                      <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                        No student enrollment records found for your courses.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($student_summaries as $s_idx => $s): ?>
                      <tr class="roster-row"
                          data-course="<?php echo htmlspecialchars($s['course_title']); ?>"
                          data-status="<?php echo htmlspecialchars($s['learning_status']); ?>"
                          data-student-name="<?php echo htmlspecialchars($s['student_name']); ?>"
                          data-student-email="<?php echo htmlspecialchars($s['student_email']); ?>"
                          data-academic-id="<?php echo htmlspecialchars($s['student_academic_id']); ?>">
                        
                        <!-- Student Information -->
                        <td>
                          <div class="d-flex align-items-center gap-2.5">
                            <img src="<?php echo htmlspecialchars($s['student_avatar']); ?>"
                                 onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($s['student_name']); ?>&background=0f4c81&color=fff';"
                                 class="rounded-circle border" style="width: 38px; height: 38px; object-fit: cover;" alt="Avatar">
                            <div>
                              <div class="fw-bold text-dark text-truncate" style="max-width: 170px;"><?php echo htmlspecialchars($s['student_name']); ?></div>
                              <div class="text-muted fs-9 text-truncate" style="max-width: 170px;"><?php echo htmlspecialchars($s['student_email']); ?></div>
                              <span class="badge bg-light text-secondary border fs-9 mt-0.5"><?php echo htmlspecialchars($s['student_academic_id']); ?></span>
                            </div>
                          </div>
                        </td>

                        <!-- Course Title -->
                        <td>
                          <span class="badge bg-light text-primary border fs-9 fw-semibold text-wrap text-start lh-base mb-1" style="max-width: 220px;">
                            <?php echo htmlspecialchars($s['course_title']); ?>
                          </span>
                          <div class="text-muted fs-9"><i class="bi bi-clock-history me-1"></i>Last active: <?php echo $s['last_active']; ?></div>
                        </td>

                        <!-- Overall Progress -->
                        <td>
                          <div class="d-flex align-items-center gap-2 mb-1">
                            <div class="progress flex-grow-1" style="height: 7px; border-radius: 10px; background-color: #e2e8f0;">
                              <div class="progress-bar rounded-pill <?php echo $s['status_badge_class']; ?>" role="progressbar" style="width: <?php echo $s['overall_progress']; ?>%;"></div>
                            </div>
                            <span class="fw-bold text-dark fs-8"><?php echo $s['overall_progress']; ?>%</span>
                          </div>
                          <small class="text-muted fs-9">
                            Watched <?php echo round($s['total_watch_seconds'] / 60); ?>m video content
                          </small>
                        </td>

                        <!-- Lessons Completed -->
                        <td>
                          <span class="badge bg-light text-dark border fs-8 fw-semibold">
                            <i class="bi bi-check-circle-fill text-success me-1"></i><?php echo $s['completed_lessons']; ?> / <?php echo $s['total_lessons']; ?>
                          </span>
                        </td>

                        <!-- Quizzes Passed -->
                        <td>
                          <span class="badge bg-light text-dark border fs-8 fw-semibold">
                            <i class="bi bi-trophy-fill text-warning me-1"></i><?php echo $s['passed_quizzes']; ?> / <?php echo $s['total_quizzes']; ?>
                          </span>
                        </td>

                        <!-- Avg Quiz Score -->
                        <td>
                          <?php if ($s['avg_quiz_score'] !== null): ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 fs-8 fw-bold">
                              <?php echo $s['avg_quiz_score']; ?>%
                            </span>
                          <?php else: ?>
                            <span class="text-muted fs-9 italic">N/A</span>
                          <?php endif; ?>
                        </td>

                        <!-- Learning Status -->
                        <td class="text-center">
                          <span class="badge <?php echo $s['status_badge_class']; ?> px-3 py-1.5 rounded-pill fs-8 fw-semibold">
                            <?php echo htmlspecialchars($s['learning_status']); ?>
                          </span>
                        </td>

                        <!-- Action: View Record -->
                        <td class="text-center">
                          <button onclick="openStudentDossier(<?php echo $s_idx; ?>)" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fs-8 fw-semibold">
                            <i class="bi bi-eye-fill me-1"></i><?php echo __('view_record', 'View Record'); ?>
                          </button>
                        </td>

                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- TAB 2: Granular Lesson & Quiz Matrix -->
          <div class="tab-pane fade" id="matrix-pane" role="tabpanel">
            <div class="table-responsive">
              <table class="table custom-table align-middle mb-0" id="matrix-table">
                <thead>
                  <tr>
                    <th scope="col" style="min-width: 200px;"><?php echo __('student_info', 'Student Information'); ?></th>
                    <th scope="col" style="min-width: 200px;">Course & Lesson</th>
                    <th scope="col" style="min-width: 220px;"><?php echo __('video_progress', 'Video Watch Progress'); ?></th>
                    <th scope="col" style="min-width: 200px;"><?php echo __('quiz_performance', 'Quiz Performance'); ?></th>
                    <th scope="col" class="text-center" style="min-width: 140px;">Module Status</th>
                  </tr>
                </thead>
                <tbody class="fs-8" id="matrix-tbody">
                  <?php if (empty($matrix_rows)): ?>
                    <tr>
                      <td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                        No granular progress records found.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($matrix_rows as $row): 
                      $pct = (float)$row['progress_percent'];
                      $isComp = $row['is_completed'];
                      $dispPct = $isComp ? 100 : round($pct);
                      $hasQ = $row['has_quiz'];
                      $qScore = (int)($row['quiz_score'] ?? 0);
                      $qTotal = (int)($row['quiz_total'] ?? 0);
                      $qFinalized = $row['quiz_finalized'];
                      $qPassed = $row['quiz_passed'];
                      $isFullyDone = $row['is_fully_completed'];
                    ?>
                      <tr class="matrix-row"
                          data-course="<?php echo htmlspecialchars($row['course_title']); ?>"
                          data-status="<?php echo $isFullyDone ? '100% Completed' : ($dispPct > 0 ? 'On Track' : 'Not Started'); ?>"
                          data-student-name="<?php echo htmlspecialchars($row['student_name']); ?>"
                          data-student-email="<?php echo htmlspecialchars($row['student_email']); ?>"
                          data-academic-id="<?php echo htmlspecialchars($row['student_academic_id']); ?>"
                          data-lesson="<?php echo htmlspecialchars($row['lesson_title']); ?>">
                        
                        <!-- Student Info -->
                        <td>
                          <div class="d-flex align-items-center gap-2.5">
                            <img src="<?php echo htmlspecialchars($row['student_avatar']); ?>"
                                 onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($row['student_name']); ?>&background=0f4c81&color=fff';"
                                 class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;" alt="Avatar">
                            <div>
                              <div class="fw-bold text-dark text-truncate" style="max-width: 160px;"><?php echo htmlspecialchars($row['student_name']); ?></div>
                              <small class="text-muted fs-9"><?php echo htmlspecialchars($row['student_academic_id']); ?></small>
                            </div>
                          </div>
                        </td>

                        <!-- Course & Lesson -->
                        <td>
                          <span class="badge bg-light text-primary border fs-9 mb-1"><?php echo htmlspecialchars($row['course_title']); ?></span>
                          <div class="fw-semibold text-dark text-truncate" style="max-width: 220px;">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border me-1">#<?php echo $row['lesson_order']; ?></span>
                            <?php echo htmlspecialchars($row['lesson_title']); ?>
                          </div>
                        </td>

                        <!-- Video Watch Progress -->
                        <td>
                          <div class="d-flex align-items-center gap-2 mb-1">
                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                              <div class="progress-bar rounded-pill <?php echo $isComp ? 'bg-success' : 'bg-primary'; ?>" role="progressbar" style="width: <?php echo $dispPct; ?>%;"></div>
                            </div>
                            <span class="fw-bold text-dark fs-8"><?php echo $dispPct; ?>%</span>
                          </div>
                          <small class="text-muted fs-9">
                            <i class="bi bi-clock me-1"></i>Watched <?php echo round($row['position_seconds']); ?>s of <?php echo round($row['duration_seconds']); ?>s
                          </small>
                        </td>

                        <!-- Quiz Performance -->
                        <td>
                          <?php if ($hasQ): ?>
                            <?php if ($row['quiz_attempts'] > 0): ?>
                              <div class="d-flex align-items-center gap-1.5 mb-1">
                                <span class="badge <?php echo $qPassed ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-warning bg-opacity-10 text-dark border border-warning'; ?> fs-9 fw-bold">
                                  Score: <?php echo $qScore; ?> / <?php echo $qTotal; ?>
                                </span>
                                <?php if ($qFinalized): ?>
                                  <span class="badge bg-success text-white fs-9" title="Finalized"><i class="bi bi-check-circle-fill"></i> Final</span>
                                <?php else: ?>
                                  <span class="badge bg-light text-secondary border fs-9">In Progress</span>
                                <?php endif; ?>
                              </div>
                              <small class="text-muted fs-9">
                                <i class="bi bi-arrow-repeat me-1"></i>Attempts: <?php echo $row['quiz_attempts']; ?>
                              </small>
                            <?php else: ?>
                              <span class="text-muted italic fs-9"><i class="bi bi-dash-circle me-1"></i>Not attempted</span>
                            <?php endif; ?>
                          <?php else: ?>
                            <span class="text-muted fs-9"><i class="bi bi-slash-circle me-1"></i>No quiz for lesson</span>
                          <?php endif; ?>
                        </td>

                        <!-- Status Badge -->
                        <td class="text-center">
                          <?php if ($isFullyDone): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
                              <i class="bi bi-check-circle-fill me-1"></i> 100% Completed
                            </span>
                          <?php elseif ($dispPct > 0 || $row['quiz_attempts'] > 0): ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-35 px-3 py-1.5 rounded-pill fs-8">
                              <i class="bi bi-play-circle me-1"></i> In Progress (<?php echo $dispPct; ?>%)
                            </span>
                          <?php else: ?>
                            <span class="badge bg-light text-secondary border px-3 py-1.5 rounded-pill fs-8">
                              <i class="bi bi-lock-fill me-1"></i> Not Started
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

          <!-- TAB 3: Course Insights & Drop-off Analysis -->
          <div class="tab-pane fade" id="insights-pane" role="tabpanel">
            
            <div class="p-3 bg-light rounded-4 border mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div>
                <h6 class="fw-bold text-dark mb-1"><i class="bi bi-bar-chart-steps text-primary me-2"></i><?php echo __('lesson_dropoff_title', 'Lesson Completion & Quiz Bottlenecks'); ?></h6>
                <p class="text-muted fs-8 mb-0"><?php echo __('lesson_dropoff_desc', 'Identify which lessons have high engagement and where students encounter difficulties or drop off.'); ?></p>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table custom-table align-middle mb-0" id="insights-table">
                <thead>
                  <tr>
                    <th scope="col" style="min-width: 220px;">Course & Lesson</th>
                    <th scope="col" style="min-width: 200px;"><?php echo __('completion_rate', 'Completion Rate'); ?></th>
                    <th scope="col" style="min-width: 140px;"><?php echo __('quiz_attempts_count', 'Quiz Attempts'); ?></th>
                    <th scope="col" style="min-width: 130px;"><?php echo __('avg_score', 'Avg Quiz Score'); ?></th>
                    <th scope="col" style="min-width: 130px;"><?php echo __('kpi_quiz_pass_rate', 'Pass Rate'); ?></th>
                    <th scope="col" class="text-center" style="min-width: 160px;"><?php echo __('difficulty_level', 'Difficulty Insight'); ?></th>
                  </tr>
                </thead>
                <tbody class="fs-8" id="insights-tbody">
                  <?php if (empty($lesson_insights)): ?>
                    <tr>
                      <td colspan="6" class="text-center py-5 text-muted">
                        No lesson insights available.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($lesson_insights as $li): ?>
                      <tr class="insights-row" data-course="<?php echo htmlspecialchars($li['course_title']); ?>" data-lesson="<?php echo htmlspecialchars($li['lesson_title']); ?>">
                        
                        <!-- Course & Lesson -->
                        <td>
                          <span class="badge bg-light text-primary border fs-9 mb-1"><?php echo htmlspecialchars($li['course_title']); ?></span>
                          <div class="fw-bold text-dark"><?php echo htmlspecialchars($li['lesson_title']); ?></div>
                          <small class="text-muted fs-9"><i class="bi bi-clock me-1"></i>Duration: <?php echo htmlspecialchars($li['lesson_duration']); ?></small>
                        </td>

                        <!-- Video Completion Rate -->
                        <td>
                          <div class="d-flex align-items-center gap-2 mb-1">
                            <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                              <div class="progress-bar bg-success rounded-pill" style="width: <?php echo $li['video_completion_rate']; ?>%;"></div>
                            </div>
                            <span class="fw-bold text-dark fs-8"><?php echo $li['video_completion_rate']; ?>%</span>
                          </div>
                          <small class="text-muted fs-9"><?php echo $li['watched_100_count']; ?> of <?php echo $li['total_enrolled']; ?> students completed 100%</small>
                        </td>

                        <!-- Quiz Attempt Rate -->
                        <td>
                          <?php if ($li['has_quiz']): ?>
                            <span class="badge bg-light text-dark border fs-8 fw-semibold">
                              <i class="bi bi-people-fill text-primary me-1"></i><?php echo $li['quiz_attempted_students']; ?> / <?php echo $li['total_enrolled']; ?>
                            </span>
                            <div class="text-muted fs-9 mt-0.5">Avg Retakes: <?php echo $li['avg_attempts']; ?></div>
                          <?php else: ?>
                            <span class="text-muted fs-9 italic">No quiz attached</span>
                          <?php endif; ?>
                        </td>

                        <!-- Avg Quiz Score -->
                        <td>
                          <?php if ($li['has_quiz'] && $li['avg_quiz_score'] !== null): ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fs-8 fw-bold">
                              <?php echo $li['avg_quiz_score']; ?>%
                            </span>
                          <?php else: ?>
                            <span class="text-muted fs-9 italic">N/A</span>
                          <?php endif; ?>
                        </td>

                        <!-- Pass Rate -->
                        <td>
                          <?php if ($li['has_quiz'] && $li['quiz_pass_rate'] !== null): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fs-8 fw-bold">
                              <?php echo $li['quiz_pass_rate']; ?>%
                            </span>
                          <?php else: ?>
                            <span class="text-muted fs-9 italic">N/A</span>
                          <?php endif; ?>
                        </td>

                        <!-- Difficulty Insight Badge -->
                        <td class="text-center">
                          <span class="badge <?php echo $li['difficulty_class']; ?> px-3 py-1.5 rounded-pill fs-8 fw-semibold">
                            <?php echo htmlspecialchars($li['difficulty_badge']); ?>
                          </span>
                        </td>

                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>

        </div>

      </div>

    </div>
  </main>

  <!-- Interactive Student Learning Dossier Modal -->
  <div class="modal fade" id="studentDossierModal" tabindex="-1" aria-labelledby="studentDossierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header border-bottom py-3 px-4" style="background-color: #0f4c81; color: #ffffff;">
          <div class="d-flex align-items-center gap-3">
            <img id="modal-student-avatar" src="" class="rounded-circle border border-2 border-white shadow-sm" style="width: 46px; height: 46px; object-fit: cover;" alt="Avatar">
            <div>
              <h5 class="modal-title fw-bold text-white mb-0 fs-6" id="modal-student-name">Student Name</h5>
              <div class="fs-9 text-white text-opacity-80" id="modal-student-email">student@example.com</div>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        
        <div class="modal-body p-4 bg-light">
          <!-- Summary Cards Strip -->
          <div class="row g-2.5 mb-4">
            <div class="col-sm-3">
              <div class="bg-white p-3 rounded-4 border text-center shadow-xs">
                <small class="text-muted fs-9 fw-bold text-uppercase"><?php echo __('overall_progress', 'Overall Progress'); ?></small>
                <h4 class="fw-bold text-primary mb-0 mt-1" id="modal-overall-progress">0%</h4>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="bg-white p-3 rounded-4 border text-center shadow-xs">
                <small class="text-muted fs-9 fw-bold text-uppercase"><?php echo __('lessons_completed', 'Lessons Done'); ?></small>
                <h4 class="fw-bold text-success mb-0 mt-1" id="modal-lessons-done">0 / 0</h4>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="bg-white p-3 rounded-4 border text-center shadow-xs">
                <small class="text-muted fs-9 fw-bold text-uppercase"><?php echo __('quizzes_passed', 'Quizzes Passed'); ?></small>
                <h4 class="fw-bold text-warning mb-0 mt-1" id="modal-quizzes-passed">0 / 0</h4>
              </div>
            </div>
            <div class="col-sm-3">
              <div class="bg-white p-3 rounded-4 border text-center shadow-xs">
                <small class="text-muted fs-9 fw-bold text-uppercase"><?php echo __('avg_score', 'Avg Quiz Score'); ?></small>
                <h4 class="fw-bold text-dark mb-0 mt-1" id="modal-avg-score">N/A</h4>
              </div>
            </div>
          </div>

          <!-- Course Title Banner -->
          <div class="p-2.5 px-3 bg-white rounded-3 border mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="fs-8 fw-bold text-dark"><i class="bi bi-journal-bookmark-fill text-primary me-1.5"></i>Course: <span id="modal-course-title"></span></span>
            <span class="badge rounded-pill fs-8 fw-semibold" id="modal-learning-status"></span>
          </div>

          <!-- Syllabus Modules Detail Table -->
          <div class="bg-white rounded-4 border overflow-hidden">
            <div class="table-responsive">
              <table class="table align-middle mb-0 fs-8">
                <thead class="table-light fs-9 text-uppercase text-secondary">
                  <tr>
                    <th>#</th>
                    <th>Lesson Title</th>
                    <th>Video Watch %</th>
                    <th>Quiz Performance</th>
                    <th class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody id="modal-lessons-tbody">
                  <!-- Dynamically Rendered via JS -->
                </tbody>
              </table>
            </div>
          </div>

        </div>
        
        <div class="modal-footer bg-white border-top py-2.5 px-4">
          <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal"><?php echo __('close', 'Close'); ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Local Bootstrap JS -->
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <!-- Modern Notification System JS Client -->
  <script src="assets/js/notifications.js"></script>
  <!-- Real-Time Analytics Engine -->
  <script src="assets/js/student-analytics-realtime.js"></script>

  <!-- Data Payload for Client-Side Interactivity -->
  <script>
    window.STUDENT_SUMMARIES = <?php echo json_encode($student_summaries); ?>;
    window.MATRIX_ROWS = <?php echo json_encode($matrix_rows); ?>;
    window.LESSON_INSIGHTS = <?php echo json_encode($lesson_insights); ?>;

    // Filter Logic
    document.addEventListener('DOMContentLoaded', function () {
      const searchInput = document.getElementById('analytics-search-input');
      const courseFilter = document.getElementById('course-filter-select');
      const statusFilter = document.getElementById('status-filter-select');

      function filterAllTables() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const selCourse = (courseFilter ? courseFilter.value : '').toLowerCase().trim();
        const selStatus = statusFilter ? statusFilter.value.trim() : '';

        let visibleRosterCount = 0;
        let visibleMatrixCount = 0;
        let visibleInsightsCount = 0;

        // Dynamic KPI Accumulators
        let kpiStudentsSet = new Set();
        let kpiActiveSet = new Set();
        let kpiProgressSum = 0;
        let kpiProgressCount = 0;
        let kpiQuizScores = [];
        let kpiQuizPassCount = 0;
        let kpiStudySeconds = 0;

        // 1. Filter Roster Table
        document.querySelectorAll('.roster-row').forEach(row => {
          const name = (row.getAttribute('data-student-name') || '').toLowerCase();
          const email = (row.getAttribute('data-student-email') || '').toLowerCase();
          const acId = (row.getAttribute('data-academic-id') || '').toLowerCase();
          const course = (row.getAttribute('data-course') || '').toLowerCase();
          const status = row.getAttribute('data-status') || '';

          const matchesSearch = !query || name.includes(query) || email.includes(query) || acId.includes(query) || course.includes(query);
          const matchesCourse = !selCourse || course === selCourse;
          const matchesStatus = !selStatus || status === selStatus;

          const isVisible = matchesSearch && matchesCourse && matchesStatus;
          row.style.display = isVisible ? '' : 'none';

          if (isVisible) {
            visibleRosterCount++;
          }
        });

        // 2. Filter Matrix Table
        document.querySelectorAll('.matrix-row').forEach(row => {
          const name = (row.getAttribute('data-student-name') || '').toLowerCase();
          const email = (row.getAttribute('data-student-email') || '').toLowerCase();
          const acId = (row.getAttribute('data-academic-id') || '').toLowerCase();
          const course = (row.getAttribute('data-course') || '').toLowerCase();
          const lesson = (row.getAttribute('data-lesson') || '').toLowerCase();
          const status = row.getAttribute('data-status') || '';

          const matchesSearch = !query || name.includes(query) || email.includes(query) || acId.includes(query) || course.includes(query) || lesson.includes(query);
          const matchesCourse = !selCourse || course === selCourse;
          const matchesStatus = !selStatus || status === selStatus;

          const isVisible = matchesSearch && matchesCourse && matchesStatus;
          row.style.display = isVisible ? '' : 'none';

          if (isVisible) {
            visibleMatrixCount++;
          }
        });

        // 3. Filter Insights Table
        document.querySelectorAll('.insights-row').forEach(row => {
          const course = (row.getAttribute('data-course') || '').toLowerCase();
          const lesson = (row.getAttribute('data-lesson') || '').toLowerCase();

          const matchesSearch = !query || course.includes(query) || lesson.includes(query);
          const matchesCourse = !selCourse || course === selCourse;

          const isVisible = matchesSearch && matchesCourse;
          row.style.display = isVisible ? '' : 'none';

          if (isVisible) {
            visibleInsightsCount++;
          }
        });

        // 4. Update Tab Counts
        const rosterCountEl = document.getElementById('tab-roster-count');
        const matrixCountEl = document.getElementById('tab-matrix-count');
        const insightsCountEl = document.getElementById('tab-insights-count');
        if (rosterCountEl) rosterCountEl.textContent = visibleRosterCount;
        if (matrixCountEl) matrixCountEl.textContent = visibleMatrixCount;
        if (insightsCountEl) insightsCountEl.textContent = visibleInsightsCount;

        // 5. Update Dynamic KPI Summary Cards based on filtered student summaries
        const summaries = window.STUDENT_SUMMARIES || [];
        summaries.forEach(s => {
          const courseMatch = !selCourse || (s.course_title || '').toLowerCase() === selCourse;
          const statusMatch = !selStatus || s.learning_status === selStatus;
          const searchMatch = !query || (s.student_name || '').toLowerCase().includes(query) || (s.student_email || '').toLowerCase().includes(query) || (s.student_academic_id || '').toLowerCase().includes(query);

          if (courseMatch && statusMatch && searchMatch) {
            kpiStudentsSet.add(s.student_id);
            if (s.total_watch_seconds > 0 || s.passed_quizzes > 0 || s.finalized_quizzes > 0) {
              kpiActiveSet.add(s.student_id);
            }
            kpiProgressSum += (s.overall_progress || 0);
            kpiProgressCount++;
            kpiStudySeconds += (s.total_watch_seconds || 0);

            if (s.avg_quiz_score !== null && s.avg_quiz_score !== undefined) {
              kpiQuizScores.push(s.avg_quiz_score);
            }
            if (s.passed_quizzes > 0) {
              kpiQuizPassCount += s.passed_quizzes;
            }
          }
        });

        const dynTotalStudents = kpiStudentsSet.size;
        const dynActiveLearners = kpiActiveSet.size;
        const dynAvgProgress = kpiProgressCount > 0 ? Math.round(kpiProgressSum / kpiProgressCount) : 0;
        const dynAvgScore = kpiQuizScores.length > 0 ? Math.round(kpiQuizScores.reduce((a, b) => a + b, 0) / kpiQuizScores.length) : 0;
        const dynPassRate = kpiQuizScores.length > 0 ? Math.round((kpiQuizPassCount / kpiQuizScores.length) * 100) : 0;
        
        const studyHrs = Math.floor(kpiStudySeconds / 3600);
        const studyMins = Math.round((kpiStudySeconds % 3600) / 60);
        const dynStudyDisplay = studyHrs > 0 ? `${studyHrs}h ${studyMins}m` : `${studyMins}m`;

        const totalStudentsEl = document.getElementById('kpi-total-students');
        const activeLearnersEl = document.getElementById('kpi-active-learners');
        const courseCompletionEl = document.getElementById('kpi-course-completion');
        const completionBarEl = document.getElementById('kpi-completion-bar');
        const avgScoreEl = document.getElementById('kpi-avg-score');
        const passRateEl = document.getElementById('kpi-pass-rate');
        const studyTimeEl = document.getElementById('kpi-study-time');

        if (totalStudentsEl) totalStudentsEl.textContent = dynTotalStudents;
        if (activeLearnersEl) activeLearnersEl.textContent = dynActiveLearners;
        if (courseCompletionEl) courseCompletionEl.textContent = dynAvgProgress + '%';
        if (completionBarEl) completionBarEl.style.width = dynAvgProgress + '%';
        if (avgScoreEl) avgScoreEl.textContent = dynAvgScore + '%';
        if (passRateEl) passRateEl.textContent = dynPassRate;
        if (studyTimeEl) studyTimeEl.textContent = dynStudyDisplay;
      }

      window.filterAllTables = filterAllTables;

      if (searchInput) searchInput.addEventListener('input', filterAllTables);
      if (courseFilter) courseFilter.addEventListener('change', filterAllTables);
      if (statusFilter) statusFilter.addEventListener('change', filterAllTables);

      // Start Real-Time Background Polling Engine
      initStudentAnalyticsRealtime({
        endpoint: 'api/get_student_analytics.php',
        isAdmin: false,
        pollInterval: 8000
      });
    });

    // Open Student Learning Dossier Modal
    function openStudentDossier(index) {
      const student = STUDENT_SUMMARIES[index];
      if (!student) return;

      const defaultAvatar = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(student.student_name || 'Student') + '&background=0f4c81&color=fff';
      const avatarImg = document.getElementById('modal-student-avatar');
      avatarImg.src = student.student_avatar || defaultAvatar;
      avatarImg.onerror = function() {
        this.onerror = null;
        this.src = defaultAvatar;
      };

      document.getElementById('modal-student-name').textContent = student.student_name + (student.student_academic_id !== 'N/A' ? ' (' + student.student_academic_id + ')' : '');
      document.getElementById('modal-student-email').textContent = student.student_email;
      document.getElementById('modal-course-title').textContent = student.course_title;
      document.getElementById('modal-overall-progress').textContent = student.overall_progress + '%';
      document.getElementById('modal-lessons-done').textContent = student.completed_lessons + ' / ' + student.total_lessons;
      document.getElementById('modal-quizzes-passed').textContent = student.passed_quizzes + ' / ' + student.total_quizzes;
      document.getElementById('modal-avg-score').textContent = student.avg_quiz_score !== null ? student.avg_quiz_score + '%' : 'N/A';

      const statusBadge = document.getElementById('modal-learning-status');
      statusBadge.textContent = student.learning_status;
      statusBadge.className = 'badge rounded-pill fs-8 fw-semibold ' + student.status_badge_class;

      // Render Lessons Table
      const tbody = document.getElementById('modal-lessons-tbody');
      tbody.innerHTML = '';

      if (student.lessons && student.lessons.length > 0) {
        student.lessons.forEach(les => {
          const tr = document.createElement('tr');
          const isComp = les.is_completed;
          const pct = isComp ? 100 : Math.round(les.progress_percent);
          const hasQuiz = les.has_quiz;
          const qFinal = les.quiz_finalized;
          const qPass = les.quiz_passed;

          tr.innerHTML = `
            <td class="fw-bold text-secondary">${les.lesson_order}</td>
            <td>
              <div class="fw-semibold text-dark">${escapeHtml(les.lesson_title)}</div>
              <small class="text-muted fs-9"><i class="bi bi-clock me-1"></i>${escapeHtml(les.lesson_duration || '')}</small>
            </td>
            <td>
              <div class="d-flex align-items-center gap-2 mb-0.5">
                <div class="progress flex-grow-1" style="height: 5px; width: 80px;">
                  <div class="progress-bar ${isComp ? 'bg-success' : 'bg-primary'}" style="width: ${pct}%;"></div>
                </div>
                <span class="fw-bold fs-9">${pct}%</span>
              </div>
              <small class="text-muted fs-9">${Math.round(les.position_seconds)}s / ${Math.round(les.duration_seconds)}s</small>
            </td>
            <td>
              ${hasQuiz ? `
                ${les.quiz_attempts > 0 ? `
                  <span class="badge ${qPass ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-warning bg-opacity-10 text-dark border border-warning'} fs-9">
                    ${les.quiz_score} / ${les.quiz_total} (${les.quiz_pct}%)
                  </span>
                  ${qFinal ? `<span class="badge bg-success text-white fs-9 ms-1"><i class="bi bi-check-circle-fill"></i> Final</span>` : ''}
                ` : `<span class="text-muted italic fs-9">Not attempted</span>`}
              ` : `<span class="text-muted fs-9">No quiz</span>`}
            </td>
            <td class="text-center">
              ${les.is_fully_completed ? `
                <span class="badge bg-success bg-opacity-10 text-success border border-success px-2 py-1 rounded-pill fs-9">
                  <i class="bi bi-check-circle-fill me-1"></i> Completed
                </span>
              ` : (pct > 0 || les.quiz_attempts > 0 ? `
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-2 py-1 rounded-pill fs-9">
                  In Progress
                </span>
              ` : `
                <span class="badge bg-light text-secondary border px-2 py-1 rounded-pill fs-9">
                  Not Started
                </span>
              `)}
            </td>
          `;
          tbody.appendChild(tr);
        });
      } else {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">No lessons found in this course.</td></tr>`;
      }

      const modal = new bootstrap.Modal(document.getElementById('studentDossierModal'));
      modal.show();
    }

    // Export Gradebook to CSV
    function exportGradebookCSV() {
      if (!STUDENT_SUMMARIES || STUDENT_SUMMARIES.length === 0) {
        alert('No student records available to export.');
        return;
      }

      const rows = [
        ['Student ID', 'Student Name', 'Student Email', 'Academic ID', 'Course Title', 'Lessons Completed', 'Total Lessons', 'Quizzes Passed', 'Total Quizzes', 'Avg Quiz Score (%)', 'Overall Progress (%)', 'Learning Status', 'Last Active']
      ];

      STUDENT_SUMMARIES.forEach(s => {
        rows.push([
          s.student_id,
          s.student_name,
          s.student_email,
          s.student_academic_id,
          s.course_title,
          s.completed_lessons,
          s.total_lessons,
          s.passed_quizzes,
          s.total_quizzes,
          s.avg_quiz_score !== null ? s.avg_quiz_score : 'N/A',
          s.overall_progress,
          s.learning_status,
          s.last_active
        ]);
      });

      let csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + rows.map(e => e.map(item => `"${String(item).replace(/"/g, '""')}"`).join(',')).join('\n');
      const encodedUri = encodeURI(csvContent);
      const link = document.createElement('a');
      link.setAttribute('href', encodedUri);
      link.setAttribute('download', `Gradebook_Export_${new Date().toISOString().slice(0, 10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text || '';
      return div.innerHTML;
    }

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
