-- +goose Up
-- +goose StatementBegin
-- Phase 6.1: Category Groups (Hierarchy)
-- Migration to add category grouping and hierarchy support

-- ============================================================================
-- DATA LAYER: Add columns to accounts table for category groups
-- ============================================================================

-- Add parent_category_id to support hierarchy
alter table data.accounts add column if not exists parent_category_id bigint references data.accounts(id) on delete set null;

-- Add sort_order for custom ordering
alter table data.accounts add column if not exists sort_order integer not null default 0;

-- Add is_group flag to identify group categories
alter table data.accounts add column if not exists is_group boolean not null default false;

-- Create index for parent lookups
create index if not exists idx_accounts_parent_category on data.accounts(parent_category_id) where parent_category_id is not null;

-- Create index for sorting
create index if not exists idx_accounts_sort_order on data.accounts(ledger_id, sort_order, name);

-- Add constraint: Only equity accounts can be groups or have parents
do $$
begin
    if not exists (
        select 1 from pg_constraint
        where conname = 'category_groups_equity_only'
    ) then
        alter table data.accounts add constraint category_groups_equity_only
            check (
                (parent_category_id is null and is_group = false) or
                (type = 'equity')
            );
    end if;
end $$;

comment on column data.accounts.parent_category_id is
'Reference to parent category for hierarchical grouping. NULL for top-level categories and groups.';

comment on column data.accounts.sort_order is
'Custom sort order within the ledger. Lower numbers appear first.';

comment on column data.accounts.is_group is
'True if this is a category group (container) rather than a regular budget category.';

-- ============================================================================
-- UTILS LAYER: Helper functions for category groups
-- ============================================================================

-- -----------------------------------------------------------------------------
-- Get category with parent information
-- -----------------------------------------------------------------------------
create or replace function utils.get_category_with_group(
    p_category_id bigint,
    p_user_data text
) returns table (
    category_id bigint,
    category_uuid text,
    category_name text,
    is_group boolean,
    parent_id bigint,
    parent_uuid text,
    parent_name text,
    sort_order integer
) as $func$
begin
    return query
    select
        c.id as category_id,
        c.uuid as category_uuid,
        c.name as category_name,
        c.is_group,
        c.parent_category_id as parent_id,
        p.uuid as parent_uuid,
        p.name as parent_name,
        c.sort_order
    from data.accounts c
    left join data.accounts p on c.parent_category_id = p.id
    where c.id = p_category_id
      and c.user_data = p_user_data
      and c.type = 'equity';
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_category_with_group is
'Get category information including parent group details.';

-- -----------------------------------------------------------------------------
-- Get all categories organized by groups
-- -----------------------------------------------------------------------------
create or replace function utils.get_categories_by_group(
    p_ledger_id bigint,
    p_user_data text
) returns table (
    category_id bigint,
    category_uuid text,
    category_name text,
    is_group boolean,
    parent_id bigint,
    parent_uuid text,
    parent_name text,
    sort_order integer,
    level integer
) as $func$
begin
    return query
    with recursive category_tree as (
        -- Top level: groups and ungrouped categories
        select
            c.id as category_id,
            c.uuid as category_uuid,
            c.name as category_name,
            c.is_group,
            c.parent_category_id as parent_id,
            cast(null as text) as parent_uuid,
            cast(null as text) as parent_name,
            c.sort_order,
            0 as level
        from data.accounts c
        where c.ledger_id = p_ledger_id
          and c.user_data = p_user_data
          and c.type = 'equity'
          and c.parent_category_id is null
          and c.name not in ('Income', 'Unassigned', 'Off-budget')

        union all

        -- Child categories
        select
            c.id as category_id,
            c.uuid as category_uuid,
            c.name as category_name,
            c.is_group,
            c.parent_category_id as parent_id,
            p.uuid as parent_uuid,
            p.name as parent_name,
            c.sort_order,
            ct.level + 1 as level
        from data.accounts c
        join category_tree ct on c.parent_category_id = ct.category_id
        join data.accounts p on c.parent_category_id = p.id
        where c.user_data = p_user_data
          and c.type = 'equity'
    )
    select * from category_tree
    order by
        coalesce(parent_id, category_id),
        case when is_group then 0 else 1 end,
        sort_order,
        category_name;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_categories_by_group is
