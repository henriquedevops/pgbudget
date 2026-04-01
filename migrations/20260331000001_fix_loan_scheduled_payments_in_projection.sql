-- +goose Up
-- +goose StatementBegin

-- Fix 1: utils.project_loan_amortization
-- Before the main loop, subtract scheduled-but-pre-window principals from v_balance
-- so that loans whose remaining payments are already recorded as 'scheduled' in
-- data.loan_payments do not generate phantom projections starting at p_start_month.
-- Also skip months in the loop that already have a scheduled entry, using the
-- bank-recorded amounts rather than recomputing them.

CREATE OR REPLACE FUNCTION utils.project_loan_amortization(
    p_loan_id    bigint,
    p_start_month date,
    p_months_ahead integer
)
RETURNS TABLE(
    month             date,
    payment_number    integer,
    amortization      numeric,
    interest          numeric,
    total_payment     numeric,
    remaining_balance numeric
)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
declare
    v_loan record;
    v_balance numeric(19,4);
    v_monthly_rate numeric(19,8);
    v_payment numeric(19,4);
    v_interest_amt numeric(19,4);
    v_principal_amt numeric(19,4);
    v_current_month date;
    v_end_month date;
    v_payment_num integer;
    v_initial_payments_made integer;
    v_loan_first_month date;
    v_months_back integer;
    v_idx integer;
    v_pre_window_scheduled numeric(19,4);
    v_sched_principal numeric(19,4);
    v_sched_interest numeric(19,4);
begin
    -- Get loan details
    select l.* into v_loan
    from data.loans l
    where l.id = p_loan_id;

    if not found then
        return;
    end if;

    -- Only project active loans
    if v_loan.status != 'active' then
        return;
    end if;

    -- Calculate monthly interest rate
    -- interest_rate is stored as annual percentage (e.g., 12.0 for 12%)
    v_monthly_rate := v_loan.interest_rate / 100.0 / 12.0;

    -- Determine starting balance and payment number
    -- Check how many payments have been made (from loan_payments table)
    select count(*) into v_initial_payments_made
    from data.loan_payments lp
    where lp.loan_id = p_loan_id
      and lp.status = 'paid';

    -- Use current_balance from the loan record as starting point
    v_balance := v_loan.current_balance;
    v_payment := v_loan.payment_amount;
    v_payment_num := v_initial_payments_made;

    v_loan_first_month := date_trunc('month', v_loan.first_payment_date)::date;
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    if date_trunc('month', p_start_month::timestamp)::date < v_loan_first_month then
        -- Requested start is before the loan's first payment month.
        -- Reverse-amortize current_balance backwards to get the historical balance
        -- at p_start_month, then start projecting from there.
        v_months_back := (
            (date_part('year', v_loan_first_month) * 12 + date_part('month', v_loan_first_month)) -
            (date_part('year', date_trunc('month', p_start_month::timestamp)::date) * 12 +
             date_part('month', date_trunc('month', p_start_month::timestamp)::date))
        )::integer;

        -- For a Price (constant-payment) loan: B_prev = (B + PMT) / (1 + r)
        -- For a 0% loan: B_prev = B + PMT
        -- (For interest_only, balance is constant, same formula gives B_prev = B since PMT = B*r)
        for v_idx in 1..v_months_back loop
            if v_monthly_rate > 0 then
                v_balance := (v_balance + v_payment) / (1.0 + v_monthly_rate);
            else
                v_balance := v_balance + v_payment;
            end if;
        end loop;

        v_current_month := date_trunc('month', p_start_month::timestamp)::date;
    else
        -- Requested start is at or after the first payment month: use original logic
        v_current_month := greatest(
            date_trunc('month', p_start_month::timestamp)::date,
            v_loan_first_month
        );
    end if;

    -- Skip months that already have paid loan_payments
    -- by advancing to after the last paid payment
    declare
        v_last_paid_date date;
    begin
        select max(lp.due_date) into v_last_paid_date
        from data.loan_payments lp
        where lp.loan_id = p_loan_id
          and lp.status = 'paid';

        if v_last_paid_date is not null and v_last_paid_date >= v_current_month then
            v_current_month := (date_trunc('month', v_last_paid_date) + interval '1 month')::date;
        end if;
    end;

    -- Subtract the principal of any SCHEDULED (not yet paid) loan_payments that are
    -- due BEFORE v_current_month. These represent payments already planned but not yet
    -- executed; since current_balance already reflects them as outstanding, re-projecting
    -- them from v_current_month would generate phantom future payments.
    select coalesce(sum(coalesce(lp.actual_principal, lp.scheduled_principal)), 0)
    into v_pre_window_scheduled
    from data.loan_payments lp
    where lp.loan_id = p_loan_id
      and lp.status = 'scheduled'
      and date_trunc('month', lp.due_date) < v_current_month;

    v_balance := greatest(v_balance - v_pre_window_scheduled, 0);

    -- Project future payments
    while v_current_month <= v_end_month and v_balance > 0.01 loop
        v_payment_num := v_payment_num + 1;

        -- If this month already has a scheduled (or paid) entry in loan_payments,
        -- use the bank-recorded amounts and skip recomputing via the formula.
        -- This keeps the projection in sync with the actual payment schedule.
        select coalesce(sum(coalesce(lp.actual_principal, lp.scheduled_principal)), 0),
               coalesce(sum(coalesce(lp.actual_interest,  lp.scheduled_interest)),  0)
        into v_sched_principal, v_sched_interest
        from data.loan_payments lp
        where lp.loan_id = p_loan_id
          and lp.status in ('paid', 'scheduled')
          and date_trunc('month', lp.due_date) = v_current_month;

        if v_sched_principal > 0 or v_sched_interest > 0 then
            -- Entry exists: reduce balance by scheduled principal and skip
            -- (section 4c/4d or 4e/4f in generate_cash_flow_projection will emit the row)
            v_balance := greatest(v_balance - v_sched_principal, 0);
            v_current_month := (v_current_month + interval '1 month')::date;
            continue;
        end if;

        -- Calculate interest for this period
        v_interest_amt := round(v_balance * v_monthly_rate, 4);

        -- Handle different amortization types
        if v_loan.amortization_type = 'interest_only' then
            -- Interest-only: no principal reduction
            v_principal_amt := 0;
            month := v_current_month;
            payment_number := v_payment_num;
            amortization := v_principal_amt;
            interest := v_interest_amt;
            total_payment := v_interest_amt;
            remaining_balance := v_balance;
            return next;
        else
            -- Standard amortization (Price system)
            -- If payment covers more than remaining balance + interest, adjust
            if v_payment >= v_balance + v_interest_amt then
                v_principal_amt := v_balance;
                v_balance := 0;
            else
                v_principal_amt := v_payment - v_interest_amt;
                -- Guard against negative principal (rate too high for payment)
                if v_principal_amt < 0 then
                    v_principal_amt := 0;
                end if;
                v_balance := v_balance - v_principal_amt;
            end if;

            month := v_current_month;
            payment_number := v_payment_num;
            amortization := v_principal_amt;
            interest := v_interest_amt;
            total_payment := v_principal_amt + v_interest_amt;
            remaining_balance := v_balance;
            return next;
        end if;

        v_current_month := (v_current_month + interval '1 month')::date;
    end loop;

    return;
