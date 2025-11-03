<?php
/**
 * Add Transaction with Installment Plan API Endpoint
 *
 * Handles atomic creation of a transaction and its associated installment plan
 * Part of transaction-installment integration (Phase 1)
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
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

// Validate required transaction fields
if (!$data || !isset($data['ledger_uuid'], $data['type'], $data['amount'], $data['date'], $data['description'], $data['account'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required transaction fields']);
    exit;
}

// Extract transaction data
$ledger_uuid = sanitizeInput($data['ledger_uuid']);
$type = sanitizeInput($data['type']);
$amount = parseCurrency($data['amount']);
$date = sanitizeInput($data['date']);
$description = sanitizeInput($data['description']);
$account_uuid = sanitizeInput($data['account']);
$category_uuid = isset($data['category']) && !empty($data['category']) ? sanitizeInput($data['category']) : null;
$payee_name = isset($data['payee']) && !empty($data['payee']) ? sanitizeInput($data['payee']) : null;

// Extract installment data (optional)
$create_installment = isset($data['installment']) && isset($data['installment']['enabled']) && $data['installment']['enabled'] === true;
$installment = $data['installment'] ?? null;

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

// Validate installment data if enabled
if ($create_installment) {
    // Only allow installments for outflows
    if ($type !== 'outflow') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Installment plans can only be created for expense transactions']);
        exit;
    }

    if (!isset($installment['number_of_installments']) || $installment['number_of_installments'] < 2 || $installment['number_of_installments'] > 36) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Number of installments must be between 2 and 36']);
        exit;
    }

    if (empty($installment['start_date'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Installment start date is required']);
        exit;
    }

    // Validate start date format
    $startDateObj = DateTime::createFromFormat('Y-m-d', $installment['start_date']);
    if (!$startDateObj || $startDateObj->format('Y-m-d') !== $installment['start_date']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid installment start date format']);
        exit;
    }

    // Validate frequency
    $frequency = $installment['frequency'] ?? 'monthly';
    if (!in_array($frequency, ['monthly', 'bi-weekly', 'weekly'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid installment frequency']);
        exit;
    }
}

try {
    $db = getDbConnection();

    // Set user context
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$userId]);

    // Begin database transaction for atomic operation
    $db->beginTransaction();

    try {
        // Step 1: Create the transaction
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

        $transaction_result = $stmt->fetch();

        if (!$transaction_result || !$transaction_result[0]) {
            throw new Exception('Failed to create transaction');
        }

        $transaction_uuid = $transaction_result[0];

        $response = [
            'success' => true,
            'message' => 'Transaction added successfully!',
            'transaction_uuid' => $transaction_uuid
        ];

        // Step 2: Create installment plan if requested
        if ($create_installment) {
            // Get IDs for installment plan creation
            $stmt = $db->prepare("SELECT id FROM data.ledgers WHERE uuid = ?");
            $stmt->execute([$ledger_uuid]);
            $ledger = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ledger) {
                throw new Exception('Ledger not found');
            }

            $stmt = $db->prepare("SELECT id, type FROM data.accounts WHERE uuid = ?");
            $stmt->execute([$account_uuid]);
            $cc_account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cc_account) {
                throw new Exception('Account not found');
            }

            // Verify account is a liability (credit card)
            if ($cc_account['type'] !== 'liability') {
                throw new Exception('Installment plans can only be created for credit card (liability) accounts');
            }

            $category_id = null;
            if (!empty($category_uuid)) {
                $stmt = $db->prepare("SELECT id FROM data.accounts WHERE uuid = ?");
                $stmt->execute([$category_uuid]);
                $category = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($category) {
                    $category_id = $category['id'];
                }
            }

            $stmt = $db->prepare("SELECT id FROM data.transactions WHERE uuid = ?");
            $stmt->execute([$transaction_uuid]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                throw new Exception('Transaction not found after creation');
            }

            // Calculate installment amounts with proper rounding
            $purchase_amount = floatval($amount);
            $num_installments = intval($installment['number_of_installments']);
            $installment_amount = round($purchase_amount / $num_installments, 2);

            // Adjust last installment to account for rounding differences
            $total_scheduled = $installment_amount * ($num_installments - 1);
            $last_installment = round($purchase_amount - $total_scheduled, 2);

            // Insert installment plan
            $stmt = $db->prepare("
                INSERT INTO data.installment_plans (
                    ledger_id, original_transaction_id, purchase_amount,
                    purchase_date, description, credit_card_account_id,
                    number_of_installments, installment_amount, frequency,
                    start_date, category_account_id, notes, user_data
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id, uuid
            ");

            $notes = isset($installment['notes']) ? $installment['notes'] : null;

            $stmt->execute([
                $ledger['id'],
                $transaction['id'],
                $purchase_amount,
                $date, // Use transaction date as purchase date
                $description,
                $cc_account['id'],
                $num_installments,
                $installment_amount,
                $frequency,
                $installment['start_date'],
                $category_id,
                $notes,
                $userId
            ]);

            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            // Generate installment schedule
            $schedules = [];
            $current_date = new DateTime($installment['start_date']);

            for ($i = 1; $i <= $num_installments; $i++) {
                // Use adjusted amount for last installment
                $schedule_amount = ($i == $num_installments) ? $last_installment : $installment_amount;

                $stmt = $db->prepare("
                    INSERT INTO data.installment_schedules (
                        installment_plan_id, installment_number,
                        due_date, scheduled_amount, user_data
                    ) VALUES (?, ?, ?, ?, ?)
                    RETURNING id, uuid
                ");

                $stmt->execute([
                    $plan['id'],
                    $i,
                    $current_date->format('Y-m-d'),
                    $schedule_amount,
                    $userId
                ]);

                $schedule_item = $stmt->fetch(PDO::FETCH_ASSOC);
                $schedules[] = [
                    'installment_number' => $i,
                    'due_date' => $current_date->format('Y-m-d'),
                    'scheduled_amount' => $schedule_amount,
                    'uuid' => $schedule_item['uuid']
                ];

                // Calculate next date based on frequency
                switch ($frequency) {
                    case 'weekly':
                        $current_date->modify('+1 week');
                        break;
                    case 'bi-weekly':
                        $current_date->modify('+2 weeks');
                        break;
                    case 'monthly':
                    default:
                        $current_date->modify('+1 month');
                        break;
                }
            }

            $response['installment_plan'] = [
                'uuid' => $plan['uuid'],
                'schedule' => $schedules
            ];
            $response['message'] = 'Transaction and installment plan created successfully!';
        }

        // Commit the database transaction
        $db->commit();

        echo json_encode($response);

    } catch (Exception $e) {
        // Rollback on any error
        $db->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
