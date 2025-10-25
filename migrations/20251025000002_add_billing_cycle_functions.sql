-- +goose Up
-- Migration: Add billing cycle and statement generation functions
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 3
-- Purpose: Implement statement generation, due date tracking, and minimum payment calculation

-- ============================================================================
-- UTILS SCHEMA - Internal Business Logic Functions
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Function: utils.calculate_minimum_payment
-- Purpose: Calculate minimum payment for a credit card balance
-- Parameters:
--   p_balance: Current balance (in cents, bigint)
--   p_minimum_payment_percent: Percentage of balance (e.g., 2.0 for 2%)
--   p_minimum_payment_flat: Flat minimum amount (in cents)
-- Returns: Minimum payment amount in cents (bigint)
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.calculate_minimum_payment(
    p_balance BIGINT,
    p_minimum_payment_percent NUMERIC,
    p_minimum_payment_flat BIGINT
)
RETURNS BIGINT
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_percent_payment BIGINT;
    v_minimum_payment BIGINT;
BEGIN
    -- If balance is 0 or negative, no payment required
    IF p_balance <= 0 THEN
        RETURN 0;
    END IF;

    -- Calculate percentage-based payment
    v_percent_payment := ROUND(p_balance * p_minimum_payment_percent / 100.0);

    -- Use the greater of percentage payment or flat minimum
    v_minimum_payment := GREATEST(v_percent_payment, p_minimum_payment_flat);

    -- Minimum payment cannot exceed the balance
    v_minimum_payment := LEAST(v_minimum_payment, p_balance);

    RETURN v_minimum_payment;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.calculate_minimum_payment IS 'Calculate minimum payment for a credit card balance based on percentage and flat minimum';

-- ----------------------------------------------------------------------------
-- Function: utils.calculate_next_statement_date
-- Purpose: Calculate the next statement date based on statement day of month
-- Parameters:
--   p_reference_date: Reference date (defaults to current date)
--   p_statement_day_of_month: Day of month for statement (1-31)
-- Returns: Next statement date
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.calculate_next_statement_date(
    p_reference_date DATE DEFAULT CURRENT_DATE,
    p_statement_day_of_month INTEGER DEFAULT 1
)
RETURNS DATE
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_next_date DATE;
    v_max_day INTEGER;
BEGIN
    -- Validate statement day
    IF p_statement_day_of_month < 1 OR p_statement_day_of_month > 31 THEN
        RAISE EXCEPTION 'Invalid statement_day_of_month: %. Must be between 1 and 31', p_statement_day_of_month;
    END IF;

    -- Calculate candidate date in current month
    v_next_date := DATE_TRUNC('month', p_reference_date)::DATE + (p_statement_day_of_month - 1);

    -- If the candidate date is in the past or today, move to next month
    IF v_next_date <= p_reference_date THEN
        v_next_date := (DATE_TRUNC('month', p_reference_date) + INTERVAL '1 month')::DATE + (p_statement_day_of_month - 1);
    END IF;

    -- Handle months with fewer days (e.g., Feb 31 -> Feb 28/29)
    v_max_day := EXTRACT(DAY FROM (DATE_TRUNC('month', v_next_date) + INTERVAL '1 month - 1 day')::DATE);
    IF p_statement_day_of_month > v_max_day THEN
        v_next_date := (DATE_TRUNC('month', v_next_date) + INTERVAL '1 month - 1 day')::DATE;
    END IF;

    RETURN v_next_date;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.calculate_next_statement_date IS 'Calculate next statement date based on statement day of month';

