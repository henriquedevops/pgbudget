-- +goose Up

-- Allow NULL password_hash for OAuth-only users
ALTER TABLE data.users DROP CONSTRAINT users_password_hash_check;
ALTER TABLE data.users ALTER COLUMN password_hash DROP NOT NULL;

-- Find or create a user by Google profile (returns user_uuid + username)
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.find_or_create_google_user(
    p_google_id   text,
    p_email       text,
    p_first_name  text,
    p_last_name   text,
    p_picture_url text DEFAULT NULL
)
RETURNS TABLE(user_uuid text, username text, is_new boolean)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = data, utils, public
AS $$
DECLARE
    v_uuid     text;
    v_username text;
    v_is_new   boolean := false;
    v_base     text;
    v_candidate text;
    v_suffix   int;
BEGIN
    -- 1. Lookup by google_id stored in metadata
    SELECT u.uuid, u.username
      INTO v_uuid, v_username
      FROM data.users u
     WHERE u.metadata->>'google_id' = p_google_id
     LIMIT 1;

    IF FOUND THEN
        RETURN QUERY SELECT v_uuid, v_username, false;
        RETURN;
    END IF;

    -- 2. Lookup by email (link existing account)
    SELECT u.uuid, u.username
      INTO v_uuid, v_username
      FROM data.users u
     WHERE lower(u.email) = lower(p_email)
     LIMIT 1;

    IF FOUND THEN
        -- Attach google_id to existing account
        UPDATE data.users
           SET metadata = coalesce(metadata, '{}'::jsonb)
                       || jsonb_build_object('google_id', p_google_id,
                                             'picture_url', p_picture_url)
         WHERE uuid = v_uuid;

        RETURN QUERY SELECT v_uuid, v_username, false;
        RETURN;
    END IF;

    -- 3. New user — auto-generate username from email prefix
    v_base := lower(regexp_replace(split_part(p_email, '@', 1), '[^a-z0-9]', '_', 'g'));
    v_base := left(v_base, 20);
    -- Ensure at least 3 chars
    IF length(v_base) < 3 THEN
        v_base := v_base || '_usr';
    END IF;
    v_candidate := v_base;

    -- Resolve username collisions
    v_suffix := 1000;
    WHILE EXISTS (SELECT 1 FROM data.users WHERE data.users.username = v_candidate) LOOP
        v_suffix    := v_suffix + floor(random() * 9000)::int;
        v_candidate := v_base || '_' || v_suffix::text;
    END LOOP;

    v_username := v_candidate;
    v_uuid     := utils.nanoid(8);
    v_is_new   := true;

    INSERT INTO data.users (uuid, username, email, password_hash, first_name, last_name, is_active, email_verified, metadata)
    VALUES (
        v_uuid,
        v_username,
        p_email,
        NULL,
        p_first_name,
        p_last_name,
        true,
        true,   -- email already verified by Google
        jsonb_build_object('google_id', p_google_id, 'picture_url', p_picture_url)
    );

    -- Create default ledger for new user (mirrors what register_user does)
    INSERT INTO data.ledgers (user_data, name, currency, is_default)
    VALUES (v_username, 'My Budget', 'USD', true);

    RETURN QUERY SELECT v_uuid, v_username, true;
END;
$$;
-- +goose StatementEnd

GRANT EXECUTE ON FUNCTION api.find_or_create_google_user(text, text, text, text, text) TO pgbudget_user;

-- +goose Down

REVOKE EXECUTE ON FUNCTION api.find_or_create_google_user(text, text, text, text, text) FROM pgbudget_user;
DROP FUNCTION IF EXISTS api.find_or_create_google_user(text, text, text, text, text);

-- Restore password_hash NOT NULL (only safe if no OAuth users exist)
ALTER TABLE data.users ALTER COLUMN password_hash SET NOT NULL;
ALTER TABLE data.users ADD CONSTRAINT users_password_hash_check CHECK (char_length(password_hash) >= 60);
