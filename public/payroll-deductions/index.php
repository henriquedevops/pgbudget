<?php
/**
 * Payroll Deductions Index - Redirect to combined dashboard
 */
$ledger = $_GET['ledger'] ?? '';
header("Location: ../income-sources/index.php?ledger=" . urlencode($ledger) . "&tab=deductions");
exit;
