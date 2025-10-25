# Phase 4: Payment Scheduling

This document describes the implementation of Phase 4 from the Credit Card Limits Design Guide, which adds payment scheduling capabilities including one-time scheduled payments, recurring auto-payments, and automated payment processing.

## Overview

Phase 4 implements comprehensive payment scheduling for credit card accounts. Users can schedule one-time payments, set up automatic payments from statements, and have payments processed automatically on their scheduled dates. The system handles payment amount calculation, validates sufficient funds, and provides detailed processing results.

## Features Implemented

### 1. Payment Scheduling
- **One-Time Scheduled Payments**: Schedule a single payment for a specific date
- **Auto-Payments from Statements**: Automatically create payments when statements are generated
- **Flexible Payment Types**: Minimum payment, full balance, fixed amount, or custom amount
- **Multiple Accounts**: Schedule payments from any bank account in the same ledger
- **Future-Dated**: Schedule payments for any future date

### 2. Payment Types
- **Minimum Payment**: Pay the calculated minimum payment due from statement
- **Full Balance**: Pay the entire credit card balance
- **Fixed Amount**: Pay a specific configured amount
- **Custom Amount**: Pay any user-specified amount

### 3. Payment Processing
- **Automatic Processing**: Daily batch job processes due payments
- **Insufficient Funds Check**: Validates bank account has sufficient funds
- **Transaction Creation**: Creates proper double-entry transaction
- **Error Handling**: Tracks failures with retry counter
- **Status Tracking**: Pending → Processing → Completed/Failed

### 4. Auto-Payment Configuration
- **Statement Integration**: Auto-create payments when statements generate
- **Configurable Type**: Minimum, full balance, or fixed amount
- **Payment Date**: Configure specific day of month or use due date
- **Per-Card Settings**: Each card can have different auto-payment rules

### 5. Database Schema

#### New Table: `data.scheduled_payments`
```sql
Key Fields:
- credit_card_account_id: Credit card to pay
- bank_account_id: Bank account to pay from
- statement_id: Related statement (if any)
- payment_type: minimum|full_balance|fixed_amount|custom
- payment_amount: Amount in cents (for fixed/custom)
- scheduled_date: Date to process payment
- status: pending|processing|completed|failed|cancelled
- processed_transaction_id: Created transaction reference
- actual_amount_paid: Amount actually paid
- error_message: Failure reason (if failed)
- retry_count: Number of retry attempts
```

### 6. Database Functions

#### Utils Layer (Internal Business Logic)
- `utils.calculate_payment_amount(type, statement_id, amount)` - Calculate payment amount
- `utils.process_scheduled_payment(payment_id, user)` - Process single payment
- `utils.process_all_scheduled_payments(date)` - Batch process due payments
- `utils.create_auto_payments_from_statements(date)` - Create auto-payments from new statements

#### API Layer (Public Interface)
- `api.scheduled_payments` - View of scheduled payments with status
- `api.schedule_payment(...)` - Create new scheduled payment
- `api.cancel_scheduled_payment(payment_uuid)` - Cancel pending payment
- `api.get_scheduled_payments(card_uuid, status)` - Query scheduled payments

### 7. API Endpoint

#### `/api/scheduled-payments.php`
Comprehensive payment scheduling management.

**GET** - Retrieve scheduled payments
```bash
# Get specific payment
GET /api/scheduled-payments.php?payment_uuid=abc12345

# Get all payments for a credit card
GET /api/scheduled-payments.php?credit_card_uuid=xyz78901

# Filter by status
GET /api/scheduled-payments.php?status=pending

# Get all scheduled payments
GET /api/scheduled-payments.php
```

**POST** - Schedule a payment
```json
{
  "credit_card_uuid": "xyz78901",
  "bank_account_uuid": "bank5678",
  "payment_type": "minimum",
  "scheduled_date": "2025-11-15",
  "statement_uuid": "stmt1234"
}
```

