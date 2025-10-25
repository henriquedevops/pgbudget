#!/usr/bin/env php
<?php
/**
 * Nightly Interest Accrual Batch Job
 *
 * This script processes daily interest accrual for all credit card accounts
 * with active limits and APR > 0.
 *
 * Part of Phase 2 (Interest Accrual) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
 *
 * Usage:
 *   php /var/www/html/pgbudget/scripts/nightly-interest-accrual.php [date]
 *
 * Arguments:
 *   date - Optional date in YYYY-MM-DD format (defaults to today)
 *
 * Examples:
 *   php nightly-interest-accrual.php
 *   php nightly-interest-accrual.php 2025-10-25
 *
 * Cron setup (run at 1:00 AM daily):
 *   0 1 * * * /usr/bin/php /var/www/html/pgbudget/scripts/nightly-interest-accrual.php >> /var/log/pgbudget-interest-accrual.log 2>&1
 */

// Set up error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure script is run from CLI
if (php_sapi_name() !== 'cli') {
    die("Error: This script can only be run from the command line\n");
}

// Load database configuration
require_once __DIR__ . '/../config/database.php';

// Get accrual date from command line argument or use today
$accrual_date = $argv[1] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $accrual_date)) {
    die("Error: Invalid date format. Use YYYY-MM-DD\n");
}

// Validate date is valid
$date_parts = explode('-', $accrual_date);
if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
    die("Error: Invalid date: $accrual_date\n");
}

echo "==========================================================\n";
echo "PGBudget Nightly Interest Accrual\n";
echo "==========================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Accrual Date: $accrual_date\n";
echo "==========================================================\n\n";

try {
    $db = getDbConnection();

    // Call the database function to process all interest accruals
    echo "Processing interest accrual for all eligible accounts...\n\n";

    $stmt = $db->prepare("SELECT utils.process_all_interest_accruals(?)");
    $stmt->execute([$accrual_date]);

    $result = $stmt->fetchColumn();
    $result_data = json_decode($result, true);

    if (!$result_data || !isset($result_data['success'])) {
        throw new Exception("Failed to process interest accruals");
    }

    // Display results
    echo "==========================================================\n";
    echo "RESULTS\n";
    echo "==========================================================\n";
    echo "Total Processed: " . ($result_data['total_processed'] ?? 0) . "\n";
    echo "Successfully Accrued: " . ($result_data['success_count'] ?? 0) . "\n";
    echo "Skipped: " . ($result_data['skipped_count'] ?? 0) . "\n";
    echo "Errors: " . ($result_data['error_count'] ?? 0) . "\n";
    echo "==========================================================\n\n";

    // Display details for each account
    if (!empty($result_data['results'])) {
        echo "DETAILS:\n";
        echo "----------------------------------------------------------\n";

        foreach ($result_data['results'] as $result) {
            $account_name = $result['account_name'] ?? 'Unknown';
            $account_uuid = $result['account_uuid'] ?? 'N/A';
            $message = $result['message'] ?? 'No message';

            if (!empty($result['accrued'])) {
                // Interest was accrued
                $interest = $result['interest_amount_display'] ?? '$0.00';
                $balance = '$' . number_format($result['balance'] / 100, 2);
                $apr = $result['apr'] ?? 0;

                echo "✓ $account_name ($account_uuid)\n";
                echo "  Balance: $balance\n";
                echo "  APR: {$apr}%\n";
                echo "  Interest Charged: $interest\n";
                echo "  Message: $message\n";
            } else {
                // Skipped or error
                $status = isset($result['error']) ? '✗' : '○';
                echo "$status $account_name ($account_uuid)\n";
                echo "  Message: $message\n";
                if (isset($result['error'])) {
                    echo "  Error: " . $result['error'] . "\n";
                }
            }
            echo "\n";
        }

        echo "----------------------------------------------------------\n";
    }

    // Exit with appropriate code
    $exit_code = ($result_data['error_count'] ?? 0) > 0 ? 1 : 0;

    echo "\n";
    echo "Batch job completed " . ($exit_code === 0 ? "successfully" : "with errors") . "\n";
    echo "==========================================================\n";

    exit($exit_code);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    echo "\nBatch job FAILED\n";
    echo "==========================================================\n";
    exit(2);
}
