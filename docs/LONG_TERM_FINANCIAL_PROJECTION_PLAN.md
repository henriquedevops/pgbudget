# Long-Term Financial Projection Implementation Plan

**Created:** 2026-02-16
**Status:** Planning
**Priority:** High
**Related Features:**
- Obligations (existing)
- Loans (existing)
- Recurring Transactions (existing)
- Budget System (existing)
- Reports (existing)

---

## 1. Objective

Replicate the spreadsheet-based long-term financial projection (`obl_bud_trn.csv`) inside pgbudget. The spreadsheet tracks **every known future cash flow** — income components, loan amortization/interest, fixed obligations, variable expenses, payroll deductions, and one-time events — projected month-by-month over 10+ years, producing:

- A **monthly net balance** (fechamento saldo mes)
- A **cumulative balance** (saldo acumulado)

The goal is to make this projection a **living, auto-updating report** that pulls data from existing pgbudget entities (obligations, loans, recurring transactions) and new entities (income sources, payroll deductions, one-time projections), rather than a static spreadsheet that must be manually maintained.

---

## 2. Gap Analysis: Spreadsheet vs. pgbudget

### What the spreadsheet has that pgbudget already covers

| Spreadsheet concept | pgbudget entity | Notes |
|---|---|---|
| Recurring expenses (housing, Neoenergia, APCEF, gym, subscriptions, diarista, Kumon, school) | `data.obligations` + `data.recurring_transactions` | Obligations have schedule generation with `start_date`/`end_date`, frequency, and amounts. These can be projected forward. |
| Loan amortization (CAIXA contracts, JP, Picpay, Carol Nubank, FUNCEF) | `data.loans` + `data.loan_payments` | Loans already track principal, rate, term. Amortization schedules exist. |
| Credit card installments (OLX S6 Lite) | `data.installment_plans` + `data.installment_schedules` | Already projected with start/end and per-installment amounts. |
| Budget categories (food, housing, car, education, etc.) | `data.accounts` (equity type) with category groups | Hierarchy exists: groups > categories. |

### What the spreadsheet has that pgbudget is MISSING

| Spreadsheet concept | What's needed | Priority |
|---|---|---|
| **Income projection** — Salary components (Salario Padrao, Funcao Gratificada, Complemento, VR Alimentacao) projected monthly | New **Income Sources** entity with recurring amounts, start/end dates | Critical |
| **Payroll deductions** — INSS, IRPF, FUNCEF contribution, Saude CAIXA, Moradia Cidadania, deducted at source | New **Payroll Deductions** entity (or model as negative income components) | Critical |
| **Loan interest vs. amortization split** in projection — each loan shows two rows (amort + interest) with different monthly values | Amortization schedule projection function that computes month-by-month principal vs. interest | High |
| **One-time future events** — 13th salary, vacation advance returns, lump-sum settlements (acerto), FGTS birthday withdrawal | New **Projected Events** entity for one-time or irregular future cash flows | High |
| **Monthly net balance** (fechamento saldo mes) | Projection report that sums all inflows and outflows per month | Critical |
| **Cumulative balance** (saldo acumulado) | Running sum of monthly net balances | Critical |
| **Multi-year projection view** — spreadsheet spans Dec 2024 to Dec 2035 (11 years) | Projection report UI with configurable horizon (1-15 years) | High |
| **Interest-only obligations** — FUNCEF CredPlan Variavel and Fixo show interest payments with no amortization (interest-only period) | Support for interest-only loan phases or model as obligations | Medium |

---

## 3. Data Model: New Entities

### 3.1 `data.income_sources` — Recurring Income Components

Each row represents one component of income (e.g., base salary, bonus, food allowance).