end;
$$;

-- +goose StatementEnd
-- +goose StatementBegin

-- Fix 2: api.generate_cash_flow_projection
-- Add sections 4e and 4f: scheduled (not yet paid) loan_payments within the projection
-- window. These use the bank-recorded amounts rather than the amortization formula,
-- and are now the sole source for months covered by scheduled entries (section 4
-- skips those months after the fix to project_loan_amortization above).

CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid  text,
    p_start_month  date    DEFAULT date_trunc('month', CURRENT_DATE)::date,
    p_months_ahead integer DEFAULT 120
)
RETURNS TABLE(
    month        date,
    source_type  text,
    source_id    bigint,
    source_uuid  text,
    category     text,
    subcategory  text,
    description  text,
    amount       bigint
)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
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

    -- 4c. LOAN AMORTIZATION - historical paid payments (numeric(19,4) -> cents)
    SELECT
        date_trunc('month', lp.due_date)::date, 'loan_amort'::text, l.id, l.uuid,
        'expense'::text, 'ln amort'::text, l.lender_name || ' amort',
        -(COALESCE(lp.actual_principal, lp.scheduled_principal) * 100)::bigint
    FROM data.loan_payments lp
    JOIN data.loans l ON l.id = lp.loan_id
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
      AND lp.status = 'paid'
      AND date_trunc('month', lp.due_date) BETWEEN p_start_month AND v_end_month

    UNION ALL

    -- 4d. LOAN INTEREST - historical paid payments (numeric(19,4) -> cents)
    SELECT
        date_trunc('month', lp.due_date)::date, 'loan_interest'::text, l.id, l.uuid,
        'interest'::text, 'ln int'::text, l.lender_name || ' int',
        -(COALESCE(lp.actual_interest, lp.scheduled_interest) * 100)::bigint
    FROM data.loan_payments lp
    JOIN data.loans l ON l.id = lp.loan_id
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
      AND lp.status = 'paid'
      AND date_trunc('month', lp.due_date) BETWEEN p_start_month AND v_end_month

    UNION ALL

    -- 4e. LOAN AMORTIZATION - scheduled (not yet paid) payments within window
    -- Uses bank-recorded amounts; project_loan_amortization now skips these months.
    SELECT
        date_trunc('month', lp.due_date)::date, 'loan_amort'::text, l.id, l.uuid,
        'expense'::text, 'ln amort'::text, l.lender_name || ' amort',
        -(COALESCE(lp.actual_principal, lp.scheduled_principal) * 100)::bigint
    FROM data.loan_payments lp
    JOIN data.loans l ON l.id = lp.loan_id
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
      AND lp.status = 'scheduled'
      AND date_trunc('month', lp.due_date) BETWEEN p_start_month AND v_end_month

    UNION ALL

    -- 4f. LOAN INTEREST - scheduled (not yet paid) payments within window
    SELECT
        date_trunc('month', lp.due_date)::date, 'loan_interest'::text, l.id, l.uuid,
        'interest'::text, 'ln int'::text, l.lender_name || ' int',
        -(COALESCE(lp.actual_interest, lp.scheduled_interest) * 100)::bigint
    FROM data.loan_payments lp
    JOIN data.loans l ON l.id = lp.loan_id
    WHERE l.ledger_id = v_ledger_id AND l.user_data = v_user_data AND l.status = 'active'
      AND lp.status = 'scheduled'
      AND date_trunc('month', lp.due_date) BETWEEN p_start_month AND v_end_month
      AND COALESCE(lp.actual_interest, lp.scheduled_interest) > 0

    UNION ALL

    -- 5. INSTALLMENT PLANS (already in cents - no * 100)
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

    -- 8. ACTUAL TRANSACTIONS
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
      AND t.date >= (p_start_month - INTERVAL '60 days')::date
      AND t.date <= (v_end_month + INTERVAL '1 month' - INTERVAL '1 day')::date
      AND utils.derive_transaction_direction(t.debit_account_id, t.credit_account_id) <> 'unknown'
      AND NOT EXISTS (SELECT 1 FROM data.transaction_log tl WHERE tl.reversal_transaction_id = t.id)
      AND NOT EXISTS (SELECT 1 FROM data.transaction_log tl WHERE tl.original_transaction_id = t.id AND tl.reversal_transaction_id IS NOT NULL)
      AND NOT EXISTS (SELECT 1 FROM data.projected_events pe WHERE pe.linked_transaction_id = t.id AND pe.is_realized = true)
      AND NOT EXISTS (SELECT 1 FROM data.projected_event_occurrences peo WHERE peo.transaction_id = t.id AND peo.is_realized = true)
      AND NOT EXISTS (SELECT 1 FROM data.reconciliations r2 WHERE r2.adjustment_transaction_id = t.id AND r2.user_data = v_user_data)
      AND (
          ((ca.type IS DISTINCT FROM 'liability' OR ccl.statement_day_of_month IS NULL)
           AND date_trunc('month', t.date) BETWEEN p_start_month AND v_end_month)
          OR
          (ca.type = 'liability' AND ccl.statement_day_of_month IS NOT NULL
           AND date_trunc('month', utils.cc_charge_due_date(t.date, ccl.statement_day_of_month, ccl.due_date_offset_days)) BETWEEN p_start_month AND v_end_month)
      )

    UNION ALL

    -- 9. RECONCILIATION ADJUSTMENTS
    SELECT
        date_trunc('month', r.reconciliation_date)::date,
        'reconciliation'::text, r.id, r.uuid,
        'reconciliation'::text, 'adjustment'::text,
        a.name || ' - recon adj (' || to_char(r.reconciliation_date, 'Mon YYYY') || ')',
        CASE WHEN t.credit_account_id = a.id THEN -t.amount ELSE t.amount END
    FROM data.reconciliations r
    JOIN data.accounts ra ON ra.id = r.account_id AND ra.ledger_id = v_ledger_id AND ra.user_data = v_user_data
    JOIN data.transactions t ON t.id = r.adjustment_transaction_id
    JOIN data.accounts a ON (a.id = t.debit_account_id OR a.id = t.credit_account_id) AND a.type = 'asset'
    WHERE r.user_data = v_user_data
      AND r.difference <> 0
      AND date_trunc('month', r.reconciliation_date) BETWEEN p_start_month AND v_end_month

    ORDER BY 1, 2, 7;
END;
$$;

GRANT EXECUTE ON FUNCTION api.generate_cash_flow_projection(text, date, integer) TO pgbudget_user;
GRANT EXECUTE ON FUNCTION utils.project_loan_amortization(bigint, date, integer) TO pgbudget_user;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
SELECT 1; -- no rollback needed; previous versions are in git
-- +goose StatementEnd
