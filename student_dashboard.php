<?php
// Student Dashboard Route / Alias
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

header("Location: dashboard.php");
exit;
