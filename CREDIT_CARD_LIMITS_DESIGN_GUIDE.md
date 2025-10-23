# PGBudget Architecture Analysis: Credit Card Limits & Billing Feature Design Guide

## Executive Summary

PGBudget is a sophisticated zero-sum budgeting application built on PostgreSQL with double-entry accounting principles. It's a PHP-based web application using PostgreSQL with PostgREST-style SQL functions for API operations. The system is fully multi-tenant with Row-Level Security (RLS) for data isolation.

---

## 1. DATABASE ARCHITECTURE

### 1.1 Technology Stack
- **Database**: PostgreSQL (primary data layer)
- **Connection**: PDO (PHP Database Objects)
- **Schema Organization**: Three-layer architecture (data, utils, api)
- **Migrations**: goose SQL migration tool
- **User Context**: Session-based with PostgreSQL config settings for context

### 1.2 Core Database Schemas

#### **data Schema** (Raw Data & Persistence)
- Core tables for all persistent data
- Row-Level Security (RLS) enforced on all tables
- User isolation via `user_data` column (defaults to `utils.get_user()`)
- Primary keys: `bigint generated always as identity`
- Public identifiers: 8-character nanoid format

**Key Tables:**
- `data.ledgers` - Budget ledgers (master records)
- `data.accounts` - Accounts with type system (asset, liability, equity)
  - `type` (accounting type)
  - `internal_type` (asset_like vs liability_like)
  - Supports hierarchy: `parent_category_id`, `is_group`, `sort_order`
- `data.transactions` - Double-entry transactions
  - `debit_account_id`, `credit_account_id`, `amount`, `date`
  - Soft delete support: `deleted_at`
- `data.balances` - Account balance snapshots
- `data.category_goals` - Goal tracking for categories
- `data.payees` - Transaction payee information
- `data.loans` - Loan records
- `data.loan_payments` - Loan payment schedules
- `data.installment_plans` - Installment payment tracking
- `data.transaction_splits` - Split transaction support
- `data.recurring_transactions` - Recurring transaction templates
- `data.notification_preferences` - User notifications
- `data.users` - User authentication (username, email, password_hash)

#### **utils Schema** (Internal Business Logic)
- Helper functions with `SECURITY DEFINER` for privilege escalation
- Complex SQL logic encapsulated for reusability
- User context passed explicitly or derived from JWT claims
- Function naming: `<entity>_<action>_<type>`

**Key Functions:**
- `utils.get_user()` - Retrieves current user from session
- `utils.nanoid(length)` - Generates unique IDs
- `utils.set_updated_at_fn()` - Trigger for timestamps
- `utils.get_account_balance(ledger_id, account_id)` - Balance calculation
- `utils.add_transaction(...)` - Core transaction creation
- `utils.move_between_categories(...)` - Budget reallocation
- `utils.create_default_ledger_accounts()` - Ledger setup

#### **api Schema** (Public Interface)
- Views and functions exposed to clients
- `SECURITY INVOKER` execution (respects RLS)
- Clean, user-friendly interface
- Functions often follow Pattern A (call utils, query api view) or Pattern B (construct response from utils result)

**Key Views & Functions:**
- `api.ledgers` - Ledger listing
- `api.accounts` - Account details
- `api.transactions` - Transaction interface
- `api.assign_to_category(...)` - Budget allocation
- `api.add_transaction(...)` - Transaction creation wrapper
- `api.pay_credit_card(...)` - CC payment logic
- `api.get_budget_status(ledger_uuid, [period])` - Budget summary
- `api.get_account_transactions(account_uuid)` - Transaction history
- `api.get_account_balance(account_uuid)` - Current balance

### 1.3 Double-Entry Accounting Model

**Account Type System:**
```
Semantic Type → Internal Type → Normal Balance Direction
Asset         → asset_like    → Debit (+)
Expense       → asset_like    → Debit (+)
Liability     → liability_like → Credit (+)
Equity        → liability_like → Credit (+)
Revenue       → liability_like → Credit (+)
```

**Core Transaction Patterns:**

1. **Income Receipt**
   - Debit: Bank Account (asset) | Credit: Income (equity)

2. **Budget Allocation (Budgeting Money)**
   - Debit: Income (equity) | Credit: Category (equity)

3. **Cash Spending**
   - Debit: Category | Credit: Bank Account (asset)

4. **Credit Card Spending**
   - Debit: Category | Credit: Credit Card (liability)

5. **Credit Card Payment**
   - Debit: Credit Card (liability) | Credit: Bank Account (asset)

6. **Budget Movement Between Categories**
   - Debit: Source Category | Credit: Destination Category

### 1.4 Row-Level Security Implementation

**RLS Policy Pattern:**
```sql
ALTER TABLE data.<table> ENABLE ROW LEVEL SECURITY;
CREATE POLICY <table>_policy ON data.<table>
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());
```

**User Context Flow:**
1. Session starts with `$_SESSION['user_id']`
2. PHP calls: `SET app.current_user_id = ?` via PostgreSQL config
3. Functions retrieve: `utils.get_user()` from session context
4. RLS policies automatically filter by `user_data` column

### 1.5 Database Constraints & Validation

