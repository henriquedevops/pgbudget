<?php
/**
 * AJAX endpoint for moving money between budget categories
 * Implements YNAB Rule 3: Roll With The Punches
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
$from_category_uuid = $input['from_category_uuid'] ?? '';
$to_category_uuid = $input['to_category_uuid'] ?? '';
$amount = isset($input['amount']) ? parseCurrency($input['amount']) : null;
$date = $input['date'] ?? date('Y-m-d');
$description = $input['description'] ?? '';

if (empty($ledger_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ledger UUID is required']);
    exit;
}

if (empty($from_category_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Source category is required']);
    exit;
}

if (empty($to_category_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Destination category is required']);
    exit;
}

if ($from_category_uuid === $to_category_uuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Source and destination categories must be different']);
    exit;
}

if ($amount === null || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid amount is required']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get category names for description if not provided
    if (empty($description)) {
        $stmt = $db->prepare("
            SELECT a1.name as from_name, a2.name as to_name
            FROM api.accounts a1, api.accounts a2
            WHERE a1.uuid = ? AND a2.uuid = ? AND a1.ledger_uuid = ? AND a2.ledger_uuid = ?
        ");
        $stmt->execute([$from_category_uuid, $to_category_uuid, $ledger_uuid, $ledger_uuid]);
        $categories = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($categories) {
            $description = 'Move money: ' . $categories['from_name'] . ' → ' . $categories['to_name'];
        } else {
            $description = 'Move money between categories';
        }
    }

    // Move money using the API function
    $stmt = $db->prepare("SELECT api.move_between_categories(?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $ledger_uuid,
        $from_category_uuid,
        $to_category_uuid,
        $amount,
        $date,
        $description
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && isset($result['move_between_categories'])) {
        // Get updated budget status for both categories
        $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
        $stmt->execute([$ledger_uuid]);
        $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Find updated categories
        $from_category = null;
        $to_category = null;
        foreach ($all_categories as $cat) {
            if ($cat['category_uuid'] === $from_category_uuid) {
                $from_category = $cat;
            }
            if ($cat['category_uuid'] === $to_category_uuid) {
                $to_category = $cat;
            }
        }

        // Get updated budget totals
        $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
        $stmt->execute([$ledger_uuid]);
        $updated_totals = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'transaction_uuid' => $result['move_between_categories'],
            'message' => 'Successfully moved ' . formatCurrency($amount) . ' between categories',
            'from_category' => $from_category ? [
                'uuid' => $from_category['category_uuid'],
                'name' => $from_category['category_name'],
                'budgeted' => $from_category['budgeted'],
                'budgeted_formatted' => formatCurrency($from_category['budgeted']),
                'activity' => $from_category['activity'],
                'activity_formatted' => formatCurrency($from_category['activity']),
                'balance' => $from_category['balance'],
                'balance_formatted' => formatCurrency($from_category['balance'])
            ] : null,
            'to_category' => $to_category ? [
                'uuid' => $to_category['category_uuid'],
                'name' => $to_category['category_name'],
                'budgeted' => $to_category['budgeted'],
                'budgeted_formatted' => formatCurrency($to_category['budgeted']),
                'activity' => $to_category['activity'],
                'activity_formatted' => formatCurrency($to_category['activity']),
                'balance' => $to_category['balance'],
                'balance_formatted' => formatCurrency($to_category['balance'])
            ] : null,
            'updated_totals' => [
                'left_to_budget' => $updated_totals['left_to_budget'],
                'left_to_budget_formatted' => formatCurrency($updated_totals['left_to_budget']),
                'budgeted' => $updated_totals['budgeted'],
                'budgeted_formatted' => formatCurrency($updated_totals['budgeted'])
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to move money between categories']);
    }

} catch (PDOException $e) {
    error_log("Move money error: " . $e->getMessage());
    http_response_code(500);

    // Parse PostgreSQL error messages for user-friendly output
    $error_message = $e->getMessage();

    if (strpos($error_message, 'Insufficient funds') !== false) {
        // Extract the error message from PostgreSQL
        if (preg_match('/Insufficient funds in category "([^"]+)".*Available: (\d+), Requested: (\d+)/', $error_message, $matches)) {
            echo json_encode([
                'success' => false,
                'error' => sprintf(
                    'Insufficient funds in "%s". Available: %s, Requested: %s',
                    $matches[1],
                    formatCurrency((int)$matches[2]),
                    formatCurrency((int)$matches[3])
                )
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Insufficient funds in source category']);
        }
    } elseif (strpos($error_message, 'not found') !== false) {
        echo json_encode(['success' => false, 'error' => 'Category not found or access denied']);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
}
