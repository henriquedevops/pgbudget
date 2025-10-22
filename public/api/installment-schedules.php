<?php
/**
 * API: Installment Schedules
 * Returns installment schedules for a ledger
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

try {
    $ledger_uuid = $_GET['ledger'] ?? '';
    $plan_uuid = $_GET['plan'] ?? '';
    $upcoming_days = isset($_GET['upcoming']) ? intval($_GET['upcoming']) : null;
    $status = $_GET['status'] ?? '';

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

    // Build query
    $where_conditions = ["ip.ledger_id = ?"];
    $params = [$ledger_id];

    if (!empty($plan_uuid)) {
        $where_conditions[] = "ip.uuid = ?";
        $params[] = $plan_uuid;
    }

    if (!empty($status)) {
        $where_conditions[] = "isc.status = ?";
        $params[] = $status;
    }

    if ($upcoming_days !== null) {
        $where_conditions[] = "isc.scheduled_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '$upcoming_days days'";
        $where_conditions[] = "isc.status = 'pending'";
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get schedules
    $stmt = $db->prepare("
        SELECT
            isc.uuid,
            isc.scheduled_date,
            isc.scheduled_amount,
            isc.installment_number,
            isc.status,
            isc.processed_date,
            isc.actual_amount,
            ip.uuid as plan_uuid,
            ip.description as plan_description,
            ip.number_of_installments as total_installments,
            ip.status as plan_status,
            a.name as category_name,
            a.uuid as category_uuid,
            cc.name as credit_card_name,
            cc.uuid as credit_card_uuid
        FROM data.installment_schedules isc
        JOIN data.installment_plans ip ON isc.installment_plan_id = ip.id
        LEFT JOIN data.accounts a ON ip.category_account_id = a.id
        LEFT JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        WHERE $where_clause
        ORDER BY isc.scheduled_date ASC, isc.installment_number ASC
    ");
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'schedules' => $schedules
    ]);

} catch (PDOException $e) {
    error_log("Database error in installment-schedules.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}
