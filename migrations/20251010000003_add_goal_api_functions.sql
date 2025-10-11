-- +goose Up
-- Phase 2.3: Goal API Functions - Public interface for goal management
-- Creates API views and functions for goal CRUD operations and status queries

-- +goose StatementBegin
-- create api view for category_goals
-- exposes goals with user-friendly UUIDs instead of internal IDs
create or replace view api.category_goals with (security_invoker = true) as
select
    cg.uuid,
    cg.goal_type,
    cg.target_amount,
    cg.target_date,
    cg.repeat_frequency,
    cg.created_at,
    cg.updated_at,
    a.uuid as category_uuid,
    a.name as category_name,
    l.uuid as ledger_uuid
from data.category_goals cg
join data.accounts a on a.id = cg.category_id
join data.ledgers l on l.id = a.ledger_id;
-- +goose StatementEnd

-- +goose StatementBegin
-- grant select on api view
comment on view api.category_goals is 'Public view of category goals with UUIDs for API consumption';
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: create_category_goal
-- creates a new goal for a category
create or replace function api.create_category_goal(
    p_category_uuid text,
    p_goal_type text,
    p_target_amount bigint,
    p_target_date date default null,
    p_repeat_frequency text default null
) returns setof api.category_goals as $apifunc$
declare
    v_category_id bigint;
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
    v_goal_uuid text;
begin
    -- validate goal type
    if p_goal_type not in ('monthly_funding', 'target_balance', 'target_by_date') then
        raise exception 'Invalid goal type: %. Must be one of: monthly_funding, target_balance, target_by_date', p_goal_type;
    end if;

    -- validate target amount
    if p_target_amount <= 0 then
        raise exception 'Target amount must be positive: %', p_target_amount;
    end if;

    -- validate target_date is required for target_by_date goals
    if p_goal_type = 'target_by_date' and p_target_date is null then
        raise exception 'target_date is required for target_by_date goals';
    end if;

    -- validate repeat_frequency if provided
    if p_repeat_frequency is not null and p_repeat_frequency not in ('weekly', 'monthly', 'yearly') then
        raise exception 'Invalid repeat_frequency: %. Must be one of: weekly, monthly, yearly', p_repeat_frequency;
    end if;

    -- get category id and verify it exists and belongs to user
    select a.id, a.ledger_id into v_category_id, v_ledger_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data
      and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category with UUID % not found or not a budget category for current user', p_category_uuid;
    end if;

    -- check if category already has a goal
    if exists(select 1 from data.category_goals where category_id = v_category_id) then
        raise exception 'Category "%" already has a goal. Update or delete the existing goal first.',
            (select name from data.accounts where id = v_category_id);
    end if;

    -- insert the goal
    insert into data.category_goals (
        category_id,
        goal_type,
        target_amount,
        target_date,
        repeat_frequency,
        user_data
    )
    values (
        v_category_id,
        p_goal_type,
        p_target_amount,
        p_target_date,
        p_repeat_frequency,
        v_user_data
    )
    returning uuid into v_goal_uuid;

    -- return the created goal from api view
    return query
    select * from api.category_goals
    where uuid = v_goal_uuid;
end;
$apifunc$ language plpgsql volatile security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: update_category_goal
-- updates an existing goal's parameters
create or replace function api.update_category_goal(
    p_goal_uuid text,
    p_target_amount bigint default null,
    p_target_date date default null,
    p_repeat_frequency text default null
) returns setof api.category_goals as $apifunc$
declare
    v_goal_id bigint;
    v_goal_type text;
    v_user_data text := utils.get_user();
begin
    -- get goal and verify ownership
    select cg.id, cg.goal_type into v_goal_id, v_goal_type
    from data.category_goals cg
    where cg.uuid = p_goal_uuid
      and cg.user_data = v_user_data;

    if v_goal_id is null then
        raise exception 'Goal with UUID % not found for current user', p_goal_uuid;
    end if;

    -- validate target amount if provided
    if p_target_amount is not null and p_target_amount <= 0 then
        raise exception 'Target amount must be positive: %', p_target_amount;
    end if;

    -- validate repeat_frequency if provided
    if p_repeat_frequency is not null and p_repeat_frequency not in ('weekly', 'monthly', 'yearly') then
        raise exception 'Invalid repeat_frequency: %. Must be one of: weekly, monthly, yearly', p_repeat_frequency;
    end if;

    -- validate target_date for target_by_date goals
    if v_goal_type = 'target_by_date' and p_target_date is null and p_target_amount is not null then
        -- if updating a target_by_date goal and not providing date, keep existing
        -- (this is handled by update with defaults)
        null;
    end if;

    -- update the goal (only fields that are not null)
    update data.category_goals
    set
        target_amount = coalesce(p_target_amount, target_amount),
        target_date = coalesce(p_target_date, target_date),
        repeat_frequency = coalesce(p_repeat_frequency, repeat_frequency)
    where id = v_goal_id;

    -- return the updated goal from api view
    return query
    select * from api.category_goals
    where uuid = p_goal_uuid;
end;
$apifunc$ language plpgsql volatile security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: delete_category_goal
-- deletes a goal
create or replace function api.delete_category_goal(
    p_goal_uuid text
) returns boolean as $apifunc$
declare
    v_user_data text := utils.get_user();
    v_deleted boolean;
