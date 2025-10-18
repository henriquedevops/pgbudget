<?php
/**
 * Delete Ledger API Endpoint
 * Deletes a ledger and all its related data
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['ledger_uuid'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required field: ledger_uuid']);
        exit;
    }

    $ledger_uuid = $input['ledger_uuid'];

    // Validate ledger UUID format (should be 8 characters from nanoid)
    if (!preg_match('/^[A-Za-z0-9_-]{8}$/', $ledger_uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ledger UUID format']);
        exit;
    }

    // Connect to database
    $db = getDbConnection();
    setUserContext($db);

    // Call delete function
    $stmt = $db->prepare("SELECT api.delete_ledger(?) as result");
    $stmt->execute([$ledger_uuid]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Failed to delete ledger');
    }

    // Parse the JSON result from the database function
    $deleteResult = json_decode($result['result'], true);

    if (!$deleteResult || !$deleteResult['success']) {
        throw new Exception('Ledger deletion failed');
    }

    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Ledger deleted successfully',
        'ledger_name' => $deleteResult['ledger_name'],
        'deleted_counts' => $deleteResult['deleted_counts']
    ]);

} catch (PDOException $e) {
    error_log("Database error in delete-ledger.php: " . $e->getMessage());

    // Check for specific error messages
    if (strpos($e->getMessage(), 'not found or access denied') !== false) {
        http_response_code(404);
        echo json_encode(['error' => 'Ledger not found or access denied']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error occurred']);
    }
} catch (Exception $e) {
    error_log("Error in delete-ledger.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
