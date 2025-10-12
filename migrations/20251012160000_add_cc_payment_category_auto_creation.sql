-- Migration: Add Credit Card Payment Category Auto-Creation
-- Phase 4.1 - Credit Card Payment Category Auto-Creation
-- Created: 2025-10-12

-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- When a credit card (liability) account is created, this migration
-- automatically creates a companion "CC Payment: [Card Name]" category (equity account).
-- This category will hold the budget allocated for paying the credit card balance.
--
-- The category is linked to the credit card via metadata for easy identification.
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Function to create CC payment category
-- ============================================================================

-- creates a credit card payment category for a given credit card account
create or replace function utils.create_cc_payment_category(
    p_credit_card_id bigint,
    p_credit_card_uuid text,
    p_credit_card_name text,
    p_ledger_id bigint,
    p_user_data text
) returns text as
$$
declare
    v_category_name text;
    v_category_uuid text;
begin
    -- construct the payment category name
    v_category_name := 'CC Payment: ' || p_credit_card_name;

    -- check if a payment category already exists for this credit card
    select a.uuid into v_category_uuid
      from data.accounts a
     where a.ledger_id = p_ledger_id
       and a.type = 'equity'
       and a.user_data = p_user_data
       and a.metadata->>'credit_card_uuid' = p_credit_card_uuid;

    -- if payment category already exists, return its uuid
    if v_category_uuid is not null then
        return v_category_uuid;
    end if;

    -- create the payment category as an equity account
    insert into data.accounts (
        name,
        type,
        description,
        ledger_id,
        user_data,
        metadata
    )
    values (
        v_category_name,
        'equity',
        'Payment category for ' || p_credit_card_name,
        p_ledger_id,
        p_user_data,
        jsonb_build_object(
            'credit_card_uuid', p_credit_card_uuid,
            'is_cc_payment_category', true
        )
    )
    returning uuid into v_category_uuid;

    -- update the credit card account's metadata to link to the payment category
    update data.accounts
       set metadata = coalesce(metadata, '{}'::jsonb) ||
                      jsonb_build_object('payment_category_uuid', v_category_uuid)
     where id = p_credit_card_id;

    return v_category_uuid;
end;
$$ language plpgsql security definer;

comment on function utils.create_cc_payment_category is
'Creates a companion payment category (equity account) for a credit card account.
The category is named "CC Payment: [Card Name]" and linked via metadata.';

-- grant execute permission to web user
grant execute on function utils.create_cc_payment_category(bigint, text, text, bigint, text) to pgbudget;

-- ============================================================================
-- TRIGGER: Auto-create payment category when credit card is created
-- ============================================================================

-- trigger function to automatically create payment category for credit cards
create or replace function utils.auto_create_cc_payment_category_fn()
returns trigger as
$$
declare
    v_payment_category_uuid text;
begin
    -- only create payment category for liability accounts (credit cards)
    if new.type = 'liability' then
        -- create the payment category
        v_payment_category_uuid := utils.create_cc_payment_category(
            new.id,
            new.uuid,
            new.name,
            new.ledger_id,
            new.user_data
        );

        -- log the creation for debugging
        raise notice 'Created CC payment category % for credit card %',
                     v_payment_category_uuid, new.name;
    end if;

    return new;
end;
$$ language plpgsql;

comment on function utils.auto_create_cc_payment_category_fn is
'Trigger function that automatically creates a payment category when a liability account (credit card) is created.';

-- create the trigger on data.accounts table
create trigger trigger_auto_create_cc_payment_category
    after insert
    on data.accounts
    for each row
execute function utils.auto_create_cc_payment_category_fn();

comment on trigger trigger_auto_create_cc_payment_category on data.accounts is
'Automatically creates a companion payment category when a credit card (liability) account is created.';

-- ============================================================================
-- API LAYER: Function to manually create payment category if needed
-- ============================================================================

-- api function to manually create a payment category for an existing credit card
create or replace function api.create_cc_payment_category(
    p_credit_card_uuid text
) returns text as
$$
declare
    v_user_id text;
    v_credit_card_id bigint;
    v_credit_card_name text;
    v_ledger_id bigint;
    v_account_type text;
    v_user_data text;
    v_payment_category_uuid text;
begin
    -- get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- get the credit card account details
    select a.id, a.name, a.ledger_id, a.type, a.user_data
      into v_credit_card_id, v_credit_card_name, v_ledger_id, v_account_type, v_user_data
      from data.accounts a
     where a.uuid = p_credit_card_uuid
       and a.user_data = utils.get_user();

    if v_credit_card_id is null then
        raise exception 'Credit card account not found';
    end if;

    -- verify it's a liability account
    if v_account_type != 'liability' then
        raise exception 'Account % is not a liability account (credit card)', v_credit_card_name;
    end if;

    -- create the payment category
    v_payment_category_uuid := utils.create_cc_payment_category(
        v_credit_card_id,
        p_credit_card_uuid,
        v_credit_card_name,
        v_ledger_id,
        v_user_data
    );

    return v_payment_category_uuid;
end;
$$ language plpgsql security definer;

comment on function api.create_cc_payment_category is
'Manually creates a payment category for an existing credit card account.
Useful for credit cards created before this feature was implemented.';

-- grant execute permission to web user
grant execute on function api.create_cc_payment_category(text) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- drop the trigger
drop trigger if exists trigger_auto_create_cc_payment_category on data.accounts cascade;

-- drop the trigger function
drop function if exists utils.auto_create_cc_payment_category_fn() cascade;

-- drop the api function
drop function if exists api.create_cc_payment_category(text) cascade;

-- drop the utils function
drop function if exists utils.create_cc_payment_category(bigint, text, text, bigint, text) cascade;

-- note: we don't delete the payment categories that were created,
-- as they may have transactions associated with them

-- +goose StatementEnd
