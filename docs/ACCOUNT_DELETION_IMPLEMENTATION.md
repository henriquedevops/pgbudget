# Account Deletion Implementation

**Date:** 2025-11-01
**Type:** Soft Delete
**Status:** ✅ Implemented

---

## Overview

Implemented safe account deletion functionality with soft delete pattern to preserve historical data while hiding deleted accounts from normal views.

---

## Features

### 1. **Soft Delete Pattern**
- Accounts are not permanently removed
- `deleted_at` timestamp column marks deleted accounts
- Deleted accounts hidden from all views via `api.accounts` view
- Historical data preserved for audit purposes

### 2. **Safety Checks**
- Protected accounts (Income, Off-budget, Unassigned) cannot be deleted
- Pre-check warns about:
  - Number of transactions that will be affected
  - Non-zero account balances
  - Active credit card limits
  - Active loans
  - Active installment plans
  - Active category goals

### 3. **Cascading Effects**
When an account is deleted:
- ✅ All transactions involving the account are soft-deleted
- ✅ Credit card limits are deactivated (`is_active = false`)
- ✅ Category goals are deactivated
- ✅ Transaction descriptions marked with "DELETED:" prefix
- ❌ Loans and installment plans preserved (historical data)

---

## Database Changes

### Migration: `20251101000002_add_account_deletion.sql`

#### New Column
```sql
ALTER TABLE data.accounts
ADD COLUMN deleted_at timestamp with time zone DEFAULT NULL;
```

#### New Functions

**`utils.can_delete_account(p_account_id bigint) RETURNS jsonb`**
- Checks if an account can be safely deleted
- Returns: `{can_delete, reason, warnings[]}`
- Validates:
  - Account exists and not already deleted
  - Not a special system account
  - Counts related transactions, goals, limits, etc.

**`api.delete_account(p_account_uuid text) RETURNS jsonb`**
- Performs soft deletion of account and related data
- Returns: `{success, message, deleted_transactions, warnings[]}`
- Atomic transaction ensures data consistency

#### Updated View
```sql
CREATE OR REPLACE VIEW api.accounts AS
-- ... existing columns ...
WHERE a.user_data = utils.get_user()
AND a.deleted_at IS NULL;  -- Hide deleted accounts
```

---

## API Endpoint

**File:** `public/api/delete-account.php`
**Method:** `POST`
**Content-Type:** `application/json`

### Request Formats

#### Pre-check (before deletion)
```json
{
  "account_uuid": "abc123",
  "precheck": true
}
```

#### Response (pre-check)
```json
{
  "success": true,
  "account_name": "Chase Credit Card",
  "account_type": "liability",
  "can_delete": true,
  "reason": null,
  "warnings": [
    "This account has 45 transaction(s). They will be soft-deleted along with the account.",
    "This account has a non-zero balance: -1250.00",
    "This account has active credit card limits that will be deactivated."
  ]
}
```

#### Actual Deletion
```json
{
  "account_uuid": "abc123"
}
```

#### Response (deletion)
```json
{
  "success": true,
  "message": "Account \"Chase Credit Card\" deleted successfully",
  "deleted_transactions": 45,
  "warnings": [...]
}
```

#### Error Response
```json
{
  "success": false,
  "error": "This is a special system account that cannot be deleted"
}
```

---

## User Interface

### Account List Page (`public/accounts/list.php`)

#### Delete Button
- Added "🗑️ Delete" button for each account
- Hidden for special system accounts (Income, Off-budget, Unassigned)
- Button attributes:
  ```html
  <button class="btn btn-small btn-danger delete-account-btn"
          data-account-uuid="..."
          data-account-name="..."
          data-account-type="...">
      🗑️ Delete
  </button>
  ```

#### Confirmation Modal
1. **Loading State**: "Checking account deletion impact..."
2. **Warning Display**: Shows all warnings from pre-check
3. **Confirmation**: User must confirm understanding of impact
4. **Processing**: "Deleting..." with disabled button
5. **Result**: Success message or error alert

---

## Protected Accounts

Cannot be deleted:
- `Income` (equity)
- `Off-budget` (equity)
- `Unassigned` (equity)

These are system accounts required for budget functionality.

---

## Use Cases

### 1. Delete Empty Account (Clean)
```
Account: "Old Savings"
Transactions: 0
Balance: $0.00
Result: Deleted immediately with no warnings
```

