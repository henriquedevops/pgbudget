-- Migration: Add Credit Card Payment Logic
-- Created: 2025-10-12
-- Purpose: Simplify credit card payment workflow and automatically reduce CC payment category budget

-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- This migration adds functionality to handle credit card payments from bank
-- accounts with automatic budget reduction in the CC payment category.
--
-- When paying a credit card:
-- 1. Main transaction: Debit Liability (CC), Credit Asset (Bank) - reduces CC debt
-- 2. Automatically: Debit CC Payment category - reduces payment available budget
--
-- This ensures that when you pay your credit card, the budgeted payment amount
-- is properly "used up" from the CC Payment category.
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Function to reduce CC payment budget after payment
-- ============================================================================

-- Reduces budget in CC payment category when a payment is made
create or replace function utils.reduce_cc_payment_budget(
    p_payment_transaction_id bigint,
    p_credit_card_id bigint,
    p_amount bigint,
    p_date timestamptz,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_payment_category_id bigint;
    v_budget_reduction_tx_id bigint;
begin
    -- Get the CC payment category for this credit card
    v_payment_category_id := utils.get_cc_payment_category_id(
        p_credit_card_id,
        p_ledger_id,
        p_user_data
    );

    -- If no payment category exists, skip budget reduction
    if v_payment_category_id is null then
        raise notice 'No payment category found for credit card. Skipping budget reduction.';
        return null;
    end if;

    -- Create a transaction to reduce budget in CC Payment category
    -- This is a special transaction: Debit CC Payment, Credit Income
    -- It effectively "uses" the budgeted payment amount
    insert into data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        user_data,
        metadata
    )
    values (
        p_ledger_id,
        p_date,
        'Budget reduction for CC payment',
        p_amount,
        v_payment_category_id, -- Debit CC Payment category (reduces budget)
        (select id from data.accounts where ledger_id = p_ledger_id and name = 'Income' and type = 'equity' and user_data = p_user_data), -- Credit Income
        p_user_data,
        jsonb_build_object(
            'is_cc_payment_budget_reduction', true,
            'payment_transaction_id', p_payment_transaction_id,
            'credit_card_id', p_credit_card_id
        )
    )
    returning id into v_budget_reduction_tx_id;

    return v_budget_reduction_tx_id;
end;
$$ language plpgsql security definer;

comment on function utils.reduce_cc_payment_budget is
'Reduces budget in CC payment category when a payment is made to the credit card.
Creates a transaction that debits the CC Payment category (reducing available budget).';

