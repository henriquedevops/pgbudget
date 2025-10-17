-- +goose Up
-- +goose StatementBegin
-- Phase 5.4: Category Spending Trends
-- Migration to add database functions for category-level spending trend analysis

-- ============================================================================
-- UTILS LAYER: Core calculation functions
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Get spending trend for a specific category over time
-- Returns monthly spending data for a category with statistics
-- -----------------------------------------------------------------------------
create or replace function utils.get_category_spending_trend(
    p_category_id bigint,
    p_ledger_id bigint,
    p_months integer,
    p_user_data text
) returns table (
    month text,              -- YYYY-MM format
    month_name text,         -- e.g., "January 2025"
    actual_spending bigint,  -- Actual spending for the month
    budgeted_amount bigint,  -- Budgeted amount for the month
    difference bigint,       -- Actual - Budgeted (negative = under budget)
    percent_of_budget numeric -- (Actual / Budgeted) * 100
) as $func$
declare
    v_start_date date;
begin
    -- Calculate start date (N months ago from today)
    v_start_date := date_trunc('month', now())::date - (p_months || ' months')::interval;

    return query
    with date_series as (
        -- Generate series of months
        select
            to_char(dt, 'YYYY-MM') as month,
            to_char(dt, 'Month YYYY') as month_name,
            date_trunc('month', dt)::date as month_start,
            (date_trunc('month', dt) + interval '1 month' - interval '1 day')::date as month_end
        from generate_series(
            v_start_date,
            date_trunc('month', now())::date,
            interval '1 month'
        ) as dt
    ),
    monthly_spending as (
        select
            ds.month,
            ds.month_name,
            -- Calculate actual spending (debits from this category to non-equity accounts)
            coalesce(sum(case
                when t.debit_account_id = p_category_id
                    and credit_acct.type != 'equity'
                then t.amount
                else 0
            end), 0)::bigint as actual_spending
        from date_series ds
        left join data.transactions t on t.date >= ds.month_start
            and t.date <= ds.month_end
            and t.user_data = p_user_data
        left join data.accounts credit_acct on t.credit_account_id = credit_acct.id
        where (t.debit_account_id = p_category_id or t.id is null)
        group by ds.month, ds.month_name
    ),
    monthly_budgeted as (
        select
            ds.month,
            -- Calculate budgeted amount (credits to this category from Unassigned)
            coalesce(sum(case
                when t.credit_account_id = p_category_id
                    and debit_acct.name = 'Unassigned'
                    and debit_acct.type = 'equity'
                then t.amount
                else 0
            end), 0)::bigint as budgeted_amount
        from date_series ds
        left join data.transactions t on t.date >= ds.month_start
            and t.date <= ds.month_end
            and t.user_data = p_user_data
        left join data.accounts debit_acct on t.debit_account_id = debit_acct.id
        where (t.credit_account_id = p_category_id or t.id is null)
        group by ds.month
    )
    select
        ms.month,
        trim(ms.month_name) as month_name,
        ms.actual_spending,
        mb.budgeted_amount,
        (ms.actual_spending - mb.budgeted_amount)::bigint as difference,
        case
            when mb.budgeted_amount > 0 then
                round((ms.actual_spending::numeric / mb.budgeted_amount::numeric * 100), 2)
            else
                case when ms.actual_spending > 0 then 999.99::numeric else 0::numeric end
        end as percent_of_budget
    from monthly_spending ms
    join monthly_budgeted mb on ms.month = mb.month
    order by ms.month;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_category_spending_trend is
'Get spending trend for a specific category over time. Returns monthly spending vs budgeted amounts.';