```sql
CREATE TABLE data.income_sources (
    id BIGSERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL DEFAULT utils.nanoid(8),
    user_data TEXT NOT NULL,
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Income details
    name TEXT NOT NULL,                    -- e.g., 'SALARIO PADRAO'
    description TEXT,
    income_type TEXT NOT NULL DEFAULT 'salary',
        -- 'salary', 'bonus', 'benefit', 'freelance', 'rental', 'investment', 'other'
    income_subtype TEXT,                   -- e.g., 'base', 'gratification', 'food_allowance'

    -- Amount
    amount NUMERIC(15,2) NOT NULL,         -- gross monthly amount (positive)
    currency TEXT DEFAULT 'BRL',

    -- Frequency & schedule
    frequency TEXT NOT NULL DEFAULT 'monthly',
        -- 'monthly', 'biweekly', 'weekly', 'annual', 'semiannual', 'one_time'
    pay_day_of_month INTEGER,              -- e.g., 20 for salary paid on 20th
    occurrence_months INTEGER[],           -- for annual/semiannual: {12} for Dec-only (13th salary)

    -- Date range
    start_date DATE NOT NULL,
    end_date DATE,                         -- NULL = indefinite

    -- Category link
    default_category_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Grouping (to group salary components under one employer)
    employer_name TEXT,                    -- e.g., 'CAIXA Economica Federal'
    group_tag TEXT,                        -- arbitrary grouping tag

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

ALTER TABLE data.income_sources ENABLE ROW LEVEL SECURITY;
CREATE POLICY income_sources_isolation ON data.income_sources
    USING (user_data = utils.get_user());
```

**Mapping from spreadsheet:**

| Spreadsheet row | income_type | income_subtype | amount | frequency | occurrence_months |
|---|---|---|---|---|---|
| VR Alimentacao | benefit | food_allowance | 2,097.73 | monthly | NULL |
| SALARIO PADRAO | salary | base | 8,042.00 | monthly | NULL |
| FUNCAO GRATIFICADA EFETIVA | salary | gratification | 13,571.00 | monthly | NULL |
| COMPL TEMP VARIAVEL AJUSTE MERCADO | salary | market_adjustment | 9,683.00 | monthly | NULL |
| GRAT NATAL - 13 SALARIO | bonus | 13th_salary | 31,299.26 | annual | {12} |
| AC APIP/IP CONVERSAO | bonus | conversion | 5,216.00 | one_time | NULL |
| Vendas | other | sales | 1,000.00 | one_time | NULL |

---

### 3.2 `data.payroll_deductions` — Recurring Deductions from Income

Deductions that are withheld at source before net pay is received.

```sql
CREATE TABLE data.payroll_deductions (
    id BIGSERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL DEFAULT utils.nanoid(8),
    user_data TEXT NOT NULL,
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Deduction details
    name TEXT NOT NULL,                    -- e.g., 'INSS CONTRIBUICAO'
    description TEXT,
    deduction_type TEXT NOT NULL DEFAULT 'tax',
        -- 'tax', 'social_security', 'health_plan', 'pension_fund',
        -- 'union_dues', 'donation', 'loan_repayment', 'other'

    -- Amount (can be fixed or variable/estimated)
    is_fixed_amount BOOLEAN DEFAULT TRUE,
    fixed_amount NUMERIC(15,2),            -- positive number; will be subtracted
    estimated_amount NUMERIC(15,2),        -- for variable deductions
    is_percentage BOOLEAN DEFAULT FALSE,
    percentage_value NUMERIC(8,4),         -- e.g., 14.0000 for 14% INSS
    percentage_base TEXT,                  -- what it's a % of: 'gross_salary', 'base_salary'

    -- Frequency & schedule
    frequency TEXT NOT NULL DEFAULT 'monthly',
    occurrence_months INTEGER[],           -- for annual deductions

    -- Date range
    start_date DATE NOT NULL,
    end_date DATE,

    -- Category link
    default_category_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Link to employer
    employer_name TEXT,
    group_tag TEXT,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

ALTER TABLE data.payroll_deductions ENABLE ROW LEVEL SECURITY;
CREATE POLICY payroll_deductions_isolation ON data.payroll_deductions
    USING (user_data = utils.get_user());
```

**Mapping from spreadsheet:**

