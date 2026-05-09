-- +goose Up
-- +goose StatementBegin

-- Fix: corrected transactions were still counted in balances.
-- The transaction_log JOIN was filtering only mutation_type='deletion',
-- so when a transaction was corrected (mutation_type='correction') its
-- original remained visible and double-counted.
-- Removing the mutation_type filter excludes originals for both
-- deletions and corrections.
CREATE OR REPLACE FUNCTION utils.get_ledger_current_balances(p_ledger_uuid text)
 RETURNS TABLE(account_uuid text, account_name text, account_type text, current_balance bigint)
 LANGUAGE plpgsql
 SECURITY DEFINER
AS $function$
DECLARE
    v_ledger_id bigint;
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found: %', p_ledger_uuid;
    END IF;

    RETURN QUERY
    SELECT
        a.uuid::text,
        a.name,
        a.type,
        COALESCE((
            SELECT SUM(
                CASE
                    WHEN t.debit_account_id = a.id THEN
                        CASE WHEN a.internal_type = 'asset_like' THEN t.amount ELSE -t.amount END
                    ELSE -- credit side
                        CASE WHEN a.internal_type = 'asset_like' THEN -t.amount ELSE t.amount END
                END
            )::bigint
            FROM data.transactions t
            LEFT JOIN data.transaction_log tl
                ON t.id = tl.original_transaction_id
            WHERE (t.debit_account_id = a.id OR t.credit_account_id = a.id)
              AND t.ledger_id = v_ledger_id
              AND t.deleted_at IS NULL
              AND t.description NOT LIKE 'DELETED:%'
              AND t.description NOT LIKE 'REVERSAL:%'
              AND tl.id IS NULL
              AND t.user_data = utils.get_user()
        ), 0)
    FROM data.accounts a
    WHERE a.ledger_id = v_ledger_id
      AND a.user_data = utils.get_user()
      AND a.deleted_at IS NULL
    ORDER BY a.type, a.name;
END;
$function$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

CREATE OR REPLACE FUNCTION utils.get_ledger_current_balances(p_ledger_uuid text)
 RETURNS TABLE(account_uuid text, account_name text, account_type text, current_balance bigint)
 LANGUAGE plpgsql
 SECURITY DEFINER
AS $function$
DECLARE
    v_ledger_id bigint;
BEGIN
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found: %', p_ledger_uuid;
    END IF;

    RETURN QUERY
    SELECT
        a.uuid::text,
        a.name,
        a.type,
        COALESCE((
            SELECT SUM(
                CASE
                    WHEN t.debit_account_id = a.id THEN
                        CASE WHEN a.internal_type = 'asset_like' THEN t.amount ELSE -t.amount END
                    ELSE
                        CASE WHEN a.internal_type = 'asset_like' THEN -t.amount ELSE t.amount END
                END
            )::bigint
            FROM data.transactions t
            LEFT JOIN data.transaction_log tl
                ON t.id = tl.original_transaction_id AND tl.mutation_type = 'deletion'
            WHERE (t.debit_account_id = a.id OR t.credit_account_id = a.id)
              AND t.ledger_id = v_ledger_id
              AND t.deleted_at IS NULL
              AND t.description NOT LIKE 'DELETED:%'
              AND t.description NOT LIKE 'REVERSAL:%'
              AND tl.id IS NULL
              AND t.user_data = utils.get_user()
        ), 0)
    FROM data.accounts a
    WHERE a.ledger_id = v_ledger_id
      AND a.user_data = utils.get_user()
      AND a.deleted_at IS NULL
    ORDER BY a.type, a.name;
END;
$function$;

-- +goose StatementEnd
