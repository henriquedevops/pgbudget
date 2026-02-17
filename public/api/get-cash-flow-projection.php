<?php
/**
 * Cash Flow Projection JSON API Endpoint
 * Returns raw projection data for a ledger/period
 *
 * GET params:
 *   ledger       - ledger UUID
 *   start_month  - YYYY-MM or YYYY-MM-DD (default: current month)
 *   months       - number of months ahead (1-120, default: 24)
 *   type         - 'detail' | 'summary' (default: summary)
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$ledger_uuid = $_GET['ledger'] ?? '';
if (empty($ledger_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ledger parameter required']);
    exit;
}

$start_month_raw = $_GET['start_month'] ?? '';
if (preg_match('/^\d{4}-\d{2}$/', $start_month_raw)) {
    $start_month = $start_month_raw . '-01';
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_month_raw)) {
    $start_month = $start_month_raw;
} else {
    $start_month = date('Y-m-01');
}

$months_ahead = (int)($_GET['months'] ?? 24);
$months_ahead = max(1, min(120, $months_ahead));

$type = in_array($_GET['type'] ?? '', ['detail', 'summary']) ? ($_GET['type']) : 'summary';

try {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    if ($type === 'detail') {
        $stmt = $db->prepare("SELECT * FROM api.generate_cash_flow_projection(?, ?::date, ?)");
        $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'success'      => true,
            'ledger'       => $ledger_uuid,
            'start_month'  => $start_month,
            'months_ahead' => $months_ahead,
            'count'        => count($rows),
            'data'         => $rows,
        ]);
    } else {
        $stmt = $db->prepare("SELECT * FROM api.get_projection_summary(?, ?::date, ?)");
        $stmt->execute([$ledger_uuid, $start_month, $months_ahead]);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'success'      => true,
            'ledger'       => $ledger_uuid,
            'start_month'  => $start_month,
            'months_ahead' => $months_ahead,
            'count'        => count($rows),
            'data'         => $rows,
        ]);
    }

} catch (Exception $e) {
    error_log('Cash Flow Projection API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
