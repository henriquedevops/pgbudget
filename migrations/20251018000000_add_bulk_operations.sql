-- +goose Up
-- +goose StatementBegin
-- Migration: Add bulk operation API functions for Phase 6.3
-- Description: Implement bulk categorize, delete, and edit operations for transactions

-- Bulk categorize transactions
CREATE OR REPLACE FUNCTION api.bulk_categorize_transactions(
    p_transaction_uuids text[],
    p_category_uuid text,
    p_user_data text DEFAULT utils.get_user()
) RETURNS integer AS $bulk_categorize$
DECLARE
    v_count integer := 0;
    v_transaction_uuid text;
    v_category_id bigint;
BEGIN
    -- Validate category exists and belongs to user
    SELECT id INTO v_category_id
    FROM data.accounts
    WHERE uuid = p_category_uuid
      AND user_data = p_user_data
      AND type = 'equity';

    IF v_category_id IS NULL THEN
        RAISE EXCEPTION 'Category not found or does not belong to user';
    END IF;

    -- Update each transaction
    FOREACH v_transaction_uuid IN ARRAY p_transaction_uuids
    LOOP
        -- Update transaction to use new category
        -- This updates the debit/credit entries to point to the new category
        UPDATE data.transactions
        SET account_id = v_category_id,
            updated_at = now()
        WHERE uuid = v_transaction_uuid
          AND user_data = p_user_data;

        IF FOUND THEN
            v_count := v_count + 1;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$bulk_categorize$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.bulk_categorize_transactions IS
'Bulk update category for multiple transactions. Returns count of updated transactions.';

-- Bulk delete transactions
CREATE OR REPLACE FUNCTION api.bulk_delete_transactions(
    p_transaction_uuids text[],
    p_user_data text DEFAULT utils.get_user()
) RETURNS integer AS $bulk_delete$
DECLARE
    v_count integer := 0;
    v_transaction_uuid text;
BEGIN
    -- Delete each transaction
    FOREACH v_transaction_uuid IN ARRAY p_transaction_uuids
    LOOP
        DELETE FROM data.transactions
        WHERE uuid = v_transaction_uuid
          AND user_data = p_user_data;

        IF FOUND THEN
            v_count := v_count + 1;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$bulk_delete$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.bulk_delete_transactions IS
'Bulk delete multiple transactions. Returns count of deleted transactions.';

-- Bulk edit transaction dates
CREATE OR REPLACE FUNCTION api.bulk_edit_transaction_dates(
    p_transaction_uuids text[],
    p_new_date timestamptz,
    p_user_data text DEFAULT utils.get_user()
) RETURNS integer AS $bulk_edit_dates$
DECLARE
    v_count integer := 0;
    v_transaction_uuid text;
BEGIN
    -- Update each transaction date
    FOREACH v_transaction_uuid IN ARRAY p_transaction_uuids
    LOOP
        UPDATE data.transactions
        SET date = p_new_date,
            updated_at = now()
        WHERE uuid = v_transaction_uuid
          AND user_data = p_user_data;

        IF FOUND THEN
            v_count := v_count + 1;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$bulk_edit_dates$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.bulk_edit_transaction_dates IS
'Bulk update date for multiple transactions. Returns count of updated transactions.';

-- Bulk edit transaction accounts
CREATE OR REPLACE FUNCTION api.bulk_edit_transaction_accounts(
    p_transaction_uuids text[],
    p_new_account_uuid text,
    p_user_data text DEFAULT utils.get_user()
) RETURNS integer AS $bulk_edit_accounts$
DECLARE
    v_count integer := 0;
    v_transaction_uuid text;
    v_account_id bigint;
BEGIN
    -- Validate account exists and belongs to user
    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_new_account_uuid
      AND user_data = p_user_data;

    IF v_account_id IS NULL THEN
        RAISE EXCEPTION 'Account not found or does not belong to user';
    END IF;

    -- Update each transaction
    FOREACH v_transaction_uuid IN ARRAY p_transaction_uuids
    LOOP
        UPDATE data.transactions
        SET account_id = v_account_id,
            updated_at = now()
        WHERE uuid = v_transaction_uuid
          AND user_data = p_user_data;

        IF FOUND THEN
            v_count := v_count + 1;
        END IF;
    END LOOP;

    RETURN v_count;
END;
$bulk_edit_accounts$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.bulk_edit_transaction_accounts IS
'Bulk update account for multiple transactions. Returns count of updated transactions.';

-- Grant execute permissions
GRANT EXECUTE ON FUNCTION api.bulk_categorize_transactions TO pgbudget;
GRANT EXECUTE ON FUNCTION api.bulk_delete_transactions TO pgbudget;
GRANT EXECUTE ON FUNCTION api.bulk_edit_transaction_dates TO pgbudget;
GRANT EXECUTE ON FUNCTION api.bulk_edit_transaction_accounts TO pgbudget;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Rollback: Remove bulk operation functions

DROP FUNCTION IF EXISTS api.bulk_categorize_transactions;
DROP FUNCTION IF EXISTS api.bulk_delete_transactions;
DROP FUNCTION IF EXISTS api.bulk_edit_transaction_dates;
DROP FUNCTION IF EXISTS api.bulk_edit_transaction_accounts;

-- +goose StatementEnd