begin
    -- delete the goal (RLS ensures user ownership)
    delete from data.category_goals
    where uuid = p_goal_uuid
      and user_data = v_user_data;

    -- check if anything was deleted
    get diagnostics v_deleted = row_count;

    if v_deleted = 0 then
        raise exception 'Goal with UUID % not found for current user', p_goal_uuid;
    end if;

    return true;
end;
$apifunc$ language plpgsql volatile security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: get_category_goal_status
-- get goal status with progress for a specific goal
create or replace function api.get_category_goal_status(
    p_goal_uuid text,
    p_month text default to_char(current_date, 'YYYYMM')
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
    funded_this_month bigint,
    needed_this_month bigint,
    target_date date,
    months_remaining integer,
    needed_per_month bigint,
    is_on_track boolean
) as $apifunc$
begin
    -- delegate to utils function which does the heavy lifting
    return query
    select * from utils.calculate_goal_status(p_goal_uuid, p_month);
end;
$apifunc$ language plpgsql stable security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: get_ledger_goals
-- get all goals for a ledger with their current status
create or replace function api.get_ledger_goals(
    p_ledger_uuid text,
    p_month text default to_char(current_date, 'YYYYMM')
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
    funded_this_month bigint,
    needed_this_month bigint,
    target_date date,
    months_remaining integer,
    needed_per_month bigint,
    is_on_track boolean
) as $apifunc$
declare
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
begin
    -- get ledger id and verify ownership
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid
      and l.user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger with UUID % not found for current user', p_ledger_uuid;
    end if;

    -- get all goals for categories in this ledger with their status
    return query
    select gs.*
    from data.category_goals cg
    join data.accounts a on a.id = cg.category_id
    cross join lateral utils.calculate_goal_status(cg.uuid, p_month) gs
    where a.ledger_id = v_ledger_id
      and cg.user_data = v_user_data
    order by a.name;
end;
$apifunc$ language plpgsql stable security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- api function: get_underfunded_goals
-- get goals that need attention (not met/not on track)
create or replace function api.get_underfunded_goals(
    p_ledger_uuid text,
    p_month text default to_char(current_date, 'YYYYMM')
) returns table(
    goal_uuid text,
    goal_type text,
    category_uuid text,
    category_name text,
    target_amount bigint,
    current_amount bigint,
    remaining_amount bigint,
    percent_complete numeric,
    needed_this_month bigint,
    needed_per_month bigint,
    is_on_track boolean,
    priority_score numeric
) as $apifunc$
declare
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
begin
    -- get ledger id and verify ownership
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid
      and l.user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger with UUID % not found for current user', p_ledger_uuid;
    end if;

    -- get underfunded goals with priority scoring
    return query
    select
        gs.goal_uuid,
        gs.goal_type,
        gs.category_uuid,
        gs.category_name,
        gs.target_amount,
        gs.current_amount,
        gs.remaining_amount,
        gs.percent_complete,
        gs.needed_this_month,
        gs.needed_per_month,
        gs.is_on_track,
        -- priority score: higher = more urgent
        case
            when gs.goal_type = 'monthly_funding' and gs.needed_this_month > 0 then
                (gs.needed_this_month::numeric / gs.target_amount::numeric) * 100
            when gs.goal_type = 'target_by_date' and not coalesce(gs.is_on_track, false) then
                case
                    when gs.months_remaining <= 1 then 100.0
                    when gs.months_remaining <= 3 then 80.0
                    when gs.months_remaining <= 6 then 60.0
                    else 40.0
                end
            when gs.goal_type = 'target_balance' and gs.remaining_amount > 0 then
                (gs.current_amount::numeric / gs.target_amount::numeric) * 50
            else 0
        end as priority_score
    from data.category_goals cg
    join data.accounts a on a.id = cg.category_id
    cross join lateral utils.calculate_goal_status(cg.uuid, p_month) gs
    where a.ledger_id = v_ledger_id
      and cg.user_data = v_user_data
      and (
          -- monthly_funding: not fully funded this month
          (gs.goal_type = 'monthly_funding' and gs.needed_this_month > 0)
          -- target_by_date: not on track or not complete
          or (gs.goal_type = 'target_by_date' and (not coalesce(gs.is_on_track, false) or not gs.is_complete))
          -- target_balance: not complete
          or (gs.goal_type = 'target_balance' and not gs.is_complete)
      )
    order by priority_score desc, gs.category_name;
end;
$apifunc$ language plpgsql stable security invoker;
-- +goose StatementEnd

-- +goose StatementBegin
-- comment on api functions
comment on function api.create_category_goal(text, text, bigint, date, text) is 'Create a new goal for a category';
comment on function api.update_category_goal(text, bigint, date, text) is 'Update an existing goal''s parameters';
comment on function api.delete_category_goal(text) is 'Delete a goal';
comment on function api.get_category_goal_status(text, text) is 'Get goal status with progress for a specific goal';
comment on function api.get_ledger_goals(text, text) is 'Get all goals for a ledger with their current status';
comment on function api.get_underfunded_goals(text, text) is 'Get goals that need attention, sorted by priority';
-- +goose StatementEnd

-- +goose Down
-- Remove goal API functions and view

-- +goose StatementBegin
drop function if exists api.get_underfunded_goals(text, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.get_ledger_goals(text, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.get_category_goal_status(text, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.delete_category_goal(text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.update_category_goal(text, bigint, date, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.create_category_goal(text, text, bigint, date, text);
-- +goose StatementEnd

-- +goose StatementBegin
drop view if exists api.category_goals;
-- +goose StatementEnd
