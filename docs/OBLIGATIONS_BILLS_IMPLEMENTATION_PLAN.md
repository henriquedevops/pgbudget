# Obligations and Bills Registration Implementation Plan

**Created:** 2025-11-15
**Status:** Planning
**Priority:** High
**Related Features:**
- Recurring Transactions (existing)
- Loan Payments (existing)
- Payment Reminders (future)

---

## Overview

This document outlines the plan for implementing a comprehensive **Obligations and Bills Registration** system in PGBudget. The feature will allow users to track upcoming bills, recurring obligations, and payment schedules for various financial commitments including utilities, subscriptions, school tuition, debt payments, and other recurring expenses.

---

## Problem Statement

Currently, PGBudget users face challenges managing upcoming financial obligations:

1. **No Centralized View** - Users cannot see all upcoming bills in one place
2. **Payment Tracking** - Difficult to track which bills have been paid vs. pending
3. **Due Date Management** - No visibility into upcoming due dates
4. **Budget Planning** - Hard to plan for recurring expenses
5. **Payment Reminders** - No system to alert users of upcoming bills
6. **Obligation Types** - Limited support for different types of obligations (utilities, subscriptions, tuition, etc.)

---

## Solution Overview

Implement a comprehensive **Obligations Management System** that includes:

### Core Features

1. **Obligation Registration**
   - Register bills and recurring obligations
   - Support multiple obligation types (utilities, rent, subscriptions, insurance, tuition, etc.)
   - Set payment frequency (monthly, quarterly, annually, custom)
   - Define payment amounts (fixed or variable)
   - Track payee/vendor information

2. **Payment Tracking**
   - Mark obligations as paid
   - Link payments to actual transactions
   - Track payment history
   - Identify missed or late payments
   - Support partial payments

3. **Due Date Management**
   - Calendar view of upcoming obligations
   - Color-coded status indicators (upcoming, due soon, overdue)
   - Flexible due date patterns
   - Grace period support

4. **Budgeting Integration**
   - Project upcoming expenses
   - Include obligations in budget forecasting
   - Alert when obligations exceed budget
   - Category-based obligation grouping

5. **Payment Reminders**
   - Email notifications for upcoming bills
   - Configurable reminder timing (X days before due)
   - Dashboard widgets showing upcoming bills
   - Overdue alerts

---

## Types of Obligations Supported

### 1. Utilities
- Electricity
- Water/Sewer
- Gas
- Internet/Cable
- Phone/Mobile

### 2. Housing
- Rent/Mortgage (linked to existing loan system)
- HOA Fees
- Property Taxes
- Homeowners Insurance
- Renters Insurance

### 3. Subscriptions
- Streaming Services (Netflix, Spotify, etc.)
- Software/SaaS
- Gym Memberships
- Magazine/News Subscriptions
- Cloud Storage

### 4. Education
- Tuition Payments
- Student Loan Payments (linked to existing loan system)
- School Fees
- Textbooks/Supplies
- Childcare/Daycare

### 5. Debt Payments
- Credit Card Payments (linked to credit card system)
- Personal Loans (linked to existing loan system)
- Medical Bills
- Installment Plans

### 6. Insurance
- Health Insurance
- Auto Insurance
- Life Insurance
- Disability Insurance

### 7. Taxes & Government
- Income Tax Estimates
- Property Tax
- Vehicle Registration
- Professional Licenses

### 8. Other
- Charitable Donations
- Child Support/Alimony
- Pet Care
- Custom Categories

---

## Database Schema

### New Table: `data.obligations`

