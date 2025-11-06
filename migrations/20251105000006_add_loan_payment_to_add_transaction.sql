-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- ADD LOAN PAYMENT SUPPORT TO api.add_transaction
-- ============================================================================
-- This migration adds support for linking transactions to loan payments
-- Part of Phase 2: LOAN_PAYMENTS_IN_TRANSACTIONS_PLAN.md
-- ============================================================================

-- ----------------------------------------------------------------------------
-- FUNCTION: api.add_transaction (with loan payment support)
-- ----------------------------------------------------------------------------
-- Overloaded version of add_transaction that accepts a loan_payment_uuid
-- Creates transaction and links it to a loan payment if specified
CREATE OR REPLACE FUNCTION api.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL::text,
    p_payee_name text DEFAULT NULL::text,
    p_loan_payment_uuid text DEFAULT NULL::text
)
RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_transaction_uuid text;
    v_payment_id bigint;
    v_loan_id bigint;
    v_loan_uuid text;
    v_current_balance numeric;
    v_interest_rate numeric;
    v_monthly_rate numeric;
    v_interest_amount bigint;
    v_principal_amount bigint;
    v_from_account_id bigint;
    v_user_data text := utils.get_user();
BEGIN
    -- Validate transaction type
    IF p_type NOT IN ('inflow', 'outflow') THEN
        RAISE EXCEPTION 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    END IF;

    -- Create the transaction first (using existing function)
    v_transaction_uuid := utils.add_transaction(
        p_ledger_uuid => p_ledger_uuid,
        p_date => p_date,
        p_description => p_description,
        p_type => p_type,
        p_amount => p_amount,
        p_account_uuid => p_account_uuid,
        p_category_uuid => p_category_uuid,
        p_payee_name => p_payee_name,
        p_allow_overspending => false
    );

    -- If loan_payment_uuid is provided, link the transaction to the payment
    IF p_loan_payment_uuid IS NOT NULL THEN
        -- Get payment details and verify ownership
        SELECT
            lp.id,
            lp.loan_id,
            l.uuid,
            l.current_balance,
            l.interest_rate
        INTO
            v_payment_id,
            v_loan_id,
            v_loan_uuid,
            v_current_balance,
            v_interest_rate
        FROM data.loan_payments lp
        JOIN data.loans l ON l.id = lp.loan_id
        WHERE lp.uuid = p_loan_payment_uuid
          AND lp.user_data = v_user_data;

        -- Verify payment exists
        IF v_payment_id IS NULL THEN
            RAISE EXCEPTION 'Loan payment with UUID % not found or does not belong to current user', p_loan_payment_uuid;
        END IF;

        -- Verify payment is not already linked
        IF EXISTS (
            SELECT 1 FROM data.loan_payments
            WHERE id = v_payment_id AND transaction_id IS NOT NULL
        ) THEN
            RAISE EXCEPTION 'Loan payment % is already linked to a transaction', p_loan_payment_uuid;
        END IF;

        -- Get the account ID from the transaction we just created
        -- For an outflow, the source account is the debit_account_id
        SELECT CASE
            WHEN p_type = 'outflow' THEN debit_account_id
            ELSE credit_account_id
        END INTO v_from_account_id
        FROM data.transactions
        WHERE uuid = v_transaction_uuid;

        -- Calculate interest and principal split
        -- Formula: monthly_interest = current_balance * (annual_rate / 100 / 12)
        v_monthly_rate := (v_interest_rate / 100) / 12;
        v_interest_amount := ROUND(v_current_balance * v_monthly_rate);
        v_principal_amount := p_amount - v_interest_amount;

        -- Ensure principal doesn't exceed current balance
        IF v_principal_amount > v_current_balance THEN
            v_principal_amount := v_current_balance;
            v_interest_amount := p_amount - v_principal_amount;
        END IF;

        -- Update the loan payment record
        UPDATE data.loan_payments
        SET
            transaction_id = (SELECT id FROM data.transactions WHERE uuid = v_transaction_uuid),
            paid_date = p_date,
            actual_amount_paid = p_amount,
            actual_principal = v_principal_amount,
            actual_interest = v_interest_amount,
            from_account_id = v_from_account_id,
            status = 'paid',
            updated_at = current_timestamp
        WHERE id = v_payment_id;

        -- The trigger update_loan_balance_on_payment will automatically update the loan balance
    END IF;

    RETURN v_transaction_uuid;
END;
$$;

COMMENT ON FUNCTION api.add_transaction(text, date, text, text, bigint, text, text, text, text) IS
'Add a transaction with optional loan payment linking (9-parameter version with loan_payment_uuid)';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION api.add_transaction(text, date, text, text, bigint, text, text, text, text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop the new overloaded version
DROP FUNCTION IF EXISTS api.add_transaction(text, date, text, text, bigint, text, text, text, text);

-- +goose StatementEnd
