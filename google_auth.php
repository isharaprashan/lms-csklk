<?php
require_once __DIR__ . '/config/google_oauth.php';
init_lms_session();

if (!is_google_oauth_enabled()) {
    $_SESSION['auth_error'] = 'Google Sign-In is currently disabled.';
    header("Location: login.php");
    exit;
}

$clientId = get_google_client_id();
if (empty($clientId)) {
    $_SESSION['auth_error'] = 'Google Sign-In is not yet configured. Please configure Google Client ID in the Admin Panel or contact support.';
    $referrer = $_SERVER['HTTP_REFERER'] ?? 'login.php';
    header("Location: " . (str_contains($referrer, 'register.php') ? 'register.php' : 'login.php'));
    exit;
}

$role = $_GET['role'] ?? 'student';
$redirect = $_GET['redirect'] ?? '';

$authUrl = get_google_auth_url($role, $redirect);
header("Location: " . $authUrl);
exit;
