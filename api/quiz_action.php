<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('login_required', 'Please log in to continue.')]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$action = $input['action'] ?? 'get_state';
$course_id = trim($input['course_id'] ?? '');
$lesson_id = trim($input['lesson_id'] ?? '');

if (empty($course_id)) {
    echo json_encode(['success' => false, 'message' => __('course_required', 'Course ID is required.')]);
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch Course Quiz Settings (max_attempts, pass_percentage)
    $stmt = $pdo->prepare("SELECT * FROM course_quiz_settings WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $settings = $stmt->fetch() ?: ['max_attempts' => 3, 'pass_percentage' => 50];
    $max_allowed_attempts = (int) $settings['max_attempts'];

    // Fetch quiz questions for this course / lesson
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND lesson_id = ? ORDER BY id ASC");
        $stmt->execute([$course_id, $lesson_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND (lesson_id IS NULL OR lesson_id = '') ORDER BY id ASC");
        $stmt->execute([$course_id]);
    }
    $questions = $stmt->fetchAll();

    if (empty($questions)) {
        echo json_encode(['success' => false, 'message' => __('no_quiz_found', 'No quiz found for this course.')]);
        exit;
    }

    $total_questions = count($questions);

    // Verify lesson is unlocked for student access
    $user_role = $_SESSION['user_role'] ?? '';
    if (empty($user_role) || !in_array($user_role, ['student', 'teacher', 'admin', 'super_admin'])) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_role = $stmt->fetchColumn() ?: 'student';
    }
    $is_admin_or_teacher = in_array($user_role, ['admin', 'super_admin', 'teacher']);

    if (!$is_admin_or_teacher && !empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$course_id]);
        $course_lessons = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT lesson_id FROM completed_lessons WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $comp = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("SELECT lp.lesson_id FROM lesson_progress lp INNER JOIN lessons l ON l.id = lp.lesson_id WHERE lp.user_id = ? AND l.course_id = ? AND (lp.completed = 1 OR lp.progress_percent >= 90)");
        $stmt->execute([$user_id, $course_id]);
        $watched = array_unique(array_merge($comp ?? [], $stmt->fetchAll(PDO::FETCH_COLUMN)));

        $unlocked_ids = [];
        foreach ($course_lessons as $idx => $l) {
            if ($idx === 0) {
                $unlocked_ids[] = $l['id'];
            } else {
                $prev_l = $course_lessons[$idx - 1];
                if (in_array($prev_l['id'], $watched)) {
                    $unlocked_ids[] = $l['id'];
                }
            }
        }

        if (!in_array($lesson_id, $unlocked_ids)) {
            echo json_encode(['success' => false, 'message' => __('quiz_locked', 'This quiz is locked because the corresponding lesson is not unlocked yet.')]);
            exit;
        }
    }

    // Fetch all attempts for user & course & lesson ordered by attempt_number DESC
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND lesson_id = ? ORDER BY attempt_number DESC");
        $stmt->execute([$user_id, $course_id, $lesson_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE user_id = ? AND course_id = ? AND (lesson_id IS NULL OR lesson_id = '') ORDER BY attempt_number DESC");
        $stmt->execute([$user_id, $course_id]);
    }
    $all_attempts = $stmt->fetchAll();

    $latest_attempt = $all_attempts[0] ?? null;

    // If latest attempt is in progress and total questions in lesson changed, sync total_questions
    if ($latest_attempt && $latest_attempt['status'] === 'in_progress' && (int)$latest_attempt['total_questions'] !== $total_questions) {
        $stmt = $pdo->prepare("UPDATE quiz_attempts SET total_questions = ? WHERE id = ?");
        $stmt->execute([$total_questions, $latest_attempt['id']]);
        $latest_attempt['total_questions'] = $total_questions;
    }

    if ($action === 'get_state') {
        if (!$latest_attempt) {
            // Create attempt 1
            $now = time();
            $stmt = $pdo->prepare("INSERT INTO quiz_attempts (user_id, course_id, lesson_id, attempt_number, score, total_questions, status, current_question_index, question_started_at, answers_json) VALUES (?, ?, ?, 1, 0, ?, 'in_progress', 0, ?, '{}')");
            $stmt->execute([$user_id, $course_id, $lesson_id, $total_questions, $now]);

            $attempt_id = $pdo->lastInsertId();
            $latest_attempt = [
                'id' => $attempt_id,
                'user_id' => $user_id,
                'course_id' => $course_id,
                'lesson_id' => $lesson_id,
                'attempt_number' => 1,
                'score' => 0,
                'total_questions' => $total_questions,
                'status' => 'in_progress',
                'current_question_index' => 0,
                'question_started_at' => $now,
                'answers_json' => '{}'
            ];
        }

        // Process timeout checks if in_progress
        $latest_attempt = process_timeout_checks($pdo, $latest_attempt, $questions);

        echo json_encode(build_response_payload($latest_attempt, $questions, $max_allowed_attempts));
        exit;
    }

    if ($action === 'submit_answer') {
        if (!$latest_attempt || $latest_attempt['status'] !== 'in_progress') {
            echo json_encode(['success' => false, 'message' => __('no_active_attempt', 'No active quiz attempt found.')]);
            exit;
        }

        $latest_attempt = process_timeout_checks($pdo, $latest_attempt, $questions);

        if ($latest_attempt['status'] !== 'in_progress') {
            echo json_encode(build_response_payload($latest_attempt, $questions, $max_allowed_attempts));
            exit;
        }

        $curr_idx = (int) $latest_attempt['current_question_index'];

        if ($curr_idx >= $total_questions) {
            $latest_attempt['status'] = 'completed';
            update_quiz_results_table($pdo, $user_id, $course_id, $latest_attempt['score'], $total_questions, $latest_attempt['attempt_number'], 'completed', $lesson_id);
            echo json_encode(build_response_payload($latest_attempt, $questions, $max_allowed_attempts));
            exit;
        }

        $current_q = $questions[$curr_idx];
        $q_type = $current_q['question_type'] ?? 'mcq';
        $is_correct = false;
        $user_given_answer = null;

        if ($q_type === 'mcq') {
            $selected_index = isset($input['selected_index']) ? (int) $input['selected_index'] : -1;
            $user_given_answer = $selected_index;
            $correct_index = (int) $current_q['answer_index'];
            $is_correct = ($selected_index === $correct_index);
        } else {
            // Text Input Question grading
            $user_text = trim($input['user_answer_text'] ?? '');
            $user_given_answer = $user_text;
            $target_pattern = trim($current_q['correct_answer'] ?? '');

            if (!empty($target_pattern)) {
                $user_clean = mb_strtolower(trim($user_text));
                $target_clean = mb_strtolower(trim($target_pattern));

                // Exact match or substring keyword match
                if ($user_clean === $target_clean || ($target_clean !== '' && strpos($user_clean, $target_clean) !== false)) {
                    $is_correct = true;
                }
            }
        }

        $answers = json_decode($latest_attempt['answers_json'] ?? '{}', true) ?: [];
        $answers[$current_q['question_id']] = $user_given_answer;

        $next_idx = $curr_idx + 1;
        $now = time();
        if ($next_idx >= $total_questions) {
            $new_status = ((int)$latest_attempt['attempt_number'] >= $max_allowed_attempts) ? 'finalized' : 'completed';
        } else {
            $new_status = 'in_progress';
        }

        // Calculate running score
        $score = calculate_attempt_score($questions, $answers);

        $stmt = $pdo->prepare("UPDATE quiz_attempts SET current_question_index = ?, question_started_at = ?, answers_json = ?, score = ?, status = ? WHERE id = ?");
        $stmt->execute([$next_idx, $now, json_encode($answers), $score, $new_status, $latest_attempt['id']]);

        $latest_attempt['current_question_index'] = $next_idx;
        $latest_attempt['question_started_at'] = $now;
        $latest_attempt['answers_json'] = json_encode($answers);
        $latest_attempt['score'] = $score;
        $latest_attempt['status'] = $new_status;

        if ($new_status === 'completed' || $new_status === 'finalized') {
            update_quiz_results_table($pdo, $user_id, $course_id, $score, $total_questions, $latest_attempt['attempt_number'], $new_status, $lesson_id);
        }

        $payload = build_response_payload($latest_attempt, $questions, $max_allowed_attempts);
        $payload['is_correct'] = $is_correct;
        echo json_encode($payload);
        exit;
    }

    if ($action === 'finalize_quiz') {
        if (!$latest_attempt) {
            echo json_encode(['success' => false, 'message' => __('no_attempt_to_finalize', 'No attempt found to finalize.')]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE quiz_attempts SET status = 'finalized' WHERE id = ?");
        $stmt->execute([$latest_attempt['id']]);

        $latest_attempt['status'] = 'finalized';

        update_quiz_results_table($pdo, $user_id, $course_id, $latest_attempt['score'], $total_questions, $latest_attempt['attempt_number'], 'finalized', $lesson_id);

        echo json_encode(build_response_payload($latest_attempt, $questions, $max_allowed_attempts));
        exit;
    }

    if ($action === 'retake_quiz') {
        if (!$latest_attempt) {
            echo json_encode(['success' => false, 'message' => __('no_previous_attempt', 'No previous attempt found.')]);
            exit;
        }

        $max_attempt = (int) $latest_attempt['attempt_number'];

        if ($max_attempt >= $max_allowed_attempts || $latest_attempt['status'] === 'finalized') {
            echo json_encode(['success' => false, 'message' => __('max_attempts_reached', 'Maximum attempts reached or quiz already finalized.')]);
            exit;
        }

        $next_attempt_num = $max_attempt + 1;
        $now = time();

        $stmt = $pdo->prepare("INSERT INTO quiz_attempts (user_id, course_id, lesson_id, attempt_number, score, total_questions, status, current_question_index, question_started_at, answers_json) VALUES (?, ?, ?, ?, 0, ?, 'in_progress', 0, ?, '{}')");
        $stmt->execute([$user_id, $course_id, $lesson_id, $next_attempt_num, $total_questions, $now]);

        $new_attempt_id = $pdo->lastInsertId();
        $new_attempt = [
            'id' => $new_attempt_id,
            'user_id' => $user_id,
            'course_id' => $course_id,
            'lesson_id' => $lesson_id,
            'attempt_number' => $next_attempt_num,
            'score' => 0,
            'total_questions' => $total_questions,
            'status' => 'in_progress',
            'current_question_index' => 0,
            'question_started_at' => $now,
            'answers_json' => '{}'
        ];

        echo json_encode(build_response_payload($new_attempt, $questions, $max_allowed_attempts));
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

function process_timeout_checks($pdo, $attempt, $questions)
{
    if ($attempt['status'] !== 'in_progress') {
        return $attempt;
    }

    $total = count($questions);
    $curr_idx = (int) $attempt['current_question_index'];
    $started_at = (int) ($attempt['question_started_at'] ?? time());
    $answers = json_decode($attempt['answers_json'] ?? '{}', true) ?: [];

    $now = time();

    while ($curr_idx < $total && $attempt['status'] === 'in_progress') {
        $current_q = $questions[$curr_idx];
        $time_limit = (int) ($current_q['time_limit_seconds'] ?? 30);
        $elapsed = $now - $started_at;

        if ($elapsed >= $time_limit) {
            $qid = $current_q['question_id'];
            if (!isset($answers[$qid])) {
                $answers[$qid] = ($current_q['question_type'] === 'text') ? '' : -1;
            }
            $curr_idx++;
            $started_at += $time_limit;
            if ($curr_idx >= $total) {
                $attempt['status'] = 'completed';
                break;
            }
        } else {
            break;
        }
    }

    $score = calculate_attempt_score($questions, $answers);

    $stmt = $pdo->prepare("UPDATE quiz_attempts SET current_question_index = ?, question_started_at = ?, answers_json = ?, score = ?, status = ? WHERE id = ?");
    $stmt->execute([$curr_idx, $started_at, json_encode($answers), $score, $attempt['status'], $attempt['id']]);

    $attempt['current_question_index'] = $curr_idx;
    $attempt['question_started_at'] = $started_at;
    $attempt['answers_json'] = json_encode($answers);
    $attempt['score'] = $score;

    if ($attempt['status'] === 'completed') {
        update_quiz_results_table($pdo, $attempt['user_id'], $attempt['course_id'], $score, $total, $attempt['attempt_number']);
    }

    return $attempt;
}

function calculate_attempt_score($questions, $answers)
{
    $score = 0;
    foreach ($questions as $q) {
        $qid = $q['question_id'];
        $q_type = $q['question_type'] ?? 'mcq';
        if (isset($answers[$qid])) {
            if ($q_type === 'mcq') {
                if ((int) $answers[$qid] === (int) $q['answer_index']) {
                    $score++;
                }
            } else {
                $user_clean = mb_strtolower(trim((string) $answers[$qid]));
                $target_clean = mb_strtolower(trim((string) ($q['correct_answer'] ?? '')));
                if (!empty($target_clean) && ($user_clean === $target_clean || strpos($user_clean, $target_clean) !== false)) {
                    $score++;
                }
            }
        }
    }
    return $score;
}

function update_quiz_results_table($pdo, $user_id, $course_id, $score, $total, $attempt_num, $status = 'completed', $lesson_id = '')
{
    $userRole = $_SESSION['user_role'] ?? '';
    if (in_array($userRole, ['admin', 'super_admin', 'teacher'])) {
        return; // Exclude admins and teachers from DB progress saving
    }
    $stmt = $pdo->prepare("INSERT INTO quiz_results (user_id, course_id, score, total_questions, status, attempts_count)
                           VALUES (?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                             score = GREATEST(score, VALUES(score)),
                             total_questions = VALUES(total_questions),
                             status = VALUES(status),
                             attempts_count = GREATEST(attempts_count, VALUES(attempts_count))");
    $stmt->execute([$user_id, $course_id, $score, $total, $status, $attempt_num]);
}

function build_response_payload($attempt, $questions, $max_allowed_attempts)
{
    $total = count($questions);
    $curr_idx = (int) $attempt['current_question_index'];
    $started_at = (int) ($attempt['question_started_at'] ?? time());
    $now = time();

    $current_q = $questions[$curr_idx] ?? null;
    $time_limit = (int) ($current_q['time_limit_seconds'] ?? 30);
    $remaining_seconds = max(0, $time_limit - ($now - $started_at));

    $is_locked = ($attempt['status'] === 'finalized' || (int) $attempt['attempt_number'] >= $max_allowed_attempts && $attempt['status'] !== 'in_progress');

    $response = [
        'success' => true,
        'attempt' => [
            'id' => (int) $attempt['id'],
            'attempt_number' => (int) $attempt['attempt_number'],
            'max_allowed_attempts' => $max_allowed_attempts,
            'score' => (int) $attempt['score'],
            'total_questions' => $total,
            'status' => $attempt['status'],
            'current_question_index' => $curr_idx,
            'is_locked' => $is_locked
        ],
        'remaining_seconds' => $remaining_seconds
    ];

    if ($attempt['status'] === 'in_progress' && $curr_idx < $total) {
        $q = $questions[$curr_idx];
        $response['current_question'] = [
            'question_id' => $q['question_id'],
            'question_number' => $curr_idx + 1,
            'question' => $q['question'],
            'question_type' => $q['question_type'] ?? 'mcq',
            'image_path' => $q['image_path'] ?? null,
            'time_limit_seconds' => (int) ($q['time_limit_seconds'] ?? 30),
            'options' => ($q['question_type'] ?? 'mcq') === 'mcq' ? [
                $q['option_1'],
                $q['option_2'],
                $q['option_3'],
                $q['option_4']
            ] : []
        ];
    }

    if ($attempt['status'] === 'completed' || $attempt['status'] === 'finalized' || $is_locked) {
        $user_answers = json_decode($attempt['answers_json'] ?? '{}', true) ?: [];
        $review = [];

        foreach ($questions as $q_idx => $q) {
            $qid = $q['question_id'];
            $q_type = $q['question_type'] ?? 'mcq';
            $user_ans = $user_answers[$qid] ?? null;
            $is_correct = false;

            if ($q_type === 'mcq') {
                $user_sel = ($user_ans !== null) ? (int) $user_ans : -1;
                $correct_idx = (int) $q['answer_index'];
                $is_correct = ($user_sel === $correct_idx);

                $review[] = [
                    'question_number' => $q_idx + 1,
                    'question_id' => $qid,
                    'question' => $q['question'],
                    'question_type' => 'mcq',
                    'image_path' => $q['image_path'] ?? null,
                    'options' => [
                        $q['option_1'],
                        $q['option_2'],
                        $q['option_3'],
                        $q['option_4']
                    ],
                    'user_selection' => $user_sel,
                    'correct_index' => $correct_idx,
                    'is_correct' => $is_correct,
                    'explanation' => $q['explanation'] ?? __('default_explanation', 'Refer to course materials.')
                ];
            } else {
                $user_text = (string) ($user_ans ?? '');
                $target_pattern = (string) ($q['correct_answer'] ?? '');
                $user_clean = mb_strtolower(trim($user_text));
                $target_clean = mb_strtolower(trim($target_pattern));

                if (!empty($target_clean) && ($user_clean === $target_clean || strpos($user_clean, $target_clean) !== false)) {
                    $is_correct = true;
                }

                $review[] = [
                    'question_number' => $q_idx + 1,
                    'question_id' => $qid,
                    'question' => $q['question'],
                    'question_type' => 'text',
                    'image_path' => $q['image_path'] ?? null,
                    'user_text' => $user_text,
                    'correct_answer' => $target_pattern,
                    'is_correct' => $is_correct,
                    'explanation' => $q['explanation'] ?? __('default_explanation', 'Refer to course materials.')
                ];
            }
        }

        $response['review'] = $review;
    }

    return $response;
}
?>