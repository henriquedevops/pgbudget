-- +goose Up
-- Phase 3.2: Add recurring_transactions table to support scheduled repeating transactions
-- This enables users to schedule transactions that repeat on a regular basis (rent, salary, subscriptions, etc.)

-- +goose StatementBegin
-- create recurring_transactions table in data schema
create table data.recurring_transactions
(
    id               bigint generated always as identity primary key,
    uuid             text        not null default utils.nanoid(8),

    created_at       timestamptz not null default current_timestamp,
    updated_at       timestamptz not null default current_timestamp,

    -- link to ledger
    ledger_id        bigint      not null,

    -- transaction details
    description      text        not null,
    amount           bigint      not null,

    -- recurring schedule
    frequency        text        not null,
    next_date        date        not null,
    end_date         date,

    -- accounts involved
    account_id       bigint      not null,
    category_id      bigint,

    -- transaction type
    transaction_type text        not null,

    -- automation settings
    auto_create      boolean     not null default false,
    enabled          boolean     not null default true,

    -- user ownership
    user_data        text        not null default utils.get_user(),

    -- constraints
    constraint recurring_transactions_uuid_unique unique (uuid),
    constraint recurring_transactions_ledger_fk foreign key (ledger_id)
        references data.ledgers (id) on delete cascade,
    constraint recurring_transactions_account_fk foreign key (account_id)
        references data.accounts (id) on delete cascade,
    constraint recurring_transactions_category_fk foreign key (category_id)
        references data.accounts (id) on delete set null,
    constraint recurring_transactions_frequency_check check (frequency in ('daily', 'weekly', 'biweekly', 'monthly', 'yearly')),
    constraint recurring_transactions_type_check check (transaction_type in ('inflow', 'outflow')),
    constraint recurring_transactions_amount_check check (amount > 0),
    constraint recurring_transactions_end_date_check check (end_date is null or end_date >= next_date)
);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for ledger lookups
create index recurring_transactions_ledger_id_idx on data.recurring_transactions (ledger_id);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for user data filtering
create index recurring_transactions_user_data_idx on data.recurring_transactions (user_data);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for next_date queries (for finding due transactions)
create index recurring_transactions_next_date_idx on data.recurring_transactions (next_date) where enabled = true;
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for account queries
create index recurring_transactions_account_id_idx on data.recurring_transactions (account_id);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for category queries
create index recurring_transactions_category_id_idx on data.recurring_transactions (category_id);
-- +goose StatementEnd

-- +goose StatementBegin
-- enable row level security
alter table data.recurring_transactions enable row level security;
-- +goose StatementEnd

-- +goose StatementBegin
-- create rls policy for recurring_transactions
create policy recurring_transactions_policy on data.recurring_transactions
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());
-- +goose StatementEnd

-- +goose StatementBegin
-- create trigger to update updated_at timestamp
create trigger recurring_transactions_updated_at_trigger
    before update on data.recurring_transactions
    for each row
    execute procedure utils.set_updated_at_fn();
-- +goose StatementEnd

-- +goose StatementBegin
-- create comment on table
comment on table data.recurring_transactions is 'Scheduled repeating transactions (rent, salary, subscriptions, etc.)';
-- +goose StatementEnd

-- +goose StatementBegin
-- create comments on columns
comment on column data.recurring_transactions.frequency is 'How often the transaction repeats: daily, weekly, biweekly, monthly, yearly';
comment on column data.recurring_transactions.next_date is 'The next date this transaction should be created';
comment on column data.recurring_transactions.end_date is 'Optional end date for the recurring schedule';
comment on column data.recurring_transactions.transaction_type is 'Type of transaction: inflow (income) or outflow (expense)';
comment on column data.recurring_transactions.auto_create is 'If true, transactions are created automatically when due. If false, user must manually create them.';
comment on column data.recurring_transactions.enabled is 'If false, this recurring transaction is paused and will not create transactions';
-- +goose StatementEnd

-- +goose Down
-- Remove recurring_transactions table and related objects

-- +goose StatementBegin
drop trigger if exists recurring_transactions_updated_at_trigger on data.recurring_transactions;
-- +goose StatementEnd

-- +goose StatementBegin
drop policy if exists recurring_transactions_policy on data.recurring_transactions;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.recurring_transactions_category_id_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.recurring_transactions_account_id_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.recurring_transactions_next_date_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.recurring_transactions_user_data_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.recurring_transactions_ledger_id_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop table if exists data.recurring_transactions;
-- +goose StatementEnd
