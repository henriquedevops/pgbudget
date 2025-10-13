<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON response header
header('Content-Type: application/json');

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get uncleared transactions or reconciliation history
        $action = $_GET['action'] ?? 'uncleared';
        $account_uuid = $_GET['account'] ?? '';

        if (empty($account_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Account UUID is required']);
            exit;
        }

        if ($action === 'uncleared') {
            // Get uncleared transactions
            $stmt = $db->prepare("SELECT * FROM api.get_uncleared_transactions(?)");
            $stmt->execute([$account_uuid]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'transactions' => $transactions
            ]);

        } elseif ($action === 'history') {
            // Get reconciliation history
            $stmt = $db->prepare("SELECT * FROM api.get_reconciliation_history(?)");
            $stmt->execute([$account_uuid]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'history' => $history
            ]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "uncleared" or "history"']);
        }

    } elseif ($method === 'POST') {
        // Reconcile account or toggle cleared status
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? 'reconcile';

        if ($action === 'toggle_cleared') {
            // Toggle transaction cleared status
            $transaction_uuid = $input['transaction_uuid'] ?? '';

            if (empty($transaction_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Transaction UUID is required']);
                exit;
            }

            $stmt = $db->prepare("SELECT api.toggle_transaction_cleared(?)");
            $stmt->execute([$transaction_uuid]);
            $new_status = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'new_status' => $new_status
            ]);

        } elseif ($action === 'reconcile') {
            // Reconcile account
            $account_uuid = $input['account_uuid'] ?? '';
            $reconciliation_date = $input['reconciliation_date'] ?? '';
            $statement_balance = $input['statement_balance'] ?? null;
            $transaction_uuids = $input['transaction_uuids'] ?? [];
            $notes = $input['notes'] ?? null;

            if (empty($account_uuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'Account UUID is required']);
                exit;
            }

            if (empty($reconciliation_date)) {
                http_response_code(400);
                echo json_encode(['error' => 'Reconciliation date is required']);
                exit;
            }

            if ($statement_balance === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Statement balance is required']);
                exit;
            }

            // Convert transaction UUIDs to PostgreSQL array format
            $transaction_uuids_pg = $transaction_uuids ? '{' . implode(',', array_map(function($uuid) {
                return '"' . str_replace('"', '""', $uuid) . '"';
            }, $transaction_uuids)) . '}' : null;

            // Call reconciliation function
            if ($notes !== null) {
                $stmt = $db->prepare("SELECT api.reconcile_account(?, ?::date, ?, ?::text[], ?)");
                $stmt->execute([$account_uuid, $reconciliation_date, $statement_balance, $transaction_uuids_pg, $notes]);
            } else {
                $stmt = $db->prepare("SELECT api.reconcile_account(?, ?::date, ?, ?::text[])");
                $stmt->execute([$account_uuid, $reconciliation_date, $statement_balance, $transaction_uuids_pg]);
            }

            $reconciliation_uuid = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'reconciliation_uuid' => $reconciliation_uuid
            ]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use "toggle_cleared" or "reconcile"']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
