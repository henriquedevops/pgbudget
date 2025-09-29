-- +goose Up
-- +goose StatementBegin

-- Create users table for authentication
create table data.users
(
    id              bigint generated always as identity primary key,
    uuid            text        not null default utils.nanoid(8),

    created_at      timestamptz not null default current_timestamp,
    updated_at      timestamptz not null default current_timestamp,

    username        text        not null,
    email           text        not null,
    password_hash   text        not null,

    -- User profile information
    first_name      text,
    last_name       text,

    -- Account status
    is_active       boolean     not null default true,
    email_verified  boolean     not null default false,

    -- Metadata for additional user information
    metadata        jsonb,

    constraint users_uuid_unique unique (uuid),
    constraint users_username_unique unique (username),
    constraint users_email_unique unique (email),
    constraint users_username_length_check check (char_length(username) >= 3 AND char_length(username) <= 50),
    constraint users_email_length_check check (char_length(email) <= 255),
    constraint users_password_hash_check check (char_length(password_hash) >= 60), -- bcrypt hashes are 60 chars
    constraint users_first_name_length_check check (char_length(first_name) <= 100),
    constraint users_last_name_length_check check (char_length(last_name) <= 100),
    constraint users_username_format_check check (username ~ '^[a-zA-Z0-9_]+$') -- alphanumeric and underscore only
);

-- Add trigger for updated_at
create trigger users_set_updated_at
    before update on data.users
    for each row
    execute function utils.set_updated_at_fn();

-- Create API view for users (excluding sensitive information)
create or replace view api.users with (security_invoker = true) as
select u.uuid,
       u.username,
       u.email,
       u.first_name,
       u.last_name,
       u.is_active,
       u.email_verified,
       u.created_at,
       u.metadata
  from data.users u
 where u.username = utils.get_user(); -- Users can only see their own profile

comment on view api.users is 'Provides a secure view of user profiles. Users can only access their own information.';

-- Create authentication functions
create or replace function api.authenticate_user(p_username text, p_password text)
returns table(success boolean, user_uuid text, message text) as
$$
declare
    v_user_record record;
    v_password_valid boolean := false;
begin
    -- Find user by username
    select u.uuid, u.username, u.password_hash, u.is_active, u.email_verified
      into v_user_record
      from data.users u
     where u.username = p_username;

    if not found then
        return query select false, null::text, 'Invalid username or password'::text;
        return;
    end if;

    -- Check if user is active
    if not v_user_record.is_active then
        return query select false, null::text, 'Account is disabled'::text;
        return;
    end if;

    -- Verify password (in a real implementation, you'd use a proper password verification function)
    -- For now, we'll assume the password is already hashed when passed in
    if v_user_record.password_hash = crypt(p_password, v_user_record.password_hash) then
        v_password_valid := true;
    end if;

    if v_password_valid then
        return query select true, v_user_record.uuid, 'Authentication successful'::text;
    else
        return query select false, null::text, 'Invalid username or password'::text;
    end if;
end;
$$ language plpgsql security definer;

-- Create user registration function
create or replace function api.register_user(
    p_username text,
    p_email text,
    p_password text,
    p_first_name text default null,
    p_last_name text default null
)
returns table(success boolean, user_uuid text, message text) as
$$
declare
    v_user_uuid text;
    v_password_hash text;
begin
    -- Check if username already exists
    if exists (select 1 from data.users where username = p_username) then
        return query select false, null::text, 'Username already exists'::text;
        return;
    end if;

    -- Check if email already exists
    if exists (select 1 from data.users where email = p_email) then
        return query select false, null::text, 'Email already exists'::text;
        return;
    end if;

    -- Hash the password using PostgreSQL's crypt function
    v_password_hash := crypt(p_password, gen_salt('bf', 12));

    -- Insert new user
    insert into data.users (username, email, password_hash, first_name, last_name)
    values (p_username, p_email, v_password_hash, p_first_name, p_last_name)
    returning uuid into v_user_uuid;

    return query select true, v_user_uuid, 'User registered successfully'::text;
exception
    when others then
        return query select false, null::text, 'Registration failed: ' || SQLERRM;
end;
$$ language plpgsql security definer;

comment on function api.authenticate_user(text, text) is 'Authenticates a user with username and password. Returns success status, user UUID, and message.';
comment on function api.register_user(text, text, text, text, text) is 'Registers a new user. Returns success status, user UUID, and message.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

drop function if exists api.register_user(text, text, text, text, text);
drop function if exists api.authenticate_user(text, text);
drop view if exists api.users;
drop trigger if exists users_set_updated_at on data.users;
drop table if exists data.users;

-- +goose StatementEnd