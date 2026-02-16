-- +goose Up
-- API view and CRUD functions for projected events

-- API view for projected events
create or replace view api.projected_events as
select
    e.uuid,
    e.name,
    e.description,
    e.event_type,
    e.direction,
    e.amount,
    e.currency,
    e.event_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    e.is_confirmed,
    e.is_realized,
    t.uuid as linked_transaction_uuid,
    e.notes,
    e.metadata,
    e.created_at,
    e.updated_at,
    l.uuid as ledger_uuid
from data.projected_events e
left join data.ledgers l on l.id = e.ledger_id
left join data.accounts c on c.id = e.default_category_id
left join data.transactions t on t.id = e.linked_transaction_id
where e.user_data = utils.get_user();

-- +goose StatementBegin
-- Create projected event
create or replace function api.create_projected_event(
    p_ledger_uuid text,
    p_name text,
    p_amount numeric,
    p_event_date date,
    p_direction text default 'outflow',
    p_event_type text default 'other',
    p_description text default null,
    p_currency text default 'BRL',
    p_default_category_uuid text default null,
    p_is_confirmed boolean default false,
    p_notes text default null
) returns setof api.projected_events as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_event_uuid text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate ledger
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    -- Resolve category UUID
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = v_user_data;
    end if;

    -- Insert
    insert into data.projected_events (
        ledger_id, name, description, event_type, direction,
        amount, currency, event_date, default_category_id,
        is_confirmed, notes
    ) values (
        v_ledger_id, p_name, p_description, p_event_type, p_direction,
        p_amount, p_currency, p_event_date, v_category_id,
        p_is_confirmed, p_notes
    ) returning uuid into v_event_uuid;

    return query
    select * from api.projected_events
    where uuid = v_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Update projected event
create or replace function api.update_projected_event(
    p_event_uuid text,
    p_name text default null,
    p_description text default null,
    p_event_type text default null,
    p_direction text default null,
    p_amount numeric default null,
    p_currency text default null,
    p_event_date date default null,
    p_default_category_uuid text default null,
    p_is_confirmed boolean default null,
    p_is_realized boolean default null,
    p_linked_transaction_uuid text default null,
    p_notes text default null
) returns setof api.projected_events as $$
declare
    v_event_id bigint;
    v_category_id bigint;
    v_transaction_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate ownership
    select id into v_event_id
    from data.projected_events
    where uuid = p_event_uuid
      and user_data = v_user_data;

    if v_event_id is null then
        raise exception 'Projected event not found';
    end if;

    -- Resolve category UUID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = v_user_data;
    end if;

    -- Resolve transaction UUID if provided
    if p_linked_transaction_uuid is not null then
        select id into v_transaction_id
        from data.transactions
        where uuid = p_linked_transaction_uuid
          and user_data = v_user_data;
    end if;

    update data.projected_events
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        event_type = coalesce(p_event_type, event_type),
        direction = coalesce(p_direction, direction),
        amount = coalesce(p_amount, amount),
        currency = coalesce(p_currency, currency),
        event_date = coalesce(p_event_date, event_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        is_confirmed = coalesce(p_is_confirmed, is_confirmed),
        is_realized = coalesce(p_is_realized, is_realized),
        linked_transaction_id = coalesce(v_transaction_id, linked_transaction_id),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_event_id;

    return query
    select * from api.projected_events
    where uuid = p_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Delete projected event
create or replace function api.delete_projected_event(
    p_event_uuid text
) returns boolean as $$
declare
    v_event_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_event_id
    from data.projected_events
    where uuid = p_event_uuid
      and user_data = v_user_data;

    if v_event_id is null then
        raise exception 'Projected event not found';
    end if;

    delete from data.projected_events
    where id = v_event_id;

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get all projected events for a ledger
create or replace function api.get_projected_events(
    p_ledger_uuid text
) returns setof api.projected_events as $$
begin
    return query
    select * from api.projected_events
    where ledger_uuid = p_ledger_uuid
    order by event_date, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get a single projected event
create or replace function api.get_projected_event(
    p_event_uuid text
) returns setof api.projected_events as $$
begin
    return query
    select * from api.projected_events
    where uuid = p_event_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists api.get_projected_event(text);
drop function if exists api.get_projected_events(text);
drop function if exists api.delete_projected_event(text);
drop function if exists api.update_projected_event(text, text, text, text, text, numeric, text, date, text, boolean, boolean, text, text);
drop function if exists api.create_projected_event(text, text, numeric, date, text, text, text, text, text, boolean, text);
drop view if exists api.projected_events;
