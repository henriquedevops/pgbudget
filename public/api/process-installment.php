<?php
/**
 * Process Installment API Endpoint
 * Handles processing individual installment payments from installment plans
 * Part of Step 2.2 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 *
 * Transaction Flow:
 * Initial Purchase (already recorded):
 *   DR: Category (e.g., "Electronics") $1,200
 *   CR: Credit Card                    $1,200
 *
 * Each Monthly Installment Processing:
 *   DR: Credit Card Payment Category   $200
 *   CR: Category (e.g., "Electronics") $200
 *
 * Effect: Spreads the $1,200 category impact across 6 months
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Validate required fields
    if (empty($input['schedule_uuid'])) {
        throw new Exception('Missing required field: schedule_uuid');
    }

    // Optional: Allow overriding the amount and date
    $actual_amount = isset($input['actual_amount']) ? floatval($input['actual_amount']) : null;
    $processed_date = isset($input['processed_date']) ? $input['processed_date'] : date('Y-m-d');
    $notes = isset($input['notes']) ? $input['notes'] : null;

    // Begin transaction
    $db->beginTransaction();

    try {
        // 1. Get installment schedule with plan details
        $stmt = $db->prepare("
            SELECT
                isch.id as schedule_id,
                isch.uuid as schedule_uuid,
                isch.installment_plan_id,
                isch.installment_number,
                isch.due_date,
                isch.scheduled_amount,
                isch.status as schedule_status,
                ip.id as plan_id,
                ip.uuid as plan_uuid,
                ip.ledger_id,
                ip.description as plan_description,
                ip.credit_card_account_id,
                ip.category_account_id,
                ip.number_of_installments,
                ip.completed_installments,
                ip.status as plan_status,
                l.uuid as ledger_uuid,
                cc.uuid as credit_card_uuid,
                cc.name as credit_card_name,
                cat.uuid as category_uuid,
                cat.name as category_name
            FROM data.installment_schedules isch
            JOIN data.installment_plans ip ON isch.installment_plan_id = ip.id
            JOIN data.ledgers l ON ip.ledger_id = l.id
            JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
            LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
            WHERE isch.uuid = ?
        ");
        $stmt->execute([$input['schedule_uuid']]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) {
            throw new Exception('Installment schedule not found');
        }

        // 2. Validate installment is scheduled (not already processed)
        if ($schedule['schedule_status'] !== 'scheduled') {
            throw new Exception('Installment has already been processed or skipped. Current status: ' . $schedule['schedule_status']);
        }

        // Validate plan is active
        if ($schedule['plan_status'] !== 'active') {
            throw new Exception('Installment plan is not active. Current status: ' . $schedule['plan_status']);
        }

        // Use actual amount if provided, otherwise use scheduled amount
        $amount_to_process = $actual_amount ?? floatval($schedule['scheduled_amount']);

        if ($amount_to_process <= 0) {
            throw new Exception('Amount must be greater than zero');
        }

        // Validate category exists (required for installment processing)
        if (empty($schedule['category_account_id'])) {
            throw new Exception('No category assigned to this installment plan. Please assign a category before processing.');
        }

        // 3. Find or create Credit Card Payment category for this credit card
        // This is where the installment will be moved FROM the original category TO
        $cc_payment_category_name = 'CC Payment: ' . $schedule['credit_card_name'];

        // Try to find existing CC payment category
        $stmt = $db->prepare("
            SELECT id, uuid FROM data.accounts
            WHERE ledger_id = ?
            AND name = ?
            AND type = 'equity'
            LIMIT 1
        ");
        $stmt->execute([$schedule['ledger_id'], $cc_payment_category_name]);
        $cc_payment_category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cc_payment_category) {
            // Create the CC payment category if it doesn't exist
            $stmt = $db->prepare("
                INSERT INTO data.accounts (
                    ledger_id, name, type, internal_type
                ) VALUES (?, ?, 'equity', 'equity')
                RETURNING id, uuid
            ");
            $stmt->execute([
                $schedule['ledger_id'],
                $cc_payment_category_name
            ]);
            $cc_payment_category = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 4. Create budget transaction to spread the category impact
        // Transaction description: "Installment X/Y: {original description}"
        $transaction_description = sprintf(
            'Installment %d/%d: %s',
            $schedule['installment_number'],
            $schedule['number_of_installments'],
            $schedule['plan_description']
        );

        // Convert amount to cents (bigint) for the API
        $amount_cents = intval($amount_to_process * 100);

        // Transaction Flow:
        // DR: CC Payment Category (debit = increase this category)
        // CR: Original Category (credit = decrease this category)
        // This moves the budget impact from the original category to the CC payment category

        $stmt = $db->prepare("
            INSERT INTO data.transactions (
                ledger_id,
                date,
                description,
                debit_account_id,
                credit_account_id,
                amount
            ) VALUES (?, ?, ?, ?, ?, ?)
            RETURNING id, uuid
        ");

        $stmt->execute([
            $schedule['ledger_id'],
            $processed_date,
            $transaction_description,
            $cc_payment_category['id'],  // Debit (increase CC payment category)
            $schedule['category_account_id'],  // Credit (decrease original category)
            $amount_cents
        ]);

        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        // 5. Update installment schedule status to 'processed'
        $stmt = $db->prepare("
            UPDATE data.installment_schedules
            SET
                status = 'processed',
                processed_date = ?,
                actual_amount = ?,
                budget_transaction_id = ?,
                notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $processed_date,
            $amount_to_process,
            $transaction['id'],
            $notes,
            $schedule['schedule_id']
        ]);

        // 6. Update installment plan completed count
        $new_completed_count = intval($schedule['completed_installments']) + 1;

        $stmt = $db->prepare("
            UPDATE data.installment_plans
            SET completed_installments = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $new_completed_count,
            $schedule['plan_id']
        ]);

        // 7. If all installments processed, mark plan as 'completed'
        if ($new_completed_count >= intval($schedule['number_of_installments'])) {
            $stmt = $db->prepare("
                UPDATE data.installment_plans
                SET status = 'completed'
                WHERE id = ?
            ");
            $stmt->execute([$schedule['plan_id']]);
        }

        $db->commit();

        // Return success with details
        echo json_encode([
            'success' => true,
            'message' => 'Installment processed successfully',
            'data' => [
                'schedule_uuid' => $schedule['schedule_uuid'],
                'plan_uuid' => $schedule['plan_uuid'],
                'installment_number' => $schedule['installment_number'],
                'total_installments' => $schedule['number_of_installments'],
                'amount_processed' => $amount_to_process,
                'processed_date' => $processed_date,
                'transaction_uuid' => $transaction['uuid'],
                'transaction_description' => $transaction_description,
                'completed_installments' => $new_completed_count,
                'plan_completed' => ($new_completed_count >= intval($schedule['number_of_installments'])),
                'cc_payment_category' => [
                    'uuid' => $cc_payment_category['uuid'],
                    'name' => $cc_payment_category_name
                ],
                'original_category' => [
                    'uuid' => $schedule['category_uuid'],
                    'name' => $schedule['category_name']
                ]
            ]
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
