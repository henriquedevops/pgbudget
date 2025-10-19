<?php
/**
 * Loan Payments API Endpoint
 * Handles operations for loan payment schedules and recording payments
 * Part of Step 2.2 of LOAN_MANAGEMENT_IMPLEMENTATION.md
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
        case 'POST': // Record a loan payment
            // Validate required fields
            if (empty($input['payment_uuid'])) {
                throw new Exception('Missing required field: payment_uuid');
            }
            if (empty($input['paid_date'])) {
                throw new Exception('Missing required field: paid_date');
            }
            if (!isset($input['actual_amount']) || $input['actual_amount'] <= 0) {
                throw new Exception('Missing or invalid field: actual_amount');
            }
            if (empty($input['from_account_uuid'])) {
                throw new Exception('Missing required field: from_account_uuid');
            }

            // Call API function to record payment
            $stmt = $db->prepare("
                SELECT * FROM api.record_loan_payment(
                    p_payment_uuid := ?,
                    p_paid_date := ?,
                    p_actual_amount := ?,
                    p_from_account_uuid := ?,
                    p_notes := ?
                )
            ");

            $stmt->execute([
                $input['payment_uuid'],
                $input['paid_date'],
                $input['actual_amount'],
                $input['from_account_uuid'],
                $input['notes'] ?? null
            ]);

            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Failed to record payment');
            }

            // Get updated loan information to return current balance
            $stmt = $db->prepare("SELECT * FROM api.get_loan(?)");
            $stmt->execute([$payment['loan_uuid']]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment' => $payment,
                'loan' => $loan
            ]);
            break;

        case 'GET': // Get payment schedule for a loan
            if (isset($_GET['loan_uuid'])) {
                // Get all payments for a loan
                $stmt = $db->prepare("SELECT * FROM api.get_loan_payments(?)");
                $stmt->execute([$_GET['loan_uuid']]);
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calculate summary statistics
                $total_scheduled = 0;
                $total_paid = 0;
                $total_remaining = 0;
                $next_payment = null;
                $paid_count = 0;
                $scheduled_count = 0;

                foreach ($payments as $payment) {
                    $total_scheduled += floatval($payment['scheduled_amount']);

                    if ($payment['status'] === 'paid') {
                        $total_paid += floatval($payment['actual_amount_paid'] ?? 0);
                        $paid_count++;
                    } else {
                        $total_remaining += floatval($payment['scheduled_amount']);
                        $scheduled_count++;

                        // Find next unpaid payment
                        if ($next_payment === null || $payment['due_date'] < $next_payment['due_date']) {
                            $next_payment = $payment;
                        }
                    }
                }

                echo json_encode([
                    'success' => true,
                    'payments' => $payments,
                    'summary' => [
                        'total_payments' => count($payments),
                        'paid_count' => $paid_count,
                        'remaining_count' => $scheduled_count,
                        'total_scheduled' => number_format($total_scheduled, 2, '.', ''),
                        'total_paid' => number_format($total_paid, 2, '.', ''),
                        'total_remaining' => number_format($total_remaining, 2, '.', ''),
                        'next_payment' => $next_payment
                    ]
                ]);
            } elseif (isset($_GET['payment_uuid'])) {
                // Get single payment details
                $stmt = $db->prepare("
                    SELECT * FROM api.loan_payments
                    WHERE uuid = ?
                ");
                $stmt->execute([$_GET['payment_uuid']]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$payment) {
                    throw new Exception('Payment not found');
                }

                echo json_encode([
                    'success' => true,
                    'payment' => $payment
                ]);
            } else {
                throw new Exception('Missing required parameter: loan_uuid or payment_uuid');
            }
            break;

        case 'PUT': // Update payment details (notes, etc.)
            if (empty($input['payment_uuid'])) {
                throw new Exception('Missing required field: payment_uuid');
            }

            // Build UPDATE query for mutable fields
            $updates = [];
            $params = [];

            if (isset($input['notes'])) {
                $updates[] = "notes = ?";
                $params[] = $input['notes'];
            }

            if (empty($updates)) {
                throw new Exception('No fields to update');
            }

            // Add payment_uuid to params
            $params[] = $_SESSION['user_id'];
            $params[] = $input['payment_uuid'];

            // Update the payment
            $sql = "
                UPDATE data.loan_payments
                SET " . implode(', ', $updates) . "
                WHERE user_data = ? AND uuid = ?
                RETURNING *
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception('Payment not found or update failed');
            }

            // Fetch from API view to get proper formatting
            $stmt = $db->prepare("SELECT * FROM api.loan_payments WHERE uuid = ?");
            $stmt->execute([$input['payment_uuid']]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Payment updated successfully',
                'payment' => $payment
            ]);
            break;

        default:
            throw new Exception('Invalid request method');
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
