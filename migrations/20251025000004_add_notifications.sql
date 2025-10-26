-- +goose Up
-- Migration: Add notification system for credit cards
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 5
-- Purpose: Implement notifications for statements, due dates, payments, and alerts

-- ============================================================================
-- DATA SCHEMA - Notifications Table
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Table: data.credit_card_notifications
-- Purpose: Store notification records for credit card events
-- ----------------------------------------------------------------------------
CREATE TABLE data.credit_card_notifications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),

    -- Notification details
    notification_type TEXT NOT NULL CHECK (notification_type IN (
        'statement_ready',
        'due_reminder_7day',
        'due_reminder_3day',
        'due_reminder_1day',
        'payment_overdue',
        'payment_processed',
        'payment_failed',
        'large_purchase',
        'high_utilization',
        'limit_approaching'
    )),
    priority TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),

    -- Related entities
    credit_card_account_id BIGINT REFERENCES data.accounts(id) ON DELETE CASCADE,
    statement_id BIGINT REFERENCES data.credit_card_statements(id) ON DELETE SET NULL,
    scheduled_payment_id BIGINT REFERENCES data.scheduled_payments(id) ON DELETE SET NULL,
    transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,

    -- Notification content
    title TEXT NOT NULL,
    message TEXT NOT NULL,

    -- Status
    is_read BOOLEAN NOT NULL DEFAULT false,
    read_at TIMESTAMPTZ,
    is_dismissed BOOLEAN NOT NULL DEFAULT false,
    dismissed_at TIMESTAMPTZ,

    -- Metadata
    metadata JSONB,

    -- Constraints
    CONSTRAINT credit_card_notifications_uuid_unique UNIQUE(uuid),
    CONSTRAINT credit_card_notifications_user_data_length CHECK (char_length(user_data) <= 255),
    CONSTRAINT credit_card_notifications_title_length CHECK (char_length(title) <= 255),
    CONSTRAINT credit_card_notifications_message_length CHECK (char_length(message) <= 2000)
);

-- Create indexes
CREATE INDEX idx_cc_notifications_user_data ON data.credit_card_notifications(user_data);
CREATE INDEX idx_cc_notifications_type ON data.credit_card_notifications(notification_type);
CREATE INDEX idx_cc_notifications_account ON data.credit_card_notifications(credit_card_account_id);
CREATE INDEX idx_cc_notifications_is_read ON data.credit_card_notifications(is_read);
CREATE INDEX idx_cc_notifications_created_at ON data.credit_card_notifications(created_at DESC);

-- Enable RLS
ALTER TABLE data.credit_card_notifications ENABLE ROW LEVEL SECURITY;

CREATE POLICY credit_card_notifications_policy ON data.credit_card_notifications
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Add comments
COMMENT ON TABLE data.credit_card_notifications IS 'Notification records for credit card events including statements, payments, and alerts';
COMMENT ON COLUMN data.credit_card_notifications.notification_type IS 'Type of notification: statement_ready, due_reminder_*, payment_*, large_purchase, high_utilization, limit_approaching';
COMMENT ON COLUMN data.credit_card_notifications.priority IS 'Notification priority: low, normal, high, urgent';

