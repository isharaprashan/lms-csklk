<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

$stmt = $pdo->query("SELECT course_id, lesson_id, count(*) as cnt FROM quizzes GROUP BY course_id, lesson_id");
print_r($stmt->fetchAll());
