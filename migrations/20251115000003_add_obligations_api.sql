-- +goose Up
-- API functions for obligations management (Phase 1)

-- API view for obligations
create or replace view api.obligations as
select
    o.uuid,
    o.name,
    o.description,
    o.obligation_type,
    o.obligation_subtype,
    o.payee_name,
    p.uuid as payee_uuid,
    o.account_number,
    pa.uuid as default_payment_account_uuid,
    pa.name as default_payment_account_name,
    c.uuid as default_category_uuid,
    c.name as default_category_name,
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
    l.uuid as ledger_uuid,
    (
        select min(op.due_date)
        from data.obligation_payments op
        where op.obligation_id = o.id
          and op.status = 'scheduled'
          and op.due_date >= current_date
    ) as next_due_date,
    (
        select op.scheduled_amount
        from data.obligation_payments op
        where op.obligation_id = o.id
          and op.status = 'scheduled'
          and op.due_date >= current_date
        order by op.due_date
        limit 1
    ) as next_payment_amount,
    (
        select count(*)
        from data.obligation_payments op
        where op.obligation_id = o.id
          and op.status = 'paid'
    ) as total_payments_made
from data.obligations o
left join data.ledgers l on l.id = o.ledger_id
left join data.payees p on p.id = o.payee_id
left join data.accounts pa on pa.id = o.default_payment_account_id
left join data.accounts c on c.id = o.default_category_id
where o.user_data = utils.get_user();

-- API view for obligation payments
create or replace view api.obligation_payments as
select
    op.uuid,
    o.uuid as obligation_uuid,
    o.name as obligation_name,
    o.payee_name,
    op.due_date,
    op.scheduled_amount,
    op.status,
    op.paid_date,
    op.actual_amount_paid,
    t.uuid as transaction_uuid,
    pa.uuid as payment_account_uuid,
    pa.name as payment_account_name,
    op.payment_method,
    op.confirmation_number,
    op.days_late,
    op.late_fee_charged,
    op.notes,
    op.created_at,
    op.updated_at,
    op.payment_marked_at,
    (current_date - op.due_date) as days_until_due,
    case
        when op.status in ('paid', 'skipped') then false
        when current_date > op.due_date then true
        else false
    end as is_overdue
from data.obligation_payments op
join data.obligations o on o.id = op.obligation_id
left join data.transactions t on t.id = op.transaction_id
left join data.accounts pa on pa.id = op.payment_account_id
where op.user_data = utils.get_user();

