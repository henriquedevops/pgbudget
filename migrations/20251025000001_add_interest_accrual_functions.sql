-- +goose Up
-- Migration: Add interest accrual functions for credit cards
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 2
-- Purpose: Implement interest calculation and accrual for credit card accounts with APR

-- ============================================================================
-- UTILS SCHEMA - Internal Business Logic Functions
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Function: utils.calculate_daily_interest
-- Purpose: Calculate daily interest charge for a credit card balance
-- Parameters:
--   p_balance: Current balance (in cents)
--   p_apr: Annual percentage rate (0-100)
--   p_compounding_frequency: 'daily' or 'monthly'
-- Returns: Interest amount in cents (bigint)
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.calculate_daily_interest(
    p_balance NUMERIC,
    p_apr NUMERIC,
    p_compounding_frequency TEXT DEFAULT 'daily'
)
RETURNS BIGINT
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_daily_rate NUMERIC;
    v_interest_amount NUMERIC;
BEGIN
    -- Return 0 if balance is zero or negative, or APR is 0
    IF p_balance <= 0 OR p_apr <= 0 THEN
        RETURN 0;
    END IF;

    -- Calculate daily rate based on compounding frequency
    IF p_compounding_frequency = 'daily' THEN
        -- Daily compounding: APR / 365
        v_daily_rate := p_apr / 100.0 / 365.0;
    ELSIF p_compounding_frequency = 'monthly' THEN
        -- Monthly compounding: APR / 12 / days_in_month (approximate with 30.4167)
        v_daily_rate := p_apr / 100.0 / 12.0 / 30.4167;
    ELSE
        RAISE EXCEPTION 'Invalid compounding_frequency: %. Must be daily or monthly', p_compounding_frequency;
    END IF;

    -- Calculate interest: balance * daily_rate
    v_interest_amount := p_balance * v_daily_rate;

    -- Round to nearest cent and return as bigint (cents)
    RETURN ROUND(v_interest_amount)::BIGINT;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.calculate_daily_interest IS 'Calculate daily interest charge for a credit card balance based on APR and compounding frequency';

-- ----------------------------------------------------------------------------
-- Function: utils.get_credit_card_limit_by_account_id
-- Purpose: Get credit card limit configuration by internal account ID
-- Parameters:
--   p_account_id: Internal account ID
-- Returns: Record with limit configuration
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.get_credit_card_limit_by_account_id(
    p_account_id BIGINT
)
RETURNS TABLE (
    id BIGINT,
    uuid TEXT,
    credit_card_account_id BIGINT,
    credit_limit NUMERIC,
    annual_percentage_rate NUMERIC,
    interest_type TEXT,
    compounding_frequency TEXT,
    statement_day_of_month INTEGER,
    due_date_offset_days INTEGER,
    grace_period_days INTEGER,
    minimum_payment_percent NUMERIC,
    minimum_payment_flat NUMERIC,
    auto_payment_enabled BOOLEAN,
    auto_payment_type TEXT,
    auto_payment_amount NUMERIC,
    auto_payment_date INTEGER,
    is_active BOOLEAN,
    last_interest_accrual_date DATE,
    notes TEXT,
    metadata JSONB
)
LANGUAGE sql
STABLE
SECURITY DEFINER
AS $$
    SELECT
        id,
        uuid,
        credit_card_account_id,
        credit_limit,
        annual_percentage_rate,
        interest_type,
        compounding_frequency,
        statement_day_of_month,
        due_date_offset_days,
        grace_period_days,
        minimum_payment_percent,
        minimum_payment_flat,
        auto_payment_enabled,
        auto_payment_type,
        auto_payment_amount,
        auto_payment_date,
        is_active,
        last_interest_accrual_date,
        notes,
        metadata
    FROM data.credit_card_limits
    WHERE credit_card_account_id = p_account_id
        AND is_active = true
        AND user_data = utils.get_user()
    LIMIT 1;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.get_credit_card_limit_by_account_id IS 'Get active credit card limit configuration by internal account ID with user context validation';

