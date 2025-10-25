-- +goose Up
-- Migration: Add payment scheduling for credit cards
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 4
-- Purpose: Implement scheduled payments, auto-payment, and payment processing

-- ============================================================================
-- DATA SCHEMA - Scheduled Payments Table
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: data.scheduled_payments
-- Purpose: Store scheduled payment configurations for credit cards
-- ----------------------------------------------------------------------------
CREATE TABLE data.scheduled_payments (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),

    -- Payment references
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,
    bank_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,
    statement_id BIGINT REFERENCES data.credit_card_statements(id) ON DELETE SET NULL,

    -- Payment details
    payment_type TEXT NOT NULL CHECK (payment_type IN ('minimum', 'full_balance', 'fixed_amount', 'custom')),
    payment_amount BIGINT CHECK (payment_type != 'fixed_amount' OR payment_amount > 0),
    scheduled_date DATE NOT NULL,

    -- Status and processing
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'cancelled')),
    processed_date TIMESTAMPTZ,
    processed_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,
    actual_amount_paid BIGINT,

    -- Error tracking
    error_message TEXT,
    retry_count INTEGER NOT NULL DEFAULT 0,
    last_retry_at TIMESTAMPTZ,

    -- Metadata
    notes TEXT,
    metadata JSONB,

    -- Constraints
    CONSTRAINT scheduled_payments_uuid_unique UNIQUE(uuid),
    CONSTRAINT scheduled_payments_user_data_length CHECK (char_length(user_data) <= 255),
    CONSTRAINT scheduled_payments_notes_length CHECK (char_length(notes) <= 1000),
    CONSTRAINT scheduled_payments_retry_count_nonneg CHECK (retry_count >= 0)
);

-- Create indexes for optimal query performance
CREATE INDEX idx_scheduled_payments_credit_card ON data.scheduled_payments(credit_card_account_id);
CREATE INDEX idx_scheduled_payments_bank_account ON data.scheduled_payments(bank_account_id);
CREATE INDEX idx_scheduled_payments_statement ON data.scheduled_payments(statement_id);
CREATE INDEX idx_scheduled_payments_scheduled_date ON data.scheduled_payments(scheduled_date);
CREATE INDEX idx_scheduled_payments_status ON data.scheduled_payments(status);
CREATE INDEX idx_scheduled_payments_user_data ON data.scheduled_payments(user_data);

-- Enable Row-Level Security
ALTER TABLE data.scheduled_payments ENABLE ROW LEVEL SECURITY;

-- Create RLS policy
CREATE POLICY scheduled_payments_policy ON data.scheduled_payments
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Create trigger for updated_at
CREATE TRIGGER update_scheduled_payments_updated_at
    BEFORE UPDATE ON data.scheduled_payments
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Add comments
COMMENT ON TABLE data.scheduled_payments IS 'Scheduled payment configurations for credit card accounts including auto-payments and one-time scheduled payments';
COMMENT ON COLUMN data.scheduled_payments.payment_type IS 'Type of payment: minimum (minimum payment due), full_balance (entire balance), fixed_amount (specific amount), custom (user-specified)';
COMMENT ON COLUMN data.scheduled_payments.payment_amount IS 'Fixed amount in cents when payment_type is fixed_amount or custom';
COMMENT ON COLUMN data.scheduled_payments.scheduled_date IS 'Date when payment should be processed';
COMMENT ON COLUMN data.scheduled_payments.status IS 'Payment status: pending, processing, completed, failed, cancelled';
COMMENT ON COLUMN data.scheduled_payments.retry_count IS 'Number of times payment processing has been retried after failure';

-- ============================================================================
-- UTILS SCHEMA - Internal Business Logic Functions
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Function: utils.calculate_payment_amount
-- Purpose: Calculate payment amount based on payment type
-- Parameters:
--   p_payment_type: Type of payment (minimum, full_balance, fixed_amount, custom)
--   p_statement_id: Statement ID for minimum/full balance calculations
--   p_fixed_amount: Fixed amount for fixed_amount/custom types
-- Returns: Payment amount in cents
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.calculate_payment_amount(
    p_payment_type TEXT,
    p_statement_id BIGINT DEFAULT NULL,
    p_fixed_amount BIGINT DEFAULT NULL
)
RETURNS BIGINT
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_statement RECORD;
    v_payment_amount BIGINT;
