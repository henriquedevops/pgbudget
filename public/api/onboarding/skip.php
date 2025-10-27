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

try {
    // Call the API function to skip onboarding
    $stmt = $db->prepare("SELECT api.skip_onboarding()");
    $stmt->execute();
    
    $result = $stmt->fetchColumn();
    $data = json_decode($result, true);
    
    if ($data['success']) {
        echo json_encode([
            'success' => true,
            'skipped' => true
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to skip onboarding'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error skipping onboarding: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
