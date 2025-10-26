-- +goose Up
-- +goose StatementBegin

-- Fix: Update api.accounts view to include category group columns
-- The category groups migration (20251016300000) added is_group, parent_category_id,
-- and sort_order columns to data.accounts, but the api.accounts view was not updated.
-- This migration updates the view to expose these columns.

DROP VIEW IF EXISTS api.accounts CASCADE;

CREATE VIEW api.accounts AS
SELECT
    a.uuid,
    a.name,
    a.type,
    a.description,
    a.metadata,
    a.user_data,
    l.uuid AS ledger_uuid,
    a.is_group,
    a.parent_category_id,
    p.uuid AS parent_uuid,
    a.sort_order
FROM data.accounts a
JOIN data.ledgers l ON a.ledger_id = l.id
LEFT JOIN data.accounts p ON a.parent_category_id = p.id
WHERE a.user_data = utils.get_user();

ALTER VIEW api.accounts SET (security_invoker = true);

COMMENT ON VIEW api.accounts IS 'Public API view of accounts with category group support. Includes is_group, parent_uuid, and sort_order for hierarchical category organization.';

-- Recreate api.add_category function (dropped by CASCADE)
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

COMMENT ON FUNCTION api.add_category IS 'Create a new category account. Returns the created account with all fields including group-related columns.';

-- Recreate api.add_categories function (dropped by CASCADE)
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

COMMENT ON FUNCTION api.add_categories IS 'Batch create multiple category accounts. Returns all created accounts with group-related columns.';

-- Recreate triggers for api.accounts view (dropped by CASCADE)
CREATE TRIGGER accounts_insert_tg
    INSTEAD OF INSERT
    ON api.accounts
    FOR EACH ROW
EXECUTE FUNCTION utils.accounts_insert_single_fn();

CREATE TRIGGER accounts_update_tg
    INSTEAD OF UPDATE
    ON api.accounts
    FOR EACH ROW
EXECUTE PROCEDURE utils.accounts_update_single_fn();

CREATE TRIGGER accounts_delete_tg
    INSTEAD OF DELETE
    ON api.accounts
    FOR EACH ROW
EXECUTE PROCEDURE utils.accounts_delete_single_fn();

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to original api.accounts view without category group columns
DROP VIEW IF EXISTS api.accounts CASCADE;

CREATE VIEW api.accounts AS
SELECT
    a.uuid,
    a.name,
    a.type,
    a.description,
    a.metadata,
    a.user_data,
    l.uuid AS ledger_uuid
FROM data.accounts a
JOIN data.ledgers l ON a.ledger_id = l.id
WHERE a.user_data = utils.get_user();

ALTER VIEW api.accounts SET (security_invoker = true);

-- Recreate the functions with old view definition
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

-- Recreate triggers
CREATE TRIGGER accounts_insert_tg
    INSTEAD OF INSERT
    ON api.accounts
    FOR EACH ROW
EXECUTE FUNCTION utils.accounts_insert_single_fn();

CREATE TRIGGER accounts_update_tg
    INSTEAD OF UPDATE
    ON api.accounts
    FOR EACH ROW
EXECUTE PROCEDURE utils.accounts_update_single_fn();

CREATE TRIGGER accounts_delete_tg
    INSTEAD OF DELETE
    ON api.accounts
    FOR EACH ROW
EXECUTE PROCEDURE utils.accounts_delete_single_fn();

-- +goose StatementEnd
