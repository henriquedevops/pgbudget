# Loan Payments in Add Transaction - Implementation Plan

**Created:** 2025-11-05
**Status:** Planning
**Related Files:**
- `public/transactions/add.php`
- `public/api/quick-add-transaction.php`
- `migrations/20251018300000_add_loan_tables.sql`
- `migrations/20251018400000_add_loan_api_functions.sql`

---

## Overview

This document outlines the plan for integrating loan payment tracking into the existing "Add Transaction" feature. Users will be able to mark transactions as loan payments, which will automatically update the loan payment schedule and track principal/interest splits.

---

## Current System Architecture

### Existing Infrastructure

**Database Tables:**
- `data.loans` - Tracks loan metadata (principal, balance, interest, terms)
  - Located: `migrations/20251018300000_add_loan_tables.sql:16-113`
- `data.loan_payments` - Stores payment schedule with `transaction_id` foreign key
  - Located: `migrations/20251018300000_add_loan_tables.sql:144-217`

**API Functions:**
- `api.record_loan_payment()` - Updates payment records but **doesn't create transactions**
  - Located: `migrations/20251018400000_add_loan_api_functions.sql:635-726`
  - Note at line 718: "Transaction creation would be handled by separate transaction API"
- `api.add_transaction()` - Creates transactions separately
- `api.get_loan_payments()` - Fetches payment schedule
  - Located: `migrations/20251018400000_add_loan_api_functions.sql:431-454`

**UI Features:**
- Add transaction form: `public/transactions/add.php:1-1609`
- Quick-add API: `public/api/quick-add-transaction.php:1-213`
- Current support for:
  - Regular transactions (lines 88-109)
  - Split transactions (lines 38-86)
  - Installment plans for credit cards (lines 270-339)

**Recent Enhancements:**
- Commit `6bc5f78`: Added `initial_payments_made` field to track payments made before tracking began
- Loans can now be created with partial payment history

---

## Implementation Plan

### Phase 1: Database Updates

**Goal:** Ensure schema supports transaction-to-payment linkage

#### Tasks:

1. **Verify existing schema**
   - ✅ `loan_payments.transaction_id` already exists (line 154 in add_loan_tables.sql)
   - ✅ Foreign key constraint to `data.transactions` with `ON DELETE SET NULL`
   - ✅ Index exists: `idx_loan_payments_transaction_id`

2. **Optional: Add helper view** (if needed for performance)
   ```sql
   CREATE VIEW api.unpaid_loan_payments AS
   SELECT
       lp.*,
       l.lender_name,
       l.loan_type
   FROM api.loan_payments lp
   JOIN api.loans l ON l.uuid = lp.loan_uuid
   WHERE lp.status IN ('scheduled', 'late', 'missed')
   ORDER BY lp.due_date;
   ```

**Estimated Time:** 1-2 hours

---

### Phase 2: Backend API Enhancement

**Goal:** Enable transaction creation to update loan payments

#### Option A: Extend `api.add_transaction()` (Recommended)

**Create new database migration:**
```sql
-- migrations/YYYYMMDD_add_loan_payment_to_add_transaction.sql

-- Modify api.add_transaction to accept loan_payment_uuid
-- After transaction creation, update loan payment record
```

**Logic:**
1. Add optional parameter: `p_loan_payment_uuid text DEFAULT NULL`
2. Create transaction normally
3. If `p_loan_payment_uuid` provided:
   - Validate payment exists and is unpaid
   - Calculate principal/interest split using loan's interest rate
   - Update `loan_payments` record:
     - `transaction_id` = new transaction UUID
     - `paid_date` = transaction date
     - `actual_amount_paid` = transaction amount
     - `actual_principal` = calculated value
     - `actual_interest` = calculated value
     - `from_account_id` = transaction account
     - `status` = 'paid'
   - Trigger automatically updates loan balance via `update_loan_balance_after_payment()`

#### Option B: Create wrapper function

**Alternative approach if modifying core function is risky:**
```sql
CREATE FUNCTION api.add_transaction_with_loan_payment(
    -- all transaction params
    p_loan_payment_uuid text DEFAULT NULL
) RETURNS SETOF api.transactions
```

