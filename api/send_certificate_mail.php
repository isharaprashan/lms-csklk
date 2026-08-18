<?php
// Central Certificate Email Dispatch API with FPDF & Database-driven PHPMailer
if (session_status() === PHP_SESSION_NONE) {
    session_name('LMS_ADMIN_SESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/']);
    session_start();
}

require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

init_lms_language();

// Session recovery check
if (!isset($_SESSION['user_id'])) {
    $sid = $_GET['sid'] ?? $_POST['sid'] ?? ($_COOKIE['PHPSESSID'] ?? null);
    if ($sid) {
        session_write_close();
        session_name('PHPSESSID');
        if ($sid !== ($_COOKIE['PHPSESSID'] ?? null)) {
            session_id($sid);
        }
        session_start();
    }
}

header('Content-Type: application/json; charset=utf-8');

$pdo = getDBConnection();

// 1. Guard for Administrator / Super Admin Authorization
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;

if ($user_id) {
    try {
        $stmtRole = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ?");
        $stmtRole->execute([$user_id]);
        $dbUser = $stmtRole->fetch(PDO::FETCH_ASSOC);
        if ($dbUser && in_array($dbUser['role'], ['admin', 'super_admin'])) {
            $role = $dbUser['role'];
        }
    } catch (Exception $e) {}
}

if (!$user_id || !in_array($role, ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('unauthorized_access', 'Unauthorized access. Administrator privileges required.')
    ]);
    exit;
}

// 2. Parse Request ID from JSON or POST
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$request_id = intval($jsonData['request_id'] ?? $_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => __('invalid_request_id', 'Invalid certificate request ID.')
    ]);
    exit;
}