-- +goose StatementBegin
-- Create obligation
create or replace function api.create_obligation(
    p_ledger_uuid text,
    p_name text,
    p_payee_name text,
    p_obligation_type text,
    p_frequency text,
    p_is_fixed_amount boolean,
    p_start_date date,
    p_fixed_amount decimal default null,
    p_estimated_amount decimal default null,
    p_due_day_of_month integer default null,
    p_due_day_of_week integer default null,
    p_due_months integer[] default null,
    p_custom_frequency_days integer default null,
    p_default_payment_account_uuid text default null,
    p_default_category_uuid text default null,
    p_obligation_subtype text default null,
    p_description text default null,
    p_account_number text default null,
    p_reminder_days_before integer default 3,
    p_grace_period_days integer default 0,
    p_notes text default null
) returns setof api.obligations as $$
declare
    v_ledger_id bigint;
    v_payment_account_id bigint;
    v_category_id bigint;
    v_payee_id bigint;
    v_obligation_uuid text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate and get ledger ID
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    -- Resolve payment account UUID to ID if provided
    if p_default_payment_account_uuid is not null then
        select id into v_payment_account_id
        from data.accounts
        where uuid = p_default_payment_account_uuid
          and user_data = v_user_data
          and deleted_at is null;

        if v_payment_account_id is null then
            raise exception 'Payment account not found';
        end if;
    end if;

    -- Resolve category UUID to ID if provided
    if p_default_category_uuid is not null then
        select id into v_category_id
        from data.accounts
        where uuid = p_default_category_uuid
          and user_data = v_user_data
          and type = 'equity'
          and deleted_at is null;

        if v_category_id is null then
            raise exception 'Category not found';
        end if;
    end if;

    -- Get or create payee
    v_payee_id := utils.get_or_create_payee(p_payee_name, v_category_id, v_user_data);

    -- Validate amount
    if p_is_fixed_amount and p_fixed_amount is null then
        raise exception 'Fixed amount is required when is_fixed_amount is true';
    end if;

    -- Validate frequency-specific fields
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

    -- Create obligation
    insert into data.obligations (
        user_data,
        ledger_id,
        name,
        description,
        obligation_type,
        obligation_subtype,
        payee_name,
        payee_id,
        account_number,
        default_payment_account_id,
        default_category_id,
        is_fixed_amount,
        fixed_amount,
        estimated_amount,
        frequency,
        custom_frequency_days,
        due_day_of_month,
        due_day_of_week,
        due_months,
        start_date,
        reminder_days_before,
        grace_period_days,
        notes
    ) values (
        v_user_data,
        v_ledger_id,
        p_name,
        p_description,
        p_obligation_type,
        p_obligation_subtype,
        p_payee_name,
        v_payee_id,
        p_account_number,
        v_payment_account_id,
        v_category_id,
        p_is_fixed_amount,
        p_fixed_amount,
        p_estimated_amount,
        p_frequency,
        p_custom_frequency_days,
        p_due_day_of_month,
        p_due_day_of_week,
        p_due_months,
        p_start_date,
        p_reminder_days_before,
        p_grace_period_days,
        p_notes
    ) returning uuid into v_obligation_uuid;

    -- Return created obligation
    return query
    select * from api.obligations
    where uuid = v_obligation_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Update obligation
create or replace function api.update_obligation(
    p_obligation_uuid text,
    p_name text default null,
    p_description text default null,
    p_payee_name text default null,
    p_fixed_amount decimal default null,
    p_estimated_amount decimal default null,
    p_reminder_days_before integer default null,
    p_grace_period_days integer default null,
    p_is_active boolean default null,
    p_is_paused boolean default null,
    p_pause_until date default null,
    p_notes text default null
) returns setof api.obligations as $$
declare
    v_obligation_id bigint;
    v_payee_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get obligation ID and verify ownership
    select id into v_obligation_id
    from data.obligations
    where uuid = p_obligation_uuid
      and user_data = v_user_data;

    if v_obligation_id is null then
        raise exception 'Obligation not found or access denied';
    end if;

    -- Update payee if changed
    if p_payee_name is not null then
        select payee_id into v_payee_id
        from data.obligations
        where id = v_obligation_id;

        v_payee_id := utils.get_or_create_payee(p_payee_name, null, v_user_data);
    end if;

    -- Update obligation
    update data.obligations
    set
        name = coalesce(p_name, name),
        description = coalesce(p_description, description),
        payee_name = coalesce(p_payee_name, payee_name),
        payee_id = coalesce(v_payee_id, payee_id),
        fixed_amount = coalesce(p_fixed_amount, fixed_amount),
        estimated_amount = coalesce(p_estimated_amount, estimated_amount),
        reminder_days_before = coalesce(p_reminder_days_before, reminder_days_before),
        grace_period_days = coalesce(p_grace_period_days, grace_period_days),
        is_active = coalesce(p_is_active, is_active),
        is_paused = coalesce(p_is_paused, is_paused),
        pause_until = coalesce(p_pause_until, pause_until),
        notes = coalesce(p_notes, notes),
        updated_at = now()
    where id = v_obligation_id;

    -- Return updated obligation
    return query
    select * from api.obligations
    where uuid = p_obligation_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get upcoming obligations
create or replace function api.get_upcoming_obligations(
    p_ledger_uuid text,
    p_days_ahead integer default 30,
    p_include_overdue boolean default true
) returns table (
    obligation_uuid text,
    payment_uuid text,
    name text,
    payee_name text,
    due_date date,
    amount decimal,
    status text,
    days_until_due integer,
    is_overdue boolean,
    obligation_type text,
    payment_account_name text
) as $$
declare
    v_ledger_id bigint;
    v_user_data text;
    v_start_date date;
