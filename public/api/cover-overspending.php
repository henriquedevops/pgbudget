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
        // Get overspent categories
        $ledger_uuid = $_GET['ledger'] ?? '';

        if (empty($ledger_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ledger UUID is required']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM api.get_overspent_categories(?)");
        $stmt->execute([$ledger_uuid]);
        $overspent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'overspent_categories' => $overspent
        ]);

    } elseif ($method === 'POST') {
        // Cover overspending
        $input = json_decode(file_get_contents('php://input'), true);

        $overspent_category_uuid = $input['overspent_category_uuid'] ?? '';
        $source_category_uuid = $input['source_category_uuid'] ?? '';
        $amount = $input['amount'] ?? null;

        if (empty($overspent_category_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Overspent category UUID is required']);
            exit;
        }

        if (empty($source_category_uuid)) {
            http_response_code(400);
            echo json_encode(['error' => 'Source category UUID is required']);
            exit;
        }

        // Call API function
        if ($amount !== null && $amount > 0) {
            $stmt = $db->prepare("SELECT api.cover_overspending(?, ?, ?)");
            $stmt->execute([$overspent_category_uuid, $source_category_uuid, $amount]);
        } else {
            $stmt = $db->prepare("SELECT api.cover_overspending(?, ?)");
            $stmt->execute([$overspent_category_uuid, $source_category_uuid]);
        }

        $transaction_uuid = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'transaction_uuid' => $transaction_uuid
        ]);

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
