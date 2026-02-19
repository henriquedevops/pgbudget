# Financial Command Center

**Goal:** Transform `/projected-events/` into a single-page financial overview that gives a
complete picture of where you stand today, what is coming up, and where you are headed —
without having to visit five different sections of the app.

---

## Design Philosophy

The current projected-events page is already forward-looking. The idea is to keep the events
list as the page's anchor while adding **contextual panels above it** that cover the present
(balances, budget, debts) and the immediate future (upcoming bills, goals, projection
mini-chart). Everything links back to the detailed module for further action.

The page becomes a **dashboard with depth**: skim it in 30 seconds, or drill into any panel.

---

## Proposed Page Layout

```
┌─────────────────────────────────────────────────────────────┐
│  HEADER: Financial Overview — [Ledger Name]                 │
│  subtitle: "As of [today]"                   [+ New Event]  │
├─────────────┬─────────────┬─────────────┬───────────────────┤
│  Net Worth  │  Cash/Bank  │  Budget     │  Monthly Net      │
│  (total)    │  (liquid)   │  Available  │  Income           │
├─────────────┴─────────────┴─────────────┴───────────────────┤
│  SECTION 1 — UPCOMING OBLIGATIONS & PAYMENTS  (next 30 d)  │
│  Overdue | Due This Week | Due This Month | Installments    │
├─────────────────────────────────────────────────────────────┤
│  SECTION 2 — DEBT SNAPSHOT                                  │
│  Loans | Credit Cards | Active Installment Plans            │
├─────────────────────────────────────────────────────────────┤
│  SECTION 3 — GOALS PROGRESS                                 │
│  Per-category goal bars; highlight underfunded goals        │
├─────────────────────────────────────────────────────────────┤
│  SECTION 4 — CASH FLOW MINI-CHART (next 6 months)          │
│  Bar chart: net per month; link to full projection report   │
├─────────────────────────────────────────────────────────────┤
│  SECTION 5 — PROJECTED EVENTS (existing list)               │
│  Upcoming | Recurring | Realized (collapsible)              │
└─────────────────────────────────────────────────────────────┘
```

---

## Section Breakdown

### Top Cards — Financial Snapshot

Four KPI cards always visible at the top.

| Card | Data Source | DB Function |
|------|-------------|-------------|
| **Net Worth** | Assets − Liabilities | `api.get_net_worth_summary(ledger_uuid)` |
| **Liquid Cash** | Sum of asset (checking + savings) account balances | `api.get_ledger_balances(ledger_uuid)` |
| **Budget Available** | Sum of all category `available` amounts for current month | `api.get_budget_totals(ledger_uuid, period)` |
| **Monthly Net Income** | Sum of active income sources − sum of active deductions | `api.get_income_sources` + `api.get_payroll_deductions` |

Color rules: Net Worth green/red. Budget Available red if negative. Net Income static (informational).

---

### Section 1 — Upcoming Obligations & Payments

A consolidated bill-pay view for the next 30 days. Grouped into three visual bands:

- **Overdue** (red) — obligations or loan payments past due
- **Due This Week** (orange) — due in 0–7 days
- **Due This Month** (neutral) — due in 8–30 days

Each row shows: name, type badge (bill / loan / installment), amount, due date, and a
quick-action button (Mark Paid / View).

| Source | API Call | Notes |
|--------|----------|-------|
| Obligations | `api.get_upcoming_obligations(ledger_uuid, 30, false)` | Returns bills with due date, amount |
| Loan payments | `api.get_loan_payments` filtered by upcoming due dates | Join to `api.unpaid_loan_payments` |
| Installment schedules | `data.installment_schedules` WHERE status='scheduled' AND due_date ≤ today+30 | Via `api.installment-schedules.php` |
| Recurring transactions | `api.get_due_recurring_transactions(ledger_uuid, date)` | Items due or overdue |

**Implementation note:** A single PHP query can union all four sources into one list sorted by
`due_date`. Add a `source_type` column for the badge.

---

### Section 2 — Debt Snapshot

Three collapsible sub-panels showing total debt exposure.

#### 2a. Loans
- Total outstanding principal across all active loans
- Monthly payment total
- Per-loan row: name, lender, remaining balance, monthly payment, payoff date, interest rate
- Link to `/loans/view.php?loan=...`

**Data:** `api.get_loans(ledger_uuid)` — already returns `remaining_balance`, `monthly_payment`,
`status`, computed `payoff_date`.

#### 2b. Credit Cards
- Per-card: limit, current balance, utilization % (color-coded: green < 30%, yellow 30–70%, red > 70%)
- Available credit
- Statement due date if within 30 days

**Data:** `api.credit_card_limits` (limit, APR), `api.get_ledger_balances` (current balance),
`api.credit_card_statements` (latest statement due date).

#### 2c. Active Installment Plans
- Count of active plans, total remaining amount, total remaining installments
- Per-plan row: description, card, remaining payments × amount, next due date
- Link to `/installments/view.php?plan=...`

