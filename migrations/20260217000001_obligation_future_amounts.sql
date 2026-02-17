-- +goose Up
-- Add future amount scheduling to obligations.
-- Allows users to record a new amount with an effective date so the
-- cash-flow projection uses the old amount until that date.

ALTER TABLE data.obligations
    ADD COLUMN future_fixed_amount          numeric(15,2),
    ADD COLUMN future_estimated_amount      numeric(15,2),
    ADD COLUMN future_amount_effective_date date;

COMMENT ON COLUMN data.obligations.future_fixed_amount
    IS 'Scheduled new fixed amount, effective from future_amount_effective_date';
COMMENT ON COLUMN data.obligations.future_estimated_amount
    IS 'Scheduled new estimated amount, effective from future_amount_effective_date';
COMMENT ON COLUMN data.obligations.future_amount_effective_date
    IS 'When future_fixed/estimated_amount takes effect in cash-flow projections';

-- Recreate api.obligations view to expose new columns.
-- All functions returning SETOF api.obligations are dropped by CASCADE.
-- +goose StatementBegin
DROP VIEW IF EXISTS api.obligations CASCADE;

CREATE VIEW api.obligations AS
SELECT
    o.uuid,
    o.name,
    o.description,
    o.obligation_type,
    o.obligation_subtype,
    o.payee_name,
    p.uuid  AS payee_uuid,
    o.account_number,
    pa.uuid AS default_payment_account_uuid,
    pa.name AS default_payment_account_name,
    c.uuid  AS default_category_uuid,
    c.name  AS default_category_name,
    o.is_fixed_amount,
    o.fixed_amount,
    o.estimated_amount,
    o.amount_range_min,
    o.amount_range_max,
    o.currency,
    o.frequency,
    o.custom_frequency_days,
    o.due_day_of_month,
    o.due_day_of_week,
    o.due_months,
    o.start_date,
    o.end_date,
    o.reminder_enabled,
    o.reminder_days_before,
    o.grace_period_days,
    o.late_fee_amount,
    o.is_active,
    o.is_paused,
    o.pause_until,
    o.notes,
    o.created_at,
    o.updated_at,
    -- future amount scheduling
    o.future_fixed_amount,
    o.future_estimated_amount,
    o.future_amount_effective_date,
    l.uuid AS ledger_uuid,
    ( SELECT min(op.due_date)
        FROM data.obligation_payments op
       WHERE op.obligation_id = o.id
         AND op.status = 'scheduled'
         AND op.due_date >= CURRENT_DATE
    ) AS next_due_date,
    ( SELECT op.scheduled_amount
        FROM data.obligation_payments op
       WHERE op.obligation_id = o.id
         AND op.status = 'scheduled'
         AND op.due_date >= CURRENT_DATE
       ORDER BY op.due_date
       LIMIT 1
    ) AS next_payment_amount,
    ( SELECT count(*)
        FROM data.obligation_payments op
       WHERE op.obligation_id = o.id
         AND op.status = 'paid'
    ) AS total_payments_made
FROM data.obligations o
LEFT JOIN data.ledgers  l  ON l.id  = o.ledger_id
LEFT JOIN data.payees   p  ON p.id  = o.payee_id
LEFT JOIN data.accounts pa ON pa.id = o.default_payment_account_id
LEFT JOIN data.accounts c  ON c.id  = o.default_category_id
WHERE o.user_data = utils.get_user();

GRANT SELECT ON api.obligations TO pgbudget_user;
-- +goose StatementEnd

-- Recreate api.create_obligation
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.create_obligation(
    p_ledger_uuid               text,
    p_name                      text,
    p_payee_name                text,
    p_obligation_type           text,
    p_frequency                 text,
    p_is_fixed_amount           boolean,
    p_start_date                date,
    p_fixed_amount              numeric  DEFAULT NULL,
    p_estimated_amount          numeric  DEFAULT NULL,
    p_due_day_of_month          integer  DEFAULT NULL,
    p_due_day_of_week           integer  DEFAULT NULL,
    p_due_months                integer[] DEFAULT NULL,
    p_custom_frequency_days     integer  DEFAULT NULL,
    p_default_payment_account_uuid text  DEFAULT NULL,
    p_default_category_uuid     text     DEFAULT NULL,
    p_obligation_subtype        text     DEFAULT NULL,
    p_description               text     DEFAULT NULL,
    p_account_number            text     DEFAULT NULL,
    p_reminder_days_before      integer  DEFAULT 3,
    p_grace_period_days         integer  DEFAULT 0,
    p_notes                     text     DEFAULT NULL
)
RETURNS SETOF api.obligations
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_ledger_id            bigint;
    v_payment_account_id   bigint;
    v_category_id          bigint;
    v_payee_id             bigint;
    v_obligation_uuid      text;
    v_user_data            text;
