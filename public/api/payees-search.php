<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Set JSON header
header('Content-Type: application/json');

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get search query
    $search = $_GET['q'] ?? '';

    if (empty(trim($search))) {
        // Return empty array if no search term
        echo json_encode([]);
        exit;
    }

    // Search payees
    $stmt = $db->prepare("SELECT * FROM api.search_payees(?)");
    $stmt->execute([trim($search)]);
    $payees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $results = array_map(function($payee) {
        return [
            'uuid' => $payee['uuid'],
            'name' => $payee['name'],
            'default_category_uuid' => $payee['default_category_uuid'],
            'default_category_name' => $payee['default_category_name'],
            'auto_categorize' => $payee['auto_categorize'] === 't',
            'transaction_count' => (int)$payee['transaction_count'],
            'last_used' => $payee['last_used']
        ];
    }, $payees);

    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
