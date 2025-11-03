-- +goose Up
-- +goose StatementBegin

-- Create the missing function that get_group_subtotals() needs
CREATE OR REPLACE FUNCTION utils.get_budget_status_for_category(
    p_category_id bigint,
    p_ledger_id bigint,
    p_period text,
    p_user_data text
) RETURNS TABLE (
    budgeted bigint,
    activity bigint,
    balance bigint
) AS $func$
BEGIN
    -- If period is provided, filter by period (YYYYMM format)
    IF p_period IS NOT NULL THEN
        RETURN QUERY
        SELECT
            COALESCE(SUM(CASE WHEN t.debit_account_id = p_category_id THEN t.amount ELSE 0 END), 0)::bigint as budgeted,
            COALESCE(SUM(CASE WHEN t.credit_account_id = p_category_id THEN -t.amount ELSE 0 END), 0)::bigint as activity,
            (COALESCE(SUM(CASE WHEN t.debit_account_id = p_category_id THEN t.amount ELSE 0 END), 0) +
             COALESCE(SUM(CASE WHEN t.credit_account_id = p_category_id THEN -t.amount ELSE 0 END), 0))::bigint as balance
        FROM data.transactions t
        WHERE t.ledger_id = p_ledger_id
          AND t.user_data = p_user_data
          AND t.deleted_at IS NULL
          AND TO_CHAR(t.date, 'YYYYMM') = p_period
          AND (t.debit_account_id = p_category_id OR t.credit_account_id = p_category_id);
    ELSE
        -- No period filter - current month
        RETURN QUERY
        SELECT
            COALESCE(SUM(CASE WHEN t.debit_account_id = p_category_id THEN t.amount ELSE 0 END), 0)::bigint as budgeted,
            COALESCE(SUM(CASE WHEN t.credit_account_id = p_category_id THEN -t.amount ELSE 0 END), 0)::bigint as activity,
            (COALESCE(SUM(CASE WHEN t.debit_account_id = p_category_id THEN t.amount ELSE 0 END), 0) +
             COALESCE(SUM(CASE WHEN t.credit_account_id = p_category_id THEN -t.amount ELSE 0 END), 0))::bigint as balance
        FROM data.transactions t
        WHERE t.ledger_id = p_ledger_id
          AND t.user_data = p_user_data
          AND t.deleted_at IS NULL
          AND DATE_TRUNC('month', t.date) = DATE_TRUNC('month', CURRENT_DATE)
          AND (t.debit_account_id = p_category_id OR t.credit_account_id = p_category_id);
    END IF;
END;
$func$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

COMMENT ON FUNCTION utils.get_budget_status_for_category IS
'Get budget status (budgeted, activity, balance) for a specific category.
Used by utils.get_group_subtotals() to calculate group totals.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

DROP FUNCTION IF EXISTS utils.get_budget_status_for_category(bigint, bigint, text, text);

-- +goose StatementEnd
