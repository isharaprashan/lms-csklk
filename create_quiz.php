<?php
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Auth Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$course_id = $_GET['course_id'] ?? '';
$lesson_id = $_GET['lesson_id'] ?? '';

if (empty($course_id)) {
    header("Location: dashboard.php");
    exit;
}

try {
    $pdo = getDBConnection();

    // Fetch user details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (in_array($user['role'] ?? '', ['admin', 'super_admin']) || in_array($user_role, ['admin', 'super_admin'])) {
        die("Unauthorized access. Admins cannot create or edit quizzes because admin mode is preview only. Go back to <a href='classroom.php?course_id=" . urlencode($course_id) . "&admin_preview=1'>Classroom</a>.");
    }

    if (!$user || ($user['role'] ?? '') !== 'teacher') {
        die("Unauthorized access. Only instructors can manage quizzes. <a href='dashboard.php'>Dashboard</a>.");
    }

    // Fetch course details
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $course = $stmt->fetch();

    if (!$course) {
        die("Course not found. Go back to <a href='dashboard.php'>Dashboard</a>.");
    }

    if ((int)$course['tutor_id'] !== $user_id) {
        // Allow if tutor_id is NULL (course not yet assigned) and user is a teacher who can manage
        if (!($user['role'] === 'teacher' && empty($course['tutor_id']))) {
            die("You do not have permission to manage quizzes for this course.");
        }
    }

    // Fetch global quiz settings for this course
    // Fetch global quiz settings for this course
    $stmt = $pdo->prepare("SELECT * FROM course_quiz_settings WHERE course_id = ?");
    $stmt->execute([$course_id]);
    $settings = $stmt->fetch() ?: ['max_attempts' => 3, 'pass_percentage' => 50];

    // Fetch all lessons for this course
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$course_id]);
    $course_lessons = $stmt->fetchAll();

    // Default to the first lesson if lesson_id is not specified
    if (empty($lesson_id) && !empty($course_lessons)) {
        $lesson_id = $course_lessons[0]['id'];
    }

    // Fetch target lesson details
    $target_lesson = null;
    foreach ($course_lessons as $cl) {
        if ($cl['id'] === $lesson_id) {
            $target_lesson = $cl;
            break;
        }
    }

    // Fetch quiz count for each lesson in this course
    $stmt = $pdo->prepare("SELECT lesson_id, COUNT(*) as q_count FROM quizzes WHERE course_id = ? GROUP BY lesson_id");
    $stmt->execute([$course_id]);
    $lesson_q_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch existing quiz questions for this lesson
    if (!empty($lesson_id)) {
        $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? AND lesson_id = ? ORDER BY id ASC");
        $stmt->execute([$course_id, $lesson_id]);
        $existing_quizzes = $stmt->fetchAll();
    } else {
        $existing_quizzes = [];
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('create_quiz_title', 'Quiz Management System'); ?> - <?php echo htmlspecialchars($course['title']); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo function_exists('get_site_favicon') ? get_site_favicon() : 'assets/logo.png'; ?>?v=<?php echo time(); ?>">

    <!-- Google Fonts & Bootstrap 5 & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/notifications.css">

    <?php render_i18n_js(); ?>

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #212529; min-height: 100vh; }
        .page-card { border: none; border-radius: 16px; box-shadow: 0 8px 24px rgba(15,76,129,0.06); background: #ffffff; }
        .question-card { border: 1px solid #e9ecef; border-radius: 14px; background: #ffffff; transition: all 0.2s ease; }
        .question-card:hover { border-color: #74c0fc; box-shadow: 0 4px 16px rgba(15,76,129,0.08); }
        .question-card-header { background: #f8f9fa; border-bottom: 1px solid #e9ecef; padding: 1rem 1.5rem; border-radius: 14px 14px 0 0; }
        .img-preview { max-height: 140px; border-radius: 8px; border: 1px solid #dee2e6; object-fit: contain; }
    </style>
</head>
<body>

    <!-- Unified LMS Top Header Bar -->
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="bg-white border-bottom py-2 shadow-xs">
        <div class="container px-4 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <a href="classroom.php?course_id=<?php echo urlencode($course_id); ?>&sid=<?php echo urlencode(session_id()); ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1">
                    <i class="bi bi-arrow-left me-1"></i> <?php echo __('back', 'Back to Classroom'); ?>
                </a>
            </div>
            <div class="fs-8 text-muted">
                <i class="bi bi-gear-wide-connected me-1 text-primary"></i>Quiz Management Console
            </div>
        </div>
    </div>

    <div class="container py-4 px-3 px-md-4" style="max-width: 960px;">

        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1.5 rounded-pill fs-8 fw-semibold">
                        <?php echo htmlspecialchars($course['title']); ?>
                    </span>
                    <?php if (!empty($target_lesson)): ?>
                        <span class="badge bg-warning text-dark border border-warning px-3 py-1.5 rounded-pill fs-8 fw-bold">
                            <i class="bi bi-journal-bookmark-fill me-1 text-primary"></i>Lesson: <?php echo htmlspecialchars($target_lesson['title']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h2 class="fw-bold text-dark mb-1 fs-3">
                    <i class="bi bi-patch-question-fill text-primary me-2"></i><?php echo __('create_quiz_title', 'Quiz Management System'); ?>
                </h2>
                <p class="text-muted fs-7 mb-0">
                    <?php echo __('create_quiz_subtitle', 'Design custom quizzes, add MCQ or Text questions, upload images, set per-question timers, and set attempt limits.'); ?>
                </p>
            </div>

            <button type="button" onclick="saveQuizForm()" id="main-save-btn" class="btn btn-primary btn-lg px-4 py-2.5 border-0 shadow-sm fw-bold" style="background-color: #0f4c81;">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo __('save_quiz_btn', 'Save Quiz & Publish'); ?>
            </button>
        </div>

        <!-- Lesson Quiz Selector Bar -->
        <?php if (!empty($course_lessons)): ?>
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="fs-8 fw-bold text-uppercase text-muted tracking-wider">
                        <i class="bi bi-collection-play me-1 text-primary"></i> Manage Quizzes for Lesson:
                    </span>
                    <?php if (!empty($target_lesson)): ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 fs-9 fw-bold">
                            Current: <?php echo htmlspecialchars($target_lesson['title']); ?> (<?php echo count($existing_quizzes); ?> Questions)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($course_lessons as $idx => $cl): ?>
                        <?php 
                            $is_sel = ($cl['id'] === $lesson_id);
                            $q_count = (int)($lesson_q_counts[$cl['id']] ?? 0);
                        ?>
                        <a href="create_quiz.php?course_id=<?php echo urlencode($course_id); ?>&lesson_id=<?php echo urlencode($cl['id']); ?>&sid=<?php echo urlencode(session_id()); ?>"
                            class="btn btn-sm rounded-pill px-3 py-1.5 fs-8 fw-semibold text-nowrap <?php echo $is_sel ? 'btn-primary text-white shadow-sm' : 'btn-outline-secondary border-secondary border-opacity-25'; ?>"
                            style="<?php echo $is_sel ? 'background-color: #0f4c81; border: none;' : ''; ?>">
                            <i class="bi <?php echo $is_sel ? 'bi-pencil-square' : 'bi-journal-text'; ?> me-1"></i>
                            Lesson <?php echo $idx + 1; ?> (<?php echo $q_count; ?> <?php echo $q_count === 1 ? 'Question' : 'Questions'; ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form id="quiz-builder-form" enctype="multipart/form-data">
            <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id); ?>">
            <input type="hidden" name="lesson_id" value="<?php echo htmlspecialchars($lesson_id); ?>">

            <!-- REQUIREMENT 2: Global Quiz Level Settings Card -->
            <div class="page-card p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3 border-bottom pb-2.5">
                    <i class="bi bi-sliders me-2 text-primary"></i><?php echo __('global_quiz_settings', 'Global Quiz Settings'); ?>
                </h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="max_attempts" class="form-label fw-semibold text-secondary fs-7">
                            <?php echo __('max_attempts_setting', 'Max Attempts Allowed'); ?>
                        </label>
                        <select class="form-select bg-light border" id="max_attempts" name="max_attempts">
                            <option value="1" <?php echo intval($settings['max_attempts']) === 1 ? 'selected' : ''; ?>>1 Attempt Only</option>
                            <option value="2" <?php echo intval($settings['max_attempts']) === 2 ? 'selected' : ''; ?>>2 Total Attempts</option>
                            <option value="3" <?php echo intval($settings['max_attempts']) === 3 ? 'selected' : ''; ?>>3 Maximum Attempts (Standard)</option>
                        </select>
                        <small class="text-muted fs-8">Students can attempt up to this limit before score finalization.</small>
                    </div>
                    <div class="col-md-6">
                        <label for="pass_percentage" class="form-label fw-semibold text-secondary fs-7">
                            <?php echo __('pass_percentage', 'Pass Percentage (%)'); ?>
                        </label>
                        <input type="number" class="form-control bg-light border" id="pass_percentage" name="pass_percentage" min="1" max="100" value="<?php echo htmlspecialchars($settings['pass_percentage']); ?>">
                        <small class="text-muted fs-8">Minimum score ratio required to pass the module.</small>
                    </div>
                </div>
            </div>

            <!-- REQUIREMENT 3: Dynamic Question Builder Container -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-list-task me-2 text-primary"></i>Quiz Questions List
                    </h5>
                    <button type="button" onclick="addNewQuestionCard()" class="btn btn-outline-primary fw-bold btn-sm px-3 py-2">
                        <i class="bi bi-plus-lg me-1"></i><?php echo __('add_question_btn', '+ Add Question'); ?>
                    </button>
                </div>

                <div id="questions-container" class="d-flex flex-column gap-4">
                    <!-- Loaded dynamically via JS -->
                </div>
            </div>

            <!-- Bottom Floating Save Bar -->
            <div class="d-flex justify-content-between align-items-center page-card p-3 mb-5">
                <button type="button" onclick="addNewQuestionCard()" class="btn btn-outline-primary fw-bold btn-sm px-3">
                    <i class="bi bi-plus-lg me-1"></i><?php echo __('add_question_btn', '+ Add Question'); ?>
                </button>
                <button type="button" onclick="saveQuizForm()" class="btn btn-primary px-4 py-2 border-0 fw-bold" style="background-color: #0f4c81;">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo __('save_quiz_btn', 'Save Quiz & Publish'); ?>
                </button>
            </div>
        </form>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const INITIAL_QUESTIONS = <?php echo json_encode($existing_quizzes); ?>;
        let questionCount = 0;

        document.addEventListener('DOMContentLoaded', () => {
            if (INITIAL_QUESTIONS && INITIAL_QUESTIONS.length > 0) {
                INITIAL_QUESTIONS.forEach(q => {
                    addNewQuestionCard(q);
                });
            } else {
                // Add an initial empty question card
                addNewQuestionCard();
            }
        });

        function addNewQuestionCard(data = null) {
            questionCount++;
            const container = document.getElementById('questions-container');
            const qIdx = questionCount;

            const qId = data ? data.question_id : 'q-' + Date.now() + '-' + Math.floor(Math.random()*1000);
            const qType = data ? (data.question_type || 'mcq') : 'mcq';
            const qText = data ? (data.question || '') : '';
            const timerSec = data ? (data.time_limit_seconds || 30) : 30;
            const imagePath = data ? (data.image_path || '') : '';
            const explanation = data ? (data.explanation || '') : '';

            const opt1 = data ? (data.option_1 || '') : '';
            const opt2 = data ? (data.option_2 || '') : '';
            const opt3 = data ? (data.option_3 || '') : '';
            const opt4 = data ? (data.option_4 || '') : '';
            const answerIdx = data ? (parseInt(data.answer_index) >= 0 ? parseInt(data.answer_index) : 0) : 0;
            const correctAnsText = data ? (data.correct_answer || '') : '';

            const card = document.createElement('div');
            card.className = 'question-card';
            card.id = `q-card-${qIdx}`;
            card.setAttribute('data-question-id', qId);

            card.innerHTML = `
                <div class="question-card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">${qIdx}</span>
                        <h6 class="fw-bold text-dark mb-0">Question #${qIdx}</h6>
                    </div>
                    <button type="button" onclick="removeQuestionCard(${qIdx})" class="btn btn-outline-danger btn-sm border-0" title="Delete Question">
                        <i class="bi bi-trash-fill me-1"></i> ${window.i18n__ ? window.i18n__('delete_question', 'Delete') : 'Delete'}
                    </button>
                </div>
                <div class="p-4">
                    <div class="row g-3 mb-3">
                        <!-- Question Type Selector -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary fs-7">
                                ${window.i18n__ ? window.i18n__('question_type', 'Question Type') : 'Question Type'}
                            </label>
                            <select class="form-select bg-light border q-type-select" onchange="toggleQuestionTypeFields(${qIdx})">
                                <option value="mcq" ${qType === 'mcq' ? 'selected' : ''}>
                                    ${window.i18n__ ? window.i18n__('mcq_type', 'Multiple Choice (MCQ)') : 'Multiple Choice (MCQ)'}
                                </option>
                                <option value="text" ${qType === 'text' ? 'selected' : ''}>
                                    ${window.i18n__ ? window.i18n__('text_type', 'Text Input (Type Answer)') : 'Text Input (Type Answer)'}
                                </option>
                            </select>
                        </div>

                        <!-- Custom Timer Per Question -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary fs-7">
                                <i class="bi bi-clock-history me-1 text-primary"></i>${window.i18n__ ? window.i18n__('timer_sec', 'Timer (Seconds)') : 'Timer (Seconds)'}
                            </label>
                            <input type="number" class="form-control bg-light border q-timer-input" min="5" max="600" value="${timerSec}" placeholder="30">
                        </div>
                    </div>

                    <!-- Question Text / Description -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary fs-7">
                            ${window.i18n__ ? window.i18n__('question_text', 'Question Text / Description') : 'Question Text / Description'}
                        </label>
                        <textarea class="form-control bg-light border q-text-input" rows="2" placeholder="e.g. What is the time complexity of binary search?" required>${escapeHtml(qText)}</textarea>
                    </div>

                    <!-- Optional Image Upload Attachment -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-secondary fs-7">
                            <i class="bi bi-image me-1 text-primary"></i>${window.i18n__ ? window.i18n__('upload_image', 'Attach Image (Optional)') : 'Attach Image (Optional)'}
                        </label>
                        <input type="file" class="form-control bg-light border q-image-file" accept="image/*" onchange="previewQuestionImage(this, ${qIdx})">
                        <div class="mt-2 ${imagePath ? '' : 'd-none'}" id="preview-box-${qIdx}">
                            <img src="${imagePath}" class="img-preview" id="preview-img-${qIdx}" alt="Question Image">
                        </div>
                    </div>

                    <!-- Dynamic Answer Section MCQ vs Text Input -->
                    <div id="mcq-section-${qIdx}" class="${qType === 'mcq' ? '' : 'd-none'} p-3 rounded bg-light border mb-3">
                        <label class="form-label fw-semibold text-dark fs-7 mb-2">
                            ${window.i18n__ ? window.i18n__('correct_option', 'Multiple Choice Options & Correct Option') : 'Multiple Choice Options & Correct Option'}
                        </label>
                        
                        <div class="input-group mb-2">
                            <div class="input-group-text bg-white">
                                <input class="form-check-input mt-0 q-mcq-radio" type="radio" name="correct_radio_${qIdx}" value="0" ${answerIdx === 0 ? 'checked' : ''}>
                                <span class="ms-2 fw-bold">A</span>
                            </div>
                            <input type="text" class="form-control q-opt-1" placeholder="Option A text" value="${escapeHtml(opt1)}">
                        </div>

                        <div class="input-group mb-2">
                            <div class="input-group-text bg-white">
                                <input class="form-check-input mt-0 q-mcq-radio" type="radio" name="correct_radio_${qIdx}" value="1" ${answerIdx === 1 ? 'checked' : ''}>
                                <span class="ms-2 fw-bold">B</span>
                            </div>
                            <input type="text" class="form-control q-opt-2" placeholder="Option B text" value="${escapeHtml(opt2)}">
                        </div>

                        <div class="input-group mb-2">
                            <div class="input-group-text bg-white">
                                <input class="form-check-input mt-0 q-mcq-radio" type="radio" name="correct_radio_${qIdx}" value="2" ${answerIdx === 2 ? 'checked' : ''}>
                                <span class="ms-2 fw-bold">C</span>
                            </div>
                            <input type="text" class="form-control q-opt-3" placeholder="Option C text" value="${escapeHtml(opt3)}">
                        </div>

                        <div class="input-group mb-2">
                            <div class="input-group-text bg-white">
                                <input class="form-check-input mt-0 q-mcq-radio" type="radio" name="correct_radio_${qIdx}" value="3" ${answerIdx === 3 ? 'checked' : ''}>
                                <span class="ms-2 fw-bold">D</span>
                            </div>
                            <input type="text" class="form-control q-opt-4" placeholder="Option D text" value="${escapeHtml(opt4)}">
                        </div>
                    </div>

                    <div id="text-section-${qIdx}" class="${qType === 'text' ? '' : 'd-none'} p-3 rounded bg-light border mb-3">
                        <label class="form-label fw-semibold text-dark fs-7 mb-2">
                            ${window.i18n__ ? window.i18n__('exact_answer_keyword', 'Exact Correct Keyword / Answer Pattern') : 'Exact Correct Keyword / Answer Pattern'}
                        </label>
                        <input type="text" class="form-control bg-white q-text-answer" placeholder="e.g. O(log n)" value="${escapeHtml(correctAnsText)}">
                        <small class="text-muted fs-8">Automated grading will compare the student's typed answer with this keyword pattern (case-insensitive).</small>
                    </div>

                    <!-- Explanation -->
                    <div>
                        <label class="form-label fw-semibold text-secondary fs-7">
                            <i class="bi bi-lightbulb me-1 text-warning"></i>${window.i18n__ ? window.i18n__('explanation', 'Explanation') : 'Explanation'}
                        </label>
                        <textarea class="form-control bg-light border q-explanation-input" rows="2" placeholder="Provide background concept explanation for student review.">${escapeHtml(explanation)}</textarea>
                    </div>
                </div>
            `;

            container.appendChild(card);
            renumberQuestionCards();
        }

        function toggleQuestionTypeFields(qIdx) {
            const card = document.getElementById(`q-card-${qIdx}`);
            if (!card) return;

            const qType = card.querySelector('.q-type-select').value;
            const mcqSec = document.getElementById(`mcq-section-${qIdx}`);
            const textSec = document.getElementById(`text-section-${qIdx}`);

            if (qType === 'mcq') {
                mcqSec.classList.remove('d-none');
                textSec.classList.add('d-none');
            } else {
                mcqSec.classList.add('d-none');
                textSec.classList.remove('d-none');
            }
        }

        function removeQuestionCard(qIdx) {
            const card = document.getElementById(`q-card-${qIdx}`);
            if (card) {
                card.remove();
                renumberQuestionCards();
            }
        }

        function renumberQuestionCards() {
            const cards = document.querySelectorAll('.question-card');
            cards.forEach((c, idx) => {
                const headerBadge = c.querySelector('.question-card-header .badge');
                const headerTitle = c.querySelector('.question-card-header h6');
                if (headerBadge) headerBadge.innerText = idx + 1;
                if (headerTitle) headerTitle.innerText = `Question #${idx + 1}`;
            });
        }

        function previewQuestionImage(input, qIdx) {
            const previewBox = document.getElementById(`preview-box-${qIdx}`);
            const previewImg = document.getElementById(`preview-img-${qIdx}`);

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewBox.classList.remove('d-none');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function saveQuizForm() {
            const saveBtn = document.getElementById('main-save-btn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Saving...`;

            const formData = new FormData();
            formData.append('course_id', document.querySelector('input[name="course_id"]').value);
            formData.append('lesson_id', document.querySelector('input[name="lesson_id"]').value);
            formData.append('max_attempts', document.getElementById('max_attempts').value);
            formData.append('pass_percentage', document.getElementById('pass_percentage').value);

            const questions = [];
            const cards = document.querySelectorAll('.question-card');

            cards.forEach((c, idx) => {
                const qId = c.getAttribute('data-question-id');
                const qType = c.querySelector('.q-type-select').value;
                const timerSec = c.querySelector('.q-timer-input').value;
                const qText = c.querySelector('.q-text-input').value;
                const explanation = c.querySelector('.q-explanation-input').value;

                let radioVal = 0;
                const checkedRadio = c.querySelector(`.q-mcq-radio:checked`);
                if (checkedRadio) radioVal = parseInt(checkedRadio.value);

                const qObj = {
                    question_id: qId,
                    question_type: qType,
                    time_limit_seconds: parseInt(timerSec) || 30,
                    question_text: qText,
                    explanation: explanation,
                    option_1: c.querySelector('.q-opt-1').value,
                    option_2: c.querySelector('.q-opt-2').value,
                    option_3: c.querySelector('.q-opt-3').value,
                    option_4: c.querySelector('.q-opt-4').value,
                    answer_index: radioVal,
                    correct_answer: qType === 'text' ? c.querySelector('.q-text-answer').value : String(radioVal)
                };

                questions.push(qObj);

                // Append image file if selected
                const fileInput = c.querySelector('.q-image-file');
                if (fileInput && fileInput.files && fileInput.files[0]) {
                    formData.append(`question_image_${idx}`, fileInput.files[0]);
                }
            });

            formData.append('questions_json', JSON.stringify(questions));

            try {
                const response = await fetch('api/save_quiz.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    alert(data.message || 'Quiz saved and published successfully!');
                    location.href = `classroom.php?course_id=${encodeURIComponent(document.querySelector('input[name="course_id"]').value)}&sid=<?php echo urlencode(session_id()); ?>`;
                } else {
                    alert('Error saving quiz: ' + data.message);
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>Save Quiz & Publish`;
                }
            } catch (err) {
                console.error(err);
                alert('Connection error while saving quiz.');
                saveBtn.disabled = false;
                saveBtn.innerHTML = `<i class="bi bi-check-circle-fill me-2"></i>Save Quiz & Publish`;
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
    <!-- Modern Notification System JS Client -->
    <script src="assets/js/notifications.js"></script>
</body>
</html>
