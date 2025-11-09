-- Migration: Fix add_account_transfer function to use user_data instead of user_id
-- Date: 2025-01-09

-- +goose Up
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.add_account_transfer(
    p_ledger_uuid text,
    p_from_account_uuid text,
    p_to_account_uuid text,
    p_amount numeric,
    p_date date,
    p_memo text DEFAULT NULL
)
RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $function$
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

    -- Verify ledger belongs to user (FIX: use user_data instead of user_id)
    SELECT user_data INTO v_ledger_user_id
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
$function$;
-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Revert to the old version with user_id (for rollback)
CREATE OR REPLACE FUNCTION api.add_account_transfer(
    p_ledger_uuid text,
    p_from_account_uuid text,
    p_to_account_uuid text,
    p_amount numeric,
    p_date date,
    p_memo text DEFAULT NULL
)
RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $function$
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
$function$;
-- +goose StatementEnd
