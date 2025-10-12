-- +goose Up
-- Utils functions for payee management (Phase 3.3)

-- Get or create payee by name
-- This function will automatically create a payee if it doesn't exist
create or replace function utils.get_or_create_payee(
    p_name text,
    p_default_category_id bigint default null,
    p_user_data text default utils.get_user()
) returns bigint as $$
declare
    v_payee_id bigint;
begin
    -- Validate input
    if p_name is null or trim(p_name) = '' then
        raise exception 'Payee name cannot be empty';
    end if;

    -- Try to find existing payee (excluding merged payees)
    select id into v_payee_id
    from data.payees
    where name = trim(p_name)
      and user_data = p_user_data
      and merged_into_id is null;

    -- If not found, create new payee
    if v_payee_id is null then
        insert into data.payees (name, default_category_id, user_data)
        values (trim(p_name), p_default_category_id, p_user_data)
        returning id into v_payee_id;
    end if;

    return v_payee_id;
end;
$$ language plpgsql security definer;

-- Create payee
create or replace function utils.create_payee(
    p_name text,
    p_default_category_id bigint default null,
    p_auto_categorize boolean default true,
    p_user_data text default utils.get_user()
) returns text as $$
declare
    v_payee_uuid text;
begin
    -- Validate input
    if p_name is null or trim(p_name) = '' then
        raise exception 'Payee name cannot be empty';
    end if;

    -- Check if payee with same name already exists
    if exists (
        select 1 from data.payees
        where name = trim(p_name)
          and user_data = p_user_data
          and merged_into_id is null
    ) then
        raise exception 'Payee with name "%" already exists', trim(p_name);
    end if;

    -- Validate category if provided
    if p_default_category_id is not null then
        if not exists (
            select 1 from data.accounts
            where id = p_default_category_id
              and user_data = p_user_data
              and type = 'equity'
              and name not in ('Income', 'Unassigned')
        ) then
            raise exception 'Invalid category specified';
        end if;
    end if;

    -- Create payee
    insert into data.payees (name, default_category_id, auto_categorize, user_data)
    values (trim(p_name), p_default_category_id, p_auto_categorize, p_user_data)
    returning uuid into v_payee_uuid;

    return v_payee_uuid;
end;
$$ language plpgsql security definer;

-- Update payee
create or replace function utils.update_payee(
    p_payee_uuid text,
    p_name text default null,
    p_default_category_id bigint default null,
    p_auto_categorize boolean default null,
    p_user_data text default utils.get_user()
) returns boolean as $$
declare
    v_payee_id bigint;
begin
    -- Get payee ID and verify ownership
    select id into v_payee_id
    from data.payees
    where uuid = p_payee_uuid
      and user_data = p_user_data
      and merged_into_id is null;

    if v_payee_id is null then
        raise exception 'Payee not found or access denied';
    end if;

    -- Validate name if provided
    if p_name is not null then
        if trim(p_name) = '' then
            raise exception 'Payee name cannot be empty';
        end if;

        -- Check for name conflicts (excluding current payee)
        if exists (
            select 1 from data.payees
            where name = trim(p_name)
              and user_data = p_user_data
              and id != v_payee_id
              and merged_into_id is null
        ) then
            raise exception 'Payee with name "%" already exists', trim(p_name);
        end if;
    end if;

    -- Validate category if provided
    if p_default_category_id is not null then
        if not exists (
            select 1 from data.accounts
            where id = p_default_category_id
              and user_data = p_user_data
              and type = 'equity'
              and name not in ('Income', 'Unassigned')
        ) then
            raise exception 'Invalid category specified';
        end if;
    end if;

    -- Update payee
    update data.payees
    set
        name = coalesce(trim(p_name), name),
        default_category_id = coalesce(p_default_category_id, default_category_id),
        auto_categorize = coalesce(p_auto_categorize, auto_categorize),
        updated_at = now()
    where id = v_payee_id;

    return true;
end;
$$ language plpgsql security definer;

-- Delete payee
create or replace function utils.delete_payee(
    p_payee_uuid text,
    p_user_data text default utils.get_user()
) returns boolean as $$
declare
    v_payee_id bigint;
begin
    -- Get payee ID and verify ownership
    select id into v_payee_id
    from data.payees
    where uuid = p_payee_uuid
      and user_data = p_user_data
      and merged_into_id is null;

    if v_payee_id is null then
        raise exception 'Payee not found or access denied';
    end if;

    -- Set payee_id to NULL on all linked transactions
    update data.transactions
    set payee_id = null
    where payee_id = v_payee_id;

    -- Delete payee
    delete from data.payees
    where id = v_payee_id;

    return true;
end;
$$ language plpgsql security definer;

-- Merge payees
-- This function merges source payee into target payee
-- All transactions linked to source payee will be relinked to target payee
create or replace function utils.merge_payees(
    p_source_payee_uuid text,
    p_target_payee_uuid text,
    p_user_data text default utils.get_user()
) returns boolean as $$
declare
    v_source_id bigint;
    v_target_id bigint;
    v_transaction_count integer;
