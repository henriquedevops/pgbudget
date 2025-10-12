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

    // Delete recurring transaction
    $stmt = $db->prepare("SELECT api.delete_recurring_transaction(?)");
    $stmt->execute([$recurring_uuid]);

    $result = $stmt->fetch();
    if ($result && $result[0] === true) {
        $_SESSION['success'] = 'Recurring transaction deleted successfully!';
    } else {
        $_SESSION['error'] = 'Failed to delete recurring transaction.';
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
}

header("Location: list.php?ledger=" . $ledger_uuid);
exit;
