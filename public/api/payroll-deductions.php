<?php
/**
 * Payroll Deductions API Endpoint
 * Handles AJAX requests for payroll deduction operations
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
                createPayrollDeduction($db);
                break;

            case 'update':
                updatePayrollDeduction($db);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                exit;
        }
    }

    // Handle DELETE requests
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $deduction_uuid = $_GET['deduction_uuid'] ?? '';
        if (empty($deduction_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Deduction UUID required']);
            exit;
        }
        deletePayrollDeduction($db, $deduction_uuid);
    }

} catch (Exception $e) {
    error_log("Payroll Deductions API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit;
}

/**
 * Create a new payroll deduction
 */
function createPayrollDeduction($db) {
    $ledger_uuid = $_POST['ledger_uuid'] ?? '';
    $name = $_POST['name'] ?? '';
    $deduction_type = !empty($_POST['deduction_type']) ? $_POST['deduction_type'] : 'other';
    $start_date = $_POST['start_date'] ?? '';

    // Boolean fields
    $is_fixed_amount = ($_POST['is_fixed_amount'] ?? 'true') === 'true';
    $is_percentage = ($_POST['is_percentage'] ?? 'false') === 'true';

    // Amount fields
    $fixed_amount = !empty($_POST['fixed_amount']) ? intval(round(floatval($_POST['fixed_amount']) * 100)) : null;
    $estimated_amount = !empty($_POST['estimated_amount']) ? intval(round(floatval($_POST['estimated_amount']) * 100)) : null;
    $percentage_value = !empty($_POST['percentage_value']) ? $_POST['percentage_value'] : null;
    $percentage_base = !empty($_POST['percentage_base']) ? $_POST['percentage_base'] : null;

    // Optional fields
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $currency = !empty($_POST['currency']) ? $_POST['currency'] : 'BRL';
    $frequency = !empty($_POST['frequency']) ? $_POST['frequency'] : 'monthly';
    $occurrence_months = !empty($_POST['occurrence_months']) ? $_POST['occurrence_months'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $employer_name = !empty($_POST['employer_name']) ? $_POST['employer_name'] : null;
    $group_tag = !empty($_POST['group_tag']) ? $_POST['group_tag'] : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;

    // Validate required fields
    if (empty($ledger_uuid) || empty($name) || empty($start_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: name and start date are required']);
        exit;
    }

    // Parse occurrence_months if provided
    $occurrence_months_array = null;
    if (!empty($occurrence_months)) {
        $occurrence_months_array = '{' . $occurrence_months . '}';
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.create_payroll_deduction(
                p_ledger_uuid := ?,
                p_name := ?,
                p_deduction_type := ?,
                p_start_date := ?::date,
                p_is_fixed_amount := ?::boolean,
                p_fixed_amount := ?::numeric,
                p_estimated_amount := ?::numeric,
                p_is_percentage := ?::boolean,
                p_percentage_value := ?::numeric,
                p_percentage_base := ?,
                p_description := ?,
                p_currency := ?,
                p_frequency := ?,
                p_occurrence_months := ?::integer[],
                p_end_date := ?::date,
                p_default_category_uuid := ?,
                p_employer_name := ?,
                p_group_tag := ?,
                p_notes := ?
            )
        ");

        $stmt->execute([
            $ledger_uuid,
            $name,
            $deduction_type,
            $start_date,
            $is_fixed_amount ? 'true' : 'false',
            $fixed_amount,
            $estimated_amount,
            $is_percentage ? 'true' : 'false',
            $percentage_value,
            $percentage_base,
            $description,
            $currency,
            $frequency,
            $occurrence_months_array,
            $end_date,
            $default_category_uuid,
            $employer_name,
            $group_tag,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to create payroll deduction');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Payroll deduction created successfully',
            'deduction_uuid' => $result['uuid'],
            'deduction_name' => $result['name']
        ]);

    } catch (Exception $e) {
        error_log("Create Payroll Deduction Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Update an existing payroll deduction
 */
function updatePayrollDeduction($db) {
    $deduction_uuid = $_POST['deduction_uuid'] ?? '';
    $name = !empty($_POST['name']) ? $_POST['name'] : null;
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $deduction_type = !empty($_POST['deduction_type']) ? $_POST['deduction_type'] : null;

    // Boolean fields
    $is_fixed_amount = isset($_POST['is_fixed_amount']) ? (($_POST['is_fixed_amount']) === 'true') : null;
    $is_percentage = isset($_POST['is_percentage']) ? (($_POST['is_percentage']) === 'true') : null;

    // Amount fields - convert to cents when present
    $fixed_amount = !empty($_POST['fixed_amount']) ? intval(round(floatval($_POST['fixed_amount']) * 100)) : null;
    $estimated_amount = !empty($_POST['estimated_amount']) ? intval(round(floatval($_POST['estimated_amount']) * 100)) : null;
    $percentage_value = !empty($_POST['percentage_value']) ? $_POST['percentage_value'] : null;
    $percentage_base = !empty($_POST['percentage_base']) ? $_POST['percentage_base'] : null;

    // Optional fields
    $currency = !empty($_POST['currency']) ? $_POST['currency'] : null;
    $frequency = !empty($_POST['frequency']) ? $_POST['frequency'] : null;
    $occurrence_months = !empty($_POST['occurrence_months']) ? $_POST['occurrence_months'] : null;
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $employer_name = !empty($_POST['employer_name']) ? $_POST['employer_name'] : null;
    $group_tag = !empty($_POST['group_tag']) ? $_POST['group_tag'] : null;
    $is_active = isset($_POST['is_active']) ? (($_POST['is_active']) === 'true') : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;

    if (empty($deduction_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Deduction UUID required']);
        exit;
    }

    // Parse occurrence_months if provided
    $occurrence_months_array = null;
    if (!empty($occurrence_months)) {
        $occurrence_months_array = '{' . $occurrence_months . '}';
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.update_payroll_deduction(
                p_deduction_uuid := ?,
                p_name := ?,
                p_description := ?,
                p_deduction_type := ?,
                p_is_fixed_amount := ?::boolean,
                p_fixed_amount := ?::numeric,
                p_estimated_amount := ?::numeric,
                p_is_percentage := ?::boolean,
                p_percentage_value := ?::numeric,
                p_percentage_base := ?,
                p_currency := ?,
                p_frequency := ?,
                p_occurrence_months := ?::integer[],
                p_start_date := ?::date,
                p_end_date := ?::date,
                p_default_category_uuid := ?,
                p_employer_name := ?,
                p_group_tag := ?,
                p_is_active := ?::boolean,
                p_notes := ?
            )
        ");

        $stmt->execute([
            $deduction_uuid,
            $name,
            $description,
            $deduction_type,
            $is_fixed_amount !== null ? ($is_fixed_amount ? 'true' : 'false') : null,
            $fixed_amount,
            $estimated_amount,
            $is_percentage !== null ? ($is_percentage ? 'true' : 'false') : null,
            $percentage_value,
            $percentage_base,
            $currency,
            $frequency,
            $occurrence_months_array,
            $start_date,
            $end_date,
            $default_category_uuid,
            $employer_name,
            $group_tag,
            $is_active !== null ? ($is_active ? 'true' : 'false') : null,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to update payroll deduction');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Payroll deduction updated successfully',
            'deduction_uuid' => $result['uuid'],
            'deduction_name' => $result['name']
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Delete a payroll deduction
 */
function deletePayrollDeduction($db, $deduction_uuid) {
    try {
        $stmt = $db->prepare("SELECT api.delete_payroll_deduction(?)");
        $stmt->execute([$deduction_uuid]);
        $result = $stmt->fetchColumn();

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Payroll deduction deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete payroll deduction');
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
