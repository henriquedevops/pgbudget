<?php
/**
 * API: Installment Impact Analysis
 * Returns category-by-category impact of upcoming installments
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

try {
    $ledger_uuid = $_GET['ledger'] ?? '';

    if (empty($ledger_uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ledger parameter']);
        exit;
    }

    $db = getDbConnection();
    setUserContext($db);

    // Verify ledger access
    $stmt = $db->prepare("SELECT id FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        http_response_code(404);
        echo json_encode(['error' => 'Ledger not found']);
        exit;
    }

    $ledger_id = $ledger['id'];
    $current_period = date('Ym');

    // Get category impact analysis
    // Show how much will be spent on installments in the next 30 days per category
    $stmt = $db->prepare("
        SELECT
            a.uuid as category_uuid,
            a.name as category_name,
            COALESCE(ba.budgeted_amount, 0) as budgeted,
            COALESCE(ba.actual_amount, 0) as actual,
            COALESCE(ba.budgeted_amount - ba.actual_amount, 0) as available,
            COALESCE(SUM(
                CASE
                    WHEN isc.status = 'pending'
                    AND isc.scheduled_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
                    THEN isc.scheduled_amount
                    ELSE 0
                END
            ), 0) as installment_impact
        FROM data.accounts a
        LEFT JOIN data.budget_amounts ba ON a.id = ba.account_id AND ba.period = ?
        LEFT JOIN data.installment_plans ip ON a.id = ip.category_account_id AND ip.status = 'active'
        LEFT JOIN data.installment_schedules isc ON ip.id = isc.installment_plan_id
        WHERE a.ledger_uuid = ?
        AND a.type = 'equity'
        AND (a.is_group = false OR a.is_group IS NULL)
        GROUP BY a.uuid, a.name, ba.budgeted_amount, ba.actual_amount
        HAVING COALESCE(SUM(
            CASE
                WHEN isc.status = 'pending'
                AND isc.scheduled_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
                THEN isc.scheduled_amount
                ELSE 0
            END
        ), 0) > 0
        ORDER BY installment_impact DESC
    ");
    $stmt->execute([$current_period, $ledger_uuid]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);

} catch (PDOException $e) {
    error_log("Database error in installment-impact.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}
