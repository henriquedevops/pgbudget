<?php
/**
 * Obligations API Endpoint
 * Handles AJAX requests for obligation operations
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create':
                createObligation($db);
                break;

            case 'update':
                updateObligation($db);
                break;

            case 'mark_paid':
                markPaymentAsPaid($db);
                break;

            case 'edit_payment':
                editPayment($db);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                exit;
        }
    }

    // Handle DELETE requests
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $obligation_uuid = $_GET['obligation_uuid'] ?? '';
        if (empty($obligation_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Obligation UUID required']);
            exit;
        }
        deleteObligation($db, $obligation_uuid);
    }

} catch (Exception $e) {
    // Log the full error for debugging
    error_log("Obligations API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString() // Include trace in development
    ]);
    exit;
}

/**
 * Create a new obligation
 */
function createObligation($db) {
    // Required fields
    $ledger_uuid = $_POST['ledger_uuid'] ?? '';
    $name = $_POST['name'] ?? '';
    $payee_name = $_POST['payee_name'] ?? '';
    $obligation_type = $_POST['obligation_type'] ?? '';
    $frequency = $_POST['frequency'] ?? '';
    $is_fixed_amount = ($_POST['is_fixed_amount'] ?? 'true') === 'true';
    $start_date = $_POST['start_date'] ?? '';

    // Amount fields - convert empty strings to null
    $fixed_amount = !empty($_POST['fixed_amount']) ? $_POST['fixed_amount'] : null;
    $estimated_amount = !empty($_POST['estimated_amount']) ? $_POST['estimated_amount'] : null;
    $amount_range_min = !empty($_POST['amount_range_min']) ? $_POST['amount_range_min'] : null;
    $amount_range_max = !empty($_POST['amount_range_max']) ? $_POST['amount_range_max'] : null;

    // Frequency fields - convert empty strings to null
    $due_day_of_month = !empty($_POST['due_day_of_month']) ? $_POST['due_day_of_month'] : null;
    $due_day_of_week = !empty($_POST['due_day_of_week']) ? $_POST['due_day_of_week'] : null;
    $custom_frequency_days = !empty($_POST['custom_frequency_days']) ? $_POST['custom_frequency_days'] : null;
    $due_months = !empty($_POST['due_months']) ? $_POST['due_months'] : null;

    // Optional fields - convert empty strings to null
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $obligation_subtype = !empty($_POST['obligation_subtype']) ? $_POST['obligation_subtype'] : null;
    $default_payment_account_uuid = !empty($_POST['default_payment_account_uuid']) ? $_POST['default_payment_account_uuid'] : null;
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $account_number = !empty($_POST['account_number']) ? $_POST['account_number'] : null;
    $reminder_days_before = !empty($_POST['reminder_days_before']) ? $_POST['reminder_days_before'] : 3;
    $grace_period_days = !empty($_POST['grace_period_days']) ? $_POST['grace_period_days'] : 0;
    $late_fee_amount = !empty($_POST['late_fee_amount']) ? $_POST['late_fee_amount'] : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    // Validate required fields
    if (empty($ledger_uuid) || empty($name) || empty($payee_name) || empty($obligation_type) || empty($frequency) || empty($start_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    // Validate amount
    if ($is_fixed_amount && (empty($fixed_amount) || $fixed_amount <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Fixed amount is required and must be greater than 0']);
        exit;
    }

    if (!$is_fixed_amount && (empty($estimated_amount) || $estimated_amount <= 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Estimated amount is required and must be greater than 0']);
        exit;
    }

    // Parse due_months if provided (comma-separated string)
    $due_months_array = null;
    if (!empty($due_months)) {
        $due_months_array = '{' . $due_months . '}';
    }

    try {
        $db->beginTransaction();

        // Call the create_obligation API function
        $stmt = $db->prepare("
            SELECT * FROM api.create_obligation(
                p_ledger_uuid := ?,
                p_name := ?,
                p_payee_name := ?,
                p_obligation_type := ?,
                p_frequency := ?,
                p_is_fixed_amount := ?,
                p_start_date := ?::date,
                p_fixed_amount := ?::decimal,
                p_estimated_amount := ?::decimal,
                p_due_day_of_month := ?::integer,
                p_due_day_of_week := ?::integer,
                p_due_months := ?::integer[],
                p_custom_frequency_days := ?::integer,
                p_default_payment_account_uuid := ?,
                p_default_category_uuid := ?,
                p_obligation_subtype := ?,
                p_description := ?,
                p_account_number := ?,
                p_reminder_days_before := ?::integer,
                p_grace_period_days := ?::integer,
                p_notes := ?
            )
        ");

        $stmt->execute([
            $ledger_uuid,
            $name,
            $payee_name,
            $obligation_type,
            $frequency,
            $is_fixed_amount ? 'true' : 'false',
            $start_date,
            $fixed_amount, // null if not set
            $estimated_amount, // null if not set
            $due_day_of_month, // null if not set
            $due_day_of_week, // null if not set
            $due_months_array, // null if not set
            $custom_frequency_days, // null if not set
            $default_payment_account_uuid, // already null if empty
            $default_category_uuid, // already null if empty
            $obligation_subtype,
            $description,
            $account_number,
            $reminder_days_before,
            $grace_period_days,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to create obligation');
        }

        // Update end_date if provided
        if (!empty($end_date)) {
            $stmt = $db->prepare("
                UPDATE data.obligations
                SET end_date = ?::date
                WHERE uuid = ?
                AND user_data = utils.get_user()
            ");
            $stmt->execute([$end_date, $result['uuid']]);
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Obligation created successfully',
            'obligation_uuid' => $result['uuid'],
            'obligation_name' => $result['name']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        // Log the full error for debugging
        error_log("Create Obligation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString() // Include trace in development
        ]);
    }
}

/**
 * Update an existing obligation
 *
 * Supports future-amount scheduling via amount_timing field:
 *   'immediately' — update current amount now (and clear any pending future amount)
 *   'future'      — schedule the new amount for a specific effective date
 *   'clear'       — remove a previously scheduled future amount only
 */
function updateObligation($db) {
    $obligation_uuid = $_POST['obligation_uuid'] ?? '';
    $name = $_POST['name'] ?? null;
    $description = $_POST['description'] ?? null;
    $payee_name = $_POST['payee_name'] ?? null;
    $reminder_days_before = $_POST['reminder_days_before'] ?? null;
    $grace_period_days = $_POST['grace_period_days'] ?? null;
    $is_active = isset($_POST['is_active']) ? (($_POST['is_active'] ?? 'true') === 'true') : null;
    $is_paused = isset($_POST['is_paused']) ? (($_POST['is_paused'] ?? 'false') === 'true') : null;
    $notes = $_POST['notes'] ?? null;

    // Amount timing logic
    $amount_timing = $_POST['amount_timing'] ?? 'immediately';
    $raw_fixed    = !empty($_POST['fixed_amount'])    ? $_POST['fixed_amount']    : null;
    $raw_estimated = !empty($_POST['estimated_amount']) ? $_POST['estimated_amount'] : null;
    $is_fixed_amount = isset($_POST['is_fixed_amount']) ? (($_POST['is_fixed_amount'] ?? 'true') === 'true') : null;

    // Determine which params to pass based on timing
    $p_fixed_amount          = null;
    $p_estimated_amount      = null;
    $p_future_fixed          = null;
    $p_future_estimated      = null;
    $p_future_effective_date = null;
    $p_clear_future          = 'false';

    if ($amount_timing === 'noop') {
        // Amount field was not changed — don't touch any amount columns
        // (all p_* amount vars remain null, which means COALESCE keeps existing values)
    } elseif ($amount_timing === 'immediately') {
        // Apply immediately: update current amount, clear any future schedule
        $p_fixed_amount     = $raw_fixed;
        $p_estimated_amount = $raw_estimated;
        $p_clear_future     = 'true';
    } elseif ($amount_timing === 'future') {
        // Schedule for future: keep current amount, store as future amount
        $effective_date = !empty($_POST['future_amount_effective_date'])
            ? $_POST['future_amount_effective_date'] : null;
        if (empty($effective_date)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Effective date is required for scheduled amount changes']);
            exit;
        }
        if ($is_fixed_amount) {
            $p_future_fixed = $raw_fixed;
        } else {
            $p_future_estimated = $raw_estimated;
        }
        $p_future_effective_date = $effective_date;
    } elseif ($amount_timing === 'clear') {
        // Just clear the scheduled future amount, don't change current amount
        $p_clear_future = 'true';
    }

    if (empty($obligation_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Obligation UUID required']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.update_obligation(
                p_obligation_uuid              := ?,
                p_name                         := ?,
                p_description                  := ?,
                p_payee_name                   := ?,
                p_fixed_amount                 := ?::decimal,
                p_estimated_amount             := ?::decimal,
                p_reminder_days_before         := ?::integer,
                p_grace_period_days            := ?::integer,
                p_is_active                    := ?,
                p_is_paused                    := ?,
                p_notes                        := ?,
                p_future_fixed_amount          := ?::decimal,
                p_future_estimated_amount      := ?::decimal,
                p_future_amount_effective_date := ?::date,
                p_clear_future_amount          := ?::boolean
            )
        ");

        $stmt->execute([
            $obligation_uuid,
            $name,
            $description,
            $payee_name,
            $p_fixed_amount,
            $p_estimated_amount,
            $reminder_days_before,
            $grace_period_days,
            $is_active !== null ? ($is_active ? 'true' : 'false') : null,
            $is_paused !== null ? ($is_paused ? 'true' : 'false') : null,
            $notes,
            $p_future_fixed,
            $p_future_estimated,
            $p_future_effective_date,
            $p_clear_future,
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to update obligation');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Obligation updated successfully',
            'obligation_uuid' => $result['uuid'],
            'obligation_name' => $result['name']
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Edit an existing payment
 */
function editPayment($db) {
    $payment_uuid = $_POST['payment_uuid'] ?? '';
    $actual_amount = $_POST['actual_amount'] ?? '';
    $paid_date = $_POST['paid_date'] ?? '';
    $payment_method = $_POST['payment_method'] ?? null;
    $confirmation_number = $_POST['confirmation_number'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $status = $_POST['status'] ?? null;

    if (empty($payment_uuid) || empty($actual_amount)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Update the payment record
        $stmt = $db->prepare("
            UPDATE data.obligation_payments
            SET
                actual_amount_paid = ?::decimal,
                paid_date = ?::date,
                payment_method = ?,
                confirmation_number = ?,
                notes = ?,
                status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE uuid = ?
            AND user_data = utils.get_user()
        ");

        $stmt->execute([
            $actual_amount,
            $paid_date,
            $payment_method,
            $confirmation_number,
            $notes,
            $status,
            $payment_uuid
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Payment not found or access denied');
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payment updated successfully'
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        // Log the full error for debugging
        error_log("Create Obligation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString() // Include trace in development
        ]);
    }
}

/**
 * Mark an obligation payment as paid
 */
function markPaymentAsPaid($db) {
    $payment_uuid = $_POST['payment_uuid'] ?? '';
    $paid_date = $_POST['paid_date'] ?? '';
    $actual_amount = $_POST['actual_amount'] ?? '';
    $notes = $_POST['notes'] ?? null;
    $payment_method = $_POST['payment_method'] ?? null;
    $confirmation_number = $_POST['confirmation_number'] ?? null;
    $create_transaction = isset($_POST['create_transaction']) && $_POST['create_transaction'] === '1';

    if (empty($payment_uuid) || empty($paid_date) || empty($actual_amount)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Mark the payment as paid
        $stmt = $db->prepare("
            SELECT * FROM api.mark_obligation_paid(
                p_payment_uuid := ?,
                p_paid_date := ?::date,
                p_actual_amount := ?::decimal,
                p_transaction_uuid := null,
                p_payment_account_uuid := null,
                p_payment_method := ?,
                p_confirmation_number := ?,
                p_notes := ?
            )
        ");
        $stmt->execute([
            $payment_uuid,
            $paid_date,
            $actual_amount,
            $payment_method,
            $confirmation_number,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to mark payment as paid');
        }

        // TODO: If create_transaction is true, create a transaction
        // This will be implemented in Phase 5 (Transaction Integration)

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payment marked as paid successfully',
            'payment_uuid' => $result['payment_uuid'],
            'status' => $result['status'],
            'next_due_date' => $result['next_due_date']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        // Log the full error for debugging
        error_log("Create Obligation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString() // Include trace in development
        ]);
    }
}

/**
 * Delete an obligation
 */
function deleteObligation($db, $obligation_uuid) {
    try {
        $stmt = $db->prepare("SELECT api.delete_obligation(?)");
        $stmt->execute([$obligation_uuid]);
        $result = $stmt->fetchColumn();

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Obligation deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete obligation');
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
