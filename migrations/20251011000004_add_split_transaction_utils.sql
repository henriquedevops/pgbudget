-- +goose Up
-- +goose StatementBegin

-- function to add a split transaction
-- a split transaction divides a single transaction amount across multiple categories
-- e.g., grocery store purchase: $50 groceries, $20 household items, $10 personal care
create or replace function utils.add_split_transaction(
    p_ledger_uuid text,
    p_date timestamptz,
    p_description text,
    p_type text, -- 'inflow' or 'outflow'
    p_total_amount bigint,
    p_account_uuid text, -- the bank account or credit card
    p_splits jsonb, -- array of {category_uuid: text, amount: bigint, memo: text}
    p_user_data text = utils.get_user()
) returns int as
$$
declare
    v_ledger_id             int;
    v_account_id            int;
    v_account_internal_type text;
    v_transaction_id        int;
    v_split                 jsonb;
    v_split_total           bigint := 0;
    v_category_id           int;
    v_category_uuid         text;
    v_split_amount          bigint;
    v_split_memo            text;
    v_debit_account_id      int;
    v_credit_account_id     int;
    v_income_account_id     int;
begin
    -- validate inputs early for fast failure
    if p_total_amount <= 0 then
        raise exception 'Transaction amount must be positive: %', p_total_amount;
    end if;

    if p_type not in ('inflow', 'outflow') then
        raise exception 'Invalid transaction type: %. Must be either "inflow" or "outflow"', p_type;
    end if;

    if jsonb_array_length(p_splits) = 0 then
        raise exception 'Split transaction must have at least one split';
    end if;

    -- find the ledger_id from uuid and validate ownership
    select l.id into v_ledger_id
      from data.ledgers l
     where l.uuid = p_ledger_uuid
       and l.user_data = p_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger with UUID % not found for current user', p_ledger_uuid;
    end if;

    -- find the account_id and internal_type in one query
    select a.id, a.internal_type
      into v_account_id, v_account_internal_type
      from data.accounts a
     where a.uuid = p_account_uuid
       and a.ledger_id = v_ledger_id
       and a.user_data = p_user_data;

    if v_account_id is null then
        raise exception 'Account with UUID % not found in ledger % for current user',
                       p_account_uuid, p_ledger_uuid;
    end if;

    -- for inflows, we'll need the Income account
    if p_type = 'inflow' then
        select a.id into v_income_account_id
          from data.accounts a
         where a.ledger_id = v_ledger_id
           and a.user_data = p_user_data
           and a.name = 'Income'
           and a.type = 'equity';

        if v_income_account_id is null then
            raise exception 'Could not find "Income" account in ledger % for current user',
                           p_ledger_uuid;
        end if;
    end if;

    -- validate splits total equals transaction amount
    for v_split in select * from jsonb_array_elements(p_splits)
    loop
        v_split_amount := (v_split->>'amount')::bigint;

        if v_split_amount <= 0 then
            raise exception 'Split amount must be positive: %', v_split_amount;
        end if;

        v_split_total := v_split_total + v_split_amount;
    end loop;

    if v_split_total != p_total_amount then
        raise exception 'Sum of splits (%) must equal total transaction amount (%)',
                       v_split_total, p_total_amount;
    end if;

    -- determine the parent transaction accounts based on type
    -- for split transactions, the parent transaction goes to/from Income or Unassigned
    case
        when v_account_internal_type = 'asset_like' and p_type = 'inflow' then
            -- inflow to asset: debit asset (increase), credit Income (increase)
            v_debit_account_id := v_account_id;
            v_credit_account_id := v_income_account_id;

        when v_account_internal_type = 'asset_like' and p_type = 'outflow' then
            -- outflow from asset: this will be handled via splits
            -- parent transaction is just a placeholder, actual debits/credits via splits
            v_debit_account_id := null;
            v_credit_account_id := v_account_id;

        when v_account_internal_type = 'liability_like' and p_type = 'inflow' then
            -- inflow to liability: this will be handled via splits
            v_debit_account_id := null;
            v_credit_account_id := v_account_id;

        when v_account_internal_type = 'liability_like' and p_type = 'outflow' then
            -- outflow from liability: debit liability (decrease), handled via splits
            v_debit_account_id := v_account_id;
            v_credit_account_id := null;

        else
            raise exception 'Unsupported combination: account_type=% and transaction_type=%',
                           v_account_internal_type, p_type;
    end case;

    -- create the parent transaction
    -- for split transactions, we use a special metadata flag
    insert into data.transactions (
        ledger_id,
        date,
        description,
        debit_account_id,
        credit_account_id,
        amount,
        metadata,
        user_data
    )
    values (
        v_ledger_id,
        p_date,
        p_description,
        coalesce(v_debit_account_id, v_account_id), -- use account_id as placeholder if null
        coalesce(v_credit_account_id, v_account_id), -- use account_id as placeholder if null
        p_total_amount,
        jsonb_build_object('is_split', true),
        p_user_data
    )
    returning id into v_transaction_id;

    -- now create the splits and their corresponding transactions
    for v_split in select * from jsonb_array_elements(p_splits)
    loop
        v_category_uuid := v_split->>'category_uuid';
        v_split_amount := (v_split->>'amount')::bigint;
        v_split_memo := v_split->>'memo';

        -- find the category
        select a.id into v_category_id
          from data.accounts a
         where a.uuid = v_category_uuid
           and a.ledger_id = v_ledger_id
           and a.user_data = p_user_data;

        if v_category_id is null then
            raise exception 'Category with UUID % not found in ledger % for current user',
                           v_category_uuid, p_ledger_uuid;
        end if;

        -- insert the split record
        insert into data.transaction_splits (
            parent_transaction_id,
            category_id,
            amount,
            memo,
            user_data
        )
        values (
            v_transaction_id,
            v_category_id,
            v_split_amount,
            v_split_memo,
            p_user_data
        );

        -- create the actual transaction for this split
        -- this ensures the accounting entries are correct
        case
            when v_account_internal_type = 'asset_like' and p_type = 'outflow' then
                -- outflow from asset: debit category (decrease), credit already recorded in parent
                insert into data.transactions (
                    ledger_id,
                    date,
                    description,
                    debit_account_id,
                    credit_account_id,
                    amount,
                    metadata,
                    user_data
                )
                values (
                    v_ledger_id,
                    p_date,
                    coalesce(v_split_memo, p_description),
                    v_category_id,
                    v_account_id,
                    v_split_amount,
                    jsonb_build_object('parent_transaction_id', v_transaction_id, 'is_split_child', true),
                    p_user_data
                );

            when v_account_internal_type = 'liability_like' and p_type = 'inflow' then
                -- inflow to liability: credit already recorded, debit category
                insert into data.transactions (
                    ledger_id,
                    date,
                    description,
                    debit_account_id,
                    credit_account_id,
                    amount,
                    metadata,
                    user_data
                )
                values (
                    v_ledger_id,
                    p_date,
                    coalesce(v_split_memo, p_description),
                    v_category_id,
                    v_account_id,
                    v_split_amount,
                    jsonb_build_object('parent_transaction_id', v_transaction_id, 'is_split_child', true),
                    p_user_data
                );

            when v_account_internal_type = 'liability_like' and p_type = 'outflow' then
                -- outflow from liability: debit already recorded, credit category
                insert into data.transactions (
                    ledger_id,
                    date,
                    description,
                    debit_account_id,
                    credit_account_id,
                    amount,
                    metadata,
                    user_data
                )
                values (
                    v_ledger_id,
                    p_date,
                    coalesce(v_split_memo, p_description),
                    v_account_id,
                    v_category_id,
                    v_split_amount,
                    jsonb_build_object('parent_transaction_id', v_transaction_id, 'is_split_child', true),
                    p_user_data
                );

            when v_account_internal_type = 'asset_like' and p_type = 'inflow' then
                -- inflow to asset: parent already has debit to asset, now credit each category
                insert into data.transactions (
                    ledger_id,
                    date,
                    description,
                    debit_account_id,
                    credit_account_id,
                    amount,
                    metadata,
                    user_data
                )
                values (
                    v_ledger_id,
                    p_date,
                    coalesce(v_split_memo, p_description),
                    v_account_id,
                    v_category_id,
                    v_split_amount,
                    jsonb_build_object('parent_transaction_id', v_transaction_id, 'is_split_child', true),
                    p_user_data
                );
        end case;
    end loop;

    return v_transaction_id;
end;
$$ language plpgsql security definer;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

drop function if exists utils.add_split_transaction(text, timestamptz, text, text, bigint, text, jsonb, text);

-- +goose StatementEnd
