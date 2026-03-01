-- +goose Up
-- Integrate recorded transactions into the cash-flow projection.
--
-- Changes:
--   1. Enable pg_trgm (needed for similarity() scoring)
--   2. Add transaction_id to projected_event_occurrences (links realized occurrence → tx)
--   3. GIN trigram index on projected_events.name (fast fuzzy name matching)
--   4. Drop view-dependent functions + view (they bind to view column types)
--   5. Recreate api.projected_event_occurrences view with transaction_id, transaction_uuid
--   6. Recreate api.realize_projected_event_occurrence with optional p_transaction_uuid
--   7. Recreate api.unrealize_projected_event_occurrence + api.get_projected_event_occurrences
--   8. Create utils.derive_transaction_direction helper
--   9. Create utils.auto_match_projected_event trigger function (scoring + match logic)
--  10. Create AFTER INSERT trigger on data.transactions
--  11. Recreate api.generate_cash_flow_projection with Branch 9 (unmatched transactions)

-- +goose StatementBegin
CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- +goose StatementEnd

-- +goose StatementBegin
ALTER TABLE data.projected_event_occurrences
    ADD COLUMN transaction_id bigint
        REFERENCES data.transactions(id) ON DELETE SET NULL;

CREATE INDEX idx_peo_transaction_id
    ON data.projected_event_occurrences (transaction_id)
    WHERE transaction_id IS NOT NULL;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE INDEX idx_projected_events_name_trgm
    ON data.projected_events USING gin (name gin_trgm_ops);
-- +goose StatementEnd

-- +goose StatementBegin
DROP FUNCTION IF EXISTS api.realize_projected_event_occurrence(text, date, date, bigint, text);
DROP FUNCTION IF EXISTS api.get_projected_event_occurrences(text);
DROP FUNCTION IF EXISTS api.unrealize_projected_event_occurrence(text, date);
DROP VIEW IF EXISTS api.projected_event_occurrences;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE VIEW api.projected_event_occurrences AS
SELECT
    o.uuid,
    e.uuid              AS projected_event_uuid,
    o.scheduled_month,
    o.is_realized,
    o.realized_date,
    o.realized_amount,
    o.notes,
    o.transaction_id,
    t.uuid              AS transaction_uuid,
    o.created_at,
    o.updated_at
FROM data.projected_event_occurrences o
JOIN data.projected_events e ON e.id = o.projected_event_id
LEFT JOIN data.transactions t ON t.id = o.transaction_id
WHERE o.user_data = utils.get_user();

GRANT SELECT ON api.projected_event_occurrences TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.realize_projected_event_occurrence(
    p_event_uuid        text,
    p_scheduled_month   date,
    p_realized_date     date,
    p_realized_amount   bigint  DEFAULT NULL,
    p_notes             text    DEFAULT NULL,
    p_transaction_uuid  text    DEFAULT NULL
) RETURNS SETOF api.projected_event_occurrences AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
    v_occ_uuid  text;
    v_tx_id     bigint;