begin
    v_user_data := utils.get_user();

    select id into v_ledger_id from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;
    if v_ledger_id is null then raise exception 'Ledger not found'; end if;

    if p_default_payment_account_uuid is not null then
        select id into v_payment_account_id from data.accounts
        where uuid = p_default_payment_account_uuid and user_data = v_user_data and deleted_at is null;
        if v_payment_account_id is null then raise exception 'Payment account not found'; end if;
    end if;

    if p_default_category_uuid is not null then
        select id into v_category_id from data.accounts
        where uuid = p_default_category_uuid and user_data = v_user_data and type = 'equity' and deleted_at is null;
        if v_category_id is null then raise exception 'Category not found'; end if;
    end if;

    v_payee_id := utils.get_or_create_payee(p_payee_name, v_category_id, v_user_data);

    if p_is_fixed_amount and p_fixed_amount is null then
        raise exception 'Fixed amount is required when is_fixed_amount is true';
    end if;
    if p_frequency in ('weekly', 'biweekly') and p_due_day_of_week is null then
        raise exception 'due_day_of_week is required for weekly/biweekly frequency';
    end if;
    if p_frequency in ('monthly', 'quarterly') and p_due_day_of_month is null then
        raise exception 'due_day_of_month is required for monthly/quarterly frequency';
    end if;
    if p_frequency in ('semiannual', 'annual') and (p_due_day_of_month is null or p_due_months is null) then
        raise exception 'due_day_of_month and due_months are required for semiannual/annual frequency';
    end if;
    if p_frequency = 'custom' and p_custom_frequency_days is null then
        raise exception 'custom_frequency_days is required for custom frequency';
    end if;

    insert into data.obligations (
        user_data, ledger_id, name, description, obligation_type, obligation_subtype,
        payee_name, payee_id, account_number, default_payment_account_id, default_category_id,
        is_fixed_amount, fixed_amount, estimated_amount, frequency, custom_frequency_days,
        due_day_of_month, due_day_of_week, due_months, start_date, reminder_days_before,
        grace_period_days, notes
    ) values (
        v_user_data, v_ledger_id, p_name, p_description, p_obligation_type, p_obligation_subtype,
        p_payee_name, v_payee_id, p_account_number, v_payment_account_id, v_category_id,
        p_is_fixed_amount, p_fixed_amount, p_estimated_amount, p_frequency, p_custom_frequency_days,
        p_due_day_of_month, p_due_day_of_week, p_due_months, p_start_date, p_reminder_days_before,
        p_grace_period_days, p_notes
    ) returning uuid into v_obligation_uuid;

    return query select * from api.obligations where uuid = v_obligation_uuid;
end;
$$;
GRANT EXECUTE ON FUNCTION api.create_obligation TO pgbudget_user;
-- +goose StatementEnd

-- Recreate api.get_obligation
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_obligation(p_obligation_uuid text)
RETURNS SETOF api.obligations
LANGUAGE plpgsql SECURITY DEFINER
AS $$
begin
    return query select * from api.obligations where uuid = p_obligation_uuid;
end;
$$;
GRANT EXECUTE ON FUNCTION api.get_obligation TO pgbudget_user;
-- +goose StatementEnd

-- Recreate api.get_obligations
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_obligations(p_ledger_uuid text)
RETURNS SETOF api.obligations
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_ledger_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();
    select id into v_ledger_id from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;
    if v_ledger_id is null then raise exception 'Ledger not found'; end if;
    return query select * from api.obligations where ledger_uuid = p_ledger_uuid order by name;
