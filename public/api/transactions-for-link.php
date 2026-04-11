<?php
/**
 * Transactions for Linking Modal
 * GET /api/transactions-for-link.php
 * Params: ledger_uuid, amount_cents (absolute), month (YYYY-MM-01)
 *
 * Returns transactions from the given ledger near the target month,
 * sorted by closeness to the projected amount.
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

$ledger_uuid  = trim($_GET['ledger_uuid']  ?? '');
$amount_cents = abs((int)($_GET['amount_cents'] ?? 0));
$month        = trim($_GET['month'] ?? '');

if (!$ledger_uuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ledger_uuid required']);
    exit;
}

try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Date window: ±2 months around target month; fallback: last 6 months
    if ($month) {
        $date_from = date('Y-m-d', strtotime($month . ' -2 months'));
        $date_to   = date('Y-m-d', strtotime($month . ' +3 months'));
    } else {
        $date_from = date('Y-m-d', strtotime('-6 months'));
        $date_to   = date('Y-m-d', strtotime('+1 month'));
    }

    $stmt = $db->prepare("
        SELECT
            t.uuid,
            t.date::text        AS date,
            t.description,
            t.amount,
            da.name             AS debit_account,
            da.type             AS debit_type,
            ca.name             AS credit_account,
            ca.type             AS credit_type,
            ABS(t.amount - ?)   AS amount_diff
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        JOIN data.ledgers  l  ON l.id  = t.ledger_id
        WHERE l.uuid = ?
          AND t.date >= ?::date
          AND t.date <  ?::date
          AND t.description NOT LIKE 'DELETED:%'
          AND t.description NOT LIKE 'REVERSAL:%'
        ORDER BY ABS(t.amount - ?) ASC, t.date DESC
        LIMIT 50
    ");
    $stmt->execute([$amount_cents, $ledger_uuid, $date_from, $date_to, $amount_cents]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Derive display account name (prefer asset side)
    foreach ($rows as &$r) {
        $r['account'] = ($r['debit_type'] === 'asset')
            ? $r['debit_account']
            : (($r['credit_type'] === 'asset') ? $r['credit_account'] : $r['debit_account']);
        unset($r['debit_type'], $r['credit_type'], $r['debit_account'], $r['credit_account']);
    }
    unset($r);

    echo json_encode(['success' => true, 'transactions' => $rows]);

} catch (Exception $e) {
    error_log('Transactions For Link Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