BEGIN
    v_user_data := utils.get_user();

    SELECT id INTO v_event_id
    FROM data.projected_events
    WHERE uuid = p_event_uuid AND user_data = v_user_data;

    IF v_event_id IS NULL THEN
        RAISE EXCEPTION 'Projected event not found';
    END IF;

    -- Optionally resolve transaction UUID to internal ID
    IF p_transaction_uuid IS NOT NULL THEN
        SELECT id INTO v_tx_id
        FROM data.transactions
        WHERE uuid = p_transaction_uuid AND user_data = v_user_data;
    END IF;

    INSERT INTO data.projected_event_occurrences (
        user_data, projected_event_id, scheduled_month,
        is_realized, realized_date, realized_amount, notes, transaction_id
    ) VALUES (
        v_user_data, v_event_id, date_trunc('month', p_scheduled_month)::date,
        true, p_realized_date, p_realized_amount, p_notes, v_tx_id
    )
    ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
    SET
        is_realized     = true,
        realized_date   = p_realized_date,
        realized_amount = p_realized_amount,
        notes           = COALESCE(EXCLUDED.notes, data.projected_event_occurrences.notes),
        transaction_id  = COALESCE(EXCLUDED.transaction_id, data.projected_event_occurrences.transaction_id),
        updated_at      = now()
    RETURNING uuid INTO v_occ_uuid;

    RETURN QUERY
    SELECT * FROM api.projected_event_occurrences WHERE uuid = v_occ_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.realize_projected_event_occurrence(text, date, date, bigint, text, text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.unrealize_projected_event_occurrence(
    p_event_uuid        text,
    p_scheduled_month   date
) RETURNS boolean AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
BEGIN
    v_user_data := utils.get_user();

    SELECT id INTO v_event_id
    FROM data.projected_events
    WHERE uuid = p_event_uuid AND user_data = v_user_data;

    IF v_event_id IS NULL THEN
        RAISE EXCEPTION 'Projected event not found';
    END IF;

    DELETE FROM data.projected_event_occurrences
    WHERE projected_event_id = v_event_id
      AND scheduled_month    = date_trunc('month', p_scheduled_month)::date
      AND user_data          = v_user_data;

    RETURN found;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.unrealize_projected_event_occurrence(text, date) TO pgbudget_user;

CREATE OR REPLACE FUNCTION api.get_projected_event_occurrences(
    p_event_uuid text
) RETURNS SETOF api.projected_event_occurrences AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
BEGIN
    v_user_data := utils.get_user();

    SELECT id INTO v_event_id
    FROM data.projected_events
    WHERE uuid = p_event_uuid AND user_data = v_user_data;

    IF v_event_id IS NULL THEN
        RAISE EXCEPTION 'Projected event not found';
    END IF;

    RETURN QUERY
    SELECT * FROM api.projected_event_occurrences
    WHERE projected_event_uuid = p_event_uuid
    ORDER BY scheduled_month;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.get_projected_event_occurrences(text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
-- Derive whether a transaction is an inflow or outflow based on account types.
-- Rules:
--   debit=asset                          → 'inflow'  (salary/payment received into checking)
--   credit=asset AND debit≠liability     → 'outflow' (expense paid from checking)
--   credit=liability                     → 'outflow' (CC charge — accrual: expense when incurred)
--   debit=liability, credit=asset        → 'unknown' (CC bill payment — balance sheet move, skip)
--   otherwise                            → 'unknown'
CREATE OR REPLACE FUNCTION utils.derive_transaction_direction(
    p_debit_id  bigint,
    p_credit_id bigint
) RETURNS text AS $$
DECLARE
    v_debit_type  text;
    v_credit_type text;
BEGIN
    SELECT type INTO v_debit_type  FROM data.accounts WHERE id = p_debit_id;
    SELECT type INTO v_credit_type FROM data.accounts WHERE id = p_credit_id;

    IF v_debit_type = 'asset' THEN
        RETURN 'inflow';
    ELSIF v_credit_type = 'asset' AND v_debit_type <> 'liability' THEN
        RETURN 'outflow';
    ELSIF v_credit_type = 'liability' THEN
        RETURN 'outflow';
    ELSE
        RETURN 'unknown';
    END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

GRANT EXECUTE ON FUNCTION utils.derive_transaction_direction(bigint, bigint) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
-- Auto-match a newly inserted transaction to a projected event.
--
-- Scoring (threshold ≥ 60 to match):
--   Amount diff ≤ R$1 (100 cents): +50
--   Amount diff ≤  5%:             +40
--   Amount diff ≤ 10%:             +30
--   Amount diff ≤ 20%:             +20
--   Amount diff ≤ 50%:             +10
--   Amount diff > 50%:             DISQUALIFY
--   Same month:                    +30
--   ±1 month:                      +15
--   ±2 months:                     +5
--   >2 months:                     DISQUALIFY
--   similarity(event_name, tx_desc) > 0.4: +20
--   similarity(event_name, tx_desc) > 0.2: +10
--   event_name substring of tx_desc:       +15 bonus
--
-- On match:
--   one_time   → UPDATE projected_events SET is_realized=true, linked_transaction_id=tx.id
--   recurring  → UPSERT projected_event_occurrences for tx month
--   Always     → UPDATE transactions.metadata with matched event uuid+name
--
-- Wrapped in EXCEPTION WHEN OTHERS → never fails the triggering INSERT.
CREATE OR REPLACE FUNCTION utils.auto_match_projected_event(
    p_transaction_id bigint
) RETURNS void AS $$
DECLARE
    v_user_data      text;
    v_ledger_id      bigint;
    v_tx_date        date;
    v_tx_amount      bigint;
    v_tx_description text;
    v_tx_metadata    jsonb;
    v_debit_id       bigint;
    v_credit_id      bigint;
    v_direction      text;
    v_best_id        bigint;
    v_best_uuid      text;
    v_best_name      text;
    v_best_frequency text;
    v_score          int;
BEGIN
    BEGIN  -- inner block: catch all errors, never fail the INSERT

        v_user_data := utils.get_user();
        IF v_user_data IS NULL OR v_user_data = '' THEN RETURN; END IF;

        -- Fetch transaction basics
        SELECT t.date, t.amount, t.description, t.ledger_id, t.metadata,
               t.debit_account_id, t.credit_account_id
        INTO v_tx_date, v_tx_amount, v_tx_description, v_ledger_id, v_tx_metadata,
             v_debit_id, v_credit_id
        FROM data.transactions t
        WHERE t.id = p_transaction_id
          AND t.user_data = v_user_data
          AND t.deleted_at IS NULL;

        IF NOT FOUND THEN RETURN; END IF;

        -- Skip if metadata flags say so
        IF (v_tx_metadata->>'skip_auto_match') = 'true'
           OR (v_tx_metadata->>'is_cc_budget_move') = 'true'
           OR (v_tx_metadata->>'is_cc_payment_budget_reduction') = 'true'
        THEN RETURN; END IF;

        -- Skip reversal transactions (bookkeeping entries created by delete/correction)
        IF EXISTS (
            SELECT 1 FROM data.transaction_log tl
            WHERE tl.reversal_transaction_id = p_transaction_id
        ) THEN RETURN; END IF;

        -- Derive direction from account types
        v_direction := utils.derive_transaction_direction(v_debit_id, v_credit_id);
        IF v_direction = 'unknown' THEN RETURN; END IF;

        -- Score all candidate projected events and pick the best match
        SELECT e.id, e.uuid, e.name, e.frequency, scored.total
        INTO v_best_id, v_best_uuid, v_best_name, v_best_frequency, v_score
        FROM data.projected_events e
        -- Pre-compute derived values once
        CROSS JOIN LATERAL (
            SELECT
                ABS(
                    (EXTRACT(YEAR FROM e.event_date)::int * 12 + EXTRACT(MONTH FROM e.event_date)::int) -
                    (EXTRACT(YEAR FROM v_tx_date)::int  * 12 + EXTRACT(MONTH FROM v_tx_date)::int)
                ) AS month_diff,
                ABS(e.amount - v_tx_amount) AS amt_diff,
                similarity(e.name, COALESCE(v_tx_description, '')) AS name_sim
        ) computed
        -- Compute total score
        CROSS JOIN LATERAL (
            SELECT
                -- Amount score (disqualify if diff > 50%)
                CASE
                    WHEN computed.amt_diff <= 100 THEN 50
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.05 THEN 40
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.10 THEN 30
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.20 THEN 20
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.50 THEN 10
                    ELSE -999
                END
                -- Date score (disqualify if > 2 months away)
                + CASE
                    WHEN computed.month_diff = 0 THEN 30
                    WHEN computed.month_diff = 1 THEN 15
                    WHEN computed.month_diff = 2 THEN 5
                    ELSE -999
                END
                -- Name similarity score
                + CASE
                    WHEN computed.name_sim > 0.4 THEN 20
                    WHEN computed.name_sim > 0.2 THEN 10
                    ELSE 0
                END
                -- Substring bonus: event name appears literally in transaction description
                + CASE
                    WHEN POSITION(lower(e.name) IN lower(COALESCE(v_tx_description, ''))) > 0 THEN 15
                    ELSE 0
                END
            AS total
        ) scored
        WHERE e.ledger_id = v_ledger_id
          AND e.user_data = v_user_data
          AND e.direction = v_direction
          AND e.is_realized = false
          AND computed.month_diff <= 2
          -- For recurring events, skip if this month's occurrence is already realized
          AND (
              e.frequency = 'one_time'
              OR NOT EXISTS (
                  SELECT 1 FROM data.projected_event_occurrences o
                  WHERE o.projected_event_id = e.id
                    AND o.scheduled_month    = date_trunc('month', v_tx_date)::date
                    AND o.is_realized        = true
                    AND o.user_data          = v_user_data
              )
          )
          AND scored.total >= 60
        ORDER BY scored.total DESC, computed.amt_diff
        LIMIT 1;

        IF NOT FOUND THEN RETURN; END IF;

        -- Apply the match
        IF v_best_frequency = 'one_time' THEN
            UPDATE data.projected_events
            SET is_realized         = true,
                linked_transaction_id = p_transaction_id
            WHERE id        = v_best_id
              AND user_data = v_user_data;
        ELSE
            -- Realize the occurrence for the transaction's month
            INSERT INTO data.projected_event_occurrences (
                user_data, projected_event_id, scheduled_month,
                is_realized, realized_date, transaction_id
            ) VALUES (
                v_user_data, v_best_id,
                date_trunc('month', v_tx_date)::date,
                true, v_tx_date, p_transaction_id
            )
            ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
            SET is_realized    = true,
                transaction_id = p_transaction_id,
                realized_date  = EXCLUDED.realized_date;
        END IF;

        -- Store match info in transaction metadata for webhook /undo and display
        UPDATE data.transactions
        SET metadata = COALESCE(metadata, '{}'::jsonb) ||
                       jsonb_build_object(
                           'matched_event_uuid', v_best_uuid,
                           'matched_event_name', v_best_name
                       )
        WHERE id        = p_transaction_id
          AND user_data = v_user_data;

    EXCEPTION WHEN OTHERS THEN
        NULL;  -- Never fail the triggering INSERT
    END;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION utils.auto_match_projected_event(bigint) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.tg_auto_match_projected_event_fn()
RETURNS trigger AS $$
BEGIN
    PERFORM utils.auto_match_projected_event(NEW.id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE TRIGGER tg_auto_match_projected_event
    AFTER INSERT ON data.transactions
    FOR EACH ROW EXECUTE FUNCTION utils.tg_auto_match_projected_event_fn();
-- +goose StatementEnd

-- +goose StatementBegin
-- Recreate generate_cash_flow_projection with Branch 9:
-- unmatched transactions (not linked to any realized projected event) appear as
-- an "Actual Transactions" group in the projection, counted in the net balance.
CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid text,
    p_start_month date DEFAULT date_trunc('month', current_date)::date,
    p_months_ahead integer DEFAULT 120
) RETURNS TABLE (
    month       date,
    source_type text,
    source_id   bigint,
    source_uuid text,
    category    text,
    subcategory text,
    description text,
    amount      bigint
) AS $$
DECLARE
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
BEGIN
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    RETURN QUERY

    -- 1. INCOME SOURCES: monthly (already in cents)
    SELECT
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, COALESCE(i.income_subtype, i.income_type),
        i.name, i.amount
    FROM data.income_sources i
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true AND i.frequency = 'monthly'

    UNION ALL

    -- 1b. INCOME SOURCES: annual/semiannual
    SELECT
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, COALESCE(i.income_subtype, i.income_type),
        i.name, i.amount
    FROM data.income_sources i
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true
      AND i.frequency IN ('annual', 'semiannual')
      AND (i.occurrence_months IS NULL OR EXTRACT(MONTH FROM gs.month)::integer = ANY(i.occurrence_months))

    UNION ALL

    -- 1c. INCOME SOURCES: one_time
    SELECT
        date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid,
        'income'::text, COALESCE(i.income_subtype, i.income_type),
        i.name, i.amount
    FROM data.income_sources i
    WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true
      AND i.frequency = 'one_time'
      AND date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp)
      AND date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)

    UNION ALL

    -- 2. PAYROLL DEDUCTIONS: monthly (already in cents, negate)
    SELECT
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(COALESCE(d.fixed_amount, d.estimated_amount, 0::bigint))
    FROM data.payroll_deductions d
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE d.ledger_id = v_ledger_id AND d.user_data = v_user_data AND d.is_active = true AND d.frequency = 'monthly'
      AND (d.occurrence_months IS NULL OR EXTRACT(MONTH FROM gs.month)::integer = ANY(d.occurrence_months))

    UNION ALL

    -- 2b. PAYROLL DEDUCTIONS: annual/semiannual
    SELECT
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(COALESCE(d.fixed_amount, d.estimated_amount, 0::bigint))
    FROM data.payroll_deductions d
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE d.ledger_id = v_ledger_id AND d.user_data = v_user_data AND d.is_active = true
      AND d.frequency IN ('annual', 'semiannual')
      AND (d.occurrence_months IS NOT NULL AND EXTRACT(MONTH FROM gs.month)::integer = ANY(d.occurrence_months))

    UNION ALL

    -- 3. OBLIGATIONS: from scheduled payments (decimal(15,2) → cents)
    SELECT
        date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid,
        COALESCE(o.obligation_type, 'other'), COALESCE(o.obligation_subtype, o.obligation_type),
        o.name, -(op.scheduled_amount * 100)::bigint
    FROM data.obligation_payments op
    JOIN data.obligations o ON o.id = op.obligation_id
    WHERE o.ledger_id = v_ledger_id AND o.user_data = v_user_data AND o.is_active = true
      AND op.status IN ('scheduled', 'partial')
      AND op.due_date >= p_start_month AND op.due_date <= v_end_month

    UNION ALL

    -- 3b. OBLIGATIONS: projected from params (decimal(15,2) → cents)
    SELECT
        gs.month::date, 'obligation'::text, o.id, o.uuid,
        COALESCE(o.obligation_type, 'other'), COALESCE(o.obligation_subtype, o.obligation_type),
        o.name, -(COALESCE(o.fixed_amount, o.estimated_amount, 0) * 100)::bigint
    FROM data.obligations o
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE o.ledger_id = v_ledger_id AND o.user_data = v_user_data AND o.is_active = true AND o.frequency = 'monthly'
      AND NOT EXISTS (
          SELECT 1 FROM data.obligation_payments op2
          WHERE op2.obligation_id = o.id AND date_trunc('month', op2.due_date) = gs.month
            AND op2.status IN ('scheduled', 'paid', 'partial')
      )
      AND gs.month > COALESCE(
          (SELECT max(date_trunc('month', op3.due_date)) FROM data.obligation_payments op3
           WHERE op3.obligation_id = o.id AND op3.status = 'paid'), '1900-01-01'::date
      )

    UNION ALL

    -- 4. LOAN AMORTIZATION (numeric(19,4) → cents)
    SELECT la.month, 'loan_amort'::text, l.id, l.uuid,
        'expense'::text, 'ln amort'::text, l.lender_name || ' amort',
        -(la.amortization * 100)::bigint
    FROM data.loans l
    CROSS JOIN LATERAL utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'

    UNION ALL

    -- 4b. LOAN INTEREST (numeric(19,4) → cents)
    SELECT la.month, 'loan_interest'::text, l.id, l.uuid,
        'interest'::text, 'ln int'::text, l.lender_name || ' int',
        -(la.interest * 100)::bigint
    FROM data.loans l
    CROSS JOIN LATERAL utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'

    UNION ALL

    -- 5. INSTALLMENT PLANS (already in cents — no * 100)
    SELECT
        date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid,
        'expense'::text, 'installment'::text, ip.description,
        -(s.scheduled_amount)::bigint
    FROM data.installment_plans ip
    JOIN data.installment_schedules s ON s.installment_plan_id = ip.id
    WHERE ip.ledger_id = v_ledger_id AND ip.user_data = v_user_data AND ip.status = 'active'
      AND s.status = 'scheduled' AND s.due_date >= p_start_month AND s.due_date <= v_end_month

    UNION ALL

    -- 6. RECURRING TRANSACTIONS (already in cents)
    SELECT
        gs.month::date, 'recurring'::text, rt.id, rt.uuid,
        CASE WHEN rt.transaction_type = 'inflow' THEN 'income' ELSE 'expense' END,
        'recurring'::text, rt.description,
        CASE WHEN rt.transaction_type = 'inflow' THEN rt.amount ELSE -(rt.amount) END
    FROM data.recurring_transactions rt
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)),
        least(COALESCE(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        CASE rt.frequency
            WHEN 'monthly' THEN INTERVAL '1 month' WHEN 'yearly' THEN INTERVAL '1 year'
            WHEN 'weekly' THEN INTERVAL '1 week' WHEN 'biweekly' THEN INTERVAL '2 weeks'
            WHEN 'daily' THEN INTERVAL '1 day' ELSE INTERVAL '1 month' END
    ) AS gs(month)
    WHERE rt.ledger_id = v_ledger_id AND rt.user_data = v_user_data AND rt.enabled = true

    UNION ALL

    -- 7a. PROJECTED EVENTS: one_time (already in cents)
    SELECT
        date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END
    FROM data.projected_events e
    WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false
      AND e.frequency = 'one_time'
      AND e.event_date >= p_start_month AND e.event_date <= v_end_month

    UNION ALL

    -- 7b. PROJECTED EVENTS: monthly recurring (skip realized occurrences)
    SELECT
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END
    FROM data.projected_events e
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false
      AND e.frequency = 'monthly'
      AND NOT EXISTS (
          SELECT 1 FROM data.projected_event_occurrences o
          WHERE o.projected_event_id = e.id
            AND o.scheduled_month    = gs.month::date
            AND o.is_realized        = true
            AND o.user_data          = v_user_data
      )

    UNION ALL

    -- 7c. PROJECTED EVENTS: annual recurring (skip realized occurrences)
    SELECT
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END
    FROM data.projected_events e
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false
      AND e.frequency = 'annual'
      AND EXTRACT(MONTH FROM gs.month)::integer = EXTRACT(MONTH FROM e.event_date)::integer
      AND NOT EXISTS (
          SELECT 1 FROM data.projected_event_occurrences o
          WHERE o.projected_event_id = e.id
            AND o.scheduled_month    = gs.month::date
            AND o.is_realized        = true
            AND o.user_data          = v_user_data
      )

    UNION ALL

    -- 7d. PROJECTED EVENTS: semiannual recurring (skip realized occurrences)
    SELECT
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END
    FROM data.projected_events e
    CROSS JOIN LATERAL generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        INTERVAL '1 month'
    ) AS gs(month)
    WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false
      AND e.frequency = 'semiannual'
      AND EXTRACT(MONTH FROM gs.month)::integer = ANY(ARRAY[
          EXTRACT(MONTH FROM e.event_date)::integer,
          ((EXTRACT(MONTH FROM e.event_date)::integer - 1 + 6) % 12) + 1
      ])
      AND NOT EXISTS (
          SELECT 1 FROM data.projected_event_occurrences o
          WHERE o.projected_event_id = e.id
            AND o.scheduled_month    = gs.month::date
            AND o.is_realized        = true
            AND o.user_data          = v_user_data
      )

    UNION ALL

    -- 7e. REALIZED OCCURRENCES: appear in realized_date's month (not scheduled month)
    --     Replaces the projected row; counted in net balance at the actual date.
    SELECT
        date_trunc('month', o.realized_date)::date, 'realized_occurrence'::text, e.id, e.uuid,
        e.event_type, e.event_type,
        e.name || ' (' || to_char(o.scheduled_month, 'Mon YYYY') || ')',
        CASE WHEN e.direction = 'inflow'
             THEN  COALESCE(o.realized_amount, e.amount)
             ELSE -COALESCE(o.realized_amount, e.amount)
        END
    FROM data.projected_event_occurrences o
    JOIN data.projected_events e ON e.id = o.projected_event_id
    WHERE e.ledger_id = v_ledger_id
      AND e.user_data = v_user_data
      AND o.user_data = v_user_data
      AND o.is_realized = true
      AND o.realized_date IS NOT NULL
      AND date_trunc('month', o.realized_date)
            BETWEEN date_trunc('month', p_start_month::timestamp)
                AND date_trunc('month', v_end_month::timestamp)

    UNION ALL

    -- 8. ACTUAL TRANSACTIONS: real cash flows + CC charges (accrual).
    --    Includes: inflows to asset, outflows from asset, CC charges (credit=liability).
    --    Excludes: CC bill payments (debit=liability → unknown), reversals, corrected
    --              originals, and transactions already matched to projected events.
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
      -- Exclude reversal transactions (bookkeeping entries, the reversal itself)
      AND NOT EXISTS (
          SELECT 1 FROM data.transaction_log tl WHERE tl.reversal_transaction_id = t.id
      )
      -- Exclude original transactions that were reversed/corrected
      AND NOT EXISTS (
          SELECT 1 FROM data.transaction_log tl
          WHERE tl.original_transaction_id = t.id
            AND tl.reversal_transaction_id IS NOT NULL
      )
      -- Exclude transactions that realized a one-time projected event
      AND NOT EXISTS (
          SELECT 1 FROM data.projected_events pe
          WHERE pe.linked_transaction_id = t.id AND pe.is_realized = true
      )
      -- Exclude transactions that realized a recurring occurrence
      AND NOT EXISTS (
          SELECT 1 FROM data.projected_event_occurrences peo
          WHERE peo.transaction_id = t.id AND peo.is_realized = true
      )

    ORDER BY 1, 2, 7;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd

-- +goose Down

-- +goose StatementBegin
DROP TRIGGER IF EXISTS tg_auto_match_projected_event ON data.transactions;
DROP FUNCTION IF EXISTS utils.tg_auto_match_projected_event_fn();
-- +goose StatementEnd

-- +goose StatementBegin
DROP FUNCTION IF EXISTS utils.auto_match_projected_event(bigint);
-- +goose StatementEnd

-- +goose StatementBegin
DROP FUNCTION IF EXISTS utils.derive_transaction_direction(bigint, bigint);
-- +goose StatementEnd

-- +goose StatementBegin
-- Restore generate_cash_flow_projection to state from 20260219000002 (no Branch 9)
CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid text,
    p_start_month date DEFAULT date_trunc('month', current_date)::date,
    p_months_ahead integer DEFAULT 120
) RETURNS TABLE (
    month date, source_type text, source_id bigint, source_uuid text,
    category text, subcategory text, description text, amount bigint
) AS $$
DECLARE
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
BEGIN
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;
    SELECT id INTO v_ledger_id FROM data.ledgers WHERE uuid = p_ledger_uuid AND user_data = v_user_data;
    IF v_ledger_id IS NULL THEN RAISE EXCEPTION 'Ledger not found'; END IF;
    RETURN QUERY
    SELECT gs.month::date, 'income'::text, i.id, i.uuid, 'income'::text, COALESCE(i.income_subtype, i.income_type), i.name, i.amount FROM data.income_sources i CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true AND i.frequency = 'monthly'
    UNION ALL SELECT gs.month::date, 'income'::text, i.id, i.uuid, 'income'::text, COALESCE(i.income_subtype, i.income_type), i.name, i.amount FROM data.income_sources i CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true AND i.frequency IN ('annual', 'semiannual') AND (i.occurrence_months IS NULL OR EXTRACT(MONTH FROM gs.month)::integer = ANY(i.occurrence_months))
    UNION ALL SELECT date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid, 'income'::text, COALESCE(i.income_subtype, i.income_type), i.name, i.amount FROM data.income_sources i WHERE i.ledger_id = v_ledger_id AND i.user_data = v_user_data AND i.is_active = true AND i.frequency = 'one_time' AND date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp) AND date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)
    UNION ALL SELECT gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(COALESCE(d.fixed_amount, d.estimated_amount, 0::bigint)) FROM data.payroll_deductions d CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE d.ledger_id = v_ledger_id AND d.user_data = v_user_data AND d.is_active = true AND d.frequency = 'monthly' AND (d.occurrence_months IS NULL OR EXTRACT(MONTH FROM gs.month)::integer = ANY(d.occurrence_months))
    UNION ALL SELECT gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(COALESCE(d.fixed_amount, d.estimated_amount, 0::bigint)) FROM data.payroll_deductions d CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE d.ledger_id = v_ledger_id AND d.user_data = v_user_data AND d.is_active = true AND d.frequency IN ('annual', 'semiannual') AND (d.occurrence_months IS NOT NULL AND EXTRACT(MONTH FROM gs.month)::integer = ANY(d.occurrence_months))
    UNION ALL SELECT date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid, COALESCE(o.obligation_type, 'other'), COALESCE(o.obligation_subtype, o.obligation_type), o.name, -(op.scheduled_amount * 100)::bigint FROM data.obligation_payments op JOIN data.obligations o ON o.id = op.obligation_id WHERE o.ledger_id = v_ledger_id AND o.user_data = v_user_data AND o.is_active = true AND op.status IN ('scheduled', 'partial') AND op.due_date >= p_start_month AND op.due_date <= v_end_month
    UNION ALL SELECT gs.month::date, 'obligation'::text, o.id, o.uuid, COALESCE(o.obligation_type, 'other'), COALESCE(o.obligation_subtype, o.obligation_type), o.name, -(COALESCE(o.fixed_amount, o.estimated_amount, 0) * 100)::bigint FROM data.obligations o CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE o.ledger_id = v_ledger_id AND o.user_data = v_user_data AND o.is_active = true AND o.frequency = 'monthly' AND NOT EXISTS (SELECT 1 FROM data.obligation_payments op2 WHERE op2.obligation_id = o.id AND date_trunc('month', op2.due_date) = gs.month AND op2.status IN ('scheduled', 'paid', 'partial')) AND gs.month > COALESCE((SELECT max(date_trunc('month', op3.due_date)) FROM data.obligation_payments op3 WHERE op3.obligation_id = o.id AND op3.status = 'paid'), '1900-01-01'::date)
    UNION ALL SELECT la.month, 'loan_amort'::text, l.id, l.uuid, 'expense'::text, 'ln amort'::text, l.lender_name || ' amort', -(la.amortization * 100)::bigint FROM data.loans l CROSS JOIN LATERAL utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
    UNION ALL SELECT la.month, 'loan_interest'::text, l.id, l.uuid, 'interest'::text, 'ln int'::text, l.lender_name || ' int', -(la.interest * 100)::bigint FROM data.loans l CROSS JOIN LATERAL utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
    UNION ALL SELECT date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid, 'expense'::text, 'installment'::text, ip.description, -(s.scheduled_amount)::bigint FROM data.installment_plans ip JOIN data.installment_schedules s ON s.installment_plan_id = ip.id WHERE ip.ledger_id = v_ledger_id AND ip.user_data = v_user_data AND ip.status = 'active' AND s.status = 'scheduled' AND s.due_date >= p_start_month AND s.due_date <= v_end_month
    UNION ALL SELECT gs.month::date, 'recurring'::text, rt.id, rt.uuid, CASE WHEN rt.transaction_type = 'inflow' THEN 'income' ELSE 'expense' END, 'recurring'::text, rt.description, CASE WHEN rt.transaction_type = 'inflow' THEN rt.amount ELSE -(rt.amount) END FROM data.recurring_transactions rt CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), CASE rt.frequency WHEN 'monthly' THEN INTERVAL '1 month' WHEN 'yearly' THEN INTERVAL '1 year' WHEN 'weekly' THEN INTERVAL '1 week' WHEN 'biweekly' THEN INTERVAL '2 weeks' WHEN 'daily' THEN INTERVAL '1 day' ELSE INTERVAL '1 month' END) AS gs(month) WHERE rt.ledger_id = v_ledger_id AND rt.user_data = v_user_data AND rt.enabled = true
    UNION ALL SELECT date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END FROM data.projected_events e WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false AND e.frequency = 'one_time' AND e.event_date >= p_start_month AND e.event_date <= v_end_month
    UNION ALL SELECT gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END FROM data.projected_events e CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false AND e.frequency = 'monthly' AND NOT EXISTS (SELECT 1 FROM data.projected_event_occurrences o WHERE o.projected_event_id = e.id AND o.scheduled_month = gs.month::date AND o.is_realized = true AND o.user_data = v_user_data)
    UNION ALL SELECT gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END FROM data.projected_events e CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false AND e.frequency = 'annual' AND EXTRACT(MONTH FROM gs.month)::integer = EXTRACT(MONTH FROM e.event_date)::integer AND NOT EXISTS (SELECT 1 FROM data.projected_event_occurrences o WHERE o.projected_event_id = e.id AND o.scheduled_month = gs.month::date AND o.is_realized = true AND o.user_data = v_user_data)
    UNION ALL SELECT gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, CASE WHEN e.direction = 'inflow' THEN e.amount ELSE -(e.amount) END FROM data.projected_events e CROSS JOIN LATERAL generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(COALESCE(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), INTERVAL '1 month') AS gs(month) WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND e.is_realized = false AND e.frequency = 'semiannual' AND EXTRACT(MONTH FROM gs.month)::integer = ANY(ARRAY[EXTRACT(MONTH FROM e.event_date)::integer, ((EXTRACT(MONTH FROM e.event_date)::integer - 1 + 6) % 12) + 1]) AND NOT EXISTS (SELECT 1 FROM data.projected_event_occurrences o WHERE o.projected_event_id = e.id AND o.scheduled_month = gs.month::date AND o.is_realized = true AND o.user_data = v_user_data)
    UNION ALL SELECT date_trunc('month', o.realized_date)::date, 'realized_occurrence'::text, e.id, e.uuid, e.event_type, e.event_type, e.name || ' (' || to_char(o.scheduled_month, 'Mon YYYY') || ')', CASE WHEN e.direction = 'inflow' THEN COALESCE(o.realized_amount, e.amount) ELSE -COALESCE(o.realized_amount, e.amount) END FROM data.projected_event_occurrences o JOIN data.projected_events e ON e.id = o.projected_event_id WHERE e.ledger_id = v_ledger_id AND e.user_data = v_user_data AND o.user_data = v_user_data AND o.is_realized = true AND o.realized_date IS NOT NULL AND date_trunc('month', o.realized_date) BETWEEN date_trunc('month', p_start_month::timestamp) AND date_trunc('month', v_end_month::timestamp)
    UNION ALL SELECT gs.month::date, 'cc_payment'::text, a.id, a.uuid, 'liability'::text, 'cc_payment'::text, a.name || ' payment', -(bal.balance)::bigint FROM data.accounts a JOIN data.ledgers l ON l.id = a.ledger_id CROSS JOIN LATERAL (SELECT utils.get_account_balance(a.ledger_id, a.id) AS balance) AS bal LEFT JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = a.id AND ccl.user_data = v_user_data AND ccl.is_active = true CROSS JOIN LATERAL generate_series(date_trunc('month', p_start_month::timestamp), date_trunc('month', v_end_month::timestamp), INTERVAL '1 month') AS gs(month) WHERE l.id = v_ledger_id AND a.user_data = v_user_data AND a.type = 'liability' AND a.deleted_at IS NULL AND bal.balance > 0 AND NOT EXISTS (SELECT 1 FROM data.loans lo WHERE lo.account_id = a.id AND lo.user_data = v_user_data AND lo.status = 'active') AND (ccl.id IS NULL OR NOT ccl.auto_payment_enabled OR ccl.auto_payment_type = 'full_balance')
    UNION ALL SELECT gs.month::date, 'cc_payment'::text, a.id, a.uuid, 'liability'::text, 'cc_payment'::text, a.name || ' payment', -(GREATEST((bal.balance * ccl.minimum_payment_percent / 100)::bigint, (ccl.minimum_payment_flat * 100)::bigint))::bigint FROM data.accounts a JOIN data.ledgers l ON l.id = a.ledger_id JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = a.id AND ccl.user_data = v_user_data AND ccl.is_active = true CROSS JOIN LATERAL (SELECT utils.get_account_balance(a.ledger_id, a.id) AS balance) AS bal CROSS JOIN LATERAL generate_series(date_trunc('month', p_start_month::timestamp), date_trunc('month', v_end_month::timestamp), INTERVAL '1 month') AS gs(month) WHERE l.id = v_ledger_id AND a.user_data = v_user_data AND a.type = 'liability' AND a.deleted_at IS NULL AND bal.balance > 0 AND ccl.auto_payment_enabled = true AND ccl.auto_payment_type = 'minimum' AND NOT EXISTS (SELECT 1 FROM data.loans lo WHERE lo.account_id = a.id AND lo.user_data = v_user_data AND lo.status = 'active')
    UNION ALL SELECT gs.month::date, 'cc_payment'::text, a.id, a.uuid, 'liability'::text, 'cc_payment'::text, a.name || ' payment', -(ccl.auto_payment_amount * 100)::bigint FROM data.accounts a JOIN data.ledgers l ON l.id = a.ledger_id JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = a.id AND ccl.user_data = v_user_data AND ccl.is_active = true CROSS JOIN LATERAL (SELECT utils.get_account_balance(a.ledger_id, a.id) AS balance) AS bal CROSS JOIN LATERAL generate_series(date_trunc('month', p_start_month::timestamp), date_trunc('month', v_end_month::timestamp), INTERVAL '1 month') AS gs(month) WHERE l.id = v_ledger_id AND a.user_data = v_user_data AND a.type = 'liability' AND a.deleted_at IS NULL AND bal.balance > 0 AND ccl.auto_payment_enabled = true AND ccl.auto_payment_type = 'fixed_amount' AND COALESCE(ccl.auto_payment_amount, 0) > 0 AND NOT EXISTS (SELECT 1 FROM data.loans lo WHERE lo.account_id = a.id AND lo.user_data = v_user_data AND lo.status = 'active')
    ORDER BY 1, 2, 7;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd

