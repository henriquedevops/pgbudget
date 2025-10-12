# pgbudget

<!--markdownlint-disable MD033-->
![pgbudget](pgbudget.png)
<!--markdownlint-enable MD033-->

A PostgreSQL-based zero-sum budgeting database engine that implements double-entry accounting principles for personal finance applications.

## What it is

pgbudget provides a complete database foundation for zero-sum budgeting applications (similar to YNAB). It handles the complex accounting logic so you can focus on building user interfaces and application features.

The system implements proper double-entry accounting where every transaction affects two accounts, ensuring mathematical accuracy and providing a complete audit trail. Budget categories function as equity accounts that track your financial intentions, while asset and liability accounts track your actual money.

## Features

- **Complete budgeting workflow**: Create ledgers, accounts, categories, and transactions
- **Zero-sum budgeting**: Every dollar gets assigned a job through proper allocation
- **Double-entry accounting**: All transactions maintain accounting equation balance
- **Multi-tenant support**: Row-level security for multiple users
- **Real-time balance calculations**: On-demand account balance computation
- **Transaction history**: Complete audit trail with running balances
- **Budget status reporting**: Track budgeted vs spent amounts per category
- **Error correction**: Functions to correct or delete transactions with audit trail
- **Credit card workflow**: Automatic payment category creation for credit cards
- **Move money between categories**: Easily reallocate budgeted funds
- **Goals system**: Set monthly funding targets and track progress
- **Recurring transactions**: Schedule and auto-create repeating transactions
- **Split transactions**: Allocate a single transaction across multiple categories
- **Account transfers**: Simplified transfer between asset and liability accounts
- **Payee management**: Track and auto-categorize transactions by payee

## Requirements

