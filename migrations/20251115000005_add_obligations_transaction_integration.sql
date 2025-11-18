-- +goose Up
-- Transaction Integration for Obligations (Phase 5)

-- +goose StatementBegin
-- Function to find matching obligation payments for a transaction
-- This function suggests potential obligation payments that could be linked to a transaction
create or replace function api.suggest_obligation_payments_for_transaction(
    p_transaction_uuid text,
    p_match_tolerance_days integer default 7,
    p_amount_tolerance_percent decimal default 10.0
) returns table (
    payment_uuid text,
    obligation_uuid text,
    obligation_name text,
    payee_name text,
    due_date date,
    scheduled_amount decimal,
    match_score integer,
    match_reasons text[]
) as $$
declare
    v_transaction_id bigint;
    v_transaction_date date;
    v_transaction_amount decimal;
    v_transaction_payee_id bigint;
    v_transaction_payee_name text;
    v_user_data text;
    v_amount_min decimal;
    v_amount_max decimal;
begin
    v_user_data := utils.get_user();

    -- Get transaction details
    select t.id, t.date, abs(t.amount), t.payee_id, p.name
    into v_transaction_id, v_transaction_date, v_transaction_amount, v_transaction_payee_id, v_transaction_payee_name
    from data.transactions t
    left join data.payees p on p.id = t.payee_id
    where t.uuid = p_transaction_uuid
      and t.user_data = v_user_data
      and t.deleted_at is null;

    if v_transaction_id is null then
        raise exception 'Transaction not found';
    end if;

    -- Calculate amount tolerance range
    v_amount_min := v_transaction_amount * (1 - p_amount_tolerance_percent / 100.0);
    v_amount_max := v_transaction_amount * (1 + p_amount_tolerance_percent / 100.0);

    -- Find matching obligation payments
    return query
    with matches as (
        select
            op.uuid as payment_uuid,
            o.uuid as obligation_uuid,
            o.name as obligation_name,
            o.payee_name,
            op.due_date,
            op.scheduled_amount,
            -- Calculate match score (higher is better)
            (
                -- Exact date match: +50 points
                case when op.due_date = v_transaction_date then 50 else 0 end +
                -- Date within tolerance: +30 points, decreasing with distance
                case
                    when abs(op.due_date - v_transaction_date) <= p_match_tolerance_days then
                        30 - (abs(op.due_date - v_transaction_date) * 3)
                    else 0
                end +
                -- Exact amount match: +30 points
                case when op.scheduled_amount = v_transaction_amount then 30 else 0 end +
                -- Amount within tolerance: +20 points
                case
                    when op.scheduled_amount between v_amount_min and v_amount_max then 20
                    else 0
                end +
                -- Payee match: +20 points
                case
                    when v_transaction_payee_id is not null and o.payee_id = v_transaction_payee_id then 20
                    when v_transaction_payee_name is not null and
                         lower(o.payee_name) = lower(v_transaction_payee_name) then 20
                    else 0
                end
            ) as match_score,
            -- Build match reasons array
            array_remove(array[
                case when op.due_date = v_transaction_date then 'Exact date match' end,
                case
                    when op.due_date != v_transaction_date and
                         abs(op.due_date - v_transaction_date) <= p_match_tolerance_days
                    then 'Date within ' || p_match_tolerance_days || ' days'
                end,
                case when op.scheduled_amount = v_transaction_amount then 'Exact amount match' end,
                case
                    when op.scheduled_amount != v_transaction_amount and
                         op.scheduled_amount between v_amount_min and v_amount_max
                    then 'Amount within ' || p_amount_tolerance_percent || '% tolerance'
                end,
                case
                    when (v_transaction_payee_id is not null and o.payee_id = v_transaction_payee_id) or
                         (v_transaction_payee_name is not null and lower(o.payee_name) = lower(v_transaction_payee_name))
                    then 'Matching payee'
                end
            ], null) as match_reasons
        from data.obligation_payments op
        join data.obligations o on o.id = op.obligation_id
        where op.user_data = v_user_data
          and o.is_active = true
          and op.status in ('scheduled', 'partial')
          and op.transaction_id is null  -- Not already linked
          -- Date range: within tolerance days of transaction date
          and op.due_date between (v_transaction_date - p_match_tolerance_days)
                              and (v_transaction_date + p_match_tolerance_days)
          -- Amount range: within tolerance percent of transaction amount
          and (
              op.scheduled_amount between v_amount_min and v_amount_max
              or o.is_fixed_amount = false  -- Include variable amounts
          )
    )
    select
        m.payment_uuid,
        m.obligation_uuid,
        m.obligation_name,
        m.payee_name,
        m.due_date,
        m.scheduled_amount,
        m.match_score,
        m.match_reasons
    from matches m
    where m.match_score > 0  -- Only return matches with some score
    order by m.match_score desc, m.due_date;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Function to get pending obligation payments for a ledger
