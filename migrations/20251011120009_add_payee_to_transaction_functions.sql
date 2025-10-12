-- +goose Up
-- +goose StatementBegin
-- Update transaction functions to support payees

-- Update utils.add_transaction to accept payee name
CREATE OR REPLACE FUNCTION utils.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL,
    p_payee_name text DEFAULT NULL
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

-- Update api.add_transaction to accept payee name
CREATE OR REPLACE FUNCTION api.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL,
    p_payee_name text DEFAULT NULL
) RETURNS text AS $$
BEGIN
    -- Validate transaction type
    IF p_type NOT IN ('inflow', 'outflow') THEN
        RAISE EXCEPTION 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    END IF;

    -- Call the utils function
    RETURN utils.add_transaction(
        p_ledger_uuid,
        p_date,
        p_description,
        p_type,
        p_amount,
        p_account_uuid,
        p_category_uuid,
        p_payee_name
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Revert to original functions without payee support
CREATE OR REPLACE FUNCTION utils.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL
) RETURNS text AS $$
DECLARE
    v_ledger_id bigint;
    v_account_id bigint;
    v_category_id bigint;
    v_transaction_uuid text;
    v_debit_account_id bigint;
    v_credit_account_id bigint;
    v_income_account_id bigint;
    v_unassigned_account_id bigint;
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
      AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    SELECT id INTO v_account_id
    FROM data.accounts
    WHERE uuid = p_account_uuid
      AND ledger_id = v_ledger_id
      AND user_data = utils.get_user();

    IF v_account_id IS NULL THEN
        RAISE EXCEPTION 'Account not found';
    END IF;

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

    IF p_type = 'inflow' THEN
        v_debit_account_id := v_account_id;
        v_credit_account_id := COALESCE(v_category_id, v_income_account_id);
    ELSIF p_type = 'outflow' THEN
        v_debit_account_id := COALESCE(v_category_id, v_unassigned_account_id);
        v_credit_account_id := v_account_id;
    ELSE
        RAISE EXCEPTION 'Invalid transaction type: %', p_type;
    END IF;

    INSERT INTO data.transactions (
        ledger_id,
        date,
        description,
        amount,
        debit_account_id,
        credit_account_id,
        user_data
    ) VALUES (
        v_ledger_id,
        p_date,
        p_description,
        p_amount,
        v_debit_account_id,
        v_credit_account_id,
        utils.get_user()
    ) RETURNING uuid INTO v_transaction_uuid;

    RETURN v_transaction_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

CREATE OR REPLACE FUNCTION api.add_transaction(
    p_ledger_uuid text,
    p_date date,
    p_description text,
    p_type text,
    p_amount bigint,
    p_account_uuid text,
    p_category_uuid text DEFAULT NULL
) RETURNS text AS $$
BEGIN
    IF p_type NOT IN ('inflow', 'outflow') THEN
        RAISE EXCEPTION 'Invalid transaction type: %. Must be "inflow" or "outflow"', p_type;
    END IF;

    RETURN utils.add_transaction(
        p_ledger_uuid,
        p_date,
        p_description,
        p_type,
        p_amount,
        p_account_uuid,
        p_category_uuid
    );
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
-- +goose StatementEnd
