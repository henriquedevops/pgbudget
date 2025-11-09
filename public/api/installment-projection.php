<?php
/**
 * API: Installment Projection
 * Returns projected installment payments for the next N months
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
requireAuth();

try {
    $ledger_uuid = $_GET['ledger'] ?? '';
    $months = min(12, max(1, intval($_GET['months'] ?? 6)));

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

    // Generate projection for next N months
    $projection = [];
    $currentDate = new DateTime();

    for ($i = 0; $i < $months; $i++) {
        $monthStart = (clone $currentDate)->modify("+$i months")->modify('first day of this month')->format('Y-m-d');
        $monthEnd = (clone $currentDate)->modify("+$i months")->modify('last day of this month')->format('Y-m-d');
        $monthLabel = (clone $currentDate)->modify("+$i months")->format('M Y');

        // Get total installment payments for this month
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(isc.scheduled_amount), 0) as total_amount
            FROM data.installment_schedules isc
            JOIN data.installment_plans ip ON isc.installment_plan_id = ip.id
            WHERE ip.ledger_id = ?
            AND isc.scheduled_date BETWEEN ? AND ?
            AND isc.status = 'pending'
        ");
        $stmt->execute([$ledger_id, $monthStart, $monthEnd]);
        $result = $stmt->fetch();

        $projection[] = [
            'month' => $monthStart,
            'label' => $monthLabel,
            'total_amount' => intval($result['total_amount'] ?? 0),
            'start_date' => $monthStart,
            'end_date' => $monthEnd
        ];
    }

    echo json_encode([
        'success' => true,
        'months' => $projection
    ]);

} catch (PDOException $e) {
    error_log("Database error in installment-projection.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}
