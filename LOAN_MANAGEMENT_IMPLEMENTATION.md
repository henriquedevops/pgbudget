# Loan Management Implementation Plan

## Overview
This document outlines the step-by-step plan to implement comprehensive loan management functionality in pgbudget. The implementation will follow existing codebase patterns and integrate seamlessly with the current accounting system.

## Current State
- `data.loans` table exists in the database
- Loans can be associated with liability accounts
- No UI or API functions currently implemented for loan management
- Basic transaction system exists for recording payments

## Goals
- Full CRUD operations for loans
- Payment schedule tracking
- Payment recording with automatic transaction creation
- Loan balance and interest tracking
- Integration with existing account system
- Clean UI following existing design patterns

---

## Implementation Steps

### Phase 1: Database Schema & Migration

#### Step 1.1: Review and Enhance Loans Table
**File**: Review existing schema in database
**Actions**:
- Examine current `data.loans` table structure
- Identify missing fields needed for comprehensive loan management
- Design schema for:
  - Loan metadata (principal, interest rate, term, start date)
  - Payment schedule information
  - Current balance tracking
  - Interest calculation method

#### Step 1.2: Create Loan Payments Table
**File**: New migration file `migrations/YYYYMMDDHHMMSS_add_loan_management.sql`
**Actions**:
- Create `data.loan_payments` table for payment schedule
  - Fields: payment_id, loan_id, due_date, amount, principal_amount, interest_amount, paid_date, transaction_id
- Add indexes for performance
- Add foreign key constraints

#### Step 1.3: Create API Layer Functions
**File**: Same migration file
**Actions**:
- `api.get_loans(p_ledger_id)` - List all loans for a ledger
- `api.get_loan(p_loan_id)` - Get single loan details
- `api.create_loan(...)` - Create new loan with payment schedule
- `api.update_loan(...)` - Update loan details
- `api.delete_loan(p_loan_id)` - Delete loan and cleanup
- `api.get_loan_payments(p_loan_id)` - Get payment schedule
- `api.record_loan_payment(...)` - Record a payment and create transaction
- `api.calculate_loan_schedule(...)` - Calculate amortization schedule

#### Step 1.4: Add RLS Policies
**File**: Same migration file
**Actions**:
- Add Row Level Security policies for `data.loan_payments`
- Ensure users can only access their own loan data
- Follow existing RLS patterns from other tables

---

### Phase 2: Backend API Endpoints

#### Step 2.1: Create Loans API Endpoint
**File**: `public/api/loans.php`
**Actions**:
- Handle GET requests (list loans, get single loan)
- Handle POST requests (create loan)
- Handle PUT requests (update loan)
- Handle DELETE requests (delete loan)
- Return JSON responses
- Follow pattern from `public/api/goals.php`

#### Step 2.2: Create Loan Payments API Endpoint
**File**: `public/api/loan-payments.php`
**Actions**:
- Handle GET requests (get payment schedule)
- Handle POST requests (record payment)
- Calculate remaining balance
- Create associated transaction entry
- Return JSON responses

---

### Phase 3: Frontend Pages

#### Step 3.1: Loans List Page
**File**: `public/loans/index.php`
**Actions**:
- Display all loans for current ledger
- Show key info: lender, principal, balance, interest rate, status
- Add "New Loan" button
- Add edit/delete actions per loan
- Show quick summary (total owed, monthly payments)
- Follow design pattern from `public/categories/index.php`

#### Step 3.2: Create Loan Page
**File**: `public/loans/create.php`
**Actions**:
- Form to create new loan:
  - Lender name
  - Principal amount
  - Interest rate
  - Loan term (months/years)
  - Start date
  - Payment frequency (monthly, bi-weekly, etc.)
  - Associated liability account (dropdown)
  - Payment day of month
- Calculate and preview payment schedule
- Submit to API
- Follow pattern from `public/categories/create.php`

#### Step 3.3: Edit Loan Page
**File**: `public/loans/edit.php`
**Actions**:
- Load existing loan data
- Allow editing of mutable fields
- Prevent changing calculated/historical data
- Update via API
- Follow pattern from `public/categories/edit.php`