**Pseudo-code:**
```sql
BEGIN
    -- 1. Call api.add_transaction()
    v_transaction_uuid := api.add_transaction(...);

    -- 2. If loan payment specified, link it
    IF p_loan_payment_uuid IS NOT NULL THEN
        PERFORM link_transaction_to_loan_payment(
            v_transaction_uuid,
            p_loan_payment_uuid
        );
    END IF;

    RETURN transaction;
END;
```

#### New API Endpoint

**File:** `public/api/loan-payments-unpaid.php`

**Purpose:** Fetch unpaid payments for UI dropdown

**Endpoint:** `GET /api/loan-payments-unpaid.php?loan_uuid=X`

**Response:**
```json
{
  "success": true,
  "payments": [
    {
      "uuid": "abc123",
      "payment_number": 12,
      "due_date": "2025-02-01",
      "scheduled_amount": 450.00,
      "scheduled_principal": 380.50,
      "scheduled_interest": 69.50,
      "status": "scheduled",
      "days_until_due": 5
    }
  ]
}
```

**Estimated Time:** 4-6 hours

---

### Phase 3: Frontend UI Updates

**Goal:** Add loan payment option to transaction form

#### File: `public/transactions/add.php`

**Changes Required:**

1. **Add Loan Payment Section** (after line 268, before split section)

```html
<!-- Loan Payment Section (only for outflows) -->
<div id="loan-payment-section" class="loan-payment-section" style="display: none;">
    <div class="loan-payment-header">
        <label class="loan-payment-toggle">
            <input type="checkbox" id="enable-loan-payment" name="enable_loan_payment" value="1">
            <span class="loan-payment-toggle-label">🏦 This is a loan payment</span>
        </label>
        <small class="form-help">Track this payment against a loan schedule</small>
    </div>

    <div id="loan-payment-config" class="loan-payment-config" style="display: none;">
        <div class="form-group">
            <label for="loan_uuid" class="form-label">Select Loan *</label>
            <select id="loan_uuid" name="loan_uuid" class="form-select">
                <option value="">Choose loan...</option>
                <!-- Populated via AJAX -->
            </select>
        </div>

        <div id="payment-selection-group" class="form-group" style="display: none;">
            <label for="loan_payment_uuid" class="form-label">Select Payment *</label>
            <select id="loan_payment_uuid" name="loan_payment_uuid" class="form-select">
                <option value="">Choose scheduled payment...</option>
                <!-- Populated via AJAX when loan selected -->
            </select>
            <small class="form-help">Shows unpaid scheduled payments</small>
        </div>

        <div id="payment-details" class="payment-details" style="display: none;">
            <h4>Payment Details</h4>
            <div class="detail-row">
                <span>Scheduled Amount:</span>
                <span id="detail-scheduled-amount">$0.00</span>
            </div>
            <div class="detail-row">
                <span>Principal:</span>
                <span id="detail-principal">$0.00</span>
            </div>
            <div class="detail-row">
                <span>Interest:</span>
                <span id="detail-interest">$0.00</span>
            </div>
            <div class="detail-row">
                <span>Due Date:</span>
                <span id="detail-due-date">-</span>
            </div>
        </div>

        <div id="amount-warning" class="warning-message" style="display: none;">
            ⚠️ Transaction amount differs from scheduled payment amount
        </div>
    </div>
</div>
```

2. **Update JavaScript** (add after line 1344)

```javascript
// Loan Payment Management
function initializeLoanPayment() {
    const enableLoanPaymentCheckbox = document.getElementById('enable-loan-payment');
    const loanPaymentConfig = document.getElementById('loan-payment-config');
    const loanSelect = document.getElementById('loan_uuid');
    const paymentSelect = document.getElementById('loan_payment_uuid');
    const amountInput = document.getElementById('amount');

    // Toggle loan payment config
    enableLoanPaymentCheckbox.addEventListener('change', function() {
        if (this.checked) {
            loanPaymentConfig.style.display = 'block';
            loadLoans();

            // Disable split and installment
            disableOtherFeatures();
        } else {
            loanPaymentConfig.style.display = 'none';
            resetLoanPaymentForm();
        }
    });

    // Load payments when loan selected
    loanSelect.addEventListener('change', function() {
        if (this.value) {
            loadUnpaidPayments(this.value);
        }
    });

    // Auto-populate fields when payment selected
    paymentSelect.addEventListener('change', function() {
        if (this.value) {
            autoPopulateFromPayment(this.value);
        }
    });

    // Warn if amount differs from scheduled
    amountInput.addEventListener('input', function() {
        checkAmountMatch();
    });
}

function loadLoans() {
    const ledgerUuid = '<?= $ledger_uuid ?>';
    fetch(`/pgbudget/public/api/loans.php?ledger_uuid=${ledgerUuid}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateLoanDropdown(data.loans);
            }
        })
        .catch(error => console.error('Error loading loans:', error));
}

