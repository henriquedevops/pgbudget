-- Migration: Add liability subtype to distinguish credit cards from other liabilities
-- Created: 2025-11-01
-- Purpose: Not all liability accounts are credit cards. This migration adds a way to
--          specify the subtype of liability accounts (credit card, loan, mortgage, etc.)

-- +goose Up
-- +goose StatementBegin

-- Add helper function to check if an account is a credit card
CREATE OR REPLACE FUNCTION utils.is_credit_card(p_account_id bigint)
RETURNS boolean
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_account RECORD;
BEGIN
    SELECT type, metadata
    INTO v_account
    FROM data.accounts
    WHERE id = p_account_id;

    -- Not a liability = not a credit card
    IF v_account.type != 'liability' THEN
        RETURN FALSE;
    END IF;

    -- Check metadata for liability_subtype
    IF v_account.metadata IS NOT NULL
       AND v_account.metadata->>'liability_subtype' = 'credit_card' THEN
        RETURN TRUE;
    END IF;

    -- If no metadata is set, check if this account has credit card limits
    -- This provides backward compatibility for existing accounts
    IF EXISTS (
        SELECT 1
        FROM data.credit_card_limits
        WHERE credit_card_account_id = p_account_id
        AND is_active = true
    ) THEN
        RETURN TRUE;
    END IF;

    RETURN FALSE;
END;
$$;

COMMENT ON FUNCTION utils.is_credit_card(bigint) IS
'Determines if an account is a credit card based on metadata or existing credit card limits';

-- Add helper function to get liability subtype
CREATE OR REPLACE FUNCTION utils.get_liability_subtype(p_account_id bigint)
RETURNS text
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_account RECORD;
    v_subtype text;
BEGIN
    SELECT type, metadata
    INTO v_account
    FROM data.accounts
    WHERE id = p_account_id;

    -- Not a liability = return NULL
    IF v_account.type != 'liability' THEN
        RETURN NULL;
    END IF;

    -- Get subtype from metadata
    v_subtype := v_account.metadata->>'liability_subtype';

    -- If not set, try to determine from related data
    IF v_subtype IS NULL THEN
        -- Check if has credit card limits
        IF EXISTS (
            SELECT 1
            FROM data.credit_card_limits
            WHERE credit_card_account_id = p_account_id
            AND is_active = true
        ) THEN
            RETURN 'credit_card';
        END IF;

        -- Check if has loan
        IF EXISTS (
            SELECT 1
            FROM data.loans
            WHERE account_id = p_account_id
        ) THEN
            RETURN 'loan';
        END IF;

        -- Default to 'other' for unspecified liabilities
        RETURN 'other';
    END IF;

    RETURN v_subtype;
END;
$$;

COMMENT ON FUNCTION utils.get_liability_subtype(bigint) IS
'Returns the subtype of a liability account (credit_card, loan, mortgage, line_of_credit, other)';

-- Update existing liability accounts that have credit card limits
-- to set their metadata correctly
DO $$
DECLARE
    v_account RECORD;
    v_new_metadata jsonb;
BEGIN
    FOR v_account IN
        SELECT DISTINCT a.id, a.metadata
        FROM data.accounts a
        INNER JOIN data.credit_card_limits ccl ON ccl.credit_card_account_id = a.id
        WHERE a.type = 'liability'
        AND ccl.is_active = true
    LOOP
        -- Build new metadata
        IF v_account.metadata IS NULL THEN
            v_new_metadata := '{"liability_subtype": "credit_card"}'::jsonb;
        ELSE
            v_new_metadata := v_account.metadata || '{"liability_subtype": "credit_card"}'::jsonb;
        END IF;

        -- Update the account
        UPDATE data.accounts
        SET metadata = v_new_metadata,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = v_account.id;

        RAISE NOTICE 'Marked account ID % as credit card', v_account.id;
    END LOOP;
END $$;

-- Update existing liability accounts that have loans
-- to set their metadata correctly
DO $$
DECLARE
    v_account RECORD;
    v_new_metadata jsonb;
BEGIN
    FOR v_account IN
        SELECT DISTINCT a.id, a.metadata
        FROM data.accounts a
        INNER JOIN data.loans l ON l.account_id = a.id
        WHERE a.type = 'liability'
        AND (a.metadata IS NULL OR a.metadata->>'liability_subtype' IS NULL)
    LOOP
        -- Build new metadata
        IF v_account.metadata IS NULL THEN
            v_new_metadata := '{"liability_subtype": "loan"}'::jsonb;
        ELSE
            v_new_metadata := v_account.metadata || '{"liability_subtype": "loan"}'::jsonb;
        END IF;

        -- Update the account
        UPDATE data.accounts
        SET metadata = v_new_metadata,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = v_account.id;

        RAISE NOTICE 'Marked account ID % as loan', v_account.id;
    END LOOP;
END $$;

-- Grant permissions (if role exists)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'pgbudget_user') THEN
        GRANT EXECUTE ON FUNCTION utils.is_credit_card(bigint) TO pgbudget_user;
        GRANT EXECUTE ON FUNCTION utils.get_liability_subtype(bigint) TO pgbudget_user;
    END IF;
END $$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS utils.get_liability_subtype(bigint);
DROP FUNCTION IF EXISTS utils.is_credit_card(bigint);

-- +goose StatementEnd
