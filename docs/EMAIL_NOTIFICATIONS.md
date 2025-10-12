# Email Notifications for Recurring Transactions

This document explains how to set up and configure email notifications for automatically created recurring transactions in PGBudget.

## Overview

PGBudget can send email notifications when:
- Recurring transactions are automatically created
- Recurring transaction creation fails
- Daily/weekly summaries of created transactions (coming soon)

Users can control their notification preferences through their account settings.

## Setup

### 1. Configure Environment Variables

Edit your `.env` file and add email configuration:

```env
# Email Configuration
MAIL_ENABLED=true
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME=PGBudget
```

**Configuration Options:**

- `MAIL_ENABLED` - Set to `true` to enable email sending, `false` to disable (default: false)
- `MAIL_FROM_ADDRESS` - Email address that notifications are sent from
- `MAIL_FROM_NAME` - Display name for sent emails

### 2. Configure Mail Server (Optional)

By default, PGBudget uses PHP's built-in `mail()` function. For production use, you should configure a proper mail server:

#### Option A: System Sendmail/Postfix

Configure your system's mail transfer agent (MTA) to send emails. This is the simplest option for basic needs.

```bash
# Install and configure postfix
sudo apt-get install postfix
sudo dpkg-reconfigure postfix
```

#### Option B: SMTP Server (Recommended for Production)

For better deliverability, you can modify `EmailService.php` to use PHPMailer or SwiftMailer with an SMTP server like:
- Gmail SMTP
- SendGrid
- Amazon SES
- Mailgun
- SMTP2GO

Example `.env` additions for SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
```

### 3. Database Migration

The notification preferences table is automatically created during setup. If needed, run:

```bash
goose -dir migrations postgres "your-connection-string" up
```

This creates the `data.notification_preferences` table.

### 4. Test Email Configuration

Test that emails are working:

```bash
# Run cron in dry-run mode with verbose output
php cron/process-recurring-transactions.php --dry-run --verbose
```

Check the logs:
```bash
tail -f logs/email.log
```

## User Notification Preferences

### Default Settings

By default, users have the following notification preferences:
- ✅ Email on recurring transaction created: **Enabled**
- ❌ Email daily summary: **Disabled**
- ❌ Email weekly summary: **Disabled**
- ✅ Email on recurring transaction failed: **Enabled**

### Database API

Users' notification preferences are stored in `data.notification_preferences` and can be accessed via API functions:

#### Get Notification Preferences

```sql
SELECT * FROM api.get_notification_preferences();
```

Returns:
- `email_on_recurring_transaction` - Send email when transaction is created
- `email_daily_summary` - Send daily summary of all created transactions
- `email_weekly_summary` - Send weekly summary
- `email_on_recurring_transaction_failed` - Send email on failures

#### Update Notification Preferences

```sql
SELECT api.update_notification_preferences(
    p_email_on_recurring_transaction := true,
    p_email_daily_summary := false,
    p_email_weekly_summary := false,
    p_email_on_recurring_transaction_failed := true
);
```

All parameters are optional - only pass the ones you want to update.

### User Interface (Coming Soon)

A settings page will be added to allow users to manage their notification preferences through the web interface.

## Email Templates

### Success Notification

Sent when recurring transactions are successfully created.

**Subject:** "Recurring Transactions Created - {Date}"

**Contents:**
- List of created transactions with:
  - Description
  - Account
  - Category
  - Amount
- Total impact on budget
- Number of transactions created
- Link to view in PGBudget

### Failure Notification

Sent when recurring transaction creation fails.

**Subject:** "Action Required: Recurring Transaction Failures - {Date}"

**Contents:**
- List of failed transactions with:
  - Description
  - Amount
  - Error message
- Troubleshooting steps
- Link to manage recurring transactions

## How It Works

### Email Sending Flow

1. **Cron job runs** hourly (or on your schedule)
2. **Processes recurring transactions** for each user
3. **Tracks results** - successes and failures
4. **Checks user preferences** from database
5. **Sends appropriate emails** based on:
   - Whether transactions were created
   - Whether failures occurred
   - User's notification preferences
   - Email is enabled in `.env`

### Email Sending Logic

```php
// Per user, after processing all their transactions:
if (MAIL_ENABLED && user has email) {

    if (successes > 0 && email_on_recurring_transaction) {
        sendSuccessNotification();
    }

    if (failures > 0 && email_on_recurring_transaction_failed) {
        sendFailureNotification();
    }
}
```

### Logging

Email sending attempts are logged to:
- `/logs/email.log` - All email attempts (sent/failed)
- `/logs/recurring-transactions.log` - General processing log

Example log entries:
```
[2025-10-12 01:00:00] Email SENT: To=user@example.com, Subject=Recurring Transactions Created
[2025-10-12 01:00:05] Email FAILED: To=user@example.com, Subject=Recurring Transactions Created
```

## Email Content Customization

### Modifying Templates

Email templates are defined in `/includes/email/EmailService.php`.

To customize:

1. Edit the template methods:
   - `renderRecurringTransactionTemplate()` - HTML version
   - `renderRecurringTransactionTextTemplate()` - Plain text version
   - `renderFailureTemplate()` - Failure notification HTML
   - `renderFailureTextTemplate()` - Failure notification text

2. Modify styling:
   - Colors, fonts, layout are inline CSS
   - Keep mobile-responsive design in mind
   - Test in multiple email clients

3. Update links:
   - Replace `https://your-pgbudget-url.com` with your actual URL
   - Ensure links work for all users

### Branding

Update branding elements in `.env`:

```env
MAIL_FROM_NAME=Your Company Name
MAIL_FROM_ADDRESS=noreply@your-domain.com
```

