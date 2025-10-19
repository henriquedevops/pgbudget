-- +goose Up
-- +goose StatementBegin

-- ============================================================================
-- LOAN MANAGEMENT TABLES
-- ============================================================================
-- This migration creates the tables for comprehensive loan management
-- including loan metadata and payment schedules.
-- Part of Step 1.2 and 1.3 of LOAN_MANAGEMENT_IMPLEMENTATION.md
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: data.loans
-- ----------------------------------------------------------------------------
-- Stores loan metadata including terms, rates, and current status
CREATE TABLE data.loans (
    -- Standard fields (following pgbudget pattern)
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid            text        NOT NULL DEFAULT utils.nanoid(8),
    created_at      timestamptz NOT NULL DEFAULT current_timestamp,
    updated_at      timestamptz NOT NULL DEFAULT current_timestamp,
    user_data       text        NOT NULL DEFAULT utils.get_user(),

    -- Foreign keys
    ledger_id       bigint      NOT NULL REFERENCES data.ledgers (id) ON DELETE CASCADE,
    account_id      bigint      NULL REFERENCES data.accounts (id) ON DELETE SET NULL,

    -- Loan identification
    lender_name     text        NOT NULL,
    loan_type       text        NOT NULL,

    -- Loan amounts
    principal_amount    numeric(19,4) NOT NULL,
    current_balance     numeric(19,4) NOT NULL,

    -- Interest details
    interest_rate       numeric(8,5)  NOT NULL,
    interest_type       text          NOT NULL,
    compounding_frequency text        NOT NULL DEFAULT 'monthly',

    -- Loan term
    loan_term_months    integer       NOT NULL,
    remaining_months    integer       NOT NULL,

    -- Dates
    start_date          date          NOT NULL,
    first_payment_date  date          NOT NULL,

    -- Payment details
    payment_amount      numeric(19,4) NOT NULL,
    payment_frequency   text          NOT NULL,
    payment_day_of_month integer      NULL,

    -- Loan configuration
    amortization_type   text          NOT NULL DEFAULT 'standard',
    status              text          NOT NULL DEFAULT 'active',

    -- Additional info
    notes               text          NULL,
    metadata            jsonb         NULL,

    -- ========================================================================
    -- CONSTRAINTS
    -- ========================================================================

    -- Unique constraints
    CONSTRAINT loans_uuid_unique UNIQUE (uuid),
    CONSTRAINT loans_lender_ledger_unique UNIQUE (lender_name, ledger_id, user_data),

    -- Length checks
    CONSTRAINT loans_lender_name_length_check CHECK (char_length(lender_name) <= 255),
    CONSTRAINT loans_user_data_length_check CHECK (char_length(user_data) <= 255),
    CONSTRAINT loans_notes_length_check CHECK (char_length(notes) <= 1000),

    -- Type checks
    CONSTRAINT loans_type_check CHECK (
        loan_type IN ('mortgage', 'auto', 'personal', 'student', 'credit_line', 'other')
    ),
    CONSTRAINT loans_interest_type_check CHECK (
        interest_type IN ('fixed', 'variable')
    ),
    CONSTRAINT loans_payment_frequency_check CHECK (
        payment_frequency IN ('monthly', 'bi-weekly', 'weekly', 'quarterly')
    ),
    CONSTRAINT loans_compounding_frequency_check CHECK (
        compounding_frequency IN ('daily', 'monthly', 'annually')
    ),
    CONSTRAINT loans_amortization_type_check CHECK (
        amortization_type IN ('standard', 'interest_only', 'balloon')
    ),
    CONSTRAINT loans_status_check CHECK (
        status IN ('active', 'paid_off', 'defaulted', 'refinanced', 'closed')
    ),

    -- Value checks
    CONSTRAINT loans_principal_positive CHECK (principal_amount > 0),
    CONSTRAINT loans_current_balance_non_negative CHECK (current_balance >= 0),
    CONSTRAINT loans_interest_rate_range CHECK (interest_rate >= 0 AND interest_rate <= 100),
    CONSTRAINT loans_term_positive CHECK (loan_term_months > 0),
    CONSTRAINT loans_remaining_non_negative CHECK (remaining_months >= 0),
    CONSTRAINT loans_remaining_not_exceed_term CHECK (remaining_months <= loan_term_months),
    CONSTRAINT loans_payment_non_negative CHECK (payment_amount >= 0),
    CONSTRAINT loans_balance_reasonable CHECK (current_balance <= principal_amount * 2),

    -- Date logic
    CONSTRAINT loans_first_payment_after_start CHECK (first_payment_date >= start_date),

    -- Payment day logic
    CONSTRAINT loans_payment_day_range CHECK (
        payment_day_of_month IS NULL OR
        (payment_day_of_month >= 1 AND payment_day_of_month <= 31)
    )
);

