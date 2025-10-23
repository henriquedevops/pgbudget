# PGBudget Architecture Quick Reference

## Database Layer (PostgreSQL)

### Three-Layer Schema Architecture
```
┌─ api Schema ────────────────────┐
│ Public API interface            │
│ Views & SECURITY INVOKER fns    │
├─ utils Schema ──────────────────┤
│ Internal business logic         │
│ SECURITY DEFINER functions      │
├─ data Schema ───────────────────┤
│ Raw data with RLS enforcement   │
│ All tables use user_data column │
└─────────────────────────────────┘
```

### User Isolation Pattern
```sql
-- Every table in data schema:
1. Has user_data TEXT NOT NULL DEFAULT utils.get_user()
2. Enables RLS with policy: user_data = utils.get_user()
3. Constraints like: UNIQUE(uuid, name, ledger_id, user_data)
```

### Data Flow
```
PHP Session → SET app.current_user_id = ? → utils.get_user() → RLS Policy
$_SESSION['user_id'] ↓                                          ↓
                    PostgreSQL config setting              Filters by user
```

## Account Type System

### Semantic Types
| Type      | Internal Type    | Normal Balance | Examples |
|-----------|------------------|----------------|----------|
| asset     | asset_like       | Debit (+)      | Bank accounts |
| liability | liability_like   | Credit (+)     | Credit cards |
| equity    | liability_like   | Credit (+)     | Categories, Income |

### Double-Entry Core Transactions
```
1. Income In:         Debit Bank → Credit Income
2. Budget:            Debit Income → Credit Category
3. Cash Spend:        Debit Category → Credit Bank
4. CC Spend:          Debit Category → Credit CC
5. CC Payment:        Debit CC → Credit Bank
6. Budget Move:       Debit Category A → Credit Category B
```

## Key Tables Reference

### Core Tables
```
data.ledgers             Parent budget (auto-creates Income, Unassigned, Off-budget)
data.accounts           Assets, Liabilities, Categories (type + internal_type)
data.transactions       Double-entry transactions (debit_account_id, credit_account_id)
data.balances          Denormalized balance snapshots (updated by triggers)
data.category_goals    Budget goals per category (monthly_funding, target_balance, target_by_date)
```

### Credit Card Tables
```
data.loans              Loan tracking (APR, payment schedule, status)
data.loan_payments      Loan payment history
data.installment_plans  Installment payment plans
data.installment_schedules  Individual installments
```

### New Tables for Limits Feature (Proposed)
```
data.credit_card_limits  Spending limit, APR, billing cycle config
data.credit_card_statements  Monthly statements with interest/fees
```

## API Design Patterns

### Pattern A: Call Utils → Query API View
```php
$result = utils.function_name(...);           // Get internal ID
SELECT * FROM api.view WHERE uuid = result;  // Return user format
```

### Pattern B: Call Utils → Construct Response
```php
$result = utils.function_name(...);  // Get multiple fields
// Construct response from utils result + input params
return constructed_record;
```

### Request/Response Structure
```
Request: JSON with fields matching API function params
         All amounts in CENTS (bigint)
         All dates in YYYY-MM-DD or ISO format
         All IDs as 8-char nanoids

Response: { success: bool, message: str, data: obj, error: str }
```

## Input Handling

### Currency (Stored as cents)
```
Input:  "10.50", "$10.50", "10,50" → parseCurrency() → 1050 (cents)
Output: 1050 (cents) → formatCurrency() → "$10.50"
```

### Validation
```
- Required field checks
- Format: dates (YYYY-MM-DD), UUIDs (8-char nanoid)
- Amount > 0, balance >= 0
- Ledger/account ownership via RLS
```

## Security Layers

### SQL Injection Prevention
- Prepared statements with ? placeholders
- PDO::ERRMODE_EXCEPTION for error handling

### RLS Enforcement
- Database-level: ALL data.* queries filtered by user_data
- Prevents unauthorized access even with SQL injection

### Authentication
- Session-based: $_SESSION['user_id']
- Per-request context: SET app.current_user_id = ?
- Demo mode flag for limited access

### Password Security
- Bcrypt: crypt(password, gen_salt('bf', 12))
- Verification: crypt(input, stored_hash) = stored_hash

## Common Function Patterns

### Add Resource (with auto-category for CCs)
```sql
utils.add_[resource](p_uuid, p_name, ..., p_user_data = utils.get_user())
  → Resolve UUIDs to IDs with user check
  → Insert into data table
  → Trigger creates related records (e.g., CC Payment category)
  → Return ID/UUID
```

### Move Money / Create Budget Move
```sql
utils.move_[between_categories|for_cc_spending](...)
  → Check source balance >= amount
  → Create transaction: debit source, credit destination
  → Update balance snapshots
  → Metadata tracks origin (is_cc_budget_move, etc.)
```

### Get Balance
```sql
utils.get_account_balance(ledger_id, account_id)
  → Sum of (credits - debits) for liability_like accounts
  → Sum of (debits - credits) for asset_like accounts
  → Returns signed integer (can be negative)
```

