-- +goose Up
-- Phase 2.2: Goal calculation functions for goal-based budgeting
-- Implements calculation logic for monthly_funding, target_balance, and target_by_date goals

-- +goose StatementBegin
-- helper function: get current balance for a category
-- returns the current balance of a category account
create or replace function utils.get_category_current_balance(
    p_category_id bigint
) returns bigint as $func$
declare
    v_balance bigint;
begin
    -- get the most recent balance for this category
    select balance into v_balance
    from data.balances
    where account_id = p_category_id
    order by id desc
    limit 1;

    -- return 0 if no balance found
    return coalesce(v_balance, 0);
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- helper function: get budgeted amount for a category in a specific month
-- calculates total budgeted (assigned from income) for the month
create or replace function utils.get_category_budgeted_amount(
    p_category_id bigint,
    p_month text  -- format: YYYYMM
) returns bigint as $func$
declare
    v_start_date date;
    v_end_date date;
    v_budgeted bigint;
begin
    -- convert month string to date range
    v_start_date := to_date(p_month || '01', 'YYYYMMDD');
    v_end_date := (v_start_date + interval '1 month')::date;

    -- sum all credits to this category from Income account in this month
    -- (these are budget assignments)
    select coalesce(sum(t.amount), 0) into v_budgeted
    from data.transactions t
    join data.accounts debit_acct on debit_acct.id = t.debit_account_id
    where t.credit_account_id = p_category_id
      and debit_acct.name = 'Income'
      and debit_acct.type = 'equity'
      and t.date >= v_start_date
      and t.date < v_end_date;

    return v_budgeted;
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- helper function: calculate months between two dates
-- returns the number of full months remaining from now to target date
create or replace function utils.months_between(
    p_from_date date,
    p_to_date date
) returns integer as $func$
declare
    v_months integer;
begin
    -- calculate number of months between dates
    -- use date_part for year and month difference
    v_months := (
        (extract(year from p_to_date) - extract(year from p_from_date)) * 12 +
        (extract(month from p_to_date) - extract(month from p_from_date))
    )::integer;

    -- return at least 1 month if target date is in the future
    if v_months < 1 and p_to_date > p_from_date then
        return 1;
    end if;

    return greatest(v_months, 0);
end;
$func$ language plpgsql immutable;
-- +goose StatementEnd

-- +goose StatementBegin
-- calculate goal status for monthly_funding goals
-- returns progress information for a monthly funding goal
create or replace function utils.calculate_monthly_funding_goal(
    p_goal_id bigint,
    p_category_id bigint,
    p_target_amount bigint,
    p_month text default to_char(current_date, 'YYYYMM')
) returns table(
    funded_amount bigint,
    target_amount bigint,
    remaining_amount bigint,
    percent_complete numeric,
    is_funded boolean,
    needed_this_month bigint
) as $func$
declare
    v_funded bigint;
begin
    -- get amount budgeted to this category this month
    v_funded := utils.get_category_budgeted_amount(p_category_id, p_month);

    return query
    select
        v_funded as funded_amount,
        p_target_amount as target_amount,
        greatest(p_target_amount - v_funded, 0) as remaining_amount,
        case
            when p_target_amount > 0 then
                round((v_funded::numeric / p_target_amount::numeric) * 100, 2)
            else 0
        end as percent_complete,
        v_funded >= p_target_amount as is_funded,
        greatest(p_target_amount - v_funded, 0) as needed_this_month;
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- calculate goal status for target_balance goals
-- returns progress information for a cumulative savings goal
create or replace function utils.calculate_target_balance_goal(
    p_goal_id bigint,
    p_category_id bigint,
    p_target_amount bigint
) returns table(
    current_balance bigint,
    target_amount bigint,
    remaining_amount bigint,
    percent_complete numeric,
    is_complete boolean
) as $func$
declare
    v_current_balance bigint;
begin
    -- get current balance of the category
    v_current_balance := utils.get_category_current_balance(p_category_id);

    return query
    select
        v_current_balance as current_balance,
        p_target_amount as target_amount,
        greatest(p_target_amount - v_current_balance, 0) as remaining_amount,
        case
            when p_target_amount > 0 then
                round((v_current_balance::numeric / p_target_amount::numeric) * 100, 2)
            else 0
        end as percent_complete,
        v_current_balance >= p_target_amount as is_complete;
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- calculate goal status for target_by_date goals
-- returns progress information including monthly needed amount
create or replace function utils.calculate_target_by_date_goal(
    p_goal_id bigint,
    p_category_id bigint,
    p_target_amount bigint,
    p_target_date date
) returns table(
    current_balance bigint,
    target_amount bigint,
    remaining_amount bigint,
    percent_complete numeric,
    months_remaining integer,
    needed_per_month bigint,
    is_on_track boolean,
    is_complete boolean,
    target_date date
) as $func$
declare
    v_current_balance bigint;
    v_months_left integer;
    v_needed_monthly bigint;
    v_current_month text;
    v_budgeted_this_month bigint;
