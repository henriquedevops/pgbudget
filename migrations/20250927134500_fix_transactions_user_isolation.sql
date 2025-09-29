-- +goose Up
-- +goose StatementBegin

-- Force enable RLS for transactions table
ALTER TABLE data.transactions FORCE ROW LEVEL SECURITY;

-- Update the transactions API view to include explicit user filtering
-- Maintain the existing structure while adding user isolation
CREATE OR REPLACE VIEW api.transactions WITH (security_invoker = true) AS
SELECT
    t.uuid,
    t.description,
    t.amount,
    t.date,
    t.metadata,
    l.uuid as ledger_uuid,
    null::text as type,
    null::text as account_uuid,
    null::text as category_uuid
FROM data.transactions t
JOIN data.ledgers l ON t.ledger_id = l.id
WHERE t.user_data = utils.get_user();

COMMENT ON VIEW api.transactions IS 'Provides a user-filtered view of transactions. Only shows transactions belonging to the current user context.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to regular RLS (not forced)
ALTER TABLE data.transactions NO FORCE ROW LEVEL SECURITY;

-- Restore original view structure (without explicit user filtering)
-- This assumes the original view was created without the WHERE clause

-- +goose StatementEnd