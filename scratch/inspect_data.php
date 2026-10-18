<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

echo "--- Lessons Sample ---\n";
$stmt = $pdo->query("SELECT id, course_id, title, duration FROM lessons LIMIT 5");
print_r($stmt->fetchAll());

echo "--- Completed Lessons Sample ---\n";
$stmt = $pdo->query("SELECT * FROM completed_lessons LIMIT 5");
print_r($stmt->fetchAll());

echo "--- Quizzes Sample ---\n";
$stmt = $pdo->query("SELECT id, question_id, course_id, lesson_id, question FROM quizzes LIMIT 5");
print_r($stmt->fetchAll());

echo "--- Assignments Sample ---\n";
$stmt = $pdo->query("SELECT id, lesson_id, course_id, title FROM lesson_assignments");
print_r($stmt->fetchAll());

echo "--- Enrollments in archaeology-ba-bsc ---\n";
$stmt = $pdo->query("SELECT user_id, course_id FROM enrollments WHERE course_id = 'archaeology-ba-bsc'");
print_r($stmt->fetchAll());


