-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- ADD INITIAL PAYMENTS MADE TRACKING TO LOANS
-- ============================================================================
-- This migration adds a field to track how many payments were already made
-- before starting to track a loan in the system. This allows proper payment
-- numbering and remaining term calculation.
-- ============================================================================

-- Add initial payments made tracking column
ALTER TABLE data.loans
    ADD COLUMN initial_payments_made integer DEFAULT 0 NOT NULL;

-- Add constraint to ensure data integrity
ALTER TABLE data.loans
    ADD CONSTRAINT loans_initial_payments_non_negative
        CHECK (initial_payments_made >= 0);

ALTER TABLE data.loans
    ADD CONSTRAINT loans_initial_payments_not_exceed_term
        CHECK (initial_payments_made < loan_term_months);

-- Add comment
COMMENT ON COLUMN data.loans.initial_payments_made IS
'Number of payments already made toward this loan before tracking began in this system';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Remove the column and constraints
ALTER TABLE data.loans
    DROP CONSTRAINT IF EXISTS loans_initial_payments_not_exceed_term,
    DROP CONSTRAINT IF EXISTS loans_initial_payments_non_negative,
    DROP COLUMN IF EXISTS initial_payments_made;

-- +goose StatementEnd
