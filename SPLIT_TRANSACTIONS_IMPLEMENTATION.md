# Split Transactions Implementation

## Overview

Split transactions allow users to divide a single transaction amount across multiple budget categories. This is especially useful for purchases where a single receipt includes items from different categories (e.g., groceries, household items, and personal care from one store).

## Implementation Summary

### Database Changes

#### 1. Transaction Splits Table (`data.transaction_splits`)
**Migration:** `20251011000003_add_transaction_splits_table.sql`

```sql
CREATE TABLE data.transaction_splits (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    parent_transaction_id bigint NOT NULL REFERENCES data.transactions(id) ON DELETE CASCADE,
    category_id bigint NOT NULL REFERENCES data.accounts(id),
    amount bigint NOT NULL CHECK (amount > 0),
    memo text,
    created_at timestamptz NOT NULL DEFAULT current_timestamp,
    user_data text NOT NULL DEFAULT utils.get_user()
);
```

**Features:**
- Links to parent transaction via `parent_transaction_id`
- Each split has its own category, amount, and optional memo
- RLS (Row Level Security) enabled for multi-tenant security
- Cascade delete when parent transaction is deleted

#### 2. Utils Function (`utils.add_split_transaction`)
**Migration:** `20251011000004_add_split_transaction_utils.sql`

**Purpose:** Core business logic for creating split transactions

**Key Features:**
- Validates that splits array is not empty
- Ensures sum of splits equals total transaction amount
- Creates parent transaction with metadata flag `{is_split: true}`
- Creates individual split records in `transaction_splits` table
- Creates corresponding accounting transactions for each split
- Handles both inflow and outflow transactions
- Supports both asset-like and liability-like accounts

**Parameters:**
- `p_ledger_uuid` - The ledger UUID
- `p_date` - Transaction date
- `p_description` - Main transaction description
- `p_type` - 'inflow' or 'outflow'
- `p_total_amount` - Total transaction amount (in cents)
- `p_account_uuid` - The bank account or credit card UUID
- `p_splits` - JSON array of splits: `[{category_uuid, amount, memo}]`
- `p_user_data` - User context (defaults to current user)

**Returns:** Parent transaction ID

#### 3. API Functions
**Migration:** `20251011000005_add_split_transaction_api.sql`

##### `api.add_split_transaction()`
Public API wrapper for creating split transactions. Validates input and calls the utils function.

**Returns:** Transaction UUID (string)

##### `api.get_transaction_splits()`
Retrieves all splits for a given transaction.

**Parameters:**
- `p_transaction_uuid` - The parent transaction UUID

**Returns Table:**
- `split_uuid` - The split's unique identifier
- `category_uuid` - The category UUID
- `category_name` - The category name
- `amount` - The split amount (in cents)
- `memo` - Optional memo for this split

### Frontend Changes

#### Updated Transaction Form (`public/transactions/add.php`)

**New UI Elements:**
1. **Split Toggle Checkbox**
   - Enables/disables split transaction mode
   - Shows/hides split container

2. **Split Container**
   - Dynamic split rows with:
     - Category selector
     - Amount input
     - Memo field
     - Remove button
   - "Add Category" button to add more splits
   - Real-time summary showing:
     - Total transaction amount
     - Assigned amount (sum of splits)
     - Remaining amount (color-coded)

3. **Form Handling**
   - Regular transactions use existing `api.add_transaction()`
   - Split transactions use new `api.add_split_transaction()`
   - Server-side validation ensures splits sum equals total

**JavaScript Features:**
- Dynamic split row management
- Real-time calculation of remaining amount
- Visual feedback (green/red/blue) based on remaining
- Input validation (currency format, decimal places)
- Form submission validation (splits must equal total)
- Automatic currency formatting in summary

**CSS Styling:**
- Clean, modern design with proper spacing
- Responsive layout (mobile-friendly)
- Color-coded feedback for split status
- Hover effects and visual hierarchy

## Usage Examples

### Example 1: Grocery Store Purchase
**Scenario:** $100 purchase at Walmart
- Groceries: $60
- Household items: $30
- Personal care: $10

**API Call:**
```sql
SELECT api.add_split_transaction(
    'ledger_uuid_here',
    '2025-10-11',
    'Walmart - multiple categories',
    'outflow',
    10000,  -- $100.00
    'checking_account_uuid',
    '[
        {"category_uuid": "groceries_uuid", "amount": 6000, "memo": "Food and beverages"},
        {"category_uuid": "household_uuid", "amount": 3000, "memo": "Cleaning supplies"},
        {"category_uuid": "personal_care_uuid", "amount": 1000, "memo": "Toiletries"}
    ]'::jsonb
);
```

### Example 2: Online Purchase with Shipping
**Scenario:** $85 online purchase
- Electronics: $75
- Shipping: $10

**Split:**
```json
[
    {"category_uuid": "electronics_uuid", "amount": 7500, "memo": "USB cable"},
    {"category_uuid": "shipping_uuid", "amount": 1000, "memo": "Shipping cost"}
]
```

