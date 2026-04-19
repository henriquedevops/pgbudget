-- +goose Up

-- 1. Add transaction_type column to data.transactions
ALTER TABLE data.transactions
    ADD COLUMN transaction_type text NOT NULL DEFAULT 'standard';

ALTER TABLE data.transactions
    ADD CONSTRAINT transactions_transaction_type_check
    CHECK (transaction_type IN ('standard', 'transfer'));

-- 2. api.add_transfer: asset/liability-to-asset/liability transfer
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.add_transfer(
    p_ledger_uuid      text,
    p_date             date,
    p_description      text,
    p_amount           bigint,
    p_source_uuid      text,
    p_destination_uuid text
) RETURNS text
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_ledger_id      bigint;
    v_source_id      bigint;
    v_destination_id bigint;
    v_txn_uuid       text;
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    SELECT id INTO v_source_id
    FROM data.accounts
    WHERE uuid = p_source_uuid
      AND ledger_id = v_ledger_id
      AND user_data = utils.get_user()
      AND type IN ('asset', 'liability')
      AND deleted_at IS NULL;

    IF v_source_id IS NULL THEN
        RAISE EXCEPTION 'Source account not found or not an asset/liability account';
    END IF;

    SELECT id INTO v_destination_id
    FROM data.accounts
    WHERE uuid = p_destination_uuid
      AND ledger_id = v_ledger_id
      AND user_data = utils.get_user()
      AND type IN ('asset', 'liability')
      AND deleted_at IS NULL;

    IF v_destination_id IS NULL THEN
        RAISE EXCEPTION 'Destination account not found or not an asset/liability account';
    END IF;

    IF v_source_id = v_destination_id THEN
        RAISE EXCEPTION 'Source and destination accounts must be different';
    END IF;

    -- Double-entry: debit destination (money in), credit source (money out)
    INSERT INTO data.transactions (
        ledger_id, date, description, amount,
        debit_account_id, credit_account_id,
        transaction_type, user_data
    ) VALUES (
        v_ledger_id, p_date, p_description, p_amount,
        v_destination_id, v_source_id,
        'transfer', utils.get_user()
    ) RETURNING uuid INTO v_txn_uuid;

    RETURN v_txn_uuid;
END;
$$;
-- +goose StatementEnd

GRANT EXECUTE ON FUNCTION api.add_transfer(text, date, text, bigint, text, text) TO pgbudget_user;

-- +goose Down

REVOKE EXECUTE ON FUNCTION api.add_transfer(text, date, text, bigint, text, text) FROM pgbudget_user;
DROP FUNCTION IF EXISTS api.add_transfer(text, date, text, bigint, text, text);
ALTER TABLE data.transactions DROP CONSTRAINT IF EXISTS transactions_transaction_type_check;
ALTER TABLE data.transactions DROP COLUMN IF EXISTS transaction_type;
