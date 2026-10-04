#!/usr/bin/env python3
"""Sync pgbudget database → Obsidian vault markdown files."""

import psycopg2
import psycopg2.extras
from datetime import date, datetime, timedelta
from pathlib import Path
import sys

VAULT = Path("/home/pgbudget/obsidian/pgbudget")
LEDGER_UUID = "eNF2EkfD"
USER_DATA = "m43str0"
DSN = "host=/var/run/postgresql dbname=pgbudget user=pgbudget sslmode=disable"


def connect():
    conn = psycopg2.connect(DSN)
    conn.autocommit = True
    cur = conn.cursor()
    cur.execute("SELECT set_config('app.current_user_id', %s, false)", (USER_DATA,))
    return conn, psycopg2.extras.RealDictCursor(conn)


def q(cur, sql, params=None):
    cur.execute(sql, params)
    return cur.fetchall()


def brl(cents, parens_negative=False):
    """Format cents as R$ 1,234.56"""
    if cents is None:
        return "—"
    cents = int(cents)
    neg = cents < 0
    v = abs(cents)
    dollars = v // 100
    rem = v % 100
    s = f"{dollars:,}.{rem:02d}"
    if neg:
        if parens_negative:
            return f"−R\\$ {s} *(credit balance)*"
        return f"−R\\$ {s}"
    return f"R\\$ {s}"


def brl_sign(cents):
    """Format with explicit +/- sign."""
    if cents is None:
        return "—"
    cents = int(cents)
    prefix = "+" if cents >= 0 else "−"
    v = abs(cents)
    dollars = v // 100
    rem = v % 100
    return f"{prefix}R\\$ {v//100:,}.{rem:02d}"


def today_str():
    return date.today().isoformat()


def month_name(d):
    months = ["January", "February", "March", "April", "May", "June",
              "July", "August", "September", "October", "November", "December"]
    return months[d.month - 1]


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


# ── Account balances helper ───────────────────────────────────────────────────
# Balances come from utils.get_ledger_current_balances (same as the app): it skips
# DELETED:/REVERSAL: rows and corrected originals. balance_snapshots does not.

BALANCE_SQL = """
SELECT a.id, a.name, a.type,
    COALESCE(
        (SELECT cb.current_balance FROM utils.get_ledger_current_balances(%(ledger)s) cb
             WHERE cb.account_uuid = a.uuid),
        0
    ) AS balance
FROM data.accounts a
JOIN data.ledgers l ON l.id = a.ledger_id
WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s AND a.is_group = false
ORDER BY a.type, a.sort_order NULLS LAST, a.id
"""


def get_balances(cur):
    rows = q(cur, BALANCE_SQL, {"u": USER_DATA, "ledger": LEDGER_UUID})
    return rows


# ── Category spending helper ──────────────────────────────────────────────────
# Budget categories in this app are mostly type 'equity' (only a few are
# 'expense'). Spending = outflows from real (asset/liability) accounts into a
# category, net of refunds; internal budget moves between categories are ignored,
# as are DELETED:/REVERSAL: rows and corrected originals (same rule as balances).
SPENDING_SQL = """
    SELECT CASE WHEN cat.name = 'Unassigned' THEN 'Unassigned (sem categoria)' ELSE cat.name END AS name,
           SUM(CASE WHEN t.debit_account_id = cat.id THEN t.amount ELSE -t.amount END) AS total
    FROM data.transactions t
    JOIN data.ledgers l ON l.id = t.ledger_id AND l.uuid = %(ledger)s
    JOIN data.accounts cat ON cat.id IN (t.debit_account_id, t.credit_account_id)
    JOIN data.accounts other ON other.id = CASE WHEN t.debit_account_id = cat.id
                                                THEN t.credit_account_id ELSE t.debit_account_id END
    LEFT JOIN data.transaction_log tl ON tl.original_transaction_id = t.id
    WHERE t.user_data = %(u)s AND t.deleted_at IS NULL AND tl.id IS NULL
      AND t.description NOT LIKE 'DELETED:%%' AND t.description NOT LIKE 'REVERSAL:%%'
      AND cat.type IN ('expense', 'equity')  -- groups too: some get postings directly
      AND cat.name NOT IN ('Income', 'Off-budget') AND cat.name NOT LIKE 'CC Payment:%%'
      AND other.type IN ('asset', 'liability')
      AND t.date >= %(start)s AND t.date < %(end)s
    GROUP BY 1
    HAVING SUM(CASE WHEN t.debit_account_id = cat.id THEN t.amount ELSE -t.amount END) > 0
    ORDER BY 2 DESC
"""

