-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- RECREATE LOAN API FUNCTIONS WITH INITIAL PAYMENT SUPPORT
-- ============================================================================
-- This migration recreates the loan API functions to support the new
-- initial_amount_paid and initial_paid_as_of_date fields
-- ============================================================================

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.get_loans
-- ----------------------------------------------------------------------------
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

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.get_loan
-- ----------------------------------------------------------------------------
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

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.create_loan
-- ----------------------------------------------------------------------------
-- Updated to support initial payment tracking
CREATE OR REPLACE FUNCTION api.create_loan(
    p_ledger_uuid text,
    p_lender_name text,
    p_loan_type text,
    p_principal_amount numeric,
    p_interest_rate numeric,
    p_loan_term_months integer,
    p_start_date date,
    p_first_payment_date date,
    p_payment_frequency text,
    p_account_uuid text DEFAULT NULL,
    p_interest_type text DEFAULT 'fixed',
    p_compounding_frequency text DEFAULT 'monthly',
    p_payment_day_of_month integer DEFAULT NULL,
    p_amortization_type text DEFAULT 'standard',
    p_notes text DEFAULT NULL,
    p_initial_amount_paid numeric DEFAULT 0,
    p_initial_paid_as_of_date date DEFAULT NULL
) RETURNS SETOF api.loans AS $apifunc$
DECLARE
    v_ledger_id bigint;
    v_account_id bigint;
    v_user_data text := utils.get_user();
    v_loan_uuid text;
    v_payment_amount numeric;
    v_monthly_rate numeric;
    v_current_balance numeric;
    v_remaining_months integer;
BEGIN
    -- Validate loan type
    IF p_loan_type NOT IN ('mortgage', 'auto', 'personal', 'student', 'credit_line', 'other') THEN
        RAISE EXCEPTION 'Invalid loan type: %. Must be one of: mortgage, auto, personal, student, credit_line, other', p_loan_type;
    END IF;

    -- Validate interest type
    IF p_interest_type NOT IN ('fixed', 'variable') THEN
        RAISE EXCEPTION 'Invalid interest type: %. Must be fixed or variable', p_interest_type;
    END IF;

    -- Validate payment frequency
    IF p_payment_frequency NOT IN ('monthly', 'bi-weekly', 'weekly', 'quarterly') THEN
        RAISE EXCEPTION 'Invalid payment frequency: %. Must be one of: monthly, bi-weekly, weekly, quarterly', p_payment_frequency;
    END IF;

    -- Validate compounding frequency
    IF p_compounding_frequency NOT IN ('daily', 'monthly', 'annually') THEN
        RAISE EXCEPTION 'Invalid compounding frequency: %. Must be one of: daily, monthly, annually', p_compounding_frequency;
    END IF;

    -- Validate amortization type
    IF p_amortization_type NOT IN ('standard', 'interest_only', 'balloon') THEN
        RAISE EXCEPTION 'Invalid amortization type: %. Must be one of: standard, interest_only, balloon', p_amortization_type;
    END IF;

    -- Validate amounts
    IF p_principal_amount <= 0 THEN
        RAISE EXCEPTION 'Principal amount must be positive: %', p_principal_amount;
    END IF;

    IF p_interest_rate < 0 OR p_interest_rate > 100 THEN
        RAISE EXCEPTION 'Interest rate must be between 0 and 100: %', p_interest_rate;
    END IF;

    IF p_loan_term_months <= 0 THEN
        RAISE EXCEPTION 'Loan term must be positive: %', p_loan_term_months;
    END IF;

    -- Validate initial payment
    IF p_initial_amount_paid < 0 THEN
        RAISE EXCEPTION 'Initial amount paid cannot be negative: %', p_initial_amount_paid;
    END IF;

    IF p_initial_amount_paid > p_principal_amount THEN
        RAISE EXCEPTION 'Initial amount paid cannot exceed principal amount';
    END IF;

    -- Validate dates
    IF p_first_payment_date < p_start_date THEN
        RAISE EXCEPTION 'First payment date cannot be before start date';
    END IF;

    IF p_initial_paid_as_of_date IS NOT NULL AND p_initial_paid_as_of_date < p_start_date THEN
        RAISE EXCEPTION 'Initial paid as-of date cannot be before start date';
    END IF;

    -- Get ledger ID and verify ownership
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger with UUID % not found for current user', p_ledger_uuid;
    END IF;

    -- Get account ID if provided and verify it's a liability account
    IF p_account_uuid IS NOT NULL THEN
        SELECT id INTO v_account_id
        FROM data.accounts
        WHERE uuid = p_account_uuid
          AND ledger_id = v_ledger_id
          AND user_data = v_user_data
          AND type = 'liability';

        IF v_account_id IS NULL THEN
            RAISE EXCEPTION 'Account with UUID % not found or not a liability account for this ledger', p_account_uuid;
        END IF;
    END IF;

    -- Calculate payment amount using standard amortization formula
    v_monthly_rate := (p_interest_rate / 100) / 12;

    IF v_monthly_rate > 0 THEN
        v_payment_amount := p_principal_amount *
            (v_monthly_rate * POWER(1 + v_monthly_rate, p_loan_term_months)) /
            (POWER(1 + v_monthly_rate, p_loan_term_months) - 1);
    ELSE
        v_payment_amount := p_principal_amount / p_loan_term_months;
    END IF;

    v_payment_amount := ROUND(v_payment_amount, 2);

    -- Calculate current balance and remaining months
    v_current_balance := p_principal_amount - p_initial_amount_paid;

    -- Estimate remaining months based on the current balance
    -- This is a simplified calculation - actual remaining months would need
    -- to recalculate the amortization schedule
    IF p_initial_amount_paid > 0 AND p_principal_amount > 0 THEN
        -- Approximate remaining months as a percentage of the remaining balance
        v_remaining_months := CEIL(p_loan_term_months * (v_current_balance / p_principal_amount));
    ELSE
        v_remaining_months := p_loan_term_months;
    END IF;

    -- Insert the loan
    INSERT INTO data.loans (
        ledger_id,
        account_id,
        lender_name,
        loan_type,
        principal_amount,
        current_balance,
        interest_rate,
        interest_type,
        compounding_frequency,
        loan_term_months,
        remaining_months,
        start_date,
        first_payment_date,
        payment_amount,
        payment_frequency,
        payment_day_of_month,
        amortization_type,
        status,
        notes,
        initial_amount_paid,
        initial_paid_as_of_date,
        user_data
    )
    VALUES (
        v_ledger_id,
        v_account_id,
        p_lender_name,
        p_loan_type,
        p_principal_amount,
        v_current_balance,
        p_interest_rate,
        p_interest_type,
        p_compounding_frequency,
        p_loan_term_months,
        v_remaining_months,
        p_start_date,
        p_first_payment_date,
        v_payment_amount,
        p_payment_frequency,
        p_payment_day_of_month,
        p_amortization_type,
        'active',
        p_notes,
        p_initial_amount_paid,
        p_initial_paid_as_of_date,
        v_user_data
    )
    RETURNING uuid INTO v_loan_uuid;

    -- Return the created loan
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE uuid = v_loan_uuid;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.create_loan IS
'Create a new loan with automatic payment amount calculation and support for initial payments already made';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.create_loan(text, text, text, numeric, numeric, integer, date, date, text, text, text, text, integer, text, text, numeric, date);
DROP FUNCTION IF EXISTS api.get_loan(text);
DROP FUNCTION IF EXISTS api.get_loans(text);

-- +goose StatementEnd