-- +goose StatementBegin
-- Remove Branch 9 view+column dependencies, restore old view and functions
DROP FUNCTION IF EXISTS api.realize_projected_event_occurrence(text, date, date, bigint, text, text);
DROP FUNCTION IF EXISTS api.get_projected_event_occurrences(text);
DROP FUNCTION IF EXISTS api.unrealize_projected_event_occurrence(text, date);
DROP VIEW IF EXISTS api.projected_event_occurrences;
DROP INDEX IF EXISTS idx_peo_transaction_id;
ALTER TABLE data.projected_event_occurrences DROP COLUMN IF EXISTS transaction_id;
DROP INDEX IF EXISTS idx_projected_events_name_trgm;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE VIEW api.projected_event_occurrences AS
SELECT
    o.uuid,
    e.uuid              AS projected_event_uuid,
    o.scheduled_month,
    o.is_realized,
    o.realized_date,
    o.realized_amount,
    o.notes,
    o.created_at,
    o.updated_at
FROM data.projected_event_occurrences o
JOIN data.projected_events e ON e.id = o.projected_event_id
WHERE o.user_data = utils.get_user();

GRANT SELECT ON api.projected_event_occurrences TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.realize_projected_event_occurrence(
    p_event_uuid        text,
    p_scheduled_month   date,
    p_realized_date     date,
    p_realized_amount   bigint  DEFAULT NULL,
    p_notes             text    DEFAULT NULL
) RETURNS SETOF api.projected_event_occurrences AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
    v_occ_uuid  text;