# ── Home.md ───────────────────────────────────────────────────────────────────

def gen_home(cur):
    balances = get_balances(cur)

    assets = [r for r in balances if r["type"] == "asset"]
    liabilities = [r for r in balances if r["type"] == "liability"]

    total_assets = sum(r["balance"] for r in assets)

    # Credit card debt = liability accounts with negative balance (money owed)
    # Liability balance is negative when owed (debit reduces liability)
    cc_rows = q(cur, """
        SELECT a.name, COALESCE(
            (SELECT cb.current_balance FROM utils.get_ledger_current_balances(%(ledger)s) cb
             WHERE cb.account_uuid = a.uuid), 0
        ) AS balance
        FROM data.accounts a
        JOIN data.ledgers l ON l.id = a.ledger_id
        WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s
          AND a.metadata->>'liability_subtype' = 'credit_card' AND a.is_group = false
    """, {"u": USER_DATA, "ledger": LEDGER_UUID})

    cc_owed = sum(-r["balance"] for r in cc_rows if r["balance"] < 0)

    loan_rows = q(cur, """
        SELECT COALESCE(SUM(current_balance), 0) AS total
        FROM api.loans WHERE ledger_uuid = %(ledger)s
    """, {"ledger": LEDGER_UUID})
    total_loans = loan_rows[0]["total"] if loan_rows else 0

    net_worth = total_assets - cc_owed - total_loans

    # Monthly income (last 4 months) — inflows to asset accounts from equity (salary/income)
    income_rows = q(cur, """
        SELECT
            to_char(date_trunc('month', t.date), 'Month YYYY') AS month,
            SUM(t.amount) AS total
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        WHERE t.user_data = %(u)s
          AND t.deleted_at IS NULL
          AND ca.name = 'Income'
          AND da.type = 'asset'
          AND t.description NOT LIKE 'DELETED:%%'
          AND t.description NOT LIKE 'Budget reduction%%'
          AND t.date >= date_trunc('month', CURRENT_DATE) - INTERVAL '3 months'
        GROUP BY date_trunc('month', t.date)
        ORDER BY date_trunc('month', t.date) DESC
    """, {"u": USER_DATA})

    # Top spending current month
    _m0 = date.today().replace(day=1)
    _m1 = (_m0.replace(day=28) + timedelta(days=4)).replace(day=1)
    top_rows = q(cur, SPENDING_SQL, {"u": USER_DATA, "ledger": LEDGER_UUID,
                                     "start": _m0, "end": _m1})[:8]

    cur_month = month_name(date.today())
    cur_year = date.today().year

    income_table = "\n".join(
        f"| {r['month'].strip()} | {brl(r['total'])} |"
        for r in income_rows
    )

    top_table = "\n".join(
        f"| {r['name']} | {brl(r['total'])} |"
        for r in top_rows
    )

    # Navigation: last 4 months
    months_nav = []
    for i in range(4):
        d = date.today().replace(day=1)
        for _ in range(i):
            d = (d - timedelta(days=1)).replace(day=1)
        months_nav.append(f"  - [[Budget/{month_name(d)} {d.year}|{month_name(d)} {d.year} Budget]]")
    months_nav_str = "\n".join(months_nav)

    content = f"""# PGBudget — Ledger Dashboard
**Ledger:** `{LEDGER_UUID}` · **User:** {USER_DATA} · **Updated:** {today_str()} · **Currency:** BRL (R$)

---

## Net Worth Snapshot

| Category | Balance |
|---|---|
| **Total Assets** | {brl(total_assets)} |
| **Credit Card Debt (owed)** | {brl(cc_owed)} |
| **Total Loans** | {brl(total_loans)} |
| **Net Worth** | **{brl(net_worth)}** |

> [!info] Ledger `{LEDGER_UUID}` — data as of {today_str()}

---

## Monthly Income

| Month | Gross Income |
|---|---|
{income_table}

---

## Quick Navigation

- [[Accounts/Accounts Overview|Accounts Overview]]
  - [[Accounts/Checking Accounts|Checking Accounts]]
  - [[Accounts/Credit Cards|Credit Cards]]
  - [[Accounts/Loans|Loans]]
- [[Budget/Budget Overview|Budget Overview]]
{months_nav_str}
- [[Transactions/Recent Transactions|Recent Transactions]]
- [[Income/Income Sources|Income Sources]]
- [[Projected Events/Recurring Events|Recurring Events]]
- [[Projected Events/One-time Events|One-time Events]]

---

## Top Spending — {cur_month} {cur_year}

| Category | Amount |
|---|---|
{top_table}
"""
    write(VAULT / "Home.md", content)


