-- +goose Up
-- +goose StatementBegin
-- Phase 5.3: Net Worth Report
-- Migration to add database functions for net worth tracking and analytics

-- ============================================================================
-- UTILS LAYER: Core calculation functions
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Get net worth over time (end of month snapshots)
-- Returns net worth (Assets - Liabilities) at the end of each month
-- -----------------------------------------------------------------------------
create or replace function utils.get_net_worth_over_time(
    p_ledger_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    month text,              -- YYYY-MM format
    month_name text,         -- e.g., "January 2025"
    end_date date,           -- Last day of the month
    total_assets bigint,     -- Sum of all asset account balances
    total_liabilities bigint,-- Sum of all liability account balances (as positive number)
    net_worth bigint,        -- Assets - Liabilities
    change_from_previous bigint, -- Change from previous month
    percent_change numeric   -- Percentage change from previous month
) as $func$
begin
    return query
    with date_series as (
        -- Generate series of month-end dates
        select
            date_trunc('month', dt)::date + interval '1 month' - interval '1 day' as month_end_date,
            to_char(dt, 'YYYY-MM') as month,
            to_char(dt, 'Month YYYY') as month_name
        from generate_series(
            date_trunc('month', p_start_date::date),
            date_trunc('month', p_end_date::date),
            interval '1 month'
        ) as dt
    ),
    monthly_balances as (
        select
            ds.month_end_date,
            ds.month,
            ds.month_name,
            -- Calculate total assets (sum of all asset account balances)
            coalesce(sum(case
                when a.type = 'asset' then (
                    -- Debits to asset accounts increase balance
                    coalesce((select sum(t.amount) from data.transactions t
                     where t.debit_account_id = a.id
                       and t.date <= ds.month_end_date
                       and t.user_data = p_user_data), 0)
                    -
                    -- Credits to asset accounts decrease balance
                    coalesce((select sum(t.amount) from data.transactions t
                     where t.credit_account_id = a.id
                       and t.date <= ds.month_end_date
                       and t.user_data = p_user_data), 0)
                )
                else 0
            end), 0)::bigint as total_assets,
            -- Calculate total liabilities (sum of all liability account balances)
            -- Liabilities have credit balance, so we reverse the calculation
            coalesce(sum(case
                when a.type = 'liability' then (
                    -- Credits to liability accounts increase balance
                    coalesce((select sum(t.amount) from data.transactions t
                     where t.credit_account_id = a.id
                       and t.date <= ds.month_end_date
                       and t.user_data = p_user_data), 0)
                    -
                    -- Debits to liability accounts decrease balance
                    coalesce((select sum(t.amount) from data.transactions t
                     where t.debit_account_id = a.id
                       and t.date <= ds.month_end_date
                       and t.user_data = p_user_data), 0)
                )
                else 0
            end), 0)::bigint as total_liabilities
        from date_series ds
        cross join data.accounts a
        where a.ledger_id = p_ledger_id
          and a.type in ('asset', 'liability')
          and a.user_data = p_user_data
        group by ds.month_end_date, ds.month, ds.month_name
    ),
    net_worth_data as (
        select
            mb.month,
            mb.month_name,
            mb.month_end_date as end_date,
            mb.total_assets,
            mb.total_liabilities,
            (mb.total_assets - mb.total_liabilities)::bigint as net_worth,
            lag((mb.total_assets - mb.total_liabilities)::bigint) over (order by mb.month_end_date) as previous_net_worth
        from monthly_balances mb
    )
    select
        nw.month,
        trim(nw.month_name) as month_name,
        nw.end_date,
        nw.total_assets,
        nw.total_liabilities,
        nw.net_worth,
        case
            when nw.previous_net_worth is not null then (nw.net_worth - nw.previous_net_worth)::bigint
            else 0::bigint
        end as change_from_previous,
        case
            when nw.previous_net_worth is not null and nw.previous_net_worth != 0 then
                round(((nw.net_worth - nw.previous_net_worth)::numeric / abs(nw.previous_net_worth)::numeric * 100), 2)
            else 0::numeric
        end as percent_change
    from net_worth_data nw
    order by nw.end_date;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_net_worth_over_time is
'Get net worth (assets - liabilities) at the end of each month for a date range. Returns monthly snapshots with change calculations.';

