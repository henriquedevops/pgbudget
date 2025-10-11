<?php
/**
 * AJAX endpoint for quick budget assignment
 * Allows inline editing of category budgets
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Set JSON response header
header('Content-Type: application/json');

// Require authentication
requireAuth();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
$ledger_uuid = $input['ledger_uuid'] ?? '';
$category_uuid = $input['category_uuid'] ?? '';
$amount = isset($input['amount']) ? parseCurrency($input['amount']) : null;
$date = $input['date'] ?? date('Y-m-d');

if (empty($ledger_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ledger UUID is required']);
    exit;
}

if (empty($category_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Category UUID is required']);
    exit;
}

if ($amount === null || $amount < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid amount is required']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get current budget totals
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
    $stmt->execute([$ledger_uuid]);
    $budget_totals = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$budget_totals) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Budget not found']);
        exit;
    }

    // Get category current status and name
    $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
    $stmt->execute([$ledger_uuid]);
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find the category
    $category = null;
    foreach ($all_categories as $cat) {
        if ($cat['category_uuid'] === $category_uuid) {
            $category = $cat;
            break;
        }
    }

    if (!$category) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Category not found']);
        exit;
    }

    // Calculate the difference between desired amount and current budgeted amount
    // $amount is the NEW total budget the user wants
    // $category['budgeted'] is the CURRENT budgeted amount
    $difference = $amount - $category['budgeted'];

    // If difference is 0, nothing to do
    if ($difference == 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Budget amount unchanged',
            'updated_totals' => [
                'left_to_budget' => $budget_totals['left_to_budget'],
                'left_to_budget_formatted' => formatCurrency($budget_totals['left_to_budget']),
                'budgeted' => $budget_totals['budgeted'],
                'budgeted_formatted' => formatCurrency($budget_totals['budgeted'])
            ],
            'updated_category' => [
                'budgeted' => $category['budgeted'],
                'budgeted_formatted' => formatCurrency($category['budgeted']),
                'activity' => $category['activity'],
                'activity_formatted' => formatCurrency($category['activity']),
                'balance' => $category['balance'],
                'balance_formatted' => formatCurrency($category['balance'])
            ]
        ]);
        exit;
    }

    // Check if sufficient funds available (only for positive difference)
    if ($difference > 0 && $difference > $budget_totals['left_to_budget']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Insufficient funds to assign. Available: ' . formatCurrency($budget_totals['left_to_budget'])
        ]);
        exit;
    }

    // Create assignment description
    $description = 'Budget: ' . $category['category_name'];

    // Assign the difference (positive = add, negative = remove)
    $stmt = $db->prepare("SELECT uuid FROM api.assign_to_category(?, ?, ?, ?, ?)");
    $stmt->execute([
        $ledger_uuid,
        $date,
        $description,
        $difference,
        $category_uuid
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['uuid']) {
        // Get updated budget totals
        $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
        $stmt->execute([$ledger_uuid]);
        $updated_totals = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get updated category status
        $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
        $stmt->execute([$ledger_uuid]);
        $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Find the updated category
        $updated_category = null;
        foreach ($all_categories as $cat) {
            if ($cat['category_uuid'] === $category_uuid) {
                $updated_category = $cat;
                break;
            }
        }

        // Create success message based on the change
        $change_msg = $difference > 0
            ? 'Increased budget by ' . formatCurrency($difference)
            : 'Decreased budget by ' . formatCurrency(abs($difference));

        echo json_encode([
            'success' => true,
            'transaction_uuid' => $result['uuid'],
            'message' => $change_msg . ' for ' . $category['category_name'],
            'updated_totals' => [
                'left_to_budget' => $updated_totals['left_to_budget'],
                'left_to_budget_formatted' => formatCurrency($updated_totals['left_to_budget']),
                'budgeted' => $updated_totals['budgeted'],
                'budgeted_formatted' => formatCurrency($updated_totals['budgeted'])
            ],
            'updated_category' => $updated_category ? [
                'budgeted' => $updated_category['budgeted'],
                'budgeted_formatted' => formatCurrency($updated_category['budgeted']),
                'activity' => $updated_category['activity'],
                'activity_formatted' => formatCurrency($updated_category['activity']),
                'balance' => $updated_category['balance'],
                'balance_formatted' => formatCurrency($updated_category['balance'])
            ] : null
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to assign money to category']);
    }

} catch (PDOException $e) {
    error_log("Quick assign error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
