-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- LOAN MANAGEMENT API FUNCTIONS
-- ============================================================================
-- This migration creates the API layer for loan management
-- Part of Step 1.3 of LOAN_MANAGEMENT_IMPLEMENTATION.md
-- ============================================================================

-- ----------------------------------------------------------------------------
-- API VIEW: api.loans
-- ----------------------------------------------------------------------------
-- Exposes loans with user-friendly UUIDs instead of internal IDs
CREATE OR REPLACE VIEW api.loans WITH (security_invoker = true) AS
SELECT
    l.uuid,
    l.lender_name,
    l.loan_type,
    l.principal_amount,
    l.current_balance,
    l.interest_rate,
    l.interest_type,
    l.compounding_frequency,
    l.loan_term_months,
    l.remaining_months,
    l.start_date,
    l.first_payment_date,
    l.payment_amount,
    l.payment_frequency,
    l.payment_day_of_month,
    l.amortization_type,
    l.status,
    l.notes,
    l.metadata,
    l.created_at,
    l.updated_at,
    ledg.uuid as ledger_uuid,
    acct.uuid as account_uuid,
    acct.name as account_name
FROM data.loans l
JOIN data.ledgers ledg ON ledg.id = l.ledger_id
LEFT JOIN data.accounts acct ON acct.id = l.account_id;

COMMENT ON VIEW api.loans IS
'Public view of loans with UUIDs for API consumption';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API VIEW: api.loan_payments
-- ----------------------------------------------------------------------------
-- Exposes loan payments with user-friendly UUIDs
CREATE OR REPLACE VIEW api.loan_payments WITH (security_invoker = true) AS
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
    l.uuid as loan_uuid,
    t.uuid as transaction_uuid,
    a.uuid as from_account_uuid,
    a.name as from_account_name
FROM data.loan_payments lp
JOIN data.loans l ON l.id = lp.loan_id
LEFT JOIN data.transactions t ON t.id = lp.transaction_id
LEFT JOIN data.accounts a ON a.id = lp.from_account_id;

