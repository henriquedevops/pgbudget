<?php
/**
 * Link Projected Event API
 * POST /api/link-projected-event.php
 *
 * JSON body (multi-event format):
 *   {
 *     events: [{ event_uuid, month }],  // array — one or many projections to link
 *     txn_uuid,
 *     treat_as_interest,
 *     interest_amount_cents             // only stored on the first/primary event
 *   }
 *
 * Legacy single-event format (backward-compat):
 *   { event_uuid, txn_uuid, month, treat_as_interest, interest_amount_cents }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$txn_uuid = trim($body['txn_uuid'] ?? '');
$treat_as_interest     = !empty($body['treat_as_interest']);
$interest_amount_cents = isset($body['interest_amount_cents']) ? (int)$body['interest_amount_cents'] : null;

if (!$treat_as_interest) {
    $interest_amount_cents = null;
}

// Normalise to an array of { event_uuid, month } entries
if (!empty($body['events']) && is_array($body['events'])) {
    $events = $body['events'];
} elseif (!empty($body['event_uuid'])) {
    // Legacy single-event format
    $events = [[
        'event_uuid' => trim($body['event_uuid']),
        'month'      => trim($body['month'] ?? ''),
    ]];
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'events array (or legacy event_uuid) is required']);
    exit;
}

if (!$txn_uuid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'txn_uuid is required']);
    exit;
}

if (empty($events)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'events array must not be empty']);
    exit;
}

try {
    $db   = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Fetch the real transaction's date and amount once (used for recurring events)
    $txnStmt = $db->prepare("SELECT date::text AS txn_date, amount FROM data.transactions WHERE uuid = ?");
    $txnStmt->execute([$txn_uuid]);
    $txn = $txnStmt->fetch(PDO::FETCH_ASSOC);
    if (!$txn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Transaction not found']);
        exit;
    }

    $db->beginTransaction();

    foreach ($events as $idx => $ev) {
        $event_uuid = trim($ev['event_uuid'] ?? '');
        $month      = trim($ev['month']      ?? '');

        if (!$event_uuid) continue;

        // Only apply interest to the first (primary) event in the bundle
        $ev_interest = ($idx === 0) ? $interest_amount_cents : null;

        // Fetch frequency
        $stmt = $db->prepare("SELECT frequency FROM api.projected_events WHERE uuid = ?");
        $stmt->execute([$event_uuid]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Projected event not found: {$event_uuid}"]);
            exit;
        }

        if ($event['frequency'] === 'one_time') {
            $stmt = $db->prepare("
                SELECT uuid FROM api.update_projected_event(
                    p_event_uuid              := ?,
                    p_is_realized             := true,
                    p_linked_transaction_uuid := ?,
                    p_interest_amount_cents   := ?::bigint
                )
            ");
            $stmt->execute([$event_uuid, $txn_uuid, $ev_interest]);

        } else {
            if (!$month) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "month is required for recurring event: {$event_uuid}"]);
                exit;
            }

            $stmt = $db->prepare("
                SELECT uuid FROM api.realize_projected_event_occurrence(
                    p_event_uuid            := ?,
                    p_scheduled_month       := ?::date,
                    p_realized_date         := ?::date,
                    p_realized_amount       := ?::bigint,
                    p_transaction_uuid      := ?,
                    p_interest_amount_cents := ?::bigint
                )
            ");
            $stmt->execute([$event_uuid, $month, $txn['txn_date'], (int)$txn['amount'], $txn_uuid, $ev_interest]);
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'linked' => count($events)]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Link Projected Event Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