-- ----------------------------------------------------------------------------
-- Function: utils.process_interest_accrual
-- Purpose: Calculate and record interest charges for a credit card account
-- Parameters:
--   p_credit_card_account_id: Internal account ID for credit card
--   p_accrual_date: Date for which to accrue interest (defaults to current date)
--   p_user_data: User identifier (defaults to current user)
-- Returns: JSON with interest details and transaction ID
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.process_interest_accrual(
    p_credit_card_account_id BIGINT,
    p_accrual_date DATE DEFAULT CURRENT_DATE,
    p_user_data TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_user_data TEXT;
    v_limit_config RECORD;
    v_account RECORD;
    v_current_balance NUMERIC;
    v_interest_amount BIGINT;
    v_ledger_id BIGINT;
    v_interest_category_id BIGINT;
    v_transaction_id BIGINT;
    v_result JSONB;
BEGIN
    -- Get user context
    v_user_data := COALESCE(p_user_data, utils.get_user());

    -- Validate user owns the account
    SELECT id, ledger_id, uuid, name, type, internal_type
    INTO v_account
    FROM data.accounts
    WHERE id = p_credit_card_account_id
        AND user_data = v_user_data
        AND type = 'liability';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Credit card account not found or access denied';
    END IF;

    v_ledger_id := v_account.ledger_id;

    -- Get credit card limit configuration
    SELECT * INTO v_limit_config
    FROM utils.get_credit_card_limit_by_account_id(p_credit_card_account_id);

    IF NOT FOUND THEN
        -- No limit configuration, skip interest accrual
        RETURN jsonb_build_object(
            'success', true,
            'accrued', false,
            'message', 'No credit card limit configuration found',
            'account_uuid', v_account.uuid,
            'accrual_date', p_accrual_date
        );
    END IF;

    -- Check if APR is 0, skip if so
    IF v_limit_config.annual_percentage_rate <= 0 THEN
        RETURN jsonb_build_object(
            'success', true,
            'accrued', false,
            'message', 'APR is 0%, no interest to accrue',
            'account_uuid', v_account.uuid,
            'accrual_date', p_accrual_date,
            'apr', v_limit_config.annual_percentage_rate
        );
    END IF;

    -- Check if interest was already accrued today
    IF v_limit_config.last_interest_accrual_date = p_accrual_date THEN
        RETURN jsonb_build_object(
            'success', true,
            'accrued', false,
            'message', 'Interest already accrued for this date',
            'account_uuid', v_account.uuid,
            'accrual_date', p_accrual_date,
            'last_accrual_date', v_limit_config.last_interest_accrual_date
        );
    END IF;

    -- Get current balance for the credit card from latest snapshot
    -- Balance is stored in cents (bigint) in balance_snapshots
    v_current_balance := COALESCE(
        (SELECT balance FROM data.balance_snapshots
         WHERE account_id = p_credit_card_account_id
         ORDER BY transaction_id DESC
         LIMIT 1),
        0
    );

    -- Calculate interest for the day
    v_interest_amount := utils.calculate_daily_interest(
        v_current_balance,
        v_limit_config.annual_percentage_rate,
        v_limit_config.compounding_frequency
    );

    -- If interest is 0 or negative, skip transaction creation
    IF v_interest_amount <= 0 THEN
        RETURN jsonb_build_object(
            'success', true,
            'accrued', false,
            'message', 'Interest amount is 0, no transaction created',
            'account_uuid', v_account.uuid,
            'accrual_date', p_accrual_date,
            'balance', v_current_balance,
            'interest_amount', v_interest_amount
        );
    END IF;

    -- Find or create "Interest & Finance Charges" category
    SELECT id INTO v_interest_category_id
    FROM data.accounts
    WHERE ledger_id = v_ledger_id
        AND user_data = v_user_data
        AND type = 'equity'
        AND name = 'Interest & Finance Charges'
    LIMIT 1;

    IF NOT FOUND THEN
        -- Create the interest category
        INSERT INTO data.accounts (
            ledger_id,
            name,
            type,
            internal_type,
            user_data,
            metadata
        ) VALUES (
            v_ledger_id,
            'Interest & Finance Charges',
            'equity',
            'liability_like',
            v_user_data,
            jsonb_build_object(
                'is_system_category', true,
                'category_type', 'interest'
            )
        )
        RETURNING id INTO v_interest_category_id;
    END IF;

    -- Create interest charge transaction
    -- Debit: Interest & Finance Charges (increases expense)
    -- Credit: Credit Card Account (increases liability)
    INSERT INTO data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        user_data,
        metadata
    ) VALUES (
        v_ledger_id,
        p_accrual_date,
        'Interest charge - ' || v_account.name,
        v_interest_amount,
        v_interest_category_id,  -- Debit interest expense
        p_credit_card_account_id,  -- Credit CC liability
        v_user_data,
        jsonb_build_object(
            'is_interest_charge', true,
            'credit_card_uuid', v_account.uuid,
            'apr', v_limit_config.annual_percentage_rate,
            'compounding_frequency', v_limit_config.compounding_frequency,
            'balance_at_accrual', v_current_balance,
            'accrual_date', p_accrual_date
        )
    )
    RETURNING id INTO v_transaction_id;

    -- Update last_interest_accrual_date in credit_card_limits
    UPDATE data.credit_card_limits
    SET last_interest_accrual_date = p_accrual_date,
        updated_at = CURRENT_TIMESTAMP
    WHERE credit_card_account_id = p_credit_card_account_id
        AND user_data = v_user_data;

    -- Build success response
    v_result := jsonb_build_object(
        'success', true,
        'accrued', true,
        'message', 'Interest accrued successfully',
        'account_uuid', v_account.uuid,
        'account_name', v_account.name,
        'accrual_date', p_accrual_date,
        'balance', v_current_balance,
        'apr', v_limit_config.annual_percentage_rate,
        'compounding_frequency', v_limit_config.compounding_frequency,
        'interest_amount', v_interest_amount,
        'interest_amount_display', '$' || (v_interest_amount / 100.0)::TEXT,
        'transaction_id', v_transaction_id
    );

    RETURN v_result;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.process_interest_accrual IS 'Calculate and record daily interest charges for a credit card account based on current balance and APR configuration';