## Frontend Technology

### Server-Side
- PHP 7.4+ (template rendering)
- Session management (built-in)
- No frameworks

### Client-Side
- Vanilla JavaScript (no jQuery/React)
- CSS Grid/Flexbox responsive layouts
- Fetch API for AJAX requests
- Auto-dismissing messages (5s)

### Key JS Modules
```
quick-add-modal.js       Transaction creation
move-money-modal.js      Budget reallocation
goals-manager.js         Goal configuration
loans.js                 Loan management
installments.js          Installment tracking
keyboard-shortcuts.js    Keyboard navigation
```

## File Structure

```
/public/api/           API endpoints (.php)
/public/budget/        Dashboard & budget pages
/public/accounts/      Account management
/public/transactions/  Transaction pages
/public/loans/         Loan pages
/public/installments/  Installment pages
/public/settings/      User settings
/public/css/           Stylesheets
/public/js/            JavaScript modules
/config/database.php   DB connection & helpers
/includes/auth.php     Authentication functions
/includes/functions.php  Helper functions
/migrations/           SQL migrations (goose)
```

## For Credit Card Limits Feature Implementation

### Key Considerations
1. **Always use RLS**: credit_card_limits table gets user_data column
2. **Follow patterns**: Implement utils → api pattern
3. **Double-entry**: Interest is debit category/liability, credit CC
4. **Soft deletes**: Mark inactive, don't delete
5. **Audit trail**: Log all limit changes via action_history
6. **Validation**: Check constraints in SQL + PHP validation
7. **User context**: Always verify CC account ownership
8. **Denormalization**: Store calculated fields in statements table
9. **Consistency**: Use cents, nanoid UUIDs, ISO dates
10. **Triggers**: Auto-create interest transactions on schedule

### Proposed Tables Structure
```
data.credit_card_limits
  - credit_card_account_id (FK)
  - credit_limit (numeric, > 0)
  - annual_percentage_rate (0-100)
  - statement_day_of_month (1-31)
  - due_date_offset_days
  - auto_payment_enabled, auto_payment_type
  - RLS: user_data = utils.get_user()

data.credit_card_statements
  - credit_card_account_id (FK)
  - statement_period_start, statement_period_end
  - purchases_amount, interest_charged, fees_charged
  - minimum_payment_due, due_date
  - ending_balance
  - RLS: user_data = utils.get_user()
```

### Key Functions to Implement
```sql
utils.set_credit_card_limit(...)           -- Configure limits/APR
utils.check_credit_limit_violation(...)    -- Pre-transaction check
utils.calculate_interest_accrual(...)      -- Monthly interest
utils.generate_credit_card_statement(...)  -- Statement generation
utils.calculate_minimum_payment(...)       -- Min payment calc
utils.process_auto_payment(...)            -- Payment processing

api.set_credit_card_limit(...)            -- API wrapper
api.get_credit_card_limits(...)           -- Read limits
api.get_billing_summary(...)              -- Current billing info
api.schedule_payment(...)                 -- Queue payment
```

## Testing Patterns

### Database Testing
```sql
-- Set user context
SELECT set_config('app.current_user_id', 'test_user', false);

-- Create test data
INSERT INTO data.ledgers (name) VALUES ('Test Budget');

-- Verify RLS
SELECT * FROM data.ledgers;  -- Only test_user's data

-- Test function
SELECT utils.add_transaction(...);

-- Check transaction created
SELECT * FROM data.transactions;
```

### API Testing
```bash
curl -X POST http://localhost/pgbudget/api/endpoint.php \
  -H "Content-Type: application/json" \
  -d '{"field1": "value1", ...}'
```

## Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "user_data = utils.get_user() failed" | Session not set | Call setUserContext($db) first |
| "violates unique constraint" | Duplicate name in ledger | Names must be unique per ledger+user |
| "violates foreign key constraint" | Referenced account not found | Verify ledger ownership |
| "amount must be positive" | Amount = 0 or negative | Validate amount > 0 in PHP |
| "credit_account_id != debit_account_id" | Same account for both | Ensure different accounts |

## Documentation Files

```
ARCHITECTURE.md                    - Full architecture details
SPEC.md                           - Double-entry accounting spec
CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - This feature's detailed guide
PHASE_4.X_*.md                    - CC payment implementation docs
LOAN_MANAGEMENT_IMPLEMENTATION.md  - Loan system details
INSTALLMENT_PAYMENTS_*.md         - Installment system details
```

---

**Quick Start for New Feature Development:**
1. Review ARCHITECTURE.md for full context
2. Check existing similar feature (loans, installments)
3. Create migration with tables + RLS + triggers
4. Implement utils functions with user_data parameter
5. Implement api functions calling utils
6. Add PHP API endpoints wrapping database functions
7. Add frontend UI with modal dialogs
8. Test with setUserContext() for multi-user isolation
