<?php
/**
 * Loans API Endpoint
 * Handles CRUD operations for loans
 * Part of Step 2.1 of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

require_once '../../includes/session.php';
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
        case 'POST': // Create loan
            // Validate required fields
            if (empty($input['ledger_uuid'])) {
                throw new Exception('Missing required field: ledger_uuid');
            }
            if (empty($input['lender_name'])) {
                throw new Exception('Missing required field: lender_name');
            }
            if (empty($input['loan_type'])) {
                throw new Exception('Missing required field: loan_type');
            }
            if (!isset($input['principal_amount']) || $input['principal_amount'] <= 0) {
                throw new Exception('Missing or invalid field: principal_amount');
            }
            if (!isset($input['interest_rate']) || $input['interest_rate'] < 0) {
                throw new Exception('Missing or invalid field: interest_rate');
            }
            if (empty($input['loan_term_months']) || $input['loan_term_months'] <= 0) {
                throw new Exception('Missing or invalid field: loan_term_months');
            }
            if (empty($input['start_date'])) {
                throw new Exception('Missing required field: start_date');
            }
            if (empty($input['first_payment_date'])) {
                throw new Exception('Missing required field: first_payment_date');
            }
            if (empty($input['payment_frequency'])) {
                throw new Exception('Missing required field: payment_frequency');
            }

            // Call API function to create loan
            $stmt = $db->prepare("
                SELECT * FROM api.create_loan(
                    p_ledger_uuid := ?,
                    p_lender_name := ?,
                    p_loan_type := ?,
                    p_principal_amount := ?,
                    p_interest_rate := ?,
                    p_loan_term_months := ?,
                    p_start_date := ?,
                    p_first_payment_date := ?,
                    p_payment_frequency := ?,
                    p_account_uuid := ?,
                    p_interest_type := ?,
                    p_compounding_frequency := ?,
                    p_payment_day_of_month := ?,
                    p_amortization_type := ?,
                    p_notes := ?,
                    p_initial_amount_paid := ?,
                    p_initial_paid_as_of_date := ?,
                    p_initial_payments_made := ?
                )
            ");

            $stmt->execute([
                $input['ledger_uuid'],
                $input['lender_name'],
                $input['loan_type'],
                $input['principal_amount'],
                $input['interest_rate'],
                intval($input['loan_term_months']),
                $input['start_date'],
                $input['first_payment_date'],
                $input['payment_frequency'],
                $input['account_uuid'] ?? null,
                $input['interest_type'] ?? 'fixed',
                $input['compounding_frequency'] ?? 'monthly',
                isset($input['payment_day_of_month']) ? intval($input['payment_day_of_month']) : null,
                $input['amortization_type'] ?? 'standard',
                $input['notes'] ?? null,
                isset($input['initial_amount_paid']) ? floatval($input['initial_amount_paid']) : 0,
                $input['initial_paid_as_of_date'] ?? null,
                isset($input['initial_payments_made']) ? intval($input['initial_payments_made']) : 0
            ]);

            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$loan) {
                throw new Exception('Failed to create loan');
            }

            // Generate payment schedule
            $stmt = $db->prepare("SELECT api.generate_loan_schedule(?)");
            $stmt->execute([$loan['uuid']]);
            $payment_count = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => 'Loan created successfully',
                'loan' => $loan,
                'payments_generated' => $payment_count
            ]);
            break;

        case 'PUT': // Update loan
            if (empty($input['loan_uuid'])) {
                throw new Exception('Missing required field: loan_uuid');
            }

            $stmt = $db->prepare("
                SELECT * FROM api.update_loan(
                    p_loan_uuid := ?,
                    p_lender_name := ?,
                    p_interest_rate := ?,
                    p_interest_type := ?,
                    p_status := ?,
                    p_notes := ?
                )
            ");

            $stmt->execute([
                $input['loan_uuid'],
                $input['lender_name'] ?? null,
                isset($input['interest_rate']) ? floatval($input['interest_rate']) : null,
                $input['interest_type'] ?? null,
                $input['status'] ?? null,
                $input['notes'] ?? null
            ]);

            $loan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$loan) {
                throw new Exception('Failed to update loan');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Loan updated successfully',
                'loan' => $loan
            ]);
            break;

        case 'DELETE': // Delete loan
            if (empty($_GET['loan_uuid'])) {
                throw new Exception('Missing required parameter: loan_uuid');
            }

            $stmt = $db->prepare("SELECT api.delete_loan(?)");
            $stmt->execute([$_GET['loan_uuid']]);
            $result = $stmt->fetchColumn();

            if (!$result) {
                throw new Exception('Failed to delete loan');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Loan deleted successfully'
            ]);
            break;

        case 'GET': // Get loans
            if (isset($_GET['loan_uuid'])) {
                // Get single loan
                $stmt = $db->prepare("SELECT * FROM api.get_loan(?)");
                $stmt->execute([$_GET['loan_uuid']]);
                $loan = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$loan) {
                    throw new Exception('Loan not found');
                }

                echo json_encode([
                    'success' => true,
                    'loan' => $loan
                ]);
            } elseif (isset($_GET['ledger_uuid'])) {
                // Get all loans for ledger
                $stmt = $db->prepare("SELECT * FROM api.get_loans(?)");
                $stmt->execute([$_GET['ledger_uuid']]);
                $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'loans' => $loans
                ]);
            } else {
                throw new Exception('Missing required parameter: ledger_uuid or loan_uuid');
            }
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
