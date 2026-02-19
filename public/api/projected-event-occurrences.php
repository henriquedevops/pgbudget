<?php
/**
 * Projected Event Occurrences API
 * Handles per-occurrence realization for recurring projected events.
 *
 * GET  ?event_uuid=xxx          → list realized occurrences for an event
 * POST action=realize            → mark a specific month's occurrence as realized
 * POST action=unrealize          → undo realization (delete occurrence record)
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

try {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $event_uuid = $_GET['event_uuid'] ?? '';
        if (empty($event_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'event_uuid required']);
            exit;
        }
        getOccurrences($db, $event_uuid);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'realize') {
            realizeOccurrence($db);
        } elseif ($action === 'unrealize') {
            unrealizeOccurrence($db);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Projected Event Occurrences API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

function getOccurrences($db, $event_uuid) {
    $stmt = $db->prepare("SELECT * FROM api.get_projected_event_occurrences(?)");
    $stmt->execute([$event_uuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'occurrences' => $rows]);
}

function realizeOccurrence($db) {
    $event_uuid      = $_POST['event_uuid']      ?? '';
    $scheduled_month = $_POST['scheduled_month'] ?? '';
    $realized_date   = $_POST['realized_date']   ?? '';

    if (empty($event_uuid) || empty($scheduled_month) || empty($realized_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'event_uuid, scheduled_month, and realized_date are required']);
        return;
    }

    $realized_amount = null;
    if (!empty($_POST['realized_amount'])) {
        $realized_amount = intval(round(floatval($_POST['realized_amount']) * 100));
    }
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;

    $stmt = $db->prepare("
        SELECT * FROM api.realize_projected_event_occurrence(
            p_event_uuid      := ?,
            p_scheduled_month := ?::date,
            p_realized_date   := ?::date,
            p_realized_amount := ?::bigint,
            p_notes           := ?
        )
    ");
    $stmt->execute([$event_uuid, $scheduled_month, $realized_date, $realized_amount, $notes]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Failed to realize occurrence');
    }

    echo json_encode(['success' => true, 'occurrence' => $result]);
}

function unrealizeOccurrence($db) {
    $event_uuid      = $_POST['event_uuid']      ?? '';
    $scheduled_month = $_POST['scheduled_month'] ?? '';

    if (empty($event_uuid) || empty($scheduled_month)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'event_uuid and scheduled_month are required']);
        return;
    }

    $stmt = $db->prepare("
        SELECT api.unrealize_projected_event_occurrence(
            p_event_uuid      := ?,
            p_scheduled_month := ?::date
        )
    ");
    $stmt->execute([$event_uuid, $scheduled_month]);
    $ok = $stmt->fetchColumn();

    echo json_encode(['success' => (bool)$ok]);
}
