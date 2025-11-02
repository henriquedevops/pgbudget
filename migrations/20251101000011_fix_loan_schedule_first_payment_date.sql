-- Migration: Fix loan schedule first payment date calculation
-- Created: 2025-11-01
-- Purpose: Fix bug where first payment was scheduled 1 month after first_payment_date

-- +goose Up
-- +goose StatementBegin

-- Fix the amortization schedule calculation
-- Bug: v_payment_date := p_start_date + (i || ' months')::interval;
-- When i=1, this adds 1 month to start date, making first payment 1 month late
-- Fix: Use (i-1) so first payment (i=1) is at p_start_date, second is +1 month, etc.

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
    -- Calculate monthly interest rate
    v_monthly_rate := (p_annual_rate / 100) / 12;

    -- Calculate payment amount
    IF v_monthly_rate > 0 THEN
        v_payment_amount := p_principal *
            (v_monthly_rate * POWER(1 + v_monthly_rate, p_term_months)) /
            (POWER(1 + v_monthly_rate, p_term_months) - 1);
    ELSE
        v_payment_amount := p_principal / p_term_months;
    END IF;

    v_payment_amount := ROUND(v_payment_amount, 2);
    v_balance := p_principal;

    -- Generate payment schedule
    FOR i IN 1..p_term_months LOOP
        -- Calculate payment date
        -- FIXED: Use (i-1) so first payment is at p_start_date
        -- i=1: p_start_date + 0 months = p_start_date (correct)
        -- i=2: p_start_date + 1 month (correct)
        v_payment_date := p_start_date + ((i - 1) || ' months')::interval;

        -- Calculate interest for this payment
        v_interest := ROUND(v_balance * v_monthly_rate, 2);

        -- Calculate principal for this payment
        v_principal := v_payment_amount - v_interest;

        -- Adjust last payment to account for rounding
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

COMMENT ON FUNCTION utils.calculate_amortization_schedule(numeric, numeric, integer, date) IS
'Calculate loan amortization schedule with correct first payment date (fixed bug where first payment was 1 month late)';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore original version (with bug)
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
        v_payment_date := p_start_date + (i || ' months')::interval;

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
