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
