<?php
// Centralized Mail Dispatch Helper Engine for LMS
// Connects dynamically to database-driven SMTP configurations

if (!function_exists('get_smtp_settings')) {
    function get_smtp_settings($pdo = null)
    {
        if ($pdo === null) {
            if (function_exists('getDBConnection')) {
                $pdo = getDBConnection();
            } else {
                require_once __DIR__ . '/../db/db_connect.php';
                $pdo = getDBConnection();
            }
        }

        try {
            // First check smtp_settings table
            $stmt = $pdo->query("SELECT * FROM smtp_settings ORDER BY id DESC LIMIT 1");
            $config = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($config) {
                return [
                    'id' => $config['id'] ?? 1,
                    'smtp_host' => $config['smtp_host'] ?? 'smtp.gmail.com',
                    'smtp_port' => intval($config['smtp_port'] ?? 587),
                    'smtp_user' => trim($config['smtp_user'] ?? ''),
                    'smtp_pass' => trim($config['smtp_pass'] ?? ''),
                    'smtp_secure' => strtolower($config['smtp_secure'] ?? 'tls'),
                    'from_email' => trim($config['from_email'] ?? 'noreply@computerscience.lk'),
                    'from_name' => trim($config['from_name'] ?? 'Computerscience.lk Academy'),
                    'updated_at' => $config['updated_at'] ?? date('Y-m-d H:i:s')
                ];
            }

            // Fallback check smtp_configs table
            $stmt2 = $pdo->query("SELECT * FROM smtp_configs WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $config2 = $stmt2 ? $stmt2->fetch(PDO::FETCH_ASSOC) : null;
            if ($config2) {
                return [
                    'id' => $config2['id'] ?? 1,
                    'smtp_host' => $config2['host'] ?? 'smtp.gmail.com',
                    'smtp_port' => intval($config2['port'] ?? 587),
                    'smtp_user' => trim($config2['username'] ?? ''),
                    'smtp_pass' => trim($config2['password'] ?? ''),
                    'smtp_secure' => strtolower($config2['encryption'] ?? 'tls'),
                    'from_email' => trim($config2['from_email'] ?? 'noreply@computerscience.lk'),
                    'from_name' => trim($config2['from_name'] ?? 'Computerscience.lk Academy'),
                    'updated_at' => $config2['updated_at'] ?? date('Y-m-d H:i:s')
                ];
            }
        } catch (Exception $e) {
            error_log("get_smtp_settings error: " . $e->getMessage());
        }

        // Default fallback values
        return [
            'id' => 0,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
            'from_email' => 'noreply@computerscience.lk',
            'from_name' => 'Computerscience.lk Academy',
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
}

if (!function_exists('get_phpmailer_instance')) {
    function get_phpmailer_instance($customConfig = null)
    {
        require_once __DIR__ . '/../includes/phpmailer/Exception.php';
        require_once __DIR__ . '/../includes/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/../includes/phpmailer/SMTP.php';

        $config = $customConfig !== null ? $customConfig : get_smtp_settings();

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        // 1. Enforce SMTP protocol explicitly
        $mail->isSMTP();
        $mail->Host = !empty($config['smtp_host']) ? $config['smtp_host'] : 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'] ?? '';
        $mail->Password = $config['smtp_pass'] ?? '';

        $secure = strtolower($config['smtp_secure'] ?? 'tls');
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->Port = intval(!empty($config['smtp_port']) ? $config['smtp_port'] : 587);
        $mail->Timeout = 20;

        // 2. Prevent SSL certificate verification blocks on local / XAMPP environments
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $fromEmail = !empty($config['from_email']) ? $config['from_email'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'noreply@computerscience.lk');
        $fromName = !empty($config['from_name']) ? $config['from_name'] : 'Computerscience.lk Academy';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);

        return $mail;
    }
}

if (!function_exists('send_system_email')) {
    function send_system_email($toEmail, $toName, $subject, $htmlBody, $altBody = '', $attachments = [], $customConfig = null)
    {
        $result = [
            'success' => false,
            'message' => '',
            'debug' => ''
        ];

        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result['message'] = 'Invalid recipient email address.';
            return $result;
        }

        try {
            $mail = get_phpmailer_instance($customConfig);
            $mail->addAddress($toEmail, $toName ?: 'User');

            // Attachments
            if (!empty($attachments) && is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (isset($att['binary'], $att['filename'])) {
                        $mail->addStringAttachment($att['binary'], $att['filename'], 'base64', $att['type'] ?? 'application/pdf');
                    } elseif (isset($att['path']) && file_exists($att['path'])) {
                        $mail->addAttachment($att['path'], $att['filename'] ?? basename($att['path']));
                    }
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody ?: strip_tags($htmlBody);

            $mail->send();
            $result['success'] = true;
            $result['message'] = 'Email delivered successfully.';
        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
            $result['debug'] = $e->getMessage();
        }

        return $result;
    }
}

if (!function_exists('send_otp_email')) {
    function send_otp_email($toEmail, $toName, $otpCode)
    {
        $fromName = 'Computerscience.lk Academy';
        $subject = "Your Verification Code: {$otpCode} - Computerscience.lk";

        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Verification Code</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f4f6f9; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table width='100%' max-width='560' border='0' cellspacing='0' cellpadding='0' style='max-width: 560px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #091527 0%, #0f3d6c 50%, #174b85 100%); padding: 30px 25px; text-align: center;'>
                                    <h1 style='color: #ffffff; font-size: 22px; font-weight: 700; margin: 0; letter-spacing: 0.5px;'>computerscience.lk</h1>
                                    <p style='color: rgba(255,255,255,0.75); font-size: 13px; margin: 6px 0 0;'>Academic Identity & Verification Service</p>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style='padding: 35px 30px;'>
                                    <h2 style='color: #1e293b; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 12px;'>Email Verification Code</h2>
                                    <p style='color: #475569; font-size: 14px; line-height: 1.6; margin: 0 0 20px;'>
                                        Hello " . htmlspecialchars($toName ?: 'Student') . ",<br>
                                        Thank you for registering on Computerscience.lk. Please use the 6-digit verification code below to verify your email address and activate your account.
                                    </p>

                                    <!-- OTP Box -->
                                    <div style='background: #f8fafc; border: 2px dashed #0f4c81; border-radius: 12px; padding: 20px; text-align: center; margin: 25px 0;'>
                                        <span style='font-family: Consolas, Monaco, \"Courier New\", monospace; font-size: 34px; font-weight: 800; letter-spacing: 10px; color: #0f4c81; display: inline-block;'>
                                            " . htmlspecialchars($otpCode) . "
                                        </span>
                                        <div style='margin-top: 8px; color: #64748b; font-size: 12px;'>
                                            ⏱ Valid for <strong>10 minutes</strong>
                                        </div>
                                    </div>

                                    <p style='color: #64748b; font-size: 13px; line-height: 1.5; margin: 0 0 10px;'>
                                        Enter this 6-digit code on the verification screen to complete your registration.
                                    </p>
                                    
                                    <div style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin-top: 20px;'>
                                        <p style='color: #92400e; font-size: 12px; margin: 0; line-height: 1.4;'>
                                            <strong>Security Notice:</strong> Never share this OTP with anyone. Computerscience.lk staff will never ask for your verification code.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;'>
                                    <p style='color: #94a3b8; font-size: 12px; margin: 0; line-height: 1.5;'>
                                        This email was sent to " . htmlspecialchars($toEmail) . ". If you did not create an account, you can safely ignore this email.<br>
                                        &copy; " . date('Y') . " Computerscience.lk Academy. All rights reserved.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        $altBody = "Your Computerscience.lk verification code is: {$otpCode}\n\nThis code is valid for 10 minutes. Please do not share this code with anyone.\n\nComputerscience.lk Academy";

        return send_system_email($toEmail, $toName, $subject, $htmlBody, $altBody);
    }
}

if (!function_exists('test_smtp_connection')) {
    function test_smtp_connection($testEmail, $customConfig = null)
    {
        require_once __DIR__ . '/../includes/phpmailer/Exception.php';
        require_once __DIR__ . '/../includes/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/../includes/phpmailer/SMTP.php';

        $config = $customConfig !== null ? $customConfig : get_smtp_settings();
        $debugOutput = '';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        // Capture verbose debug logs
        $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function ($str, $level) use (&$debugOutput) {
            $debugOutput .= "[" . date('H:i:s') . " DEBUG]: " . trim($str) . "\n";
        };

        $result = [
            'success' => false,
            'message' => '',
            'debug' => ''
        ];

        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_user'];
            $mail->Password = $config['smtp_pass'];

            $secure = strtolower($config['smtp_secure'] ?? 'tls');
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
                $mail->SMTPSecure = '';
            }

            $mail->Port = intval($config['smtp_port'] ?? 587);
            $mail->Timeout = 20;

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $fromEmail = !empty($config['from_email']) ? $config['from_email'] : (!empty($config['smtp_user']) ? $config['smtp_user'] : 'noreply@computerscience.lk');
            $fromName = !empty($config['from_name']) ? $config['from_name'] : 'Computerscience.lk Academy';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($testEmail, 'LMS Administrator');
            $mail->isHTML(true);

            $mail->Subject = "SMTP Test Connection Verification - " . $fromName;
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 560px; margin: 20px auto; padding: 25px; border-radius: 12px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <div style='font-size: 36px;'>✅</div>
                        <h2 style='color: #0f4c81; margin: 8px 0 4px;'>SMTP Test Successful</h2>
                        <p style='color: #64748b; font-size: 13px; margin: 0;'>Computerscience.lk Mailer Diagnostics</p>
                    </div>
                    <div style='background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px; color: #334155;'>
                        <p style='margin: 4px 0;'><strong>SMTP Host:</strong> " . htmlspecialchars($config['smtp_host']) . "</p>
                        <p style='margin: 4px 0;'><strong>Port & Security:</strong> " . htmlspecialchars($config['smtp_port']) . " (" . strtoupper($config['smtp_secure']) . ")</p>
                        <p style='margin: 4px 0;'><strong>Authenticated Account:</strong> " . htmlspecialchars($config['smtp_user']) . "</p>
                        <p style='margin: 4px 0;'><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
                    </div>
                    <p style='color: #475569; font-size: 13px; margin-top: 18px; line-height: 1.5;'>
                        This test email verifies that your SMTP credentials are valid and ready to dispatch official certificates and student notifications.
                    </p>
                </div>
            ";
            $mail->AltBody = "SMTP Connection Test Succeeded!\nHost: {$config['smtp_host']}\nPort: {$config['smtp_port']}\nUser: {$config['smtp_user']}\nDate: " . date('Y-m-d H:i:s');

            $mail->send();
            $result['success'] = true;
            $result['message'] = "SMTP Connection test passed! Test email successfully delivered to {$testEmail}.";
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $result['success'] = false;
            $result['message'] = "SMTP Handshake Error: " . ($mail->ErrorInfo ?: $e->getMessage());
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = "SMTP Handshake Error: " . $e->getMessage();
        }

        $result['debug'] = $debugOutput;
        return $result;
    }
}
