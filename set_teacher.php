<?php
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();
$_SESSION['user_id'] = 42;
$_SESSION['user_role'] = 'teacher';
$_SESSION['user_name'] = 'Nilaksha Nuwan';
$_SESSION['user_email'] = 'nilaksha@gmail.com';
header("Location: dashboard.php");
exit;
