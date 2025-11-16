-- +goose Up
-- Utils functions for obligations management (Phase 1)

-- +goose StatementBegin
-- Calculate next due date based on obligation frequency
create or replace function utils.calculate_next_due_date(
    p_obligation_id bigint,
    p_from_date date default current_date
) returns date as $$
declare
    v_obligation record;
    v_next_date date;
    v_day_offset integer;
    v_current_month integer;
    v_target_month integer;
    v_current_year integer;
begin
    -- Get obligation details
    select * into v_obligation
    from data.obligations
    where id = p_obligation_id;

    if not found then
        raise exception 'Obligation not found';
    end if;

    -- Calculate next due date based on frequency
    case v_obligation.frequency
        when 'weekly' then
            -- Find next occurrence of due_day_of_week
            v_day_offset := (v_obligation.due_day_of_week - extract(dow from p_from_date)::integer + 7) % 7;
            if v_day_offset = 0 then
                v_day_offset := 7; -- Next week if today is the due day
            end if;
            v_next_date := p_from_date + v_day_offset;

        when 'biweekly' then
            -- Find next occurrence of due_day_of_week, then add 14 days if needed
            v_day_offset := (v_obligation.due_day_of_week - extract(dow from p_from_date)::integer + 7) % 7;
            if v_day_offset = 0 then
                v_day_offset := 14;
            end if;
            v_next_date := p_from_date + v_day_offset;

        when 'monthly' then
            -- Next occurrence of due_day_of_month
            v_next_date := make_date(
                extract(year from p_from_date)::integer,
                extract(month from p_from_date)::integer,
                least(v_obligation.due_day_of_month, extract(day from (date_trunc('month', p_from_date) + interval '1 month - 1 day'))::integer)
            );

            -- If we've already passed this month's due date, move to next month
            if v_next_date <= p_from_date then
                v_next_date := make_date(
                    extract(year from p_from_date + interval '1 month')::integer,
                    extract(month from p_from_date + interval '1 month')::integer,
                    least(v_obligation.due_day_of_month, extract(day from (date_trunc('month', p_from_date + interval '1 month') + interval '1 month - 1 day'))::integer)
                );
            end if;

        when 'quarterly' then
            -- Every 3 months on due_day_of_month
            v_next_date := make_date(
                extract(year from p_from_date)::integer,
                extract(month from p_from_date)::integer,
                least(v_obligation.due_day_of_month, extract(day from (date_trunc('month', p_from_date) + interval '1 month - 1 day'))::integer)
            );

            if v_next_date <= p_from_date then
                v_next_date := make_date(
                    extract(year from p_from_date + interval '3 months')::integer,
                    extract(month from p_from_date + interval '3 months')::integer,
                    least(v_obligation.due_day_of_month, extract(day from (date_trunc('month', p_from_date + interval '3 months') + interval '1 month - 1 day'))::integer)
                );
            end if;

        when 'semiannual' then
            -- Every 6 months on specific months
            v_current_month := extract(month from p_from_date)::integer;
            v_current_year := extract(year from p_from_date)::integer;
            v_next_date := null;

            -- Find next month in due_months array
            foreach v_target_month in array v_obligation.due_months loop
                if v_target_month > v_current_month or
                   (v_target_month = v_current_month and
                    p_from_date < make_date(v_current_year, v_target_month, v_obligation.due_day_of_month)) then
                    v_next_date := make_date(
                        v_current_year,
                        v_target_month,
                        least(v_obligation.due_day_of_month, extract(day from (make_date(v_current_year, v_target_month, 1) + interval '1 month - 1 day'))::integer)
                    );
                    exit;
                end if;
            end loop;

            -- If no month found this year, use first month of next year
            if v_next_date is null then
                v_next_date := make_date(
                    v_current_year + 1,
                    v_obligation.due_months[1],
                    least(v_obligation.due_day_of_month, extract(day from (make_date(v_current_year + 1, v_obligation.due_months[1], 1) + interval '1 month - 1 day'))::integer)
                );
            end if;

        when 'annual' then
            -- Once per year on specific month and day
            v_target_month := v_obligation.due_months[1];
            v_current_year := extract(year from p_from_date)::integer;

            v_next_date := make_date(
                v_current_year,
                v_target_month,
                least(v_obligation.due_day_of_month, extract(day from (make_date(v_current_year, v_target_month, 1) + interval '1 month - 1 day'))::integer)
            );

            -- If already passed this year, move to next year
            if v_next_date <= p_from_date then
                v_next_date := make_date(
                    v_current_year + 1,
                    v_target_month,
                    least(v_obligation.due_day_of_month, extract(day from (make_date(v_current_year + 1, v_target_month, 1) + interval '1 month - 1 day'))::integer)
                );
            end if;

        when 'custom' then
            -- Custom frequency in days
            v_next_date := p_from_date + v_obligation.custom_frequency_days;

        else
            raise exception 'Invalid frequency: %', v_obligation.frequency;
    end case;

    -- Ensure date is not before start_date
    if v_next_date < v_obligation.start_date then
        v_next_date := v_obligation.start_date;
    end if;

    -- Check if date is after end_date
    if v_obligation.end_date is not null and v_next_date > v_obligation.end_date then
        return null;
    end if;

    return v_next_date;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Generate payment schedule for an obligation