-- ----------------------------------------------------------------------------
-- Function: utils.get_statement_period_transactions
-- Purpose: Get all transactions for a credit card within a statement period
-- Parameters:
--   p_account_id: Internal account ID
--   p_start_date: Statement period start date
--   p_end_date: Statement period end date
-- Returns: Summary of purchases, payments, interest, and fees
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.get_statement_period_transactions(
    p_account_id BIGINT,
    p_start_date DATE,
    p_end_date DATE
)
RETURNS TABLE (
    purchases_amount BIGINT,
    payments_amount BIGINT,
    interest_charged BIGINT,
    fees_charged BIGINT,
    transaction_count INTEGER
)
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
BEGIN
    RETURN QUERY
    SELECT
        -- Purchases: transactions that credit the CC account (increase liability)
        COALESCE(SUM(
            CASE
                WHEN t.credit_account_id = p_account_id
                    AND (t.metadata->>'is_interest_charge' IS NULL OR t.metadata->>'is_interest_charge' = 'false')
                THEN t.amount
                ELSE 0
            END
        ), 0)::BIGINT as purchases_amount,

        -- Payments: transactions that debit the CC account (decrease liability)
        COALESCE(SUM(
            CASE
                WHEN t.debit_account_id = p_account_id
                THEN t.amount
                ELSE 0
            END
        ), 0)::BIGINT as payments_amount,

        -- Interest: transactions marked as interest charges
        COALESCE(SUM(
            CASE
                WHEN t.credit_account_id = p_account_id
                    AND t.metadata->>'is_interest_charge' = 'true'
                THEN t.amount
                ELSE 0
            END
        ), 0)::BIGINT as interest_charged,

        -- Fees: would be tracked with metadata flag (placeholder for future)
        0::BIGINT as fees_charged,

        -- Total transaction count
        COUNT(*)::INTEGER as transaction_count
    FROM data.transactions t
    WHERE (t.credit_account_id = p_account_id OR t.debit_account_id = p_account_id)
        AND t.date >= p_start_date
        AND t.date <= p_end_date
        AND t.deleted_at IS NULL;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.get_statement_period_transactions IS 'Get transaction summary for a statement period including purchases, payments, interest, and fees';