**Key Constraint Patterns:**
- Unique constraints on (uuid)
- Unique constraints on (name, ledger_id, user_data) for namespacing
- Check constraints for enum-like values
- Foreign key constraints with ON DELETE CASCADE for cleanup
- Balance validation checks (non-negative where required)
- Amount positivity checks (stored in cents as bigint)

**Trigger Patterns:**
- `BEFORE UPDATE` triggers for `updated_at` timestamps
- `AFTER INSERT` triggers for related record creation
- `INSTEAD OF INSERT/UPDATE/DELETE` triggers on API views for complex mutations

---

## 2. BACKEND API ARCHITECTURE

### 2.1 Technology Stack
- **Language**: PHP (7.4+)
- **Database Access**: PDO with prepared statements
- **API Style**: Function-based (PostgREST-style SQL functions)
- **Authentication**: Session-based with PHP `$_SESSION`
- **File Structure**: `/public/api/*.php` endpoint files

### 2.2 API Design Patterns

**Pattern A: Call Utils → Query API View**
```php
// In API layer:
$result = utils.function_name(...);  // Get internal ID
SELECT * FROM api.view_name WHERE id = $result['id'];  // Return user-friendly format
```

**Pattern B: Call Utils → Construct Response**
```php
// In API layer:
$result = utils.function_name(...);  // Get multiple fields
// Construct response from utils result + input parameters
return constructed_record;
```

### 2.3 Existing API Endpoints

**Core Endpoints:**
- `GET /api/get-accounts.php?ledger=<uuid>[&type=<type>]` - List accounts
- `GET /api/get-categories.php?ledger=<uuid>` - List budget categories
- `POST /api/quick_add_transaction.php` - Quick transaction creation
- `POST /api/account-transfer.php` - Transfer between accounts
- `POST /api/pay-credit-card.php` - CC payment
- `POST /api/move_money.php` - Move budget between categories

**Report Endpoints:**
- `GET /api/get-budget-status.php?ledger=<uuid>` - Budget overview
- `GET /api/get-spending-report.php` - Category spending
- `GET /api/get-income-expense-report.php` - Income/expense summary
- `GET /api/get-net-worth-report.php` - Net worth calculation
- `GET /api/get-category-trends.php` - Spending trends
- `GET /api/get-age-of-money.php` - Age of money metric

**Specialized Endpoints:**
- `POST /api/goals.php` - Goal management
- `POST /api/loans.php` - Loan management
- `POST /api/installment-plans.php` - Installment tracking
- `POST /api/bulk-operations.php` - Bulk actions

### 2.4 Request/Response Pattern

**Standard Request:**
```php
$input = json_decode(file_get_contents('php://input'), true);
// Validate required fields
// Sanitize with sanitizeInput()
// Parse currency with parseCurrency() [stored as cents]
// Execute SQL function
// Return JSON response
```

**Standard Response:**
```json
{
  "success": true,
  "message": "...",
  "data": { ... },
  "error": null
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message",
  "http_code": 400
}
```

### 2.5 Input Handling

**Sanitization:**
- `sanitizeInput()` - HTML escaping with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`

**Currency Handling:**
- `parseCurrency($amount)` - Converts to cents (integer)
  - Handles $, decimals, commas, multiple formats
- `formatCurrency($cents)` - Displays currency
  - Returns formatted string: `$XX.XX`

**Validation:**
- Date format validation: `YYYY-MM-DD`
- UUID validation: 8-char nanoid or standard UUID
- Amount > 0 check
- Ledger/Account ownership verification

---

## 3. AUTHENTICATION & USER MANAGEMENT

### 3.1 Current Authentication System

**User Table Structure:**
```sql
data.users:
  - id (bigint, primary key)
  - uuid (text, nanoid)
  - username (text, unique, 3-50 chars, alphanumeric+underscore)
  - email (text, unique)
  - password_hash (text, bcrypt, 60+ chars)
  - first_name, last_name (optional)
  - is_active (boolean)
  - email_verified (boolean)
  - created_at, updated_at (timestamps)
```

### 3.2 Authentication Flow

**Login:**
1. User submits credentials
2. PHP checks session existence
3. If no session, redirects to `/auth/login.php`
4. Login page verifies credentials against `data.users`
5. Session set to `$_SESSION['user_id'] = username` (or user_uuid)

**Context Setup:**
1. On each request, call `setUserContext($db)`
2. Executes: `SET app.current_user_id = ?` with session user ID
3. Functions use `utils.get_user()` to retrieve this value
4. RLS policies filter by this value

**Demo Mode:**
- Special `demo_user` session value
- Limited feature access
- No account modifications

### 3.3 Authorization Patterns

**User Context Parameter:**
```php
function requireAuth($allowDemo = false) {
    if (!isset($_SESSION['user_id'])) {
        if ($allowDemo && isset($_GET['demo'])) {
            $_SESSION['user_id'] = 'demo_user';
            return;
        }
        header('Location: /pgbudget/auth/login.php');
        exit;
    }
}
```

**Resource Ownership:**
- Verified via API queries: `SELECT ... WHERE user_data = ?` (from session)
- RLS policies enforce at database level
- 404 responses for non-existent or inaccessible resources

---

## 4. TRANSACTION HANDLING

### 4.1 Transaction Creation

**Primary Function: `utils.add_transaction`**
```sql
Parameters:
  p_ledger_uuid text
  p_date timestamptz
  p_description text
  p_type text ('inflow' or 'outflow')
  p_amount bigint (in cents)
  p_account_uuid text (asset/liability account)
  p_category_uuid text (optional, defaults to 'Unassigned')
  p_user_data text (defaults to utils.get_user())

