-- +goose Up
-- When an obligation's amount is updated, sync all future scheduled
-- (not yet paid/partial) obligation_payments to reflect the new amount.
--
-- Immediate update  → updates all future scheduled payments to new amount.
-- Future-date update → updates scheduled payments on/after the effective date.
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.update_obligation(
    p_obligation_uuid                text,
    p_name                           text     DEFAULT NULL,
    p_description                    text     DEFAULT NULL,
    p_payee_name                     text     DEFAULT NULL,
    p_fixed_amount                   numeric  DEFAULT NULL,
    p_estimated_amount               numeric  DEFAULT NULL,
    p_reminder_days_before           integer  DEFAULT NULL,
    p_grace_period_days              integer  DEFAULT NULL,
    p_is_active                      boolean  DEFAULT NULL,
    p_is_paused                      boolean  DEFAULT NULL,
    p_pause_until                    date     DEFAULT NULL,
    p_notes                          text     DEFAULT NULL,
    -- Future-amount scheduling
    p_future_fixed_amount            numeric  DEFAULT NULL,
    p_future_estimated_amount        numeric  DEFAULT NULL,
    p_future_amount_effective_date   date     DEFAULT NULL,
    p_clear_future_amount            boolean  DEFAULT false
)
RETURNS SETOF api.obligations
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_obligation_id bigint;
    v_payee_id      bigint;
    v_user_data     text;
    v_new_amount    numeric;   -- resolved new amount for immediate payment sync
    v_future_amount numeric;   -- resolved new amount for future payment sync
begin
    v_user_data := utils.get_user();

    select id into v_obligation_id
    from data.obligations
    where uuid = p_obligation_uuid and user_data = v_user_data;

    if v_obligation_id is null then
        raise exception 'Obligation not found or access denied';
    end if;

    if p_payee_name is not null then
        select payee_id into v_payee_id from data.obligations where id = v_obligation_id;
        v_payee_id := utils.get_or_create_payee(p_payee_name, null, v_user_data);
    end if;

    update data.obligations set
        name                         = coalesce(p_name,                 name),
        description                  = coalesce(p_description,          description),
        payee_name                   = coalesce(p_payee_name,           payee_name),
        payee_id                     = coalesce(v_payee_id,             payee_id),
        fixed_amount                 = coalesce(p_fixed_amount,         fixed_amount),
        estimated_amount             = coalesce(p_estimated_amount,     estimated_amount),
        reminder_days_before         = coalesce(p_reminder_days_before, reminder_days_before),
        grace_period_days            = coalesce(p_grace_period_days,    grace_period_days),
        is_active                    = coalesce(p_is_active,            is_active),
        is_paused                    = coalesce(p_is_paused,            is_paused),
        pause_until                  = coalesce(p_pause_until,          pause_until),
        notes                        = coalesce(p_notes,                notes),
        future_fixed_amount          = CASE
                                         WHEN p_clear_future_amount THEN NULL
                                         ELSE coalesce(p_future_fixed_amount,          future_fixed_amount)
                                       END,
        future_estimated_amount      = CASE
                                         WHEN p_clear_future_amount THEN NULL
                                         ELSE coalesce(p_future_estimated_amount,      future_estimated_amount)
                                       END,
        future_amount_effective_date = CASE
                                         WHEN p_clear_future_amount THEN NULL
                                         ELSE coalesce(p_future_amount_effective_date, future_amount_effective_date)
                                       END,
        updated_at = now()
    where id = v_obligation_id;

    -- ----------------------------------------------------------------
    -- Sync obligation_payments.scheduled_amount for unpaid future rows
    -- ----------------------------------------------------------------

    -- Case 1: immediate amount change — update all future scheduled payments
    if (p_fixed_amount is not null or p_estimated_amount is not null) and p_clear_future_amount = true then
        v_new_amount := coalesce(p_fixed_amount, p_estimated_amount);

        update data.obligation_payments
        set    scheduled_amount = v_new_amount,
               updated_at       = now()
        where  obligation_id = v_obligation_id
          and  status        = 'scheduled'
          and  due_date      >= CURRENT_DATE;
    end if;

    -- Case 2: future-dated amount change — update scheduled payments from effective date onward
    if p_future_amount_effective_date is not null and p_clear_future_amount = false then
        v_future_amount := coalesce(p_future_fixed_amount, p_future_estimated_amount);

        if v_future_amount is not null then
            update data.obligation_payments
            set    scheduled_amount = v_future_amount,
                   updated_at       = now()
            where  obligation_id = v_obligation_id
              and  status        = 'scheduled'
              and  due_date      >= p_future_amount_effective_date;
        end if;
    end if;

    return query select * from api.obligations where uuid = p_obligation_uuid;
