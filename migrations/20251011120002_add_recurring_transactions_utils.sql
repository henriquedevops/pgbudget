-- +goose Up
-- Phase 3.2: Add utils functions for recurring transaction operations
-- These internal functions handle the logic for creating and managing recurring transactions

-- +goose StatementBegin
-- function to calculate the next occurrence date based on frequency
create or replace function utils.calculate_next_occurrence(
    p_current_date date,
    p_frequency text
) returns date as $$
declare
    v_next_date date;
begin
    case p_frequency
        when 'daily' then
            v_next_date := p_current_date + interval '1 day';
        when 'weekly' then
            v_next_date := p_current_date + interval '1 week';
        when 'biweekly' then
            v_next_date := p_current_date + interval '2 weeks';
        when 'monthly' then
            v_next_date := p_current_date + interval '1 month';
        when 'yearly' then
            v_next_date := p_current_date + interval '1 year';
        else
            raise exception 'Invalid frequency: %. Must be daily, weekly, biweekly, monthly, or yearly', p_frequency;
    end case;

    return v_next_date;
end;
$$ language plpgsql immutable;
-- +goose StatementEnd

-- +goose StatementBegin
-- function to add a recurring transaction
create or replace function utils.add_recurring_transaction(
    p_ledger_uuid text,
    p_description text,
    p_amount bigint,
    p_frequency text,
    p_start_date date,
    p_end_date date,
    p_account_uuid text,
    p_category_uuid text,
    p_transaction_type text,
    p_auto_create boolean,
    p_enabled boolean
) returns int as $$
declare
    v_ledger_id int;
    v_account_id int;
    v_category_id int;
    v_recurring_id int;
begin
    -- validate transaction type
    if p_transaction_type not in ('inflow', 'outflow') then
        raise exception 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_transaction_type;
    end if;

    -- validate frequency
    if p_frequency not in ('daily', 'weekly', 'biweekly', 'monthly', 'yearly') then
        raise exception 'Invalid frequency: %. Must be daily, weekly, biweekly, monthly, or yearly', p_frequency;
    end if;

    -- validate amount
    if p_amount <= 0 then
        raise exception 'Amount must be greater than zero';
    end if;

    -- validate dates
    if p_end_date is not null and p_end_date < p_start_date then
        raise exception 'End date must be after start date';
    end if;

    -- get ledger id
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = utils.get_user();

    if v_ledger_id is null then
        raise exception 'Ledger not found: %', p_ledger_uuid;
    end if;

    -- get account id
    select id into v_account_id
    from data.accounts
    where uuid = p_account_uuid
      and ledger_id = v_ledger_id
      and user_data = utils.get_user();

    if v_account_id is null then
        raise exception 'Account not found: %', p_account_uuid;
    end if;

    -- get category id (can be null)
    if p_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_category_uuid
          and ledger_id = v_ledger_id
          and type = 'equity'
          and user_data = utils.get_user();

        if v_category_id is null then
            raise exception 'Category not found: %', p_category_uuid;
        end if;
    end if;

    -- insert recurring transaction
    insert into data.recurring_transactions (
        ledger_id,
        description,
        amount,
        frequency,
        next_date,
        end_date,
        account_id,
        category_id,
        transaction_type,
        auto_create,
        enabled,
        user_data
    ) values (
        v_ledger_id,
        p_description,
        p_amount,
        p_frequency,
        p_start_date,
        p_end_date,
        v_account_id,
        v_category_id,
        p_transaction_type,
        p_auto_create,
        p_enabled,
        utils.get_user()
    ) returning id into v_recurring_id;

    return v_recurring_id;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- function to update a recurring transaction
create or replace function utils.update_recurring_transaction(
    p_recurring_uuid text,
    p_description text,
    p_amount bigint,
    p_frequency text,
    p_next_date date,
    p_end_date date,
    p_account_uuid text,
    p_category_uuid text,
    p_transaction_type text,
    p_auto_create boolean,
    p_enabled boolean
) returns int as $$
declare
    v_recurring_id int;
    v_ledger_id int;
    v_account_id int;
    v_category_id int;
begin
    -- validate transaction type
    if p_transaction_type not in ('inflow', 'outflow') then
        raise exception 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_transaction_type;
    end if;

    -- validate frequency
    if p_frequency not in ('daily', 'weekly', 'biweekly', 'monthly', 'yearly') then
        raise exception 'Invalid frequency: %. Must be daily, weekly, biweekly, monthly, or yearly', p_frequency;
    end if;

    -- validate amount
    if p_amount <= 0 then
        raise exception 'Amount must be greater than zero';
    end if;

    -- validate dates
    if p_end_date is not null and p_end_date < p_next_date then
        raise exception 'End date must be after next date';
    end if;

    -- get recurring transaction
    select id, ledger_id into v_recurring_id, v_ledger_id
    from data.recurring_transactions
    where uuid = p_recurring_uuid
      and user_data = utils.get_user();

    if v_recurring_id is null then
        raise exception 'Recurring transaction not found: %', p_recurring_uuid;
    end if;

    -- get account id
    select id into v_account_id
    from data.accounts
    where uuid = p_account_uuid
      and ledger_id = v_ledger_id
      and user_data = utils.get_user();

    if v_account_id is null then
        raise exception 'Account not found: %', p_account_uuid;
    end if;

    -- get category id (can be null)
    if p_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_category_uuid
          and ledger_id = v_ledger_id
          and type = 'equity'
          and user_data = utils.get_user();

        if v_category_id is null then
            raise exception 'Category not found: %', p_category_uuid;
        end if;
    end if;

    -- update recurring transaction
    update data.recurring_transactions
    set description = p_description,
        amount = p_amount,
        frequency = p_frequency,
        next_date = p_next_date,
        end_date = p_end_date,
        account_id = v_account_id,
        category_id = v_category_id,
        transaction_type = p_transaction_type,
        auto_create = p_auto_create,
        enabled = p_enabled,
        updated_at = current_timestamp
    where id = v_recurring_id;

    return v_recurring_id;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- function to delete a recurring transaction