begin
    -- get current balance
    v_current_balance := utils.get_category_current_balance(p_category_id);

    -- calculate months remaining from today to target date
    v_months_left := utils.months_between(current_date, p_target_date);

    -- calculate needed per month
    if v_months_left > 0 then
        v_needed_monthly := greatest(
            ceil((p_target_amount - v_current_balance)::numeric / v_months_left::numeric)::bigint,
            0
        );
    else
        v_needed_monthly := 0;
    end if;

    -- get amount budgeted this month to check if on track
    v_current_month := to_char(current_date, 'YYYYMM');
    v_budgeted_this_month := utils.get_category_budgeted_amount(p_category_id, v_current_month);

    return query
    select
        v_current_balance as current_balance,
        p_target_amount as target_amount,
        greatest(p_target_amount - v_current_balance, 0) as remaining_amount,
        case
            when p_target_amount > 0 then
                round((v_current_balance::numeric / p_target_amount::numeric) * 100, 2)
            else 0
        end as percent_complete,
        v_months_left as months_remaining,
        v_needed_monthly as needed_per_month,
        -- on track if: already complete OR budgeting at least the needed monthly amount
        (v_current_balance >= p_target_amount) or
        (v_months_left > 0 and v_budgeted_this_month >= v_needed_monthly) as is_on_track,
        v_current_balance >= p_target_amount as is_complete,
        p_target_date as target_date;
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- unified function: calculate goal status for any goal type
-- routes to appropriate calculation function based on goal type
create or replace function utils.calculate_goal_status(
    p_goal_uuid text,
    p_month text default to_char(current_date, 'YYYYMM'),
    p_user_data text default utils.get_user()
) returns table(
    goal_uuid text,
    goal_type text,
    category_uuid text,
    category_name text,
    target_amount bigint,
    current_amount bigint,
    remaining_amount bigint,
    percent_complete numeric,
    is_complete boolean,
    -- monthly_funding specific
    funded_this_month bigint,
    needed_this_month bigint,
    -- target_by_date specific
    target_date date,
    months_remaining integer,
    needed_per_month bigint,
    is_on_track boolean
) as $func$
declare
    v_goal record;
    v_monthly_result record;
    v_balance_result record;
    v_bydate_result record;
begin
    -- get goal details
    select
        cg.id,
        cg.uuid,
        cg.goal_type,
        cg.category_id,
        cg.target_amount,
        cg.target_date,
        a.uuid as cat_uuid,
        a.name as cat_name
    into v_goal
    from data.category_goals cg
    join data.accounts a on a.id = cg.category_id
    where cg.uuid = p_goal_uuid
      and cg.user_data = p_user_data;

    if not found then
        raise exception 'Goal with UUID % not found for current user', p_goal_uuid;
    end if;

    -- route to appropriate calculation based on goal type
    if v_goal.goal_type = 'monthly_funding' then
        -- monthly funding goal
        select * into v_monthly_result
        from utils.calculate_monthly_funding_goal(
            v_goal.id,
            v_goal.category_id,
            v_goal.target_amount,
            p_month
        );

        return query
        select
            v_goal.uuid,
            v_goal.goal_type,
            v_goal.cat_uuid,
            v_goal.cat_name,
            v_goal.target_amount,
            v_monthly_result.funded_amount,
            v_monthly_result.remaining_amount,
            v_monthly_result.percent_complete,
            v_monthly_result.is_funded,
            v_monthly_result.funded_amount,
            v_monthly_result.needed_this_month,
            null::date,
            null::integer,
            null::bigint,
            null::boolean;

    elsif v_goal.goal_type = 'target_balance' then
        -- target balance goal
        select * into v_balance_result
        from utils.calculate_target_balance_goal(
            v_goal.id,
            v_goal.category_id,
            v_goal.target_amount
        );

        return query
        select
            v_goal.uuid,
            v_goal.goal_type,
            v_goal.cat_uuid,
            v_goal.cat_name,
            v_goal.target_amount,
            v_balance_result.current_balance,
            v_balance_result.remaining_amount,
            v_balance_result.percent_complete,
            v_balance_result.is_complete,
            null::bigint,
            null::bigint,
            null::date,
            null::integer,
            null::bigint,
            null::boolean;

    elsif v_goal.goal_type = 'target_by_date' then
        -- target by date goal
        select * into v_bydate_result
        from utils.calculate_target_by_date_goal(
            v_goal.id,
            v_goal.category_id,
            v_goal.target_amount,
            v_goal.target_date
        );

        return query
        select
            v_goal.uuid,
            v_goal.goal_type,
            v_goal.cat_uuid,
            v_goal.cat_name,
            v_goal.target_amount,
            v_bydate_result.current_balance,
            v_bydate_result.remaining_amount,
            v_bydate_result.percent_complete,
            v_bydate_result.is_complete,
            null::bigint,
            null::bigint,
            v_bydate_result.target_date,
            v_bydate_result.months_remaining,
            v_bydate_result.needed_per_month,
            v_bydate_result.is_on_track;
    else
        raise exception 'Unknown goal type: %', v_goal.goal_type;
    end if;
end;
$func$ language plpgsql stable security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- comment on functions
comment on function utils.get_category_current_balance(bigint) is 'Returns the current balance for a category account';
comment on function utils.get_category_budgeted_amount(bigint, text) is 'Returns the amount budgeted to a category in a specific month (YYYYMM format)';
comment on function utils.months_between(date, date) is 'Calculates the number of months between two dates';
comment on function utils.calculate_monthly_funding_goal(bigint, bigint, bigint, text) is 'Calculates progress for monthly_funding goals';
comment on function utils.calculate_target_balance_goal(bigint, bigint, bigint) is 'Calculates progress for target_balance goals';
comment on function utils.calculate_target_by_date_goal(bigint, bigint, bigint, date) is 'Calculates progress for target_by_date goals with monthly needed amount';
comment on function utils.calculate_goal_status(text, text, text) is 'Unified function to calculate goal status for any goal type';
-- +goose StatementEnd

-- +goose Down
-- Remove goal calculation functions

-- +goose StatementBegin
drop function if exists utils.calculate_goal_status(text, text, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.calculate_target_by_date_goal(bigint, bigint, bigint, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.calculate_target_balance_goal(bigint, bigint, bigint);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.calculate_monthly_funding_goal(bigint, bigint, bigint, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.months_between(date, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.get_category_budgeted_amount(bigint, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.get_category_current_balance(bigint);
-- +goose StatementEnd
