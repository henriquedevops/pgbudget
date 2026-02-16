-- +goose Up
-- Create income_sources table for tracking recurring income components (salary, benefits, bonuses, etc.)

-- +goose StatementBegin
create table data.income_sources (
    id              bigint generated always as identity primary key,
    uuid            text not null default utils.nanoid(8),
    user_data       text not null default utils.get_user(),
    ledger_id       bigint not null references data.ledgers(id) on delete cascade,

    -- Income details
    name            text not null,
    description     text,
    income_type     text not null default 'salary',
    income_subtype  text,

    -- Amount
    amount          numeric(15,2) not null,
    currency        text default 'BRL',

    -- Frequency & schedule
    frequency       text not null default 'monthly',
    pay_day_of_month integer,
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

    constraint income_sources_uuid_unique unique (uuid),
    constraint income_sources_valid_type check (
        income_type in ('salary', 'bonus', 'benefit', 'freelance', 'rental', 'investment', 'other')
    ),
    constraint income_sources_valid_frequency check (
        frequency in ('monthly', 'biweekly', 'weekly', 'annual', 'semiannual', 'one_time')
    ),
    constraint income_sources_valid_pay_day check (
        pay_day_of_month is null or (pay_day_of_month >= 1 and pay_day_of_month <= 31)
    ),
    constraint income_sources_positive_amount check (amount > 0)
);
-- +goose StatementEnd

-- Indexes
create index income_sources_user_data_idx on data.income_sources(user_data);
create index income_sources_ledger_id_idx on data.income_sources(ledger_id);
create index income_sources_active_idx on data.income_sources(is_active) where is_active = true;
create index income_sources_type_idx on data.income_sources(income_type);

-- RLS
alter table data.income_sources enable row level security;

create policy income_sources_isolation on data.income_sources
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Updated_at trigger
create trigger income_sources_updated_at
    before update on data.income_sources
    for each row
    execute function utils.update_updated_at();

-- +goose Down
drop trigger if exists income_sources_updated_at on data.income_sources;
drop policy if exists income_sources_isolation on data.income_sources;
drop table if exists data.income_sources;
