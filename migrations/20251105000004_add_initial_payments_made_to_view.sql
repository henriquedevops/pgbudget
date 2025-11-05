-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- ADD initial_payments_made TO api.loans VIEW
-- ============================================================================
-- This migration updates the api.loans view to include the initial_payments_made field
-- ============================================================================

DROP VIEW IF EXISTS api.loans CASCADE;

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
    acct.name AS account_name,
    -- Add initial payment tracking fields
    l.initial_amount_paid,
    l.initial_paid_as_of_date,
    l.initial_payments_made
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

-- Recreate dependent functions

-- api.get_loans
CREATE OR REPLACE FUNCTION api.get_loans(
    p_ledger_uuid text
) RETURNS SETOF api.loans AS $apifunc$
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
$apifunc$ LANGUAGE plpgsql STABLE SECURITY INVOKER;

COMMENT ON FUNCTION api.get_loans(text) IS
'Get all loans for a specific ledger';

-- api.get_loan
CREATE OR REPLACE FUNCTION api.get_loan(
    p_loan_uuid text
) RETURNS SETOF api.loans AS $apifunc$
DECLARE
    v_user_data text := utils.get_user();
BEGIN
    -- Return the loan (RLS ensures user ownership)
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE uuid = p_loan_uuid;

    -- Raise exception if not found
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;
END;
$apifunc$ LANGUAGE plpgsql STABLE SECURITY INVOKER;

COMMENT ON FUNCTION api.get_loan(text) IS
'Get a single loan by its UUID';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous view without initial_payments_made
DROP VIEW IF EXISTS api.loans CASCADE;

CREATE VIEW api.loans AS
SELECT
    l.uuid,
    l.lender_name,
    l.loan_type,
    (l.principal_amount * 100)::bigint AS principal_amount,
    (l.current_balance * 100)::bigint AS current_balance,
    l.interest_rate,
    l.interest_type,
    l.compounding_frequency,
    l.loan_term_months,
    l.remaining_months,
    l.start_date,
    l.first_payment_date,
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
    acct.name AS account_name,
    l.initial_amount_paid,
    l.initial_paid_as_of_date
FROM data.loans l
JOIN data.ledgers ledg ON ledg.id = l.ledger_id
LEFT JOIN data.accounts acct ON acct.id = l.account_id;

-- Recreate functions
CREATE OR REPLACE FUNCTION api.get_loans(p_ledger_uuid text)
RETURNS SETOF api.loans AS $$
DECLARE
    v_ledger_id bigint;
    v_user_data text := utils.get_user();
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger with UUID % not found for current user', p_ledger_uuid;
    END IF;

    RETURN QUERY
    SELECT * FROM api.loans
    WHERE ledger_uuid = p_ledger_uuid
    ORDER BY created_at DESC;
END;
$$ LANGUAGE plpgsql STABLE SECURITY INVOKER;

CREATE OR REPLACE FUNCTION api.get_loan(p_loan_uuid text)
RETURNS SETOF api.loans AS $$
DECLARE
    v_user_data text := utils.get_user();
BEGIN
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE uuid = p_loan_uuid;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;
END;
$$ LANGUAGE plpgsql STABLE SECURITY INVOKER;

-- +goose StatementEnd
