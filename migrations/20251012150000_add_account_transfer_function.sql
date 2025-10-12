-- Migration: Add Account Transfer Function
-- Phase 3.5 - Account Transfers Simplified
-- Created: 2025-10-12

-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UTILS LAYER: Core transfer logic
-- ============================================================================

CREATE OR REPLACE FUNCTION utils.add_account_transfer(
    p_ledger_uuid TEXT,
    p_from_account_uuid TEXT,
    p_to_account_uuid TEXT,
    p_amount NUMERIC(15,2),
    p_date DATE,
    p_memo TEXT DEFAULT NULL
) RETURNS TEXT AS $$
DECLARE
    v_transaction_uuid TEXT;
    v_from_account_name TEXT;
    v_to_account_name TEXT;
    v_from_account_ledger TEXT;
    v_to_account_ledger TEXT;
    v_from_account_type TEXT;
    v_to_account_type TEXT;
BEGIN
    -- Validate amount is positive
    IF p_amount <= 0 THEN
        RAISE EXCEPTION 'Transfer amount must be positive';
    END IF;

    -- Validate accounts are different
    IF p_from_account_uuid = p_to_account_uuid THEN
        RAISE EXCEPTION 'Cannot transfer to the same account';
    END IF;

    -- Get account details and validate they exist
    SELECT name, ledger_uuid, type INTO v_from_account_name, v_from_account_ledger, v_from_account_type
    FROM data.accounts
    WHERE uuid = p_from_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Source account not found';
    END IF;

    SELECT name, ledger_uuid, type INTO v_to_account_name, v_to_account_ledger, v_to_account_type
    FROM data.accounts
    WHERE uuid = p_to_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Destination account not found';
    END IF;

    -- Validate both accounts belong to the specified ledger
    IF v_from_account_ledger != p_ledger_uuid OR v_to_account_ledger != p_ledger_uuid THEN
        RAISE EXCEPTION 'Both accounts must belong to the specified ledger';
    END IF;

    -- Validate both accounts are asset or liability accounts
    IF v_from_account_type NOT IN ('asset', 'liability') THEN
        RAISE EXCEPTION 'Source account must be an asset or liability account';
    END IF;

    IF v_to_account_type NOT IN ('asset', 'liability') THEN
        RAISE EXCEPTION 'Destination account must be an asset or liability account';
    END IF;

    -- Generate UUID for the transaction
    v_transaction_uuid := utils.generate_uuid();

    -- Insert transaction with two splits representing the transfer
    INSERT INTO data.transactions (uuid, ledger_uuid, date, description, metadata)
    VALUES (
        v_transaction_uuid,
        p_ledger_uuid,
        p_date,
        COALESCE(p_memo, 'Transfer: ' || v_from_account_name || ' → ' || v_to_account_name),
        jsonb_build_object('type', 'transfer', 'memo', p_memo)
    );

    -- Create debit split (decrease source account)
    INSERT INTO data.splits (transaction_uuid, account_uuid, amount, metadata)
    VALUES (
        v_transaction_uuid,
        p_from_account_uuid,
        -p_amount,  -- Negative amount decreases the account
        jsonb_build_object('description', 'Transfer to: ' || v_to_account_name)
    );

    -- Create credit split (increase destination account)
    INSERT INTO data.splits (transaction_uuid, account_uuid, amount, metadata)
    VALUES (
        v_transaction_uuid,
        p_to_account_uuid,
        p_amount,  -- Positive amount increases the account
        jsonb_build_object('description', 'Transfer from: ' || v_from_account_name)
    );

    RETURN v_transaction_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Grant execute permission to web user
GRANT EXECUTE ON FUNCTION utils.add_account_transfer(TEXT, TEXT, TEXT, NUMERIC, DATE, TEXT) TO pgbudget;

-- ============================================================================
-- API LAYER: RLS-aware wrapper
-- ============================================================================

CREATE OR REPLACE FUNCTION api.add_account_transfer(
    p_ledger_uuid TEXT,
    p_from_account_uuid TEXT,
    p_to_account_uuid TEXT,
    p_amount NUMERIC(15,2),
    p_date DATE,
    p_memo TEXT DEFAULT NULL
) RETURNS TEXT AS $$
DECLARE
    v_user_id TEXT;
    v_ledger_user_id TEXT;
    v_transaction_uuid TEXT;
BEGIN
    -- Get current user from session
    v_user_id := current_setting('app.current_user_id', true);

    IF v_user_id IS NULL OR v_user_id = '' THEN
        RAISE EXCEPTION 'No user context set';
    END IF;

    -- Verify ledger belongs to user
    SELECT user_id INTO v_ledger_user_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    IF v_ledger_user_id != v_user_id THEN
        RAISE EXCEPTION 'Access denied: Ledger does not belong to current user';
    END IF;

    -- Call the utils function to create the transfer
    v_transaction_uuid := utils.add_account_transfer(
        p_ledger_uuid,
        p_from_account_uuid,
        p_to_account_uuid,
        p_amount,
        p_date,
        p_memo
    );

    RETURN v_transaction_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Grant execute permission to web user
GRANT EXECUTE ON FUNCTION api.add_account_transfer(TEXT, TEXT, TEXT, NUMERIC, DATE, TEXT) TO pgbudget;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON FUNCTION utils.add_account_transfer IS
'Creates an account transfer transaction with proper double-entry bookkeeping.
Validates that both accounts are in the same ledger and are asset/liability accounts.';

COMMENT ON FUNCTION api.add_account_transfer IS
'RLS-aware wrapper for creating account transfers. Validates user ownership of the ledger.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.add_account_transfer(TEXT, TEXT, TEXT, NUMERIC, DATE, TEXT);
DROP FUNCTION IF EXISTS utils.add_account_transfer(TEXT, TEXT, TEXT, NUMERIC, DATE, TEXT);

-- +goose StatementEnd