-- ----------------------------------------------------------------------------
-- Update notification_preferences if it exists, or create it
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
DO $$
BEGIN
    -- Check if notification_preferences table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'data' AND table_name = 'notification_preferences') THEN
        -- Add credit card notification preferences if columns don't exist
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'notification_preferences' AND column_name = 'cc_statement_ready') THEN
            ALTER TABLE data.notification_preferences
            ADD COLUMN cc_statement_ready BOOLEAN DEFAULT true,
            ADD COLUMN cc_due_reminder_7day BOOLEAN DEFAULT true,
            ADD COLUMN cc_due_reminder_3day BOOLEAN DEFAULT true,
            ADD COLUMN cc_due_reminder_1day BOOLEAN DEFAULT true,
            ADD COLUMN cc_payment_overdue BOOLEAN DEFAULT true,
            ADD COLUMN cc_payment_processed BOOLEAN DEFAULT true,
            ADD COLUMN cc_payment_failed BOOLEAN DEFAULT true,
            ADD COLUMN cc_large_purchase BOOLEAN DEFAULT true,
            ADD COLUMN cc_large_purchase_threshold NUMERIC(19,4) DEFAULT 500.00,
            ADD COLUMN cc_high_utilization BOOLEAN DEFAULT true,
            ADD COLUMN cc_high_utilization_threshold INTEGER DEFAULT 80,
            ADD COLUMN cc_limit_approaching BOOLEAN DEFAULT true;
        END IF;
    ELSE
        -- Create notification_preferences table
        CREATE TABLE data.notification_preferences (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_data TEXT NOT NULL DEFAULT utils.get_user(),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

            -- Credit card notifications
            cc_statement_ready BOOLEAN DEFAULT true,
            cc_due_reminder_7day BOOLEAN DEFAULT true,
            cc_due_reminder_3day BOOLEAN DEFAULT true,
            cc_due_reminder_1day BOOLEAN DEFAULT true,
            cc_payment_overdue BOOLEAN DEFAULT true,
            cc_payment_processed BOOLEAN DEFAULT true,
            cc_payment_failed BOOLEAN DEFAULT true,
            cc_large_purchase BOOLEAN DEFAULT true,
            cc_large_purchase_threshold NUMERIC(19,4) DEFAULT 500.00,
            cc_high_utilization BOOLEAN DEFAULT true,
            cc_high_utilization_threshold INTEGER DEFAULT 80,
            cc_limit_approaching BOOLEAN DEFAULT true,

            CONSTRAINT notification_preferences_user_unique UNIQUE(user_data),
            CONSTRAINT notification_preferences_user_data_length CHECK (char_length(user_data) <= 255)
        );

        CREATE INDEX idx_notification_preferences_user ON data.notification_preferences(user_data);

        ALTER TABLE data.notification_preferences ENABLE ROW LEVEL SECURITY;

        CREATE POLICY notification_preferences_policy ON data.notification_preferences
            USING (user_data = utils.get_user())
            WITH CHECK (user_data = utils.get_user());

        CREATE TRIGGER update_notification_preferences_updated_at
            BEFORE UPDATE ON data.notification_preferences
            FOR EACH ROW
            EXECUTE FUNCTION utils.update_updated_at();
    END IF;
END $$;
-- +goose StatementEnd

-- ============================================================================
-- UTILS SCHEMA - Notification Functions
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Function: utils.get_notification_preferences
-- Purpose: Get notification preferences for a user
-- Parameters:
--   p_user_data: User identifier
-- Returns: Notification preferences record
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.get_notification_preferences(
    p_user_data TEXT DEFAULT NULL
)
RETURNS TABLE (
    cc_statement_ready BOOLEAN,
    cc_due_reminder_7day BOOLEAN,
    cc_due_reminder_3day BOOLEAN,
    cc_due_reminder_1day BOOLEAN,
    cc_payment_overdue BOOLEAN,
    cc_payment_processed BOOLEAN,
    cc_payment_failed BOOLEAN,
    cc_large_purchase BOOLEAN,
    cc_large_purchase_threshold NUMERIC,
    cc_high_utilization BOOLEAN,
    cc_high_utilization_threshold INTEGER,
    cc_limit_approaching BOOLEAN
)
LANGUAGE plpgsql
STABLE
SECURITY DEFINER
AS $$
DECLARE
    v_user_data TEXT;
