# Recurring Transactions Cron Job Setup

This document explains how to set up and configure the automated recurring transactions processor for PGBudget.

## Overview

The recurring transactions cron job automatically creates transactions from recurring templates when:
- The recurring transaction has `auto_create = true`
- The recurring transaction is `enabled = true`
- The `next_date` is today or earlier
- The `end_date` has not been reached (or is NULL)

## Prerequisites

- PHP CLI (command-line interface) installed
- Access to crontab or system task scheduler
- Write permissions to `/var/www/html/pgbudget/logs/` directory

## Installation

### 1. Verify Script Permissions

Ensure the cron script is executable:

```bash
chmod +x /var/www/html/pgbudget/cron/process-recurring-transactions.php
```

### 2. Create Log Directory

```bash
mkdir -p /var/www/html/pgbudget/logs
chmod 755 /var/www/html/pgbudget/logs
```

### 3. Test the Script Manually

Run the script in dry-run mode to verify it works:

```bash
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run --verbose
```

This will show what transactions would be created without actually creating them.

### 4. Configure Crontab

Edit your crontab:

```bash
crontab -e
```

Add one of the following entries based on your preferred schedule:

#### Recommended: Every Hour
```cron
0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
```

#### Alternative: Every 30 Minutes
```cron
*/30 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
```

#### Alternative: Once Daily at 6 AM
```cron
0 6 * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
```

#### Alternative: Twice Daily (6 AM and 6 PM)
```cron
0 6,18 * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
```

### 5. Configure Log Rotation (Optional but Recommended)

Create a logrotate configuration to prevent log files from growing too large:

Create `/etc/logrotate.d/pgbudget`:

```
/var/www/html/pgbudget/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
    sharedscripts
}

/var/log/pgbudget-cron.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
```

## Usage

### Command-Line Options

The cron script supports the following options:

#### `--dry-run`
Run the script without actually creating transactions. Useful for testing.

```bash
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run
```

#### `--verbose` or `-v`
Show detailed output including all log messages.

```bash
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --verbose
```

#### Combined Options
```bash
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run --verbose
```

### Manual Execution

You can run the script manually at any time:

```bash
# Run normally
php /var/www/html/pgbudget/cron/process-recurring-transactions.php

# Run with verbose output
php /var/www/html/pgbudget/cron/process-recurring-transactions.php -v

# Test without creating transactions
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run -v
```

## Logging

The script creates two log files in `/var/www/html/pgbudget/logs/`:

### 1. Main Log: `recurring-transactions.log`
Contains all processing information including:
- Start/end timestamps
- Users processed
- Transactions created
- Summary statistics

Example:
```
[2025-10-11 12:00:00] [INFO] === Starting Recurring Transactions Processor ===
[2025-10-11 12:00:00] [INFO] Found 3 users with due recurring transactions
[2025-10-11 12:00:01] [INFO] Processing user: user123
[2025-10-11 12:00:01] [INFO]   Found 2 due recurring transactions
[2025-10-11 12:00:01] [INFO]   [abc123] Monthly Rent - $1,500.00 (monthly) - Next: 2025-10-11
[2025-10-11 12:00:01] [SUCCESS]     ✓ Created transaction: xyz789
[2025-10-11 12:00:02] [INFO] === Processing Complete ===
[2025-10-11 12:00:02] [INFO] Total recurring transactions processed: 2
[2025-10-11 12:00:02] [INFO] Successfully created: 2
[2025-10-11 12:00:02] [INFO] Failed: 0
```

### 2. Error Log: `recurring-transactions-errors.log`
Contains only errors and failures for easy troubleshooting.

Example:
```
[2025-10-11 12:00:05] [ERROR]     ✗ Database error: SQLSTATE[23503]: Foreign key violation
[2025-10-11 12:00:10] [FATAL] Database connection error: Connection refused
```

## Monitoring

### Check Recent Logs

```bash
# View last 50 lines of main log
tail -n 50 /var/www/html/pgbudget/logs/recurring-transactions.log

# View errors only
tail -n 50 /var/www/html/pgbudget/logs/recurring-transactions-errors.log

# Follow logs in real-time
tail -f /var/www/html/pgbudget/logs/recurring-transactions.log
```

### Check Cron Execution

```bash
# View cron output log
tail -n 50 /var/log/pgbudget-cron.log

# Check if cron is scheduled
crontab -l
```

### Monitor Database

Check recently created transactions:

```sql
-- View transactions created in last hour
SELECT t.uuid, t.date, t.description, t.amount, t.created_at
FROM data.transactions t
WHERE t.created_at >= NOW() - INTERVAL '1 hour'
ORDER BY t.created_at DESC;

-- View recurring transactions status
SELECT
    rt.uuid,
    rt.description,
    rt.next_date,
    rt.enabled,
    rt.auto_create,
    l.name as ledger_name
FROM data.recurring_transactions rt
JOIN data.ledgers l ON l.id = rt.ledger_id
WHERE rt.auto_create = true
ORDER BY rt.next_date;
```

