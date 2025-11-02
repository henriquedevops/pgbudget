-- Migration: Recreate api.get_loan function
-- Created: 2025-11-01
-- Purpose: Recreate function that was dropped by CASCADE when fixing loan currency display

-- +goose Up
-- +goose StatementBegin

-- Recreate api.get_loan function (singular - gets one loan by UUID)
CREATE OR REPLACE FUNCTION api.get_loan(p_loan_uuid text)
RETURNS SETOF api.loans
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_user_data text := utils.get_user();
BEGIN
    -- Return the loan with the specified UUID
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE uuid = p_loan_uuid
      AND ledger_uuid IN (
          SELECT uuid FROM api.ledgers WHERE uuid = api.loans.ledger_uuid
      )
    LIMIT 1;
END;
$$;

COMMENT ON FUNCTION api.get_loan(text) IS
'Get a specific loan by UUID';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION api.get_loan(text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.get_loan(text);

-- +goose StatementEnd
