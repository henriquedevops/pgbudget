-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UPDATE api.create_loan TO SUPPORT initial_payments_made
-- ============================================================================
-- This migration updates the api.create_loan function to accept and store
-- the number of payments already made on a loan
-- ============================================================================

-- Drop the existing function first
DROP FUNCTION IF EXISTS api.create_loan(text, text, text, numeric, numeric, integer, date, date, text, text, text, text, integer, text, text, numeric, date);

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
    p_initial_paid_as_of_date date DEFAULT NULL,
    p_initial_payments_made integer DEFAULT 0
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
    v_calculated_amount_paid numeric;
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

    -- Validate initial payments
    IF p_initial_payments_made < 0 THEN
        RAISE EXCEPTION 'Initial payments made cannot be negative: %', p_initial_payments_made;
    END IF;

    IF p_initial_payments_made >= p_loan_term_months THEN
        RAISE EXCEPTION 'Initial payments made cannot exceed or equal loan term: %', p_initial_payments_made;
    END IF;

    IF p_initial_amount_paid < 0 THEN
        RAISE EXCEPTION 'Initial amount paid cannot be negative: %', p_initial_amount_paid;
    END IF;

    IF p_initial_amount_paid > p_principal_amount THEN
        RAISE EXCEPTION 'Initial amount paid cannot exceed principal: %', p_initial_amount_paid;
    END IF;

    -- Get ledger ID and verify ownership
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger with UUID % not found for current user', p_ledger_uuid;
    END IF;

    -- Get account ID if provided
    IF p_account_uuid IS NOT NULL THEN
        SELECT id INTO v_account_id
        FROM data.accounts
        WHERE uuid = p_account_uuid
          AND user_data = v_user_data;

        IF v_account_id IS NULL THEN
            RAISE EXCEPTION 'Account with UUID % not found for current user', p_account_uuid;
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

    -- Round to 2 decimal places
    v_payment_amount := ROUND(v_payment_amount, 2);

    -- Calculate current balance and remaining months based on payments already made
    IF p_initial_payments_made > 0 THEN
        -- Calculate total amount paid based on payment schedule
        -- For simplicity, we'll use the payment amount * number of payments
        -- This assumes all previous payments were the standard amount
        v_calculated_amount_paid := v_payment_amount * p_initial_payments_made;

        -- Use the provided initial_amount_paid if given, otherwise use calculated
        IF p_initial_amount_paid > 0 THEN
            v_current_balance := p_principal_amount - p_initial_amount_paid;
        ELSE
            v_current_balance := p_principal_amount - v_calculated_amount_paid;
        END IF;

        -- Ensure balance doesn't go negative
        v_current_balance := GREATEST(0, v_current_balance);

        -- Calculate remaining months
        v_remaining_months := p_loan_term_months - p_initial_payments_made;
    ELSIF p_initial_amount_paid > 0 THEN
        -- If only amount is provided without payment count, calculate balance
        v_current_balance := p_principal_amount - p_initial_amount_paid;
        v_remaining_months := p_loan_term_months;
    ELSE
        -- No initial payments
        v_current_balance := p_principal_amount;
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
        initial_payments_made,
        user_data
    ) VALUES (
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
        COALESCE(p_initial_amount_paid, 0),
        p_initial_paid_as_of_date,
        p_initial_payments_made,
        v_user_data
    )
    RETURNING uuid INTO v_loan_uuid;

    -- Return the created loan
    RETURN QUERY
    SELECT * FROM api.loans WHERE uuid = v_loan_uuid;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.create_loan IS
'Create a new loan with support for tracking payments already made before tracking began';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous version without initial_payments_made parameter
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
        RAISE EXCEPTION 'Initial amount paid cannot exceed principal: %', p_initial_amount_paid;
    END IF;

    -- Get ledger ID and verify ownership
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = v_user_data;

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger with UUID % not found for current user', p_ledger_uuid;
    END IF;

    -- Get account ID if provided
    IF p_account_uuid IS NOT NULL THEN
        SELECT id INTO v_account_id
        FROM data.accounts
        WHERE uuid = p_account_uuid
          AND user_data = v_user_data;

        IF v_account_id IS NULL THEN
            RAISE EXCEPTION 'Account with UUID % not found for current user', p_account_uuid;
        END IF;
    END IF;

    -- Calculate payment amount
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
    IF p_initial_amount_paid > 0 THEN
        v_current_balance := p_principal_amount - p_initial_amount_paid;
        v_remaining_months := p_loan_term_months;
    ELSE
        v_current_balance := p_principal_amount;
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
    ) VALUES (
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
        COALESCE(p_initial_amount_paid, 0),
        p_initial_paid_as_of_date,
        v_user_data
    )
    RETURNING uuid INTO v_loan_uuid;

    -- Return the created loan
    RETURN QUERY
    SELECT * FROM api.loans WHERE uuid = v_loan_uuid;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

-- +goose StatementEnd
