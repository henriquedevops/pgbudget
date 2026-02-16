-- +goose Up
-- API view and CRUD functions for income sources

-- API view for income sources
create or replace view api.income_sources as
select
    i.uuid,
    i.name,
    i.description,
    i.income_type,
    i.income_subtype,
    i.amount,
    i.currency,
    i.frequency,
    i.pay_day_of_month,
    i.occurrence_months,
    i.start_date,
    i.end_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    i.employer_name,
    i.group_tag,
    i.is_active,
    i.notes,
    i.metadata,
    i.created_at,
    i.updated_at,
    l.uuid as ledger_uuid
from data.income_sources i
left join data.ledgers l on l.id = i.ledger_id
left join data.accounts c on c.id = i.default_category_id
where i.user_data = utils.get_user();

-- +goose StatementBegin
-- Create income source
create or replace function api.create_income_source(
    p_ledger_uuid text,
    p_name text,
    p_amount numeric,
    p_start_date date,
    p_income_type text default 'salary',
    p_income_subtype text default null,
    p_description text default null,
    p_currency text default 'BRL',
    p_frequency text default 'monthly',
    p_pay_day_of_month integer default null,
    p_occurrence_months integer[] default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_notes text default null
) returns setof api.income_sources as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_income_uuid text;
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
    insert into data.income_sources (
        ledger_id, name, description, income_type, income_subtype,
        amount, currency, frequency, pay_day_of_month, occurrence_months,
        start_date, end_date, default_category_id,
        employer_name, group_tag, notes
    ) values (
        v_ledger_id, p_name, p_description, p_income_type, p_income_subtype,
        p_amount, p_currency, p_frequency, p_pay_day_of_month, p_occurrence_months,
        p_start_date, p_end_date, v_category_id,
        p_employer_name, p_group_tag, p_notes
    ) returning uuid into v_income_uuid;

    return query
    select * from api.income_sources
    where uuid = v_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Update income source
create or replace function api.update_income_source(
    p_income_uuid text,
    p_name text default null,
    p_description text default null,
    p_income_type text default null,
    p_income_subtype text default null,
    p_amount numeric default null,
    p_currency text default null,
    p_frequency text default null,
    p_pay_day_of_month integer default null,
    p_occurrence_months integer[] default null,
    p_start_date date default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_is_active boolean default null,
    p_notes text default null
) returns setof api.income_sources as $$
declare
    v_income_id bigint;
    v_category_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate ownership
    select id into v_income_id
    from data.income_sources
    where uuid = p_income_uuid
      and user_data = v_user_data;

    if v_income_id is null then
        raise exception 'Income source not found';
    end if;

    -- Resolve category UUID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = v_user_data;
    end if;

    update data.income_sources
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        income_type = coalesce(p_income_type, income_type),
        income_subtype = coalesce(p_income_subtype, income_subtype),
        amount = coalesce(p_amount, amount),
        currency = coalesce(p_currency, currency),
        frequency = coalesce(p_frequency, frequency),
        pay_day_of_month = coalesce(p_pay_day_of_month, pay_day_of_month),
        occurrence_months = coalesce(p_occurrence_months, occurrence_months),
        start_date = coalesce(p_start_date, start_date),
        end_date = coalesce(p_end_date, end_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        employer_name = coalesce(p_employer_name, employer_name),
        group_tag = coalesce(p_group_tag, group_tag),
        is_active = coalesce(p_is_active, is_active),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_income_id;

    return query
    select * from api.income_sources
    where uuid = p_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Delete income source
create or replace function api.delete_income_source(
    p_income_uuid text
) returns boolean as $$
declare
    v_income_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_income_id
    from data.income_sources
    where uuid = p_income_uuid
      and user_data = v_user_data;

    if v_income_id is null then
        raise exception 'Income source not found';
    end if;

    delete from data.income_sources
    where id = v_income_id;

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get all income sources for a ledger
create or replace function api.get_income_sources(
    p_ledger_uuid text
) returns setof api.income_sources as $$
begin
    return query
    select * from api.income_sources
    where ledger_uuid = p_ledger_uuid
    order by employer_name nulls last, income_type, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get a single income source
create or replace function api.get_income_source(
    p_income_uuid text
) returns setof api.income_sources as $$
begin
    return query
    select * from api.income_sources
    where uuid = p_income_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists api.get_income_source(text);
drop function if exists api.get_income_sources(text);
drop function if exists api.delete_income_source(text);
drop function if exists api.update_income_source(text, text, text, text, text, numeric, text, text, integer, integer[], date, date, text, text, text, boolean, text);
drop function if exists api.create_income_source(text, text, numeric, date, text, text, text, text, text, integer, integer[], date, text, text, text, text);
drop view if exists api.income_sources;