-- -----------------------------------------------------------------------------
-- Get net worth summary statistics
-- Returns aggregate statistics for net worth over the date range
-- -----------------------------------------------------------------------------
create or replace function utils.get_net_worth_summary(
    p_ledger_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    current_net_worth bigint,
    starting_net_worth bigint,
    total_change bigint,
    percent_change numeric,
    current_assets bigint,
    current_liabilities bigint,
    average_monthly_change bigint,
    highest_net_worth bigint,
    highest_net_worth_date date,
    lowest_net_worth bigint,
    lowest_net_worth_date date,
    months_count integer
) as $func$
begin
    return query
    with monthly_data as (
        select * from utils.get_net_worth_over_time(
            p_ledger_id,
            p_start_date,
            p_end_date,
            p_user_data
        )
    ),
    summary as (
        select
            count(*)::integer as month_count,
            (select net_worth from monthly_data order by end_date desc limit 1) as latest_net_worth,
            (select net_worth from monthly_data order by end_date asc limit 1) as earliest_net_worth,
            (select total_assets from monthly_data order by end_date desc limit 1) as latest_assets,
            (select total_liabilities from monthly_data order by end_date desc limit 1) as latest_liabilities,
            max(md.net_worth) as max_net_worth,
            (select end_date from monthly_data where net_worth = max(md.net_worth) limit 1) as max_date,
            min(md.net_worth) as min_net_worth,
            (select end_date from monthly_data where net_worth = min(md.net_worth) limit 1) as min_date,
            avg(md.change_from_previous) filter (where md.change_from_previous != 0) as avg_change
        from monthly_data md
    )
    select
        s.latest_net_worth as current_net_worth,
        s.earliest_net_worth as starting_net_worth,
        (s.latest_net_worth - s.earliest_net_worth)::bigint as total_change,
        case
            when s.earliest_net_worth != 0 then
                round(((s.latest_net_worth - s.earliest_net_worth)::numeric / abs(s.earliest_net_worth)::numeric * 100), 2)
            else 0::numeric
        end as percent_change,
        s.latest_assets as current_assets,
        s.latest_liabilities as current_liabilities,
        coalesce(s.avg_change, 0)::bigint as average_monthly_change,
        s.max_net_worth as highest_net_worth,
        s.max_date as highest_net_worth_date,
        s.min_net_worth as lowest_net_worth,
        s.min_date as lowest_net_worth_date,
        s.month_count as months_count
    from summary s;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_net_worth_summary is
'Get summary statistics for net worth over a date range. Returns current net worth, changes, and min/max values.';

-- ============================================================================
-- API LAYER: Public-facing functions with UUID-based access
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get net worth over time
-- -----------------------------------------------------------------------------
create or replace function api.get_net_worth_over_time(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '12 months',
    p_end_date timestamptz default now()
) returns table (
    month text,
    month_name text,
    end_date date,
    total_assets bigint,
    total_liabilities bigint,
    net_worth bigint,
    change_from_previous bigint,
    percent_change numeric
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

    -- Return net worth data
    return query
    select
        nw.month,
        nw.month_name,
        nw.end_date,
        nw.total_assets,
        nw.total_liabilities,
        nw.net_worth,
        nw.change_from_previous,
        nw.percent_change
    from utils.get_net_worth_over_time(
        v_ledger_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) nw;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_net_worth_over_time is
'API: Get net worth (assets - liabilities) over time with monthly snapshots. Returns data suitable for line charts and trend analysis.
Usage: SELECT * FROM api.get_net_worth_over_time(''ledger_uuid'', ''2024-01-01'', ''2024-12-31'')';

-- -----------------------------------------------------------------------------
-- API: Get net worth summary
-- -----------------------------------------------------------------------------
create or replace function api.get_net_worth_summary(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '12 months',
    p_end_date timestamptz default now()
) returns table (
    current_net_worth bigint,
    starting_net_worth bigint,
    total_change bigint,
    percent_change numeric,
    current_assets bigint,
    current_liabilities bigint,
    average_monthly_change bigint,
    highest_net_worth bigint,
    highest_net_worth_date date,
    lowest_net_worth bigint,
    lowest_net_worth_date date,
    months_count integer
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

    -- Return summary data
    return query
    select
        s.current_net_worth,
        s.starting_net_worth,
        s.total_change,
        s.percent_change,
        s.current_assets,
        s.current_liabilities,
        s.average_monthly_change,
        s.highest_net_worth,
        s.highest_net_worth_date,
        s.lowest_net_worth,
        s.lowest_net_worth_date,
        s.months_count
    from utils.get_net_worth_summary(
        v_ledger_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) s;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_net_worth_summary is
'API: Get summary statistics for net worth over a date range. Returns current net worth, changes, and min/max values.
Usage: SELECT * FROM api.get_net_worth_summary(''ledger_uuid'', ''2024-01-01'', ''2024-12-31'')';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Drop API functions
drop function if exists api.get_net_worth_summary(text, timestamptz, timestamptz);
drop function if exists api.get_net_worth_over_time(text, timestamptz, timestamptz);

-- Drop utils functions
drop function if exists utils.get_net_worth_summary(bigint, timestamptz, timestamptz, text);
drop function if exists utils.get_net_worth_over_time(bigint, timestamptz, timestamptz, text);

-- +goose StatementEnd
