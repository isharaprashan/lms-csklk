<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../config/mail.php';
init_lms_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($input['email'] ?? ($_POST['email'] ?? ($_SESSION['pending_otp_email'] ?? '')));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

// Enforce 60-second cooldown
$lastResendTime = $_SESSION['last_otp_resend_' . md5($email)] ?? 0;
$currentTime = time();
$cooldownSeconds = 60;
$timePassed = $currentTime - $lastResendTime;

if ($lastResendTime > 0 && $timePassed < $cooldownSeconds) {
    $remaining = $cooldownSeconds - $timePassed;
    echo json_encode([
        'success' => false,
        'message' => "Please wait {$remaining} seconds before requesting a new code.",
        'cooldown_remaining' => $remaining
    ]);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, name, email, email_verified FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email address.']);
        exit;
    }

    if ($user['email_verified'] == 1) {
        echo json_encode(['success' => false, 'message' => 'This account email is already verified. You can sign in directly.', 'already_verified' => true]);
        exit;
    }

    // Generate random 6-digit OTP and 10-minute expiry
    $newOtp = sprintf('%06d', random_int(100000, 999999));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $upStmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
    $upStmt->execute([$newOtp, $expiresAt, $user['id']]);

    // Dispatch email
    $mailResult = send_otp_email($user['email'], $user['name'], $newOtp);

    // Save cooldown timestamp
    $_SESSION['last_otp_resend_' . md5($email)] = time();
    $_SESSION['pending_otp_email'] = $email;
    $_SESSION['pending_otp_user_id'] = $user['id'];

    echo json_encode([
        'success' => true,
        'message' => 'A new 6-digit verification code has been dispatched to ' . htmlspecialchars($email) . '.',
        'cooldown' => $cooldownSeconds
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