end;
$$;
GRANT EXECUTE ON FUNCTION api.update_obligation TO pgbudget_user;
-- +goose StatementEnd

-- +goose Down
-- Restore previous version (without payment sync) — identical to what
-- migration 20260217000001 created.
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.update_obligation(
    p_obligation_uuid                text,
    p_name                           text     DEFAULT NULL,
    p_description                    text     DEFAULT NULL,
    p_payee_name                     text     DEFAULT NULL,
    p_fixed_amount                   numeric  DEFAULT NULL,
    p_estimated_amount               numeric  DEFAULT NULL,
    p_reminder_days_before           integer  DEFAULT NULL,
    p_grace_period_days              integer  DEFAULT NULL,
    p_is_active                      boolean  DEFAULT NULL,
    p_is_paused                      boolean  DEFAULT NULL,
    p_pause_until                    date     DEFAULT NULL,
    p_notes                          text     DEFAULT NULL,
    p_future_fixed_amount            numeric  DEFAULT NULL,
    p_future_estimated_amount        numeric  DEFAULT NULL,
    p_future_amount_effective_date   date     DEFAULT NULL,
    p_clear_future_amount            boolean  DEFAULT false
)
RETURNS SETOF api.obligations
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_obligation_id bigint;
    v_payee_id      bigint;
    v_user_data     text;
begin
    v_user_data := utils.get_user();
    select id into v_obligation_id from data.obligations where uuid = p_obligation_uuid and user_data = v_user_data;
    if v_obligation_id is null then raise exception 'Obligation not found or access denied'; end if;
    if p_payee_name is not null then
        select payee_id into v_payee_id from data.obligations where id = v_obligation_id;
        v_payee_id := utils.get_or_create_payee(p_payee_name, null, v_user_data);
    end if;
    update data.obligations set
        name = coalesce(p_name, name), description = coalesce(p_description, description),
        payee_name = coalesce(p_payee_name, payee_name), payee_id = coalesce(v_payee_id, payee_id),
        fixed_amount = coalesce(p_fixed_amount, fixed_amount), estimated_amount = coalesce(p_estimated_amount, estimated_amount),
        reminder_days_before = coalesce(p_reminder_days_before, reminder_days_before), grace_period_days = coalesce(p_grace_period_days, grace_period_days),
        is_active = coalesce(p_is_active, is_active), is_paused = coalesce(p_is_paused, is_paused), pause_until = coalesce(p_pause_until, pause_until),
        notes = coalesce(p_notes, notes),
        future_fixed_amount = CASE WHEN p_clear_future_amount THEN NULL ELSE coalesce(p_future_fixed_amount, future_fixed_amount) END,
        future_estimated_amount = CASE WHEN p_clear_future_amount THEN NULL ELSE coalesce(p_future_estimated_amount, future_estimated_amount) END,
        future_amount_effective_date = CASE WHEN p_clear_future_amount THEN NULL ELSE coalesce(p_future_amount_effective_date, future_amount_effective_date) END,
        updated_at = now()
    where id = v_obligation_id;
    return query select * from api.obligations where uuid = p_obligation_uuid;
end;
$$;
GRANT EXECUTE ON FUNCTION api.update_obligation TO pgbudget_user;
-- +goose StatementEnd
