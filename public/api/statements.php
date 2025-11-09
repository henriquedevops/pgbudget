<?php
/**
 * Credit Card Statements API Endpoint
 * Handles statement generation and retrieval operations
 * Part of Phase 3 (Billing Cycle Management) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
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
        case 'GET':
            // Get statements
            if (isset($_GET['statement_uuid'])) {
                // Get specific statement by UUID
                $stmt = $db->prepare("SELECT * FROM api.get_statement(?)");
                $stmt->execute([$_GET['statement_uuid']]);
                $statement = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$statement) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Statement not found'
                    ]);
                    exit;
                }

                // Get transactions for this statement
                $stmt = $db->prepare("
                    SELECT
                        t.uuid as transaction_uuid,
                        t.date,
                        t.description,
                        t.amount,
                        CASE
                            WHEN t.credit_account_id = a.id THEN 'purchase'
                            WHEN t.debit_account_id = a.id THEN 'payment'
                        END as transaction_type,
                        t.metadata->>'is_interest_charge' as is_interest
                    FROM data.transactions t
                    JOIN data.accounts a ON (a.uuid = ? AND (t.credit_account_id = a.id OR t.debit_account_id = a.id))
                    JOIN data.credit_card_statements s ON s.uuid = ?
                    WHERE t.date >= s.statement_period_start
                        AND t.date <= s.statement_period_end
                        AND t.user_data = utils.get_user()
                        AND t.deleted_at IS NULL
                    ORDER BY t.date DESC, t.created_at DESC
                ");
                $stmt->execute([$statement['account_uuid'], $_GET['statement_uuid']]);
                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $statement['transactions'] = $transactions;

                echo json_encode([
                    'success' => true,
                    'data' => $statement
                ]);

            } elseif (isset($_GET['account_uuid'])) {
                // Get all statements for an account
                if (isset($_GET['current']) && $_GET['current'] === 'true') {
                    // Get only current statement
                    $stmt = $db->prepare("SELECT * FROM api.get_current_statement(?)");
                    $stmt->execute([$_GET['account_uuid']]);
                    $statement = $stmt->fetch(PDO::FETCH_ASSOC);

                    echo json_encode([
                        'success' => true,
                        'data' => $statement ?: null
                    ]);
                } else {
                    // Get all statements
                    $stmt = $db->prepare("SELECT * FROM api.get_statements_for_account(?)");
                    $stmt->execute([$_GET['account_uuid']]);
                    $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode([
                        'success' => true,
                        'data' => $statements
                    ]);
                }

            } elseif (isset($_GET['upcoming_due_dates'])) {
                // Get upcoming due dates
                $days_ahead = isset($_GET['days_ahead']) ? intval($_GET['days_ahead']) : 30;

                $stmt = $db->prepare("SELECT * FROM api.get_upcoming_due_dates(?)");
                $stmt->execute([$days_ahead]);
                $due_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'data' => $due_dates
                ]);

            } else {
                // Get all statements for user
                $stmt = $db->prepare("SELECT * FROM api.credit_card_statements");
                $stmt->execute();
                $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'data' => $statements
                ]);
            }
            break;

        case 'POST':
            // Generate statement
            if (empty($input['account_uuid'])) {
                throw new Exception('Missing required field: account_uuid');
            }

            $statement_date = $input['statement_date'] ?? date('Y-m-d');

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statement_date)) {
                throw new Exception('Invalid date format. Use YYYY-MM-DD');
            }

            // Generate statement
            $stmt = $db->prepare("SELECT api.generate_statement(?, ?)");
            $stmt->execute([
                $input['account_uuid'],
                $statement_date
            ]);

            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            if (!$result_data || !$result_data['success']) {
                throw new Exception($result_data['error'] ?? $result_data['message'] ?? 'Failed to generate statement');
            }

            // Fetch the generated statement
            $stmt = $db->prepare("SELECT * FROM api.get_statement(?)");
            $stmt->execute([$result_data['statement_uuid']]);
            $statement = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Statement generated successfully',
                'data' => $statement
            ]);
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
