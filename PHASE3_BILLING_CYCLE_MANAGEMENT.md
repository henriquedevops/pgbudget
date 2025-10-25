# Phase 3: Billing Cycle Management

This document describes the implementation of Phase 3 from the Credit Card Limits Design Guide, which adds billing cycle management including statement generation, due date tracking, and minimum payment calculation.

## Overview

Phase 3 implements comprehensive billing cycle management for credit card accounts. The system automatically generates monthly statements on the configured statement day, calculates minimum payments, tracks due dates, and provides detailed transaction summaries for each billing period.

## Features Implemented

### 1. Statement Generation
- **Automatic Monthly Statements**: Generated on configured statement day of month
- **Period-based Calculation**: Tracks all transactions within the billing cycle
- **Balance Tracking**: Previous balance, purchases, payments, interest, and fees
- **Current Statement Marking**: Only one active statement per card at a time
- **Statement Archival**: Previous statements remain accessible for history

### 2. Minimum Payment Calculation
- **Percentage-based**: Configurable percentage of balance (e.g., 2%)
- **Flat Minimum**: Configurable flat minimum amount (e.g., $25)
- **Smart Calculation**: Uses greater of percentage or flat minimum
- **Balance Cap**: Minimum payment never exceeds total balance

### 3. Due Date Management
- **Configurable Offset**: Due date calculated from statement date + offset days (typically 21-25 days)
- **Upcoming Due Dates**: API to retrieve all upcoming payments
- **Overdue Detection**: Automatic flagging of overdue statements
- **Days Until Due**: Real-time calculation of days remaining

### 4. Transaction Summary
- **Purchases**: All spending during the period
- **Payments**: All payments made during the period
- **Interest Charges**: Interest accrued during the period
- **Fees**: Late fees and other charges (extensible)
- **Transaction Count**: Total transactions in statement

### 5. Database Functions

#### Utils Layer (Internal Business Logic)
- `utils.calculate_minimum_payment(balance, percent, flat_min)` - Calculate minimum payment
- `utils.calculate_next_statement_date(date, day_of_month)` - Calculate next statement date
- `utils.get_statement_period_transactions(account_id, start, end)` - Get transaction summary
- `utils.generate_statement(account_id, date, user)` - Generate complete statement
- `utils.generate_all_statements(date)` - Batch generate for all eligible cards

#### API Layer (Public Interface)
- `api.credit_card_statements` - View of statements with due date calculations
- `api.get_statement(statement_uuid)` - Get specific statement
- `api.get_statements_for_account(account_uuid)` - All statements for a card
- `api.get_current_statement(account_uuid)` - Current active statement
- `api.generate_statement(account_uuid, date)` - Generate statement manually
- `api.get_upcoming_due_dates(days_ahead)` - Upcoming payment due dates

### 6. API Endpoint

#### `/api/statements.php`
Comprehensive statement management endpoint.

**GET** - Retrieve statements
```bash
# Get specific statement with transactions
GET /api/statements.php?statement_uuid=abc12345

# Get all statements for an account
GET /api/statements.php?account_uuid=xyz78901

# Get current statement for an account
GET /api/statements.php?account_uuid=xyz78901&current=true

# Get upcoming due dates (next 30 days)
GET /api/statements.php?upcoming_due_dates=true&days_ahead=30

# Get all statements for current user
GET /api/statements.php
```

**POST** - Generate statement
```json
{
  "account_uuid": "xyz78901",
  "statement_date": "2025-10-25"
}
```

### 7. Monthly Batch Job

**Script**: `/var/www/html/pgbudget/scripts/monthly-statement-generation.php`