Returns: int (transaction ID)
```

**Logic Flow:**
1. Validate inputs (amount > 0, type in enum, etc.)
2. Resolve UUIDs to internal IDs with user context check
3. Determine debit/credit accounts based on:
   - Account internal_type (asset_like vs liability_like)
   - Transaction type (inflow vs outflow)
4. Insert into `data.transactions`
5. Triggers automatically update balances

**Debit/Credit Resolution Example:**
- Asset account + inflow → Debit asset, credit category
- Asset account + outflow → Debit category, credit asset
- Liability account + inflow → Debit category, credit liability
- Liability account + outflow → Debit liability, credit category

### 4.2 Credit Card Transaction Handling

**Credit Card Spending Logic:**
```
When user spends on credit card:
1. Debit: Spending Category | Credit: Credit Card Account
2. Automatically create budget move:
   Debit: Spending Category | Credit: CC Payment Category
3. This "reserves" budget for paying the card balance
```

**Implementation:**
- Trigger on transaction insert for liability accounts
- Calls `utils.move_budget_for_cc_spending(...)`
- CC Payment category auto-created when CC account created
- Metadata tracks origin: `is_cc_budget_move: true`

### 4.3 Transaction Soft Delete

**Implementation:**
- Column: `deleted_at timestamptz` (nullable)
- Set to `current_timestamp` on deletion (don't actually delete)
- Queries filter: `WHERE deleted_at IS NULL`
- Allows audit trail and recovery

### 4.4 Balance Calculation

**Balance Table:**
- Stores denormalized balance snapshots per account
- Updated by triggers after transaction insert
- Enables fast balance queries without scanning all transactions

**Balance Query Pattern:**
```sql
SELECT utils.get_account_balance(ledger_id, account_id)
-- or
SELECT * FROM api.get_ledger_balances(ledger_uuid)
-- or
SELECT * FROM api.get_account_balance(account_uuid)
```

**Running Balance Calculation:**
- `api.get_account_transactions()` includes running balance
- Calculated with UNION of debit/credit transactions
- Ordered by date + created_at
- Accumulates balance through transaction history

### 4.5 Budget Status Calculation

**Function: `api.get_budget_status(ledger_uuid, [period])`**

Returns for each category:
- `budgeted_amount` - Total from Income allocation
- `activity_amount` - Spending/income activity
- `balance_amount` - Net effect (budgeted - activity)
- `available_amount` - Balance if positive, 0 if negative

**Period Support:**
- Optional `YYYYMM` format for monthly views
- Filters transactions within period
- Defaults to all-time if not provided

---

## 5. CATEGORY SYSTEM

### 5.1 Category Structure

**Core Properties:**
- `type: 'equity'` (always for categories)
- `internal_type: 'liability_like'` (so balance increases with credit)
- `name` - User-friendly name
- `uuid` - Public identifier
- `ledger_id` - Parent ledger (cascades on delete)

**Special Categories (Auto-Created):**
1. **Income** - Unallocated funds from income
2. **Unassigned** - Default category for new transactions
3. **Off-budget** - For transactions not counted toward budget
4. **CC Payment Categories** - Auto-created per credit card

### 5.2 Category Hierarchy (Phase 6.1)

**New Fields:**
- `parent_category_id bigint` - Link to parent group
- `sort_order integer` - Custom ordering (0-based)
- `is_group boolean` - True if container (doesn't hold transactions)

**Constraint:**
- Only equity accounts can have parents or be groups
- Non-group categories can't have children

**Functions:**
- `utils.get_category_with_group(...)` - Get category + parent info
- `utils.get_categories_by_group(...)` - Organized category list
- `api.create_category_group(...)` - Create container
- `api.move_category_to_group(...)` - Update parent

### 5.3 Category Goals (YNAB Rule 2: Embrace Your True Expenses)

**Goal Types:**
1. **monthly_funding** - Budget X per month (resets monthly)
2. **target_balance** - Save to total amount (cumulative)
3. **target_by_date** - Reach amount by specific date

**Table: `data.category_goals`**
```sql
category_id bigint (unique per category)
goal_type text
target_amount bigint (in cents, > 0)
target_date date (required for target_by_date)
repeat_frequency text ('weekly', 'monthly', 'yearly', null)
```

**API Functions:**
- `api.create_category_goal(...)`
- `api.update_category_goal(...)`
- `api.delete_category_goal(...)`
- `api.get_category_goal_status(category_uuid)` - Returns progress
- `api.get_ledger_goals(ledger_uuid)` - All goals with status

### 5.4 Category Operations

**Add Category:**
```php
POST /api/add_category?ledger_uuid=...&name=...
// Calls: api.add_category(ledger_uuid, name)
```

**Assign Money to Category:**
```php
POST /api/assign_to_category
// Parameters: ledger_uuid, date, description, amount, category_uuid
// Creates debit: Income, credit: Category
```

**Move Between Categories:**
```php
POST /api/move_money.php
// Parameters: ledger_uuid, from_category_uuid, to_category_uuid, amount
// Creates debit: from_category, credit: to_category
```

**Delete Category:**
```php
POST /api/delete-category.php
// Prevents deletion of special categories (Income, Unassigned, Off-budget)
// Deletes category and related transactions
```

---

## 6. CREDIT CARD & PAYMENT SYSTEM

### 6.1 Credit Card Account Type

**Characteristics:**
- `type: 'liability'`
- `internal_type: 'liability_like'`
- Stores balance as negative (credit side)
- Name typically: "Visa", "Mastercard", etc.

**Automatic Features:**
- CC Payment category created on account creation
- Category named: `<CC Name> - Pending Payment`
- Metadata link: `credit_card_uuid` and `is_cc_payment_category: true`

### 6.2 Credit Card Spending Logic

**When user records spending on CC:**

1. **Spending Transaction:**
   ```
   Debit: Groceries (-$50)
   Credit: Credit Card (+$50)
   ```

2. **Automatic Budget Move:**
   ```
   Debit: Groceries (-$50)
   Credit: Groceries CC Payment Category (+$50)
   ```

**Effect:**
- Budget for Groceries shows negative (overspent)
- CC Payment category shows $50 reserved for payment
- User must either:
  - Cover overspending from another category
  - Or accept the balance as "what must be paid"

**Functions:**
- `utils.move_budget_for_cc_spending(...)` - Creates budget move
- `utils.get_cc_payment_category_id(...)` - Finds payment category

### 6.3 Credit Card Payment

**Function: `api.pay_credit_card()`**

```sql
Parameters:
  p_credit_card_uuid text
  p_bank_account_uuid text
  p_amount numeric (in cents)
  p_date timestamptz
  p_memo text (optional)

