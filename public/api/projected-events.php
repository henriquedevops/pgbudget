<?php
/**
 * Projected Events API Endpoint
 * Handles AJAX requests for projected event CRUD operations
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create':
                createProjectedEvent($db);
                break;

            case 'update':
                updateProjectedEvent($db);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $event_uuid = $_GET['event_uuid'] ?? '';
        if (empty($event_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Event UUID required']);
            exit;
        }
        deleteProjectedEvent($db, $event_uuid);
    }

} catch (Exception $e) {
    error_log("Projected Events API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

function createProjectedEvent($db) {
    $ledger_uuid = $_POST['ledger_uuid'] ?? '';
    $name        = $_POST['name'] ?? '';
    $amount      = !empty($_POST['amount']) ? $_POST['amount'] : null;
    $event_date  = $_POST['event_date'] ?? '';

    if (empty($ledger_uuid) || empty($name) || empty($amount) || empty($event_date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: name, amount, and event date are required']);
        exit;
    }

    $direction    = !empty($_POST['direction'])    ? $_POST['direction']    : 'outflow';
    $event_type   = !empty($_POST['event_type'])   ? $_POST['event_type']   : 'other';
    $description  = !empty($_POST['description'])  ? $_POST['description']  : null;
    $currency     = !empty($_POST['currency'])      ? $_POST['currency']     : 'BRL';
    $default_category_uuid = !empty($_POST['default_category_uuid']) ? $_POST['default_category_uuid'] : null;
    $is_confirmed = isset($_POST['is_confirmed']) && $_POST['is_confirmed'] === '1';
    $notes        = !empty($_POST['notes']) ? $_POST['notes'] : null;
    $frequency    = !empty($_POST['frequency']) ? $_POST['frequency'] : 'one_time';
    $recurrence_end_date = !empty($_POST['recurrence_end_date']) ? $_POST['recurrence_end_date'] : null;

    // Convert amount to cents (bigint)
    $amount_cents = intval(round(floatval($amount) * 100));

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.create_projected_event(
                p_ledger_uuid := ?,
                p_name := ?,
                p_amount := ?::bigint,
                p_event_date := ?::date,
                p_direction := ?,
                p_event_type := ?,
                p_description := ?,
                p_currency := ?,
                p_default_category_uuid := ?,
                p_is_confirmed := ?::boolean,
                p_notes := ?,
                p_frequency := ?,
                p_recurrence_end_date := ?::date
            )
        ");

        $stmt->execute([
            $ledger_uuid,
            $name,
            $amount_cents,
            $event_date,
            $direction,
            $event_type,
            $description,
            $currency,
            $default_category_uuid,
            $is_confirmed ? 'true' : 'false',
            $notes,
            $frequency,
            $recurrence_end_date,
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to create projected event');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Projected event created successfully',
            'event_uuid' => $result['uuid'],
            'event_name' => $result['name'],
        ]);

    } catch (Exception $e) {
        error_log("Create Projected Event Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function updateProjectedEvent($db) {
    $event_uuid = $_POST['event_uuid'] ?? '';

    if (empty($event_uuid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Event UUID required']);
        exit;
    }

    $name         = !empty($_POST['name'])        ? $_POST['name']        : null;
    $description  = !empty($_POST['description']) ? $_POST['description'] : null;
    $event_type   = !empty($_POST['event_type'])  ? $_POST['event_type']  : null;
    $direction    = !empty($_POST['direction'])   ? $_POST['direction']   : null;
    $amount       = !empty($_POST['amount'])      ? $_POST['amount']      : null;
    $currency     = !empty($_POST['currency'])    ? $_POST['currency']    : null;
    $event_date   = !empty($_POST['event_date'])  ? $_POST['event_date']  : null;
    $default_category_uuid    = !empty($_POST['default_category_uuid'])    ? $_POST['default_category_uuid']    : null;
    $linked_transaction_uuid  = !empty($_POST['linked_transaction_uuid'])  ? $_POST['linked_transaction_uuid']  : null;
    $notes        = !empty($_POST['notes'])       ? $_POST['notes']       : null;

    $is_confirmed = isset($_POST['is_confirmed']) ? ($_POST['is_confirmed'] === '1') : null;
    $is_realized  = isset($_POST['is_realized'])  ? ($_POST['is_realized']  === '1') : null;
    $frequency    = !empty($_POST['frequency']) ? $_POST['frequency'] : null;
    $recurrence_end_date = !empty($_POST['recurrence_end_date']) ? $_POST['recurrence_end_date'] : null;

    // Convert amount to cents if provided
    $amount_cents = null;
    if ($amount !== null) {
        $amount_cents = intval(round(floatval($amount) * 100));
    }

    try {
        $stmt = $db->prepare("
            SELECT * FROM api.update_projected_event(
                p_event_uuid := ?,
                p_name := ?,
                p_description := ?,
                p_event_type := ?,
                p_direction := ?,
                p_amount := ?::bigint,
                p_currency := ?,
                p_event_date := ?::date,
                p_default_category_uuid := ?,
                p_is_confirmed := ?::boolean,
                p_is_realized := ?::boolean,
                p_linked_transaction_uuid := ?,
                p_notes := ?,
                p_frequency := ?,
                p_recurrence_end_date := ?::date
            )
        ");

        $stmt->execute([
            $event_uuid,
            $name,
            $description,
            $event_type,
            $direction,
            $amount_cents,
            $currency,
            $event_date,
            $default_category_uuid,
            $is_confirmed !== null ? ($is_confirmed ? 'true' : 'false') : null,
            $is_realized  !== null ? ($is_realized  ? 'true' : 'false') : null,
            $linked_transaction_uuid,
            $notes,
            $frequency,
            $recurrence_end_date,
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to update projected event');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Projected event updated successfully',
            'event_uuid' => $result['uuid'],
            'event_name' => $result['name'],
        ]);

    } catch (Exception $e) {
        error_log("Update Projected Event Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function deleteProjectedEvent($db, $event_uuid) {
    try {
        $stmt = $db->prepare("SELECT api.delete_projected_event(?)");
        $stmt->execute([$event_uuid]);
        $result = $stmt->fetchColumn();

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Projected event deleted successfully',
            ]);
        } else {
            throw new Exception('Failed to delete projected event');
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
