<?php
/**
 * Email Service
 *
 * Handles sending emails for PGBudget notifications
 */

class EmailService {
    private $fromEmail;
    private $fromName;
    private $enabled;

    public function __construct() {
        $this->fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@pgbudget.local';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'PGBudget';
        $this->enabled = ($_ENV['MAIL_ENABLED'] ?? 'false') === 'true';
    }

    /**
     * Check if email is enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }

    /**
     * Send recurring transaction notification email
     */
    public function sendRecurringTransactionNotification($toEmail, $toName, $transactions) {
        if (!$this->enabled) {
            return false;
        }

        $subject = 'Recurring Transactions Created - ' . date('F j, Y');
        $htmlBody = $this->renderRecurringTransactionTemplate($toName, $transactions);
        $textBody = $this->renderRecurringTransactionTextTemplate($toName, $transactions);

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Send recurring transaction failure notification
     */
    public function sendRecurringTransactionFailureNotification($toEmail, $toName, $failures) {
        if (!$this->enabled) {
            return false;
        }

        $subject = 'Action Required: Recurring Transaction Failures - ' . date('F j, Y');
        $htmlBody = $this->renderFailureTemplate($toName, $failures);
        $textBody = $this->renderFailureTextTemplate($toName, $failures);

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Send daily summary email
     */
    public function sendDailySummary($toEmail, $toName, $transactions) {
        if (!$this->enabled) {
            return false;
        }

        $subject = 'Daily Summary: ' . count($transactions) . ' Transactions Created - ' . date('F j, Y');
        $htmlBody = $this->renderDailySummaryTemplate($toName, $transactions);
        $textBody = $this->renderDailySummaryTextTemplate($toName, $transactions);

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Internal send method
     */
    private function send($to, $subject, $htmlBody, $textBody) {
        $headers = [
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8'
        ];

        // Use mail() function - can be replaced with PHPMailer or other service
        $success = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

        // Log email attempt
        $logMessage = sprintf(
            "[%s] Email %s: To=%s, Subject=%s\n",
            date('Y-m-d H:i:s'),
            $success ? 'SENT' : 'FAILED',
            $to,
            $subject
        );
        error_log($logMessage, 3, __DIR__ . '/../../logs/email.log');

        return $success;
    }

    /**
     * Render recurring transaction HTML template
     */
    private function renderRecurringTransactionTemplate($name, $transactions) {
        $transactionRows = '';
        $totalAmount = 0;

        foreach ($transactions as $txn) {
            $amount = $this->formatCurrency($txn['amount']);
            $type = $txn['transaction_type'] === 'inflow' ? 'Income' : 'Expense';
            $typeColor = $txn['transaction_type'] === 'inflow' ? '#38a169' : '#e53e3e';

            $transactionRows .= sprintf(
                '<tr>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; color: %s; font-weight: 600;">%s</td>
                </tr>',
                htmlspecialchars($txn['description']),
                htmlspecialchars($txn['account_name']),
                htmlspecialchars($txn['category_name'] ?? 'Unassigned'),
                $typeColor,
                $amount
            );

            if ($txn['transaction_type'] === 'outflow') {
                $totalAmount -= $txn['amount'];
            } else {
                $totalAmount += $txn['amount'];
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Transactions Created</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0; font-size: 24px;">💰 PGBudget</h1>
        <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Recurring Transactions Created</p>
    </div>

    <div style="background: #f7fafc; padding: 30px; border-radius: 0 0 8px 8px;">
        <p style="margin: 0 0 20px 0; font-size: 16px;">Hello {$name},</p>

        <p style="margin: 0 0 20px 0;">Your recurring transactions have been automatically created for today, <strong>{$this->formatDate()}</strong>.</p>

        <table style="width: 100%; background: white; border-radius: 8px; border-collapse: collapse; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <thead>
                <tr style="background: #edf2f7;">
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Description</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Account</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Category</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Amount</th>
                </tr>
            </thead>
            <tbody>
                {$transactionRows}
            </tbody>
        </table>

        <div style="background: #ebf8ff; border-left: 4px solid #3182ce; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 14px; color: #2c5282;">
                <strong>Total Impact:</strong> {$this->formatCurrency($totalAmount)}<br>
                <strong>Transactions Created:</strong> {$this->count($transactions)}
            </p>
        </div>

        <p style="margin: 0 0 10px 0; font-size: 14px; color: #718096;">
            These transactions have been added to your budget automatically based on your recurring transaction schedule.
        </p>

        <div style="text-align: center; margin-top: 30px;">
            <a href="https://your-pgbudget-url.com" style="display: inline-block; background: #3182ce; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 600;">View in PGBudget</a>
        </div>

        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">

        <p style="margin: 0; font-size: 12px; color: #a0aec0; text-align: center;">
            You received this email because you have email notifications enabled for recurring transactions.<br>
            To change your notification preferences, visit your account settings in PGBudget.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render recurring transaction plain text template
     */
    private function renderRecurringTransactionTextTemplate($name, $transactions) {
        $text = "Hello {$name},\n\n";
        $text .= "Your recurring transactions have been automatically created for today, " . $this->formatDate() . ".\n\n";
        $text .= "TRANSACTIONS CREATED:\n";
        $text .= str_repeat("-", 60) . "\n";

        foreach ($transactions as $txn) {
            $text .= sprintf(
                "%s\n  Account: %s\n  Category: %s\n  Amount: %s\n\n",
                $txn['description'],
                $txn['account_name'],
                $txn['category_name'] ?? 'Unassigned',
                $this->formatCurrency($txn['amount'])
            );
        }

        $text .= "Total Transactions: " . count($transactions) . "\n\n";
        $text .= "View your budget at: https://your-pgbudget-url.com\n\n";
        $text .= "---\n";
        $text .= "You received this email because you have email notifications enabled.\n";
        $text .= "To change your preferences, visit your account settings in PGBudget.\n";

        return $text;
    }

    /**
     * Render failure notification HTML template
     */
    private function renderFailureTemplate($name, $failures) {
        $failureRows = '';

        foreach ($failures as $failure) {
            $failureRows .= sprintf(
                '<tr>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;">%s</td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; color: #e53e3e; font-size: 12px;">%s</td>
                </tr>',
                htmlspecialchars($failure['description']),
                $this->formatCurrency($failure['amount']),
                htmlspecialchars($failure['error'])
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Transaction Failures</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #fc8181 0%, #f56565 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0; font-size: 24px;">⚠️ Action Required</h1>
        <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Recurring Transaction Failures</p>
    </div>

    <div style="background: #f7fafc; padding: 30px; border-radius: 0 0 8px 8px;">
        <p style="margin: 0 0 20px 0; font-size: 16px;">Hello {$name},</p>

        <p style="margin: 0 0 20px 0;">Some of your recurring transactions failed to be created automatically. Please review the failures below and take action.</p>

        <table style="width: 100%; background: white; border-radius: 8px; border-collapse: collapse; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <thead>
                <tr style="background: #fed7d7;">
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #742a2a;">Description</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #742a2a;">Amount</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #742a2a;">Error</th>
                </tr>
            </thead>
            <tbody>
                {$failureRows}
            </tbody>
        </table>

        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 14px; color: #78350f;">
                <strong>What to do:</strong><br>
                1. Log in to PGBudget and review your recurring transactions<br>
                2. Check that accounts and categories still exist<br>
                3. Manually create these transactions if needed<br>
                4. Contact support if the issue persists
            </p>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="https://your-pgbudget-url.com/recurring" style="display: inline-block; background: #e53e3e; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 600;">Manage Recurring Transactions</a>
        </div>

        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">

        <p style="margin: 0; font-size: 12px; color: #a0aec0; text-align: center;">
            You received this email because recurring transaction errors occurred in your account.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render failure notification text template
     */
    private function renderFailureTextTemplate($name, $failures) {
        $text = "Hello {$name},\n\n";
        $text .= "⚠️ ACTION REQUIRED: Some recurring transactions failed to be created.\n\n";
        $text .= "FAILURES:\n";
        $text .= str_repeat("-", 60) . "\n";

        foreach ($failures as $failure) {
            $text .= sprintf(
                "%s - %s\n  Error: %s\n\n",
                $failure['description'],
                $this->formatCurrency($failure['amount']),
                $failure['error']
            );
        }

        $text .= "What to do:\n";
        $text .= "1. Log in to PGBudget and review your recurring transactions\n";
        $text .= "2. Check that accounts and categories still exist\n";
        $text .= "3. Manually create these transactions if needed\n\n";
        $text .= "Manage recurring transactions: https://your-pgbudget-url.com/recurring\n";

        return $text;
    }

    /**
     * Render daily summary templates (similar to above)
     */
    private function renderDailySummaryTemplate($name, $transactions) {
        // Similar to renderRecurringTransactionTemplate but with "Daily Summary" theme
        return $this->renderRecurringTransactionTemplate($name, $transactions);
    }

    private function renderDailySummaryTextTemplate($name, $transactions) {
        return $this->renderRecurringTransactionTextTemplate($name, $transactions);
    }

    /**
     * Helper: Format currency
     */
    private function formatCurrency($cents) {
        return '$' . number_format($cents / 100, 2);
    }

    /**
     * Helper: Format date
     */
    private function formatDate() {
        return date('F j, Y');
    }

    /**
     * Helper: Count
     */
    private function count($array) {
        return count($array);
    }
}