**Data:** `data.installment_plans` WHERE `status='active'`; join `installment_schedules`
to get next due date and remaining count.

---

### Section 3 — Goals Progress

Shows all category goals with a visual progress bar. Sorted: underfunded first.

Each goal row:
- Category name
- Progress bar: funded / target (fills green when ≥ 100%)
- Amount funded vs. target
- Due date (if set)
- Status badge: On Track / Underfunded / Completed

**Data:** `api.get_ledger_goals(ledger_uuid, current_period)` — returns `budgeted`, `target_amount`,
`goal_type`, `target_date` per category.

Only show categories that have a goal (`target_amount IS NOT NULL`). If no goals exist, show
a prompt to create one with a link to `/categories/manage.php`.

---

### Section 4 — Cash Flow Mini-Chart (next 6 months)

A compact bar chart showing **net cash flow per month** for the next 6 months: positive months
in green, negative in red. Below the chart: a single cumulative balance line.

- Clicking the chart navigates to the full projection report
- Show the starting balance assumption (current liquid cash) alongside the chart

**Data:** `api.get_projection_summary(ledger_uuid, start_month, 6)` — returns
`month`, `net_amount`, `cumulative_amount` per row. This is already used by the full report.

**Rendering:** A lightweight JS canvas bar chart (or CSS-based bars — no external library needed).
No filters needed here; the mini-chart always shows `start_month = current month` and 6 months.

---

### Section 5 — Projected Events (existing list)

The current events table, unchanged in functionality, but styled to match the rest of the
command center. Add two UX improvements:

1. **Tab bar** above the table: All | Upcoming | Recurring | Realized — so realized events are
   hidden by default but accessible without a page reload (client-side show/hide).
2. **Inline quick-mark-realized button** next to each upcoming event row (calls the existing
   update API with `is_realized=true`).

---

## Implementation Plan

### Phase 1 — Top Cards + Section 1 (Highest value, low effort)

Changes are **PHP-only** (no new DB functions needed):

1. Create `/public/financial-overview/index.php` (new page, not replacing projected-events yet)
2. Add queries at the top: `get_net_worth_summary`, `get_ledger_balances`, `get_budget_totals`,
   income/deduction sum
3. Add unified upcoming-payments query (obligations + loan payments + installments in one loop)
4. Render top KPI cards + Section 1 table
5. Add nav link in the sidebar/header pointing to this page

**Effort:** ~1 day. No migrations needed.

### Phase 2 — Section 2: Debt Snapshot

1. Add queries for `api.get_loans`, `api.credit_card_limits` joined to account balances,
   installment plans
2. Render the three sub-panels (collapsible with `<details>` HTML or simple JS toggle)

**Effort:** ~half day. No migrations needed.

### Phase 3 — Section 3: Goals

1. Add query for `api.get_ledger_goals`; filter to categories with a goal
2. Render progress bars with CSS (no external library)

**Effort:** ~half day. No migrations needed.

### Phase 4 — Section 4: Cash Flow Mini-Chart

1. Fetch `api.get_projection_summary` for 6 months via inline PHP (same as the full report does)
2. Render bars with CSS `height` set inline from percentage of max value
3. Link to full report

**Effort:** ~half day. Minimal JS.

### Phase 5 — Section 5: Merge Projected Events

1. Move the events list into this page (replace `/projected-events/index.php` redirect or keep
   both and add a "Full View" link)
2. Add client-side tab filtering (All / Upcoming / Recurring / Realized)
3. Add quick-mark-realized button per row (AJAX call to existing API)

**Effort:** ~half day.

### Phase 6 — Navigation Integration

1. Update the sidebar nav to add "Financial Overview" as a top-level link, replacing or above
   the current "Projected Events" entry
2. Update the budget dashboard's "View Projection" link to point here instead of, or in
   addition to, the cash flow report

**Effort:** ~1 hour.

---

## Data Query Summary

All queries run server-side in PHP before page render. No new API endpoints or DB migrations
needed for Phases 1–6. All functions are already in the `api` schema.

| Query | Function | Returns |
|-------|----------|---------|
| Net worth | `api.get_net_worth_summary($ledger_uuid)` | `total_assets`, `total_liabilities`, `net_worth` |
| Account balances | `api.get_ledger_balances($ledger_uuid)` | Per-account balance list |
| Budget totals | `api.get_budget_totals($ledger_uuid, $period)` | `total_budgeted`, `total_activity`, `total_available` |
| Income sources | `api.get_income_sources($ledger_uuid)` | Per-source `amount`, `frequency` |
| Payroll deductions | `api.get_payroll_deductions($ledger_uuid)` | Per-deduction `fixed_amount` or `estimated_amount` |
| Upcoming bills | `api.get_upcoming_obligations($ledger_uuid, 30, false)` | Bills with `due_date`, `current_amount` |
| Unpaid loan payments | `api.unpaid_loan_payments` WHERE `ledger_uuid = ?` | Loan installments with `due_date`, `total_payment` |
| Installment schedules | Direct table join | Due dates for active installment plans |
| Due recurring | `api.get_due_recurring_transactions($ledger_uuid, today)` | Overdue recurring templates |
| Loans | `api.get_loans($ledger_uuid)` | Loan summaries |
| CC limits | `api.credit_card_limits` + account balance | Utilization per card |
| Goals | `api.get_ledger_goals($ledger_uuid, $period)` | Goal progress |
| Mini projection | `api.get_projection_summary($ledger_uuid, $start, 6)` | Net + cumulative per month |
| Projected events | `api.get_projected_events($ledger_uuid)` | Future event list |