begin
    v_user_data := utils.get_user();

    -- Validate and get ledger ID
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    -- Set start date based on whether to include overdue
    if p_include_overdue then
        v_start_date := '1900-01-01'::date;
    else
        v_start_date := current_date;
    end if;

    return query
    select
        o.uuid as obligation_uuid,
        op.uuid as payment_uuid,
        o.name,
        o.payee_name,
        op.due_date,
        op.scheduled_amount as amount,
        op.status,
        (op.due_date - current_date) as days_until_due,
        (current_date > op.due_date and op.status not in ('paid', 'skipped')) as is_overdue,
        o.obligation_type,
        pa.name as payment_account_name
    from data.obligation_payments op
    join data.obligations o on o.id = op.obligation_id
    left join data.accounts pa on pa.id = o.default_payment_account_id
    where o.ledger_id = v_ledger_id
      and o.user_data = v_user_data
      and o.is_active = true
      and op.status in ('scheduled', 'partial')
      and op.due_date >= v_start_date
      and op.due_date <= current_date + p_days_ahead
    order by op.due_date, o.name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Mark obligation payment as paid
create or replace function api.mark_obligation_paid(
    p_payment_uuid text,
    p_paid_date date,
    p_actual_amount decimal,
    p_transaction_uuid text default null,
    p_payment_account_uuid text default null,
    p_payment_method text default null,
    p_confirmation_number text default null,
    p_notes text default null
) returns table (
    payment_uuid text,
    status text,
    next_due_date date
) as $$
declare
    v_payment_id bigint;
    v_obligation_id bigint;
    v_transaction_id bigint;
    v_payment_account_id bigint;
    v_scheduled_amount decimal;
    v_due_date date;
    v_days_late integer;
    v_new_status text;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get payment details and verify ownership
    select op.id, op.obligation_id, op.scheduled_amount, op.due_date
    into v_payment_id, v_obligation_id, v_scheduled_amount, v_due_date
    from data.obligation_payments op
    where op.uuid = p_payment_uuid
      and op.user_data = v_user_data;

    if v_payment_id is null then
        raise exception 'Payment not found or access denied';
    end if;

    -- Resolve transaction UUID to ID if provided
    if p_transaction_uuid is not null then
        select id into v_transaction_id
        from data.transactions
        where uuid = p_transaction_uuid
          and user_data = v_user_data
          and deleted_at is null;

        if v_transaction_id is null then
            raise exception 'Transaction not found';
        end if;
    end if;

    -- Resolve payment account UUID to ID if provided
    if p_payment_account_uuid is not null then
        select id into v_payment_account_id
        from data.accounts
        where uuid = p_payment_account_uuid
          and user_data = v_user_data
          and deleted_at is null;

        if v_payment_account_id is null then
            raise exception 'Payment account not found';
        end if;
    end if;

    -- Calculate days late
    v_days_late := greatest(0, p_paid_date - v_due_date);

    -- Determine status
    if p_actual_amount >= v_scheduled_amount then
        if v_days_late > 0 then
            v_new_status := 'late';
        else
            v_new_status := 'paid';
        end if;
    else
        v_new_status := 'partial';
    end if;

    -- Update payment
    update data.obligation_payments
    set
        paid_date = p_paid_date,
        actual_amount_paid = p_actual_amount,
        transaction_id = v_transaction_id,
        transaction_uuid = p_transaction_uuid,
        payment_account_id = v_payment_account_id,
        payment_method = coalesce(p_payment_method, payment_method),
        confirmation_number = coalesce(p_confirmation_number, confirmation_number),
        days_late = v_days_late,
        status = v_new_status,
        notes = coalesce(p_notes, notes),
        payment_marked_at = now(),
        updated_at = now()
    where id = v_payment_id;

    -- Generate next payment if this was the last scheduled payment
    perform utils.generate_obligation_schedule(v_obligation_id, 12);

    -- Return result
    return query
    select
        p_payment_uuid,
        v_new_status,
        (
            select min(op.due_date)
            from data.obligation_payments op
            where op.obligation_id = v_obligation_id
              and op.status = 'scheduled'
              and op.due_date > v_due_date
        );
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Link transaction to obligation payment
create or replace function api.link_transaction_to_obligation(
    p_transaction_uuid text,
    p_payment_uuid text
) returns boolean as $$
declare
    v_transaction_id bigint;
    v_transaction_date date;
    v_transaction_amount decimal;
    v_payment_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get transaction details
    select id, date, abs(amount) into v_transaction_id, v_transaction_date, v_transaction_amount
    from data.transactions
    where uuid = p_transaction_uuid
      and user_data = v_user_data
      and deleted_at is null;

    if v_transaction_id is null then
        raise exception 'Transaction not found';
    end if;

    -- Get payment ID
    select id into v_payment_id
    from data.obligation_payments
    where uuid = p_payment_uuid
      and user_data = v_user_data;

    if v_payment_id is null then
        raise exception 'Payment not found';
    end if;

    -- Link the transaction and mark as paid
    perform api.mark_obligation_paid(
        p_payment_uuid := p_payment_uuid,
        p_paid_date := v_transaction_date,
        p_actual_amount := v_transaction_amount,
        p_transaction_uuid := p_transaction_uuid
    );

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Generate payment schedule (wrapper for utils function)
create or replace function api.generate_obligation_schedule(
    p_obligation_uuid text,
    p_months_ahead integer default 12
) returns integer as $$
declare
    v_obligation_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get obligation ID
    select id into v_obligation_id
    from data.obligations
    where uuid = p_obligation_uuid
      and user_data = v_user_data;

    if v_obligation_id is null then
        raise exception 'Obligation not found';
    end if;

    return utils.generate_obligation_schedule(v_obligation_id, p_months_ahead);
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get all obligations for a ledger
create or replace function api.get_obligations(
    p_ledger_uuid text
) returns setof api.obligations as $$
declare
    v_ledger_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Validate and get ledger ID
    select id into v_ledger_id
    from data.ledgers
    where uuid = p_ledger_uuid
      and user_data = v_user_data;

    if v_ledger_id is null then
        raise exception 'Ledger not found';
    end if;

    return query
    select * from api.obligations
    where ledger_uuid = p_ledger_uuid
    order by name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get single obligation
