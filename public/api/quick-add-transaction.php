<?php
/**
 * Quick-Add Transaction API Endpoint
 * Phase 3.4 - Quick-Add Transaction Modal
 *
 * Handles AJAX requests to add transactions from the quick-add modal
 */

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
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate required fields
if (!$data || !isset($data['ledger_uuid'], $data['type'], $data['amount'], $data['date'], $data['description'], $data['account'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$ledger_uuid = sanitizeInput($data['ledger_uuid']);
$type = sanitizeInput($data['type']);
$amount = parseCurrency($data['amount']);
$date = sanitizeInput($data['date']);
$description = sanitizeInput($data['description']);
$account_uuid = sanitizeInput($data['account']);
$category_uuid = isset($data['category']) && !empty($data['category']) ? sanitizeInput($data['category']) : null;
$payee_name = isset($data['payee']) && !empty($data['payee']) ? sanitizeInput($data['payee']) : null;

// Validate transaction type
if (!in_array($type, ['inflow', 'outflow'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction type']);
    exit;
}

// Validate amount
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
    exit;
}

// Validate date format
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

// Validate category for outflows
if ($type === 'outflow' && empty($category_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Category is required for expenses']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Add transaction using API function
    $stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $ledger_uuid,
        $date,
        $description,
        $type,
        $amount,
        $account_uuid,
        $category_uuid,
        $payee_name
    ]);

    $result = $stmt->fetch();

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Transaction added successfully!',
            'transaction_uuid' => $result[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to add transaction'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
