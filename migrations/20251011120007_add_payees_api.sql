-- +goose Up
-- API functions for payee management (Phase 3.3)

-- API view for payees
create or replace view api.payees as
select
    p.uuid,
    p.name,
    p.default_category_id,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    p.auto_categorize,
    (
        select count(*)
        from data.transactions t
        where t.payee_id = p.id
          and t.deleted_at is null
    ) as transaction_count,
    (
        select max(t.date)
        from data.transactions t
        where t.payee_id = p.id
          and t.deleted_at is null
    ) as last_used,
    p.created_at,
    p.updated_at
from data.payees p
left join data.accounts c on c.id = p.default_category_id
where p.user_data = utils.get_user()
  and p.merged_into_id is null;

-- Create payee
create or replace function api.create_payee(
    p_name text,
    p_default_category_uuid text default null,
    p_auto_categorize boolean default true
) returns setof api.payees as $$
declare
    v_category_id bigint;
    v_payee_uuid text;
begin
    -- Resolve category UUID to ID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = utils.get_user()
          and type = 'equity'
          and name not in ('Income', 'Unassigned');

        if v_category_id is null then
            raise exception 'Category not found';
        end if;
    end if;

    -- Create payee
    v_payee_uuid := utils.create_payee(
        p_name := p_name,
        p_default_category_id := v_category_id,
        p_auto_categorize := p_auto_categorize
    );

    -- Return created payee
    return query
    select * from api.payees
    where uuid = v_payee_uuid;
end;
$$ language plpgsql security definer;

-- Update payee
create or replace function api.update_payee(
    p_payee_uuid text,
    p_name text default null,
    p_default_category_uuid text default null,
    p_auto_categorize boolean default null
) returns setof api.payees as $$
declare
    v_category_id bigint;
begin
    -- Resolve category UUID to ID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = utils.get_user()
          and type = 'equity'
          and name not in ('Income', 'Unassigned');

        if v_category_id is null then
            raise exception 'Category not found';
        end if;
    end if;

    -- Update payee
    perform utils.update_payee(
        p_payee_uuid := p_payee_uuid,
        p_name := p_name,
        p_default_category_id := v_category_id,
        p_auto_categorize := p_auto_categorize
    );

    -- Return updated payee
    return query
    select * from api.payees
    where uuid = p_payee_uuid;
end;
$$ language plpgsql security definer;

-- Delete payee
create or replace function api.delete_payee(
    p_payee_uuid text
) returns boolean as $$
begin
    return utils.delete_payee(p_payee_uuid);
end;
$$ language plpgsql security definer;

-- Merge payees
create or replace function api.merge_payees(
    p_source_payee_uuid text,
    p_target_payee_uuid text
) returns boolean as $$
begin
    return utils.merge_payees(
        p_source_payee_uuid := p_source_payee_uuid,
        p_target_payee_uuid := p_target_payee_uuid
    );
end;
$$ language plpgsql security definer;

-- Get payee by UUID
create or replace function api.get_payee(
    p_payee_uuid text
) returns setof api.payees as $$
begin
    return query
    select * from api.payees
    where uuid = p_payee_uuid;
end;
$$ language plpgsql security definer;

-- Get all payees
create or replace function api.get_payees()
returns setof api.payees as $$
begin
    return query
    select * from api.payees
    order by name;
end;
$$ language plpgsql security definer;

-- Search payees
create or replace function api.search_payees(
    p_search text
) returns table (
    uuid text,
    name text,
    default_category_uuid text,
    default_category_name text,
    auto_categorize boolean,
    transaction_count bigint,
    last_used timestamptz
) as $$
begin
    return query
    select
        p.uuid,
        p.name,
        p.default_category_uuid,
        p.default_category_name,
        p.auto_categorize,
        p.transaction_count::bigint,
        p.last_used
    from utils.search_payees(p_search) as p;
end;
$$ language plpgsql security definer;

-- +goose Down
drop function if exists api.search_payees(text);
drop function if exists api.get_payees();
drop function if exists api.get_payee(text);
drop function if exists api.merge_payees(text, text);
drop function if exists api.delete_payee(text);
drop function if exists api.update_payee(text, text, text, boolean);
drop function if exists api.create_payee(text, text, boolean);
drop view if exists api.payees;