Returns: UUID of payment transaction
```

**Logic:**
1. Validate card balance >= payment amount
2. Create transaction:
   ```
   Debit: Credit Card (-$X)
   Credit: Bank Account (-$X)
   ```
3. Reduce CC Payment category balance

### 6.4 Card Limits & Billing (FEATURE DESIGN AREA)

**Planned Features:**
- Hard limit enforcement (prevent overspend)
- APR calculation on outstanding balance
- Billing cycle tracking
- Due date tracking
- Minimum payment calculation
- Interest accrual simulation
- Payment scheduling
- Statement generation

**Related Tables for Design:**
- `data.loans` - Can track APR, dates, payment schedule
- `data.installment_plans` - For installment purchases
- Custom table needed: `data.credit_card_limits` (proposed)

---

## 7. LOAN & INSTALLMENT MANAGEMENT

### 7.1 Loan System

**Table: `data.loans`**
```sql
Fields:
  - uuid, ledger_id, account_id (FK to liability account)
  - lender_name, loan_type (mortgage/auto/personal/student/credit_line/other)
  - principal_amount, current_balance (numeric 19,4)
  - interest_rate, interest_type (fixed/variable)
  - compounding_frequency (daily/monthly/annually)
  - loan_term_months, remaining_months
  - start_date, first_payment_date, payment_day_of_month
  - payment_amount, payment_frequency (monthly/bi-weekly/weekly/quarterly)
  - amortization_type (standard/interest_only/balloon)
  - status (active/paid_off/defaulted/refinanced/closed)
  - notes, metadata
```

**API Functions:**
- `api.create_loan(...)` - Register loan
- `api.update_loan(...)` - Modify terms
- `api.delete_loan(...)` - Remove loan
- `api.get_loan(loan_uuid)` - Single loan details
- `api.list_loans(ledger_uuid)` - All ledger loans
- `api.calculate_loan_payment(...)` - Payment calculation

### 7.2 Loan Payments

**Table: `data.loan_payments`**
```sql
Fields:
  - uuid, loan_id (FK), date
  - principal_paid, interest_paid, total_paid
  - payment_method, status (scheduled/paid/missed/late)
  - memo, metadata
```

**Payment Tracking:**
- Schedule generated based on loan terms
- Record actual payments
- Track missed/late payments
- Calculate accrued interest

### 7.3 Installment Plans

**Table: `data.installment_plans`**
```sql
Fields:
  - uuid, ledger_id, credit_card_account_id
  - original_transaction_id, purchase_amount, purchase_date
  - description, category_account_id
  - number_of_installments, installment_amount
  - frequency (monthly/bi-weekly/weekly), start_date
  - status (active/completed/cancelled)
  - completed_installments, notes, metadata
```

**Installment Schedules: `data.installment_schedules`**
```sql
Fields:
  - uuid, installment_plan_id
  - installment_number, due_date, amount
  - status (pending/paid/overdue), payment_transaction_id
  - paid_date, memo
```

**Features:**
- Split large purchases across months
- Track installment payment progress
- Auto-create schedule based on frequency
- Record individual installment payments
- Support multiple installments per plan

**API Functions:**
- `api.create_installment_plan(...)`
- `api.process_installment_payment(...)`
- `api.get_installment_schedule(...)`
- `api.get_installment_reports(...)`

---

## 8. FRONTEND ARCHITECTURE

### 8.1 Technology Stack

**Server-Side:**
- PHP 7.4+ (template rendering)
- Session management (PHP built-in)

**Client-Side:**
- Vanilla JavaScript (no framework)
- CSS Grid/Flexbox for layouts
- Responsive design with mobile-first approach

**File Structure:**
```
/public
  /api              - API endpoint files (.php)
  /auth             - Authentication pages
  /budget           - Dashboard & budget pages
  /accounts         - Account management
  /transactions     - Transaction pages
  /installments     - Installment pages
  /loans            - Loan management pages
  /settings         - Settings pages
  /payees           - Payee management
  /css              - Stylesheets
  /js               - JavaScript modules
  /images           - Icons and images
  index.php         - Home/ledger selection