#### Step 3.4: View Loan Details Page
**File**: `public/loans/view.php`
**Actions**:
- Display complete loan information
- Show amortization schedule
- Display payment history
- Show current balance and next payment due
- Add "Record Payment" button
- Show associated transactions
- Charts for principal vs interest over time

#### Step 3.5: Record Payment Page
**File**: `public/loans/record-payment.php`
**Actions**:
- Form to record loan payment:
  - Payment date
  - Amount paid
  - Account paid from (dropdown)
  - Notes/memo
- Auto-calculate principal/interest split
- Create transaction automatically
- Update payment schedule
- Redirect to loan view page

---

### Phase 4: JavaScript Modules

#### Step 4.1: Loan Management Module
**File**: `public/js/loans.js`
**Actions**:
- Class-based module following existing patterns
- AJAX functions for API calls:
  - `createLoan(loanData)`
  - `updateLoan(loanId, loanData)`
  - `deleteLoan(loanId)`
  - `getLoans(ledgerId)`
  - `getLoan(loanId)`
- Payment schedule calculation
- Form validation
- Error handling
- Follow pattern from `public/js/goals.js`

#### Step 4.2: Loan Payment Module
**File**: `public/js/loan-payments.js`
**Actions**:
- Handle payment recording
- Calculate payment splits (principal/interest)
- Preview payment effect
- AJAX calls for payment API
- Update UI after payment recorded

---

### Phase 5: Integration & UI Updates

#### Step 5.1: Add Loans to Navigation
**File**: `public/includes/header.php`
**Actions**:
- Add "Loans" menu item
- Link to `/loans/`
- Place logically in navigation (near Accounts or Goals)

#### Step 5.2: Update Dashboard (Optional)
**File**: `public/dashboard.php`
**Actions**:
- Add loan summary widget
- Show total debt
- Show upcoming payments
- Link to loans page

#### Step 5.3: Link Loans to Accounts
**File**: `public/accounts/view.php`
**Actions**:
- If account is linked to a loan, show loan details
- Add link to associated loan
- Show loan balance vs account balance

---

### Phase 6: Testing

#### Step 6.1: Database Migration Test
**Actions**:
- Run migration up
- Verify tables created
- Verify functions exist
- Run migration down (if applicable)
- Test RLS policies

#### Step 6.2: Integration Tests
**File**: `loan_test.go`
**Actions**:
- Test loan creation
- Test loan retrieval
- Test loan update
- Test loan deletion
- Test payment recording
- Test payment schedule generation
- Test RLS isolation between users
- Follow pattern from existing `main_test.go`

#### Step 6.3: Manual UI Testing
**Actions**:
- Test all pages load correctly
- Test form validation
- Test CRUD operations via UI
- Test payment recording
- Test calculations (interest, amortization)
- Test error handling
- Test responsive design

---

## Database Schema Design

### data.loans (existing - may need enhancement)
```sql
- loan_id (UUID, PK)
- ledger_id (UUID, FK)
- account_id (UUID, FK) -- linked liability account
- lender_name (TEXT)
- principal_amount (NUMERIC)
- interest_rate (NUMERIC) -- annual rate
- loan_term_months (INTEGER)
- start_date (DATE)
- payment_amount (NUMERIC) -- regular payment amount
- payment_frequency (TEXT) -- 'monthly', 'bi-weekly', etc.
- payment_day (INTEGER) -- day of month/period
- status (TEXT) -- 'active', 'paid_off', 'closed'
- created_at (TIMESTAMPTZ)
- updated_at (TIMESTAMPTZ)
```

### data.loan_payments (new)
```sql
- payment_id (UUID, PK)
- loan_id (UUID, FK)
- payment_number (INTEGER)
- due_date (DATE)
- scheduled_amount (NUMERIC)
- principal_amount (NUMERIC)
- interest_amount (NUMERIC)
- paid_date (DATE, nullable)
- actual_amount_paid (NUMERIC, nullable)
- transaction_id (UUID, FK, nullable) -- link to actual transaction
- status (TEXT) -- 'scheduled', 'paid', 'overdue', 'skipped'
- notes (TEXT)
- created_at (TIMESTAMPTZ)
```

---

## API Function Signatures