# ── Accounts Overview ─────────────────────────────────────────────────────────

def gen_accounts_overview(cur):
    rows = get_balances(cur)

    assets = [r for r in rows if r["type"] == "asset"]
    total_assets = sum(r["balance"] for r in assets)

    asset_rows = "\n".join(
        f"| {r['name']} | {brl(r['balance'])} |" for r in assets
    )

    cc_rows = q(cur, """
        SELECT a.name, COALESCE(
            (SELECT cb.current_balance FROM utils.get_ledger_current_balances(%(ledger)s) cb
             WHERE cb.account_uuid = a.uuid), 0
        ) AS balance
        FROM data.accounts a
        JOIN data.ledgers l ON l.id = a.ledger_id
        WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s
          AND a.metadata->>'liability_subtype' = 'credit_card' AND a.is_group = false
        ORDER BY a.sort_order NULLS LAST, a.id
    """, {"u": USER_DATA, "ledger": LEDGER_UUID})

    cc_table = "\n".join(
        f"| {r['name']} | {brl(-r['balance'], parens_negative=(r['balance'] > 0))} |"
        for r in cc_rows
    )

    loan_rows = q(cur, """
        SELECT account_name, current_balance
        FROM api.loans WHERE ledger_uuid = %(ledger)s
        ORDER BY current_balance DESC
    """, {"ledger": LEDGER_UUID})
    total_loans = sum(r["current_balance"] for r in loan_rows)

    loan_table = "\n".join(
        f"| {r['account_name']} | {brl(r['current_balance'])} |"
        for r in loan_rows
    )

    content = f"""# Accounts Overview
← [[../Home|Home]]

**Ledger:** `{LEDGER_UUID}` · **Updated:** {today_str()}

---

## Asset Accounts

| Account | Balance |
|---|---|
{asset_rows}
| **Total** | **{brl(total_assets)}** |

---

## Credit Cards

| Account | Balance Owed |
|---|---|
{cc_table}

> [!note] A negative balance on a credit card means you have a credit / the card owes you money (overpayment or pending charges not yet posted).

---

## Loans & Personal Debt

| Account | Balance |
|---|---|
{loan_table}
| **Total** | **{brl(total_loans)}** |

---

## See Also

- [[Checking Accounts|Checking Accounts detail]]
- [[Credit Cards|Credit Cards detail]]
- [[Loans|Loans detail]]
"""
    write(VAULT / "Accounts/Accounts Overview.md", content)


# ── Checking Accounts ─────────────────────────────────────────────────────────

