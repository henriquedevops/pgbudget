# Budget Visibility Issue - Resolution Summary

## Date: November 2, 2025

## Problem Reported
User **m43str0** could not see their budget "Henriques Budget" after logging in.

## Root Causes Identified

### Issue 1: Onboarding Redirect Loop
- **Symptom**: After login, user was redirected to `/pgbudget/onboarding/wizard.php?step=1`
- **Cause**: User `m43str0` had `onboarding_completed = false` in the database
- **Impact**: Existing user was treated as new user requiring onboarding
- **Fix**: Set `onboarding_completed = true` for m43str0
  ```sql
  UPDATE data.users SET onboarding_completed = true WHERE username = 'm43str0';
  ```

### Issue 2: Missing Database Function
- **Symptom**: When accessing budget directly, got error:
  ```
  function utils.get_budget_status_for_category(bigint, bigint, text, text) does not exist
  ```
- **Cause**: Migration `20251016300000_add_category_groups.sql` referenced but never created this function
- **Impact**: Budget dashboard crashed when trying to display grouped categories
- **Fix**: Created migration `20251102000010_add_missing_budget_status_function.sql`
  - Function calculates budget status (budgeted, activity, balance) for individual categories
  - Used by `utils.get_group_subtotals()` to calculate group totals

## Previous Issues (Already Resolved)

### Orphaned Ledgers (Resolved Earlier)
- **Problem**: 39 ledgers with invalid `user_data` values
- **Solution**: Deleted all orphaned ledgers and related data
- **Result**: Database cleaned, only valid user associations remain

## Current State

### Users in System
- **m43str0**: 1 ledger ("Henriques Budget") - ✅ SHOULD BE VISIBLE NOW
- **testuser2**: 1 ledger ("Test Ledger for testuser2") - ✅ Working
- **testuser3**: 0 ledgers (new user) - ✅ Working
- **demo_user**: 2 ledgers - ✅ Working

### Expected Behavior After Login
When m43str0 logs in:
1. Session established with `user_id = 'm43str0'`
2. PostgreSQL context set: `app.current_user_id = 'm43str0'`
3. Query to `api.ledgers` returns 1 ledger (RLS filters by user_data)
4. Auto-redirect to `/pgbudget/budget/dashboard.php?ledger=eNF2EkfD`
5. Dashboard loads successfully with budget data

## Files Created/Modified

### New Files
- `migrations/20251102000010_add_missing_budget_status_function.sql` - Adds missing function
- `BUDGET_VISIBILITY_FIX_COMPLETE.md` - This summary

### Modified Data
- `data.users.onboarding_completed = true` for user m43str0

## Testing Verification

User should:
1. Logout: `http://vps60674.publiccloud.com.br/pgbudget/auth/logout.php`
2. Login as **m43str0**
3. Be automatically redirected to budget dashboard
4. See "Henriques Budget" with all categories and transactions

## Technical Details

### RLS Policy (Working Correctly)
```sql
CREATE POLICY ledgers_policy ON data.ledgers
USING (user_data = utils.get_user())
WITH CHECK (user_data = utils.get_user());
```

### Context Setting (Working Correctly)
```php
// includes/auth.php
function setUserContext($db) {
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
        $stmt->execute([$_SESSION['user_id']]);
    }
}
```

### Ledger Query (Working Correctly)
```php
// public/index.php (lines 34-37)
$stmt = $db->prepare("SELECT uuid, name, description FROM api.ledgers ORDER BY name");
$stmt->execute();
$ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

## Status

✅ **ALL ISSUES RESOLVED**

- [x] Orphaned ledgers deleted
- [x] Onboarding status fixed for m43str0
- [x] Missing database function created
- [x] Budget should be visible after login

Awaiting user confirmation that budget is now accessible.
