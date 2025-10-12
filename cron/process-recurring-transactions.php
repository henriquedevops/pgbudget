#!/usr/bin/env php
<?php
/**
 * Cron Job: Process Recurring Transactions
 *
 * This script automatically creates transactions from recurring templates
 * when auto_create is enabled and the transaction is due.
 *
 * Usage: php /var/www/html/pgbudget/cron/process-recurring-transactions.php
 *
 * Recommended cron schedule: Run every hour
 * Crontab entry: 0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
 */

// Set working directory to project root
chdir(dirname(__DIR__));

// Load configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email/EmailService.php';

// Ensure this script is run from CLI only
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Configuration
$logFile = __DIR__ . '/../logs/recurring-transactions.log';
$errorLogFile = __DIR__ . '/../logs/recurring-transactions-errors.log';
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

// Helper function to log messages
function logMessage($message, $level = 'INFO', $isError = false) {
    global $logFile, $errorLogFile, $verbose;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";

    // Always log to main log file
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Log errors to separate error log
    if ($isError) {
        file_put_contents($errorLogFile, $logEntry, FILE_APPEND);
    }

    // Output to console if verbose or if it's an error
    if ($verbose || $isError || $level === 'ERROR') {
        echo $logEntry;
    }
}

// Ensure log directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

logMessage("=== Starting Recurring Transactions Processor ===");
if ($dryRun) {
    logMessage("DRY RUN MODE - No transactions will be created", 'INFO');
}