-- Indexes for performance
CREATE INDEX idx_loans_ledger_id ON data.loans(ledger_id);
CREATE INDEX idx_loans_account_id ON data.loans(account_id);
CREATE INDEX idx_loans_status ON data.loans(status);
CREATE INDEX idx_loans_user_data ON data.loans(user_data);

-- Comments
COMMENT ON TABLE data.loans IS
'Stores loan metadata including principal, interest rates, terms, and payment schedules.';

COMMENT ON COLUMN data.loans.lender_name IS 'Name of the lender or creditor';
COMMENT ON COLUMN data.loans.loan_type IS 'Type of loan: mortgage, auto, personal, student, credit_line, or other';
COMMENT ON COLUMN data.loans.principal_amount IS 'Original loan amount borrowed';
COMMENT ON COLUMN data.loans.current_balance IS 'Current outstanding balance on the loan';
COMMENT ON COLUMN data.loans.interest_rate IS 'Annual interest rate as a percentage (e.g., 5.25 for 5.25%)';
COMMENT ON COLUMN data.loans.interest_type IS 'Whether the interest rate is fixed or variable';
COMMENT ON COLUMN data.loans.compounding_frequency IS 'How often interest compounds: daily, monthly, or annually';
COMMENT ON COLUMN data.loans.loan_term_months IS 'Total term of the loan in months';
COMMENT ON COLUMN data.loans.remaining_months IS 'Number of months remaining on the loan';
COMMENT ON COLUMN data.loans.payment_amount IS 'Regular payment amount';
COMMENT ON COLUMN data.loans.payment_frequency IS 'Payment frequency: monthly, bi-weekly, weekly, or quarterly';
COMMENT ON COLUMN data.loans.payment_day_of_month IS 'Day of month payment is due (for monthly payments)';
COMMENT ON COLUMN data.loans.amortization_type IS 'Type of amortization: standard, interest_only, or balloon';
COMMENT ON COLUMN data.loans.status IS 'Current status: active, paid_off, defaulted, refinanced, or closed';