'Get all categories organized hierarchically by groups. Returns tree structure with levels.';

-- -----------------------------------------------------------------------------
-- Get group subtotals (sum of child categories)
-- -----------------------------------------------------------------------------
create or replace function utils.get_group_subtotals(
    p_ledger_id bigint,
    p_period text,
    p_user_data text
) returns table (
    group_id bigint,
    group_uuid text,
    group_name text,
    total_budgeted bigint,
    total_activity bigint,
    total_balance bigint,
    child_count integer
) as $func$
begin
    return query
    select
        g.id as group_id,
        g.uuid as group_uuid,
        g.name as group_name,
        coalesce(sum(bs.budgeted), 0)::bigint as total_budgeted,
        coalesce(sum(bs.activity), 0)::bigint as total_activity,
        coalesce(sum(bs.balance), 0)::bigint as total_balance,
        count(c.id)::integer as child_count
    from data.accounts g
    left join data.accounts c on c.parent_category_id = g.id
        and c.user_data = p_user_data
    left join lateral (
        select * from utils.get_budget_status_for_category(
            c.id,
            p_ledger_id,
            p_period,
            p_user_data
        )
    ) bs on true
    where g.ledger_id = p_ledger_id
      and g.user_data = p_user_data
      and g.type = 'equity'
      and g.is_group = true
    group by g.id, g.uuid, g.name
    order by g.sort_order, g.name;
end;
$func$ language plpgsql security definer stable;

comment on function utils.get_group_subtotals is
'Calculate subtotals for category groups by summing child category values.';

-- ============================================================================
-- API LAYER: Public-facing functions for category groups
-- ============================================================================

