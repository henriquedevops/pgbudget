-- Migration: Fix loan currency display values
-- Created: 2025-11-01
-- Purpose: Convert loan monetary values from numeric (dollars) to bigint (cents) for consistency

-- +goose Up
-- +goose StatementBegin

-- Drop the existing view first (can't change column types with CREATE OR REPLACE)
DROP VIEW IF EXISTS api.loans CASCADE;

-- Recreate api.loans view to convert monetary values to cents
CREATE VIEW api.loans AS
SELECT
    l.uuid,
    l.lender_name,
    l.loan_type,
    -- Convert principal_amount from dollars to cents (multiply by 100, cast to bigint)
    (l.principal_amount * 100)::bigint AS principal_amount,
    -- Convert current_balance from dollars to cents
    (l.current_balance * 100)::bigint AS current_balance,
    l.interest_rate,
    l.interest_type,
    l.compounding_frequency,
    l.loan_term_months,
    l.remaining_months,
    l.start_date,
    l.first_payment_date,
    -- Convert payment_amount from dollars to cents
    (l.payment_amount * 100)::bigint AS payment_amount,
    l.payment_frequency,
    l.payment_day_of_month,
    l.amortization_type,
    l.status,
    l.notes,
    l.metadata,
    l.created_at,
    l.updated_at,
    ledg.uuid AS ledger_uuid,
    acct.uuid AS account_uuid,
    acct.name AS account_name
FROM data.loans l
JOIN data.ledgers ledg ON ledg.id = l.ledger_id
LEFT JOIN data.accounts acct ON acct.id = l.account_id;

COMMENT ON VIEW api.loans IS
'View of loans with monetary values converted to cents (bigint) for consistency with the rest of the application';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT SELECT ON api.loans TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop the modified view
DROP VIEW IF EXISTS api.loans CASCADE;

-- Restore original view (values in dollars as numeric)
CREATE VIEW api.loans AS
SELECT
    l.uuid,
    l.lender_name,
    l.loan_type,
    l.principal_amount,
    l.current_balance,
    l.interest_rate,
    l.interest_type,
    l.compounding_frequency,
    l.loan_term_months,
    l.remaining_months,
    l.start_date,
    l.first_payment_date,
    l.payment_amount,
    l.payment_frequency,
    l.payment_day_of_month,
    l.amortization_type,
    l.status,
    l.notes,
    l.metadata,
    l.created_at,
    l.updated_at,
    ledg.uuid AS ledger_uuid,
    acct.uuid AS account_uuid,
    acct.name AS account_name
FROM data.loans l
JOIN data.ledgers ledg ON ledg.id = l.ledger_id
LEFT JOIN data.accounts acct ON acct.id = l.account_id;

-- +goose StatementEnd
