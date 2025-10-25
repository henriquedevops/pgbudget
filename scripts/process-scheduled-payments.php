#!/usr/bin/env php
<?php
/**
 * Process Scheduled Payments Batch Job
 *
 * This script processes scheduled payments that are due today or overdue.
 * It also creates auto-payments from newly generated statements.
 *
 * Part of Phase 4 (Payment Scheduling) of CREDIT_CARD_LIMITS_DESIGN_GUIDE.md
 *
 * Usage:
 *   php /var/www/html/pgbudget/scripts/process-scheduled-payments.php [date]
 *
 * Arguments:
 *   date - Optional date in YYYY-MM-DD format (defaults to today)
 *
 * Examples:
 *   php process-scheduled-payments.php
 *   php process-scheduled-payments.php 2025-10-25
 *
 * Cron setup (run daily at 3:00 AM after statements are generated):
 *   0 3 * * * /usr/bin/php /var/www/html/pgbudget/scripts/process-scheduled-payments.php >> /var/log/pgbudget-payments.log 2>&1
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

// Get processing date from command line argument or use today
$processing_date = $argv[1] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $processing_date)) {
    die("Error: Invalid date format. Use YYYY-MM-DD\n");
}

// Validate date is valid
$date_parts = explode('-', $processing_date);
if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
    die("Error: Invalid date: $processing_date\n");
}

echo "==========================================================\n";
echo "PGBudget Scheduled Payment Processing\n";
echo "==========================================================\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Processing Date: $processing_date\n";
echo "==========================================================\n\n";

try {
    $db = getDbConnection();

    // Step 1: Create auto-payments from statements
    echo "Step 1: Creating auto-payments from statements...\n";
    echo "----------------------------------------------------------\n";

    $stmt = $db->prepare("SELECT utils.create_auto_payments_from_statements(?)");
    $stmt->execute([$processing_date]);

    $result = $stmt->fetchColumn();
    $auto_payment_result = json_decode($result, true);

    if (!$auto_payment_result || !isset($auto_payment_result['success'])) {
        throw new Exception("Failed to create auto-payments from statements");
    }

    echo "Auto-payments created: " . ($auto_payment_result['created_count'] ?? 0) . "\n";
    echo "Skipped: " . ($auto_payment_result['skipped_count'] ?? 0) . "\n";
    echo "\n";

    // Step 2: Process scheduled payments
    echo "Step 2: Processing scheduled payments...\n";
    echo "----------------------------------------------------------\n";

    $stmt = $db->prepare("SELECT utils.process_all_scheduled_payments(?)");
    $stmt->execute([$processing_date]);

    $result = $stmt->fetchColumn();
    $result_data = json_decode($result, true);

    if (!$result_data || !isset($result_data['success'])) {
        throw new Exception("Failed to process scheduled payments");
    }

    // Display results
    echo "\n";
    echo "==========================================================\n";
    echo "PAYMENT PROCESSING RESULTS\n";
    echo "==========================================================\n";
    echo "Total Processed: " . ($result_data['total_processed'] ?? 0) . "\n";
    echo "Successfully Processed: " . ($result_data['success_count'] ?? 0) . "\n";
    echo "Skipped: " . ($result_data['skipped_count'] ?? 0) . "\n";
    echo "Failed: " . ($result_data['failed_count'] ?? 0) . "\n";
    echo "==========================================================\n\n";

    // Display details for each payment
    if (!empty($result_data['results'])) {
        echo "PAYMENT DETAILS:\n";
        echo "----------------------------------------------------------\n";

        foreach ($result_data['results'] as $result) {
            $credit_card_name = $result['credit_card_name'] ?? 'Unknown';
            $payment_uuid = $result['payment_uuid'] ?? 'N/A';
            $payment_type = $result['payment_type'] ?? 'unknown';

            if (!empty($result['success']) && !empty($result['processed'])) {
                // Payment was processed
                $payment_amount = $result['payment_amount_display'] ?? '$0.00';
                $bank_account = $result['bank_account'] ?? 'Unknown';

                echo "✓ $credit_card_name ($payment_uuid)\n";
                echo "  Type: $payment_type\n";
                echo "  Amount: $payment_amount\n";
                echo "  From: $bank_account\n";
                echo "  Message: " . ($result['message'] ?? 'Success') . "\n";

            } else {
                // Failed or skipped
                $status = isset($result['error']) ? '✗' : '○';
                $message = $result['message'] ?? $result['error'] ?? 'Unknown status';

                echo "$status $credit_card_name ($payment_uuid)\n";
                echo "  Type: $payment_type\n";
                echo "  Message: $message\n";
            }
            echo "\n";
        }

        echo "----------------------------------------------------------\n";
    }

    // Exit with appropriate code
    $exit_code = ($result_data['failed_count'] ?? 0) > 0 ? 1 : 0;

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
