<?php
/**
 * Installment Plans API Endpoint
 * Handles CRUD operations for credit card installment payment plans
 * Part of Step 2.1 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
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
            // Check if fetching single plan or list
            if (isset($_GET['plan_uuid'])) {
                // Get single plan with schedule
                $stmt = $db->prepare("
                    SELECT
                        ip.id, ip.uuid, ip.created_at, ip.updated_at,
                        ip.ledger_id, ip.original_transaction_id,
                        ip.purchase_amount, ip.purchase_date, ip.description,
                        ip.credit_card_account_id, ip.number_of_installments,
                        ip.installment_amount, ip.frequency, ip.start_date,
                        ip.category_account_id, ip.status, ip.completed_installments,
                        ip.notes, ip.metadata,
                        cc.name as credit_card_name,
                        cc.uuid as credit_card_uuid,
                        cat.name as category_name,
                        cat.uuid as category_uuid,
                        l.uuid as ledger_uuid,
                        t.uuid as original_transaction_uuid
                    FROM data.installment_plans ip
                    JOIN data.ledgers l ON ip.ledger_id = l.id
                    JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
                    LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
                    LEFT JOIN data.transactions t ON ip.original_transaction_id = t.id
                    WHERE ip.uuid = ?
                ");
                $stmt->execute([$_GET['plan_uuid']]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$plan) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Installment plan not found']);
                    exit;
                }

                // Get schedule items
                $stmt = $db->prepare("
                    SELECT
                        isch.id, isch.uuid, isch.created_at, isch.updated_at,
                        isch.installment_plan_id, isch.installment_number,
                        isch.due_date, isch.scheduled_amount, isch.status,
                        isch.processed_date, isch.actual_amount,
                        isch.budget_transaction_id, isch.notes,
                        bt.uuid as budget_transaction_uuid
                    FROM data.installment_schedules isch
                    LEFT JOIN data.transactions bt ON isch.budget_transaction_id = bt.id
                    WHERE isch.installment_plan_id = ?
                    ORDER BY isch.installment_number ASC
                ");
                $stmt->execute([$plan['id']]);
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $plan['schedule'] = $schedule;

                echo json_encode([
                    'success' => true,
                    'plan' => $plan
                ]);

            } elseif (isset($_GET['ledger_uuid'])) {
                // List all plans for ledger
                $stmt = $db->prepare("
                    SELECT
                        ip.id, ip.uuid, ip.created_at, ip.updated_at,
                        ip.purchase_amount, ip.purchase_date, ip.description,
                        ip.number_of_installments, ip.installment_amount,
                        ip.frequency, ip.start_date, ip.status,
                        ip.completed_installments, ip.notes,
                        cc.name as credit_card_name,
                        cc.uuid as credit_card_uuid,
                        cat.name as category_name,
                        cat.uuid as category_uuid,
                        l.uuid as ledger_uuid
                    FROM data.installment_plans ip
                    JOIN data.ledgers l ON ip.ledger_id = l.id
                    JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
                    LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
                    WHERE l.uuid = ?
                    ORDER BY ip.created_at DESC
                ");
                $stmt->execute([$_GET['ledger_uuid']]);
                $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'plans' => $plans
                ]);

            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required parameter: ledger_uuid or plan_uuid']);
            }
            break;

        case 'POST': // Create new installment plan
            // Validate required fields
            if (empty($input['ledger_uuid'])) {
                throw new Exception('Missing required field: ledger_uuid');
            }
            if (empty($input['credit_card_account_uuid'])) {
                throw new Exception('Missing required field: credit_card_account_uuid');
            }
            if (!isset($input['purchase_amount']) || $input['purchase_amount'] <= 0) {
                throw new Exception('Missing or invalid field: purchase_amount');
            }
            if (empty($input['purchase_date'])) {
                throw new Exception('Missing required field: purchase_date');
            }
            if (empty($input['description'])) {
                throw new Exception('Missing required field: description');
            }
            if (empty($input['number_of_installments']) || $input['number_of_installments'] < 2) {
                throw new Exception('Number of installments must be at least 2');
            }
            if (empty($input['start_date'])) {
                throw new Exception('Missing required field: start_date');
            }

            // Get ledger ID
            $stmt = $db->prepare("SELECT id FROM data.ledgers WHERE uuid = ?");
            $stmt->execute([$input['ledger_uuid']]);
            $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ledger) {
                throw new Exception('Ledger not found');
            }

            // Get credit card account ID
            $stmt = $db->prepare("SELECT id FROM data.accounts WHERE uuid = ?");
            $stmt->execute([$input['credit_card_account_uuid']]);
            $cc_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cc_account) {
                throw new Exception('Credit card account not found');
            }

            // Get category account ID (optional)
            $category_id = null;
            if (!empty($input['category_account_uuid'])) {
                $stmt = $db->prepare("SELECT id FROM data.accounts WHERE uuid = ?");
                $stmt->execute([$input['category_account_uuid']]);
                $category = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($category) {
                    $category_id = $category['id'];
                }
            }

            // Get original transaction ID (optional)
            $transaction_id = null;
            if (!empty($input['original_transaction_uuid'])) {
                $stmt = $db->prepare("SELECT id FROM data.transactions WHERE uuid = ?");
                $stmt->execute([$input['original_transaction_uuid']]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($transaction) {
                    $transaction_id = $transaction['id'];
                }
            }

            // Calculate installment amount with proper rounding
            $purchase_amount = floatval($input['purchase_amount']);
            $num_installments = intval($input['number_of_installments']);
            $installment_amount = round($purchase_amount / $num_installments, 2);

            // Adjust last installment to account for rounding differences
            $total_scheduled = $installment_amount * ($num_installments - 1);
            $last_installment = round($purchase_amount - $total_scheduled, 2);

            // Default frequency to monthly
            $frequency = $input['frequency'] ?? 'monthly';

            // Begin transaction
            $db->beginTransaction();

            try {
                // Insert installment plan
                $stmt = $db->prepare("
                    INSERT INTO data.installment_plans (
                        ledger_id, original_transaction_id, purchase_amount,
                        purchase_date, description, credit_card_account_id,
                        number_of_installments, installment_amount, frequency,
                        start_date, category_account_id, notes, metadata
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    RETURNING id, uuid
                ");

                $metadata = isset($input['metadata']) ? json_encode($input['metadata']) : null;

                $stmt->execute([
                    $ledger['id'],
                    $transaction_id,
                    $purchase_amount,
                    $input['purchase_date'],
                    $input['description'],
                    $cc_account['id'],
                    $num_installments,
                    $installment_amount,
                    $frequency,
                    $input['start_date'],
                    $category_id,
                    $input['notes'] ?? null,
                    $metadata
                ]);

                $plan = $stmt->fetch(PDO::FETCH_ASSOC);

                // Generate installment schedule
                $schedules = [];
                $current_date = new DateTime($input['start_date']);

                for ($i = 1; $i <= $num_installments; $i++) {
                    // Use adjusted amount for last installment
                    $amount = ($i == $num_installments) ? $last_installment : $installment_amount;

                    $stmt = $db->prepare("
                        INSERT INTO data.installment_schedules (
                            installment_plan_id, installment_number,
                            due_date, scheduled_amount
                        ) VALUES (?, ?, ?, ?)
                        RETURNING id, uuid
                    ");

                    $stmt->execute([
                        $plan['id'],
                        $i,
                        $current_date->format('Y-m-d'),
                        $amount
                    ]);

                    $schedule_item = $stmt->fetch(PDO::FETCH_ASSOC);
                    $schedules[] = [
                        'installment_number' => $i,
                        'due_date' => $current_date->format('Y-m-d'),
                        'scheduled_amount' => $amount,
                        'uuid' => $schedule_item['uuid']
                    ];

                    // Calculate next date based on frequency
                    switch ($frequency) {
                        case 'weekly':
                            $current_date->modify('+1 week');
                            break;
                        case 'bi-weekly':
                            $current_date->modify('+2 weeks');
                            break;
                        case 'monthly':
                        default:
                            $current_date->modify('+1 month');
                            break;
                    }
                }

                $db->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Installment plan created successfully',
                    'plan' => [
                        'uuid' => $plan['uuid'],
                        'schedule' => $schedules
                    ]
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'PUT': // Update installment plan (limited fields)
            if (empty($_GET['plan_uuid'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required parameter: plan_uuid']);
                exit;
            }

            // Get existing plan
            $stmt = $db->prepare("
                SELECT id, status, completed_installments
                FROM data.installment_plans
                WHERE uuid = ?
            ");
            $stmt->execute([$_GET['plan_uuid']]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                http_response_code(404);
                echo json_encode(['error' => 'Installment plan not found']);
                exit;
            }

            // Only allow updating certain fields
            $allowed_fields = ['notes', 'metadata', 'category_account_id'];
            $updates = [];
            $params = [];

            foreach ($allowed_fields as $field) {
                if (isset($input[$field])) {
                    if ($field === 'category_account_id' && !empty($input['category_account_uuid'])) {
                        // Resolve category UUID to ID
                        $stmt = $db->prepare("SELECT id FROM data.accounts WHERE uuid = ?");
                        $stmt->execute([$input['category_account_uuid']]);
                        $category = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($category) {
                            $updates[] = "category_account_id = ?";
                            $params[] = $category['id'];
                        }
                    } elseif ($field === 'metadata') {
                        $updates[] = "metadata = ?";
                        $params[] = json_encode($input[$field]);
                    } else {
                        $updates[] = "$field = ?";
                        $params[] = $input[$field];
                    }
                }
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                exit;
            }

            $params[] = $_GET['plan_uuid'];

            $stmt = $db->prepare("
                UPDATE data.installment_plans
                SET " . implode(', ', $updates) . "
                WHERE uuid = ?
                RETURNING uuid
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Installment plan updated successfully',
                'plan_uuid' => $result['uuid']
            ]);
            break;

        case 'DELETE': // Cancel/delete installment plan
            if (empty($_GET['plan_uuid'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required parameter: plan_uuid']);
                exit;
            }

            // Get plan with processed installments count
            $stmt = $db->prepare("
                SELECT
                    ip.id,
                    ip.uuid,
                    ip.status,
                    ip.completed_installments,
                    COUNT(isch.id) FILTER (WHERE isch.status = 'processed') as processed_count
                FROM data.installment_plans ip
                LEFT JOIN data.installment_schedules isch ON ip.id = isch.installment_plan_id
                WHERE ip.uuid = ?
                GROUP BY ip.id, ip.uuid, ip.status, ip.completed_installments
            ");
            $stmt->execute([$_GET['plan_uuid']]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                http_response_code(404);
                echo json_encode(['error' => 'Installment plan not found']);
                exit;
            }

            // Prevent deletion if installments already processed
            if ($plan['processed_count'] > 0) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Cannot delete installment plan with processed installments',
                    'processed_count' => $plan['processed_count']
                ]);
                exit;
            }

            // Delete plan (cascades to schedules)
            $stmt = $db->prepare("
                DELETE FROM data.installment_plans
                WHERE uuid = ?
            ");
            $stmt->execute([$_GET['plan_uuid']]);

            echo json_encode([
                'success' => true,
                'message' => 'Installment plan deleted successfully'
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
