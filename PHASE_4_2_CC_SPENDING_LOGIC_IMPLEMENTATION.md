# Phase 4.2: Credit Card Spending Logic - Implementation Summary

**Implementation Date:** 2025-10-12
**Status:** ✅ Complete and Tested

## Overview

Implemented YNAB-style credit card spending logic that automatically moves budget from spending categories to credit card payment categories when spending on credit cards. This ensures budget is "reserved" for paying the credit card balance.

## What Was Implemented

### 1. Database Migration (`20251012170000_add_cc_spending_logic.sql`)

Created a comprehensive migration with three layers:

#### Utils Layer Functions

1. **`utils.move_budget_for_cc_spending()`**
   - Moves budget from spending category to CC payment category
   - Creates a transaction with metadata marking it as a budget move
   - Handles cases where source category doesn't have sufficient budget (creates overspending)

2. **`utils.get_cc_payment_category_id()`**
   - Retrieves the payment category ID for a given credit card
   - Uses metadata to find the linked payment category
   - Returns NULL if no payment category exists

3. **`utils.auto_move_cc_budget_fn()` (Trigger Function)**
   - Automatically fires after INSERT on data.transactions
   - Detects credit card spending pattern: Debit Category, Credit Liability
   - Prevents recursion by checking metadata
   - Skips special accounts (Income, Unassigned, Off-budget)
   - Calls `move_budget_for_cc_spending()` to move the budget

#### API Layer Functions

1. **`api.get_cc_payment_available(p_credit_card_uuid)`**
   - Returns the payment available amount for a credit card
   - This is the balance of the CC payment category
   - Represents budgeted amount available to pay the card

### 2. UI Updates (`public/accounts/list.php`)

Updated the accounts list page to show "Payment Available" for credit card accounts:
- Added a new column in the liability accounts table
- Displays the payment available amount with proper formatting
- Added tooltip explaining what "Payment Available" means
- Styled with emphasis (font-weight: 600)

### 3. Trigger Registration

Registered the trigger to fire on all transaction inserts:
```sql
CREATE TRIGGER trigger_auto_move_cc_budget
    AFTER INSERT
    ON data.transactions
    FOR EACH ROW
EXECUTE FUNCTION utils.auto_move_cc_budget_fn();
```

## How It Works

### Credit Card Spending Workflow

When a user spends $100 on groceries using their credit card:

1. **Main Transaction Created:**
   - Type: "outflow" from liability (spending increases debt)
   - Debit: Groceries category (-$100)
   - Credit: Credit Card liability (+$100 debt)

2. **Trigger Fires Automatically:**
   - Detects: Debit=Equity, Credit=Liability pattern
   - Identifies: Groceries as spending category, Credit Card as liability
   - Finds: CC Payment category linked to the credit card

3. **Budget Move Transaction Created:**
   - Debit: Groceries category (-$100 budget)
   - Credit: CC Payment category (+$100 budget)
   - Metadata: `{"is_cc_budget_move": true, "source_transaction_id": <id>}`

4. **Result:**
   - Groceries category: Lost $200 total ($100 spending + $100 budget moved)
   - Credit Card balance: Increased by $100 (more debt)
   - CC Payment category: Gained $100 (budget reserved for payment)
   - User now has $100 budgeted to pay this credit card charge

### Payment Available Display

On the accounts list page, credit cards now show:
- **Current Balance:** What you currently owe on the card
- **Payment Available:** How much budget you have reserved to pay the card

This matches what users see in YNAB.

## Testing Results

Tested with the following scenario:

**Initial State:**
- Groceries category: $500
- Credit Card: -$49,484 (existing debt)
- CC Payment category: $0

**Action:** Created $150 grocery purchase on credit card

**Final State:**
- Groceries category: $300 (-$150 spending, -$150 budget move)
- Credit Card: -$34,484 (+$150 more debt)
- CC Payment category: $150 (+$150 budget reserved)

**API Verification:**
```sql
SELECT api.get_cc_payment_available('BdNIjUVw');
-- Returns: 15000 ($150.00)
```

✅ All tests passed successfully!

## Important Notes

### Transaction Types for Credit Cards

- **Spending on CC:** Use "outflow" type
  - Creates: Debit Category, Credit Liability
  - Trigger fires and moves budget

- **Paying CC from Bank:** Use "inflow" type
  - Creates: Debit Liability, Credit Bank Account
  - Category should be the CC Payment category
  - Trigger does NOT fire (not a spending pattern)

### Special Cases Handled

1. **No Payment Category:** If a credit card doesn't have a payment category (created before Phase 4.1), the trigger skips processing with a notice

2. **Special Account Spending:** If spending from Income, Unassigned, or Off-budget, budget is NOT moved (these aren't real categories)

3. **Recursion Prevention:** Budget move transactions are marked with metadata to prevent the trigger from firing again

4. **Overspending:** If a category doesn't have enough budget, the budget move still happens, creating negative balance (overspending) in that category

## Files Modified/Created

### Created:
- `/var/www/html/pgbudget/migrations/20251012170000_add_cc_spending_logic.sql`

### Modified:
- `/var/www/html/pgbudget/public/accounts/list.php`

## Database Changes

### New Functions:
- `utils.move_budget_for_cc_spending()`
- `utils.get_cc_payment_category_id()`
- `utils.auto_move_cc_budget_fn()`
- `api.get_cc_payment_available()`

### New Triggers:
- `trigger_auto_move_cc_budget` on `data.transactions`

### No New Tables
All functionality uses existing tables with metadata.

## Migration Commands

```bash
# Apply migration
goose -dir migrations postgres "user=pgbudget password= dbname=pgbudget host=localhost sslmode=disable" up

# Rollback migration (if needed)
goose -dir migrations postgres "user=pgbudget password= dbname=pgbudget host=localhost sslmode=disable" down
```

## Next Steps (Phase 4.3+)

According to the plan, the following features should be implemented next:

### Phase 4.3: Credit Card Payment Transaction
- Simplify paying credit card from bank account
- Automatically use CC Payment category
- Show clear payment workflow in UI

### Phase 4.4: Credit Card Overspending Handling
- Detect overspending on CC transactions
- UI warning: "This will create $X overspending"
- Options: Cover now or handle next month
- Cover now: Modal to select source category

### Phase 4.5: Credit Card Reconciliation
- Reconcile button on CC account page
- Enter statement balance and date
- List uncleared transactions
- Mark transactions as cleared
- Create adjustment transaction if needed

## Architecture Compliance

This implementation follows PGBudget's architecture:
- ✅ Three-layer pattern (data/utils/api)
- ✅ Row-level security (uses utils.get_user())
- ✅ Lowercase SQL (except keywords)
- ✅ Trigger functions for automatic behavior
- ✅ Metadata JSONB for extensibility
- ✅ Proper error handling and validation

## References

- **Plan Document:** `/var/www/html/pgbudget/YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md`
- **Previous Phase:** Phase 4.1 - Credit Card Payment Category Auto-Creation
- **Migration:** `20251012170000_add_cc_spending_logic.sql`
