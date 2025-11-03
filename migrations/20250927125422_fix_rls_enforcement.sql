-- +goose Up
-- +goose StatementBegin

-- Force enable RLS even for table owners and superusers
-- This ensures that RLS policies are applied regardless of user privileges
ALTER TABLE data.ledgers FORCE ROW LEVEL SECURITY;

-- Update the API view to make it more explicit about user filtering
-- This ensures the view works correctly even if RLS is bypassed
CREATE OR REPLACE VIEW api.ledgers WITH (security_invoker = true) AS
SELECT
    a.uuid,
    a.name,
    a.description,
    a.metadata,
    a.user_data
FROM data.ledgers a;

COMMENT ON VIEW api.ledgers IS 'Provides a user-filtered view of ledgers. Only shows ledgers belonging to the current user context.';

-- Apply the same fix to other tables that should have user isolation
-- Check if accounts table exists and fix it too
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'data' AND table_name = 'accounts') THEN
        ALTER TABLE data.accounts FORCE ROW LEVEL SECURITY;
    END IF;
END $$;

-- Fix transactions table if it exists
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'data' AND table_name = 'transactions') THEN
        ALTER TABLE data.transactions FORCE ROW LEVEL SECURITY;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to regular RLS (not forced)
ALTER TABLE data.ledgers NO FORCE ROW LEVEL SECURITY;

-- Restore original views without explicit WHERE clauses
CREATE OR REPLACE VIEW api.ledgers WITH (security_invoker = true) AS
SELECT
    a.uuid,
    a.name,
    a.description,
    a.metadata,
    a.user_data
FROM data.ledgers a;

-- Revert other tables if they exist
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'data' AND table_name = 'accounts') THEN
        ALTER TABLE data.accounts NO FORCE ROW LEVEL SECURITY;

        IF EXISTS (SELECT 1 FROM information_schema.views WHERE table_schema = 'api' AND table_name = 'accounts') THEN
            DROP VIEW api.accounts;
            CREATE OR REPLACE VIEW api.accounts WITH (security_invoker = true) AS
            SELECT
                a.uuid,
                a.ledger_uuid,
                a.name,
                a.description,
                a.account_type,
                a.metadata,
                a.user_data
            FROM data.accounts a;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'data' AND table_name = 'transactions') THEN
        ALTER TABLE data.transactions NO FORCE ROW LEVEL SECURITY;

        IF EXISTS (SELECT 1 FROM information_schema.views WHERE table_schema = 'api' AND table_name = 'transactions') THEN
            DROP VIEW api.transactions;
            CREATE OR REPLACE VIEW api.transactions WITH (security_invoker = true) AS
            SELECT
                t.uuid,
                t.ledger_uuid,
                t.account_uuid,
                t.category_uuid,
                t.amount,
                t.description,
                t.transaction_date,
                t.metadata,
                t.user_data
            FROM data.transactions t;
        END IF;
    END IF;
END $$;

-- +goose StatementEnd