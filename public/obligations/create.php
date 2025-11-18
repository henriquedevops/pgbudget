<?php
/**
 * Create Obligation Page
 * Will be implemented in Phase 3
 */

require_once '../../includes/session.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

$_SESSION['info'] = 'Create obligation functionality coming in Phase 3!';
header("Location: index.php?ledger=$ledger_uuid");
exit;