| Spreadsheet row | deduction_type | amount | frequency |
|---|---|---|---|
| INSS CONTRIBUICAO | social_security | ~1,031.62 | monthly |
| IMPOSTO DE RENDA | tax | ~8,200.00 | monthly |
| FUNCEF CONTRIBUICAO REB2002 | pension_fund | 432.26 | monthly |
| SAUDE CAIXA | health_plan | 2,055.36 | monthly |
| MORADIA E CIDADANIA | donation | 53.38 | monthly |
| REP DEVOLUCAO ADIANT FERIAS | loan_repayment | 1,460.48 | monthly |
| REP ADIANT GRATIFICACAO NATAL | other | 31,370.53 | annual {12} |

---

### 3.3 `data.projected_events` — One-Time or Irregular Future Events

For events that don't fit neatly into obligations or recurring transactions.

```sql
CREATE TABLE data.projected_events (
    id BIGSERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL DEFAULT utils.nanoid(8),
    user_data TEXT NOT NULL,
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Event details
    name TEXT NOT NULL,
    description TEXT,
    event_type TEXT NOT NULL DEFAULT 'other',
        -- 'bonus', 'tax_refund', 'settlement', 'asset_sale', 'gift',
        -- 'large_purchase', 'vacation', 'medical', 'other'
    direction TEXT NOT NULL DEFAULT 'outflow',  -- 'inflow' or 'outflow'

    -- Amount
    amount NUMERIC(15,2) NOT NULL,         -- always positive; direction determines sign

    -- When
    event_date DATE NOT NULL,              -- the specific month/date

    -- Category link
    default_category_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Status
    is_confirmed BOOLEAN DEFAULT FALSE,    -- confirmed vs. speculative
    is_realized BOOLEAN DEFAULT FALSE,     -- already happened (linked to actual transaction)
    linked_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,

    notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,

    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

ALTER TABLE data.projected_events ENABLE ROW LEVEL SECURITY;
CREATE POLICY projected_events_isolation ON data.projected_events
    USING (user_data = utils.get_user());
```

**Mapping from spreadsheet:**

| Spreadsheet row | event_type | direction | amount | event_date |
|---|---|---|---|---|
| acerto outros | settlement | inflow | 83,308.27 | Jan 2026 |
| CAIXA Antecip Saq Aniv 0012079-77 (amort+int) | settlement | outflow | 4,278.47 | Jun 2026 |
| CAIXA Antecip Saq Aniv 0012080-00 (amort+int) | settlement | outflow | 4,064.57 | Jun 2027 |
| FUNCEF CredPlan 13o Fev (amort+int) | settlement | outflow | 11,216.33 | Feb 2026 |

---

## 4. Projection Engine: `api.generate_cash_flow_projection()`

This is the core function that replaces the spreadsheet. It aggregates all sources of future cash flow into a month-by-month table.

### 4.1 Function Signature

```sql
CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid TEXT,
    p_start_month DATE DEFAULT date_trunc('month', CURRENT_DATE),
    p_months_ahead INTEGER DEFAULT 120  -- 10 years default
) RETURNS TABLE (
    month DATE,
    source_type TEXT,       -- 'income', 'deduction', 'obligation', 'loan_amort',
                            -- 'loan_interest', 'installment', 'recurring', 'event'
    source_id BIGINT,
    source_uuid TEXT,
    category TEXT,          -- mapped from categoria column
    subcategory TEXT,       -- mapped from subcat column
    description TEXT,       -- mapped from desc column
    amount NUMERIC(15,2)    -- positive = inflow, negative = outflow
) AS $$
...
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

### 4.2 Data Sources Aggregated

The function UNIONs projections from all sources:

```
1. data.income_sources        → future income by month
2. data.payroll_deductions    → future deductions by month (negative)
3. data.obligations           → future obligation payments (negative)
4. data.loans                 → amortization schedule (negative, split amort/interest)
5. data.installment_plans     → remaining installments (negative)
6. data.recurring_transactions → future recurring items (positive or negative)
7. data.projected_events      → one-time events (positive or negative)
```

Each source is expanded into monthly rows covering the requested horizon:

```sql
-- Example for income_sources:
SELECT
    generate_series_month AS month,
    'income' AS source_type,
    is.id AS source_id,
    is.uuid AS source_uuid,
    'income' AS category,
    is.income_subtype AS subcategory,
    is.name AS description,
    is.amount AS amount
