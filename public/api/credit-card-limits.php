<?php
/**
 * Credit Card Limits API Endpoint
 * Handles CRUD operations for credit card limits and configurations
 * Part of Phase 2 (Interest Accrual) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
 */

session_start();

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
            // Get credit card limit by account UUID
            if (isset($_GET['account_uuid'])) {
                $stmt = $db->prepare("SELECT * FROM api.get_credit_card_limit(?)");
                $stmt->execute([$_GET['account_uuid']]);
                $limit = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$limit) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Credit card limit not found'
                    ]);
                    exit;
                }

                echo json_encode([
                    'success' => true,
                    'data' => $limit
                ]);
            } else {
                // Get all credit card limits for user
                $stmt = $db->prepare("SELECT * FROM api.credit_card_limits ORDER BY account_name");
                $stmt->execute();
                $limits = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'data' => $limits
                ]);
            }
            break;

        case 'POST':
            // Create or update credit card limit
            // Validate required fields
            if (empty($input['account_uuid'])) {
                throw new Exception('Missing required field: account_uuid');
            }
            if (!isset($input['credit_limit']) || $input['credit_limit'] <= 0) {
                throw new Exception('Missing or invalid field: credit_limit');
            }

            // Verify the account is actually a credit card (not just any liability)
            $stmt_check = $db->prepare("
                SELECT utils.is_credit_card(a.id) as is_cc
                FROM data.accounts a
                WHERE a.uuid = ? AND a.user_data = utils.get_user()
            ");
            $stmt_check->execute([$input['account_uuid']]);
            $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if (!$check_result || !$check_result['is_cc']) {
                throw new Exception('This account is not a credit card. Credit limits can only be set on credit card accounts.');
            }

            // Build SQL for creating/updating limit
            $stmt = $db->prepare("
                INSERT INTO data.credit_card_limits (
                    credit_card_account_id,
                    credit_limit,
                    warning_threshold_percent,
                    annual_percentage_rate,
                    interest_type,
                    compounding_frequency,
                    statement_day_of_month,
                    due_date_offset_days,
                    grace_period_days,
                    minimum_payment_percent,
                    minimum_payment_flat,
                    auto_payment_enabled,
                    auto_payment_type,
                    auto_payment_amount,
                    auto_payment_date,
                    notes
                )
                SELECT
                    a.id,
                    ?,
                    COALESCE(?, 80),
                    COALESCE(?, 0.0),
                    COALESCE(?, 'variable'),
                    COALESCE(?, 'daily'),
                    COALESCE(?, 1),
                    COALESCE(?, 21),
                    COALESCE(?, 0),
                    COALESCE(?, 1.0),
                    COALESCE(?, 25.00),
                    COALESCE(?, false),
                    ?,
                    ?,
                    ?,
                    ?
                FROM data.accounts a
                WHERE a.uuid = ?
                    AND a.type = 'liability'
                    AND utils.is_credit_card(a.id) = true
                    AND a.user_data = utils.get_user()
                ON CONFLICT (credit_card_account_id, user_data) DO UPDATE SET
                    credit_limit = EXCLUDED.credit_limit,
                    warning_threshold_percent = EXCLUDED.warning_threshold_percent,
                    annual_percentage_rate = EXCLUDED.annual_percentage_rate,
                    interest_type = EXCLUDED.interest_type,
                    compounding_frequency = EXCLUDED.compounding_frequency,
                    statement_day_of_month = EXCLUDED.statement_day_of_month,
                    due_date_offset_days = EXCLUDED.due_date_offset_days,
                    grace_period_days = EXCLUDED.grace_period_days,
                    minimum_payment_percent = EXCLUDED.minimum_payment_percent,
                    minimum_payment_flat = EXCLUDED.minimum_payment_flat,
                    auto_payment_enabled = EXCLUDED.auto_payment_enabled,
                    auto_payment_type = EXCLUDED.auto_payment_type,
                    auto_payment_amount = EXCLUDED.auto_payment_amount,
                    auto_payment_date = EXCLUDED.auto_payment_date,
                    notes = EXCLUDED.notes,
                    updated_at = CURRENT_TIMESTAMP
                RETURNING uuid
            ");

            $stmt->execute([
                $input['credit_limit'],
                $input['warning_threshold_percent'] ?? null,
                $input['annual_percentage_rate'] ?? null,
                $input['interest_type'] ?? null,
                $input['compounding_frequency'] ?? null,
                $input['statement_day_of_month'] ?? null,
                $input['due_date_offset_days'] ?? null,
                $input['grace_period_days'] ?? null,
                $input['minimum_payment_percent'] ?? null,
                $input['minimum_payment_flat'] ?? null,
                $input['auto_payment_enabled'] ?? null,
                $input['auto_payment_type'] ?? null,
                $input['auto_payment_amount'] ?? null,
                $input['auto_payment_date'] ?? null,
                $input['notes'] ?? null,
                $input['account_uuid']
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                throw new Exception('Failed to create/update credit card limit. Account may not exist.');
            }

            // Fetch the full limit configuration
            $stmt = $db->prepare("SELECT * FROM api.get_credit_card_limit(?)");
            $stmt->execute([$input['account_uuid']]);
            $limit = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Credit card limit saved successfully',
                'data' => $limit
            ]);
            break;

        case 'DELETE':
            // Deactivate credit card limit
            if (empty($input['account_uuid'])) {
                throw new Exception('Missing required field: account_uuid');
            }

            $stmt = $db->prepare("
                UPDATE data.credit_card_limits ccl
                SET is_active = false, updated_at = CURRENT_TIMESTAMP
                FROM data.accounts a
                WHERE ccl.credit_card_account_id = a.id
                    AND a.uuid = ?
                    AND ccl.user_data = utils.get_user()
            ");
            $stmt->execute([$input['account_uuid']]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Credit card limit not found');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Credit card limit deactivated successfully'
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
