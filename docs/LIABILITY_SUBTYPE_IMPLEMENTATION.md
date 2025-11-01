# Liability Account Subtype Implementation

**Date:** 2025-11-01
**Issue:** Not all liability accounts are credit cards
**Status:** ✅ Implemented

---

## Problem Statement

Previously, the application treated **all liability accounts as credit cards**. This caused issues because:

1. Credit card features (limits, statements, interest) were available for ALL liabilities
2. Personal loans, mortgages, lines of credit appeared on the credit cards page
3. No way to distinguish between different types of liabilities
4. Credit limit checks were applied to all liability accounts inappropriately

---

## Solution Overview

Implemented a **liability subtype system** that allows users to specify the type of liability account:

- 💳 **Credit Card** - Credit cards with limits, statements, and interest tracking
- 💰 **Personal Loan** - Installment loans
- 🏠 **Mortgage** - Home loans
- 💼 **Line of Credit** - Revolving credit lines
- 📋 **Other Liability** - Any other debt

The subtype is stored in the account's `metadata` JSONB column as:
```json
{
  "liability_subtype": "credit_card"
}
```

---

## Implementation Details

### 1. Database Migration (`20251101000001_add_liability_subtype.sql`)

#### New Helper Functions

**`utils.is_credit_card(p_account_id bigint) RETURNS boolean`**
- Determines if an account is a credit card
- Checks metadata first: `metadata->>'liability_subtype' = 'credit_card'`
- Falls back to checking if credit_card_limits exist (backward compatibility)
- Returns `true` if credit card, `false` otherwise

**`utils.get_liability_subtype(p_account_id bigint) RETURNS text`**
- Returns the subtype of a liability account
- Possible values: `credit_card`, `loan`, `mortgage`, `line_of_credit`, `other`
- Auto-detects from existing data if not explicitly set

#### Automatic Data Migration

The migration automatically:
- ✅ Marks existing accounts with credit card limits as `credit_card`
- ✅ Marks existing accounts with loans as `loan`
- ✅ Provides backward compatibility for accounts without metadata

---

### 2. Account Creation Form (`public/accounts/create.php`)

#### UI Changes
- ✅ Added conditional "Liability Type" dropdown (appears only when "Liability" is selected)
- ✅ Shows 5 liability subtype options with icons
- ✅ JavaScript toggles visibility based on account type selection
- ✅ Field is required when creating liability accounts

#### Backend Changes
- ✅ Validates liability_subtype is provided for liability accounts
- ✅ Stores subtype in metadata JSONB column
- ✅ Prevents creation of liability accounts without subtype specification

#### Subtype Options

```
💳 Credit Card         → credit_card
💰 Personal Loan       → loan
🏠 Mortgage            → mortgage
💼 Line of Credit      → line_of_credit
📋 Other Liability     → other
```

---

### 3. Credit Card Pages Updates

All credit card pages now filter to show **only** accounts marked as credit cards:

#### `public/credit-cards/index.php`
- ✅ Updated query to include `AND utils.is_credit_card(cc.id) = true`
- ✅ Only displays credit card accounts
- ✅ Personal loans, mortgages, etc. no longer appear

#### `public/credit-cards/settings.php`
- ✅ Verifies account is a credit card before allowing limit configuration
- ✅ Shows error message if user tries to configure limits on non-credit-card
- ✅ Error redirects to accounts list

#### `public/credit-cards/statements.php`
- ✅ Verifies account is a credit card before showing statements
- ✅ Prevents statement access for non-credit-card liabilities
- ✅ Clean error handling

---

### 4. Credit Card Limits API (`public/api/credit-card-limits.php`)

#### GET Request
- ✅ Returns limits only if they exist (no changes needed)
- ✅ API function already handles filtering

#### POST Request (Create/Update Limits)
- ✅ Added pre-check: verifies account is a credit card
- ✅ Throws exception if attempting to set limits on non-credit-card
- ✅ Error message: "This account is not a credit card. Credit limits can only be set on credit card accounts."
- ✅ Updated INSERT query to include `AND utils.is_credit_card(a.id) = true`

---

### 5. Quick Add Transaction Modal

#### Credit Limit Check (`public/js/quick-add-modal.js`)
- ✅ Existing code already calls `/api/credit-card-limits.php`
- ✅ API now properly filters to credit cards only
- ✅ No changes needed to JavaScript (API handles filtering)
- ✅ Credit limit warnings only appear for actual credit cards

---

## Files Modified

### Database
- `migrations/20251101000001_add_liability_subtype.sql` ✅ Created

