-- +goose Up
-- +goose StatementBegin

-- Fix: Resolve ambiguous column reference in utils.get_categories_by_group
-- The original function had an ambiguous reference to 'parent_id' in the ORDER BY clause
-- This migration fixes that by properly qualifying the column with the alias 'ct'

CREATE OR REPLACE FUNCTION utils.get_categories_by_group(
    p_ledger_id bigint,
    p_user_data text
) RETURNS TABLE (
    category_id bigint,
    category_uuid text,
    category_name text,
    is_group boolean,
    parent_id bigint,
    parent_uuid text,
    parent_name text,
    sort_order integer,
    level integer
) AS $func$
BEGIN
    RETURN QUERY
    WITH RECURSIVE category_tree AS (
        -- Top level: groups and ungrouped categories
        SELECT
            c.id AS category_id,
            c.uuid AS category_uuid,
            c.name AS category_name,
            c.is_group,
            c.parent_category_id AS parent_id,
            CAST(NULL AS text) AS parent_uuid,
            CAST(NULL AS text) AS parent_name,
            c.sort_order,
            0 AS level
        FROM data.accounts c
        WHERE c.ledger_id = p_ledger_id
          AND c.user_data = p_user_data
          AND c.type = 'equity'
          AND c.parent_category_id IS NULL
          AND c.name NOT IN ('Income', 'Unassigned', 'Off-budget')

        UNION ALL

        -- Child categories
        SELECT
            c.id AS category_id,
            c.uuid AS category_uuid,
            c.name AS category_name,
            c.is_group,
            c.parent_category_id AS parent_id,
            p.uuid AS parent_uuid,
            p.name AS parent_name,
            c.sort_order,
            ct.level + 1 AS level
        FROM data.accounts c
        JOIN category_tree ct ON c.parent_category_id = ct.category_id
        JOIN data.accounts p ON c.parent_category_id = p.id
        WHERE c.user_data = p_user_data
          AND c.type = 'equity'
    )
    SELECT
        ct.category_id,
        ct.category_uuid,
        ct.category_name,
        ct.is_group,
        ct.parent_id,
        ct.parent_uuid,
        ct.parent_name,
        ct.sort_order,
        ct.level
    FROM category_tree ct
    ORDER BY
        COALESCE(ct.parent_id, ct.category_id),
        CASE WHEN ct.is_group THEN 0 ELSE 1 END,
        ct.sort_order,
        ct.category_name;
END;
$func$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

COMMENT ON FUNCTION utils.get_categories_by_group IS
'Get all categories organized hierarchically by groups. Returns tree structure with levels. Fixed ORDER BY clause to use qualified column names.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Revert to original (broken) version
CREATE OR REPLACE FUNCTION utils.get_categories_by_group(
    p_ledger_id bigint,
    p_user_data text
) RETURNS TABLE (
    category_id bigint,
    category_uuid text,
    category_name text,
    is_group boolean,
    parent_id bigint,
    parent_uuid text,
    parent_name text,
    sort_order integer,
    level integer
) AS $func$
BEGIN
    RETURN QUERY
    WITH RECURSIVE category_tree AS (
        -- Top level: groups and ungrouped categories
        SELECT
            c.id AS category_id,
            c.uuid AS category_uuid,
            c.name AS category_name,
            c.is_group,
            c.parent_category_id AS parent_id,
            CAST(NULL AS text) AS parent_uuid,
            CAST(NULL AS text) AS parent_name,
            c.sort_order,
            0 AS level
        FROM data.accounts c
        WHERE c.ledger_id = p_ledger_id
          AND c.user_data = p_user_data
          AND c.type = 'equity'
          AND c.parent_category_id IS NULL
          AND c.name NOT IN ('Income', 'Unassigned', 'Off-budget')

        UNION ALL

        -- Child categories
        SELECT
            c.id AS category_id,
            c.uuid AS category_uuid,
            c.name AS category_name,
            c.is_group,
            c.parent_category_id AS parent_id,
            p.uuid AS parent_uuid,
            p.name AS parent_name,
            c.sort_order,
            ct.level + 1 AS level
        FROM data.accounts c
        JOIN category_tree ct ON c.parent_category_id = ct.category_id
        JOIN data.accounts p ON c.parent_category_id = p.id
        WHERE c.user_data = p_user_data
          AND c.type = 'equity'
    )
    SELECT * FROM category_tree
    ORDER BY
        COALESCE(parent_id, category_id),
        CASE WHEN is_group THEN 0 ELSE 1 END,
        sort_order,
        category_name;
END;
$func$ LANGUAGE plpgsql SECURITY DEFINER STABLE;

-- +goose StatementEnd
