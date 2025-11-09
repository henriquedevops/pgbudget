<?php
require_once '../../includes/session.php';
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

    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Create credit card payment
        $input = json_decode(file_get_contents('php://input'), true);

        $credit_card_uuid = $input['credit_card_uuid'] ?? '';
        $bank_account_uuid = $input['bank_account_uuid'] ?? '';
        $amount = $input['amount'] ?? 0;
        $date = $input['date'] ?? null;
        $memo = $input['memo'] ?? null;

        if (empty($credit_card_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Credit card UUID is required']);
            exit;
        }

        if (empty($bank_account_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Bank account UUID is required']);
            exit;
        }

        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Amount must be positive']);
            exit;
        }

        // Use current timestamp if no date provided
        if (empty($date)) {
            $date = date('Y-m-d H:i:s');
        }

        // Call API function
        if ($memo !== null && $memo !== '') {
            $stmt = $db->prepare("SELECT api.pay_credit_card(?, ?, ?, ?::timestamptz, ?)");
            $stmt->execute([$credit_card_uuid, $bank_account_uuid, $amount, $date, $memo]);
        } else {
            $stmt = $db->prepare("SELECT api.pay_credit_card(?, ?, ?, ?::timestamptz)");
            $stmt->execute([$credit_card_uuid, $bank_account_uuid, $amount, $date]);
        }

        $transaction_uuid = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'transaction_uuid' => $transaction_uuid
        ]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
