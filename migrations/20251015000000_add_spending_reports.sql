-- +goose Up
-- +goose StatementBegin
-- Phase 5.1: Spending by Category Report
-- Migration to add database functions for spending reports and analytics

-- ============================================================================
-- UTILS LAYER: Core calculation functions
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Get spending by category for a date range
-- Returns spending breakdown per category (only outflows from budget categories)
-- -----------------------------------------------------------------------------
create or replace function utils.get_spending_by_category(
    p_ledger_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    category_id bigint,
    category_uuid text,
    category_name text,
    total_spent bigint,  -- Total outflows (as positive number)
    transaction_count bigint,
    percentage numeric(5,2)  -- Percentage of total spending
) as $func$
declare
    v_total_spending bigint;
begin
    -- First, calculate total spending across all categories
    -- Only count outflows from equity (budget category) accounts
    select coalesce(sum(t.amount), 0)
    into v_total_spending
    from data.transactions t
    join data.accounts debit_acct on t.debit_account_id = debit_acct.id
    join data.accounts credit_acct on t.credit_account_id = credit_acct.id
    join data.ledgers l on debit_acct.ledger_id = l.id
    where l.id = p_ledger_id
      and t.date >= p_start_date
      and t.date <= p_end_date
      and debit_acct.type = 'equity'  -- Spending from budget category
      and debit_acct.name not in ('Income', 'Unassigned', 'Off-budget')  -- Exclude special categories
      and credit_acct.type != 'equity'  -- To asset or liability account
      and t.user_data = p_user_data;

    -- Return spending breakdown by category
    return query
    select
        debit_acct.id as category_id,
        debit_acct.uuid as category_uuid,
        debit_acct.name as category_name,
        coalesce(sum(t.amount), 0)::bigint as total_spent,
        count(t.id)::bigint as transaction_count,
        case
            when v_total_spending > 0 then
                round((coalesce(sum(t.amount), 0)::numeric / v_total_spending::numeric * 100), 2)
            else 0::numeric
        end as percentage
    from data.accounts debit_acct
    join data.ledgers l on debit_acct.ledger_id = l.id
    left join data.transactions t on t.debit_account_id = debit_acct.id
        and t.date >= p_start_date
        and t.date <= p_end_date
        and t.user_data = p_user_data
    left join data.accounts credit_acct on t.credit_account_id = credit_acct.id
    where l.id = p_ledger_id
      and debit_acct.type = 'equity'
      and debit_acct.name not in ('Income', 'Unassigned', 'Off-budget')
      and (credit_acct.type != 'equity' or credit_acct.id is null)  -- Only real spending
      and debit_acct.user_data = p_user_data
    group by debit_acct.id, debit_acct.uuid, debit_acct.name
    having coalesce(sum(t.amount), 0) > 0  -- Only categories with spending
    order by total_spent desc;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_spending_by_category is
'Get spending breakdown by category for a ledger and date range. Returns categories with their total spending, transaction count, and percentage of total.';

-- -----------------------------------------------------------------------------
-- Get transactions for a specific category in a date range
-- Used for drill-down from spending report
-- -----------------------------------------------------------------------------
create or replace function utils.get_category_transactions(
    p_category_id bigint,
    p_start_date timestamptz,
    p_end_date timestamptz,
    p_user_data text
) returns table (
    transaction_uuid text,
    transaction_date date,
    description text,
    amount bigint,
    other_account_name text,
    other_account_type text
) as $func$
begin
    return query
    select
        t.uuid as transaction_uuid,
        t.date as transaction_date,
        t.description,
        t.amount,
        credit_acct.name as other_account_name,
        credit_acct.type as other_account_type
    from data.transactions t
    join data.accounts credit_acct on t.credit_account_id = credit_acct.id
    where t.debit_account_id = p_category_id
      and t.date >= p_start_date
      and t.date <= p_end_date
      and t.user_data = p_user_data
      and credit_acct.type != 'equity'  -- Only real spending transactions
    order by t.date desc, t.created_at desc;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_category_transactions is
