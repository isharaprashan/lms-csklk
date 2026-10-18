<?php
require_once __DIR__ . '/../db/db_connect.php';
$pdo = getDBConnection();

// Set session as teacher user 75
$_SESSION['user_id'] = 75;
$_SESSION['user_role'] = 'teacher';
$_SESSION['user_name'] = 'Abilash Rajendra';
$_SESSION['user_email'] = 'abilash@example.com';

ob_start();
include __DIR__ . '/../teacher_assignments.php';
$html = ob_get_clean();

file_put_contents(__DIR__ . '/rendered_page.html', $html);
echo "Wrote " . strlen($html) . " bytes to rendered_page.html\n";
