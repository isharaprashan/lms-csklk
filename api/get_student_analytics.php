<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to view analytics.']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Check user role
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $role = $stmt->fetchColumn();

    if (!in_array($role, ['teacher', 'admin', 'super_admin'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Teacher/Admin access required.']);
        exit;
    }

    if (in_array($role, ['admin', 'super_admin'])) {
        $stmt = $pdo->query("SELECT id FROM courses");
        $course_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE tutor_id = ?");
        $stmt->execute([$user_id]);
        $course_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($course_ids)) {
        echo json_encode([
            'success' => true,
            'summary' => [
                'total_students' => 0,
                'active_learners' => 0,
                'avg_course_completion' => 0,
                'avg_quiz_score' => 0,
                'quiz_pass_rate' => 0,
                'total_study_hours' => '0m'
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

    // 3. Enrollments
    $stmt = $pdo->prepare("SELECT e.user_id, e.course_id, u.name as student_name, u.email as student_email, u.academic_id, u.avatar as student_avatar, u.status as user_status, c.title as course_title
                           FROM enrollments e
                           JOIN users u ON e.user_id = u.id
                           JOIN courses c ON e.course_id = c.id
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

    // Indexing
    $course_lessons_map = [];
    foreach ($all_lessons as $l) {
        $course_lessons_map[$l['course_id']][] = $l;
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

            $lesson_fully_completed = $is_completed_lesson && (!$has_quiz || $q_is_finalized);

            $matrix_rows[] = [
                'student_id' => $uid,
                'student_name' => $enr['student_name'],
                'student_email' => $enr['student_email'],
                'student_academic_id' => $enr['academic_id'],
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

        $student_summaries[] = [
            'student_id' => $uid,
            'student_name' => $enr['student_name'],
            'student_email' => $enr['student_email'],
            'student_academic_id' => $enr['academic_id'] ?: 'N/A',
            'student_avatar' => $enr['student_avatar'],
            'course_id' => $cid,
            'course_title' => $enr['course_title'],
            'total_lessons' => $total_lessons_count,
            'completed_lessons' => $completed_lessons_count,
            'total_quizzes' => $total_quizzes_count,
            'passed_quizzes' => $passed_quizzes_count,
            'finalized_quizzes' => $finalized_quizzes_count,
            'avg_quiz_score' => $avg_quiz_score,
            'overall_progress' => $overall_progress,
            'total_watch_seconds' => $student_watch_seconds,
            'last_active' => $last_active_time ? date('Y-m-d H:i:s', strtotime($last_active_time)) : null
        ];
    }

    $total_students = count($unique_students_set);
    $active_learners_count = count($active_learners_set);
    $enrollments_count = count($enrollments);
    $avg_course_completion = ($enrollments_count > 0) ? round($total_progress_accum / $enrollments_count) : 0;
    $avg_quiz_score_overall = (count($all_finalized_quiz_scores) > 0) ? round(array_sum($all_finalized_quiz_scores) / count($all_finalized_quiz_scores)) : 0;
    $quiz_pass_rate_overall = (count($all_finalized_quiz_scores) > 0) ? round(($all_quiz_pass_count / count($all_finalized_quiz_scores)) * 100) : 0;

    $study_hours = floor($total_study_seconds / 3600);
    $study_minutes = round(($total_study_seconds % 3600) / 60);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_students' => $total_students,
            'active_learners' => $active_learners_count,
            'avg_course_completion' => $avg_course_completion,
            'avg_quiz_score' => $avg_quiz_score_overall,
            'quiz_pass_rate' => $quiz_pass_rate_overall,
            'total_study_time' => ($study_hours > 0) ? "{$study_hours}h {$study_minutes}m" : "{$study_minutes}m"
        ],
        'student_summaries' => $student_summaries,
        'matrix_rows' => $matrix_rows
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