create or replace function api.get_obligation(
    p_obligation_uuid text
) returns setof api.obligations as $$
begin
    return query
    select * from api.obligations
    where uuid = p_obligation_uuid;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Get payment history for an obligation
create or replace function api.get_obligation_payment_history(
    p_obligation_uuid text
) returns setof api.obligation_payments as $$
declare
    v_user_data text;
begin
    v_user_data := utils.get_user();

    return query
    select * from api.obligation_payments
    where obligation_uuid = p_obligation_uuid
    order by due_date desc;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Delete obligation
create or replace function api.delete_obligation(
    p_obligation_uuid text
) returns boolean as $$
declare
    v_obligation_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get obligation ID and verify ownership
    select id into v_obligation_id
    from data.obligations
    where uuid = p_obligation_uuid
      and user_data = v_user_data;

    if v_obligation_id is null then
        raise exception 'Obligation not found or access denied';
    end if;

    -- Delete obligation (cascade will delete payments)
    delete from data.obligations
    where id = v_obligation_id;

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists api.delete_obligation(text);
drop function if exists api.get_obligation_payment_history(text);
drop function if exists api.get_obligation(text);
drop function if exists api.get_obligations(text);
drop function if exists api.generate_obligation_schedule(text, integer);
drop function if exists api.link_transaction_to_obligation(text, text);
drop function if exists api.mark_obligation_paid(text, date, decimal, text, text, text, text, text);
drop function if exists api.get_upcoming_obligations(text, integer, boolean);
drop function if exists api.update_obligation(text, text, text, text, decimal, decimal, integer, integer, boolean, boolean, date, text);
drop function if exists api.create_obligation(text, text, text, text, text, boolean, date, decimal, decimal, integer, integer, integer[], integer, text, text, text, text, text, integer, integer, text);
drop view if exists api.obligation_payments;
drop view if exists api.obligations;
