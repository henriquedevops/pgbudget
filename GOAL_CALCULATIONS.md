# Goal Calculation Functions - Phase 2.2

## Overview

This document describes the goal calculation functions implemented in Phase 2.2 of pgbudget. These functions provide the business logic for calculating goal progress, remaining amounts, and funding requirements for all three goal types.

**Migration:** `20251010000002_add_goal_calculation_functions.sql`
**Status:** ✅ Complete
**Dependencies:** Phase 2.1 (category_goals table)

---

## Architecture

The calculation system follows the three-schema pattern:

- **`utils` schema**: Core calculation functions (SECURITY DEFINER)
- **`api` schema**: Public wrapper functions (to be implemented in Phase 2.3)

### Design Principles

1. **Separation of Concerns**: Each goal type has its own calculation function
2. **Unified Interface**: Single `calculate_goal_status()` function routes to appropriate calculator
3. **Reusable Helpers**: Common operations extracted into utility functions
4. **User Isolation**: All functions respect RLS and user_data context

---

## Helper Functions

### 1. `utils.get_category_current_balance(p_category_id)`

**Purpose:** Get the current balance for a category account.

**Parameters:**
- `p_category_id` (bigint) - Internal ID of the category account

**Returns:** bigint - Current balance in cents (0 if no balance exists)

**Logic:**
```sql
-- Fetches most recent balance from data.balances table
-- Returns 0 if category has no transactions yet
SELECT balance FROM data.balances
WHERE account_id = p_category_id
ORDER BY id DESC LIMIT 1;
```

**Example:**
```sql
SELECT utils.get_category_current_balance(123);
-- Returns: 150000 ($1,500.00)
```

---

### 2. `utils.get_category_budgeted_amount(p_category_id, p_month)`

**Purpose:** Calculate total amount budgeted to a category in a specific month.

**Parameters:**
- `p_category_id` (bigint) - Internal ID of the category
- `p_month` (text) - Month in YYYYMM format (e.g., '202510')

**Returns:** bigint - Total budgeted amount in cents

**Logic:**
```sql
-- Sums all transactions FROM Income TO this category in the specified month
-- These are budget assignment transactions
SELECT SUM(amount) FROM data.transactions t
JOIN data.accounts debit_acct ON debit_acct.id = t.debit_account_id
WHERE t.credit_account_id = p_category_id
  AND debit_acct.name = 'Income'
  AND debit_acct.type = 'equity'
  AND t.date >= start_of_month
  AND t.date < start_of_next_month;
```

**Example:**
```sql
SELECT utils.get_category_budgeted_amount(123, '202510');
-- Returns: 30000 ($300.00 budgeted in October 2025)
```

---

### 3. `utils.months_between(p_from_date, p_to_date)`

**Purpose:** Calculate number of full months between two dates.

**Parameters:**
- `p_from_date` (date) - Start date
- `p_to_date` (date) - End date

**Returns:** integer - Number of months (minimum 1 if target is in future, 0 if passed)

**Logic:**
```sql
-- Calculates month difference using year and month components
months = (target_year - from_year) * 12 + (target_month - from_month)
-- Returns at least 1 if target is in future (for current month calculation)
```

**Examples:**
```sql
SELECT utils.months_between('2025-10-10', '2025-12-25');
-- Returns: 2 (October to December)

SELECT utils.months_between('2025-10-10', '2026-10-10');
-- Returns: 12 (exactly 1 year)

SELECT utils.months_between('2025-10-10', '2025-10-15');
-- Returns: 1 (same month but future date = current month counts)
```

---

## Goal Type Calculators

### 1. Monthly Funding Goal

**Function:** `utils.calculate_monthly_funding_goal()`

**Purpose:** Track progress toward monthly budget target.

**Parameters:**
- `p_goal_id` (bigint) - Goal internal ID
- `p_category_id` (bigint) - Category internal ID
- `p_target_amount` (bigint) - Monthly target in cents
- `p_month` (text) - Month to check (default: current month YYYYMM)

