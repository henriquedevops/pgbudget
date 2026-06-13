<?php
/**
 * Payroll Deductions Index - Redirect to combined dashboard
 */
require_once '../../includes/session.php';
require_once '../../config/database.php';
$ledger = pgb_current_ledger();
header("Location: ../income-sources/index.php?ledger=" . urlencode($ledger) . "&tab=deductions");
exit;
