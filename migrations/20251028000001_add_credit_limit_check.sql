-- +goose Up
-- +goose StatementBegin

CREATE OR REPLACE FUNCTION utils.check_credit_limit_violation(
    p_account_id bigint,
    p_amount bigint
) RETURNS void AS $$
DECLARE
    v_credit_limit numeric;
    v_current_balance numeric;
    v_account_type text;
BEGIN
    -- Get account type
    SELECT type INTO v_account_type
    FROM data.accounts
    WHERE id = p_account_id;

    -- Only check for liability accounts
    IF v_account_type = 'liability' THEN
        -- Get credit limit for the account
        SELECT credit_limit INTO v_credit_limit
        FROM data.credit_card_limits
        WHERE credit_card_account_id = p_account_id
          AND is_active = true;

        -- If a credit limit is defined for this account
        IF v_credit_limit IS NOT NULL THEN
            -- Get the current balance of the account
            v_current_balance := utils.get_account_balance(
                (SELECT ledger_id FROM data.accounts WHERE id = p_account_id),
                p_account_id
            );

            -- Check if the new transaction would exceed the credit limit
            IF (v_current_balance + p_amount) > v_credit_limit THEN
                RAISE EXCEPTION 'Transaction would exceed credit limit'
                    USING ERRCODE = 'P0002',
                          DETAIL = json_build_object(
                              'credit_limit', v_credit_limit,
                              'current_balance', v_current_balance,
                              'proposed_amount', p_amount,
                              'exceeded_by', (v_current_balance + p_amount) - v_credit_limit
                          )::text;
            END IF;
        END IF;
    END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION utils.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL,
    p_payee_name text DEFAULT NULL,
    p_allow_overspending boolean DEFAULT false
) RETURNS text AS $$
DECLARE
    v_ledger_id bigint;
    v_account_id bigint;
    v_category_id bigint;
    v_payee_id bigint;
    v_transaction_uuid text;
    v_debit_account_id bigint;
    v_credit_account_id bigint;
    v_income_account_id bigint;
    v_unassigned_account_id bigint;
BEGIN
    -- Get ledger ID
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    -- Get account ID
    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_account_uuid
      AND ledger_id = v_ledger_id
      AND user_data = utils.get_user();

    IF v_account_id IS NULL THEN
        RAISE EXCEPTION 'Account not found';
    END IF;

    -- Get or create payee if name provided
    IF p_payee_name IS NOT NULL AND TRIM(p_payee_name) != '' THEN
        v_payee_id := utils.get_or_create_payee(TRIM(p_payee_name));
    END IF;

    -- Handle category
    IF p_category_uuid IS NOT NULL THEN
        SELECT id INTO v_category_id
        FROM data.accounts
        WHERE uuid = p_category_uuid
          AND ledger_id = v_ledger_id
          AND user_data = utils.get_user()
          AND type = 'equity';

        IF v_category_id IS NULL THEN
            RAISE EXCEPTION 'Category not found';
        END IF;
    END IF;

    -- Get Income and Unassigned accounts
    SELECT id INTO v_income_account_id
    FROM data.accounts
    WHERE ledger_id = v_ledger_id
      AND name = 'Income'
      AND type = 'equity';

    SELECT id INTO v_unassigned_account_id
    FROM data.accounts
    WHERE ledger_id = v_ledger_id
      AND name = 'Unassigned'
      AND type = 'equity';

    -- Determine debit and credit accounts based on transaction type
    IF p_type = 'inflow' THEN
        v_debit_account_id := v_account_id;
        v_credit_account_id := COALESCE(v_category_id, v_income_account_id);
    ELSIF p_type = 'outflow' THEN
        v_debit_account_id := COALESCE(v_category_id, v_unassigned_account_id);
        v_credit_account_id := v_account_id;
    ELSE
        RAISE EXCEPTION 'Invalid transaction type: %', p_type;
    END IF;

    -- Check for overspending on outflow transactions
    IF p_type = 'outflow' AND p_allow_overspending = false AND v_category_id IS NOT NULL THEN
        DECLARE
            v_category_balance bigint;
        BEGIN
            v_category_balance := utils.get_account_balance(v_ledger_id, v_category_id);
            IF (v_category_balance - p_amount) < 0 THEN
                RAISE EXCEPTION 'Insufficient funds in category'
                    USING ERRCODE = 'P0001',
                          DETAIL = json_build_object(
                              'overspent_amount', p_amount - v_category_balance,
                              'category_name', (SELECT name FROM data.accounts WHERE id = v_category_id)
                          )::text;
            END IF;
        END;
    END IF;

    -- Check for credit limit violation on outflow from liability account
    IF p_type = 'outflow' THEN
        PERFORM utils.check_credit_limit_violation(v_account_id, p_amount);
    END IF;

    -- Insert transaction with payee
    INSERT INTO data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        payee_id,
        user_data
    ) VALUES (
        v_ledger_id,
        p_date,
        p_description,
        p_amount,
        v_debit_account_id,
        v_credit_account_id,
        v_payee_id,
        utils.get_user()
    ) RETURNING uuid INTO v_transaction_uuid;

    RETURN v_transaction_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS utils.check_credit_limit_violation(bigint, bigint);

