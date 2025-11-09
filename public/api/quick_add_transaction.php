<?php
/**
 * Quick Add Transaction API Endpoint
 * Creates a transaction directly from the budget dashboard
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Require authentication
requireAuth();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['ledger_uuid', 'type', 'amount', 'description', 'account_uuid', 'category_uuid', 'date'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || trim($input[$field]) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

$ledger_uuid = sanitizeInput($input['ledger_uuid']);
$type = sanitizeInput($input['type']); // 'inflow' or 'outflow'
$amount_str = sanitizeInput($input['amount']);
$description = sanitizeInput($input['description']);
$account_uuid = sanitizeInput($input['account_uuid']);
$category_uuid = sanitizeInput($input['category_uuid']);
$date = sanitizeInput($input['date']);

// Validate transaction type
if (!in_array($type, ['inflow', 'outflow'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid transaction type. Must be inflow or outflow.']);
    exit;
}

// Parse amount (convert from decimal to cents)
$amount_cents = parseCurrencyToCents($amount_str);

if ($amount_cents <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Verify ledger belongs to user
    $stmt = $db->prepare("SELECT uuid FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ledger not found or access denied']);
        exit;
    }

    // Verify account belongs to ledger
    $stmt = $db->prepare("SELECT uuid, name FROM api.accounts WHERE uuid = ? AND ledger_uuid = ?");
    $stmt->execute([$account_uuid, $ledger_uuid]);
    $account = $stmt->fetch();
    if (!$account) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Account not found or does not belong to this ledger']);
        exit;
    }

    // Verify category belongs to ledger
    $stmt = $db->prepare("SELECT uuid, name FROM api.accounts WHERE uuid = ? AND ledger_uuid = ?");
    $stmt->execute([$category_uuid, $ledger_uuid]);
    $category = $stmt->fetch();
    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Category not found or does not belong to this ledger']);
        exit;
    }

    // Create the transaction using api.add_transaction
    // For inflow: debit account, credit category (usually Income)
    // For outflow: debit category, credit account

    $stmt = $db->prepare("
        SELECT api.add_transaction(
            ?,  -- ledger_uuid
            ?::date,  -- date
            ?,  -- description
            ?,  -- type
            ?,  -- amount (in cents)
            ?,  -- account_uuid
            ?   -- category_uuid
        ) as transaction_uuid
    ");

    $stmt->execute([
        $ledger_uuid,
        $date,
        $description,
        $type,
        $amount_cents,
        $account_uuid,
        $category_uuid
    ]);

    $result = $stmt->fetch();
    $transaction_uuid = $result['transaction_uuid'];

    // Get updated budget totals
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
    $stmt->execute([$ledger_uuid]);
    $budget_totals = $stmt->fetch();

    // Success response
    echo json_encode([
        'success' => true,
        'message' => sprintf(
            'Added %s transaction: %s for %s',
            $type,
            $description,
            formatCurrency($amount_cents)
        ),
        'transaction_uuid' => $transaction_uuid,
        'account_name' => $account['name'],
        'category_name' => $category['name'],
        'amount' => $amount_cents,
        'amount_formatted' => formatCurrency($amount_cents),
        'type' => $type,
        'updated_totals' => [
            'income' => $budget_totals['income'],
            'budgeted' => $budget_totals['budgeted'],
            'left_to_budget' => $budget_totals['left_to_budget'],
            'left_to_budget_formatted' => formatCurrency($budget_totals['left_to_budget'])
        ]
    ]);

} catch (PDOException $e) {
    error_log('Quick add transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
