<?php
/**
 * Ledger Data API Endpoint
 * Phase 3.4 - Quick-Add Transaction Modal
 *
 * Returns accounts and categories for a specific ledger
 */

session_start();

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Set JSON header
header('Content-Type: application/json');

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get ledger UUID from query parameter
$ledger_uuid = isset($_GET['ledger']) ? sanitizeInput($_GET['ledger']) : '';

if (empty($ledger_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ledger UUID is required']);
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Verify ledger exists and belongs to user
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ledger not found']);
        exit;
    }

    // Get accounts for this ledger (assets and liabilities only for transactions)
    $stmt = $db->prepare("
        SELECT uuid, name, type
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type IN ('asset', 'liability')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get categories (equity accounts that aren't special)
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        AND (is_group = false OR is_group IS NULL)
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ledger' => [
            'uuid' => $ledger['uuid'],
            'name' => $ledger['name']
        ],
        'accounts' => $accounts,
        'categories' => $categories
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
