<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 75;
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$u = $stmt->fetch();

if ($u) {
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_name'] = $u['name'];
    $_SESSION['user_email'] = $u['email'];
    $_SESSION['user_role'] = $u['role'];
    $_SESSION['user_avatar'] = get_user_avatar($u['avatar'], $u['name']);
    $_SESSION['academic_id'] = $u['academic_id'];
    if (in_array($u['role'], ['admin', 'super_admin'])) {
        setcookie('LMS_ADMIN_SESS', session_id(), 0, '/');
    }
}

$redirect = $_GET['redirect'] ?? 'teacher_assignments.php';
header("Location: ../" . $redirect);
exit;