-- ----------------------------------------------------------------------------
-- Function: utils.process_all_interest_accruals
-- Purpose: Process interest accrual for all credit cards with active limits
-- Parameters:
--   p_accrual_date: Date for which to accrue interest (defaults to current date)
-- Returns: JSON array with results for each card
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.process_all_interest_accruals(
    p_accrual_date DATE DEFAULT CURRENT_DATE
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
BEGIN
    -- Process each active credit card limit
    FOR v_limit_record IN
        SELECT
            ccl.credit_card_account_id,
            ccl.user_data,
            a.uuid as account_uuid,
            a.name as account_name
        FROM data.credit_card_limits ccl
        JOIN data.accounts a ON a.id = ccl.credit_card_account_id
        WHERE ccl.is_active = true
            AND ccl.annual_percentage_rate > 0
            AND (ccl.last_interest_accrual_date IS NULL
                 OR ccl.last_interest_accrual_date < p_accrual_date)
    LOOP
        BEGIN
            -- Process interest for this card
            v_result := utils.process_interest_accrual(
                v_limit_record.credit_card_account_id,
                p_accrual_date,
                v_limit_record.user_data
            );

            -- Count results
            IF (v_result->>'accrued')::BOOLEAN THEN
                v_success_count := v_success_count + 1;
            ELSE
                v_skipped_count := v_skipped_count + 1;
            END IF;

            -- Add to results array
            v_results := array_append(v_results, v_result);

        EXCEPTION WHEN OTHERS THEN
            -- Log error and continue
            v_error_count := v_error_count + 1;
            v_results := array_append(v_results, jsonb_build_object(
                'success', false,
                'accrued', false,
                'account_uuid', v_limit_record.account_uuid,
                'account_name', v_limit_record.account_name,
                'error', SQLERRM
            ));
        END;
    END LOOP;

    -- Return summary with all results
    RETURN jsonb_build_object(
        'success', true,
        'accrual_date', p_accrual_date,
        'total_processed', array_length(v_results, 1),
        'success_count', v_success_count,
        'skipped_count', v_skipped_count,
        'error_count', v_error_count,
        'results', to_jsonb(v_results)
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.process_all_interest_accruals IS 'Process daily interest accrual for all active credit cards with APR > 0. Used by nightly batch job.';

-- ============================================================================
-- API SCHEMA - Public Interface Functions and Views
-- ============================================================================

-- ----------------------------------------------------------------------------
-- View: api.credit_card_limits
-- Purpose: Public view of credit card limits with account details
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW api.credit_card_limits AS
SELECT
    ccl.uuid,
    ccl.created_at,
    ccl.updated_at,
    a.uuid as account_uuid,
    a.name as account_name,
    ccl.credit_limit,
    ccl.warning_threshold_percent,
    ccl.annual_percentage_rate,
    ccl.interest_type,
    ccl.compounding_frequency,
    ccl.statement_day_of_month,
    ccl.due_date_offset_days,
    ccl.grace_period_days,
    ccl.minimum_payment_percent,
    ccl.minimum_payment_flat,
    ccl.auto_payment_enabled,
    ccl.auto_payment_type,
    ccl.auto_payment_amount,
    ccl.auto_payment_date,
    ccl.is_active,
    ccl.last_interest_accrual_date,
    ccl.notes,
    ccl.metadata,
    -- Calculate current balance from latest snapshot
    COALESCE(
        (SELECT bs.balance
         FROM data.balance_snapshots bs
         WHERE bs.account_id = ccl.credit_card_account_id
         ORDER BY bs.transaction_id DESC
         LIMIT 1),
        0
    ) as current_balance,
    -- Calculate utilization percentage
    CASE
        WHEN ccl.credit_limit > 0 THEN
            ROUND((
                COALESCE(
                    (SELECT bs.balance
                     FROM data.balance_snapshots bs
                     WHERE bs.account_id = ccl.credit_card_account_id
                     ORDER BY bs.transaction_id DESC
                     LIMIT 1),
                    0
                ) / ccl.credit_limit * 100
            ), 2)
        ELSE 0
    END as utilization_percent
FROM data.credit_card_limits ccl
JOIN data.accounts a ON a.id = ccl.credit_card_account_id
WHERE ccl.user_data = utils.get_user();

COMMENT ON VIEW api.credit_card_limits IS 'Public view of credit card limits with account details and current utilization';

-- ----------------------------------------------------------------------------
-- Function: api.get_credit_card_limit
-- Purpose: Get credit card limit configuration by account UUID
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
-- Returns: Record with limit configuration and current utilization
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_credit_card_limit(
    p_account_uuid TEXT
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ,
    account_uuid TEXT,
    account_name TEXT,
    credit_limit NUMERIC,
    warning_threshold_percent INTEGER,
    annual_percentage_rate NUMERIC,
    interest_type TEXT,
    compounding_frequency TEXT,
    statement_day_of_month INTEGER,
    due_date_offset_days INTEGER,
    grace_period_days INTEGER,
    minimum_payment_percent NUMERIC,
    minimum_payment_flat NUMERIC,
    auto_payment_enabled BOOLEAN,
    auto_payment_type TEXT,
    auto_payment_amount NUMERIC,
    auto_payment_date INTEGER,
    is_active BOOLEAN,
    last_interest_accrual_date DATE,
    notes TEXT,
    metadata JSONB,
    current_balance NUMERIC,
    utilization_percent NUMERIC
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT *
    FROM api.credit_card_limits
    WHERE account_uuid = p_account_uuid
    LIMIT 1;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_credit_card_limit IS 'Get credit card limit configuration by account UUID';

-- ----------------------------------------------------------------------------
-- Function: api.process_interest_accrual
-- Purpose: Process interest accrual for a credit card (wrapper for utils)
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
--   p_accrual_date: Date for which to accrue interest (optional)
-- Returns: JSON with interest details and transaction ID
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.process_interest_accrual(
    p_account_uuid TEXT,
    p_accrual_date DATE DEFAULT CURRENT_DATE
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

    -- Process interest accrual
    v_result := utils.process_interest_accrual(
        v_account_id,
        p_accrual_date
    );

    RETURN v_result;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.process_interest_accrual IS 'Process interest accrual for a credit card account by UUID. Wrapper for utils.process_interest_accrual.';

-- ----------------------------------------------------------------------------
-- Function: api.get_interest_summary
-- Purpose: Get summary of interest charges for a credit card account
-- Parameters:
--   p_account_uuid: Public UUID of credit card account
--   p_start_date: Start date for summary (optional)
--   p_end_date: End date for summary (optional)
-- Returns: Table with interest transaction details
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_interest_summary(
    p_account_uuid TEXT,
    p_start_date DATE DEFAULT NULL,
    p_end_date DATE DEFAULT NULL
)
RETURNS TABLE (
    transaction_uuid TEXT,
    date DATE,
    description TEXT,
    amount BIGINT,
    amount_display TEXT,
    balance_at_accrual NUMERIC,
    apr NUMERIC,
    compounding_frequency TEXT,
    created_at TIMESTAMPTZ
)
LANGUAGE plpgsql
STABLE
SECURITY INVOKER
AS $$
DECLARE
    v_account_id BIGINT;
    v_start_date DATE;
    v_end_date DATE;
BEGIN
    -- Get account ID
    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_account_uuid
        AND user_data = utils.get_user()
        AND type = 'liability';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Credit card account not found or access denied';
    END IF;

    -- Set default date range (last 90 days if not specified)
    v_start_date := COALESCE(p_start_date, CURRENT_DATE - INTERVAL '90 days');
    v_end_date := COALESCE(p_end_date, CURRENT_DATE);

    -- Return interest transactions
    RETURN QUERY
    SELECT
        t.uuid as transaction_uuid,
        t.date::DATE as date,
        t.description,
        t.amount,
        '$' || (t.amount / 100.0)::TEXT as amount_display,
        (t.metadata->>'balance_at_accrual')::NUMERIC as balance_at_accrual,
        (t.metadata->>'apr')::NUMERIC as apr,
        t.metadata->>'compounding_frequency' as compounding_frequency,
        t.created_at
    FROM data.transactions t
    WHERE t.credit_account_id = v_account_id
        AND t.user_data = utils.get_user()
        AND t.metadata->>'is_interest_charge' = 'true'
        AND t.date >= v_start_date
        AND t.date <= v_end_date
        AND t.deleted_at IS NULL
    ORDER BY t.date DESC, t.created_at DESC;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_interest_summary IS 'Get summary of interest charges for a credit card account within a date range';

-- +goose Down
-- Drop all functions and views in reverse order

DROP FUNCTION IF EXISTS api.get_interest_summary(TEXT, DATE, DATE);
DROP FUNCTION IF EXISTS api.process_interest_accrual(TEXT, DATE);
DROP FUNCTION IF EXISTS api.get_credit_card_limit(TEXT);
DROP VIEW IF EXISTS api.credit_card_limits;

DROP FUNCTION IF EXISTS utils.process_all_interest_accruals(DATE);
DROP FUNCTION IF EXISTS utils.process_interest_accrual(BIGINT, DATE, TEXT);
DROP FUNCTION IF EXISTS utils.get_credit_card_limit_by_account_id(BIGINT);
DROP FUNCTION IF EXISTS utils.calculate_daily_interest(NUMERIC, NUMERIC, TEXT);
