-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UPDATE SCHEDULE GENERATION TO RESPECT INITIAL PAYMENTS MADE
-- ============================================================================
-- This migration updates the generate_loan_schedule function to start
-- payment numbering from initial_payments_made + 1
-- ============================================================================

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
    FOR v_schedule_rec IN
        SELECT * FROM utils.calculate_amortization_schedule(
            v_current_balance,  -- Use current balance, not original principal
            v_interest_rate,
            v_remaining_term,   -- Use remaining term, not total term
            v_first_payment_date
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
'Generate and insert payment schedule for a loan, respecting payments already made before tracking began';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous version
DROP FUNCTION IF EXISTS api.generate_loan_schedule(text);

CREATE OR REPLACE FUNCTION api.generate_loan_schedule(
    p_loan_uuid text
) RETURNS integer AS $apifunc$
DECLARE
    v_loan_id bigint;
    v_principal numeric;
    v_interest_rate numeric;
    v_term_months integer;
    v_first_payment_date date;
    v_user_data text := utils.get_user();
    v_payment_count integer := 0;
    v_schedule_rec record;
BEGIN
    -- Get loan details and verify ownership
    SELECT
        id,
        principal_amount,
        interest_rate,
        loan_term_months,
        first_payment_date
    INTO
        v_loan_id,
        v_principal,
        v_interest_rate,
        v_term_months,
        v_first_payment_date
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

    -- Generate and insert payment schedule
    FOR v_schedule_rec IN
        SELECT * FROM utils.calculate_amortization_schedule(
            v_principal,
            v_interest_rate,
            v_term_months,
            v_first_payment_date
        )
    LOOP
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
            v_schedule_rec.payment_number,
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
