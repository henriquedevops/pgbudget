-- Migration: Add Credit Card Spending Logic
-- Phase 4.2 - Credit Card Spending Logic
-- Created: 2025-10-12

-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- When spending on a credit card, this migration automatically moves budget
-- from the spending category to the CC Payment category. This ensures that
-- budget is "reserved" for paying the credit card balance.
--
-- When paying a credit card from a bank account, the CC Payment category
-- budget is reduced.
--
-- This implements YNAB-style credit card budgeting workflow.
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Function to move budget between categories
-- ============================================================================

-- moves budget from one category to another when credit card spending occurs
create or replace function utils.move_budget_for_cc_spending(
    p_transaction_id bigint,
    p_from_category_id bigint,
    p_to_payment_category_id bigint,
    p_amount bigint,
    p_date timestamptz,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_budget_move_transaction_id bigint;
    v_from_category_balance bigint;
begin
    -- check if source category has sufficient balance
    v_from_category_balance := utils.get_account_balance(p_ledger_id, p_from_category_id);

    -- if the category doesn't have sufficient budget, we still create the move
    -- but this will result in a negative balance (overspending)
    -- the user can then choose to cover it from another category

    -- create a budget move transaction: debit from_category, credit cc_payment_category
    -- this moves the budget from the spending category to the payment category
    insert into data.transactions (
        ledger_id,
        date,
        description,
        debit_account_id,
        credit_account_id,
        amount,
        user_data,
        metadata
    )
    values (
        p_ledger_id,
        p_date,
        'CC Budget Move: Credit card spending',
        p_from_category_id,
        p_to_payment_category_id,
        p_amount,
        p_user_data,
        jsonb_build_object(
            'is_cc_budget_move', true,
            'source_transaction_id', p_transaction_id
        )
    )
    returning id into v_budget_move_transaction_id;

    return v_budget_move_transaction_id;
end;
$$ language plpgsql security definer;

comment on function utils.move_budget_for_cc_spending is
'Moves budget from a spending category to a CC payment category when credit card spending occurs.
This ensures budget is reserved for paying the credit card.';

-- grant execute permission to web user
grant execute on function utils.move_budget_for_cc_spending(bigint, bigint, bigint, bigint, timestamptz, bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Function to get payment category for a credit card
-- ============================================================================

-- gets the payment category uuid for a given credit card account id
create or replace function utils.get_cc_payment_category_id(
    p_credit_card_id bigint,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_payment_category_id bigint;
    v_cc_uuid text;
begin
    -- get the credit card uuid
    select uuid into v_cc_uuid
      from data.accounts
     where id = p_credit_card_id;

    if v_cc_uuid is null then
        return null;
    end if;

    -- find the payment category linked to this credit card
    select a.id into v_payment_category_id
      from data.accounts a
     where a.ledger_id = p_ledger_id
       and a.type = 'equity'
       and a.user_data = p_user_data
       and a.metadata->>'credit_card_uuid' = v_cc_uuid
       and (a.metadata->>'is_cc_payment_category')::boolean = true;

    return v_payment_category_id;
end;
$$ language plpgsql stable security definer;

comment on function utils.get_cc_payment_category_id is
'Gets the payment category ID for a given credit card account.
Returns NULL if no payment category exists for the credit card.';

-- grant execute permission to web user
grant execute on function utils.get_cc_payment_category_id(bigint, bigint, text) to pgbudget;

-- ============================================================================
-- TRIGGER: Auto-move budget on credit card spending
-- ============================================================================

-- trigger function to automatically move budget when spending on credit card
create or replace function utils.auto_move_cc_budget_fn()
returns trigger as
$$
declare
    v_debit_account_type text;
    v_credit_account_type text;
    v_debit_account_internal_type text;
    v_credit_account_internal_type text;
    v_spending_category_id bigint;
    v_credit_card_id bigint;
    v_payment_category_id bigint;
    v_budget_move_transaction_id bigint;
    v_is_cc_budget_move boolean;
begin
    -- check if this transaction is itself a cc budget move (to prevent recursion)
    v_is_cc_budget_move := coalesce((new.metadata->>'is_cc_budget_move')::boolean, false);

    if v_is_cc_budget_move then
        -- skip processing for budget move transactions
        return new;
    end if;

    -- get account types for both debit and credit accounts
    select a.type, a.internal_type into v_debit_account_type, v_debit_account_internal_type
      from data.accounts a
     where a.id = new.debit_account_id;

    select a.type, a.internal_type into v_credit_account_type, v_credit_account_internal_type
      from data.accounts a
     where a.id = new.credit_account_id;

    -- check for credit card spending pattern:
    -- debit equity (category), credit liability (credit card)
    if v_debit_account_type = 'equity' and
       v_credit_account_type = 'liability' and
       v_credit_account_internal_type = 'liability_like' then

        -- this is spending on a credit card
        v_spending_category_id := new.debit_account_id;
        v_credit_card_id := new.credit_account_id;

        -- get the payment category for this credit card
        v_payment_category_id := utils.get_cc_payment_category_id(
            v_credit_card_id,
            new.ledger_id,
            new.user_data
        );

        -- if no payment category exists, skip (card was created before this feature)
        if v_payment_category_id is null then
            raise notice 'No payment category found for credit card ID %, skipping budget move', v_credit_card_id;
            return new;
        end if;

        -- don't move budget if spending category is Income, Unassigned, or Off-budget
        if v_spending_category_id in (
            select a.id from data.accounts a
            where a.ledger_id = new.ledger_id
              and a.type = 'equity'
              and a.name in ('Income', 'Unassigned', 'Off-budget')
        ) then
            raise notice 'Spending category is special account, skipping budget move';
            return new;
        end if;

        -- move budget from spending category to payment category
        v_budget_move_transaction_id := utils.move_budget_for_cc_spending(
            new.id,
            v_spending_category_id,
            v_payment_category_id,
            new.amount,
            new.date,
            new.ledger_id,
            new.user_data
        );

        raise notice 'Moved $ % budget from category ID % to payment category ID % for CC spending transaction %',
                     new.amount, v_spending_category_id, v_payment_category_id, new.id;
    end if;

    return new;
end;
$$ language plpgsql;

comment on function utils.auto_move_cc_budget_fn is
'Trigger function that automatically moves budget from spending category to CC payment category
when a credit card spending transaction occurs.';

-- create the trigger on data.transactions table
create trigger trigger_auto_move_cc_budget
    after insert
    on data.transactions
    for each row
execute function utils.auto_move_cc_budget_fn();

comment on trigger trigger_auto_move_cc_budget on data.transactions is
'Automatically moves budget from spending category to CC payment category on credit card spending.';

-- ============================================================================
-- API LAYER: Function to get payment available for credit card
-- ============================================================================

-- gets the payment available amount for a credit card account
create or replace function api.get_cc_payment_available(
    p_credit_card_uuid text
) returns bigint as
$$
declare
    v_user_id text;
    v_credit_card_id bigint;
    v_ledger_id bigint;
    v_account_type text;
    v_user_data text;
    v_payment_category_id bigint;
    v_payment_available bigint;
begin
    -- get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- get the credit card account details
    select a.id, a.ledger_id, a.type, a.user_data
      into v_credit_card_id, v_ledger_id, v_account_type, v_user_data
      from data.accounts a
     where a.uuid = p_credit_card_uuid
       and a.user_data = utils.get_user();

    if v_credit_card_id is null then
        raise exception 'Credit card account not found';
    end if;

    -- verify it's a liability account
    if v_account_type != 'liability' then
        raise exception 'Account is not a liability account (credit card)';
    end if;

    -- get the payment category for this credit card
    v_payment_category_id := utils.get_cc_payment_category_id(
        v_credit_card_id,
        v_ledger_id,
        v_user_data
    );

    -- if no payment category exists, return 0
    if v_payment_category_id is null then
        return 0;
    end if;

    -- get the balance of the payment category
    v_payment_available := utils.get_account_balance(
        v_ledger_id,
        v_payment_category_id
    );

    return coalesce(v_payment_available, 0);
end;
$$ language plpgsql security definer;

comment on function api.get_cc_payment_available is
'Gets the payment available amount for a credit card account.
This is the balance of the CC payment category, representing the budgeted amount
available to pay the credit card.';

-- grant execute permission to web user
grant execute on function api.get_cc_payment_available(text) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- drop the trigger
drop trigger if exists trigger_auto_move_cc_budget on data.transactions cascade;

-- drop the trigger function
drop function if exists utils.auto_move_cc_budget_fn() cascade;

-- drop the api function
drop function if exists api.get_cc_payment_available(text) cascade;

-- drop the utils functions
drop function if exists utils.get_cc_payment_category_id(bigint, bigint, text) cascade;
drop function if exists utils.move_budget_for_cc_spending(bigint, bigint, bigint, bigint, timestamptz, bigint, text) cascade;

-- note: we don't delete the budget move transactions that were created,
-- as they are part of the transaction history

-- +goose StatementEnd
