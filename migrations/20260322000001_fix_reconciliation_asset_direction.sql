-- +goose Up
-- Fix utils.create_reconciliation:
-- 1. Use balance as-of reconciliation date (not all-time)
-- 2. Correct direction for asset_like accounts (was reversed)

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.create_reconciliation(
    p_account_id bigint,
    p_reconciliation_date date,
    p_statement_balance bigint,
    p_transaction_ids bigint[],
    p_notes text,
    p_user_data text
) RETURNS bigint
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
declare
    v_reconciliation_id bigint;
    v_pgbudget_balance bigint;
    v_difference bigint;
    v_adjustment_txn_id bigint;
    v_account_uuid text;
    v_ledger_id bigint;
    v_internal_type text;
    v_unassigned_id bigint;
    v_debit_id bigint;
    v_credit_id bigint;
begin
    -- Get account details including internal_type
    select a.uuid, a.ledger_id, a.internal_type
    into v_account_uuid, v_ledger_id, v_internal_type
    from data.accounts a
    where a.id = p_account_id
      and a.user_data = p_user_data;

    if v_account_uuid is null then
        raise exception 'Account not found or not accessible';
    end if;

    -- Compute balance AS OF p_reconciliation_date (not all-time)
    if v_internal_type = 'asset_like' then
        select coalesce(sum(
            case
                when debit_account_id = p_account_id then amount
                when credit_account_id = p_account_id then -amount
                else 0
            end
        ), 0) into v_pgbudget_balance
        from data.transactions
        where ledger_id = v_ledger_id
          and (debit_account_id = p_account_id or credit_account_id = p_account_id)
          and deleted_at is null
          and date <= p_reconciliation_date;
    else -- liability_like
        select coalesce(sum(
            case
                when credit_account_id = p_account_id then amount
                when debit_account_id = p_account_id then -amount
                else 0
            end
        ), 0) into v_pgbudget_balance
        from data.transactions
        where ledger_id = v_ledger_id
          and (debit_account_id = p_account_id or credit_account_id = p_account_id)
          and deleted_at is null
          and date <= p_reconciliation_date;
    end if;

    -- Calculate difference: positive = statement > DB (need to add), negative = DB > statement (need to subtract)
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
        -- Get Unassigned account ID for this ledger
        select id into v_unassigned_id
        from data.accounts
        where ledger_id = v_ledger_id
          and name = 'Unassigned'
        limit 1;

        -- Correct direction based on account type:
        -- asset_like: debit = increase, credit = decrease
        --   difference > 0 (need to ADD): debit account, credit Unassigned
        --   difference < 0 (need to SUBTRACT): debit Unassigned, credit account
        -- liability_like: credit = increase, debit = decrease
        --   difference > 0 (need to ADD): credit account, debit Unassigned
        --   difference < 0 (need to SUBTRACT): credit Unassigned, debit account
        if v_internal_type = 'asset_like' then
            if v_difference > 0 then
                v_debit_id  := p_account_id;
                v_credit_id := v_unassigned_id;
            else
                v_debit_id  := v_unassigned_id;
                v_credit_id := p_account_id;
            end if;
        else -- liability_like
            if v_difference > 0 then
                v_debit_id  := v_unassigned_id;
                v_credit_id := p_account_id;
            else
                v_debit_id  := p_account_id;
                v_credit_id := v_unassigned_id;
            end if;
        end if;

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
            p_reconciliation_date,
            'Reconciliation adjustment',
            abs(v_difference),
            v_debit_id,
            v_credit_id,
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
$$;
-- +goose StatementEnd

-- +goose Down
-- Restore original (buggy) version - left as no-op since original had direction bug
SELECT 1;