BEGIN
    -- Validate payment type
    IF p_payment_type NOT IN ('minimum', 'full_balance', 'fixed_amount', 'custom') THEN
        RAISE EXCEPTION 'Invalid payment_type: %. Must be minimum, full_balance, fixed_amount, or custom', p_payment_type;
    END IF;

    -- Handle fixed amount types
    IF p_payment_type IN ('fixed_amount', 'custom') THEN
        IF p_fixed_amount IS NULL OR p_fixed_amount <= 0 THEN
            RAISE EXCEPTION 'fixed_amount must be provided and > 0 for payment_type %', p_payment_type;
        END IF;
        RETURN p_fixed_amount;
    END IF;

    -- Handle statement-based types
    IF p_statement_id IS NULL THEN
        RAISE EXCEPTION 'statement_id must be provided for payment_type %', p_payment_type;
    END IF;

    -- Get statement details
    SELECT ending_balance, minimum_payment_due
    INTO v_statement
    FROM data.credit_card_statements
    WHERE id = p_statement_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Statement not found: %', p_statement_id;
    END IF;

    -- Calculate based on type
    IF p_payment_type = 'minimum' THEN
        v_payment_amount := v_statement.minimum_payment_due;
    ELSIF p_payment_type = 'full_balance' THEN
        v_payment_amount := v_statement.ending_balance;
    ELSE
        RAISE EXCEPTION 'Unhandled payment_type: %', p_payment_type;
    END IF;

    -- Ensure amount is not negative
    v_payment_amount := GREATEST(v_payment_amount, 0);

    RETURN v_payment_amount;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.calculate_payment_amount IS 'Calculate payment amount based on payment type (minimum, full_balance, fixed_amount, custom)';

