-- +goose Up
-- Require at least some name similarity for auto-matching projected events.
-- Previously, amount + date alone (80 pts) could trigger a match even when
-- the event name and transaction description were completely unrelated.
-- Now a match is only attempted if trigram similarity > 0.1 or the event name
-- appears literally in the transaction description.

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.auto_match_projected_event(p_transaction_id bigint)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
AS $function$
DECLARE
    v_user_data      text;
    v_ledger_id      bigint;
    v_tx_date        date;
    v_tx_amount      bigint;
    v_tx_description text;
    v_tx_metadata    jsonb;
    v_debit_id       bigint;
    v_credit_id      bigint;
    v_direction      text;
    v_best_id        bigint;
    v_best_uuid      text;
    v_best_name      text;
    v_best_frequency text;
    v_score          int;
BEGIN
    BEGIN  -- inner block: catch all errors, never fail the INSERT

        v_user_data := utils.get_user();
        IF v_user_data IS NULL OR v_user_data = '' THEN RETURN; END IF;

        -- Fetch transaction basics
        SELECT t.date, t.amount, t.description, t.ledger_id, t.metadata,
               t.debit_account_id, t.credit_account_id
        INTO v_tx_date, v_tx_amount, v_tx_description, v_ledger_id, v_tx_metadata,
             v_debit_id, v_credit_id
        FROM data.transactions t
        WHERE t.id = p_transaction_id
          AND t.user_data = v_user_data
          AND t.deleted_at IS NULL;

        IF NOT FOUND THEN RETURN; END IF;

        -- Skip if metadata flags say so
        IF (v_tx_metadata->>'skip_auto_match') = 'true'
           OR (v_tx_metadata->>'is_cc_budget_move') = 'true'
           OR (v_tx_metadata->>'is_cc_payment_budget_reduction') = 'true'
        THEN RETURN; END IF;

        -- Skip reversal transactions (bookkeeping entries created by delete/correction)
        IF EXISTS (
            SELECT 1 FROM data.transaction_log tl
            WHERE tl.reversal_transaction_id = p_transaction_id
        ) THEN RETURN; END IF;

        -- Derive direction from account types
        v_direction := utils.derive_transaction_direction(v_debit_id, v_credit_id);
        IF v_direction = 'unknown' THEN RETURN; END IF;

        -- Score all candidate projected events and pick the best match
        SELECT e.id, e.uuid, e.name, e.frequency, scored.total
        INTO v_best_id, v_best_uuid, v_best_name, v_best_frequency, v_score
        FROM data.projected_events e
        -- Pre-compute derived values once
        CROSS JOIN LATERAL (
            SELECT
                ABS(
                    (EXTRACT(YEAR FROM e.event_date)::int * 12 + EXTRACT(MONTH FROM e.event_date)::int) -
                    (EXTRACT(YEAR FROM v_tx_date)::int  * 12 + EXTRACT(MONTH FROM v_tx_date)::int)
                ) AS month_diff,
                ABS(e.amount - v_tx_amount) AS amt_diff,
                similarity(e.name, COALESCE(v_tx_description, '')) AS name_sim
        ) computed
        -- Compute total score
        CROSS JOIN LATERAL (
            SELECT
                -- Amount score (disqualify if diff > 50%)
                CASE
                    WHEN computed.amt_diff <= 100 THEN 50
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.05 THEN 40
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.10 THEN 30
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.20 THEN 20
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.50 THEN 10
                    ELSE -999
                END
                -- Date score (disqualify if > 2 months away)
                + CASE
                    WHEN computed.month_diff = 0 THEN 30
                    WHEN computed.month_diff = 1 THEN 15
                    WHEN computed.month_diff = 2 THEN 5
                    ELSE -999
                END
                -- Name similarity score
                + CASE
                    WHEN computed.name_sim > 0.4 THEN 20
                    WHEN computed.name_sim > 0.2 THEN 10
                    ELSE 0
                END
                -- Substring bonus: event name appears literally in transaction description
                + CASE
                    WHEN POSITION(lower(e.name) IN lower(COALESCE(v_tx_description, ''))) > 0 THEN 15
                    ELSE 0
                END
            AS total
        ) scored
        WHERE e.ledger_id = v_ledger_id
          AND e.user_data = v_user_data
          AND e.direction = v_direction
          AND e.is_realized = false
          AND computed.month_diff <= 2
          -- Require at least some name connection — prevents amount-only false matches
          AND (
              computed.name_sim > 0.1
              OR POSITION(lower(e.name) IN lower(COALESCE(v_tx_description, ''))) > 0
          )
          -- For recurring events, skip if this month's occurrence is already realized
          AND (
              e.frequency = 'one_time'
              OR NOT EXISTS (
                  SELECT 1 FROM data.projected_event_occurrences o
                  WHERE o.projected_event_id = e.id
                    AND o.scheduled_month    = date_trunc('month', v_tx_date)::date
                    AND o.is_realized        = true
                    AND o.user_data          = v_user_data
              )
          )
          AND scored.total >= 60
        ORDER BY scored.total DESC, computed.amt_diff
        LIMIT 1;

        IF NOT FOUND THEN RETURN; END IF;

        -- Apply the match
        IF v_best_frequency = 'one_time' THEN
            UPDATE data.projected_events
            SET is_realized         = true,
                linked_transaction_id = p_transaction_id
            WHERE id        = v_best_id
              AND user_data = v_user_data;
        ELSE
            -- Realize the occurrence for the transaction's month
            INSERT INTO data.projected_event_occurrences (
                user_data, projected_event_id, scheduled_month,
                is_realized, realized_date, transaction_id
            ) VALUES (
                v_user_data, v_best_id,
                date_trunc('month', v_tx_date)::date,
                true, v_tx_date, p_transaction_id
            )
            ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
            SET is_realized    = true,
                transaction_id = p_transaction_id,
                realized_date  = EXCLUDED.realized_date;
        END IF;

        -- Store match info in transaction metadata for webhook /undo and display
        UPDATE data.transactions
        SET metadata = COALESCE(metadata, '{}'::jsonb) ||
                       jsonb_build_object(
                           'matched_event_uuid', v_best_uuid,
                           'matched_event_name', v_best_name
                       )
        WHERE id        = p_transaction_id
          AND user_data = v_user_data;

    EXCEPTION WHEN OTHERS THEN
        NULL;  -- Never fail the triggering INSERT
    END;
END;
$function$;
-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.auto_match_projected_event(p_transaction_id bigint)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
AS $function$
DECLARE
    v_user_data      text;
    v_ledger_id      bigint;
    v_tx_date        date;
    v_tx_amount      bigint;
    v_tx_description text;
    v_tx_metadata    jsonb;
    v_debit_id       bigint;
    v_credit_id      bigint;
    v_direction      text;
    v_best_id        bigint;
    v_best_uuid      text;
    v_best_name      text;
    v_best_frequency text;
    v_score          int;
BEGIN
    BEGIN
        v_user_data := utils.get_user();
        IF v_user_data IS NULL OR v_user_data = '' THEN RETURN; END IF;

        SELECT t.date, t.amount, t.description, t.ledger_id, t.metadata,
               t.debit_account_id, t.credit_account_id
        INTO v_tx_date, v_tx_amount, v_tx_description, v_ledger_id, v_tx_metadata,
             v_debit_id, v_credit_id
        FROM data.transactions t
        WHERE t.id = p_transaction_id
          AND t.user_data = v_user_data
          AND t.deleted_at IS NULL;

        IF NOT FOUND THEN RETURN; END IF;

        IF (v_tx_metadata->>'skip_auto_match') = 'true'
           OR (v_tx_metadata->>'is_cc_budget_move') = 'true'
           OR (v_tx_metadata->>'is_cc_payment_budget_reduction') = 'true'
        THEN RETURN; END IF;

        IF EXISTS (
            SELECT 1 FROM data.transaction_log tl
            WHERE tl.reversal_transaction_id = p_transaction_id
        ) THEN RETURN; END IF;

        v_direction := utils.derive_transaction_direction(v_debit_id, v_credit_id);
        IF v_direction = 'unknown' THEN RETURN; END IF;

        SELECT e.id, e.uuid, e.name, e.frequency, scored.total
        INTO v_best_id, v_best_uuid, v_best_name, v_best_frequency, v_score
        FROM data.projected_events e
        CROSS JOIN LATERAL (
            SELECT
                ABS(
                    (EXTRACT(YEAR FROM e.event_date)::int * 12 + EXTRACT(MONTH FROM e.event_date)::int) -
                    (EXTRACT(YEAR FROM v_tx_date)::int  * 12 + EXTRACT(MONTH FROM v_tx_date)::int)
                ) AS month_diff,
                ABS(e.amount - v_tx_amount) AS amt_diff,
                similarity(e.name, COALESCE(v_tx_description, '')) AS name_sim
        ) computed
        CROSS JOIN LATERAL (
            SELECT
                CASE
                    WHEN computed.amt_diff <= 100 THEN 50
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.05 THEN 40
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.10 THEN 30
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.20 THEN 20
                    WHEN e.amount > 0 AND computed.amt_diff::float / e.amount::float <= 0.50 THEN 10
                    ELSE -999
                END
                + CASE
                    WHEN computed.month_diff = 0 THEN 30
                    WHEN computed.month_diff = 1 THEN 15
                    WHEN computed.month_diff = 2 THEN 5
                    ELSE -999
                END
                + CASE
                    WHEN computed.name_sim > 0.4 THEN 20
                    WHEN computed.name_sim > 0.2 THEN 10
                    ELSE 0
                END
                + CASE
                    WHEN POSITION(lower(e.name) IN lower(COALESCE(v_tx_description, ''))) > 0 THEN 15
                    ELSE 0
                END
            AS total
        ) scored
        WHERE e.ledger_id = v_ledger_id
          AND e.user_data = v_user_data
          AND e.direction = v_direction
          AND e.is_realized = false
          AND computed.month_diff <= 2
          AND (
              e.frequency = 'one_time'
              OR NOT EXISTS (
                  SELECT 1 FROM data.projected_event_occurrences o
                  WHERE o.projected_event_id = e.id
                    AND o.scheduled_month    = date_trunc('month', v_tx_date)::date
                    AND o.is_realized        = true
                    AND o.user_data          = v_user_data
              )
          )
          AND scored.total >= 60
        ORDER BY scored.total DESC, computed.amt_diff
        LIMIT 1;

        IF NOT FOUND THEN RETURN; END IF;

        IF v_best_frequency = 'one_time' THEN
            UPDATE data.projected_events
            SET is_realized         = true,
                linked_transaction_id = p_transaction_id
            WHERE id        = v_best_id
              AND user_data = v_user_data;
        ELSE
            INSERT INTO data.projected_event_occurrences (
                user_data, projected_event_id, scheduled_month,
                is_realized, realized_date, transaction_id
            ) VALUES (
                v_user_data, v_best_id,
                date_trunc('month', v_tx_date)::date,
                true, v_tx_date, p_transaction_id
            )
            ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
            SET is_realized    = true,
                transaction_id = p_transaction_id,
                realized_date  = EXCLUDED.realized_date;
        END IF;

        UPDATE data.transactions
        SET metadata = COALESCE(metadata, '{}'::jsonb) ||
                       jsonb_build_object(
                           'matched_event_uuid', v_best_uuid,
                           'matched_event_name', v_best_name
                       )
        WHERE id        = p_transaction_id
          AND user_data = v_user_data;

    EXCEPTION WHEN OTHERS THEN
        NULL;
    END;
END;
$function$;
-- +goose StatementEnd