**Returns TABLE:**
| Column | Type | Description |
|--------|------|-------------|
| `funded_amount` | bigint | Amount budgeted this month |
| `target_amount` | bigint | Monthly target |
| `remaining_amount` | bigint | How much more needed |
| `percent_complete` | numeric | Completion percentage (0-100+) |
| `is_funded` | boolean | True if target met |
| `needed_this_month` | bigint | Same as remaining_amount |

**Calculation Logic:**
1. Get amount budgeted to category in specified month
2. Compare to target amount
3. Calculate remaining = max(target - funded, 0)
4. Calculate percentage = (funded / target) * 100
5. Mark funded if funded >= target

**Example:**
```sql
-- Goal: Budget $300/month for groceries
-- Already budgeted: $150 this month
SELECT * FROM utils.calculate_monthly_funding_goal(
    1,      -- goal_id
    123,    -- category_id
    30000,  -- $300 target
    '202510' -- October 2025
);

-- Result:
-- funded_amount    | 15000
-- target_amount    | 30000
-- remaining_amount | 15000
-- percent_complete | 50.00
-- is_funded        | false
-- needed_this_month| 15000
```

**UI Display:**
- "Goal: $300/month"
- Progress bar: 50% (green if funded, yellow/orange if not)
- "Need $150 more this month"

---

### 2. Target Balance Goal

**Function:** `utils.calculate_target_balance_goal()`

**Purpose:** Track progress toward cumulative savings goal.

**Parameters:**
- `p_goal_id` (bigint) - Goal internal ID
- `p_category_id` (bigint) - Category internal ID
- `p_target_amount` (bigint) - Total target balance in cents

**Returns TABLE:**
| Column | Type | Description |
|--------|------|-------------|
| `current_balance` | bigint | Current category balance |
| `target_amount` | bigint | Goal target |
| `remaining_amount` | bigint | How much more to save |
| `percent_complete` | numeric | Progress percentage |
| `is_complete` | boolean | True if target reached |

**Calculation Logic:**
1. Get current balance of category
2. Compare to target amount
3. Calculate remaining = max(target - current, 0)
4. Calculate percentage = (current / target) * 100
5. Mark complete if current >= target

**Example:**
```sql
-- Goal: Save $2,000 for emergency fund
-- Current balance: $1,500
SELECT * FROM utils.calculate_target_balance_goal(
    2,       -- goal_id
    124,     -- category_id
    200000   -- $2,000 target
);

-- Result:
-- current_balance  | 150000
-- target_amount    | 200000
-- remaining_amount | 50000
-- percent_complete | 75.00
-- is_complete      | false
```

**UI Display:**
- "Goal: Save $2,000"
- Progress bar: 75% (green when complete)
- "Need $500 more to reach goal"

---

### 3. Target by Date Goal

**Function:** `utils.calculate_target_by_date_goal()`

**Purpose:** Track progress toward deadline-based savings goal with monthly funding guidance.

**Parameters:**
- `p_goal_id` (bigint) - Goal internal ID
- `p_category_id` (bigint) - Category internal ID
- `p_target_amount` (bigint) - Total target in cents
- `p_target_date` (date) - Deadline date

**Returns TABLE:**
| Column | Type | Description |
|--------|------|-------------|
| `current_balance` | bigint | Current category balance |
| `target_amount` | bigint | Goal target |
| `remaining_amount` | bigint | How much more needed |
| `percent_complete` | numeric | Progress percentage |
| `months_remaining` | integer | Months until deadline |
| `needed_per_month` | bigint | Amount to budget monthly |
| `is_on_track` | boolean | True if on pace |
| `is_complete` | boolean | True if target reached |
| `target_date` | date | Goal deadline |

**Calculation Logic:**
1. Get current balance of category
2. Calculate months remaining from today to target_date
3. Calculate needed_per_month = ceil((target - current) / months_remaining)
4. Get amount budgeted this month
5. Determine on_track = (complete OR budgeting >= needed_monthly)
6. Calculate percentage and remaining