-- ----------------------------------------------------------------------------
-- Function: utils.process_scheduled_payment
-- Purpose: Process a scheduled payment
-- Parameters:
--   p_scheduled_payment_id: Internal ID of scheduled payment
--   p_user_data: User identifier
-- Returns: JSON with payment processing result
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.process_scheduled_payment(
    p_scheduled_payment_id BIGINT,
    p_user_data TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_user_data TEXT;
    v_payment RECORD;
    v_credit_card RECORD;
    v_bank_account RECORD;
    v_ledger_id BIGINT;
    v_payment_amount BIGINT;
    v_current_balance BIGINT;
    v_bank_balance BIGINT;
    v_transaction_id BIGINT;
    v_result JSONB;
BEGIN
    -- Get user context
    v_user_data := COALESCE(p_user_data, utils.get_user());

    -- Get scheduled payment with lock
    SELECT * INTO v_payment
    FROM data.scheduled_payments
    WHERE id = p_scheduled_payment_id
        AND user_data = v_user_data
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Scheduled payment not found or access denied'
        );
    END IF;

    -- Check if already processed
    IF v_payment.status IN ('completed', 'processing', 'cancelled') THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Payment already ' || v_payment.status,
            'status', v_payment.status
        );
    END IF;

    -- Mark as processing
    UPDATE data.scheduled_payments
    SET status = 'processing',
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_scheduled_payment_id;

    -- Get credit card account details
    SELECT id, ledger_id, uuid, name
    INTO v_credit_card
    FROM data.accounts
    WHERE id = v_payment.credit_card_account_id
        AND user_data = v_user_data
        AND type = 'liability';

    IF NOT FOUND THEN
        UPDATE data.scheduled_payments
        SET status = 'failed',
            error_message = 'Credit card account not found',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_scheduled_payment_id;

        RETURN jsonb_build_object(
            'success', false,
            'error', 'Credit card account not found'
        );
    END IF;

    v_ledger_id := v_credit_card.ledger_id;

    -- Get bank account details
    SELECT id, uuid, name
    INTO v_bank_account
    FROM data.accounts
    WHERE id = v_payment.bank_account_id
        AND user_data = v_user_data
        AND ledger_id = v_ledger_id
        AND type = 'asset';

    IF NOT FOUND THEN
        UPDATE data.scheduled_payments
        SET status = 'failed',
            error_message = 'Bank account not found',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_scheduled_payment_id;

        RETURN jsonb_build_object(
            'success', false,
            'error', 'Bank account not found'
        );
    END IF;

    -- Calculate payment amount
    BEGIN
        v_payment_amount := utils.calculate_payment_amount(
            v_payment.payment_type,
            v_payment.statement_id,
            v_payment.payment_amount
        );
    EXCEPTION WHEN OTHERS THEN
        UPDATE data.scheduled_payments
        SET status = 'failed',
            error_message = 'Failed to calculate payment amount: ' || SQLERRM,
            retry_count = retry_count + 1,
            last_retry_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_scheduled_payment_id;

        RETURN jsonb_build_object(
            'success', false,
            'error', 'Failed to calculate payment amount: ' || SQLERRM
        );
    END;

    -- Check if payment amount is zero or negative
    IF v_payment_amount <= 0 THEN
        UPDATE data.scheduled_payments
        SET status = 'completed',
            processed_date = CURRENT_TIMESTAMP,
            actual_amount_paid = 0,
            notes = COALESCE(notes || E'\n', '') || 'No payment needed - balance is zero',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_scheduled_payment_id;

        RETURN jsonb_build_object(
            'success', true,
            'processed', false,
            'message', 'No payment needed - balance is zero',
            'payment_amount', 0
        );
    END IF;

    -- Get current credit card balance
    v_current_balance := COALESCE(
        (SELECT balance FROM data.balance_snapshots
         WHERE account_id = v_payment.credit_card_account_id
         ORDER BY transaction_id DESC
         LIMIT 1),
        0
    );

    -- Cap payment at current balance
    v_payment_amount := LEAST(v_payment_amount, v_current_balance);

    -- Get bank account balance
    v_bank_balance := COALESCE(
        (SELECT balance FROM data.balance_snapshots
         WHERE account_id = v_payment.bank_account_id
         ORDER BY transaction_id DESC
         LIMIT 1),
        0
    );

    -- Check if bank has sufficient funds
    IF v_bank_balance < v_payment_amount THEN
        UPDATE data.scheduled_payments
        SET status = 'failed',
            error_message = 'Insufficient funds in bank account',
            retry_count = retry_count + 1,
            last_retry_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = p_scheduled_payment_id;

        RETURN jsonb_build_object(
            'success', false,
            'error', 'Insufficient funds in bank account',
            'bank_balance', v_bank_balance,
            'payment_amount', v_payment_amount
        );
    END IF;

    -- Create payment transaction
    -- Debit: Credit Card (decrease liability)
    -- Credit: Bank Account (decrease asset)
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
        v_payment.scheduled_date,
        'Scheduled payment - ' || v_credit_card.name,
        v_payment_amount,
        v_payment.credit_card_account_id,
        v_payment.bank_account_id,
        v_user_data,
        jsonb_build_object(
            'is_scheduled_payment', true,
            'scheduled_payment_uuid', v_payment.uuid,
            'payment_type', v_payment.payment_type,
            'credit_card_uuid', v_credit_card.uuid,
            'bank_account_uuid', v_bank_account.uuid
        )
    )
    RETURNING id INTO v_transaction_id;

    -- Update scheduled payment as completed
    UPDATE data.scheduled_payments
    SET status = 'completed',
        processed_date = CURRENT_TIMESTAMP,
        processed_transaction_id = v_transaction_id,
        actual_amount_paid = v_payment_amount,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_scheduled_payment_id;

    -- Return success
    RETURN jsonb_build_object(
        'success', true,
        'processed', true,
        'message', 'Payment processed successfully',
        'payment_uuid', v_payment.uuid,
        'transaction_id', v_transaction_id,
        'payment_amount', v_payment_amount,
        'payment_amount_display', '$' || (v_payment_amount / 100.0)::TEXT,
        'credit_card', v_credit_card.name,
        'bank_account', v_bank_account.name
    );

