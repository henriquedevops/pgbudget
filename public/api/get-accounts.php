<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $ledger_uuid = $_GET['ledger'] ?? '';
    $type = $_GET['type'] ?? '';

    if (empty($ledger_uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ledger UUID is required']);
        exit;
    }

    // Build query based on type filter
    if (!empty($type)) {
        $stmt = $db->prepare("
            SELECT account_uuid as uuid, account_name as name, current_balance as balance
            FROM api.get_ledger_balances(?)
            WHERE account_type = ?
            ORDER BY account_name
        ");
        $stmt->execute([$ledger_uuid, $type]);
    } else {
        $stmt = $db->prepare("
            SELECT account_uuid as uuid, account_name as name, current_balance as balance, account_type as type
            FROM api.get_ledger_balances(?)
            ORDER BY account_type, account_name
        ");
        $stmt->execute([$ledger_uuid]);
    }

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'accounts' => $accounts
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
