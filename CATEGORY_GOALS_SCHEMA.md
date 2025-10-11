# Category Goals Schema - Phase 2.1

## Overview

This document describes the database schema implementation for category goals in pgbudget. This is the first component of **Phase 2: Goals & Planning**, which implements YNAB Rule 2: "Embrace Your True Expenses."

**Migration:** `20251010000001_add_category_goals_table.sql`
**Status:** ✅ Schema Complete (API functions pending in Phase 2.2-2.5)

---

## Purpose

Category goals enable users to:
- Plan for irregular expenses (insurance, gifts, vacations)
- Set monthly funding targets for recurring expenses
- Build savings toward specific financial goals
- Track progress toward time-based targets

This helps users budget proactively rather than reactively, reducing financial stress and overspending.

---

## Table Structure

### `data.category_goals`

```sql
create table data.category_goals
(
    -- Identity
    id               bigint generated always as identity primary key,
    uuid             text        not null default utils.nanoid(8),

    -- Timestamps
    created_at       timestamptz not null default current_timestamp,
    updated_at       timestamptz not null default current_timestamp,

    -- Goal configuration
    category_id      bigint      not null,
    goal_type        text        not null,
    target_amount    bigint      not null,
    target_date      date,
    repeat_frequency text,

    -- User ownership
    user_data        text        not null default utils.get_user()
);
```

**Indexes:**
- `category_goals_pkey` - Primary key on `id`
- `category_goals_uuid_unique` - Unique constraint on `uuid`
- `category_goals_one_per_category` - Unique constraint on `category_id`
- `category_goals_category_id_idx` - Index for category lookups
- `category_goals_user_data_idx` - Index for user filtering
- `category_goals_goal_type_idx` - Index for goal type queries

---

## Goal Types

### 1. Monthly Funding (`monthly_funding`)

**Purpose:** Budget a fixed amount every month for recurring expenses.

**Behavior:**
- Target amount is the desired monthly budget
- Progress resets at the start of each month
- Ideal for: groceries, gas, utilities, subscriptions

**Example:**
```sql
-- Goal: Budget $300/month for groceries
{
  "goal_type": "monthly_funding",
  "target_amount": 30000,  -- $300.00 in cents
  "target_date": null,
  "repeat_frequency": "monthly"
}
```

**UI Display:**
- "Goal: $300/month"
- Progress bar: $150 of $300 budgeted this month (50%)

---

### 2. Target Balance (`target_balance`)

**Purpose:** Save up to a specific total amount over time.