-- -----------------------------------------------------------------------------
-- Get trend statistics for a category
-- Returns aggregate statistics and insights
-- -----------------------------------------------------------------------------
create or replace function utils.get_category_trend_statistics(
    p_category_id bigint,
    p_ledger_id bigint,
    p_months integer,
    p_user_data text
) returns table (
    average_spending bigint,
    median_spending bigint,
    min_spending bigint,
    max_spending bigint,
    min_month text,
    max_month text,
    total_spending bigint,
    average_budgeted bigint,
    months_over_budget integer,
    months_under_budget integer,
    trend_direction text  -- 'increasing', 'decreasing', 'stable'
) as $func$
begin
    return query
    with monthly_data as (
        select * from utils.get_category_spending_trend(
            p_category_id,
            p_ledger_id,
            p_months,
            p_user_data
        )
    ),
    stats as (
        select
            avg(md.actual_spending)::bigint as avg_spending,
            percentile_cont(0.5) within group (order by md.actual_spending)::bigint as median_spending,
            min(md.actual_spending) as min_spending,
            max(md.actual_spending) as max_spending,
            (select month_name from monthly_data where actual_spending = min(md.actual_spending) limit 1) as min_month_name,
            (select month_name from monthly_data where actual_spending = max(md.actual_spending) limit 1) as max_month_name,
            sum(md.actual_spending)::bigint as total_spending,
            avg(md.budgeted_amount)::bigint as avg_budgeted,
            count(*) filter (where md.actual_spending > md.budgeted_amount)::integer as over_budget_count,
            count(*) filter (where md.actual_spending < md.budgeted_amount)::integer as under_budget_count
        from monthly_data md
    ),
    trend_calc as (
        -- Simple trend calculation: compare first half to second half
        select
            avg(case when row_num <= total_rows / 2.0 then actual_spending else null end) as first_half_avg,
            avg(case when row_num > total_rows / 2.0 then actual_spending else null end) as second_half_avg
        from (
            select
                actual_spending,
                row_number() over (order by month) as row_num,
                count(*) over () as total_rows
            from monthly_data
        ) numbered
    )
    select
        s.avg_spending as average_spending,
        s.median_spending as median_spending,
        s.min_spending as min_spending,
        s.max_spending as max_spending,
        s.min_month_name as min_month,
        s.max_month_name as max_month,
        s.total_spending as total_spending,
        s.avg_budgeted as average_budgeted,
        s.over_budget_count as months_over_budget,
        s.under_budget_count as months_under_budget,
        case
            when tc.second_half_avg > tc.first_half_avg * 1.1 then 'increasing'::text
            when tc.second_half_avg < tc.first_half_avg * 0.9 then 'decreasing'::text
            else 'stable'::text
        end as trend_direction
    from stats s
    cross join trend_calc tc;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_category_trend_statistics is
'Get statistical summary for category spending trend. Returns averages, min/max, and trend direction.';

-- -----------------------------------------------------------------------------
-- Get multi-category comparison
-- Returns spending data for multiple categories for comparison
-- -----------------------------------------------------------------------------
create or replace function utils.get_multi_category_trends(
    p_category_ids bigint[],
    p_ledger_id bigint,
    p_months integer,
    p_user_data text
) returns table (
    category_id bigint,
    category_name text,
    month text,
    month_name text,
    actual_spending bigint,
    budgeted_amount bigint
) as $func$
begin
    return query
    select
        a.id as category_id,
        a.name as category_name,
        t.month,
        t.month_name,
        t.actual_spending,
        t.budgeted_amount
    from unnest(p_category_ids) as cat_id
    join data.accounts a on a.id = cat_id and a.user_data = p_user_data
    cross join lateral utils.get_category_spending_trend(
        cat_id,
        p_ledger_id,
        p_months,
        p_user_data
    ) t
    order by t.month, a.name;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_multi_category_trends is
'Get spending trends for multiple categories for comparison. Returns data for all categories over time.';

