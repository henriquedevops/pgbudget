-- +goose Up
-- Utility function to project loan amortization schedules (Price/SAC system)
-- Computes month-by-month principal, interest, and remaining balance for active loans

-- +goose StatementBegin
create or replace function utils.project_loan_amortization(
    p_loan_id bigint,
    p_start_month date,
    p_months_ahead integer
) returns table (
    month date,
    payment_number integer,
    amortization numeric(19,4),
    interest numeric(19,4),
    total_payment numeric(19,4),
    remaining_balance numeric(19,4)
) as $$
declare
    v_loan record;
    v_balance numeric(19,4);
    v_monthly_rate numeric(19,8);
    v_payment numeric(19,4);
    v_interest_amt numeric(19,4);
    v_principal_amt numeric(19,4);
    v_current_month date;
    v_end_month date;
    v_payment_num integer;
    v_initial_payments_made integer;
    v_first_projection_month date;
begin
    -- Get loan details
    select l.* into v_loan
    from data.loans l
    where l.id = p_loan_id;

    if not found then
        return;
    end if;

    -- Only project active loans
    if v_loan.status != 'active' then
        return;
    end if;

    -- Calculate monthly interest rate
    -- interest_rate is stored as annual percentage (e.g., 12.0 for 12%)
    v_monthly_rate := v_loan.interest_rate / 100.0 / 12.0;

    -- Determine starting balance and payment number
    -- Check how many payments have been made (from loan_payments table)
    select count(*) into v_initial_payments_made
    from data.loan_payments lp
    where lp.loan_id = p_loan_id
      and lp.status = 'paid';

    -- Use current_balance from the loan record as starting point
    v_balance := v_loan.current_balance;
    v_payment := v_loan.payment_amount;
    v_payment_num := v_initial_payments_made;

    -- Determine the projection start: the later of p_start_month or the loan's first payment
    v_first_projection_month := greatest(
        date_trunc('month', p_start_month)::date,
        date_trunc('month', v_loan.first_payment_date)::date
    );
    v_end_month := (p_start_month + (p_months_ahead || ' months')::interval)::date;

    v_current_month := v_first_projection_month;

    -- Skip months that already have paid loan_payments
    -- by advancing to after the last paid payment
    declare
        v_last_paid_date date;
    begin
        select max(lp.due_date) into v_last_paid_date
        from data.loan_payments lp
        where lp.loan_id = p_loan_id
          and lp.status = 'paid';

        if v_last_paid_date is not null and v_last_paid_date >= v_current_month then
            v_current_month := (date_trunc('month', v_last_paid_date) + interval '1 month')::date;
        end if;
    end;

    -- Project future payments
    while v_current_month <= v_end_month and v_balance > 0.01 loop
        v_payment_num := v_payment_num + 1;

        -- Calculate interest for this period
        v_interest_amt := round(v_balance * v_monthly_rate, 4);

        -- Handle different amortization types
        if v_loan.amortization_type = 'interest_only' then
            -- Interest-only: no principal reduction
            v_principal_amt := 0;
            month := v_current_month;
            payment_number := v_payment_num;
            amortization := v_principal_amt;
            interest := v_interest_amt;
            total_payment := v_interest_amt;
            remaining_balance := v_balance;
            return next;
        else
            -- Standard amortization (Price system)
            -- If payment covers more than remaining balance + interest, adjust
            if v_payment >= v_balance + v_interest_amt then
                v_principal_amt := v_balance;
                v_balance := 0;
            else
                v_principal_amt := v_payment - v_interest_amt;
                -- Guard against negative principal (rate too high for payment)
                if v_principal_amt < 0 then
                    v_principal_amt := 0;
                end if;
                v_balance := v_balance - v_principal_amt;
            end if;

            month := v_current_month;
            payment_number := v_payment_num;
            amortization := v_principal_amt;
            interest := v_interest_amt;
            total_payment := v_principal_amt + v_interest_amt;
            remaining_balance := v_balance;
            return next;
        end if;

        v_current_month := (v_current_month + interval '1 month')::date;
    end loop;

    return;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
drop function if exists utils.project_loan_amortization(bigint, date, integer);
