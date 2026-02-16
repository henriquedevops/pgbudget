-- +goose Up
-- Create projected_events table for one-time or irregular future financial events

-- +goose StatementBegin
create table data.projected_events (
    id              bigint generated always as identity primary key,
    uuid            text not null default utils.nanoid(8),
    user_data       text not null default utils.get_user(),
    ledger_id       bigint not null references data.ledgers(id) on delete cascade,

    -- Event details
    name            text not null,
    description     text,
    event_type      text not null default 'other',
    direction       text not null default 'outflow',

    -- Amount
    amount          numeric(15,2) not null,
    currency        text default 'BRL',

    -- When
    event_date      date not null,

    -- Category link
    default_category_id bigint references data.accounts(id) on delete set null,

    -- Status
    is_confirmed    boolean not null default false,
    is_realized     boolean not null default false,
    linked_transaction_id bigint references data.transactions(id) on delete set null,

    notes           text,
    metadata        jsonb default '{}'::jsonb,

    created_at      timestamptz not null default now(),
    updated_at      timestamptz not null default now(),

    constraint projected_events_uuid_unique unique (uuid),
    constraint projected_events_valid_type check (
        event_type in ('bonus', 'tax_refund', 'settlement', 'asset_sale', 'gift',
                        'large_purchase', 'vacation', 'medical', 'other')
    ),
    constraint projected_events_valid_direction check (
        direction in ('inflow', 'outflow')
    ),
    constraint projected_events_positive_amount check (amount > 0)
);
-- +goose StatementEnd

-- Indexes
create index projected_events_user_data_idx on data.projected_events(user_data);
create index projected_events_ledger_id_idx on data.projected_events(ledger_id);
create index projected_events_date_idx on data.projected_events(event_date);
create index projected_events_realized_idx on data.projected_events(is_realized) where is_realized = false;

-- RLS
alter table data.projected_events enable row level security;

create policy projected_events_isolation on data.projected_events
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Updated_at trigger
create trigger projected_events_updated_at
    before update on data.projected_events
    for each row
    execute function utils.update_updated_at();

-- +goose Down
drop trigger if exists projected_events_updated_at on data.projected_events;
drop policy if exists projected_events_isolation on data.projected_events;
drop table if exists data.projected_events;