```sql
CREATE TABLE data.obligations (
    id BIGSERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL DEFAULT utils.generate_uuid(),
    user_data TEXT NOT NULL,
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Obligation Details
    name TEXT NOT NULL,
    description TEXT,
    obligation_type TEXT NOT NULL, -- 'utility', 'rent', 'subscription', 'tuition', 'debt', 'insurance', 'tax', 'other'
    obligation_subtype TEXT, -- specific type like 'electricity', 'netflix', 'student_loan'

    -- Payee Information
    payee_name TEXT NOT NULL,
    payee_id BIGINT REFERENCES data.payees(id) ON DELETE SET NULL,
    account_number TEXT, -- utility account #, policy #, etc.

    -- Payment Account
    default_payment_account_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,
    default_category_id BIGINT REFERENCES data.categories(id) ON DELETE SET NULL,

    -- Amount Details
    is_fixed_amount BOOLEAN DEFAULT true,
    fixed_amount DECIMAL(15,2),
    estimated_amount DECIMAL(15,2), -- for variable bills
    amount_range_min DECIMAL(15,2),
    amount_range_max DECIMAL(15,2),
    currency TEXT DEFAULT 'USD',

    -- Frequency & Scheduling
    frequency TEXT NOT NULL, -- 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom'
    custom_frequency_days INTEGER, -- for custom frequency

    -- Due Date Pattern
    due_day_of_month INTEGER, -- 1-31, or NULL for weekly/custom
    due_day_of_week INTEGER, -- 0-6 (Sun-Sat) for weekly
    due_months INTEGER[], -- for annual/semiannual (e.g., {1,7} for Jan and Jul)

    -- Start and End Dates
    start_date DATE NOT NULL,
    end_date DATE, -- NULL for indefinite

    -- Reminder Settings
    reminder_enabled BOOLEAN DEFAULT true,
    reminder_days_before INTEGER DEFAULT 3,
    reminder_email BOOLEAN DEFAULT true,
    reminder_dashboard BOOLEAN DEFAULT true,

    -- Grace Period
    grace_period_days INTEGER DEFAULT 0,
    late_fee_amount DECIMAL(15,2),

    -- Auto-Payment Settings
    auto_pay_enabled BOOLEAN DEFAULT false,
    auto_create_transaction BOOLEAN DEFAULT false, -- link to recurring transactions
    recurring_transaction_id BIGINT REFERENCES data.recurring_transactions(id) ON DELETE SET NULL,

    -- Linked Resources
    linked_loan_id BIGINT REFERENCES data.loans(id) ON DELETE SET NULL,
    linked_credit_card_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Status
    is_active BOOLEAN DEFAULT true,
    is_paused BOOLEAN DEFAULT false,
    pause_until DATE,

    -- Notes
    notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT obligations_valid_frequency CHECK (
        frequency IN ('weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'custom')
    ),
    CONSTRAINT obligations_valid_due_day CHECK (
        due_day_of_month IS NULL OR (due_day_of_month >= 1 AND due_day_of_month <= 31)
    ),
    CONSTRAINT obligations_valid_due_dow CHECK (
        due_day_of_week IS NULL OR (due_day_of_week >= 0 AND due_day_of_week <= 6)
    ),
    CONSTRAINT obligations_amount_required CHECK (
        is_fixed_amount = false OR fixed_amount IS NOT NULL
    )
);

-- Indexes
CREATE INDEX idx_obligations_user ON data.obligations(user_data);
CREATE INDEX idx_obligations_ledger ON data.obligations(ledger_id);
CREATE INDEX idx_obligations_type ON data.obligations(obligation_type);
CREATE INDEX idx_obligations_active ON data.obligations(is_active) WHERE is_active = true;
CREATE INDEX idx_obligations_payee ON data.obligations(payee_id);
CREATE INDEX idx_obligations_next_due ON data.obligations(user_data, is_active) WHERE is_active = true;

-- RLS Policies
ALTER TABLE data.obligations ENABLE ROW LEVEL SECURITY;

CREATE POLICY obligations_isolation ON data.obligations
    USING (user_data = utils.get_user());
```

### New Table: `data.obligation_payments`

