<?php
/**
 * Account Deletion API Endpoint
 * Handles soft deletion of accounts with validation
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Validate input
    if (empty($input['account_uuid'])) {
        throw new Exception('Missing required field: account_uuid');
    }

    $account_uuid = $input['account_uuid'];

    // Check if we should perform pre-check first
    if (isset($input['precheck']) && $input['precheck'] === true) {
        // Get account ID first
        $stmt = $db->prepare("
            SELECT id, name, type
            FROM data.accounts
            WHERE uuid = ?
            AND user_data = utils.get_user()
            AND deleted_at IS NULL
        ");
        $stmt->execute([$account_uuid]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            echo json_encode([
                'success' => false,
                'error' => 'Account not found or already deleted'
            ]);
            exit;
        }

        // Run pre-check
        $stmt = $db->prepare("SELECT utils.can_delete_account(?)");
        $stmt->execute([$account['id']]);
        $result = $stmt->fetchColumn();
        $check_result = json_decode($result, true);

        echo json_encode([
            'success' => true,
            'account_name' => $account['name'],
            'account_type' => $account['type'],
            'can_delete' => $check_result['can_delete'],
            'reason' => $check_result['reason'] ?? null,
            'warnings' => $check_result['warnings'] ?? []
        ]);
        exit;
    }

    // Perform actual deletion
    $stmt = $db->prepare("SELECT api.delete_account(?)");
    $stmt->execute([$account_uuid]);
    $result = $stmt->fetchColumn();
    $delete_result = json_decode($result, true);

    if ($delete_result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $delete_result['message'],
            'deleted_transactions' => $delete_result['deleted_transactions'] ?? 0,
            'warnings' => $delete_result['warnings'] ?? []
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $delete_result['error']
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
