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

if (!isset($input['ledger_uuid']) || !isset($input['template_uuid'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$ledgerUuid = $input['ledger_uuid'];
$templateUuid = $input['template_uuid'];

try {
    // Call the API function to apply template
    $stmt = $db->prepare("SELECT api.apply_budget_template(:ledger_uuid, :template_uuid)");
    $stmt->bindParam(':ledger_uuid', $ledgerUuid, PDO::PARAM_STR);
    $stmt->bindParam(':template_uuid', $templateUuid, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetchColumn();
    $data = json_decode($result, true);
    
    if ($data['success']) {
        echo json_encode([
            'success' => true,
            'categories_created' => $data['categories_created'],
            'categories' => $data['categories']
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $data['error'] ?? 'Failed to apply template'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error applying template: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
