# Loan Management Schema Review and Design

## Date: 2025-10-18

## Executive Summary

**Current State**: The `data.loans` table does **NOT** exist in the database. It is only referenced in the `api.delete_ledger()` function (migrations/20251018200000_add_delete_ledger.sql:56) but has never been created.

**Action Required**: Create the complete loan management schema from scratch.

---

## Review Findings

### 1. Database Search Results

**Search Locations**:
- All migration files in `/var/www/html/pgbudget/migrations/`
- Database schema initialization files
- Existing table definitions

**Findings**:
- No `CREATE TABLE data.loans` statement found
- Only reference to `data.loans` is in the delete_ledger function:
  ```sql
  -- Delete loans if they exist
  DELETE FROM data.loans WHERE ledger_id = v_ledger_id;
  ```
- This suggests the table was planned but never implemented

### 2. Pattern Analysis from Existing Tables

Based on the `data.accounts` table (migrations/20250506163248_add_accounts_table.sql), the standard pattern includes:

**Required Fields** (all tables):
- `id` - bigint generated always as identity primary key
- `uuid` - text not null default utils.nanoid(8) with unique constraint
- `created_at` - timestamptz not null default current_timestamp
- `updated_at` - timestamptz not null default current_timestamp
- `user_data` - text not null default utils.get_user() for RLS
- `ledger_id` - bigint not null references data.ledgers (id) on delete cascade

**Security Requirements**:
- Row Level Security (RLS) enabled
- RLS policy using `user_data = utils.get_user()`
- Proper length checks on text fields
- Check constraints for enumerated values
- Foreign key constraints with appropriate cascades

---

## Proposed Loan Schema Design

### Table 1: `data.loans`

Primary table for storing loan metadata and terms.

#### Fields

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | bigint | PK, generated always as identity | Internal ID |
| `uuid` | text | NOT NULL, UNIQUE, default utils.nanoid(8) | External identifier |
| `created_at` | timestamptz | NOT NULL, default current_timestamp | Creation timestamp |
| `updated_at` | timestamptz | NOT NULL, default current_timestamp | Last update timestamp |
| `user_data` | text | NOT NULL, default utils.get_user() | User isolation for RLS |
| `ledger_id` | bigint | NOT NULL, FK to data.ledgers(id) ON DELETE CASCADE | Parent ledger |
| `account_id` | bigint | NULL, FK to data.accounts(id) ON DELETE SET NULL | Associated liability account |
| `lender_name` | text | NOT NULL, length <= 255 | Name of lender/creditor |
| `loan_type` | text | NOT NULL, CHECK IN values | Type: 'mortgage', 'auto', 'personal', 'student', 'credit_line', 'other' |
| `principal_amount` | numeric(19,4) | NOT NULL, CHECK > 0 | Original loan amount |
| `current_balance` | numeric(19,4) | NOT NULL, CHECK >= 0, default principal_amount | Current outstanding balance |
| `interest_rate` | numeric(8,5) | NOT NULL, CHECK >= 0 AND <= 100 | Annual interest rate (percentage) |
| `interest_type` | text | NOT NULL, CHECK IN values | 'fixed', 'variable' |
| `loan_term_months` | integer | NOT NULL, CHECK > 0 | Total term in months |
| `remaining_months` | integer | NOT NULL, CHECK >= 0, default loan_term_months | Months remaining |
| `start_date` | date | NOT NULL | Loan origination date |
| `first_payment_date` | date | NOT NULL | Date of first payment |
| `payment_amount` | numeric(19,4) | NOT NULL, CHECK >= 0 | Regular payment amount (calculated or custom) |
| `payment_frequency` | text | NOT NULL, CHECK IN values | 'monthly', 'bi-weekly', 'weekly', 'quarterly' |
| `payment_day_of_month` | integer | NULL, CHECK >= 1 AND <= 31 | Day of month for monthly payments |
| `compounding_frequency` | text | NOT NULL, default 'monthly' | 'daily', 'monthly', 'annually' |
| `amortization_type` | text | NOT NULL, default 'standard' | 'standard', 'interest_only', 'balloon' |
| `status` | text | NOT NULL, default 'active' | 'active', 'paid_off', 'defaulted', 'refinanced', 'closed' |
| `notes` | text | NULL | User notes/comments |
| `metadata` | jsonb | NULL | Additional flexible data |