-- ============================================================================
-- API LAYER: Public-facing functions with UUID-based access
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get category spending trend
-- -----------------------------------------------------------------------------
create or replace function api.get_category_spending_trend(
    p_category_uuid text,
    p_months integer default 12
) returns table (
    month text,
    month_name text,
    actual_spending bigint,
    budgeted_amount bigint,
    difference bigint,
    percent_of_budget numeric
) as $func$
declare
    v_category_id bigint;
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get category ID and ledger ID, validate access
    select a.id, a.ledger_id into v_category_id, v_ledger_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data
      and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category not found or access denied';
    end if;

    -- Return trend data
    return query
    select
        t.month,
        t.month_name,
        t.actual_spending,
        t.budgeted_amount,
        t.difference,
        t.percent_of_budget
    from utils.get_category_spending_trend(
        v_category_id,
        v_ledger_id,
        p_months,
        v_user_data
    ) t;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_category_spending_trend is
'API: Get spending trend for a category over time. Returns monthly spending vs budgeted amounts.
Usage: SELECT * FROM api.get_category_spending_trend(''category_uuid'', 12)';

-- -----------------------------------------------------------------------------
-- API: Get category trend statistics
-- -----------------------------------------------------------------------------
create or replace function api.get_category_trend_statistics(
    p_category_uuid text,
    p_months integer default 12
) returns table (
    average_spending bigint,
    median_spending bigint,
    min_spending bigint,
    max_spending bigint,
    min_month text,
    max_month text,
    total_spending bigint,
    average_budgeted bigint,
    months_over_budget integer,
    months_under_budget integer,
    trend_direction text
) as $func$
declare
    v_category_id bigint;
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get category ID and ledger ID, validate access
    select a.id, a.ledger_id into v_category_id, v_ledger_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data
      and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category not found or access denied';
    end if;

    -- Return statistics
    return query
    select
        s.average_spending,
        s.median_spending,
        s.min_spending,
        s.max_spending,
        s.min_month,
        s.max_month,
        s.total_spending,
        s.average_budgeted,
        s.months_over_budget,
        s.months_under_budget,
        s.trend_direction
    from utils.get_category_trend_statistics(
        v_category_id,
        v_ledger_id,
        p_months,
        v_user_data
    ) s;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_category_trend_statistics is
'API: Get statistical summary for category spending trend.
Usage: SELECT * FROM api.get_category_trend_statistics(''category_uuid'', 12)';

-- -----------------------------------------------------------------------------
-- API: Get multi-category trends for comparison
-- -----------------------------------------------------------------------------
create or replace function api.get_multi_category_trends(
    p_ledger_uuid text,
    p_category_uuids text[],
    p_months integer default 12
) returns table (
    category_uuid text,
    category_name text,
    month text,
    month_name text,
    actual_spending bigint,
    budgeted_amount bigint
) as $func$
declare
    v_ledger_id bigint;
    v_category_ids bigint[];
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

    -- Convert UUIDs to IDs
    select array_agg(a.id)
    into v_category_ids
    from data.accounts a
    where a.uuid = any(p_category_uuids)
      and a.ledger_id = v_ledger_id
      and a.user_data = v_user_data
      and a.type = 'equity';

    if v_category_ids is null or array_length(v_category_ids, 1) = 0 then
        raise exception 'No valid categories found';
    end if;

    -- Return comparison data
    return query
    select
        a.uuid as category_uuid,
        t.category_name,
        t.month,
        t.month_name,
        t.actual_spending,
        t.budgeted_amount
    from utils.get_multi_category_trends(
        v_category_ids,
        v_ledger_id,
        p_months,
        v_user_data
    ) t
    join data.accounts a on a.id = t.category_id;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_multi_category_trends is
'API: Get spending trends for multiple categories for comparison.
Usage: SELECT * FROM api.get_multi_category_trends(''ledger_uuid'', ARRAY[''cat1_uuid'', ''cat2_uuid''], 12)';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Drop API functions
drop function if exists api.get_multi_category_trends(text, text[], integer);
drop function if exists api.get_category_trend_statistics(text, integer);
drop function if exists api.get_category_spending_trend(text, integer);

-- Drop utils functions
drop function if exists utils.get_multi_category_trends(bigint[], bigint, integer, text);
drop function if exists utils.get_category_trend_statistics(bigint, bigint, integer, text);
drop function if exists utils.get_category_spending_trend(bigint, bigint, integer, text);

-- +goose StatementEnd