```

### 8.2 Page Structure

**Dashboard: `/budget/dashboard.php`**
- Retrieves ledger details
- Gets budget status (with optional period filter)
- Displays budget totals (income, budgeted, left to budget)
- Shows recent transactions
- Lists overspent categories
- Displays active loans

**Key Data Flows:**
```
1. Load page → Set user context
2. Fetch budget status → Display categories with budgets
3. Fetch accounts → Populate quick-add form
4. Fetch transactions → Display transaction list
5. Fetch loan summary → Display active loans
```

### 8.3 JavaScript Modules

**Key Modules:**
- `budget-dashboard-enhancements.js` - Dashboard interactions
- `quick-add-modal.js` - Quick transaction entry
- `move-money-modal.js` - Budget movement UI
- `goals-manager.js` - Goal creation/editing
- `loans.js` - Loan management
- `installments.js` - Installment tracking
- `keyboard-shortcuts.js` - Keyboard navigation

**Ajax Pattern:**
```javascript
fetch('/pgbudget/api/endpoint.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify(data)
})
.then(r => r.json())
.then(data => {
  if (data.success) {
    // Update UI
  } else {
    // Show error
  }
});
```

### 8.4 CSS Structure

**Files:**
- `style.css` - Main stylesheet
- `mobile.css` - Mobile-specific styles
- `keyboard-shortcuts.css` - Keyboard UI styles
- `undo.css` - Undo functionality styles
- `delete-ledger.css` - Delete modal styles

**Design System:**
- Color scheme: Blues/grays (#2b6cb0 primary)
- Responsive grid layouts
- Modal dialogs for complex operations
- Auto-dismissing messages (5s)

### 8.5 Common UI Patterns

**Message Display:**
```html
<div class="message message-success">Success message</div>
<!-- Auto-dismisses after 5 seconds -->
```

**Form Patterns:**
```html
<form method="POST" action="/api/endpoint.php">
  <input type="text" name="field" required>
  <button type="submit">Submit</button>
