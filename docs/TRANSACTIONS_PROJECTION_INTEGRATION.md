# Transactions ↔ Projection Integration

## Context

Recorded transactions (via Telegram bot or web UI) are currently invisible in the cash-flow-projection. This document describes the plan to fix that with two behaviours:

1. **Auto-match**: when a transaction is inserted, try to find a matching planned `projected_event` and automatically realize it (event shifts from planned to realized at the actual transaction date — no standalone row needed).
2. **Standalone fallback**: when no match is found, the transaction itself appears as a new "Actual Transactions" group in the projection (counted in the net balance).

No double-counting: matched transactions realize the event (which already counts in the balance via `realized_occurrence`), so they are excluded from the standalone group.

---

## Architecture

### Direction derivation (from `data.accounts.type`)

| Condition | Direction |
|-----------|-----------|
| `debit_account.type = 'asset'` | **inflow** (money into checking/savings) |
| `credit_account.type = 'asset'` | **outflow** (money out of checking) |
| `credit_account.type = 'liability'` | **outflow** (credit card charge) |
| Otherwise | `'unknown'` — skip matching and standalone display |

### Scoring algorithm (threshold ≥ 60 to auto-match)

| Criterion | Points |
|-----------|--------|
| Amount diff ≤ R$1 | 50 |
| Amount diff ≤ 5% | 40 |
| Amount diff ≤ 10% | 30 |
| Amount diff ≤ 20% | 20 |
| Amount diff ≤ 50% | 10 |
| Amount diff > 50% | **disqualify** |
| Same month | 30 |
| ±1 month | 15 |
| ±2 months | 5 |
| > 2 months | **disqualify** |
| `similarity(name, description) > 0.4` (pg_trgm) | 20 |
| `similarity(name, description) > 0.2` | 10 |
| Name is substring of description (case-insensitive) | +15 bonus |

- Direction must match the event's `direction` field (required filter, not scored).
- Skip matching for: `metadata->>'skip_auto_match' = 'true'`, CC budget move transactions, and reversal transactions (those referenced in `transaction_log.reversal_transaction_id`).

### Exclusion from standalone branch

A transaction is excluded from the "Actual Transactions" group if:
- Linked to a **realized one-time event**: `EXISTS (projected_events WHERE linked_transaction_id = t.id AND is_realized = true)`
- Linked to a **realized occurrence**: `EXISTS (projected_event_occurrences WHERE transaction_id = t.id AND is_realized = true)`

---

## Step 1 — Migration `migrations/20260228000001_integrate_transactions_projections.sql`

Goose file with 11 `StatementBegin/End` blocks in this exact order:

**Block 1** — Enable pg_trgm extension (needed for `similarity()`)
```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

**Block 2** — Add `transaction_id` to `data.projected_event_occurrences`
```sql
ALTER TABLE data.projected_event_occurrences
    ADD COLUMN transaction_id bigint
        REFERENCES data.transactions(id) ON DELETE SET NULL;
CREATE INDEX idx_peo_transaction_id
    ON data.projected_event_occurrences (transaction_id)
    WHERE transaction_id IS NOT NULL;
```
`ON DELETE SET NULL` means deleting a transaction clears the link — the occurrence stays realized, but as "manual" (no backing transaction).

**Block 3** — GIN trigram index on projected event names
```sql
CREATE INDEX idx_projected_events_name_trgm
    ON data.projected_events USING gin (name gin_trgm_ops);
```

**Block 4** — Drop view-dependent functions + view

These must be dropped before the view can be recreated, because they declare `RETURNS SETOF api.projected_event_occurrences` (the return type is bound to the view's column list at creation time).

```sql
DROP FUNCTION IF EXISTS api.realize_projected_event_occurrence(text, date, date, bigint, text);
DROP FUNCTION IF EXISTS api.get_projected_event_occurrences(text);
DROP FUNCTION IF EXISTS api.unrealize_projected_event_occurrence(text, date);
DROP VIEW IF EXISTS api.projected_event_occurrences;
```

**Block 5** — Recreate `api.projected_event_occurrences` view with new `transaction_id` and `transaction_uuid` columns
```sql
CREATE VIEW api.projected_event_occurrences AS
SELECT o.uuid, e.uuid AS projected_event_uuid, o.scheduled_month,
       o.is_realized, o.realized_date, o.realized_amount, o.notes,
       o.transaction_id, t.uuid AS transaction_uuid,
       o.created_at, o.updated_at
