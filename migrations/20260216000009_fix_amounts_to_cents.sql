-- +goose Up
-- Fix all new tables to store monetary amounts in cents (bigint) instead of numeric(15,2)
-- This follows the pgbudget convention used in data.transactions and data.recurring_transactions

-- ============================================================================
-- 1. DROP VIEWS that depend on columns being altered
-- ============================================================================
drop view if exists api.income_sources cascade;
drop view if exists api.payroll_deductions cascade;
drop view if exists api.projected_events cascade;

-- ============================================================================
-- 2. ALTER TABLES: change amount columns to bigint (cents)
-- ============================================================================

-- income_sources: amount numeric(15,2) -> bigint
alter table data.income_sources drop constraint income_sources_positive_amount;
alter table data.income_sources alter column amount type bigint using (amount * 100)::bigint;
alter table data.income_sources add constraint income_sources_positive_amount check (amount > 0);

-- payroll_deductions: fixed_amount, estimated_amount numeric(15,2) -> bigint
alter table data.payroll_deductions drop constraint payroll_deductions_amount_required;
alter table data.payroll_deductions alter column fixed_amount type bigint using (fixed_amount * 100)::bigint;
alter table data.payroll_deductions alter column estimated_amount type bigint using (estimated_amount * 100)::bigint;
alter table data.payroll_deductions add constraint payroll_deductions_amount_required check (
    is_fixed_amount = false or fixed_amount is not null
);

-- projected_events: amount numeric(15,2) -> bigint
alter table data.projected_events drop constraint projected_events_positive_amount;
alter table data.projected_events alter column amount type bigint using (amount * 100)::bigint;
alter table data.projected_events add constraint projected_events_positive_amount check (amount > 0);

-- ============================================================================
-- 2. RECREATE API VIEWS (they expose the columns directly)
-- ============================================================================

create or replace view api.income_sources as
select
    i.uuid,
    i.name,
    i.description,
    i.income_type,
    i.income_subtype,
    i.amount,
    i.currency,
    i.frequency,
    i.pay_day_of_month,
    i.occurrence_months,
    i.start_date,
    i.end_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    i.employer_name,
    i.group_tag,
    i.is_active,
    i.notes,
    i.metadata,
    i.created_at,
    i.updated_at,
    l.uuid as ledger_uuid
from data.income_sources i
left join data.ledgers l on l.id = i.ledger_id
left join data.accounts c on c.id = i.default_category_id
where i.user_data = utils.get_user();

create or replace view api.payroll_deductions as
select
    d.uuid,
    d.name,
    d.description,
    d.deduction_type,
    d.is_fixed_amount,
    d.fixed_amount,
    d.estimated_amount,
    d.is_percentage,
    d.percentage_value,
    d.percentage_base,
    d.currency,
    d.frequency,
    d.occurrence_months,
    d.start_date,
    d.end_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    d.employer_name,
    d.group_tag,
    d.is_active,
    d.notes,
    d.metadata,
    d.created_at,
    d.updated_at,
    l.uuid as ledger_uuid
from data.payroll_deductions d
left join data.ledgers l on l.id = d.ledger_id
left join data.accounts c on c.id = d.default_category_id
where d.user_data = utils.get_user();

create or replace view api.projected_events as
select
    e.uuid,
    e.name,
    e.description,
    e.event_type,
    e.direction,
    e.amount,
    e.currency,
    e.event_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    e.is_confirmed,
    e.is_realized,
    t.uuid as linked_transaction_uuid,
    e.notes,
    e.metadata,
    e.created_at,
    e.updated_at,
    l.uuid as ledger_uuid
from data.projected_events e
left join data.ledgers l on l.id = e.ledger_id
left join data.accounts c on c.id = e.default_category_id
left join data.transactions t on t.id = e.linked_transaction_id
where e.user_data = utils.get_user();

-- ============================================================================
-- 3. RECREATE CRUD FUNCTIONS (amount params change to bigint)
-- ============================================================================