And in email templates:
- Logo/header (add image URL)
- Colors (update gradient values)
- Footer text

## Troubleshooting

### Emails Not Sending

**Check email is enabled:**
```bash
grep MAIL_ENABLED .env
# Should show: MAIL_ENABLED=true
```

**Check logs:**
```bash
# Check for email sending attempts
tail -f logs/email.log

# Check for errors in cron log
tail -f logs/recurring-transactions.log
```

**Test PHP mail:**
```bash
php -r "mail('test@example.com', 'Test', 'Test message');"
```

### Emails Going to Spam

**Solutions:**
1. Configure SPF records for your domain
2. Set up DKIM signing
3. Use a dedicated email service (SendGrid, SES, etc.)
4. Verify sender domain
5. Avoid spam trigger words in subject/content

**SPF Record Example:**
```
TXT @ "v=spf1 a mx include:_spf.google.com ~all"
```

### Users Not Receiving Emails

**Check user has valid email:**
```sql
SELECT username, email FROM data.users WHERE username = 'the_user';
```

**Check notification preferences:**
```sql
SELECT * FROM data.notification_preferences WHERE username = 'the_user';
```

**Check if user context is set correctly:**
```bash
# Run cron in verbose mode
php cron/process-recurring-transactions.php --verbose
```

### Email Delivery Issues

**Common causes:**
1. **No MTA configured** - Install postfix or configure SMTP
2. **Firewall blocking** - Port 25, 587, or 465 blocked
3. **Authentication required** - SMTP needs credentials
4. **Rate limiting** - Sending too many emails
5. **Domain not verified** - Using email service that requires verification

## Security Considerations

### Data Privacy

- Email addresses are stored securely in the database
- RLS policies ensure users only see their own preferences
- Email logs don't contain sensitive transaction details
- Users can opt out of all notifications

### Authentication

- No authentication tokens sent via email
- Links in emails don't contain user credentials
- Session-based authentication required to view budget

### Rate Limiting

Consider implementing rate limits:

```php
// In EmailService.php
private $maxEmailsPerHour = 100;
private $emailCount = 0;

public function send($to, $subject, $body) {
    if ($this->emailCount >= $this->maxEmailsPerHour) {
        throw new Exception('Email rate limit exceeded');
    }

    // Send email...
    $this->emailCount++;
}
```

## Performance Considerations

### Email Sending Time

- Each email takes 100-500ms to send
- For 100 users: 10-50 seconds additional processing time
- Consider async email queue for large installations

### Optimization Tips

1. **Batch daily summaries** instead of per-transaction emails
2. **Use async job queue** (Redis, RabbitMQ) for large scale
3. **Implement retry logic** for failed sends
4. **Cache email templates** to avoid regenerating

### Example Async Implementation

```php
// Queue email instead of sending immediately
$queue->push('send-email', [
    'to' => $user['email'],
    'type' => 'recurring-transaction-success',
    'data' => $transactions
]);
```

## Monitoring

### Email Delivery Monitoring

Track email metrics:

```sql
-- Create email tracking table (future enhancement)
CREATE TABLE data.email_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT,
    email_type TEXT,
    sent_at TIMESTAMPTZ,
    status TEXT, -- sent, failed, bounced
    error_message TEXT
);
```

### Metrics to Track

- **Delivery rate** - % of emails successfully sent
- **Open rate** - If using tracking pixels
- **Click rate** - If tracking link clicks
- **Bounce rate** - Invalid email addresses
- **Unsubscribe rate** - Users opting out

### Integration with Monitoring Services

Example with Healthchecks.io:

```php
// After sending emails successfully
file_get_contents('https://hc-ping.com/your-uuid-for-emails');
```

## Advanced Features

### Daily Summary Email

Coming soon - aggregate all daily transactions into one email.

Implementation location: `EmailService::sendDailySummary()`

### Weekly Summary Email

Coming soon - weekly digest of all auto-created transactions.

### Custom Email Providers

To use services like SendGrid:

```php
// In EmailService.php
private function send($to, $subject, $htmlBody, $textBody) {
    $email = new \SendGrid\Mail\Mail();
    $email->setFrom($this->fromEmail, $this->fromName);
    $email->setSubject($subject);
    $email->addTo($to);
    $email->addContent("text/html", $htmlBody);

    $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY'));
    $response = $sendgrid->send($email);

    return $response->statusCode() == 202;
}
```

## API Reference

### EmailService Methods

```php
$emailService = new EmailService();

// Check if email is enabled
$emailService->isEnabled(); // returns boolean

// Send recurring transaction notification
$emailService->sendRecurringTransactionNotification(
    $toEmail,      // string
    $toName,       // string
    $transactions  // array of transaction data
);

// Send failure notification
$emailService->sendRecurringTransactionFailureNotification(
    $toEmail,   // string
    $toName,    // string
    $failures   // array of failure data
);

// Send daily summary
$emailService->sendDailySummary(
    $toEmail,      // string
    $toName,       // string
    $transactions  // array of transaction data
);
```

### Database Functions

```sql
-- Get user's notification preferences
SELECT * FROM api.get_notification_preferences();

-- Update preferences
SELECT api.update_notification_preferences(
    p_email_on_recurring_transaction := true,
    p_email_daily_summary := false,
    p_email_weekly_summary := false,
    p_email_on_recurring_transaction_failed := true
);
```

## Support

For issues or questions:
- Check logs: `/logs/email.log` and `/logs/recurring-transactions.log`
- Review this documentation
- Test with `--dry-run` mode first
- File an issue on GitHub

## Version History

- **v1.0** (2025-10-11): Initial implementation
  - Success/failure notifications
  - User preference management
  - HTML and text email templates
  - Logging and error handling