end;
$$;
GRANT EXECUTE ON FUNCTION api.get_obligations TO pgbudget_user;
-- +goose StatementEnd

-- Recreate api.update_obligation with future-amount scheduling params
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

    return query select * from api.obligations where uuid = p_obligation_uuid;
end;
$$;
GRANT EXECUTE ON FUNCTION api.update_obligation TO pgbudget_user;
-- +goose StatementEnd

-- Update projection engine: use future amounts in section 3b (unscheduled months)
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid  text,
    p_start_month  date    DEFAULT (date_trunc('month', CURRENT_DATE))::date,
    p_months_ahead integer DEFAULT 120
)
RETURNS TABLE(
    month        date,
    source_type  text,
    source_id    bigint,
    source_uuid  text,
    category     text,
    subcategory  text,
    description  text,
    amount       bigint
)
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
begin
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    return query

    -- 1. INCOME SOURCES: monthly (already in cents)
    select
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'monthly'

    union all

    -- 1b. INCOME SOURCES: annual/semiannual
    select
        gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    cross join lateral generate_series(
        greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true
      and i.frequency in ('annual', 'semiannual')
      and (i.occurrence_months is null or extract(month from gs.month)::integer = any(i.occurrence_months))

    union all

    -- 1c. INCOME SOURCES: one_time
    select
        date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type),
        i.name, i.amount
    from data.income_sources i
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true
      and i.frequency = 'one_time'
      and date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp)
      and date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)

    union all

    -- 2. PAYROLL DEDUCTIONS: monthly (already in cents, negate)
    select
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency = 'monthly'
      and (d.occurrence_months is null or extract(month from gs.month)::integer = any(d.occurrence_months))

    union all

    -- 2b. PAYROLL DEDUCTIONS: annual
    select
        gs.month::date, 'deduction'::text, d.id, d.uuid,
        'deduction'::text, d.deduction_type,
        d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d
    cross join lateral generate_series(
        greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true
      and d.frequency in ('annual', 'semiannual')
      and (d.occurrence_months is not null and extract(month from gs.month)::integer = any(d.occurrence_months))

    union all

    -- 3. OBLIGATIONS: from already-scheduled payments (decimal -> cents)
    select
        date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid,
        coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type),
        o.name, -(op.scheduled_amount * 100)::bigint
    from data.obligation_payments op
    join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true
      and op.status in ('scheduled', 'partial')
      and op.due_date >= p_start_month and op.due_date <= v_end_month

    union all

    -- 3b. OBLIGATIONS: projected months without a scheduled payment
    --     Uses future_fixed/estimated_amount when month >= future_amount_effective_date
    select
        gs.month::date, 'obligation'::text, o.id, o.uuid,
        coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type),
        o.name,
        -(CASE
            WHEN o.future_amount_effective_date IS NOT NULL
             AND gs.month >= o.future_amount_effective_date
            THEN coalesce(o.future_fixed_amount, o.future_estimated_amount,
                          o.fixed_amount,        o.estimated_amount, 0)
            ELSE coalesce(o.fixed_amount, o.estimated_amount, 0)
          END * 100)::bigint
    from data.obligations o
    cross join lateral generate_series(
        greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        interval '1 month'
    ) as gs(month)
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and o.frequency = 'monthly'
      and not exists (
          select 1 from data.obligation_payments op2
          where op2.obligation_id = o.id and date_trunc('month', op2.due_date) = gs.month
            and op2.status in ('scheduled', 'paid', 'partial')
      )
      and gs.month > coalesce(
          (select max(date_trunc('month', op3.due_date)) from data.obligation_payments op3
           where op3.obligation_id = o.id and op3.status = 'paid'), '1900-01-01'::date
      )

    union all

    -- 4. LOAN AMORTIZATION (numeric -> cents)
    select la.month, 'loan_amort'::text, l.id, l.uuid,
        'expense'::text, 'ln amort'::text, l.lender_name || ' amort',
        -(la.amortization * 100)::bigint
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'

    union all

    -- 4b. LOAN INTEREST (numeric -> cents)
    select la.month, 'loan_interest'::text, l.id, l.uuid,
        'interest'::text, 'ln int'::text, l.lender_name || ' int',
        -(la.interest * 100)::bigint
    from data.loans l
    cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'

    union all

    -- 5. INSTALLMENT PLANS (numeric -> cents)
    select
        date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid,
        'expense'::text, 'installment'::text, ip.description,
        -(s.scheduled_amount * 100)::bigint
    from data.installment_plans ip
    join data.installment_schedules s on s.installment_plan_id = ip.id
    where ip.ledger_id = v_ledger_id and ip.user_data = v_user_data and ip.status = 'active'
      and s.status = 'scheduled' and s.due_date >= p_start_month and s.due_date <= v_end_month

    union all

    -- 6. RECURRING TRANSACTIONS (already in cents)
    select
        gs.month::date, 'recurring'::text, rt.id, rt.uuid,
        case when rt.transaction_type = 'inflow' then 'income' else 'expense' end,
        'recurring'::text, rt.description,
        case when rt.transaction_type = 'inflow' then rt.amount else -(rt.amount) end
    from data.recurring_transactions rt
    cross join lateral generate_series(
        greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)),
        least(coalesce(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)),
        case rt.frequency
            when 'monthly'   then interval '1 month'
            when 'yearly'    then interval '1 year'
            when 'weekly'    then interval '1 week'
            when 'biweekly'  then interval '2 weeks'
            when 'daily'     then interval '1 day'
            else                  interval '1 month'
        end
    ) as gs(month)
    where rt.ledger_id = v_ledger_id and rt.user_data = v_user_data and rt.enabled = true

    union all

    -- 7. PROJECTED EVENTS (already in cents)
    select
        date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid,
        e.event_type, e.event_type, e.name,
        case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false
      and e.event_date >= p_start_month and e.event_date <= v_end_month

    order by 1, 2, 7;