BEGIN
    v_user_data := COALESCE(p_user_data, utils.get_user());

    -- Get or create preferences
    IF NOT EXISTS (SELECT 1 FROM data.notification_preferences WHERE user_data = v_user_data) THEN
        INSERT INTO data.notification_preferences (user_data) VALUES (v_user_data);
    END IF;

    RETURN QUERY
    SELECT
        np.cc_statement_ready,
        np.cc_due_reminder_7day,
        np.cc_due_reminder_3day,
        np.cc_due_reminder_1day,
        np.cc_payment_overdue,
        np.cc_payment_processed,
        np.cc_payment_failed,
        np.cc_large_purchase,
        np.cc_large_purchase_threshold,
        np.cc_high_utilization,
        np.cc_high_utilization_threshold,
        np.cc_limit_approaching
    FROM data.notification_preferences np
    WHERE np.user_data = v_user_data;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.get_notification_preferences IS 'Get notification preferences for a user, creating defaults if not exist';

-- ----------------------------------------------------------------------------
-- Function: utils.create_notification
-- Purpose: Create a new notification
-- Parameters:
--   p_notification_type: Type of notification
--   p_credit_card_account_id: Credit card account ID
--   p_title: Notification title
--   p_message: Notification message
--   p_priority: Priority level
--   p_metadata: Additional metadata
-- Returns: Notification UUID
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.create_notification(
    p_notification_type TEXT,
    p_credit_card_account_id BIGINT,
    p_title TEXT,
    p_message TEXT,
    p_priority TEXT DEFAULT 'normal',
    p_statement_id BIGINT DEFAULT NULL,
    p_scheduled_payment_id BIGINT DEFAULT NULL,
    p_transaction_id BIGINT DEFAULT NULL,
    p_metadata JSONB DEFAULT NULL,
    p_user_data TEXT DEFAULT NULL
)
RETURNS TEXT
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_user_data TEXT;
    v_notification_uuid TEXT;
    v_prefs RECORD;
    v_enabled BOOLEAN;
BEGIN
    v_user_data := COALESCE(p_user_data, utils.get_user());

    -- Get user preferences
    SELECT * INTO v_prefs FROM utils.get_notification_preferences(v_user_data);

    -- Check if this notification type is enabled
    v_enabled := CASE p_notification_type
        WHEN 'statement_ready' THEN v_prefs.cc_statement_ready
        WHEN 'due_reminder_7day' THEN v_prefs.cc_due_reminder_7day
        WHEN 'due_reminder_3day' THEN v_prefs.cc_due_reminder_3day
        WHEN 'due_reminder_1day' THEN v_prefs.cc_due_reminder_1day
        WHEN 'payment_overdue' THEN v_prefs.cc_payment_overdue
        WHEN 'payment_processed' THEN v_prefs.cc_payment_processed
        WHEN 'payment_failed' THEN v_prefs.cc_payment_failed
        WHEN 'large_purchase' THEN v_prefs.cc_large_purchase
        WHEN 'high_utilization' THEN v_prefs.cc_high_utilization
        WHEN 'limit_approaching' THEN v_prefs.cc_limit_approaching
        ELSE true
    END;

    -- Skip if notification type is disabled
    IF NOT v_enabled THEN
        RETURN NULL;
    END IF;

    -- Create notification
    INSERT INTO data.credit_card_notifications (
        notification_type,
        priority,
        credit_card_account_id,
        statement_id,
        scheduled_payment_id,
        transaction_id,
        title,
        message,
        metadata,
        user_data
    ) VALUES (
        p_notification_type,
        p_priority,
        p_credit_card_account_id,
        p_statement_id,
        p_scheduled_payment_id,
        p_transaction_id,
        p_title,
        p_message,
        p_metadata,
        v_user_data
    )
    RETURNING uuid INTO v_notification_uuid;

    RETURN v_notification_uuid;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.create_notification IS 'Create a notification for a credit card event, respecting user preferences';

