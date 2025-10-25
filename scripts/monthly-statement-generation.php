#!/usr/bin/env php
<?php
/**
 * Monthly Statement Generation Batch Job
 *
 * This script generates monthly billing statements for credit card accounts
 * on their statement day of month.
 *
 * Part of Phase 3 (Billing Cycle Management) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
 *
 * Usage:
 *   php /var/www/html/pgbudget/scripts/monthly-statement-generation.php [date]
 *
 * Arguments:
 *   date - Optional date in YYYY-MM-DD format (defaults to today)
 *
 * Examples:
 *   php monthly-statement-generation.php
 *   php monthly-statement-generation.php 2025-10-15
 *
 * Cron setup (run daily at 2:00 AM to check for statement days):
 *   0 2 * * * /usr/bin/php /var/www/html/pgbudget/scripts/monthly-statement-generation.php >> /var/log/pgbudget-statements.log 2>&1
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

// Get statement date from command line argument or use today
$statement_date = $argv[1] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statement_date)) {
    die("Error: Invalid date format. Use YYYY-MM-DD\n");
}

// Validate date is valid
$date_parts = explode('-', $statement_date);
if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
    die("Error: Invalid date: $statement_date\n");
}

echo "==========================================================\n";
echo "PGBudget Monthly Statement Generation\n";
echo "==========================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Statement Date: $statement_date\n";
echo "==========================================================\n\n";

try {
    $db = getDbConnection();

    // Call the database function to generate all statements
    echo "Generating statements for eligible accounts...\n\n";

    $stmt = $db->prepare("SELECT utils.generate_all_statements(?)");
    $stmt->execute([$statement_date]);

    $result = $stmt->fetchColumn();
    $result_data = json_decode($result, true);

    if (!$result_data || !isset($result_data['success'])) {
        throw new Exception("Failed to generate statements");
    }

    // Display results
    echo "==========================================================\n";
    echo "RESULTS\n";
    echo "==========================================================\n";
    echo "Total Processed: " . ($result_data['total_processed'] ?? 0) . "\n";
    echo "Successfully Generated: " . ($result_data['success_count'] ?? 0) . "\n";
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

            if (!empty($result['success']) && !empty($result['statement_uuid'])) {
                // Statement was generated
                $period_start = $result['statement_period_start'] ?? 'N/A';
                $period_end = $result['statement_period_end'] ?? 'N/A';
                $ending_balance = isset($result['ending_balance']) ? '$' . number_format($result['ending_balance'] / 100, 2) : '$0.00';
                $min_payment = isset($result['minimum_payment_due']) ? '$' . number_format($result['minimum_payment_due'] / 100, 2) : '$0.00';
                $due_date = $result['due_date'] ?? 'N/A';

                echo "✓ $account_name ($account_uuid)\n";
                echo "  Statement Period: $period_start to $period_end\n";
                echo "  Ending Balance: $ending_balance\n";
                echo "  Minimum Payment: $min_payment\n";
                echo "  Due Date: $due_date\n";

                if (!empty($result['purchases_amount'])) {
                    echo "  Purchases: $" . number_format($result['purchases_amount'] / 100, 2) . "\n";
                }
                if (!empty($result['payments_amount'])) {
                    echo "  Payments: $" . number_format($result['payments_amount'] / 100, 2) . "\n";
                }
                if (!empty($result['interest_charged'])) {
                    echo "  Interest: $" . number_format($result['interest_charged'] / 100, 2) . "\n";
                }
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
