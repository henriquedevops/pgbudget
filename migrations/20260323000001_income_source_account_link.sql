-- +goose Up
-- Link income sources to the bank/salary account that receives the money.
--
-- Changes:
--   1. Add account_id column to data.income_sources (nullable FK → data.accounts)
--   2. Drop functions that return SETOF api.income_sources (view type dependency)
--   3. Drop api.income_sources view
--   4. Recreate view with account_uuid + account_name columns
--   5. Recreate api.get_income_sources, api.get_income_source,
--      api.create_income_source, api.update_income_source with p_account_uuid param

-- +goose StatementBegin
ALTER TABLE data.income_sources
    ADD COLUMN account_id bigint
        REFERENCES data.accounts(id) ON DELETE SET NULL;
-- +goose StatementEnd

-- +goose StatementBegin
DROP FUNCTION IF EXISTS api.create_income_source(text,text,bigint,date,text,text,text,text,text,integer,integer[],date,text,text,text,text);
DROP FUNCTION IF EXISTS api.update_income_source(text,text,text,text,text,bigint,text,text,integer,integer[],date,date,text,text,text,boolean,text);
DROP FUNCTION IF EXISTS api.get_income_sources(text);
DROP FUNCTION IF EXISTS api.get_income_source(text);
DROP VIEW  IF EXISTS api.income_sources;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE VIEW api.income_sources AS
SELECT
    i.uuid,
    i.name,
    i.description,
    i.income_type,
    i.income_subtype,
    i.amount,
    i.currency,
    i.frequency,
    i.pay_day_of_month,
    i.occurrence_months,
    i.start_date,
    i.end_date,
    c.uuid  AS default_category_uuid,
    c.name  AS default_category_name,
    i.employer_name,
    i.group_tag,
    i.is_active,
    i.notes,
    i.metadata,
    i.created_at,
    i.updated_at,
    l.uuid  AS ledger_uuid,
    a.uuid  AS account_uuid,
    a.name  AS account_name
FROM data.income_sources i
LEFT JOIN data.ledgers  l ON l.id = i.ledger_id
LEFT JOIN data.accounts c ON c.id = i.default_category_id
LEFT JOIN data.accounts a ON a.id = i.account_id
WHERE i.user_data = utils.get_user();

GRANT SELECT ON api.income_sources TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_income_sources(p_ledger_uuid text)
RETURNS SETOF api.income_sources
LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT v.*
    FROM api.income_sources v
    JOIN data.ledgers l ON l.uuid = p_ledger_uuid AND l.user_data = utils.get_user()
    JOIN data.income_sources i ON i.uuid = v.uuid AND i.ledger_id = l.id
    ORDER BY v.employer_name NULLS LAST, v.name;
$$;

