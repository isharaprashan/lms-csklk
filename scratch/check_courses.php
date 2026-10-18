<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$courses = $pdo->query("SELECT id, title, tutor_id FROM courses")->fetchAll(PDO::FETCH_ASSOC);
print_r($courses);