FROM data.income_sources is
CROSS JOIN LATERAL generate_series(
    GREATEST(is.start_date, p_start_month),
    COALESCE(is.end_date, p_start_month + (p_months_ahead || ' months')::interval),
    '1 month'::interval
) AS generate_series_month
WHERE is.ledger_id = v_ledger_id
  AND is.is_active = TRUE
  AND is.frequency = 'monthly';
```

### 4.3 Loan Amortization Projection

For loans, the function must compute the amortization schedule (Price/SAC table) month-by-month, splitting principal and interest. This leverages the existing `data.loans` data:

```sql
-- For each active loan, compute future payments using the amortization formula:
-- Price system (most CAIXA loans): fixed payment, decreasing interest, increasing principal
-- PMT = P * [r(1+r)^n] / [(1+r)^n - 1]
-- Interest_month = remaining_balance * monthly_rate
-- Principal_month = PMT - Interest_month
-- remaining_balance = remaining_balance - Principal_month
```

This should be implemented as a helper function:

```sql
CREATE OR REPLACE FUNCTION utils.project_loan_amortization(
    p_loan_id BIGINT,
    p_start_month DATE,
    p_months_ahead INTEGER
) RETURNS TABLE (
    month DATE,
    amortization NUMERIC(19,4),    -- principal portion (negative)
    interest NUMERIC(19,4),        -- interest portion (negative)
    remaining_balance NUMERIC(19,4)
) AS $$
...
$$ LANGUAGE plpgsql;
```

### 4.4 Summary View

A wrapper function produces the monthly summary (the spreadsheet's bottom rows):

```sql
CREATE OR REPLACE FUNCTION api.get_projection_summary(
    p_ledger_uuid TEXT,
    p_start_month DATE DEFAULT date_trunc('month', CURRENT_DATE),
    p_months_ahead INTEGER DEFAULT 120
) RETURNS TABLE (
    month DATE,
    total_income NUMERIC(15,2),
    total_deductions NUMERIC(15,2),
    total_obligations NUMERIC(15,2),
    total_loan_amort NUMERIC(15,2),
    total_loan_interest NUMERIC(15,2),
    total_installments NUMERIC(15,2),
    total_recurring NUMERIC(15,2),
    total_events NUMERIC(15,2),
    net_monthly_balance NUMERIC(15,2),     -- fechamento saldo mes
    cumulative_balance NUMERIC(15,2)       -- saldo acumulado
) AS $$
    SELECT
        month,
        SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS total_income,
        -- ... group by source_type ...
        SUM(amount) AS net_monthly_balance,
        SUM(SUM(amount)) OVER (ORDER BY month) AS cumulative_balance
    FROM api.generate_cash_flow_projection(p_ledger_uuid, p_start_month, p_months_ahead)
    GROUP BY month
    ORDER BY month;