**DELETE** - Cancel a scheduled payment
```json
{
  "payment_uuid": "pay4567"
}
```

**PATCH** - Process payment manually (testing/admin)
```json
{
  "payment_uuid": "pay4567",
  "action": "process"
}
```

### 8. Batch Job

**Script**: `/var/www/html/pgbudget/scripts/process-scheduled-payments.php`

**Two-Step Process**:
1. **Create Auto-Payments**: Generate scheduled payments from new statements with auto-payment enabled
2. **Process Due Payments**: Process all payments scheduled for today or earlier

**Cron Schedule**: Daily at 3:00 AM (after statements at 2:00 AM)
```bash
0 3 * * * /usr/bin/php /var/www/html/pgbudget/scripts/process-scheduled-payments.php >> /var/log/pgbudget-payments.log 2>&1
```

**Manual Execution**:
```bash
# Process payments for today
php scripts/process-scheduled-payments.php

# Process payments for specific date
php scripts/process-scheduled-payments.php 2025-10-25
```

**Output Example**:
```
==========================================================
PGBudget Scheduled Payment Processing
==========================================================
Date: 2025-10-25 03:00:00
Processing Date: 2025-10-25
==========================================================

Step 1: Creating auto-payments from statements...
----------------------------------------------------------
Auto-payments created: 2
Skipped: 1

Step 2: Processing scheduled payments...
----------------------------------------------------------

==========================================================
PAYMENT PROCESSING RESULTS
==========================================================
Total Processed: 3
Successfully Processed: 2
Skipped: 0
Failed: 1
==========================================================

PAYMENT DETAILS:
----------------------------------------------------------
✓ Chase Freedom (pay123)
  Type: minimum
  Amount: $30.47
  From: Checking Account
  Message: Payment processed successfully

✓ Discover Card (pay456)
  Type: full_balance
  Amount: $289.12
  From: Savings Account
  Message: Payment processed successfully

✗ Visa Card (pay789)
  Type: fixed_amount
  Message: Insufficient funds in bank account
----------------------------------------------------------

Batch job completed with errors
==========================================================
```

## Payment Processing Flow

### Scheduling a Payment

1. **User Action**: Schedule payment via API or auto-payment configuration
2. **Validation**:
   - Verify credit card and bank account exist
   - Check both accounts in same ledger
   - Validate payment type and amount
   - Ensure scheduled date not in past
3. **Creation**: Insert record into `scheduled_payments` with status='pending'
4. **Response**: Return payment UUID and details

### Processing a Payment

1. **Daily Batch Job**: Runs at 3:00 AM
2. **Query**: Find all payments with `status='pending'` and `scheduled_date <= today`
3. **For Each Payment**:
   - Lock payment record (status='processing')
   - Calculate payment amount based on type
   - Get current credit card balance
   - Cap payment at current balance
   - Check bank account has sufficient funds
   - Create transaction (debit CC, credit bank)
   - Update payment status='completed'
   - Record transaction ID and actual amount
4. **Error Handling**:
   - On failure: status='failed', increment retry_count
   - Store error message
   - Continue processing other payments

### Auto-Payment Creation

1. **Trigger**: Statement generated (Phase 3)
2. **Check**: Is auto-payment enabled for this card?
3. **Validate**: Statement has balance > 0
4. **Find Bank Account**: Get primary bank account (first asset account)
5. **Determine Date**:
   - Use configured `auto_payment_date` if set
   - Otherwise use statement `due_date`
6. **Create Payment**: Insert scheduled payment record
7. **Link**: Associate payment with statement

## Payment Type Details

### Minimum Payment
```json
{
  "payment_type": "minimum",
  "statement_uuid": "stmt123"
}
```
- Requires statement reference
- Uses `minimum_payment_due` from statement
- Best for maintaining account in good standing

### Full Balance
```json
{
  "payment_type": "full_balance",
  "statement_uuid": "stmt123"
}
```
- Requires statement reference
- Uses `ending_balance` from statement
- Avoids all interest charges

