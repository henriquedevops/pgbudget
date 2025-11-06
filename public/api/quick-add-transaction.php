<?php
/**
 * Quick-Add Transaction API Endpoint
 * Phase 3.4 - Quick-Add Transaction Modal
 *
 * Handles AJAX requests to add transactions from the quick-add modal
 */

// Start session first
session_start();

$logFile = __DIR__ . '/../../logs/quick-add-api.log';
@file_put_contents($logFile, "\n\n=== " . date('Y-m-d H:i:s') . " - SCRIPT EXECUTION STARTED ===\n", FILE_APPEND);
@file_put_contents($logFile, "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n", FILE_APPEND);
@file_put_contents($logFile, "Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . "\n", FILE_APPEND);
@file_put_contents($logFile, "HTTP Host: " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "\n", FILE_APPEND);

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    require_once '../../config/database.php';
    file_put_contents($logFile, "Database config loaded\n", FILE_APPEND);
    file_put_contents($logFile, "Cookies received: " . print_r($_COOKIE, true) . "\n", FILE_APPEND);
    file_put_contents($logFile, "Session ID: " . session_id() . "\n", FILE_APPEND);
    file_put_contents($logFile, "Session data: " . print_r($_SESSION, true) . "\n", FILE_APPEND);
    file_put_contents($logFile, "Session save path: " . session_save_path() . "\n", FILE_APPEND);

    require_once '../../includes/auth.php';
    file_put_contents($logFile, "Auth included loaded\n", FILE_APPEND);

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        file_put_contents($logFile, "ERROR: No user_id in session! Returning JSON error instead of redirect.\n", FILE_APPEND);
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated. Please refresh the page and try again.']);
        exit;
    }

    file_put_contents($logFile, "User authenticated: " . $_SESSION['user_id'] . "\n", FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($logFile, "Early exception: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($logFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

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

// Debug logging (logFile already defined at top)
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Quick-Add API processing request\n", FILE_APPEND);
file_put_contents($logFile, "Input: " . $input . "\n", FILE_APPEND);
file_put_contents($logFile, "Decoded data: " . print_r($data, true) . "\n", FILE_APPEND);
error_log("Quick-Add API Called");
error_log("Input: " . $input);
error_log("Decoded data: " . print_r($data, true));

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
$loan_payment_uuid = isset($data['loan_payment_uuid']) && !empty($data['loan_payment_uuid']) ? sanitizeInput($data['loan_payment_uuid']) : null;

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
    $userId = $_SESSION['user_id'];
    file_put_contents($logFile, "Setting user context: $userId\n", FILE_APPEND);
    error_log("Setting user context: " . $userId);
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$userId]);

    // Add transaction using API function (with optional loan payment linking)
    file_put_contents($logFile, "Calling api.add_transaction with: ledger=$ledger_uuid, date=$date, desc=$description, type=$type, amount=$amount, account=$account_uuid, category=$category_uuid, payee=$payee_name, loan_payment=$loan_payment_uuid\n", FILE_APPEND);
    error_log("Calling api.add_transaction with: ledger=$ledger_uuid, date=$date, desc=$description, type=$type, amount=$amount, account=$account_uuid, category=$category_uuid, payee=$payee_name, loan_payment=$loan_payment_uuid");
    $stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $ledger_uuid,
        $date,
        $description,
        $type,
        $amount,
        $account_uuid,
        $category_uuid,
        $payee_name,
        $loan_payment_uuid
    ]);

    $result = $stmt->fetch();
    file_put_contents($logFile, "API result: " . print_r($result, true) . "\n", FILE_APPEND);
    error_log("API result: " . print_r($result, true));

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Transaction added successfully!',
            'transaction_uuid' => $result[0]
        ]);
    } else {
        error_log("API call returned no result");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to add transaction'
        ]);
    }

} catch (PDOException $e) {
    file_put_contents($logFile, "PDO Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($logFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    error_log("PDO Exception: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Check if this is a user-defined validation error (SQLSTATE P0001)
    if (strpos($e->getCode(), 'P0001') !== false || strpos($e->getMessage(), 'SQLSTATE[P0001]') !== false) {
        // Extract user-friendly error message from the exception
        $message = $e->getMessage();

        // Check for insufficient funds error
        if (strpos($message, 'Insufficient funds in category') !== false) {
            // Extract category name from JSON detail if available
            preg_match('/"category_name"\s*:\s*"([^"]+)"/', $message, $matches);
            $categoryName = isset($matches[1]) ? $matches[1] : 'this category';

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "Insufficient funds in $categoryName. Please add more budget to the category or choose a different category."
            ]);
        } else {
            // Other validation errors
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Validation error: ' . $e->getMessage()
            ]);
        }
    } else {
        // Real database error
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
} catch (Exception $e) {
    file_put_contents($logFile, "General Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($logFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    error_log("General Exception: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
