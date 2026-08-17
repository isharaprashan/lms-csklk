<?php
session_name('LMS_ADMIN_SESS');
session_start();

// Unset all session variables
$_SESSION = array();

// Destroy session cookies if active
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to admin login
header("Location: login.php");
exit;
