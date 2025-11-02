-- Migration: Fix existing loan payment dates
-- Created: 2025-11-01
-- Purpose: Update all existing loan payment schedules to have correct due dates

-- +goose Up
-- +goose StatementBegin

-- Update all existing loan payment due dates to match the corrected calculation
-- For each payment, the due date should be:
--   first_payment_date + (payment_number - 1) months

UPDATE data.loan_payments lp
SET due_date = (
    SELECT l.first_payment_date + ((lp.payment_number - 1) || ' months')::interval
    FROM data.loans l
    WHERE l.id = lp.loan_id
)
WHERE lp.status = 'scheduled'  -- Only update scheduled payments, not paid ones
  AND lp.paid_date IS NULL;    -- Don't modify payments that have been paid

-- Log the number of rows updated
DO $$
DECLARE
    v_count integer;
BEGIN
    GET DIAGNOSTICS v_count = ROW_COUNT;
    RAISE NOTICE 'Updated % loan payment due dates to match corrected first payment dates', v_count;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Cannot reliably restore the old incorrect dates
-- This migration is one-way only
RAISE NOTICE 'Cannot restore incorrect payment dates - migration is one-way only';

-- +goose StatementEnd