### Fixed Amount
```json
{
  "payment_type": "fixed_amount",
  "payment_amount": 100.00
}
```
- Configured in credit card limit settings
- Same amount every time
- Good for consistent budgeting

### Custom Amount
```json
{
  "payment_type": "custom",
  "payment_amount": 250.50
}
```
- User specifies exact amount
- Flexible for any situation
- One-time use typically

## Auto-Payment Configuration

When setting up credit card limits, configure auto-payment:

```bash
curl -X POST http://localhost/pgbudget/api/credit-card-limits.php \
  -H "Content-Type: application/json" \
  -d '{
    "account_uuid": "card123",
    "credit_limit": 5000.00,
    "annual_percentage_rate": 18.99,
    "statement_day_of_month": 15,
    "due_date_offset_days": 21,
    "auto_payment_enabled": true,
    "auto_payment_type": "minimum",
    "auto_payment_date": 5
  }'
```

**Auto-Payment Settings**:
- `auto_payment_enabled`: true/false
- `auto_payment_type`: minimum|full_balance|fixed_amount
- `auto_payment_amount`: Amount (required if type=fixed_amount)
- `auto_payment_date`: Day of month (optional, uses due date if not set)

## Usage Examples

### Schedule One-Time Payment

**Minimum Payment on Due Date**:
```bash
curl -X POST http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "credit_card_uuid": "card123",
    "bank_account_uuid": "bank456",
    "payment_type": "minimum",
    "scheduled_date": "2025-11-15",
    "statement_uuid": "stmt789"
  }'
```

**Custom Amount on Specific Date**:
```bash
curl -X POST http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "credit_card_uuid": "card123",
    "bank_account_uuid": "bank456",
    "payment_type": "custom",
    "payment_amount": 150.00,
    "scheduled_date": "2025-11-01"
  }'
```

### View Scheduled Payments

**All Pending Payments**:
```bash
curl "http://localhost/pgbudget/api/scheduled-payments.php?status=pending"
```

**Payments for Specific Card**:
```bash
curl "http://localhost/pgbudget/api/scheduled-payments.php?credit_card_uuid=card123"
```

### Cancel Scheduled Payment

```bash
curl -X DELETE http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "payment_uuid": "pay789"
  }'
```

### Process Payment Manually (Testing)

```bash
curl -X PATCH http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "payment_uuid": "pay789",
    "action": "process"
  }'
```

## Scheduled Payment Structure

```json
{
  "uuid": "pay12345",
  "created_at": "2025-10-25T10:30:00Z",
  "updated_at": "2025-10-25T10:30:00Z",
  "credit_card_uuid": "card678",
  "credit_card_name": "Chase Freedom",
  "bank_account_uuid": "bank901",
  "bank_account_name": "Checking Account",
  "statement_uuid": "stmt234",
  "payment_type": "minimum",
  "payment_amount": null,
  "scheduled_date": "2025-11-15",
  "status": "pending",
  "processed_date": null,
  "transaction_uuid": null,
  "actual_amount_paid": null,
  "error_message": null,
  "retry_count": 0,
  "notes": null,
  "metadata": {
    "is_auto_payment": true
  },
  "days_until_scheduled": 21,
  "is_overdue": false
}
```

## Payment Status Lifecycle

```
pending → processing → completed
                   ↓
                 failed → (retry) → pending
                                ↓
                            cancelled
```

**Status Descriptions**:
- **pending**: Waiting to be processed
- **processing**: Currently being processed (temporary)
- **completed**: Successfully processed, transaction created
- **failed**: Processing failed, see error_message
- **cancelled**: User cancelled before processing

## Error Handling

### Common Errors

**Insufficient Funds**:
```json
{
  "success": false,
  "error": "Insufficient funds in bank account",
  "bank_balance": 50000,
  "payment_amount": 100000
}
```
- Status set to 'failed'
- Retry count incremented
- Can be retried when funds available

