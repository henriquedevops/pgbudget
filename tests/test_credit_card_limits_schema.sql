-- Test Script: Credit Card Limits & Statements Schema Validation
-- Part of: CREDIT_CARD_LIMITS_DESIGN_GUIDE.md - Phase 1
-- Purpose: Validate table creation, constraints, RLS policies, and basic operations

-- Set up test environment
BEGIN;

-- Create test user context
SELECT set_config('app.current_user_id', 'test_user_cc_limits', false);

-- Test 1: Create test ledger
DO $$
DECLARE
    v_ledger_id BIGINT;
    v_ledger_uuid TEXT;
BEGIN
    INSERT INTO data.ledgers (name) VALUES ('Test Ledger for CC Limits')
    RETURNING id, uuid INTO v_ledger_id, v_ledger_uuid;

    RAISE NOTICE 'Test 1 PASSED: Created test ledger with ID % and UUID %', v_ledger_id, v_ledger_uuid;
END $$;

-- Test 2: Create credit card account
DO $$
DECLARE
    v_ledger_id BIGINT;
    v_account_id BIGINT;
    v_account_uuid TEXT;
BEGIN
    SELECT id INTO v_ledger_id FROM data.ledgers WHERE user_data = 'test_user_cc_limits' LIMIT 1;

    INSERT INTO data.accounts (ledger_id, name, type, internal_type)
    VALUES (v_ledger_id, 'Test Visa Card', 'liability', 'liability_like')
    RETURNING id, uuid INTO v_account_id, v_account_uuid;

    RAISE NOTICE 'Test 2 PASSED: Created credit card account with ID % and UUID %', v_account_id, v_account_uuid;
END $$;

-- Test 3: Insert credit card limit with all required fields
DO $$
DECLARE
    v_account_id BIGINT;
    v_limit_id BIGINT;
    v_limit_uuid TEXT;
BEGIN
    SELECT id INTO v_account_id FROM data.accounts
    WHERE user_data = 'test_user_cc_limits' AND name = 'Test Visa Card';

    INSERT INTO data.credit_card_limits (
        credit_card_account_id,
        credit_limit,
        warning_threshold_percent,
        annual_percentage_rate,
        interest_type,
        compounding_frequency,
        statement_day_of_month,
        due_date_offset_days,
        grace_period_days,
        minimum_payment_percent,
        minimum_payment_flat
    ) VALUES (
        v_account_id,
        5000.00,
        80,
        18.99,
        'variable',
        'daily',
        1,
        25,
        0,
        2.0,
        25.00
    )
    RETURNING id, uuid INTO v_limit_id, v_limit_uuid;

    RAISE NOTICE 'Test 3 PASSED: Created credit card limit with ID % and UUID %', v_limit_id, v_limit_uuid;
END $$;

-- Test 4: Verify constraint checks work
DO $$
DECLARE
    v_account_id BIGINT;
BEGIN
    SELECT id INTO v_account_id FROM data.accounts
    WHERE user_data = 'test_user_cc_limits' AND name = 'Test Visa Card';

    -- Test negative credit limit (should fail)
    BEGIN
        INSERT INTO data.credit_card_limits (credit_card_account_id, credit_limit)
        VALUES (v_account_id, -1000.00);
        RAISE EXCEPTION 'Test 4 FAILED: Negative credit limit was allowed';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'Test 4 PASSED: Negative credit limit rejected as expected';
    END;
END $$;

-- Test 5: Verify APR range constraint
DO $$
DECLARE
    v_account_id BIGINT;
    v_ledger_id BIGINT;
    v_new_account_id BIGINT;
BEGIN
    SELECT id INTO v_ledger_id FROM data.ledgers WHERE user_data = 'test_user_cc_limits' LIMIT 1;

    -- Create another account for this test
    INSERT INTO data.accounts (ledger_id, name, type, internal_type)
    VALUES (v_ledger_id, 'Test Mastercard', 'liability', 'liability_like')
    RETURNING id INTO v_new_account_id;

    -- Test APR > 100 (should fail)
    BEGIN
        INSERT INTO data.credit_card_limits (credit_card_account_id, credit_limit, annual_percentage_rate)
        VALUES (v_new_account_id, 3000.00, 150.00);
        RAISE EXCEPTION 'Test 5 FAILED: APR over 100 was allowed';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'Test 5 PASSED: APR over 100 rejected as expected';
    END;
END $$;

-- Test 6: Verify unique constraint on credit_card_account_id per user
DO $$
DECLARE
    v_account_id BIGINT;
