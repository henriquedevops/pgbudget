-- Migration: Fix add_transaction function ambiguity
-- Created: 2025-11-01
-- Purpose: Resolve ambiguous function call in api.add_transaction

-- +goose Up
-- +goose StatementBegin

-- Recreate api.add_transaction to explicitly call the correct utils function
CREATE OR REPLACE FUNCTION api.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL::text,
    p_payee_name text DEFAULT NULL::text
)
RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    -- Validate transaction type
    IF p_type NOT IN ('inflow', 'outflow') THEN
        RAISE EXCEPTION 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    END IF;

    -- Call the utils function with explicit parameter names to avoid ambiguity
    -- This calls the 9-parameter version with allow_overspending defaulted to false
    RETURN utils.add_transaction(
        p_ledger_uuid => p_ledger_uuid,
        p_date => p_date,
        p_description => p_description,
        p_type => p_type,
        p_amount => p_amount,
        p_account_uuid => p_account_uuid,
        p_category_uuid => p_category_uuid,
        p_payee_name => p_payee_name,
        p_allow_overspending => false
    );
END;
$$;

COMMENT ON FUNCTION api.add_transaction(text, date, text, text, bigint, text, text, text) IS
'Add a transaction via API (resolves ambiguity by explicitly calling 9-param utils version)';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION api.add_transaction(text, date, text, text, bigint, text, text, text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore original version (which will be ambiguous again)
CREATE OR REPLACE FUNCTION api.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL::text,
    p_payee_name text DEFAULT NULL::text
)
RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    IF p_type NOT IN ('inflow', 'outflow') THEN
        RAISE EXCEPTION 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    END IF;

    RETURN utils.add_transaction(
        p_ledger_uuid,
        p_date,
        p_description,
        p_type,
        p_amount,
        p_account_uuid,
        p_category_uuid,
        p_payee_name
    );
END;
$$;

-- +goose StatementEnd
