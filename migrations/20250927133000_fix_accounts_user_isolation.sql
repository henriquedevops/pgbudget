-- +goose Up
-- +goose StatementBegin

-- Force enable RLS for accounts table
ALTER TABLE data.accounts FORCE ROW LEVEL SECURITY;

-- Update the accounts API view to include explicit user filtering
CREATE OR REPLACE VIEW api.accounts WITH (security_invoker = true) AS
SELECT
    a.uuid,
    a.name,
    a.type,
    a.description,
    a.metadata,
    a.user_data,
    l.uuid::text AS ledger_uuid
FROM data.accounts a
JOIN data.ledgers l ON a.ledger_id = l.id
WHERE a.user_data = utils.get_user();

COMMENT ON VIEW api.accounts IS 'Provides a user-filtered view of accounts. Only shows accounts belonging to the current user context.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to regular RLS (not forced)
ALTER TABLE data.accounts NO FORCE ROW LEVEL SECURITY;

-- Restore original view without explicit WHERE clause
CREATE OR REPLACE VIEW api.accounts WITH (security_invoker = true) AS
SELECT
    a.uuid,
    a.name,
    a.type,
    a.description,
    a.metadata,
    a.user_data,
    l.uuid::text AS ledger_uuid
FROM data.accounts a
JOIN data.ledgers l ON a.ledger_id = l.id;

-- +goose StatementEnd