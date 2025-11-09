-- Migration: Fix CC payment deletion to also reverse budget reduction transactions
-- Date: 2025-01-09
-- Bug: When a CC payment is deleted, the associated budget reduction transaction is orphaned

-- +goose Up
-- +goose StatementBegin

-- Update utils.delete_transaction to also delete associated budget reduction transactions
CREATE OR REPLACE FUNCTION utils.delete_transaction(
    p_original_uuid text,
    p_reason text DEFAULT 'Transaction deleted'
) RETURNS int AS $$
DECLARE
    v_original_tx data.transactions;
    v_reversal_id bigint;
    v_budget_reduction_uuid text;
    v_budget_reduction_id bigint;
BEGIN
    -- Get original transaction
    SELECT * INTO v_original_tx
    FROM data.transactions
    WHERE uuid = p_original_uuid
      AND user_data = utils.get_user();

    IF v_original_tx.id IS NULL THEN
        RAISE EXCEPTION 'Transaction not found: %', p_original_uuid;
    END IF;

    -- Check if this transaction has an associated budget reduction transaction
    -- (created automatically when paying a credit card)
    SELECT t.uuid, t.id INTO v_budget_reduction_uuid, v_budget_reduction_id
    FROM data.transactions t
    WHERE (t.metadata->>'payment_transaction_id')::bigint = v_original_tx.id
      AND (t.metadata->>'is_cc_payment_budget_reduction')::boolean = true
      AND t.user_data = utils.get_user()
    LIMIT 1;

    -- If a budget reduction exists, delete it first (recursively)
    IF v_budget_reduction_uuid IS NOT NULL THEN
        RAISE NOTICE 'Deleting associated budget reduction transaction: %', v_budget_reduction_uuid;
        PERFORM utils.delete_transaction(
            v_budget_reduction_uuid,
            'Auto-reversal: CC payment was deleted'
        );
    END IF;

    -- Create reversal transaction to cancel original
    INSERT INTO data.transactions (amount, description, date, debit_account_id, credit_account_id, ledger_id, user_data)
    VALUES (
        v_original_tx.amount,
        'DELETED: ' || v_original_tx.description,
        v_original_tx.date,
        v_original_tx.credit_account_id,  -- Swap accounts to reverse
        v_original_tx.debit_account_id,
        v_original_tx.ledger_id,
        utils.get_user()
    ) RETURNING id INTO v_reversal_id;

    -- Record the deletion in transaction log
    INSERT INTO data.transaction_log (original_transaction_id, reversal_transaction_id, mutation_type, reason)
    VALUES (
        v_original_tx.id,
        v_reversal_id,
        'deletion',
        p_reason
    );

    RETURN v_reversal_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION utils.delete_transaction IS
'Deletes a transaction by creating a reversal transaction.
Also automatically reverses any associated budget reduction transactions
(e.g., when deleting a CC payment).';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to the original version (without budget reduction handling)
CREATE OR REPLACE FUNCTION utils.delete_transaction(
    p_original_uuid text,
    p_reason text DEFAULT 'Transaction deleted'
) RETURNS int AS $$
DECLARE
    v_original_tx data.transactions;
    v_reversal_id bigint;
BEGIN
    -- Get original transaction
    SELECT * INTO v_original_tx
    FROM data.transactions
    WHERE uuid = p_original_uuid
      AND user_data = utils.get_user();

    IF v_original_tx.id IS NULL THEN
        RAISE EXCEPTION 'Transaction not found: %', p_original_uuid;
    END IF;

    -- Create reversal transaction to cancel original
    INSERT INTO data.transactions (amount, description, date, debit_account_id, credit_account_id, ledger_id, user_data)
    VALUES (
        v_original_tx.amount,
        'DELETED: ' || v_original_tx.description,
        v_original_tx.date,
        v_original_tx.credit_account_id,  -- Swap accounts to reverse
        v_original_tx.debit_account_id,
        v_original_tx.ledger_id,
        utils.get_user()
    ) RETURNING id INTO v_reversal_id;

    -- Record the deletion in transaction log
    INSERT INTO data.transaction_log (original_transaction_id, reversal_transaction_id, mutation_type, reason)
    VALUES (
        v_original_tx.id,
        v_reversal_id,
        'deletion',
        p_reason
    );

    RETURN v_reversal_id;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- +goose StatementEnd
