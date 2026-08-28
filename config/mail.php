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

if (!function_exists('send_password_reset_email')) {
    function send_password_reset_email($toEmail, $toName, $resetLink, $expiresMinutes = 30)
    {
        $subject = "Password Reset Request - Computerscience.lk";

        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Password Reset Request</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f4f6f9; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table width='100%' max-width='580' border='0' cellspacing='0' cellpadding='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #091527 0%, #0f3d6c 50%, #174b85 100%); padding: 32px 25px; text-align: center;'>
                                    <h1 style='color: #ffffff; font-size: 22px; font-weight: 700; margin: 0; letter-spacing: 0.5px;'>computerscience.lk</h1>
                                    <p style='color: rgba(255,255,255,0.75); font-size: 13px; margin: 6px 0 0;'>Academic Security & Authentication Service</p>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style='padding: 35px 30px;'>
                                    <h2 style='color: #1e293b; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 12px;'>Password Reset Request</h2>
                                    <p style='color: #475569; font-size: 14px; line-height: 1.6; margin: 0 0 20px;'>
                                        Hello " . htmlspecialchars($toName ?: 'User') . ",<br>
                                        We received a request to reset the password for your account on <strong>Computerscience.lk</strong>. Click the button below to choose a new password:
                                    </p>

                                    <!-- CTA Button -->
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='" . htmlspecialchars($resetLink) . "' target='_blank' style='display: inline-block; background: linear-gradient(135deg, #2b529a 0%, #0f4c81 100%); color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; padding: 14px 34px; border-radius: 50px; box-shadow: 0 4px 15px rgba(15, 76, 129, 0.35);'>
                                            🔒 Reset My Password
                                        </a>
                                        <div style='margin-top: 12px; color: #64748b; font-size: 12px;'>
                                            ⏱ This link will expire in <strong>" . intval($expiresMinutes) . " minutes</strong>.
                                        </div>
                                    </div>

                                    <!-- Fallback Link Box -->
                                    <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin: 25px 0;'>
                                        <p style='color: #64748b; font-size: 12px; margin: 0 0 8px; font-weight: 600;'>
                                            If the button above does not work, copy and paste this link into your browser:
                                        </p>
                                        <p style='margin: 0; word-break: break-all;'>
                                            <a href='" . htmlspecialchars($resetLink) . "' target='_blank' style='color: #0f4c81; font-size: 12px; text-decoration: underline;'>
                                                " . htmlspecialchars($resetLink) . "
                                            </a>
                                        </p>
                                    </div>
                                    
                                    <!-- Security Notice -->
                                    <div style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin-top: 25px;'>
                                        <p style='color: #92400e; font-size: 12px; margin: 0; line-height: 1.5;'>
                                            <strong>Security Alert:</strong> If you did not request this password reset, please ignore this email or contact support if you suspect unauthorized activity. Your password will remain unchanged.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;'>
                                    <p style='color: #94a3b8; font-size: 12px; margin: 0; line-height: 1.5;'>
                                        This email was sent to " . htmlspecialchars($toEmail) . ".<br>
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

        $altBody = "Password Reset Request - Computerscience.lk\n\nHello " . ($toName ?: 'User') . ",\n\nWe received a request to reset your password. Please visit the following link to set a new password:\n\n{$resetLink}\n\nThis link is valid for {$expiresMinutes} minutes.\n\nIf you did not make this request, please ignore this email.\n\nComputerscience.lk Academy";

        return send_system_email($toEmail, $toName, $subject, $htmlBody, $altBody);
    }
}