-- -----------------------------------------------------------------------------
-- API: Get categories organized by groups
-- -----------------------------------------------------------------------------
create or replace function api.get_categories_by_group(
    p_ledger_uuid text
) returns table (
    category_uuid text,
    category_name text,
    is_group boolean,
    parent_uuid text,
    parent_name text,
    sort_order integer,
    level integer
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

    -- Return categorized hierarchy
    return query
    select
        cat.category_uuid,
        cat.category_name,
        cat.is_group,
        cat.parent_uuid,
        cat.parent_name,
        cat.sort_order,
        cat.level
    from utils.get_categories_by_group(v_ledger_id, v_user_data) cat;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_categories_by_group is
'API: Get categories organized by groups for a ledger. Returns hierarchical structure.
Usage: SELECT * FROM api.get_categories_by_group(''ledger_uuid'')';

-- -----------------------------------------------------------------------------
-- API: Get group subtotals
-- -----------------------------------------------------------------------------
create or replace function api.get_group_subtotals(
    p_ledger_uuid text,
    p_period text default null
) returns table (
    group_uuid text,
    group_name text,
    total_budgeted bigint,
    total_activity bigint,
    total_balance bigint,
    child_count integer
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

    -- Return group subtotals
    return query
    select
        gs.group_uuid,
        gs.group_name,
        gs.total_budgeted,
        gs.total_activity,
        gs.total_balance,
        gs.child_count
    from utils.get_group_subtotals(v_ledger_id, p_period, v_user_data) gs;
end;
$func$ language plpgsql security definer stable;

comment on function api.get_group_subtotals is
'API: Get subtotals for category groups. Returns aggregated values for all child categories.
Usage: SELECT * FROM api.get_group_subtotals(''ledger_uuid'', ''202510'')';

-- -----------------------------------------------------------------------------
-- API: Create category group
-- -----------------------------------------------------------------------------
create or replace function api.create_category_group(
    p_ledger_uuid text,
    p_group_name text,
    p_sort_order integer default 0
) returns table (
    group_uuid text,
    group_name text,
    sort_order integer
) as $func$
declare
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
    v_new_uuid text;
begin
    -- Get ledger ID and validate access
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid
      and l.user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found or access denied';
    end if;

    -- Create group category (UUID is auto-generated by default)
    insert into data.accounts (
        ledger_id,
        name,
        type,
        is_group,
        sort_order,
        user_data
    ) values (
        v_ledger_id,
        p_group_name,
        'equity',
        true,
        p_sort_order,
        v_user_data
    )
    returning uuid into v_new_uuid;

    -- Return created group
    return query
    select
        v_new_uuid as group_uuid,
        p_group_name as group_name,
        p_sort_order as sort_order;
end;
$func$ language plpgsql security definer;

comment on function api.create_category_group is
'API: Create a new category group.
Usage: SELECT * FROM api.create_category_group(''ledger_uuid'', ''Monthly Bills'', 0)';

-- -----------------------------------------------------------------------------
-- API: Assign category to group
-- -----------------------------------------------------------------------------
create or replace function api.assign_category_to_group(
    p_category_uuid text,
    p_group_uuid text
) returns boolean as $func$
declare
    v_category_id bigint;
    v_group_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get category ID and validate access
    select a.id into v_category_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data
      and a.type = 'equity'
      and a.is_group = false;

    if v_category_id is null then
        raise exception 'Category not found or access denied';
    end if;

    -- Get group ID and validate (null means remove from group)
    if p_group_uuid is not null then
        select a.id into v_group_id
        from data.accounts a
        where a.uuid = p_group_uuid
          and a.user_data = v_user_data
          and a.type = 'equity'
          and a.is_group = true;

        if v_group_id is null then
            raise exception 'Group not found or access denied';
        end if;
    end if;

    -- Update category
    update data.accounts
    set parent_category_id = v_group_id
    where id = v_category_id;

    return true;
end;
$func$ language plpgsql security definer;

comment on function api.assign_category_to_group is
'API: Assign a category to a group, or remove from group by passing NULL.
Usage: SELECT api.assign_category_to_group(''category_uuid'', ''group_uuid'')';

-- -----------------------------------------------------------------------------
-- API: Update sort order
-- -----------------------------------------------------------------------------
create or replace function api.update_category_sort_order(
    p_category_uuid text,
    p_sort_order integer
) returns boolean as $func$
declare
    v_category_id bigint;
    v_user_data text := utils.get_user();
begin
    -- Get category ID and validate access
    select a.id into v_category_id
    from data.accounts a
    where a.uuid = p_category_uuid
      and a.user_data = v_user_data
      and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category not found or access denied';
    end if;

    -- Update sort order
    update data.accounts
    set sort_order = p_sort_order
    where id = v_category_id;

    return true;
end;
$func$ language plpgsql security definer;

comment on function api.update_category_sort_order is
'API: Update the sort order of a category or group.
Usage: SELECT api.update_category_sort_order(''category_uuid'', 10)';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop API functions
drop function if exists api.update_category_sort_order(text, integer);
drop function if exists api.assign_category_to_group(text, text);
drop function if exists api.create_category_group(text, text, integer);
drop function if exists api.get_group_subtotals(text, text);
drop function if exists api.get_categories_by_group(text);

-- Drop utils functions
drop function if exists utils.get_group_subtotals(bigint, text, text);
drop function if exists utils.get_categories_by_group(bigint, text);
drop function if exists utils.get_category_with_group(bigint, text);

-- Drop indexes
drop index if exists data.idx_accounts_sort_order;
drop index if exists data.idx_accounts_parent_category;

-- Drop constraint
alter table data.accounts drop constraint if exists category_groups_equity_only;

-- Drop columns
alter table data.accounts drop column if exists is_group;
alter table data.accounts drop column if exists sort_order;
alter table data.accounts drop column if exists parent_category_id;

-- +goose StatementEnd