def gen_checking_accounts(cur):
    assets = q(cur, """
        SELECT a.id, a.name, a.description, COALESCE(
            (SELECT cb.current_balance FROM utils.get_ledger_current_balances(%(ledger)s) cb
             WHERE cb.account_uuid = a.uuid), 0
        ) AS balance
        FROM data.accounts a
        JOIN data.ledgers l ON l.id = a.ledger_id
        WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s
          AND a.type = 'asset' AND a.is_group = false
        ORDER BY a.sort_order NULLS LAST, a.id
    """, {"u": USER_DATA, "ledger": LEDGER_UUID})

    cur_month_start = date.today().replace(day=1)

    sections = []
    for acc in assets:
        txs = q(cur, """
            SELECT t.date, t.description, t.amount,
                CASE WHEN t.debit_account_id = %(aid)s THEN 'debit' ELSE 'credit' END AS side
            FROM data.transactions t
            WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
              AND (t.debit_account_id = %(aid)s OR t.credit_account_id = %(aid)s)
              AND t.date >= %(start)s
            ORDER BY t.date DESC, t.id DESC
            LIMIT 15
        """, {"u": USER_DATA, "aid": acc["id"], "start": cur_month_start})

        desc_line = f"**Type:** Asset — {acc['description']}" if acc["description"] else "**Type:** Asset"

        section = f"""## {acc['name']}
**Balance:** {brl(acc['balance'])}
{desc_line}
"""
        if txs:
            tx_rows = "\n".join(
                f"| {r['date']} | {r['description']} | "
                f"{'+'if r['side']=='debit' else '−'}R\\$ {abs(r['amount'])//100:,}.{abs(r['amount'])%100:02d} |"
                for r in txs
            )
            section += f"""
### Recent Activity ({month_name(date.today())} {date.today().year})
| Date | Description | Amount |
|---|---|---|
{tx_rows}
"""
        sections.append(section)

    sep = "---\n\n"
    content = f"""# Checking Accounts
← [[Accounts Overview]] · [[../Home|Home]]

---

{sep.join(sections)}"""
    write(VAULT / "Accounts/Checking Accounts.md", content)


# ── Credit Cards ──────────────────────────────────────────────────────────────

def gen_credit_cards(cur):
    cards = q(cur, """
        SELECT a.id, a.name, a.description, COALESCE(
            (SELECT cb.current_balance FROM utils.get_ledger_current_balances(%(ledger)s) cb
             WHERE cb.account_uuid = a.uuid), 0
        ) AS balance
        FROM data.accounts a
        JOIN data.ledgers l ON l.id = a.ledger_id
        WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s
          AND a.metadata->>'liability_subtype' = 'credit_card' AND a.is_group = false
        ORDER BY a.sort_order NULLS LAST, a.id
    """, {"u": USER_DATA, "ledger": LEDGER_UUID})

    cur_month_start = date.today().replace(day=1)
    sections = []

    for card in cards:
        bal = card["balance"]
        # Liability: negative balance = you owe money; positive = credit in your favor
        if bal < 0:
            bal_line = f"**Balance Owed:** {brl(-bal)}  \n**Type:** Liability"
        else:
            bal_line = f"**Balance:** {brl(-bal, parens_negative=True)}  \n**Type:** Liability"

        txs = q(cur, """
            SELECT t.date, t.description, t.amount,
                CASE WHEN t.debit_account_id = %(aid)s THEN 'debit' ELSE 'credit' END AS side
            FROM data.transactions t
            WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
              AND (t.debit_account_id = %(aid)s OR t.credit_account_id = %(aid)s)
              AND t.date >= %(start)s
            ORDER BY t.date DESC, t.id DESC
            LIMIT 20
        """, {"u": USER_DATA, "aid": card["id"], "start": cur_month_start})

        section = f"""## {card['name']}
{bal_line}
"""
        if txs:
            tx_rows = "\n".join(
                f"| {r['date']} | {r['description']} | R\\$ {r['amount']//100:,}.{r['amount']%100:02d} |"
                for r in txs
            )
            section += f"""
### Recent Charges
| Date | Description | Amount |
|---|---|---|
{tx_rows}
"""
        sections.append(section)

    sep = "---\n\n"
    content = f"""# Credit Cards
← [[Accounts Overview]] · [[../Home|Home]]

---

{sep.join(sections)}"""
    write(VAULT / "Accounts/Credit Cards.md", content)


