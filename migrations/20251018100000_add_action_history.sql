-- +goose Up
-- +goose StatementBegin
-- Migration: Add action history table for undo functionality
-- Description: Track user actions for undo/redo and audit trail (Phase 6.6)

-- Create action_history table
CREATE TABLE data.action_history (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid text NOT NULL DEFAULT utils.nanoid(8),
    ledger_id bigint REFERENCES data.ledgers(id) ON DELETE CASCADE,
    action_type text NOT NULL CHECK (action_type IN (
        'create', 'update', 'delete',
        'assign', 'move', 'transfer',
        'bulk_categorize', 'bulk_delete', 'bulk_update'
    )),
    entity_type text NOT NULL CHECK (entity_type IN (
        'transaction', 'category', 'account', 'assignment', 'budget'
    )),
    entity_id bigint,
    entity_uuid text,
    old_data jsonb,
    new_data jsonb,
    description text,
    created_at timestamptz NOT NULL DEFAULT now(),
    user_data text NOT NULL DEFAULT utils.get_user(),

    CONSTRAINT action_history_uuid_unique UNIQUE (uuid)
);

-- Add index for efficient querying
CREATE INDEX idx_action_history_user ON data.action_history(user_data);
CREATE INDEX idx_action_history_ledger ON data.action_history(ledger_id);
CREATE INDEX idx_action_history_created ON data.action_history(created_at DESC);
CREATE INDEX idx_action_history_entity ON data.action_history(entity_type, entity_uuid);

-- Enable Row Level Security
ALTER TABLE data.action_history ENABLE ROW LEVEL SECURITY;

-- RLS Policy: Users can only see their own action history
CREATE POLICY action_history_policy ON data.action_history
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Function to record action in history
CREATE OR REPLACE FUNCTION utils.record_action(
    p_ledger_id bigint,
    p_action_type text,
    p_entity_type text,
    p_entity_id bigint,
    p_entity_uuid text,
    p_old_data jsonb DEFAULT NULL,
    p_new_data jsonb DEFAULT NULL,
    p_description text DEFAULT NULL
) RETURNS text AS $record_action$
DECLARE
    v_action_uuid text;
BEGIN
    INSERT INTO data.action_history (
        ledger_id,
        action_type,
        entity_type,
        entity_id,
        entity_uuid,
        old_data,
        new_data,
        description
    ) VALUES (
        p_ledger_id,
        p_action_type,
        p_entity_type,
        p_entity_id,
        p_entity_uuid,
        p_old_data,
        p_new_data,
        p_description
    ) RETURNING uuid INTO v_action_uuid;

    RETURN v_action_uuid;
END;
$record_action$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION utils.record_action IS
'Record an action in the action history for undo/redo functionality';

-- API function to get action history
CREATE OR REPLACE FUNCTION api.get_action_history(
    p_ledger_uuid text DEFAULT NULL,
    p_limit integer DEFAULT 50,
    p_offset integer DEFAULT 0
) RETURNS TABLE (
    uuid text,
    action_type text,
    entity_type text,
    entity_uuid text,
    description text,
    created_at timestamptz,
    old_data jsonb,
    new_data jsonb
) AS $get_action_history$
BEGIN
    RETURN QUERY
    SELECT
        ah.uuid,
        ah.action_type,
        ah.entity_type,
        ah.entity_uuid,
        ah.description,
        ah.created_at,
        ah.old_data,
        ah.new_data
    FROM data.action_history ah
    LEFT JOIN data.ledgers l ON ah.ledger_id = l.id
    WHERE ah.user_data = utils.get_user()
      AND (p_ledger_uuid IS NULL OR l.uuid = p_ledger_uuid)
    ORDER BY ah.created_at DESC
    LIMIT p_limit
    OFFSET p_offset;
END;
$get_action_history$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.get_action_history IS
'Get action history for the current user, optionally filtered by ledger';

-- API function to clear old action history (30 days)
CREATE OR REPLACE FUNCTION api.cleanup_action_history(
    p_days_to_keep integer DEFAULT 30
) RETURNS integer AS $cleanup_action_history$
DECLARE
    v_deleted_count integer;
BEGIN
    DELETE FROM data.action_history
    WHERE user_data = utils.get_user()
      AND created_at < now() - (p_days_to_keep || ' days')::interval
      AND action_type != 'delete'; -- Keep delete actions longer for recovery

    GET DIAGNOSTICS v_deleted_count = ROW_COUNT;

    RETURN v_deleted_count;
END;
$cleanup_action_history$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION api.cleanup_action_history IS
'Clean up old action history entries (default: 30 days). Keeps delete actions longer.';

-- Grant permissions
GRANT EXECUTE ON FUNCTION utils.record_action TO pgbudget;
GRANT EXECUTE ON FUNCTION api.get_action_history TO pgbudget;
GRANT EXECUTE ON FUNCTION api.cleanup_action_history TO pgbudget;

-- Add comment to table
COMMENT ON TABLE data.action_history IS
'Tracks all user actions for undo/redo functionality and audit trail. Max retention: 30 days (configurable).';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin
-- Rollback: Remove action history functionality

DROP FUNCTION IF EXISTS api.cleanup_action_history;
DROP FUNCTION IF EXISTS api.get_action_history;
DROP FUNCTION IF EXISTS utils.record_action;

DROP TABLE IF EXISTS data.action_history;

-- +goose StatementEnd