EXCEPTION WHEN OTHERS THEN
    -- Handle unexpected errors
    UPDATE data.scheduled_payments
    SET status = 'failed',
        error_message = SQLERRM,
        retry_count = retry_count + 1,
        last_retry_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_scheduled_payment_id;

    RETURN jsonb_build_object(
        'success', false,
        'error', 'Payment processing failed: ' || SQLERRM
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.process_scheduled_payment IS 'Process a scheduled payment by creating a transaction and updating payment status';

-- ----------------------------------------------------------------------------
-- Function: utils.process_all_scheduled_payments
-- Purpose: Process all scheduled payments due today
-- Parameters:
--   p_processing_date: Date to process payments for (defaults to current date)
-- Returns: JSON array with results for each payment
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.process_all_scheduled_payments(
    p_processing_date DATE DEFAULT CURRENT_DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_payment_record RECORD;
    v_result JSONB;
    v_results JSONB[] := ARRAY[]::JSONB[];
    v_success_count INTEGER := 0;
    v_failed_count INTEGER := 0;
    v_skipped_count INTEGER := 0;
BEGIN
    -- Process each pending scheduled payment due on or before processing date
    FOR v_payment_record IN
        SELECT
            sp.id,
            sp.uuid,
            sp.user_data,
            a.uuid as credit_card_uuid,
            a.name as credit_card_name,
            sp.payment_type,
            sp.scheduled_date
        FROM data.scheduled_payments sp
        JOIN data.accounts a ON a.id = sp.credit_card_account_id
        WHERE sp.status = 'pending'
            AND sp.scheduled_date <= p_processing_date
        ORDER BY sp.scheduled_date ASC, sp.created_at ASC
    LOOP
        BEGIN
            -- Process payment
            v_result := utils.process_scheduled_payment(
                v_payment_record.id,
                v_payment_record.user_data
            );

            -- Count results
            IF (v_result->>'success')::BOOLEAN THEN
                IF (v_result->>'processed')::BOOLEAN THEN
                    v_success_count := v_success_count + 1;
                ELSE
                    v_skipped_count := v_skipped_count + 1;
                END IF;
            ELSE
                v_failed_count := v_failed_count + 1;
            END IF;

            -- Add metadata
            v_result := v_result || jsonb_build_object(
                'payment_uuid', v_payment_record.uuid,
                'credit_card_uuid', v_payment_record.credit_card_uuid,
                'credit_card_name', v_payment_record.credit_card_name,
                'payment_type', v_payment_record.payment_type,
                'scheduled_date', v_payment_record.scheduled_date
            );

            v_results := array_append(v_results, v_result);

        EXCEPTION WHEN OTHERS THEN
            -- Log error and continue
            v_failed_count := v_failed_count + 1;
            v_results := array_append(v_results, jsonb_build_object(
                'success', false,
                'processed', false,
                'payment_uuid', v_payment_record.uuid,
                'credit_card_name', v_payment_record.credit_card_name,
                'error', 'Unexpected error: ' || SQLERRM
            ));
        END;
    END LOOP;

    -- Return summary
    RETURN jsonb_build_object(
        'success', true,
        'processing_date', p_processing_date,
        'total_processed', array_length(v_results, 1),
        'success_count', v_success_count,
        'failed_count', v_failed_count,
        'skipped_count', v_skipped_count,
        'results', to_jsonb(v_results)
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.process_all_scheduled_payments IS 'Process all scheduled payments due on or before the processing date. Used by daily batch job.';

-- ----------------------------------------------------------------------------
-- Function: utils.create_auto_payments_from_statements
-- Purpose: Create scheduled payments from statements with auto-payment enabled
-- Parameters:
--   p_processing_date: Date to check for new statements
-- Returns: JSON with created payment count
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.create_auto_payments_from_statements(
    p_processing_date DATE DEFAULT CURRENT_DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_statement_record RECORD;
    v_limit_config RECORD;
    v_bank_account_id BIGINT;
    v_payment_date DATE;
    v_created_count INTEGER := 0;
    v_skipped_count INTEGER := 0;
BEGIN
    -- Find statements created today with auto-payment enabled
    FOR v_statement_record IN
        SELECT
            s.id as statement_id,
            s.credit_card_account_id,
            s.user_data,
            s.due_date,
            a.uuid as credit_card_uuid,
            a.name as credit_card_name
        FROM data.credit_card_statements s
        JOIN data.accounts a ON a.id = s.credit_card_account_id
        WHERE s.created_at::DATE = p_processing_date
            AND s.is_current = true
            AND s.ending_balance > 0
    LOOP
        -- Get credit card limit configuration
        SELECT * INTO v_limit_config
        FROM utils.get_credit_card_limit_by_account_id(v_statement_record.credit_card_account_id);

        -- Skip if auto-payment not enabled
        IF NOT v_limit_config.auto_payment_enabled THEN
            v_skipped_count := v_skipped_count + 1;
            CONTINUE;
        END IF;

        -- Check if payment already scheduled for this statement
        IF EXISTS (
            SELECT 1 FROM data.scheduled_payments
            WHERE statement_id = v_statement_record.statement_id
                AND status IN ('pending', 'processing', 'completed')
        ) THEN
            v_skipped_count := v_skipped_count + 1;
            CONTINUE;
        END IF;

        -- Find primary bank account (first asset account in ledger)
        SELECT id INTO v_bank_account_id
        FROM data.accounts
        WHERE ledger_id = (SELECT ledger_id FROM data.accounts WHERE id = v_statement_record.credit_card_account_id)
            AND user_data = v_statement_record.user_data
            AND type = 'asset'
        ORDER BY created_at ASC
        LIMIT 1;

        IF v_bank_account_id IS NULL THEN
            v_skipped_count := v_skipped_count + 1;
            CONTINUE;
        END IF;

        -- Determine payment date
        IF v_limit_config.auto_payment_date IS NOT NULL THEN
            -- Use configured auto-payment date
            v_payment_date := DATE_TRUNC('month', v_statement_record.due_date)::DATE + (v_limit_config.auto_payment_date - 1);
            -- If payment date is after due date, use due date
            IF v_payment_date > v_statement_record.due_date THEN
                v_payment_date := v_statement_record.due_date;
            END IF;
        ELSE
            -- Default to due date
            v_payment_date := v_statement_record.due_date;
        END IF;

        -- Create scheduled payment
        INSERT INTO data.scheduled_payments (
            credit_card_account_id,
            bank_account_id,
            statement_id,
            payment_type,
            payment_amount,
            scheduled_date,
            status,
            user_data,
            notes,
            metadata
        ) VALUES (
            v_statement_record.credit_card_account_id,
            v_bank_account_id,
            v_statement_record.statement_id,
            v_limit_config.auto_payment_type,
            v_limit_config.auto_payment_amount,
            v_payment_date,
            'pending',
            v_statement_record.user_data,
            'Auto-payment created from statement',
            jsonb_build_object(
                'is_auto_payment', true,
                'created_from_statement', true
            )
        );

        v_created_count := v_created_count + 1;
    END LOOP;

    RETURN jsonb_build_object(
        'success', true,
        'processing_date', p_processing_date,
        'created_count', v_created_count,
        'skipped_count', v_skipped_count
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.create_auto_payments_from_statements IS 'Create auto-payment scheduled payments from newly generated statements';

-- ============================================================================
-- API SCHEMA - Public Interface Functions and Views
-- ============================================================================

-- ----------------------------------------------------------------------------
-- View: api.scheduled_payments
-- Purpose: Public view of scheduled payments
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW api.scheduled_payments AS
SELECT
    sp.uuid,
    sp.created_at,
    sp.updated_at,
    cc.uuid as credit_card_uuid,
    cc.name as credit_card_name,
    ba.uuid as bank_account_uuid,
    ba.name as bank_account_name,
    s.uuid as statement_uuid,
    sp.payment_type,
    sp.payment_amount,
    sp.scheduled_date,
    sp.status,
    sp.processed_date,
    t.uuid as transaction_uuid,
    sp.actual_amount_paid,
    sp.error_message,
    sp.retry_count,
    sp.notes,
    sp.metadata,
    -- Calculate days until scheduled
    (sp.scheduled_date - CURRENT_DATE) as days_until_scheduled,
    -- Determine if overdue
    CASE
        WHEN sp.scheduled_date < CURRENT_DATE AND sp.status = 'pending' THEN true
        ELSE false
    END as is_overdue
FROM data.scheduled_payments sp
JOIN data.accounts cc ON cc.id = sp.credit_card_account_id
JOIN data.accounts ba ON ba.id = sp.bank_account_id
LEFT JOIN data.credit_card_statements s ON s.id = sp.statement_id
LEFT JOIN data.transactions t ON t.id = sp.processed_transaction_id
WHERE sp.user_data = utils.get_user()
ORDER BY sp.scheduled_date DESC, sp.created_at DESC;

COMMENT ON VIEW api.scheduled_payments IS 'Public view of scheduled payments with account details and processing status';

-- ----------------------------------------------------------------------------
-- Function: api.schedule_payment
-- Purpose: Schedule a payment for a credit card
-- Parameters:
--   p_credit_card_uuid: Credit card account UUID
--   p_bank_account_uuid: Bank account UUID
--   p_payment_type: Type of payment
--   p_payment_amount: Amount for fixed/custom payments
--   p_scheduled_date: Date to process payment
--   p_statement_uuid: Optional statement UUID
-- Returns: Created scheduled payment
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.schedule_payment(
    p_credit_card_uuid TEXT,
    p_bank_account_uuid TEXT,
    p_payment_type TEXT,
    p_payment_amount BIGINT DEFAULT NULL,
    p_scheduled_date DATE DEFAULT NULL,
    p_statement_uuid TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_credit_card_id BIGINT;
    v_bank_account_id BIGINT;
    v_statement_id BIGINT;
    v_ledger_id BIGINT;
    v_scheduled_date DATE;
    v_payment_uuid TEXT;
BEGIN
    -- Validate payment type
    IF p_payment_type NOT IN ('minimum', 'full_balance', 'fixed_amount', 'custom') THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Invalid payment_type. Must be minimum, full_balance, fixed_amount, or custom'
        );
    END IF;

    -- Get credit card account
    SELECT id, ledger_id INTO v_credit_card_id, v_ledger_id
    FROM data.accounts
    WHERE uuid = p_credit_card_uuid
        AND user_data = utils.get_user()
        AND type = 'liability';

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Credit card account not found or access denied'
        );
    END IF;

    -- Get bank account and verify same ledger
    SELECT id INTO v_bank_account_id
    FROM data.accounts
    WHERE uuid = p_bank_account_uuid
        AND user_data = utils.get_user()
        AND ledger_id = v_ledger_id
        AND type = 'asset';

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Bank account not found or not in same ledger'
        );
    END IF;

    -- Get statement if provided
    IF p_statement_uuid IS NOT NULL THEN
        SELECT id INTO v_statement_id
        FROM data.credit_card_statements
        WHERE uuid = p_statement_uuid
            AND credit_card_account_id = v_credit_card_id
            AND user_data = utils.get_user();

        IF NOT FOUND THEN
            RETURN jsonb_build_object(
                'success', false,
                'error', 'Statement not found'
            );
        END IF;
    END IF;

    -- Determine scheduled date
    v_scheduled_date := COALESCE(p_scheduled_date, CURRENT_DATE);

    -- Validate scheduled date is not in the past
    IF v_scheduled_date < CURRENT_DATE THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Scheduled date cannot be in the past'
        );
    END IF;

    -- Validate payment amount for fixed/custom types
    IF p_payment_type IN ('fixed_amount', 'custom') AND (p_payment_amount IS NULL OR p_payment_amount <= 0) THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'payment_amount must be provided and > 0 for ' || p_payment_type
        );
    END IF;

    -- Create scheduled payment
    INSERT INTO data.scheduled_payments (
        credit_card_account_id,
        bank_account_id,
        statement_id,
        payment_type,
        payment_amount,
        scheduled_date,
        status,
        user_data
    ) VALUES (
        v_credit_card_id,
        v_bank_account_id,
        v_statement_id,
        p_payment_type,
        p_payment_amount,
        v_scheduled_date,
        'pending',
        utils.get_user()
    )
    RETURNING uuid INTO v_payment_uuid;

    -- Return created payment
    RETURN jsonb_build_object(
        'success', true,
        'message', 'Payment scheduled successfully',
        'payment_uuid', v_payment_uuid
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.schedule_payment IS 'Schedule a payment for a credit card account';

-- ----------------------------------------------------------------------------
-- Function: api.cancel_scheduled_payment
-- Purpose: Cancel a pending scheduled payment
-- Parameters:
--   p_payment_uuid: UUID of scheduled payment
-- Returns: Success status
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.cancel_scheduled_payment(
    p_payment_uuid TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    v_payment RECORD;
BEGIN
    -- Get payment
    SELECT * INTO v_payment
    FROM data.scheduled_payments
    WHERE uuid = p_payment_uuid
        AND user_data = utils.get_user();

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Scheduled payment not found'
        );
    END IF;

    -- Check if can be cancelled
    IF v_payment.status NOT IN ('pending', 'failed') THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Payment cannot be cancelled. Current status: ' || v_payment.status
        );
    END IF;

    -- Cancel payment
    UPDATE data.scheduled_payments
    SET status = 'cancelled',
        updated_at = CURRENT_TIMESTAMP
    WHERE uuid = p_payment_uuid
        AND user_data = utils.get_user();

    RETURN jsonb_build_object(
        'success', true,
        'message', 'Payment cancelled successfully'
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.cancel_scheduled_payment IS 'Cancel a pending or failed scheduled payment';

-- ----------------------------------------------------------------------------
-- Function: api.get_scheduled_payments
-- Purpose: Get scheduled payments with optional filters
-- Parameters:
--   p_credit_card_uuid: Optional filter by credit card
--   p_status: Optional filter by status
-- Returns: Filtered scheduled payments
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_scheduled_payments(
    p_credit_card_uuid TEXT DEFAULT NULL,
    p_status TEXT DEFAULT NULL
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    credit_card_uuid TEXT,
    credit_card_name TEXT,
    bank_account_uuid TEXT,
    bank_account_name TEXT,
    payment_type TEXT,
    payment_amount BIGINT,
    scheduled_date DATE,
    status TEXT,
    processed_date TIMESTAMPTZ,
    actual_amount_paid BIGINT,
    error_message TEXT,
    days_until_scheduled INTEGER,
    is_overdue BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT
        uuid,
        created_at,
        credit_card_uuid,
        credit_card_name,
        bank_account_uuid,
        bank_account_name,
        payment_type,
        payment_amount,
        scheduled_date,
        status,
        processed_date,
        actual_amount_paid,
        error_message,
        days_until_scheduled,
        is_overdue
    FROM api.scheduled_payments
    WHERE (p_credit_card_uuid IS NULL OR credit_card_uuid = p_credit_card_uuid)
        AND (p_status IS NULL OR status = p_status)
    ORDER BY scheduled_date DESC, created_at DESC;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_scheduled_payments IS 'Get scheduled payments with optional filters for credit card and status';

-- +goose Down
-- Drop all functions and views in reverse order

DROP FUNCTION IF EXISTS api.get_scheduled_payments(TEXT, TEXT);
DROP FUNCTION IF EXISTS api.cancel_scheduled_payment(TEXT);
DROP FUNCTION IF EXISTS api.schedule_payment(TEXT, TEXT, TEXT, BIGINT, DATE, TEXT);
DROP VIEW IF EXISTS api.scheduled_payments;

DROP FUNCTION IF EXISTS utils.create_auto_payments_from_statements(DATE);
DROP FUNCTION IF EXISTS utils.process_all_scheduled_payments(DATE);
DROP FUNCTION IF EXISTS utils.process_scheduled_payment(BIGINT, TEXT);
DROP FUNCTION IF EXISTS utils.calculate_payment_amount(TEXT, BIGINT, BIGINT);

DROP TABLE IF EXISTS data.scheduled_payments CASCADE;