# ── Loans ─────────────────────────────────────────────────────────────────────

def gen_loans(cur):
    loans = q(cur, """
        SELECT account_name, lender_name, loan_type, current_balance,
               payment_amount, notes
        FROM api.loans WHERE ledger_uuid = %(ledger)s
        ORDER BY current_balance DESC
    """, {"ledger": LEDGER_UUID})

    total = sum(r["current_balance"] for r in loans)
    active = [r for r in loans if r["current_balance"] > 0]
    zeroed = [r for r in loans if r["current_balance"] == 0]

    active_rows = "\n".join(
        f"""
### {r['account_name']}
**Balance:** {brl(r['current_balance'])}
**Monthly payment:** {brl(r['payment_amount']) if r['payment_amount'] else '—'}
**Type:** {r['loan_type'] or r['lender_name'] or 'Loan'}
"""
        for r in active
    )

    zero_rows = "\n".join(
        f"| {r['account_name']} | R\\$ 0.00 |"
        for r in zeroed
    )

    zero_section = ""
    if zeroed:
        zero_section = f"""---

## Zero-Balance Loans (paid / closed)

| Loan | Status |
|---|---|
{zero_rows}
"""

    content = f"""# Loans & Personal Debt
← [[Accounts Overview]] · [[../Home|Home]]

---

> [!warning] Total Loan Debt: {brl(total)}

---

{active_rows}

{zero_section}"""
    write(VAULT / "Accounts/Loans.md", content)


# ── Budget Overview ───────────────────────────────────────────────────────────

def gen_budget_overview(cur):
    cats = q(cur, """
        SELECT a.name, p.name AS parent
        FROM data.accounts a
        LEFT JOIN data.accounts p ON p.id = a.parent_category_id
        JOIN data.ledgers l ON l.id = a.ledger_id
        WHERE l.uuid = %(ledger)s AND a.user_data = %(u)s
          AND a.type IN ('expense', 'equity') AND a.is_group = false AND a.deleted_at IS NULL
          AND a.name NOT IN ('Income', 'Off-budget', 'Unassigned') AND a.name NOT LIKE 'CC Payment:%%'
        ORDER BY p.name NULLS LAST, a.name
    """, {"u": USER_DATA, "ledger": LEDGER_UUID})

    cat_rows = "\n".join(
        f"| {r['name']} | {r['parent'] or ''} |"
        for r in cats
    )

    # Last 4 months nav
    month_links = []
    d = date.today().replace(day=1)
    for _ in range(4):
        month_links.append(f"| {month_name(d)} {d.year} | [[{month_name(d)} {d.year}]] |")
        d = (d - timedelta(days=1)).replace(day=1)

    month_nav = "\n".join(month_links)

    content = f"""# Budget Overview
← [[../Home|Home]]

---

## Budget Categories

| Category | Group |
|---|---|
{cat_rows}

---

## Monthly Summary

| Month | Budget Page |
|---|---|
{month_nav}
"""
    write(VAULT / "Budget/Budget Overview.md", content)


# ── Monthly Budget ────────────────────────────────────────────────────────────

