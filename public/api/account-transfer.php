<?php
/**
 * Account Transfer API Endpoint
 * Phase 3.5 - Account Transfers Simplified
 *
 * Creates a transfer transaction between two accounts
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON header
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$required_fields = ['ledger_uuid', 'from_account_uuid', 'to_account_uuid', 'amount', 'date'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

// Extract and sanitize inputs
$ledger_uuid = sanitizeInput($input['ledger_uuid']);
$from_account_uuid = sanitizeInput($input['from_account_uuid']);
$to_account_uuid = sanitizeInput($input['to_account_uuid']);
$amount = $input['amount'];
$date = sanitizeInput($input['date']);
$memo = isset($input['memo']) ? sanitizeInput($input['memo']) : null;

// Validate amount is positive number
if (!is_numeric($amount) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be a positive number']);
    exit;
}

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Validate accounts are different
if ($from_account_uuid === $to_account_uuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cannot transfer to the same account']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context for RLS
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Call the API function to create the transfer
    $stmt = $db->prepare("SELECT api.add_account_transfer(?, ?, ?, ?, ?, ?) as transaction_uuid");
    $stmt->execute([
        $ledger_uuid,
        $from_account_uuid,
        $to_account_uuid,
        $amount,
        $date,
        $memo
    ]);

    $result = $stmt->fetch();

    if ($result && isset($result['transaction_uuid'])) {
        echo json_encode([
            'success' => true,
            'transaction_uuid' => $result['transaction_uuid'],
            'message' => 'Transfer created successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create transfer'
        ]);
    }

} catch (PDOException $e) {
    // Check if this is a custom exception from our function
    $error_message = $e->getMessage();

    if (strpos($error_message, 'Transfer amount must be positive') !== false ||
        strpos($error_message, 'Cannot transfer to the same account') !== false ||
        strpos($error_message, 'Source account not found') !== false ||
        strpos($error_message, 'Destination account not found') !== false ||
        strpos($error_message, 'Both accounts must belong') !== false ||
        strpos($error_message, 'must be an asset or liability') !== false ||
        strpos($error_message, 'Access denied') !== false ||
        strpos($error_message, 'Ledger not found') !== false) {
        // These are validation errors, return 400
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $error_message
        ]);
    } else {
        // Other database errors, return 500
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $error_message
        ]);
    }
}
