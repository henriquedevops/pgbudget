<?php
/**
 * Income Sources API Endpoint
 * Handles AJAX requests for income source operations
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
                createIncomeSource($db);
                break;

            case 'update':
                updateIncomeSource($db);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                exit;
        }
    }

    // Handle DELETE requests
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $source_uuid = $_GET['source_uuid'] ?? '';
        if (empty($source_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Source UUID required']);
            exit;
        }
        deleteIncomeSource($db, $source_uuid);
    }

} catch (Exception $e) {
    error_log("Income Sources API Error: " . $e->getMessage());
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
 * Create a new income source
 */
function createIncomeSource($db) {
    $ledger_uuid = $_POST['ledger_uuid'] ?? '';
    $name = $_POST['name'] ?? '';
    $amount = !empty($_POST['amount']) ? $_POST['amount'] : null;
    $start_date = $_POST['start_date'] ?? '';

    // Optional fields
    $income_type = !empty($_POST['income_type']) ? $_POST['income_type'] : 'salary';
    $income_subtype = !empty($_POST['income_subtype']) ? $_POST['income_subtype'] : null;
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $currency = !empty($_POST['currency']) ? $_POST['currency'] : 'BRL';
    $frequency = !empty($_POST['frequency']) ? $_POST['frequency'] : 'monthly';
    $pay_day_of_month = !empty($_POST['pay_day_of_month']) ? $_POST['pay_day_of_month'] : null;
    $occurrence_months = !empty($_POST['occurrence_months']) ? $_POST['occurrence_months'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $employer_name = !empty($_POST['employer_name']) ? $_POST['employer_name'] : null;
    $group_tag = !empty($_POST['group_tag']) ? $_POST['group_tag'] : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;

    // Validate required fields
    if (empty($ledger_uuid) || empty($name) || empty($amount) || empty($start_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: name, amount, and start date are required']);
        exit;
    }

    // Convert amount to cents (bigint)
    $amount_cents = intval(round(floatval($amount) * 100));

    // Parse occurrence_months if provided (comma-separated string to PG array)
    $occurrence_months_array = null;
    if (!empty($occurrence_months)) {
        $occurrence_months_array = '{' . $occurrence_months . '}';
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.create_income_source(
                p_ledger_uuid := ?,
                p_name := ?,
                p_amount := ?::numeric,
                p_start_date := ?::date,
                p_income_type := ?,
                p_income_subtype := ?,
                p_description := ?,
                p_currency := ?,
                p_frequency := ?,
                p_pay_day_of_month := ?::integer,
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
            $amount_cents,
            $start_date,
            $income_type,
            $income_subtype,
            $description,
            $currency,
            $frequency,
            $pay_day_of_month,
            $occurrence_months_array,
            $end_date,
            $default_category_uuid,
            $employer_name,
            $group_tag,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to create income source');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Income source created successfully',
            'income_uuid' => $result['uuid'],
            'income_name' => $result['name']
        ]);

    } catch (Exception $e) {
        error_log("Create Income Source Error: " . $e->getMessage());
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
 * Update an existing income source
 */
function updateIncomeSource($db) {
    $income_uuid = $_POST['income_uuid'] ?? '';
    $name = !empty($_POST['name']) ? $_POST['name'] : null;
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $income_type = !empty($_POST['income_type']) ? $_POST['income_type'] : null;
    $income_subtype = !empty($_POST['income_subtype']) ? $_POST['income_subtype'] : null;
    $amount = !empty($_POST['amount']) ? $_POST['amount'] : null;
    $currency = !empty($_POST['currency']) ? $_POST['currency'] : null;
    $frequency = !empty($_POST['frequency']) ? $_POST['frequency'] : null;
    $pay_day_of_month = !empty($_POST['pay_day_of_month']) ? $_POST['pay_day_of_month'] : null;
    $occurrence_months = !empty($_POST['occurrence_months']) ? $_POST['occurrence_months'] : null;
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $employer_name = !empty($_POST['employer_name']) ? $_POST['employer_name'] : null;
    $group_tag = !empty($_POST['group_tag']) ? $_POST['group_tag'] : null;
    $is_active = isset($_POST['is_active']) ? (($_POST['is_active']) === 'true') : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;

    if (empty($income_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Income source UUID required']);
        exit;
    }

    // Convert amount to cents if provided
    $amount_cents = null;
    if ($amount !== null) {
        $amount_cents = intval(round(floatval($amount) * 100));
    }

    // Parse occurrence_months if provided
    $occurrence_months_array = null;
    if (!empty($occurrence_months)) {
        $occurrence_months_array = '{' . $occurrence_months . '}';
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.update_income_source(
                p_income_uuid := ?,
                p_name := ?,
                p_description := ?,
                p_income_type := ?,
                p_income_subtype := ?,
                p_amount := ?::numeric,
                p_currency := ?,
                p_frequency := ?,
                p_pay_day_of_month := ?::integer,
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
            $income_uuid,
            $name,
            $description,
            $income_type,
            $income_subtype,
            $amount_cents,
            $currency,
            $frequency,
            $pay_day_of_month,
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
            throw new Exception('Failed to update income source');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Income source updated successfully',
            'income_uuid' => $result['uuid'],
            'income_name' => $result['name']
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Delete an income source
 */
function deleteIncomeSource($db, $source_uuid) {
    try {
        $stmt = $db->prepare("SELECT api.delete_income_source(?)");
        $stmt->execute([$source_uuid]);
        $result = $stmt->fetchColumn();

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Income source deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete income source');
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