CREATE OR REPLACE FUNCTION utils.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL,
    p_payee_name text DEFAULT NULL,
    p_allow_overspending boolean DEFAULT false
) RETURNS text AS $$
DECLARE
    v_ledger_id bigint;
    v_account_id bigint;
    v_category_id bigint;
    v_payee_id bigint;
    v_transaction_uuid text;
    v_debit_account_id bigint;
    v_credit_account_id bigint;
    v_income_account_id bigint;
    v_unassigned_account_id bigint;
BEGIN
    -- Get ledger ID
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    -- Get account ID
    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_account_uuid
      AND ledger_id = v_ledger_id
      AND user_data = utils.get_user();

    IF v_account_id IS NULL THEN
        RAISE EXCEPTION 'Account not found';
    END IF;

    -- Get or create payee if name provided
    IF p_payee_name IS NOT NULL AND TRIM(p_payee_name) != '' THEN
        v_payee_id := utils.get_or_create_payee(TRIM(p_payee_name));
    END IF;

    -- Handle category
    IF p_category_uuid IS NOT NULL THEN
        SELECT id INTO v_category_id
        FROM data.accounts
        WHERE uuid = p_category_uuid
          AND ledger_id = v_ledger_id
          AND user_data = utils.get_user()
          AND type = 'equity';

        IF v_category_id IS NULL THEN
            RAISE EXCEPTION 'Category not found';
        END IF;
    END IF;

    -- Get Income and Unassigned accounts
    SELECT id INTO v_income_account_id
    FROM data.accounts
    WHERE ledger_id = v_ledger_id
      AND name = 'Income'
      AND type = 'equity';

    SELECT id INTO v_unassigned_account_id
    FROM data.accounts
    WHERE ledger_id = v_ledger_id
      AND name = 'Unassigned'
      AND type = 'equity';

    -- Determine debit and credit accounts based on transaction type
    IF p_type = 'inflow' THEN
        v_debit_account_id := v_account_id;
        v_credit_account_id := COALESCE(v_category_id, v_income_account_id);
    ELSIF p_type = 'outflow' THEN
        v_debit_account_id := COALESCE(v_category_id, v_unassigned_account_id);
        v_credit_account_id := v_account_id;
    ELSE
        RAISE EXCEPTION 'Invalid transaction type: %', p_type;
    END IF;

    -- Check for overspending on outflow transactions
    IF p_type = 'outflow' AND p_allow_overspending = false AND v_category_id IS NOT NULL THEN
        DECLARE
            v_category_balance bigint;
        BEGIN
            v_category_balance := utils.get_account_balance(v_ledger_id, v_category_id);
            IF (v_category_balance - p_amount) < 0 THEN
                RAISE EXCEPTION 'Insufficient funds in category'
                    USING ERRCODE = 'P0001',
                          DETAIL = json_build_object(
                              'overspent_amount', p_amount - v_category_balance,
                              'category_name', (SELECT name FROM data.accounts WHERE id = v_category_id)
                          )::text;
            END IF;
        END;
    END IF;

    -- Insert transaction with payee
    INSERT INTO data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        payee_id,
        user_data
    ) VALUES (
        v_ledger_id,
        p_date,
        p_description,
        p_amount,
        v_debit_account_id,
        v_credit_account_id,
        v_payee_id,
        utils.get_user()
    ) RETURNING uuid INTO v_transaction_uuid;

    RETURN v_transaction_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- +goose StatementEnd
