# Phase 2: Credit Card Interest Accrual

This document describes the implementation of Phase 2 from the Credit Card Limits Design Guide, which adds automatic interest accrual for credit card accounts with APR.

## Overview

Phase 2 implements daily interest calculation and accrual for credit card accounts. When a credit card has an Annual Percentage Rate (APR) configured, the system automatically calculates and applies interest charges daily based on the current balance.

## Features Implemented

### 1. Interest Calculation
- **Daily Interest Formula**: Balance × (APR / 365) for daily compounding
- **Monthly Compounding**: Balance × (APR / 12 / 30.4167) for monthly compounding
- **Accurate Calculation**: Interest rounded to nearest cent
- **Smart Processing**: Skips cards with 0% APR or zero balance

### 2. Database Functions

#### Utils Layer (Internal Business Logic)
- `utils.calculate_daily_interest(balance, apr, compounding_frequency)` - Pure interest calculation
- `utils.get_credit_card_limit_by_account_id(account_id)` - Fetch limit configuration
- `utils.process_interest_accrual(account_id, date, user)` - Process and record interest
- `utils.process_all_interest_accruals(date)` - Batch process all eligible cards

#### API Layer (Public Interface)
- `api.credit_card_limits` - View of limits with current balance and utilization
- `api.get_credit_card_limit(account_uuid)` - Get limit configuration
- `api.process_interest_accrual(account_uuid, date)` - Process interest for one card
- `api.get_interest_summary(account_uuid, start_date, end_date)` - View interest history

### 3. API Endpoints

#### `/api/credit-card-limits.php`
Manage credit card limit configurations.

**GET** - Retrieve credit card limits
- `GET /api/credit-card-limits.php` - Get all limits for current user
- `GET /api/credit-card-limits.php?account_uuid=xxx` - Get limit for specific card

**POST** - Create or update credit card limit
```json
{
  "account_uuid": "abc12345",
  "credit_limit": 5000.00,
  "annual_percentage_rate": 18.99,
  "interest_type": "variable",
  "compounding_frequency": "daily",
  "statement_day_of_month": 15,
  "due_date_offset_days": 21,
  "grace_period_days": 0,
  "minimum_payment_percent": 2.0,
  "minimum_payment_flat": 25.00,
  "warning_threshold_percent": 80,
  "notes": "Chase Freedom Card"
}
```

**DELETE** - Deactivate a credit card limit
```json
{
  "account_uuid": "abc12345"
}
```

#### `/api/process-interest.php`
Process and view interest accrual.

**POST** - Process interest accrual
- Process single account:
```json
{
  "account_uuid": "abc12345",
  "accrual_date": "2025-10-25"
}
```

- Process all accounts (admin/testing):
```json
{
  "process_all": true,
  "accrual_date": "2025-10-25"
}
```

**GET** - Get interest summary
- `GET /api/process-interest.php?account_uuid=xxx&start_date=2025-01-01&end_date=2025-10-25`

### 4. Nightly Batch Job

**Script**: `/var/www/html/pgbudget/scripts/nightly-interest-accrual.php`

**Cron Schedule**: Daily at 1:00 AM
```bash
0 1 * * * /usr/bin/php /var/www/html/pgbudget/scripts/nightly-interest-accrual.php >> /var/log/pgbudget-interest-accrual.log 2>&1
```

**Manual Execution**:
```bash
# Process interest for today
php scripts/nightly-interest-accrual.php

# Process interest for specific date
php scripts/nightly-interest-accrual.php 2025-10-25
```

**Output Example**:
```
==========================================================
PGBudget Nightly Interest Accrual
==========================================================
Date: 2025-10-25 01:00:00
Accrual Date: 2025-10-25
==========================================================

Processing interest accrual for all eligible accounts...

==========================================================
RESULTS
==========================================================
Total Processed: 2
Successfully Accrued: 2
Skipped: 0
Errors: 0
==========================================================

DETAILS:
----------------------------------------------------------
✓ Chase Freedom (abc12345)
  Balance: $1,250.00
  APR: 18.99%
  Interest Charged: $0.65
  Message: Interest accrued successfully

✓ Discover Card (def67890)
  Balance: $3,500.00
  APR: 15.24%
  Interest Charged: $1.46
  Message: Interest accrued successfully
----------------------------------------------------------

Batch job completed successfully
==========================================================
```

## Interest Transaction Details

When interest is accrued, the system creates a transaction with the following characteristics:

- **Description**: `"Interest charge - [Card Name]"`
- **Accounting Entry**:
  - Debit: "Interest & Finance Charges" category (expense)
  - Credit: Credit Card Account (liability)