begin
    -- Validate input
    if p_source_payee_uuid = p_target_payee_uuid then
        raise exception 'Cannot merge a payee into itself';
    end if;

    -- Get source payee ID and verify ownership
    select id into v_source_id
    from data.payees
    where uuid = p_source_payee_uuid
      and user_data = p_user_data
      and merged_into_id is null;

    if v_source_id is null then
        raise exception 'Source payee not found or access denied';
    end if;

    -- Get target payee ID and verify ownership
    select id into v_target_id
    from data.payees
    where uuid = p_target_payee_uuid
      and user_data = p_user_data
      and merged_into_id is null;

    if v_target_id is null then
        raise exception 'Target payee not found or access denied';
    end if;

    -- Update all transactions from source to target
    update data.transactions
    set payee_id = v_target_id
    where payee_id = v_source_id;

    get diagnostics v_transaction_count = row_count;

    -- Mark source payee as merged into target
    update data.payees
    set merged_into_id = v_target_id,
        updated_at = now()
    where id = v_source_id;

    -- Log the merge
    raise notice 'Merged payee "%" into "%", updated % transactions',
        p_source_payee_uuid, p_target_payee_uuid, v_transaction_count;

    return true;
end;
$$ language plpgsql security definer;

-- Get payee by UUID
create or replace function utils.get_payee(
    p_payee_uuid text,
    p_user_data text default utils.get_user()
) returns table (
    id bigint,
    uuid text,
    name text,
    default_category_id bigint,
    default_category_uuid text,
    default_category_name text,
    auto_categorize boolean,
    transaction_count bigint,
    last_used timestamptz,
    created_at timestamptz,
    updated_at timestamptz
) as $$
begin
    return query
    select
        p.id,
        p.uuid,
        p.name,
        p.default_category_id,
        c.uuid as default_category_uuid,
        c.name as default_category_name,
        p.auto_categorize,
        count(distinct t.id) as transaction_count,
        max(t.date) as last_used,
        p.created_at,
        p.updated_at
    from data.payees p
    left join data.accounts c on c.id = p.default_category_id
    left join data.transactions t on t.payee_id = p.id and t.deleted_at is null
    where p.uuid = p_payee_uuid
      and p.user_data = p_user_data
      and p.merged_into_id is null
    group by p.id, p.uuid, p.name, p.default_category_id, c.uuid, c.name,
             p.auto_categorize, p.created_at, p.updated_at;
end;
$$ language plpgsql security definer;

-- Get all payees for user
create or replace function utils.get_all_payees(
    p_user_data text default utils.get_user()
) returns table (
    id bigint,
    uuid text,
    name text,
    default_category_id bigint,
    default_category_uuid text,
    default_category_name text,
    auto_categorize boolean,
    transaction_count bigint,
    last_used timestamptz,
    created_at timestamptz,
    updated_at timestamptz
) as $$
begin
    return query
    select
        p.id,
        p.uuid,
        p.name,
        p.default_category_id,
        c.uuid as default_category_uuid,
        c.name as default_category_name,
        p.auto_categorize,
        count(distinct t.id) as transaction_count,
        max(t.date) as last_used,
        p.created_at,
        p.updated_at
    from data.payees p
    left join data.accounts c on c.id = p.default_category_id
    left join data.transactions t on t.payee_id = p.id and t.deleted_at is null
    where p.user_data = p_user_data
      and p.merged_into_id is null
    group by p.id, p.uuid, p.name, p.default_category_id, c.uuid, c.name,
             p.auto_categorize, p.created_at, p.updated_at
    order by p.name;
end;
$$ language plpgsql security definer;

-- Search payees by name
create or replace function utils.search_payees(
    p_search text,
    p_user_data text default utils.get_user()
) returns table (
    id bigint,
    uuid text,
    name text,
    default_category_id bigint,
    default_category_uuid text,
    default_category_name text,
    auto_categorize boolean,
    transaction_count bigint,
    last_used timestamptz
) as $$
begin
    return query
    select
        p.id,
        p.uuid,
        p.name,
        p.default_category_id,
        c.uuid as default_category_uuid,
        c.name as default_category_name,
        p.auto_categorize,
        count(distinct t.id) as transaction_count,
        max(t.date) as last_used
    from data.payees p
    left join data.accounts c on c.id = p.default_category_id
    left join data.transactions t on t.payee_id = p.id and t.deleted_at is null
    where p.user_data = p_user_data
      and p.merged_into_id is null
      and p.name ilike '%' || p_search || '%'
    group by p.id, p.uuid, p.name, p.default_category_id, c.uuid, c.name,
             p.auto_categorize
    order by
        -- Prioritize exact matches and most recently used
        case when lower(p.name) = lower(p_search) then 0 else 1 end,
        max(t.date) desc nulls last,
        p.name;
end;
$$ language plpgsql security definer;

-- +goose Down
drop function if exists utils.search_payees(text, text);
drop function if exists utils.get_all_payees(text);
drop function if exists utils.get_payee(text, text);
drop function if exists utils.merge_payees(text, text, text);
drop function if exists utils.delete_payee(text, text);
drop function if exists utils.update_payee(text, text, bigint, boolean, text);
drop function if exists utils.create_payee(text, bigint, boolean, text);
drop function if exists utils.get_or_create_payee(text, bigint, text);
