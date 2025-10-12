-- +goose Up
-- Create payees table for Phase 3.3
create table data.payees (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),
    name text not null,
    default_category_id bigint references data.accounts(id) on delete set null,
    auto_categorize boolean not null default true,
    merged_into_id bigint references data.payees(id) on delete set null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    user_data text not null default utils.get_user(),

    constraint payees_uuid_unique unique (uuid),
    constraint payees_name_user_unique unique (name, user_data)
);

-- Create indexes
create index payees_user_data_idx on data.payees(user_data);
create index payees_name_idx on data.payees(name);
create index payees_merged_into_idx on data.payees(merged_into_id) where merged_into_id is not null;

-- Enable RLS
alter table data.payees enable row level security;

-- Create RLS policy
create policy payees_policy on data.payees
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- Create trigger for updated_at
create trigger payees_updated_at
    before update on data.payees
    for each row
    execute function utils.update_updated_at();

-- Add payee_id column to transactions table
alter table data.transactions
    add column payee_id bigint references data.payees(id) on delete set null;

-- Create index on payee_id
create index transactions_payee_id_idx on data.transactions(payee_id) where payee_id is not null;

-- +goose Down
-- Remove payee_id column from transactions
alter table data.transactions drop column if exists payee_id;

-- Drop payees table
drop table if exists data.payees cascade;