create or replace function utils.delete_recurring_transaction(
    p_recurring_uuid text
) returns boolean as $$
declare
    v_deleted boolean;
begin
    delete from data.recurring_transactions
    where uuid = p_recurring_uuid
      and user_data = utils.get_user();

    v_deleted := found;

    if not v_deleted then
        raise exception 'Recurring transaction not found: %', p_recurring_uuid;
    end if;

    return v_deleted;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- function to create a transaction from a recurring template
create or replace function utils.create_from_recurring(
    p_recurring_uuid text,
    p_date date default null
) returns int as $$
declare
    v_recurring record;
    v_transaction_id int;
    v_actual_date date;
    v_next_date date;
    v_account record;
    v_category record;
    v_unassigned_id int;
begin
    -- get recurring transaction
    select * into v_recurring
    from data.recurring_transactions
    where uuid = p_recurring_uuid
      and user_data = utils.get_user()
      and enabled = true;

    if v_recurring is null then
        raise exception 'Recurring transaction not found or not enabled: %', p_recurring_uuid;
    end if;

    -- check if end date has passed
    if v_recurring.end_date is not null and v_recurring.end_date < current_date then
        raise exception 'Recurring transaction has ended';
    end if;

    -- use provided date or next_date
    v_actual_date := coalesce(p_date, v_recurring.next_date);

    -- get account details
    select * into v_account
    from data.accounts
    where id = v_recurring.account_id;

    -- get category or use unassigned
    if v_recurring.category_id is not null then
        select * into v_category
        from data.accounts
        where id = v_recurring.category_id;
    else
        -- get unassigned category
        select id into v_unassigned_id
        from data.accounts
        where ledger_id = v_recurring.ledger_id
          and name = 'Unassigned'
          and type = 'equity'
          and user_data = utils.get_user();

        if v_unassigned_id is null then
            raise exception 'Unassigned category not found for ledger';
        end if;

        v_recurring.category_id := v_unassigned_id;
    end if;

    -- create the transaction based on type
    if v_recurring.transaction_type = 'inflow' then
        -- inflow: debit account, credit category
        insert into data.transactions (
            ledger_id,
            date,
            description,
            debit_account_id,
            credit_account_id,
            amount,
            user_data
        ) values (
            v_recurring.ledger_id,
            v_actual_date::timestamptz,
            v_recurring.description,
            v_recurring.account_id,  -- debit the bank account (increase asset)
            v_recurring.category_id, -- credit the category (increase equity/income)
            v_recurring.amount,
            utils.get_user()
        ) returning id into v_transaction_id;
    else
        -- outflow: debit category, credit account
        insert into data.transactions (
            ledger_id,
            date,
            description,
            debit_account_id,
            credit_account_id,
            amount,
            user_data
        ) values (
            v_recurring.ledger_id,
            v_actual_date::timestamptz,
            v_recurring.description,
            v_recurring.category_id, -- debit the category (decrease equity)
            v_recurring.account_id,  -- credit the bank account (decrease asset or increase liability)
            v_recurring.amount,
            utils.get_user()
        ) returning id into v_transaction_id;
    end if;

    -- calculate and update next occurrence
    v_next_date := utils.calculate_next_occurrence(v_actual_date, v_recurring.frequency);

    -- don't update next_date if it would go past end_date
    if v_recurring.end_date is null or v_next_date <= v_recurring.end_date then
        update data.recurring_transactions
        set next_date = v_next_date,
            updated_at = current_timestamp
        where id = v_recurring.id;
    else
        -- disable the recurring transaction if we've reached the end
        update data.recurring_transactions
        set enabled = false,
            updated_at = current_timestamp
        where id = v_recurring.id;
    end if;

    return v_transaction_id;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- function to get due recurring transactions
create or replace function utils.get_due_recurring_transactions(
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
    select
        rt.uuid as recurring_uuid,
        rt.description,
        rt.amount,
        rt.frequency,
        rt.next_date,
        a.name as account_name,
        c.name as category_name,
        rt.transaction_type,
        rt.auto_create
    from data.recurring_transactions rt
    join data.ledgers l on l.id = rt.ledger_id
    join data.accounts a on a.id = rt.account_id
    left join data.accounts c on c.id = rt.category_id
    where l.uuid = p_ledger_uuid
      and rt.enabled = true
      and rt.next_date <= p_as_of_date
      and (rt.end_date is null or rt.end_date >= p_as_of_date)
      and rt.user_data = utils.get_user()
    order by rt.next_date, rt.description;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
-- Remove utils functions for recurring transactions

-- +goose StatementBegin
drop function if exists utils.get_due_recurring_transactions(text, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.create_from_recurring(text, date);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.delete_recurring_transaction(text);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.update_recurring_transaction(text, text, bigint, text, date, date, text, text, text, boolean, boolean);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.add_recurring_transaction(text, text, bigint, text, date, date, text, text, text, boolean, boolean);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists utils.calculate_next_occurrence(date, text);
-- +goose StatementEnd
