-- +goose Up
-- Migration: Create installment_schedules table for individual installment payment tracking
-- Part of: INSTALLMENT_PAYMENTS_IMPLEMENTATION.md - Phase 1, Step 1.2
-- Purpose: Track individual installment payment schedule items for each installment plan

-- Create installment_schedules table
CREATE TABLE data.installment_schedules (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Link to parent plan
    installment_plan_id BIGINT NOT NULL REFERENCES data.installment_plans(id) ON DELETE CASCADE,

    -- Schedule details
    installment_number INTEGER NOT NULL,
    due_date DATE NOT NULL,
    scheduled_amount NUMERIC(19,4) NOT NULL,

    -- Completion tracking
    status TEXT NOT NULL DEFAULT 'scheduled',
    processed_date DATE,
    actual_amount NUMERIC(19,4),

    -- Link to the budget assignment transaction
    budget_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,

    -- Optional
    notes TEXT,

    CONSTRAINT installment_schedules_uuid_unique UNIQUE(uuid),
    CONSTRAINT installment_schedules_plan_number_unique UNIQUE(installment_plan_id, installment_number),
    CONSTRAINT installment_schedules_number_positive CHECK (installment_number > 0),
    CONSTRAINT installment_schedules_amount_positive CHECK (scheduled_amount > 0),
    CONSTRAINT installment_schedules_status_check CHECK (status IN ('scheduled', 'processed', 'skipped')),
    CONSTRAINT installment_schedules_notes_length_check CHECK (char_length(notes) <= 500)
);

-- Create indexes for optimal query performance
CREATE INDEX idx_installment_schedules_plan_id ON data.installment_schedules(installment_plan_id);
CREATE INDEX idx_installment_schedules_status ON data.installment_schedules(status);
CREATE INDEX idx_installment_schedules_due_date ON data.installment_schedules(due_date);
CREATE INDEX idx_installment_schedules_transaction ON data.installment_schedules(budget_transaction_id);

-- Create trigger to automatically update updated_at timestamp
CREATE TRIGGER update_installment_schedules_updated_at
    BEFORE UPDATE ON data.installment_schedules
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Add table and column comments for documentation
COMMENT ON TABLE data.installment_schedules IS 'Individual installment payment schedule items. Each record represents one installment in a payment plan.';
COMMENT ON COLUMN data.installment_schedules.id IS 'Primary key - internal ID';
COMMENT ON COLUMN data.installment_schedules.uuid IS 'Public-facing unique identifier';
COMMENT ON COLUMN data.installment_schedules.installment_plan_id IS 'Reference to the parent installment plan';
COMMENT ON COLUMN data.installment_schedules.installment_number IS 'Sequential number of this installment (1 to N)';
COMMENT ON COLUMN data.installment_schedules.due_date IS 'Date when this installment is due to be processed';
COMMENT ON COLUMN data.installment_schedules.scheduled_amount IS 'Amount scheduled for this installment';
COMMENT ON COLUMN data.installment_schedules.status IS 'Status: scheduled, processed, or skipped';
COMMENT ON COLUMN data.installment_schedules.processed_date IS 'Date when the installment was actually processed';
COMMENT ON COLUMN data.installment_schedules.actual_amount IS 'Actual amount processed (may differ from scheduled)';
COMMENT ON COLUMN data.installment_schedules.budget_transaction_id IS 'Transaction that moved money from category to CC payment category';
COMMENT ON COLUMN data.installment_schedules.notes IS 'Optional notes about this specific installment';

-- +goose Down
-- Drop the installment_schedules table and all related objects
DROP TRIGGER IF EXISTS update_installment_schedules_updated_at ON data.installment_schedules;
DROP TABLE IF EXISTS data.installment_schedules CASCADE;