-- ----------------------------------------------------------------------------
-- Function: utils.check_due_date_reminders
-- Purpose: Check for upcoming due dates and create reminder notifications
-- Parameters:
--   p_check_date: Date to check from (defaults to current date)
-- Returns: Count of reminders created
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.check_due_date_reminders(
    p_check_date DATE DEFAULT CURRENT_DATE
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_statement RECORD;
    v_days_until_due INTEGER;
    v_notification_type TEXT;
    v_reminder_count INTEGER := 0;
    v_account_name TEXT;
BEGIN
    -- Check all current statements with balances
    FOR v_statement IN
        SELECT
            s.id,
            s.credit_card_account_id,
            s.due_date,
            s.ending_balance,
            s.minimum_payment_due,
            s.user_data,
            a.name as account_name,
            a.uuid as account_uuid
        FROM data.credit_card_statements s
        JOIN data.accounts a ON a.id = s.credit_card_account_id
        WHERE s.is_current = true
            AND s.ending_balance > 0
            AND s.due_date >= p_check_date
    LOOP
        v_days_until_due := s.due_date - p_check_date;
        v_notification_type := NULL;

        -- Determine notification type based on days until due
        IF v_days_until_due = 7 THEN
            v_notification_type := 'due_reminder_7day';
        ELSIF v_days_until_due = 3 THEN
            v_notification_type := 'due_reminder_3day';
        ELSIF v_days_until_due = 1 THEN
            v_notification_type := 'due_reminder_1day';
        END IF;

        -- Create reminder if applicable and not already sent
        IF v_notification_type IS NOT NULL THEN
            -- Check if reminder already exists
            IF NOT EXISTS (
                SELECT 1 FROM data.credit_card_notifications
                WHERE notification_type = v_notification_type
                    AND statement_id = v_statement.id
                    AND user_data = v_statement.user_data
            ) THEN
                -- Create reminder notification
                PERFORM utils.create_notification(
                    v_notification_type,
                    v_statement.credit_card_account_id,
                    'Payment Due Soon - ' || v_statement.account_name,
                    format('Your %s payment of $%s is due in %s day%s on %s.',
                        v_statement.account_name,
                        (v_statement.minimum_payment_due / 100.0)::TEXT,
                        v_days_until_due,
                        CASE WHEN v_days_until_due = 1 THEN '' ELSE 's' END,
                        TO_CHAR(v_statement.due_date, 'Mon DD, YYYY')
                    ),
                    CASE v_days_until_due
                        WHEN 1 THEN 'urgent'
                        WHEN 3 THEN 'high'
                        ELSE 'normal'
                    END,
                    v_statement.id,
                    NULL,
                    NULL,
                    jsonb_build_object(
                        'due_date', v_statement.due_date,
                        'minimum_payment', v_statement.minimum_payment_due,
                        'ending_balance', v_statement.ending_balance,
                        'days_until_due', v_days_until_due
                    ),
                    v_statement.user_data
                );

                v_reminder_count := v_reminder_count + 1;
            END IF;
        END IF;
    END LOOP;

    RETURN v_reminder_count;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.check_due_date_reminders IS 'Check for upcoming payment due dates and create reminder notifications (7, 3, 1 day)';

-- ----------------------------------------------------------------------------
-- Function: utils.check_overdue_payments
-- Purpose: Check for overdue payments and create alert notifications
-- Parameters:
--   p_check_date: Date to check from (defaults to current date)
-- Returns: Count of alerts created
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.check_overdue_payments(
    p_check_date DATE DEFAULT CURRENT_DATE
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_statement RECORD;
    v_days_overdue INTEGER;
    v_alert_count INTEGER := 0;
BEGIN
    -- Check all current statements with overdue payments
    FOR v_statement IN
        SELECT
            s.id,
            s.credit_card_account_id,
            s.due_date,
            s.ending_balance,
            s.minimum_payment_due,
            s.user_data,
            a.name as account_name,
            a.uuid as account_uuid
        FROM data.credit_card_statements s
        JOIN data.accounts a ON a.id = s.credit_card_account_id
        WHERE s.is_current = true
            AND s.ending_balance > 0
            AND s.due_date < p_check_date
    LOOP
        v_days_overdue := p_check_date - v_statement.due_date;

        -- Create overdue alert if not already sent today
        IF NOT EXISTS (
            SELECT 1 FROM data.credit_card_notifications
            WHERE notification_type = 'payment_overdue'
                AND statement_id = v_statement.id
                AND created_at::DATE = p_check_date
                AND user_data = v_statement.user_data
        ) THEN
            PERFORM utils.create_notification(
                'payment_overdue',
                v_statement.credit_card_account_id,
                'Payment Overdue - ' || v_statement.account_name,
                format('Your %s payment of $%s was due %s day%s ago on %s. Please make a payment as soon as possible to avoid additional fees.',
                    v_statement.account_name,
                    (v_statement.minimum_payment_due / 100.0)::TEXT,
                    v_days_overdue,
                    CASE WHEN v_days_overdue = 1 THEN '' ELSE 's' END,
                    TO_CHAR(v_statement.due_date, 'Mon DD, YYYY')
                ),
                'urgent',
                v_statement.id,
                NULL,
                NULL,
                jsonb_build_object(
                    'due_date', v_statement.due_date,
                    'minimum_payment', v_statement.minimum_payment_due,
                    'ending_balance', v_statement.ending_balance,
                    'days_overdue', v_days_overdue
                ),
                v_statement.user_data
            );

            v_alert_count := v_alert_count + 1;
        END IF;
    END LOOP;

    RETURN v_alert_count;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.check_overdue_payments IS 'Check for overdue payments and create urgent alert notifications';

-- ----------------------------------------------------------------------------
-- Function: utils.check_high_utilization
-- Purpose: Check for high credit card utilization and create alerts
-- Parameters:
--   p_check_date: Date to check from (defaults to current date)
-- Returns: Count of alerts created
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.check_high_utilization(
    p_check_date DATE DEFAULT CURRENT_DATE
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_limit RECORD;
    v_prefs RECORD;
    v_alert_count INTEGER := 0;
    v_current_balance BIGINT;
    v_utilization NUMERIC;
BEGIN
    -- Check each credit card with active limits
    FOR v_limit IN
        SELECT
            ccl.id,
            ccl.credit_card_account_id,
            ccl.credit_limit,
            ccl.user_data,
            a.name as account_name,
            a.uuid as account_uuid
        FROM data.credit_card_limits ccl
        JOIN data.accounts a ON a.id = ccl.credit_card_account_id
        WHERE ccl.is_active = true
            AND ccl.credit_limit > 0
    LOOP
        -- Get user preferences
        SELECT * INTO v_prefs FROM utils.get_notification_preferences(v_limit.user_data);

        -- Get current balance
        v_current_balance := COALESCE(
            (SELECT balance FROM data.balance_snapshots
             WHERE account_id = v_limit.credit_card_account_id
             ORDER BY transaction_id DESC
             LIMIT 1),
            0
        );

        -- Calculate utilization percentage
        v_utilization := (v_current_balance / (v_limit.credit_limit * 100.0)) * 100;

        -- Check if utilization exceeds threshold
        IF v_utilization >= v_prefs.cc_high_utilization_threshold THEN
            -- Create alert if not already sent today
            IF NOT EXISTS (
                SELECT 1 FROM data.credit_card_notifications
                WHERE notification_type = 'high_utilization'
                    AND credit_card_account_id = v_limit.credit_card_account_id
                    AND created_at::DATE = p_check_date
                    AND user_data = v_limit.user_data
            ) THEN
                PERFORM utils.create_notification(
                    'high_utilization',
                    v_limit.credit_card_account_id,
                    'High Credit Utilization - ' || v_limit.account_name,
                    format('Your %s is at %s%% utilization ($%s of $%s limit). High utilization can impact your credit score.',
                        v_limit.account_name,
                        ROUND(v_utilization, 1),
                        (v_current_balance / 100.0)::TEXT,
                        (v_limit.credit_limit)::TEXT
                    ),
                    'high',
                    NULL,
                    NULL,
                    NULL,
                    jsonb_build_object(
                        'current_balance', v_current_balance,
                        'credit_limit', v_limit.credit_limit * 100,
                        'utilization_percent', ROUND(v_utilization, 2),
                        'threshold', v_prefs.cc_high_utilization_threshold
                    ),
                    v_limit.user_data
                );

                v_alert_count := v_alert_count + 1;
            END IF;
        END IF;
    END LOOP;

    RETURN v_alert_count;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.check_high_utilization IS 'Check for high credit card utilization and create alerts based on user threshold';

-- ----------------------------------------------------------------------------
-- Function: utils.process_all_notifications
-- Purpose: Process all notification checks
-- Parameters:
--   p_check_date: Date to check from (defaults to current date)
-- Returns: JSON summary of notifications created
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION utils.process_all_notifications(
    p_check_date DATE DEFAULT CURRENT_DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_due_reminders INTEGER;
    v_overdue_alerts INTEGER;
    v_utilization_alerts INTEGER;
    v_total INTEGER;
BEGIN
    -- Check due date reminders
    v_due_reminders := utils.check_due_date_reminders(p_check_date);

    -- Check overdue payments
    v_overdue_alerts := utils.check_overdue_payments(p_check_date);

    -- Check high utilization
    v_utilization_alerts := utils.check_high_utilization(p_check_date);

    -- Calculate total
    v_total := v_due_reminders + v_overdue_alerts + v_utilization_alerts;

    RETURN jsonb_build_object(
        'success', true,
        'check_date', p_check_date,
        'total_notifications', v_total,
        'due_reminders', v_due_reminders,
        'overdue_alerts', v_overdue_alerts,
        'utilization_alerts', v_utilization_alerts
    );
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION utils.process_all_notifications IS 'Process all notification checks and return summary. Used by daily batch job.';

-- ============================================================================
-- API SCHEMA - Public Interface Functions and Views
-- ============================================================================

-- ----------------------------------------------------------------------------
-- View: api.credit_card_notifications
-- Purpose: Public view of notifications with account details
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW api.credit_card_notifications AS
SELECT
    n.uuid,
    n.created_at,
    n.notification_type,
    n.priority,
    a.uuid as credit_card_uuid,
    a.name as credit_card_name,
    s.uuid as statement_uuid,
    sp.uuid as scheduled_payment_uuid,
    t.uuid as transaction_uuid,
    n.title,
    n.message,
    n.is_read,
    n.read_at,
    n.is_dismissed,
    n.dismissed_at,
    n.metadata
FROM data.credit_card_notifications n
LEFT JOIN data.accounts a ON a.id = n.credit_card_account_id
LEFT JOIN data.credit_card_statements s ON s.id = n.statement_id
LEFT JOIN data.scheduled_payments sp ON sp.id = n.scheduled_payment_id
LEFT JOIN data.transactions t ON t.id = n.transaction_id
WHERE n.user_data = utils.get_user()
ORDER BY
    n.is_read ASC,
    CASE n.priority
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'normal' THEN 3
        WHEN 'low' THEN 4
    END ASC,
    n.created_at DESC;

COMMENT ON VIEW api.credit_card_notifications IS 'Public view of credit card notifications ordered by read status, priority, and date';

-- ----------------------------------------------------------------------------
-- Function: api.get_notifications
-- Purpose: Get notifications with optional filters
-- Parameters:
--   p_is_read: Filter by read status (optional)
--   p_notification_type: Filter by type (optional)
-- Returns: Filtered notifications
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_notifications(
    p_is_read BOOLEAN DEFAULT NULL,
    p_notification_type TEXT DEFAULT NULL
)
RETURNS TABLE (
    uuid TEXT,
    created_at TIMESTAMPTZ,
    notification_type TEXT,
    priority TEXT,
    credit_card_uuid TEXT,
    credit_card_name TEXT,
    title TEXT,
    message TEXT,
    is_read BOOLEAN,
    read_at TIMESTAMPTZ,
    metadata JSONB
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT
        uuid,
        created_at,
        notification_type,
        priority,
        credit_card_uuid,
        credit_card_name,
        title,
        message,
        is_read,
        read_at,
        metadata
    FROM api.credit_card_notifications
    WHERE (p_is_read IS NULL OR is_read = p_is_read)
        AND (p_notification_type IS NULL OR notification_type = p_notification_type)
        AND is_dismissed = false;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_notifications IS 'Get notifications with optional filters for read status and type';

-- ----------------------------------------------------------------------------
-- Function: api.mark_notification_read
-- Purpose: Mark a notification as read
-- Parameters:
--   p_notification_uuid: UUID of notification
-- Returns: Success status
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.mark_notification_read(
    p_notification_uuid TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
BEGIN
    UPDATE data.credit_card_notifications
    SET is_read = true,
        read_at = CURRENT_TIMESTAMP
    WHERE uuid = p_notification_uuid
        AND user_data = utils.get_user()
        AND is_read = false;

    IF FOUND THEN
        RETURN jsonb_build_object('success', true, 'message', 'Notification marked as read');
    ELSE
        RETURN jsonb_build_object('success', false, 'error', 'Notification not found or already read');
    END IF;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.mark_notification_read IS 'Mark a notification as read';

-- ----------------------------------------------------------------------------
-- Function: api.dismiss_notification
-- Purpose: Dismiss a notification
-- Parameters:
--   p_notification_uuid: UUID of notification
-- Returns: Success status
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.dismiss_notification(
    p_notification_uuid TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
BEGIN
    UPDATE data.credit_card_notifications
    SET is_dismissed = true,
        dismissed_at = CURRENT_TIMESTAMP,
        is_read = true,
        read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
    WHERE uuid = p_notification_uuid
        AND user_data = utils.get_user();

    IF FOUND THEN
        RETURN jsonb_build_object('success', true, 'message', 'Notification dismissed');
    ELSE
        RETURN jsonb_build_object('success', false, 'error', 'Notification not found');
    END IF;
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.dismiss_notification IS 'Dismiss a notification (also marks as read)';

-- ----------------------------------------------------------------------------
-- Function: api.get_notification_preferences
-- Purpose: Get user's notification preferences
-- Returns: Notification preferences
-- ----------------------------------------------------------------------------
DROP FUNCTION IF EXISTS api.get_notification_preferences();

-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.get_notification_preferences()
RETURNS TABLE (
    cc_statement_ready BOOLEAN,
    cc_due_reminder_7day BOOLEAN,
    cc_due_reminder_3day BOOLEAN,
    cc_due_reminder_1day BOOLEAN,
    cc_payment_overdue BOOLEAN,
    cc_payment_processed BOOLEAN,
    cc_payment_failed BOOLEAN,
    cc_large_purchase BOOLEAN,
    cc_large_purchase_threshold NUMERIC,
    cc_high_utilization BOOLEAN,
    cc_high_utilization_threshold INTEGER,
    cc_limit_approaching BOOLEAN
)
LANGUAGE sql
STABLE
SECURITY INVOKER
AS $$
    SELECT * FROM utils.get_notification_preferences(utils.get_user());
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.get_notification_preferences IS 'Get notification preferences for current user';

-- ----------------------------------------------------------------------------
-- Function: api.update_notification_preferences
-- Purpose: Update user's notification preferences
-- Parameters: All notification preference fields
-- Returns: Success status
-- ----------------------------------------------------------------------------
-- +goose StatementBegin
CREATE OR REPLACE FUNCTION api.update_notification_preferences(
    p_cc_statement_ready BOOLEAN DEFAULT NULL,
    p_cc_due_reminder_7day BOOLEAN DEFAULT NULL,
    p_cc_due_reminder_3day BOOLEAN DEFAULT NULL,
    p_cc_due_reminder_1day BOOLEAN DEFAULT NULL,
    p_cc_payment_overdue BOOLEAN DEFAULT NULL,
    p_cc_payment_processed BOOLEAN DEFAULT NULL,
    p_cc_payment_failed BOOLEAN DEFAULT NULL,
    p_cc_large_purchase BOOLEAN DEFAULT NULL,
    p_cc_large_purchase_threshold NUMERIC DEFAULT NULL,
    p_cc_high_utilization BOOLEAN DEFAULT NULL,
    p_cc_high_utilization_threshold INTEGER DEFAULT NULL,
    p_cc_limit_approaching BOOLEAN DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
BEGIN
    -- Ensure preferences exist
    IF NOT EXISTS (SELECT 1 FROM data.notification_preferences WHERE user_data = utils.get_user()) THEN
        INSERT INTO data.notification_preferences (user_data) VALUES (utils.get_user());
    END IF;

    -- Update preferences
    UPDATE data.notification_preferences
    SET
        cc_statement_ready = COALESCE(p_cc_statement_ready, cc_statement_ready),
        cc_due_reminder_7day = COALESCE(p_cc_due_reminder_7day, cc_due_reminder_7day),
        cc_due_reminder_3day = COALESCE(p_cc_due_reminder_3day, cc_due_reminder_3day),
        cc_due_reminder_1day = COALESCE(p_cc_due_reminder_1day, cc_due_reminder_1day),
        cc_payment_overdue = COALESCE(p_cc_payment_overdue, cc_payment_overdue),
        cc_payment_processed = COALESCE(p_cc_payment_processed, cc_payment_processed),
        cc_payment_failed = COALESCE(p_cc_payment_failed, cc_payment_failed),
        cc_large_purchase = COALESCE(p_cc_large_purchase, cc_large_purchase),
        cc_large_purchase_threshold = COALESCE(p_cc_large_purchase_threshold, cc_large_purchase_threshold),
        cc_high_utilization = COALESCE(p_cc_high_utilization, cc_high_utilization),
        cc_high_utilization_threshold = COALESCE(p_cc_high_utilization_threshold, cc_high_utilization_threshold),
        cc_limit_approaching = COALESCE(p_cc_limit_approaching, cc_limit_approaching),
        updated_at = CURRENT_TIMESTAMP
    WHERE user_data = utils.get_user();

    RETURN jsonb_build_object('success', true, 'message', 'Notification preferences updated');
END;
$$;
-- +goose StatementEnd

COMMENT ON FUNCTION api.update_notification_preferences IS 'Update notification preferences for current user';

-- +goose Down
-- Drop all functions and views in reverse order

DROP FUNCTION IF EXISTS api.update_notification_preferences;
DROP FUNCTION IF EXISTS api.get_notification_preferences();
DROP FUNCTION IF EXISTS api.dismiss_notification(TEXT);
DROP FUNCTION IF EXISTS api.mark_notification_read(TEXT);
DROP FUNCTION IF EXISTS api.get_notifications(BOOLEAN, TEXT);
DROP VIEW IF EXISTS api.credit_card_notifications;

DROP FUNCTION IF EXISTS utils.process_all_notifications(DATE);
DROP FUNCTION IF EXISTS utils.check_high_utilization(DATE);
DROP FUNCTION IF EXISTS utils.check_overdue_payments(DATE);
DROP FUNCTION IF EXISTS utils.check_due_date_reminders(DATE);
DROP FUNCTION IF EXISTS utils.create_notification;
DROP FUNCTION IF EXISTS utils.get_notification_preferences(TEXT);

DROP TABLE IF EXISTS data.credit_card_notifications CASCADE;
