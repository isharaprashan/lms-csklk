<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();
$stmt = $pdo->exec("DELETE FROM courses WHERE id LIKE 'test-course-case-%'");
echo "Cleaned up test courses. Rows affected: {$stmt}\n";
