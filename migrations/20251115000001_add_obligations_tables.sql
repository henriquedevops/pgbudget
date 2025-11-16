-- +goose Up
-- Create obligations and obligation_payments tables for Phase 1

-- Create obligations table
create table data.obligations (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),
    user_data text not null default utils.get_user(),
    ledger_id bigint not null references data.ledgers(id) on delete cascade,

    -- Obligation Details
    name text not null,
    description text,
    obligation_type text not null, -- 'utility', 'rent', 'subscription', 'tuition', 'debt', 'insurance', 'tax', 'other'
    obligation_subtype text, -- specific type like 'electricity', 'netflix', 'student_loan'

    -- Payee Information
    payee_name text not null,
    payee_id bigint references data.payees(id) on delete set null,
    account_number text, -- utility account #, policy #, etc.

    -- Payment Account
    default_payment_account_id bigint references data.accounts(id) on delete set null,
    default_category_id bigint references data.accounts(id) on delete set null,

    -- Amount Details
    is_fixed_amount boolean not null default true,
    fixed_amount decimal(15,2),
    estimated_amount decimal(15,2), -- for variable bills
    amount_range_min decimal(15,2),
    amount_range_max decimal(15,2),
    currency text not null default 'USD',

    -- Frequency & Scheduling
    frequency text not null, -- 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom'
    custom_frequency_days integer, -- for custom frequency

    -- Due Date Pattern
    due_day_of_month integer, -- 1-31, or NULL for weekly/custom
    due_day_of_week integer, -- 0-6 (Sun-Sat) for weekly
    due_months integer[], -- for annual/semiannual (e.g., {1,7} for Jan and Jul)

    -- Start and End Dates
    start_date date not null,
    end_date date, -- NULL for indefinite

    -- Reminder Settings
    reminder_enabled boolean not null default true,
    reminder_days_before integer not null default 3,
    reminder_email boolean not null default true,
    reminder_dashboard boolean not null default true,

    -- Grace Period
    grace_period_days integer not null default 0,
    late_fee_amount decimal(15,2),

    -- Auto-Payment Settings
    auto_pay_enabled boolean not null default false,
    auto_create_transaction boolean not null default false, -- link to recurring transactions
    recurring_transaction_id bigint references data.recurring_transactions(id) on delete set null,

    -- Linked Resources
    linked_loan_id bigint references data.loans(id) on delete set null,
    linked_credit_card_id bigint references data.accounts(id) on delete set null,

    -- Status
    is_active boolean not null default true,
    is_paused boolean not null default false,
    pause_until date,

    -- Notes
    notes text,
    metadata jsonb not null default '{}'::jsonb,

    -- Audit
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),

    -- Constraints
    constraint obligations_uuid_unique unique (uuid),
    constraint obligations_valid_frequency check (
        frequency in ('weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom')
    ),
    constraint obligations_valid_due_day check (
        due_day_of_month is null or (due_day_of_month >= 1 and due_day_of_month <= 31)
    ),
    constraint obligations_valid_due_dow check (
        due_day_of_week is null or (due_day_of_week >= 0 and due_day_of_week <= 6)
    ),
    constraint obligations_amount_required check (
        is_fixed_amount = false or fixed_amount is not null
    ),
    constraint obligations_valid_type check (
        obligation_type in ('utility', 'housing', 'subscription', 'education', 'debt', 'insurance', 'tax', 'other')
    )
);

-- Create indexes for obligations
create index obligations_user_data_idx on data.obligations(user_data);
create index obligations_ledger_id_idx on data.obligations(ledger_id);
create index obligations_type_idx on data.obligations(obligation_type);
create index obligations_active_idx on data.obligations(is_active) where is_active = true;
create index obligations_payee_id_idx on data.obligations(payee_id) where payee_id is not null;
create index obligations_next_due_idx on data.obligations(user_data, is_active) where is_active = true;

-- Enable RLS on obligations
alter table data.obligations enable row level security;

-- Create RLS policy for obligations
create policy obligations_isolation on data.obligations
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Create trigger for updated_at on obligations
create trigger obligations_updated_at
    before update on data.obligations
    for each row
    execute function utils.update_updated_at();

-- Create obligation_payments table
create table data.obligation_payments (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),
    user_data text not null default utils.get_user(),

    -- Link to Obligation
    obligation_id bigint not null references data.obligations(id) on delete cascade,

    -- Payment Schedule
    due_date date not null,
    scheduled_amount decimal(15,2) not null,

    -- Payment Status
    status text not null default 'scheduled', -- 'scheduled', 'paid', 'partial', 'late', 'missed', 'skipped'

    -- Actual Payment Details
    paid_date date,
    actual_amount_paid decimal(15,2),
    transaction_id bigint references data.transactions(id) on delete set null,
    transaction_uuid text,

    -- Payment Method
    payment_account_id bigint references data.accounts(id) on delete set null,
    payment_method text, -- 'bank_transfer', 'credit_card', 'cash', 'check', 'autopay'
    confirmation_number text,

    -- Late Payment Tracking
    days_late integer,
    late_fee_charged decimal(15,2) not null default 0,
    late_fee_transaction_id bigint references data.transactions(id) on delete set null,

    -- Notes
    notes text,
    metadata jsonb not null default '{}'::jsonb,

    -- Audit
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    payment_marked_at timestamptz,

    -- Constraints
    constraint obligation_payments_uuid_unique unique (uuid),
    constraint obligation_payments_valid_status check (
        status in ('scheduled', 'paid', 'partial', 'late', 'missed', 'skipped')
    ),
    constraint obligation_payments_paid_requires_date check (
        status != 'paid' or paid_date is not null
    )
);

-- Create indexes for obligation_payments
create index obligation_payments_user_data_idx on data.obligation_payments(user_data);
create index obligation_payments_obligation_id_idx on data.obligation_payments(obligation_id);
create index obligation_payments_due_date_idx on data.obligation_payments(due_date);
create index obligation_payments_status_idx on data.obligation_payments(status);
create index obligation_payments_transaction_id_idx on data.obligation_payments(transaction_id) where transaction_id is not null;
create index obligation_payments_upcoming_idx on data.obligation_payments(user_data, due_date, status)
    where status in ('scheduled', 'partial');

-- Enable RLS on obligation_payments
alter table data.obligation_payments enable row level security;

-- Create RLS policy for obligation_payments
create policy obligation_payments_isolation on data.obligation_payments
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Create trigger for updated_at on obligation_payments
create trigger obligation_payments_updated_at
    before update on data.obligation_payments
    for each row
    execute function utils.update_updated_at();

-- +goose Down
-- Drop obligation_payments table
drop table if exists data.obligation_payments cascade;

-- Drop obligations table
drop table if exists data.obligations cascade;
