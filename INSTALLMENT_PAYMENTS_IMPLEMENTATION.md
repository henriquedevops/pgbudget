# Credit Card Installment Payments - Implementation Plan

## Overview
This plan outlines the implementation of an installment payment feature for credit card purchases. This feature allows users to split large credit card purchases into multiple monthly payments, helping with budgeting and cash flow management.

## Use Case
When a user makes a large purchase on their credit card (e.g., $1,200 laptop), they can choose to spread the payment across multiple months (e.g., 6 months × $200/month). This helps:
- Track the full purchase amount immediately
- Budget for monthly installment payments
- Avoid overspending categories in a single month
- Match payment schedules offered by credit card companies or retailers

## Architecture Overview

### Existing Systems to Integrate With
1. **Transactions System** - Double-entry accounting for all money movement
2. **Accounts System** - Asset, Liability, and Equity accounts
3. **Categories System** - Budget categories (equity accounts)
4. **Recurring Transactions** - Already handles scheduled future transactions
5. **Credit Card Payment System** - Handles CC payment tracking

### Key Difference from Loans
- **Loans**: Track external debt with interest calculations, payment schedules, and principal/interest splits
- **Installments**: Internal budgeting tool to spread a single transaction's impact across multiple budget periods
- Loans are "real" financial obligations; installments are budgeting helpers

---

## Phase 1: Database Schema

### Step 1.1: Create Installment Plans Table
**File**: `migrations/create_installment_plans_table.sql`

**Purpose**: Track the master installment plan for a purchase

```sql
CREATE TABLE data.installment_plans (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_data TEXT NOT NULL DEFAULT utils.get_user(),
    ledger_id BIGINT NOT NULL REFERENCES data.ledgers(id) ON DELETE CASCADE,

    -- Original transaction details
    original_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE CASCADE,
    purchase_amount NUMERIC(19,4) NOT NULL,
    purchase_date DATE NOT NULL,
    description TEXT NOT NULL,

    -- Credit card information
    credit_card_account_id BIGINT NOT NULL REFERENCES data.accounts(id) ON DELETE CASCADE,

    -- Installment details
    number_of_installments INTEGER NOT NULL,
    installment_amount NUMERIC(19,4) NOT NULL,
    frequency TEXT NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,

    -- Category assignment
    category_account_id BIGINT REFERENCES data.accounts(id) ON DELETE SET NULL,

    -- Status tracking
    status TEXT NOT NULL DEFAULT 'active',
    completed_installments INTEGER NOT NULL DEFAULT 0,

    -- Optional
    notes TEXT,
    metadata JSONB,

    CONSTRAINT installment_plans_uuid_unique UNIQUE(uuid),
    CONSTRAINT installment_plans_purchase_positive CHECK (purchase_amount > 0),
    CONSTRAINT installment_plans_installments_positive CHECK (number_of_installments > 0),
    CONSTRAINT installment_plans_installment_amount_positive CHECK (installment_amount > 0),
    CONSTRAINT installment_plans_frequency_check CHECK (frequency IN ('monthly', 'bi-weekly', 'weekly')),
    CONSTRAINT installment_plans_status_check CHECK (status IN ('active', 'completed', 'cancelled')),
    CONSTRAINT installment_plans_completed_range CHECK (completed_installments >= 0 AND completed_installments <= number_of_installments),
    CONSTRAINT installment_plans_user_data_length_check CHECK (char_length(user_data) <= 255),
    CONSTRAINT installment_plans_description_length_check CHECK (char_length(description) <= 255),
    CONSTRAINT installment_plans_notes_length_check CHECK (char_length(notes) <= 1000)
);

-- Indexes
CREATE INDEX idx_installment_plans_ledger_id ON data.installment_plans(ledger_id);
CREATE INDEX idx_installment_plans_user_data ON data.installment_plans(user_data);
CREATE INDEX idx_installment_plans_status ON data.installment_plans(status);
CREATE INDEX idx_installment_plans_credit_card ON data.installment_plans(credit_card_account_id);
CREATE INDEX idx_installment_plans_category ON data.installment_plans(category_account_id);

-- RLS Policy
ALTER TABLE data.installment_plans ENABLE ROW LEVEL SECURITY;

CREATE POLICY installment_plans_policy ON data.installment_plans
    USING (user_data = utils.get_user())
    WITH CHECK (user_data = utils.get_user());

-- Trigger for updated_at
CREATE TRIGGER update_installment_plans_updated_at
    BEFORE UPDATE ON data.installment_plans
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Comments
COMMENT ON TABLE data.installment_plans IS 'Tracks installment payment plans for credit card purchases';
COMMENT ON COLUMN data.installment_plans.original_transaction_id IS 'Reference to the original purchase transaction';
COMMENT ON COLUMN data.installment_plans.purchase_amount IS 'Total purchase amount before installments';
COMMENT ON COLUMN data.installment_plans.installment_amount IS 'Amount of each installment payment';
COMMENT ON COLUMN data.installment_plans.completed_installments IS 'Number of installments that have been processed';
```

