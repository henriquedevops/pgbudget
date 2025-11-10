-- Migration: Clean up existing CC budget move transactions
-- Date: 2025-01-09
-- Purpose: Remove the double-deduction transactions created by the buggy trigger

-- +goose Up
-- +goose StatementBegin

-- First, identify all CC budget move transactions to delete
WITH budget_move_ids AS (
    SELECT id
    FROM data.transactions
    WHERE description LIKE 'CC Budget Move:%'
      AND (metadata->>'is_cc_budget_move')::boolean = true
      AND description NOT LIKE 'DELETED:%'
      AND description NOT LIKE 'REVERSAL:%'
)
-- Delete balance_snapshots that reference these transactions
, deleted_snapshots AS (
    DELETE FROM data.balance_snapshots
    WHERE transaction_id IN (SELECT id FROM budget_move_ids)
    RETURNING id
)
-- Delete transaction_log entries that reference these transactions
, deleted_logs AS (
    DELETE FROM data.transaction_log
    WHERE original_transaction_id IN (SELECT id FROM budget_move_ids)
       OR reversal_transaction_id IN (SELECT id FROM budget_move_ids)
       OR correction_transaction_id IN (SELECT id FROM budget_move_ids)
    RETURNING id
)
SELECT
    (SELECT COUNT(*) FROM deleted_snapshots) as snapshots_deleted,
    (SELECT COUNT(*) FROM deleted_logs) as logs_deleted;

-- Now delete all existing CC budget move transactions
-- These were automatically created and caused categories to be debited twice
DELETE FROM data.transactions
WHERE description LIKE 'CC Budget Move:%'
  AND (metadata->>'is_cc_budget_move')::boolean = true
  AND description NOT LIKE 'DELETED:%'
  AND description NOT LIKE 'REVERSAL:%';

-- Log the cleanup
DO $$
DECLARE
    v_deleted_count integer;
BEGIN
    GET DIAGNOSTICS v_deleted_count = ROW_COUNT;
    RAISE NOTICE 'Cleaned up % CC budget move transactions that were causing double-deduction from categories', v_deleted_count;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Cannot restore deleted transactions
-- This migration is not reversible
RAISE EXCEPTION 'Cannot rollback cleanup of CC budget move transactions - they have been permanently deleted';

-- +goose StatementEnd
