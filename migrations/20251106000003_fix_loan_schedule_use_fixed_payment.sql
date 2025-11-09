-- Migration: Fix loan schedule to use fixed payment amount
-- Created: 2025-11-06
-- Purpose: Fix bug where schedule generation with initial payments recalculates
--          payment amount instead of using the loan's fixed payment_amount

-- +goose Up
-- +goose StatementBegin

-- Create new overloaded version of calculate_amortization_schedule that accepts fixed payment
CREATE OR REPLACE FUNCTION utils.calculate_amortization_schedule(
    p_principal numeric,
    p_annual_rate numeric,
    p_term_months integer,
    p_start_date date,
    p_fixed_payment numeric DEFAULT NULL  -- New optional parameter
)
RETURNS TABLE(
    payment_number integer,
    due_date date,
    payment_amount numeric,
    principal_amount numeric,
    interest_amount numeric,
    remaining_balance numeric
)
LANGUAGE plpgsql
IMMUTABLE
AS $$
DECLARE
    v_monthly_rate numeric;
    v_payment_amount numeric;
    v_balance numeric;
    v_interest numeric;
    v_principal numeric;
    v_payment_date date;
    i integer;
BEGIN
    -- Calculate monthly interest rate
    v_monthly_rate := (p_annual_rate / 100) / 12;

    -- Use fixed payment if provided, otherwise calculate it
    IF p_fixed_payment IS NOT NULL THEN
        v_payment_amount := p_fixed_payment;
    ELSE
        -- Calculate payment amount
        IF v_monthly_rate > 0 THEN
            v_payment_amount := p_principal *
                (v_monthly_rate * POWER(1 + v_monthly_rate, p_term_months)) /
                (POWER(1 + v_monthly_rate, p_term_months) - 1);
        ELSE
            v_payment_amount := p_principal / p_term_months;
        END IF;
        v_payment_amount := ROUND(v_payment_amount, 2);
    END IF;

    v_balance := p_principal;

    -- Generate payment schedule
    FOR i IN 1..p_term_months LOOP
        -- Calculate payment date
        v_payment_date := p_start_date + ((i - 1) || ' months')::interval;

        -- Calculate interest for this payment
        v_interest := ROUND(v_balance * v_monthly_rate, 2);

        -- Calculate principal for this payment
        v_principal := v_payment_amount - v_interest;

        -- Adjust last payment to account for rounding and pay off remaining balance
        IF i = p_term_months THEN
            v_principal := v_balance;
            v_payment_amount := v_principal + v_interest;
        END IF;

        -- Update balance
        v_balance := v_balance - v_principal;

        -- Return this payment
        payment_number := i;
        due_date := v_payment_date;
        payment_amount := v_payment_amount;
        principal_amount := v_principal;
        interest_amount := v_interest;
        remaining_balance := GREATEST(0, v_balance);

        RETURN NEXT;
    END LOOP;
END;
$$;

COMMENT ON FUNCTION utils.calculate_amortization_schedule(numeric, numeric, integer, date, numeric) IS
'Calculate loan amortization schedule with optional fixed payment amount';

-- Update generate_loan_schedule to use fixed payment amount
DROP FUNCTION IF EXISTS api.generate_loan_schedule(text);

CREATE OR REPLACE FUNCTION api.generate_loan_schedule(
    p_loan_uuid text
) RETURNS integer AS $apifunc$
DECLARE
    v_loan_id bigint;
    v_principal numeric;
    v_current_balance numeric;
    v_interest_rate numeric;
    v_term_months integer;
    v_first_payment_date date;
    v_initial_payments_made integer;
    v_payment_amount numeric;  -- NEW: Store the fixed payment amount
    v_user_data text := utils.get_user();
    v_payment_count integer := 0;
    v_schedule_rec record;
    v_payment_number integer;
    v_remaining_term integer;
BEGIN
    -- Get loan details and verify ownership
    SELECT
        id,
        principal_amount,
        current_balance,
        interest_rate,
        loan_term_months,
        first_payment_date,
        payment_amount,  -- NEW: Get fixed payment amount
        COALESCE(initial_payments_made, 0)
    INTO
        v_loan_id,
        v_principal,
        v_current_balance,
        v_interest_rate,
        v_term_months,
        v_first_payment_date,
        v_payment_amount,  -- NEW: Store it
        v_initial_payments_made
    FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    IF v_loan_id IS NULL THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    -- Check if schedule already exists
    IF EXISTS(SELECT 1 FROM data.loan_payments WHERE loan_id = v_loan_id) THEN
        RAISE EXCEPTION 'Payment schedule already exists for this loan. Delete existing payments first.';
    END IF;

    -- Calculate remaining term (total term - payments already made)
    v_remaining_term := v_term_months - v_initial_payments_made;

    IF v_remaining_term <= 0 THEN
        RAISE EXCEPTION 'No payments remaining to schedule (initial_payments_made: %, loan_term: %)',
            v_initial_payments_made, v_term_months;
    END IF;

    -- Adjust first payment date if initial payments were made
    -- Add initial_payments_made months to the first payment date
    IF v_initial_payments_made > 0 THEN
        v_first_payment_date := v_first_payment_date + (v_initial_payments_made || ' months')::interval;
    END IF;

    -- Generate and insert payment schedule for remaining payments
    -- FIXED: Pass the fixed payment_amount to maintain consistent payments
    FOR v_schedule_rec IN
        SELECT * FROM utils.calculate_amortization_schedule(
            v_current_balance,      -- Use current balance
            v_interest_rate,
            v_remaining_term,       -- Use remaining term
            v_first_payment_date,
            v_payment_amount        -- NEW: Use fixed payment amount
        )
    LOOP
        -- Calculate actual payment number (1-indexed from initial_payments_made)
        v_payment_number := v_initial_payments_made + v_schedule_rec.payment_number;

        INSERT INTO data.loan_payments (
            loan_id,
            payment_number,
            due_date,
            scheduled_amount,
            scheduled_principal,
            scheduled_interest,
            status,
            user_data
        )
        VALUES (
            v_loan_id,
            v_payment_number,  -- Use adjusted payment number
            v_schedule_rec.due_date,
            v_schedule_rec.payment_amount,
            v_schedule_rec.principal_amount,
            v_schedule_rec.interest_amount,
            'scheduled',
            v_user_data
        );

        v_payment_count := v_payment_count + 1;
    END LOOP;

    RETURN v_payment_count;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.generate_loan_schedule(text) IS