## Troubleshooting

### Issue: Script Not Running

**Check cron service:**
```bash
# Linux
sudo systemctl status cron
sudo systemctl start cron

# Check cron logs
sudo tail -f /var/log/syslog | grep CRON
```

**Verify crontab:**
```bash
crontab -l
```

### Issue: Permission Denied

**Fix script permissions:**
```bash
chmod +x /var/www/html/pgbudget/cron/process-recurring-transactions.php
```

**Fix log directory permissions:**
```bash
chmod 755 /var/www/html/pgbudget/logs
```

### Issue: Database Connection Failed

**Verify .env file:**
```bash
cat /var/www/html/pgbudget/.env
```

**Test PHP connection:**
```bash
php -r "require '/var/www/html/pgbudget/config/database.php'; getDbConnection(); echo 'OK';"
```

### Issue: No Transactions Created

**Check recurring transactions:**
```sql
SELECT * FROM data.recurring_transactions
WHERE enabled = true
  AND auto_create = true
  AND next_date <= CURRENT_DATE;
```

**Run in verbose mode:**
```bash
php /var/www/html/pgbudget/cron/process-recurring-transactions.php --verbose
```

### Issue: Transactions Created Multiple Times

This can happen if the cron runs multiple times before `next_date` is updated.

**Solution:**
- Ensure only one cron job is scheduled (check `crontab -l`)
- The script updates `next_date` after creating each transaction to prevent duplicates
- Check logs for database errors that might prevent the update

## Exit Codes

The script uses standard exit codes:

- `0` - Success (all transactions processed successfully)
- `1` - Partial failure (some transactions failed but script completed)
- `2` - Fatal error (database connection failed or critical error)

## Performance Considerations

### Recommended Schedule

- **Hourly**: Good balance for most users (recommended)
- **Every 30 minutes**: For users who need more precision
- **Daily**: Sufficient for monthly/yearly recurring transactions
- **Avoid**: Every minute (unnecessary overhead)

### Processing Time

The script typically completes in:
- Small installations (< 100 users): 1-5 seconds
- Medium installations (100-1000 users): 5-30 seconds
- Large installations (> 1000 users): 30-120 seconds

### Optimization Tips

1. **Schedule during off-peak hours** for large installations
2. **Use hourly schedule** as a starting point
3. **Monitor logs** to adjust frequency as needed
4. **Enable log rotation** to prevent disk space issues

## Security Considerations

1. **File Permissions**: Ensure cron script is not world-writable
   ```bash
   chmod 750 /var/www/html/pgbudget/cron/process-recurring-transactions.php
   ```

2. **Log Protection**: Restrict log file access
   ```bash
   chmod 640 /var/www/html/pgbudget/logs/*.log
   ```

3. **Database Credentials**: Never log database passwords
   - The script uses the existing `.env` configuration
   - Credentials are not written to logs

4. **User Context**: The script properly sets user context for RLS
   - Each user's transactions are isolated
   - No cross-user data leakage

## Backup and Recovery

### Before Enabling Auto-Create

1. **Test with dry-run:**
   ```bash
   php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run -v
   ```

2. **Backup database:**
   ```bash
   pg_dump -U pgbudget pgbudget > pgbudget_backup_$(date +%Y%m%d).sql
   ```

3. **Enable for one user first** as a pilot test

### Recovery from Errors

If transactions are created incorrectly:

1. **Check error logs** to identify the issue
2. **Disable auto-create** for affected recurring transactions
3. **Delete incorrect transactions** using the UI or SQL
4. **Fix the issue** and test with dry-run
5. **Re-enable auto-create** when ready

## Advanced Configuration

### Environment-Specific Settings

You can create environment-specific cron schedules:

**Production:**
```cron
0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php
```

**Staging/Development:**
```cron
0 6 * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php --dry-run -v
```

### Email Notifications

Configure cron to send email on failures:

```cron
MAILTO=admin@example.com
0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php || echo "Recurring transactions cron failed"
```

### Monitoring Integration

Integrate with monitoring tools like Healthchecks.io:

```cron
0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php && curl -fsS --retry 3 https://hc-ping.com/your-uuid > /dev/null
```

## Support

For issues or questions:
- Check logs: `/var/www/html/pgbudget/logs/`
- Review this documentation
- File an issue on GitHub: https://github.com/anthropics/pgbudget/issues

## Version History

- **v1.0** (2025-10-11): Initial implementation
  - Auto-create transactions from recurring templates
  - Dry-run and verbose modes
  - Comprehensive logging
  - Error handling and recovery
