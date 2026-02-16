-- +goose Up
-- Create payroll_deductions table for tracking recurring deductions from income (taxes, social security, health plan, etc.)

-- +goose StatementBegin
create table data.payroll_deductions (
    id              bigint generated always as identity primary key,
    uuid            text not null default utils.nanoid(8),
    user_data       text not null default utils.get_user(),
    ledger_id       bigint not null references data.ledgers(id) on delete cascade,

    -- Deduction details
    name            text not null,
    description     text,
    deduction_type  text not null default 'other',

    -- Amount
    is_fixed_amount boolean not null default true,
    fixed_amount    numeric(15,2),
    estimated_amount numeric(15,2),
    is_percentage   boolean not null default false,
    percentage_value numeric(8,4),
    percentage_base text,
    currency        text default 'BRL',

    -- Frequency & schedule
    frequency       text not null default 'monthly',
    occurrence_months integer[],

    -- Date range
    start_date      date not null,
    end_date        date,

    -- Category link
    default_category_id bigint references data.accounts(id) on delete set null,

    -- Grouping
    employer_name   text,
    group_tag       text,

    -- Status
    is_active       boolean not null default true,
    notes           text,
    metadata        jsonb default '{}'::jsonb,

    created_at      timestamptz not null default now(),
    updated_at      timestamptz not null default now(),

    constraint payroll_deductions_uuid_unique unique (uuid),
    constraint payroll_deductions_valid_type check (
        deduction_type in ('tax', 'social_security', 'health_plan', 'pension_fund',
                           'union_dues', 'donation', 'loan_repayment', 'other')
    ),
    constraint payroll_deductions_valid_frequency check (
        frequency in ('monthly', 'biweekly', 'weekly', 'annual', 'semiannual')
    ),
    constraint payroll_deductions_amount_required check (
        is_fixed_amount = false or fixed_amount is not null
    ),
    constraint payroll_deductions_percentage_check check (
        is_percentage = false or (percentage_value is not null and percentage_base is not null)
    )
);
-- +goose StatementEnd

-- Indexes
create index payroll_deductions_user_data_idx on data.payroll_deductions(user_data);
create index payroll_deductions_ledger_id_idx on data.payroll_deductions(ledger_id);
create index payroll_deductions_active_idx on data.payroll_deductions(is_active) where is_active = true;
create index payroll_deductions_type_idx on data.payroll_deductions(deduction_type);

-- RLS
alter table data.payroll_deductions enable row level security;

create policy payroll_deductions_isolation on data.payroll_deductions
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Updated_at trigger
create trigger payroll_deductions_updated_at
    before update on data.payroll_deductions
    for each row
    execute function utils.update_updated_at();

-- +goose Down
drop trigger if exists payroll_deductions_updated_at on data.payroll_deductions;
drop policy if exists payroll_deductions_isolation on data.payroll_deductions;
drop table if exists data.payroll_deductions;
