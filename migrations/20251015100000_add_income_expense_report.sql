-- +goose Up
-- +goose StatementBegin
-- Phase 5.2: Income vs Expense Report
-- Migration to add database functions for income vs expense tracking and analytics

-- ============================================================================
-- UTILS LAYER: Core calculation functions
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Get income vs expense by month for a date range
-- Returns monthly breakdown of income, expenses, and net (surplus/deficit)
-- -----------------------------------------------------------------------------
create or replace function utils.get_income_vs_expense_by_month(
    p_ledger_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    month text,              -- YYYY-MM format
    month_name text,         -- e.g., "January 2025"
    total_income bigint,     -- Total inflows to Income category
    total_expense bigint,    -- Total outflows from budget categories
    net bigint,              -- Income - Expense (positive = surplus, negative = deficit)
    savings_rate numeric     -- (Net / Income) * 100, percentage
) as $func$
begin
    return query
    with monthly_data as (
        select
            to_char(t.date, 'YYYY-MM') as month,
            to_char(t.date, 'Month YYYY') as month_name,
            -- Income: credits to Income category (positive)
            coalesce(sum(case
                when credit_acct.name = 'Income' and credit_acct.type = 'equity'
                then t.amount
                else 0
            end), 0)::bigint as total_income,
            -- Expenses: debits from budget categories (excluding Income, Unassigned, Off-budget)
            coalesce(sum(case
                when debit_acct.type = 'equity'
                    and debit_acct.name not in ('Income', 'Unassigned', 'Off-budget')
                    and credit_acct.type != 'equity'
                then t.amount
                else 0
            end), 0)::bigint as total_expense
        from data.transactions t
        join data.accounts debit_acct on t.debit_account_id = debit_acct.id
        join data.accounts credit_acct on t.credit_account_id = credit_acct.id
        join data.ledgers l on debit_acct.ledger_id = l.id
        where l.id = p_ledger_id
          and t.date >= p_start_date::date
          and t.date <= p_end_date::date
          and t.user_data = p_user_data
        group by to_char(t.date, 'YYYY-MM'), to_char(t.date, 'Month YYYY')
    )
    select
        md.month,
        trim(md.month_name) as month_name,
        md.total_income,
        md.total_expense,
        (md.total_income - md.total_expense)::bigint as net,
        case
            when md.total_income > 0 then
                round(((md.total_income - md.total_expense)::numeric / md.total_income::numeric * 100), 2)
            else 0::numeric
        end as savings_rate
    from monthly_data md
    order by md.month;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_income_vs_expense_by_month is
'Get monthly income vs expense breakdown for a ledger and date range. Returns income, expenses, net, and savings rate per month.';

-- -----------------------------------------------------------------------------
-- Get income vs expense summary statistics
-- Returns aggregate statistics for the entire date range
-- -----------------------------------------------------------------------------
create or replace function utils.get_income_expense_summary(
    p_ledger_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    total_income bigint,
    total_expense bigint,
    net_total bigint,
    average_monthly_income bigint,
    average_monthly_expense bigint,
    average_monthly_net bigint,
    overall_savings_rate numeric,
    months_count integer,
    surplus_months integer,
    deficit_months integer
) as $func$
begin
    return query
    with monthly_data as (
        select * from utils.get_income_vs_expense_by_month(
            p_ledger_id,
            p_start_date,
            p_end_date,
            p_user_data
        )
    ),
    summary as (
        select
            coalesce(sum(md.total_income), 0)::bigint as sum_income,
            coalesce(sum(md.total_expense), 0)::bigint as sum_expense,
            coalesce(sum(md.net), 0)::bigint as sum_net,
            count(*)::integer as month_count,
            count(*) filter (where md.net > 0)::integer as surplus_count,
            count(*) filter (where md.net < 0)::integer as deficit_count
        from monthly_data md
    )
    select
        s.sum_income as total_income,
        s.sum_expense as total_expense,
        s.sum_net as net_total,
        case when s.month_count > 0 then (s.sum_income / s.month_count)::bigint else 0::bigint end as average_monthly_income,
        case when s.month_count > 0 then (s.sum_expense / s.month_count)::bigint else 0::bigint end as average_monthly_expense,
        case when s.month_count > 0 then (s.sum_net / s.month_count)::bigint else 0::bigint end as average_monthly_net,
        case
            when s.sum_income > 0 then
                round((s.sum_net::numeric / s.sum_income::numeric * 100), 2)
            else 0::numeric
        end as overall_savings_rate,
        s.month_count as months_count,
        s.surplus_count as surplus_months,
        s.deficit_count as deficit_months
    from summary s;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_income_expense_summary is
'Get summary statistics for income vs expense over a date range. Returns totals, averages, and savings rate.';

-- ============================================================================
-- API LAYER: Public-facing functions with UUID-based access
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get income vs expense by month
-- -----------------------------------------------------------------------------
create or replace function api.get_income_vs_expense_by_month(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '12 months',
    p_end_date timestamptz default now()
) returns table (
    month text,
    month_name text,
    total_income bigint,
    total_expense bigint,
    net bigint,
    savings_rate numeric
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

    -- Return monthly data
    return query
    select
        m.month,
        m.month_name,
        m.total_income,
        m.total_expense,
        m.net,
        m.savings_rate
    from utils.get_income_vs_expense_by_month(
        v_ledger_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) m;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_income_vs_expense_by_month is
'API: Get monthly income vs expense breakdown for a ledger. Returns data suitable for bar charts and trend analysis.
Usage: SELECT * FROM api.get_income_vs_expense_by_month(''ledger_uuid'', ''2024-01-01'', ''2024-12-31'')';

-- -----------------------------------------------------------------------------
-- API: Get income vs expense summary
-- -----------------------------------------------------------------------------
create or replace function api.get_income_expense_summary(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '12 months',
    p_end_date timestamptz default now()
) returns table (
    total_income bigint,
    total_expense bigint,
    net_total bigint,
    average_monthly_income bigint,
    average_monthly_expense bigint,
    average_monthly_net bigint,
    overall_savings_rate numeric,
    months_count integer,
    surplus_months integer,
    deficit_months integer
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
        s.total_income,
        s.total_expense,
        s.net_total,
        s.average_monthly_income,
        s.average_monthly_expense,
        s.average_monthly_net,
        s.overall_savings_rate,
        s.months_count,
        s.surplus_months,
        s.deficit_months
    from utils.get_income_expense_summary(
        v_ledger_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) s;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_income_expense_summary is
'API: Get summary statistics for income vs expense over a date range.
Usage: SELECT * FROM api.get_income_expense_summary(''ledger_uuid'', ''2024-01-01'', ''2024-12-31'')';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Drop API functions
drop function if exists api.get_income_expense_summary(text, timestamptz, timestamptz);
drop function if exists api.get_income_vs_expense_by_month(text, timestamptz, timestamptz);

-- Drop utils functions
drop function if exists utils.get_income_expense_summary(bigint, timestamptz, timestamptz, text);
drop function if exists utils.get_income_vs_expense_by_month(bigint, timestamptz, timestamptz, text);

-- +goose StatementEnd
