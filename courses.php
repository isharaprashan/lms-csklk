<?php
// Course Catalog Route
require_once __DIR__ . '/db/db_connect.php';
init_lms_session();

// Redirect to index.php course catalog section
header("Location: index.php#courses-section");
exit;
