-- Migration: Disable automatic CC budget move trigger
-- Date: 2025-01-09
-- Bug: When spending on CC, the category is debited twice (once for purchase, once for budget move)

-- +goose Up
-- +goose StatementBegin

-- Drop the trigger that automatically moves budget on CC spending
-- This trigger was causing double-deduction from spending categories
DROP TRIGGER IF EXISTS trigger_auto_move_cc_budget ON data.transactions;

COMMENT ON FUNCTION utils.auto_move_cc_budget_fn IS
'DISABLED: This trigger function was causing double-deduction from spending categories.
Previously: Automatically moved budget from spending category to CC payment category on CC spending.
Issue: The spending category was debited twice - once for the actual purchase, and once for the budget move.
Result: When spending $10 on CC from "Groceries", the category would lose $20 instead of $10.';

-- The utility functions are kept for potential future use or manual budget moves
-- but the automatic trigger is disabled

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Re-enable the trigger
CREATE TRIGGER trigger_auto_move_cc_budget
    AFTER INSERT
    ON data.transactions
    FOR EACH ROW
EXECUTE FUNCTION utils.auto_move_cc_budget_fn();

COMMENT ON TRIGGER trigger_auto_move_cc_budget ON data.transactions IS
'Automatically moves budget from spending category to CC payment category on credit card spending.';

COMMENT ON FUNCTION utils.auto_move_cc_budget_fn IS
'Trigger function that automatically moves budget from spending category to CC payment category
when a credit card spending transaction occurs.';

-- +goose StatementEnd