### Step 1.2: Create Installment Schedules Table
**File**: `migrations/create_installment_schedules_table.sql`

**Purpose**: Track individual installment payments

```sql
CREATE TABLE data.installment_schedules (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid TEXT NOT NULL DEFAULT utils.nanoid(8),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Link to parent plan
    installment_plan_id BIGINT NOT NULL REFERENCES data.installment_plans(id) ON DELETE CASCADE,

    -- Schedule details
    installment_number INTEGER NOT NULL,
    due_date DATE NOT NULL,
    scheduled_amount NUMERIC(19,4) NOT NULL,

    -- Completion tracking
    status TEXT NOT NULL DEFAULT 'scheduled',
    processed_date DATE,
    actual_amount NUMERIC(19,4),

    -- Link to the budget assignment transaction
    budget_transaction_id BIGINT REFERENCES data.transactions(id) ON DELETE SET NULL,

    -- Optional
    notes TEXT,

    CONSTRAINT installment_schedules_uuid_unique UNIQUE(uuid),
    CONSTRAINT installment_schedules_plan_number_unique UNIQUE(installment_plan_id, installment_number),
    CONSTRAINT installment_schedules_number_positive CHECK (installment_number > 0),
    CONSTRAINT installment_schedules_amount_positive CHECK (scheduled_amount > 0),
    CONSTRAINT installment_schedules_status_check CHECK (status IN ('scheduled', 'processed', 'skipped')),
    CONSTRAINT installment_schedules_notes_length_check CHECK (char_length(notes) <= 500)
);

-- Indexes
CREATE INDEX idx_installment_schedules_plan_id ON data.installment_schedules(installment_plan_id);
CREATE INDEX idx_installment_schedules_status ON data.installment_schedules(status);
CREATE INDEX idx_installment_schedules_due_date ON data.installment_schedules(due_date);

-- Trigger for updated_at
CREATE TRIGGER update_installment_schedules_updated_at
    BEFORE UPDATE ON data.installment_schedules
    FOR EACH ROW
    EXECUTE FUNCTION utils.update_updated_at();

-- Comments
COMMENT ON TABLE data.installment_schedules IS 'Individual installment payment schedule items';
COMMENT ON COLUMN data.installment_schedules.installment_number IS 'Sequential number of this installment (1 to N)';
COMMENT ON COLUMN data.installment_schedules.budget_transaction_id IS 'Transaction that moved money from category to CC payment category';
```

---

## Phase 2: API Layer

### Step 2.1: Installment Plans API
**File**: `public/api/installment-plans.php`

**Endpoints**:
- `GET /api/installment-plans.php?ledger_uuid={uuid}` - List all installment plans
- `GET /api/installment-plans.php?plan_uuid={uuid}` - Get single plan with schedule
- `POST /api/installment-plans.php` - Create new installment plan
- `PUT /api/installment-plans.php?plan_uuid={uuid}` - Update plan (limited fields)
- `DELETE /api/installment-plans.php?plan_uuid={uuid}` - Cancel/delete plan

**Key Features**:
- Validate purchase amount matches transaction
- Auto-generate installment schedule on creation
- Calculate installment amounts (handle rounding)
- Support different frequencies (monthly, bi-weekly, weekly)
- Prevent deletion if installments already processed

### Step 2.2: Installment Processing API
**File**: `public/api/process-installment.php`

**Endpoint**:
- `POST /api/process-installment.php` - Process a single installment

**Functionality**:
1. Validate installment is scheduled (not already processed)
2. Create budget transaction:
   - Debit: Category (where purchase was assigned)
   - Credit: Credit Card Payment Category for this card
   - Amount: Installment amount
   - Description: "Installment X/Y: {original description}"
3. Update installment schedule status to 'processed'
4. Update installment plan completed count
5. If all installments processed, mark plan as 'completed'