FROM data.projected_event_occurrences o
JOIN data.projected_events e ON e.id = o.projected_event_id
LEFT JOIN data.transactions t ON t.id = o.transaction_id
WHERE o.user_data = utils.get_user();
GRANT SELECT ON api.projected_event_occurrences TO pgbudget_user;
```

**Block 6** — Recreate `api.realize_projected_event_occurrence` with new `p_transaction_uuid text DEFAULT NULL` parameter. Resolves the UUID to an internal ID and stores it in the occurrence row. Grant both the 5-arg (old callers) and 6-arg (new callers) overloads.

**Block 7** — Recreate `api.unrealize_projected_event_occurrence` and `api.get_projected_event_occurrences` (logic unchanged — verbatim from migration `20260219000002`).

**Block 8** — Create `utils.derive_transaction_direction(p_debit_id bigint, p_credit_id bigint) RETURNS text`

```sql
-- Reads data.accounts for both IDs, returns 'inflow' | 'outflow' | 'unknown'
-- debit.type = 'asset'                    → 'inflow'
-- credit.type IN ('asset', 'liability')   → 'outflow'
-- else                                    → 'unknown'
-- SECURITY DEFINER; STABLE;
-- GRANT EXECUTE ON FUNCTION ... TO pgbudget_user;
```

**Block 9** — Create `utils.auto_match_projected_event(p_transaction_id bigint) RETURNS void`

Entire function is wrapped in `EXCEPTION WHEN OTHERS THEN NULL` — a matching failure must never abort the transaction insert.

Logic:
1. Fetch the transaction (id, amount, date, description, ledger_id, debit/credit account ids, metadata). Skip if not found for current user.
2. Skip if metadata flags are set: `skip_auto_match`, `is_cc_budget_move`, `is_cc_payment_budget_reduction`.
3. Skip if transaction is a reversal: `EXISTS (SELECT 1 FROM data.transaction_log WHERE reversal_transaction_id = p_transaction_id)`.
4. Derive direction via `utils.derive_transaction_direction()`; return if `'unknown'`.
5. Score candidate projected events:
   - Same `ledger_id`, same `direction`, `is_realized = false`, event month within ±2 months of transaction date.
   - Score = `amount_score + date_score + name_score` using `similarity()`.
   - Pick highest-scoring candidate with `score >= 60`; tie-break by closest amount.
6. If match found:
   - **One-time** (`frequency = 'one_time'`): `UPDATE data.projected_events SET is_realized = true, linked_transaction_id = p_transaction_id WHERE id = best.id`
   - **Recurring** (`monthly/annual/semiannual`): `INSERT INTO data.projected_event_occurrences (..., transaction_id, is_realized = true, realized_date = tx.date, scheduled_month = date_trunc('month', best.event_date)) ON CONFLICT (...) DO UPDATE SET is_realized = true, transaction_id = EXCLUDED.transaction_id, realized_date = EXCLUDED.realized_date`
7. Store result in tx metadata: `UPDATE data.transactions SET metadata = COALESCE(metadata,'{}') || jsonb_build_object('matched_event_uuid', best.uuid, 'matched_event_name', best.name) WHERE id = p_transaction_id`

`SECURITY DEFINER;` — `GRANT EXECUTE TO pgbudget_user;`

**Block 10** — Create AFTER INSERT trigger on `data.transactions`
```sql
CREATE OR REPLACE FUNCTION utils.tg_auto_match_projected_event_fn() RETURNS trigger AS $$
BEGIN
    PERFORM utils.auto_match_projected_event(NEW.id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER tg_auto_match_projected_event
    AFTER INSERT ON data.transactions
    FOR EACH ROW EXECUTE FUNCTION utils.tg_auto_match_projected_event_fn();
```

**Block 11** — Drop and recreate `api.generate_cash_flow_projection()` to add Branch 9.

`api.get_projection_summary()` does NOT need changes — it already sums all amounts regardless of `source_type`.

Branch 9 SQL (UNION ALL appended before the function's closing `RETURN QUERY`):
```sql
-- Branch 9: Actual transactions not linked to any realized projected event
SELECT
    date_trunc('month', t.date)::date AS month,
    'transaction'                      AS source_type,
    t.id                               AS source_id,
    t.uuid                             AS source_uuid,
    'transaction'                      AS category,
    utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) AS subcategory,
    t.description,
    CASE utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id)
        WHEN 'inflow'  THEN  t.amount
        WHEN 'outflow' THEN -t.amount
        ELSE 0
    END AS amount
