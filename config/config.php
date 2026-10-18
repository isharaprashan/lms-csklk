<?php
// Centralized Application & Path Configuration for Computerscience.lk LMS
// 100% Universal, Portable, and Cross-Platform (Linux/cPanel, Windows, Localhost, VPS)

if (!defined('ROOT_PATH')) {
    $calculatedRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    define('ROOT_PATH', $calculatedRoot);
}

if (!defined('BASE_URL')) {
    // 1. Detect Protocol (handles HTTPS, Reverse Proxies, Cloudflare, Port 443)
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    );
    $protocol = $isHttps ? "https://" : "http://";

    // 2. Detect Host / Domain & Port
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 3. Detect Subdirectory relative to Document Root
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $appDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));

    if (!empty($docRoot) && strpos($appDir, $docRoot) === 0) {
        $subDir = substr($appDir, strlen($docRoot));
    } else {
        // Fallback for symbolic links, CLI, or non-standard docroots
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = dirname($scriptPath);
        // Clean known subfolders like /admin, /api, /config, /ajax, /includes, /scratch
        $cleanDir = preg_replace('~/(admin|api|config|includes|ajax|uploads|scratch).*$~i', '', $scriptDir);
        $subDir = ($cleanDir === '/' || $cleanDir === '\\' || $cleanDir === '.') ? '' : $cleanDir;
    }

    $subDir = str_replace('\\', '/', $subDir);
    $subDir = '/' . trim($subDir, '/');
    if ($subDir === '/') {
        $subDir = '';
    }

    define('BASE_URL', rtrim($protocol . $host . $subDir, '/'));
}

if (!function_exists('url')) {
    /**
     * Generate universal absolute or relative web URL
     * e.g. url('login.php') -> "http://localhost/lms/login.php" or "https://domain.com/login.php"
     */
    function url($path = '')
    {
        $clean = ltrim(str_replace('\\', '/', (string)$path), '/');
        return $clean === '' ? BASE_URL : BASE_URL . '/' . $clean;
    }
}

if (!function_exists('admin_url')) {
    /**
     * Generate universal Admin portal web URL
     * e.g. admin_url('login.php') -> "http://localhost/lms/admin/login.php"
     */
    function admin_url($path = '')
    {
        $clean = ltrim(str_replace('\\', '/', (string)$path), '/');
        return $clean === '' ? BASE_URL . '/admin' : BASE_URL . '/admin/' . $clean;
    }
}

if (!function_exists('asset_url')) {
    /**
     * Generate universal static asset URL
     * e.g. asset_url('assets/css/style.css') -> "http://localhost/lms/assets/css/style.css"
     */
    function asset_url($path = '')
    {
        $clean = ltrim(str_replace('\\', '/', (string)$path), '/');
        return BASE_URL . '/' . $clean;
    }
}

if (!function_exists('root_path')) {
    /**
     * Generate universal normalized filesystem path
     * e.g. root_path('uploads/resources') -> "C:/xampp/htdocs/lms/uploads/resources" or "/var/www/html/uploads/resources"
     */
    function root_path($path = '')
    {
        $clean = ltrim(str_replace('\\', '/', (string)$path), '/');
        return $clean === '' ? ROOT_PATH : ROOT_PATH . '/' . $clean;
    }
}

if (!function_exists('redirect')) {
    /**
     * Universal safe redirect
     */
    function redirect($path)
    {
        if (preg_match('~^https?://~i', $path)) {
            header("Location: " . $path);
        } else {
            header("Location: " . url($path));
        }
        exit;
    }
}