**Cron Schedule**: Daily at 2:00 AM (checks if it's statement day for any card)
```bash
0 2 * * * /usr/bin/php /var/www/html/pgbudget/scripts/monthly-statement-generation.php >> /var/log/pgbudget-statements.log 2>&1
```

**Manual Execution**:
```bash
# Generate statements for today (if it's statement day)
php scripts/monthly-statement-generation.php

# Generate statements for specific date
php scripts/monthly-statement-generation.php 2025-10-15
```

**Output Example**:
```
==========================================================
PGBudget Monthly Statement Generation
==========================================================
Date: 2025-10-25 02:00:00
Statement Date: 2025-10-25
==========================================================

Generating statements for eligible accounts...

==========================================================
RESULTS
==========================================================
Total Processed: 2
Successfully Generated: 2
Skipped: 0
Errors: 0
==========================================================

DETAILS:
----------------------------------------------------------
✓ Chase Freedom (abc12345)
  Statement Period: 2025-09-26 to 2025-10-25
  Ending Balance: $1,523.45
  Minimum Payment: $30.47
  Due Date: 2025-11-15
  Purchases: $850.00
  Payments: $500.00
  Interest: $23.45

✓ Discover Card (def67890)
  Statement Period: 2025-09-26 to 2025-10-25
  Ending Balance: $2,890.12
  Minimum Payment: $57.80
  Due Date: 2025-11-15
  Purchases: $1,200.00
  Payments: $1,000.00
  Interest: $40.12
----------------------------------------------------------

Batch job completed successfully
==========================================================
```

## Statement Structure

### Statement Record
```json
{
  "uuid": "stmt1234",
  "account_uuid": "card5678",
  "account_name": "Chase Freedom",
  "statement_period_start": "2025-09-26",
  "statement_period_end": "2025-10-25",
  "previous_balance": 115000,
  "purchases_amount": 85000,
  "payments_amount": 50000,
  "interest_charged": 2345,
  "fees_charged": 0,
  "ending_balance": 152345,
  "minimum_payment_due": 3047,
  "due_date": "2025-11-15",
  "is_current": true,
  "days_until_due": 21,
  "is_overdue": false,
  "metadata": {
    "transaction_count": 15,
    "credit_limit": 5000.00,
    "available_credit": 3476.55
  }
}
```

**Note**: All monetary amounts are in cents (bigint). Divide by 100 for dollar display.

## How It Works

### Statement Generation Flow

1. **Trigger**: Batch job runs daily at 2:00 AM
2. **Check Statement Day**: For each credit card with a limit configuration
3. **Date Matching**: If today matches `statement_day_of_month`
4. **Duplicate Check**: Verify no statement exists for current month
5. **Period Calculation**:
   - Start: Day after previous statement end
   - End: Current statement date
6. **Transaction Summary**: Query all transactions in period
7. **Balance Calculation**:
   ```
   ending_balance = previous_balance + purchases + interest + fees - payments
   ```
8. **Minimum Payment**: Calculate using percentage and flat minimum
9. **Due Date**: Statement date + `due_date_offset_days`
10. **Statement Creation**: Insert into `credit_card_statements` table
11. **Mark Current**: Set new statement as current, mark others as historical

### Minimum Payment Calculation

```javascript
// Example: Balance $1,000, 2% minimum, $25 flat
percentage_payment = $1,000 * 0.02 = $20
minimum_payment = max($20, $25) = $25

// Example: Balance $5,000, 2% minimum, $25 flat
percentage_payment = $5,000 * 0.02 = $100
minimum_payment = max($100, $25) = $100

// Example: Balance $10, 2% minimum, $25 flat
percentage_payment = $10 * 0.02 = $0.20
minimum_payment = max($0.20, $25) = $25
// But limited to balance: min($25, $10) = $10
```

## Usage Examples

### Setting Up Billing Cycle

When creating a credit card limit, configure the billing cycle:

```bash
curl -X POST http://localhost/pgbudget/api/credit-card-limits.php \
  -H "Content-Type: application/json" \
  -d '{
    "account_uuid": "card123",
    "credit_limit": 5000.00,
    "annual_percentage_rate": 18.99,
    "statement_day_of_month": 15,
    "due_date_offset_days": 21,
    "minimum_payment_percent": 2.0,
    "minimum_payment_flat": 25.00
  }'
```

### Generating Statements

**Manual Generation** (for testing or catch-up):
```bash
curl -X POST http://localhost/pgbudget/api/statements.php \
  -H "Content-Type: application/json" \
  -d '{
    "account_uuid": "card123",
    "statement_date": "2025-10-15"
  }'
```

**Automatic Generation**: Statements are generated automatically by the nightly batch job on the statement day.

### Viewing Statements

**Get Current Statement**:
```bash
curl "http://localhost/pgbudget/api/statements.php?account_uuid=card123&current=true"
```

**Get Statement History**:
```bash
curl "http://localhost/pgbudget/api/statements.php?account_uuid=card123"
```

**Get Specific Statement with Transactions**:
```bash
curl "http://localhost/pgbudget/api/statements.php?statement_uuid=stmt456"
```

**Get Upcoming Due Dates**:
```bash
curl "http://localhost/pgbudget/api/statements.php?upcoming_due_dates=true&days_ahead=30"
```

## Statement Period Logic

### First Statement
- **Period Start**: First day of current month
- **Period End**: Statement date
- **Previous Balance**: Balance at start of month

### Subsequent Statements
- **Period Start**: Day after previous statement end
- **Period End**: Current statement date
- **Previous Balance**: Ending balance from previous statement

### Example Timeline
```
Card created: October 1, 2025
Statement day: 15th of month
Due offset: 21 days

Statement 1:
  Period: Oct 1 - Oct 15
  Due: Nov 5 (Oct 15 + 21 days)

Statement 2:
  Period: Oct 16 - Nov 15
  Due: Dec 6 (Nov 15 + 21 days)

Statement 3:
  Period: Nov 16 - Dec 15
  Due: Jan 5 (Dec 15 + 21 days)
```

## Database Schema Updates

### Key Fields in `data.credit_card_statements`
- `statement_period_start` - First day of billing cycle
- `statement_period_end` - Last day of billing cycle (statement date)
- `previous_balance` - Balance from previous statement
- `purchases_amount` - Total purchases in period
- `payments_amount` - Total payments in period
- `interest_charged` - Interest accrued in period
- `fees_charged` - Fees charged in period
- `ending_balance` - Calculated balance at statement end
- `minimum_payment_due` - Calculated minimum payment
- `due_date` - Payment due date
- `is_current` - Only one statement marked current per card

## Important Notes

### Statement Day Behavior
- Statements are only generated on the configured `statement_day_of_month`
- The batch job runs daily but only generates when appropriate
- If statement day is 31st and month has 30 days, uses last day of month

### Duplicate Prevention
- System checks for existing statements in the same month
- Prevents duplicate generation even if run multiple times
- Previous statements automatically marked as historical

### Transaction Categorization
- **Purchases**: Transactions that credit the CC account (increase liability)
- **Payments**: Transactions that debit the CC account (decrease liability)
- **Interest**: Marked with `metadata->>'is_interest_charge' = 'true'`
- **Fees**: Extensible via metadata flags (future feature)

### Balance Accuracy
- Balances are calculated from transaction history
- Uses `balance_snapshots` for efficient queries
- Ending balance cannot be negative (minimum 0)

## Testing

### Test Statement Generation
```sql
-- Generate statement for specific card
SELECT api.generate_statement('card_uuid_here', '2025-10-25');

-- View generated statement
SELECT * FROM api.get_current_statement('card_uuid_here');

-- Check statement period transactions
SELECT * FROM utils.get_statement_period_transactions(
  account_id_here,
  '2025-09-26',
  '2025-10-25'
);
```

### Test Minimum Payment Calculation
```sql
-- Test minimum payment for $1000 balance, 2%, $25 flat
SELECT utils.calculate_minimum_payment(100000, 2.0, 2500);
-- Expected: 2500 (cents = $25, since 2% of $1000 = $20 < $25)

-- Test minimum payment for $5000 balance, 2%, $25 flat
SELECT utils.calculate_minimum_payment(500000, 2.0, 2500);
-- Expected: 10000 (cents = $100, since 2% of $5000 = $100 > $25)
```

### Test Batch Job
```bash
# Run statement generation for today
php scripts/monthly-statement-generation.php

# Run for specific date
php scripts/monthly-statement-generation.php 2025-10-15
```

## Integration with Phase 2

Phase 3 builds on Phase 2 (Interest Accrual):

1. **Interest Charges**: Appear in statements as `interest_charged`
2. **Interest Transactions**: Included in statement period summary
3. **Statement Timing**: Generated after interest accrual (2:00 AM vs 1:00 AM)
4. **Balance Accuracy**: Includes all interest up to statement date

## Next Steps (Future Phases)

Future enhancements could include:

### Phase 4: Payment Scheduling
- Scheduled payment creation
- Auto-payment on due date
- Payment reminders
- Recurring payment setup

### Phase 5: Notifications
- Statement ready notifications
- Due date reminders (7 days, 3 days, 1 day)
- Overdue payment alerts
- Large purchase alerts

### Phase 6: Advanced Features
- Statement PDF generation
- Email delivery
- Payment history analytics
- Spending insights per statement

## Files Added/Modified

### New Files
- `migrations/20251025000002_add_billing_cycle_functions.sql` - Database functions
- `public/api/statements.php` - Statements API endpoint
- `scripts/monthly-statement-generation.php` - Batch job script
- `PHASE3_BILLING_CYCLE_MANAGEMENT.md` - This documentation

### Cron Configuration
- Added daily cron job for pgbudget user at 2:00 AM

## Troubleshooting

### Check Cron Job Status
```bash
# View crontab
crontab -l -u pgbudget

# Check cron execution logs
journalctl -u cron | grep monthly-statement

# View batch job logs
tail -f /var/log/pgbudget-statements.log
```

### Verify Database Functions
```sql
-- Check if functions exist
\df utils.generate_statement
\df api.get_statement

-- View all statements
SELECT * FROM api.credit_card_statements;

-- View upcoming due dates
SELECT * FROM api.get_upcoming_due_dates(30);

-- Check credit card limit configurations
SELECT account_uuid, account_name, statement_day_of_month, due_date_offset_days
FROM api.credit_card_limits;
```

### Common Issues

1. **No statements generated**:
   - Check if today matches `statement_day_of_month` in credit card limits
   - Verify credit card has an active limit configuration
   - Check if statement already exists for current month

2. **Incorrect minimum payment**:
   - Verify `minimum_payment_percent` and `minimum_payment_flat` settings
   - Check ending balance calculation

3. **Missing transactions in statement**:
   - Verify transaction dates fall within statement period
   - Check that transactions are not soft-deleted (`deleted_at IS NULL`)

4. **Cron not running**:
   - Verify cron service is active: `systemctl status cron`
   - Check script permissions: `ls -la scripts/monthly-statement-generation.php`
   - Ensure script is executable: `chmod +x scripts/monthly-statement-generation.php`

## API Response Examples

### Successful Statement Generation
```json
{
  "success": true,
  "message": "Statement generated successfully",
  "data": {
    "uuid": "stmt1234",
    "account_uuid": "card5678",
    "account_name": "Chase Freedom",
    "statement_period_start": "2025-09-26",
    "statement_period_end": "2025-10-25",
    "previous_balance": 115000,
    "purchases_amount": 85000,
    "payments_amount": 50000,
    "interest_charged": 2345,
    "fees_charged": 0,
    "ending_balance": 152345,
    "minimum_payment_due": 3047,
    "due_date": "2025-11-15",
    "is_current": true,
    "days_until_due": 21,
    "is_overdue": false
  }
}
```

### Statement with Transactions
```json
{
  "success": true,
  "data": {
    "uuid": "stmt1234",
    "account_name": "Chase Freedom",
    "ending_balance": 152345,
    "minimum_payment_due": 3047,
    "due_date": "2025-11-15",
    "transactions": [
      {
        "transaction_uuid": "txn001",
        "date": "2025-10-20",
        "description": "Amazon.com",
        "amount": 5999,
        "transaction_type": "purchase"
      },
      {
        "transaction_uuid": "txn002",
        "date": "2025-10-15",
        "description": "Payment - Thank You",
        "amount": 50000,
        "transaction_type": "payment"
      },
      {
        "transaction_uuid": "txn003",
        "date": "2025-10-10",
        "description": "Interest charge - Chase Freedom",
        "amount": 2345,
        "transaction_type": "purchase",
        "is_interest": "true"
      }
    ]
  }
}
```

### Upcoming Due Dates
```json
{
  "success": true,
  "data": [
    {
      "account_uuid": "card123",
      "account_name": "Chase Freedom",
      "statement_uuid": "stmt456",
      "due_date": "2025-11-05",
      "days_until_due": 11,
      "ending_balance": 152345,
      "minimum_payment_due": 3047,
      "is_overdue": false
    },
    {
      "account_uuid": "card789",
      "account_name": "Discover Card",
      "statement_uuid": "stmt012",
      "due_date": "2025-11-10",
      "days_until_due": 16,
      "ending_balance": 289012,
      "minimum_payment_due": 5780,
      "is_overdue": false
    }
  ]
}
```

## Support

For issues or questions about Phase 3 implementation, refer to:
- `CREDIT_CARD_LIMITS_DESIGN_GUIDE.md` - Overall design guide
- `PHASE2_INTEREST_ACCRUAL.md` - Phase 2 documentation (interest integration)
- Database function comments in migration file
- Inline code documentation in API endpoints

---

**Phase 3 is complete!** The system now provides comprehensive billing cycle management with automatic statement generation, minimum payment calculation, and due date tracking.