- **Metadata**: Includes balance at accrual, APR, compounding frequency
- **Auto-Creation**: "Interest & Finance Charges" category created automatically if needed

## Database Schema

### Key Fields in `data.credit_card_limits`
- `annual_percentage_rate` - APR (0-100%)
- `interest_type` - 'fixed' or 'variable'
- `compounding_frequency` - 'daily' or 'monthly'
- `last_interest_accrual_date` - Prevents duplicate accrual
- `is_active` - Only active limits are processed

## Usage Examples

### Setting Up Interest Accrual

1. **Create a credit card limit with APR**:
```bash
curl -X POST http://localhost/pgbudget/api/credit-card-limits.php \
  -H "Content-Type: application/json" \
  -d '{
    "account_uuid": "abc12345",
    "credit_limit": 5000.00,
    "annual_percentage_rate": 18.99,
    "compounding_frequency": "daily"
  }'
```

2. **Check current balance and utilization**:
```bash
curl http://localhost/pgbudget/api/credit-card-limits.php?account_uuid=abc12345
```

3. **View interest history**:
```bash
curl "http://localhost/pgbudget/api/process-interest.php?account_uuid=abc12345&start_date=2025-01-01"
```

### Manual Interest Processing (Testing)

```bash
# Process interest for a specific card
curl -X POST http://localhost/pgbudget/api/process-interest.php \
  -H "Content-Type: application/json" \
  -d '{
    "account_uuid": "abc12345",
    "accrual_date": "2025-10-25"
  }'

# Process all eligible cards
curl -X POST http://localhost/pgbudget/api/process-interest.php \
  -H "Content-Type: application/json" \
  -d '{
    "process_all": true
  }'
```

## Important Notes

### Duplicate Prevention
The system prevents duplicate interest accrual for the same date by tracking `last_interest_accrual_date`. If interest has already been accrued for a date, subsequent attempts will be skipped.

### Zero Balance Handling
Cards with zero or negative balance do not accrue interest.

### Deactivated Limits
Only limits with `is_active = true` are processed by the nightly batch job.

### User Isolation
All interest accrual respects Row-Level Security (RLS) policies. Users can only process interest for their own credit card accounts.

## Testing

### Test Interest Calculation
```sql
-- Calculate interest for $1000 balance at 18.99% APR (daily compounding)
SELECT utils.calculate_daily_interest(100000, 18.99, 'daily');
-- Expected: ~52 cents per day

-- Calculate interest for $1000 balance at 18.99% APR (monthly compounding)
SELECT utils.calculate_daily_interest(100000, 18.99, 'monthly');
-- Expected: ~62 cents per day
```

### Test Manual Processing
```bash
# Run batch job for today
php scripts/nightly-interest-accrual.php

# Run batch job for past date
php scripts/nightly-interest-accrual.php 2025-10-24
```

## Next Steps (Phase 3)

Phase 3 will implement billing cycle management:
- Statement generation
- Due date tracking
- Minimum payment calculation
- Statement archival
- Payment notifications

## Files Added/Modified

### New Files
- `migrations/20251025000001_add_interest_accrual_functions.sql` - Database functions
- `public/api/credit-card-limits.php` - Limits API endpoint
- `public/api/process-interest.php` - Interest processing API
- `scripts/nightly-interest-accrual.php` - Batch job script
- `PHASE2_INTEREST_ACCRUAL.md` - This documentation

### Cron Configuration
- Added daily cron job for pgbudget user at 1:00 AM

## Troubleshooting

### Check Cron Job Status
```bash
# View crontab
crontab -l -u pgbudget

# Check cron execution logs
journalctl -u cron | grep interest-accrual

# View batch job logs
tail -f /var/log/pgbudget-interest-accrual.log
```

### Verify Database Functions
```sql
-- Check if functions exist
\df utils.process_interest_accrual
\df api.process_interest_accrual

-- View credit card limits
SELECT * FROM api.credit_card_limits;

-- View recent interest transactions
SELECT * FROM api.get_interest_summary('account_uuid_here', '2025-01-01', '2025-12-31');
```

### Common Issues

1. **No interest accrued**: Check that APR > 0 and `is_active = true`
2. **Duplicate accrual prevented**: Check `last_interest_accrual_date` in limits table
3. **Cron not running**: Verify cron service is active: `systemctl status cron`
4. **Permission denied**: Ensure script is executable: `chmod +x scripts/nightly-interest-accrual.php`

## Support

For issues or questions about Phase 2 implementation, refer to:
- `CREDIT_CARD_LIMITS_DESIGN_GUIDE.md` - Overall design guide
- Database function comments in migration file
- Inline code documentation in API endpoints
