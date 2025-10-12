<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../index.php');
    exit;
}

$recurring_uuid = $_POST['recurring_uuid'] ?? '';
$ledger_uuid = $_POST['ledger_uuid'] ?? '';

if (empty($recurring_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Create transaction from recurring template
    $stmt = $db->prepare("SELECT api.create_from_recurring(?)");
    $stmt->execute([$recurring_uuid]);

    $result = $stmt->fetch();
    if ($result && !empty($result[0])) {
        $_SESSION['success'] = 'Transaction created successfully from recurring template!';
    } else {
        $_SESSION['error'] = 'Failed to create transaction from recurring template.';
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

header("Location: list.php?ledger=" . $ledger_uuid);
exit;