try {
    $pdo = getDBConnection();

    // 3. Fetch Certificate Request and Student Information
    $stmt = $pdo->prepare("
        SELECT cr.*, u.email as user_email, u.name as user_account_name
        FROM certificate_requests cr
        LEFT JOIN users u ON cr.user_id = u.id
        WHERE cr.id = ?
    ");
    $stmt->execute([$request_id]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => __('cert_not_found', 'Certificate request record not found.')
        ]);
        exit;
    }

    $studentName = !empty($cert['full_name_on_certificate']) ? trim($cert['full_name_on_certificate']) : trim($cert['user_account_name'] ?? 'Student');
    $recipientEmail = !empty($cert['registered_email']) ? trim($cert['registered_email']) : trim($cert['user_email'] ?? '');
    $courseTitle = $cert['course_title'] ?? 'Course';
    $certCode = $cert['certificate_code'] ?? ('CERT-CSLK-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8)));
    $compDateStr = !empty($cert['completion_date']) ? date('F d, Y', strtotime($cert['completion_date'])) : date('F d, Y');
    $quizSummary = !empty($cert['quiz_score_summary']) ? $cert['quiz_score_summary'] : 'Progress: 100% | Verified';

    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => __('invalid_student_email', 'Invalid student email address: ') . htmlspecialchars($recipientEmail)
        ]);
        exit;
    }

    // 4. Generate Official Certificate PDF using FPDF
    require_once __DIR__ . '/../includes/fpdf/fpdf.php';

    $pdf = new FPDF('L', 'mm', 'A4'); // A4 Landscape (297mm x 210mm)
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $certImgPath = !empty($cert['certificate_image']) ? __DIR__ . '/../' . ltrim($cert['certificate_image'], '/') : null;

    if ($certImgPath && file_exists($certImgPath)) {
        // Embed the generated high-resolution certificate image across the A4 landscape canvas
        $pdf->Image($certImgPath, 0, 0, 297, 210);
    } else {
        // High-Quality Institutional Vector Fallback
        // Background tint
        $pdf->SetFillColor(254, 252, 248);
        $pdf->Rect(0, 0, 297, 210, 'F');

        // Outer Deep Navy Border (#0f4c81)
        $pdf->SetDrawColor(15, 76, 129);
        $pdf->SetLineWidth(3);
        $pdf->Rect(10, 10, 277, 190);

        // Inner Gold Border (#b8860b)
        $pdf->SetDrawColor(184, 134, 11);
        $pdf->SetLineWidth(1);
        $pdf->Rect(14, 14, 269, 182);

        // Academy Header
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->SetTextColor(15, 76, 129);
        $pdf->SetXY(0, 28);
        $pdf->Cell(297, 10, 'COMPUTERSCIENCE.LK', 0, 1, 'C');

        $pdf->SetFont('Arial', 'I', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(297, 6, 'Advanced Computer Science & IT Learning Academy', 0, 1, 'C');

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetTextColor(184, 134, 11);
        $pdf->Cell(297, 9, 'CERTIFICATE OF COMPLETION', 0, 1, 'C');

        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(297, 7, 'This credential is proudly awarded to', 0, 1, 'C');

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->SetTextColor(15, 76, 129);
        $pdf->Cell(297, 11, utf8_decode($studentName), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(297, 7, 'for successfully demonstrating academic mastery and completing all requirements for:', 0, 1, 'C');

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(184, 134, 11);
        $pdf->Cell(297, 9, utf8_decode($courseTitle), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(297, 7, "Completion Date: {$compDateStr}   |   {$quizSummary}", 0, 1, 'C');

        // Verification Footer
        $pdf->SetXY(20, 172);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(15, 76, 129);
        $pdf->Cell(100, 5, "Credential ID: {$certCode}", 0, 0, 'L');
        $pdf->SetXY(177, 172);
        $pdf->Cell(100, 5, "Verified Institution: computerscience.lk", 0, 0, 'R');
    }

    $pdfBinary = $pdf->Output('S');

    // 5. Retrieve Active SMTP Credentials from Database
    require_once __DIR__ . '/../config/mail.php';
    $smtp = get_smtp_settings($pdo);

    // 6. Build Branded Congratulatory HTML Email
    $verifyUrl = "https://computerscience.lk/verify?code=" . urlencode($certCode);
    $safeStudentNameHtml = htmlspecialchars($studentName);
    $safeCourseTitleHtml = htmlspecialchars($courseTitle);
    $safeCertCodeHtml = htmlspecialchars($certCode);
    $safeCompDateHtml = htmlspecialchars($compDateStr);

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Official Certificate</title>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f7fb; margin: 0; padding: 0; color: #1e293b; }
    .email-container { max-width: 600px; margin: 30px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 76, 129, 0.08); border: 1px solid #e2e8f0; }
    .email-header { background: linear-gradient(135deg, #0f4c81 0%, #1e3a8a 100%); padding: 36px 30px; text-align: center; color: #ffffff; }
    .header-logo { font-size: 26px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; margin: 0; color: #ffffff; }
    .header-sub { font-size: 12px; letter-spacing: 1px; color: rgba(255,255,255,0.85); text-transform: uppercase; margin-top: 6px; }
    .email-body { padding: 36px 32px; }
    .badge-congrats { display: inline-block; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 12px; padding: 6px 16px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
    .greeting { font-size: 22px; font-weight: 700; color: #0f4c81; margin: 0 0 12px; }
    .message { font-size: 15px; line-height: 1.6; color: #475569; margin: 0 0 24px; }
    .details-card { background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; margin-bottom: 28px; }
    .verify-btn { display: inline-block; background: #0f4c81; color: #ffffff !important; font-weight: 700; font-size: 15px; padding: 14px 32px; border-radius: 50px; text-decoration: none; text-align: center; box-shadow: 0 4px 12px rgba(15, 76, 129, 0.25); }
    .attachment-note { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 14px 16px; border-radius: 0 8px 8px 0; margin-top: 24px; font-size: 13px; color: #1e40af; }
    .email-footer { background: #f1f5f9; padding: 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
  </style>
</head>
<body>
  <div class="email-container">
    <div class="email-header">
      <div style="font-size: 38px; margin-bottom: 8px;">🎓</div>
      <h1 class="header-logo">Computerscience.lk</h1>
      <div class="header-sub">Advanced Computer Science & IT Learning Academy</div>
    </div>
    <div class="email-body">
      <div class="badge-congrats">🏆 Official Credential Issued</div>
      <h2 class="greeting">Dear {$safeStudentNameHtml},</h2>
      <p class="message">
        Congratulations on your outstanding achievement! We are delighted to inform you that you have successfully satisfied all assessment criteria and completed:
      </p>
      
      <div class="details-card">
        <table style="width: 100%; border-collapse: collapse;">
          <tr>
            <td style="padding: 6px 0; color: #64748b; font-weight: 600; font-size: 14px;">Course Name:</td>
            <td style="padding: 6px 0; color: #0f4c81; font-weight: 700; font-size: 14px; text-align: right;">{$safeCourseTitleHtml}</td>
          </tr>
          <tr>
            <td style="padding: 6px 0; color: #64748b; font-weight: 600; font-size: 14px;">Completion Date:</td>
            <td style="padding: 6px 0; color: #0f172a; font-weight: 700; font-size: 14px; text-align: right;">{$safeCompDateHtml}</td>
          </tr>
          <tr>
            <td style="padding: 6px 0; color: #64748b; font-weight: 600; font-size: 14px;">Credential ID:</td>
            <td style="padding: 6px 0; color: #b8860b; font-weight: 800; font-size: 14px; text-align: right; font-family: monospace;">{$safeCertCodeHtml}</td>
          </tr>
        </table>
      </div>

      <div style="text-align: center; margin: 28px 0;">
        <a href="{$verifyUrl}" target="_blank" class="verify-btn">
          Verify Certificate Online &rarr;
        </a>
      </div>

      <div class="attachment-note">
        <strong>📎 Official PDF Attached:</strong> Your high-resolution certified document (<code>{$safeStudentNameHtml}_Certificate.pdf</code>) is attached to this email and is suitable for high-quality printing or adding to your academic portfolio / LinkedIn.
      </div>
    </div>

    <div class="email-footer">
      &copy; 2026 Computerscience.lk Learning Academy. All rights reserved.<br>
      For verification and inquiries, visit <a href="https://computerscience.lk" style="color: #0f4c81; text-decoration: none;">computerscience.lk</a>.
    </div>
  </div>
</body>
</html>
HTML;

    $altBody = "Congratulations {$studentName}!\n\nYour official certificate for {$courseTitle} has been issued.\n\nCredential ID: {$certCode}\nCompletion Date: {$compDateStr}\nOnline Verification: {$verifyUrl}\n\nPlease find your official Certificate PDF attached to this email.\n\nComputerscience.lk Academy";

    // 7. PHPMailer Dispatch (Enforce SMTP Protocol)
    require_once __DIR__ . '/../includes/phpmailer/Exception.php';
    require_once __DIR__ . '/../includes/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/../includes/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    // Explicitly enforce SMTP protocol
    $mail->isSMTP();
    $mail->Host = !empty($smtp['smtp_host']) ? $smtp['smtp_host'] : 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $smtp['smtp_user'] ?? '';
    $mail->Password = $smtp['smtp_pass'] ?? '';

    $secure = strtolower($smtp['smtp_secure'] ?? 'tls');
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->Port = intval(!empty($smtp['smtp_port']) ? $smtp['smtp_port'] : 587);
    $mail->Timeout = 20;

    // Prevent SSL certificate verification blocks on local / XAMPP environments
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    $fromEmail = !empty($smtp['from_email']) ? $smtp['from_email'] : (!empty($smtp['smtp_user']) ? $smtp['smtp_user'] : 'certificates@computerscience.lk');
    $fromName = !empty($smtp['from_name']) ? $smtp['from_name'] : 'Computerscience.lk Academy';

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipientEmail, $studentName);
    $mail->addReplyTo($fromEmail, $fromName);

    // Attachment
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentName);
    $attachmentFileName = "{$safeName}_Certificate.pdf";
    $mail->addStringAttachment($pdfBinary, $attachmentFileName, 'base64', 'application/pdf');

    $mail->isHTML(true);
    $mail->Subject = "Congratulations on Completing {$courseTitle}! Your Official Certificate is Attached";
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;

    try {
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $me) {
        http_response_code(500);
        $errorDetail = $mail->ErrorInfo ?: $me->getMessage();
        echo json_encode([
            'success' => false,
            'message' => __('email_cert_failed', 'Failed to dispatch certificate email: ') . $errorDetail
        ]);
        exit;
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => __('email_cert_failed', 'Failed to dispatch certificate email: ') . $e->getMessage()
        ]);
        exit;
    }

    // 8. Update Database email_sent_at Timestamp
    $now = date('Y-m-d H:i:s');
    $upStmt = $pdo->prepare("UPDATE certificate_requests SET email_sent_at = ?, updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$now, $request_id]);

    // 9. Send In-App Notification to Student
    if (!empty($cert['user_id'])) {
        $notifMsg = "Your official certificate for '{$courseTitle}' has been sent to your email ({$recipientEmail}) with the attached PDF! (Ref: {$certCode})";
        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
        $nStmt->execute([$cert['user_id'], $notifMsg]);
    }

    echo json_encode([
        'success' => true,
        'message' => "Certificate successfully emailed to {$recipientEmail} with PDF attachment!",
        'email_sent_at' => date('M d, Y H:i', strtotime($now)),
        'email_sent_at_formatted' => date('M d, H:i', strtotime($now)),
        'recipient' => $recipientEmail,
        'certificate_code' => $certCode
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage()
    ]);
}
