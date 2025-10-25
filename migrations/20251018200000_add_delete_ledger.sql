-- +goose Up
-- +goose StatementBegin

-- Function to delete a ledger and all related data
-- This will cascade delete all accounts, transactions, balances, etc.
CREATE OR REPLACE FUNCTION api.delete_ledger(
    p_ledger_uuid text,
    p_user_data text DEFAULT utils.get_user()
) RETURNS json AS $delete_ledger$
DECLARE
    v_ledger_id bigint;
    v_ledger_name text;
    v_counts json;
BEGIN
    -- Get ledger ID and verify ownership
    SELECT id, name INTO v_ledger_id, v_ledger_name
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = p_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found or access denied';
    END IF;

    -- Collect counts before deletion for reporting
    SELECT json_build_object(
        'transactions', (SELECT COUNT(*) FROM data.transactions WHERE ledger_id = v_ledger_id),
        'accounts', (SELECT COUNT(*) FROM data.accounts WHERE ledger_id = v_ledger_id),
        'recurring_transactions', (SELECT COUNT(*) FROM data.recurring_transactions WHERE ledger_id = v_ledger_id),
        'installment_plans', (SELECT COUNT(*) FROM data.installment_plans WHERE ledger_id = v_ledger_id)
    ) INTO v_counts;

    -- Delete related data in proper order
    -- Must delete in dependency order due to NO ACTION foreign key constraints

    -- Delete transaction_log first (references transactions with NO ACTION)
    DELETE FROM data.transaction_log
    WHERE original_transaction_id IN (SELECT id FROM data.transactions WHERE ledger_id = v_ledger_id);

    -- Delete balance_snapshots (references accounts and transactions with NO ACTION)
    DELETE FROM data.balance_snapshots
    WHERE account_id IN (SELECT id FROM data.accounts WHERE ledger_id = v_ledger_id);

    -- Delete transaction_splits (references transactions with CASCADE and accounts with NO ACTION)
    DELETE FROM data.transaction_splits
    WHERE parent_transaction_id IN (SELECT id FROM data.transactions WHERE ledger_id = v_ledger_id);

    -- Delete installment schedules (references transactions)
    DELETE FROM data.installment_schedules
    WHERE installment_plan_id IN (SELECT id FROM data.installment_plans WHERE ledger_id = v_ledger_id);

    -- Delete installment plans (has CASCADE from ledgers, but explicit for clarity)
    DELETE FROM data.installment_plans WHERE ledger_id = v_ledger_id;

    -- Delete reconciliations (references accounts and transactions)
    DELETE FROM data.reconciliations
    WHERE account_id IN (SELECT id FROM data.accounts WHERE ledger_id = v_ledger_id);

    -- Delete loan payments (references accounts and transactions)
    DELETE FROM data.loan_payments
    WHERE loan_id IN (SELECT id FROM data.loans WHERE ledger_id = v_ledger_id);

    -- Delete credit card statements
    DELETE FROM data.credit_card_statements
    WHERE credit_card_account_id IN (SELECT id FROM data.accounts WHERE ledger_id = v_ledger_id);

    -- Delete credit card limits
    DELETE FROM data.credit_card_limits
    WHERE credit_card_account_id IN (SELECT id FROM data.accounts WHERE ledger_id = v_ledger_id);

    -- Delete category goals
    DELETE FROM data.category_goals
    WHERE category_id IN (SELECT id FROM data.accounts WHERE ledger_id = v_ledger_id);

    -- Delete action history for this ledger
    DELETE FROM data.action_history WHERE ledger_id = v_ledger_id;

    -- Delete age of money cache
    DELETE FROM data.age_of_money_cache WHERE ledger_id = v_ledger_id;

    -- Delete recurring transactions
    DELETE FROM data.recurring_transactions WHERE ledger_id = v_ledger_id;

    -- Delete transactions (this should cascade to splits if properly configured)
    DELETE FROM data.transactions WHERE ledger_id = v_ledger_id;

    -- Delete loans
    DELETE FROM data.loans WHERE ledger_id = v_ledger_id;

    -- Temporarily disable the trigger that prevents deletion of special accounts
    ALTER TABLE data.accounts DISABLE TRIGGER trigger_prevent_special_account_deletion;

    -- Delete accounts (including special accounts like Income, Off-budget, Unassigned)
    DELETE FROM data.accounts WHERE ledger_id = v_ledger_id;

    -- Re-enable the trigger
    ALTER TABLE data.accounts ENABLE TRIGGER trigger_prevent_special_account_deletion;

    -- Finally, delete the ledger itself
    DELETE FROM data.ledgers WHERE id = v_ledger_id;

    -- Return summary of what was deleted
    RETURN json_build_object(
        'success', true,
        'ledger_name', v_ledger_name,
        'ledger_uuid', p_ledger_uuid,
        'deleted_counts', v_counts
    );
END;
$delete_ledger$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.delete_ledger(text, text) IS
'Deletes a ledger and all its related data (transactions, accounts, balances, etc.).
Returns a summary of what was deleted. Requires ledger ownership.';

-- Grant execute permission
GRANT EXECUTE ON FUNCTION api.delete_ledger(text, text) TO pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.delete_ledger(text, text);

-- +goose StatementEnd
