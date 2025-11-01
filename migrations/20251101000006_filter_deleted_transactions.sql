-- Migration: Filter deleted transactions from all queries
-- Created: 2025-11-01
-- Purpose: Ensure deleted transactions don't show up anywhere in the application

-- +goose Up
-- +goose StatementBegin

-- 1. Update api.transactions view to filter deleted transactions
CREATE OR REPLACE VIEW api.transactions AS
SELECT
    t.uuid,
    t.description,
    t.amount,
    t.date,
    t.metadata,
    l.uuid AS ledger_uuid,
    NULL::text AS type,
    NULL::text AS account_uuid,
    NULL::text AS category_uuid
FROM data.transactions t
JOIN data.ledgers l ON t.ledger_id = l.id
WHERE t.user_data = utils.get_user()
  AND t.deleted_at IS NULL;  -- ADDED: Filter deleted transactions

COMMENT ON VIEW api.transactions IS
'View of all active (non-deleted) transactions for the current user';

-- 2. Update utils.get_category_transactions to filter deleted transactions
CREATE OR REPLACE FUNCTION utils.get_category_transactions(
    p_category_id bigint,
    p_start_date timestamp with time zone,
    p_end_date timestamp with time zone,
    p_user_data text
)
RETURNS TABLE(
    transaction_uuid text,
    transaction_date date,
    description text,
    amount bigint,
    other_account_name text,
    other_account_type text
)
LANGUAGE plpgsql
STABLE SECURITY DEFINER
AS $$
BEGIN
    RETURN QUERY
    SELECT
        t.uuid AS transaction_uuid,
        t.date AS transaction_date,
        t.description,
        t.amount,
        credit_acct.name AS other_account_name,
        credit_acct.type AS other_account_type
    FROM data.transactions t
    JOIN data.accounts credit_acct ON t.credit_account_id = credit_acct.id
    WHERE t.debit_account_id = p_category_id
      AND t.date >= p_start_date
      AND t.date <= p_end_date
      AND t.user_data = p_user_data
      AND t.deleted_at IS NULL  -- ADDED: Filter deleted transactions
      AND credit_acct.type != 'equity'  -- Only real spending transactions
    ORDER BY t.date DESC, t.created_at DESC;
END;
$$;

COMMENT ON FUNCTION utils.get_category_transactions(bigint, timestamp with time zone, timestamp with time zone, text) IS
'Get all active (non-deleted) transactions for a category within a date range';

-- Grant execute permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        -- Re-grant view permissions
        GRANT SELECT ON api.transactions TO pgbudget_user;
        -- Re-grant function permissions
        GRANT EXECUTE ON FUNCTION utils.get_category_transactions(bigint, timestamp with time zone, timestamp with time zone, text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore original view (without deleted_at filter)
CREATE OR REPLACE VIEW api.transactions AS
SELECT
    t.uuid,
    t.description,
    t.amount,
    t.date,
    t.metadata,
    l.uuid AS ledger_uuid,
    NULL::text AS type,
    NULL::text AS account_uuid,
    NULL::text AS category_uuid
FROM data.transactions t
JOIN data.ledgers l ON t.ledger_id = l.id
WHERE t.user_data = utils.get_user();

-- Restore original function (without deleted_at filter)
CREATE OR REPLACE FUNCTION utils.get_category_transactions(
    p_category_id bigint,
    p_start_date timestamp with time zone,
    p_end_date timestamp with time zone,
    p_user_data text
)
RETURNS TABLE(
    transaction_uuid text,
    transaction_date date,
    description text,
    amount bigint,
    other_account_name text,
    other_account_type text
)
LANGUAGE plpgsql
STABLE SECURITY DEFINER
AS $$
BEGIN
    RETURN QUERY
    SELECT
        t.uuid AS transaction_uuid,
        t.date AS transaction_date,
        t.description,
        t.amount,
        credit_acct.name AS other_account_name,
        credit_acct.type AS other_account_type
    FROM data.transactions t
    JOIN data.accounts credit_acct ON t.credit_account_id = credit_acct.id
    WHERE t.debit_account_id = p_category_id
      AND t.date >= p_start_date
      AND t.date <= p_end_date
      AND t.user_data = p_user_data
      AND credit_acct.type != 'equity'
    ORDER BY t.date DESC, t.created_at DESC;
END;
$$;

-- +goose StatementEnd
