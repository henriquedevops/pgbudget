<?php
/**
 * Debug version of Ledger Data API Endpoint
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set JSON header
header('Content-Type: application/json');

try {
    echo json_encode(['step' => 'Starting', 'time' => date('Y-m-d H:i:s')]) . "\n";

    // Test include paths
    if (!file_exists('../../config/database.php')) {
        throw new Exception('database.php not found at ../../config/database.php');
    }
    echo json_encode(['step' => 'database.php exists']) . "\n";

    require_once '../../config/database.php';
    echo json_encode(['step' => 'database.php loaded']) . "\n";

    if (!file_exists('../../includes/auth.php')) {
        throw new Exception('auth.php not found at ../../includes/auth.php');
    }
    echo json_encode(['step' => 'auth.php exists']) . "\n";

    require_once '../../includes/auth.php';
    echo json_encode(['step' => 'auth.php loaded']) . "\n";

    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo json_encode(['step' => 'session started', 'user' => $_SESSION['user_id'] ?? 'not set']) . "\n";

    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['step' => 'not authenticated', 'session' => $_SESSION]) . "\n";
        throw new Exception('User not authenticated');
    }
    echo json_encode(['step' => 'user authenticated', 'user_id' => $_SESSION['user_id']]) . "\n";

    // Get ledger UUID
    $ledger_uuid = isset($_GET['ledger']) ? sanitizeInput($_GET['ledger']) : '';
    echo json_encode(['step' => 'ledger from GET', 'ledger_uuid' => $ledger_uuid]) . "\n";

    if (empty($ledger_uuid)) {
        throw new Exception('Ledger UUID is required');
    }

    // Get database connection
    $db = getDbConnection();
    echo json_encode(['step' => 'database connected']) . "\n";

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['step' => 'user context set']) . "\n";

    // Verify ledger exists
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();
    echo json_encode(['step' => 'ledger query executed', 'found' => $ledger ? 'yes' : 'no']) . "\n";

    if (!$ledger) {
        throw new Exception('Ledger not found');
    }

    // Get accounts
    $stmt = $db->prepare("
        SELECT uuid, name, type, balance
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type IN ('asset', 'liability')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['step' => 'accounts loaded', 'count' => count($accounts)]) . "\n";

    // Get categories
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
    echo json_encode(['step' => 'categories loaded', 'count' => count($categories)]) . "\n";

    // Final result
    $result = [
        'success' => true,
        'ledger' => [
            'uuid' => $ledger['uuid'],
            'name' => $ledger['name']
        ],
        'accounts' => $accounts,
        'categories' => $categories
    ];

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}