**Transaction Flow**:
```
Initial Purchase:
  DR: Category (e.g., "Electronics") $1,200
  CR: Credit Card                    $1,200

Each Monthly Installment Processing:
  DR: Credit Card Payment Category   $200
  CR: Category (e.g., "Electronics") $200

Effect: Spreads the $1,200 category impact across 6 months
```

---

## Phase 3: User Interface

### Step 3.1: Create Installment Plan Page
**File**: `public/installments/create.php`

**Form Fields**:
- Transaction Selector (dropdown of recent CC transactions)
- OR Manual Entry:
  - Credit Card Account (dropdown)
  - Purchase Amount
  - Purchase Date
  - Description
- Number of Installments (slider or input: 2-36 months)
- Start Date (default: next month)
- Frequency (monthly, bi-weekly, weekly)
- Category Assignment (which category to spread)
- Notes (optional)

**Display**:
- Calculated installment amount
- Payment schedule preview (table of dates)
- Total vs. Sum validation
- Visual timeline/calendar

### Step 3.2: Installment Plans List Page
**File**: `public/installments/index.php`

**Display**:
- Active installment plans table:
  - Description
  - Credit Card
  - Total Amount
  - Monthly Payment
  - Progress (X/Y installments completed)
  - Next Due Date
  - Status
  - Actions (View, Cancel)
- Summary stats:
  - Total active installment debt
  - Total monthly installment obligations
  - Number of active plans
- Filters: Status (active/completed/cancelled), Credit Card

### Step 3.3: View Installment Plan Page
**File**: `public/installments/view.php`

**Display**:
- Plan Details:
  - Purchase information
  - Credit card
  - Total amount
  - Payment schedule
  - Category
  - Status
- Installment Schedule Table:
  - Installment number
  - Due date
  - Amount
  - Status
  - Processed date
  - Action: "Process Now" button for scheduled installments
- Progress bar
- Summary of completed vs. remaining
- Actions: Edit (limited), Cancel Plan

### Step 3.4: Process Installment Page
**File**: `public/installments/process.php`

**Functionality**:
- Display next due installment
- Show plan details
- Preview budget impact
- Confirm and process
- Option to process early/late
- Batch process multiple overdue installments

### Step 3.5: Edit Installment Plan Page
**File**: `public/installments/edit.php`

**Editable Fields** (limited to prevent data integrity issues):
- Description
- Notes
- Category (redistribute remaining installments)
- Number of remaining installments (adjust schedule)

**Restrictions**:
- Cannot edit completed installments
- Cannot change total purchase amount
- Cannot change credit card account

---

## Phase 4: JavaScript Modules

### Step 4.1: Installment Manager Module
**File**: `public/js/installments.js`

**Classes**:
- `InstallmentManager` - Main class for CRUD operations
- `InstallmentCalculator` - Calculate installment amounts, dates
- `InstallmentScheduleGenerator` - Generate payment schedule

**Key Functions**:
- `calculateInstallmentAmount(total, count)` - Handle rounding
- `generateSchedule(startDate, count, frequency)` - Create date array
- `validatePlan(planData)` - Form validation
- `createPlan(planData)` - API call to create
- `processBatch(installmentIds)` - Process multiple installments

### Step 4.2: Installment Processing Module
**File**: `public/js/installment-processor.js`

**Functions**:
- `processInstallment(installmentId)` - Process single installment
- `showProcessPreview(installmentId)` - Show what will happen
- `confirmProcessing(installmentId)` - Confirmation modal
- `handleProcessSuccess()` - Update UI after processing
- `handleBatchProcessing(planId)` - Process all overdue

---

## Phase 5: Integration & UI Updates

### Step 5.1: Add to Navigation
**File**: `includes/header.php`

**Action**: Add "Installments" menu item next to Loans

### Step 5.2: Update Dashboard
**File**: `public/budget/dashboard.php`

**Widget**: Installment Obligations Summary
- Total monthly installment obligations
- Upcoming installments this month
- Link to installments page

### Step 5.3: Link to Transactions
**File**: `public/transactions/list.php`

**Action**:
- Show installment indicator on transactions that have plans
- "Create Installment Plan" button on CC transactions
- Quick create flow

### Step 5.4: Link to Credit Cards
**File**: `public/accounts/list.php`

**Action**:
- Show total installment debt per credit card
- Link to filtered installment plans for that card

### Step 5.5: Category Budget View
**File**: `public/budget/dashboard.php`

**Action**:
- Show scheduled installment impacts on categories
- Visual indicator for categories with upcoming installments
- Separate "Committed to Installments" amount

---

## Phase 6: Automation & Reminders

