-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- HELPER VIEW FOR UNPAID LOAN PAYMENTS
-- ============================================================================
-- This migration creates a helper view for efficiently fetching unpaid loan
-- payments, which will be used in the "Add Transaction" feature to allow
-- users to link transactions to scheduled loan payments.
-- Part of Phase 1: LOAN_PAYMENTS_IN_TRANSACTIONS_PLAN.md
-- ============================================================================

-- ----------------------------------------------------------------------------
-- API VIEW: api.unpaid_loan_payments
-- ----------------------------------------------------------------------------
-- Provides a filtered view of loan payments that are not yet paid, along with
-- helpful metadata for the UI (days until/past due, loan details)
CREATE OR REPLACE VIEW api.unpaid_loan_payments WITH (security_invoker = true) AS
SELECT
    lp.uuid,
    lp.payment_number,
    lp.due_date,
    lp.scheduled_amount,
    lp.scheduled_principal,
    lp.scheduled_interest,
    lp.status,
    lp.notes,
    lp.created_at,
    lp.updated_at,
    -- Loan information
    l.uuid as loan_uuid,
    l.lender_name,
    l.loan_type,
    l.current_balance as loan_current_balance,
    l.payment_frequency,
    -- Ledger information
    ledg.uuid as ledger_uuid,
    ledg.name as ledger_name,
    -- Account information
    acct.uuid as account_uuid,
    acct.name as account_name,
    -- Calculated fields
    CASE
        WHEN lp.due_date > CURRENT_DATE THEN lp.due_date - CURRENT_DATE
        ELSE 0
    END as days_until_due,
    CASE
        WHEN lp.due_date < CURRENT_DATE THEN CURRENT_DATE - lp.due_date
        ELSE 0
    END as days_past_due,
    CASE
        WHEN lp.due_date > CURRENT_DATE THEN 'upcoming'
        WHEN lp.due_date = CURRENT_DATE THEN 'due_today'
        ELSE 'overdue'
    END as payment_status
FROM data.loan_payments lp
JOIN data.loans l ON l.id = lp.loan_id
JOIN data.ledgers ledg ON ledg.id = l.ledger_id
LEFT JOIN data.accounts acct ON acct.id = l.account_id
WHERE lp.status IN ('scheduled', 'late', 'missed', 'partial')
  AND lp.transaction_id IS NULL;  -- Only show payments not yet linked to a transaction

COMMENT ON VIEW api.unpaid_loan_payments IS
'Filtered view of unpaid loan payments with helpful metadata for transaction linking UI';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop the helper view
DROP VIEW IF EXISTS api.unpaid_loan_payments;

-- +goose StatementEnd
