<?php
/**
 * Obligations API Endpoint
 * Handles AJAX requests for obligation operations
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'mark_paid':
                markPaymentAsPaid($db);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                exit;
        }
    }

    // Handle DELETE requests
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $obligation_uuid = $_GET['obligation_uuid'] ?? '';
        if (empty($obligation_uuid)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Obligation UUID required']);
            exit;
        }
        deleteObligation($db, $obligation_uuid);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

/**
 * Mark an obligation payment as paid
 */
function markPaymentAsPaid($db) {
    $payment_uuid = $_POST['payment_uuid'] ?? '';
    $paid_date = $_POST['paid_date'] ?? '';
    $actual_amount = $_POST['actual_amount'] ?? '';
    $notes = $_POST['notes'] ?? null;
    $create_transaction = isset($_POST['create_transaction']) && $_POST['create_transaction'] === '1';

    if (empty($payment_uuid) || empty($paid_date) || empty($actual_amount)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Mark the payment as paid
        $stmt = $db->prepare("
            SELECT * FROM api.mark_obligation_paid(
                p_payment_uuid := ?,
                p_paid_date := ?::date,
                p_actual_amount := ?::decimal,
                p_transaction_uuid := null,
                p_payment_account_uuid := null,
                p_payment_method := null,
                p_confirmation_number := null,
                p_notes := ?
            )
        ");
        $stmt->execute([
            $payment_uuid,
            $paid_date,
            $actual_amount,
            $notes
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            throw new Exception('Failed to mark payment as paid');
        }

        // TODO: If create_transaction is true, create a transaction
        // This will be implemented in Phase 5 (Transaction Integration)

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payment marked as paid successfully',
            'payment_uuid' => $result['payment_uuid'],
            'status' => $result['status'],
            'next_due_date' => $result['next_due_date']
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Delete an obligation
 */
function deleteObligation($db, $obligation_uuid) {
    try {
        $stmt = $db->prepare("SELECT api.delete_obligation(?)");
        $stmt->execute([$obligation_uuid]);
        $result = $stmt->fetchColumn();

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Obligation deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete obligation');
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
