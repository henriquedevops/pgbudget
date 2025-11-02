-- Migration: Fix loan payments currency display values
-- Created: 2025-11-01
-- Purpose: Convert loan payment monetary values from numeric (dollars) to bigint (cents)

-- +goose Up
-- +goose StatementBegin

-- Drop the existing view first (CASCADE will also drop api.get_loan_payments function)
DROP VIEW IF EXISTS api.loan_payments CASCADE;

-- Recreate api.loan_payments view to convert monetary values to cents
CREATE VIEW api.loan_payments AS
SELECT
    lp.uuid,
    lp.payment_number,
    lp.due_date,
    -- Convert scheduled amounts from dollars to cents
    (lp.scheduled_amount * 100)::bigint AS scheduled_amount,
    (lp.scheduled_principal * 100)::bigint AS scheduled_principal,
    (lp.scheduled_interest * 100)::bigint AS scheduled_interest,
    lp.paid_date,
    -- Convert actual amounts from dollars to cents (handle NULL values)
    CASE WHEN lp.actual_amount_paid IS NOT NULL THEN (lp.actual_amount_paid * 100)::bigint ELSE NULL END AS actual_amount_paid,
    CASE WHEN lp.actual_principal IS NOT NULL THEN (lp.actual_principal * 100)::bigint ELSE NULL END AS actual_principal,
    CASE WHEN lp.actual_interest IS NOT NULL THEN (lp.actual_interest * 100)::bigint ELSE NULL END AS actual_interest,
    lp.status,
    lp.days_late,
    lp.notes,
    lp.created_at,
    lp.updated_at,
    l.uuid AS loan_uuid,
    t.uuid AS transaction_uuid,
    a.uuid AS from_account_uuid,
    a.name AS from_account_name
FROM data.loan_payments lp
JOIN data.loans l ON l.id = lp.loan_id
LEFT JOIN data.transactions t ON t.id = lp.transaction_id
LEFT JOIN data.accounts a ON a.id = lp.from_account_id;

COMMENT ON VIEW api.loan_payments IS
'View of loan payments with monetary values converted to cents (bigint) for consistency';

-- Recreate api.get_loan_payments function
CREATE OR REPLACE FUNCTION api.get_loan_payments(p_loan_uuid text)
RETURNS SETOF api.loan_payments
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_loan_id bigint;
    v_user_data text := utils.get_user();
BEGIN
    -- Get loan ID and verify ownership
    SELECT id INTO v_loan_id
    FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    IF v_loan_id IS NULL THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    -- Return all payments for the loan
    RETURN QUERY
    SELECT * FROM api.loan_payments
    WHERE loan_uuid = p_loan_uuid
    ORDER BY payment_number;
END;
$$;

COMMENT ON FUNCTION api.get_loan_payments(text) IS
'Get all payments for a specific loan';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT SELECT ON api.loan_payments TO pgbudget_user;
        GRANT EXECUTE ON FUNCTION api.get_loan_payments(text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop the modified view and function
DROP VIEW IF EXISTS api.loan_payments CASCADE;
DROP FUNCTION IF EXISTS api.get_loan_payments(text);

-- Restore original view (values in dollars as numeric)
CREATE VIEW api.loan_payments AS
SELECT
    lp.uuid,
    lp.payment_number,
    lp.due_date,
    lp.scheduled_amount,
    lp.scheduled_principal,
    lp.scheduled_interest,
    lp.paid_date,
    lp.actual_amount_paid,
    lp.actual_principal,
    lp.actual_interest,
    lp.status,
    lp.days_late,
    lp.notes,
    lp.created_at,
    lp.updated_at,
    l.uuid AS loan_uuid,
    t.uuid AS transaction_uuid,
    a.uuid AS from_account_uuid,
    a.name AS from_account_name
FROM data.loan_payments lp
JOIN data.loans l ON l.id = lp.loan_id
LEFT JOIN data.transactions t ON t.id = lp.transaction_id
LEFT JOIN data.accounts a ON a.id = lp.from_account_id;

-- Restore function
CREATE OR REPLACE FUNCTION api.get_loan_payments(p_loan_uuid text)
RETURNS SETOF api.loan_payments
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_loan_id bigint;
    v_user_data text := utils.get_user();
BEGIN
    SELECT id INTO v_loan_id
    FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    IF v_loan_id IS NULL THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    RETURN QUERY
    SELECT * FROM api.loan_payments
    WHERE loan_uuid = p_loan_uuid
    ORDER BY payment_number;
END;
$$;

-- +goose StatementEnd