def gen_monthly_budget(cur, year, month):
    month_start = date(year, month, 1)
    if month == 12:
        month_end = date(year + 1, 1, 1)
    else:
        month_end = date(year, month + 1, 1)
    label = f"{month_name(month_start)} {year}"

    # Income: inflows from the Income equity account to any asset
    income_rows = q(cur, """
        SELECT t.description, t.amount
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
          AND ca.name = 'Income' AND da.type = 'asset'
          AND t.description NOT LIKE 'DELETED:%%'
          AND t.description NOT LIKE 'Budget reduction%%'
          AND t.date >= %(start)s AND t.date < %(end)s
        ORDER BY t.amount DESC
    """, {"u": USER_DATA, "start": month_start, "end": month_end})

    total_income = sum(r["amount"] for r in income_rows)

    income_table = "\n".join(
        f"| {r['description']} | {brl_sign(r['amount'])} |"
        for r in income_rows
    )

    # Expenses by category
    expense_rows = q(cur, SPENDING_SQL, {"u": USER_DATA, "ledger": LEDGER_UUID,
                                         "start": month_start, "end": month_end})

    total_expenses = sum(r["total"] for r in expense_rows)

    expense_table = "\n".join(
        f"| {r['name']} | {brl(r['total'])} | |"
        for r in expense_rows
    )

    # Notable transactions
    notable = q(cur, """
        SELECT t.date, t.description, t.amount,
               da.type AS debit_type, ca.type AS credit_type
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
          AND t.date >= %(start)s AND t.date < %(end)s
          AND t.amount >= 5000
          AND t.description NOT LIKE 'DELETED:%%'
          AND t.description NOT LIKE 'REVERSAL:%%'
          AND t.description NOT LIKE 'Budget reduction%%'
        ORDER BY t.amount DESC
        LIMIT 15
    """, {"u": USER_DATA, "start": month_start, "end": month_end})

    notable_rows = "\n".join(
        f"| {r['date']} | {r['description']} | "
        f"{'+'if r['debit_type']=='asset' else '−'}R\\$ {r['amount']//100:,}.{r['amount']%100:02d} |"
        for r in notable
    )

    # Prev/next month links
    prev_d = (month_start - timedelta(days=1)).replace(day=1)
    prev_label = f"{month_name(prev_d)} {prev_d.year}"
    if month == 12:
        next_d = date(year + 1, 1, 1)
    else:
        next_d = date(year, month + 1, 1)
    next_label = f"{month_name(next_d)} {next_d.year}"

    content = f"""# Budget — {label}
← [[Budget Overview]] · [[../Home|Home]]
← [[{prev_label}]] | [[{next_label}]] →

---

## Income

| Source | Amount |
|---|---|
{income_table}
| **Total Gross Income** | **{brl(total_income)}** |

---

## Expenses

| Category | Amount | Notes |
|---|---|---|
{expense_table}
| **Total Tracked** | **{brl(total_expenses)}** | |

---

## Notable Transactions

| Date | Description | Amount |
|---|---|---|
{notable_rows}
"""
    write(VAULT / f"Budget/{label}.md", content)


# ── Income Sources ────────────────────────────────────────────────────────────

def gen_income_sources(cur):
    sources = q(cur, """
        SELECT name, income_type, frequency, amount, start_date, end_date,
               occurrence_months, notes, is_active
        FROM api.income_sources
        WHERE ledger_uuid = %(ledger)s
        ORDER BY amount DESC
    """, {"ledger": LEDGER_UUID})

    freq_map = {"monthly": "Monthly", "annual": "Annual", "semiannual": "Semiannual",
                "one_time": "One-time", "biweekly": "Biweekly"}

    rows = "\n".join(
        f"| {r['name']} | {brl(r['amount'])} | {freq_map.get(r['frequency'], r['frequency'])} |"
        f"{'  *(inactive)*' if not r['is_active'] else ''}"
        for r in sources
    )

    # Monthly totals (last 4 months)
    monthly = q(cur, """
        SELECT
            to_char(date_trunc('month', t.date), 'Month YYYY') AS month,
            SUM(t.amount) AS total
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
          AND ca.name = 'Income' AND da.type = 'asset'
          AND t.description NOT LIKE 'DELETED:%%'
          AND t.description NOT LIKE 'Budget reduction%%'
          AND t.date >= date_trunc('month', CURRENT_DATE) - INTERVAL '3 months'
        GROUP BY date_trunc('month', t.date)
        ORDER BY date_trunc('month', t.date) DESC
    """, {"u": USER_DATA})

    monthly_rows = "\n".join(
        f"| {r['month'].strip()} | {brl(r['total'])} |"
        for r in monthly
    )

    content = f"""# Income Sources
← [[../Home|Home]]

---

## Configured Income Sources

| Name | Amount | Frequency |
|---|---|---|
{rows}

---

## Monthly Income Summary

| Month | Total Income |
|---|---|
{monthly_rows}
"""
    write(VAULT / "Income/Income Sources.md", content)