#### Constraints

```sql
-- Name uniqueness per ledger
CONSTRAINT loans_lender_ledger_unique UNIQUE (lender_name, ledger_id, user_data)

-- Length checks
CONSTRAINT loans_lender_name_length_check CHECK (char_length(lender_name) <= 255)
CONSTRAINT loans_user_data_length_check CHECK (char_length(user_data) <= 255)
CONSTRAINT loans_notes_length_check CHECK (char_length(notes) <= 1000)

-- Type checks
CONSTRAINT loans_type_check CHECK (
    loan_type IN ('mortgage', 'auto', 'personal', 'student', 'credit_line', 'other')
)
CONSTRAINT loans_interest_type_check CHECK (
    interest_type IN ('fixed', 'variable')
)
CONSTRAINT loans_payment_frequency_check CHECK (
    payment_frequency IN ('monthly', 'bi-weekly', 'weekly', 'quarterly')
)
CONSTRAINT loans_compounding_frequency_check CHECK (
    compounding_frequency IN ('daily', 'monthly', 'annually')
)
CONSTRAINT loans_amortization_type_check CHECK (
    amortization_type IN ('standard', 'interest_only', 'balloon')
)
CONSTRAINT loans_status_check CHECK (
    status IN ('active', 'paid_off', 'defaulted', 'refinanced', 'closed')
)

-- Value checks
CONSTRAINT loans_principal_positive CHECK (principal_amount > 0)
CONSTRAINT loans_current_balance_non_negative CHECK (current_balance >= 0)
CONSTRAINT loans_interest_rate_range CHECK (interest_rate >= 0 AND interest_rate <= 100)
CONSTRAINT loans_term_positive CHECK (loan_term_months > 0)
CONSTRAINT loans_remaining_non_negative CHECK (remaining_months >= 0)
CONSTRAINT loans_payment_non_negative CHECK (payment_amount >= 0)
CONSTRAINT loans_balance_not_exceed_principal CHECK (current_balance <= principal_amount * 2) -- Allow for accumulated interest

-- Date logic
CONSTRAINT loans_first_payment_after_start CHECK (first_payment_date >= start_date)
```

#### Indexes

```sql
CREATE INDEX idx_loans_ledger_id ON data.loans(ledger_id);
CREATE INDEX idx_loans_account_id ON data.loans(account_id);
CREATE INDEX idx_loans_status ON data.loans(status);
CREATE INDEX idx_loans_user_data ON data.loans(user_data);
```

#### RLS Policy

```sql
ALTER TABLE data.loans ENABLE ROW LEVEL SECURITY;

CREATE POLICY loans_policy ON data.loans
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

COMMENT ON POLICY loans_policy ON data.loans IS
'Ensures that users can only access and modify their own loans based on the user_data column.';
```

---

### Table 2: `data.loan_payments`

Payment schedule and history for loans.

#### Fields

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | bigint | PK, generated always as identity | Internal ID |
| `uuid` | text | NOT NULL, UNIQUE, default utils.nanoid(8) | External identifier |
| `created_at` | timestamptz | NOT NULL, default current_timestamp | Creation timestamp |
| `updated_at` | timestamptz | NOT NULL, default current_timestamp | Last update timestamp |
| `user_data` | text | NOT NULL, default utils.get_user() | User isolation for RLS |
| `loan_id` | bigint | NOT NULL, FK to data.loans(id) ON DELETE CASCADE | Parent loan |
| `payment_number` | integer | NOT NULL, CHECK > 0 | Sequential payment number |
| `due_date` | date | NOT NULL | Scheduled payment date |
| `scheduled_amount` | numeric(19,4) | NOT NULL, CHECK >= 0 | Scheduled total payment |
| `scheduled_principal` | numeric(19,4) | NOT NULL, CHECK >= 0 | Scheduled principal portion |
| `scheduled_interest` | numeric(19,4) | NOT NULL, CHECK >= 0 | Scheduled interest portion |
| `paid_date` | date | NULL | Actual payment date (NULL if unpaid) |
| `actual_amount_paid` | numeric(19,4) | NULL, CHECK >= 0 | Actual amount paid |
| `actual_principal` | numeric(19,4) | NULL, CHECK >= 0 | Actual principal portion |
| `actual_interest` | numeric(19,4) | NULL, CHECK >= 0 | Actual interest portion |
| `transaction_id` | bigint | NULL, FK to data.transactions(id) ON DELETE SET NULL | Linked transaction |
| `from_account_id` | bigint | NULL, FK to data.accounts(id) ON DELETE SET NULL | Account payment came from |
| `status` | text | NOT NULL, default 'scheduled' | 'scheduled', 'paid', 'partial', 'late', 'missed', 'skipped' |
| `days_late` | integer | NULL, CHECK >= 0 | Days past due if late |
| `notes` | text | NULL | Payment notes |