**Account Not Found**:
```json
{
  "success": false,
  "error": "Credit card account not found"
}
```
- Status set to 'failed'
- Payment should be cancelled and recreated

**Zero Balance**:
```json
{
  "success": true,
  "processed": false,
  "message": "No payment needed - balance is zero",
  "payment_amount": 0
}
```
- Status set to 'completed'
- No transaction created
- Considered successful (nothing to pay)

### Retry Logic

- Failed payments remain in 'failed' status
- `retry_count` incremented on each failure
- `last_retry_at` timestamp updated
- Manual retry: Change status to 'pending' or process manually
- Automatic retry: Not currently implemented (could be added)

## Integration with Previous Phases

### Phase 2 (Interest Accrual)
- Interest charges increase credit card balance
- Payments reduce balance after interest applied
- Payment amount calculations include accrued interest

### Phase 3 (Statements)
- Statements provide payment amount calculations
- Auto-payments created when statements generate
- Minimum payment and full balance use statement data
- Due dates drive payment scheduling

**Timeline**:
```
1:00 AM - Interest Accrual (Phase 2)
2:00 AM - Statement Generation (Phase 3)
3:00 AM - Payment Processing (Phase 4)
```

## Transaction Details

When a payment is processed, a transaction is created:

```
Description: "Scheduled payment - [Card Name]"
Date: scheduled_date
Amount: actual_amount_paid (in cents)

Accounting Entry:
  Debit: Credit Card Account (decrease liability)
  Credit: Bank Account (decrease asset)

Metadata:
  is_scheduled_payment: true
  scheduled_payment_uuid: [payment UUID]
  payment_type: [type]
  credit_card_uuid: [card UUID]
  bank_account_uuid: [bank UUID]
```

## Testing

### Test Payment Amount Calculation

```sql
-- Test minimum payment calculation
SELECT utils.calculate_payment_amount('minimum', statement_id_here, NULL);

-- Test full balance calculation
SELECT utils.calculate_payment_amount('full_balance', statement_id_here, NULL);

-- Test fixed amount
SELECT utils.calculate_payment_amount('fixed_amount', NULL, 10000);
-- Expected: 10000 (cents = $100)
```

### Test Payment Processing

```bash
# Schedule a test payment
curl -X POST http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "credit_card_uuid": "card123",
    "bank_account_uuid": "bank456",
    "payment_type": "custom",
    "payment_amount": 50.00,
    "scheduled_date": "2025-10-25"
  }'

# Process it immediately (testing)
curl -X PATCH http://localhost/pgbudget/api/scheduled-payments.php \
  -H "Content-Type: application/json" \
  -d '{
    "payment_uuid": "[returned UUID]",
    "action": "process"
  }'
```

### Test Batch Job

```bash
# Run payment processing for today
php scripts/process-scheduled-payments.php

# Run for specific date
php scripts/process-scheduled-payments.php 2025-10-25
```

## Important Notes

### Payment Amount Caps
- Payment amount never exceeds current credit card balance
- If user schedules $1000 but balance is $500, only $500 is paid
- Prevents overpayment and negative balances

### Bank Account Selection
- For auto-payments: Uses first (oldest) asset account in ledger
- For manual payments: User specifies bank account
- Must be in same ledger as credit card

### Date Validation
- Scheduled date cannot be in the past
- Batch job processes date <= today (includes overdue)
- No timezone conversion (uses database server timezone)

### Concurrent Processing
- Payment locked during processing (status='processing')
- Prevents duplicate processing if batch job runs twice
- Database transaction ensures atomic updates

### Statement Association
- Optional for fixed_amount and custom types
- Required for minimum and full_balance types
- Links payment to specific billing cycle

## Files Added/Modified

