-- +goose Up
-- +goose StatementBegin

-- public api function to add a split transaction
-- this provides a stable public interface for adding transactions split across multiple categories
create or replace function api.add_split_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text, -- 'inflow' or 'outflow'
    p_total_amount bigint,
    p_account_uuid text, -- the bank account or credit card
    p_splits jsonb -- array of {category_uuid: text, amount: bigint, memo: text}
) returns text as $$
declare
    v_transaction_id int;
    v_transaction_uuid text;
begin
    -- validate transaction type
    if p_type not in ('inflow', 'outflow') then
        raise exception 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    end if;

    -- validate splits is an array
    if jsonb_typeof(p_splits) != 'array' then
        raise exception 'Splits must be a JSON array';
    end if;

    -- call the utils function
    select utils.add_split_transaction(
        p_ledger_uuid,
        p_date::timestamptz,
        p_description,
        p_type,
        p_total_amount,
        p_account_uuid,
        p_splits
    ) into v_transaction_id;

    -- get the uuid of the created parent transaction
    select uuid into v_transaction_uuid
    from data.transactions
    where id = v_transaction_id;

    return v_transaction_uuid;
end;
$$ language plpgsql security definer;

-- function to get splits for a transaction
create or replace function api.get_transaction_splits(
    p_transaction_uuid text
) returns table (
    split_uuid text,
    category_uuid text,
    category_name text,
    amount bigint,
    memo text
) as $$
begin
    return query
    select
        ts.uuid as split_uuid,
        a.uuid as category_uuid,
        a.name as category_name,
        ts.amount,
        ts.memo
    from data.transaction_splits ts
    join data.transactions t on t.id = ts.parent_transaction_id
    join data.accounts a on a.id = ts.category_id
    where t.uuid = p_transaction_uuid
      and t.user_data = utils.get_user()
      and ts.user_data = utils.get_user()
    order by ts.id;
end;
$$ language plpgsql security definer;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

drop function if exists api.get_transaction_splits(text);
drop function if exists api.add_split_transaction(text, date, text, text, bigint, text, jsonb);

-- +goose StatementEnd
