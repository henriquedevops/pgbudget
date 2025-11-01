-- Migration: Filter deleted accounts from balance functions
-- Created: 2025-11-01
-- Purpose: Ensure deleted accounts don't show up in account lists and balance queries

-- +goose Up
-- +goose StatementBegin

-- Update utils.get_ledger_current_balances to filter deleted accounts
CREATE OR REPLACE FUNCTION utils.get_ledger_current_balances(p_ledger_uuid text)
RETURNS TABLE(account_uuid text, account_name text, account_type text, current_balance bigint)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_ledger_id bigint;
BEGIN
    -- Get ledger id
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found: %', p_ledger_uuid;
    END IF;

    -- Return current balance for each account in the ledger
    -- FILTER OUT DELETED ACCOUNTS
    RETURN QUERY
    SELECT
        a.uuid::text,
        a.name,
        a.type,
        COALESCE(utils.get_account_current_balance(a.id), 0)
    FROM data.accounts a
    WHERE a.ledger_id = v_ledger_id
      AND a.user_data = utils.get_user()
      AND a.deleted_at IS NULL  -- ADDED: Don't include deleted accounts
    ORDER BY a.type, a.name;
END;
$$;

COMMENT ON FUNCTION utils.get_ledger_current_balances(text) IS
'Get current balances for all active (non-deleted) accounts in a ledger';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore original version (without deleted_at filter)
CREATE OR REPLACE FUNCTION utils.get_ledger_current_balances(p_ledger_uuid text)
RETURNS TABLE(account_uuid text, account_name text, account_type text, current_balance bigint)
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_ledger_id bigint;
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found: %', p_ledger_uuid;
    END IF;

    RETURN QUERY
    SELECT
        a.uuid::text,
        a.name,
        a.type,
        COALESCE(utils.get_account_current_balance(a.id), 0)
    FROM data.accounts a
    WHERE a.ledger_id = v_ledger_id
      AND a.user_data = utils.get_user()
    ORDER BY a.type, a.name;
END;
$$;

-- +goose StatementEnd