```sql
CREATE TABLE data.obligation_payments (
    id BIGSERIAL PRIMARY KEY,
    uuid TEXT UNIQUE NOT NULL DEFAULT utils.generate_uuid(),
    user_data TEXT NOT NULL,

    -- Link to Obligation
    obligation_id BIGINT NOT NULL REFERENCES data.obligations(id) ON DELETE CASCADE,

    -- Payment Schedule
    due_date DATE NOT NULL,
    scheduled_amount DECIMAL(15,2) NOT NULL,

    -- Payment Status
    status TEXT NOT NULL DEFAULT 'scheduled', -- 'scheduled', 'paid', 'partial', 'late', 'missed', 'skipped'

    -- Actual Payment Details
    paid_date DATE,
    actual_amount_paid DECIMAL(15,2),
    transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,
    transaction_uuid TEXT,

    -- Payment Method
    payment_account_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,
    payment_method TEXT, -- 'bank_transfer', 'credit_card', 'cash', 'check', 'autopay'
    confirmation_number TEXT,

    -- Late Payment Tracking
    days_late INTEGER,
    late_fee_charged DECIMAL(15,2) DEFAULT 0,
    late_fee_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,

    -- Notes
    notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_marked_at TIMESTAMP,

    -- Constraints
    CONSTRAINT obligation_payments_valid_status CHECK (
        status IN ('scheduled', 'paid', 'partial', 'late', 'missed', 'skipped')
    ),
    CONSTRAINT obligation_payments_paid_requires_date CHECK (
        status != 'paid' OR paid_date IS NOT NULL
    )
);

-- Indexes
CREATE INDEX idx_obligation_payments_user ON data.obligation_payments(user_data);
CREATE INDEX idx_obligation_payments_obligation ON data.obligation_payments(obligation_id);
CREATE INDEX idx_obligation_payments_due_date ON data.obligation_payments(due_date);
CREATE INDEX idx_obligation_payments_status ON data.obligation_payments(status);
CREATE INDEX idx_obligation_payments_transaction ON data.obligation_payments(transaction_id);
CREATE INDEX idx_obligation_payments_upcoming ON data.obligation_payments(user_data, due_date, status)
    WHERE status IN ('scheduled', 'partial');

-- RLS Policies
ALTER TABLE data.obligation_payments ENABLE ROW LEVEL SECURITY;

CREATE POLICY obligation_payments_isolation ON data.obligation_payments
    USING (user_data = utils.get_user());
```

---

## API Functions

### Core Functions

#### 1. Create Obligation

```sql
CREATE FUNCTION api.create_obligation(
    p_ledger_uuid TEXT,
    p_name TEXT,
    p_payee_name TEXT,
    p_obligation_type TEXT,
    p_frequency TEXT,
    p_is_fixed_amount BOOLEAN,
    p_fixed_amount DECIMAL DEFAULT NULL,
    p_estimated_amount DECIMAL DEFAULT NULL,
    p_start_date DATE,
    p_due_day_of_month INTEGER DEFAULT NULL,
    p_default_payment_account_uuid TEXT DEFAULT NULL,
    p_default_category_uuid TEXT DEFAULT NULL,
    p_reminder_days_before INTEGER DEFAULT 3,
    p_notes TEXT DEFAULT NULL
) RETURNS TABLE (
    uuid TEXT,
    name TEXT,
    next_due_date DATE
) AS $$
-- Implementation creates obligation and generates initial payment schedule
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

#### 2. Update Obligation

```sql
CREATE FUNCTION api.update_obligation(
    p_obligation_uuid TEXT,
    p_name TEXT DEFAULT NULL,
    p_payee_name TEXT DEFAULT NULL,
    p_fixed_amount DECIMAL DEFAULT NULL,
    p_estimated_amount DECIMAL DEFAULT NULL,
    p_reminder_days_before INTEGER DEFAULT NULL,
    p_is_active BOOLEAN DEFAULT NULL,
    p_notes TEXT DEFAULT NULL
) RETURNS TABLE (
    uuid TEXT,
    name TEXT,
    updated_at TIMESTAMP
) AS $$
-- Implementation updates obligation details
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

#### 3. Get Upcoming Obligations

```sql
CREATE FUNCTION api.get_upcoming_obligations(
    p_ledger_uuid TEXT,
    p_days_ahead INTEGER DEFAULT 30,
    p_include_overdue BOOLEAN DEFAULT true
) RETURNS TABLE (
    obligation_uuid TEXT,
    payment_uuid TEXT,
    name TEXT,
    payee_name TEXT,
    due_date DATE,
    amount DECIMAL,
    status TEXT,
    days_until_due INTEGER,
    is_overdue BOOLEAN
) AS $$
-- Implementation returns upcoming payments sorted by due date
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

#### 4. Mark Payment as Paid

```sql
CREATE FUNCTION api.mark_obligation_paid(
    p_payment_uuid TEXT,
    p_paid_date DATE,
    p_actual_amount DECIMAL,
    p_transaction_uuid TEXT DEFAULT NULL,
    p_payment_account_uuid TEXT DEFAULT NULL,
    p_confirmation_number TEXT DEFAULT NULL
) RETURNS TABLE (
    payment_uuid TEXT,
    status TEXT,
    next_due_date DATE
) AS $$
-- Implementation marks payment as paid and generates next scheduled payment
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