grant execute on function utils.reduce_cc_payment_budget(bigint, bigint, bigint, timestamptz, bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Trigger function to auto-reduce budget on CC payment
-- ============================================================================

-- Trigger function that fires after a CC payment transaction is created
create or replace function utils.auto_reduce_cc_payment_budget_fn()
returns trigger as
$$
declare
    v_debit_account_type text;
    v_credit_account_type text;
    v_is_special_transaction boolean;
begin
    -- Check if this is a special transaction (budget move or budget reduction)
    -- to prevent infinite recursion
    v_is_special_transaction := coalesce(
        (new.metadata->>'is_cc_budget_move')::boolean,
        false
    ) or coalesce(
        (new.metadata->>'is_cc_payment_budget_reduction')::boolean,
        false
    );

    if v_is_special_transaction then
        return new;
    end if;

    -- Get account types
    select type into v_debit_account_type
      from data.accounts
     where id = new.debit_account_id;

    select type into v_credit_account_type
      from data.accounts
     where id = new.credit_account_id;

    -- Pattern: Debit Liability (CC), Credit Asset (Bank)
    -- This is a credit card payment from a bank account
    if v_debit_account_type = 'liability' and v_credit_account_type = 'asset' then
        -- Check if the debit account (CC) has a payment category
        -- If yes, reduce budget in payment category
        perform utils.reduce_cc_payment_budget(
            new.id,
            new.debit_account_id, -- Credit card account
            new.amount,
            new.date,
            new.ledger_id,
            new.user_data
        );
    end if;

    return new;
end;
$$ language plpgsql security definer;

comment on function utils.auto_reduce_cc_payment_budget_fn is
'Trigger function that automatically reduces CC payment category budget when
a payment is made from a bank account to a credit card.
Detects pattern: Debit Liability, Credit Asset.';

grant execute on function utils.auto_reduce_cc_payment_budget_fn() to pgbudget;

-- ============================================================================
-- CREATE TRIGGER
-- ============================================================================

-- Register trigger to fire after transaction insert
create trigger trigger_auto_reduce_cc_payment_budget
    after insert
    on data.transactions
    for each row
execute function utils.auto_reduce_cc_payment_budget_fn();

comment on trigger trigger_auto_reduce_cc_payment_budget on data.transactions is
'Automatically reduces CC payment category budget when a credit card payment is made.';

-- ============================================================================
-- API LAYER: Simplified function to create CC payment transaction
-- ============================================================================

-- API function to create a credit card payment transaction
create or replace function api.pay_credit_card(
    p_credit_card_uuid text,
    p_bank_account_uuid text,
    p_amount bigint,
    p_date timestamptz default current_timestamp,
    p_memo text default null
) returns text as
$$
declare
    v_user_id text;
    v_ledger_id bigint;
    v_credit_card_id bigint;
    v_bank_account_id bigint;
    v_transaction_uuid text;
    v_payment_category_id bigint;
    v_payment_available bigint;
begin
    -- Get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get credit card account
    select a.id, a.ledger_id
      into v_credit_card_id, v_ledger_id
      from data.accounts a
     where a.uuid = p_credit_card_uuid
       and a.user_data = utils.get_user()
       and a.type = 'liability';

    if v_credit_card_id is null then
        raise exception 'Credit card account not found or not accessible';
    end if;

    -- Get bank account
    select a.id
      into v_bank_account_id
      from data.accounts a
     where a.uuid = p_bank_account_uuid
       and a.ledger_id = v_ledger_id
       and a.user_data = utils.get_user()
       and a.type = 'asset';

    if v_bank_account_id is null then
        raise exception 'Bank account not found or not accessible';
    end if;

    -- Validate amount is positive
    if p_amount <= 0 then
        raise exception 'Payment amount must be positive';
    end if;

    -- Get payment available amount to provide helpful feedback
    v_payment_category_id := utils.get_cc_payment_category_id(
        v_credit_card_id,
        v_ledger_id,
        utils.get_user()
    );

    if v_payment_category_id is not null then
        v_payment_available := utils.get_account_balance(v_ledger_id, v_payment_category_id);

        -- Warn if paying more than budgeted
        if p_amount > v_payment_available then
            raise notice 'Payment amount ($%) exceeds budgeted payment available ($%). This will create overspending in the payment category.',
                p_amount::numeric / 100,
                v_payment_available::numeric / 100;
        end if;
    end if;

    -- Create the payment transaction: Debit CC, Credit Bank
    insert into data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        user_data,
        metadata
    )
    values (
        v_ledger_id,
        p_date,
        coalesce(p_memo, 'Credit card payment'),
        p_amount,
        v_credit_card_id, -- Debit liability (reduces CC debt)
        v_bank_account_id, -- Credit asset (reduces bank balance)
        utils.get_user(),
        jsonb_build_object(
            'is_cc_payment', true
        )
    )
    returning uuid into v_transaction_uuid;

    -- Trigger will automatically reduce CC payment category budget

    return v_transaction_uuid;
end;
$$ language plpgsql security definer;

comment on function api.pay_credit_card is
'Simplified API to create a credit card payment transaction from a bank account.
Automatically reduces budget in CC payment category via trigger.
Parameters:
  - p_credit_card_uuid: UUID of credit card to pay
  - p_bank_account_uuid: UUID of bank account to pay from
  - p_amount: Amount to pay (in cents)
  - p_date: Payment date (default: now)
  - p_memo: Optional memo
Returns: Transaction UUID';

grant execute on function api.pay_credit_card(text, text, bigint, timestamptz, text) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop trigger
drop trigger if exists trigger_auto_reduce_cc_payment_budget on data.transactions;

-- Drop api function
drop function if exists api.pay_credit_card(text, text, bigint, timestamptz, text) cascade;

-- Drop utils functions
drop function if exists utils.auto_reduce_cc_payment_budget_fn() cascade;
drop function if exists utils.reduce_cc_payment_budget(bigint, bigint, bigint, timestamptz, bigint, text) cascade;

-- +goose StatementEnd
