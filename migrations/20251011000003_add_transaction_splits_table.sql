-- +goose Up
-- +goose StatementBegin

-- table to store split transaction details
-- when a transaction is split across multiple categories, each split is stored here
create table data.transaction_splits (
    id bigint generated always as identity primary key,
    uuid text not null default utils.nanoid(8),

    parent_transaction_id bigint not null references data.transactions(id) on delete cascade,
    category_id bigint not null references data.accounts(id),
    amount bigint not null check (amount > 0),
    memo text,

    created_at timestamptz not null default current_timestamp,
    user_data text not null default utils.get_user(),

    constraint transaction_splits_uuid_unique unique (uuid),
    constraint transaction_splits_memo_length_check check (char_length(memo) < 255)
);

-- enable RLS
alter table data.transaction_splits
    enable row level security;

create policy transaction_splits_policy on data.transaction_splits
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());

-- index for faster lookups by parent transaction
create index transaction_splits_parent_transaction_id_idx
    on data.transaction_splits(parent_transaction_id);

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

drop index if exists transaction_splits_parent_transaction_id_idx;
drop policy if exists transaction_splits_policy on data.transaction_splits;
drop table if exists data.transaction_splits;

-- +goose StatementEnd