'Get all spending transactions for a specific category within a date range. Used for drill-down from reports.';

-- ============================================================================
-- API LAYER: Public-facing functions with UUID-based access
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get spending by category
-- -----------------------------------------------------------------------------
create or replace function api.get_spending_by_category(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '30 days',
    p_end_date timestamptz default now()
) returns table (
    category_uuid text,
    category_name text,
    total_spent bigint,
    transaction_count bigint,
    percentage numeric(5,2)
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

    -- Return spending data
    return query
    select
        s.category_uuid,
        s.category_name,
        s.total_spent,
        s.transaction_count,
        s.percentage
    from utils.get_spending_by_category(
        v_ledger_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) s;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_spending_by_category is
'API: Get spending breakdown by category for a ledger and date range. Returns spending data suitable for charts and reports.
Usage: SELECT * FROM api.get_spending_by_category(''ledger_uuid'', ''2025-01-01'', ''2025-01-31'')';

-- -----------------------------------------------------------------------------
-- API: Get category transactions for drill-down
-- -----------------------------------------------------------------------------
create or replace function api.get_category_transactions(
    p_category_uuid text,
    p_start_date timestamptz default now() - interval '30 days',
    p_end_date timestamptz default now()
) returns table (
    transaction_uuid text,
    transaction_date date,
    description text,
    amount bigint,
    other_account_name text,
    other_account_type text
) as $func$
declare
    v_category_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get category ID and validate access
    select a.id into v_category_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data;

    if v_category_id is null then
        raise exception 'Category not found or access denied';
    end if;

    -- Return transactions
    return query
    select
        ct.transaction_uuid,
        ct.transaction_date,
        ct.description,
        ct.amount,
        ct.other_account_name,
        ct.other_account_type
    from utils.get_category_transactions(
        v_category_id,
        p_start_date,
        p_end_date,
        v_user_data
    ) ct;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_category_transactions is
'API: Get all transactions for a specific category within a date range. Used for drill-down from spending reports.
Usage: SELECT * FROM api.get_category_transactions(''category_uuid'', ''2025-01-01'', ''2025-01-31'')';

-- -----------------------------------------------------------------------------
-- API: Get spending summary stats
-- Returns overall spending statistics for a date range
-- -----------------------------------------------------------------------------
create or replace function api.get_spending_summary(
    p_ledger_uuid text,
    p_start_date timestamptz default now() - interval '30 days',
    p_end_date timestamptz default now()
) returns table (
    total_spending bigint,
    category_count bigint,
    transaction_count bigint,
    average_per_category bigint,
    largest_category_name text,
    largest_category_amount bigint
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

    -- Calculate summary statistics
    return query
    with spending_data as (
        select * from utils.get_spending_by_category(
            v_ledger_id,
            p_start_date,
            p_end_date,
            v_user_data
        )
    )
    select
        coalesce(sum(s.total_spent), 0)::bigint as total_spending,
        count(*)::bigint as category_count,
        coalesce(sum(s.transaction_count), 0)::bigint as transaction_count,
        case
            when count(*) > 0 then (sum(s.total_spent) / count(*))::bigint
            else 0::bigint
        end as average_per_category,
        (select category_name from spending_data order by total_spent desc limit 1) as largest_category_name,
        (select total_spent from spending_data order by total_spent desc limit 1) as largest_category_amount
    from spending_data s;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_spending_summary is
'API: Get overall spending summary statistics for a ledger and date range.
Usage: SELECT * FROM api.get_spending_summary(''ledger_uuid'', ''2025-01-01'', ''2025-01-31'')';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Drop API functions
drop function if exists api.get_spending_summary(text, timestamptz, timestamptz);
drop function if exists api.get_category_transactions(text, timestamptz, timestamptz);
drop function if exists api.get_spending_by_category(text, timestamptz, timestamptz);

-- Drop utils functions
drop function if exists utils.get_category_transactions(bigint, timestamptz, timestamptz, text);
drop function if exists utils.get_spending_by_category(bigint, timestamptz, timestamptz, text);

-- +goose StatementEnd