#### 5. Generate Payment Schedule

```sql
CREATE FUNCTION api.generate_obligation_schedule(
    p_obligation_id BIGINT,
    p_months_ahead INTEGER DEFAULT 12
) RETURNS INTEGER AS $$
-- Implementation generates payment schedule based on obligation frequency
-- Returns number of payments created
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

#### 6. Link Transaction to Obligation Payment

```sql
CREATE FUNCTION api.link_transaction_to_obligation(
    p_transaction_uuid TEXT,
    p_payment_uuid TEXT
) RETURNS BOOLEAN AS $$
-- Implementation links an existing transaction to an obligation payment
-- Automatically marks payment as paid
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

---

## User Interface

### 1. Obligations Dashboard (`public/obligations/index.php`)

**Purpose:** Central hub for viewing all obligations and upcoming bills

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│  📋 Obligations & Bills                              │
├─────────────────────────────────────────────────────┤
│  [+ New Obligation]  [Calendar View]  [List View]   │
├─────────────────────────────────────────────────────┤
│  Upcoming Bills (Next 30 Days)                      │
│  ┌───────────────────────────────────────────────┐ │
│  │ ⚠️ OVERDUE (2)                                 │ │
│  │  • Electric Bill - $85.00 - 3 days overdue    │ │
│  │  • Water Bill - $45.00 - 1 day overdue        │ │
│  └───────────────────────────────────────────────┘ │
│  ┌───────────────────────────────────────────────┐ │
│  │ 🔔 DUE SOON (Next 7 Days)                     │ │
│  │  • Netflix - $15.99 - Due Nov 18              │ │
│  │  • Rent - $1,500.00 - Due Nov 20              │ │
│  │  • Car Insurance - $125.00 - Due Nov 22       │ │
│  └───────────────────────────────────────────────┘ │
│  ┌───────────────────────────────────────────────┐ │
│  │ 📅 UPCOMING (8-30 Days)                       │ │
│  │  • Spotify - $9.99 - Due Dec 1                │ │
│  │  • Internet - $69.99 - Due Dec 5              │ │
│  │  • Phone - $80.00 - Due Dec 10                │ │
│  └───────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────┤
│  Monthly Obligation Summary                         │
│  Total Monthly Obligations: $2,543.97               │
│  Paid This Month: $1,245.00 (49%)                   │
│  Remaining: $1,298.97                               │
└─────────────────────────────────────────────────────┘
```

**Features:**
- Color-coded status indicators
- Quick pay buttons
- Mark as paid functionality
- View payment history
- Filter by obligation type
- Search functionality

---

### 2. Create/Edit Obligation Form (`public/obligations/create.php`, `edit.php`)

**Form Sections:**

#### Basic Information
```
Name: _____________ (e.g., "Electric Bill - Main Street")
Payee: _____________ (searchable dropdown, creates new if needed)
Type: [Dropdown: Utilities, Rent, Subscription, etc.]
Subtype: _____________ (e.g., "Electricity")
```

#### Amount Details
```
Amount Type:
  ( ) Fixed Amount: $______
  ( ) Variable/Estimated: $______ (average)
      Min: $______ Max: $______
```

#### Payment Frequency
```
Frequency: [Monthly ▼]
  Options: Weekly, Biweekly, Monthly, Quarterly, Semi-Annual, Annual, Custom

Due Date:
  [For Monthly] Day of Month: [1-31]
  [For Weekly] Day of Week: [Mon/Tue/Wed/etc.]
  [For Annual] Months: [✓ Jan] [ ] Feb [✓ Jul] ...
```

#### Payment Settings
```
Default Payment Account: [Checking Account ▼]
Default Category: [Utilities ▼]

Auto-Payment:
  [ ] Enable automatic transaction creation
  [ ] Link to recurring transaction (if exists)
```

#### Reminders
```
[ ] Enable payment reminders
Remind me: [3] days before due date
  [✓] Email notification
  [✓] Dashboard alert
