-- +goose Up
-- API view and CRUD functions for payroll deductions

-- API view for payroll deductions
create or replace view api.payroll_deductions as
select
    d.uuid,
    d.name,
    d.description,
    d.deduction_type,
    d.is_fixed_amount,
    d.fixed_amount,
    d.estimated_amount,
    d.is_percentage,
    d.percentage_value,
    d.percentage_base,
    d.currency,
    d.frequency,
    d.occurrence_months,
    d.start_date,
    d.end_date,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
    d.employer_name,
    d.group_tag,
    d.is_active,
    d.notes,
    d.metadata,
    d.created_at,
    d.updated_at,
    l.uuid as ledger_uuid
from data.payroll_deductions d
left join data.ledgers l on l.id = d.ledger_id
left join data.accounts c on c.id = d.default_category_id
where d.user_data = utils.get_user();

-- +goose StatementBegin
-- Create payroll deduction
create or replace function api.create_payroll_deduction(
    p_ledger_uuid text,
    p_name text,
    p_deduction_type text,
    p_start_date date,
    p_is_fixed_amount boolean default true,
    p_fixed_amount numeric default null,
    p_estimated_amount numeric default null,
    p_is_percentage boolean default false,
    p_percentage_value numeric default null,
    p_percentage_base text default null,
    p_description text default null,
    p_currency text default 'BRL',
    p_frequency text default 'monthly',
    p_occurrence_months integer[] default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_notes text default null
) returns setof api.payroll_deductions as $$
declare
    v_ledger_id bigint;
    v_category_id bigint;
    v_deduction_uuid text;
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
    insert into data.payroll_deductions (
        ledger_id, name, description, deduction_type,
        is_fixed_amount, fixed_amount, estimated_amount,
        is_percentage, percentage_value, percentage_base,
        currency, frequency, occurrence_months,
        start_date, end_date, default_category_id,
        employer_name, group_tag, notes
    ) values (
        v_ledger_id, p_name, p_description, p_deduction_type,
        p_is_fixed_amount, p_fixed_amount, p_estimated_amount,
        p_is_percentage, p_percentage_value, p_percentage_base,
        p_currency, p_frequency, p_occurrence_months,
        p_start_date, p_end_date, v_category_id,
        p_employer_name, p_group_tag, p_notes
    ) returning uuid into v_deduction_uuid;

    return query
    select * from api.payroll_deductions
    where uuid = v_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Update payroll deduction
create or replace function api.update_payroll_deduction(
    p_deduction_uuid text,
    p_name text default null,
    p_description text default null,
    p_deduction_type text default null,
    p_is_fixed_amount boolean default null,
    p_fixed_amount numeric default null,
    p_estimated_amount numeric default null,
    p_is_percentage boolean default null,
    p_percentage_value numeric default null,
    p_percentage_base text default null,
    p_currency text default null,
    p_frequency text default null,
    p_occurrence_months integer[] default null,
    p_start_date date default null,
    p_end_date date default null,
    p_default_category_uuid text default null,
    p_employer_name text default null,
    p_group_tag text default null,
    p_is_active boolean default null,
    p_notes text default null
) returns setof api.payroll_deductions as $$
declare
    v_deduction_id bigint;
    v_category_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate ownership
    select id into v_deduction_id
    from data.payroll_deductions
    where uuid = p_deduction_uuid
      and user_data = v_user_data;

    if v_deduction_id is null then
        raise exception 'Payroll deduction not found';
    end if;

    -- Resolve category UUID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = v_user_data;
    end if;

    update data.payroll_deductions
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        deduction_type = coalesce(p_deduction_type, deduction_type),
        is_fixed_amount = coalesce(p_is_fixed_amount, is_fixed_amount),
        fixed_amount = coalesce(p_fixed_amount, fixed_amount),
        estimated_amount = coalesce(p_estimated_amount, estimated_amount),
        is_percentage = coalesce(p_is_percentage, is_percentage),
        percentage_value = coalesce(p_percentage_value, percentage_value),
        percentage_base = coalesce(p_percentage_base, percentage_base),
        currency = coalesce(p_currency, currency),
        frequency = coalesce(p_frequency, frequency),
        occurrence_months = coalesce(p_occurrence_months, occurrence_months),
        start_date = coalesce(p_start_date, start_date),
        end_date = coalesce(p_end_date, end_date),
        default_category_id = coalesce(v_category_id, default_category_id),
        employer_name = coalesce(p_employer_name, employer_name),
        group_tag = coalesce(p_group_tag, group_tag),
        is_active = coalesce(p_is_active, is_active),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_deduction_id;

    return query
    select * from api.payroll_deductions
    where uuid = p_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Delete payroll deduction
create or replace function api.delete_payroll_deduction(
    p_deduction_uuid text
) returns boolean as $$
declare
    v_deduction_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    select id into v_deduction_id
    from data.payroll_deductions
    where uuid = p_deduction_uuid
      and user_data = v_user_data;

    if v_deduction_id is null then
        raise exception 'Payroll deduction not found';
    end if;

    delete from data.payroll_deductions
    where id = v_deduction_id;

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get all payroll deductions for a ledger
create or replace function api.get_payroll_deductions(
    p_ledger_uuid text
) returns setof api.payroll_deductions as $$
begin
    return query
    select * from api.payroll_deductions
    where ledger_uuid = p_ledger_uuid
    order by employer_name nulls last, deduction_type, name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get a single payroll deduction
create or replace function api.get_payroll_deduction(
    p_deduction_uuid text
) returns setof api.payroll_deductions as $$
begin
    return query
    select * from api.payroll_deductions
    where uuid = p_deduction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists api.get_payroll_deduction(text);
drop function if exists api.get_payroll_deductions(text);
drop function if exists api.delete_payroll_deduction(text);
drop function if exists api.update_payroll_deduction(text, text, text, text, boolean, numeric, numeric, boolean, numeric, text, text, text, integer[], date, date, text, text, text, boolean, text);
drop function if exists api.create_payroll_deduction(text, text, text, date, boolean, numeric, numeric, boolean, numeric, text, text, text, text, integer[], date, text, text, text, text);
drop view if exists api.payroll_deductions;
