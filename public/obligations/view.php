<?php
/**
 * View Obligation Details Page
 * Will be implemented in Phase 4
 */

require_once '../../includes/session.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$obligation_uuid = $_GET['obligation'] ?? '';

if (empty($ledger_uuid) || empty($obligation_uuid)) {
    $_SESSION['error'] = 'Invalid parameters.';
    header('Location: ../index.php');
    exit;
}

$_SESSION['info'] = 'View obligation details functionality coming in Phase 4!';
header("Location: index.php?ledger=$ledger_uuid");
exit;