'Generate and insert payment schedule for a loan using fixed payment amount';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous version
DROP FUNCTION IF EXISTS utils.calculate_amortization_schedule(numeric, numeric, integer, date, numeric);
DROP FUNCTION IF EXISTS api.generate_loan_schedule(text);

-- Restore 4-parameter version
CREATE OR REPLACE FUNCTION utils.calculate_amortization_schedule(
    p_principal numeric,
    p_annual_rate numeric,
    p_term_months integer,
    p_start_date date
)
RETURNS TABLE(
    payment_number integer,
    due_date date,
    payment_amount numeric,
    principal_amount numeric,
    interest_amount numeric,
    remaining_balance numeric
)
LANGUAGE plpgsql
IMMUTABLE
AS $$
DECLARE
    v_monthly_rate numeric;
    v_payment_amount numeric;
    v_balance numeric;
    v_interest numeric;
    v_principal numeric;
    v_payment_date date;
    i integer;
BEGIN
    v_monthly_rate := (p_annual_rate / 100) / 12;

    IF v_monthly_rate > 0 THEN
        v_payment_amount := p_principal *
            (v_monthly_rate * POWER(1 + v_monthly_rate, p_term_months)) /
            (POWER(1 + v_monthly_rate, p_term_months) - 1);
    ELSE
        v_payment_amount := p_principal / p_term_months;
    END IF;

    v_payment_amount := ROUND(v_payment_amount, 2);
    v_balance := p_principal;

    FOR i IN 1..p_term_months LOOP
        v_payment_date := p_start_date + ((i - 1) || ' months')::interval;
        v_interest := ROUND(v_balance * v_monthly_rate, 2);
        v_principal := v_payment_amount - v_interest;

        IF i = p_term_months THEN
            v_principal := v_balance;
            v_payment_amount := v_principal + v_interest;
        END IF;

        v_balance := v_balance - v_principal;

        payment_number := i;
        due_date := v_payment_date;
        payment_amount := v_payment_amount;
        principal_amount := v_principal;
        interest_amount := v_interest;
        remaining_balance := GREATEST(0, v_balance);

        RETURN NEXT;
    END LOOP;
END;
$$;

-- Restore old generate_loan_schedule
CREATE OR REPLACE FUNCTION api.generate_loan_schedule(
    p_loan_uuid text
) RETURNS integer AS $apifunc$
DECLARE
    v_loan_id bigint;
    v_principal numeric;
    v_current_balance numeric;
    v_interest_rate numeric;
    v_term_months integer;
    v_first_payment_date date;
    v_initial_payments_made integer;
    v_user_data text := utils.get_user();
    v_payment_count integer := 0;
    v_schedule_rec record;
    v_payment_number integer;
    v_remaining_term integer;
BEGIN
    SELECT
        id,
        principal_amount,
        current_balance,
        interest_rate,
        loan_term_months,
        first_payment_date,
        COALESCE(initial_payments_made, 0)
    INTO
        v_loan_id,
        v_principal,
        v_current_balance,
        v_interest_rate,
        v_term_months,
        v_first_payment_date,
        v_initial_payments_made
    FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    IF v_loan_id IS NULL THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    IF EXISTS(SELECT 1 FROM data.loan_payments WHERE loan_id = v_loan_id) THEN
        RAISE EXCEPTION 'Payment schedule already exists for this loan. Delete existing payments first.';
    END IF;

    v_remaining_term := v_term_months - v_initial_payments_made;

    IF v_remaining_term <= 0 THEN
        RAISE EXCEPTION 'No payments remaining to schedule (initial_payments_made: %, loan_term: %)',
            v_initial_payments_made, v_term_months;
    END IF;

    IF v_initial_payments_made > 0 THEN
        v_first_payment_date := v_first_payment_date + (v_initial_payments_made || ' months')::interval;
    END IF;

    FOR v_schedule_rec IN
        SELECT * FROM utils.calculate_amortization_schedule(
            v_current_balance,
            v_interest_rate,
            v_remaining_term,
            v_first_payment_date
        )
    LOOP
        v_payment_number := v_initial_payments_made + v_schedule_rec.payment_number;

        INSERT INTO data.loan_payments (
            loan_id,
            payment_number,
            due_date,
            scheduled_amount,
            scheduled_principal,
            scheduled_interest,
            status,
            user_data
        )
        VALUES (
            v_loan_id,
            v_payment_number,
            v_schedule_rec.due_date,
            v_schedule_rec.payment_amount,
            v_schedule_rec.principal_amount,
            v_schedule_rec.interest_amount,
            'scheduled',
            v_user_data
        );

        v_payment_count := v_payment_count + 1;
    END LOOP;

    RETURN v_payment_count;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

-- +goose StatementEnd