# ── Recent Transactions ───────────────────────────────────────────────────────

def gen_recent_transactions(cur):
    txs = q(cur, """
        SELECT t.date, t.description, t.amount,
               da.name AS debit_account,
               ca.name AS credit_account
        FROM data.transactions t
        JOIN data.accounts da ON da.id = t.debit_account_id
        JOIN data.accounts ca ON ca.id = t.credit_account_id
        WHERE t.user_data = %(u)s AND t.deleted_at IS NULL
          AND t.description NOT LIKE 'DELETED:%%'
          AND t.description NOT LIKE 'REVERSAL:%%'
          AND t.description NOT LIKE 'Budget reduction%%'
        ORDER BY t.date DESC, t.id DESC
        LIMIT 50
    """, {"u": USER_DATA})

    rows = "\n".join(
        f"| {r['date']} | {r['description']} | {r['debit_account']} | {r['credit_account']} | "
        f"R\\$ {r['amount']//100:,}.{r['amount']%100:02d} |"
        for r in txs
    )

    content = f"""# Recent Transactions
← [[../Home|Home]]

**Last updated:** {today_str()} · Showing most recent 50 transactions

---

| Date | Description | Debit Account | Credit Account | Amount |
|---|---|---|---|---|
{rows}
"""
    write(VAULT / "Transactions/Recent Transactions.md", content)


# ── Projected Events ──────────────────────────────────────────────────────────

