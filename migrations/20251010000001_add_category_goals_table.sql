-- +goose Up
-- Phase 2.1: Add category_goals table to support goal-based budgeting (YNAB Rule 2)
-- This enables monthly funding goals, target balance goals, and target by date goals

-- +goose StatementBegin
-- create category_goals table in data schema
create table data.category_goals
(
    id               bigint generated always as identity primary key,
    uuid             text        not null default utils.nanoid(8),

    created_at       timestamptz not null default current_timestamp,
    updated_at       timestamptz not null default current_timestamp,

    -- link to category (equity account)
    category_id      bigint      not null,

    -- goal type: monthly_funding, target_balance, target_by_date
    goal_type        text        not null,

    -- target amount in cents
    target_amount    bigint      not null,

    -- target date (required for target_by_date, optional for others)
    target_date      date,

    -- repeat frequency (for recurring goals)
    repeat_frequency text,

    -- user ownership
    user_data        text        not null default utils.get_user(),

    -- constraints
    constraint category_goals_uuid_unique unique (uuid),
    constraint category_goals_one_per_category unique (category_id),
    constraint category_goals_category_fk foreign key (category_id)
        references data.accounts (id) on delete cascade,
    constraint category_goals_goal_type_check check (goal_type in ('monthly_funding', 'target_balance', 'target_by_date')),
    constraint category_goals_target_amount_check check (target_amount > 0),
    constraint category_goals_repeat_frequency_check check (repeat_frequency is null or repeat_frequency in ('weekly', 'monthly', 'yearly')),
    constraint category_goals_target_date_required_check check (
        (goal_type = 'target_by_date' and target_date is not null) or
        (goal_type != 'target_by_date')
    )
);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for category lookups
create index category_goals_category_id_idx on data.category_goals (category_id);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for user data filtering
create index category_goals_user_data_idx on data.category_goals (user_data);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for goal type queries
create index category_goals_goal_type_idx on data.category_goals (goal_type);
-- +goose StatementEnd

-- +goose StatementBegin
-- enable row level security
alter table data.category_goals enable row level security;
-- +goose StatementEnd

-- +goose StatementBegin
-- create rls policy for category_goals
create policy category_goals_policy on data.category_goals
    using (user_data = utils.get_user())
    with check (user_data = utils.get_user());
-- +goose StatementEnd

-- +goose StatementBegin
-- create trigger to update updated_at timestamp
create trigger category_goals_updated_at_trigger
    before update on data.category_goals
    for each row
    execute procedure utils.set_updated_at_fn();
-- +goose StatementEnd

-- +goose StatementBegin
-- create comment on table
comment on table data.category_goals is 'Budget category goals for planning irregular expenses (YNAB Rule 2: Embrace Your True Expenses)';
-- +goose StatementEnd

-- +goose StatementBegin
-- create comments on columns
comment on column data.category_goals.goal_type is 'Type of goal: monthly_funding (budget X every month), target_balance (save X total), target_by_date (save X by specific date)';
comment on column data.category_goals.target_amount is 'Target amount in cents';
comment on column data.category_goals.target_date is 'Target date for target_by_date goals';
comment on column data.category_goals.repeat_frequency is 'Repeat frequency for recurring goals (weekly, monthly, yearly)';
-- +goose StatementEnd

-- +goose Down
-- Remove category_goals table and related objects

-- +goose StatementBegin
drop trigger if exists category_goals_updated_at_trigger on data.category_goals;
-- +goose StatementEnd

-- +goose StatementBegin
drop policy if exists category_goals_policy on data.category_goals;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.category_goals_goal_type_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.category_goals_user_data_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.category_goals_category_id_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop table if exists data.category_goals;
-- +goose StatementEnd