</form>
```

**Modal Dialogs:**
- Confirmation modals for destructive actions
- Input modals for quick operations
- Overlay with escape key to close

---

## 9. SECURITY PATTERNS

### 9.1 Input Validation & Sanitization

**Validation Layers:**
1. **PHP Layer:**
   - Required field checks
   - Format validation (date YYYY-MM-DD, UUID format)
   - Amount validation (> 0)
   - Type validation (enum values)

2. **SQL Layer:**
   - Prepared statements (prevents SQL injection)
   - Check constraints on tables
   - Foreign key constraints for referential integrity
   - Type coercion (`::<type>` casts)

**Sanitization:**
- `sanitizeInput()` - HTML entity encoding for user input
- Currency parsing - Strips non-numeric characters
- UUID validation - Regex check before query

### 9.2 Authentication & Authorization

**Session Security:**
- `session_start()` at request start
- Redirect to login if not authenticated
- Demo mode flag prevents certain operations
- User context set per request

**Row-Level Security (RLS):**
- All `data` tables have RLS enabled
- Policies enforce `user_data = utils.get_user()`
- Even if user somehow modifies SQL, RLS blocks access
- Database-level enforcement (most secure)

**Function Security:**
- `SECURITY DEFINER` for utils functions (run as creator)
- `SECURITY INVOKER` for API functions (run as caller)
- User context passed explicitly to utils functions
- User ownership verified before operations

### 9.3 CSRF & Cross-Origin Protection

**Current Implementation:**
- Not explicitly visible in code
- POST endpoints require JSON body
- Session-based auth (not API key)
- Consider: CSRF tokens for form submissions

### 9.4 Password Security

**User Password Storage:**
- Bcrypt hashing: `crypt(password, gen_salt('bf', 12))`
- 60+ character hash requirement (bcrypt minimum)
- Verification: `crypt(input, stored_hash) = stored_hash`

### 9.5 API Security

**No Direct Data Exposure:**
- Internal `bigint` IDs never exposed
- Only 8-char nanoids in API responses
- Sensitive fields (password_hash) excluded from views
- `api.users` view filters to current user only

**Rate Limiting:**
- Not explicitly implemented (consider for production)
- Database constraints prevent duplicate inserts
- Transaction validation prevents invalid states

---

## 10. EXISTING FEATURES SUMMARY

### Budget Management
- Zero-sum budgeting with category allocation
- Budget goals (monthly funding, target balance, target by date)
- Category hierarchies with grouping
- Monthly period filtering
- Overspending tracking and coverage

### Transaction Management
- Double-entry accounting core
- Transaction types: inflow/outflow
- Split transactions support
- Recurring transactions
- Soft delete with recovery
- Transaction search and filtering
- Payee management

### Account Management
- Asset accounts (bank accounts)
- Liability accounts (credit cards)
- Equity accounts (categories, income)
- Account balances and history
- Account transfers
- Account reconciliation

### Financial Reporting
- Budget status (budgeted/activity/balance)
- Spending reports by category
- Income/expense summary
- Net worth calculation
- Category spending trends
- Age of money metric
- Action history/audit trail

### Credit Card Features
- CC account creation
- CC payment tracking
- Automatic CC Payment category creation
- Spending on credit card with automatic budget moves
- CC payment transaction creation
- Balance tracking per card

### Loan Management
- Loan registration (mortgage, auto, personal, etc.)
- Interest rate and term tracking
- Payment schedule tracking
- Multiple loan types and amortization
- Loan status management

### Installment Payments
- Installment plan creation (2-36 installments)
- Monthly/bi-weekly/weekly scheduling
- Installment schedule generation
- Individual installment payment tracking
- Installment reporting and projections

### User Features
- Multi-user support with RLS
- User registration and authentication
- Notification preferences
- Keyboard shortcuts
- Mobile-responsive interface
- PWA manifest for app-like experience

---

## 11. DESIGN CONSIDERATIONS FOR CREDIT CARD LIMITS & BILLING FEATURE

### 11.1 Feature Scope

**Proposed Features:**
1. **Hard Spend Limits**
   - Maximum balance limit per card
   - Warning at 80%/90%/95% thresholds
   - Soft block at 100% (user override with confirmation)
   - Hard block at configured limit

2. **Interest Calculation**
   - Monthly interest accrual based on APR
   - Compounding calculation (daily/monthly)
   - Interest payable tracking
   - Automatic transaction generation for interest charges

3. **Billing Cycle Management**
   - Statement date (day of month)
   - Due date (configurable offset from statement)
   - Minimum payment requirement
   - Grace period tracking

4. **Payment Scheduling**
   - Scheduled payments queue
   - Auto-payment setup (monthly minimum, full balance, or fixed amount)
   - Payment reminders
   - Due date tracking

5. **Detailed Reporting**
   - Statement generation (transactions within cycle)
   - Interest paid tracking
   - Payment history
   - Due date warnings
   - Auto-payment history

### 11.2 Database Schema Design

**New Table: `data.credit_card_limits`**
```sql
CREATE TABLE data.credit_card_limits (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),
    
    -- Credit card reference
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,
    
    -- Spend limit
    credit_limit NUMERIC(19,4) NOT NULL,
    warning_threshold_percent INTEGER DEFAULT 80 CHECK (warning_threshold_percent > 0 AND warning_threshold_percent < 100),
    
    -- APR and interest
    annual_percentage_rate NUMERIC(8,5) NOT NULL DEFAULT 0.0 CHECK (annual_percentage_rate >= 0 AND annual_percentage_rate <= 100),
    interest_type TEXT NOT NULL DEFAULT 'variable' CHECK (interest_type IN ('fixed', 'variable')),
    compounding_frequency TEXT NOT NULL DEFAULT 'daily' CHECK (compounding_frequency IN ('daily', 'monthly')),
    
    -- Billing cycle
    statement_day_of_month INTEGER NOT NULL DEFAULT 1 CHECK (statement_day_of_month >= 1 AND statement_day_of_month <= 31),
    due_date_offset_days INTEGER NOT NULL DEFAULT 21 CHECK (due_date_offset_days > 0),
    grace_period_days INTEGER NOT NULL DEFAULT 0 CHECK (grace_period_days >= 0),
    
    -- Minimum payment
    minimum_payment_percent NUMERIC(8,5) NOT NULL DEFAULT 1.0 CHECK (minimum_payment_percent > 0),
    minimum_payment_flat NUMERIC(19,4) NOT NULL DEFAULT 25.00 CHECK (minimum_payment_flat >= 0),
    
    -- Auto-payment
    auto_payment_enabled BOOLEAN NOT NULL DEFAULT false,
    auto_payment_type TEXT CHECK (auto_payment_type IN ('minimum', 'full_balance', 'fixed_amount', NULL)),
    auto_payment_amount NUMERIC(19,4) CHECK (auto_payment_type IS NULL OR auto_payment_amount > 0),
    auto_payment_date INTEGER CHECK (auto_payment_date IS NULL OR (auto_payment_date >= 1 AND auto_payment_date <= 31)),
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- Audit
    last_interest_accrual_date DATE,
    notes TEXT,
    metadata JSONB,
    
    CONSTRAINT credit_card_limits_uuid_unique UNIQUE(uuid),
    CONSTRAINT credit_card_limits_credit_card_unique UNIQUE(credit_card_account_id, user_data),
    CONSTRAINT credit_card_limits_limit_positive CHECK (credit_limit > 0),
    CONSTRAINT credit_card_limits_user_data_length CHECK (char_length(user_data) <= 255),
    CONSTRAINT credit_card_limits_notes_length CHECK (char_length(notes) <= 1000)
);

ALTER TABLE data.credit_card_limits ENABLE ROW LEVEL SECURITY;
CREATE POLICY credit_card_limits_policy ON data.credit_card_limits
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