BEGIN
    v_user_data := utils.get_user();
    SELECT id INTO v_event_id FROM data.projected_events WHERE uuid = p_event_uuid AND user_data = v_user_data;
    IF v_event_id IS NULL THEN RAISE EXCEPTION 'Projected event not found'; END IF;
    INSERT INTO data.projected_event_occurrences (user_data, projected_event_id, scheduled_month, is_realized, realized_date, realized_amount, notes)
    VALUES (v_user_data, v_event_id, date_trunc('month', p_scheduled_month)::date, true, p_realized_date, p_realized_amount, p_notes)
    ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
    SET is_realized = true, realized_date = p_realized_date, realized_amount = p_realized_amount,
        notes = COALESCE(EXCLUDED.notes, data.projected_event_occurrences.notes), updated_at = now()
    RETURNING uuid INTO v_occ_uuid;
    RETURN QUERY SELECT * FROM api.projected_event_occurrences WHERE uuid = v_occ_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.realize_projected_event_occurrence(text, date, date, bigint, text) TO pgbudget_user;

CREATE OR REPLACE FUNCTION api.unrealize_projected_event_occurrence(
    p_event_uuid        text,
    p_scheduled_month   date
) RETURNS boolean AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
BEGIN
    v_user_data := utils.get_user();
    SELECT id INTO v_event_id FROM data.projected_events WHERE uuid = p_event_uuid AND user_data = v_user_data;
    IF v_event_id IS NULL THEN RAISE EXCEPTION 'Projected event not found'; END IF;
    DELETE FROM data.projected_event_occurrences WHERE projected_event_id = v_event_id AND scheduled_month = date_trunc('month', p_scheduled_month)::date AND user_data = v_user_data;
    RETURN found;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.unrealize_projected_event_occurrence(text, date) TO pgbudget_user;

CREATE OR REPLACE FUNCTION api.get_projected_event_occurrences(p_event_uuid text)
RETURNS SETOF api.projected_event_occurrences AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
BEGIN
    v_user_data := utils.get_user();
    SELECT id INTO v_event_id FROM data.projected_events WHERE uuid = p_event_uuid AND user_data = v_user_data;
    IF v_event_id IS NULL THEN RAISE EXCEPTION 'Projected event not found'; END IF;
    RETURN QUERY SELECT * FROM api.projected_event_occurrences WHERE projected_event_uuid = p_event_uuid ORDER BY scheduled_month;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

GRANT EXECUTE ON FUNCTION api.get_projected_event_occurrences(text) TO pgbudget_user;
-- +goose StatementEnd
