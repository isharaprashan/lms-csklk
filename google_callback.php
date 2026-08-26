<?php
require_once __DIR__ . '/config/google_oauth.php';
init_lms_session();

// 1. Handle error response from Google
if (isset($_GET['error'])) {
    $err = $_GET['error'];
    $desc = $_GET['error_description'] ?? 'Google authentication was cancelled or encountered an error.';
    $_SESSION['auth_error'] = 'Google Sign-In failed: ' . htmlspecialchars($desc);
    header("Location: login.php");
    exit;
}

$code = $_GET['code'] ?? '';
$stateParam = $_GET['state'] ?? '';

if (empty($code) || empty($stateParam)) {
    $_SESSION['auth_error'] = 'Invalid Google OAuth response parameters.';
    header("Location: login.php");
    exit;
}

// 2. Validate CSRF State Token
$stateData = null;
try {
    $decoded = base64_decode(urldecode($stateParam));
    if ($decoded) {
        $stateData = json_decode($decoded, true);
    }
} catch (Exception $e) {
}

$expectedCsrf = $_SESSION['google_oauth_csrf'] ?? '';
if (!$stateData || empty($stateData['csrf']) || $stateData['csrf'] !== $expectedCsrf) {
    $_SESSION['auth_error'] = 'Google OAuth security validation (CSRF state) failed. Please try again.';
    header("Location: login.php");
    exit;
}

// Clear CSRF state once validated
unset($_SESSION['google_oauth_csrf']);

$targetRole = $stateData['role'] ?? 'student';
$targetRedirect = $stateData['redirect'] ?? '';

// 3. Exchange Code for Access Token
$tokenResult = exchange_google_code_for_token($code);
if (!$tokenResult['success']) {
    $_SESSION['auth_error'] = 'Google authentication failed: ' . ($tokenResult['error'] ?? 'Could not verify token.');
    header("Location: login.php");
    exit;
}

$accessToken = $tokenResult['data']['access_token'] ?? '';
if (empty($accessToken)) {
    $_SESSION['auth_error'] = 'Failed to acquire access token from Google.';
    header("Location: login.php");
    exit;
}

// 4. Retrieve Google User Profile
$profileResult = get_google_user_profile($accessToken);
if (!$profileResult['success']) {
    $_SESSION['auth_error'] = 'Failed to retrieve user profile from Google: ' . ($profileResult['error'] ?? 'Unknown error');
    header("Location: login.php");
    exit;
}

$googleProfile = $profileResult['data'];
$googleId = $googleProfile['sub'];
$googleEmail = strtolower(trim($googleProfile['email']));
$googleName = trim($googleProfile['name'] ?: ($googleProfile['given_name'] . ' ' . $googleProfile['family_name']));
$googlePicture = $googleProfile['picture'] ?? null;

if (empty($googleEmail)) {
    $_SESSION['auth_error'] = 'Unable to obtain verified email address from your Google Account.';
    header("Location: login.php");
    exit;
}

try {
    $pdo = getDBConnection();

    // 5. Look up existing user by google_id or email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
    $stmt->execute([$googleId, $googleEmail]);
    $user = $stmt->fetch();

    if ($user) {
        // Check if account is inactive
        if (isset($user['status']) && $user['status'] === 'inactive') {
            $_SESSION['auth_error'] = function_exists('__') ? __('account_inactive_error', 'Your account has been deactivated by system administrators. Access is disabled.') : 'Your account has been deactivated by system administrators. Access is disabled.';
            header("Location: login.php?error=account_inactive");
            exit;
        }

        // User exists -> Update Google credentials & mark verified
        $updateSql = "UPDATE users SET google_id = ?, auth_provider = 'google', email_verified = 1";
        $updateParams = [$googleId];

        if (!empty($googlePicture) && (empty($user['avatar']) || strpos($user['avatar'], 'ui-avatars.com') !== false)) {
            $updateSql .= ", avatar = ?";
            $updateParams[] = $googlePicture;
        }

        $updateSql .= " WHERE id = ?";
        $updateParams[] = $user['id'];

        $upStmt = $pdo->prepare($updateSql);
        $upStmt->execute($updateParams);

        // Fetch refreshed user record
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user = $stmt->fetch();
    } else {
        // New user -> Auto-register with Google profile
        $role = in_array($targetRole, ['student', 'teacher']) ? $targetRole : 'student';
        $status = ($role === 'teacher') ? 'pending' : 'active';
        $academicId = ($role === 'teacher' ? 'TCHR-' : 'ACAD-') . rand(100000, 999999);
        $dummyPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $avatar = !empty($googlePicture) ? $googlePicture : get_user_avatar(null, $googleName);

        $insStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, avatar, academic_id, role, status, google_id, auth_provider, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'google', 1)");
        $insStmt->execute([
            $googleName,
            $googleEmail,
            $dummyPasswordHash,
            $avatar,
            $academicId,
            $role,
            $status,
            $googleId
        ]);

        $newUserId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$newUserId]);
        $user = $stmt->fetch();
    }

    // 6. Establish LMS Session
    session_regenerate_id(true);
    $newSid = session_id();

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_avatar'] = get_user_avatar($user['avatar'], $user['name']);
    $_SESSION['academic_id'] = $user['academic_id'];
    $_SESSION['user_role'] = $user['role'] ?? 'student';
    $_SESSION['sid'] = $newSid;

    // Admin portal session setup
    if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin') {
        $admin_uid = $_SESSION['user_id'];
        $admin_name = $_SESSION['user_name'];
        $admin_email = $_SESSION['user_email'];
        $admin_avatar = $_SESSION['user_avatar'];
        $admin_academic_id = $_SESSION['academic_id'];
        $admin_role = $_SESSION['user_role'];

        session_write_close();
        session_name('LMS_ADMIN_SESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
        session_start();
        $_SESSION['user_id'] = $admin_uid;
        $_SESSION['user_name'] = $admin_name;
        $_SESSION['user_email'] = $admin_email;
        $_SESSION['user_avatar'] = $admin_avatar;
        $_SESSION['academic_id'] = $admin_academic_id;
        $_SESSION['user_role'] = $admin_role;

        header("Location: admin/index.php");
        exit;
    }

    // Redirect to requested redirect or standard dashboard
    if (!empty($targetRedirect) && !preg_match('~^(https?:)?//~i', $targetRedirect)) {
        header("Location: " . $targetRedirect . (str_contains($targetRedirect, '?') ? '&' : '?') . "sid=" . urlencode($newSid));
    } else {
        header("Location: dashboard.php?sid=" . urlencode($newSid));
    }
    exit;

} catch (PDOException $e) {
    $_SESSION['auth_error'] = 'Database error during Google login: ' . $e->getMessage();
    header("Location: login.php");
    exit;
}