if (!function_exists('send_admin_welcome_credentials_email')) {
    function send_admin_welcome_credentials_email($toEmail, $toName, $tempPassword, $loginUrl)
    {
        $subject = "🎉 Welcome to Computerscience.lk Admin Team - Your Login Credentials";

        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Welcome to Admin Team</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #f4f6f9; padding: 30px 10px;'>
                <tr>
                    <td align='center'>
                        <table width='100%' max-width='580' border='0' cellspacing='0' cellpadding='0' style='max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #052014 0%, #0b4528 50%, #125b36 100%); padding: 32px 25px; text-align: center;'>
                                    <h1 style='color: #ffffff; font-size: 22px; font-weight: 700; margin: 0; letter-spacing: 0.5px;'>computerscience.lk</h1>
                                    <p style='color: rgba(255,255,255,0.75); font-size: 13px; margin: 6px 0 0;'>Academic Administration & Faculty Management</p>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style='padding: 35px 30px;'>
                                    <div style='text-align: center; margin-bottom: 20px;'>
                                        <span style='font-size: 40px;'>🎉</span>
                                        <h2 style='color: #0b4528; font-size: 20px; font-weight: 700; margin-top: 8px; margin-bottom: 6px;'>Welcome to the Admin Team!</h2>
                                        <p style='color: #64748b; font-size: 13px; margin: 0;'>Official Administrator Credentials</p>
                                    </div>

                                    <p style='color: #475569; font-size: 14px; line-height: 1.6; margin: 0 0 20px;'>
                                        Hello <strong>" . htmlspecialchars($toName ?: 'Administrator') . "</strong>,<br>
                                        Congratulations! You have been granted administrative privileges on the <strong>Computerscience.lk LMS Platform</strong>. Use the temporary credentials below to access your administrator portal:
                                    </p>

                                    <!-- Credentials Box -->
                                    <div style='background: #f8fafc; border: 1.5px solid #d1e7dd; border-radius: 12px; padding: 22px; margin: 25px 0;'>
                                        <table width='100%' border='0' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td style='padding: 6px 0; color: #64748b; font-size: 13px; width: 130px; font-weight: 600;'>Username / Email:</td>
                                                <td style='padding: 6px 0; color: #1e293b; font-size: 14px; font-weight: 700;'>" . htmlspecialchars($toEmail) . "</td>
                                            </tr>
                                            <tr>
                                                <td style='padding: 6px 0; color: #64748b; font-size: 13px; font-weight: 600;'>Temporary Password:</td>
                                                <td style='padding: 6px 0;'>
                                                    <span style='font-family: Consolas, Monaco, monospace; font-size: 17px; font-weight: 800; color: #0b4528; background: #e8f5e9; padding: 4px 10px; border-radius: 6px; border: 1px dashed #2e7d32; display: inline-block; letter-spacing: 1px;'>
                                                        " . htmlspecialchars($tempPassword) . "
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style='padding: 6px 0; color: #64748b; font-size: 13px; font-weight: 600;'>Assigned Role:</td>
                                                <td style='padding: 6px 0; color: #0f5132; font-size: 13px; font-weight: 600;'>System Administrator</td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- CTA Button -->
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='" . htmlspecialchars($loginUrl) . "' target='_blank' style='display: inline-block; background: linear-gradient(135deg, #0b4528 0%, #125b36 100%); color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; padding: 14px 34px; border-radius: 50px; box-shadow: 0 4px 15px rgba(11, 69, 40, 0.35);'>
                                            🔐 Sign In to Admin Console
                                        </a>
                                    </div>

                                    <!-- Security Mandatory Warning -->
                                    <div style='background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 14px 16px; border-radius: 6px; margin-top: 25px;'>
                                        <p style='color: #664d03; font-size: 13px; margin: 0; line-height: 1.5;'>
                                            <strong>⚠️ Mandatory Security Notice:</strong> For security compliance, you are required to change this temporary password immediately upon your first login. You will be prompted to choose a permanent, private password before accessing the admin dashboard.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 20px 30px; border-top: 1px solid #e2e8f0; text-align: center;'>
                                    <p style='color: #94a3b8; font-size: 12px; margin: 0; line-height: 1.5;'>
                                        This confidential email was sent to " . htmlspecialchars($toEmail) . ".<br>
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

        $altBody = "Welcome to Computerscience.lk Admin Team!\n\nHello {$toName},\n\nYou have been granted administrative privileges on Computerscience.lk.\n\nLogin URL: {$loginUrl}\nEmail: {$toEmail}\nTemporary Password: {$tempPassword}\n\nSecurity Notice: You will be required to change this temporary password immediately upon your first login.\n\nComputerscience.lk Academy";

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

if (!function_exists('send_admin_password_reset_email')) {
    function send_admin_password_reset_email($toEmail, $toName, $resetLink, $role = 'admin', $expiresMinutes = 20, $ipAddress = null)
    {
        $roleLabel = ($role === 'super_admin') ? 'Super Administrator' : 'Administrator';
        $siteName = 'Computerscience.lk';
        $subject = "🔒 Security Alert: Admin Password Reset Request - {$siteName}";
        $reqTime = date('Y-m-d H:i:s T');
        $clientIp = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? 'Unknown IP');

        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Admin Password Reset Request</title>
        </head>
        <body style='margin: 0; padding: 0; background-color: #0b1329; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <table width='100%' border='0' cellspacing='0' cellpadding='0' style='background-color: #0b1329; padding: 35px 12px;'>
                <tr>
                    <td align='center'>
                        <table width='100%' max-width='600' border='0' cellspacing='0' cellpadding='0' style='max-width: 600px; background-color: #ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 20px 45px rgba(0,0,0,0.35); border: 1px solid #1e293b;'>
                            <!-- Header -->
                            <tr>
                                <td style='background: linear-gradient(135deg, #052014 0%, #0b4528 50%, #125b36 100%); padding: 32px 28px; text-align: center;'>
                                    <div style='display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 50px; padding: 5px 16px; margin-bottom: 12px;'>
                                        <span style='color: #ffffff; font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;'>🔒 Security & Access Control</span>
                                    </div>
                                    <h1 style='color: #ffffff; font-size: 23px; font-weight: 800; margin: 0; letter-spacing: 0.5px;'>{$siteName}</h1>
                                    <p style='color: rgba(255,255,255,0.8); font-size: 13px; margin: 6px 0 0; font-weight: 500;'>Management Console & Administrative Security</p>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style='padding: 38px 32px; background-color: #ffffff;'>
                                    <div style='display: flex; align-items: center; margin-bottom: 16px;'>
                                        <span style='display: inline-block; background-color: #e6f4ea; color: #137333; border: 1px solid #34a853; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase;'>
                                            " . htmlspecialchars($roleLabel) . " Account
                                        </span>
                                    </div>

                                    <h2 style='color: #0f172a; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 12px;'>Password Reset Request</h2>
                                    <p style='color: #334155; font-size: 14px; line-height: 1.65; margin: 0 0 20px;'>
                                        Hello <strong>" . htmlspecialchars($toName ?: 'Administrator') . "</strong>,<br>
                                        We received an administrative request to reset the access credentials for your <strong>" . htmlspecialchars($roleLabel) . "</strong> account on <strong>{$siteName}</strong>.
                                    </p>

                                    <!-- Reset CTA Button -->
                                    <div style='text-align: center; margin: 30px 0;'>
                                        <a href='" . htmlspecialchars($resetLink) . "' style='background: linear-gradient(135deg, #0b4528 0%, #125b36 100%); color: #ffffff; text-decoration: none; padding: 14px 34px; border-radius: 50px; font-weight: 700; font-size: 15px; display: inline-block; box-shadow: 0 8px 20px rgba(11,69,40,0.3); letter-spacing: 0.3px;'>
                                            🔑 Reset Admin Password
                                        </a>
                                    </div>

                                    <!-- Metadata & Expiry Box -->
                                    <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin: 24px 0; font-size: 13px; color: #475569;'>
                                        <table width='100%' border='0' cellspacing='0' cellpadding='4'>
                                            <tr>
                                                <td width='40%' style='color: #64748b; font-weight: 600;'>⏱ Token Expiration:</td>
                                                <td width='60%' style='color: #0f172a; font-weight: 700;'>" . intval($expiresMinutes) . " Minutes</td>
                                            </tr>
                                            <tr>
                                                <td style='color: #64748b; font-weight: 600;'>🌐 Requested From IP:</td>
                                                <td style='color: #0f172a; font-family: Consolas, monospace;'>" . htmlspecialchars($clientIp) . "</td>
                                            </tr>
                                            <tr>
                                                <td style='color: #64748b; font-weight: 600;'>📅 Timestamp:</td>
                                                <td style='color: #0f172a;'>" . htmlspecialchars($reqTime) . "</td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- Security Advisory Alert -->
                                    <div style='background-color: #fef2f2; border-left: 4px solid #ef4444; border-radius: 8px; padding: 14px 18px; margin: 24px 0;'>
                                        <p style='color: #991b1b; font-size: 13px; margin: 0; line-height: 1.5;'>
                                            <strong>⚠️ Security Advisory:</strong> If you did not initiate this password reset, your administrator credentials may be under targeted probing. Please notify the <strong>Super Administrator</strong> immediately and audit your account activity.
                                        </p>
                                    </div>

                                    <!-- Plain Link Fallback -->
                                    <p style='color: #64748b; font-size: 12px; line-height: 1.6; margin: 24px 0 0;'>
                                        If the button above does not work, copy and paste the following URL into your browser's address bar:<br>
                                        <a href='" . htmlspecialchars($resetLink) . "' style='color: #0b4528; word-break: break-all; font-family: Consolas, Monaco, monospace; font-size: 11px;'>
                                            " . htmlspecialchars($resetLink) . "
                                        </a>
                                    </p>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style='background-color: #f8fafc; padding: 22px 30px; border-top: 1px solid #e2e8f0; text-align: center;'>
                                    <p style='color: #94a3b8; font-size: 12px; margin: 0; line-height: 1.6;'>
                                        This is a privileged security notification delivered to " . htmlspecialchars($toEmail) . ".<br>
                                        &copy; " . date('Y') . " {$siteName} Enterprise Security & Governance.
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

        $altBody = "Security Alert: Admin Password Reset Request - {$siteName}\n\nHello {$toName},\n\nA password reset request was initiated for your {$roleLabel} account.\n\nReset Link: {$resetLink}\n\nValid for {$expiresMinutes} minutes.\nRequested From IP: {$clientIp}\nTimestamp: {$reqTime}\n\nIf you did not request this, please contact Super Admin immediately.\n\n{$siteName}";

        return send_system_email($toEmail, $toName, $subject, $htmlBody, $altBody);
    }
}