GRANT EXECUTE ON FUNCTION api.get_income_sources(text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_income_source(p_income_uuid text)
RETURNS SETOF api.income_sources
LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT * FROM api.income_sources WHERE uuid = p_income_uuid;
$$;

GRANT EXECUTE ON FUNCTION api.get_income_source(text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.create_income_source(
    p_ledger_uuid          text,
    p_name                 text,
    p_amount               bigint,
    p_start_date           date,
    p_income_type          text    DEFAULT 'salary',
    p_income_subtype       text    DEFAULT NULL,
    p_description          text    DEFAULT NULL,
    p_currency             text    DEFAULT 'BRL',
    p_frequency            text    DEFAULT 'monthly',
    p_pay_day_of_month     integer DEFAULT NULL,
    p_occurrence_months    integer[] DEFAULT NULL,
    p_end_date             date    DEFAULT NULL,
    p_default_category_uuid text   DEFAULT NULL,
    p_employer_name        text    DEFAULT NULL,
    p_group_tag            text    DEFAULT NULL,
    p_notes                text    DEFAULT NULL,
    p_account_uuid         text    DEFAULT NULL
) RETURNS SETOF api.income_sources
LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE
    v_ledger_id   bigint;
    v_category_id bigint;
    v_account_id  bigint;
    v_user_data   text := utils.get_user();
BEGIN
    SELECT id INTO v_ledger_id FROM data.ledgers
    WHERE uuid = p_ledger_uuid AND user_data = v_user_data;
    IF v_ledger_id IS NULL THEN RAISE EXCEPTION 'Ledger not found'; END IF;

    IF p_default_category_uuid IS NOT NULL THEN
        SELECT id INTO v_category_id FROM data.accounts
        WHERE uuid = p_default_category_uuid AND user_data = v_user_data;
    END IF;

    IF p_account_uuid IS NOT NULL THEN
        SELECT id INTO v_account_id FROM data.accounts
        WHERE uuid = p_account_uuid AND user_data = v_user_data AND type = 'asset';
        IF v_account_id IS NULL THEN RAISE EXCEPTION 'Account not found or not an asset account'; END IF;
    END IF;

    INSERT INTO data.income_sources (
        ledger_id, name, description, income_type, income_subtype,
        amount, currency, frequency, pay_day_of_month, occurrence_months,
        start_date, end_date, default_category_id, employer_name,
        group_tag, notes, account_id
    ) VALUES (
        v_ledger_id, p_name, p_description, p_income_type, p_income_subtype,
        p_amount, p_currency, p_frequency, p_pay_day_of_month, p_occurrence_months,
        p_start_date, p_end_date, v_category_id, p_employer_name,
        p_group_tag, p_notes, v_account_id
    );

    RETURN QUERY SELECT * FROM api.income_sources
    WHERE uuid = (SELECT uuid FROM data.income_sources
                  WHERE ledger_id = v_ledger_id AND user_data = v_user_data
                  ORDER BY id DESC LIMIT 1);
END;
$$;

GRANT EXECUTE ON FUNCTION api.create_income_source(text,text,bigint,date,text,text,text,text,text,integer,integer[],date,text,text,text,text,text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.update_income_source(
    p_income_uuid          text,
    p_name                 text    DEFAULT NULL,
    p_description          text    DEFAULT NULL,
    p_income_type          text    DEFAULT NULL,
    p_income_subtype       text    DEFAULT NULL,
    p_amount               bigint  DEFAULT NULL,
    p_currency             text    DEFAULT NULL,
    p_frequency            text    DEFAULT NULL,
    p_pay_day_of_month     integer DEFAULT NULL,
    p_occurrence_months    integer[] DEFAULT NULL,
    p_start_date           date    DEFAULT NULL,
    p_end_date             date    DEFAULT NULL,
    p_default_category_uuid text   DEFAULT NULL,
    p_employer_name        text    DEFAULT NULL,
    p_group_tag            text    DEFAULT NULL,
    p_is_active            boolean DEFAULT NULL,
    p_notes                text    DEFAULT NULL,
    p_account_uuid         text    DEFAULT NULL
) RETURNS SETOF api.income_sources
LANGUAGE plpgsql SECURITY DEFINER AS $$
DECLARE
    v_category_id bigint;
    v_account_id  bigint;
    v_user_data   text := utils.get_user();
BEGIN
    IF p_default_category_uuid IS NOT NULL THEN
        SELECT id INTO v_category_id FROM data.accounts
        WHERE uuid = p_default_category_uuid AND user_data = v_user_data;
    END IF;

    -- NULL string sentinel: pass 'NULL' to explicitly clear the account link
    IF p_account_uuid IS NOT NULL AND p_account_uuid <> 'NULL' THEN
        SELECT id INTO v_account_id FROM data.accounts
        WHERE uuid = p_account_uuid AND user_data = v_user_data AND type = 'asset';
        IF v_account_id IS NULL THEN RAISE EXCEPTION 'Account not found or not an asset account'; END IF;
    END IF;

    UPDATE data.income_sources SET
        name                = COALESCE(p_name,                name),
        description         = COALESCE(p_description,         description),
        income_type         = COALESCE(p_income_type,         income_type),
        income_subtype      = COALESCE(p_income_subtype,      income_subtype),
        amount              = COALESCE(p_amount,              amount),
        currency            = COALESCE(p_currency,            currency),
        frequency           = COALESCE(p_frequency,           frequency),
        pay_day_of_month    = COALESCE(p_pay_day_of_month,    pay_day_of_month),
        occurrence_months   = COALESCE(p_occurrence_months,   occurrence_months),
        start_date          = COALESCE(p_start_date,          start_date),
        end_date            = COALESCE(p_end_date,            end_date),
        default_category_id = COALESCE(v_category_id,         default_category_id),
        employer_name       = COALESCE(p_employer_name,       employer_name),
        group_tag           = COALESCE(p_group_tag,           group_tag),
        is_active           = COALESCE(p_is_active,           is_active),
        notes               = COALESCE(p_notes,               notes),
        account_id          = CASE
            WHEN p_account_uuid = 'NULL' THEN NULL
            WHEN v_account_id IS NOT NULL THEN v_account_id
            ELSE account_id
        END
    WHERE uuid = p_income_uuid AND user_data = v_user_data;

    RETURN QUERY SELECT * FROM api.income_sources WHERE uuid = p_income_uuid;
END;
$$;

GRANT EXECUTE ON FUNCTION api.update_income_source(text,text,text,text,text,bigint,text,text,integer,integer[],date,date,text,text,text,boolean,text,text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose Down

-- +goose StatementBegin
DROP FUNCTION IF EXISTS api.create_income_source(text,text,bigint,date,text,text,text,text,text,integer,integer[],date,text,text,text,text,text);
DROP FUNCTION IF EXISTS api.update_income_source(text,text,text,text,text,bigint,text,text,integer,integer[],date,date,text,text,text,boolean,text,text);
DROP FUNCTION IF EXISTS api.get_income_sources(text);
DROP FUNCTION IF EXISTS api.get_income_source(text);
DROP VIEW IF EXISTS api.income_sources;
-- +goose StatementEnd

-- +goose StatementBegin
ALTER TABLE data.income_sources DROP COLUMN IF EXISTS account_id;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE VIEW api.income_sources AS
SELECT i.uuid, i.name, i.description, i.income_type, i.income_subtype, i.amount, i.currency, i.frequency, i.pay_day_of_month, i.occurrence_months, i.start_date, i.end_date, c.uuid AS default_category_uuid, c.name AS default_category_name, i.employer_name, i.group_tag, i.is_active, i.notes, i.metadata, i.created_at, i.updated_at, l.uuid AS ledger_uuid
FROM data.income_sources i LEFT JOIN data.ledgers l ON l.id = i.ledger_id LEFT JOIN data.accounts c ON c.id = i.default_category_id
WHERE i.user_data = utils.get_user();
GRANT SELECT ON api.income_sources TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_income_sources(p_ledger_uuid text) RETURNS SETOF api.income_sources LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT v.* FROM api.income_sources v JOIN data.ledgers l ON l.uuid = p_ledger_uuid AND l.user_data = utils.get_user() JOIN data.income_sources i ON i.uuid = v.uuid AND i.ledger_id = l.id ORDER BY v.employer_name NULLS LAST, v.name; $$;
GRANT EXECUTE ON FUNCTION api.get_income_sources(text) TO pgbudget_user;
CREATE OR REPLACE FUNCTION api.get_income_source(p_income_uuid text) RETURNS SETOF api.income_sources LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT * FROM api.income_sources WHERE uuid = p_income_uuid; $$;
GRANT EXECUTE ON FUNCTION api.get_income_source(text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.create_income_source(p_ledger_uuid text, p_name text, p_amount bigint, p_start_date date, p_income_type text DEFAULT 'salary', p_income_subtype text DEFAULT NULL, p_description text DEFAULT NULL, p_currency text DEFAULT 'BRL', p_frequency text DEFAULT 'monthly', p_pay_day_of_month integer DEFAULT NULL, p_occurrence_months integer[] DEFAULT NULL, p_end_date date DEFAULT NULL, p_default_category_uuid text DEFAULT NULL, p_employer_name text DEFAULT NULL, p_group_tag text DEFAULT NULL, p_notes text DEFAULT NULL) RETURNS SETOF api.income_sources LANGUAGE plpgsql SECURITY DEFINER AS $$ DECLARE v_ledger_id bigint; v_category_id bigint; v_user_data text := utils.get_user(); BEGIN SELECT id INTO v_ledger_id FROM data.ledgers WHERE uuid = p_ledger_uuid AND user_data = v_user_data; IF v_ledger_id IS NULL THEN RAISE EXCEPTION 'Ledger not found'; END IF; IF p_default_category_uuid IS NOT NULL THEN SELECT id INTO v_category_id FROM data.accounts WHERE uuid = p_default_category_uuid AND user_data = v_user_data; END IF; INSERT INTO data.income_sources (ledger_id,name,description,income_type,income_subtype,amount,currency,frequency,pay_day_of_month,occurrence_months,start_date,end_date,default_category_id,employer_name,group_tag,notes) VALUES (v_ledger_id,p_name,p_description,p_income_type,p_income_subtype,p_amount,p_currency,p_frequency,p_pay_day_of_month,p_occurrence_months,p_start_date,p_end_date,v_category_id,p_employer_name,p_group_tag,p_notes); RETURN QUERY SELECT * FROM api.income_sources WHERE uuid = (SELECT uuid FROM data.income_sources WHERE ledger_id = v_ledger_id AND user_data = v_user_data ORDER BY id DESC LIMIT 1); END; $$;
GRANT EXECUTE ON FUNCTION api.create_income_source(text,text,bigint,date,text,text,text,text,text,integer,integer[],date,text,text,text,text) TO pgbudget_user;
-- +goose StatementEnd

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.update_income_source(p_income_uuid text, p_name text DEFAULT NULL, p_description text DEFAULT NULL, p_income_type text DEFAULT NULL, p_income_subtype text DEFAULT NULL, p_amount bigint DEFAULT NULL, p_currency text DEFAULT NULL, p_frequency text DEFAULT NULL, p_pay_day_of_month integer DEFAULT NULL, p_occurrence_months integer[] DEFAULT NULL, p_start_date date DEFAULT NULL, p_end_date date DEFAULT NULL, p_default_category_uuid text DEFAULT NULL, p_employer_name text DEFAULT NULL, p_group_tag text DEFAULT NULL, p_is_active boolean DEFAULT NULL, p_notes text DEFAULT NULL) RETURNS SETOF api.income_sources LANGUAGE plpgsql SECURITY DEFINER AS $$ DECLARE v_category_id bigint; v_user_data text := utils.get_user(); BEGIN IF p_default_category_uuid IS NOT NULL THEN SELECT id INTO v_category_id FROM data.accounts WHERE uuid = p_default_category_uuid AND user_data = v_user_data; END IF; UPDATE data.income_sources SET name=COALESCE(p_name,name),description=COALESCE(p_description,description),income_type=COALESCE(p_income_type,income_type),income_subtype=COALESCE(p_income_subtype,income_subtype),amount=COALESCE(p_amount,amount),currency=COALESCE(p_currency,currency),frequency=COALESCE(p_frequency,frequency),pay_day_of_month=COALESCE(p_pay_day_of_month,pay_day_of_month),occurrence_months=COALESCE(p_occurrence_months,occurrence_months),start_date=COALESCE(p_start_date,start_date),end_date=COALESCE(p_end_date,end_date),default_category_id=COALESCE(v_category_id,default_category_id),employer_name=COALESCE(p_employer_name,employer_name),group_tag=COALESCE(p_group_tag,group_tag),is_active=COALESCE(p_is_active,is_active),notes=COALESCE(p_notes,notes) WHERE uuid=p_income_uuid AND user_data=v_user_data; RETURN QUERY SELECT * FROM api.income_sources WHERE uuid=p_income_uuid; END; $$;
GRANT EXECUTE ON FUNCTION api.update_income_source(text,text,text,text,text,bigint,text,text,integer,integer[],date,date,text,text,text,boolean,text) TO pgbudget_user;
-- +goose StatementEnd
