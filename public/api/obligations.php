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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

    // Amount fields
    $fixed_amount = $_POST['fixed_amount'] ?? null;
    $estimated_amount = $_POST['estimated_amount'] ?? null;
    $amount_range_min = $_POST['amount_range_min'] ?? null;
    $amount_range_max = $_POST['amount_range_max'] ?? null;

    // Frequency fields
    $due_day_of_month = $_POST['due_day_of_month'] ?? null;
    $due_day_of_week = $_POST['due_day_of_week'] ?? null;
    $custom_frequency_days = $_POST['custom_frequency_days'] ?? null;
    $due_months = $_POST['due_months'] ?? null;

    // Optional fields
    $description = $_POST['description'] ?? null;
    $obligation_subtype = $_POST['obligation_subtype'] ?? null;
    $default_payment_account_uuid = $_POST['default_payment_account_uuid'] ?? null;
    $default_category_uuid = $_POST['default_category_uuid'] ?? null;
    $account_number = $_POST['account_number'] ?? null;
    $reminder_days_before = $_POST['reminder_days_before'] ?? 3;
    $grace_period_days = $_POST['grace_period_days'] ?? 0;
    $late_fee_amount = $_POST['late_fee_amount'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $end_date = $_POST['end_date'] ?? null;

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
            $fixed_amount,
            $estimated_amount,
            $due_day_of_month,
            $due_day_of_week,
            $due_months_array,
            $custom_frequency_days,
            $default_payment_account_uuid ?: null,
            $default_category_uuid ?: null,
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
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Update an existing obligation
 */
function updateObligation($db) {
    $obligation_uuid = $_POST['obligation_uuid'] ?? '';
    $name = $_POST['name'] ?? null;
    $description = $_POST['description'] ?? null;
    $payee_name = $_POST['payee_name'] ?? null;
    $is_fixed_amount = isset($_POST['is_fixed_amount']) ? (($_POST['is_fixed_amount'] ?? 'true') === 'true') : null;
    $fixed_amount = $_POST['fixed_amount'] ?? null;
    $estimated_amount = $_POST['estimated_amount'] ?? null;
    $reminder_days_before = $_POST['reminder_days_before'] ?? null;
    $grace_period_days = $_POST['grace_period_days'] ?? null;
    $is_active = isset($_POST['is_active']) ? (($_POST['is_active'] ?? 'true') === 'true') : null;
    $is_paused = isset($_POST['is_paused']) ? (($_POST['is_paused'] ?? 'false') === 'true') : null;
    $notes = $_POST['notes'] ?? null;

    if (empty($obligation_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Obligation UUID required']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.update_obligation(
                p_obligation_uuid := ?,
                p_name := ?,
                p_description := ?,
                p_payee_name := ?,
                p_fixed_amount := ?::decimal,
                p_estimated_amount := ?::decimal,
                p_reminder_days_before := ?::integer,
                p_grace_period_days := ?::integer,
                p_is_active := ?,
                p_is_paused := ?,
                p_notes := ?
            )
        ");

        $stmt->execute([
            $obligation_uuid,
            $name,
            $description,
            $payee_name,
            $fixed_amount,
            $estimated_amount,
            $reminder_days_before,
            $grace_period_days,
            $is_active !== null ? ($is_active ? 'true' : 'false') : null,
            $is_paused !== null ? ($is_paused ? 'true' : 'false') : null,
            $notes
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
 * Mark an obligation payment as paid
 */
function markPaymentAsPaid($db) {
    $payment_uuid = $_POST['payment_uuid'] ?? '';
    $paid_date = $_POST['paid_date'] ?? '';
    $actual_amount = $_POST['actual_amount'] ?? '';
    $notes = $_POST['notes'] ?? null;
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
                p_payment_method := null,
                p_confirmation_number := null,
                p_notes := ?
            )
        ");
        $stmt->execute([
            $payment_uuid,
            $paid_date,
            $actual_amount,
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
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