#### Constraints

```sql
-- Unique payment number per loan
CONSTRAINT loan_payments_number_unique UNIQUE (loan_id, payment_number)

-- Length checks
CONSTRAINT loan_payments_user_data_length_check CHECK (char_length(user_data) <= 255)
CONSTRAINT loan_payments_notes_length_check CHECK (char_length(notes) <= 500)

-- Status check
CONSTRAINT loan_payments_status_check CHECK (
    status IN ('scheduled', 'paid', 'partial', 'late', 'missed', 'skipped')
)

-- Payment logic
CONSTRAINT loan_payments_scheduled_split CHECK (
    scheduled_amount = scheduled_principal + scheduled_interest
)
CONSTRAINT loan_payments_actual_split CHECK (
    actual_amount_paid IS NULL OR
    actual_amount_paid = COALESCE(actual_principal, 0) + COALESCE(actual_interest, 0)
)

-- Paid payments must have details
CONSTRAINT loan_payments_paid_has_date CHECK (
    status != 'paid' OR (paid_date IS NOT NULL AND actual_amount_paid IS NOT NULL)
)
```

#### Indexes

```sql
CREATE INDEX idx_loan_payments_loan_id ON data.loan_payments(loan_id);
CREATE INDEX idx_loan_payments_due_date ON data.loan_payments(due_date);
CREATE INDEX idx_loan_payments_status ON data.loan_payments(status);
CREATE INDEX idx_loan_payments_transaction_id ON data.loan_payments(transaction_id);
CREATE INDEX idx_loan_payments_user_data ON data.loan_payments(user_data);
```

#### RLS Policy

```sql
ALTER TABLE data.loan_payments ENABLE ROW LEVEL SECURITY;

CREATE POLICY loan_payments_policy ON data.loan_payments
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

COMMENT ON POLICY loan_payments_policy ON data.loan_payments IS
'Ensures that users can only access and modify their own loan payments based on the user_data column.';
```

---

## Integration Points

### 1. Account Linkage
- Each loan can be linked to a liability account (`account_id`)
- When payments are recorded, they affect the linked account balance
- Follows double-entry accounting: debit checking account, credit loan account

### 2. Transaction Linkage
- Each payment creates a transaction in `data.transactions`
- Payment record stores `transaction_id` reference
- Allows full audit trail and account reconciliation

### 3. Ledger Cascade
- Loans cascade delete when parent ledger is deleted
- Already implemented in `api.delete_ledger()` function

---

## Calculated Fields and Triggers

### 1. Auto-update Trigger
Following the pattern from accounts table, add trigger for `updated_at`:

```sql
CREATE TRIGGER update_loans_updated_at
    BEFORE UPDATE ON data.loans
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

CREATE TRIGGER update_loan_payments_updated_at
    BEFORE UPDATE ON data.loan_payments
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();
```

### 2. Balance Update Trigger
When a payment is marked as paid, update loan's `current_balance`:

```sql
CREATE OR REPLACE FUNCTION utils.update_loan_balance()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.status = 'paid' AND OLD.status != 'paid' THEN
        UPDATE data.loans
        SET current_balance = current_balance - COALESCE(NEW.actual_principal, 0),
            remaining_months = remaining_months - 1,
            status = CASE
                WHEN current_balance - COALESCE(NEW.actual_principal, 0) <= 0.01
                THEN 'paid_off'
                ELSE status
            END
        WHERE id = NEW.loan_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_loan_balance_on_payment
    AFTER UPDATE ON data.loan_payments
    FOR EACH ROW
    WHEN (NEW.status = 'paid')
    EXECUTE FUNCTION utils.update_loan_balance();
```

