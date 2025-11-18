<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

header('Content-Type: application/json');

try {
    $ledger_uuid = $_GET['ledger_uuid'] ?? '';
    $days_back = isset($_GET['days_back']) ? (int)$_GET['days_back'] : 30;
    $days_ahead = isset($_GET['days_ahead']) ? (int)$_GET['days_ahead'] : 30;

    if (empty($ledger_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing ledger_uuid parameter']);
        exit;
    }

    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Call the API function to get pending obligation payments
    $stmt = $db->prepare("SELECT * FROM api.get_pending_obligation_payments(?, ?, ?)");
    $stmt->execute([$ledger_uuid, $days_back, $days_ahead]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'payments' => $payments
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
