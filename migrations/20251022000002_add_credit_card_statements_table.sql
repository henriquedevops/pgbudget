-- +goose Up
-- Migration: Create credit_card_statements table for monthly billing statements
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 1
-- Purpose: Store monthly billing statements with transaction summaries and payment requirements

-- Create credit_card_statements table
CREATE TABLE data.credit_card_statements (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),

    -- Statement reference
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,
    statement_period_start DATE NOT NULL,
    statement_period_end DATE NOT NULL,

    -- Amounts
    previous_balance NUMERIC(19,4) NOT NULL DEFAULT 0,
    purchases_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    payments_amount NUMERIC(19,4) NOT NULL DEFAULT 0,
    interest_charged NUMERIC(19,4) NOT NULL DEFAULT 0,
    fees_charged NUMERIC(19,4) NOT NULL DEFAULT 0,
    ending_balance NUMERIC(19,4) NOT NULL,

    -- Payment info
    minimum_payment_due NUMERIC(19,4) NOT NULL,
    due_date DATE NOT NULL,

    -- Status
    is_current BOOLEAN NOT NULL DEFAULT false,

    -- Optional
    metadata JSONB,

    CONSTRAINT credit_card_statements_uuid_unique UNIQUE(uuid),
    CONSTRAINT credit_card_statements_period_order CHECK (statement_period_start < statement_period_end),
    CONSTRAINT credit_card_statements_due_after_end CHECK (due_date >= statement_period_end),
    CONSTRAINT credit_card_statements_previous_balance_nonneg CHECK (previous_balance >= 0),
    CONSTRAINT credit_card_statements_purchases_nonneg CHECK (purchases_amount >= 0),
    CONSTRAINT credit_card_statements_payments_nonneg CHECK (payments_amount >= 0),
    CONSTRAINT credit_card_statements_interest_nonneg CHECK (interest_charged >= 0),
    CONSTRAINT credit_card_statements_fees_nonneg CHECK (fees_charged >= 0),
    CONSTRAINT credit_card_statements_ending_balance_nonneg CHECK (ending_balance >= 0),
    CONSTRAINT credit_card_statements_minimum_payment_nonneg CHECK (minimum_payment_due >= 0),
    CONSTRAINT credit_card_statements_user_data_length CHECK (char_length(user_data) <= 255)
);

-- Create indexes for optimal query performance
CREATE INDEX idx_credit_card_statements_account_id ON data.credit_card_statements(credit_card_account_id);
CREATE INDEX idx_credit_card_statements_user_data ON data.credit_card_statements(user_data);
CREATE INDEX idx_credit_card_statements_period_end ON data.credit_card_statements(statement_period_end);
CREATE INDEX idx_credit_card_statements_due_date ON data.credit_card_statements(due_date);
CREATE INDEX idx_credit_card_statements_is_current ON data.credit_card_statements(is_current);

-- Enable Row-Level Security for multi-tenant data isolation
ALTER TABLE data.credit_card_statements ENABLE ROW LEVEL SECURITY;

-- Create RLS policy to ensure users only see their own data
CREATE POLICY credit_card_statements_policy ON data.credit_card_statements
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Add table and column comments for documentation
COMMENT ON TABLE data.credit_card_statements IS 'Monthly billing statements for credit card accounts. Stores transaction summaries, interest charges, and payment requirements for each billing cycle.';
COMMENT ON COLUMN data.credit_card_statements.id IS 'Primary key - internal ID';
COMMENT ON COLUMN data.credit_card_statements.uuid IS 'Public-facing unique identifier';
COMMENT ON COLUMN data.credit_card_statements.user_data IS 'User identifier for RLS policy enforcement';
COMMENT ON COLUMN data.credit_card_statements.credit_card_account_id IS 'Reference to the credit card account';
COMMENT ON COLUMN data.credit_card_statements.statement_period_start IS 'Start date of the billing cycle';
COMMENT ON COLUMN data.credit_card_statements.statement_period_end IS 'End date of the billing cycle (statement date)';
COMMENT ON COLUMN data.credit_card_statements.previous_balance IS 'Balance carried over from previous statement';
COMMENT ON COLUMN data.credit_card_statements.purchases_amount IS 'Total purchases made during this billing cycle';
COMMENT ON COLUMN data.credit_card_statements.payments_amount IS 'Total payments made during this billing cycle';
COMMENT ON COLUMN data.credit_card_statements.interest_charged IS 'Interest accrued during this billing cycle';
COMMENT ON COLUMN data.credit_card_statements.fees_charged IS 'Fees charged during this billing cycle (late fees, over-limit fees, etc.)';
COMMENT ON COLUMN data.credit_card_statements.ending_balance IS 'Total balance at end of billing cycle';
COMMENT ON COLUMN data.credit_card_statements.minimum_payment_due IS 'Calculated minimum payment for this statement';
COMMENT ON COLUMN data.credit_card_statements.due_date IS 'Payment due date for this statement';
COMMENT ON COLUMN data.credit_card_statements.is_current IS 'Whether this is the current active statement';
COMMENT ON COLUMN data.credit_card_statements.metadata IS 'Optional JSON metadata for extensibility';

-- +goose Down
-- Drop the credit_card_statements table and all related objects
DROP POLICY IF EXISTS credit_card_statements_policy ON data.credit_card_statements;
DROP TABLE IF EXISTS data.credit_card_statements CASCADE;
