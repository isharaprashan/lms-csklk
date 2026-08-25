<?php
// Central Multilingual Translation Engine (English & Sinhala)

if (!function_exists('init_lms_language')) {
    function init_lms_language() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (function_exists('init_lms_session')) {
                init_lms_session();
            } else {
                session_start();
            }
        }

        if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], ['en', 'si'])) {
            $_SESSION['lang'] = 'en';
        }

        return $_SESSION['lang'];
    }
}

if (!function_exists('get_translations')) {
    function get_translations($lang = null) {
        static $cached_translations = [];

        if ($lang === null) {
            $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';
        }

        if (isset($cached_translations[$lang])) {
            return $cached_translations[$lang];
        }

        $file = __DIR__ . '/' . $lang . '.php';
        if (file_exists($file)) {
            $cached_translations[$lang] = require $file;
        } else {
            $cached_translations[$lang] = require __DIR__ . '/en.php';
        }

        return $cached_translations[$lang];
    }
}

if (!function_exists('__')) {
    function __($key, $default = null) {
        $translations = get_translations();
        if (isset($translations[$key])) {
            return $translations[$key];
        }
        return $default !== null ? $default : $key;
    }
}

if (!function_exists('render_i18n_js')) {
    function render_i18n_js() {
        $lang = $_SESSION['lang'] ?? 'en';
        $translations = get_translations();
        $json = json_encode($translations, JSON_UNESCAPED_UNICODE);
        echo "<script>
          window.LANG = " . json_encode($lang) . ";
          window.I18N = " . $json . ";
          if (typeof window.i18n__ !== 'function') {
            window.i18n__ = function(key, defaultVal) {
              return (window.I18N && window.I18N[key]) ? window.I18N[key] : (defaultVal || key);
            };
          }
        </script>";
    }
}

if (!function_exists('format_time_ago_lms')) {
    function format_time_ago_lms($timestamp) {
        if (empty($timestamp)) return __('recently', 'Recently');
        $time = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
        $diff = time() - $time;
        $is_si = (isset($_SESSION['lang']) && $_SESSION['lang'] === 'si');

        if ($diff < 60) {
            return $is_si ? 'සුළු මොහොතකට පෙර' : 'Just now';
        } elseif ($diff < 3600) {
            $mins = max(1, floor($diff / 60));
            return $is_si ? "මිනිත්තු {$mins}කට පෙර" : ($mins . 'm ago');
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $is_si ? "පැය {$hours}කට පෙර" : ($hours . 'h ago');
        } elseif ($diff < 172800) {
            return $is_si ? 'ඊයේ' : 'Yesterday';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $is_si ? "දින {$days}කට පෙර" : ($days . 'd ago');
        } else {
            return date('M d, Y', $time);
        }
    }
}

// Auto-run initialization
init_lms_language();
?>
