-- +goose Up
-- Core cash flow projection engine that aggregates all sources of future cash flow
-- into a month-by-month table, plus a summary function for net/cumulative balances

-- +goose StatementBegin
-- Generate monthly cash flow projection from all data sources
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
    amount numeric(15,2)
) as $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
begin
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    -- Validate ledger
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    return query

    -- ========================================================================
    -- 1. INCOME SOURCES (positive amounts)
    -- ========================================================================
    select
        gs.month::date as month,
        'income'::text as source_type,
        i.id as source_id,
        i.uuid as source_uuid,
        'income'::text as category,
        coalesce(i.income_subtype, i.income_type) as subcategory,
        i.name as description,
        i.amount as amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id
      and i.user_data = v_user_data
      and i.is_active = true
      and i.frequency = 'monthly'

    union all

    -- Income sources: annual frequency (only in specified months)
    select
        gs.month::date as month,
        'income'::text as source_type,
        i.id as source_id,
        i.uuid as source_uuid,
        'income'::text as category,
        coalesce(i.income_subtype, i.income_type) as subcategory,
        i.name as description,
        i.amount as amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id
      and i.user_data = v_user_data
      and i.is_active = true
      and i.frequency in ('annual', 'semiannual')
      and (
          i.occurrence_months is null
          or extract(month from gs.month)::integer = any(i.occurrence_months)
      )

    union all

    -- Income sources: one_time
    select
        date_trunc('month', i.start_date)::date as month,
        'income'::text as source_type,
        i.id as source_id,
        i.uuid as source_uuid,
        'income'::text as category,
        coalesce(i.income_subtype, i.income_type) as subcategory,
        i.name as description,
        i.amount as amount
    from data.income_sources i
    where i.ledger_id = v_ledger_id
      and i.user_data = v_user_data
      and i.is_active = true
      and i.frequency = 'one_time'
      and date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp)
      and date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)

    union all

    -- ========================================================================
    -- 2. PAYROLL DEDUCTIONS (negative amounts)
    -- ========================================================================
    select
        gs.month::date as month,
        'deduction'::text as source_type,
        d.id as source_id,
        d.uuid as source_uuid,
        'deduction'::text as category,
        d.deduction_type as subcategory,
        d.name as description,
        -(coalesce(d.fixed_amount, d.estimated_amount, 0)) as amount
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id
      and d.user_data = v_user_data
      and d.is_active = true
      and d.frequency = 'monthly'
      -- filter by occurrence_months if set
      and (
          d.occurrence_months is null
          or extract(month from gs.month)::integer = any(d.occurrence_months)
      )

    union all

    -- Payroll deductions: annual
    select
        gs.month::date as month,
        'deduction'::text as source_type,
        d.id as source_id,
        d.uuid as source_uuid,
        'deduction'::text as category,
        d.deduction_type as subcategory,
        d.name as description,
        -(coalesce(d.fixed_amount, d.estimated_amount, 0)) as amount
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id
      and d.user_data = v_user_data
      and d.is_active = true
      and d.frequency in ('annual', 'semiannual')
      and (
          d.occurrence_months is not null
          and extract(month from gs.month)::integer = any(d.occurrence_months)
      )

    union all

    -- ========================================================================
    -- 3. OBLIGATIONS (negative amounts)
    -- ========================================================================
    -- Use existing scheduled obligation payments
    select
        date_trunc('month', op.due_date)::date as month,
        'obligation'::text as source_type,
        o.id as source_id,
        o.uuid as source_uuid,
        coalesce(o.obligation_type, 'other') as category,
        coalesce(o.obligation_subtype, o.obligation_type) as subcategory,
        o.name as description,
        -(op.scheduled_amount) as amount
    from data.obligation_payments op
    join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id
      and o.user_data = v_user_data
      and o.is_active = true
      and op.status in ('scheduled', 'partial')
      and op.due_date >= p_start_month
      and op.due_date <= v_end_month

    union all

    -- For obligations without pre-generated payments, project from obligation params
    -- (obligations that have no future scheduled payments but have an active schedule)
    select
        gs.month::date as month,
        'obligation'::text as source_type,
        o.id as source_id,
        o.uuid as source_uuid,
        coalesce(o.obligation_type, 'other') as category,
        coalesce(o.obligation_subtype, o.obligation_type) as subcategory,
        o.name as description,
        -(coalesce(o.fixed_amount, o.estimated_amount, 0)) as amount
    from data.obligations o
    cross join lateral generate_series(
        greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        interval '1 month'
    ) as gs(month)
    where o.ledger_id = v_ledger_id
      and o.user_data = v_user_data
      and o.is_active = true
      and o.frequency = 'monthly'
      -- Only include if there are no scheduled payments for this month
      and not exists (
          select 1 from data.obligation_payments op2
          where op2.obligation_id = o.id
            and date_trunc('month', op2.due_date) = gs.month
            and op2.status in ('scheduled', 'paid', 'partial')
      )
      -- Must be after the last paid payment
      and gs.month > coalesce(
          (select max(date_trunc('month', op3.due_date))
           from data.obligation_payments op3
           where op3.obligation_id = o.id and op3.status = 'paid'),
          '1900-01-01'::date
      )

    union all

    -- ========================================================================
    -- 4. LOAN AMORTIZATION (negative amounts, split into amort and interest)
    -- ========================================================================
    select
        la.month,
        'loan_amort'::text as source_type,
        l.id as source_id,
        l.uuid as source_uuid,
        'expense'::text as category,
        'ln amort'::text as subcategory,
        l.lender_name || ' amort' as description,
        -(la.amortization)::numeric(15,2) as amount
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id
      and l.user_data = v_user_data
      and l.status = 'active'

    union all

    select
        la.month,
        'loan_interest'::text as source_type,
        l.id as source_id,
        l.uuid as source_uuid,
        'interest'::text as category,
        'ln int'::text as subcategory,
        l.lender_name || ' int' as description,
        -(la.interest)::numeric(15,2) as amount
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id
      and l.user_data = v_user_data
      and l.status = 'active'

    union all

    -- ========================================================================
    -- 5. INSTALLMENT PLANS (negative amounts)
    -- ========================================================================
    select
        date_trunc('month', s.due_date)::date as month,
        'installment'::text as source_type,
        ip.id as source_id,
        ip.uuid as source_uuid,
        'expense'::text as category,
        'installment'::text as subcategory,
        ip.description as description,
        -(s.scheduled_amount)::numeric(15,2) as amount
    from data.installment_plans ip
    join data.installment_schedules s on s.installment_plan_id = ip.id
    where ip.ledger_id = v_ledger_id
      and ip.user_data = v_user_data
      and ip.status = 'active'
      and s.status = 'scheduled'
      and s.due_date >= p_start_month
      and s.due_date <= v_end_month

    union all

    -- ========================================================================
    -- 6. RECURRING TRANSACTIONS (positive for inflow, negative for outflow)
    -- ========================================================================
    select
        gs.month::date as month,
        'recurring'::text as source_type,
        rt.id as source_id,
        rt.uuid as source_uuid,
        case when rt.transaction_type = 'inflow' then 'income' else 'expense' end as category,
        'recurring'::text as subcategory,
        rt.description as description,
        case
            when rt.transaction_type = 'inflow' then (rt.amount / 100.0)::numeric(15,2)
            else -(rt.amount / 100.0)::numeric(15,2)
        end as amount
    from data.recurring_transactions rt
    cross join lateral generate_series(
        greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)),
        least(
            coalesce(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)),
            date_trunc('month', v_end_month::timestamp)
        ),
        case rt.frequency
            when 'monthly' then interval '1 month'
            when 'yearly' then interval '1 year'
            when 'weekly' then interval '1 week'
            when 'biweekly' then interval '2 weeks'
            when 'daily' then interval '1 day'
            else interval '1 month'
        end
    ) as gs(month)
    where rt.ledger_id = v_ledger_id
      and rt.user_data = v_user_data
      and rt.enabled = true

    union all

    -- ========================================================================
    -- 7. PROJECTED EVENTS (positive for inflow, negative for outflow)
    -- ========================================================================
    select
        date_trunc('month', e.event_date)::date as month,
        'event'::text as source_type,
        e.id as source_id,
        e.uuid as source_uuid,
        e.event_type as category,
        e.event_type as subcategory,
        e.name as description,
        case
            when e.direction = 'inflow' then e.amount
            else -(e.amount)
        end as amount
    from data.projected_events e
    where e.ledger_id = v_ledger_id
      and e.user_data = v_user_data
      and e.is_realized = false
      and e.event_date >= p_start_month
      and e.event_date <= v_end_month

    order by 1, 2, 7;  -- order by month, source_type, description
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Summary function: aggregates projection into net monthly and cumulative balances
create or replace function api.get_projection_summary(
    p_ledger_uuid text,
    p_start_month date default date_trunc('month', current_date)::date,
    p_months_ahead integer default 120
) returns table (
    month date,
    total_income numeric(15,2),
    total_deductions numeric(15,2),
    total_obligations numeric(15,2),
    total_loan_amort numeric(15,2),
    total_loan_interest numeric(15,2),
    total_installments numeric(15,2),
    total_recurring numeric(15,2),
    total_events numeric(15,2),
    net_monthly_balance numeric(15,2),
    cumulative_balance numeric(15,2)
) as $$
begin
    return query
    select
        p.month,
        coalesce(sum(case when p.source_type = 'income' then p.amount end), 0)::numeric(15,2)
            as total_income,
        coalesce(sum(case when p.source_type = 'deduction' then p.amount end), 0)::numeric(15,2)
            as total_deductions,
        coalesce(sum(case when p.source_type = 'obligation' then p.amount end), 0)::numeric(15,2)
            as total_obligations,
        coalesce(sum(case when p.source_type = 'loan_amort' then p.amount end), 0)::numeric(15,2)
            as total_loan_amort,
        coalesce(sum(case when p.source_type = 'loan_interest' then p.amount end), 0)::numeric(15,2)
            as total_loan_interest,
        coalesce(sum(case when p.source_type = 'installment' then p.amount end), 0)::numeric(15,2)
            as total_installments,
        coalesce(sum(case when p.source_type = 'recurring' then p.amount end), 0)::numeric(15,2)
            as total_recurring,
        coalesce(sum(case when p.source_type = 'event' then p.amount end), 0)::numeric(15,2)
            as total_events,
        coalesce(sum(p.amount), 0)::numeric(15,2)
            as net_monthly_balance,
        (sum(coalesce(sum(p.amount), 0)) over (order by p.month))::numeric(15,2)
            as cumulative_balance
    from api.generate_cash_flow_projection(p_ledger_uuid, p_start_month, p_months_ahead) p
    group by p.month
    order by p.month;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists api.get_projection_summary(text, date, integer);
drop function if exists api.generate_cash_flow_projection(text, date, integer);