create or replace function utils.generate_obligation_schedule(
    p_obligation_id bigint,
    p_months_ahead integer default 12
) returns integer as $$
declare
    v_obligation record;
    v_current_date date;
    v_next_date date;
    v_end_date date;
    v_scheduled_amount decimal(15,2);
    v_payments_created integer := 0;
begin
    -- Get obligation details
    select * into v_obligation
    from data.obligations
    where id = p_obligation_id;

    if not found then
        raise exception 'Obligation not found';
    end if;

    -- Don't generate if obligation is not active
    if not v_obligation.is_active then
        return 0;
    end if;

    -- Determine the amount to use
    if v_obligation.is_fixed_amount then
        v_scheduled_amount := v_obligation.fixed_amount;
    else
        v_scheduled_amount := coalesce(v_obligation.estimated_amount, v_obligation.amount_range_min);
    end if;

    -- Set end date for generation
    v_end_date := current_date + (p_months_ahead || ' months')::interval;
    if v_obligation.end_date is not null and v_obligation.end_date < v_end_date then
        v_end_date := v_obligation.end_date;
    end if;

    -- Find the last scheduled payment date
    select max(due_date) into v_current_date
    from data.obligation_payments
    where obligation_id = p_obligation_id
      and status in ('scheduled', 'paid', 'partial', 'late');

    -- If no payments exist, start from obligation start_date
    if v_current_date is null then
        v_current_date := v_obligation.start_date - 1; -- Subtract 1 so first calculation gives start_date
    end if;

    -- Generate payments
    loop
        -- Calculate next due date
        v_next_date := utils.calculate_next_due_date(p_obligation_id, v_current_date);

        -- Exit if no more dates to generate
        exit when v_next_date is null or v_next_date > v_end_date;

        -- Check if payment already exists for this date
        if not exists (
            select 1 from data.obligation_payments
            where obligation_id = p_obligation_id
              and due_date = v_next_date
        ) then
            -- Create payment record
            insert into data.obligation_payments (
                user_data,
                obligation_id,
                due_date,
                scheduled_amount,
                status
            ) values (
                v_obligation.user_data,
                p_obligation_id,
                v_next_date,
                v_scheduled_amount,
                'scheduled'
            );

            v_payments_created := v_payments_created + 1;
        end if;

        v_current_date := v_next_date;
    end loop;

    return v_payments_created;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Trigger function to auto-generate schedule when obligation is created
create or replace function utils.obligation_after_insert()
returns trigger as $$
begin
    -- Generate initial payment schedule (12 months ahead)
    perform utils.generate_obligation_schedule(new.id, 12);
    return new;
end;
$$ language plpgsql security definer;

-- Create trigger for auto-generating schedule
create trigger obligation_auto_generate_schedule
    after insert on data.obligations
    for each row
    when (new.is_active = true)
    execute function utils.obligation_after_insert();
-- +goose StatementEnd

-- +goose StatementBegin
-- Get obligation by UUID
create or replace function utils.get_obligation(
    p_obligation_uuid text,
    p_user_data text default utils.get_user()
) returns table (
    id bigint,
    uuid text,
    ledger_id bigint,
    name text,
    description text,
    obligation_type text,
    obligation_subtype text,
    payee_name text,
    payee_id bigint,
    account_number text,
    is_fixed_amount boolean,
    fixed_amount decimal,
    estimated_amount decimal,
    frequency text,
    start_date date,
    end_date date,
    is_active boolean,
    next_due_date date,
    next_payment_amount decimal
) as $$
begin
    return query
    select
        o.id,
        o.uuid,
        o.ledger_id,
        o.name,
        o.description,
        o.obligation_type,
        o.obligation_subtype,
        o.payee_name,
        o.payee_id,
        o.account_number,
        o.is_fixed_amount,
        o.fixed_amount,
        o.estimated_amount,
        o.frequency,
        o.start_date,
        o.end_date,
        o.is_active,
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
        ) as next_payment_amount
    from data.obligations o
    where o.uuid = p_obligation_uuid
      and o.user_data = p_user_data;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Update payment status when transaction is deleted
create or replace function utils.obligation_payment_transaction_cleanup()
returns trigger as $$
begin
    -- When a transaction is deleted, update any linked obligation payments
    update data.obligation_payments
    set
        transaction_id = null,
        transaction_uuid = null,
        status = case
            when actual_amount_paid is not null and actual_amount_paid > 0 then 'partial'
            else 'scheduled'
        end
    where transaction_id = old.id;

    return old;
end;
$$ language plpgsql security definer;

-- Create trigger to cleanup obligation payments when transaction is deleted
create trigger obligation_payment_transaction_cleanup
    before delete on data.transactions
    for each row
    execute function utils.obligation_payment_transaction_cleanup();
-- +goose StatementEnd

-- +goose Down
-- Drop triggers and functions
drop trigger if exists obligation_payment_transaction_cleanup on data.transactions;
drop function if exists utils.obligation_payment_transaction_cleanup();
drop trigger if exists obligation_auto_generate_schedule on data.obligations;
drop function if exists utils.obligation_after_insert();
drop function if exists utils.get_obligation(text, text);
drop function if exists utils.generate_obligation_schedule(bigint, integer);
drop function if exists utils.calculate_next_due_date(bigint, date);
