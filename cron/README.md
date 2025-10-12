# PGBudget Cron Jobs

This directory contains automated cron job scripts for PGBudget.

## Available Scripts

### process-recurring-transactions.php
Automatically creates transactions from recurring templates when auto-create is enabled.

**Quick Setup:**
```bash
# Add to crontab (runs every hour)
crontab -e

# Add this line:
0 * * * * php /var/www/html/pgbudget/cron/process-recurring-transactions.php >> /var/log/pgbudget-cron.log 2>&1
```

**Test the script:**
```bash
# Dry run (shows what would happen without creating transactions)
php process-recurring-transactions.php --dry-run --verbose
```

**Full Documentation:**
See `/docs/RECURRING_TRANSACTIONS_CRON.md` for complete setup and configuration instructions.

## Command-Line Options

- `--dry-run` - Test mode, no transactions are created
- `--verbose` or `-v` - Show detailed output
- Both options can be combined

## Logs

Logs are stored in `/var/www/html/pgbudget/logs/`:
- `recurring-transactions.log` - All processing information
- `recurring-transactions-errors.log` - Errors only

## Requirements

- PHP CLI
- Database connection configured in `.env`
- Write permissions to `logs/` directory

## Support

For issues or questions, see the full documentation or file an issue on GitHub.