-- ----------------------------------------------------------------------------
-- TABLE: data.loan_payments
-- ----------------------------------------------------------------------------
-- Stores payment schedule and history for loans
CREATE TABLE data.loan_payments (
    -- Standard fields
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid            text        NOT NULL DEFAULT utils.nanoid(8),
    created_at      timestamptz NOT NULL DEFAULT current_timestamp,
    updated_at      timestamptz NOT NULL DEFAULT current_timestamp,
    user_data       text        NOT NULL DEFAULT utils.get_user(),

    -- Foreign keys
    loan_id         bigint      NOT NULL REFERENCES data.loans (id) ON DELETE CASCADE,
    transaction_id  bigint      NULL REFERENCES data.transactions (id) ON DELETE SET NULL,
    from_account_id bigint      NULL REFERENCES data.accounts (id) ON DELETE SET NULL,

    -- Payment identification
    payment_number  integer     NOT NULL,
    due_date        date        NOT NULL,

    -- Scheduled amounts
    scheduled_amount    numeric(19,4) NOT NULL,
    scheduled_principal numeric(19,4) NOT NULL,
    scheduled_interest  numeric(19,4) NOT NULL,

    -- Actual payment details
    paid_date           date          NULL,
    actual_amount_paid  numeric(19,4) NULL,
    actual_principal    numeric(19,4) NULL,
    actual_interest     numeric(19,4) NULL,

    -- Payment status
    status          text        NOT NULL DEFAULT 'scheduled',
    days_late       integer     NULL,
    notes           text        NULL,

    -- ========================================================================
    -- CONSTRAINTS
    -- ========================================================================

    -- Unique constraints
    CONSTRAINT loan_payments_uuid_unique UNIQUE (uuid),
    CONSTRAINT loan_payments_number_unique UNIQUE (loan_id, payment_number),

    -- Length checks
    CONSTRAINT loan_payments_user_data_length_check CHECK (char_length(user_data) <= 255),
    CONSTRAINT loan_payments_notes_length_check CHECK (char_length(notes) <= 500),

    -- Status check
    CONSTRAINT loan_payments_status_check CHECK (
        status IN ('scheduled', 'paid', 'partial', 'late', 'missed', 'skipped')
    ),

    -- Value checks
    CONSTRAINT loan_payments_payment_number_positive CHECK (payment_number > 0),
    CONSTRAINT loan_payments_scheduled_amount_non_negative CHECK (scheduled_amount >= 0),
    CONSTRAINT loan_payments_scheduled_principal_non_negative CHECK (scheduled_principal >= 0),
    CONSTRAINT loan_payments_scheduled_interest_non_negative CHECK (scheduled_interest >= 0),
    CONSTRAINT loan_payments_actual_amount_non_negative CHECK (actual_amount_paid IS NULL OR actual_amount_paid >= 0),
    CONSTRAINT loan_payments_actual_principal_non_negative CHECK (actual_principal IS NULL OR actual_principal >= 0),
    CONSTRAINT loan_payments_actual_interest_non_negative CHECK (actual_interest IS NULL OR actual_interest >= 0),
    CONSTRAINT loan_payments_days_late_non_negative CHECK (days_late IS NULL OR days_late >= 0),

    -- Payment logic
    CONSTRAINT loan_payments_scheduled_split CHECK (
        ABS(scheduled_amount - (scheduled_principal + scheduled_interest)) < 0.01
    ),
    CONSTRAINT loan_payments_actual_split CHECK (
        actual_amount_paid IS NULL OR
        ABS(actual_amount_paid - (COALESCE(actual_principal, 0) + COALESCE(actual_interest, 0))) < 0.01
    ),

    -- Paid payments must have details
    CONSTRAINT loan_payments_paid_has_date CHECK (
        status != 'paid' OR (paid_date IS NOT NULL AND actual_amount_paid IS NOT NULL)
    )
);

-- Indexes for performance
CREATE INDEX idx_loan_payments_loan_id ON data.loan_payments(loan_id);
CREATE INDEX idx_loan_payments_due_date ON data.loan_payments(due_date);
CREATE INDEX idx_loan_payments_status ON data.loan_payments(status);
CREATE INDEX idx_loan_payments_transaction_id ON data.loan_payments(transaction_id);
CREATE INDEX idx_loan_payments_user_data ON data.loan_payments(user_data);

-- Comments
COMMENT ON TABLE data.loan_payments IS
'Stores payment schedule and history for loans, including both scheduled and actual payment details.';

COMMENT ON COLUMN data.loan_payments.payment_number IS 'Sequential payment number (1, 2, 3, etc.)';
COMMENT ON COLUMN data.loan_payments.due_date IS 'Scheduled payment due date';
COMMENT ON COLUMN data.loan_payments.scheduled_amount IS 'Scheduled total payment amount';
COMMENT ON COLUMN data.loan_payments.scheduled_principal IS 'Scheduled principal portion of payment';
COMMENT ON COLUMN data.loan_payments.scheduled_interest IS 'Scheduled interest portion of payment';
COMMENT ON COLUMN data.loan_payments.paid_date IS 'Actual date payment was made (NULL if unpaid)';
COMMENT ON COLUMN data.loan_payments.actual_amount_paid IS 'Actual total amount paid';
COMMENT ON COLUMN data.loan_payments.actual_principal IS 'Actual principal portion paid';
COMMENT ON COLUMN data.loan_payments.actual_interest IS 'Actual interest portion paid';
COMMENT ON COLUMN data.loan_payments.transaction_id IS 'Link to the transaction record for this payment';
COMMENT ON COLUMN data.loan_payments.from_account_id IS 'Account the payment was made from';
COMMENT ON COLUMN data.loan_payments.status IS 'Payment status: scheduled, paid, partial, late, missed, or skipped';
COMMENT ON COLUMN data.loan_payments.days_late IS 'Number of days past due (if applicable)';