CREATE INDEX idx_credit_card_limits_account_id ON data.credit_card_limits(credit_card_account_id);
CREATE INDEX idx_credit_card_limits_user_data ON data.credit_card_limits(user_data);
```

**New Table: `data.credit_card_statements`**
```sql
CREATE TABLE data.credit_card_statements (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),
    
    -- Statement reference
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id),
    statement_period_start DATE NOT NULL,
    statement_period_end DATE NOT NULL,
    
    -- Amounts
    previous_balance NUMERIC(19,4) NOT NULL DEFAULT 0,
    purchases_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    payments_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    interest_charged NUMERIC(19,4) NOT NULL DEFAULT 0,
    fees_charged NUMERIC(19,4) NOT NULL DEFAULT 0,
    ending_balance NUMERIC(19,4) NOT NULL,
    
    -- Payment info
    minimum_payment_due NUMERIC(19,4) NOT NULL,
    due_date DATE NOT NULL,
    
    -- Status
    is_current BOOLEAN NOT NULL DEFAULT false,
    
    metadata JSONB,
    
    CONSTRAINT credit_card_statements_uuid_unique UNIQUE(uuid),
    CONSTRAINT credit_card_statements_period_order CHECK (statement_period_start < statement_period_end),
    CONSTRAINT credit_card_statements_user_data_length CHECK (char_length(user_data) <= 255)
);

ALTER TABLE data.credit_card_statements ENABLE ROW LEVEL SECURITY;
CREATE POLICY credit_card_statements_policy ON data.credit_card_statements
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

CREATE INDEX idx_credit_card_statements_account_id ON data.credit_card_statements(credit_card_account_id);
CREATE INDEX idx_credit_card_statements_user_data ON data.credit_card_statements(user_data);
```

### 11.3 Implementation Architecture

**Utils Layer Functions:**
```sql
-- Create/update limit configuration
utils.set_credit_card_limit(
    p_account_uuid text,
    p_credit_limit numeric,
    p_apr numeric,
    p_statement_day_of_month int,
    p_due_date_offset_days int,
    ...
)

-- Check if spending would exceed limit
utils.check_credit_limit_violation(
    p_account_id bigint,
    p_proposed_amount bigint
)

-- Calculate interest accrual for period
utils.calculate_interest_accrual(
    p_account_id bigint,
    p_from_date date,
    p_to_date date
)

-- Generate statement for period
utils.generate_credit_card_statement(
    p_account_id bigint,
    p_statement_start_date date,
    p_statement_end_date date
)

-- Calculate minimum payment
utils.calculate_minimum_payment(
    p_account_id bigint,
    p_balance numeric
)

-- Process scheduled payment
utils.process_auto_payment(
    p_account_id bigint,
    p_ledger_id bigint
)
```

**API Layer Functions:**
```sql
api.set_credit_card_limit(...)      -- Wrapper for utils
api.get_credit_card_limits(...)     -- Read limits
api.update_credit_card_limit(...)   -- Update configuration
api.get_account_utilization(...)    -- Current usage %
api.get_billing_summary(...)        -- Statement info
api.schedule_payment(...)            -- Schedule payment
api.process_interest_accrual(...)   -- Run nightly accrual
```

### 11.4 Frontend Integration

**New Components:**
1. **Credit Card Limits Panel**
   - Visual progress bar showing utilization
   - Warning indicators at thresholds
   - Quick limit adjustment

2. **Billing Information Section**
   - Statement dates
   - Due dates
   - Minimum payment due
   - Interest charged
   - Payment history

3. **Payment Scheduling Modal**
   - Schedule one-time payment
   - Setup auto-payment (min, full, fixed)
   - View scheduled payments
   - Cancel scheduled payment

4. **Statements View**
   - Monthly statement summary
   - Transaction list within period
   - Interest breakdown
   - Payment history

### 11.5 Business Logic Flows

**Spending on Limited Card:**
```
1. User creates transaction on CC with limit set
2. System checks: current_balance + new_amount <= credit_limit
3. If exceeds: Show warning, allow override (soft block)
4. Create transaction (debit category, credit CC)
5. Update CC balance
6. Check new balance against limits
7. If warning threshold: Notify user
8. If hard limit: Decline and show error
```

**Interest Accrual (Nightly Batch):**
```
1. For each active CC account with APR > 0
2. Get statement period balance
3. Calculate: balance * (APR / days_in_year) * days_in_period
4. Create transaction: debit category, credit CC (interest charge)
5. Update balance snapshots
6. Log in audit trail
```

**Payment Processing:**
```
1. User initiates payment (manual or scheduled)
2. Validate bank account has sufficient funds
3. Create transaction: debit CC, credit bank account
4. Update CC balance
5. Update CC Payment category balance
6. Record in payment history
7. If auto-payment: Update next scheduled date
8. Generate receipt/confirmation
```

**Statement Generation (Monthly):**
```
1. Determine statement period (1st to day N)
2. Get transactions in period
3. Calculate opening balance
4. Sum purchases, payments, interest, fees
5. Calculate minimum payment
6. Calculate due date
7. Create statement record
8. Mark as current
9. Archive previous month's statement
10. Notify user of new statement
```

### 11.6 Data Isolation & Security

**Considerations:**
- All limits per user (RLS on credit_card_limits table)
- Credit card account ownership verified
- Ledger ownership verified before operations
- Prevent accessing other users' limits/statements
- Audit all limit changes (in action_history)
- Transaction creation with proper user context

---

## 12. DEPLOYMENT & CONFIGURATION

### 12.1 Environment Setup

**Required Environment Variables (.env):**
```
DB_HOST=localhost
DB_PORT=5432
DB_NAME=pgbudget
DB_USER=pgbudget_user
DB_PASSWORD=secure_password
```

**PHP Configuration:**
- Session management enabled
- Error reporting: production-safe settings
- PDO error mode: ERRMODE_EXCEPTION

### 12.2 Database Initialization

**Steps:**
1. Create database and user
2. Run migrations with goose
   ```bash
   goose -dir migrations postgres "connection_string" up
   ```
3. Verify schemas: data, utils, api
4. Test RLS policies
5. Seed demo data (optional)

### 12.3 Testing Approach

**Test Data Setup:**
```sql
-- Set user context for testing
SELECT set_config('app.current_user_id', 'test_user', false);

