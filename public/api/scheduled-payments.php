<?php
/**
 * Scheduled Payments API Endpoint
 * Handles payment scheduling, cancellation, and retrieval operations
 * Part of Phase 4 (Payment Scheduling) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    switch ($method) {
        case 'GET':
            // Get scheduled payments
            $credit_card_uuid = $_GET['credit_card_uuid'] ?? null;
            $status = $_GET['status'] ?? null;

            if (isset($_GET['payment_uuid'])) {
                // Get specific payment by UUID
                $stmt = $db->prepare("
                    SELECT * FROM api.scheduled_payments
                    WHERE uuid = ?
                    LIMIT 1
                ");
                $stmt->execute([$_GET['payment_uuid']]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$payment) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Scheduled payment not found'
                    ]);
                    exit;
                }

                echo json_encode([
                    'success' => true,
                    'data' => $payment
                ]);
            } else {
                // Get filtered payments
                $stmt = $db->prepare("
                    SELECT * FROM api.get_scheduled_payments(?, ?)
                ");
                $stmt->execute([$credit_card_uuid, $status]);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'data' => $payments
                ]);
            }
            break;

        case 'POST':
            // Schedule a payment
            if (empty($input['credit_card_uuid'])) {
                throw new Exception('Missing required field: credit_card_uuid');
            }
            if (empty($input['bank_account_uuid'])) {
                throw new Exception('Missing required field: bank_account_uuid');
            }
            if (empty($input['payment_type'])) {
                throw new Exception('Missing required field: payment_type');
            }

            // Validate payment type
            $valid_types = ['minimum', 'full_balance', 'fixed_amount', 'custom'];
            if (!in_array($input['payment_type'], $valid_types)) {
                throw new Exception('Invalid payment_type. Must be one of: ' . implode(', ', $valid_types));
            }

            // Validate payment amount for fixed/custom types
            if (in_array($input['payment_type'], ['fixed_amount', 'custom'])) {
                if (!isset($input['payment_amount']) || $input['payment_amount'] <= 0) {
                    throw new Exception('payment_amount is required and must be > 0 for ' . $input['payment_type']);
                }
                // Convert to cents
                $payment_amount = intval($input['payment_amount'] * 100);
            } else {
                $payment_amount = null;
            }

            // Schedule payment
            $stmt = $db->prepare("
                SELECT api.schedule_payment(?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $input['credit_card_uuid'],
                $input['bank_account_uuid'],
                $input['payment_type'],
                $payment_amount,
                $input['scheduled_date'] ?? null,
                $input['statement_uuid'] ?? null
            ]);

            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            if (!$result_data || !$result_data['success']) {
                throw new Exception($result_data['error'] ?? 'Failed to schedule payment');
            }

            // Fetch the created payment
            $stmt = $db->prepare("
                SELECT * FROM api.scheduled_payments
                WHERE uuid = ?
                LIMIT 1
            ");
            $stmt->execute([$result_data['payment_uuid']]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Payment scheduled successfully',
                'data' => $payment
            ]);
            break;

        case 'DELETE':
            // Cancel a scheduled payment
            if (empty($input['payment_uuid'])) {
                throw new Exception('Missing required field: payment_uuid');
            }

            $stmt = $db->prepare("SELECT api.cancel_scheduled_payment(?)");
            $stmt->execute([$input['payment_uuid']]);

            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            if (!$result_data || !$result_data['success']) {
                throw new Exception($result_data['error'] ?? 'Failed to cancel payment');
            }

            echo json_encode([
                'success' => true,
                'message' => $result_data['message']
            ]);
            break;

        case 'PATCH':
            // Process a payment manually (for testing/admin)
            if (empty($input['payment_uuid'])) {
                throw new Exception('Missing required field: payment_uuid');
            }
            if (empty($input['action']) || $input['action'] !== 'process') {
                throw new Exception('Invalid action. Use action: "process" to process a payment');
            }

            // Get payment internal ID
            $stmt = $db->prepare("
                SELECT sp.id
                FROM data.scheduled_payments sp
                WHERE sp.uuid = ?
                    AND sp.user_data = utils.get_user()
            ");
            $stmt->execute([$input['payment_uuid']]);
            $payment_id = $stmt->fetchColumn();

            if (!$payment_id) {
                throw new Exception('Scheduled payment not found');
            }

            // Process payment
            $stmt = $db->prepare("SELECT utils.process_scheduled_payment(?)");
            $stmt->execute([$payment_id]);

            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            echo json_encode($result_data);
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