function loadUnpaidPayments(loanUuid) {
    fetch(`/pgbudget/public/api/loan-payments-unpaid.php?loan_uuid=${loanUuid}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populatePaymentDropdown(data.payments);
                document.getElementById('payment-selection-group').style.display = 'block';
            }
        })
        .catch(error => console.error('Error loading payments:', error));
}

function autoPopulateFromPayment(paymentUuid) {
    // Fetch payment details and populate form
    // Set amount, date, description
    // Show payment details panel
}

function checkAmountMatch() {
    // Compare current amount with scheduled amount
    // Show warning if different
}
```

3. **Update visibility logic** (modify `updateInstallmentVisibility()`)

```javascript
function updateLoanPaymentVisibility() {
    const type = document.getElementById('type').value;
    const loanPaymentSection = document.getElementById('loan-payment-section');

    // Only show for outflows
    if (type === 'outflow') {
        loanPaymentSection.style.display = 'block';
    } else {
        loanPaymentSection.style.display = 'none';
        document.getElementById('enable-loan-payment').checked = false;
        document.getElementById('loan-payment-config').style.display = 'none';
    }
}
```

4. **Add mutual exclusivity** (modify around line 1109)

```javascript
// Disable loan payment if split enabled
const enableLoanPayment = document.getElementById('enable-loan-payment');
if (enableLoanPayment.checked) {
    enableLoanPayment.checked = false;
    document.getElementById('loan-payment-config').style.display = 'none';
}
```

5. **Add CSS styling** (after line 826)

```css
/* Loan Payment Styles */
.loan-payment-section {
    background: #f0f9ff;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #bfdbfe;
    margin-top: 1rem;
}

.loan-payment-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    color: #2d3748;
}

.loan-payment-config {
    background: white;
    padding: 1.5rem;
    border-radius: 6px;
    margin-top: 1rem;
    border: 1px solid #bfdbfe;
}

.payment-details {
    background: #f7fafc;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    margin-top: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    color: #4a5568;
}

.warning-message {
    background: #fef3c7;
    color: #92400e;
    padding: 0.75rem;
    border-radius: 6px;
    border-left: 4px solid #f59e0b;
    margin-top: 1rem;
}
```

**Estimated Time:** 6-8 hours

---

### Phase 4: Backend Transaction Handler

**Goal:** Process loan payment data when transaction is created

#### File: `public/api/quick-add-transaction.php`

**Changes (around line 82):**

```php
$ledger_uuid = sanitizeInput($data['ledger_uuid']);
$type = sanitizeInput($data['type']);
$amount = parseCurrency($data['amount']);
$date = sanitizeInput($data['date']);
$description = sanitizeInput($data['description']);
$account_uuid = sanitizeInput($data['account']);
$category_uuid = isset($data['category']) && !empty($data['category']) ? sanitizeInput($data['category']) : null;
$payee_name = isset($data['payee']) && !empty($data['payee']) ? sanitizeInput($data['payee']) : null;

// NEW: Loan payment support
$loan_payment_uuid = isset($data['loan_payment_uuid']) && !empty($data['loan_payment_uuid'])
    ? sanitizeInput($data['loan_payment_uuid'])
    : null;
```

**Update transaction creation (around line 133):**

```php
// Add transaction using API function
$stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $ledger_uuid,
    $date,
    $description,
    $type,
    $amount,
    $account_uuid,
    $category_uuid,
    $payee_name
]);

$result = $stmt->fetch();
$transaction_uuid = $result[0];

// NEW: Link to loan payment if specified
if ($loan_payment_uuid) {
    $stmt = $db->prepare("
        UPDATE data.loan_payments lp
        SET
            transaction_id = (SELECT id FROM data.transactions WHERE uuid = ?),
            paid_date = ?::date,
            actual_amount_paid = ?,
            from_account_id = (SELECT id FROM data.accounts WHERE uuid = ?),
            status = 'paid'
        FROM data.loans l
        WHERE lp.uuid = ?
          AND lp.loan_id = l.id
          AND lp.user_data = utils.get_user()
        RETURNING lp.uuid
    ");

    $stmt->execute([
        $transaction_uuid,
        $date,
        $amount,
        $account_uuid,
        $loan_payment_uuid
    ]);

    $payment_result = $stmt->fetch();
    if (!$payment_result) {
        throw new Exception('Failed to link transaction to loan payment');
    }

    // Recalculate principal/interest split
    $stmt = $db->prepare("
        UPDATE data.loan_payments lp
        SET
            actual_interest = ROUND(
                (SELECT current_balance * (interest_rate / 100 / 12)
                 FROM data.loans WHERE id = lp.loan_id),
                2
            ),
            actual_principal = actual_amount_paid - ROUND(
                (SELECT current_balance * (interest_rate / 100 / 12)
                 FROM data.loans WHERE id = lp.loan_id),
                2
            )
        WHERE uuid = ?
    ");
    $stmt->execute([$loan_payment_uuid]);
}
```

#### File: `public/transactions/add.php`

**POST handler updates (around line 26):**

```php
$payee_name = isset($_POST['payee']) ? sanitizeInput($_POST['payee']) : null;
$is_split = isset($_POST['is_split']) && $_POST['is_split'] === '1';

// NEW: Loan payment support
$loan_payment_uuid = isset($_POST['loan_payment_uuid']) && !empty($_POST['loan_payment_uuid'])
    ? sanitizeInput($_POST['loan_payment_uuid'])
    : null;
```

**Similar update logic as quick-add after line 101**

**Estimated Time:** 3-4 hours

---

### Phase 5: Display & Validation

**Goal:** Prevent errors and improve UX

#### Validation Rules

1. **Prevent duplicate payments**
   - Check if payment already has `transaction_id` before allowing selection
   - Show status badge in dropdown: "✓ Paid", "📅 Scheduled", "⚠️ Late"

2. **Account validation**
   - If loan has linked account, suggest/pre-select it
   - Show warning if user selects different account:
     > "⚠️ This loan is associated with [Account Name]. Are you sure you want to use a different account?"

3. **Amount validation**
   - Allow different amounts (user might pay more/less)
   - Show clear warning if amount differs from scheduled
   - Calculate days early/late: `paid_date - due_date`

4. **Date validation**
   - Allow past-dated payments
   - Show indicator: "5 days early" or "3 days late"

#### UI Enhancements

**Payment dropdown format:**
```
Payment #12 - Due Feb 1, 2025 ($450.00) [📅 Scheduled]
Payment #11 - Due Jan 1, 2025 ($450.00) [⚠️ 34 days late]
Payment #10 - Due Dec 1, 2024 ($450.00) [✓ Paid]
```

**Success message:**
```
✅ Transaction added and loan payment #12 marked as paid!
Remaining balance: $8,450.00 | Payments remaining: 18
```

**Estimated Time:** 2-3 hours

---

## Key Design Decisions

### 1. Transaction Creation Approach

**Chosen:** Option A - User creates transaction → automatically updates loan payment

**Rationale:**
- Fits existing workflow
- Transaction is source of truth
- Less UI complexity
- Users think "I'm paying a bill" not "I'm updating a schedule"

**Alternative (Option B):** User records payment via loan interface → creates transaction
- Requires separate UI
- More complex state management
- Better for loan-centric workflows

### 2. Principal/Interest Split

**Chosen:** Option B - Record as single transaction, store split in `loan_payments` table

**Rationale:**
- Simpler implementation
- Matches existing `record_loan_payment()` logic
- Split stored for reporting but doesn't complicate transaction
- Avoids forced split transactions

**Alternative (Option A):** Auto-split using amortization schedule
- Requires split transaction creation
- More accurate categorization (interest as expense, principal as transfer)
- Complexity: What if user already enabled split?

### 3. Category Assignment

**Chosen:** Use single category for loan payment, split stored separately

**Options:**
1. Auto-create "Loan Payment - [Lender]" category
2. Use existing "Debt Repayment" or "Loan Payments" category
3. Don't require category (special handling for loan payments)

**Recommendation:**
- Add "Loan Payments" category group to equity accounts on first use
- Store actual split in `loan_payments` table for interest tracking
- Future: Generate report showing interest paid per loan

### 4. Mutual Exclusivity

**Rules:**
- Loan payment **cannot** be combined with:
  - ✅ Split transactions (conflicts with single transaction model)
  - ✅ Installment plans (semantically different: future vs. past debt)
  - ✅ Transfers (loans are payments, not account transfers)

- Loan payment **can** be combined with:
  - ✅ Payee tracking
  - ✅ Custom descriptions
  - ✅ Future/past dates

---

## File Changes Summary

### Backend Changes

| File | Change Type | Lines Est. | Description |
|------|-------------|------------|-------------|
| `migrations/YYYYMMDD_add_loan_payment_to_transactions.sql` | New | ~100 | Update `api.add_transaction()` or create wrapper |
| `public/api/loan-payments-unpaid.php` | New | ~80 | Endpoint to fetch unpaid payments |
| `public/api/quick-add-transaction.php` | Modify | ~40 | Accept and process loan_payment_uuid |
| `public/transactions/add.php` (PHP) | Modify | ~30 | POST handler for loan payment data |

**Total Backend:** ~250 lines

### Frontend Changes

| File | Change Type | Lines Est. | Description |
|------|-------------|------------|-------------|
| `public/transactions/add.php` (HTML) | Modify | ~80 | Add loan payment section HTML |
| `public/transactions/add.php` (CSS) | Modify | ~60 | Styling for loan payment UI |
| `public/transactions/add.php` (JS) | Modify | ~200 | Loan selection, payment loading, auto-population |

**Total Frontend:** ~340 lines

**Grand Total:** ~590 lines across 5-6 files

---

## Example User Flow

### Scenario: User makes monthly car loan payment

1. User navigates to "Add Transaction"
2. Selects:
   - Type: **Expense (Money Out)**
   - Account: **Checking Account**
3. Checks: **"🏦 This is a loan payment"**
4. Loan payment section appears:
   - Dropdown shows: "Auto Loan - Honda Civic", "Mortgage - Main Street", "Student Loan - Federal"
5. Selects: **"Auto Loan - Honda Civic"**
6. Second dropdown loads:
   ```
   Payment #12 - Due Feb 1, 2025 ($450.00) [📅 Scheduled]
   Payment #11 - Due Jan 1, 2025 ($450.00) [⚠️ 34 days late]
   ```
7. Selects: **"Payment #12"**
8. Form auto-fills:
   - **Amount:** $450.00
   - **Description:** "Auto Loan Payment #12"
   - **Date:** 2025-02-01 (due date)
   - **Category:** "Loan Payments" (auto-created)
9. Payment details panel shows:
   ```
   Scheduled Amount: $450.00
   Principal: $380.50
   Interest: $69.50
   Due Date: Feb 1, 2025
   ```
10. User adjusts date to today (Jan 28, 2025)
11. Form shows: "✓ Paying 4 days early"
12. User clicks **"Add Transaction"**
13. Success message:
    ```
    ✅ Transaction added and loan payment #12 marked as paid!
    Auto Loan - Honda Civic
    Remaining balance: $8,450.00
    Payments remaining: 18
    Next payment due: Mar 1, 2025 ($450.00)
    ```

---

## Testing Checklist

### Unit Tests

- [ ] `api.add_transaction()` with `loan_payment_uuid` updates payment
- [ ] Principal/interest calculation is accurate
- [ ] Loan balance decreases correctly
- [ ] Transaction linkage is bidirectional
- [ ] RLS prevents cross-user payment access

### Integration Tests

- [ ] Form submission creates transaction + updates payment
- [ ] Quick-add modal works with loan payments
- [ ] Payment already paid returns error
- [ ] Invalid payment UUID returns error
- [ ] Amount mismatch shows warning but succeeds

### UI Tests

- [ ] Loan dropdown loads correctly
- [ ] Payment dropdown filters unpaid only
- [ ] Auto-population works for all fields
- [ ] Mutual exclusivity prevents conflicts
- [ ] Amount warning appears when different
- [ ] Success message shows updated balance
- [ ] Mobile responsive layout

### Edge Cases

- [ ] Loan with no scheduled payments
- [ ] Payment amount exceeds scheduled (overpayment)
- [ ] Payment amount less than scheduled (partial)
- [ ] Multiple loans with same lender
- [ ] Loan deleted after payment scheduled
- [ ] User tries to pay same payment twice
- [ ] Payment made from wrong account type

---

## Migration Path

### For Existing Users

1. **No breaking changes** - Existing transaction functionality unchanged
2. **Opt-in feature** - Only visible when checkbox enabled
3. **Backward compatible** - Old transactions unaffected

### For Existing Loans

1. Users with active loans see new option immediately
2. Historical payments remain as-is (no retroactive linking)
3. Future payments can use new workflow

---

## Future Enhancements

### Phase 6: Advanced Features (Post-MVP)

1. **Bulk payment recording**
   - "Mark multiple payments as paid"
   - Upload CSV of payment history

2. **Auto-split for interest tracking**
   - Create split transaction automatically
   - Principal → Transfer to liability account
   - Interest → Expense category

3. **Payment reminders**
   - Email/notification X days before due date
   - Integration with recurring transaction system

4. **Loan dashboard**
   - Amortization chart visualization
   - Interest paid YTD
   - Payoff projections

5. **Extra payment handling**
   - Apply extra to principal
   - Recalculate remaining schedule
   - Show payoff date impact

6. **Refinance support**
   - Close old loan
   - Create new loan with balance transfer
   - Link transaction history

---

## Questions & Considerations

### Open Questions

1. **Category creation:** Should we auto-create "Loan Payments" category or require user to create it?
   - **Recommendation:** Auto-create with group "Loan Management"

2. **Interest categorization:** Should interest be tracked separately as expense?
   - **Recommendation:** Phase 2 feature (requires split transaction)

3. **Account type validation:** Should we enforce payment from asset account only?
   - **Recommendation:** Warn but allow (user might pay from credit card)

4. **Overpayment handling:** What happens if user pays more than scheduled?
   - **Recommendation:** Record actual amount, show as overpayment, future feature to apply to next payment

5. **Payee linking:** Should payment auto-populate payee from `lender_name`?
   - **Recommendation:** Yes, pre-fill but allow override

### Performance Considerations

- Loan dropdown: Cache results for session
- Payment dropdown: Load on-demand (AJAX)
- Consider pagination for users with many loans (>20)

### Security Considerations

- Validate `loan_payment_uuid` belongs to user
- Verify loan belongs to specified ledger
- Check payment not already marked paid
- Rate limit API endpoints

---

## Success Metrics

### User Metrics
- % of loan users who use the feature
- Time to record payment (before vs. after)
- Error rate on payment recording

### Technical Metrics
- API response time for payment loading
- Database query performance
- Form submission success rate

### Business Metrics
- User satisfaction (survey/feedback)
- Support tickets related to loans
- Feature adoption rate

---

## References

- Existing loan implementation: `migrations/20251018*`
- Add transaction form: `public/transactions/add.php`
- Quick-add API: `public/api/quick-add-transaction.php`
- Loan API functions: `public/api/loan-payments.php`
- Related commit: `6bc5f78` - Initial payments tracking

---

## Implementation Timeline

| Phase | Description | Estimated Time | Priority |
|-------|-------------|----------------|----------|
| Phase 1 | Database verification | 1-2 hours | High |
| Phase 2 | Backend API | 4-6 hours | High |
| Phase 3 | Frontend UI | 6-8 hours | High |
| Phase 4 | Backend handlers | 3-4 hours | High |
| Phase 5 | Validation & polish | 2-3 hours | Medium |

**Total Estimated Time:** 16-23 hours (2-3 days for one developer)

---

## Next Steps

1. **Review this plan** with stakeholders
2. **Decide on design questions** (category creation, interest tracking)
3. **Create GitHub issue** for tracking
4. **Begin Phase 1** (database verification)
5. **Iterate on feedback** from initial implementation

---

*Document maintained by: Development Team*
*Last updated: 2025-11-05*