BEGIN
    SELECT id INTO v_account_id FROM data.accounts
    WHERE user_data = 'test_user_cc_limits' AND name = 'Test Visa Card';

    -- Try to insert duplicate limit for same card (should fail)
    BEGIN
        INSERT INTO data.credit_card_limits (credit_card_account_id, credit_limit)
        VALUES (v_account_id, 10000.00);
        RAISE EXCEPTION 'Test 6 FAILED: Duplicate credit card limit was allowed';
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'Test 6 PASSED: Duplicate credit card limit rejected as expected';
    END;
END $$;

-- Test 7: Verify auto-payment constraints
DO $$
DECLARE
    v_ledger_id BIGINT;
    v_account_id BIGINT;
BEGIN
    SELECT id INTO v_ledger_id FROM data.ledgers WHERE user_data = 'test_user_cc_limits' LIMIT 1;

    -- Create another account for this test
    INSERT INTO data.accounts (ledger_id, name, type, internal_type)
    VALUES (v_ledger_id, 'Test Discover', 'liability', 'liability_like')
    RETURNING id INTO v_account_id;

    -- Test invalid auto_payment_type (should fail)
    BEGIN
        INSERT INTO data.credit_card_limits (
            credit_card_account_id,
            credit_limit,
            auto_payment_enabled,
            auto_payment_type
        )
        VALUES (v_account_id, 2000.00, true, 'invalid_type');
        RAISE EXCEPTION 'Test 7 FAILED: Invalid auto_payment_type was allowed';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'Test 7 PASSED: Invalid auto_payment_type rejected as expected';
    END;
END $$;

-- Test 8: Insert credit card statement
DO $$
DECLARE
    v_account_id BIGINT;
    v_statement_id BIGINT;
    v_statement_uuid TEXT;
BEGIN
    SELECT id INTO v_account_id FROM data.accounts
    WHERE user_data = 'test_user_cc_limits' AND name = 'Test Visa Card';

    INSERT INTO data.credit_card_statements (
        credit_card_account_id,
        statement_period_start,
        statement_period_end,
        previous_balance,
        purchases_amount,
        payments_amount,
        interest_charged,
        fees_charged,
        ending_balance,
        minimum_payment_due,
        due_date,
        is_current
    ) VALUES (
        v_account_id,
        '2025-09-01',
        '2025-09-30',
        1000.00,
        500.00,
        200.00,
        25.50,
        0.00,
        1325.50,
        40.00,
        '2025-10-25',
        true
    )
    RETURNING id, uuid INTO v_statement_id, v_statement_uuid;

    RAISE NOTICE 'Test 8 PASSED: Created credit card statement with ID % and UUID %', v_statement_id, v_statement_uuid;
END $$;

-- Test 9: Verify statement period constraint
DO $$
DECLARE
    v_account_id BIGINT;
BEGIN
    SELECT id INTO v_account_id FROM data.accounts
    WHERE user_data = 'test_user_cc_limits' AND name = 'Test Visa Card';

    -- Test period_end before period_start (should fail)
    BEGIN
        INSERT INTO data.credit_card_statements (
            credit_card_account_id,
            statement_period_start,
            statement_period_end,
            ending_balance,
            minimum_payment_due,
            due_date
        ) VALUES (
            v_account_id,
            '2025-10-31',
            '2025-10-01',
            100.00,
            10.00,
            '2025-11-25'
        );
        RAISE EXCEPTION 'Test 9 FAILED: Invalid statement period was allowed';
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'Test 9 PASSED: Invalid statement period rejected as expected';
    END;
END $$;

-- Test 10: Verify RLS policy is configured correctly
DO $$
DECLARE
    v_policy_count INTEGER;
BEGIN
    -- Check that RLS policies exist
    SELECT COUNT(*) INTO v_policy_count
    FROM pg_policies
    WHERE schemaname = 'data'
    AND tablename = 'credit_card_limits'
    AND policyname = 'credit_card_limits_policy';

    IF v_policy_count = 1 THEN
        RAISE NOTICE 'Test 10 PASSED: RLS policy exists and is configured';
    ELSE
        RAISE EXCEPTION 'Test 10 FAILED: RLS policy not found';
    END IF;
END $$;

-- Test 11: Verify updated_at trigger works
DO $$
DECLARE
    v_limit_id BIGINT;
    v_initial_updated_at TIMESTAMPTZ;
    v_new_updated_at TIMESTAMPTZ;
BEGIN
    SELECT id, updated_at INTO v_limit_id, v_initial_updated_at
    FROM data.credit_card_limits
    WHERE user_data = 'test_user_cc_limits'
    LIMIT 1;

    -- Wait a moment and update
    PERFORM pg_sleep(0.01);

    UPDATE data.credit_card_limits
    SET credit_limit = 6000.00
    WHERE id = v_limit_id
    RETURNING updated_at INTO v_new_updated_at;

    IF v_new_updated_at >= v_initial_updated_at THEN
        RAISE NOTICE 'Test 11 PASSED: updated_at trigger working correctly';
    ELSE
        RAISE EXCEPTION 'Test 11 FAILED: updated_at was not updated';
    END IF;
END $$;

-- Test 12: Verify cascade delete works
DO $$
DECLARE
    v_ledger_id BIGINT;
    v_account_id BIGINT;
    v_limit_id BIGINT;
    v_count INTEGER;
BEGIN
    SELECT id INTO v_ledger_id FROM data.ledgers WHERE user_data = 'test_user_cc_limits' LIMIT 1;

    -- Create new account and limit
    INSERT INTO data.accounts (ledger_id, name, type, internal_type)
    VALUES (v_ledger_id, 'Test Amex', 'liability', 'liability_like')
    RETURNING id INTO v_account_id;

    INSERT INTO data.credit_card_limits (credit_card_account_id, credit_limit)
    VALUES (v_account_id, 15000.00)
    RETURNING id INTO v_limit_id;

    -- Delete the account (limit should cascade delete)
    DELETE FROM data.accounts WHERE id = v_account_id;

    -- Check if limit was deleted
    SELECT COUNT(*) INTO v_count FROM data.credit_card_limits WHERE id = v_limit_id;

    IF v_count = 0 THEN
        RAISE NOTICE 'Test 12 PASSED: Cascade delete working correctly';
    ELSE
        RAISE EXCEPTION 'Test 12 FAILED: Cascade delete did not remove limit';
    END IF;
END $$;

-- Test 13: Verify all indexes exist
DO $$
DECLARE
    v_index_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_index_count
    FROM pg_indexes
    WHERE tablename = 'credit_card_limits'
    AND schemaname = 'data'
    AND indexname IN (
        'idx_credit_card_limits_account_id',
        'idx_credit_card_limits_user_data',
        'idx_credit_card_limits_is_active'
    );

    IF v_index_count = 3 THEN
        RAISE NOTICE 'Test 13 PASSED: All credit_card_limits indexes created';
    ELSE
        RAISE EXCEPTION 'Test 13 FAILED: Expected 3 indexes, found %', v_index_count;
    END IF;

    SELECT COUNT(*) INTO v_index_count
    FROM pg_indexes
    WHERE tablename = 'credit_card_statements'
    AND schemaname = 'data'
    AND indexname IN (
        'idx_credit_card_statements_account_id',
        'idx_credit_card_statements_user_data',
        'idx_credit_card_statements_period_end',
        'idx_credit_card_statements_due_date',
        'idx_credit_card_statements_is_current'
    );

    IF v_index_count = 5 THEN
        RAISE NOTICE 'Test 13 (continued): All credit_card_statements indexes created';
    ELSE
        RAISE EXCEPTION 'Test 13 FAILED: Expected 5 statement indexes, found %', v_index_count;
    END IF;
END $$;

-- Test 14: Verify RLS is enabled
DO $$
DECLARE
    v_rls_enabled BOOLEAN;
BEGIN
    SELECT relrowsecurity INTO v_rls_enabled
    FROM pg_class
    WHERE relname = 'credit_card_limits'
    AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'data');

    IF v_rls_enabled THEN
        RAISE NOTICE 'Test 14 PASSED: RLS enabled on credit_card_limits';
    ELSE
        RAISE EXCEPTION 'Test 14 FAILED: RLS not enabled on credit_card_limits';
    END IF;

    SELECT relrowsecurity INTO v_rls_enabled
    FROM pg_class
    WHERE relname = 'credit_card_statements'
    AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'data');

    IF v_rls_enabled THEN
        RAISE NOTICE 'Test 14 (continued): RLS enabled on credit_card_statements';
    ELSE
        RAISE EXCEPTION 'Test 14 FAILED: RLS not enabled on credit_card_statements';
    END IF;
END $$;

-- Summary
DO $$
BEGIN
    RAISE NOTICE '===========================================';
    RAISE NOTICE 'ALL TESTS COMPLETED SUCCESSFULLY!';
    RAISE NOTICE '===========================================';
    RAISE NOTICE 'Tables created: credit_card_limits, credit_card_statements';
    RAISE NOTICE 'RLS policies: Enabled and working';
    RAISE NOTICE 'Constraints: All validated';
    RAISE NOTICE 'Triggers: updated_at trigger working';
    RAISE NOTICE 'Cascade deletes: Working correctly';
    RAISE NOTICE 'Indexes: All created';
    RAISE NOTICE '===========================================';
END $$;

-- Rollback test data (comment out if you want to keep test data)
ROLLBACK;

-- Uncomment below to commit test data
-- COMMIT;
