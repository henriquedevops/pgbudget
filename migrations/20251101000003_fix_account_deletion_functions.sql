-- Migration: Fix account deletion functions
-- Created: 2025-11-01
-- Purpose: Fix can_delete_account to work with actual schema (category_goals has no is_active)

-- +goose Up
-- +goose StatementBegin

-- Recreate function to check if account can be deleted (fixed version)
CREATE OR REPLACE FUNCTION utils.can_delete_account(p_account_id bigint)
RETURNS jsonb
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_account RECORD;
    v_transaction_count int;
    v_balance numeric;
    v_can_delete boolean := true;
    v_reason text := '';
    v_warnings text[] := ARRAY[]::text[];
BEGIN
    -- Get account details
    SELECT id, name, type, ledger_id, deleted_at
    INTO v_account
    FROM data.accounts
    WHERE id = p_account_id;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'can_delete', false,
            'reason', 'Account not found',
            'warnings', ARRAY[]::text[]
        );
    END IF;

    -- Check if already deleted
    IF v_account.deleted_at IS NOT NULL THEN
        RETURN jsonb_build_object(
            'can_delete', false,
            'reason', 'Account is already deleted',
            'warnings', ARRAY[]::text[]
        );
    END IF;

    -- Check if it's a special protected account
    IF v_account.name IN ('Income', 'Off-budget', 'Unassigned') AND v_account.type = 'equity' THEN
        RETURN jsonb_build_object(
            'can_delete', false,
            'reason', 'This is a special system account that cannot be deleted',
            'warnings', ARRAY[]::text[]
        );
    END IF;

    -- Check for existing transactions
    SELECT COUNT(*)
    INTO v_transaction_count
    FROM data.transactions
    WHERE (credit_account_id = p_account_id OR debit_account_id = p_account_id)
    AND deleted_at IS NULL;

    IF v_transaction_count > 0 THEN
        v_warnings := array_append(v_warnings,
            format('This account has %s transaction(s). They will be soft-deleted along with the account.', v_transaction_count)
        );
    END IF;

    -- Check account balance
    v_balance := utils.get_account_balance(v_account.ledger_id, p_account_id);

    IF v_balance != 0 THEN
        v_warnings := array_append(v_warnings,
            format('This account has a non-zero balance: %s', v_balance / 100.0)
        );
    END IF;

    -- Check for related data
    -- Credit card limits
    IF EXISTS (SELECT 1 FROM data.credit_card_limits WHERE credit_card_account_id = p_account_id AND is_active = true) THEN
        v_warnings := array_append(v_warnings, 'This account has active credit card limits that will be deactivated.');
    END IF;

    -- Loans
    IF EXISTS (SELECT 1 FROM data.loans WHERE account_id = p_account_id AND status != 'paid_off') THEN
        v_warnings := array_append(v_warnings, 'This account has active loan(s) that will be affected.');
    END IF;

    -- Installment plans
    IF EXISTS (SELECT 1 FROM data.installment_plans WHERE credit_card_account_id = p_account_id AND status = 'active') THEN
        v_warnings := array_append(v_warnings, 'This account has active installment plans that will be affected.');
    END IF;

    -- Category goals (no is_active column, just check existence)
    IF EXISTS (SELECT 1 FROM data.category_goals WHERE category_id = p_account_id) THEN
        v_warnings := array_append(v_warnings, 'This category has goals that will be deleted via CASCADE.');
    END IF;

    RETURN jsonb_build_object(
        'can_delete', v_can_delete,
        'reason', v_reason,
        'warnings', v_warnings
    );
END;
$$;

COMMENT ON FUNCTION utils.can_delete_account(bigint) IS
'Checks if an account can be deleted and returns warnings about related data';

-- Recreate function to soft delete an account (fixed version)
CREATE OR REPLACE FUNCTION api.delete_account(p_account_uuid text)
RETURNS jsonb
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_account_id bigint;
    v_account_name text;
    v_can_delete_result jsonb;
    v_transaction_count int := 0;
BEGIN
    -- Get account ID
    SELECT id, name
    INTO v_account_id, v_account_name
    FROM data.accounts
    WHERE uuid = p_account_uuid
    AND user_data = utils.get_user()
    AND deleted_at IS NULL;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', 'Account not found or already deleted'
        );
    END IF;

    -- Check if account can be deleted
    v_can_delete_result := utils.can_delete_account(v_account_id);

    IF NOT (v_can_delete_result->>'can_delete')::boolean THEN
        RETURN jsonb_build_object(
            'success', false,
            'error', v_can_delete_result->>'reason'
        );
    END IF;

    -- Soft delete the account
    UPDATE data.accounts
    SET deleted_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = v_account_id;

    -- Soft delete all transactions involving this account
    UPDATE data.transactions
    SET deleted_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP,
        description = 'DELETED: ' || description
    WHERE (credit_account_id = v_account_id OR debit_account_id = v_account_id)
    AND deleted_at IS NULL;

    GET DIAGNOSTICS v_transaction_count = ROW_COUNT;

    -- Deactivate credit card limits
    UPDATE data.credit_card_limits
    SET is_active = false,
        updated_at = CURRENT_TIMESTAMP
    WHERE credit_card_account_id = v_account_id;

    -- Note: Category goals will be deleted automatically via ON DELETE CASCADE
    -- Loans and installment plans are preserved as historical data

    RETURN jsonb_build_object(
        'success', true,
        'message', format('Account "%s" deleted successfully', v_account_name),
        'deleted_transactions', v_transaction_count,
        'warnings', v_can_delete_result->'warnings'
    );
END;
$$;

COMMENT ON FUNCTION api.delete_account(text) IS
'Soft deletes an account and all its associated transactions';

-- Grant permissions
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION utils.can_delete_account(bigint) TO pgbudget_user;
        GRANT EXECUTE ON FUNCTION api.delete_account(text) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS api.delete_account(text);
DROP FUNCTION IF EXISTS utils.can_delete_account(bigint);

-- +goose StatementEnd