-- ----------------------------------------------------------------------------
-- Function: utils.generate_statement
-- Purpose: Generate a monthly billing statement for a credit card
-- Parameters:
--   p_credit_card_account_id: Internal account ID
--   p_statement_date: Statement end date
--   p_user_data: User identifier
-- Returns: JSON with statement details
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.generate_statement(
    p_credit_card_account_id BIGINT,
    p_statement_date DATE DEFAULT CURRENT_DATE,
    p_user_data TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_user_data TEXT;
    v_account RECORD;
    v_limit_config RECORD;
    v_previous_statement RECORD;
    v_period_start DATE;
    v_period_end DATE;
    v_due_date DATE;
    v_previous_balance BIGINT;
    v_current_balance BIGINT;
    v_transactions RECORD;
    v_ending_balance BIGINT;
    v_minimum_payment BIGINT;
    v_statement_id BIGINT;
    v_statement_uuid TEXT;
BEGIN
    -- Get user context
    v_user_data := COALESCE(p_user_data, utils.get_user());

    -- Validate user owns the account
    SELECT id, ledger_id, uuid, name
    INTO v_account
    FROM data.accounts
    WHERE id = p_credit_card_account_id
        AND user_data = v_user_data
        AND type = 'liability';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Credit card account not found or access denied';
    END IF;

    -- Get credit card limit configuration
    SELECT * INTO v_limit_config
    FROM utils.get_credit_card_limit_by_account_id(p_credit_card_account_id);

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No credit card limit configuration found',
            'account_uuid', v_account.uuid
        );
    END IF;

    -- Determine statement period
    -- Find most recent statement
    SELECT * INTO v_previous_statement
    FROM data.credit_card_statements
    WHERE credit_card_account_id = p_credit_card_account_id
        AND user_data = v_user_data
    ORDER BY statement_period_end DESC
    LIMIT 1;

    IF v_previous_statement.id IS NOT NULL THEN
        -- Start from day after previous statement
        v_period_start := v_previous_statement.statement_period_end + 1;
    ELSE
        -- First statement: start from beginning of current month
        v_period_start := DATE_TRUNC('month', p_statement_date)::DATE;
    END IF;

    v_period_end := p_statement_date;

    -- Calculate due date
    v_due_date := v_period_end + v_limit_config.due_date_offset_days;

    -- Get previous balance (ending balance from previous statement or current balance before period)
    IF v_previous_statement.id IS NOT NULL THEN
        v_previous_balance := v_previous_statement.ending_balance;
    ELSE
        -- Get balance at start of period
        v_previous_balance := COALESCE(
            (SELECT balance FROM data.balance_snapshots bs
             JOIN data.transactions t ON t.id = bs.transaction_id
             WHERE bs.account_id = p_credit_card_account_id
                AND t.date < v_period_start
             ORDER BY bs.transaction_id DESC
             LIMIT 1),
            0
        );
    END IF;

    -- Get transaction summary for period
    SELECT * INTO v_transactions
    FROM utils.get_statement_period_transactions(
        p_credit_card_account_id,
        v_period_start,
        v_period_end
    );

    -- Calculate ending balance
    v_ending_balance := v_previous_balance + v_transactions.purchases_amount +
                        v_transactions.interest_charged + v_transactions.fees_charged -
                        v_transactions.payments_amount;

    -- Ensure ending balance is not negative
    v_ending_balance := GREATEST(v_ending_balance, 0);

    -- Calculate minimum payment
    v_minimum_payment := utils.calculate_minimum_payment(
        v_ending_balance,
        v_limit_config.minimum_payment_percent,
        ROUND(v_limit_config.minimum_payment_flat * 100)::BIGINT  -- Convert to cents
    );

    -- Mark all previous statements as not current
    UPDATE data.credit_card_statements
    SET is_current = false
    WHERE credit_card_account_id = p_credit_card_account_id
        AND user_data = v_user_data
        AND is_current = true;

    -- Insert new statement
    INSERT INTO data.credit_card_statements (
        credit_card_account_id,
        statement_period_start,
        statement_period_end,
        previous_balance,
        purchases_amount,
        payments_amount,
        interest_charged,
        fees_charged,
        ending_balance,
        minimum_payment_due,
        due_date,
        is_current,
        user_data,
        metadata
    ) VALUES (
        p_credit_card_account_id,
        v_period_start,
        v_period_end,
        v_previous_balance,
        v_transactions.purchases_amount,
        v_transactions.payments_amount,
        v_transactions.interest_charged,
        v_transactions.fees_charged,
        v_ending_balance,
        v_minimum_payment,
        v_due_date,
        true,
        v_user_data,
        jsonb_build_object(
            'transaction_count', v_transactions.transaction_count,
            'credit_limit', v_limit_config.credit_limit,
            'available_credit', v_limit_config.credit_limit - (v_ending_balance / 100.0)
        )
    )
    RETURNING id, uuid INTO v_statement_id, v_statement_uuid;

    -- Return statement details
    RETURN jsonb_build_object(
        'success', true,
        'statement_uuid', v_statement_uuid,
        'statement_id', v_statement_id,
        'account_uuid', v_account.uuid,
        'account_name', v_account.name,
        'statement_period_start', v_period_start,
        'statement_period_end', v_period_end,
        'previous_balance', v_previous_balance,
        'purchases_amount', v_transactions.purchases_amount,
        'payments_amount', v_transactions.payments_amount,
        'interest_charged', v_transactions.interest_charged,
        'fees_charged', v_transactions.fees_charged,
        'ending_balance', v_ending_balance,
        'minimum_payment_due', v_minimum_payment,
        'due_date', v_due_date,
        'transaction_count', v_transactions.transaction_count
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.generate_statement IS 'Generate a monthly billing statement for a credit card account with transaction summary and minimum payment calculation';

-- ----------------------------------------------------------------------------
-- Function: utils.generate_all_statements
-- Purpose: Generate statements for all credit cards that need them
-- Parameters:
--   p_statement_date: Statement date (defaults to current date)
-- Returns: JSON array with results for each card
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.generate_all_statements(
    p_statement_date DATE DEFAULT CURRENT_DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_limit_record RECORD;
    v_result JSONB;
    v_results JSONB[] := ARRAY[]::JSONB[];
    v_success_count INTEGER := 0;
    v_skipped_count INTEGER := 0;
    v_error_count INTEGER := 0;
    v_should_generate BOOLEAN;
    v_last_statement_date DATE;
BEGIN
    -- Process each active credit card limit
    FOR v_limit_record IN
        SELECT
            ccl.credit_card_account_id,
            ccl.user_data,
            ccl.statement_day_of_month,
            a.uuid as account_uuid,
            a.name as account_name
        FROM data.credit_card_limits ccl
        JOIN data.accounts a ON a.id = ccl.credit_card_account_id
        WHERE ccl.is_active = true
    LOOP
        BEGIN
            -- Check if statement should be generated
            -- Generate if it's the statement day of month and no statement exists for this month
            v_should_generate := false;

            IF EXTRACT(DAY FROM p_statement_date) = v_limit_record.statement_day_of_month THEN
                -- Check for existing statement this month
                SELECT MAX(statement_period_end) INTO v_last_statement_date
                FROM data.credit_card_statements
                WHERE credit_card_account_id = v_limit_record.credit_card_account_id
                    AND user_data = v_limit_record.user_data
                    AND EXTRACT(YEAR FROM statement_period_end) = EXTRACT(YEAR FROM p_statement_date)
                    AND EXTRACT(MONTH FROM statement_period_end) = EXTRACT(MONTH FROM p_statement_date);

                IF v_last_statement_date IS NULL THEN
                    v_should_generate := true;
                END IF;
            END IF;

            IF v_should_generate THEN
                -- Generate statement
                v_result := utils.generate_statement(
                    v_limit_record.credit_card_account_id,
                    p_statement_date,
                    v_limit_record.user_data
                );

                IF (v_result->>'success')::BOOLEAN THEN
                    v_success_count := v_success_count + 1;
                ELSE
                    v_skipped_count := v_skipped_count + 1;
                END IF;

                v_results := array_append(v_results, v_result);
            ELSE
                -- Skip this card
                v_skipped_count := v_skipped_count + 1;
                v_results := array_append(v_results, jsonb_build_object(
                    'success', true,
                    'generated', false,
                    'account_uuid', v_limit_record.account_uuid,
                    'account_name', v_limit_record.account_name,
                    'message', 'Not statement day or statement already exists for this month'
                ));
            END IF;

        EXCEPTION WHEN OTHERS THEN
            -- Log error and continue
            v_error_count := v_error_count + 1;
            v_results := array_append(v_results, jsonb_build_object(
                'success', false,
                'generated', false,
                'account_uuid', v_limit_record.account_uuid,
                'account_name', v_limit_record.account_name,
                'error', SQLERRM
            ));
        END;
    END LOOP;

    -- Return summary
    RETURN jsonb_build_object(
        'success', true,
        'statement_date', p_statement_date,
        'total_processed', array_length(v_results, 1),
        'success_count', v_success_count,
        'skipped_count', v_skipped_count,
        'error_count', v_error_count,
        'results', to_jsonb(v_results)
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.generate_all_statements IS 'Generate statements for all credit cards on their statement day. Used by monthly batch job.';

-- ============================================================================
-- API SCHEMA - Public Interface Functions and Views
-- ============================================================================

-- ----------------------------------------------------------------------------
-- View: api.credit_card_statements
-- Purpose: Public view of credit card statements with account details
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW api.credit_card_statements AS
SELECT
    s.uuid,
    s.created_at,
    a.uuid as account_uuid,
    a.name as account_name,
    s.statement_period_start,
    s.statement_period_end,
    s.previous_balance,
    s.purchases_amount,
    s.payments_amount,
    s.interest_charged,
    s.fees_charged,
    s.ending_balance,
    s.minimum_payment_due,
    s.due_date,
    s.is_current,
    s.metadata,
    -- Calculate days until due
    (s.due_date - CURRENT_DATE) as days_until_due,
    -- Determine if overdue
    CASE
        WHEN s.due_date < CURRENT_DATE AND s.ending_balance > 0 THEN true
        ELSE false
    END as is_overdue
FROM data.credit_card_statements s
JOIN data.accounts a ON a.id = s.credit_card_account_id
WHERE s.user_data = utils.get_user()
ORDER BY s.statement_period_end DESC;

COMMENT ON VIEW api.credit_card_statements IS 'Public view of credit card statements with account details and due date calculations';

-- ----------------------------------------------------------------------------
-- Function: api.get_statement
-- Purpose: Get a specific statement by UUID
-- Parameters:
--   p_statement_uuid: Public UUID of statement
-- Returns: Statement details
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_statement(
    p_statement_uuid TEXT
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    account_uuid TEXT,
    account_name TEXT,
    statement_period_start DATE,
    statement_period_end DATE,
    previous_balance BIGINT,
    purchases_amount BIGINT,
    payments_amount BIGINT,
    interest_charged BIGINT,
    fees_charged BIGINT,
    ending_balance BIGINT,
    minimum_payment_due BIGINT,
    due_date DATE,
    is_current BOOLEAN,
    metadata JSONB,
    days_until_due INTEGER,
    is_overdue BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT *
    FROM api.credit_card_statements
    WHERE uuid = p_statement_uuid
    LIMIT 1;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_statement IS 'Get a specific credit card statement by UUID';

-- ----------------------------------------------------------------------------
-- Function: api.get_statements_for_account
-- Purpose: Get all statements for a credit card account
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
-- Returns: All statements for the account
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_statements_for_account(
    p_account_uuid TEXT
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    account_uuid TEXT,
    account_name TEXT,
    statement_period_start DATE,
    statement_period_end DATE,
    previous_balance BIGINT,
    purchases_amount BIGINT,
    payments_amount BIGINT,
    interest_charged BIGINT,
    fees_charged BIGINT,
    ending_balance BIGINT,
    minimum_payment_due BIGINT,
    due_date DATE,
    is_current BOOLEAN,
    metadata JSONB,
    days_until_due INTEGER,
    is_overdue BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT *
    FROM api.credit_card_statements
    WHERE account_uuid = p_account_uuid
    ORDER BY statement_period_end DESC;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_statements_for_account IS 'Get all statements for a credit card account ordered by statement date';

-- ----------------------------------------------------------------------------
-- Function: api.get_current_statement
-- Purpose: Get the current (most recent) statement for an account
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
-- Returns: Current statement details
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_current_statement(
    p_account_uuid TEXT
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    account_uuid TEXT,
    account_name TEXT,
    statement_period_start DATE,
    statement_period_end DATE,
    previous_balance BIGINT,
    purchases_amount BIGINT,
    payments_amount BIGINT,
    interest_charged BIGINT,
    fees_charged BIGINT,
    ending_balance BIGINT,
    minimum_payment_due BIGINT,
    due_date DATE,
    is_current BOOLEAN,
    metadata JSONB,
    days_until_due INTEGER,
    is_overdue BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT *
    FROM api.credit_card_statements
    WHERE account_uuid = p_account_uuid
        AND is_current = true
    LIMIT 1;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_current_statement IS 'Get the current active statement for a credit card account';

-- ----------------------------------------------------------------------------
-- Function: api.generate_statement
-- Purpose: Generate a statement for a credit card (wrapper for utils)
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
--   p_statement_date: Statement end date (optional)
-- Returns: JSON with statement details
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.generate_statement(
    p_account_uuid TEXT,
    p_statement_date DATE DEFAULT CURRENT_DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_account_id BIGINT;
    v_result JSONB;
BEGIN
    -- Get account ID
    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_account_uuid
        AND user_data = utils.get_user()
        AND type = 'liability';

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Credit card account not found or access denied'
        );
    END IF;

    -- Generate statement
    v_result := utils.generate_statement(
        v_account_id,
        p_statement_date
    );

    RETURN v_result;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.generate_statement IS 'Generate a billing statement for a credit card account by UUID. Wrapper for utils.generate_statement.';

-- ----------------------------------------------------------------------------
-- Function: api.get_upcoming_due_dates
-- Purpose: Get all upcoming due dates for user's credit cards
-- Parameters:
--   p_days_ahead: Number of days to look ahead (default 30)
-- Returns: Credit cards with upcoming due dates
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_upcoming_due_dates(
    p_days_ahead INTEGER DEFAULT 30
)
RETURNS TABLE (
    account_uuid TEXT,
    account_name TEXT,
    statement_uuid TEXT,
    due_date DATE,
    days_until_due INTEGER,
    ending_balance BIGINT,
    minimum_payment_due BIGINT,
    is_overdue BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT
        s.account_uuid,
        s.account_name,
        s.uuid as statement_uuid,
        s.due_date,
        s.days_until_due,
        s.ending_balance,
        s.minimum_payment_due,
        s.is_overdue
    FROM api.credit_card_statements s
    WHERE s.is_current = true
        AND s.due_date <= CURRENT_DATE + p_days_ahead
        AND s.ending_balance > 0
    ORDER BY s.due_date ASC;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_upcoming_due_dates IS 'Get all upcoming payment due dates for active credit card statements';

-- +goose Down
-- Drop all functions and views in reverse order

DROP FUNCTION IF EXISTS api.get_upcoming_due_dates(INTEGER);
DROP FUNCTION IF EXISTS api.generate_statement(TEXT, DATE);
DROP FUNCTION IF EXISTS api.get_current_statement(TEXT);
DROP FUNCTION IF EXISTS api.get_statements_for_account(TEXT);
DROP FUNCTION IF EXISTS api.get_statement(TEXT);
DROP VIEW IF EXISTS api.credit_card_statements;

DROP FUNCTION IF EXISTS utils.generate_all_statements(DATE);
DROP FUNCTION IF EXISTS utils.generate_statement(BIGINT, DATE, TEXT);
DROP FUNCTION IF EXISTS utils.get_statement_period_transactions(BIGINT, DATE, DATE);
DROP FUNCTION IF EXISTS utils.calculate_next_statement_date(DATE, INTEGER);
DROP FUNCTION IF EXISTS utils.calculate_minimum_payment(BIGINT, NUMERIC, BIGINT);