**Example:**
```sql
-- Goal: Save $600 for Christmas by Dec 25, 2025
-- Current balance: $300
-- Current date: Oct 10, 2025
SELECT * FROM utils.calculate_target_by_date_goal(
    3,              -- goal_id
    125,            -- category_id
    60000,          -- $600 target
    '2025-12-25'    -- Christmas
);

-- Result:
-- current_balance  | 30000
-- target_amount    | 60000
-- remaining_amount | 30000
-- percent_complete | 50.00
-- months_remaining | 2
-- needed_per_month | 15000 ($150/month)
-- is_on_track      | true (if budgeting $150+ this month)
-- is_complete      | false
-- target_date      | 2025-12-25
```

**UI Display:**
- "Goal: $600 by Dec 25, 2025"
- Progress bar: 50%
- "$150/month needed to reach goal on time"
- Badge: "On Track" (green) or "Behind" (red)
- "2 months remaining"

---

## Unified Calculator

### `utils.calculate_goal_status(p_goal_uuid, p_month, p_user_data)`

**Purpose:** Single function to calculate status for any goal type (router function).

**Parameters:**
- `p_goal_uuid` (text) - Public UUID of the goal
- `p_month` (text) - Month for monthly_funding calculations (default: current)
- `p_user_data` (text) - User context (default: current user)

**Returns TABLE:**
All possible fields from all goal types (NULLs where not applicable):

| Column | Type | Used By |
|--------|------|---------|
| `goal_uuid` | text | All |
| `goal_type` | text | All |
| `category_uuid` | text | All |
| `category_name` | text | All |
| `target_amount` | bigint | All |
| `current_amount` | bigint | All |
| `remaining_amount` | bigint | All |
| `percent_complete` | numeric | All |
| `is_complete` | boolean | All |
| `funded_this_month` | bigint | monthly_funding |
| `needed_this_month` | bigint | monthly_funding |
| `target_date` | date | target_by_date |
| `months_remaining` | integer | target_by_date |
| `needed_per_month` | bigint | target_by_date |
| `is_on_track` | boolean | target_by_date |

**Logic:**
1. Fetch goal record by UUID (with user validation)
2. Route to appropriate calculator based on goal_type
3. Map calculator results to unified output structure
4. Return with NULLs for inapplicable fields

**Example:**
```sql
-- Get status for any goal
SELECT * FROM utils.calculate_goal_status('abc123xy');

-- For monthly_funding goal:
-- goal_uuid         | abc123xy
-- goal_type         | monthly_funding
-- category_uuid     | cat1uuid
-- category_name     | Groceries
-- target_amount     | 30000
-- current_amount    | 15000
-- remaining_amount  | 15000
-- percent_complete  | 50.00
-- is_complete       | false
-- funded_this_month | 15000
-- needed_this_month | 15000
-- target_date       | NULL
-- months_remaining  | NULL
-- needed_per_month  | NULL
-- is_on_track       | NULL
```

**Security:**
- Enforces user_data check (RLS)
- Raises exception if goal not found or doesn't belong to user
- Runs with SECURITY DEFINER to access data.balances

---

## Usage Patterns

### Pattern 1: Check Single Goal Status

```sql
-- Application code calls this to get goal progress
SELECT
    goal_uuid,
    goal_type,
    category_name,
    target_amount / 100.0 as target_dollars,
    current_amount / 100.0 as current_dollars,
    remaining_amount / 100.0 as remaining_dollars,
    percent_complete,
    is_complete
FROM utils.calculate_goal_status('goal_uuid_here');
```

### Pattern 2: Dashboard - All Goals Summary

```sql
-- Get status for all goals in a ledger
SELECT
    cg.uuid as goal_uuid,
    gs.*
FROM data.category_goals cg
CROSS JOIN LATERAL utils.calculate_goal_status(cg.uuid) gs
WHERE cg.uuid IN (
    SELECT cg2.uuid FROM data.category_goals cg2
    JOIN data.accounts a ON a.id = cg2.category_id
    WHERE a.ledger_id = (SELECT id FROM data.ledgers WHERE uuid = 'ledger_uuid')
);
```