-- Drop old functions with numeric signatures (CRUD)
drop function if exists api.create_income_source(text, text, numeric, date, text, text, text, text, text, integer, integer[], date, text, text, text, text);
drop function if exists api.update_income_source(text, text, text, text, text, numeric, text, text, integer, integer[], date, date, text, text, text, boolean, text);
drop function if exists api.create_payroll_deduction(text, text, text, date, boolean, numeric, numeric, boolean, numeric, text, text, text, text, integer[], date, text, text, text, text);
drop function if exists api.update_payroll_deduction(text, text, text, text, boolean, numeric, numeric, boolean, numeric, text, text, text, integer[], date, date, text, text, text, boolean, text);
drop function if exists api.create_projected_event(text, text, numeric, date, text, text, text, text, text, boolean, text);
drop function if exists api.update_projected_event(text, text, text, text, text, numeric, text, date, text, boolean, boolean, text, text);

-- Drop old projection functions (return type changed from numeric to bigint)
drop function if exists api.get_projection_summary(text, date, integer);
drop function if exists api.generate_cash_flow_projection(text, date, integer);

-- +goose StatementBegin
create or replace function api.create_income_source(
    p_ledger_uuid text,
    p_name text,
    p_amount bigint,
    p_start_date date,
    p_income_type text default 'salary',
    p_income_subtype text default null,
    p_description text default null,
    p_currency text default 'BRL',
    p_frequency text default 'monthly',
    p_pay_day_of_month integer default null,
    p_occurrence_months integer[] default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_notes text default null
) returns setof api.income_sources as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_income_uuid text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    insert into data.income_sources (
        ledger_id, name, description, income_type, income_subtype,
        amount, currency, frequency, pay_day_of_month, occurrence_months,
        start_date, end_date, default_category_id,
        employer_name, group_tag, notes
    ) values (
        v_ledger_id, p_name, p_description, p_income_type, p_income_subtype,
        p_amount, p_currency, p_frequency, p_pay_day_of_month, p_occurrence_months,
        p_start_date, p_end_date, v_category_id,
        p_employer_name, p_group_tag, p_notes
    ) returning uuid into v_income_uuid;

    return query
    select * from api.income_sources where uuid = v_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.update_income_source(
    p_income_uuid text,
    p_name text default null,
    p_description text default null,
    p_income_type text default null,
    p_income_subtype text default null,
    p_amount bigint default null,
    p_currency text default null,
    p_frequency text default null,
    p_pay_day_of_month integer default null,
    p_occurrence_months integer[] default null,
    p_start_date date default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_is_active boolean default null,
    p_notes text default null
) returns setof api.income_sources as $$
declare
    v_income_id bigint;
    v_category_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_income_id
    from data.income_sources
    where uuid = p_income_uuid and user_data = v_user_data;

    if v_income_id is null then
        raise exception 'Income source not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    update data.income_sources
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        income_type = coalesce(p_income_type, income_type),
        income_subtype = coalesce(p_income_subtype, income_subtype),
        amount = coalesce(p_amount, amount),
        currency = coalesce(p_currency, currency),
        frequency = coalesce(p_frequency, frequency),
        pay_day_of_month = coalesce(p_pay_day_of_month, pay_day_of_month),
        occurrence_months = coalesce(p_occurrence_months, occurrence_months),
        start_date = coalesce(p_start_date, start_date),
        end_date = coalesce(p_end_date, end_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        employer_name = coalesce(p_employer_name, employer_name),
        group_tag = coalesce(p_group_tag, group_tag),
        is_active = coalesce(p_is_active, is_active),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_income_id;

    return query
    select * from api.income_sources where uuid = p_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.create_payroll_deduction(
    p_ledger_uuid text,
    p_name text,
    p_deduction_type text,
    p_start_date date,
    p_is_fixed_amount boolean default true,
    p_fixed_amount bigint default null,
    p_estimated_amount bigint default null,
    p_is_percentage boolean default false,
    p_percentage_value numeric default null,
    p_percentage_base text default null,
    p_description text default null,
    p_currency text default 'BRL',
    p_frequency text default 'monthly',
    p_occurrence_months integer[] default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_notes text default null
) returns setof api.payroll_deductions as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_deduction_uuid text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    insert into data.payroll_deductions (
        ledger_id, name, description, deduction_type,
        is_fixed_amount, fixed_amount, estimated_amount,
        is_percentage, percentage_value, percentage_base,
        currency, frequency, occurrence_months,
        start_date, end_date, default_category_id,
        employer_name, group_tag, notes
    ) values (
        v_ledger_id, p_name, p_description, p_deduction_type,
        p_is_fixed_amount, p_fixed_amount, p_estimated_amount,
        p_is_percentage, p_percentage_value, p_percentage_base,
        p_currency, p_frequency, p_occurrence_months,
        p_start_date, p_end_date, v_category_id,
        p_employer_name, p_group_tag, p_notes
    ) returning uuid into v_deduction_uuid;

    return query
    select * from api.payroll_deductions where uuid = v_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.update_payroll_deduction(
    p_deduction_uuid text,
    p_name text default null,
    p_description text default null,
    p_deduction_type text default null,
    p_is_fixed_amount boolean default null,
    p_fixed_amount bigint default null,
    p_estimated_amount bigint default null,
    p_is_percentage boolean default null,
    p_percentage_value numeric default null,
    p_percentage_base text default null,
    p_currency text default null,
    p_frequency text default null,
    p_occurrence_months integer[] default null,
    p_start_date date default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_is_active boolean default null,
    p_notes text default null
) returns setof api.payroll_deductions as $$
declare
    v_deduction_id bigint;
    v_category_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_deduction_id
    from data.payroll_deductions
    where uuid = p_deduction_uuid and user_data = v_user_data;

    if v_deduction_id is null then
        raise exception 'Payroll deduction not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    update data.payroll_deductions
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        deduction_type = coalesce(p_deduction_type, deduction_type),
        is_fixed_amount = coalesce(p_is_fixed_amount, is_fixed_amount),
        fixed_amount = coalesce(p_fixed_amount, fixed_amount),
        estimated_amount = coalesce(p_estimated_amount, estimated_amount),
        is_percentage = coalesce(p_is_percentage, is_percentage),
        percentage_value = coalesce(p_percentage_value, percentage_value),
        percentage_base = coalesce(p_percentage_base, percentage_base),
        currency = coalesce(p_currency, currency),
        frequency = coalesce(p_frequency, frequency),
        occurrence_months = coalesce(p_occurrence_months, occurrence_months),
        start_date = coalesce(p_start_date, start_date),
        end_date = coalesce(p_end_date, end_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        employer_name = coalesce(p_employer_name, employer_name),
        group_tag = coalesce(p_group_tag, group_tag),
        is_active = coalesce(p_is_active, is_active),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_deduction_id;

    return query
    select * from api.payroll_deductions where uuid = p_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.create_projected_event(
    p_ledger_uuid text,
    p_name text,
    p_amount bigint,
    p_event_date date,
    p_direction text default 'outflow',
    p_event_type text default 'other',
    p_description text default null,
    p_currency text default 'BRL',
    p_default_category_uuid text default null,
    p_is_confirmed boolean default false,
    p_notes text default null
) returns setof api.projected_events as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_event_uuid text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    insert into data.projected_events (
        ledger_id, name, description, event_type, direction,
        amount, currency, event_date, default_category_id,
        is_confirmed, notes
    ) values (
        v_ledger_id, p_name, p_description, p_event_type, p_direction,
        p_amount, p_currency, p_event_date, v_category_id,
        p_is_confirmed, p_notes
    ) returning uuid into v_event_uuid;

    return query
    select * from api.projected_events where uuid = v_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.update_projected_event(
    p_event_uuid text,
    p_name text default null,
    p_description text default null,
    p_event_type text default null,
    p_direction text default null,
    p_amount bigint default null,
    p_currency text default null,
    p_event_date date default null,
    p_default_category_uuid text default null,
    p_is_confirmed boolean default null,
    p_is_realized boolean default null,
    p_linked_transaction_uuid text default null,
    p_notes text default null
) returns setof api.projected_events as $$
declare
    v_event_id bigint;
    v_category_id bigint;
    v_transaction_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_event_id
    from data.projected_events
    where uuid = p_event_uuid and user_data = v_user_data;

    if v_event_id is null then
        raise exception 'Projected event not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    if p_linked_transaction_uuid is not null then
        select id into v_transaction_id
        from data.transactions
        where uuid = p_linked_transaction_uuid and user_data = v_user_data;
    end if;

    update data.projected_events
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        event_type = coalesce(p_event_type, event_type),
        direction = coalesce(p_direction, direction),
        amount = coalesce(p_amount, amount),
        currency = coalesce(p_currency, currency),
        event_date = coalesce(p_event_date, event_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        is_confirmed = coalesce(p_is_confirmed, is_confirmed),
        is_realized = coalesce(p_is_realized, is_realized),
        linked_transaction_id = coalesce(v_transaction_id, linked_transaction_id),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_event_id;

    return query
    select * from api.projected_events where uuid = p_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- ============================================================================
-- 4. RECREATE PROJECTION ENGINE (output in cents, convert other sources)
-- ============================================================================

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

    -- 2b. PAYROLL DEDUCTIONS: annual
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

    -- 5. INSTALLMENT PLANS (numeric(19,4) -> cents)
    select
        date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid,
        'expense'::text, 'installment'::text, ip.description,
        -(s.scheduled_amount * 100)::bigint
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

    -- 7. PROJECTED EVENTS (already in cents)
    select
        date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.event_date >= p_start_month and e.event_date <= v_end_month

    order by 1, 2, 7;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_projection_summary(
    p_ledger_uuid text,
    p_start_month date default date_trunc('month', current_date)::date,
    p_months_ahead integer default 120
) returns table (
    month date,
    total_income bigint,
    total_deductions bigint,
    total_obligations bigint,
    total_loan_amort bigint,
    total_loan_interest bigint,
    total_installments bigint,
    total_recurring bigint,
    total_events bigint,
    net_monthly_balance bigint,
    cumulative_balance bigint
) as $$
begin
    return query
    select
        p.month,
        coalesce(sum(case when p.source_type = 'income' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'deduction' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'obligation' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'loan_amort' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'loan_interest' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'installment' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'recurring' then p.amount end), 0)::bigint,
        coalesce(sum(case when p.source_type = 'event' then p.amount end), 0)::bigint,
        coalesce(sum(p.amount), 0)::bigint,
        (sum(coalesce(sum(p.amount), 0)) over (order by p.month))::bigint
    from api.generate_cash_flow_projection(p_ledger_uuid, p_start_month, p_months_ahead) p
    group by p.month
    order by p.month;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- ============================================================================
-- 4b. RECREATE GET/DELETE FUNCTIONS (dropped by cascade)
-- ============================================================================

-- +goose StatementBegin
create or replace function api.delete_income_source(p_income_uuid text) returns boolean as $$
declare v_income_id bigint; v_user_data text;
begin
    v_user_data := utils.get_user();
    select id into v_income_id from data.income_sources where uuid = p_income_uuid and user_data = v_user_data;
    if v_income_id is null then raise exception 'Income source not found'; end if;
    delete from data.income_sources where id = v_income_id;
    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_income_sources(p_ledger_uuid text) returns setof api.income_sources as $$
begin
    return query select * from api.income_sources where ledger_uuid = p_ledger_uuid order by employer_name nulls last, income_type, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_income_source(p_income_uuid text) returns setof api.income_sources as $$
begin
    return query select * from api.income_sources where uuid = p_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.delete_payroll_deduction(p_deduction_uuid text) returns boolean as $$
declare v_deduction_id bigint; v_user_data text;
begin
    v_user_data := utils.get_user();
    select id into v_deduction_id from data.payroll_deductions where uuid = p_deduction_uuid and user_data = v_user_data;
    if v_deduction_id is null then raise exception 'Payroll deduction not found'; end if;
    delete from data.payroll_deductions where id = v_deduction_id;
    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_payroll_deductions(p_ledger_uuid text) returns setof api.payroll_deductions as $$
begin
    return query select * from api.payroll_deductions where ledger_uuid = p_ledger_uuid order by employer_name nulls last, deduction_type, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_payroll_deduction(p_deduction_uuid text) returns setof api.payroll_deductions as $$
begin
    return query select * from api.payroll_deductions where uuid = p_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.delete_projected_event(p_event_uuid text) returns boolean as $$
declare v_event_id bigint; v_user_data text;
begin
    v_user_data := utils.get_user();
    select id into v_event_id from data.projected_events where uuid = p_event_uuid and user_data = v_user_data;
    if v_event_id is null then raise exception 'Projected event not found'; end if;
    delete from data.projected_events where id = v_event_id;
    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_projected_events(p_ledger_uuid text) returns setof api.projected_events as $$
begin
    return query select * from api.projected_events where ledger_uuid = p_ledger_uuid order by event_date, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
create or replace function api.get_projected_event(p_event_uuid text) returns setof api.projected_events as $$
begin
    return query select * from api.projected_events where uuid = p_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- ============================================================================
-- 5. GRANT PERMISSIONS
-- ============================================================================

grant select, insert, update, delete on data.income_sources to pgbudget_user;
grant select, insert, update, delete on data.payroll_deductions to pgbudget_user;
grant select, insert, update, delete on data.projected_events to pgbudget_user;
grant select on api.income_sources to pgbudget_user;
grant select on api.payroll_deductions to pgbudget_user;
grant select on api.projected_events to pgbudget_user;
grant execute on function api.create_income_source(text, text, bigint, date, text, text, text, text, text, integer, integer[], date, text, text, text, text) to pgbudget_user;
grant execute on function api.update_income_source(text, text, text, text, text, bigint, text, text, integer, integer[], date, date, text, text, text, boolean, text) to pgbudget_user;
grant execute on function api.delete_income_source(text) to pgbudget_user;
grant execute on function api.get_income_sources(text) to pgbudget_user;
grant execute on function api.get_income_source(text) to pgbudget_user;
grant execute on function api.create_payroll_deduction(text, text, text, date, boolean, bigint, bigint, boolean, numeric, text, text, text, text, integer[], date, text, text, text, text) to pgbudget_user;
grant execute on function api.update_payroll_deduction(text, text, text, text, boolean, bigint, bigint, boolean, numeric, text, text, text, integer[], date, date, text, text, text, boolean, text) to pgbudget_user;
grant execute on function api.delete_payroll_deduction(text) to pgbudget_user;
grant execute on function api.get_payroll_deductions(text) to pgbudget_user;
grant execute on function api.get_payroll_deduction(text) to pgbudget_user;
grant execute on function api.create_projected_event(text, text, bigint, date, text, text, text, text, text, boolean, text) to pgbudget_user;
grant execute on function api.update_projected_event(text, text, text, text, text, bigint, text, date, text, boolean, boolean, text, text) to pgbudget_user;
grant execute on function api.delete_projected_event(text) to pgbudget_user;
grant execute on function api.get_projected_events(text) to pgbudget_user;
grant execute on function api.get_projected_event(text) to pgbudget_user;
grant execute on function api.generate_cash_flow_projection(text, date, integer) to pgbudget_user;
grant execute on function api.get_projection_summary(text, date, integer) to pgbudget_user;

-- +goose Down
-- Revert amounts back to numeric(15,2)
alter table data.income_sources drop constraint income_sources_positive_amount;
alter table data.income_sources alter column amount type numeric(15,2) using (amount / 100.0)::numeric(15,2);
alter table data.income_sources add constraint income_sources_positive_amount check (amount > 0);

alter table data.payroll_deductions drop constraint payroll_deductions_amount_required;
alter table data.payroll_deductions alter column fixed_amount type numeric(15,2) using (fixed_amount / 100.0)::numeric(15,2);
alter table data.payroll_deductions alter column estimated_amount type numeric(15,2) using (estimated_amount / 100.0)::numeric(15,2);
alter table data.payroll_deductions add constraint payroll_deductions_amount_required check (
    is_fixed_amount = false or fixed_amount is not null
);

alter table data.projected_events drop constraint projected_events_positive_amount;
alter table data.projected_events alter column amount type numeric(15,2) using (amount / 100.0)::numeric(15,2);
alter table data.projected_events add constraint projected_events_positive_amount check (amount > 0);
