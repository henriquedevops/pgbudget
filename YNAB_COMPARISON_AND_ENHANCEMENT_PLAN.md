# PGBudget vs YNAB: Comparison & Enhancement Plan

## Executive Summary

PGBudget has a solid foundation implementing zero-sum budgeting with double-entry accounting. However, compared to YNAB (You Need A Budget), it lacks several key user experience features, workflow optimizations, and conceptual elements that make YNAB powerful for users.

---

## Current State Analysis

### ✅ What PGBudget Has (Strong Foundation)

1. **Core Zero-Sum Budgeting**
   - Proper double-entry accounting
   - Income allocation to categories
   - Budget status tracking (budgeted, activity, balance)
   - Month-view support

2. **Multi-tenant Architecture**
   - Row-level security
   - User authentication
   - Multiple ledgers per user

3. **Transaction Management**
   - Add income/expenses
   - Edit/delete transactions
   - Transaction history by account
   - Budget assignment workflow

4. **Account Types**
   - Assets (bank accounts)
   - Liabilities (credit cards)
   - Equity (categories, Income, Unassigned)

5. **Basic Reporting**
   - Budget totals (income, budgeted, left to budget)
   - Account balances
   - Balance history

---

## YNAB Core Features Comparison

### 🎯 YNAB's Four Rules (Methodology)

| Rule | YNAB Implementation | PGBudget Status | Gap Analysis |
|------|---------------------|-----------------|--------------|
| **Rule 1: Give Every Dollar a Job** | Clear "Ready to Assign" indicator, quick assignment | ✅ Partial - Has "Left to Budget" but UX not prominent | Need better visual emphasis on unassigned money |
| **Rule 2: Embrace Your True Expenses** | Goal templates for irregular expenses | ❌ Missing | No goal system, no monthly funding goals |
| **Rule 3: Roll With The Punches** | Easy category transfers, cover overspending | ⚠️ Limited - Manual transactions only | Missing quick move money between categories |
| **Rule 4: Age Your Money** | Age of Money metric | ❌ Missing | No AOM calculation |

### 📊 Key Feature Comparison Matrix

| Feature Area | YNAB | PGBudget | Priority | Complexity |
|--------------|------|----------|----------|------------|
| **Budget Screen** |
| Month navigation | ← → arrows, month selector | ✅ Month dropdown | Medium | Low |
| Quick budget entry | Click-to-edit inline | ❌ Separate assign page | **HIGH** | Medium |
| Move money between categories | Drag-and-drop or modal | ❌ Manual transactions | **HIGH** | Medium |
| Underfunded indicator | Yellow/red warnings | ❌ No visual warnings | **HIGH** | Low |
| Overspending handling | Auto-cover or manual | ⚠️ Shows negative, no auto-fix | **HIGH** | Medium |
| Category groups | Collapsible groups | ❌ Flat list | Medium | Medium |
| **Goals & Targets** |
| Monthly funding goals | "Target $X by date" | ❌ None | **HIGH** | High |
| Target balance goals | "Save $X total" | ❌ None | **HIGH** | High |
| Spending goals | "Spend up to $X" | ✅ Implicit in budgeting | Low | N/A |
| Goal templates | Weekly, monthly, annual | ❌ None | Medium | High |
| Underfunded alert | Shows needed amount | ❌ None | Medium | Medium |
| **Transactions** |
| Quick add from budget | "+" button on budget screen | ⚠️ Separate page | Medium | Low |
| Auto-categorization | Based on payee | ❌ None | Low | High |
| Split transactions | Multiple categories per transaction | ❌ None | Medium | High |
| Recurring transactions | Schedule repeating transactions | ❌ None | Medium | High |
| Import from banks | CSV/OFX import | ❌ None | Low | Very High |
| Payee management | Payee list, rename, merge | ❌ Description only | Medium | Medium |
| Memo field | Additional notes | ⚠️ Description only | Low | Low |
| Cleared/Uncleared | Track bank reconciliation | ❌ None | Low | Low |
| **Account Management** |
| Reconciliation | Match bank balance | ❌ None | Medium | Medium |
| Account transfers | Easy transfer between accounts | ⚠️ Manual double-entry | Medium | Low |
| Credit card workflow | Payment accounts, overspending | ⚠️ Basic liability tracking | **HIGH** | Medium |
| Off-budget accounts | Tracking only, no budgeting | ✅ Has Off-budget category | Low | N/A |
| **Reports** |
| Spending by category | Pie/bar charts | ❌ None | Medium | Medium |
| Spending trends | Month-over-month | ❌ None | Low | High |
| Net worth over time | Assets - Liabilities | ❌ None | Medium | Medium |
| Age of Money | Average age calculation | ❌ None | Low | High |
| Income vs Expense | Monthly comparison | ❌ None | Medium | Low |
| **UX/UI** |
| Mobile responsive | Excellent mobile UX | ⚠️ Basic responsive | Medium | Medium |
| Keyboard shortcuts | Power user features | ❌ None | Low | Medium |
| Undo functionality | Ctrl+Z for actions | ❌ None | Low | Medium |
| Bulk editing | Multi-select transactions | ❌ None | Low | Medium |
| Search/Filter | Advanced filtering | ❌ None | Medium | Medium |

---

## Critical Missing Features (Must-Have)

### 1. **Inline Budget Assignment** ⭐⭐⭐⭐⭐
**Current:** Separate page to assign money
**YNAB:** Click amount, type, Enter
**Impact:** Slows down budgeting workflow significantly

**Implementation:**
- Add inline editing to budget dashboard category table
- AJAX calls to `api.assign_to_category()`
- Visual feedback on assignment
- Show available money prominently

### 2. **Category-to-Category Moves** ⭐⭐⭐⭐⭐
**Current:** Must create manual transactions
**YNAB:** "Move Money" button, simple modal
**Impact:** Core to "Roll With The Punches" rule

**Implementation:**
- New API function: `api.move_between_categories()`
- Modal UI with source/destination category selectors
- Validates sufficient funds in source
- Creates proper double-entry transaction

### 3. **Monthly Funding Goals** ⭐⭐⭐⭐⭐
**Current:** None
**YNAB:** Goals show needed amount, progress
**Impact:** Helps users budget for irregular expenses (Rule 2)

**Implementation:**
- New `data.category_goals` table
- Goal types: monthly_funding, target_balance, target_by_date
- UI to set goals on categories
- Budget dashboard shows: goal amount, funded amount, remaining
- Visual indicators (progress bars, colors)

### 4. **Overspending Handling** ⭐⭐⭐⭐
**Current:** Shows negative balance, no guidance
**YNAB:** Next month subtraction or cover from other category
**Impact:** Confuses users when categories go negative

**Implementation:**
- Detect overspending in budget status
- Add "Cover Overspending" button
- Modal to select category to pull from
- Option to subtract from next month's budget
- Visual warnings for overspent categories (red background)

### 5. **Credit Card Payment Category** ⭐⭐⭐⭐
**Current:** Basic liability tracking
**YNAB:** Auto-allocates budget for CC spending
**Impact:** Makes credit card budgeting intuitive

**Implementation:**
- When spending on credit card, auto-move budget to "CC Payment" category
- Track available payment amount
- "Payment Available" column for CC accounts
- Reconciliation workflow

---

## Enhancement Roadmap

### Phase 1: Core Workflow Improvements (4-6 weeks)
**Goal:** Make daily budgeting as smooth as YNAB

#### 1.1 Inline Budget Assignment
- **Database:** No changes needed (uses existing `api.assign_to_category`)
- **Backend:** Add AJAX endpoint wrapper
- **Frontend:**
  - Make budget amount cells editable
  - Add JavaScript for inline editing
  - Visual feedback on save
- **Testing:** Assignment workflow, validation, error handling

#### 1.2 Move Money Between Categories
- **Database:**
  ```sql
  -- New function: utils.move_between_categories
  CREATE FUNCTION utils.move_between_categories(
      p_ledger_uuid text,
      p_from_category_uuid text,
      p_to_category_uuid text,
      p_amount bigint,
      p_date timestamptz,
      p_description text,
      p_user_data text DEFAULT utils.get_user()
  ) RETURNS text AS $$
  -- Validates source has sufficient balance
  -- Creates debit/credit transaction (debit source, credit dest)
  -- Returns transaction UUID
  $$;
  ```
- **Backend:** Wrap in `api.move_between_categories()`
- **Frontend:**
  - "Move Money" button on each category row
  - Modal with source/dest selectors and amount
  - Show available balance
- **Testing:** Balance validation, double-entry correctness