---

## Amortization Calculation Requirements

### Standard Amortization Formula

For a standard amortizing loan:

```
M = P * [r(1 + r)^n] / [(1 + r)^n - 1]

Where:
M = Monthly payment
P = Principal amount
r = Monthly interest rate (annual rate / 12)
n = Number of payments (loan term in months)
```

### Payment Split Calculation

For each payment:
```
Interest = Current Balance * Monthly Interest Rate
Principal = Payment Amount - Interest
New Balance = Current Balance - Principal
```

### Implementation

This will be implemented in `api.calculate_amortization_schedule()` function.

---

## Data Migration Considerations

### Backwards Compatibility
- No existing data to migrate (table doesn't exist)
- New table creation only

### Default Values
- All fields have appropriate defaults
- Can create loans without optional fields

### Future Schema Changes
- Using `metadata` JSONB field for extensibility
- Can add new columns in future migrations without breaking changes

---

## Security Considerations

### Row Level Security
- ✅ RLS enabled on all tables
- ✅ Policy based on `user_data` column
- ✅ Users cannot access other users' loans

### Foreign Key Integrity
- ✅ Proper CASCADE on ledger deletion
- ✅ SET NULL on account deletion (preserve loan data)
- ✅ CASCADE on loan deletion (remove payment schedule)

### Input Validation
- ✅ Check constraints on all enum fields
- ✅ Length limits on text fields
- ✅ Range checks on numeric fields
- ✅ Date logic validation

### SQL Injection Prevention
- Will use parameterized queries in all API functions
- All user input will be validated

---

## Performance Considerations

### Indexes
- Primary keys on `id` columns
- Unique indexes on `uuid` columns
- Foreign key indexes on `ledger_id`, `loan_id`, `account_id`, `transaction_id`
- Query optimization indexes on `status`, `due_date`, `user_data`

### Query Patterns
Expected common queries:
1. List all loans for a ledger (indexed on `ledger_id`)
2. Get upcoming payments (indexed on `due_date`, `status`)
3. Get loan details (indexed on `uuid`)
4. Get payment history for a loan (indexed on `loan_id`)

All expected queries are well-supported by proposed indexes.

---

## Testing Requirements

### Unit Tests (SQL Level)
- Test RLS policies (user isolation)
- Test check constraints (invalid data rejected)
- Test foreign key constraints (cascade behavior)
- Test triggers (balance updates, timestamp updates)

### Integration Tests (Go)
- Test loan creation
- Test payment schedule generation
- Test payment recording
- Test loan updates and deletion
- Test cross-user access prevention

### UI Tests (Manual)
- Test all CRUD operations
- Test form validation
- Test calculations
- Test payment recording workflow

---

## Summary and Next Steps

### Findings
1. ✅ `data.loans` table does NOT exist - needs to be created
2. ✅ Pattern established from existing tables (`data.accounts` as template)
3. ✅ Complete schema designed following pgbudget conventions
4. ✅ All integration points identified

### Schema Design Completed
- ✅ `data.loans` table - 24 fields, comprehensive constraints
- ✅ `data.loan_payments` table - 18 fields, payment tracking
- ✅ RLS policies designed
- ✅ Triggers specified
- ✅ Indexes planned
- ✅ Integration with accounts and transactions defined

### Ready for Implementation
The schema is ready to be implemented in the migration file.

**Next Step**: Create migration file `migrations/YYYYMMDDHHMMSS_add_loan_management.sql`

---

## References

- Pattern source: `/var/www/html/pgbudget/migrations/20250506163248_add_accounts_table.sql`
- Delete function: `/var/www/html/pgbudget/migrations/20251018200000_add_delete_ledger.sql`
- Implementation plan: `/var/www/html/pgbudget/LOAN_MANAGEMENT_IMPLEMENTATION.md`

---

**Document Status**: ✅ Complete and ready for implementation
**Review Date**: 2025-10-18
**Reviewer**: Claude Code
**Approved for Migration**: Yes
