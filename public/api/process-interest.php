<?php
/**
 * Process Interest API Endpoint
 * Handles interest accrual for credit card accounts
 * Part of Phase 2 (Interest Accrual) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
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

    if ($method === 'POST') {
        // Process interest accrual for a specific account or all accounts

        if (isset($input['account_uuid'])) {
            // Process interest for a specific account
            $accrual_date = $input['accrual_date'] ?? date('Y-m-d');

            $stmt = $db->prepare("
                SELECT api.process_interest_accrual(?, ?)
            ");
            $stmt->execute([
                $input['account_uuid'],
                $accrual_date
            ]);

            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            echo json_encode($result_data);

        } elseif (isset($input['process_all'])) {
            // Process interest for all accounts with active limits
            // This is primarily for manual testing or admin use
            $accrual_date = $input['accrual_date'] ?? date('Y-m-d');

            // Get all credit card accounts with limits for this user
            $stmt = $db->prepare("
                SELECT account_uuid
                FROM api.credit_card_limits
                WHERE is_active = true
                    AND annual_percentage_rate > 0
                ORDER BY account_name
            ");
            $stmt->execute();
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($accounts as $account) {
                $stmt = $db->prepare("SELECT api.process_interest_accrual(?, ?)");
                $stmt->execute([
                    $account['account_uuid'],
                    $accrual_date
                ]);

                $result = $stmt->fetchColumn();
                $result_data = json_decode($result, true);
                $results[] = $result_data;
            }

            // Build summary
            $success_count = 0;
            $skipped_count = 0;
            $error_count = 0;

            foreach ($results as $result) {
                if (!empty($result['success'])) {
                    if (!empty($result['accrued'])) {
                        $success_count++;
                    } else {
                        $skipped_count++;
                    }
                } else {
                    $error_count++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Processed interest for all eligible accounts',
                'accrual_date' => $accrual_date,
                'total_processed' => count($results),
                'success_count' => $success_count,
                'skipped_count' => $skipped_count,
                'error_count' => $error_count,
                'results' => $results
            ]);

        } else {
            throw new Exception('Missing required parameter: account_uuid or process_all');
        }

    } elseif ($method === 'GET') {
        // Get interest summary for an account
        if (empty($_GET['account_uuid'])) {
            throw new Exception('Missing required parameter: account_uuid');
        }

        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;

        $stmt = $db->prepare("
            SELECT * FROM api.get_interest_summary(?, ?, ?)
        ");
        $stmt->execute([
            $_GET['account_uuid'],
            $start_date,
            $end_date
        ]);

        $interest_charges = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate totals
        $total_interest = array_sum(array_column($interest_charges, 'amount'));

        echo json_encode([
            'success' => true,
            'data' => [
                'account_uuid' => $_GET['account_uuid'],
                'start_date' => $start_date ?? date('Y-m-d', strtotime('-90 days')),
                'end_date' => $end_date ?? date('Y-m-d'),
                'total_interest' => $total_interest,
                'total_interest_display' => '$' . number_format($total_interest / 100, 2),
                'charge_count' => count($interest_charges),
                'charges' => $interest_charges
            ]
        ]);

    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