end;
$$;
GRANT EXECUTE ON FUNCTION api.generate_cash_flow_projection TO pgbudget_user;
-- +goose StatementEnd

-- +goose Down
-- Restore projection engine without future-amount logic
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.generate_cash_flow_projection(
    p_ledger_uuid  text,
    p_start_month  date    DEFAULT (date_trunc('month', CURRENT_DATE))::date,
    p_months_ahead integer DEFAULT 120
)
RETURNS TABLE(
    month date, source_type text, source_id bigint, source_uuid text,
    category text, subcategory text, description text, amount bigint
)
LANGUAGE plpgsql SECURITY DEFINER
AS $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_end_month date;
begin
    v_user_data := utils.get_user();
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    select id into v_ledger_id from data.ledgers
    where uuid = p_ledger_uuid and user_data = v_user_data;
    if v_ledger_id is null then raise exception 'Ledger not found'; end if;

    return query
    select gs.month::date, 'income'::text, i.id, i.uuid,
        'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i
    cross join lateral generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'monthly'
    union all
    select gs.month::date, 'income'::text, i.id, i.uuid, 'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i cross join lateral generate_series(greatest(date_trunc('month', i.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', i.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency in ('annual', 'semiannual') and (i.occurrence_months is null or extract(month from gs.month)::integer = any(i.occurrence_months))
    union all
    select date_trunc('month', i.start_date)::date, 'income'::text, i.id, i.uuid, 'income'::text, coalesce(i.income_subtype, i.income_type), i.name, i.amount
    from data.income_sources i where i.ledger_id = v_ledger_id and i.user_data = v_user_data and i.is_active = true and i.frequency = 'one_time' and date_trunc('month', i.start_date) >= date_trunc('month', p_start_month::timestamp) and date_trunc('month', i.start_date) <= date_trunc('month', v_end_month::timestamp)
    union all
    select gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d cross join lateral generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency = 'monthly' and (d.occurrence_months is null or extract(month from gs.month)::integer = any(d.occurrence_months))
    union all
    select gs.month::date, 'deduction'::text, d.id, d.uuid, 'deduction'::text, d.deduction_type, d.name, -(coalesce(d.fixed_amount, d.estimated_amount, 0::bigint))
    from data.payroll_deductions d cross join lateral generate_series(greatest(date_trunc('month', d.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', d.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where d.ledger_id = v_ledger_id and d.user_data = v_user_data and d.is_active = true and d.frequency in ('annual', 'semiannual') and (d.occurrence_months is not null and extract(month from gs.month)::integer = any(d.occurrence_months))
    union all
    select date_trunc('month', op.due_date)::date, 'obligation'::text, o.id, o.uuid, coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type), o.name, -(op.scheduled_amount * 100)::bigint
    from data.obligation_payments op join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and op.status in ('scheduled', 'partial') and op.due_date >= p_start_month and op.due_date <= v_end_month
    union all
    select gs.month::date, 'obligation'::text, o.id, o.uuid, coalesce(o.obligation_type, 'other'), coalesce(o.obligation_subtype, o.obligation_type), o.name, -(coalesce(o.fixed_amount, o.estimated_amount, 0) * 100)::bigint
    from data.obligations o cross join lateral generate_series(greatest(date_trunc('month', o.start_date), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', o.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), interval '1 month') as gs(month)
    where o.ledger_id = v_ledger_id and o.user_data = v_user_data and o.is_active = true and o.frequency = 'monthly'
      and not exists (select 1 from data.obligation_payments op2 where op2.obligation_id = o.id and date_trunc('month', op2.due_date) = gs.month and op2.status in ('scheduled', 'paid', 'partial'))
      and gs.month > coalesce((select max(date_trunc('month', op3.due_date)) from data.obligation_payments op3 where op3.obligation_id = o.id and op3.status = 'paid'), '1900-01-01'::date)
    union all
    select la.month, 'loan_amort'::text, l.id, l.uuid, 'expense'::text, 'ln amort'::text, l.lender_name || ' amort', -(la.amortization * 100)::bigint
    from data.loans l cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'
    union all
    select la.month, 'loan_interest'::text, l.id, l.uuid, 'interest'::text, 'ln int'::text, l.lender_name || ' int', -(la.interest * 100)::bigint
    from data.loans l cross join lateral utils.project_loan_amortization(l.id, p_start_month, p_months_ahead) la
    where l.ledger_id = v_ledger_id and l.user_data = v_user_data and l.status = 'active'
    union all
    select date_trunc('month', s.due_date)::date, 'installment'::text, ip.id, ip.uuid, 'expense'::text, 'installment'::text, ip.description, -(s.scheduled_amount * 100)::bigint
    from data.installment_plans ip join data.installment_schedules s on s.installment_plan_id = ip.id
    where ip.ledger_id = v_ledger_id and ip.user_data = v_user_data and ip.status = 'active' and s.status = 'scheduled' and s.due_date >= p_start_month and s.due_date <= v_end_month
    union all
    select gs.month::date, 'recurring'::text, rt.id, rt.uuid, case when rt.transaction_type = 'inflow' then 'income' else 'expense' end, 'recurring'::text, rt.description, case when rt.transaction_type = 'inflow' then rt.amount else -(rt.amount) end
    from data.recurring_transactions rt cross join lateral generate_series(greatest(date_trunc('month', rt.next_date::timestamp), date_trunc('month', p_start_month::timestamp)), least(coalesce(date_trunc('month', rt.end_date::timestamp), date_trunc('month', v_end_month::timestamp)), date_trunc('month', v_end_month::timestamp)), case rt.frequency when 'monthly' then interval '1 month' when 'yearly' then interval '1 year' when 'weekly' then interval '1 week' when 'biweekly' then interval '2 weeks' when 'daily' then interval '1 day' else interval '1 month' end) as gs(month)
    where rt.ledger_id = v_ledger_id and rt.user_data = v_user_data and rt.enabled = true
    union all
    select date_trunc('month', e.event_date)::date, 'event'::text, e.id, e.uuid, e.event_type, e.event_type, e.name, case when e.direction = 'inflow' then e.amount else -(e.amount) end
    from data.projected_events e
    where e.ledger_id = v_ledger_id and e.user_data = v_user_data and e.is_realized = false and e.event_date >= p_start_month and e.event_date <= v_end_month
    order by 1, 2, 7;
end;
$$;
-- +goose StatementEnd

-- Restore api.obligations view without future columns, then recreate functions
-- +goose StatementBegin
DROP VIEW IF EXISTS api.obligations CASCADE;

CREATE VIEW api.obligations AS
SELECT o.uuid, o.name, o.description, o.obligation_type, o.obligation_subtype,
    o.payee_name, p.uuid AS payee_uuid, o.account_number,
    pa.uuid AS default_payment_account_uuid, pa.name AS default_payment_account_name,
    c.uuid AS default_category_uuid, c.name AS default_category_name,
    o.is_fixed_amount, o.fixed_amount, o.estimated_amount, o.amount_range_min, o.amount_range_max,
    o.currency, o.frequency, o.custom_frequency_days, o.due_day_of_month, o.due_day_of_week, o.due_months,
    o.start_date, o.end_date, o.reminder_enabled, o.reminder_days_before, o.grace_period_days, o.late_fee_amount,
    o.is_active, o.is_paused, o.pause_until, o.notes, o.created_at, o.updated_at, l.uuid AS ledger_uuid,
    (SELECT min(op.due_date) FROM data.obligation_payments op WHERE op.obligation_id = o.id AND op.status = 'scheduled' AND op.due_date >= CURRENT_DATE) AS next_due_date,
    (SELECT op.scheduled_amount FROM data.obligation_payments op WHERE op.obligation_id = o.id AND op.status = 'scheduled' AND op.due_date >= CURRENT_DATE ORDER BY op.due_date LIMIT 1) AS next_payment_amount,
    (SELECT count(*) FROM data.obligation_payments op WHERE op.obligation_id = o.id AND op.status = 'paid') AS total_payments_made
FROM data.obligations o
LEFT JOIN data.ledgers l ON l.id = o.ledger_id LEFT JOIN data.payees p ON p.id = o.payee_id
LEFT JOIN data.accounts pa ON pa.id = o.default_payment_account_id LEFT JOIN data.accounts c ON c.id = o.default_category_id
WHERE o.user_data = utils.get_user();

GRANT SELECT ON api.obligations TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.create_obligation(p_ledger_uuid text, p_name text, p_payee_name text, p_obligation_type text, p_frequency text, p_is_fixed_amount boolean, p_start_date date, p_fixed_amount numeric DEFAULT NULL, p_estimated_amount numeric DEFAULT NULL, p_due_day_of_month integer DEFAULT NULL, p_due_day_of_week integer DEFAULT NULL, p_due_months integer[] DEFAULT NULL, p_custom_frequency_days integer DEFAULT NULL, p_default_payment_account_uuid text DEFAULT NULL, p_default_category_uuid text DEFAULT NULL, p_obligation_subtype text DEFAULT NULL, p_description text DEFAULT NULL, p_account_number text DEFAULT NULL, p_reminder_days_before integer DEFAULT 3, p_grace_period_days integer DEFAULT 0, p_notes text DEFAULT NULL)
RETURNS SETOF api.obligations LANGUAGE plpgsql SECURITY DEFINER AS $$
declare v_ledger_id bigint; v_payment_account_id bigint; v_category_id bigint; v_payee_id bigint; v_obligation_uuid text; v_user_data text;
begin
    v_user_data := utils.get_user();
    select id into v_ledger_id from data.ledgers where uuid = p_ledger_uuid and user_data = v_user_data;
    if v_ledger_id is null then raise exception 'Ledger not found'; end if;
    if p_default_payment_account_uuid is not null then select id into v_payment_account_id from data.accounts where uuid = p_default_payment_account_uuid and user_data = v_user_data and deleted_at is null; if v_payment_account_id is null then raise exception 'Payment account not found'; end if; end if;
    if p_default_category_uuid is not null then select id into v_category_id from data.accounts where uuid = p_default_category_uuid and user_data = v_user_data and type = 'equity' and deleted_at is null; if v_category_id is null then raise exception 'Category not found'; end if; end if;
    v_payee_id := utils.get_or_create_payee(p_payee_name, v_category_id, v_user_data);
    if p_is_fixed_amount and p_fixed_amount is null then raise exception 'Fixed amount is required when is_fixed_amount is true'; end if;
    insert into data.obligations (user_data, ledger_id, name, description, obligation_type, obligation_subtype, payee_name, payee_id, account_number, default_payment_account_id, default_category_id, is_fixed_amount, fixed_amount, estimated_amount, frequency, custom_frequency_days, due_day_of_month, due_day_of_week, due_months, start_date, reminder_days_before, grace_period_days, notes)
    values (v_user_data, v_ledger_id, p_name, p_description, p_obligation_type, p_obligation_subtype, p_payee_name, v_payee_id, p_account_number, v_payment_account_id, v_category_id, p_is_fixed_amount, p_fixed_amount, p_estimated_amount, p_frequency, p_custom_frequency_days, p_due_day_of_month, p_due_day_of_week, p_due_months, p_start_date, p_reminder_days_before, p_grace_period_days, p_notes) returning uuid into v_obligation_uuid;
    return query select * from api.obligations where uuid = v_obligation_uuid;
end; $$;
GRANT EXECUTE ON FUNCTION api.create_obligation TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_obligation(p_obligation_uuid text) RETURNS SETOF api.obligations LANGUAGE plpgsql SECURITY DEFINER AS $$ begin return query select * from api.obligations where uuid = p_obligation_uuid; end; $$;
CREATE OR REPLACE FUNCTION api.get_obligations(p_ledger_uuid text) RETURNS SETOF api.obligations LANGUAGE plpgsql SECURITY DEFINER AS $$ declare v_ledger_id bigint; v_user_data text; begin v_user_data := utils.get_user(); select id into v_ledger_id from data.ledgers where uuid = p_ledger_uuid and user_data = v_user_data; if v_ledger_id is null then raise exception 'Ledger not found'; end if; return query select * from api.obligations where ledger_uuid = p_ledger_uuid order by name; end; $$;
CREATE OR REPLACE FUNCTION api.update_obligation(p_obligation_uuid text, p_name text DEFAULT NULL, p_description text DEFAULT NULL, p_payee_name text DEFAULT NULL, p_fixed_amount numeric DEFAULT NULL, p_estimated_amount numeric DEFAULT NULL, p_reminder_days_before integer DEFAULT NULL, p_grace_period_days integer DEFAULT NULL, p_is_active boolean DEFAULT NULL, p_is_paused boolean DEFAULT NULL, p_pause_until date DEFAULT NULL, p_notes text DEFAULT NULL)
RETURNS SETOF api.obligations LANGUAGE plpgsql SECURITY DEFINER AS $$ declare v_obligation_id bigint; v_payee_id bigint; v_user_data text; begin v_user_data := utils.get_user(); select id into v_obligation_id from data.obligations where uuid = p_obligation_uuid and user_data = v_user_data; if v_obligation_id is null then raise exception 'Obligation not found or access denied'; end if; if p_payee_name is not null then select payee_id into v_payee_id from data.obligations where id = v_obligation_id; v_payee_id := utils.get_or_create_payee(p_payee_name, null, v_user_data); end if; update data.obligations set name = coalesce(p_name, name), description = coalesce(p_description, description), payee_name = coalesce(p_payee_name, payee_name), payee_id = coalesce(v_payee_id, payee_id), fixed_amount = coalesce(p_fixed_amount, fixed_amount), estimated_amount = coalesce(p_estimated_amount, estimated_amount), reminder_days_before = coalesce(p_reminder_days_before, reminder_days_before), grace_period_days = coalesce(p_grace_period_days, grace_period_days), is_active = coalesce(p_is_active, is_active), is_paused = coalesce(p_is_paused, is_paused), pause_until = coalesce(p_pause_until, pause_until), notes = coalesce(p_notes, notes), updated_at = now() where id = v_obligation_id; return query select * from api.obligations where uuid = p_obligation_uuid; end; $$;
GRANT EXECUTE ON FUNCTION api.get_obligation TO pgbudget_user;
GRANT EXECUTE ON FUNCTION api.get_obligations TO pgbudget_user;
GRANT EXECUTE ON FUNCTION api.update_obligation TO pgbudget_user;
-- +goose StatementEnd

-- Drop future amount columns
ALTER TABLE data.obligations
    DROP COLUMN IF EXISTS future_fixed_amount,
    DROP COLUMN IF EXISTS future_estimated_amount,
    DROP COLUMN IF EXISTS future_amount_effective_date;