$$ LANGUAGE sql SECURITY DEFINER;
```

---

## 5. User Interface

### 5.1 Projection Report Page: `/public/reports/cash-flow-projection.php`

This is the primary UI — a **scrollable table** that mirrors the spreadsheet layout:

```
+------------------------------------------------------------------+
|  Cash Flow Projection                                             |
|  Ledger: [My Budget v]   From: [Feb 2026]   To: [Dec 2035]      |
|  [Generate]  [Export CSV]  [Export PDF]                            |
+------------------------------------------------------------------+
|                                                                    |
|  FILTERS:                                                          |
|  [x] Income  [x] Deductions  [x] Obligations  [x] Loans          |
|  [x] Installments  [x] Recurring  [x] Events                     |
|  Group by: [Category v]                                            |
|                                                                    |
+----------+----------+----------+----------+----------+----+-------+
| Category | Subcat   | Desc     | Feb-26   | Mar-26   | ...| Total |
+----------+----------+----------+----------+----------+----+-------+
| income   | salary   | Sal.Pad  | 8,042.00 | 8,042.00 | ...| ...   |
| income   | salary   | Func.Gr  |13,571.00 |13,571.00 | ...| ...   |
| income   | salary   | Compl.   | 9,683.00 | 9,683.00 | ...| ...   |
| income   | benefit  | VR Alim  | 2,097.73 | 2,097.73 | ...| ...   |
+----------+----------+----------+----------+----------+----+-------+
| expense  | ln amort | CAIXA 72 |  -101.87 |  -103.06 | ...| ...   |
| interest | ln int   | CAIXA 72 |  -231.81 |  -230.62 | ...| ...   |
| expense  | ln amort | CAIXA 05 |-1,218.63 |-1,231.91 | ...| ...   |
| interest | ln int   | CAIXA 05 |-2,269.28 |-2,256.00 | ...| ...   |
| ...      | ...      | ...      | ...      | ...      | ...| ...   |
+----------+----------+----------+----------+----------+----+-------+
| SUMMARY                                                            |
+----------+----------+----------+----------+----------+----+-------+
|          |          | Net Bal. |-3,311.16 |-3,272.96 | ...| ...   |
|          |          | Cum.Bal. |-25,249   |-28,522   | ...| ...   |
+----------+----------+----------+----------+----------+----+-------+
```

**Key UI features:**
- Horizontal scroll for months (sticky first 3 columns)
- Color coding: green for positive, red for negative
- Collapsible category groups
- Click a cell to see the source entity details
- Toggle between monthly and quarterly/annual views
- Sparkline mini-charts per row showing the trend

### 5.2 Income Sources Management: `/public/income/`

Pages for managing income sources and payroll deductions:

- `index.php` — List all income sources and deductions, grouped by employer
- `create.php` — Add new income source or deduction
- `edit.php` — Edit existing entry
- `view.php` — View details with projected future amounts

**Create form layout:**

```
+--------------------------------------------------+
|  Add Income Source                                 |
+--------------------------------------------------+
|  Name: [SALARIO PADRAO___________]                |
|  Type: [Salary v]  Subtype: [base__________]     |
|  Employer: [CAIXA Economica Federal__]            |
|                                                    |
|  Amount: [R$ 8,042.00___]                         |
|  Frequency: [Monthly v]                            |
|  Pay day: [20] of month                            |
|                                                    |
|  Start date: [2026-01-01]                          |
|  End date:   [__________] or [x] No end date      |
|                                                    |
|  Category: [Salary v]                              |
|  Notes: [_________________________________]       |
|                                                    |
|  [Cancel]  [Save Income Source]                    |
+--------------------------------------------------+
```

### 5.3 Projected Events Management: `/public/projected-events/`

Simple CRUD for one-time future events:

- `index.php` — List with status (upcoming, realized, speculative)
- `create.php` — Add event with date, amount, direction, category
- `edit.php` — Modify event

### 5.4 Dashboard Integration

Add a **projection summary widget** to the budget dashboard:

```
+--------------------------------------------------+
|  Cash Flow Outlook (Next 6 Months)                |
+--------------------------------------------------+
|  Feb    Mar    Apr    May    Jun    Jul            |
|  -3.3k  -3.3k  -2.9k  -3.0k  -7.3k  -0.02k     |
|  ████   ████   ███    ████   ██████  █            |
|                                                    |
|  Cumulative: -R$ 41,714 by Jul 2026               |
|  [View Full Projection →]                          |
+--------------------------------------------------+
```

---

## 6. Spreadsheet Import Tool

To bootstrap the projection with existing spreadsheet data, provide a CSV import tool.

### 6.1 Import Page: `/public/import/projection.php`

```
+--------------------------------------------------+
|  Import Financial Projection                       |
+--------------------------------------------------+
|  Upload CSV: [Choose File] obl_bud_trn.csv        |
|                                                    |
|  Format: [Auto-detect v]                           |
|  Currency: [BRL v]                                 |
|                                                    |
|  [Preview Import]                                  |
+--------------------------------------------------+
```

### 6.2 Import Logic

The importer reads the CSV and maps rows to entities:

| CSV `categoria` | CSV `subcat` | Maps to pgbudget entity |
|---|---|---|
| `income` + `Salario` | — | `data.income_sources` (salary) |
| `expense` + `ln amort` | — | `data.loans` (amortization component) |
| `interest` + `ln int` | — | `data.loans` (interest component — verified against loan schedule) |
| `expense` + `housing` | — | `data.obligations` (type=housing) |
| `expense` + `Fitness` | — | `data.obligations` (type=subscription) |
| `investment` + `education` | — | `data.obligations` (type=education) |
| `house` + `power bill` | — | `data.obligations` (type=utility) |
| `insurance` | — | `data.obligations` (type=insurance) |
| `expense` + `INSS` | — | `data.payroll_deductions` (social_security) |
| `expense` + `Salario` (negative) | — | `data.payroll_deductions` (tax, pension, health) |
| one-time values | — | `data.projected_events` |

The import wizard shows a preview with entity mapping before committing.

---

## 7. Implementation Phases

### Phase 1: Database Schema & Core Functions
**Scope:** Create the three new tables and the projection engine.

**Tasks:**
- [ ] Migration: `data.income_sources` table + RLS + indexes
- [ ] Migration: `data.payroll_deductions` table + RLS + indexes
- [ ] Migration: `data.projected_events` table + RLS + indexes
- [ ] API function: `api.create_income_source()`, `api.update_income_source()`, `api.delete_income_source()`, `api.get_income_sources()`
- [ ] API function: `api.create_payroll_deduction()`, `api.update_payroll_deduction()`, `api.delete_payroll_deduction()`, `api.get_payroll_deductions()`
- [ ] API function: `api.create_projected_event()`, `api.update_projected_event()`, `api.delete_projected_event()`, `api.get_projected_events()`
- [ ] Utility function: `utils.project_loan_amortization()` — computes Price/SAC schedule
- [ ] Core function: `api.generate_cash_flow_projection()` — unions all sources
- [ ] Summary function: `api.get_projection_summary()` — net and cumulative balances

### Phase 2: Income & Deductions UI
**Scope:** CRUD pages for income sources and payroll deductions.

**Tasks:**
- [ ] PHP pages: `/public/income/index.php`, `create.php`, `edit.php`, `view.php`
- [ ] PHP API endpoint: `/public/api/income-sources.php`
- [ ] PHP API endpoint: `/public/api/payroll-deductions.php`
- [ ] Deduction form with fixed/percentage/variable amount modes
- [ ] Income source form with frequency options
- [ ] Employer grouping view
- [ ] Navigation menu entry for "Income & Deductions"
- [ ] CSS styling consistent with existing pages

### Phase 3: Projected Events UI
**Scope:** CRUD pages for one-time future events.

**Tasks:**
- [ ] PHP pages: `/public/projected-events/index.php`, `create.php`, `edit.php`
- [ ] PHP API endpoint: `/public/api/projected-events.php`
- [ ] Status indicators (upcoming, confirmed, speculative, realized)
- [ ] Link to actual transaction once realized
- [ ] Navigation menu entry

### Phase 4: Cash Flow Projection Report
**Scope:** The main projection report page.

**Tasks:**
- [ ] PHP page: `/public/reports/cash-flow-projection.php`
- [ ] PHP API endpoint: `/public/api/get-cash-flow-projection.php`
- [ ] Spreadsheet-like table with horizontal scroll and sticky columns
- [ ] Color coding (green income, red outflow)
- [ ] Source type filters (toggle income, deductions, obligations, loans, etc.)
- [ ] Grouping options (by category, by source type, flat)
- [ ] Collapsible category sections
- [ ] Monthly/quarterly/annual toggle
- [ ] Summary rows: net monthly balance + cumulative balance
- [ ] Cell click: show source entity details
- [ ] CSV export
- [ ] JavaScript: `/public/js/cash-flow-projection.js`
- [ ] CSS: `/public/css/cash-flow-projection.css`

### Phase 5: Dashboard Widget & Integration
**Scope:** Surface projection data on the budget dashboard and integrate with existing features.

**Tasks:**
- [ ] Dashboard widget: 6-month cash flow outlook bar chart
- [ ] Link from obligation view to its projection row
- [ ] Link from loan view to its projection rows (amort + interest)
- [ ] Add projection totals to the reports navigation
- [ ] "What-if" scenario: temporarily adjust an income/obligation amount and see the impact on cumulative balance

### Phase 6: CSV Import Tool
**Scope:** Import existing spreadsheet data.

**Tasks:**
- [ ] PHP page: `/public/import/projection.php`
- [ ] CSV parser that handles the `obl_bud_trn.csv` format
- [ ] Entity mapping wizard (map CSV rows to pgbudget entities)
- [ ] Preview before import with conflict detection
- [ ] Batch insert via API functions
- [ ] Import log with success/error reporting

---

## 8. Technical Considerations

### 8.1 Performance

The projection function may generate thousands of rows (45 line items x 120 months = 5,400 rows). Strategies:

- **Materialized view option:** For projections that rarely change, cache results in a materialized view refreshed on-demand or on data change.
- **Pagination by date range:** Allow the UI to request a window (e.g., 12 months at a time) and lazy-load more.
- **Server-side rendering:** Compute the table server-side in PHP rather than sending all data as JSON to the client.

### 8.2 Currency Handling

The spreadsheet uses BRL with mixed formatting (`R$ 2,097.73`, `$ (2,096.74)`, `-2097.73`). The new tables use `NUMERIC(15,2)` consistently. The import tool must handle all three formats via `parseCurrency()`.

### 8.3 Loan Amortization Accuracy

The spreadsheet shows exact loan payment schedules from CAIXA contracts. Two approaches:

1. **Computed:** Use the loan parameters (principal, rate, term) to compute the schedule mathematically. May have small rounding differences vs. the bank's actual schedule.
2. **Imported:** Allow importing the exact bank-provided amortization schedule into `data.loan_payments` or a new `data.loan_amortization_schedule` table. This is more accurate.

**Recommendation:** Support both. Default to computed; allow override with imported schedule.

### 8.4 Amounts: Cents vs. Decimal

The existing transaction system uses `bigint` (cents). The new tables use `NUMERIC(15,2)` to match the obligations and loans pattern. The projection engine outputs `NUMERIC(15,2)`. Display formatting uses the existing `formatCurrency()` function.

### 8.5 Interaction with Existing Obligations

The projection engine reads from `data.obligations` using schedule generation. If an obligation already has `obligation_payments` generated, use those. If not, compute projected payments from the obligation's frequency/amount/start/end dates.

### 8.6 Existing Loan Amortization Schedule

The `data.loans` table stores `interest_rate`, `loan_term_months`, `payment_amount`, `remaining_months`, and `amortization_type`. The `data.loan_payments` table may contain historical payments. The projection fills in future months using the formula.

---

## 9. Migration Path from Spreadsheet

### Step-by-step for the user

1. **Import the CSV** using the import tool (Phase 6), or manually create entities:
2. **Create income sources** for each salary component (5-6 entries)
3. **Create payroll deductions** for each deduction (6-7 entries)
4. **Verify existing loans** — ensure all CAIXA contracts, JP, FUNCEF loans are in `data.loans` with correct parameters
5. **Verify existing obligations** — ensure housing, utilities, gym, school, subscriptions, Kumon, diarista are in `data.obligations`
6. **Create projected events** for one-time items (13th salary adjustment, FGTS withdrawals, settlements)
7. **Open the projection report** and compare with the spreadsheet
8. **Iterate** — adjust amounts, dates, and parameters until the projection matches

### Ongoing maintenance

Once set up, the projection updates automatically as:
- Loan payments are recorded (remaining balance decreases)
- Obligations end or are paused
- Income changes (promotion, raise)
- New events are added
- Existing items are adjusted

This eliminates the need to manually update the spreadsheet each month.

---

## 10. Future Enhancements (Post-MVP)

- **Scenario planning:** Create multiple projection scenarios (optimistic, pessimistic, base case)
- **Goal integration:** Show when category goals would be met given projected cash flow
- **Inflation adjustment:** Apply inflation rate to projected amounts over time
- **Tax bracket calculation:** Automatically compute IRPF based on gross income and deduction rules
- **Net worth projection:** Combine cash flow projection with asset/liability balances
- **Alert thresholds:** Notify when cumulative balance drops below a threshold
- **Sharing/export:** Generate PDF reports for financial advisors or family members
- **Historical comparison:** Overlay actual vs. projected for past months

---

*Document Created: 2026-02-16*
*Version: 1.0*
*Status: Planning — Awaiting Approval*
