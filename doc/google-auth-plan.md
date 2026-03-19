# Google OAuth Integration Plan

## Overview

Integrate Google Sign-In (OAuth 2.0) as an alternative to username/password authentication.
Uses server-side OAuth flow with PHP cURL — no external libraries required.
Google user identity is stored in the `metadata` jsonb column on `data.users`.

---

## Challenges & Solutions

| Problem | Solution |
|---|---|
| `password_hash` has a `MIN LENGTH 60` CHECK constraint | Relax constraint to allow `NULL` for OAuth-only users |
| `username` is required (alphanumeric, 3–50 chars), but Google doesn't provide one | Auto-generate from email prefix + random 4-digit suffix |
| Email already registered via password auth | Match on email and attach `google_id` to existing account |

---

## Step 1 — Google Cloud Setup (manual, one-time)

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and create a project.
2. Enable **Google Identity API**.
3. Under **APIs & Services → Credentials**, create an **OAuth 2.0 Client ID** (Web application).
4. Add authorized redirect URI:
   ```
   https://yourdomain/pgbudget/public/auth/google-callback.php
   ```
5. Copy **Client ID** and **Client Secret**.

---

## Step 2 — Config / Environment

Add to `.env`:
```
GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxx
```

New file `config/google.php` — reads those env vars and exposes a `$google_cfg` array
used by the OAuth endpoints.

---

## Step 3 — Database Migration

File: `migrations/YYYYMMDD000000_google_auth.sql`

```sql
-- 1. Allow NULL password_hash for OAuth users
ALTER TABLE data.users DROP CONSTRAINT IF EXISTS users_password_hash_check;
ALTER TABLE data.users ALTER COLUMN password_hash DROP NOT NULL;

-- 2. New function to find or create a user by Google profile
CREATE OR REPLACE FUNCTION api.find_or_create_google_user(
    p_google_id   text,
    p_email       text,
    p_first_name  text,
    p_last_name   text,
    p_picture_url text DEFAULT NULL
) RETURNS TABLE(user_uuid text, username text, is_new boolean)
```

### Function logic (priority order):
1. Search by `metadata->>'google_id'` → found: return user
2. Search by `email` → found: attach google_id to metadata, return user
3. Neither: auto-generate username, create user with `password_hash = NULL`

### Username auto-generation:
```
base      = regexp_replace(email_prefix, '[^a-z0-9]', '_', 'g')
candidate = left(base, 20)
if taken  → append '_' || floor(random()*9000+1000)::text
```

---

## Step 4 — New PHP Endpoints

### `public/auth/google.php` — Initiates OAuth flow
- Generates a random `state` token for CSRF protection, stores in `$_SESSION['oauth_state']`
- Redirects to Google's authorization URL with scopes: `openid email profile`

### `public/auth/google-callback.php` — Handles Google redirect
1. Validate `$_GET['state']` matches `$_SESSION['oauth_state']` (CSRF check)
2. Exchange `$_GET['code']` for tokens via POST to `https://oauth2.googleapis.com/token`
3. Fetch user profile from `https://www.googleapis.com/oauth2/v3/userinfo`
4. Call `api.find_or_create_google_user()` with the profile data
5. Set session identical to normal login flow; redirect to dashboard

---

## Step 5 — UI Changes

Add a **"Continue with Google"** button to:
- `public/auth/login.php`
- `public/auth/register.php`

Button is a plain `<a>` link pointing to `/pgbudget/public/auth/google.php`,
styled with the official Google branding guidelines (white button, Google logo SVG).

---

## File Checklist

| File | Action |
|---|---|
| `.env` | Add `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| `config/google.php` | **New** — reads Google credentials from `.env` |
| `migrations/YYYYMMDD_google_auth.sql` | **New** — DB constraint + `find_or_create_google_user()` |
| `public/auth/google.php` | **New** — OAuth redirect initiator |
| `public/auth/google-callback.php` | **New** — OAuth callback handler |
| `public/auth/login.php` | **Edit** — add Google sign-in button |
| `public/auth/register.php` | **Edit** — add Google sign-up button |

---

## Security Notes

- `state` parameter prevents CSRF on the callback
- `google_id` is the primary link — email matching is a fallback (with merge, not duplicate)
- OAuth-only users have `password_hash = NULL`; the login form should not accept blank passwords (already enforced by `api.authenticate_user`)
- Store no Google tokens server-side — only the immutable `google_id` from the ID token
