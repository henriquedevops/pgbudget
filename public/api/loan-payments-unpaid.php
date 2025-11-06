<?php
/**
 * Unpaid Loan Payments API Endpoint
 * Fetches unpaid/scheduled loan payments for a ledger or specific loan
 * Part of Phase 2: LOAN_PAYMENTS_IN_TRANSACTIONS_PLAN.md
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get query parameters
    $loan_uuid = isset($_GET['loan_uuid']) ? sanitizeInput($_GET['loan_uuid']) : null;
    $ledger_uuid = isset($_GET['ledger_uuid']) ? sanitizeInput($_GET['ledger_uuid']) : null;

    // Require at least one filter parameter
    if (!$loan_uuid && !$ledger_uuid) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameter: loan_uuid or ledger_uuid'
        ]);
        exit;
    }

    // Build query based on parameters
    $sql = "
        SELECT
            uuid,
            payment_number,
            due_date,
            scheduled_amount,
            scheduled_principal,
            scheduled_interest,
            status,
            notes,
            loan_uuid,
            lender_name,
            loan_type,
            loan_current_balance,
            payment_frequency,
            ledger_uuid,
            ledger_name,
            account_uuid,
            account_name,
            days_until_due,
            days_past_due,
            payment_status
        FROM api.unpaid_loan_payments
        WHERE 1=1
    ";

    $params = [];

    if ($loan_uuid) {
        $sql .= " AND loan_uuid = ?";
        $params[] = $loan_uuid;
    }

    if ($ledger_uuid) {
        $sql .= " AND ledger_uuid = ?";
        $params[] = $ledger_uuid;
    }

    // Order by due date (soonest first)
    $sql .= " ORDER BY due_date ASC";

    // Execute query
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format amounts for JSON (convert to float)
    foreach ($payments as &$payment) {
        $payment['scheduled_amount'] = floatval($payment['scheduled_amount']);
        $payment['scheduled_principal'] = floatval($payment['scheduled_principal']);
        $payment['scheduled_interest'] = floatval($payment['scheduled_interest']);
        $payment['loan_current_balance'] = floatval($payment['loan_current_balance']);
        $payment['payment_number'] = intval($payment['payment_number']);
        $payment['days_until_due'] = intval($payment['days_until_due']);
        $payment['days_past_due'] = intval($payment['days_past_due']);
    }

    // Return success with payments
    echo json_encode([
        'success' => true,
        'payments' => $payments,
        'count' => count($payments)
    ]);

} catch (PDOException $e) {
    error_log("Database error in loan-payments-unpaid.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in loan-payments-unpaid.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
