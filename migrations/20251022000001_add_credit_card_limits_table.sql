-- +goose Up
-- Migration: Create credit_card_limits table for credit limit and billing configuration
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 1
-- Purpose: Track credit limits, APR, billing cycles, and auto-payment configuration for credit card accounts

-- Create credit_card_limits table
CREATE TABLE data.credit_card_limits (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),

    -- Credit card reference
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,

    -- Spend limit
    credit_limit NUMERIC(19,4) NOT NULL,
    warning_threshold_percent INTEGER DEFAULT 80,

    -- APR and interest
    annual_percentage_rate NUMERIC(8,5) NOT NULL DEFAULT 0.0,
    interest_type TEXT NOT NULL DEFAULT 'variable',
    compounding_frequency TEXT NOT NULL DEFAULT 'daily',

    -- Billing cycle
    statement_day_of_month INTEGER NOT NULL DEFAULT 1,
    due_date_offset_days INTEGER NOT NULL DEFAULT 21,
    grace_period_days INTEGER NOT NULL DEFAULT 0,

    -- Minimum payment
    minimum_payment_percent NUMERIC(8,5) NOT NULL DEFAULT 1.0,
    minimum_payment_flat NUMERIC(19,4) NOT NULL DEFAULT 25.00,

    -- Auto-payment
    auto_payment_enabled BOOLEAN NOT NULL DEFAULT false,
    auto_payment_type TEXT,
    auto_payment_amount NUMERIC(19,4),
    auto_payment_date INTEGER,

    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,

    -- Audit
    last_interest_accrual_date DATE,
    notes TEXT,
    metadata JSONB,

    CONSTRAINT credit_card_limits_uuid_unique UNIQUE(uuid),
    CONSTRAINT credit_card_limits_credit_card_unique UNIQUE(credit_card_account_id, user_data),
    CONSTRAINT credit_card_limits_limit_positive CHECK (credit_limit > 0),
    CONSTRAINT credit_card_limits_warning_threshold_range CHECK (warning_threshold_percent > 0 AND warning_threshold_percent < 100),
    CONSTRAINT credit_card_limits_apr_range CHECK (annual_percentage_rate >= 0 AND annual_percentage_rate <= 100),
    CONSTRAINT credit_card_limits_interest_type_check CHECK (interest_type IN ('fixed', 'variable')),
    CONSTRAINT credit_card_limits_compounding_check CHECK (compounding_frequency IN ('daily', 'monthly')),
    CONSTRAINT credit_card_limits_statement_day_range CHECK (statement_day_of_month >= 1 AND statement_day_of_month <= 31),
    CONSTRAINT credit_card_limits_due_offset_positive CHECK (due_date_offset_days > 0),
    CONSTRAINT credit_card_limits_grace_period_nonneg CHECK (grace_period_days >= 0),
    CONSTRAINT credit_card_limits_min_payment_percent_positive CHECK (minimum_payment_percent > 0),
    CONSTRAINT credit_card_limits_min_payment_flat_nonneg CHECK (minimum_payment_flat >= 0),
    CONSTRAINT credit_card_limits_auto_payment_type_check CHECK (auto_payment_type IS NULL OR auto_payment_type IN ('minimum', 'full_balance', 'fixed_amount')),
    CONSTRAINT credit_card_limits_auto_payment_amount_positive CHECK (auto_payment_amount IS NULL OR auto_payment_amount > 0),
    CONSTRAINT credit_card_limits_auto_payment_date_range CHECK (auto_payment_date IS NULL OR (auto_payment_date >= 1 AND auto_payment_date <= 31)),
    CONSTRAINT credit_card_limits_user_data_length CHECK (char_length(user_data) <= 255),
    CONSTRAINT credit_card_limits_notes_length CHECK (char_length(notes) <= 1000)
);

