-- Migration: Add Reconciliation Support
-- Created: 2025-10-12
-- Purpose: Add cleared status tracking and reconciliation functionality for accounts
--
-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- This migration adds functionality to:
-- 1. Track cleared/uncleared status on transactions
-- 2. Record reconciliation history for accounts
-- 3. Support reconciliation workflow with statement matching
-- 4. Create adjustment transactions when balances don't match
--
-- Reconciliation helps users match their PGBudget account balances with
-- real-world bank/credit card statements, ensuring accuracy.
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- SCHEMA CHANGES: Add cleared_status to transactions table
-- ============================================================================

-- Add cleared_status column to transactions
alter table data.transactions
add column if not exists cleared_status text default 'uncleared'
check (cleared_status in ('uncleared', 'cleared', 'reconciled'));

comment on column data.transactions.cleared_status is
'Tracks clearing status: uncleared (pending), cleared (confirmed), reconciled (locked)';

-- Create index for filtering by cleared status
create index if not exists idx_transactions_cleared_status
on data.transactions(cleared_status)
where deleted_at is null;

-- ============================================================================
-- NEW TABLE: Reconciliations
-- ============================================================================

create table if not exists data.reconciliations (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),
    account_id bigint not null references data.accounts(id) on delete cascade,
    reconciliation_date date not null,
    statement_balance bigint not null,
    pgbudget_balance bigint not null,
    difference bigint not null,
    adjustment_transaction_id bigint references data.transactions(id) on delete set null,
    notes text,
    created_at timestamptz not null default now(),
    user_data text not null default utils.get_user(),

    constraint reconciliations_uuid_unique unique (uuid)
);

comment on table data.reconciliations is
'Records account reconciliation history matching PGBudget balances to real statements';

comment on column data.reconciliations.statement_balance is
'Balance from bank/credit card statement (in cents)';

comment on column data.reconciliations.pgbudget_balance is
'Calculated PGBudget balance at reconciliation (in cents)';

comment on column data.reconciliations.difference is
'Difference between statement and PGBudget balance (statement - pgbudget)';

comment on column data.reconciliations.adjustment_transaction_id is
'Transaction created to adjust balance if needed (nullable)';

-- Enable RLS
alter table data.reconciliations enable row level security;

-- RLS policy
create policy reconciliations_policy on data.reconciliations
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Grant permissions
grant select, insert, update, delete on data.reconciliations to pgbudget;
grant usage on sequence data.reconciliations_id_seq to pgbudget;

-- Create index for account lookups
create index idx_reconciliations_account_id
on data.reconciliations(account_id, reconciliation_date desc);

-- ============================================================================
-- UTILS LAYER: Get uncleared transactions for an account
-- ============================================================================

create or replace function utils.get_uncleared_transactions(
    p_account_id bigint,
    p_user_data text
) returns table(
    transaction_id bigint,
    transaction_uuid text,
    transaction_date timestamptz,
    description text,
    amount bigint,
    cleared_status text,
    other_account_name text,
    is_debit boolean
) as
$$
begin
    return query
    select
        t.id as transaction_id,
        t.uuid as transaction_uuid,
        t.date as transaction_date,
        t.description,
        t.amount,
        t.cleared_status,
        case
            when t.debit_account_id = p_account_id then ca.name
            else da.name
        end as other_account_name,
        (t.debit_account_id = p_account_id) as is_debit
    from data.transactions t
    join data.accounts da on t.debit_account_id = da.id
    join data.accounts ca on t.credit_account_id = ca.id
    where (t.debit_account_id = p_account_id or t.credit_account_id = p_account_id)
      and t.user_data = p_user_data
      and t.deleted_at is null
      and t.cleared_status in ('uncleared', 'cleared')
    order by t.date desc, t.created_at desc;
end;
$$ language plpgsql stable security definer;

comment on function utils.get_uncleared_transactions is
'Returns all uncleared and cleared (but not reconciled) transactions for an account';