try {
    $db = getDbConnection();
    $emailService = new EmailService();

    // Get all users with recurring transactions
    $stmt = $db->prepare("
        SELECT DISTINCT user_data
        FROM data.recurring_transactions
        WHERE enabled = true
          AND auto_create = true
          AND next_date <= CURRENT_DATE
          AND (end_date IS NULL OR end_date >= CURRENT_DATE)
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    logMessage("Found " . count($users) . " users with due recurring transactions");

    $totalProcessed = 0;
    $totalCreated = 0;
    $totalFailed = 0;

    foreach ($users as $userId) {
        logMessage("Processing user: $userId");

        // Track transactions and failures for this user
        $userCreatedTransactions = [];
        $userFailedTransactions = [];

        // Set user context for this user
        $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
        $stmt->execute([$userId]);

        // Get user email and notification preferences
        $stmt = $db->prepare("
            SELECT u.email, u.first_name, u.last_name,
                   np.email_on_recurring_transaction,
                   np.email_on_recurring_transaction_failed
            FROM data.users u
            LEFT JOIN data.notification_preferences np ON np.username = u.username
            WHERE u.username = ?
        ");
        $stmt->execute([$userId]);
        $userInfo = $stmt->fetch();

        // Get all due recurring transactions for this user
        $stmt = $db->prepare("
            SELECT
                rt.uuid,
                rt.description,
                rt.amount,
                rt.frequency,
                rt.next_date,
                rt.transaction_type,
                l.name as ledger_name,
                a.name as account_name,
                c.name as category_name
            FROM data.recurring_transactions rt
            JOIN data.ledgers l ON l.id = rt.ledger_id
            JOIN data.accounts a ON a.id = rt.account_id
            LEFT JOIN data.accounts c ON c.id = rt.category_id
            WHERE rt.user_data = ?
              AND rt.enabled = true
              AND rt.auto_create = true
              AND rt.next_date <= CURRENT_DATE
              AND (rt.end_date IS NULL OR rt.end_date >= CURRENT_DATE)
            ORDER BY rt.next_date, rt.description
        ");
        $stmt->execute([$userId]);
        $dueTransactions = $stmt->fetchAll();

        logMessage("  Found " . count($dueTransactions) . " due recurring transactions");

        foreach ($dueTransactions as $recurring) {
            $totalProcessed++;

            $logDetails = sprintf(
                "  [%s] %s - %s (%s) - Next: %s",
                $recurring['uuid'],
                $recurring['description'],
                formatCurrency($recurring['amount']),
                $recurring['frequency'],
                $recurring['next_date']
            );

            logMessage($logDetails);

            if ($dryRun) {
                logMessage("    [DRY RUN] Would create transaction", 'INFO');
                $totalCreated++;
                $userCreatedTransactions[] = $recurring;
                continue;
            }

            try {
                // Create transaction from recurring template
                $stmt = $db->prepare("SELECT api.create_from_recurring(?)");
                $stmt->execute([$recurring['uuid']]);
                $result = $stmt->fetch();

                if ($result && !empty($result[0])) {
                    $transactionUuid = $result[0];
                    logMessage("    ✓ Created transaction: $transactionUuid", 'SUCCESS');
                    $totalCreated++;

                    // Track successful creation for email
                    $userCreatedTransactions[] = $recurring;

                    // Log details for audit
                    logMessage(sprintf(
                        "    Transaction details: %s -> %s | %s | Amount: %s",
                        $recurring['account_name'],
                        $recurring['category_name'] ?? 'Unassigned',
                        $recurring['ledger_name'],
                        formatCurrency($recurring['amount'])
                    ), 'INFO');
                } else {
                    logMessage("    ✗ Failed to create transaction (no UUID returned)", 'ERROR', true);
                    $totalFailed++;
                    $userFailedTransactions[] = array_merge($recurring, ['error' => 'No UUID returned']);
                }
            } catch (PDOException $e) {
                $errorMsg = "    ✗ Database error: " . $e->getMessage();
                logMessage($errorMsg, 'ERROR', true);
                $totalFailed++;
                $userFailedTransactions[] = array_merge($recurring, ['error' => $e->getMessage()]);

                // Continue processing other transactions even if one fails
                continue;
            } catch (Exception $e) {
                $errorMsg = "    ✗ Unexpected error: " . $e->getMessage();
                logMessage($errorMsg, 'ERROR', true);
                $totalFailed++;
                $userFailedTransactions[] = array_merge($recurring, ['error' => $e->getMessage()]);
                continue;
            }
        }

        // Send email notifications for this user
        if (!$dryRun && $userInfo && $emailService->isEnabled()) {
            $userName = trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? '')) ?: $userId;

            // Send success notification if enabled and transactions were created
            if (!empty($userCreatedTransactions) && ($userInfo['email_on_recurring_transaction'] ?? true)) {
                try {
                    logMessage("  Sending success notification to: " . $userInfo['email']);
                    $emailService->sendRecurringTransactionNotification(
                        $userInfo['email'],
                        $userName,
                        $userCreatedTransactions
                    );
                    logMessage("  ✓ Email sent successfully");
                } catch (Exception $e) {
                    logMessage("  ✗ Failed to send email: " . $e->getMessage(), 'WARNING');
                }
            }

            // Send failure notification if enabled and there were failures
            if (!empty($userFailedTransactions) && ($userInfo['email_on_recurring_transaction_failed'] ?? true)) {
                try {
                    logMessage("  Sending failure notification to: " . $userInfo['email']);
                    $emailService->sendRecurringTransactionFailureNotification(
                        $userInfo['email'],
                        $userName,
                        $userFailedTransactions
                    );
                    logMessage("  ✓ Failure email sent successfully");
                } catch (Exception $e) {
                    logMessage("  ✗ Failed to send failure email: " . $e->getMessage(), 'WARNING');
                }
            }
        }
    }

    // Summary
    logMessage("=== Processing Complete ===");
    logMessage("Total recurring transactions processed: $totalProcessed");
    logMessage("Successfully created: $totalCreated");
    logMessage("Failed: $totalFailed");

    if ($dryRun) {
        logMessage("DRY RUN completed - no actual transactions were created");
    }

    // Exit with appropriate code
    exit($totalFailed > 0 ? 1 : 0);

} catch (PDOException $e) {
    $errorMsg = "Database connection error: " . $e->getMessage();
    logMessage($errorMsg, 'FATAL', true);
    exit(2);
} catch (Exception $e) {
    $errorMsg = "Fatal error: " . $e->getMessage();
    logMessage($errorMsg, 'FATAL', true);
    exit(2);
}
