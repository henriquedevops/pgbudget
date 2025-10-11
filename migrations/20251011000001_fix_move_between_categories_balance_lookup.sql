-- +goose Up
-- Fix move_between_categories to use correct balance lookup
-- The function was referencing data.balances which doesn't exist
-- Should use balance_snapshots or the get_account_balance function

-- +goose StatementBegin
create or replace function utils.move_between_categories(
    p_ledger_uuid text,
    p_from_category_uuid text,
    p_to_category_uuid text,
    p_amount bigint,
    p_date timestamptz,
    p_description text,
    p_user_data text default utils.get_user()
) returns text as $func$
declare
    v_ledger_id bigint;
    v_from_category_id bigint;
    v_to_category_id bigint;
    v_from_category_name text;
    v_to_category_name text;
    v_from_balance bigint;
    v_transaction_uuid text;
begin
    -- validate amount is positive
    if p_amount <= 0 then
        raise exception 'Move amount must be positive: %', p_amount;
    end if;

    -- validate from and to categories are different
    if p_from_category_uuid = p_to_category_uuid then
        raise exception 'Source and destination categories must be different';
    end if;

    -- get ledger id
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid and l.user_data = p_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger with UUID % not found for current user', p_ledger_uuid;
    end if;

    -- get source category id and name
    select a.id, a.name into v_from_category_id, v_from_category_name
    from data.accounts a
    where a.uuid = p_from_category_uuid
      and a.ledger_id = v_ledger_id
      and a.user_data = p_user_data
      and a.type = 'equity';

    if v_from_category_id is null then
        raise exception 'Source category with UUID % not found or not a budget category', p_from_category_uuid;
    end if;

    -- get destination category id and name
    select a.id, a.name into v_to_category_id, v_to_category_name
    from data.accounts a
    where a.uuid = p_to_category_uuid
      and a.ledger_id = v_ledger_id
      and a.user_data = p_user_data
      and a.type = 'equity';

    if v_to_category_id is null then
        raise exception 'Destination category with UUID % not found or not a budget category', p_to_category_uuid;
    end if;

    -- check source category has sufficient balance using balance_snapshots
    select balance into v_from_balance
    from data.balance_snapshots
    where account_id = v_from_category_id
    order by id desc
    limit 1;

    if v_from_balance is null then
        v_from_balance := 0;
    end if;

    if v_from_balance < p_amount then
        raise exception 'Insufficient funds in category "%". Available: %, Requested: %',
            v_from_category_name, v_from_balance, p_amount;
    end if;

    -- create description if not provided
    if p_description is null or trim(p_description) = '' then
        p_description := format('Move money: %s → %s', v_from_category_name, v_to_category_name);
    end if;

    -- create transaction (debit source category, credit destination category)
    insert into data.transactions (
        ledger_id,
        description,
        date,
        amount,
        debit_account_id,
        credit_account_id,
        user_data
    )
    values (
        v_ledger_id,
        p_description,
        p_date,
        p_amount,
        v_from_category_id,  -- debit (decrease) source
        v_to_category_id,    -- credit (increase) destination
        p_user_data
    )
    returning uuid into v_transaction_uuid;

    return v_transaction_uuid;
end;
$func$ language plpgsql volatile security definer;
-- +goose StatementEnd

-- +goose Down
-- Revert to original (broken) version
-- +goose StatementBegin
create or replace function utils.move_between_categories(
    p_ledger_uuid text,
    p_from_category_uuid text,
    p_to_category_uuid text,
    p_amount bigint,
    p_date timestamptz,
    p_description text,
    p_user_data text default utils.get_user()
) returns text as $func$
declare
    v_ledger_id bigint;
    v_from_category_id bigint;
    v_to_category_id bigint;
    v_from_category_name text;
    v_to_category_name text;
    v_from_balance bigint;
    v_transaction_uuid text;
begin
    -- validate amount is positive
    if p_amount <= 0 then
        raise exception 'Move amount must be positive: %', p_amount;
    end if;

    -- validate from and to categories are different
    if p_from_category_uuid = p_to_category_uuid then
        raise exception 'Source and destination categories must be different';
    end if;

    -- get ledger id
    select l.id into v_ledger_id
    from data.ledgers l
    where l.uuid = p_ledger_uuid and l.user_data = p_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger with UUID % not found for current user', p_ledger_uuid;
    end if;

    -- get source category id and name
    select a.id, a.name into v_from_category_id, v_from_category_name
    from data.accounts a
    where a.uuid = p_from_category_uuid
      and a.ledger_id = v_ledger_id
      and a.user_data = p_user_data
      and a.type = 'equity';

    if v_from_category_id is null then
        raise exception 'Source category with UUID % not found or not a budget category', p_from_category_uuid;
    end if;

    -- get destination category id and name
    select a.id, a.name into v_to_category_id, v_to_category_name
    from data.accounts a
    where a.uuid = p_to_category_uuid
      and a.ledger_id = v_ledger_id
      and a.user_data = p_user_data
      and a.type = 'equity';

    if v_to_category_id is null then
        raise exception 'Destination category with UUID % not found or not a budget category', p_to_category_uuid;
    end if;

    -- check source category has sufficient balance
    select balance into v_from_balance
    from data.balances
    where account_id = v_from_category_id
    order by id desc
    limit 1;

    if v_from_balance is null then
        v_from_balance := 0;
    end if;

    if v_from_balance < p_amount then
        raise exception 'Insufficient funds in category "%". Available: %, Requested: %',
            v_from_category_name, v_from_balance, p_amount;
    end if;

    -- create description if not provided
    if p_description is null or trim(p_description) = '' then
        p_description := format('Move money: %s → %s', v_from_category_name, v_to_category_name);
    end if;

    -- create transaction (debit source category, credit destination category)
    insert into data.transactions (
        ledger_id,
        description,
        date,
        amount,
        debit_account_id,
        credit_account_id,
        user_data
    )
    values (
        v_ledger_id,
        p_description,
        p_date,
        p_amount,
        v_from_category_id,  -- debit (decrease) source
        v_to_category_id,    -- credit (increase) destination
        p_user_data
    )
    returning uuid into v_transaction_uuid;

    return v_transaction_uuid;
end;
$func$ language plpgsql volatile security definer;
-- +goose StatementEnd
