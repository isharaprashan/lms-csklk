<?php
// Student Lesson View Entrypoint / Route
require_once __DIR__ . '/db/db_connect.php';
require_once __DIR__ . '/lang/i18n.php';
init_lms_session();

// Protection
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$course_id = $_GET['course_id'] ?? '';
$lesson_id = $_GET['lesson_id'] ?? '';

$params = [];
if (!empty($course_id)) {
    $params['course_id'] = $course_id;
}
if (!empty($lesson_id)) {
    $params['lesson_id'] = $lesson_id;
}
if (isset($_GET['locked_warning'])) {
    $params['locked_warning'] = 1;
}
if (isset($_GET['admin_preview'])) {
    $params['admin_preview'] = 1;
}

$queryString = !empty($params) ? '?' . http_build_query($params) : '';
header("Location: watch_lesson.php" . $queryString);
exit;
