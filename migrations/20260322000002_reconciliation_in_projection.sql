-- +goose Up
-- Surface bank reconciliation adjustments as a distinct line in the cash-flow projection.
--
-- Changes:
--   1. Recreate api.generate_cash_flow_projection:
--      - Branch 8 (actual transactions): exclude reconciliation adjustment transactions
--      - New Branch 9: reconciliation adjustments keyed by reconciliation UUID,
--        with source_type='reconciliation', so they render as their own group.

-- +goose StatementBegin
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
    --    CC charges with billing cycle config shift to their payment due month.
    --    Includes: inflows to asset, outflows from asset, CC charges (credit=liability).
    --    Excludes: CC bill payments (debit=liability → unknown), reversals, corrected
    --              originals, transactions matched to projected events, and reconciliation
    --              adjustments (shown separately in Branch 9).
    SELECT
        CASE
            WHEN ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL
            THEN date_trunc('month',
                     utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days)
                 )::date
            ELSE date_trunc('month', t.date)::date
        END                                AS month,
        'transaction'                      AS source_type,
        t.id                               AS source_id,
        t.uuid                             AS source_uuid,
        'transaction'                      AS category,
        utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) AS subcategory,
        CASE
            WHEN ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL
            THEN t.description || ' [due '
                 || to_char(utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days), 'Mon DD')
                 || ']'
            ELSE t.description
        END                                AS description,
        CASE utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id)
            WHEN 'inflow'  THEN  t.amount
            WHEN 'outflow' THEN -t.amount
            ELSE 0
        END AS amount
    FROM data.transactions t
    LEFT JOIN data.accounts ca ON ca.id = t.credit_account_id
    LEFT JOIN data.credit_card_limits ccl
           ON ccl.credit_card_account_id = t.credit_account_id
          AND ccl.user_data = v_user_data
          AND ccl.is_active = true
    WHERE t.ledger_id = v_ledger_id
      AND t.user_data = v_user_data
      AND t.deleted_at IS NULL
      -- Expanded outer date range: captures prior-cycle CC charges due within projection window
      AND t.date >= (p_start_month - INTERVAL '60 days')::date
      AND t.date <= (v_end_month + INTERVAL '1 month' - INTERVAL '1 day')::date
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
      -- Exclude reconciliation adjustment transactions (shown in Branch 9)
      AND NOT EXISTS (
          SELECT 1 FROM data.reconciliations r2
          WHERE r2.adjustment_transaction_id = t.id
            AND r2.user_data = v_user_data
      )
      -- Filter by payment-adjusted month:
      --   Non-CC or CC without billing config → use transaction date month
      --   CC with billing config → use payment due date month
      AND (
          (
              (ca.type IS DISTINCT FROM 'liability' OR ccl.statement_day_of_month IS NULL)
              AND date_trunc('month', t.date) BETWEEN p_start_month AND v_end_month
          )
          OR
          (
              ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL
              AND date_trunc('month',
                      utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days)
                  ) BETWEEN p_start_month AND v_end_month
          )
      )

    UNION ALL

    -- 9. RECONCILIATION ADJUSTMENTS: net untracked cash flows captured by bank reconciliation
    --    checkpoints. Each non-zero adjustment represents real spending or income that was
    --    not individually entered. Keyed by reconciliation UUID so each checkpoint is one row.
    SELECT
        date_trunc('month', r.reconciliation_date)::date  AS month,
        'reconciliation'::text                             AS source_type,
        r.id                                               AS source_id,
        r.uuid                                             AS source_uuid,
        'reconciliation'::text                             AS category,
        'adjustment'::text                                 AS subcategory,
        a.name || ' — recon adj (' || to_char(r.reconciliation_date, 'Mon YYYY') || ')' AS description,
        CASE
            WHEN t.credit_account_id = a.id THEN -t.amount   -- asset credited → outflow
            ELSE                                  t.amount   -- asset debited  → inflow
        END                                                AS amount
    FROM data.reconciliations r
    JOIN data.accounts ra ON ra.id = r.account_id
        AND ra.ledger_id = v_ledger_id AND ra.user_data = v_user_data
    JOIN data.transactions t ON t.id = r.adjustment_transaction_id
    JOIN data.accounts a ON (a.id = t.debit_account_id OR a.id = t.credit_account_id)
        AND a.type = 'asset'
    WHERE r.user_data = v_user_data
      AND r.difference <> 0
      AND date_trunc('month', r.reconciliation_date) BETWEEN p_start_month AND v_end_month

    ORDER BY 1, 2, 7;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd

-- +goose Down

-- +goose StatementBegin
-- Restore Branch 8 without the reconciliation exclusion; remove Branch 9.
-- (Re-running the previous migration's function definition.)
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
    UNION ALL SELECT CASE WHEN ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL THEN date_trunc('month', utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days))::date ELSE date_trunc('month', t.date)::date END, 'transaction'::text, t.id, t.uuid, 'transaction'::text, utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id), CASE WHEN ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL THEN t.description || ' [due ' || to_char(utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days), 'Mon DD') || ']' ELSE t.description END, CASE utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) WHEN 'inflow' THEN t.amount WHEN 'outflow' THEN -t.amount ELSE 0 END FROM data.transactions t LEFT JOIN data.accounts ca ON ca.id = t.credit_account_id LEFT JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = t.credit_account_id AND ccl.user_data = v_user_data AND ccl.is_active = true WHERE t.ledger_id = v_ledger_id AND t.user_data = v_user_data AND t.deleted_at IS NULL AND t.date >= (p_start_month - INTERVAL '60 days')::date AND t.date <= (v_end_month + INTERVAL '1 month' - INTERVAL '1 day')::date AND utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) <> 'unknown' AND NOT EXISTS (SELECT 1 FROM data.transaction_log tl WHERE tl.reversal_transaction_id = t.id) AND NOT EXISTS (SELECT 1 FROM data.transaction_log tl WHERE tl.original_transaction_id = t.id AND tl.reversal_transaction_id IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM data.projected_events pe WHERE pe.linked_transaction_id = t.id AND pe.is_realized = true) AND NOT EXISTS (SELECT 1 FROM data.projected_event_occurrences peo WHERE peo.transaction_id = t.id AND peo.is_realized = true) AND (((ca.type IS DISTINCT FROM 'liability' OR ccl.statement_day_of_month IS NULL) AND date_trunc('month', t.date) BETWEEN p_start_month AND v_end_month) OR (ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL AND date_trunc('month', utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days)) BETWEEN p_start_month AND v_end_month))
    ORDER BY 1, 2, 7;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd
