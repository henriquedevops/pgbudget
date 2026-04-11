-- +goose Up
-- +goose StatementBegin

-- Add interest_amount_cents to projected_events (one-time event realization)
ALTER TABLE data.projected_events
    ADD COLUMN IF NOT EXISTS interest_amount_cents bigint;

-- Add interest_amount_cents to projected_event_occurrences (recurring realization)
ALTER TABLE data.projected_event_occurrences
    ADD COLUMN IF NOT EXISTS interest_amount_cents bigint;

-- Update api.update_projected_event to accept an optional interest amount
CREATE OR REPLACE FUNCTION api.update_projected_event(
    p_event_uuid              text,
    p_name                    text    DEFAULT NULL,
    p_description             text    DEFAULT NULL,
    p_event_type              text    DEFAULT NULL,
    p_direction               text    DEFAULT NULL,
    p_amount                  bigint  DEFAULT NULL,
    p_currency                text    DEFAULT NULL,
    p_event_date              date    DEFAULT NULL,
    p_default_category_uuid   text    DEFAULT NULL,
    p_is_confirmed            boolean DEFAULT NULL,
    p_is_realized             boolean DEFAULT NULL,
    p_linked_transaction_uuid text    DEFAULT NULL,
    p_notes                   text    DEFAULT NULL,
    p_frequency               text    DEFAULT NULL,
    p_recurrence_end_date     date    DEFAULT NULL,
    p_interest_amount_cents   bigint  DEFAULT NULL
)
RETURNS SETOF api.projected_events
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
declare
    v_event_id       bigint;
    v_category_id    bigint;
    v_transaction_id bigint;
    v_user_data      text;
begin
    v_user_data := utils.get_user();

    select id into v_event_id
    from data.projected_events
    where uuid = p_event_uuid and user_data = v_user_data;

    if v_event_id is null then
        raise exception 'Projected event not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    if p_linked_transaction_uuid is not null then
        select id into v_transaction_id
        from data.transactions
        where uuid = p_linked_transaction_uuid and user_data = v_user_data;
    end if;

    update data.projected_events
    set
        name                  = coalesce(p_name, name),
        description           = coalesce(p_description, description),
        event_type            = coalesce(p_event_type, event_type),
        direction             = coalesce(p_direction, direction),
        amount                = coalesce(p_amount, amount),
        currency              = coalesce(p_currency, currency),
        event_date            = coalesce(p_event_date, event_date),
        default_category_id   = coalesce(v_category_id, default_category_id),
        is_confirmed          = coalesce(p_is_confirmed, is_confirmed),
        is_realized           = coalesce(p_is_realized, is_realized),
        linked_transaction_id = coalesce(v_transaction_id, linked_transaction_id),
        notes                 = coalesce(p_notes, notes),
        frequency             = coalesce(p_frequency, frequency),
        -- Clear recurrence_end_date when switching to one_time; otherwise keep/update
        recurrence_end_date   = case
            when p_frequency = 'one_time' then null
            else coalesce(p_recurrence_end_date, recurrence_end_date)
        end,
        interest_amount_cents = coalesce(p_interest_amount_cents, interest_amount_cents),
        updated_at            = now()
    where id = v_event_id;

    return query
    select * from api.projected_events where uuid = p_event_uuid;
end;
$$;

-- Update api.realize_projected_event_occurrence to accept an optional interest amount
CREATE OR REPLACE FUNCTION api.realize_projected_event_occurrence(
    p_event_uuid            text,
    p_scheduled_month       date,
    p_realized_date         date,
    p_realized_amount       bigint  DEFAULT NULL,
    p_notes                 text    DEFAULT NULL,
    p_transaction_uuid      text    DEFAULT NULL,
    p_interest_amount_cents bigint  DEFAULT NULL
)
RETURNS SETOF api.projected_event_occurrences
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
    v_occ_uuid  text;
    v_tx_id     bigint;
BEGIN
    v_user_data := utils.get_user();

    SELECT id INTO v_event_id
    FROM data.projected_events
    WHERE uuid = p_event_uuid AND user_data = v_user_data;

    IF v_event_id IS NULL THEN
        RAISE EXCEPTION 'Projected event not found';
    END IF;

    -- Optionally resolve transaction UUID to internal ID
    IF p_transaction_uuid IS NOT NULL THEN
        SELECT id INTO v_tx_id
        FROM data.transactions
        WHERE uuid = p_transaction_uuid AND user_data = v_user_data;
    END IF;

    INSERT INTO data.projected_event_occurrences (
        user_data, projected_event_id, scheduled_month,
        is_realized, realized_date, realized_amount, notes,
        transaction_id, interest_amount_cents
    ) VALUES (
        v_user_data, v_event_id, date_trunc('month', p_scheduled_month)::date,
        true, p_realized_date, p_realized_amount, p_notes,
        v_tx_id, p_interest_amount_cents
    )
    ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
    SET
        is_realized           = true,
        realized_date         = p_realized_date,
        realized_amount       = p_realized_amount,
        notes                 = COALESCE(EXCLUDED.notes,                 data.projected_event_occurrences.notes),
        transaction_id        = COALESCE(EXCLUDED.transaction_id,        data.projected_event_occurrences.transaction_id),
        interest_amount_cents = COALESCE(EXCLUDED.interest_amount_cents, data.projected_event_occurrences.interest_amount_cents),
        updated_at            = now()
    RETURNING uuid INTO v_occ_uuid;

    RETURN QUERY
    SELECT * FROM api.projected_event_occurrences WHERE uuid = v_occ_uuid;
END;
$$;

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

ALTER TABLE data.projected_events DROP COLUMN IF EXISTS interest_amount_cents;
ALTER TABLE data.projected_event_occurrences DROP COLUMN IF EXISTS interest_amount_cents;

-- Drop the new overload before restoring the original
DROP FUNCTION IF EXISTS api.update_projected_event(text,text,text,text,text,bigint,text,date,text,boolean,boolean,text,text,text,date,bigint);
DROP FUNCTION IF EXISTS api.realize_projected_event_occurrence(text,date,date,bigint,text,text,bigint);

-- Restore original function signatures (without p_interest_amount_cents)
CREATE OR REPLACE FUNCTION api.update_projected_event(
    p_event_uuid              text,
    p_name                    text    DEFAULT NULL,
    p_description             text    DEFAULT NULL,
    p_event_type              text    DEFAULT NULL,
    p_direction               text    DEFAULT NULL,
    p_amount                  bigint  DEFAULT NULL,
    p_currency                text    DEFAULT NULL,
    p_event_date              date    DEFAULT NULL,
    p_default_category_uuid   text    DEFAULT NULL,
    p_is_confirmed            boolean DEFAULT NULL,
    p_is_realized             boolean DEFAULT NULL,
    p_linked_transaction_uuid text    DEFAULT NULL,
    p_notes                   text    DEFAULT NULL,
    p_frequency               text    DEFAULT NULL,
    p_recurrence_end_date     date    DEFAULT NULL
)
RETURNS SETOF api.projected_events
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
declare
    v_event_id       bigint;
    v_category_id    bigint;
    v_transaction_id bigint;
    v_user_data      text;
begin
    v_user_data := utils.get_user();

    select id into v_event_id
    from data.projected_events
    where uuid = p_event_uuid and user_data = v_user_data;

    if v_event_id is null then
        raise exception 'Projected event not found';
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data;
    end if;

    if p_linked_transaction_uuid is not null then
        select id into v_transaction_id
        from data.transactions
        where uuid = p_linked_transaction_uuid and user_data = v_user_data;
    end if;

    update data.projected_events
    set
        name                  = coalesce(p_name, name),
        description           = coalesce(p_description, description),
        event_type            = coalesce(p_event_type, event_type),
        direction             = coalesce(p_direction, direction),
        amount                = coalesce(p_amount, amount),
        currency              = coalesce(p_currency, currency),
        event_date            = coalesce(p_event_date, event_date),
        default_category_id   = coalesce(v_category_id, default_category_id),
        is_confirmed          = coalesce(p_is_confirmed, is_confirmed),
        is_realized           = coalesce(p_is_realized, is_realized),
        linked_transaction_id = coalesce(v_transaction_id, linked_transaction_id),
        notes                 = coalesce(p_notes, notes),
        frequency             = coalesce(p_frequency, frequency),
        recurrence_end_date   = case
            when p_frequency = 'one_time' then null
            else coalesce(p_recurrence_end_date, recurrence_end_date)
        end,
        updated_at            = now()
    where id = v_event_id;

    return query
    select * from api.projected_events where uuid = p_event_uuid;
end;
$$;

CREATE OR REPLACE FUNCTION api.realize_projected_event_occurrence(
    p_event_uuid       text,
    p_scheduled_month  date,
    p_realized_date    date,
    p_realized_amount  bigint DEFAULT NULL,
    p_notes            text   DEFAULT NULL,
    p_transaction_uuid text   DEFAULT NULL
)
RETURNS SETOF api.projected_event_occurrences
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_event_id  bigint;
    v_user_data text;
    v_occ_uuid  text;
    v_tx_id     bigint;
BEGIN
    v_user_data := utils.get_user();

    SELECT id INTO v_event_id
    FROM data.projected_events
    WHERE uuid = p_event_uuid AND user_data = v_user_data;

    IF v_event_id IS NULL THEN
        RAISE EXCEPTION 'Projected event not found';
    END IF;

    IF p_transaction_uuid IS NOT NULL THEN
        SELECT id INTO v_tx_id
        FROM data.transactions
        WHERE uuid = p_transaction_uuid AND user_data = v_user_data;
    END IF;

    INSERT INTO data.projected_event_occurrences (
        user_data, projected_event_id, scheduled_month,
        is_realized, realized_date, realized_amount, notes, transaction_id
    ) VALUES (
        v_user_data, v_event_id, date_trunc('month', p_scheduled_month)::date,
        true, p_realized_date, p_realized_amount, p_notes, v_tx_id
    )
    ON CONFLICT (projected_event_id, scheduled_month, user_data) DO UPDATE
    SET
        is_realized     = true,
        realized_date   = p_realized_date,
        realized_amount = p_realized_amount,
        notes           = COALESCE(EXCLUDED.notes,          data.projected_event_occurrences.notes),
        transaction_id  = COALESCE(EXCLUDED.transaction_id, data.projected_event_occurrences.transaction_id),
        updated_at      = now()
    RETURNING uuid INTO v_occ_uuid;

    RETURN QUERY
    SELECT * FROM api.projected_event_occurrences WHERE uuid = v_occ_uuid;
END;
$$;

-- +goose StatementEnd