COMMENT ON VIEW api.loan_payments IS
'Public view of loan payments with UUIDs for API consumption';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.get_loans
-- ----------------------------------------------------------------------------
-- Get all loans for a ledger
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
-- Get a single loan by UUID
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
-- Create a new loan
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
    p_notes text DEFAULT NULL
) RETURNS SETOF api.loans AS $apifunc$
DECLARE
    v_ledger_id bigint;
    v_account_id bigint;
    v_user_data text := utils.get_user();
    v_loan_uuid text;
    v_payment_amount numeric;
    v_monthly_rate numeric;
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

    -- Validate dates
    IF p_first_payment_date < p_start_date THEN
        RAISE EXCEPTION 'First payment date cannot be before start date';
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
    -- M = P * [r(1 + r)^n] / [(1 + r)^n - 1]
    -- Where: M = monthly payment, P = principal, r = monthly rate, n = number of payments
    v_monthly_rate := (p_interest_rate / 100) / 12;

    IF v_monthly_rate > 0 THEN
        v_payment_amount := p_principal_amount *
            (v_monthly_rate * POWER(1 + v_monthly_rate, p_loan_term_months)) /
            (POWER(1 + v_monthly_rate, p_loan_term_months) - 1);
    ELSE
        -- Zero interest rate - just divide principal by term
        v_payment_amount := p_principal_amount / p_loan_term_months;
    END IF;

    -- Round to 2 decimal places
    v_payment_amount := ROUND(v_payment_amount, 2);

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
        user_data
    )
    VALUES (
        v_ledger_id,
        v_account_id,
        p_lender_name,
        p_loan_type,
        p_principal_amount,
        p_principal_amount, -- current_balance starts as principal
        p_interest_rate,
        p_interest_type,
        p_compounding_frequency,
        p_loan_term_months,
        p_loan_term_months, -- remaining_months starts as term
        p_start_date,
        p_first_payment_date,
        v_payment_amount,
        p_payment_frequency,
        p_payment_day_of_month,
        p_amortization_type,
        'active',
        p_notes,
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
'Create a new loan with automatic payment amount calculation';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.update_loan
-- ----------------------------------------------------------------------------
-- Update an existing loan
CREATE OR REPLACE FUNCTION api.update_loan(
    p_loan_uuid text,
    p_lender_name text DEFAULT NULL,
    p_interest_rate numeric DEFAULT NULL,
    p_interest_type text DEFAULT NULL,
    p_status text DEFAULT NULL,
    p_notes text DEFAULT NULL
) RETURNS SETOF api.loans AS $apifunc$
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

    -- Validate interest rate if provided
    IF p_interest_rate IS NOT NULL AND (p_interest_rate < 0 OR p_interest_rate > 100) THEN
        RAISE EXCEPTION 'Interest rate must be between 0 and 100: %', p_interest_rate;
    END IF;

    -- Validate interest type if provided
    IF p_interest_type IS NOT NULL AND p_interest_type NOT IN ('fixed', 'variable') THEN
        RAISE EXCEPTION 'Invalid interest type: %. Must be fixed or variable', p_interest_type;
    END IF;

    -- Validate status if provided
    IF p_status IS NOT NULL AND p_status NOT IN ('active', 'paid_off', 'defaulted', 'refinanced', 'closed') THEN
        RAISE EXCEPTION 'Invalid status: %. Must be one of: active, paid_off, defaulted, refinanced, closed', p_status;
    END IF;

    -- Update the loan (only fields that are not null)
    UPDATE data.loans
    SET
        lender_name = COALESCE(p_lender_name, lender_name),
        interest_rate = COALESCE(p_interest_rate, interest_rate),
        interest_type = COALESCE(p_interest_type, interest_type),
        status = COALESCE(p_status, status),
        notes = COALESCE(p_notes, notes)
    WHERE id = v_loan_id;

    -- Return the updated loan
    RETURN QUERY
    SELECT * FROM api.loans
    WHERE uuid = p_loan_uuid;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.update_loan IS
'Update mutable fields of an existing loan';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.delete_loan
-- ----------------------------------------------------------------------------
-- Delete a loan and all its payment records
CREATE OR REPLACE FUNCTION api.delete_loan(
    p_loan_uuid text
) RETURNS boolean AS $apifunc$
DECLARE
    v_user_data text := utils.get_user();
    v_deleted integer;
BEGIN
    -- Delete the loan (RLS ensures user ownership, CASCADE handles payments)
    DELETE FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    -- Check if anything was deleted
    GET DIAGNOSTICS v_deleted = ROW_COUNT;

    IF v_deleted = 0 THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    RETURN true;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.delete_loan(text) IS
'Delete a loan and all its associated payment records';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.get_loan_payments
-- ----------------------------------------------------------------------------
-- Get all payments for a loan
CREATE OR REPLACE FUNCTION api.get_loan_payments(
    p_loan_uuid text
) RETURNS SETOF api.loan_payments AS $apifunc$
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
$apifunc$ LANGUAGE plpgsql STABLE SECURITY INVOKER;

COMMENT ON FUNCTION api.get_loan_payments(text) IS
'Get all payment records for a specific loan';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- UTILITY FUNCTION: utils.calculate_amortization_schedule
-- ----------------------------------------------------------------------------
-- Calculate the complete amortization schedule for a loan
CREATE OR REPLACE FUNCTION utils.calculate_amortization_schedule(
    p_principal numeric,
    p_annual_rate numeric,
    p_term_months integer,
    p_start_date date
) RETURNS TABLE(
    payment_number integer,
    due_date date,
    payment_amount numeric,
    principal_amount numeric,
    interest_amount numeric,
    remaining_balance numeric
) AS $func$
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
        -- Calculate payment date (add months to start date)
        v_payment_date := p_start_date + (i || ' months')::interval;

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
$func$ LANGUAGE plpgsql IMMUTABLE SECURITY INVOKER;

COMMENT ON FUNCTION utils.calculate_amortization_schedule IS
'Calculate complete amortization schedule for a loan using standard amortization formula';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.generate_loan_schedule
-- ----------------------------------------------------------------------------
-- Generate payment schedule for a loan and insert into loan_payments table
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

COMMENT ON FUNCTION api.generate_loan_schedule(text) IS
'Generate and insert payment schedule for a loan based on its terms';

-- +goose StatementEnd

-- +goose StatementBegin

-- ----------------------------------------------------------------------------
-- API FUNCTION: api.record_loan_payment
-- ----------------------------------------------------------------------------
-- Record a payment against a loan
CREATE OR REPLACE FUNCTION api.record_loan_payment(
    p_payment_uuid text,
    p_paid_date date,
    p_actual_amount numeric,
    p_from_account_uuid text,
    p_notes text DEFAULT NULL
) RETURNS SETOF api.loan_payments AS $apifunc$
DECLARE
    v_payment_id bigint;
    v_loan_id bigint;
    v_from_account_id bigint;
    v_loan_account_id bigint;
    v_ledger_id bigint;
    v_current_balance numeric;
    v_monthly_rate numeric;
    v_interest_amount numeric;
    v_principal_amount numeric;
    v_user_data text := utils.get_user();
    v_transaction_uuid text;
BEGIN
    -- Get payment details and verify ownership
    SELECT
        lp.id,
        lp.loan_id,
        l.current_balance,
        l.interest_rate,
        l.account_id,
        l.ledger_id
    INTO
        v_payment_id,
        v_loan_id,
        v_current_balance,
        v_monthly_rate,
        v_loan_account_id,
        v_ledger_id
    FROM data.loan_payments lp
    JOIN data.loans l ON l.id = lp.loan_id
    WHERE lp.uuid = p_payment_uuid
      AND lp.user_data = v_user_data;

    IF v_payment_id IS NULL THEN
        RAISE EXCEPTION 'Payment with UUID % not found for current user', p_payment_uuid;
    END IF;

    -- Validate amount
    IF p_actual_amount <= 0 THEN
        RAISE EXCEPTION 'Payment amount must be positive: %', p_actual_amount;
    END IF;

    -- Get from account and verify it exists
    SELECT id INTO v_from_account_id
    FROM data.accounts
    WHERE uuid = p_from_account_uuid
      AND ledger_id = v_ledger_id
      AND user_data = v_user_data;

    IF v_from_account_id IS NULL THEN
        RAISE EXCEPTION 'Account with UUID % not found for this ledger', p_from_account_uuid;
    END IF;

    -- Calculate interest and principal portions
    v_monthly_rate := (v_monthly_rate / 100) / 12;
    v_interest_amount := ROUND(v_current_balance * v_monthly_rate, 2);
    v_principal_amount := p_actual_amount - v_interest_amount;

    -- Ensure principal doesn't exceed current balance
    IF v_principal_amount > v_current_balance THEN
        v_principal_amount := v_current_balance;
        v_interest_amount := p_actual_amount - v_principal_amount;
    END IF;

    -- Update the payment record
    UPDATE data.loan_payments
    SET
        paid_date = p_paid_date,
        actual_amount_paid = p_actual_amount,
        actual_principal = v_principal_amount,
        actual_interest = v_interest_amount,
        from_account_id = v_from_account_id,
        status = 'paid',
        notes = COALESCE(p_notes, notes)
    WHERE id = v_payment_id;

    -- Note: Transaction creation would be handled by separate transaction API
    -- The trigger will automatically update the loan balance

    -- Return the updated payment
    RETURN QUERY
    SELECT * FROM api.loan_payments
    WHERE uuid = p_payment_uuid;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.record_loan_payment IS
'Record a payment against a loan payment schedule entry';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop API functions
DROP FUNCTION IF EXISTS api.record_loan_payment(text, date, numeric, text, text);
DROP FUNCTION IF EXISTS api.generate_loan_schedule(text);
DROP FUNCTION IF EXISTS utils.calculate_amortization_schedule(numeric, numeric, integer, date);
DROP FUNCTION IF EXISTS api.get_loan_payments(text);
DROP FUNCTION IF EXISTS api.delete_loan(text);
DROP FUNCTION IF EXISTS api.update_loan(text, text, numeric, text, text, text);
DROP FUNCTION IF EXISTS api.create_loan(text, text, text, numeric, numeric, integer, date, date, text, text, text, text, integer, text, text);
DROP FUNCTION IF EXISTS api.get_loan(text);
DROP FUNCTION IF EXISTS api.get_loans(text);

-- Drop views
DROP VIEW IF EXISTS api.loan_payments;
DROP VIEW IF EXISTS api.loans;

-- +goose StatementEnd
