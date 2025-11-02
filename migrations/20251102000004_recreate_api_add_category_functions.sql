-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- FIX: Recreate api.add_category functions
-- ============================================================================
-- These functions were dropped when api.accounts view was recreated during
-- the loan initial payment feature implementation. The CASCADE from dropping
-- api.loans view affected api.accounts view which these functions depend on.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.add_category
-- ----------------------------------------------------------------------------
-- Create a single category (equity account)
CREATE OR REPLACE FUNCTION api.add_category(
    ledger_uuid text,
    name text
) RETURNS SETOF api.accounts AS $$
DECLARE
    v_util_result data.accounts;
BEGIN
    v_util_result := utils.add_category(ledger_uuid, name);

    RETURN QUERY
        SELECT *
        FROM api.accounts a
        WHERE a.uuid = v_util_result.uuid;
END;
$$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.add_category IS
'Create a new category account. Returns the created account with all fields including group-related columns.';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.add_categories
-- ----------------------------------------------------------------------------
-- Batch create multiple categories
CREATE OR REPLACE FUNCTION api.add_categories(
    ledger_uuid text,
    names text[]
) RETURNS SETOF api.accounts AS $$
DECLARE
    v_account_record record;
BEGIN
    FOR v_account_record IN SELECT * FROM utils.add_categories(ledger_uuid, names)
    LOOP
        RETURN QUERY
            SELECT *
            FROM api.accounts a
            WHERE a.uuid = v_account_record.uuid;
    END LOOP;

    RETURN;
END;
$$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.add_categories IS
'Batch create multiple category accounts. Returns all created accounts with group-related columns.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.add_categories(text, text[]);
DROP FUNCTION IF EXISTS api.add_category(text, text);

-- +goose StatementEnd