### Loan CRUD
- `api.get_loans(p_ledger_id UUID)` → TABLE
- `api.get_loan(p_loan_id UUID)` → JSON
- `api.create_loan(p_ledger_id UUID, p_account_id UUID, p_lender_name TEXT, p_principal NUMERIC, p_interest_rate NUMERIC, p_term_months INTEGER, p_start_date DATE, p_frequency TEXT, p_payment_day INTEGER)` → UUID
- `api.update_loan(p_loan_id UUID, p_lender_name TEXT, p_interest_rate NUMERIC, ...)` → BOOLEAN
- `api.delete_loan(p_loan_id UUID)` → BOOLEAN

### Payment Management
- `api.get_loan_payments(p_loan_id UUID)` → TABLE
- `api.get_upcoming_payments(p_ledger_id UUID, p_days_ahead INTEGER)` → TABLE
- `api.record_loan_payment(p_loan_id UUID, p_payment_date DATE, p_amount NUMERIC, p_from_account_id UUID, p_memo TEXT)` → UUID
- `api.calculate_amortization_schedule(p_principal NUMERIC, p_interest_rate NUMERIC, p_term_months INTEGER, p_start_date DATE)` → TABLE

---

## UI Flow Diagrams

### Creating a New Loan
1. User clicks "New Loan" from loans list
2. Fills out loan form (lender, amount, rate, term)
3. Selects or creates associated liability account
4. Previews payment schedule
5. Submits form
6. System creates loan record
7. System generates payment schedule
8. Redirects to loan view page

### Recording a Payment
1. User views loan details
2. Clicks "Record Payment"
3. Enters payment details (date, amount, account)
4. System calculates principal/interest split
5. Previews transaction to be created
6. User confirms
7. System creates transaction
8. System updates payment record
9. System updates loan balance
10. Returns to loan view with updated info

---

## Dependencies & Prerequisites

- Existing infrastructure:
  - PostgreSQL database with RLS
  - PHP session management
  - Account system
  - Transaction system
  - Ledger system

- New dependencies:
  - None (uses existing stack)

---

## Timeline Estimate

- Phase 1 (Database): 2-3 hours
- Phase 2 (API): 2-3 hours
- Phase 3 (Frontend): 4-6 hours
- Phase 4 (JavaScript): 2-3 hours
- Phase 5 (Integration): 1-2 hours
- Phase 6 (Testing): 2-3 hours

**Total**: 13-20 hours

---

## Success Criteria

- [ ] Users can create loans with all necessary details
- [ ] Users can view list of all loans
- [ ] Users can view detailed loan information and payment schedule
- [ ] Users can edit loan details
- [ ] Users can delete loans (with appropriate warnings)
- [ ] Users can record payments against loans
- [ ] Payments automatically create transactions
- [ ] Payment schedule accurately calculates amortization
- [ ] Loan balances update correctly after payments
- [ ] All data is properly isolated per user (RLS working)
- [ ] UI is consistent with existing design
- [ ] Integration tests pass
- [ ] No security vulnerabilities introduced

---

## Future Enhancements (Out of Scope)

- Refinancing loans
- Extra payment tracking and payoff scenarios
- Loan comparison calculator
- Auto-payment scheduling
- Alerts for upcoming payments
- Export amortization schedule to CSV/PDF
- Charts and graphs for loan analytics
- Support for variable interest rates
- Support for interest-only periods

---

## Notes

- Follow existing codebase patterns strictly
- Use three-tier PostgreSQL architecture (data/utils/api)
- Maintain RLS policies for all new tables
- Use UUID for all primary keys
- Follow existing PHP and JavaScript coding style
- Ensure all user inputs are validated
- Use prepared statements for SQL injection prevention
- Test thoroughly before marking complete

---

## References

- `/tmp/pgbudget_codebase_analysis.md` - Comprehensive codebase documentation
- `/tmp/code_patterns_reference.md` - Code templates and examples
- `/tmp/EXPLORATION_SUMMARY.txt` - Quick reference guide
- Existing migration pattern: `migrations/20251010000003_add_goal_api_functions.sql`
- Existing API pattern: `public/api/goals.php`
- Existing page pattern: `public/categories/create.php`
- Existing JS pattern: `public/js/goals.js`