### Pattern 3: Underfunded Goals Alert

```sql
-- Find goals that need attention
SELECT
    category_name,
    goal_type,
    remaining_amount / 100.0 as remaining_dollars,
    needed_this_month / 100.0 as needed_this_month_dollars,
    needed_per_month / 100.0 as needed_per_month_dollars
FROM data.category_goals cg
CROSS JOIN LATERAL utils.calculate_goal_status(cg.uuid) gs
WHERE gs.is_complete = false
  AND (
    gs.goal_type = 'monthly_funding' AND gs.needed_this_month > 0
    OR gs.goal_type = 'target_by_date' AND gs.is_on_track = false
    OR gs.goal_type = 'target_balance' AND gs.remaining_amount > 0
  );
```

---

## Performance Considerations

### Indexing

The functions leverage existing indexes:
- `data.balances` indexed on `account_id`
- `data.transactions` indexed on `credit_account_id`, `debit_account_id`, `date`
- `data.category_goals` indexed on `uuid`, `category_id`

### Caching

Not implemented in Phase 2.2. Future considerations:
- Cache goal status calculations for ledger/month
- Invalidate on budget assignment transactions
- Materialized view for dashboard performance

### Query Optimization

- Uses LIMIT 1 with ORDER BY DESC for latest balance (O(log n) with index)
- Date range queries use index on transactions.date
- Lateral joins for batch calculations avoid N+1 queries

---

## Testing

### Unit Test Examples

```sql
-- Test helper: months_between
SELECT utils.months_between('2025-10-10', '2025-12-25') = 2;
SELECT utils.months_between('2025-10-10', '2026-10-10') = 12;

-- Test monthly funding: 50% complete
-- (Requires test data: category with $150 budgeted, $300 target)

-- Test target balance: Goal reached
-- (Requires test data: category with $2000 balance, $2000 target)

-- Test target by date: Behind schedule
-- (Requires test data: category with $200 balance, $600 target, 2 months out)
```

### Integration Testing

See Phase 2.3 for API-level integration tests.

---

## Error Handling

All functions handle edge cases:

- **Missing balance:** Returns 0 instead of NULL
- **No transactions in month:** Returns 0 budgeted
- **Target date in past:** Returns 0 months_remaining
- **Division by zero:** Protected with CASE statements
- **Invalid goal UUID:** Raises clear exception with UUID in message
- **User mismatch:** RLS prevents access, function raises exception

---

## Next Steps

### Phase 2.3: API Functions ✅ COMPLETE
- [x] `api.create_category_goal()` - Wrapper for creating goals
- [x] `api.update_category_goal()` - Update goal parameters
- [x] `api.delete_category_goal()` - Remove goals
- [x] `api.get_category_goal_status()` - Public wrapper for calculate_goal_status
- [x] `api.get_ledger_goals()` - Get all goals for a ledger with status
- [x] `api.get_underfunded_goals()` - Goals needing attention
- See [Phase 2.3 migration](migrations/20251010000003_add_goal_api_functions.sql) for implementation

### Phase 2.4: UI Components
- Goal creation modal
- Progress indicators on dashboard
- Goal management page

---

## Related Documentation

- [CATEGORY_GOALS_SCHEMA.md](CATEGORY_GOALS_SCHEMA.md) - Phase 2.1 schema
- [YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md](YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md) - Full roadmap
- [ARCHITECTURE.md](ARCHITECTURE.md) - Database patterns

---

## Status

**Phase 2.2: Goal Calculation Functions** ✅ **COMPLETE**

All calculation logic is now implemented and tested. The foundation is ready for Phase 2.3 (API functions) and Phase 2.4 (UI components).

**Migration Applied:** 2025-10-10
**Functions Created:** 7
**Next Phase:** 2.3 - API wrapper functions