```

#### Date Range
```
Start Date: [YYYY-MM-DD]
End Date: [YYYY-MM-DD] or [ ] No end date
```

#### Advanced Settings
```
Grace Period: [0] days
Late Fee: $_____ (if applicable)
Account Number: _____________ (utility account #, policy #, etc.)
Notes: _________________________
```

---

### 3. Payment History View (`public/obligations/payments.php`)

**Purpose:** View and manage payment history for a specific obligation

```
┌─────────────────────────────────────────────────────┐
│  Electric Bill - Main Street                        │
│  Payee: City Electric Company                       │
│  Account: 123-456-789                               │
├─────────────────────────────────────────────────────┤
│  Obligation Details                                 │
│  Amount: ~$85.00 (variable)                         │
│  Frequency: Monthly (due 15th)                      │
│  Next Due: Dec 15, 2025 ($85.00)                    │
│  [Mark as Paid] [Edit] [Pause] [Delete]             │
├─────────────────────────────────────────────────────┤
│  Payment History                                    │
│  ┌───────────────────────────────────────────────┐ │
│  │ Due Date   │ Amount   │ Paid Date │ Status   │ │
│  ├───────────────────────────────────────────────┤ │
│  │ Nov 15     │ $85.00   │ Nov 14    │ ✓ Paid   │ │
│  │ Oct 15     │ $82.50   │ Oct 16    │ ⚠️ Late  │ │
│  │ Sep 15     │ $88.00   │ Sep 12    │ ✓ Paid   │ │
│  │ Aug 15     │ $91.20   │ Aug 15    │ ✓ Paid   │ │
│  └───────────────────────────────────────────────┘ │
│                                                     │
│  Statistics                                         │
│  Average Payment: $86.68                            │
│  Total Paid (12 months): $1,040.10                  │
│  On-Time Payments: 92%                              │
└─────────────────────────────────────────────────────┘
```

---

### 4. Calendar View (`public/obligations/calendar.php`)

**Purpose:** Visual calendar showing all upcoming obligations

```
┌─────────────────────────────────────────────────────┐
│  November 2025                                      │
│  [< Prev Month]  [Today]  [Next Month >]            │
├─────────────────────────────────────────────────────┤
│  Sun  Mon  Tue  Wed  Thu  Fri  Sat                  │
│  ─────────────────────────────────────────────────  │
│        1    2    3    4    5    6                   │
│                            Netflix                  │
│                            $15.99                   │
│                                                     │
│   7    8    9   10   11   12   13                   │
│                                                     │
│                                                     │
│  14   15   16   17   18   19   20                   │
│      Electric  Phone      Rent                     │
│      $85.00   $80.00     $1,500                    │
│                                                     │
│  21   22   23   24   25   26   27                   │
│      Insurance                                      │
│      $125.00                                        │
│                                                     │
│  28   29   30                                       │
└─────────────────────────────────────────────────────┘
```

---

### 5. Quick Mark as Paid Modal

**Purpose:** Quick interface to mark obligation as paid

```
┌─────────────────────────────────────────┐
│  Mark Payment as Paid                   │
├─────────────────────────────────────────┤
│  Obligation: Electric Bill              │
│  Due Date: Nov 15, 2025                 │
│  Scheduled Amount: $85.00               │
│                                         │
│  Actual Amount Paid: [$85.00]           │
│  Payment Date: [Nov 14, 2025]           │
│  Payment Account: [Checking ▼]          │
│                                         │
│  [ ] Create transaction automatically   │
│  [ ] Link to existing transaction       │
│     Transaction: [Search...▼]           │
│                                         │
│  Confirmation #: [Optional]             │
│  Notes: [Optional]                      │
│                                         │
│  [Cancel]  [Mark as Paid]               │
└─────────────────────────────────────────┘
```

---

## Integration Points

### 1. Integration with Transactions

**When creating a transaction:**
- Check if amount/payee matches an upcoming obligation
- Suggest linking transaction to obligation payment
- Auto-mark obligation as paid if linked

**Transaction form changes (`public/transactions/add.php`):**
```html
<!-- After loan payment section -->
<div id="obligation-payment-section" style="display: none;">
    <label>
        <input type="checkbox" id="link-to-obligation" name="link_to_obligation">
        Link to Obligation Payment
    </label>

    <div id="obligation-selection" style="display: none;">
        <select id="obligation_payment_uuid" name="obligation_payment_uuid">
            <option value="">Select obligation payment...</option>
            <!-- Populated via AJAX -->
        </select>
    </div>
</div>
```

### 2. Integration with Recurring Transactions

**Option to auto-create from obligations:**
- Convert obligation to recurring transaction
- Sync payment schedules
- Bidirectional linking

### 3. Integration with Budget

**Budget view enhancements:**
- Show upcoming obligations in budget period
- Compare budgeted vs. actual obligation amounts
- Alert if obligations exceed budget category

### 4. Integration with Dashboard

**Dashboard widgets:**
```html
<!-- Upcoming Bills Widget -->
<div class="dashboard-widget">
    <h3>📋 Upcoming Bills (Next 7 Days)</h3>
    <ul>
        <li class="overdue">Electric - $85.00 - OVERDUE</li>
        <li class="due-soon">Netflix - $15.99 - Due Nov 18</li>
        <li>Rent - $1,500.00 - Due Nov 20</li>
    </ul>
    <a href="/obligations">View All →</a>
</div>
```

---

## Implementation Phases

### Phase 1: Database & Core API (Week 1)
**Estimated Time:** 12-16 hours

- [ ] Create database migration with tables
- [ ] Implement RLS policies
- [ ] Create core API functions
  - [ ] `api.create_obligation()`
  - [ ] `api.update_obligation()`
  - [ ] `api.get_upcoming_obligations()`
  - [ ] `api.mark_obligation_paid()`
  - [ ] `api.generate_obligation_schedule()`
- [ ] Create trigger for automatic schedule generation
- [ ] Write unit tests for API functions

**Deliverables:**
- Migration file: `20251115_create_obligations_system.sql`
- API functions tested and working
- Sample data for testing

---

### Phase 2: Obligations List & Basic UI (Week 2)
**Estimated Time:** 16-20 hours

- [ ] Create obligations list page (`public/obligations/index.php`)
- [ ] Implement status grouping (overdue, due soon, upcoming)
- [ ] Add filter and search functionality
- [ ] Create "Mark as Paid" quick action
- [ ] Build monthly summary calculations
- [ ] Style with responsive CSS
- [ ] Add navigation menu item

**Deliverables:**
- Functional obligations dashboard
- Mobile-responsive layout
- Filter/search working

---

### Phase 3: Create/Edit Forms (Week 3)
**Estimated Time:** 12-16 hours

- [ ] Build create obligation form (`public/obligations/create.php`)
- [ ] Build edit obligation form (`public/obligations/edit.php`)
- [ ] Implement frequency selector with dynamic UI
- [ ] Add payee autocomplete/creation
- [ ] Implement form validation
- [ ] Create API endpoint for form submission
- [ ] Add success/error handling

**Deliverables:**
- Complete create/edit forms
- Form validation working
- Payee integration complete

---

### Phase 4: Payment History & Details (Week 4)
**Estimated Time:** 10-14 hours

- [ ] Create payment history view (`public/obligations/payments.php`)
- [ ] Display payment timeline
- [ ] Show payment statistics
- [ ] Implement edit payment functionality
- [ ] Add payment method tracking
- [ ] Create confirmation number tracking
- [ ] Build payment export (CSV)

**Deliverables:**
- Payment history page
- Statistics calculations
- Export functionality

---

### Phase 5: Transaction Integration (Week 5)
**Estimated Time:** 14-18 hours

- [ ] Modify transaction form to support obligation linking
- [ ] Create obligation payment matcher
- [ ] Build suggestion engine (match by amount/payee/date)
- [ ] Implement auto-linking on transaction creation
- [ ] Add obligation indicator on transaction list
- [ ] Update transaction details to show linked obligation
- [ ] Create API endpoint for linking

**Deliverables:**
- Transaction form with obligation linking
- Automatic matching working
- Bidirectional navigation (transaction ↔ obligation)

---

### Phase 6: Calendar & Advanced Views (Week 6)
**Estimated Time:** 12-16 hours

- [ ] Create calendar view (`public/obligations/calendar.php`)
- [ ] Implement month navigation
- [ ] Add day click handler for details
- [ ] Build obligation detail modal
- [ ] Create print-friendly view
- [ ] Add export to iCal format
- [ ] Implement dashboard widget

**Deliverables:**
- Interactive calendar view
- iCal export functionality
- Dashboard integration

---

### Phase 7: Reminders & Notifications (Week 7)
**Estimated Time:** 16-20 hours

- [ ] Create notification preferences system
- [ ] Build email reminder cron job
- [ ] Create email templates for reminders
- [ ] Implement dashboard notifications
- [ ] Add "dismiss" functionality for reminders
- [ ] Create notification history
- [ ] Build SMS reminder support (optional)

**Deliverables:**
- Email reminder system
- Cron job configured
- Dashboard notifications
- User preferences

---

### Phase 8: Reporting & Analytics (Week 8)
**Estimated Time:** 10-14 hours

- [ ] Create obligations report page
- [ ] Build payment trends chart
- [ ] Implement on-time payment tracking
- [ ] Create annual obligation forecast
- [ ] Add category-based grouping
- [ ] Build comparison reports (month-over-month)
- [ ] Export reports to PDF/CSV

**Deliverables:**
- Reporting dashboard
- Visual charts
- Export functionality

---

### Phase 9: Testing & Polish (Week 9)
**Estimated Time:** 12-16 hours

- [ ] Comprehensive user testing
- [ ] Fix bugs identified
- [ ] Performance optimization
- [ ] Mobile responsiveness verification
- [ ] Cross-browser testing
- [ ] Accessibility improvements
- [ ] Documentation updates

**Deliverables:**
- Bug-free system
- Performance optimized
- Documentation complete

---

## Total Estimated Timeline

**Total Development Time:** 9 weeks (114-150 hours)

**Team Size:** 1 developer

**Suggested Breakdown:**
- Week 1: Database & API Foundation
- Week 2: Core UI (List View)
- Week 3: Forms (Create/Edit)
- Week 4: Payment History
- Week 5: Transaction Integration
- Week 6: Calendar & Advanced Views
- Week 7: Reminders & Notifications
- Week 8: Reporting
- Week 9: Testing & Polish

---

## Success Metrics

### User Adoption
- % of users who create at least one obligation
- Average # of obligations per user
- # of payments marked via system

### User Engagement
- Daily/weekly active users on obligations page
- % of bills marked as paid on time
- Reminder open/click rates

### System Performance
- Page load time < 2 seconds
- API response time < 500ms
- Email delivery rate > 95%

### Business Impact
- Reduction in missed payments (user surveys)
- User satisfaction score (NPS)
- Feature usage vs. other features

---

## Risk Assessment

### Technical Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Schedule generation complexity | High | Implement robust date calculation library, extensive testing |
| Email delivery failures | Medium | Use reliable email service (SendGrid, Mailgun), implement retry logic |
| Performance with many obligations | Medium | Add pagination, optimize queries, implement caching |
| Calendar view complexity | Medium | Use proven calendar library (FullCalendar.js) |
| Transaction matching accuracy | High | Implement fuzzy matching with user confirmation |

### Business Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Low user adoption | High | Focus on UX, provide migration tools, user education |
| Feature complexity confusion | Medium | Provide onboarding tutorial, tooltips, help documentation |
| Duplicate data entry | Medium | Provide import tools, integration with recurring transactions |

---

## Future Enhancements

### Post-Launch Features

1. **Bill Pay Integration**
   - Connect to bank APIs
   - Initiate payments directly
   - Auto-mark as paid when payment clears

2. **Smart Categorization**
   - ML-based payee detection
   - Auto-categorize new bills
   - Suggest obligation creation from transactions

3. **Bill Negotiation Tracking**
   - Track rate changes
   - Set price alerts
   - Reminder to review/negotiate

4. **Shared Obligations**
   - Split bills with roommates/family
   - Track who paid what
   - Settlement calculations

5. **Mobile App**
   - Native iOS/Android apps
   - Push notifications
   - Quick pay from phone

6. **Voice Assistant Integration**
   - "Alexa, what bills are due this week?"
   - "Google, mark electric bill as paid"

7. **Debt Payoff Calculator**
   - Optimize payment order
   - Show interest savings
   - Suggest extra payments

---

## Documentation Requirements

### User Documentation

1. **Getting Started Guide**
   - How to create first obligation
   - Understanding obligation types
   - Setting up reminders

2. **User Manual**
   - Complete feature documentation
   - Screenshots and examples
   - FAQ section

3. **Video Tutorials**
   - Creating obligations
   - Marking payments
   - Using calendar view
   - Setting up reminders

### Developer Documentation

1. **API Documentation**
   - Function signatures
   - Parameter descriptions
   - Example usage
   - Error codes

2. **Database Schema Documentation**
   - Table descriptions
   - Relationship diagrams
   - RLS policy explanations

3. **Integration Guide**
   - How to integrate with other features
   - Webhook documentation
   - Extension points

---

## Security Considerations

### Data Protection

1. **Row-Level Security**
   - All tables use RLS
   - User isolation enforced
   - Ledger-based access control

2. **Input Validation**
   - Sanitize all user input
   - Validate amounts and dates
   - Prevent SQL injection
   - XSS protection

3. **Email Security**
   - Validate email addresses
   - Rate limiting on sends
   - Unsubscribe links
   - SPF/DKIM configuration

4. **Financial Data**
   - Encrypt sensitive fields
   - Audit trail for payments
   - Secure confirmation numbers
   - PCI compliance (if storing payment methods)

---

## Migration Strategy

### For Existing Users

1. **Import from Recurring Transactions**
   - One-click convert recurring transaction to obligation
   - Maintain historical data
   - Link automatically

2. **Bulk Import**
   - CSV upload for multiple obligations
   - Template download
   - Validation and preview before import

3. **Manual Entry**
   - Guided wizard for new users
   - Sample obligations provided
   - Optional templates (common bills)

### Data Preservation

- No breaking changes to existing features
- All conversions are opt-in
- Ability to undo conversions
- Export data before migration

---

## Testing Strategy

### Unit Tests

- API function testing
- Date calculation accuracy
- Payment schedule generation
- Amount calculations
- Status transitions

### Integration Tests

- Transaction linking
- Recurring transaction sync
- Email sending
- Cron job execution
- Budget integration

### User Acceptance Tests

- Create obligation workflow
- Mark payment workflow
- Edit obligation workflow
- Delete obligation workflow
- Calendar navigation
- Report generation

### Performance Tests

- Load testing with 1000+ obligations
- Concurrent user testing
- Database query optimization
- Email batch sending
- API response time

---

## Dependencies

### External Libraries

- **FullCalendar.js** - Calendar view (MIT License)
- **Chart.js** - Analytics charts (MIT License)
- **Moment.js** - Date manipulation (MIT License)
- **Email Service** - SendGrid or Mailgun API

### Internal Dependencies

- Existing transaction system
- Recurring transactions system
- Payee management
- Account system
- Category system
- Budget system (for integration)

---

## Accessibility Requirements

### WCAG 2.1 Level AA Compliance

- Keyboard navigation support
- Screen reader compatibility
- Color contrast requirements
- Focus indicators
- ARIA labels
- Alt text for icons
- Responsive text sizing
- Form error announcements

---

## Localization Considerations

### Multi-Currency Support

- Use existing currency system
- Store amounts in account currency
- Display in user's preferred currency

### Date Formats

- Respect user's locale
- Support different due date patterns
- Handle timezones correctly

### Text Translation

- Extract all UI text to translation files
- Support RTL languages
- Number formatting per locale

---

## Summary

This implementation plan provides a comprehensive roadmap for building a robust obligations and bills registration system in PGBudget. The phased approach allows for iterative development and testing, ensuring each component is solid before moving to the next.

The system will provide users with:
- ✅ Centralized bill management
- ✅ Payment tracking and history
- ✅ Automated reminders
- ✅ Budget integration
- ✅ Transaction linking
- ✅ Calendar visualization
- ✅ Payment analytics

**Next Steps:**
1. Review and approve this plan
2. Refine Phase 1 specifications
3. Create GitHub issues for each phase
4. Begin database design and API implementation
5. Set up project timeline and milestones

---

*Document Created: 2025-11-15*
*Author: Development Team*
*Version: 1.0*
*Status: Planning - Awaiting Approval*