def gen_projected_events(cur):
    recurring = q(cur, """
        SELECT name, amount, direction, frequency, event_date, recurrence_end_date, notes
        FROM api.projected_events
        WHERE ledger_uuid = %(ledger)s AND frequency != 'one_time'
        ORDER BY direction, amount DESC
    """, {"ledger": LEDGER_UUID})

    one_time_upcoming = q(cur, """
        SELECT name, amount, direction, event_date, is_realized, notes
        FROM api.projected_events
        WHERE ledger_uuid = %(ledger)s AND frequency = 'one_time'
          AND event_date >= CURRENT_DATE
        ORDER BY event_date ASC
    """, {"ledger": LEDGER_UUID})

    one_time_past = q(cur, """
        SELECT name, amount, direction, event_date, is_realized, notes
        FROM api.projected_events
        WHERE ledger_uuid = %(ledger)s AND frequency = 'one_time'
          AND event_date < CURRENT_DATE
        ORDER BY event_date DESC
        LIMIT 15
    """, {"ledger": LEDGER_UUID})

    freq_map = {"monthly": "Monthly", "annual": "Annual", "semiannual": "Semiannual"}

    monthly_out = [r for r in recurring if r["frequency"] == "monthly" and r["direction"] == "outflow"]
    monthly_in = [r for r in recurring if r["frequency"] == "monthly" and r["direction"] == "inflow"]
    annual = [r for r in recurring if r["frequency"] in ("annual", "semiannual")]

    def rec_row(r):
        end = str(r["recurrence_end_date"]) if r["recurrence_end_date"] else "—"
        sign = "+" if r["direction"] == "inflow" else ""
        note = r["notes"] or ""
        return f"| {r['name']} | {sign}{brl(r['amount'])} | {str(r['event_date'])} | {end} | {note} |"

    mo_rows = "\n".join(rec_row(r) for r in monthly_out)
    mi_rows = "\n".join(rec_row(r) for r in monthly_in)
    an_rows = "\n".join(rec_row(r) for r in annual)

    monthly_in_section = ""
    if mi_rows:
        monthly_in_section = f"""## Monthly Inflows

| Name | Amount | Start | End | Notes |
|---|---|---|---|---|
{mi_rows}

"""

    annual_section = ""
    if an_rows:
        annual_section = f"""## Annual / Semiannual

| Name | Amount | Start | End | Notes |
|---|---|---|---|---|
{an_rows}
"""

    rec_content = f"""# Recurring Projected Events
← [[../Home|Home]]

---

## Monthly Outflows

| Name | Amount | Start | End | Notes |
|---|---|---|---|---|
{mo_rows}

{monthly_in_section}{annual_section}"""
    write(VAULT / "Projected Events/Recurring Events.md", rec_content)

    def ot_row(r):
        sign = "+" if r["direction"] == "inflow" else ""
        realized = " *(realized)*" if r["is_realized"] else ""
        return f"| {r['name']} | {sign}{brl(r['amount'])} | {r['event_date']} | {r['direction'].title()}{realized} |"

    upcoming_rows = "\n".join(ot_row(r) for r in one_time_upcoming)
    past_rows = "\n".join(ot_row(r) for r in one_time_past)

    ot_content = f"""# One-time Projected Events
← [[../Home|Home]]

---

## Upcoming / Pending

| Name | Amount | Date | Direction |
|---|---|---|---|
{upcoming_rows}

---

## Past One-time Events

| Name | Amount | Date | Direction |
|---|---|---|---|
{past_rows}
"""
    write(VAULT / "Projected Events/One-time Events.md", ot_content)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    conn, cur = connect()
    print(f"[{datetime.now():%Y-%m-%d %H:%M}] Syncing vault...", flush=True)

    gen_home(cur)
    print("  ✓ Home.md")

    gen_accounts_overview(cur)
    print("  ✓ Accounts Overview")

    gen_checking_accounts(cur)
    print("  ✓ Checking Accounts")

    gen_credit_cards(cur)
    print("  ✓ Credit Cards")

    gen_loans(cur)
    print("  ✓ Loans")

    gen_budget_overview(cur)
    print("  ✓ Budget Overview")

    # Current month + last 3 months
    d = date.today().replace(day=1)
    for _ in range(4):
        gen_monthly_budget(cur, d.year, d.month)
        print(f"  ✓ Budget {month_name(d)} {d.year}")
        d = (d - timedelta(days=1)).replace(day=1)

    gen_income_sources(cur)
    print("  ✓ Income Sources")

    gen_recent_transactions(cur)
    print("  ✓ Recent Transactions")

    gen_projected_events(cur)
    print("  ✓ Projected Events")

    conn.close()

    git_push()
    print(f"[{datetime.now():%Y-%m-%d %H:%M}] Done.", flush=True)


def git_push():
    import subprocess

    def run(cmd):
        return subprocess.run(cmd, cwd=VAULT, capture_output=True, text=True)

    # Check for any changes (staged or unstaged)
    diff = run(["git", "diff", "--quiet"])
    if diff.returncode == 0:
        print("  ✓ No changes, skipping git push")
        return

    run(["git", "add", "-A"])

    msg = f"sync: auto-update from database [{datetime.now():%Y-%m-%d %H:%M}]"
    commit = run(["git", "commit", "-m", msg])
    if commit.returncode != 0:
        print(f"  ✗ git commit failed: {commit.stderr.strip()}", flush=True)
        return
    print(f"  ✓ Committed: {msg}")

    push = run(["git", "push", "origin", "main"])
    if push.returncode != 0:
        print(f"  ✗ git push failed: {push.stderr.strip()}", flush=True)
    else:
        print("  ✓ Pushed to origin/main")


if __name__ == "__main__":
    main()