---

## Projection Table Completeness

The cash-flow projection (`api.generate_cash_flow_projection`) already auto-includes data from
every structured module — loans, obligations, installments, recurring transactions, income
sources, payroll deductions, and projected events. However, **three gaps** exist where spending
or cash outflows are invisible to the projection today:

### Gap 1 — Credit Card Cash Payments (missing)

CC payments are modeled as account-to-account transfers, not as projected outflows. If you carry
a balance on a credit card, the monthly cash drain does not appear in the projection.

**Fix (Phase 7 — new migration):** Add a new section to `api.generate_cash_flow_projection`
that reads `api.credit_card_limits` joined to current account balances and emits a monthly
`cc_payment` row per card. The projected amount would be either the card's minimum payment or
the full statement balance depending on the auto-payment setting already stored in
`data.credit_card_limits.autopay_type`. No new tables needed; this is purely a function change.

```
source_type = 'cc_payment'
amount      = -(autopay_type = 'full' ? current_balance : minimum_payment)
description = account_name || ' payment'
```

### Gap 2 — Discretionary Budget Spending (missing)

The budget tracks what you *have* spent (backward-looking). The projection has no concept of
"I plan to spend R$X/month on groceries" unless it is modeled as a recurring transaction or
projected event. This means variable monthly spending (food, fuel, entertainment) is entirely
absent from the forward view.

**Fix (Phase 8 — opt-in per category):** Add a boolean column
`data.accounts.include_in_projection` (default `false`) to equity (category) accounts. When
`true`, the category's current budgeted amount is emitted monthly as a `budget_spend` outflow
in the projection. The user controls this per category in `/categories/manage.php`. This
requires a migration (new column) and a new section in the projection function.

Alternatively — and with zero migration effort — the user can model discretionary spending as
**recurring projected events** (e.g., "Groceries R$1500/month" as a recurring monthly outflow).
This is the recommended interim approach.

### Gap 3 — One-Off Planned Expenses (workflow gap)

Any planned future expense that does not fit a structured module (loan, obligation, installment,
recurring) will not appear unless explicitly entered as a projected event. This is not a bug —
it is the correct workflow. The Financial Command Center page should make this prominent:

> "Anything you know you will spend that is not a loan, bill, or installment should be a
> Projected Event."

The existing projected-events CRUD with recurrence support (one_time / monthly / annual /
semiannual) covers all cases.

---

### Coverage summary after all phases

| Cash flow source | In projection today | After Phase 7 | After Phase 8 |
|-----------------|-------------------|---------------|---------------|
| Income sources | ✅ | ✅ | ✅ |
| Payroll deductions | ✅ | ✅ | ✅ |
| Obligations/bills | ✅ | ✅ | ✅ |
| Loans (amort + interest) | ✅ | ✅ | ✅ |
| Installment plans | ✅ | ✅ | ✅ |
| Recurring transactions | ✅ | ✅ | ✅ |
| Projected events | ✅ | ✅ | ✅ |
| Credit card payments | ❌ | ✅ | ✅ |
| Discretionary budget spend | ❌ | ❌ | ✅ (opt-in) |
| Savings goal contributions | ❌ | ❌ | ❌ (out of scope) |

Goal contributions are intentionally excluded: saving money does not reduce net worth, it
reallocates it. The projection tracks cash flow, not intra-budget movements.

---

## Open Questions

1. **Page name and URL:** `/financial-overview/` vs. keep `/projected-events/` and expand it
   in place? Expanding in-place avoids breaking existing links (e.g., from the dashboard
   widget). Alternatively, redirect `/projected-events/` → `/financial-overview/`.

2. **Sidebar navigation:** Should "Projected Events" be renamed to "Financial Overview" globally,
   or kept as a separate entry? Recommend: rename to "Overview" and keep projected-events
   accessible via a tab/link within the page.

3. **Mobile layout:** The four top KPI cards collapse to 2×2 on small screens (already handled
   by CSS grid `auto-fit`). Section 1's table becomes a card stack on mobile.

4. **Performance:** All queries are fast (indexed by `ledger_id` + `user_data`). The projection
   summary for 6 months is the heaviest but already used in the dashboard widget. No caching
   needed at this scale.

5. **Phase 7 (CC payments) priority:** Since CC payment cash flows are a real gap that affects
   the accuracy of the projection, Phase 7 should be scheduled before Phase 8. It requires
   one migration (new function body only — no new tables) and does not touch any PHP pages.