### Step 6.1: Auto-Process Function
**File**: `cron/process-due-installments.php`

**Functionality**:
- Run daily via cron job
- Find all installments due today or overdue
- Auto-process if user has enabled auto-process
- Send notification/email for manual processing

### Step 6.2: Notification System Integration
**Action**: Add installment reminders to existing notification system
- "Installment due in 3 days"
- "Overdue installment"
- "Installment processed successfully"

---

## Phase 7: Reporting & Analytics

### Step 7.1: Installment Report
**File**: `public/reports/installments.php`

**Display**:
- Total installment debt over time
- Monthly installment obligations
- Category breakdown of installment purchases
- Completion rate
- Average installment plan size

### Step 7.2: Budget Impact Report
**File**: `public/reports/installment-impact.php`

**Display**:
- Forecast of upcoming installment payments
- Category-by-category impact analysis
- Cash flow projection with installments
- "What-if" scenarios (add new installment plan)

---

## Implementation Order

### Sprint 1: Foundation (Weeks 1-2)
1. ✅ Database schema (Phase 1)
2. ✅ API layer (Phase 2)
3. ✅ Basic CRUD operations

### Sprint 2: Core UI (Weeks 3-4)
1. ✅ Create installment plan page
2. ✅ List installment plans page
3. ✅ View installment plan page
4. ✅ Process installment functionality

### Sprint 3: JavaScript & UX (Week 5)
1. ✅ JavaScript modules
2. ✅ Form validation
3. ✅ Schedule preview
4. ✅ Batch processing

### Sprint 4: Integration (Week 6)
1. ✅ Navigation updates
2. ✅ Dashboard widget
3. ✅ Transaction linking
4. ✅ Credit card integration

### Sprint 5: Automation (Week 7)
1. ✅ Auto-process functionality
2. ✅ Notification system
3. ✅ Cron job setup

### Sprint 6: Reporting (Week 8)
1. ✅ Installment reports
2. ✅ Budget impact analysis
3. ✅ Testing & refinement

---

## Key Technical Considerations

### 1. Rounding Issues
**Problem**: $1,000 ÷ 3 = $333.33...
**Solution**:
- First N-1 installments: floor(total/count)
- Last installment: total - sum(previous installments)
- Store both scheduled and actual amounts

### 2. Transaction Integrity
**Challenge**: What if original transaction is deleted?
**Solution**:
- Set `ON DELETE CASCADE` for installment plan
- Or prevent deletion if installment plan exists
- Recommend: Prevent deletion, require plan cancellation first

### 3. Category Balance Impact
**Challenge**: Processing installments affects category balances
**Solution**:
- Each processing creates a transaction that moves money from category back to CC payment category
- Category shows total debt immediately, then gets "refunded" monthly
- Alternative: Show "Reserved for Installments" separately

### 4. Multiple Installment Plans per Transaction
**Decision**: Allow or prevent?
**Recommendation**: Prevent. One transaction = one installment plan max.

### 5. Changing Payment Schedule
**Challenge**: User wants to change from 6 to 12 installments mid-way
**Solution**:
- Allow editing only if no installments processed yet
- For active plans: require cancellation and recreation
- Or: Allow "refinancing" that recalculates remaining installments

### 6. Credit Card Statement Integration
**Challenge**: How does this interact with CC payment tracking?
**Solution**:
- Installment processing moves budget, not actual money
- Actual CC payment still happens separately
- This is purely a budgeting/category allocation tool

---

## API Endpoints Summary

### Installment Plans
```
GET    /api/installment-plans.php?ledger_uuid={uuid}          # List all
GET    /api/installment-plans.php?plan_uuid={uuid}            # Get one
POST   /api/installment-plans.php                             # Create
PUT    /api/installment-plans.php?plan_uuid={uuid}            # Update
DELETE /api/installment-plans.php?plan_uuid={uuid}            # Delete
```

### Installment Processing
```
POST   /api/process-installment.php                           # Process one
POST   /api/batch-process-installments.php                    # Process multiple
GET    /api/due-installments.php?ledger_uuid={uuid}           # Get due installments
```

---

## Database Schema Summary

### Tables
1. `data.installment_plans` - Master plan records
2. `data.installment_schedules` - Individual installment items

### Relationships
```
installment_plans
  ├── ledger_id → ledgers.id
  ├── original_transaction_id → transactions.id
  ├── credit_card_account_id → accounts.id
  └── category_account_id → accounts.id

installment_schedules
  ├── installment_plan_id → installment_plans.id
  └── budget_transaction_id → transactions.id
```