## Accounting Details

### How Split Transactions Work

For an **outflow** from an **asset account** (e.g., checking):
1. **Parent Transaction:** Records the total amount leaving the account
2. **Split Transactions:** Each creates a debit to its category and credit to the account
3. **Net Effect:** Account balance decreases by total, each category balance decreases by its split amount

For an **inflow** to an **asset account**:
1. **Parent Transaction:** Records the total amount entering the account
2. **Split Transactions:** Each creates a debit to the account and credit to its category
3. **Net Effect:** Account balance increases by total, each category balance increases by its split amount

### Double-Entry Accounting

Split transactions maintain proper double-entry bookkeeping:
- Every debit has a corresponding credit
- Account balances always remain accurate
- Category balances reflect actual spending/income
- Audit trail is maintained for all splits

## Validation Rules

1. **Non-empty splits:** Must have at least one split
2. **Sum validation:** Sum of all splits must exactly equal total transaction amount
3. **Positive amounts:** All split amounts must be greater than zero
4. **Valid categories:** All category UUIDs must exist and belong to the ledger
5. **Category selection:** Frontend requires category selection for each split

## User Interface Flow

1. User selects transaction type (inflow/outflow)
2. User enters total amount
3. User checks "Split this transaction across multiple categories"
4. Split UI appears with one empty split row
5. User selects category, enters amount, optionally adds memo
6. User clicks "+ Add Category" to add more splits
7. Summary shows real-time remaining amount:
   - **Green:** Unassigned amount remaining
   - **Blue:** Perfectly balanced (remaining = $0.00)
   - **Red:** Over-assigned (splits exceed total)
8. User submits form
9. Client-side validation checks splits equal total
10. Server creates split transaction

## Benefits

### For Users
- **Accuracy:** Properly categorize complex purchases
- **Clarity:** See exactly how money was spent across categories
- **Budgeting:** Better budget tracking with accurate category splits
- **Flexibility:** Handle real-world scenarios (mixed purchases)

### For Accounting
- **Precision:** Maintains accurate double-entry bookkeeping
- **Traceability:** Full audit trail of split details
- **Reporting:** Category reports reflect true spending patterns
- **Integrity:** Database constraints ensure data consistency

## Testing

Comprehensive tests added in `main_test.go`:

### Test Cases
1. **AddSplitTransaction**
   - Creates a split transaction with 2 categories
   - Verifies splits are stored correctly
   - Confirms amounts and memos match
   - Validates total equals sum of splits

2. **SplitValidation_MismatchedTotal**
   - Attempts to create split where splits don't equal total
   - Expects error about mismatched amounts

3. **SplitValidation_EmptySplits**
   - Attempts to create split with empty splits array
   - Expects error requiring at least one split

## Future Enhancements

Potential improvements for future versions:

1. **Edit Split Transactions**
   - Allow editing existing splits
   - Maintain transaction history

2. **Split Templates**
   - Save common split patterns
   - Quick-apply to new transactions

3. **Split Visualization**
   - Pie charts showing split breakdown
   - Visual split representation in transaction list

4. **Percentage Splits**
   - Allow entering splits as percentages
   - Auto-calculate amounts

5. **Recurring Split Transactions**
   - Combine with recurring transaction feature
   - Regular split patterns (rent + utilities)

6. **Import with Splits**
   - Bank import recognizes split patterns
   - Auto-suggest splits based on history

## Migration Notes

**Applied Migrations:**
- `20251011000003_add_transaction_splits_table.sql`
- `20251011000004_add_split_transaction_utils.sql`
- `20251011000005_add_split_transaction_api.sql`

**Rollback:**
All migrations include proper down migrations for safe rollback:
```bash
goose -dir migrations postgres "connection_string" down
```

## Files Modified

### New Files
- `migrations/20251011000003_add_transaction_splits_table.sql`
- `migrations/20251011000004_add_split_transaction_utils.sql`
- `migrations/20251011000005_add_split_transaction_api.sql`
- `SPLIT_TRANSACTIONS_IMPLEMENTATION.md` (this file)

### Modified Files
- `public/transactions/add.php` - Added split transaction UI and handling
- `main_test.go` - Added split transaction tests

## API Reference

### Create Split Transaction
```sql
api.add_split_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_total_amount bigint,
    p_account_uuid text,
    p_splits jsonb
) RETURNS text
```

### Get Transaction Splits
```sql
api.get_transaction_splits(
    p_transaction_uuid text
) RETURNS TABLE (
    split_uuid text,
    category_uuid text,
    category_name text,
    amount bigint,
    memo text
)
```

## Conclusion

Split transactions are now fully implemented in PGBudget, providing users with the flexibility to accurately categorize complex purchases while maintaining strict double-entry accounting principles. The feature includes comprehensive validation, a user-friendly interface, and proper database constraints to ensure data integrity.

This implementation follows Phase 3.1 of the YNAB Comparison and Enhancement Plan, bringing PGBudget one step closer to feature parity with YNAB while maintaining its open-source, self-hosted advantages.
