-- Migration: Recreate api.get_loans function
-- Created: 2025-11-01
-- Purpose: Recreate function that was dropped by CASCADE when fixing loan currency display

-- +goose Up
-- +goose StatementBegin

-- Recreate api.get_loans function
CREATE OR REPLACE FUNCTION api.get_loans(p_ledger_uuid text)
RETURNS SETOF api.loans
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
BEGIN
    -- Get ledger ID and verify ownership
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger with UUID % not found for current user', p_ledger_uuid;
    END IF;

    -- Return all loans for the ledger
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE ledger_uuid = p_ledger_uuid
    ORDER BY created_at DESC;
END;
$$;

COMMENT ON FUNCTION api.get_loans(text) IS
'Get all loans for a specific ledger';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION api.get_loans(text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.get_loans(text);

-- +goose StatementEnd
