-- +goose Up
-- +goose StatementBegin
-- Phase 5.5: Age of Money (AOM)
-- Migration to add database functions for Age of Money calculation and tracking

-- ============================================================================
-- DATA LAYER: Cache table for Age of Money calculations
-- ============================================================================

-- Cache table to store daily AOM calculations for performance
create table if not exists data.age_of_money_cache (
    ledger_id bigint not null references data.ledgers(id) on delete cascade,
    calculation_date date not null,
    age_days integer not null,
    transaction_count integer not null default 0,
    created_at timestamptz not null default now(),
    primary key (ledger_id, calculation_date)
);

comment on table data.age_of_money_cache is
'Cache table for Age of Money calculations. Stores daily AOM values to avoid expensive recalculations.';

create index if not exists idx_aom_cache_ledger_date
on data.age_of_money_cache(ledger_id, calculation_date desc);

-- ============================================================================
-- UTILS LAYER: Core calculation functions
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Calculate Age of Money for a specific date
-- Returns the average number of days between receiving money and spending it
-- -----------------------------------------------------------------------------
create or replace function utils.calculate_age_of_money(
    p_ledger_id bigint,
    p_as_of_date date,
    p_user_data text
) returns table (
    age_days integer,
    transaction_count integer,
    oldest_money_days integer,
    newest_money_days integer
) as $func$
declare
    v_lookback_days integer := 365; -- Look back 1 year for transactions
begin
    return query
    with inflows as (
        -- Get all income transactions (credits to Income equity account)
        select
            t.id,
            t.date as inflow_date,
            t.amount
        from data.transactions t
        join data.accounts debit_acct on t.debit_account_id = debit_acct.id
        join data.accounts credit_acct on t.credit_account_id = credit_acct.id
        where t.ledger_id = p_ledger_id
          and t.user_data = p_user_data
          and t.date <= p_as_of_date
          and t.date >= p_as_of_date - v_lookback_days
          and t.deleted_at is null
          -- Inflows are transactions where Income account is debited
          and debit_acct.name = 'Income'
          and debit_acct.type = 'equity'
    ),
    outflows as (
        -- Get all spending transactions (debits from equity categories to non-equity accounts)
        select
            t.id,
            t.date as outflow_date,
            t.amount,
            debit_acct.name as category_name
        from data.transactions t
        join data.accounts debit_acct on t.debit_account_id = debit_acct.id
        join data.accounts credit_acct on t.credit_account_id = credit_acct.id
        where t.ledger_id = p_ledger_id
          and t.user_data = p_user_data
          and t.date <= p_as_of_date
          and t.date >= p_as_of_date - v_lookback_days
          and t.deleted_at is null
          -- Outflows are from equity categories (not Income/Unassigned) to non-equity accounts
          and debit_acct.type = 'equity'
          and debit_acct.name not in ('Income', 'Unassigned', 'Off-budget')
          and credit_acct.type != 'equity'
    ),
    matched_outflows as (
        -- For each outflow, find the most recent inflow before it
        select
            o.outflow_date,
            o.amount,
            -- Find the most recent inflow date before this outflow
            (
                select i.inflow_date
                from inflows i
                where i.inflow_date <= o.outflow_date
                order by i.inflow_date desc
                limit 1
            ) as matched_inflow_date
        from outflows o
    ),
    age_calculations as (
        select
            outflow_date,
            matched_inflow_date,
            case
                when matched_inflow_date is not null then
                    (outflow_date - matched_inflow_date)::integer
                else null
            end as days_old
        from matched_outflows
        where matched_inflow_date is not null
    )
    select
        coalesce(round(avg(days_old))::integer, 0) as age_days,
        count(*)::integer as transaction_count,
        coalesce(max(days_old)::integer, 0) as oldest_money_days,
        coalesce(min(days_old)::integer, 0) as newest_money_days
    from age_calculations
    where days_old is not null;
end;
$func$ language plpgsql security definer stable;

comment on function utils.calculate_age_of_money is
'Calculate Age of Money for a specific date. Returns average days between receiving and spending money.';

-- -----------------------------------------------------------------------------
-- Get Age of Money over time (daily values for a date range)
-- -----------------------------------------------------------------------------
create or replace function utils.get_age_of_money_over_time(
    p_ledger_id bigint,
    p_start_date date,
    p_end_date date,
    p_user_data text
) returns table (
    calculation_date date,
    age_days integer,
    transaction_count integer,
    is_cached boolean
) as $func$
begin
    return query
    with date_series as (
        select dt::date as calc_date
        from generate_series(p_start_date, p_end_date, interval '1 day') dt
    ),
    cached_values as (
        select
            c.calculation_date,
            c.age_days,
            c.transaction_count,
            true as is_cached
        from data.age_of_money_cache c
        where c.ledger_id = p_ledger_id
          and c.calculation_date >= p_start_date
          and c.calculation_date <= p_end_date
    ),
    dates_needing_calc as (
        select ds.calc_date
        from date_series ds
        left join cached_values cv on ds.calc_date = cv.calculation_date
        where cv.calculation_date is null
    )
    -- Return cached values
    select
        cv.calculation_date,
        cv.age_days,
        cv.transaction_count,
        cv.is_cached
    from cached_values cv

    union all

    -- Calculate and return uncached values
    select
        dnc.calc_date as calculation_date,
        calc.age_days,
        calc.transaction_count,
        false as is_cached
    from dates_needing_calc dnc
    cross join lateral utils.calculate_age_of_money(
        p_ledger_id,
        dnc.calc_date,
        p_user_data
    ) calc

    order by calculation_date;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_age_of_money_over_time is