**Behavior:**
- Target amount is the total cumulative goal
- Progress is cumulative (doesn't reset monthly)
- Tracks current category balance vs target
- Ideal for: emergency fund, vacation savings, large purchase fund

**Example:**
```sql
-- Goal: Save $2,000 for emergency fund
{
  "goal_type": "target_balance",
  "target_amount": 200000,  -- $2,000.00 in cents
  "target_date": null,
  "repeat_frequency": null
}
```

**UI Display:**
- "Goal: Save $2,000"
- Progress bar: $1,500 of $2,000 saved (75%)
- "Need $500 more to reach goal"

---

### 3. Target by Date (`target_by_date`)

**Purpose:** Reach a target amount by a specific deadline.

**Behavior:**
- Target amount is the total needed by the target date
- Calculates monthly needed amount: `(target - current) / months_remaining`
- Shows if on track based on current progress
- Ideal for: Christmas gifts, annual insurance, planned events

**Example:**
```sql
-- Goal: Save $600 for Christmas by Dec 2025
{
  "goal_type": "target_by_date",
  "target_amount": 60000,  -- $600.00 in cents
  "target_date": "2025-12-25",
  "repeat_frequency": "yearly"
}
```

**UI Display:**
- "Goal: $600 by Dec 2025"
- "$50/month needed to reach goal on time"
- Progress bar: $300 of $600 saved (50%)
- Warning: "Need to increase funding by $25/month to stay on track"

---

## Constraints

### Business Rules (Enforced by Check Constraints)

1. **One Goal Per Category:**
   ```sql
   constraint category_goals_one_per_category unique (category_id)
   ```
   - Each category can have at most one active goal
   - To change a goal, update the existing record or delete and recreate

2. **Valid Goal Types:**
   ```sql
   constraint category_goals_goal_type_check
     check (goal_type in ('monthly_funding', 'target_balance', 'target_by_date'))
   ```

3. **Positive Target Amount:**
   ```sql
   constraint category_goals_target_amount_check check (target_amount > 0)
   ```
   - All goals must have a positive target amount
   - Amount is stored in cents (e.g., $100.00 = 10000)

4. **Target Date Required for `target_by_date`:**
   ```sql
   constraint category_goals_target_date_required_check check (
     (goal_type = 'target_by_date' and target_date is not null) or
     (goal_type != 'target_by_date')
   )
   ```
   - `target_by_date` goals MUST have a `target_date`
   - Other goal types may optionally have a date (for reference)

5. **Valid Repeat Frequency:**
   ```sql
   constraint category_goals_repeat_frequency_check
     check (repeat_frequency is null or repeat_frequency in ('weekly', 'monthly', 'yearly'))
   ```

### Referential Integrity

1. **Category Must Exist:**
   ```sql
   constraint category_goals_category_fk foreign key (category_id)
     references data.accounts (id) on delete cascade
   ```
   - Goals can only be created for existing categories
   - When a category is deleted, its goal is automatically deleted (`CASCADE`)

2. **Category Must Be Equity Type:**
   - Not enforced by constraint in this table
   - Should be validated in API/utils functions
   - Only budget categories (equity accounts) should have goals

---

## Row Level Security (RLS)

**Policy:** `category_goals_policy`

```sql
alter table data.category_goals enable row level security;

create policy category_goals_policy on data.category_goals
  using (user_data = utils.get_user())
  with check (user_data = utils.get_user());
```

**Enforcement:**
- Users can only see their own goals
- Users can only create/update/delete their own goals
- Automatic enforcement via `user_data` column (defaults to `utils.get_user()`)

---

## Triggers

### Updated At Trigger

```sql
create trigger category_goals_updated_at_trigger
  before update on data.category_goals
  for each row
  execute procedure utils.set_updated_at_fn();
```

**Purpose:** Automatically update `updated_at` timestamp on any row modification.

---

## Usage Examples

### Creating Goals

```sql
-- Example 1: Monthly funding goal for groceries
insert into data.category_goals (category_id, goal_type, target_amount, repeat_frequency)
values (
  (select id from data.accounts where uuid = 'cat1uuid'),
  'monthly_funding',
  30000,  -- $300/month
  'monthly'
);

-- Example 2: Emergency fund target balance
insert into data.category_goals (category_id, goal_type, target_amount)
values (
  (select id from data.accounts where uuid = 'emergencycat'),
  'target_balance',
  500000  -- $5,000 total
);

-- Example 3: Christmas by date
insert into data.category_goals (category_id, goal_type, target_amount, target_date, repeat_frequency)
values (
  (select id from data.accounts where uuid = 'christmascat'),
  'target_by_date',
  100000,  -- $1,000
  '2025-12-25',
  'yearly'
);
```

### Querying Goals

```sql
-- Get all goals for a user
select
  cg.uuid,
  a.name as category_name,
  cg.goal_type,
  cg.target_amount,
  cg.target_date,
  cg.repeat_frequency
from data.category_goals cg
join data.accounts a on a.id = cg.category_id
where cg.user_data = utils.get_user();

-- Get goals by type
select * from data.category_goals
where goal_type = 'monthly_funding'
  and user_data = utils.get_user();
```

---

## Next Steps (Remaining Phase 2 Components)

### Phase 2.2: Goal Types Implementation ✅ COMPLETE
- [x] Goal calculation logic
- [x] Progress tracking functions
- [x] Monthly vs cumulative calculations
- See [GOAL_CALCULATIONS.md](GOAL_CALCULATIONS.md) for details

### Phase 2.3: Goal API Functions ✅ COMPLETE
- [x] `api.create_category_goal()`
- [x] `api.update_category_goal()`
- [x] `api.delete_category_goal()`
- [x] `api.get_category_goal_status()`
- [x] `api.get_ledger_goals()`
- [x] `api.get_underfunded_goals()`
- Migration: `20251010000003_add_goal_api_functions.sql`

### Phase 2.4: Goal UI Components ✅ COMPLETE
- [x] Goal creation modal with type selector
- [x] Goal progress indicators on dashboard
- [x] Goal editing and deletion
- [x] Underfunded goals sidebar
- [x] Integration with budget dashboard
- Files: `public/api/goals.php`, `public/js/goals-manager.js`, `public/css/goals.css`, `public/goals/dashboard-integration.php`

### Phase 2.5: Goal Calculations
- [ ] Calculate funded amount for month/all-time
- [ ] Calculate monthly needed for target-by-date
- [ ] Identify underfunded categories
- [ ] Suggest assignment amounts

---

## Migration Details

**File:** `migrations/20251010000001_add_category_goals_table.sql`

**Up Migration:**
- Creates `data.category_goals` table
- Adds 5 indexes (PK, UUID, category, user, goal type)
- Enables RLS with user isolation policy
- Creates updated_at trigger
- Adds table and column comments

**Down Migration:**
- Drops trigger
- Drops RLS policy
- Drops all indexes
- Drops table

**Testing:**
```bash
# Apply migration
goose -dir migrations postgres "connection-string" up

# Test rollback
goose -dir migrations postgres "connection-string" down

# Re-apply
goose -dir migrations postgres "connection-string" up
```

---

## Design Decisions

### Why One Goal Per Category?

- **Simplicity:** Easier for users to understand (one target per category)
- **UI Clarity:** Single progress bar per category
- **Use Case:** Multiple goals for one category is rare; can create separate categories instead
- **Flexibility:** Can change goal type by updating the single goal

### Why Store Amount in Cents?

- **Consistency:** Matches `data.transactions` and entire pgbudget system
- **Precision:** Avoids floating-point arithmetic errors
- **Performance:** Integer arithmetic is faster than decimal

### Why Allow NULL `target_date` for Non-Date Goals?

- **Flexibility:** Users might want a reference date even for monthly/balance goals
- **Validation:** Required only when necessary (`target_by_date` type)
- **Future Use:** Could enable "soft deadlines" for non-date goals

---

## Related Documentation

- [YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md](YNAB_COMPARISON_AND_ENHANCEMENT_PLAN.md) - Full Phase 2 roadmap
- [ARCHITECTURE.md](ARCHITECTURE.md) - Database architecture patterns
- [SPEC.md](SPEC.md) - Zero-sum budgeting principles
- [CONVENTIONS.md](CONVENTIONS.md) - Code and SQL conventions

---

## Status

**Phase 2.1: Database Schema** ✅ **COMPLETE**

The foundation is now in place for goal-based budgeting. Next steps involve creating the API functions and UI components to make this data actionable for users.

**Migration Applied:** 2025-10-10
**Next Phase:** 2.2 - Goal calculation logic
