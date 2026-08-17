<?php
require_once __DIR__ . '/../db/db_connect.php';
require_once __DIR__ . '/../lang/i18n.php';

init_lms_session();

header('Content-Type: application/json');

// Read input from JSON body or POST parameter or GET parameter
$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true) ?: [];

$requested_lang = trim($input_data['lang'] ?? $_POST['lang'] ?? $_GET['lang'] ?? '');

if (in_array($requested_lang, ['en', 'si'])) {
    $_SESSION['lang'] = $requested_lang;
    
    // Check if GET parameter was sent for standard link navigation
    if (isset($_GET['lang']) && isset($_SERVER['HTTP_REFERER'])) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'lang' => $requested_lang,
        'message' => 'Language changed successfully'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Invalid language code specified'
]);
?>
