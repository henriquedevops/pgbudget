<?php
// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting test...\n";

// Test include paths
echo "Testing includes...\n";
require_once '../../config/database.php';
echo "✓ database.php loaded\n";

require_once '../../includes/auth.php';
echo "✓ auth.php loaded\n";

// Start session
session_start();
$_SESSION['user_id'] = 'demo_user';

// Test database connection
echo "\nTesting database connection...\n";
try {
    $db = getDbConnection();
    echo "✓ Database connected\n";

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);
    echo "✓ User context set\n";

    // Test ledger query
    $ledger_uuid = 'eNF2EkfD';
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if ($ledger) {
        echo "✓ Ledger found: " . $ledger['name'] . "\n";
    } else {
        echo "✗ Ledger not found\n";
    }

    // Test accounts query
    $stmt = $db->prepare("
        SELECT uuid, name, type, balance
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type IN ('asset', 'liability')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($accounts) . " accounts\n";

    // Test categories query
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($categories) . " categories\n";

    echo "\n✅ All tests passed!\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
