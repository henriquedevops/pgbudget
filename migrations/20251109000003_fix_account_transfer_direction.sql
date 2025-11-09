-- Migration: Fix account transfer direction - swap debit and credit accounts
-- Date: 2025-01-09
-- Bug: Transfers were going the wrong way (from/to were reversed)

-- +goose Up
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.add_account_transfer(
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
    v_transaction_uuid TEXT;
    v_from_account_name TEXT;
    v_to_account_name TEXT;
    v_from_account_ledger_id BIGINT;
    v_to_account_ledger_id BIGINT;
    v_from_account_type TEXT;
    v_to_account_type TEXT;
    v_ledger_id BIGINT;
    v_from_account_id BIGINT;
    v_to_account_id BIGINT;
BEGIN
    -- Validate amount is positive
    IF p_amount <= 0 THEN
        RAISE EXCEPTION 'Transfer amount must be positive';
    END IF;

    -- Validate accounts are different
    IF p_from_account_uuid = p_to_account_uuid THEN
        RAISE EXCEPTION 'Cannot transfer to the same account';
    END IF;

    -- Get ledger_id from ledger_uuid
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    -- Get account details and validate they exist
    SELECT id, name, ledger_id, type
    INTO v_from_account_id, v_from_account_name, v_from_account_ledger_id, v_from_account_type
    FROM data.accounts
    WHERE uuid = p_from_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Source account not found';
    END IF;

    SELECT id, name, ledger_id, type
    INTO v_to_account_id, v_to_account_name, v_to_account_ledger_id, v_to_account_type
    FROM data.accounts
    WHERE uuid = p_to_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Destination account not found';
    END IF;

    -- Validate both accounts belong to the specified ledger
    IF v_from_account_ledger_id != v_ledger_id OR v_to_account_ledger_id != v_ledger_id THEN
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
    v_transaction_uuid := utils.nanoid();

    -- FIX: Swap debit and credit accounts
    -- In accounting: Debit increases assets, Credit decreases assets
    -- Transfer FROM account should be credited (decreased)
    -- Transfer TO account should be debited (increased)
    INSERT INTO data.transactions (
        uuid,
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        metadata
    )
    VALUES (
        v_transaction_uuid,
        v_ledger_id,
        p_date,
        COALESCE(p_memo, 'Transfer: ' || v_from_account_name || ' → ' || v_to_account_name),
        (p_amount * 100)::BIGINT,  -- Convert to cents
        v_to_account_id,           -- TO account is debited (increased)
        v_from_account_id,         -- FROM account is credited (decreased)
        jsonb_build_object('type', 'transfer', 'memo', p_memo)
    );

    RETURN v_transaction_uuid;
END;
$function$;
-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Revert to previous (buggy) version
CREATE OR REPLACE FUNCTION utils.add_account_transfer(
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
    v_transaction_uuid TEXT;
    v_from_account_name TEXT;
    v_to_account_name TEXT;
    v_from_account_ledger_id BIGINT;
    v_to_account_ledger_id BIGINT;
    v_from_account_type TEXT;
    v_to_account_type TEXT;
    v_ledger_id BIGINT;
    v_from_account_id BIGINT;
    v_to_account_id BIGINT;
BEGIN
    -- Validate amount is positive
    IF p_amount <= 0 THEN
        RAISE EXCEPTION 'Transfer amount must be positive';
    END IF;

    -- Validate accounts are different
    IF p_from_account_uuid = p_to_account_uuid THEN
        RAISE EXCEPTION 'Cannot transfer to the same account';
    END IF;

    -- Get ledger_id from ledger_uuid
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    -- Get account details and validate they exist
    SELECT id, name, ledger_id, type
    INTO v_from_account_id, v_from_account_name, v_from_account_ledger_id, v_from_account_type
    FROM data.accounts
    WHERE uuid = p_from_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Source account not found';
    END IF;

    SELECT id, name, ledger_id, type
    INTO v_to_account_id, v_to_account_name, v_to_account_ledger_id, v_to_account_type
    FROM data.accounts
    WHERE uuid = p_to_account_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Destination account not found';
    END IF;

    -- Validate both accounts belong to the specified ledger
    IF v_from_account_ledger_id != v_ledger_id OR v_to_account_ledger_id != v_ledger_id THEN
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
    v_transaction_uuid := utils.nanoid();

    -- Insert transaction (debit from source, credit to destination)
    INSERT INTO data.transactions (
        uuid,
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        metadata
    )
    VALUES (
        v_transaction_uuid,
        v_ledger_id,
        p_date,
        COALESCE(p_memo, 'Transfer: ' || v_from_account_name || ' → ' || v_to_account_name),
        (p_amount * 100)::BIGINT,  -- Convert to cents
        v_from_account_id,
        v_to_account_id,
        jsonb_build_object('type', 'transfer', 'memo', p_memo)
    );

    RETURN v_transaction_uuid;
END;
$function$;
-- +goose StatementEnd
