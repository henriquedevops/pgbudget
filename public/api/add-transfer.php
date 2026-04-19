<?php
/**
 * Add Transfer API Endpoint
 * Records an asset-to-asset fund movement (does not affect P&L).
 */

session_start();

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data || !isset($data['ledger_uuid'], $data['amount'], $data['date'], $data['description'], $data['source_account'], $data['destination_account'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$ledger_uuid      = sanitizeInput($data['ledger_uuid']);
$amount           = parseCurrency($data['amount']);
$date             = sanitizeInput($data['date']);
$description      = sanitizeInput($data['description']);
$source_uuid      = sanitizeInput($data['source_account']);
$destination_uuid = sanitizeInput($data['destination_account']);

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

if ($source_uuid === $destination_uuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Source and destination accounts must be different']);
    exit;
}

try {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT api.add_transfer(?, ?, ?, ?, ?, ?)");
    $stmt->execute([$ledger_uuid, $date, $description, $amount, $source_uuid, $destination_uuid]);
    $result = $stmt->fetch();

    if ($result) {
        echo json_encode([
            'success'          => true,
            'message'          => 'Transfer recorded successfully!',
            'transaction_uuid' => $result[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to record transfer']);
    }

} catch (PDOException $e) {
    $msg = $e->getMessage();
    if (strpos($e->getCode(), 'P0001') !== false || strpos($msg, 'SQLSTATE[P0001]') !== false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $msg]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $msg]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
