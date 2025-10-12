-- +goose Up
-- Add notification preferences for recurring transaction emails

-- +goose StatementBegin
-- Create notification_preferences table
create table data.notification_preferences
(
    id                                    bigint generated always as identity primary key,
    uuid                                  text        not null default utils.nanoid(8),
    created_at                            timestamptz not null default current_timestamp,
    updated_at                            timestamptz not null default current_timestamp,

    -- link to user
    username                              text        not null,

    -- notification settings
    email_on_recurring_transaction        boolean     not null default true,
    email_daily_summary                   boolean     not null default false,
    email_weekly_summary                  boolean     not null default false,
    email_on_recurring_transaction_failed boolean     not null default true,

    -- constraints
    constraint notification_preferences_uuid_unique unique (uuid),
    constraint notification_preferences_username_unique unique (username)
);
-- +goose StatementEnd

-- +goose StatementBegin
-- create index for username lookups
create index notification_preferences_username_idx on data.notification_preferences (username);
-- +goose StatementEnd

-- +goose StatementBegin
-- enable row level security
alter table data.notification_preferences enable row level security;
-- +goose StatementEnd

-- +goose StatementBegin
-- create rls policy for notification_preferences
-- Users can only see and modify their own preferences
create policy notification_preferences_policy on data.notification_preferences
    using (username = utils.get_user())
    with check (username = utils.get_user());
-- +goose StatementEnd

-- +goose StatementBegin
-- create trigger to update updated_at timestamp
create trigger notification_preferences_updated_at_trigger
    before update on data.notification_preferences
    for each row
    execute procedure utils.set_updated_at_fn();
-- +goose StatementEnd

-- +goose StatementBegin
-- create comment on table
comment on table data.notification_preferences is 'User notification preferences for email alerts';
-- +goose StatementEnd

-- +goose StatementBegin
-- create comments on columns
comment on column data.notification_preferences.email_on_recurring_transaction is 'Send email when recurring transaction is auto-created';
comment on column data.notification_preferences.email_daily_summary is 'Send daily summary of all auto-created transactions';
comment on column data.notification_preferences.email_weekly_summary is 'Send weekly summary of all auto-created transactions';
comment on column data.notification_preferences.email_on_recurring_transaction_failed is 'Send email when recurring transaction creation fails';
-- +goose StatementEnd

-- +goose StatementBegin
-- create API function to get notification preferences
create or replace function api.get_notification_preferences()
returns table (
    uuid text,
    email_on_recurring_transaction boolean,
    email_daily_summary boolean,
    email_weekly_summary boolean,
    email_on_recurring_transaction_failed boolean,
    created_at timestamptz,
    updated_at timestamptz
) as $$
begin
    -- create preferences if they don't exist
    insert into data.notification_preferences (username)
    values (utils.get_user())
    on conflict (username) do nothing;

    return query
    select
        np.uuid,
        np.email_on_recurring_transaction,
        np.email_daily_summary,
        np.email_weekly_summary,
        np.email_on_recurring_transaction_failed,
        np.created_at,
        np.updated_at
    from data.notification_preferences np
    where np.username = utils.get_user();
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose StatementBegin
-- create API function to update notification preferences
create or replace function api.update_notification_preferences(
    p_email_on_recurring_transaction boolean default null,
    p_email_daily_summary boolean default null,
    p_email_weekly_summary boolean default null,
    p_email_on_recurring_transaction_failed boolean default null
) returns boolean as $$
declare
    v_updated boolean;
begin
    -- create preferences if they don't exist
    insert into data.notification_preferences (username)
    values (utils.get_user())
    on conflict (username) do nothing;

    -- update preferences
    update data.notification_preferences
    set email_on_recurring_transaction = coalesce(p_email_on_recurring_transaction, email_on_recurring_transaction),
        email_daily_summary = coalesce(p_email_daily_summary, email_daily_summary),
        email_weekly_summary = coalesce(p_email_weekly_summary, email_weekly_summary),
        email_on_recurring_transaction_failed = coalesce(p_email_on_recurring_transaction_failed, email_on_recurring_transaction_failed),
        updated_at = current_timestamp
    where username = utils.get_user();

    v_updated := found;
    return v_updated;
end;
$$ language plpgsql security definer;
-- +goose StatementEnd

-- +goose Down
-- Remove notification preferences

-- +goose StatementBegin
drop function if exists api.update_notification_preferences(boolean, boolean, boolean, boolean);
-- +goose StatementEnd

-- +goose StatementBegin
drop function if exists api.get_notification_preferences();
-- +goose StatementEnd

-- +goose StatementBegin
drop trigger if exists notification_preferences_updated_at_trigger on data.notification_preferences;
-- +goose StatementEnd

-- +goose StatementBegin
drop policy if exists notification_preferences_policy on data.notification_preferences;
-- +goose StatementEnd

-- +goose StatementBegin
drop index if exists data.notification_preferences_username_idx;
-- +goose StatementEnd

-- +goose StatementBegin
drop table if exists data.notification_preferences;
-- +goose StatementEnd