---

## User Stories

### Story 1: Create Installment Plan
**As a** user
**I want to** create an installment plan for a large purchase
**So that** I can spread the budget impact across multiple months

**Acceptance Criteria**:
- ✅ Can select any credit card transaction
- ✅ Can specify number of installments (2-36)
- ✅ See preview of payment schedule
- ✅ Can assign to specific category
- ✅ Plan is created with all installments scheduled

### Story 2: View Installment Plans
**As a** user
**I want to** see all my active installment plans
**So that** I know my monthly obligations

**Acceptance Criteria**:
- ✅ See list of all active plans
- ✅ See total monthly obligation
- ✅ See next due installment
- ✅ Can filter by status or credit card

### Story 3: Process Installment
**As a** user
**I want to** process a due installment
**So that** the budget impact is recorded

**Acceptance Criteria**:
- ✅ Can process installment with one click
- ✅ See preview of budget impact before confirming
- ✅ Transaction is created automatically
- ✅ Schedule is updated
- ✅ Category balance reflects the installment

### Story 4: Dashboard Overview
**As a** user
**I want to** see my installment obligations on the dashboard
**So that** I'm aware of upcoming payments

**Acceptance Criteria**:
- ✅ See total installment debt
- ✅ See monthly obligation
- ✅ See upcoming installments this month
- ✅ Quick link to installments page

### Story 5: Cancel Installment Plan
**As a** user
**I want to** cancel an installment plan
**So that** I can handle the purchase differently

**Acceptance Criteria**:
- ✅ Can cancel any active plan
- ✅ See confirmation with impact details
- ✅ Processed installments remain in history
- ✅ Remaining installments are cancelled
- ✅ Option to reverse budget impact of processed installments

---

## Testing Checklist

### Unit Tests
- [ ] Installment amount calculation with rounding
- [ ] Schedule generation for different frequencies
- [ ] Date calculations for bi-weekly and weekly plans
- [ ] Validation logic for plan creation
- [ ] Status transitions (scheduled → processed → completed)

### Integration Tests
- [ ] Create plan from transaction
- [ ] Process single installment
- [ ] Batch process multiple installments
- [ ] Cancel plan with processed installments
- [ ] Edit plan details

### UI Tests
- [ ] Create plan form validation
- [ ] Schedule preview display
- [ ] Progress bar accuracy
- [ ] Filter and sort functionality
- [ ] Mobile responsiveness

### Edge Cases
- [ ] Plan with 1 installment (should be prevented)
- [ ] Zero or negative amounts (should be prevented)
- [ ] Invalid date ranges
- [ ] Processing same installment twice
- [ ] Deleting transaction with active plan
- [ ] Category deletion with active installments

---

## Security Considerations

1. **Row-Level Security**: All tables have RLS policies using `user_data`
2. **Input Validation**: Validate all amounts, dates, and counts
3. **Transaction Integrity**: Use database transactions for multi-step operations
4. **Authorization**: Verify user owns the ledger, account, and transaction
5. **SQL Injection**: Use prepared statements for all queries
6. **XSS Protection**: Escape all user input in HTML output

---

## Future Enhancements

### Phase 8: Advanced Features (Future)
1. **Interest-Bearing Installments**: Support 0% APR vs. interest-bearing plans
2. **Variable Installments**: Allow different amounts per installment
3. **Early Payoff**: Calculate savings from early completion
4. **Installment Templates**: Save common configurations
5. **Merchant Integration**: Auto-create from merchant installment offers
6. **Mobile Notifications**: Push notifications for due installments
7. **Export to Calendar**: Add installments to Google Calendar/iCal
8. **Installment Refinancing**: Extend or shorten existing plans

---

## Summary

This implementation plan provides a comprehensive installment payment system that:
- ✅ Integrates seamlessly with existing transaction and account systems
- ✅ Provides clear budget impact tracking
- ✅ Offers flexible payment scheduling
- ✅ Maintains data integrity with proper constraints
- ✅ Follows existing codebase patterns and security practices
- ✅ Scales to support multiple concurrent installment plans
- ✅ Provides rich reporting and analytics

**Estimated Total Implementation Time**: 8 weeks (6-8 hours/week)

**Key Dependencies**:
- Existing transactions system
- Existing accounts/categories system
- Database access with RLS support
- Transaction API infrastructure

**Success Metrics**:
- Users can create installment plans successfully
- Budget impact is accurately reflected
- No data integrity issues
- User satisfaction with budgeting clarity
- Reduction in overspending due to large purchases