-- ============================================================================
-- ROW LEVEL SECURITY POLICIES
-- ============================================================================
-- Ensures users can only access their own loan data
-- Part of Step 1.4 of LOAN_MANAGEMENT_IMPLEMENTATION.md
-- ============================================================================

-- Enable RLS on loans table
ALTER TABLE data.loans ENABLE ROW LEVEL SECURITY;

-- Create RLS policy for loans
CREATE POLICY loans_policy ON data.loans
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

COMMENT ON POLICY loans_policy ON data.loans IS
'Ensures that users can only access and modify their own loans based on the user_data column.';

-- Enable RLS on loan_payments table
ALTER TABLE data.loan_payments ENABLE ROW LEVEL SECURITY;

-- Create RLS policy for loan_payments
CREATE POLICY loan_payments_policy ON data.loan_payments
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

COMMENT ON POLICY loan_payments_policy ON data.loan_payments IS
'Ensures that users can only access and modify their own loan payments based on the user_data column.';

-- ============================================================================
-- TRIGGERS
-- ============================================================================
-- Automatic timestamp updates and loan balance maintenance
-- ============================================================================

-- Trigger to update updated_at timestamp on loans
CREATE TRIGGER update_loans_updated_at
    BEFORE UPDATE ON data.loans
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

COMMENT ON TRIGGER update_loans_updated_at ON data.loans IS
'Automatically updates the updated_at timestamp when a loan record is modified.';

-- Trigger to update updated_at timestamp on loan_payments
CREATE TRIGGER update_loan_payments_updated_at
    BEFORE UPDATE ON data.loan_payments
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

COMMENT ON TRIGGER update_loan_payments_updated_at ON data.loan_payments IS
'Automatically updates the updated_at timestamp when a loan payment record is modified.';

-- ============================================================================
-- UTILITY FUNCTION: Update Loan Balance After Payment
-- ============================================================================
-- Updates the loan's current balance and remaining months when a payment is recorded
-- ============================================================================

CREATE OR REPLACE FUNCTION utils.update_loan_balance_after_payment()
RETURNS TRIGGER AS $$
BEGIN
    -- Only update if payment status changed to 'paid' or amount changed
    IF (NEW.status = 'paid' AND (OLD.status IS NULL OR OLD.status != 'paid'))
       OR (NEW.status = 'paid' AND NEW.actual_principal != OLD.actual_principal) THEN

        -- Update the loan's current balance and remaining months
        UPDATE data.loans
        SET
            current_balance = GREATEST(0, current_balance - COALESCE(NEW.actual_principal, 0)),
            remaining_months = GREATEST(0, remaining_months - 1),
            status = CASE
                WHEN current_balance - COALESCE(NEW.actual_principal, 0) <= 0.01 THEN 'paid_off'
                ELSE status
            END,
            updated_at = current_timestamp
        WHERE id = NEW.loan_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION utils.update_loan_balance_after_payment() IS
'Automatically updates loan balance and remaining months when a payment is recorded as paid.';

-- Trigger to update loan balance when payment is recorded
CREATE TRIGGER update_loan_balance_on_payment
    AFTER INSERT OR UPDATE ON data.loan_payments
    FOR EACH ROW
    WHEN (NEW.status = 'paid')
    EXECUTE FUNCTION utils.update_loan_balance_after_payment();

COMMENT ON TRIGGER update_loan_balance_on_payment ON data.loan_payments IS
'Updates the parent loan balance and remaining months when a payment is marked as paid.';

-- +goose StatementEnd

-- +goose Down
-- +goose StatementBegin

-- Drop triggers
DROP TRIGGER IF EXISTS update_loan_balance_on_payment ON data.loan_payments;
DROP TRIGGER IF EXISTS update_loan_payments_updated_at ON data.loan_payments;
DROP TRIGGER IF EXISTS update_loans_updated_at ON data.loans;

-- Drop utility functions
DROP FUNCTION IF EXISTS utils.update_loan_balance_after_payment();

-- Drop policies
DROP POLICY IF EXISTS loan_payments_policy ON data.loan_payments;
DROP POLICY IF EXISTS loans_policy ON data.loans;

-- Drop tables in reverse order
DROP TABLE IF EXISTS data.loan_payments;
DROP TABLE IF EXISTS data.loans;

-- +goose StatementEnd
