-- Migration: Add Overspending Detection and Handling
-- Created: 2025-10-12
-- Purpose: Detect overspending in categories and provide tools to cover it

-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- This migration adds functionality to:
-- 1. Detect when categories have overspending (negative balance)
-- 2. Provide an API to cover overspending by moving money from another category
-- 3. Track overspending metadata for month rollover logic
--
-- Overspending occurs when a category's balance goes negative, typically from:
-- - Spending more than budgeted in the category
-- - Not budgeting enough before spending on credit card
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Function to detect overspending in categories
-- ============================================================================

-- Gets all overspent categories for a ledger (categories with negative balance)
create or replace function utils.get_overspent_categories(
    p_ledger_id bigint,
    p_user_data text
) returns table(
    category_id bigint,
    category_uuid text,
    category_name text,
    overspent_amount bigint
) as
$$
begin
    return query
    select
        a.id as category_id,
        a.uuid as category_uuid,
        a.name as category_name,
        -utils.get_account_balance(p_ledger_id, a.id) as overspent_amount
    from data.accounts a
    where a.ledger_id = p_ledger_id
      and a.user_data = p_user_data
      and a.type = 'equity'
      and a.name not in ('Income', 'Unassigned', 'Off-budget')
      and utils.get_account_balance(p_ledger_id, a.id) < 0
    order by utils.get_account_balance(p_ledger_id, a.id) asc;
end;
$$ language plpgsql stable security definer;

comment on function utils.get_overspent_categories is
'Returns all categories with negative balances (overspending) for a ledger.
Excludes special accounts. Returns overspent amount as positive number.';

grant execute on function utils.get_overspent_categories(bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Function to cover overspending from another category
-- ============================================================================

-- Moves money from source category to overspent category to cover overspending
create or replace function utils.cover_overspending(
    p_overspent_category_id bigint,
    p_source_category_id bigint,
    p_amount bigint,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_transaction_id bigint;
    v_overspent_category_name text;
    v_source_category_name text;
    v_source_balance bigint;
begin
    -- Get category names for description
    select name into v_overspent_category_name
      from data.accounts
     where id = p_overspent_category_id;

    select name into v_source_category_name
      from data.accounts
     where id = p_source_category_id;

    -- Check source category has sufficient budget
    v_source_balance := utils.get_account_balance(p_ledger_id, p_source_category_id);

    if v_source_balance < p_amount then
        raise exception 'Insufficient budget in % (available: $%, needed: $%)',
            v_source_category_name,
            v_source_balance::numeric / 100,
            p_amount::numeric / 100;
    end if;

    -- Create transaction to move money: Debit source, Credit overspent
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
        current_timestamp,
        'Cover overspending: ' || v_overspent_category_name || ' from ' || v_source_category_name,
        p_amount,
        p_source_category_id, -- Debit source (reduces source budget)
        p_overspent_category_id, -- Credit overspent (increases overspent budget)
        p_user_data,
        jsonb_build_object(
            'is_cover_overspending', true,
            'overspent_category_id', p_overspent_category_id,
            'source_category_id', p_source_category_id
        )
    )
    returning id into v_transaction_id;

    return v_transaction_id;
end;
$$ language plpgsql security definer;

comment on function utils.cover_overspending is
'Covers overspending by moving budget from source category to overspent category.
Validates source has sufficient budget. Creates transaction with cover metadata.';

grant execute on function utils.cover_overspending(bigint, bigint, bigint, bigint, text) to pgbudget;

-- ============================================================================
-- API LAYER: Function to get overspent categories
-- ============================================================================

-- API wrapper to get overspent categories for a ledger
create or replace function api.get_overspent_categories(
    p_ledger_uuid text
) returns table(
    category_uuid text,
    category_name text,
    overspent_amount bigint
) as
$$
declare
    v_user_id text;
    v_ledger_id bigint;
begin
    -- Get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get ledger ID
    select l.id into v_ledger_id
      from data.ledgers l
     where l.uuid = p_ledger_uuid
       and l.user_data = utils.get_user();

    if v_ledger_id is null then
        raise exception 'Ledger not found or not accessible';
    end if;

    return query
    select
        u.category_uuid,
        u.category_name,
        u.overspent_amount
    from utils.get_overspent_categories(v_ledger_id, utils.get_user()) u;
end;
$$ language plpgsql stable security definer;

comment on function api.get_overspent_categories is
'API wrapper to get all overspent categories for a ledger.
Returns categories with negative balances (overspending).';

grant execute on function api.get_overspent_categories(text) to pgbudget;

-- ============================================================================
-- API LAYER: Function to cover overspending
-- ============================================================================

-- API wrapper to cover overspending from source category
create or replace function api.cover_overspending(
    p_overspent_category_uuid text,
    p_source_category_uuid text,
    p_amount bigint default null
) returns text as
$$
declare
    v_user_id text;
    v_ledger_id bigint;
    v_overspent_category_id bigint;
    v_source_category_id bigint;
    v_transaction_id bigint;
    v_transaction_uuid text;
    v_overspent_balance bigint;
    v_actual_amount bigint;
begin
    -- Get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get overspent category
    select a.id, a.ledger_id
      into v_overspent_category_id, v_ledger_id
      from data.accounts a
     where a.uuid = p_overspent_category_uuid
       and a.user_data = utils.get_user()
       and a.type = 'equity';

    if v_overspent_category_id is null then
        raise exception 'Overspent category not found or not accessible';
    end if;

    -- Get source category
    select a.id
      into v_source_category_id
      from data.accounts a
     where a.uuid = p_source_category_uuid
       and a.ledger_id = v_ledger_id
       and a.user_data = utils.get_user()
       and a.type = 'equity';

    if v_source_category_id is null then
        raise exception 'Source category not found or not accessible';
    end if;

    -- If amount not specified, cover full overspending
    v_overspent_balance := utils.get_account_balance(v_ledger_id, v_overspent_category_id);

    if v_overspent_balance >= 0 then
        raise exception 'Category is not overspent (balance: $%)', v_overspent_balance::numeric / 100;
    end if;

    v_actual_amount := coalesce(p_amount, -v_overspent_balance);

    -- Validate amount is positive
    if v_actual_amount <= 0 then
        raise exception 'Amount must be positive';
    end if;

    -- Cover the overspending
    v_transaction_id := utils.cover_overspending(
        v_overspent_category_id,
        v_source_category_id,
        v_actual_amount,
        v_ledger_id,
        utils.get_user()
    );

    -- Get transaction UUID
    select uuid into v_transaction_uuid
      from data.transactions
     where id = v_transaction_id;

    return v_transaction_uuid;
end;
$$ language plpgsql security definer;

comment on function api.cover_overspending is
'API wrapper to cover overspending by moving budget from source category.
If amount not specified, covers full overspending amount.
Validates categories exist, amount is positive, and source has sufficient budget.
Returns transaction UUID.';

grant execute on function api.cover_overspending(text, text, bigint) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop API functions
drop function if exists api.cover_overspending(text, text, bigint) cascade;
drop function if exists api.get_overspent_categories(text) cascade;

-- Drop utils functions
drop function if exists utils.cover_overspending(bigint, bigint, bigint, bigint, text) cascade;
drop function if exists utils.get_overspent_categories(bigint, text) cascade;

-- +goose StatementEnd
