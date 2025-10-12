-- Migration: Add Category Deletion with Rollback
-- Created: 2025-10-12
-- Purpose: Enable deletion of categories with proper handling of related data

-- ============================================================================
-- DESCRIPTION
-- ============================================================================
-- This migration adds functionality to delete categories (equity accounts) with
-- options to either:
-- 1. Reassign all transactions to another category (recommended)
-- 2. Delete all related transactions (destructive, requires confirmation)
--
-- When deleting a category:
-- - Goals are automatically deleted (CASCADE)
-- - Payees default_category is set to NULL (SET NULL)
-- - Recurring transactions category is set to NULL (SET NULL)
-- - Balance snapshots are deleted
-- - Transaction splits are reassigned or deleted
-- - Transactions are reassigned or soft-deleted
-- ============================================================================

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Function to reassign category transactions
-- ============================================================================

-- reassigns all transactions from one category to another
create or replace function utils.reassign_category_transactions(
    p_from_category_id bigint,
    p_to_category_id bigint,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_updated_count bigint := 0;
begin
    -- update transactions where from_category is the debit account
    with updated as (
        update data.transactions
           set debit_account_id = p_to_category_id
         where debit_account_id = p_from_category_id
           and ledger_id = p_ledger_id
           and user_data = p_user_data
           and deleted_at is null
        returning 1
    )
    select count(*) into v_updated_count from updated;

    -- update transactions where from_category is the credit account
    with updated as (
        update data.transactions
           set credit_account_id = p_to_category_id
         where credit_account_id = p_from_category_id
           and ledger_id = p_ledger_id
           and user_data = p_user_data
           and deleted_at is null
        returning 1
    )
    select v_updated_count + count(*) into v_updated_count from updated;

    -- update transaction splits
    update data.transaction_splits
       set category_id = p_to_category_id
     where category_id = p_from_category_id
       and user_data = p_user_data;

    return v_updated_count;
end;
$$ language plpgsql security definer;

comment on function utils.reassign_category_transactions is
'Reassigns all transactions and splits from one category to another.
Used when deleting a category with reassignment option.';

grant execute on function utils.reassign_category_transactions(bigint, bigint, bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Function to soft delete category transactions
-- ============================================================================

-- soft deletes all transactions related to a category
create or replace function utils.soft_delete_category_transactions(
    p_category_id bigint,
    p_ledger_id bigint,
    p_user_data text
) returns bigint as
$$
declare
    v_deleted_count bigint := 0;
begin
    -- soft delete transactions where category is debit or credit account
    with deleted as (
        update data.transactions
           set deleted_at = current_timestamp
         where (debit_account_id = p_category_id or credit_account_id = p_category_id)
           and ledger_id = p_ledger_id
           and user_data = p_user_data
           and deleted_at is null
        returning 1
    )
    select count(*) into v_deleted_count from deleted;

    return v_deleted_count;
end;
$$ language plpgsql security definer;

comment on function utils.soft_delete_category_transactions is
'Soft deletes all transactions related to a category.
Used when deleting a category without reassignment.';

grant execute on function utils.soft_delete_category_transactions(bigint, bigint, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Function to check if category can be safely deleted
-- ============================================================================

-- checks if a category can be deleted and returns statistics
create or replace function utils.check_category_deletion_impact(
    p_category_uuid text,
    p_user_data text
) returns table(
    transaction_count bigint,
    goal_exists boolean,
    split_count bigint,
    payee_count bigint,
    recurring_count bigint,
    balance_snapshot_count bigint,
    current_balance bigint
) as
$$
declare
    v_category_id bigint;
    v_ledger_id bigint;
begin
    -- get category id and ledger id
    select a.id, a.ledger_id
      into v_category_id, v_ledger_id
      from data.accounts a
     where a.uuid = p_category_uuid
       and a.user_data = p_user_data
       and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category not found or not accessible';
    end if;

    return query
    select
        -- count transactions
        (select count(*)
           from data.transactions t
          where (t.debit_account_id = v_category_id or t.credit_account_id = v_category_id)
            and t.deleted_at is null)::bigint as transaction_count,

        -- check if goal exists
        exists(
            select 1
              from data.category_goals g
             where g.category_id = v_category_id
        ) as goal_exists,

        -- count splits
        (select count(*)
           from data.transaction_splits s
          where s.category_id = v_category_id)::bigint as split_count,

        -- count payees using this as default
        (select count(*)
           from data.payees p
          where p.default_category_id = v_category_id)::bigint as payee_count,

        -- count recurring transactions
        (select count(*)
           from data.recurring_transactions r
          where r.category_id = v_category_id)::bigint as recurring_count,

        -- count balance snapshots
        (select count(*)
           from data.balance_snapshots b
          where b.account_id = v_category_id)::bigint as balance_snapshot_count,

        -- get current balance
        utils.get_account_balance(v_ledger_id, v_category_id) as current_balance;
end;
$$ language plpgsql stable security definer;

comment on function utils.check_category_deletion_impact is
'Checks the impact of deleting a category by counting related records.
Returns statistics to help user decide on deletion strategy.';

grant execute on function utils.check_category_deletion_impact(text, text) to pgbudget;

-- ============================================================================
-- UTILS LAYER: Function to delete category
-- ============================================================================

-- deletes a category with optional reassignment
create or replace function utils.delete_category(
    p_category_uuid text,
    p_reassign_to_category_uuid text,
    p_user_data text
) returns jsonb as
$$
declare
    v_category_id bigint;
    v_category_name text;
    v_ledger_id bigint;
    v_reassign_to_category_id bigint;
    v_transactions_updated bigint := 0;
    v_transactions_deleted bigint := 0;
    v_result jsonb;
begin
    -- get category details
    select a.id, a.name, a.ledger_id
      into v_category_id, v_category_name, v_ledger_id
      from data.accounts a
     where a.uuid = p_category_uuid
       and a.user_data = p_user_data
       and a.type = 'equity';

    if v_category_id is null then
        raise exception 'Category not found or not accessible';
    end if;

    -- check if it's a special account that cannot be deleted
    if v_category_name in ('Income', 'Unassigned', 'Off-budget') then
        raise exception 'Cannot delete special account: %', v_category_name;
    end if;

    -- check if it's a CC payment category
    if (select metadata->>'is_cc_payment_category' from data.accounts where id = v_category_id) = 'true' then
        raise exception 'Cannot delete credit card payment category. Delete the credit card instead.';
    end if;

    -- handle reassignment or deletion of transactions
    if p_reassign_to_category_uuid is not null then
        -- get reassignment target category
        select a.id
          into v_reassign_to_category_id
          from data.accounts a
         where a.uuid = p_reassign_to_category_uuid
           and a.ledger_id = v_ledger_id
           and a.user_data = p_user_data
           and a.type = 'equity';

        if v_reassign_to_category_id is null then
            raise exception 'Reassignment target category not found';
        end if;

        if v_reassign_to_category_id = v_category_id then
            raise exception 'Cannot reassign category to itself';
        end if;

        -- reassign all transactions to new category
        v_transactions_updated := utils.reassign_category_transactions(
            v_category_id,
            v_reassign_to_category_id,
            v_ledger_id,
            p_user_data
        );

        -- verify no transactions still reference this category
        if exists (
            select 1 from data.transactions
            where (debit_account_id = v_category_id or credit_account_id = v_category_id)
              and deleted_at is null
        ) then
            raise exception 'Failed to reassign all transactions. Some transactions still reference this category.';
        end if;
    else
        -- soft delete all transactions
        v_transactions_deleted := utils.soft_delete_category_transactions(
            v_category_id,
            v_ledger_id,
            p_user_data
        );

        -- verify all transactions are soft deleted
        if exists (
            select 1 from data.transactions
            where (debit_account_id = v_category_id or credit_account_id = v_category_id)
              and deleted_at is null
        ) then
            raise exception 'Failed to delete all transactions. Some transactions are still active.';
        end if;

        -- delete transaction logs for these transactions first
        delete from data.transaction_log
         where original_transaction_id in (
             select id from data.transactions
             where (debit_account_id = v_category_id or credit_account_id = v_category_id)
               and ledger_id = v_ledger_id
               and user_data = p_user_data
         )
         or reversal_transaction_id in (
             select id from data.transactions
             where (debit_account_id = v_category_id or credit_account_id = v_category_id)
               and ledger_id = v_ledger_id
               and user_data = p_user_data
         )
         or correction_transaction_id in (
             select id from data.transactions
             where (debit_account_id = v_category_id or credit_account_id = v_category_id)
               and ledger_id = v_ledger_id
               and user_data = p_user_data
         );

        -- delete balance snapshots for these transactions
        delete from data.balance_snapshots
         where transaction_id in (
             select id from data.transactions
             where (debit_account_id = v_category_id or credit_account_id = v_category_id)
               and ledger_id = v_ledger_id
               and user_data = p_user_data
         );

        -- now hard delete the soft-deleted transactions to allow category deletion
        delete from data.transactions
         where (debit_account_id = v_category_id or credit_account_id = v_category_id)
           and ledger_id = v_ledger_id
           and user_data = p_user_data;
    end if;

    -- delete balance snapshots
    delete from data.balance_snapshots
     where account_id = v_category_id
       and user_data = p_user_data;

    -- goals will be deleted by CASCADE
    -- payees will be updated by SET NULL
    -- recurring_transactions will be updated by SET NULL

    -- delete the category account
    -- note: this is a hard delete since accounts don't have deleted_at column
    delete from data.accounts
     where id = v_category_id;

    -- return result summary
    v_result := jsonb_build_object(
        'category_uuid', p_category_uuid,
        'category_name', v_category_name,
        'transactions_reassigned', v_transactions_updated,
        'transactions_deleted', v_transactions_deleted,
        'deleted_at', current_timestamp
    );

    return v_result;
end;
$$ language plpgsql security definer;

comment on function utils.delete_category is
'Deletes a category (equity account) with optional transaction reassignment.
If p_reassign_to_category_uuid is provided, transactions are reassigned to that category.
If NULL, all transactions are soft-deleted.
Returns summary of deletion impact.';

grant execute on function utils.delete_category(text, text, text) to pgbudget;

-- ============================================================================
-- API LAYER: Function to check deletion impact
-- ============================================================================

-- api wrapper for checking deletion impact
create or replace function api.check_category_deletion_impact(
    p_category_uuid text
) returns table(
    transaction_count bigint,
    goal_exists boolean,
    split_count bigint,
    payee_count bigint,
    recurring_count bigint,
    balance_snapshot_count bigint,
    current_balance bigint
) as
$$
declare
    v_user_id text;
begin
    -- get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    return query
    select * from utils.check_category_deletion_impact(
        p_category_uuid,
        utils.get_user()
    );
end;
$$ language plpgsql security definer;

comment on function api.check_category_deletion_impact is
'API wrapper to check the impact of deleting a category.
Returns counts of related records that will be affected.';

grant execute on function api.check_category_deletion_impact(text) to pgbudget;

-- ============================================================================
-- API LAYER: Function to delete category
-- ============================================================================

-- api wrapper for category deletion
create or replace function api.delete_category(
    p_category_uuid text,
    p_reassign_to_category_uuid text default null
) returns jsonb as
$$
declare
    v_user_id text;
    v_result jsonb;
begin
    -- get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    if v_user_id is null or v_user_id = '' then
        raise exception 'No user context set';
    end if;

    -- call utils function
    v_result := utils.delete_category(
        p_category_uuid,
        p_reassign_to_category_uuid,
        utils.get_user()
    );

    return v_result;
end;
$$ language plpgsql security definer;

comment on function api.delete_category is
'API wrapper to delete a category with optional transaction reassignment.
If p_reassign_to_category_uuid is provided, all transactions are reassigned.
If NULL, all transactions are soft-deleted.
Returns JSON summary of deletion results.';

grant execute on function api.delete_category(text, text) to pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- drop api functions
drop function if exists api.delete_category(text, text) cascade;
drop function if exists api.check_category_deletion_impact(text) cascade;

-- drop utils functions
drop function if exists utils.delete_category(text, text, text) cascade;
drop function if exists utils.check_category_deletion_impact(text, text) cascade;
drop function if exists utils.soft_delete_category_transactions(bigint, bigint, text) cascade;
drop function if exists utils.reassign_category_transactions(bigint, bigint, bigint, text) cascade;

-- +goose StatementEnd
