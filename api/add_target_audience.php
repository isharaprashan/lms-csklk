<?php
require_once __DIR__ . '/../db/db_connect.php';
init_lms_session();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$name = trim($input['name'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Target audience name cannot be empty.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Check if target audience already exists
    $stmt = $pdo->prepare("SELECT id, name FROM target_audiences WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'id' => $existing['id'],
            'name' => $existing['name'],
            'message' => 'Target audience already exists.'
        ]);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO target_audiences (name, status) VALUES (?, 'active')");
    $stmt->execute([$name]);
    $new_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'id' => $new_id,
        'name' => $name,
        'message' => 'New target audience added successfully!'
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
