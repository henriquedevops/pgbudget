-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- ADD INITIAL PAYMENT TRACKING TO LOANS
-- ============================================================================
-- This migration adds fields to track payments made before starting to track
-- a loan in the system. This allows users to add loans that already have
-- payments made against them.
-- ============================================================================

-- Add initial payment tracking columns
ALTER TABLE data.loans
    ADD COLUMN initial_amount_paid numeric(19,4) DEFAULT 0 NOT NULL,
    ADD COLUMN initial_paid_as_of_date date;

-- Add constraints to ensure data integrity
ALTER TABLE data.loans
    ADD CONSTRAINT loans_initial_paid_non_negative
        CHECK (initial_amount_paid >= 0);

ALTER TABLE data.loans
    ADD CONSTRAINT loans_initial_paid_not_exceed_principal
        CHECK (initial_amount_paid <= principal_amount);

ALTER TABLE data.loans
    ADD CONSTRAINT loans_initial_date_after_start
        CHECK (initial_paid_as_of_date IS NULL OR initial_paid_as_of_date >= start_date);

-- Add comments
COMMENT ON COLUMN data.loans.initial_amount_paid IS
'Amount already paid toward this loan before tracking began in this system';

COMMENT ON COLUMN data.loans.initial_paid_as_of_date IS
'Date when the initial_amount_paid was current (when user started tracking)';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Remove the columns and constraints
ALTER TABLE data.loans
    DROP CONSTRAINT IF EXISTS loans_initial_date_after_start,
    DROP CONSTRAINT IF EXISTS loans_initial_paid_not_exceed_principal,
    DROP CONSTRAINT IF EXISTS loans_initial_paid_non_negative,
    DROP COLUMN IF EXISTS initial_paid_as_of_date,
    DROP COLUMN IF EXISTS initial_amount_paid;

-- +goose StatementEnd
