-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- UPDATE api.delete_loan TO DELETE AUTO-CREATED ACCOUNTS
-- ============================================================================
-- This migration updates the api.delete_loan function to automatically delete
-- the associated liability account if it was auto-created by the system
-- (identified by the description "Auto-created for loan:")
-- ============================================================================

DROP FUNCTION IF EXISTS api.delete_loan(text);

CREATE OR REPLACE FUNCTION api.delete_loan(
    p_loan_uuid text
) RETURNS boolean AS $apifunc$
DECLARE
    v_user_data text := utils.get_user();
    v_deleted integer;
    v_account_id bigint;
    v_account_uuid text;
    v_account_description text;
    v_payment_category_uuid text;
BEGIN
    -- Get the associated account_id, uuid, and description before deleting the loan
    SELECT account_id INTO v_account_id
    FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    -- Get account details if account exists
    IF v_account_id IS NOT NULL THEN
        SELECT uuid, description INTO v_account_uuid, v_account_description
        FROM data.accounts
        WHERE id = v_account_id
          AND user_data = v_user_data;

        -- Get the associated CC payment category UUID from the account's metadata
        SELECT metadata->>'payment_category_uuid' INTO v_payment_category_uuid
        FROM data.accounts
        WHERE id = v_account_id
          AND user_data = v_user_data;
    END IF;

    -- Delete the loan (CASCADE handles payments)
    DELETE FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    -- Check if anything was deleted
    GET DIAGNOSTICS v_deleted = ROW_COUNT;

    IF v_deleted = 0 THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    -- Delete the associated account and payment category if account was auto-created
    -- Auto-created accounts have description starting with "Auto-created for loan:"
    IF v_account_id IS NOT NULL AND v_account_description IS NOT NULL THEN
        IF v_account_description LIKE 'Auto-created for loan:%' THEN
            -- First, delete the CC payment category (equity account) if it exists
            IF v_payment_category_uuid IS NOT NULL THEN
                DELETE FROM data.accounts
                WHERE uuid = v_payment_category_uuid
                  AND user_data = v_user_data
                  AND metadata->>'is_cc_payment_category' = 'true';
            END IF;

            -- Then delete the liability account itself
            DELETE FROM data.accounts
            WHERE id = v_account_id
              AND user_data = v_user_data;
        END IF;
    END IF;

    RETURN true;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.delete_loan(text) IS
'Delete a loan and all its associated payment records. If the associated liability account was auto-created, it will also be deleted.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Restore previous version
DROP FUNCTION IF EXISTS api.delete_loan(text);

CREATE OR REPLACE FUNCTION api.delete_loan(
    p_loan_uuid text
) RETURNS boolean AS $apifunc$
DECLARE
    v_user_data text := utils.get_user();
    v_deleted integer;
BEGIN
    -- Delete the loan (RLS ensures user ownership, CASCADE handles payments)
    DELETE FROM data.loans
    WHERE uuid = p_loan_uuid
      AND user_data = v_user_data;

    -- Check if anything was deleted
    GET DIAGNOSTICS v_deleted = ROW_COUNT;

    IF v_deleted = 0 THEN
        RAISE EXCEPTION 'Loan with UUID % not found for current user', p_loan_uuid;
    END IF;

    RETURN true;
END;
$apifunc$ LANGUAGE plpgsql VOLATILE SECURITY INVOKER;

COMMENT ON FUNCTION api.delete_loan(text) IS
'Delete a loan and all its associated payment records';

-- +goose StatementEnd
