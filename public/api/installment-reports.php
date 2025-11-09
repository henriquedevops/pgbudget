<?php
/**
 * Installment Reports API
 * Provides data for installment payment reporting and analytics
 * Part of Step 7.1 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

// Get ledger UUID from query params
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Ledger UUID is required'
    ]);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Verify ledger exists and belongs to user
    $stmt = $db->prepare("
        SELECT id, uuid, name
        FROM data.ledgers
        WHERE uuid = ?
        AND user_data = utils.get_user()
    ");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ledger) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Ledger not found'
        ]);
        exit;
    }

    $ledger_id = $ledger['id'];

    // 1. Summary Statistics
    $stmt = $db->prepare("
        SELECT
            COUNT(*) FILTER (WHERE status = 'active') as active_plan_count,
            COUNT(*) as total_plan_count,
            SUM(CASE
                WHEN status = 'active'
                THEN (number_of_installments - completed_installments) * installment_amount
                ELSE 0
            END) as total_remaining_debt,
            SUM(CASE
                WHEN status = 'active' AND frequency = 'monthly'
                THEN installment_amount
                WHEN status = 'active' AND frequency = 'bi-weekly'
                THEN installment_amount * 2
                WHEN status = 'active' AND frequency = 'weekly'
                THEN installment_amount * 4
                ELSE 0
            END) as monthly_obligations,
            AVG(CASE WHEN status = 'active' THEN purchase_amount ELSE NULL END) as average_plan_size
        FROM data.installment_plans
        WHERE ledger_id = ?
    ");
    $stmt->execute([$ledger_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Completion Statistics
    $stmt = $db->prepare("
        SELECT
            COUNT(*) FILTER (WHERE status = 'processed') as total_processed,
            COUNT(*) FILTER (WHERE status = 'scheduled') as total_scheduled,
            COUNT(*) FILTER (
                WHERE status = 'processed'
                AND processed_date <= due_date
            ) as on_time_processed
        FROM data.installment_schedules isch
        JOIN data.installment_plans ip ON isch.installment_plan_id = ip.id
        WHERE ip.ledger_id = ?
    ");
    $stmt->execute([$ledger_id]);
    $completion = $stmt->fetch(PDO::FETCH_ASSOC);

    $on_time_rate = 0;
    if ($completion['total_processed'] > 0) {
        $on_time_rate = round(($completion['on_time_processed'] / $completion['total_processed']) * 100, 1);
    }

    $summary['total_processed'] = $completion['total_processed'];
    $summary['total_scheduled'] = $completion['total_scheduled'];
    $summary['on_time_rate'] = $on_time_rate;

    // 3. Debt Over Time (Last 12 Months)
    $stmt = $db->prepare("
        SELECT
            TO_CHAR(DATE_TRUNC('month', month_date), 'YYYY-MM') as month,
            COALESCE(SUM(remaining_debt), 0) as total_remaining
        FROM (
            SELECT
                generate_series(
                    DATE_TRUNC('month', CURRENT_DATE - INTERVAL '11 months'),
                    DATE_TRUNC('month', CURRENT_DATE),
                    '1 month'::interval
                ) as month_date
        ) months
        LEFT JOIN (
            SELECT
                DATE_TRUNC('month', isch.due_date) as month,
                SUM(isch.scheduled_amount) as remaining_debt
            FROM data.installment_schedules isch
            JOIN data.installment_plans ip ON isch.installment_plan_id = ip.id
            WHERE ip.ledger_id = ?
            AND isch.status = 'scheduled'
            GROUP BY DATE_TRUNC('month', isch.due_date)
        ) debt ON months.month_date = debt.month
        GROUP BY month_date
        ORDER BY month_date
    ");
    $stmt->execute([$ledger_id]);
    $debt_over_time = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Category Breakdown
    $stmt = $db->prepare("
        SELECT
            cat.uuid as category_uuid,
            cat.name as category_name,
            COUNT(DISTINCT ip.id) as plan_count,
            SUM(ip.purchase_amount) as total_amount,
            SUM(CASE
                WHEN ip.status = 'active'
                THEN (ip.number_of_installments - ip.completed_installments) * ip.installment_amount
                ELSE 0
            END) as remaining_debt,
            AVG(ip.purchase_amount) as average_plan_size
        FROM data.installment_plans ip
        JOIN data.accounts cat ON ip.category_account_id = cat.id
        WHERE ip.ledger_id = ?
        GROUP BY cat.uuid, cat.name
        ORDER BY total_amount DESC
    ");
    $stmt->execute([$ledger_id]);
    $category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Monthly Obligations (Next 12 Months)
    $stmt = $db->prepare("
        SELECT
            TO_CHAR(month_date, 'YYYY-MM') as month,
            COALESCE(SUM(total_amount), 0) as total_amount
        FROM (
            SELECT
                generate_series(
                    DATE_TRUNC('month', CURRENT_DATE),
                    DATE_TRUNC('month', CURRENT_DATE + INTERVAL '11 months'),
                    '1 month'::interval
                ) as month_date
        ) months
        LEFT JOIN (
            SELECT
                DATE_TRUNC('month', isch.due_date) as month,
                SUM(isch.scheduled_amount) as total_amount
            FROM data.installment_schedules isch
            JOIN data.installment_plans ip ON isch.installment_plan_id = ip.id
            WHERE ip.ledger_id = ?
            AND isch.status = 'scheduled'
            GROUP BY DATE_TRUNC('month', isch.due_date)
        ) obligations ON months.month_date = obligations.month
        GROUP BY month_date
        ORDER BY month_date
    ");
    $stmt->execute([$ledger_id]);
    $monthly_obligations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Active Plans with Details
    $stmt = $db->prepare("
        SELECT
            ip.uuid as plan_uuid,
            ip.description,
            ip.purchase_amount,
            ip.installment_amount,
            ip.number_of_installments,
            ip.completed_installments,
            ip.frequency,
            cc.name as credit_card_name,
            cat.name as category_name,
            (
                SELECT MIN(due_date)
                FROM data.installment_schedules
                WHERE installment_plan_id = ip.id
                AND status = 'scheduled'
            ) as next_due_date
        FROM data.installment_plans ip
        JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
        WHERE ip.ledger_id = ?
        AND ip.status = 'active'
        ORDER BY next_due_date ASC NULLS LAST
        LIMIT 10
    ");
    $stmt->execute([$ledger_id]);
    $active_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $response = [
        'success' => true,
        'data' => [
            'summary' => [
                'total_remaining_debt' => (int)($summary['total_remaining_debt'] ?? 0),
                'monthly_obligations' => (int)($summary['monthly_obligations'] ?? 0),
                'active_plan_count' => (int)($summary['active_plan_count'] ?? 0),
                'total_plan_count' => (int)($summary['total_plan_count'] ?? 0),
                'average_plan_size' => (int)($summary['average_plan_size'] ?? 0),
                'total_processed' => (int)($summary['total_processed'] ?? 0),
                'total_scheduled' => (int)($summary['total_scheduled'] ?? 0),
                'on_time_rate' => (float)$on_time_rate
            ],
            'debt_over_time' => array_map(function($row) {
                return [
                    'month' => $row['month'],
                    'total_remaining' => (int)$row['total_remaining']
                ];
            }, $debt_over_time),
            'category_breakdown' => array_map(function($row) {
                return [
                    'category_uuid' => $row['category_uuid'],
                    'category_name' => $row['category_name'],
                    'plan_count' => (int)$row['plan_count'],
                    'total_amount' => (int)$row['total_amount'],
                    'remaining_debt' => (int)$row['remaining_debt'],
                    'average_plan_size' => (int)$row['average_plan_size']
                ];
            }, $category_breakdown),
            'monthly_obligations' => array_map(function($row) {
                return [
                    'month' => $row['month'],
                    'total_amount' => (int)$row['total_amount']
                ];
            }, $monthly_obligations),
            'active_plans' => array_map(function($row) {
                return [
                    'plan_uuid' => $row['plan_uuid'],
                    'description' => $row['description'],
                    'purchase_amount' => (int)$row['purchase_amount'],
                    'installment_amount' => (int)$row['installment_amount'],
                    'number_of_installments' => (int)$row['number_of_installments'],
                    'completed_installments' => (int)$row['completed_installments'],
                    'frequency' => $row['frequency'],
                    'credit_card_name' => $row['credit_card_name'],
                    'category_name' => $row['category_name'],
                    'next_due_date' => $row['next_due_date']
                ];
            }, $active_plans)
        ]
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Database error in installment-reports.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