-- Create test ledger
INSERT INTO data.ledgers (name) VALUES ('Test Budget');

-- Verify RLS works
SELECT * FROM data.ledgers;  -- Only test_user's ledger
```

---

## 13. MIGRATION PATH TO NEW FEATURES

### Phase 1: Implement Credit Card Limits
1. Create tables: credit_card_limits, credit_card_statements
2. Create utils functions for limit checking
3. Create API functions for CRUD operations
4. Update transaction creation to check limits
5. Add database tests

### Phase 2: Interest Accrual
1. Add APR fields to limits table
2. Create interest calculation utils function
3. Create nightly batch job (cron or message queue)
4. Generate interest transactions
5. Create interest tracking views

### Phase 3: Billing Cycle Management
1. Add statement_day_of_month, due_date_offset
2. Create statement generation function
3. Create statement archival logic
4. Add statement views to API
5. Implement statement notifications

### Phase 4: Payment Scheduling
1. Create scheduled_payments table
2. Add auto-payment configuration
3. Create payment processing function
4. Add notification system
5. Create payment history views

### Phase 5: Frontend Integration
1. Add limits panel to dashboard
2. Create credit card settings page
3. Add statement viewing interface
4. Create payment scheduling modal
5. Add real-time limit warnings

---

## 14. REFERENCE ARCHITECTURE DIAGRAM

```
┌─────────────────────────────────────────────────────────────┐
│                     FRONTEND (Browser)                      │
│  PHP Templates + Vanilla JS + CSS + PWA                     │
└──────────────────┬──────────────────────────────────────────┘
                   │ HTTP/JSON
┌──────────────────▼──────────────────────────────────────────┐
│                  API Layer (PHP)                            │
│  /api/*.php - Request validation, sanitization, response   │
└──────────────────┬──────────────────────────────────────────┘
                   │ Prepared Statements
┌──────────────────▼──────────────────────────────────────────┐
│              PostgreSQL Database                            │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ api SCHEMA - Public API Functions & Views           │  │
│  │  - assign_to_category(), add_transaction()         │  │
│  │  - get_budget_status(), pay_credit_card()          │  │
│  │  - [SECURITY INVOKER - respects RLS]               │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ utils SCHEMA - Internal Business Logic              │  │
│  │  - add_transaction(), calculate_balance()           │  │
│  │  - move_budget_for_cc_spending()                    │  │
│  │  - [SECURITY DEFINER - trusted context]             │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ data SCHEMA - Persistent Tables                     │  │
│  │  - ledgers, accounts, transactions, balances        │  │
│  │  - category_goals, loans, installment_plans         │  │
│  │  - [ROW LEVEL SECURITY - user_data filters]         │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 15. KEY TAKEAWAYS FOR CREDIT CARD FEATURE

1. **Use RLS for Security**: All credit_card_limits data must use user_data RLS policies
2. **Follow Patterns**: Use existing utils → api pattern for new functions
3. **Double-Entry**: Interest charges are transactions (debit category/liability, credit CC)
4. **Soft Deletes**: Don't delete limits/statements, mark inactive or archive
5. **Audit Trail**: Record all limit changes via action_history table
6. **User Context**: Always validate user owns the CC account before operations
7. **Transaction Triggers**: Use triggers to auto-create related transactions
8. **API Consistency**: Return nanoid UUIDs, amounts in cents, dates in YYYY-MM-DD format
9. **Validation Layers**: Validate in PHP AND SQL (check constraints)
10. **Denormalization**: Store calculated amounts in statements table for performance

---

## 16. FILE LOCATIONS REFERENCE

**Database Setup:**
- Migrations: `/var/www/html/pgbudget/migrations/`
- Schema: `data`, `utils`, `api`

**Backend:**
- API Endpoints: `/var/www/html/pgbudget/public/api/*.php`
- Auth: `/var/www/html/pgbudget/includes/auth.php`
- Database: `/var/www/html/pgbudget/config/database.php`
- Functions: `/var/www/html/pgbudget/includes/functions.php`

**Frontend:**
- Pages: `/var/www/html/pgbudget/public/*.php`
- Dashboard: `/var/www/html/pgbudget/public/budget/dashboard.php`
- CSS: `/var/www/html/pgbudget/public/css/*.css`
- JavaScript: `/var/www/html/pgbudget/public/js/*.js`

**Documentation:**
- Architecture: `/var/www/html/pgbudget/ARCHITECTURE.md`
- Specification: `/var/www/html/pgbudget/SPEC.md`
- Existing Features: Various `.md` files in root