### 2. Delete Account with Transactions
```
Account: "Closed Credit Card"
Transactions: 127
Balance: $0.00
Warnings:
- "This account has 127 transaction(s). They will be soft-deleted..."
Result: Account and all 127 transactions marked deleted
```

### 3. Delete Account with Balance
```
Account: "Cash Wallet"
Transactions: 25
Balance: $150.00
Warnings:
- "This account has 25 transaction(s)..."
- "This account has a non-zero balance: $150.00"
Result: User warned but can proceed
```

### 4. Try to Delete Special Account
```
Account: "Income"
Result: Error - "This is a special system account that cannot be deleted"
```

---

## Technical Details

### Soft Delete vs Hard Delete

**Why Soft Delete?**
- Preserves audit trail
- Allows potential recovery
- Maintains referential integrity
- Historical reports remain accurate
- Compliance with financial regulations

**How It Works:**
1. Set `deleted_at = CURRENT_TIMESTAMP`
2. Updated view filters `WHERE deleted_at IS NULL`
3. Deleted accounts invisible to users
4. Data remains in database for admin/audit access

### Transaction Marking
```sql
UPDATE data.transactions
SET deleted_at = CURRENT_TIMESTAMP,
    description = 'DELETED: ' || description
WHERE (credit_account_id = ... OR debit_account_id = ...)
```

Prefix helps identify deleted transactions if ever needed for recovery.

---

## Files Modified

### Database
- `migrations/20251101000002_add_account_deletion.sql` ✅ Created

### Backend
- `public/api/delete-account.php` ✅ Created
- `public/accounts/list.php` ✅ Modified (UI + JS)

### Frontend
- Delete button added to account actions
- Confirmation modal with pre-check
- JavaScript handlers for AJAX calls

---

## Testing Checklist

### ✅ Deletion Flow
- [x] Delete button appears for normal accounts
- [x] Delete button hidden for special accounts
- [x] Pre-check runs and shows warnings
- [x] Confirmation modal displays properly
- [x] Account deleted successfully
- [x] Page refreshes showing updated list

### ✅ Edge Cases
- [x] Cannot delete special accounts (Income, etc.)
- [x] Warns about transactions
- [x] Warns about non-zero balance
- [x] Warns about credit card limits
- [x] Handles network errors gracefully

### ✅ Data Integrity
- [x] Account marked with deleted_at
- [x] Transactions marked with deleted_at
- [x] Credit card limits deactivated
- [x] Category goals deactivated
- [x] Loans preserved (not deleted)
- [x] api.accounts view excludes deleted

---

## Security

### Authorization
- ✅ `requireAuth()` check in API endpoint
- ✅ User context set via `utils.get_user()`
- ✅ RLS policies apply (user can only delete own accounts)

### Validation
- ✅ Account UUID required
- ✅ Account must exist and belong to user
- ✅ Account must not already be deleted
- ✅ Special accounts protected

### Atomicity
- ✅ All operations in transaction (implicit in function)
- ✅ Rollback on error
- ✅ No partial deletes possible

---

## Future Enhancements

### Potential Additions
- [ ] Admin interface to view/restore deleted accounts
- [ ] "Undo delete" within X minutes
- [ ] Archive feature separate from delete
- [ ] Bulk account deletion
- [ ] Export account data before delete
- [ ] Scheduled purge of old deleted accounts (after X years)

### Recovery Process
Currently requires manual database update:
```sql
UPDATE data.accounts
SET deleted_at = NULL, updated_at = CURRENT_TIMESTAMP
WHERE uuid = 'account-uuid-here';

UPDATE data.transactions
SET deleted_at = NULL,
    updated_at = CURRENT_TIMESTAMP,
    description = REPLACE(description, 'DELETED: ', '')
WHERE credit_account_id = (SELECT id FROM data.accounts WHERE uuid = '...')
   OR debit_account_id = (SELECT id FROM data.accounts WHERE uuid = '...');
```

---

## Summary

**Implementation:** Complete and tested
**Pattern:** Soft delete with cascading effects
**Safety:** Multiple validation layers
**User Experience:** Clear warnings and confirmation
**Data Integrity:** Preserved for audit/recovery

The account deletion feature provides safe, reversible account removal while maintaining data integrity and audit trails.
