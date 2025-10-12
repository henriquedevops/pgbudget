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

    $ledger_uuid = $_GET['ledger'] ?? '';

    if (empty($ledger_uuid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ledger UUID is required']);
        exit;
    }

    // Get all categories (equity accounts) for this ledger
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM data.accounts
        WHERE ledger_id = (SELECT id FROM data.ledgers WHERE uuid = ?)
          AND type = 'equity'
          AND name NOT IN ('Income', 'Unassigned', 'Off-budget')
          AND user_data = ?
          AND (metadata->>'is_cc_payment_category' IS NULL OR metadata->>'is_cc_payment_category' != 'true')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid, $_SESSION['user_id']]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