- PostgreSQL 12 or higher
- [Goose](https://github.com/pressly/goose) for database migrations

## Setup

### Database Setup

1. Create a PostgreSQL database

2. Run migrations:

    ```bash
    goose -dir migrations postgres "your-connection-string" up
    ```

3. Set user context for each session:

    ```sql
    SELECT set_config('app.current_user_id', 'your_user_id', false);
    ```

### Web Interface Setup (Optional)

The repository includes a PHP-based web interface for interacting with the budget system.

1. **Environment Configuration:**
   - Copy `.env.example` to `.env`
   - Update `.env` with your database credentials:
     ```
     DB_HOST=your_database_host
     DB_PORT=5432
     DB_NAME=your_database_name
     DB_USER=your_database_username
     DB_PASSWORD=your_database_password
     ```

2. **Security Note:** 
   - Never commit `.env` to version control (it's already in `.gitignore`)
   - The `.env` file contains sensitive database credentials
   - Share `.env.example` with other developers, not `.env`

3. **PHP Requirements:**
   - PHP 7.4 or higher
   - PDO PostgreSQL extension
   - A web server (Apache, Nginx, or PHP built-in server)

4. **Testing the setup:**
   ```bash
   php -S localhost:8000 -t public/
   ```
   Then visit `http://localhost:8000` in your browser.

## API Reference

All monetary amounts are stored as integers (cents). $10.00 = 1000 cents.

### Core Functions

**Create a ledger (budget):**

```sql
INSERT INTO api.ledgers (name) VALUES ('My Budget') RETURNING uuid;
```

Example output:

```sql
   uuid   
----------
 d3pOOf6t
```

**Create an account:**

```sql
INSERT INTO api.accounts (ledger_uuid, name, type)
VALUES ('d3pOOf6t', 'Checking', 'asset') RETURNING uuid;
```

Example output:

```sql
   uuid   
----------
 aK9sLp0Q
```

**Create a budget category:**

```sql
SELECT uuid FROM api.add_category('d3pOOf6t', 'Groceries');
```

Example output:

```sql
   uuid   
----------
 mN8xPqR3
```

**Add income:**

```sql
SELECT api.add_transaction(
    'd3pOOf6t', NOW(), 'Paycheck', 'inflow', 100000,
    'aK9sLp0Q', (SELECT uuid FROM api.accounts 
                 WHERE ledger_uuid = 'd3pOOf6t' AND name = 'Income')
);
```

Example output:

```sql
 add_transaction 
-----------------
 xY7zPqR2
```

**Assign money to category:**

```sql
SELECT uuid FROM api.assign_to_category(
    'd3pOOf6t', NOW(), 'Budget: Groceries', 20000, 'mN8xPqR3'
);
```

Example output:

```sql
   uuid   
----------
 bK2tQw9L
```

**Record spending:**

```sql
SELECT api.add_transaction(
    'd3pOOf6t', NOW(), 'Grocery shopping', 'outflow', 5000,
    'aK9sLp0Q', 'mN8xPqR3'
);
```

Example output:

```sql
 add_transaction 
-----------------
 cL3uRx8M
```

### Reporting Functions

**Budget status:**

```sql
SELECT * FROM api.get_budget_status('d3pOOf6t');
```

Example output:

```sql
 category_uuid | category_name | budgeted | activity | balance 
---------------+---------------+----------+----------+---------
 r95bZcwu      | Groceries     |    40000 |    -8500 |   31500
 P6lNFJrD      | Rent          |   120000 |  -120000 |       0
 rqFGEd8I      | Utilities     |    15000 |    -7500 |    7500
```

**Budget status for specific month:**

```sql
SELECT * FROM api.get_budget_status('d3pOOf6t', '202508');
```

Example output:

```sql
 category_uuid | category_name | budgeted | activity | balance 
---------------+---------------+----------+----------+---------
 r95bZcwu      | Groceries     |    20000 |    -4250 |   15750
 P6lNFJrD      | Rent          |   120000 |  -120000 |       0
 rqFGEd8I      | Utilities     |     7500 |    -3750 |    3750
```

**Budget totals:**

```sql
SELECT * FROM api.get_budget_totals('d3pOOf6t');
```

Example output:

```sql
 income | income_remaining_from_last_month | budgeted | left_to_budget 
--------+----------------------------------+----------+----------------
 350000 |                                0 |   175000 |         175000
```

**Budget totals for specific month:**

```sql
SELECT * FROM api.get_budget_totals('d3pOOf6t', '202508');
```

Example output:

```sql
 income | income_remaining_from_last_month | budgeted | left_to_budget 
--------+----------------------------------+----------+----------------
 175000 |                            87500 |    87500 |          87500
```

**Understanding budget totals:**

- **income**: Total income received in the period
- **income_remaining_from_last_month**: Income balance carried over from previous month (month view only)
- **budgeted**: Total amount assigned to all categories in the period
- **left_to_budget**: Current balance of Income account (available to assign)

**Account balance:**

```sql
SELECT api.get_account_balance('aK9sLp0Q');
```

Example output:

```sql
 get_account_balance 
---------------------
               95000
```

**Transaction history:**

```sql
SELECT * FROM api.get_account_transactions('aK9sLp0Q');
```

Example output:

```sql
    date    |  category  |   description    |  type   | amount | running_balance 
------------+------------+------------------+---------+--------+-----------------
 2025-08-24 | Groceries  | Grocery shopping | outflow |   5000 |           95000
 2025-08-24 | Income     | Paycheck         | inflow  | 100000 |          100000
```

**All account balances:**

```sql
SELECT * FROM api.get_ledger_balances('d3pOOf6t');
```

Example output:

```sql
 account_uuid | account_name  | account_type | current_balance 
--------------+---------------+--------------+-----------------
 aK9sLp0Q     | Checking      | asset        |           95000
 pQ4vWx7N     | Income        | equity       |           72500
 mN8xPqR3     | Groceries     | equity       |           15000
 zKHL0bud     | Internet      | equity       |               0
 rT8yUi2P     | Off-budget    | equity       |               0
 sV9zOj3Q     | Unassigned    | equity       |               0
```

### Transaction Management

**Correct a transaction:**

```sql
SELECT api.correct_transaction(
    'cL3uRx8M', 'outflow', 'aK9sLp0Q', 'mN8xPqR3',
    6000, 'Updated grocery shopping', NOW(), 'Amount correction'
);
```

Example output:

```sql
 correct_transaction 
---------------------
 dM4vSy9N
```

**Delete a transaction:**

```sql
SELECT api.delete_transaction('cL3uRx8M', 'Duplicate transaction');
```

Example output:

```sql
 delete_transaction 
--------------------
 eN5wTz0O
```

## Default Accounts

Each ledger automatically creates three special accounts:

- **Income**: Holds unallocated funds until assigned to categories
- **Off-budget**: For tracking transactions outside your budget
- **Unassigned**: Default category for uncategorized transactions

## Credit Card Payment Categories

When you create a credit card (liability) account, the system automatically creates a companion payment category called "CC Payment: [Card Name]". This category:

- Tracks the budget allocated for paying your credit card balance
- Is automatically linked to the credit card via metadata
- Helps you budget for credit card payments separately from regular expenses

**Example:**
```sql
-- Create a credit card account
INSERT INTO api.accounts (ledger_uuid, name, type, description)
VALUES ('d3pOOf6t', 'My Visa Card', 'liability', 'Main credit card');
-- This automatically creates a category named "CC Payment: My Visa Card"

-- View the linked payment category
SELECT
    a.name as card_name,
    a.metadata->>'payment_category_uuid' as payment_category
FROM api.accounts a
WHERE a.type = 'liability' AND a.name = 'My Visa Card';
```

For existing credit cards created before this feature, you can manually create a payment category:
```sql
SELECT api.create_cc_payment_category('credit_card_uuid_here');
```

## Example Workflow

```sql
-- Set user context
SELECT set_config('app.current_user_id', 'user123', false);
-- Returns: set_config
--          ------------
--          

-- Create budget
INSERT INTO api.ledgers (name) VALUES ('Monthly Budget') RETURNING uuid;
-- Returns:
--    uuid   
-- ----------
--  d3pOOf6t

-- Create checking account
INSERT INTO api.accounts (ledger_uuid, name, type)
VALUES ('d3pOOf6t', 'Checking', 'asset') RETURNING uuid;
-- Returns:
--    uuid   
-- ----------
--  aK9sLp0Q

-- Create grocery category
SELECT uuid FROM api.add_category('d3pOOf6t', 'Groceries');
-- Returns:
--    uuid   
-- ----------
--  mN8xPqR3

-- Add $1000 income
SELECT api.add_transaction(
    'd3pOOf6t', NOW(), 'Paycheck', 'inflow', 100000,
    'aK9sLp0Q', (SELECT uuid FROM api.accounts 
                 WHERE ledger_uuid = 'd3pOOf6t' AND name = 'Income')
);
-- Returns:
--  add_transaction 
-- -----------------
--  xY7zPqR2

-- Assign $200 to groceries
SELECT uuid FROM api.assign_to_category(
    'd3pOOf6t', NOW(), 'Budget: Groceries', 20000, 'mN8xPqR3'
);
-- Returns:
--    uuid   
-- ----------
--  bK2tQw9L

-- Spend $50 on groceries
SELECT api.add_transaction(
    'd3pOOf6t', NOW(), 'Grocery shopping', 'outflow', 5000,
    'aK9sLp0Q', 'mN8xPqR3'
);
-- Returns:
--  add_transaction 
-- -----------------
--  cL3uRx8M

-- Check budget status
SELECT * FROM api.get_budget_status('d3pOOf6t');
-- Returns:
--  category_uuid | category_name | budgeted | activity | balance 
-- ---------------+---------------+----------+----------+---------
--  mN8xPqR3      | Groceries     |    20000 |    -5000 |   15000
--  pQ4vWx7N      | Income        |        0 |        0 |   80000
```

## Architecture

The database uses a three-schema design:

- **`data`**: Raw tables and constraints
- **`utils`**: Internal business logic functions
- **`api`**: Public interface functions with UUID parameters

This separation ensures clean interfaces while maintaining internal flexibility.

## License

Licensed under GNU Affero General Public License v3.0 (AGPL-3.0). See [LICENSE](LICENSE) for details.