### New Files
- `migrations/20251025000003_add_payment_scheduling.sql` - Database schema and functions
- `public/api/scheduled-payments.php` - Payment scheduling API
- `scripts/process-scheduled-payments.php` - Batch job script
- `PHASE4_PAYMENT_SCHEDULING.md` - This documentation

### Cron Configuration
- Added daily cron job for pgbudget user at 3:00 AM

## Troubleshooting

### Check Cron Job Status
```bash
# View crontab
crontab -l -u pgbudget

# Check cron execution logs
journalctl -u cron | grep payments

# View batch job logs
tail -f /var/log/pgbudget-payments.log
```

### Verify Database Functions
```sql
-- Check if functions exist
\df utils.process_scheduled_payment
\df api.schedule_payment

-- View all scheduled payments
SELECT * FROM api.scheduled_payments;

-- View pending payments
SELECT * FROM api.scheduled_payments WHERE status = 'pending';

-- Check failed payments
SELECT * FROM api.scheduled_payments WHERE status = 'failed';
```

### Common Issues

1. **Payment not processing**:
   - Check scheduled_date is today or earlier
   - Verify status is 'pending' (not 'failed' or 'cancelled')
   - Check batch job ran successfully
   - Review error_message if status='failed'

2. **Insufficient funds**:
   - Payment marked as 'failed'
   - Add funds to bank account
   - Change payment status back to 'pending' or reschedule

3. **Auto-payment not created**:
   - Verify auto_payment_enabled=true in credit card limit
   - Check statement was generated today
   - Ensure ending_balance > 0
   - Verify bank account exists in ledger

4. **Cron not running**:
   - Verify cron service: `systemctl status cron`
   - Check script permissions: `ls -la scripts/process-scheduled-payments.php`
   - Ensure executable: `chmod +x scripts/process-scheduled-payments.php`

## API Response Examples

### Successful Payment Scheduling
```json
{
  "success": true,
  "message": "Payment scheduled successfully",
  "data": {
    "uuid": "pay12345",
    "credit_card_name": "Chase Freedom",
    "bank_account_name": "Checking Account",
    "payment_type": "minimum",
    "scheduled_date": "2025-11-15",
    "status": "pending",
    "days_until_scheduled": 21
  }
}
```

### Successful Payment Processing
```json
{
  "success": true,
  "processed": true,
  "message": "Payment processed successfully",
  "payment_uuid": "pay12345",
  "transaction_id": 98765,
  "payment_amount": 3047,
  "payment_amount_display": "$30.47",
  "credit_card": "Chase Freedom",
  "bank_account": "Checking Account"
}
```

### Failed Payment Processing
```json
{
  "success": false,
  "error": "Insufficient funds in bank account",
  "bank_balance": 50000,
  "payment_amount": 100000
}
```

## Future Enhancements

Potential improvements for future versions:

### Advanced Features
- **Payment Reminders**: Notify users before scheduled payments
- **Automatic Retry**: Retry failed payments with configurable delays
- **Recurring Payments**: Set up repeating payment schedules
- **Payment History**: Detailed analytics and reporting
- **Multiple Bank Accounts**: Configure preferred account per card
- **Payment Approval**: Require confirmation before processing

### Risk Management
- **Balance Threshold**: Don't process if bank balance falls below threshold
- **Daily Limit**: Cap total daily payment amount
- **Velocity Checks**: Alert on unusual payment patterns
- **Account Verification**: Confirm account ownership before processing

## Support

For issues or questions about Phase 4 implementation, refer to:
- `CREDIT_CARD_LIMITS_DESIGN_GUIDE.md` - Overall design guide
- `PHASE2_INTEREST_ACCRUAL.md` - Phase 2 (interest integration)
- `PHASE3_BILLING_CYCLE_MANAGEMENT.md` - Phase 3 (statement integration)
- Database function comments in migration file
- Inline code documentation in API endpoints

---

**Phase 4 is complete!** The system now provides comprehensive payment scheduling with automatic processing, flexible payment types, and robust error handling.
