<?php
require_once __DIR__ . '/../db/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    if (isset($_COOKIE['LMS_ADMIN_SESS'])) {
        session_name('LMS_ADMIN_SESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
        session_start();
    }
}
if (!isset($_SESSION['user_id'])) {
    init_lms_session();
}

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header('Content-Type: application/json');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Please log in to view analytics.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Check user role
    $stmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $currentUser = $stmt->fetch();

    if (!$currentUser || !in_array($currentUser['role'], ['teacher', 'admin', 'super_admin'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Teacher or Admin access required.']);
        exit;
    }

    $is_admin = in_array($currentUser['role'], ['admin', 'super_admin']);
    $avatar_prefix = $is_admin ? '../' : '';

    // Fetch accessible courses
    if ($is_admin) {
        $stmt = $pdo->query("SELECT c.id, c.title, c.category, c.thumbnail, c.tutor_id, u.name as tutor_name, u.email as tutor_email, u.avatar as tutor_avatar 
                             FROM courses c 
                             LEFT JOIN users u ON c.tutor_id = u.id 
                             ORDER BY c.title ASC");
        $all_courses = $stmt->fetchAll();
        $course_ids = array_column($all_courses, 'id');
    } else {
        $stmt = $pdo->prepare("SELECT c.id, c.title, c.category, c.thumbnail, c.tutor_id, u.name as tutor_name, u.email as tutor_email, u.avatar as tutor_avatar 
                               FROM courses c 
                               LEFT JOIN users u ON c.tutor_id = u.id 
                               WHERE c.tutor_id = ? 
                               ORDER BY c.title ASC");
        $stmt->execute([$user_id]);
        $all_courses = $stmt->fetchAll();
        $course_ids = array_column($all_courses, 'id');
    }

    if (empty($course_ids)) {
        echo json_encode([
            'success' => true,
            'is_admin' => $is_admin,
            'summary' => [
                'total_students' => 0,
                'active_learners' => 0,
                'avg_course_completion' => 0,
                'avg_quiz_score' => 0,
                'quiz_pass_rate' => 0,
                'total_study_time' => '0m',
                'total_assignments' => 0,
                'total_submissions' => 0,
                'pending_submissions' => 0,
                'graded_submissions' => 0,
                'avg_assignment_grade' => null
            ],
            'student_summaries' => [],
            'matrix_rows' => [],
            'lesson_insights' => []
        ]);
        exit;
    }

    $in_clause = implode(',', array_fill(0, count($course_ids), '?'));

    // 1. Lessons
    $stmt = $pdo->prepare("SELECT id, course_id, title, duration, sort_order FROM lessons WHERE course_id IN ($in_clause) ORDER BY course_id, sort_order ASC, id ASC");
    $stmt->execute($course_ids);
    $all_lessons = $stmt->fetchAll();

    // 2. Quizzes
    $stmt = $pdo->prepare("SELECT lesson_id, course_id, COUNT(*) as question_count FROM quizzes WHERE course_id IN ($in_clause) GROUP BY lesson_id, course_id");
    $stmt->execute($course_ids);
    $quizzes_info = $stmt->fetchAll();
    $lessons_with_quizzes = [];
    foreach ($quizzes_info as $qi) {
        $lessons_with_quizzes[$qi['lesson_id']] = (int) $qi['question_count'];
    }

    // 3. Enrollments with Student and Tutor Information
    $stmt = $pdo->prepare("SELECT e.user_id, e.course_id, 
                                  u.name as student_name, u.email as student_email, u.academic_id, u.avatar as student_avatar, u.status as user_status,
                                  c.title as course_title, c.tutor_id,
                                  t.name as tutor_name, t.email as tutor_email, t.avatar as tutor_avatar
                           FROM enrollments e
                           JOIN users u ON e.user_id = u.id
                           JOIN courses c ON e.course_id = c.id
                           LEFT JOIN users t ON c.tutor_id = t.id
                           WHERE e.course_id IN ($in_clause)
                           ORDER BY c.title ASC, u.name ASC");
    $stmt->execute($course_ids);
    $enrollments = $stmt->fetchAll();

    // 4. Watch progress
    $stmt = $pdo->prepare("SELECT lp.user_id, lp.lesson_id, lp.position_seconds, lp.duration_seconds, lp.progress_percent, lp.completed, lp.updated_at, l.course_id
                           FROM lesson_progress lp
                           JOIN lessons l ON lp.lesson_id = l.id
                           WHERE l.course_id IN ($in_clause)");
    $stmt->execute($course_ids);
    $progress_data = $stmt->fetchAll();

    // 5. Completed lessons
    $stmt = $pdo->prepare("SELECT cl.user_id, cl.lesson_id, l.course_id
                           FROM completed_lessons cl
                           JOIN lessons l ON cl.lesson_id = l.id
                           WHERE l.course_id IN ($in_clause)");
    $stmt->execute($course_ids);
    $completed_lessons_data = $stmt->fetchAll();

    // 6. Quiz attempts
    $stmt = $pdo->prepare("SELECT user_id, course_id, lesson_id, MAX(score) as best_score, MAX(total_questions) as total_questions, MAX(attempt_number) as attempts_count,
                                  MAX(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) as is_finalized,
                                  MAX(updated_at) as last_attempt_at
                           FROM quiz_attempts
                           WHERE course_id IN ($in_clause)
                           GROUP BY user_id, course_id, lesson_id");
    $stmt->execute($course_ids);
    $quiz_attempts_data = $stmt->fetchAll();

    // 7. Assignments for accessible courses
    $stmt = $pdo->prepare("SELECT a.id, a.lesson_id, a.course_id, a.title, a.instructions, a.attachment_path, a.attachment_name, a.attachment_size, a.due_date,
                                  l.title as lesson_title, l.sort_order as lesson_order
                           FROM lesson_assignments a
                           INNER JOIN lessons l ON a.lesson_id = l.id
                           WHERE a.course_id IN ($in_clause)
                           ORDER BY a.course_id, l.sort_order ASC, a.id ASC");
    $stmt->execute($course_ids);
    $all_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Assignment Submissions for accessible courses
    $stmt = $pdo->prepare("SELECT sub.id, sub.assignment_id, sub.lesson_id, sub.course_id, sub.user_id,
                                  sub.submission_file, sub.file_name, sub.file_size, sub.student_notes,
                                  sub.status, sub.grade, sub.feedback, sub.graded_at, sub.submitted_at
                           FROM assignment_submissions sub
                           WHERE sub.course_id IN ($in_clause)");
    $stmt->execute($course_ids);
    $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Indexing maps
    $course_lessons_map = [];
    foreach ($all_lessons as $l) {
        $course_lessons_map[$l['course_id']][] = $l;
    }

    $course_assignments_map = [];
    $lesson_assignment_map = [];
    foreach ($all_assignments as $asg) {
        $course_assignments_map[$asg['course_id']][] = $asg;
        $lesson_assignment_map[$asg['lesson_id']] = $asg;
    }

    $assignment_submissions_map = [];
    $all_graded_assignment_grades = [];
    $total_pending_asg_submissions = 0;
    $total_graded_asg_submissions = 0;

    foreach ($all_submissions as $sub) {
        $key = $sub['user_id'] . '_' . $sub['assignment_id'];
        $assignment_submissions_map[$key] = $sub;
        if ($sub['status'] === 'graded' && $sub['grade'] !== null) {
            $total_graded_asg_submissions++;
            $all_graded_assignment_grades[] = (float)$sub['grade'];
        } else {
            $total_pending_asg_submissions++;
        }
    }

    $progress_map = [];
    $total_study_seconds = 0;
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

    $student_summaries = [];
    $matrix_rows = [];
    $active_learners_set = [];
    $unique_students_set = [];
    $total_progress_accum = 0;
    $all_finalized_quiz_scores = [];
    $all_quiz_pass_count = 0;


    foreach ($enrollments as $enr) {
        $uid = $enr['user_id'];
        $cid = $enr['course_id'];
        $tut_id = $enr['tutor_id'] ? (int) $enr['tutor_id'] : 0;
        $tut_name = $enr['tutor_name'] ?: 'Unassigned Lecturer';
        $resolved_student_avatar = get_user_avatar($enr['student_avatar'], $enr['student_name'], '0f4c81', 'fff', $avatar_prefix);

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

        // Assignments for this course
        $c_assignments = $course_assignments_map[$cid] ?? [];
        $total_assignments_count = count($c_assignments);
        $submitted_assignments_count = 0;
        $graded_assignments_count = 0;
        $student_assignment_grades = [];
        $student_assignments_list = [];

        foreach ($c_assignments as $asg) {
            $asg_id = $asg['id'];
            $asg_sub = $assignment_submissions_map[$uid . '_' . $asg_id] ?? null;
            $is_sub = ($asg_sub !== null);

            $s_grade = null;
            $s_status = 'not_submitted';
            $s_feedback = null;
            $s_file = null;
            $s_file_path = null;
            $s_submitted_at = null;
            $s_notes = null;

            if ($is_sub) {
                $submitted_assignments_count++;
                $s_status = $asg_sub['status'] ?: 'submitted';
                $s_file = $asg_sub['file_name'];
                $s_file_path = $asg_sub['submission_file'];
                $s_submitted_at = $asg_sub['submitted_at'];
                $s_notes = $asg_sub['student_notes'];
                if ($asg_sub['status'] === 'graded' && $asg_sub['grade'] !== null) {
                    $graded_assignments_count++;
                    $s_grade = (float)$asg_sub['grade'];
                    $student_assignment_grades[] = $s_grade;
                    $s_feedback = $asg_sub['feedback'];
                }
                if (!empty($asg_sub['submitted_at'])) {
                    if (!$last_active_time || strtotime($asg_sub['submitted_at']) > strtotime($last_active_time)) {
                        $last_active_time = $asg_sub['submitted_at'];
                    }
                }
            }

            $student_assignments_list[] = [
                'assignment_id' => $asg_id,
                'lesson_id' => $asg['lesson_id'],
                'lesson_title' => $asg['lesson_title'],
                'lesson_order' => $asg['lesson_order'],
                'assignment_title' => $asg['title'],
                'due_date' => $asg['due_date'],
                'is_submitted' => $is_sub,
                'status' => $s_status,
                'grade' => $s_grade,
                'feedback' => $s_feedback,
                'file_name' => $s_file,
                'submission_file' => $s_file_path,
                'submitted_at' => $s_submitted_at ? date('M d, Y H:i', strtotime($s_submitted_at)) : null,
                'student_notes' => $s_notes
            ];
        }

        foreach ($c_lessons as $idx => $les) {
            $lid = $les['id'];
            $prog_key = $uid . '_' . $lid;
            $quiz_key = $uid . '_' . $cid . '_' . $lid;
            $quiz_fallback_key = $uid . '_' . $cid . '_';

            $prog = $progress_map[$prog_key] ?? null;
            $is_completed_lesson = isset($completed_lessons_map[$prog_key]) || ($prog && ($prog['completed'] == 1 || (float) $prog['progress_percent'] >= 90));
            $pct_watched = $prog ? (float) $prog['progress_percent'] : 0;
            if ($is_completed_lesson && $pct_watched < 100) {
                $pct_watched = 100;
            }
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

            // Assignment for this specific lesson
            $asg_for_lesson = $lesson_assignment_map[$lid] ?? null;
            $has_asg = ($asg_for_lesson !== null);
            $asg_sub = $has_asg ? ($assignment_submissions_map[$uid . '_' . $asg_for_lesson['id']] ?? null) : null;
            $asg_is_sub = ($asg_sub !== null);
            $asg_status = $has_asg ? ($asg_sub ? ($asg_sub['status'] ?: 'submitted') : 'missing') : null;
            $asg_grade = ($asg_sub && $asg_sub['grade'] !== null) ? (float)$asg_sub['grade'] : null;
            $asg_feedback = $asg_sub ? ($asg_sub['feedback'] ?? null) : null;
            $asg_file = $asg_sub ? ($asg_sub['file_name'] ?? null) : null;
            $asg_file_path = $asg_sub ? ($asg_sub['submission_file'] ?? null) : null;
            $asg_submitted_at = ($asg_sub && !empty($asg_sub['submitted_at'])) ? date('M d, Y H:i', strtotime($asg_sub['submitted_at'])) : null;

            $lesson_fully_completed = $is_completed_lesson && (!$has_quiz || $q_is_finalized) && (!$has_asg || $asg_is_sub);

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
                'has_assignment' => $has_asg,
                'assignment_id' => $has_asg ? $asg_for_lesson['id'] : null,
                'assignment_title' => $has_asg ? $asg_for_lesson['title'] : null,
                'assignment_is_submitted' => $asg_is_sub,
                'assignment_status' => $asg_status,
                'assignment_grade' => $asg_grade,
                'assignment_feedback' => $asg_feedback,
                'assignment_file' => $asg_file,
                'assignment_file_path' => $asg_file_path,
                'assignment_submitted_at' => $asg_submitted_at,
                'assignment_due_date' => $has_asg ? $asg_for_lesson['due_date'] : null,
                'is_fully_completed' => $lesson_fully_completed
            ];

            $student_lesson_details[] = $lesson_detail;

            $matrix_rows[] = [
                'student_id' => $uid,
                'student_name' => $enr['student_name'],
                'student_email' => $enr['student_email'],
                'student_academic_id' => $enr['academic_id'] ?: 'N/A',
                'student_avatar' => $resolved_student_avatar,
                'tutor_id' => $tut_id,
                'tutor_name' => $tut_name,
                'course_id' => $cid,
                'course_title' => $enr['course_title'],
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
                'has_assignment' => $has_asg,
                'assignment_id' => $has_asg ? $asg_for_lesson['id'] : null,
                'assignment_title' => $has_asg ? $asg_for_lesson['title'] : null,
                'assignment_is_submitted' => $asg_is_sub,
                'assignment_status' => $asg_status,
                'assignment_grade' => $asg_grade,
                'assignment_feedback' => $asg_feedback,
                'assignment_file' => $asg_file,
                'assignment_file_path' => $asg_file_path,
                'assignment_submitted_at' => $asg_submitted_at,
                'is_fully_completed' => $lesson_fully_completed
            ];
        }

        $total_milestones = $total_lessons_count + $total_quizzes_count + $total_assignments_count;
        $completed_milestones = $completed_lessons_count + $finalized_quizzes_count + $submitted_assignments_count;
        $overall_progress = ($total_milestones > 0) ? round(($completed_milestones / $total_milestones) * 100) : 0;

        $all_assignments_done = ($total_assignments_count === 0 || $submitted_assignments_count >= $total_assignments_count);

        // REQUIREMENT: For a course to reach 100% completion, students must submit any required assignments
        if (!$all_assignments_done) {
            $overall_progress = min(99, $overall_progress);
        }
        $total_progress_accum += $overall_progress;

        $avg_quiz_score = count($student_quiz_scores_pct) > 0 ? round(array_sum($student_quiz_scores_pct) / count($student_quiz_scores_pct)) : null;
        $avg_assignment_grade = count($student_assignment_grades) > 0 ? round(array_sum($student_assignment_grades) / count($student_assignment_grades), 1) : null;

        if ($overall_progress >= 100 && $all_assignments_done) {
            $learning_status = '100% Completed';
            $status_badge_class = 'bg-success text-white';
        } elseif ($completed_lessons_count >= $total_lessons_count && !$all_assignments_done) {
            $learning_status = 'Assignments Pending';
            $status_badge_class = 'bg-warning text-dark';
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

        $student_summaries[] = [
            'student_id' => $uid,
            'student_name' => $enr['student_name'],
            'student_email' => $enr['student_email'],
            'student_academic_id' => $enr['academic_id'] ?: 'N/A',
            'student_avatar' => $resolved_student_avatar,
            'tutor_id' => $tut_id,
            'tutor_name' => $tut_name,
            'course_id' => $cid,
            'course_title' => $enr['course_title'],
            'total_lessons' => $total_lessons_count,
            'completed_lessons' => $completed_lessons_count,
            'total_quizzes' => $total_quizzes_count,
            'passed_quizzes' => $passed_quizzes_count,
            'finalized_quizzes' => $finalized_quizzes_count,
            'avg_quiz_score' => $avg_quiz_score,
            'total_assignments' => $total_assignments_count,
            'submitted_assignments' => $submitted_assignments_count,
            'graded_assignments' => $graded_assignments_count,
            'avg_assignment_grade' => $avg_assignment_grade,
            'all_assignments_done' => $all_assignments_done,
            'assignments' => $student_assignments_list,
            'overall_progress' => $overall_progress,
            'learning_status' => $learning_status,
            'status_badge_class' => $status_badge_class,
            'total_watch_seconds' => $student_watch_seconds,
            'last_active' => $last_active_time ? date('M d, Y H:i', strtotime($last_active_time)) : 'Never',
            'lessons' => $student_lesson_details
        ];
    }


    // 7. Compute Lesson Insights (Tab 3)
    $lesson_insights = [];
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
                    if ($pct >= 50) {
                        $quiz_passed_count++;
                    }
                }
            }
        }

        $video_completion_rate = ($c_total_enrolled > 0) ? round(($watched_100_count / $c_total_enrolled) * 100) : 0;
        $avg_quiz_score_l = count($quiz_scores_list) > 0 ? round(array_sum($quiz_scores_list) / count($quiz_scores_list)) : null;
        $quiz_pass_rate_l = ($quiz_attempted_students > 0) ? round(($quiz_passed_count / $quiz_attempted_students) * 100) : null;
        $avg_attempts = ($quiz_attempted_students > 0) ? round($total_retakes / $quiz_attempted_students, 1) : 0;

        $diff_badge = 'Standard';
        $diff_class = 'bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25';
        if ($avg_quiz_score_l !== null && $avg_quiz_score_l < 50) {
            $diff_badge = 'Challenging';
            $diff_class = 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25';
        } elseif ($avg_attempts > 2.0) {
            $diff_badge = 'High Retakes';
            $diff_class = 'bg-warning bg-opacity-15 text-dark border border-warning border-opacity-35';
        } elseif ($avg_quiz_score_l !== null && $avg_quiz_score_l >= 80) {
            $diff_badge = 'High Mastery';
            $diff_class = 'bg-success bg-opacity-10 text-success border border-success border-opacity-25';
        }

        $c_info = null;
        foreach ($all_courses as $ac) {
            if ($ac['id'] === $cid) {
                $c_info = $ac;
                break;
            }
        }

        // Assignment metrics for this lesson
        $asg_for_lesson = $lesson_assignment_map[$lid] ?? null;
        $has_asg = ($asg_for_lesson !== null);
        $asg_submitted_count = 0;
        $asg_graded_count = 0;
        $asg_scores_list = [];

        if ($has_asg) {
            foreach ($enrolled_in_course as $enr) {
                $uid = $enr['user_id'];
                $asub = $assignment_submissions_map[$uid . '_' . $asg_for_lesson['id']] ?? null;
                if ($asub) {
                    $asg_submitted_count++;
                    if ($asub['status'] === 'graded' && $asub['grade'] !== null) {
                        $asg_graded_count++;
                        $asg_scores_list[] = (float)$asub['grade'];
                    }
                }
            }
        }

        $asg_submission_rate = ($c_total_enrolled > 0 && $has_asg) ? round(($asg_submitted_count / $c_total_enrolled) * 100) : null;
        $asg_avg_score = count($asg_scores_list) > 0 ? round(array_sum($asg_scores_list) / count($asg_scores_list), 1) : null;

        $lesson_insights[] = [
            'course_id' => $cid,
            'course_title' => $c_info['title'] ?? $cid,
            'tutor_id' => $c_info['tutor_id'] ? (int) $c_info['tutor_id'] : 0,
            'tutor_name' => $c_info['tutor_name'] ?: 'Unassigned Lecturer',
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
            'difficulty_class' => $diff_class,
            'has_assignment' => $has_asg,
            'assignment_id' => $has_asg ? $asg_for_lesson['id'] : null,
            'assignment_title' => $has_asg ? $asg_for_lesson['title'] : null,
            'assignment_submitted_count' => $asg_submitted_count,
            'assignment_submission_rate' => $asg_submission_rate,
            'assignment_avg_grade' => $asg_avg_score
        ];
    }

    $total_students = count($unique_students_set);
    $active_learners_count = count($active_learners_set);
    $enrollments_count = count($enrollments);
    $avg_course_completion = ($enrollments_count > 0) ? round($total_progress_accum / $enrollments_count) : 0;
    $avg_quiz_score_overall = (count($all_finalized_quiz_scores) > 0) ? round(array_sum($all_finalized_quiz_scores) / count($all_finalized_quiz_scores)) : 0;
    $quiz_pass_rate_overall = (count($all_finalized_quiz_scores) > 0) ? round(($all_quiz_pass_count / count($all_finalized_quiz_scores)) * 100) : 0;

    $int_study_seconds = (int)$total_study_seconds;
    $study_hours = floor($int_study_seconds / 3600);
    $study_minutes = round(($int_study_seconds % 3600) / 60);

    echo json_encode([
        'success' => true,
        'is_admin' => $is_admin,
        'timestamp' => time(),
        'summary' => [
            'total_students' => $total_students,
            'active_learners' => $active_learners_count,
            'avg_course_completion' => $avg_course_completion,
            'avg_quiz_score' => $avg_quiz_score_overall,
            'quiz_pass_rate' => $quiz_pass_rate_overall,
            'total_study_time' => ($study_hours > 0) ? "{$study_hours}h {$study_minutes}m" : "{$study_minutes}m",
            'total_assignments' => count($all_assignments),
            'total_submissions' => count($all_submissions),
            'pending_submissions' => $total_pending_asg_submissions,
            'graded_submissions' => $total_graded_asg_submissions,
            'avg_assignment_grade' => count($all_graded_assignment_grades) > 0 ? round(array_sum($all_graded_assignment_grades) / count($all_graded_assignment_grades), 1) : null
        ],
        'student_summaries' => $student_summaries,
        'matrix_rows' => $matrix_rows,
        'lesson_insights' => $lesson_insights
    ]);


} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