grant execute on function utils.get_uncleared_transactions(bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Mark transactions as cleared
-- ============================================================================

create or replace function utils.mark_transactions_cleared(
    p_transaction_ids bigint[],
    p_user_data text
) returns integer as
$$
declare
    v_updated_count integer;
begin
    update data.transactions
    set cleared_status = 'cleared'
    where id = any(p_transaction_ids)
      and user_data = p_user_data
      and deleted_at is null
      and cleared_status = 'uncleared';

    get diagnostics v_updated_count = row_count;
    return v_updated_count;
end;
$$ language plpgsql security definer;

comment on function utils.mark_transactions_cleared is
'Marks specified transactions as cleared (only updates uncleared ones)';

grant execute on function utils.mark_transactions_cleared(bigint[], text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Create reconciliation record
-- ============================================================================

create or replace function utils.create_reconciliation(
    p_account_id bigint,
    p_reconciliation_date date,
    p_statement_balance bigint,
    p_transaction_ids bigint[],
    p_notes text,
    p_user_data text
) returns bigint as
$$
declare
    v_reconciliation_id bigint;
    v_pgbudget_balance bigint;
    v_difference bigint;
    v_adjustment_txn_id bigint;
    v_account_uuid text;
    v_ledger_id bigint;
begin
    -- Get account details
    select a.uuid, a.ledger_id, utils.get_account_balance(a.ledger_id, a.id)
    into v_account_uuid, v_ledger_id, v_pgbudget_balance
    from data.accounts a
    where a.id = p_account_id
      and a.user_data = p_user_data;

    if v_account_uuid is null then
        raise exception 'Account not found or not accessible';
    end if;

    -- Calculate difference
    v_difference := p_statement_balance - v_pgbudget_balance;

    -- Mark transactions as reconciled
    if p_transaction_ids is not null and array_length(p_transaction_ids, 1) > 0 then
        update data.transactions
        set cleared_status = 'reconciled'
        where id = any(p_transaction_ids)
          and user_data = p_user_data
          and deleted_at is null;
    end if;

    -- Create adjustment transaction if there's a difference
    if v_difference != 0 then
        -- Create adjustment transaction
        -- If difference is positive, credit the account (increase balance)
        -- If difference is negative, debit the account (decrease balance)
        insert into data.transactions (
            ledger_id,
            date,
            description,
            amount,
            debit_account_id,
            credit_account_id,
            user_data,
            cleared_status,
            metadata
        ) values (
            v_ledger_id,
            p_reconciliation_date::timestamptz,
            'Reconciliation adjustment',
            abs(v_difference),
            case when v_difference > 0 then
                (select id from data.accounts where name = 'Unassigned' and ledger_id = v_ledger_id)
                else p_account_id
            end,
            case when v_difference > 0 then p_account_id
                else (select id from data.accounts where name = 'Unassigned' and ledger_id = v_ledger_id)
            end,
            p_user_data,
            'reconciled',
            jsonb_build_object(
                'is_reconciliation_adjustment', true,
                'difference', v_difference
            )
        ) returning id into v_adjustment_txn_id;
    end if;

    -- Create reconciliation record
    insert into data.reconciliations (
        account_id,
        reconciliation_date,
        statement_balance,
        pgbudget_balance,
        difference,
        adjustment_transaction_id,
        notes,
        user_data
    ) values (
        p_account_id,
        p_reconciliation_date,
        p_statement_balance,
        v_pgbudget_balance,
        v_difference,
        v_adjustment_txn_id,
        p_notes,
        p_user_data
    ) returning id into v_reconciliation_id;

    return v_reconciliation_id;
end;
$$ language plpgsql security definer;

comment on function utils.create_reconciliation is
'Creates a reconciliation record, marks transactions as reconciled, and creates adjustment if needed';

grant execute on function utils.create_reconciliation(bigint, date, bigint, bigint[], text, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Get reconciliation history for account
-- ============================================================================

create or replace function utils.get_reconciliation_history(
    p_account_id bigint,
    p_user_data text
) returns table(
    reconciliation_id bigint,
    reconciliation_uuid text,
    reconciliation_date date,
    statement_balance bigint,
    pgbudget_balance bigint,
    difference bigint,
    adjustment_transaction_uuid text,
    notes text,
    created_at timestamptz
) as
$$
begin
    return query
    select
        r.id as reconciliation_id,
        r.uuid as reconciliation_uuid,
        r.reconciliation_date,
        r.statement_balance,
        r.pgbudget_balance,
        r.difference,
        t.uuid as adjustment_transaction_uuid,
        r.notes,
        r.created_at
    from data.reconciliations r
    left join data.transactions t on r.adjustment_transaction_id = t.id
    where r.account_id = p_account_id
      and r.user_data = p_user_data
    order by r.reconciliation_date desc, r.created_at desc;
end;
$$ language plpgsql stable security definer;

comment on function utils.get_reconciliation_history is
'Returns reconciliation history for an account';

grant execute on function utils.get_reconciliation_history(bigint, text) to pgbudget;

-- ============================================================================
-- API LAYER: Get uncleared transactions
-- ============================================================================

create or replace function api.get_uncleared_transactions(
    p_account_uuid text
) returns table(
    transaction_uuid text,
    transaction_date timestamptz,
    description text,
    amount bigint,
    cleared_status text,
    other_account_name text,
    is_debit boolean
) as
$$
declare
    v_user_id text;
    v_account_id bigint;
begin
    -- Get current user
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get account ID
    select a.id into v_account_id
    from data.accounts a
    where a.uuid = p_account_uuid
      and a.user_data = utils.get_user();

    if v_account_id is null then
        raise exception 'Account not found or not accessible';
    end if;

    return query
    select
        u.transaction_uuid,
        u.transaction_date,
        u.description,
        u.amount,
        u.cleared_status,
        u.other_account_name,
        u.is_debit
    from utils.get_uncleared_transactions(v_account_id, utils.get_user()) u;
end;
$$ language plpgsql stable security definer;

comment on function api.get_uncleared_transactions is
'API wrapper to get uncleared transactions for an account';

grant execute on function api.get_uncleared_transactions(text) to pgbudget;

-- ============================================================================
-- API LAYER: Reconcile account
-- ============================================================================

create or replace function api.reconcile_account(
    p_account_uuid text,
    p_reconciliation_date date,
    p_statement_balance bigint,
    p_transaction_uuids text[],
    p_notes text default null
) returns text as
$$
declare
    v_user_id text;
    v_account_id bigint;
    v_transaction_ids bigint[];
    v_reconciliation_id bigint;
    v_reconciliation_uuid text;
begin
    -- Get current user
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get account ID
    select a.id into v_account_id
    from data.accounts a
    where a.uuid = p_account_uuid
      and a.user_data = utils.get_user();

    if v_account_id is null then
        raise exception 'Account not found or not accessible';
    end if;

    -- Convert transaction UUIDs to IDs
    if p_transaction_uuids is not null and array_length(p_transaction_uuids, 1) > 0 then
        select array_agg(t.id)
        into v_transaction_ids
        from data.transactions t
        where t.uuid = any(p_transaction_uuids)
          and t.user_data = utils.get_user()
          and t.deleted_at is null;
    end if;

    -- Create reconciliation
    v_reconciliation_id := utils.create_reconciliation(
        v_account_id,
        p_reconciliation_date,
        p_statement_balance,
        v_transaction_ids,
        p_notes,
        utils.get_user()
    );

    -- Get reconciliation UUID
    select uuid into v_reconciliation_uuid
    from data.reconciliations
    where id = v_reconciliation_id;

    return v_reconciliation_uuid;
end;
$$ language plpgsql security definer;

comment on function api.reconcile_account is
'API wrapper to reconcile an account with statement balance.
Marks transactions as reconciled and creates adjustment if needed.
Returns reconciliation UUID.';

grant execute on function api.reconcile_account(text, date, bigint, text[], text) to pgbudget;

-- ============================================================================
-- API LAYER: Toggle transaction cleared status
-- ============================================================================

create or replace function api.toggle_transaction_cleared(
    p_transaction_uuid text
) returns text as
$$
declare
    v_user_id text;
    v_current_status text;
    v_new_status text;
begin
    -- Get current user
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get current status
    select cleared_status into v_current_status
    from data.transactions
    where uuid = p_transaction_uuid
      and user_data = utils.get_user()
      and deleted_at is null;

    if v_current_status is null then
        raise exception 'Transaction not found or not accessible';
    end if;

    -- Cannot toggle reconciled transactions
    if v_current_status = 'reconciled' then
        raise exception 'Cannot toggle reconciled transactions';
    end if;

    -- Toggle status
    v_new_status := case
        when v_current_status = 'uncleared' then 'cleared'
        when v_current_status = 'cleared' then 'uncleared'
        else v_current_status
    end;

    -- Update transaction
    update data.transactions
    set cleared_status = v_new_status
    where uuid = p_transaction_uuid
      and user_data = utils.get_user()
      and deleted_at is null;

    return v_new_status;
end;
$$ language plpgsql security definer;

comment on function api.toggle_transaction_cleared is
'Toggles transaction between cleared and uncleared status.
Cannot toggle reconciled transactions.
Returns new status.';

grant execute on function api.toggle_transaction_cleared(text) to pgbudget;

-- ============================================================================
-- API LAYER: Get reconciliation history
-- ============================================================================

create or replace function api.get_reconciliation_history(
    p_account_uuid text
) returns table(
    reconciliation_uuid text,
    reconciliation_date date,
    statement_balance bigint,
    pgbudget_balance bigint,
    difference bigint,
    adjustment_transaction_uuid text,
    notes text,
    created_at timestamptz
) as
$$
declare
    v_user_id text;
    v_account_id bigint;
begin
    -- Get current user
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- Get account ID
    select a.id into v_account_id
    from data.accounts a
    where a.uuid = p_account_uuid
      and a.user_data = utils.get_user();

    if v_account_id is null then
        raise exception 'Account not found or not accessible';
    end if;

    return query
    select
        h.reconciliation_uuid,
        h.reconciliation_date,
        h.statement_balance,
        h.pgbudget_balance,
        h.difference,
        h.adjustment_transaction_uuid,
        h.notes,
        h.created_at
    from utils.get_reconciliation_history(v_account_id, utils.get_user()) h;
end;
$$ language plpgsql stable security definer;

comment on function api.get_reconciliation_history is
'API wrapper to get reconciliation history for an account';

grant execute on function api.get_reconciliation_history(text) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop API functions
drop function if exists api.get_reconciliation_history(text) cascade;
drop function if exists api.toggle_transaction_cleared(text) cascade;
drop function if exists api.reconcile_account(text, date, bigint, text[], text) cascade;
drop function if exists api.get_uncleared_transactions(text) cascade;

-- Drop utils functions
drop function if exists utils.get_reconciliation_history(bigint, text) cascade;
drop function if exists utils.create_reconciliation(bigint, date, bigint, bigint[], text, text) cascade;
drop function if exists utils.mark_transactions_cleared(bigint[], text) cascade;
drop function if exists utils.get_uncleared_transactions(bigint, text) cascade;

-- Drop reconciliations table
drop table if exists data.reconciliations cascade;

-- Drop index on transactions
drop index if exists data.idx_transactions_cleared_status;

-- Drop cleared_status column
alter table data.transactions drop column if exists cleared_status cascade;

-- +goose StatementEnd