'Get Age of Money calculations over a date range. Uses cache when available, calculates on-demand otherwise.';

-- -----------------------------------------------------------------------------
-- Update Age of Money cache for a ledger
-- This can be run periodically to pre-calculate AOM values
-- -----------------------------------------------------------------------------
create or replace function utils.update_age_of_money_cache(
    p_ledger_id bigint,
    p_start_date date,
    p_end_date date,
    p_user_data text
) returns integer as $func$
declare
    v_rows_inserted integer := 0;
    v_calc_date date;
    v_aom_result record;
begin
    for v_calc_date in
        select dt::date
        from generate_series(p_start_date, p_end_date, interval '1 day') dt
    loop
        -- Calculate AOM for this date
        select * into v_aom_result
        from utils.calculate_age_of_money(p_ledger_id, v_calc_date, p_user_data);

        -- Insert or update cache
        insert into data.age_of_money_cache (
            ledger_id,
            calculation_date,
            age_days,
            transaction_count
        ) values (
            p_ledger_id,
            v_calc_date,
            v_aom_result.age_days,
            v_aom_result.transaction_count
        )
        on conflict (ledger_id, calculation_date)
        do update set
            age_days = excluded.age_days,
            transaction_count = excluded.transaction_count,
            created_at = now();

        v_rows_inserted := v_rows_inserted + 1;
    end loop;

    return v_rows_inserted;
end;
$func$ language plpgsql security definer;

comment on function utils.update_age_of_money_cache is
'Update Age of Money cache for a date range. Can be run periodically to pre-calculate values.';

-- -----------------------------------------------------------------------------
-- Get current Age of Money (most recent calculation)
-- -----------------------------------------------------------------------------
create or replace function utils.get_current_age_of_money(
    p_ledger_id bigint,
    p_user_data text
) returns table (
    age_days integer,
    calculation_date date,
    transaction_count integer,
    status text,
    status_message text
) as $func$
declare
    v_today date := current_date;
begin
    return query
    with current_aom as (
        select * from utils.calculate_age_of_money(
            p_ledger_id,
            v_today,
            p_user_data
        )
    )
    select
        aom.age_days,
        v_today as calculation_date,
        aom.transaction_count,
        case
            when aom.age_days >= 30 then 'excellent'::text
            when aom.age_days >= 20 then 'good'::text
            when aom.age_days >= 10 then 'fair'::text
            else 'needs_improvement'::text
        end as status,
        case
            when aom.age_days >= 30 then 'Great! You''re living on last month''s income.'::text
            when aom.age_days >= 20 then 'Good progress! Keep building your buffer.'::text
            when aom.age_days >= 10 then 'You have some buffer, but there''s room to improve.'::text
            else 'Focus on building a financial buffer by spending older money.'::text
        end as status_message
    from current_aom aom;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_current_age_of_money is
'Get current Age of Money with status assessment. Returns today''s AOM value and interpretation.';

-- ============================================================================
-- API LAYER: Public-facing functions with UUID-based access
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get current Age of Money
-- -----------------------------------------------------------------------------
create or replace function api.get_current_age_of_money(
    p_ledger_uuid text
) returns table (
    age_days integer,
    calculation_date date,
    transaction_count integer,
    status text,
    status_message text
) as $func$
declare
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get ledger ID and validate access
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid
      and l.user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found or access denied';
    end if;

    -- Return current AOM
    return query
    select
        aom.age_days,
        aom.calculation_date,
        aom.transaction_count,
        aom.status,
        aom.status_message
    from utils.get_current_age_of_money(v_ledger_id, v_user_data) aom;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_current_age_of_money is
'API: Get current Age of Money for a ledger.
Usage: SELECT * FROM api.get_current_age_of_money(''ledger_uuid'')';

-- -----------------------------------------------------------------------------
-- API: Get Age of Money over time
-- -----------------------------------------------------------------------------
create or replace function api.get_age_of_money_over_time(
    p_ledger_uuid text,
    p_days integer default 90
) returns table (
    calculation_date date,
    age_days integer,
    transaction_count integer
) as $func$
declare
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
    v_start_date date;
    v_end_date date;
begin
    -- Get ledger ID and validate access
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid
      and l.user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found or access denied';
    end if;

    -- Calculate date range
    v_end_date := current_date;
    v_start_date := v_end_date - (p_days || ' days')::interval;

    -- Return AOM over time
    return query
    select
        aom.calculation_date,
        aom.age_days,
        aom.transaction_count
    from utils.get_age_of_money_over_time(
        v_ledger_id,
        v_start_date,
        v_end_date,
        v_user_data
    ) aom
    order by aom.calculation_date;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_age_of_money_over_time is
'API: Get Age of Money trend over time. Returns daily AOM values for the specified number of days.
Usage: SELECT * FROM api.get_age_of_money_over_time(''ledger_uuid'', 90)';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop API functions
drop function if exists api.get_age_of_money_over_time(text, integer);
drop function if exists api.get_current_age_of_money(text);

-- Drop utils functions
drop function if exists utils.get_current_age_of_money(bigint, text);
drop function if exists utils.update_age_of_money_cache(bigint, date, date, text);
drop function if exists utils.get_age_of_money_over_time(bigint, date, date, text);
drop function if exists utils.calculate_age_of_money(bigint, date, text);

-- Drop cache table
drop table if exists data.age_of_money_cache;

-- +goose StatementEnd
