-- +goose Up
-- Migration: Create installment_plans table for credit card installment payment tracking
-- Part of: INSTALLMENT_PAYMENTS_IMPLEMENTATION.md - Phase 1, Step 1.1
-- Purpose: Track master installment plans for spreading credit card purchases across multiple months

-- Create installment_plans table
CREATE TABLE data.installment_plans (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Original transaction details
    original_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE CASCADE,
    purchase_amount NUMERIC(19,4) NOT NULL,
    purchase_date DATE NOT NULL,
    description TEXT NOT NULL,

    -- Credit card information
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,

    -- Installment details
    number_of_installments INTEGER NOT NULL,
    installment_amount NUMERIC(19,4) NOT NULL,
    frequency TEXT NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,

    -- Category assignment
    category_account_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Status tracking
    status TEXT NOT NULL DEFAULT 'active',
    completed_installments INTEGER NOT NULL DEFAULT 0,

    -- Optional
    notes TEXT,
    metadata JSONB,

    CONSTRAINT installment_plans_uuid_unique UNIQUE(uuid),
    CONSTRAINT installment_plans_purchase_positive CHECK (purchase_amount > 0),
    CONSTRAINT installment_plans_installments_positive CHECK (number_of_installments > 0),
    CONSTRAINT installment_plans_installment_amount_positive CHECK (installment_amount > 0),
    CONSTRAINT installment_plans_frequency_check CHECK (frequency IN ('monthly', 'bi-weekly', 'weekly')),
    CONSTRAINT installment_plans_status_check CHECK (status IN ('active', 'completed', 'cancelled')),
    CONSTRAINT installment_plans_completed_range CHECK (completed_installments >= 0 AND completed_installments <= number_of_installments),
    CONSTRAINT installment_plans_user_data_length_check CHECK (char_length(user_data) <= 255),
    CONSTRAINT installment_plans_description_length_check CHECK (char_length(description) <= 255),
    CONSTRAINT installment_plans_notes_length_check CHECK (char_length(notes) <= 1000)
);

-- Create indexes for optimal query performance
CREATE INDEX idx_installment_plans_ledger_id ON data.installment_plans(ledger_id);
CREATE INDEX idx_installment_plans_user_data ON data.installment_plans(user_data);
CREATE INDEX idx_installment_plans_status ON data.installment_plans(status);
CREATE INDEX idx_installment_plans_credit_card ON data.installment_plans(credit_card_account_id);
CREATE INDEX idx_installment_plans_category ON data.installment_plans(category_account_id);
CREATE INDEX idx_installment_plans_original_transaction ON data.installment_plans(original_transaction_id);

-- Enable Row-Level Security for multi-tenant data isolation
ALTER TABLE data.installment_plans ENABLE ROW LEVEL SECURITY;

-- Create RLS policy to ensure users only see their own data
CREATE POLICY installment_plans_policy ON data.installment_plans
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Create trigger to automatically update updated_at timestamp
CREATE TRIGGER update_installment_plans_updated_at
    BEFORE UPDATE ON data.installment_plans
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Add table and column comments for documentation
COMMENT ON TABLE data.installment_plans IS 'Tracks installment payment plans for credit card purchases. Allows users to spread large purchases across multiple budget periods.';
COMMENT ON COLUMN data.installment_plans.id IS 'Primary key - internal ID';
COMMENT ON COLUMN data.installment_plans.uuid IS 'Public-facing unique identifier';
COMMENT ON COLUMN data.installment_plans.user_data IS 'User identifier for RLS policy enforcement';
COMMENT ON COLUMN data.installment_plans.ledger_id IS 'Reference to the budget ledger';
COMMENT ON COLUMN data.installment_plans.original_transaction_id IS 'Reference to the original purchase transaction (optional)';
COMMENT ON COLUMN data.installment_plans.purchase_amount IS 'Total purchase amount before installments';
COMMENT ON COLUMN data.installment_plans.purchase_date IS 'Date of the original purchase';
COMMENT ON COLUMN data.installment_plans.description IS 'Description of the purchase';
COMMENT ON COLUMN data.installment_plans.credit_card_account_id IS 'Credit card account used for the purchase';
COMMENT ON COLUMN data.installment_plans.number_of_installments IS 'Total number of installment payments (2-36)';
COMMENT ON COLUMN data.installment_plans.installment_amount IS 'Amount of each installment payment';
COMMENT ON COLUMN data.installment_plans.frequency IS 'Payment frequency: monthly, bi-weekly, or weekly';
COMMENT ON COLUMN data.installment_plans.start_date IS 'Date of the first installment';
COMMENT ON COLUMN data.installment_plans.category_account_id IS 'Budget category to spread the purchase across';
COMMENT ON COLUMN data.installment_plans.status IS 'Plan status: active, completed, or cancelled';
COMMENT ON COLUMN data.installment_plans.completed_installments IS 'Number of installments that have been processed';
COMMENT ON COLUMN data.installment_plans.notes IS 'Optional notes about the installment plan';
COMMENT ON COLUMN data.installment_plans.metadata IS 'Optional JSON metadata for extensibility';

-- +goose Down
-- Drop the installment_plans table and all related objects
DROP TRIGGER IF EXISTS update_installment_plans_updated_at ON data.installment_plans;
DROP POLICY IF EXISTS installment_plans_policy ON data.installment_plans;
DROP TABLE IF EXISTS data.installment_plans CASCADE;
