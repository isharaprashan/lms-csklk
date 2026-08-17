<?php
// Central Tab-Isolated Session Initialization for LMS
require_once __DIR__ . '/../lang/i18n.php';

function init_lms_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Support Admin Privilege Preview Mode across all LMS pages
    if (isset($_COOKIE['LMS_ADMIN_SESS']) || isset($_GET['admin_preview'])) {
        session_name('LMS_ADMIN_SESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
        session_start();
        if (isset($_SESSION['user_id']) && in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
            return;
        }
        // Fallback if admin session not valid
        session_write_close();
    }

    $sid = $_GET['sid'] ?? $_POST['sid'] ?? $_SERVER['HTTP_X_SESSION_ID'] ?? ($_COOKIE['LMS_TAB_SID'] ?? null);

    if ($sid && preg_match('/^[a-zA-Z0-9,-]{8,128}$/', $sid)) {
        session_id($sid);
    }

    session_start();
}

function get_lms_sid() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        init_lms_session();
    }
    return session_id();
}
