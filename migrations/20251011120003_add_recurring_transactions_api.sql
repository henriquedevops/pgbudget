-- +goose Up
-- Phase 3.2: Add API functions for recurring transactions
-- These public API functions provide a stable interface for managing recurring transactions

-- +goose StatementBegin
-- public api function to add a recurring transaction
create or replace function api.add_recurring_transaction(
    p_ledger_uuid text,
    p_description text,
    p_amount bigint,
    p_frequency text,
    p_start_date date,
    p_end_date date default null,
    p_account_uuid text default null,
    p_category_uuid text default null,
    p_transaction_type text default 'outflow',
    p_auto_create boolean default false,
    p_enabled boolean default true
) returns text as $$
declare
    v_recurring_id int;
    v_recurring_uuid text;
begin
    -- call the utils function
    select utils.add_recurring_transaction(
        p_ledger_uuid,
        p_description,
        p_amount,
        p_frequency,
        p_start_date,
        p_end_date,
        p_account_uuid,
        p_category_uuid,
        p_transaction_type,
        p_auto_create,
        p_enabled
    ) into v_recurring_id;

    -- get the uuid of the created recurring transaction
    select uuid into v_recurring_uuid
    from data.recurring_transactions
    where id = v_recurring_id;

    return v_recurring_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to update a recurring transaction
create or replace function api.update_recurring_transaction(
    p_recurring_uuid text,
    p_description text,
    p_amount bigint,
    p_frequency text,
    p_next_date date,
    p_end_date date default null,
    p_account_uuid text default null,
    p_category_uuid text default null,
    p_transaction_type text default 'outflow',
    p_auto_create boolean default false,
    p_enabled boolean default true
) returns text as $$
declare
    v_recurring_id int;
begin
    -- call the utils function
    select utils.update_recurring_transaction(
        p_recurring_uuid,
        p_description,
        p_amount,
        p_frequency,
        p_next_date,
        p_end_date,
        p_account_uuid,
        p_category_uuid,
        p_transaction_type,
        p_auto_create,
        p_enabled
    ) into v_recurring_id;

    return p_recurring_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to delete a recurring transaction
create or replace function api.delete_recurring_transaction(
    p_recurring_uuid text
) returns boolean as $$
declare
    v_deleted boolean;
begin
    select utils.delete_recurring_transaction(p_recurring_uuid) into v_deleted;
    return v_deleted;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to create a transaction from a recurring template
create or replace function api.create_from_recurring(
    p_recurring_uuid text,
    p_date date default null
) returns text as $$
declare
    v_transaction_id int;
    v_transaction_uuid text;
begin
    -- call the utils function
    select utils.create_from_recurring(p_recurring_uuid, p_date) into v_transaction_id;

    -- get the uuid of the created transaction
    select uuid into v_transaction_uuid
    from data.transactions
    where id = v_transaction_id;

    return v_transaction_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to get all recurring transactions for a ledger
create or replace function api.get_recurring_transactions(
    p_ledger_uuid text
) returns table (
    recurring_uuid text,
    description text,
    amount bigint,
    frequency text,
    next_date date,
    end_date date,
    account_uuid text,
    account_name text,
    category_uuid text,
    category_name text,
    transaction_type text,
    auto_create boolean,
    enabled boolean,
    created_at timestamptz,
    updated_at timestamptz
) as $$
begin
    return query
    select
        rt.uuid as recurring_uuid,
        rt.description,
        rt.amount,
        rt.frequency,
        rt.next_date,
        rt.end_date,
        a.uuid as account_uuid,
        a.name as account_name,
        c.uuid as category_uuid,
        c.name as category_name,
        rt.transaction_type,
        rt.auto_create,
        rt.enabled,
        rt.created_at,
        rt.updated_at
    from data.recurring_transactions rt
    join data.ledgers l on l.id = rt.ledger_id
    join data.accounts a on a.id = rt.account_id
    left join data.accounts c on c.id = rt.category_id
    where l.uuid = p_ledger_uuid
      and rt.user_data = utils.get_user()
    order by rt.next_date, rt.description;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to get due recurring transactions
create or replace function api.get_due_recurring_transactions(
    p_ledger_uuid text,
    p_as_of_date date default current_date
) returns table (
    recurring_uuid text,
    description text,
    amount bigint,
    frequency text,
    next_date date,
    account_name text,
    category_name text,
    transaction_type text,
    auto_create boolean
) as $$
begin
    return query
    select * from utils.get_due_recurring_transactions(p_ledger_uuid, p_as_of_date);
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- public api function to get a single recurring transaction by uuid
create or replace function api.get_recurring_transaction(
    p_recurring_uuid text
) returns table (
    recurring_uuid text,
    description text,
    amount bigint,
    frequency text,
    next_date date,
    end_date date,
    account_uuid text,
    account_name text,
    category_uuid text,
    category_name text,
    transaction_type text,
    auto_create boolean,
    enabled boolean,
    created_at timestamptz,
    updated_at timestamptz
) as $$
begin
    return query
    select
        rt.uuid as recurring_uuid,
        rt.description,
        rt.amount,
        rt.frequency,
        rt.next_date,
        rt.end_date,
        a.uuid as account_uuid,
        a.name as account_name,
        c.uuid as category_uuid,
        c.name as category_name,
        rt.transaction_type,
        rt.auto_create,
        rt.enabled,
        rt.created_at,
        rt.updated_at
    from data.recurring_transactions rt
    join data.accounts a on a.id = rt.account_id
    left join data.accounts c on c.id = rt.category_id
    where rt.uuid = p_recurring_uuid
      and rt.user_data = utils.get_user();
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
-- Remove API functions for recurring transactions

-- +goose StatementBegin
drop function if exists api.get_recurring_transaction(text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.get_due_recurring_transactions(text, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.get_recurring_transactions(text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.create_from_recurring(text, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.delete_recurring_transaction(text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.update_recurring_transaction(text, text, bigint, text, date, date, text, text, text, boolean, boolean);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.add_recurring_transaction(text, text, bigint, text, date, date, text, text, text, boolean, boolean);
-- +goose StatementEnd