-- Useful for displaying in transaction forms
create or replace function api.get_pending_obligation_payments(
    p_ledger_uuid text,
    p_days_back integer default 30,
    p_days_ahead integer default 30
) returns table (
    payment_uuid text,
    obligation_uuid text,
    obligation_name text,
    payee_name text,
    due_date date,
    scheduled_amount decimal,
    status text,
    days_until_due integer
) as $$
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
    select
        op.uuid as payment_uuid,
        o.uuid as obligation_uuid,
        o.name as obligation_name,
        o.payee_name,
        op.due_date,
        op.scheduled_amount,
        op.status,
        (op.due_date - current_date) as days_until_due
    from data.obligation_payments op
    join data.obligations o on o.id = op.obligation_id
    where o.ledger_id = v_ledger_id
      and o.user_data = v_user_data
      and o.is_active = true
      and op.status in ('scheduled', 'partial')
      and op.transaction_id is null  -- Not already linked
      and op.due_date between (current_date - p_days_back) and (current_date + p_days_ahead)
    order by op.due_date, o.name;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Function to auto-match and link transaction to best obligation payment
create or replace function api.auto_link_transaction_to_obligation(
    p_transaction_uuid text,
    p_min_match_score integer default 70
) returns table (
    linked boolean,
    payment_uuid text,
    obligation_name text,
    match_score integer
) as $$
declare
    v_best_match record;
begin
    -- Find best matching obligation payment
    select * into v_best_match
    from api.suggest_obligation_payments_for_transaction(p_transaction_uuid)
    order by match_score desc
    limit 1;

    -- If we have a good match, link it
    if v_best_match is not null and v_best_match.match_score >= p_min_match_score then
        perform api.link_transaction_to_obligation(
            p_transaction_uuid := p_transaction_uuid,
            p_payment_uuid := v_best_match.payment_uuid
        );

        return query
        select
            true as linked,
            v_best_match.payment_uuid,
            v_best_match.obligation_name,
            v_best_match.match_score;
    else
        return query
        select
            false as linked,
            null::text as payment_uuid,
            null::text as obligation_name,
            0 as match_score;
    end if;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Function to get obligation info for a transaction
create or replace function api.get_transaction_obligation_info(
    p_transaction_uuid text
) returns table (
    payment_uuid text,
    obligation_uuid text,
    obligation_name text,
    payee_name text,
    due_date date,
    scheduled_amount decimal,
    payment_status text,
    is_overdue boolean
) as $$
declare
    v_user_data text;
begin
    v_user_data := utils.get_user();

    return query
    select
        op.uuid as payment_uuid,
        o.uuid as obligation_uuid,
        o.name as obligation_name,
        o.payee_name,
        op.due_date,
        op.scheduled_amount,
        op.status as payment_status,
        (current_date > op.due_date and op.status not in ('paid', 'skipped')) as is_overdue
    from data.transactions t
    join data.obligation_payments op on op.transaction_id = t.id
    join data.obligations o on o.id = op.obligation_id
    where t.uuid = p_transaction_uuid
      and t.user_data = v_user_data
      and t.deleted_at is null;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- Function to unlink transaction from obligation payment
create or replace function api.unlink_transaction_from_obligation(
    p_transaction_uuid text
) returns boolean as $$
declare
    v_transaction_id bigint;
    v_user_data text;
begin
    v_user_data := utils.get_user();

    -- Get transaction ID
    select id into v_transaction_id
    from data.transactions
    where uuid = p_transaction_uuid
      and user_data = v_user_data
      and deleted_at is null;

    if v_transaction_id is null then
        raise exception 'Transaction not found';
    end if;

    -- Unlink and reset payment status to scheduled
    update data.obligation_payments
    set
        transaction_id = null,
        transaction_uuid = null,
        paid_date = null,
        actual_amount_paid = null,
        payment_account_id = null,
        payment_method = null,
        confirmation_number = null,
        days_late = null,
        status = 'scheduled',
        payment_marked_at = null,
        updated_at = now()
    where transaction_id = v_transaction_id
      and user_data = v_user_data;

    return true;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- Add index for transaction lookups in obligation_payments
create index if not exists idx_obligation_payments_transaction
    on data.obligation_payments(transaction_id)
    where transaction_id is not null;

-- +goose Down
drop index if exists idx_obligation_payments_transaction;
drop function if exists api.unlink_transaction_from_obligation(text);
drop function if exists api.get_transaction_obligation_info(text);
drop function if exists api.auto_link_transaction_to_obligation(text, integer);
drop function if exists api.get_pending_obligation_payments(text, integer, integer);
drop function if exists api.suggest_obligation_payments_for_transaction(text, integer, decimal);
