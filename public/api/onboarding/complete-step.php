<?php
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth(false);

// Set PostgreSQL user context
$db = getDbConnection();
setUserContext($db);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['step']) || !is_numeric($input['step'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid step number']);
    exit;
}

$step = (int)$input['step'];

try {
    // Call the API function to complete the step
    $stmt = $db->prepare("SELECT api.complete_onboarding_step(:step)");
    $stmt->bindParam(':step', $step, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetchColumn();
    $data = json_decode($result, true);
    
    if ($data['success']) {
        echo json_encode([
            'success' => true,
            'current_step' => $data['current_step'],
            'completed' => $data['completed']
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $data['error'] ?? 'Failed to complete step'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error completing onboarding step: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
