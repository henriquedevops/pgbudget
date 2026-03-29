-- +goose Up
-- +goose StatementBegin

-- Fix: asset-to-asset transfers (e.g. "Salary transfer to checking") were
-- returning 'inflow' because only the debit side was checked.
-- They should return 'unknown' so the projection engine excludes them.
CREATE OR REPLACE FUNCTION utils.derive_transaction_direction(
    p_debit_id  bigint,
    p_credit_id bigint
) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER AS $$
DECLARE
    v_debit_type  text;
    v_credit_type text;
BEGIN
    SELECT type INTO v_debit_type  FROM data.accounts WHERE id = p_debit_id;
    SELECT type INTO v_credit_type FROM data.accounts WHERE id = p_credit_id;

    -- Asset-to-asset: internal transfer — exclude from projection
    IF v_debit_type = 'asset' AND v_credit_type = 'asset' THEN
        RETURN 'unknown';
    ELSIF v_debit_type = 'asset' THEN
        RETURN 'inflow';
    ELSIF v_credit_type = 'asset' AND v_debit_type <> 'liability' THEN
        RETURN 'outflow';  -- regular expense from checking
    ELSIF v_credit_type = 'liability' THEN
        RETURN 'outflow';  -- CC charge (accrual: expense when incurred)
    ELSE
        RETURN 'unknown';  -- CC bill payment (debit=liability, credit=asset) or other
    END IF;
END;
$$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

CREATE OR REPLACE FUNCTION utils.derive_transaction_direction(
    p_debit_id  bigint,
    p_credit_id bigint
) RETURNS text
LANGUAGE plpgsql STABLE SECURITY DEFINER AS $$
DECLARE
    v_debit_type  text;
    v_credit_type text;
BEGIN
    SELECT type INTO v_debit_type  FROM data.accounts WHERE id = p_debit_id;
    SELECT type INTO v_credit_type FROM data.accounts WHERE id = p_credit_id;

    IF v_debit_type = 'asset' THEN
        RETURN 'inflow';
    ELSIF v_credit_type = 'asset' AND v_debit_type <> 'liability' THEN
        RETURN 'outflow';
    ELSIF v_credit_type = 'liability' THEN
        RETURN 'outflow';
    ELSE
        RETURN 'unknown';
    END IF;
END;
$$;

-- +goose StatementEnd
