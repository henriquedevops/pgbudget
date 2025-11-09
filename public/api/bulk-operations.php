<?php
/**
 * Bulk Operations API Endpoint
 * Handles bulk actions on multiple transactions
 * Phase 6.3: Bulk Operations
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Set JSON headers
header('Content-Type: application/json');

// Ensure user is authenticated
requireAuth();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
if (!isset($input['action']) || !isset($input['transaction_uuids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: action, transaction_uuids']);
    exit;
}

$action = sanitizeInput($input['action']);
$transactionUuids = $input['transaction_uuids'];

// Validate transaction_uuids is an array
if (!is_array($transactionUuids) || empty($transactionUuids)) {
    http_response_code(400);
    echo json_encode(['error' => 'transaction_uuids must be a non-empty array']);
    exit;
}

// Sanitize all UUIDs
$transactionUuids = array_map('sanitizeInput', $transactionUuids);

try {
    $db = getDBConnection();
    $count = 0;

    switch ($action) {
        case 'categorize':
            if (!isset($input['category_uuid'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing category_uuid for categorize action']);
                exit;
            }

            $categoryUuid = sanitizeInput($input['category_uuid']);

            // Convert PHP array to PostgreSQL array format
            $pgArray = '{' . implode(',', array_map(function($uuid) {
                return '"' . str_replace('"', '\"', $uuid) . '"';
            }, $transactionUuids)) . '}';

            $stmt = $db->prepare("SELECT api.bulk_categorize_transactions($1::text[], $2)");
            $stmt->execute([$pgArray, $categoryUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['bulk_categorize_transactions'];
            break;

        case 'delete':
            // Convert PHP array to PostgreSQL array format
            $pgArray = '{' . implode(',', array_map(function($uuid) {
                return '"' . str_replace('"', '\"', $uuid) . '"';
            }, $transactionUuids)) . '}';

            $stmt = $db->prepare("SELECT api.bulk_delete_transactions($1::text[])");
            $stmt->execute([$pgArray]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['bulk_delete_transactions'];
            break;

        case 'edit_date':
            if (!isset($input['new_date'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing new_date for edit_date action']);
                exit;
            }

            $newDate = sanitizeInput($input['new_date']);

            // Convert PHP array to PostgreSQL array format
            $pgArray = '{' . implode(',', array_map(function($uuid) {
                return '"' . str_replace('"', '\"', $uuid) . '"';
            }, $transactionUuids)) . '}';

            $stmt = $db->prepare("SELECT api.bulk_edit_transaction_dates($1::text[], $2::timestamptz)");
            $stmt->execute([$pgArray, $newDate]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['bulk_edit_transaction_dates'];
            break;

        case 'edit_account':
            if (!isset($input['new_account_uuid'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing new_account_uuid for edit_account action']);
                exit;
            }

            $newAccountUuid = sanitizeInput($input['new_account_uuid']);

            // Convert PHP array to PostgreSQL array format
            $pgArray = '{' . implode(',', array_map(function($uuid) {
                return '"' . str_replace('"', '\"', $uuid) . '"';
            }, $transactionUuids)) . '}';

            $stmt = $db->prepare("SELECT api.bulk_edit_transaction_accounts($1::text[], $2)");
            $stmt->execute([$pgArray, $newAccountUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['bulk_edit_transaction_accounts'];
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Supported: categorize, delete, edit_date, edit_account']);
            exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'count' => $count,
        'message' => "$count transaction(s) updated successfully"
    ]);

} catch (PDOException $e) {
    error_log("Bulk operations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Bulk operations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
