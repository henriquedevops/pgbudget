-- +goose Up
-- +goose StatementBegin

-- Fix the inverted credit/debit logic in utils.add_transaction function
-- CORRECT LOGIC:
-- For INCOME (inflow): DEBIT bank account, CREDIT Income account
-- For EXPENSE (outflow): DEBIT expense category, CREDIT bank account

create or replace function utils.add_transaction(
    p_ledger_uuid text,
    p_date timestamptz,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text = null,
    p_user_data text = utils.get_user()
) returns int as
$$
declare
    v_ledger_id             int;
    v_account_id            int;
    v_account_internal_type text;
    v_category_id           int;
    v_transaction_id        int;
    v_debit_account_id      int;
    v_credit_account_id     int;
    v_cleaned_description   text;
    v_income_account_id     int;
begin
    -- validate transaction data using new utility function
    perform utils.validate_transaction_data(p_amount, p_date, p_type);

    -- validate and clean description
    v_cleaned_description := coalesce(trim(p_description), '');
    if char_length(v_cleaned_description) > 500 then
        raise exception 'Transaction description cannot exceed 500 characters. Current length: %',
            char_length(v_cleaned_description);
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

    -- handle category/income account lookup
    if p_type = 'inflow' then
        -- For income, we always credit the Income account
        select a.id into v_income_account_id
          from data.accounts a
         where a.ledger_id = v_ledger_id
           and a.user_data = p_user_data
           and a.name = 'Income'
           and a.type = 'equity';

        if v_income_account_id is null then
            raise exception 'Income account not found in ledger %. This indicates a system error.',
                p_ledger_uuid;
        end if;

        -- CORRECT LOGIC FOR INCOME:
        -- DEBIT: Bank account (receives money)
        -- CREDIT: Income account (source of money)
        v_debit_account_id := v_account_id;
        v_credit_account_id := v_income_account_id;

    else
        -- For outflow (expenses), handle category lookup
        if p_category_uuid is null then
            -- find the "Unassigned" category directly
            select a.id into v_category_id
              from data.accounts a
             where a.ledger_id = v_ledger_id
               and a.user_data = p_user_data
               and a.name = 'Unassigned'
               and a.type = 'equity';

            if v_category_id is null then
                raise exception 'Default "Unassigned" category not found in ledger %. This indicates a system error.',
                    p_ledger_uuid;
            end if;
        else
            -- find the category by UUID
            select a.id into v_category_id
              from data.accounts a
             where a.uuid = p_category_uuid
               and a.ledger_id = v_ledger_id
               and a.user_data = p_user_data
               and a.type = 'equity';

            if v_category_id is null then
                raise exception 'Category with UUID % not found in ledger % for current user',
                               p_category_uuid, p_ledger_uuid;
            end if;
        end if;

        -- CORRECT LOGIC FOR EXPENSES:
        -- DEBIT: Expense category (where money is spent)
        -- CREDIT: Bank account (loses money)
        v_debit_account_id := v_category_id;
        v_credit_account_id := v_account_id;
    end if;

    -- create the transaction with enhanced error handling
    begin
        insert into data.transactions (
            ledger_id, description, date, amount,
            debit_account_id, credit_account_id, user_data
        )
        values (
            v_ledger_id, v_cleaned_description, p_date, p_amount,
            v_debit_account_id, v_credit_account_id, p_user_data
        )
        returning id into v_transaction_id;
    exception
        when unique_violation then
            raise exception using
                message = utils.handle_constraint_violation('transactions_uuid_unique', 'transactions'),
                errcode = 'unique_violation';
        when foreign_key_violation then
            raise exception 'Invalid account reference in transaction. Please verify all accounts exist.';
        when check_violation then
            raise exception 'Transaction violates business rules. Please check amount and account constraints.';
    end;

    return v_transaction_id;
end;
$$ language plpgsql security definer;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore the previous (incorrect) version
-- This would need the exact previous implementation to be accurate
select 'Transaction logic rollback - would restore previous implementation';

-- +goose StatementEnd