-- Function to delete a ledger and all related data
-- This will cascade delete all accounts, transactions, balances, etc.
CREATE OR REPLACE FUNCTION api.delete_ledger(
    p_ledger_uuid text,
    p_user_data text DEFAULT utils.get_user()
) RETURNS json AS $$
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
        'account_balances', (SELECT COUNT(*) FROM data.account_balances WHERE ledger_id = v_ledger_id)
    ) INTO v_counts;

    -- Delete related data in proper order
    -- Note: Most should cascade automatically if foreign keys are set up,
    -- but we'll be explicit for clarity and to handle any missing cascades

    -- Delete action history for this ledger
    DELETE FROM data.action_history WHERE ledger_id = v_ledger_id;

    -- Delete age of money cache
    DELETE FROM data.age_of_money_cache WHERE ledger_id = v_ledger_id;

    -- Delete recurring transactions
    DELETE FROM data.recurring_transactions WHERE ledger_id = v_ledger_id;

    -- Delete balance snapshots (must be before transactions due to FK constraint)
    DELETE FROM data.balance_snapshots
    WHERE transaction_id IN (SELECT id FROM data.transactions WHERE ledger_id = v_ledger_id);

    -- Delete transaction log (must be before transactions due to FK constraint)
    DELETE FROM data.transaction_log
    WHERE original_transaction_id IN (SELECT id FROM data.transactions WHERE ledger_id = v_ledger_id);

    -- Note: account_balances is a VIEW, not a table, so we don't delete from it
    -- The underlying data will be cleaned up when we delete transactions and accounts

    -- Delete transactions (this should cascade to splits if properly configured)
    DELETE FROM data.transactions WHERE ledger_id = v_ledger_id;

    -- Delete accounts (including special accounts since we're deleting the entire ledger)
    -- Temporarily disable the trigger that prevents deletion of special accounts
    ALTER TABLE data.accounts DISABLE TRIGGER prevent_special_account_deletion_trigger;
    DELETE FROM data.accounts WHERE ledger_id = v_ledger_id;
    ALTER TABLE data.accounts ENABLE TRIGGER prevent_special_account_deletion_trigger;

    -- Delete loans if they exist
    DELETE FROM data.loans WHERE ledger_id = v_ledger_id;

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
$$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.delete_ledger(text, text) IS
'Deletes a ledger and all its related data (transactions, accounts, balances, etc.).
Returns a summary of what was deleted. Requires ledger ownership.';

-- Grant execute permission
GRANT EXECUTE ON FUNCTION api.delete_ledger(text, text) TO pgbudget;