-- Create indexes for optimal query performance
CREATE INDEX idx_credit_card_limits_account_id ON data.credit_card_limits(credit_card_account_id);
CREATE INDEX idx_credit_card_limits_user_data ON data.credit_card_limits(user_data);
CREATE INDEX idx_credit_card_limits_is_active ON data.credit_card_limits(is_active);

-- Enable Row-Level Security for multi-tenant data isolation
ALTER TABLE data.credit_card_limits ENABLE ROW LEVEL SECURITY;

-- Create RLS policy to ensure users only see their own data
CREATE POLICY credit_card_limits_policy ON data.credit_card_limits
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Create trigger to automatically update updated_at timestamp
CREATE TRIGGER update_credit_card_limits_updated_at
    BEFORE UPDATE ON data.credit_card_limits
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Add table and column comments for documentation
COMMENT ON TABLE data.credit_card_limits IS 'Tracks credit limits, APR, billing cycles, and auto-payment configuration for credit card accounts. Enables credit limit enforcement and billing management.';
COMMENT ON COLUMN data.credit_card_limits.id IS 'Primary key - internal ID';
COMMENT ON COLUMN data.credit_card_limits.uuid IS 'Public-facing unique identifier';
COMMENT ON COLUMN data.credit_card_limits.user_data IS 'User identifier for RLS policy enforcement';
COMMENT ON COLUMN data.credit_card_limits.credit_card_account_id IS 'Reference to the credit card liability account';
COMMENT ON COLUMN data.credit_card_limits.credit_limit IS 'Maximum credit balance allowed on this card';
COMMENT ON COLUMN data.credit_card_limits.warning_threshold_percent IS 'Percentage of limit at which to warn user (default 80%)';
COMMENT ON COLUMN data.credit_card_limits.annual_percentage_rate IS 'Annual percentage rate for interest calculation (0-100%)';
COMMENT ON COLUMN data.credit_card_limits.interest_type IS 'Type of interest rate: fixed or variable';
COMMENT ON COLUMN data.credit_card_limits.compounding_frequency IS 'How often interest compounds: daily or monthly';
COMMENT ON COLUMN data.credit_card_limits.statement_day_of_month IS 'Day of month when statements are generated (1-31)';
COMMENT ON COLUMN data.credit_card_limits.due_date_offset_days IS 'Days after statement date when payment is due (typically 21-25)';
COMMENT ON COLUMN data.credit_card_limits.grace_period_days IS 'Grace period days before late fees apply';
COMMENT ON COLUMN data.credit_card_limits.minimum_payment_percent IS 'Percentage of balance for minimum payment (typically 1-3%)';
COMMENT ON COLUMN data.credit_card_limits.minimum_payment_flat IS 'Flat minimum payment amount (e.g., $25)';
COMMENT ON COLUMN data.credit_card_limits.auto_payment_enabled IS 'Whether automatic payments are enabled';
COMMENT ON COLUMN data.credit_card_limits.auto_payment_type IS 'Type of auto-payment: minimum, full_balance, or fixed_amount';
COMMENT ON COLUMN data.credit_card_limits.auto_payment_amount IS 'Fixed payment amount if auto_payment_type is fixed_amount';
COMMENT ON COLUMN data.credit_card_limits.auto_payment_date IS 'Day of month when auto-payment processes (1-31)';
COMMENT ON COLUMN data.credit_card_limits.is_active IS 'Whether this limit configuration is active';
COMMENT ON COLUMN data.credit_card_limits.last_interest_accrual_date IS 'Last date when interest was accrued for this card';
COMMENT ON COLUMN data.credit_card_limits.notes IS 'Optional notes about this credit card limit configuration';
COMMENT ON COLUMN data.credit_card_limits.metadata IS 'Optional JSON metadata for extensibility';

-- +goose Down
-- Drop the credit_card_limits table and all related objects
DROP TRIGGER IF EXISTS update_credit_card_limits_updated_at ON data.credit_card_limits;
DROP POLICY IF EXISTS credit_card_limits_policy ON data.credit_card_limits;
DROP TABLE IF EXISTS data.credit_card_limits CASCADE;
