-- +goose Up
-- Add credit card payment projection to the cash flow engine.
--
-- CC payments are a real monthly cash outflow not currently modelled.
-- Logic per CC liability account (excluding accounts linked to active loans):
--
--   No CC limits configured, OR auto_payment_enabled = false, OR type = 'full_balance':
--     → project current balance monthly (steady-state: user charges ≈ balance/month)
--
--   auto_payment_type = 'minimum':
--     → project GREATEST(balance * min_pct%, flat_min) monthly
--
--   auto_payment_type = 'fixed_amount':
--     → project auto_payment_amount monthly
--
-- All branches skip accounts with zero balance and accounts already covered by the
-- loan amortization section (data.loans.account_id match).

-- +goose StatementBegin
create or replace function api.generate_cash_flow_projection(
    p_ledger_uuid text,
    p_start_month date default date_trunc('month', current_date)::date,
    p_months_ahead integer default 120
) returns table (
    month date,
    source_type text,
    source_id bigint,
    source_uuid text,
    category text,
    subcategory text,
    description text,
    amount bigint
) as $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
begin
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    return query

    -- 1. INCOME SOURCES: monthly (already in cents)
    select
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'monthly'

    union all

    -- 1b. INCOME SOURCES: annual/semiannual
    select
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true
      and i.frequency in ('annual', 'semiannual')
      and (i.occurrence_months is null or extract(month from gs.month)::integer = any(i.occurrence_months))

    union all

    -- 1c. INCOME SOURCES: one_time
    select
        date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true
      and i.frequency = 'one_time'
      and date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp)
      and date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)

    union all

    -- 2. PAYROLL DEDUCTIONS: monthly (already in cents, negate)
    select
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency = 'monthly'
      and (d.occurrence_months is null or extract(month from gs.month)::integer = any(d.occurrence_months))

    union all

    -- 2b. PAYROLL DEDUCTIONS: annual/semiannual
    select
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true
      and d.frequency in ('annual', 'semiannual')
      and (d.occurrence_months is not null and extract(month from gs.month)::integer = any(d.occurrence_months))

    union all

    -- 3. OBLIGATIONS: from scheduled payments (decimal(15,2) -> cents)
    select
        date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid,
        coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type),
        o.name, -(op.scheduled_amount * 100)::bigint
    from data.obligation_payments op
    join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true
      and op.status in ('scheduled', 'partial')
      and op.due_date >= p_start_month and op.due_date <= v_end_month

    union all

    -- 3b. OBLIGATIONS: projected from params (decimal(15,2) -> cents)
    select
        gs.month::date, 'obligation'::text, o.id, o.uuid,
        coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type),
        o.name, -(coalesce(o.fixed_amount, o.estimated_amount, 0) * 100)::bigint
    from data.obligations o
    cross join lateral generate_series(
        greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and o.frequency = 'monthly'
      and not exists (
          select 1 from data.obligation_payments op2
          where op2.obligation_id = o.id and date_trunc('month', op2.due_date) = gs.month
            and op2.status in ('scheduled', 'paid', 'partial')
      )
      and gs.month > coalesce(
          (select max(date_trunc('month', op3.due_date)) from data.obligation_payments op3
           where op3.obligation_id = o.id and op3.status = 'paid'), '1900-01-01'::date
      )

    union all

    -- 4. LOAN AMORTIZATION (numeric(19,4) -> cents)
    select la.month, 'loan_amort'::text, l.id, l.uuid,
        'expense'::text, 'ln amort'::text, l.lender_name || ' amort',
        -(la.amortization * 100)::bigint
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'

    union all

    -- 4b. LOAN INTEREST (numeric(19,4) -> cents)
    select la.month, 'loan_interest'::text, l.id, l.uuid,
        'interest'::text, 'ln int'::text, l.lender_name || ' int',
        -(la.interest * 100)::bigint
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'

    union all

    -- 5. INSTALLMENT PLANS (already in cents — no * 100)
    select
        date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid,
        'expense'::text, 'installment'::text, ip.description,
        -(s.scheduled_amount)::bigint
    from data.installment_plans ip
    join data.installment_schedules s on s.installment_plan_id = ip.id
    where ip.ledger_id = v_ledger_id and ip.user_data = v_user_data and ip.status = 'active'
      and s.status = 'scheduled' and s.due_date >= p_start_month and s.due_date <= v_end_month

    union all

    -- 6. RECURRING TRANSACTIONS (already in cents)
    select
        gs.month::date, 'recurring'::text, rt.id, rt.uuid,
        case when rt.transaction_type = 'inflow' then 'income' else 'expense' end,
        'recurring'::text, rt.description,
        case when rt.transaction_type = 'inflow' then rt.amount else -(rt.amount) end
    from data.recurring_transactions rt
    cross join lateral generate_series(
        greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        case rt.frequency
            when 'monthly' then interval '1 month' when 'yearly' then interval '1 year'
            when 'weekly' then interval '1 week' when 'biweekly' then interval '2 weeks'
            when 'daily' then interval '1 day' else interval '1 month' end
    ) as gs(month)
    where rt.ledger_id = v_ledger_id and rt.user_data = v_user_data and rt.enabled = true

    union all

    -- 7a. PROJECTED EVENTS: one_time (already in cents)
    select
        date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.frequency = 'one_time'
      and e.event_date >= p_start_month and e.event_date <= v_end_month

    union all

    -- 7b. PROJECTED EVENTS: monthly recurring
    select
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    cross join lateral generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.frequency = 'monthly'

    union all

    -- 7c. PROJECTED EVENTS: annual recurring
    select
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    cross join lateral generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.frequency = 'annual'
      and extract(month from gs.month)::integer = extract(month from e.event_date)::integer

    union all

    -- 7d. PROJECTED EVENTS: semiannual recurring
    select
        gs.month::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    cross join lateral generate_series(
        greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.frequency = 'semiannual'
      and extract(month from gs.month)::integer = any(array[
          extract(month from e.event_date)::integer,
          ((extract(month from e.event_date)::integer - 1 + 6) % 12) + 1
      ])

    union all

    -- 8a. CREDIT CARD PAYMENTS — no limits configured, auto-pay off, or full_balance:
    --     project current balance as steady-state monthly outflow
    select
        gs.month::date, 'cc_payment'::text, a.id, a.uuid,
        'liability'::text, 'cc_payment'::text, a.name || ' payment',
        -(bal.balance)::bigint
    from data.accounts a
    join data.ledgers l on l.id = a.ledger_id
    cross join lateral (
        select utils.get_account_balance(a.ledger_id, a.id) as balance
    ) as bal
    left join data.credit_card_limits ccl
        on ccl.credit_card_account_id = a.id
        and ccl.user_data = v_user_data
        and ccl.is_active = true
    cross join lateral generate_series(
        date_trunc('month', p_start_month::timestamp),
        date_trunc('month', v_end_month::timestamp),
        interval '1 month'
    ) as gs(month)
    where l.id = v_ledger_id
      and a.user_data = v_user_data
      and a.type = 'liability'
      and a.deleted_at is null
      and bal.balance > 0
      -- Skip accounts already covered by the loan amortization section
      and not exists (
          select 1 from data.loans lo
          where lo.account_id = a.id
            and lo.user_data = v_user_data
            and lo.status = 'active'
      )
      -- No CC limits, auto-pay off, or full_balance type
      and (
          ccl.id is null
          or not ccl.auto_payment_enabled
          or ccl.auto_payment_type = 'full_balance'
      )

    union all

    -- 8b. CREDIT CARD PAYMENTS — minimum auto-pay: monthly minimum payment
    select
        gs.month::date, 'cc_payment'::text, a.id, a.uuid,
        'liability'::text, 'cc_payment'::text, a.name || ' payment',
        -(greatest(
            (bal.balance * ccl.minimum_payment_percent / 100)::bigint,
            (ccl.minimum_payment_flat * 100)::bigint
        ))::bigint
    from data.accounts a
    join data.ledgers l on l.id = a.ledger_id
    join data.credit_card_limits ccl
        on ccl.credit_card_account_id = a.id
        and ccl.user_data = v_user_data
        and ccl.is_active = true
    cross join lateral (
        select utils.get_account_balance(a.ledger_id, a.id) as balance
    ) as bal
    cross join lateral generate_series(
        date_trunc('month', p_start_month::timestamp),
        date_trunc('month', v_end_month::timestamp),
        interval '1 month'
    ) as gs(month)
    where l.id = v_ledger_id
      and a.user_data = v_user_data
      and a.type = 'liability'
      and a.deleted_at is null
      and bal.balance > 0
      and ccl.auto_payment_enabled = true
      and ccl.auto_payment_type = 'minimum'
      and not exists (
          select 1 from data.loans lo
          where lo.account_id = a.id
            and lo.user_data = v_user_data
            and lo.status = 'active'
      )

    union all

    -- 8c. CREDIT CARD PAYMENTS — fixed-amount auto-pay: monthly fixed payment
    select
        gs.month::date, 'cc_payment'::text, a.id, a.uuid,
        'liability'::text, 'cc_payment'::text, a.name || ' payment',
        -(ccl.auto_payment_amount * 100)::bigint
    from data.accounts a
    join data.ledgers l on l.id = a.ledger_id
    join data.credit_card_limits ccl
        on ccl.credit_card_account_id = a.id
        and ccl.user_data = v_user_data
        and ccl.is_active = true
    cross join lateral (
        select utils.get_account_balance(a.ledger_id, a.id) as balance
    ) as bal
    cross join lateral generate_series(
        date_trunc('month', p_start_month::timestamp),
        date_trunc('month', v_end_month::timestamp),
        interval '1 month'
    ) as gs(month)
    where l.id = v_ledger_id
      and a.user_data = v_user_data
      and a.type = 'liability'
      and a.deleted_at is null
      and bal.balance > 0
      and ccl.auto_payment_enabled = true
      and ccl.auto_payment_type = 'fixed_amount'
      and coalesce(ccl.auto_payment_amount, 0) > 0
      and not exists (
          select 1 from data.loans lo
          where lo.account_id = a.id
            and lo.user_data = v_user_data
            and lo.status = 'active'
      )

    order by 1, 2, 7;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
-- Restore generate_cash_flow_projection to state from 20260218000002 (no CC section)
-- +goose StatementBegin
create or replace function api.generate_cash_flow_projection(
    p_ledger_uuid text,
    p_start_month date default date_trunc('month', current_date)::date,
    p_months_ahead integer default 120
) returns table (
    month date,
    source_type text,
    source_id bigint,
    source_uuid text,
    category text,
    subcategory text,
    description text,
    amount bigint
) as $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
begin
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    return query
    select gs.month::date, 'income'::text, i.id, i.uuid, 'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i cross join lateral generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'monthly'
    union all
    select gs.month::date, 'income'::text, i.id, i.uuid, 'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i cross join lateral generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency in ('annual', 'semiannual') and (i.occurrence_months is null or extract(month from gs.month)::integer = any(i.occurrence_months))
    union all
    select date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid, 'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'one_time' and date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp) and date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)
    union all
    select gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d cross join lateral generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency = 'monthly' and (d.occurrence_months is null or extract(month from gs.month)::integer = any(d.occurrence_months))
    union all
    select gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d cross join lateral generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency in ('annual', 'semiannual') and (d.occurrence_months is not null and extract(month from gs.month)::integer = any(d.occurrence_months))
    union all
    select date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid, coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type), o.name, -(op.scheduled_amount * 100)::bigint
    from data.obligation_payments op join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and op.status in ('scheduled', 'partial') and op.due_date >= p_start_month and op.due_date <= v_end_month
    union all
    select gs.month::date, 'obligation'::text, o.id, o.uuid, coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type), o.name, -(coalesce(o.fixed_amount, o.estimated_amount, 0) * 100)::bigint
    from data.obligations o cross join lateral generate_series(greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and o.frequency = 'monthly' and not exists (select 1 from data.obligation_payments op2 where op2.obligation_id = o.id and date_trunc('month', op2.due_date) = gs.month and op2.status in ('scheduled', 'paid', 'partial')) and gs.month > coalesce((select max(date_trunc('month', op3.due_date)) from data.obligation_payments op3 where op3.obligation_id = o.id and op3.status = 'paid'), '1900-01-01'::date)
    union all
    select la.month, 'loan_amort'::text, l.id, l.uuid, 'expense'::text, 'ln amort'::text, l.lender_name || ' amort', -(la.amortization * 100)::bigint
    from data.loans l cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'
    union all
    select la.month, 'loan_interest'::text, l.id, l.uuid, 'interest'::text, 'ln int'::text, l.lender_name || ' int', -(la.interest * 100)::bigint
    from data.loans l cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'
    union all
    select date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid, 'expense'::text, 'installment'::text, ip.description, -(s.scheduled_amount)::bigint
    from data.installment_plans ip join data.installment_schedules s on s.installment_plan_id = ip.id
    where ip.ledger_id = v_ledger_id and ip.user_data = v_user_data and ip.status = 'active' and s.status = 'scheduled' and s.due_date >= p_start_month and s.due_date <= v_end_month
    union all
    select gs.month::date, 'recurring'::text, rt.id, rt.uuid, case when rt.transaction_type = 'inflow' then 'income' else 'expense' end, 'recurring'::text, rt.description, case when rt.transaction_type = 'inflow' then rt.amount else -(rt.amount) end
    from data.recurring_transactions rt cross join lateral generate_series(greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), case rt.frequency when 'monthly' then interval '1 month' when 'yearly' then interval '1 year' when 'weekly' then interval '1 week' when 'biweekly' then interval '2 weeks' when 'daily' then interval '1 day' else interval '1 month' end) as gs(month)
    where rt.ledger_id = v_ledger_id and rt.user_data = v_user_data and rt.enabled = true
    union all
    select date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false and e.frequency = 'one_time' and e.event_date >= p_start_month and e.event_date <= v_end_month
    union all
    select gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e cross join lateral generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false and e.frequency = 'monthly'
    union all
    select gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e cross join lateral generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false and e.frequency = 'annual' and extract(month from gs.month)::integer = extract(month from e.event_date)::integer
    union all
    select gs.month::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e cross join lateral generate_series(greatest(date_trunc('month', e.event_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', e.recurrence_end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false and e.frequency = 'semiannual' and extract(month from gs.month)::integer = any(array[extract(month from e.event_date)::integer, ((extract(month from e.event_date)::integer - 1 + 6) % 12) + 1])
    order by 1, 2, 7;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd
