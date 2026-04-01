<?php
/**
 * Delete Transaction API Endpoint
 * DELETE /api/delete-transaction.php?uuid=<transaction_uuid>
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$uuid = $_GET['uuid'] ?? ($_POST['uuid'] ?? '');
if (empty($uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing transaction UUID']);
    exit;
}

try {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT api.delete_transaction(?, 'Deleted by user')");
    $stmt->execute([$uuid]);
    $reversal_uuid = $stmt->fetchColumn();

    if ($reversal_uuid) {
        echo json_encode(['success' => true, 'reversal_uuid' => $reversal_uuid]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Transaction not found or already deleted']);
    }
} catch (Exception $e) {
    error_log("Delete Transaction API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
