<?php
// Centralized Google OAuth 2.0 Engine for Computerscience.lk LMS
// Lightweight implementation using standard PHP cURL (Zero heavy external dependencies)

require_once __DIR__ . '/../db/db_connect.php';

if (!function_exists('get_google_client_id')) {
    function get_google_client_id()
    {
        return trim(get_site_setting('google_client_id', ''));
    }
}

if (!function_exists('get_google_client_secret')) {
    function get_google_client_secret()
    {
        return trim(get_site_setting('google_client_secret', ''));
    }
}

if (!function_exists('is_google_oauth_enabled')) {
    function is_google_oauth_enabled()
    {
        $enabled = get_site_setting('google_oauth_enabled', '1');
        return ($enabled === '1' || $enabled === 1 || $enabled === 'true');
    }
}

if (!function_exists('get_google_redirect_uri')) {
    function get_google_redirect_uri()
    {
        $custom_uri = trim(get_site_setting('google_redirect_uri', ''));
        if (!empty($custom_uri)) {
            return $custom_uri;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Auto-detect base folder (e.g. /lms or /)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $baseDir = dirname($script);
        $baseDir = str_replace('\\', '/', $baseDir);
        $baseDir = rtrim($baseDir, '/');

        // Normalize if called from a subfolder like api/ or admin/
        if (str_ends_with($baseDir, '/admin') || str_ends_with($baseDir, '/api') || str_ends_with($baseDir, '/config')) {
            $baseDir = dirname($baseDir);
            $baseDir = str_replace('\\', '/', $baseDir);
            $baseDir = rtrim($baseDir, '/');
        }

        $basePath = ($baseDir === '/' || $baseDir === '') ? '' : $baseDir;
        return $protocol . $host . $basePath . '/google_callback.php';
    }
}

if (!function_exists('get_google_auth_url')) {
    function get_google_auth_url($role = 'student', $redirectAfter = '')
    {
        $clientId = get_google_client_id();
        $redirectUri = get_google_redirect_uri();

        if (empty($clientId)) {
            return 'google_auth.php?error=missing_client_id';
        }

        // Generate secure random state token
        if (session_status() === PHP_SESSION_NONE) {
            init_lms_session();
        }

        $csrf = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_csrf'] = $csrf;

        $statePayload = [
            'csrf' => $csrf,
            'role' => in_array($role, ['student', 'teacher']) ? $role : 'student',
            'redirect' => $redirectAfter
        ];
        $stateEncoded = urlencode(base64_encode(json_encode($statePayload)));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $stateEncoded,
            'access_type' => 'online',
            'prompt' => 'select_account'
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
}

if (!function_exists('exchange_google_code_for_token')) {
    function exchange_google_code_for_token($code)
    {
        $clientId = get_google_client_id();
        $clientSecret = get_google_client_secret();
        $redirectUri = get_google_redirect_uri();

        if (empty($clientId) || empty($clientSecret)) {
            return ['success' => false, 'error' => 'Google Client ID or Client Secret not configured.'];
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'cURL Error during Google token exchange: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $errMsg = $data['error_description'] ?? ($data['error'] ?? 'Failed to exchange authorization code for token.');
            return ['success' => false, 'error' => $errMsg, 'raw' => $data];
        }

        return ['success' => true, 'data' => $data];
    }
}

if (!function_exists('get_google_user_profile')) {
    function get_google_user_profile($accessToken)
    {
        $userinfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';

        $ch = curl_init($userinfoUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => 'cURL Error retrieving user profile: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['email'])) {
            $errMsg = $data['error_description'] ?? 'Failed to retrieve Google profile data.';
            return ['success' => false, 'error' => $errMsg, 'raw' => $data];
        }

        return [
            'success' => true,
            'data' => [
                'sub' => $data['sub'] ?? ($data['id'] ?? ''),
                'name' => $data['name'] ?? 'Google User',
                'given_name' => $data['given_name'] ?? '',
                'family_name' => $data['family_name'] ?? '',
                'email' => strtolower(trim($data['email'])),
                'email_verified' => !empty($data['email_verified']),
                'picture' => $data['picture'] ?? null
            ]
        ];
    }
}