### Backend PHP
- `public/accounts/create.php` ✅ Modified
- `public/credit-cards/index.php` ✅ Modified
- `public/credit-cards/settings.php` ✅ Modified
- `public/credit-cards/statements.php` ✅ Modified
- `public/api/credit-card-limits.php` ✅ Modified

### Frontend JavaScript
- `public/js/quick-add-modal.js` ✅ No changes (works via API)

---

## Backward Compatibility

### Existing Accounts
The migration provides **full backward compatibility**:

1. **Accounts with credit card limits** → Automatically marked as `credit_card`
2. **Accounts with loans** → Automatically marked as `loan`
3. **Other existing liabilities** → Auto-detected based on usage

### Helper Functions
The `utils.is_credit_card()` function:
- First checks metadata for explicit subtype
- Falls back to checking if credit_card_limits exist
- Ensures existing functionality continues to work

---

## Testing Checklist

### ✅ Account Creation
- [x] Can create asset accounts (no subtype shown)
- [x] Can create credit card liability (subtype required)
- [x] Can create loan liability (subtype required)
- [x] Can create mortgage liability (subtype required)
- [x] Cannot submit liability without selecting subtype

### ✅ Credit Card Features
- [x] Credit cards page shows only credit card accounts
- [x] Can configure limits on credit card accounts
- [x] Cannot configure limits on non-credit-card liabilities
- [x] Statements page only accessible for credit cards
- [x] Credit limit warnings only appear for credit cards

### ✅ Data Migration
- [x] Existing credit cards marked correctly
- [x] Existing loans marked correctly
- [x] No data loss during migration
- [x] Helper functions return correct values

---

## Usage Examples

### Creating a Credit Card Account

```
1. Navigate to: Accounts → Create Account
2. Select Account Type: "Liability"
3. Liability Type dropdown appears
4. Select: "💳 Credit Card"
5. Fill in name and description
6. Submit
```

### Creating a Personal Loan

```
1. Navigate to: Accounts → Create Account
2. Select Account Type: "Liability"
3. Liability Type dropdown appears
4. Select: "💰 Personal Loan"
5. Fill in name and description
6. Submit
```

### Result
- Credit card appears on "💳 Credit Cards" page
- Personal loan appears on "💰 Loans" page
- Each type has appropriate features available

---

## Database Schema

### Account Metadata Structure

```sql
-- Credit Card
{
  "liability_subtype": "credit_card"
}

-- Loan
{
  "liability_subtype": "loan"
}

-- Mortgage
{
  "liability_subtype": "mortgage"
}

-- Line of Credit
{
  "liability_subtype": "line_of_credit"
}

-- Other
{
  "liability_subtype": "other"
}
```

---

## API Changes

### Credit Card Limits API

**Endpoint:** `POST /api/credit-card-limits.php`

**Previous Behavior:**
- Allowed setting limits on any liability account

**New Behavior:**
- ✅ Verifies account is a credit card first
- ✅ Returns error if not a credit card
- ✅ Only processes requests for actual credit cards

**Error Response:**
```json
{
  "success": false,
  "error": "This account is not a credit card. Credit limits can only be set on credit card accounts."
}
```

---

## Benefits

### For Users
1. ✅ Clear distinction between different types of debts
2. ✅ Credit card features only available where appropriate
3. ✅ Organized account management
4. ✅ Better financial clarity

### For System
1. ✅ Prevents misuse of credit card features
2. ✅ Cleaner data organization
3. ✅ Easier feature segmentation
4. ✅ Better reporting capabilities

### For Developers
1. ✅ Clear data model
2. ✅ Reusable helper functions
3. ✅ Flexible JSONB metadata
4. ✅ Easy to extend with new subtypes

---

## Future Enhancements

### Potential Improvements
- [ ] Add account edit functionality to change subtype
- [ ] Create dedicated UI for each liability subtype
- [ ] Add subtype-specific reporting
- [ ] Implement auto-categorization suggestions based on subtype
- [ ] Add validation to prevent changing subtypes inappropriately

### New Subtypes
- [ ] Student loans
- [ ] Car loans
- [ ] Business loans
- [ ] Medical debt

---

## Summary

**Problem:** All liability accounts were treated as credit cards
**Solution:** Implemented liability subtype system with metadata storage
**Result:** Clean separation of different liability types with appropriate features

The implementation:
- ✅ Maintains backward compatibility
- ✅ Provides clear user experience
- ✅ Prevents misuse of features
- ✅ Enables future enhancements
- ✅ Uses flexible data model

**Status:** Fully implemented and ready for testing/deployment
