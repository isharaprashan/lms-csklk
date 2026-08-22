<?php
$target_script = $argv[1] ?? '';
$post_b64 = $argv[2] ?? '';
$session_b64 = $argv[3] ?? '';
$get_b64 = $argv[4] ?? '';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($post_b64)) {
    $_POST = json_decode(base64_decode($post_b64), true) ?? [];
}
if (!empty($session_b64)) {
    $_SESSION = json_decode(base64_decode($session_b64), true) ?? [];
}
if (!empty($get_b64)) {
    $_GET = json_decode(base64_decode($get_b64), true) ?? [];
}

if (!empty($target_script) && file_exists(__DIR__ . '/../' . $target_script)) {
    require __DIR__ . '/../' . $target_script;
}