FROM data.transactions t
WHERE t.ledger_id = v_ledger_id
  AND t.user_data = v_user_data
  AND t.deleted_at IS NULL
  AND date_trunc('month', t.date) BETWEEN p_start_month AND v_end_month
  AND utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) <> 'unknown'
  -- Exclude reversal transactions (bookkeeping entries, not real cash flow)
  AND NOT EXISTS (
      SELECT 1 FROM data.transaction_log tl WHERE tl.reversal_transaction_id = t.id
  )
  -- Exclude transactions that realized a one-time event
  AND NOT EXISTS (
      SELECT 1 FROM data.projected_events pe
      WHERE pe.linked_transaction_id = t.id AND pe.is_realized = true
  )
  -- Exclude transactions that realized a recurring occurrence
  AND NOT EXISTS (
      SELECT 1 FROM data.projected_event_occurrences peo
      WHERE peo.transaction_id = t.id AND peo.is_realized = true
  )
```

`-- +goose Down` section reverses all changes: drops trigger, trigger function, `utils.auto_match_projected_event`, `utils.derive_transaction_direction`, restores `api.generate_cash_flow_projection` (without branch 9), drops trigram index, drops partial index, removes `transaction_id` column from `data.projected_event_occurrences`, and restores the old occurrence view + functions.

---

## Step 2 — `public/reports/cash-flow-projection.php`

- Add `'transaction'` to `$group_order` after `'cc_payment'`
- Add `'transaction' => 'Actual Transactions'` to `$group_labels`
- **Not** in `$is_realized_group` — counted in balance like all other normal groups
- Add badge elseif:

```php
<?php elseif ($type === 'transaction'): ?>
    <span class="cfp-type-badge cfp-badge-transaction">Actual</span>
```

---

## Step 3 — `public/css/cash-flow-projection.css`

```css
.cfp-badge-transaction { background: #dcfce7; color: #166534; }
```

---

## Step 4 — `public/telegram/webhook.php` — `handle_record_transaction()`

After `api.add_transaction()` returns `$tx_uuid`, read the match result from metadata:

```php
$stmt = $db->prepare(
    "SELECT metadata->>'matched_event_uuid' AS e_uuid,
            metadata->>'matched_event_name'  AS e_name
     FROM data.transactions WHERE uuid = ?"
);
$stmt->execute([$tx_uuid]);
$match = $stmt->fetch();
```

- If `$match['e_uuid']` is set → append to success message:
  `"\n✓ _Correspondeu ao evento planejado «{$match['e_name']}» e marcou como realizado._"`
- If not set → append:
  `"\n_Nenhum evento correspondente; aparecerá como transação na projeção._"`

Pass `$match['e_uuid']` as a 5th argument to `tg_action_save()` so `/undo` knows to also unrealize the matched event.

Update `handle_undo()`: if the saved action has a `matched_event_uuid`, additionally:
- **One-time event**: `UPDATE data.projected_events SET is_realized = false, linked_transaction_id = NULL WHERE uuid = ?`
- **Recurring event**: `SELECT api.unrealize_projected_event_occurrence(?, date_trunc('month', <action_date>)::date)`

---

## Files Changed

| File | Type | Change |
|------|------|--------|
| `migrations/20260228000001_integrate_transactions_projections.sql` | New | 11 blocks — core migration |
| `public/reports/cash-flow-projection.php` | Modified | Add 'transaction' group (order, labels, badge) |
| `public/css/cash-flow-projection.css` | Modified | Add `.cfp-badge-transaction` |
| `public/telegram/webhook.php` | Modified | Read match metadata, enrich messages, update /undo |

---

## Verification Checklist

1. Run migration: `goose -dir migrations postgres "host=/var/run/postgresql dbname=pgbudget user=pgbudget sslmode=disable" up`
2. **Auto-match test**: add a projected event "Netflix" R$55 outflow monthly. Bot: _"paguei Netflix 55 hoje"_. Reply should include "Correspondeu ao evento planejado «Netflix»". Report: Netflix no longer shows as a future planned item for the current month; appears as a `realized_occurrence` at today's date.
3. **Standalone test**: bot: _"recebi 1300 de venda de GPU"_. No matching planned event. Reply: "aparecerá como transação na projeção". Report: "Actual Transactions" group shows +R$1,300 in the current month; Net Monthly Balance updated.
4. **No double-count**: Net Monthly Balance = planned items + realized occurrences + unmatched transactions only.
5. **/undo after auto-match**: `/undo` → transaction deleted AND Netflix event unrealized (re-appears as a future planned item).
6. **Direction**: outflow transactions show as negative amounts; inflow as positive.
7. **Deletion/reversal**: delete a transaction → the reversal and original cancel each other out (net zero effect on the projection), or soft-deleted transactions (`deleted_at IS NOT NULL`) are filtered out entirely.
