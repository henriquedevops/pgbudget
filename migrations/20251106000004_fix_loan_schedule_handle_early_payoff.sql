-- Migration: Fix loan schedule to handle early payoff with fixed payments
-- Created: 2025-11-06
-- Purpose: Handle case where fixed payment amount pays off loan before remaining term

-- +goose Up
-- +goose StatementBegin

CREATE OR REPLACE FUNCTION utils.calculate_amortization_schedule(
    p_principal numeric,
    p_annual_rate numeric,
    p_term_months integer,
    p_start_date date,
    p_fixed_payment numeric DEFAULT NULL
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
        -- Stop if balance is already zero or negative
        IF v_balance <= 0 THEN
            EXIT;
        END IF;

        -- Calculate payment date
        v_payment_date := p_start_date + ((i - 1) || ' months')::interval;

        -- Calculate interest for this payment
        v_interest := ROUND(v_balance * v_monthly_rate, 2);

        -- Calculate principal for this payment
        v_principal := v_payment_amount - v_interest;

        -- If this payment would overpay, adjust it to final payment
        IF v_principal >= v_balance OR i = p_term_months THEN
            v_principal := v_balance;
            v_payment_amount := v_principal + v_interest;
            v_balance := 0;
        ELSE
            v_balance := v_balance - v_principal;
        END IF;

        -- Return this payment
        payment_number := i;
        due_date := v_payment_date;
        payment_amount := v_payment_amount;
        principal_amount := v_principal;
        interest_amount := v_interest;
        remaining_balance := v_balance;

        RETURN NEXT;

        -- If balance is now zero, we're done
        IF v_balance <= 0 THEN
            EXIT;
        END IF;
    END LOOP;
END;
$$;

COMMENT ON FUNCTION utils.calculate_amortization_schedule(numeric, numeric, integer, date, numeric) IS
'Calculate loan amortization schedule with optional fixed payment amount, handling early payoff';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous version
CREATE OR REPLACE FUNCTION utils.calculate_amortization_schedule(
    p_principal numeric,
    p_annual_rate numeric,
    p_term_months integer,
    p_start_date date,
    p_fixed_payment numeric DEFAULT NULL
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

    IF p_fixed_payment IS NOT NULL THEN
        v_payment_amount := p_fixed_payment;
    ELSE
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

-- +goose StatementEnd