#### 1.3 Enhanced Budget Dashboard
- Prominent "Ready to Assign" banner at top (like YNAB's header)
- Color coding:
  - Green: Fully funded / on track
  - Yellow: Underfunded (has goal, not met)
  - Red: Overspent (negative balance)
- Quick-add transaction button on budget screen
- Sticky header with budget totals when scrolling

#### 1.4 Overspending Indicators & Handling
- Red background for negative category balances
- "Cover Overspending" button appears when negative
- Warning banner if any overspending exists
- Modal to cover overspending:
  - Select source category
  - Shows available amounts
  - Creates move transaction
- Guidance text explaining overspending impact

**Phase 1 Deliverables:**
- Migration: `20251101000000_add_move_money_function.sql`
- PHP: `api/move_money.php` (AJAX endpoint)
- JavaScript: `public/js/budget-inline-edit.js`
- Updated: `public/budget/dashboard.php`
- Tests: Go tests for move money function
- Documentation: Update README with new features

---

### Phase 2: Goals & Planning (6-8 weeks)
**Goal:** Help users plan for irregular expenses (YNAB Rule 2)

#### 2.1 Database Schema for Goals
```sql
-- New table: data.category_goals
CREATE TABLE data.category_goals (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    category_id bigint REFERENCES data.accounts(id) ON DELETE CASCADE,
    goal_type text NOT NULL CHECK (goal_type IN ('monthly_funding', 'target_balance', 'target_by_date')),
    target_amount bigint NOT NULL CHECK (target_amount > 0),
    target_date date,
    repeat_frequency text CHECK (repeat_frequency IN ('weekly', 'monthly', 'yearly')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),

    CONSTRAINT category_goals_uuid_unique UNIQUE (uuid),
    CONSTRAINT category_goals_one_per_category UNIQUE (category_id)
);

-- RLS
ALTER TABLE data.category_goals ENABLE ROW LEVEL SECURITY;
CREATE POLICY category_goals_policy ON data.category_goals
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());
```

#### 2.2 Goal Types Implementation

**Monthly Funding Goal:**
- Budget $X every month
- Shows: "$200 of $300 funded this month"
- Resets each month
- Use case: Groceries, gas, regular expenses

**Target Balance Goal:**
- Save up to $X total across all time
- Shows: "$1,500 of $2,000 saved"
- Cumulative, doesn't reset
- Use case: Emergency fund, vacation savings

**Target by Date Goal:**
- Save $X by specific date
- Calculates monthly needed amount: (target - current) / months_remaining
- Shows: "$50/month needed to reach $600 by Dec 2025"
- Use case: Christmas gifts, annual insurance

#### 2.3 Goal API Functions
```sql
-- Create goal
CREATE FUNCTION api.create_category_goal(
    p_category_uuid text,
    p_goal_type text,
    p_target_amount bigint,
    p_target_date date DEFAULT NULL,
    p_repeat_frequency text DEFAULT NULL
) RETURNS setof api.category_goals;

-- Update goal
CREATE FUNCTION api.update_category_goal(
    p_goal_uuid text,
    p_target_amount bigint,
    p_target_date date,
    p_repeat_frequency text
) RETURNS setof api.category_goals;

-- Delete goal
CREATE FUNCTION api.delete_category_goal(p_goal_uuid text) RETURNS boolean;

-- Get goal status with progress
CREATE FUNCTION api.get_category_goal_status(
    p_category_uuid text,
    p_month text DEFAULT NULL -- YYYYMM format
) RETURNS TABLE (
    goal_uuid text,
    goal_type text,
    target_amount bigint,
    funded_amount bigint,
    remaining_amount bigint,
    needed_monthly bigint,
    on_track boolean,
    target_date date
);
```

#### 2.4 Goal UI Components
- **Goal creation modal** on category (gear icon or "Set Goal" button)
- **Goal type selector** with explanations
- **Progress indicators** on budget dashboard:
  - Progress bar under category name
  - Text: "Goal: $500/month" or "Save $2,000 by Dec 2025"
- **"Quick Fund Goals"** button to auto-assign to underfunded goals
- **Goal summary** in sidebar showing total goals, funded, remaining

#### 2.5 Goal Calculations
- Function to calculate current funded amount for month/all-time
- Calculate monthly needed for target-by-date goals
- Identify underfunded categories
- Suggest assignment amounts to meet goals

**Phase 2 Deliverables:**
- Migration: `20251201000000_add_category_goals.sql`
- API functions: Goal CRUD operations
- Utils functions: Goal status calculations
- PHP pages: `goals/manage.php`, `goals/create.php`
- UI components: Goal modals, progress bars
- Updated dashboard: Show goals on categories
- Tests: Goal calculation accuracy tests
- Documentation: Goal feature guide

---

### Phase 3: Transaction Enhancements (4-6 weeks)
**Goal:** Streamline transaction entry and management

#### 3.1 Split Transactions
**Database:**
```sql
CREATE TABLE data.transaction_splits (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    parent_transaction_id bigint REFERENCES data.transactions(id) ON DELETE CASCADE,
    category_id bigint REFERENCES data.accounts(id),
    amount bigint NOT NULL CHECK (amount > 0),
    memo text,
    created_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),

    CONSTRAINT transaction_splits_uuid_unique UNIQUE (uuid)
);
```

**UI:**
- "Split" button on transaction form
- Add/remove split rows dynamically
- Auto-calculate remaining amount to assign
- Visual validation (splits must equal total)

**API:**
```sql
CREATE FUNCTION api.add_split_transaction(
    p_ledger_uuid text,
    p_date timestamptz,
    p_description text,
    p_account_uuid text,
    p_total_amount bigint,
    p_splits jsonb -- [{"category_uuid": "...", "amount": 1000, "memo": "..."}]
) RETURNS text; -- Returns parent transaction UUID
```

#### 3.2 Recurring Transactions
**Database:**
```sql
CREATE TABLE data.recurring_transactions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    ledger_id bigint REFERENCES data.ledgers(id) ON DELETE CASCADE,
    description text NOT NULL,
    amount bigint NOT NULL,
    frequency text NOT NULL CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'yearly')),
    next_date date NOT NULL,
    end_date date,
    account_id bigint REFERENCES data.accounts(id),
    category_id bigint REFERENCES data.accounts(id),
    transaction_type text NOT NULL CHECK (transaction_type IN ('inflow', 'outflow')),
    auto_create boolean DEFAULT false,
    enabled boolean DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),

    CONSTRAINT recurring_transactions_uuid_unique UNIQUE (uuid)
);
```

**Features:**
- "Make Recurring" checkbox on transaction form
- Schedule editor (frequency, start date, end date)
- List of upcoming recurring transactions
- Manual "Create Now" button
- Auto-create option (with cron job or trigger)

#### 3.3 Payee Management
**Database:**
```sql
CREATE TABLE data.payees (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    name text NOT NULL,
    default_category_id bigint REFERENCES data.accounts(id),
    auto_categorize boolean DEFAULT true,
    merged_into_id bigint REFERENCES data.payees(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),

    CONSTRAINT payees_uuid_unique UNIQUE (uuid),
    CONSTRAINT payees_name_user_unique UNIQUE (name, user_data)
);

-- Link payees to transactions
ALTER TABLE data.transactions ADD COLUMN payee_id bigint REFERENCES data.payees(id);
```

**Features:**
- Auto-create payees from transaction descriptions
- Payee autocomplete on transaction form
- Remember last category used per payee
- Payee renaming (updates all linked transactions)
- Payee merging (combine duplicates)
- Default category per payee for auto-categorization

#### 3.4 Quick-Add Transaction Modal
- Keyboard shortcut: 'T' to open modal anywhere
- Modal overlay on any page (doesn't navigate away)
- Pre-fill account if on account page
- Smart date picker:
  - Today (default)
  - Yesterday
  - Custom date picker
- Save & Add Another option

#### 3.5 Account Transfers Simplified
**Current issue:** Must use `add_transaction` with complex debit/credit logic

**Solution:**
```sql
CREATE FUNCTION api.add_account_transfer(
    p_ledger_uuid text,
    p_from_account_uuid text,
    p_to_account_uuid text,
    p_amount bigint,
    p_date timestamptz,
    p_memo text DEFAULT NULL
) RETURNS text; -- Returns transaction UUID
-- Automatically creates proper double-entry
-- Shows as "Transfer: To [Account]" in from account
-- Shows as "Transfer: From [Account]" in to account
```

**UI:**
- "Transfer" button on account page
- Simple modal: From → To with amount
- Validates accounts are in same ledger
- Doesn't require category selection

**Phase 3 Deliverables:**
- Migrations: Splits, recurring, payees tables
- API functions: Split transactions, recurring, transfers
- UI: Enhanced transaction forms, quick-add modal
- JavaScript: Modal handlers, autocomplete
- Payee management page
- Tests: Split transaction logic, transfer validation
- Documentation: Transaction features guide

---

### Phase 4: Credit Card Workflow (3-4 weeks)
**Goal:** Make credit cards easier to manage (YNAB-style)

#### 4.1 Credit Card Payment Category Auto-Creation
When a credit card account is created:
- Auto-create companion "CC Payment: [Card Name]" category
- Link category to card in metadata
- This category holds budget for CC payments

#### 4.2 Credit Card Spending Logic
**When spending on credit card:**
```sql
-- On transaction: debit Category, credit CreditCard
-- Simultaneously: move budget from Category to CC Payment category
-- This ensures budget is "reserved" for paying the card
```

**Visual indicator:**
- Show "Payment Available: $X" on credit card accounts
- This equals balance of CC Payment category
- Should match what you owe on the card if all spending was budgeted

#### 4.3 Credit Card Payment Transaction
**When paying credit card from bank account:**
```sql
-- Transaction: debit CreditCard, credit BankAccount
-- Simultaneously: debit CC Payment category (reduces payment budget)
```

**Result:** Card balance goes down, payment budget is used

#### 4.4 Credit Card Overspending Handling
**Problem:** Spending on CC in category with insufficient budget

**YNAB Solution:**
- Option 1: Subtract overspending from next month (default)
- Option 2: Cover overspending now from another category

**Implementation:**
- Detect overspending on CC transactions
- UI warning: "This will create $X overspending in [Category]"
- Prompt: "Cover now or handle next month?"
- If cover now: Modal to select source category
- If next month: Flag for month rollover logic

#### 4.5 Credit Card Reconciliation
- "Reconcile" button on credit card account page
- Enter statement balance and date
- Show difference from PGBudget balance
- List uncleared transactions
- Mark transactions as cleared
- Create adjustment transaction if needed
- Lock reconciliation (mark transactions as reconciled)

**Database:**
```sql
-- Add to transactions table
ALTER TABLE data.transactions ADD COLUMN cleared_status text DEFAULT 'uncleared'
    CHECK (cleared_status IN ('uncleared', 'cleared', 'reconciled'));

-- Reconciliation history
CREATE TABLE data.reconciliations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    account_id bigint REFERENCES data.accounts(id),
    reconciliation_date date NOT NULL,
    statement_balance bigint NOT NULL,
    pgbudget_balance bigint NOT NULL,
    difference bigint NOT NULL,
    adjustment_transaction_id bigint REFERENCES data.transactions(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);
```

**Phase 4 Deliverables:**
- Migration: CC payment categories, cleared status, reconciliations
- Utils functions: CC spending logic, payment category management
- API: Reconciliation functions
- UI: CC workflow enhancements, reconciliation page
- Tests: CC spending scenarios, payment logic
- Documentation: Credit card guide

---

### Phase 5: Reporting & Analytics (6-8 weeks)
**Goal:** Help users understand their spending patterns

#### 5.1 Spending by Category Report
**Features:**
- Pie/donut chart showing category breakdown
- Bar chart option for comparison
- Filter by date range or month
- Drill-down: Click category → see transactions
- Export to CSV
- Show % of total spending per category

**Implementation:**
- Chart.js for visualizations
- New page: `reports/spending-by-category.php`
- API function: `api.get_spending_by_category(ledger_uuid, start_date, end_date)`

#### 5.2 Income vs Expense Report
**Features:**
- Monthly bar chart: Income (green) vs Expenses (red)
- Show surplus/deficit per month
- Trend line over time
- Average income/expense calculations
- Net savings rate
- Filter by date range

**Implementation:**
- Chart.js bar chart with dual datasets
- API function: `api.get_income_vs_expense(ledger_uuid, start_date, end_date)`
- Returns: month, total_income, total_expense, net

#### 5.3 Net Worth Report
**Features:**
- Line chart showing net worth over time
- Assets - Liabilities calculation
- Show asset and liability trends separately
- Compare to previous periods
- Percentage change calculations
- Milestone markers

**Implementation:**
- Calculate net worth at end of each month
- Store snapshots or calculate on-demand
- API function: `api.get_net_worth_over_time(ledger_uuid, start_date, end_date)`

#### 5.4 Category Spending Trends
**Features:**
- Line chart per category over time (last 12 months)
- Compare actual spending to budgeted amount
- Identify overspending patterns
- Monthly average and median
- Detect anomalies (outlier months)
- Budget adjustment suggestions

**Implementation:**
- Select multiple categories to compare
- API: `api.get_category_spending_trend(category_uuid, months)`

#### 5.5 Age of Money (AOM)
**Concept:** Average number of days between receiving money and spending it

**Calculation:**
1. For each outflow transaction, find most recent inflow(s) that "funded" it
2. Calculate days between inflow date and outflow date
3. Average across all outflows in period

**Higher AOM = Living on older money = Better financial cushion**

**Goal:** AOM > 30 days (living on last month's income)

**Implementation:**
- Complex calculation, may need optimization
- API: `api.get_age_of_money(ledger_uuid)` returns integer days
- Show trend over time
- Explain metric with tooltips/help text

**Database:**
```sql
-- May need to cache AOM calculations
CREATE TABLE data.age_of_money_cache (
    ledger_id bigint REFERENCES data.ledgers(id),
    calculation_date date,
    age_days integer,
    PRIMARY KEY (ledger_id, calculation_date)
);
```

**Phase 5 Deliverables:**
- New directory: `public/reports/`
- Pages: spending-by-category.php, income-vs-expense.php, net-worth.php, trends.php
- Chart.js integration: `public/js/charts.js`
- API functions: All report data functions
- CSV export: Report download functionality
- Cache layer: For expensive calculations
- Tests: Report accuracy, calculation validation
- Documentation: Reports user guide

---

### Phase 6: UX Polish & Advanced Features (4-6 weeks)
**Goal:** Make the app delightful to use

#### 6.1 Category Groups (Hierarchy)
**Database:**
```sql
-- Add to data.accounts table
ALTER TABLE data.accounts ADD COLUMN parent_category_id bigint REFERENCES data.accounts(id);
ALTER TABLE data.accounts ADD COLUMN sort_order integer DEFAULT 0;
ALTER TABLE data.accounts ADD COLUMN is_group boolean DEFAULT false;

-- Constraint: Only equity accounts can be groups or have parents
ALTER TABLE data.accounts ADD CONSTRAINT category_groups_equity_only
    CHECK (
        (parent_category_id IS NULL AND is_group = false) OR
        (type = 'equity')
    );
```

**UI:**
- Create "Category Group" (special category type)
- Assign categories to groups
- Budget dashboard: Collapsible groups
- Group subtotals (sum of child categories)
- Drag-and-drop to reorder categories and groups
- Expand/collapse all button

**Examples:**
- Monthly Bills (group)
  - Rent
  - Utilities
  - Internet
- Daily Expenses (group)
  - Groceries
  - Gas
  - Dining Out

#### 6.2 Search & Filtering
**Global search box (top nav):**
- Search transactions by description
- Search by payee
- Search by amount
- Search categories

**Transaction filtering:**
- Date range picker
- Category multi-select
- Account multi-select
- Amount range (min/max)
- Type filter (inflow/outflow)
- Cleared status filter
- Payee filter

**Save filter presets:**
- "Last 30 days Groceries"
- "Uncleared transactions"
- "This month's bills"

**Implementation:**
- JavaScript filtering (client-side for performance)
- Server-side for large datasets
- URL parameters for shareable filtered views

#### 6.3 Bulk Operations
**Multi-select transactions:**
- Checkbox column in transaction list
- "Select All" checkbox in header
- Shift+click to select range

**Bulk actions:**
- Bulk categorize: Apply category to all selected
- Bulk delete: Delete multiple transactions
- Bulk edit date: Change date for all selected
- Bulk edit account: Move to different account
- Bulk clear: Mark as cleared
- Bulk payee: Assign payee to all selected

**UI:**
- Action bar appears when items selected
- Confirmation modal for destructive actions
- Undo support (keep deleted items for 30 days)

#### 6.4 Keyboard Shortcuts
**Navigation:**
- `G` then `B` - Go to Budget
- `G` then `A` - Go to Accounts
- `G` then `T` - Go to Transactions
- `G` then `R` - Go to Reports

**Actions:**
- `T` - New transaction
- `A` - Assign money
- `M` - Move money between categories
- `C` - Create category
- `/` - Focus search box

**Budget screen:**
- `↑` `↓` - Navigate categories
- `Enter` - Edit selected category budget
- `Tab` - Move to next category
- `Esc` - Close modal/cancel edit

**Transaction list:**
- `J` / `K` - Next/previous transaction
- `E` - Edit selected transaction
- `D` - Delete selected transaction
- `X` - Toggle cleared status

**Implementation:**
- JavaScript event listeners
- Prevent conflicts with form inputs
- Show keyboard shortcut help: `?` key
- Customizable shortcuts in settings

#### 6.5 Mobile Optimization
**Responsive improvements:**
- Touch-friendly tap targets (min 44px)
- Swipe gestures:
  - Swipe left on transaction → Quick actions
  - Swipe right on transaction → Clear/unclear
  - Swipe down on budget → Refresh
- Mobile-optimized forms (larger inputs)
- Bottom navigation bar on mobile
- Hamburger menu for navigation

**Progressive Web App (PWA):**
```json
// public/manifest.json
{
  "name": "PGBudget",
  "short_name": "PGBudget",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#3182ce",
  "icons": [...]
}
```

**Service Worker for offline:**
- Cache budget data locally
- Offline transaction entry (sync when online)
- Background sync API
- Push notifications for goals/overspending

#### 6.6 Undo Functionality
**Implementation:**
- Track last 10 actions in session storage
- Actions: Create/edit/delete transaction, assign money, move money
- Undo button in top bar (or Ctrl+Z)
- Redo support (Ctrl+Shift+Z)
- Action history log page

**Database:**
```sql
-- Optional: Persist action history
CREATE TABLE data.action_history (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    action_type text NOT NULL,
    entity_type text NOT NULL, -- 'transaction', 'assignment', etc.
    entity_id bigint NOT NULL,
    old_data jsonb,
    new_data jsonb,
    created_at timestamptz DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);
```

**Phase 6 Deliverables:**
- Migration: Category groups, action history
- UI: Drag-and-drop library integration
- JavaScript: Keyboard handler, search/filter, bulk operations
- Mobile: Responsive improvements, PWA manifest, service worker
- Settings page: Keyboard shortcuts customization
- Tests: UI interaction tests (Playwright)
- Documentation: Advanced features guide

---

## Database Schema Additions Summary

### New Tables

```sql
-- Phase 2: Goals
CREATE TABLE data.category_goals (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8) UNIQUE,
    category_id bigint REFERENCES data.accounts(id) ON DELETE CASCADE,
    goal_type text NOT NULL CHECK (goal_type IN ('monthly_funding', 'target_balance', 'target_by_date')),
    target_amount bigint NOT NULL CHECK (target_amount > 0),
    target_date date,
    repeat_frequency text CHECK (repeat_frequency IN ('weekly', 'monthly', 'yearly')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);

-- Phase 3: Transaction Splits
CREATE TABLE data.transaction_splits (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8) UNIQUE,
    parent_transaction_id bigint REFERENCES data.transactions(id) ON DELETE CASCADE,
    category_id bigint REFERENCES data.accounts(id),
    amount bigint NOT NULL CHECK (amount > 0),
    memo text,
    created_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);

-- Phase 3: Recurring Transactions
CREATE TABLE data.recurring_transactions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8) UNIQUE,
    ledger_id bigint REFERENCES data.ledgers(id) ON DELETE CASCADE,
    description text NOT NULL,
    amount bigint NOT NULL,
    frequency text NOT NULL CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'yearly')),
    next_date date NOT NULL,
    end_date date,
    account_id bigint REFERENCES data.accounts(id),
    category_id bigint REFERENCES data.accounts(id),
    transaction_type text NOT NULL CHECK (transaction_type IN ('inflow', 'outflow')),
    auto_create boolean DEFAULT false,
    enabled boolean DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);

-- Phase 3: Payees
CREATE TABLE data.payees (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8) UNIQUE,
    name text NOT NULL,
    default_category_id bigint REFERENCES data.accounts(id),
    auto_categorize boolean DEFAULT true,
    merged_into_id bigint REFERENCES data.payees(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),
    CONSTRAINT payees_name_user_unique UNIQUE (name, user_data)
);

-- Phase 4: Reconciliations
CREATE TABLE data.reconciliations (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8) UNIQUE,
    account_id bigint REFERENCES data.accounts(id),
    reconciliation_date date NOT NULL,
    statement_balance bigint NOT NULL,
    pgbudget_balance bigint NOT NULL,
    difference bigint NOT NULL,
    adjustment_transaction_id bigint REFERENCES data.transactions(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);

-- Phase 5: Age of Money Cache
CREATE TABLE data.age_of_money_cache (
    ledger_id bigint REFERENCES data.ledgers(id),
    calculation_date date,
    age_days integer,
    user_data text NOT NULL DEFAULT utils.get_user(),
    PRIMARY KEY (ledger_id, calculation_date)
);

-- Phase 6: Action History (optional)
CREATE TABLE data.action_history (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    action_type text NOT NULL,
    entity_type text NOT NULL,
    entity_id bigint NOT NULL,
    old_data jsonb,
    new_data jsonb,
    created_at timestamptz DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user()
);
```

### Table Modifications

```sql
-- Phase 3: Link payees to transactions
ALTER TABLE data.transactions ADD COLUMN payee_id bigint REFERENCES data.payees(id);

-- Phase 4: Add cleared status for reconciliation
ALTER TABLE data.transactions ADD COLUMN cleared_status text DEFAULT 'uncleared'
    CHECK (cleared_status IN ('uncleared', 'cleared', 'reconciled'));

-- Phase 6: Category hierarchy
ALTER TABLE data.accounts ADD COLUMN parent_category_id bigint REFERENCES data.accounts(id);
ALTER TABLE data.accounts ADD COLUMN sort_order integer DEFAULT 0;
ALTER TABLE data.accounts ADD COLUMN is_group boolean DEFAULT false;
```

---

## API Functions to Add

### Phase 1: Budget Workflow
- `api.move_between_categories(ledger_uuid, from_category_uuid, to_category_uuid, amount, date, description)` → transaction_uuid
- `api.quick_assign(ledger_uuid, category_uuid, amount)` → Uses today's date, auto-description
- `api.cover_overspending(ledger_uuid, overspent_category_uuid, source_category_uuid)` → transaction_uuid

### Phase 2: Goals
- `api.create_category_goal(category_uuid, goal_type, target_amount, target_date, frequency)` → goal_uuid
- `api.update_category_goal(goal_uuid, ...)` → goal_uuid
- `api.delete_category_goal(goal_uuid)` → boolean
- `api.get_category_goals(ledger_uuid)` → table of goals
- `api.get_category_goal_status(category_uuid, month)` → progress data
- `api.get_underfunded_goals(ledger_uuid, month)` → table of underfunded goals

### Phase 3: Transactions
- `api.add_split_transaction(ledger_uuid, date, description, account_uuid, splits[])` → transaction_uuid
- `api.add_recurring_transaction(...)` → recurring_uuid
- `api.create_from_recurring(recurring_uuid)` → transaction_uuid
- `api.update_recurring_transaction(recurring_uuid, ...)` → recurring_uuid
- `api.add_account_transfer(ledger_uuid, from_account_uuid, to_account_uuid, amount, date, memo)` → transaction_uuid
- `api.create_payee(name, default_category_uuid)` → payee_uuid
- `api.update_payee(payee_uuid, name, default_category_uuid)` → payee_uuid
- `api.merge_payees(source_payee_uuid, target_payee_uuid)` → boolean

### Phase 4: Credit Cards
- `api.reconcile_account(account_uuid, statement_balance, statement_date, cleared_transaction_uuids[])` → reconciliation_uuid
- `api.create_adjustment_transaction(account_uuid, amount, date)` → transaction_uuid

### Phase 5: Reports
- `api.get_spending_by_category(ledger_uuid, start_date, end_date)` → category spending data
- `api.get_income_vs_expense(ledger_uuid, start_date, end_date)` → monthly comparison
- `api.get_net_worth_over_time(ledger_uuid, start_date, end_date)` → time series
- `api.get_category_spending_trend(category_uuid, months)` → monthly spending array
- `api.get_age_of_money(ledger_uuid)` → integer (days)
- `api.export_transactions_csv(ledger_uuid, start_date, end_date)` → CSV data

### Phase 6: Advanced
- `api.create_category_group(ledger_uuid, name)` → group_uuid
- `api.move_category_to_group(category_uuid, group_uuid)` → boolean
- `api.reorder_categories(category_uuid_array)` → boolean
- `api.bulk_categorize(transaction_uuids[], category_uuid)` → integer (count updated)
- `api.bulk_delete_transactions(transaction_uuids[])` → integer (count deleted)

---

## Implementation Priority Matrix

### Immediate Impact (Start Here) ⚡
1. **Inline budget editing** - Removes biggest friction point
2. **Move money button** - Enables YNAB Rule 3
3. **Overspending visual warnings** - Prevents user confusion
4. **Prominent "Ready to Assign"** - Focus on Rule 1

### High Value (Next) 🎯
5. **Monthly funding goals** - Core YNAB differentiator (Rule 2)
6. **Quick-add transaction modal** - Reduces clicks significantly
7. **Account transfers simplified** - Common operation
8. **Credit card workflow** - Major pain point

### Medium Value 📊
9. **Category groups** - Organization at scale
10. **Spending reports** - Understanding behavior
11. **Recurring transactions** - Reduces repetitive work
12. **Split transactions** - Flexibility for complex scenarios

### Nice to Have ✨
13. **Keyboard shortcuts** - Power user efficiency
14. **Search/filter** - Finding data
15. **Age of Money** - Advanced metric
16. **Mobile PWA** - Offline access

---

## Success Metrics

### User Engagement
- **Time to complete monthly budget:** Target <5 minutes (vs current ~15 min)
- **Ready to Assign dollars remaining:** Target $0 (100% assigned)
- **Category coverage:** % categories with assigned budget (target >90%)
- **Daily active usage:** Users logging in daily vs weekly

### Feature Adoption
- **Inline editing usage:** % budgets assigned via inline vs separate page
- **Goals set:** % users with at least one goal (target >50%)
- **Categorization rate:** % transactions categorized (not "Unassigned") (target >95%)
- **Move money usage:** Times used per user per month (target >5)

### Financial Outcomes
- **Zero-balance achievement:** % users reaching $0 "Ready to Assign"
- **Overspending occurrence:** % categories with negative balance (target <10%)
- **Goal achievement:** % goals met on time
- **Budget consistency:** % users budgeting monthly

---

## Technical Considerations

### Performance
- **Inline editing:** AJAX requests must complete in <200ms
- **Budget status calculations:** Cache per ledger, invalidate on transaction
- **Month view queries:** Pre-aggregate monthly data in materialized view
- **Charts/Reports:** Lazy load, render on scroll into view
- **API response time:** Target <500ms for all endpoints

### Caching Strategy
```sql
-- Materialized view for budget status (refresh on transaction)
CREATE MATERIALIZED VIEW data.budget_status_cache AS
SELECT ledger_id, month, category_id, budgeted, activity, balance
FROM ... -- budget status calculation
WITH DATA;

-- Refresh trigger
CREATE TRIGGER refresh_budget_cache
AFTER INSERT OR UPDATE OR DELETE ON data.transactions
FOR EACH ROW EXECUTE FUNCTION utils.refresh_budget_cache();
```

### Security
- **All API endpoints:** Validate `user_data` context via RLS
- **CSRF protection:** Token on all POST forms
- **Input sanitization:** On all user inputs (already using `sanitizeInput()`)
- **SQL injection prevention:** Prepared statements only (already using PDO)
- **XSS prevention:** Escape all output (already using `htmlspecialchars()`)

### Testing Strategy
- **Unit tests:** All new API functions (Go tests in `main_test.go`)
- **Integration tests:** Budget workflows end-to-end
- **UI tests:** Critical paths with Playwright or Selenium
- **Performance tests:** Budget dashboard load time <1s
- **Security tests:** SQL injection, XSS, CSRF attempts

### Backward Compatibility
- **Existing API functions:** No breaking changes
- **New features:** Purely additive
- **Database migrations:** Include rollback (down) scripts
- **Feature flags:** Gradual rollout, A/B testing capability

---

## Migration & Deployment Strategy

### Incremental Rollout
1. **Alpha testing** - Internal team tests each phase
2. **Beta testing** - Opt-in users test new features
3. **Feature flags** - Enable/disable features per user
4. **Gradual rollout** - 10% → 50% → 100% of users
5. **Monitoring** - Error rates, performance metrics

### Database Migration Safety
```bash
# Before each phase deployment
1. Backup database
2. Test migration on staging
3. Test rollback on staging
4. Deploy migration to production
5. Verify data integrity
6. Monitor for errors
7. If issues: Rollback immediately
```

### Rollback Plan
- Each migration includes `-- +goose Down` section
- Keep migrations atomic (one logical change per file)
- Test rollback before deploying
- Document rollback procedures

---

## Development Workflow

### Branch Strategy
```
main (production)
├── develop (integration)
│   ├── feature/inline-budget-edit
│   ├── feature/move-money
│   ├── feature/goals
│   └── feature/split-transactions
```

### Code Review Checklist
- [ ] Follows CONVENTIONS.md (SQL lowercase, three-schema pattern)
- [ ] Includes migration with up/down
- [ ] API function has corresponding utils function
- [ ] RLS policies added to new tables
- [ ] Go tests for database functions
- [ ] User-facing error messages
- [ ] Documentation updated
- [ ] No console.log() in production JS
- [ ] Responsive design tested

### Testing Before Merge
1. Run all Go tests: `go test -v ./...`
2. Test migration up: `goose up`
3. Test migration down: `goose down`
4. Manual UI testing
5. Check PostgreSQL logs for errors

---

## Documentation Updates Needed

### For Each Phase
- **README.md:** Add new features to feature list
- **ARCHITECTURE.md:** Document new tables, functions, patterns
- **API Reference:** Document new API functions with examples
- **User Guide:** Step-by-step for new features
- **CHANGELOG.md:** Record changes with version numbers

### New Documentation Files
- `docs/GOALS_GUIDE.md` - How to use goals
- `docs/CREDIT_CARDS.md` - CC workflow explanation
- `docs/REPORTS_GUIDE.md` - Understanding reports
- `docs/KEYBOARD_SHORTCUTS.md` - Shortcut reference
- `docs/MIGRATION_GUIDE.md` - Upgrading between versions

---

## Estimated Timelines

### Phase-by-Phase
| Phase | Features | Weeks | Developer-Weeks |
|-------|----------|-------|-----------------|
| Phase 1 | Core workflow improvements | 4-6 | 1 dev × 4-6 weeks |
| Phase 2 | Goals & planning | 6-8 | 1 dev × 6-8 weeks |
| Phase 3 | Transaction enhancements | 4-6 | 1 dev × 4-6 weeks |
| Phase 4 | Credit card workflow | 3-4 | 1 dev × 3-4 weeks |
| Phase 5 | Reports & analytics | 6-8 | 1 dev × 6-8 weeks |
| Phase 6 | UX polish & advanced | 4-6 | 1 dev × 4-6 weeks |
| **Total** | **All phases** | **27-38** | **27-38 weeks** |

### Accelerated Timeline (2 developers)
- **Phase 1 + 2:** 8 weeks (parallel work)
- **Phase 3 + 4:** 6 weeks (parallel work)
- **Phase 5 + 6:** 8 weeks (parallel work)
- **Total:** ~22 weeks (5.5 months)

### MVP to Match YNAB Core (Phases 1-3 only)
- **Timeline:** 14-20 weeks (3.5-5 months)
- **Delivers:** 80% of YNAB's essential value
- **Recommendation:** Start here, gather feedback before continuing

---

## Quick Wins (4-Week Sprint)

If time is limited, focus on these high-impact, low-effort improvements:

### Week 1-2: Visual & UX Improvements
- ✅ Prominent "Ready to Assign" banner at top of budget
- ✅ Color coding for categories (green/yellow/red)
- ✅ Overspending visual indicators (red background)
- ✅ Quick-add transaction button on budget screen
- ✅ Improved month navigation (prev/next arrows)

### Week 3-4: Move Money Feature
- ✅ `api.move_between_categories()` function
- ✅ "Move Money" modal UI
- ✅ Balance validation
- ✅ Integration into budget dashboard

**Impact:** These changes alone will make PGBudget feel 10× more polished and significantly improve the daily workflow.

---

## Conclusion

PGBudget has excellent architectural foundations with proper double-entry accounting and zero-sum budgeting principles. The enhancements outlined above will bring it to feature parity with YNAB while maintaining its core strengths:

### PGBudget Advantages (Keep These!)
- ✅ **Open source** - Users own their data
- ✅ **Self-hosted** - Complete privacy control
- ✅ **PostgreSQL-based** - Enterprise-grade reliability
- ✅ **Strong data model** - Proper accounting principles
- ✅ **Multi-tenant** - Scales to many users
- ✅ **Extensible** - Three-schema architecture for flexibility

### After Enhancements
- ✅ **YNAB-level UX** - Smooth, intuitive workflows
- ✅ **Goal-based budgeting** - Plan for irregular expenses
- ✅ **Credit card mastery** - Easy CC management
- ✅ **Rich reporting** - Understand spending patterns
- ✅ **Mobile-friendly** - Budget on the go

**Recommended Approach:**
1. **Start with Quick Wins (4 weeks)** - Immediate UX improvements
2. **Phase 1 (4-6 weeks)** - Core workflow enhancements
3. **Gather user feedback** - Validate before continuing
4. **Phase 2-3 (10-14 weeks)** - Goals and transactions
5. **Re-evaluate** - Assess needs before final phases

**Total MVP Timeline:** 18-24 weeks to match YNAB core functionality

This roadmap provides a clear path to making PGBudget a compelling open-source alternative to YNAB while maintaining its technical excellence. 🚀
