# GitHub Issues for Usability Improvement Plan

This document contains all GitHub issues to be created for implementing the Usability Improvement Plan.

## How to Create These Issues

### Option 1: Using GitHub CLI (gh)
```bash
# Install gh CLI if not already installed
# Then authenticate:
gh auth login

# Run the creation script:
bash docs/create-github-issues.sh
```

### Option 2: Manual Creation
Copy each issue below and create manually via GitHub web interface.

### Option 3: GitHub API Script
Use the provided Python script: `scripts/create_issues.py`

---

## 🔴 CRITICAL PRIORITY ISSUES

### Issue #1: Implement Onboarding Wizard

**Title:** 🔴 [Critical] Implement Onboarding Wizard

**Labels:** `priority:critical`, `enhancement`, `ux`, `phase-1`

**Description:**
```markdown
## Overview
Create a 5-step onboarding wizard for new users to guide them through initial setup and teach budgeting principles.

## Context
- Part of Phase 1 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 1
- Impact: **Highest** - First impressions determine retention
- Estimated Effort: 5-7 days

## Implementation Tasks

### Step 1: Welcome Screen
- [ ] Create `public/onboarding/wizard.php` main controller
- [ ] Create `public/onboarding/step1-welcome.php`
- [ ] Design welcome screen with "Get Started" and "Skip" options
- [ ] Add progress indicator (Step 1 of 5)

### Step 2: Philosophy Introduction
- [ ] Create `public/onboarding/step2-philosophy.php`
- [ ] Present PGBudget Method (4 principles)
- [ ] Add engaging copy about budgeting philosophy

### Step 3: Budget Creation
- [ ] Create `public/onboarding/step3-budget.php`
- [ ] Form to create first ledger with name and description
- [ ] Provide helpful examples

### Step 4: First Account
- [ ] Create `public/onboarding/step4-account.php`
- [ ] Account type selection (Checking, Savings, Cash, Other)
- [ ] Account name and starting balance inputs

### Step 5: Quick Start Categories
- [ ] Create `public/onboarding/step5-categories.php`
- [ ] Pre-select common categories (Groceries, Rent, etc.)
- [ ] Allow adding custom categories
- [ ] Option to use budget templates

### Backend & Database
- [ ] Add database columns to track onboarding state
- [ ] Create migration file `migrations/084_onboarding_system.sql`
- [ ] Implement session management for wizard state
- [ ] Add "resume onboarding" functionality
- [ ] Redirect logic: check onboarding status on login

### UI & Styling
- [ ] Create `public/css/onboarding.css`
- [ ] Create `public/js/onboarding.js` for navigation
- [ ] Implement wizard navigation (Next, Back, Skip)
- [ ] Add form validation at each step
- [ ] Mobile-responsive design

## Acceptance Criteria
- [ ] New users are automatically redirected to onboarding
- [ ] Wizard can be completed in < 5 minutes
- [ ] Users can skip onboarding if desired
- [ ] Progress is saved between sessions
- [ ] All 5 steps create necessary data (ledger, account, categories)
- [ ] Clean, friendly UI with clear instructions
- [ ] Works on mobile devices

## Success Metrics
- Onboarding completion rate > 80%
- Average completion time < 5 minutes
- Drop-off rate identified per step

## Files to Create/Modify
- `public/onboarding/wizard.php`
- `public/onboarding/step1-welcome.php`
- `public/onboarding/step2-philosophy.php`
- `public/onboarding/step3-budget.php`
- `public/onboarding/step4-account.php`
- `public/onboarding/step5-categories.php`
- `public/css/onboarding.css`
- `public/js/onboarding.js`
- `migrations/084_onboarding_system.sql`

## Related Issues
- Blocks: Budget Templates issue
- Blocks: First-Time Tips issue
```

---

### Issue #2: Language Simplification (Technical → User-Friendly)

**Title:** 🔴 [Critical] Simplify Technical Language Throughout Application

**Labels:** `priority:critical`, `ux`, `copy`, `phase-2`

**Description:**
```markdown
## Overview
Replace technical accounting terminology with user-friendly language throughout the application to reduce intimidation and improve accessibility.

## Context
- Part of Phase 2 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 2.1
- Impact: **High** - Affects every user-facing page
- Estimated Effort: 3-5 days

## Terminology Changes

| Current (Technical) | New (User-Friendly) |
|---------------------|---------------------|
| Ledger | Budget |
| Credit Account | Money Coming From |
| Debit Account | Money Going To |
| Assign to Category | Budget Money |
| Account Balance | Available Money |
| Budget Status | Category Overview |
| Outflow | Spending / Payment |
| Inflow | Income / Deposit |
| Transaction Type | Transaction Direction |
| Running Balance | Balance After |

## Implementation Tasks

### Phase 1: Audit & Document
- [ ] Audit all user-facing text across application
- [ ] Document every instance of technical terms
- [ ] Create comprehensive terminology mapping
- [ ] Get stakeholder approval on new terms

### Phase 2: Update Pages
- [ ] Update `public/budget/dashboard.php`
- [ ] Update `public/accounts/*.php`
- [ ] Update `public/transactions/*.php`
- [ ] Update `public/categories/*.php`
- [ ] Update `public/reports/*.php`
- [ ] Update all form labels and buttons
- [ ] Update navigation menu items

### Phase 3: Update API Responses
- [ ] Review API response messages
- [ ] Update error messages to be user-friendly
- [ ] Update success messages

### Phase 4: Database Display Names
- [ ] Create display name mapping function
- [ ] Update views and functions that return user-facing text
- [ ] Keep technical names in database (don't rename columns)

### Phase 5: Documentation
- [ ] Update README.md with new terminology
- [ ] Create glossary page mapping old → new terms
- [ ] Update code comments for clarity

## Acceptance Criteria
- [ ] All user-facing pages use simplified language
- [ ] Technical terms only appear in developer documentation
- [ ] Error messages are clear and actionable
- [ ] Navigation is intuitive with new labels
- [ ] Help text uses consistent terminology
- [ ] Backend/database keeps technical names (for clarity)

## Files to Audit/Modify
- All `public/**/*.php` files
- `includes/header.php` (navigation)
- `includes/footer.php`
- API endpoint files
- JavaScript files with user-facing strings

## Testing
- [ ] Manual review of every page
- [ ] User testing with non-technical users
- [ ] Ensure no broken functionality from label changes

## Related Issues
- Works with: Tooltips & Inline Help issue
```

---

### Issue #3: Quick Add Transaction Modal

**Title:** 🔴 [Critical] Implement Quick Add Transaction Modal

**Labels:** `priority:critical`, `enhancement`, `ux`, `phase-4`

**Description:**
```markdown
## Overview
Replace the multi-page transaction creation flow with a quick modal dialog accessible from anywhere in the application.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 4.1
- Impact: **High** - Most common user action
- Estimated Effort: 2-3 days

## Current vs Improved Flow

**Current Flow:**
1. Click "Add Transaction"
2. Navigate to dedicated page
3. Fill long form
4. Submit
5. Redirect back to previous page

**Improved Flow:**
1. Click "Add Transaction" (from anywhere)
2. Modal appears overlay
3. Fill simplified form
4. Submit
5. Modal closes, page updates (no reload)

## Implementation Tasks

### Modal Component
- [ ] Create `public/components/modal-transaction.php`
- [ ] Design modal with simplified form layout
- [ ] Include fields: Amount, Payee/Description, Account, Category, Date
- [ ] Add "Advanced Options" collapsible section

### Advanced Options (Collapsed by Default)
- [ ] Split transaction checkbox
- [ ] Recurring transaction checkbox
- [ ] Memo/notes field
- [ ] Custom date selector

### JavaScript
- [ ] Create `public/js/quick-transaction.js`
- [ ] Modal open/close functionality
- [ ] Form validation
- [ ] AJAX submission to API
- [ ] Success/error handling
- [ ] Update page without reload

### API Endpoint
- [ ] Create or enhance `public/api/transactions.php`
- [ ] Handle POST request for new transaction
- [ ] Return JSON response
- [ ] Validate all inputs server-side

### Styling
- [ ] Create or enhance `public/css/modals.css`
- [ ] Modal backdrop and animation
- [ ] Form styling consistent with app
- [ ] Mobile-responsive design
- [ ] Keyboard navigation support (Tab, Esc)

### Integration
- [ ] Add "Add Transaction" button to header
- [ ] Add to dashboard quick actions
- [ ] Add keyboard shortcut (Alt+T or Cmd+T)
- [ ] Trigger modal from multiple locations

### Smart Defaults
- [ ] Default date to today
- [ ] Remember last-used account
- [ ] Suggest category based on payee (if available)
- [ ] Default to "Outflow" type

## Acceptance Criteria
- [ ] Modal opens from any page via button/shortcut
- [ ] Form can be completed in < 30 seconds
- [ ] Validation provides clear error messages
- [ ] Success adds transaction without page reload
- [ ] Modal closes on success or cancel
- [ ] Works on mobile devices
- [ ] Keyboard accessible (Tab, Enter, Esc)
- [ ] Advanced options available but hidden by default

## Files to Create/Modify
- `public/components/modal-transaction.php`
- `public/js/quick-transaction.js`
- `public/css/modals.css`
- `public/api/transactions.php`
- `includes/header.php` (add button)

## Testing
- [ ] Test from different pages
- [ ] Test keyboard shortcuts
- [ ] Test form validation
- [ ] Test AJAX submission
- [ ] Test on mobile devices
- [ ] Test with screen readers (accessibility)

## Related Issues
- Part of: Quick Actions Widget issue
```

---

### Issue #4: Dashboard Redesign - Summary Section

**Title:** 🔴 [Critical] Redesign Dashboard Summary Section

**Labels:** `priority:critical`, `enhancement`, `ux`, `design`, `phase-3`

**Description:**
```markdown
## Overview
Redesign the top section of the dashboard to provide a clear, visual summary of budget status at a glance.

## Context
- Part of Phase 3 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 3.1
- Impact: **High** - Main landing page, first thing users see
- Estimated Effort: 3-4 days

## New Dashboard Layout

### Top Section: Budget Summary Card
```
┌─────────────────────────────────────────────────────┐
│ 💰 Your Budget at a Glance                          │
│                                                     │
│ Available to Budget: $1,250.00                      │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━                   │
│                                                     │
│ [💵 Budget Money] [➕ Add Transaction]              │
│                                                     │
│ Quick Stats for December 2025:                      │
│ • Income this month: $3,500.00                      │
│ • Budgeted: $3,500.00                               │
│ • Spent so far: $1,245.00                           │
│ • On track: 8 of 10 categories ✓                    │
│                                                     │
└─────────────────────────────────────────────────────┘
```

## Implementation Tasks

### Summary Card Component
- [ ] Create new summary card section in dashboard
- [ ] Display "Available to Budget" prominently
- [ ] Add visual progress bar or indicator
- [ ] Show quick action buttons (Budget Money, Add Transaction)

### Quick Stats
- [ ] Calculate monthly income
- [ ] Calculate total budgeted amount
- [ ] Calculate total spent
- [ ] Calculate categories on track vs total
- [ ] Add appropriate icons for each stat

### Visual Design
- [ ] Large, readable typography
- [ ] Use color coding (green for good, yellow for warning)
- [ ] Add whitespace and visual hierarchy
- [ ] Card-based layout with shadow/border

### Responsive Design
- [ ] Desktop: Full width card
- [ ] Tablet: Adjusted spacing
- [ ] Mobile: Stack elements vertically

### Integration
- [ ] Update `public/budget/dashboard.php`
- [ ] Query necessary data from database
- [ ] Connect quick action buttons to modals
- [ ] Update existing dashboard layout

## Implementation Files

### CSS
- [ ] Create `public/css/dashboard-v2.css`
- [ ] Define card styles
- [ ] Define color scheme for status indicators
- [ ] Define responsive breakpoints

### PHP
- [ ] Update `public/budget/dashboard.php`
- [ ] Add new data queries for summary stats
- [ ] Create reusable component for summary card
- [ ] Format currency values

### JavaScript (Optional)
- [ ] Add smooth animations
- [ ] Update stats without page reload
- [ ] Add tooltips to stats

## Acceptance Criteria
- [ ] Summary card is prominent at top of dashboard
- [ ] All key metrics are visible at a glance
- [ ] Quick action buttons are easily accessible
- [ ] Visual design is clean and modern
- [ ] Responsive on all screen sizes
- [ ] Loads quickly (< 2 seconds)
- [ ] Colors indicate status appropriately

## Design Mockup
See `docs/USABILITY_IMPROVEMENT_PLAN.md` Phase 3.1 for detailed mockup

## Files to Create/Modify
- `public/budget/dashboard.php`
- `public/css/dashboard-v2.css`
- `public/js/dashboard.js` (optional)

## Testing
- [ ] Test with different budget states (zero, negative, positive)
- [ ] Test with different screen sizes
- [ ] Test calculation accuracy
- [ ] Test with empty budget (new user)

## Future Enhancements (Not in this issue)
- Middle section: Category groups redesign
- Bottom section: Recent activity redesign

## Related Issues
- Depends on: Quick Add Transaction Modal (for button integration)
- Part of larger: Complete Dashboard Redesign (future issue)
```

---

## 🟡 HIGH PRIORITY ISSUES

### Issue #5: Implement Tooltips & Inline Help System

**Title:** 🟡 [High Priority] Implement Tooltips & Inline Help System

**Labels:** `priority:high`, `enhancement`, `ux`, `help`, `phase-5`

**Description:**
```markdown
## Overview
Add comprehensive tooltip system throughout the application to provide contextual help for every feature, term, and action.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 5.3
- Impact: **High** - Helps users understand features everywhere
- Estimated Effort: 4-5 days

## Implementation Tasks

### Tooltip Library Integration
- [ ] Evaluate and choose tooltip library (Tippy.js recommended)
- [ ] Add library to project
- [ ] Create initialization script
- [ ] Set up theme and styling

### Core Implementation
- [ ] Create tooltip helper function in `public/js/tooltips.js`
- [ ] Create PHP helper for consistent markup
- [ ] Define tooltip trigger patterns
- [ ] Set up tooltip configuration (placement, timing, etc.)

### Tooltip Coverage Areas

#### Financial Terms
- [ ] Available to Budget
- [ ] Category Balance
- [ ] Account Balance
- [ ] Running Balance
- [ ] Budgeted Amount
- [ ] Activity
- [ ] Budget vs Actual

#### Action Buttons
- [ ] Add Transaction (What happens?)
- [ ] Budget Money (What does this do?)
- [ ] Move Money (When to use this?)
- [ ] Create Goal (How do goals work?)
- [ ] Reconcile (What is reconciliation?)

#### Status Indicators
- [ ] Green status (On track meaning)
- [ ] Yellow status (Warning meaning)
- [ ] Red status (Overspent meaning)
- [ ] Goal progress indicators

#### Complex Features
- [ ] Goals system overview
- [ ] Recurring transactions explanation
- [ ] Split transactions guide
- [ ] Credit card workflow
- [ ] Loan management basics
- [ ] Installment plans

#### Forms & Inputs
- [ ] Required fields (Why required?)
- [ ] Date fields (What date to use?)
- [ ] Amount fields (Format explanation)
- [ ] Category selection (How to choose?)

### Tooltip Content

Create tooltip content library:
- [ ] Write clear, concise tooltip text (1-2 sentences)
- [ ] Add "Learn more" links where appropriate
- [ ] Use friendly, non-technical language
- [ ] Include examples where helpful

### Styling
- [ ] Create `public/css/tooltips.css`
- [ ] Design consistent tooltip appearance
- [ ] Ensure readability (contrast, size)
- [ ] Add subtle animations
- [ ] Mobile-friendly touch behavior

### Help Icon Pattern
- [ ] Design help icon (? in circle)
- [ ] Create reusable component
- [ ] Position consistently near labels
- [ ] Keyboard accessible

### Advanced Features
- [ ] Rich HTML tooltips (for complex explanations)
- [ ] Interactive tooltips (with links)
- [ ] Multi-step tooltips (for tutorials)
- [ ] Tooltip persistence settings (don't show again)

## Example Implementation

```html
<!-- Simple tooltip -->
<label>
  Available to Budget
  <span class="help-icon" data-tooltip="Money you've received but haven't assigned to categories yet">
    <svg>...</svg>
  </span>
</label>

<!-- Rich tooltip with HTML -->
<label>
  Category Balance
  <span class="help-icon" data-tooltip-html="
    <strong>Category Balance</strong><br>
    Amount remaining after spending.<br>
    Formula: Budgeted - Spent = Balance<br>
    <a href='/help/categories'>Learn more</a>
  ">
    <svg>...</svg>
  </span>
</label>
```

## Acceptance Criteria
- [ ] Tooltips appear on hover (desktop) and tap (mobile)
- [ ] All financial terms have tooltips
- [ ] All action buttons have explanatory tooltips
- [ ] Tooltips use clear, friendly language
- [ ] Tooltips are keyboard accessible
- [ ] "Learn more" links work correctly
- [ ] Consistent styling across application
- [ ] No performance impact on page load

## Tooltip Content Guidelines
1. Keep it brief (1-3 sentences max)
2. Use plain language, no jargon
3. Start with what it is, then why it matters
4. Link to help docs for more details
5. Use active voice
6. Be encouraging and helpful

## Files to Create/Modify
- `public/js/tooltips.js`
- `public/css/tooltips.css`
- `includes/functions.php` (tooltip helper)
- All user-facing PHP pages (add tooltips)

## Testing
- [ ] Test on desktop (hover behavior)
- [ ] Test on mobile (tap behavior)
- [ ] Test keyboard navigation (Tab, Focus)
- [ ] Test with screen readers
- [ ] Verify "Learn more" links
- [ ] Check performance with many tooltips

## Dependencies
- Tooltip library (Tippy.js or similar)
- Font Awesome or similar (for help icon)

## Related Issues
- Works with: Language Simplification issue
- Enhances: All feature issues
```

---

### Issue #6: Implement Friendly Messaging Throughout Application

**Title:** 🟡 [High Priority] Implement Friendly & Encouraging Messaging

**Labels:** `priority:high`, `ux`, `copy`, `phase-7`

**Description:**
```markdown
## Overview
Update all user-facing messages to be friendly, encouraging, and helpful rather than technical or bland.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 7.1
- Impact: **Medium-High** - Emotional connection with users
- Estimated Effort: 2-3 days

## Message Categories to Update

### Empty States
Transform blank pages into encouraging guidance

**Before:**
```
No transactions found.
```

**After:**
```
🎉 Ready to start!
Add your first transaction to see your budget in action.

[Add Transaction]
```

### Success Messages
Make accomplishments feel rewarding

**Before:**
```
Transaction added successfully.
```

**After:**
```
✓ Nice! Transaction added to your budget.
Your "Groceries" category now has $205 remaining.
```

### Guidance Messages
Be helpful and supportive

**Before:**
```
Complete the required fields.
```

**After:**
```
💡 Almost there!
We just need a few more details to add this transaction.
```

### Error Messages
Turn errors into helpful guidance

**Before:**
```
Error: Invalid input
```

**After:**
```
❌ Oops! That didn't work.
[Specific explanation of what went wrong]
Here's how to fix it: [Clear guidance]
```

## Implementation Tasks

### Audit Current Messages
- [ ] List all success messages
- [ ] List all error messages
- [ ] List all empty states
- [ ] List all guidance messages
- [ ] Document current message patterns

### Rewrite Messages

#### Empty States
- [ ] Dashboard (no transactions)
- [ ] Categories (no categories)
- [ ] Accounts (no accounts)
- [ ] Goals (no goals)
- [ ] Reports (no data)
- [ ] Search results (no matches)

#### Success Messages
- [ ] Transaction created
- [ ] Transaction updated
- [ ] Transaction deleted
- [ ] Category created
- [ ] Money budgeted
- [ ] Goal completed
- [ ] Account reconciled

#### Error Messages
- [ ] Form validation errors
- [ ] Database errors (user-friendly version)
- [ ] Permission errors
- [ ] Not found errors
- [ ] Overspending errors

#### Guidance Messages
- [ ] First-time instructions
- [ ] Feature introductions
- [ ] Help prompts
- [ ] Warning messages

### Update Code
- [ ] Update all PHP files with new messages
- [ ] Update JavaScript files (alerts, toasts)
- [ ] Update API responses
- [ ] Create message helper functions
- [ ] Ensure consistent voice throughout

### Message Writing Guidelines
Create documentation:
- [ ] Define voice and tone (friendly, encouraging, clear)
- [ ] Provide examples of good vs bad messages
- [ ] Create template patterns for common scenarios
- [ ] Guidelines for using emoji appropriately

## Message Writing Principles

1. **Be Human**: Write like a helpful friend, not a robot
2. **Be Positive**: Focus on what users can do, not what they can't
3. **Be Specific**: Tell users exactly what happened and why
4. **Be Actionable**: Always provide next steps
5. **Be Encouraging**: Celebrate small wins
6. **Be Brief**: Respect users' time
7. **Be Consistent**: Use similar patterns throughout

## Example Transformations

### Empty State: No Budget Data
**Before:**
```
No data available.
```

**After:**
```
📊 Let's build your first budget!

A budget helps you take control of your money. Let's start by adding some income and creating categories.

[Add Income] [Create Categories]
```

### Success: Goal Completed
**Before:**
```
Goal status updated.
```

**After:**
```
🏆 Congratulations! You did it!

Your "Emergency Fund" goal is now complete. You saved $1,000 just as planned!

[Set New Goal] [Celebrate]
```

### Error: Overspending
**Before:**
```
Error: Transaction amount exceeds category balance.
```

**After:**
```
⚠️ Hold on! This would overspend "Groceries" by $25.

What would you like to do?
• Move $25 from another category to cover it
• Record it anyway (you'll need to fix it later)
• Change the amount

[Move Money] [Record Anyway] [Go Back]
```

## Acceptance Criteria
- [ ] All empty states have helpful, encouraging messages
- [ ] All success messages celebrate the action
- [ ] All error messages explain the problem and solution
- [ ] Messages use consistent friendly tone
- [ ] Appropriate use of emoji (not excessive)
- [ ] Messages guide users to next action
- [ ] No technical jargon in user-facing messages

## Files to Modify
- All `public/**/*.php` files
- `public/js/**/*.js` files
- API endpoint files
- Message/notification system

## Testing
- [ ] Review every message type
- [ ] User testing for tone/clarity
- [ ] Ensure no broken functionality
- [ ] Check translations (if applicable)

## Documentation
- [ ] Create message style guide
- [ ] Document patterns and examples
- [ ] Share with team for consistency

## Related Issues
- Works with: Language Simplification issue
- Enhances overall UX
```

---

### Issue #7: Implement Quick Actions Widget

**Title:** 🟡 [High Priority] Implement Persistent Quick Actions Widget

**Labels:** `priority:high`, `enhancement`, `ux`, `phase-4`

**Description:**
```markdown
## Overview
Add a persistent quick actions bar at the top of every page for instant access to common actions: Add Transaction, Budget Money, Transfer.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 4.3
- Impact: **Medium** - Improves action accessibility
- Estimated Effort: 1-2 days

## Quick Actions Bar Design

```
┌─────────────────────────────────────────────────────┐
│ [➕ Add Transaction] [💵 Budget Money] [🔄 Transfer] │
└─────────────────────────────────────────────────────┘
```

## Implementation Tasks

### Quick Actions Component
- [ ] Create `public/components/quick-actions.php`
- [ ] Design horizontal button bar
- [ ] Add icons and labels for each action
- [ ] Responsive design for mobile

### Button Actions
- [ ] "Add Transaction" → Opens quick transaction modal
- [ ] "Budget Money" → Opens budget assignment modal
- [ ] "Transfer" → Opens transfer modal

### Header Integration
- [ ] Add quick actions to `includes/header.php`
- [ ] Position below main navigation
- [ ] Make sticky on scroll (optional)
- [ ] Show on all authenticated pages

### Keyboard Shortcuts
- [ ] Alt+T (or Cmd+T) → Add Transaction
- [ ] Alt+B (or Cmd+B) → Budget Money
- [ ] Alt+R (or Cmd+R) → Transfer
- [ ] Create keyboard shortcut handler
- [ ] Show shortcuts in tooltips

### Styling
- [ ] Update CSS for button styling
- [ ] Consistent with app design
- [ ] Prominent but not overwhelming
- [ ] Mobile-responsive (stack or collapse)

### Modal Integration
- [ ] Connect to existing/new modals
- [ ] Ensure modals open from any page
- [ ] Handle modal state management

## Acceptance Criteria
- [ ] Quick actions bar visible on all pages
- [ ] Three main actions easily accessible
- [ ] Keyboard shortcuts work globally
- [ ] Buttons open appropriate modals
- [ ] Responsive design works on mobile
- [ ] Consistent styling with app
- [ ] No layout conflicts on different pages

## Files to Create/Modify
- `public/components/quick-actions.php`
- `includes/header.php`
- `public/css/quick-actions.css`
- `public/js/keyboard-shortcuts.js`

## Keyboard Shortcut Reference

Create help page or modal listing shortcuts:
- Alt+T → Add Transaction
- Alt+B → Budget Money
- Alt+R → Transfer Money
- Alt+H → Help
- Esc → Close Modal

## Testing
- [ ] Test on all main pages
- [ ] Test keyboard shortcuts
- [ ] Test on mobile devices
- [ ] Test with screen readers
- [ ] Verify no conflicts with page-specific shortcuts

## Related Issues
- Depends on: Quick Add Transaction Modal
- May need: Budget Money Modal (if not exists)
- May need: Transfer Modal (if not exists)
```

---

### Issue #8: Implement Visual Enhancements (Colors, Icons, Hierarchy)

**Title:** 🟡 [High Priority] Implement Visual Enhancements & Design System

**Labels:** `priority:high`, `design`, `ux`, `phase-7`

**Description:**
```markdown
## Overview
Implement consistent color system, icons, typography, and visual hierarchy throughout the application for better usability and aesthetics.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 7.3-7.4
- Impact: **Medium** - Professional appearance, easier scanning
- Estimated Effort: 4-5 days

## Implementation Tasks

### 1. Color System

Define and implement semantic color palette:

```css
/* Success / On Track */
--success-50: #f0fdf4;
--success-500: #22c55e;
--success-700: #15803d;

/* Warning / Getting Close */
--warning-50: #fffbeb;
--warning-500: #f59e0b;
--warning-700: #b45309;

/* Danger / Overspent */
--danger-50: #fef2f2;
--danger-500: #ef4444;
--danger-700: #b91c1c;

/* Info / Neutral */
--info-50: #eff6ff;
--info-500: #3b82f6;
--info-700: #1d4ed8;

/* Primary / Brand */
--primary-50: #faf5ff;
--primary-500: #a855f7;
--primary-700: #7e22ce;

/* Neutral / Gray */
--gray-50: #f9fafb;
--gray-100: #f3f4f6;
--gray-300: #d1d5db;
--gray-500: #6b7280;
--gray-700: #374151;
--gray-900: #111827;
```

#### Tasks:
- [ ] Create `public/css/design-system.css`
- [ ] Define CSS custom properties
- [ ] Apply colors to status indicators
- [ ] Apply colors to category spending states
- [ ] Apply colors to buttons and actions
- [ ] Ensure sufficient contrast (WCAG AA)

### 2. Icon System

Implement consistent icon usage:

**Category Icons:**
- 🍔 Food & Dining
- 🏠 Housing
- 🚗 Transportation
- 💡 Utilities
- 🎬 Entertainment
- 👔 Clothing
- 🏥 Healthcare
- 📚 Education
- 💰 Savings

**Action Icons:**
- ➕ Add/Create
- ✏️ Edit
- 🗑️ Delete
- 💵 Budget/Allocate
- 🔄 Transfer
- 📊 Report
- ⚙️ Settings

**Status Icons:**
- ✓ Complete/Success
- ⚠️ Warning
- ❌ Error
- ℹ️ Information

#### Tasks:
- [ ] Choose icon library (Font Awesome or emoji)
- [ ] Create icon component/helper
- [ ] Apply icons to categories
- [ ] Apply icons to actions
- [ ] Apply icons to status messages
- [ ] Ensure consistent sizing

### 3. Typography Scale

Define typography hierarchy:

```css
/* Display - Hero text */
.text-4xl { font-size: 2.25rem; font-weight: 800; }

/* Headings */
.text-3xl { font-size: 1.875rem; font-weight: 700; }
.text-2xl { font-size: 1.5rem; font-weight: 600; }
.text-xl { font-size: 1.25rem; font-weight: 600; }

/* Body */
.text-base { font-size: 1rem; font-weight: 400; }
.text-sm { font-size: 0.875rem; }
.text-xs { font-size: 0.75rem; }
```

#### Tasks:
- [ ] Define typography scale
- [ ] Apply to headings consistently
- [ ] Set appropriate line heights
- [ ] Ensure readability (size, contrast)

### 4. Spacing System

Implement consistent spacing:

```css
--space-1: 0.25rem;  /* 4px */
--space-2: 0.5rem;   /* 8px */
--space-3: 0.75rem;  /* 12px */
--space-4: 1rem;     /* 16px */
--space-6: 1.5rem;   /* 24px */
--space-8: 2rem;     /* 32px */
--space-12: 3rem;    /* 48px */
--space-16: 4rem;    /* 64px */
```

#### Tasks:
- [ ] Define spacing scale
- [ ] Apply consistent margins/padding
- [ ] Add whitespace for breathing room
- [ ] Group related elements with proximity

### 5. Progress Bars

Create visual progress indicators for categories:

```
Groceries    ━━━━━━░░░░    $200 / $350 (57%)
```

#### Tasks:
- [ ] Design progress bar component
- [ ] Color-code by status (green/yellow/red)
- [ ] Integrate into dashboard
- [ ] Integrate into category list

### 6. Status Indicators

Visual badges for category states:

- ✓ On Track (green)
- ⚠️ Getting Close (yellow)
- ❌ Overspent (red)

#### Tasks:
- [ ] Create status badge component
- [ ] Apply to dashboard
- [ ] Apply to category views
- [ ] Add tooltips explaining each status

### 7. Cards & Containers

Consistent card-based layout:

#### Tasks:
- [ ] Define card component styles
- [ ] Add subtle shadows/borders
- [ ] Apply to summary sections
- [ ] Apply to category groups
- [ ] Apply to recent transactions

## Acceptance Criteria
- [ ] Consistent color usage throughout app
- [ ] Status colors clearly communicate meaning
- [ ] Icons used consistently
- [ ] Typography hierarchy is clear
- [ ] Adequate whitespace reduces clutter
- [ ] Progress bars show spending visually
- [ ] Cards create visual grouping
- [ ] Design passes accessibility checks (contrast)

## Files to Create/Modify
- `public/css/design-system.css`
- `public/css/colors.css`
- `public/css/typography.css`
- `public/css/components.css`
- All page CSS files

## Design Guidelines Document
- [ ] Create design system documentation
- [ ] Document color usage patterns
- [ ] Document icon conventions
- [ ] Provide code examples

## Testing
- [ ] Visual review of every page
- [ ] Test color contrast (WCAG checker)
- [ ] Test on different screen sizes
- [ ] User feedback on visual clarity

## Related Issues
- Enhances: Dashboard Redesign
- Works with: All UI issues
```

---

## 🟢 MEDIUM PRIORITY ISSUES

### Issue #9: Create Budget Templates System

**Title:** 🟢 [Medium Priority] Create Budget Templates System

**Labels:** `priority:medium`, `enhancement`, `feature`, `phase-8`

**Description:**
```markdown
## Overview
Create pre-configured budget templates for common scenarios to help new users get started quickly.

## Context
- Part of Phase 8 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 8.1
- Impact: **Medium** - Helps new users start faster
- Estimated Effort: 3-4 days

## Templates to Create

1. **Single Person Starter**
2. **Family Budget**
3. **Student Budget**
4. **Freelancer / Variable Income**
5. **Debt Payoff Focus**
6. **Custom (Start from scratch)**

## Implementation Tasks

### Database Schema
- [ ] Create migration file
- [ ] Create `data.budget_templates` table
- [ ] Define template structure (JSON)
- [ ] Insert default templates

### Template Data Structure

```json
{
  "name": "Single Person Starter",
  "description": "Basic categories for individual living",
  "target_audience": "single",
  "groups": [
    {
      "name": "Food & Dining",
      "icon": "🍔",
      "categories": ["Groceries", "Restaurants"]
    },
    ...
  ]
}
```

### Template Definitions

#### Single Person Starter
- [ ] Define category groups and categories
- [ ] Add helpful descriptions
- [ ] Set reasonable defaults

#### Family Budget
- [ ] Define family-oriented categories
- [ ] Include childcare, education
- [ ] Add family activity categories

#### Student Budget
- [ ] Education-focused categories
- [ ] Limited income assumption
- [ ] Textbooks, supplies, etc.

#### Freelancer Template
- [ ] Variable income handling
- [ ] Business expense categories
- [ ] Tax savings category

#### Debt Payoff Template
- [ ] Debt categories prominent
- [ ] Minimal discretionary spending
- [ ] Progress tracking focus

### Template Selection UI
- [ ] Create template selection page
- [ ] Show during onboarding (Step 5)
- [ ] Preview template details
- [ ] Allow customization before applying

### Template Application Logic
- [ ] Function to apply template to ledger
- [ ] Create category groups
- [ ] Create categories
- [ ] Set display order
- [ ] Associate icons

### API Endpoints
- [ ] GET `/api/templates` - List all templates
- [ ] GET `/api/templates/{id}` - Get template details
- [ ] POST `/api/templates/apply` - Apply template to ledger

## Template Content

Each template should include:
- Name and description
- Target audience
- Category groups with icons
- Individual categories
- Optional: default budget amounts
- Optional: helpful tips for that scenario

## Acceptance Criteria
- [ ] At least 5 templates available
- [ ] Templates can be previewed before applying
- [ ] Applying template creates all categories
- [ ] Templates integrate with onboarding
- [ ] Users can still customize after applying
- [ ] Templates are well-organized and logical

## Files to Create/Modify
- `migrations/085_budget_templates.sql`
- `public/api/templates.php`
- `public/onboarding/templates.php`
- `public/templates/list.php` (for viewing templates)

## Testing
- [ ] Test each template application
- [ ] Verify all categories are created
- [ ] Test with new ledger
- [ ] User testing: is template selection clear?

## Future Enhancements
- Community-contributed templates
- Template customization before applying
- Template marketplace

## Related Issues
- Integrates with: Onboarding Wizard
- Enhances: First-time user experience
```

---

### Issue #10: Create Comprehensive Help Center

**Title:** 🟢 [Medium Priority] Create Comprehensive Help Center

**Labels:** `priority:medium`, `enhancement`, `documentation`, `phase-5`

**Description:**
```markdown
## Overview
Create a comprehensive help center with guides, tutorials, FAQ, and troubleshooting documentation.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 5.2
- Impact: **Medium** - Reduces support burden, helps users
- Estimated Effort: 7-10 days (can be built incrementally)

## Help Center Structure

```
Help Center
├── Getting Started
│   ├── Welcome to PGBudget
│   ├── Your First Budget
│   ├── Adding Accounts
│   ├── Creating Categories
│   └── Recording Transactions
├── Core Concepts
│   ├── Zero-Sum Budgeting
│   ├── Double-Entry Accounting (Optional)
│   ├── Budget Categories vs Accounts
│   ├── The PGBudget Method
│   └── Transaction Workflow
├── How-To Guides
│   ├── Record a Transaction
│   ├── Budget Your Money
│   ├── Move Money Between Categories
│   ├── Set Up Recurring Transactions
│   ├── Track Credit Cards
│   ├── Create Savings Goals
│   ├── Generate Reports
│   └── Import Transactions (if available)
├── Advanced Features
│   ├── Loan Management
│   ├── Installment Plans
│   ├── Split Transactions
│   ├── Account Reconciliation
│   └── Custom Reports
├── FAQ
│   ├── General Questions
│   ├── Getting Started
│   ├── Transactions
│   ├── Budgeting
│   ├── Accounts
│   └── Technical Issues
└── Troubleshooting
    ├── Common Issues
    ├── Error Messages
    └── Contact Support
```

## Implementation Tasks

### Help Center Infrastructure
- [ ] Create `public/help/` directory
- [ ] Create main help index page
- [ ] Create navigation system
- [ ] Create search functionality (optional)
- [ ] Design help article template

### Getting Started Section
- [ ] Write "Welcome to PGBudget" article
- [ ] Write "Your First Budget" tutorial
- [ ] Write "Adding Accounts" guide
- [ ] Write "Creating Categories" guide
- [ ] Write "Recording Transactions" guide
- [ ] Add screenshots for each step

### Core Concepts Section
- [ ] Explain zero-sum budgeting
- [ ] Explain double-entry accounting (simplified)
- [ ] Explain categories vs accounts
- [ ] Explain The PGBudget Method
- [ ] Explain transaction workflow
- [ ] Use diagrams and examples

### How-To Guides (Step-by-Step)
- [ ] Record a Transaction
- [ ] Budget Your Money
- [ ] Move Money Between Categories
- [ ] Set Up Recurring Transactions
- [ ] Track Credit Cards
- [ ] Create Savings Goals
- [ ] Generate Reports

Each guide should include:
- Quick steps overview
- Detailed walkthrough with screenshots
- Video or GIF demonstration (optional)
- Related articles
- "Still need help?" section

### Advanced Features Documentation
- [ ] Loan Management guide
- [ ] Installment Plans guide
- [ ] Split Transactions guide
- [ ] Account Reconciliation guide
- [ ] Custom Reports guide

### FAQ Section
- [ ] Compile common questions from users
- [ ] Write clear, concise answers
- [ ] Organize by topic
- [ ] Make searchable

### Troubleshooting Section
- [ ] Document common error messages
- [ ] Provide solutions for each
- [ ] Link to relevant guides
- [ ] Contact support information

### Help Article Template

```markdown
# [Article Title]

## Quick Steps
1. Step one
2. Step two
3. Step three

## Detailed Guide
[Step-by-step with screenshots]

## Video Tutorial
[Embedded video or GIF]

## Tips & Best Practices
- Tip 1
- Tip 2

## Common Questions
**Q: Question here?**
A: Answer here.

## Related Articles
- [Related Article 1]
- [Related Article 2]

## Still need help?
[Contact Support] [Ask Community]
```

### Search Functionality (Optional)
- [ ] Implement search bar
- [ ] Index all help articles
- [ ] Provide search results
- [ ] Highlight matching terms

### Visual Elements
- [ ] Take screenshots for tutorials
- [ ] Create diagrams for concepts
- [ ] Record GIFs or videos (optional)
- [ ] Use consistent formatting

### Integration
- [ ] Add "Help" link to main navigation
- [ ] Add contextual help links throughout app
- [ ] Link from tooltips to relevant articles
- [ ] Link from error messages to troubleshooting

## Acceptance Criteria
- [ ] All major features documented
- [ ] Step-by-step guides with visuals
- [ ] FAQ covers common questions
- [ ] Troubleshooting for common issues
- [ ] Easy navigation and search
- [ ] Mobile-friendly design
- [ ] Clear, beginner-friendly language

## Content Writing Guidelines
1. Use simple, clear language
2. Start with what the user wants to accomplish
3. Provide step-by-step instructions
4. Use screenshots generously
5. Anticipate common questions
6. Link to related articles
7. Keep articles focused (one topic per article)

## Files to Create
- `public/help/index.php`
- `public/help/getting-started/*.php`
- `public/help/core-concepts/*.php`
- `public/help/how-to/*.php`
- `public/help/advanced/*.php`
- `public/help/faq.php`
- `public/help/troubleshooting.php`
- `public/css/help.css`

## Maintenance Plan
- [ ] Review and update quarterly
- [ ] Add new articles for new features
- [ ] Monitor which articles are most viewed
- [ ] Update based on user feedback

## Testing
- [ ] User testing: can users find answers?
- [ ] Verify all links work
- [ ] Test search functionality
- [ ] Check mobile responsiveness

## Metrics to Track
- Most viewed articles
- Search queries
- "Was this helpful?" feedback
- Time spent on help pages

## Related Issues
- Supports: All feature issues
- Integrates with: Tooltips system
```

---

### Issue #11: Implement First-Time Tips System

**Title:** 🟢 [Medium Priority] Implement First-Time Tips System

**Labels:** `priority:medium`, `enhancement`, `ux`, `phase-5`

**Description:**
```markdown
## Overview
Create a contextual tips system that shows helpful hints to users as they use the application, educating them about features and best practices.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 5.1
- Impact: **Medium** - Educates users, builds good habits
- Estimated Effort: 3-4 days

## Tip System Design

```
┌─────────────────────────────────────────┐
│ 💡 Tip: Give Every Dollar a Job          │
│                                          │
│ See that $1,250 "Available to Budget"?   │
│ That money is waiting for you to tell   │
│ it what to do! Assign it to categories   │
│ so you know exactly what it's for.       │
│                                          │
│        [Got it!]        [Show me how]    │
│                              [Don't show │
│                               tips again]│
└─────────────────────────────────────────┘
```

## Implementation Tasks

### Database Schema
- [ ] Create `data.tips` table (tip library)
- [ ] Create `data.user_tips` table (user progress)
- [ ] Define tip structure and metadata
- [ ] Insert default tips

### Tip Library

Define tips for key concepts:

1. **Available to Budget**
   - Where: Dashboard
   - When: When balance > 0
   - Content: Explain "Give every dollar a job"

2. **Category Spending**
   - Where: Category list
   - When: First transaction in category
   - Content: Explain budgeted vs spent

3. **Overspending**
   - Where: Dashboard
   - When: First overspent category
   - Content: How to handle overspending

4. **Goals**
   - Where: Goals page
   - When: First visit
   - Content: How goals help you save

5. **Recurring Transactions**
   - Where: Transactions page
   - When: After 3rd similar transaction
   - Content: Suggest setting up recurring

6. **Monthly Rollover**
   - Where: Dashboard
   - When: New month starts
   - Content: Explain how rollover works

7. **Account Reconciliation**
   - Where: Accounts page
   - When: After 1 week
   - Content: Why reconciliation matters

8. **Split Transactions**
   - Where: Add transaction modal
   - When: After 10 transactions
   - Content: When to use splits

### Tip Component
- [ ] Create `public/components/tip-card.php`
- [ ] Design tip display component
- [ ] Add dismiss functionality
- [ ] Add "Show me how" functionality
- [ ] Add "Don't show tips" option

### Tip Display Logic
- [ ] Create `public/js/tips-system.js`
- [ ] Check which tips user has seen
- [ ] Determine which tip to show (context-aware)
- [ ] Show one tip per session (not overwhelming)
- [ ] Track tip dismissals

### API Endpoints
- [ ] GET `/api/tips/next` - Get next tip to show
- [ ] POST `/api/tips/dismiss` - Dismiss specific tip
- [ ] POST `/api/tips/disable` - Disable all tips
- [ ] GET `/api/tips/status` - Get user's tip progress

### Tip Triggers

Define when each tip should appear:
- [ ] Page-based triggers (show on specific pages)
- [ ] Action-based triggers (after specific actions)
- [ ] Time-based triggers (after X days)
- [ ] State-based triggers (when conditions met)

### "Show Me How" Feature
- [ ] Link to relevant help article
- [ ] Launch interactive tutorial (optional)
- [ ] Highlight relevant UI element

### User Preferences
- [ ] Add tips settings to user preferences
- [ ] Toggle tips on/off
- [ ] Reset tips (show all again)
- [ ] Skip specific tips

## Tip Content

Each tip should include:
- Title (short, clear)
- Content (1-3 sentences max)
- Icon (emoji or image)
- Action buttons (Got it, Show me how)
- "Don't show tips" option
- Link to help article

## Example Tips

### Tip 1: Available to Budget
```
💡 Tip: Give Every Dollar a Job

See that $1,250 "Available to Budget"? That money is waiting for you to tell it what to do! Assign it to categories so you know exactly what it's for.

[Got it!] [Show me how] [Don't show tips again]
```

### Tip 2: Overspending
```
⚠️ Tip: Handling Overspending

If you overspend a category, don't worry! Just move money from another category to cover it. This keeps your budget balanced and honest.

[Got it!] [Show me how] [Don't show tips again]
```

### Tip 3: Monthly Rollover
```
🔄 Tip: Money Rolls Over

At the end of the month, any unspent money in your categories rolls over to next month. You never lose what you've budgeted!

[Got it!] [Learn more] [Don't show tips again]
```

## Acceptance Criteria
- [ ] Tips appear contextually (right place, right time)
- [ ] Only one tip shown per session
- [ ] Tips can be dismissed permanently
- [ ] "Show me how" links work correctly
- [ ] Tips are non-intrusive
- [ ] User can disable all tips
- [ ] Tips are helpful, not annoying

## Tip Writing Guidelines
1. Keep it brief (2-3 sentences max)
2. Focus on one concept per tip
3. Explain WHY, not just WHAT
4. Use friendly, encouraging tone
5. Provide actionable next step
6. Link to more detailed help

## Files to Create/Modify
- `migrations/086_tips_system.sql`
- `public/components/tip-card.php`
- `public/js/tips-system.js`
- `public/api/tips.php`
- `public/css/tips.css`

## Testing
- [ ] Test each tip in context
- [ ] Verify triggers work correctly
- [ ] Test dismiss functionality
- [ ] Test "Show me how" links
- [ ] User feedback: are tips helpful?

## Metrics to Track
- Tips shown vs dismissed
- Tips with "Show me how" clicked
- Tips that users disable
- Feature adoption after tips

## Related Issues
- Works with: Onboarding Wizard
- Works with: Help Center
- Enhances: User education
```

---

### Issue #12: Implement Simplified Budget Assignment Modal

**Title:** 🟢 [Medium Priority] Implement Simplified Budget Assignment Modal

**Labels:** `priority:medium`, `enhancement`, `ux`, `phase-4`

**Description:**
```markdown
## Overview
Create a simple modal dialog for budgeting money to categories, replacing the multi-step allocation workflow.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 4.2
- Impact: **Medium** - Second most common action after transactions
- Estimated Effort: 2-3 days

## Modal Design

```
┌─────────────────────────────────────────┐
│ Budget Money                       [×]   │
│                                          │
│ 💰 You have $1,250.00 ready to budget    │
│                                          │
│ Put $ [________] into...                 │
│                                          │
│ [Groceries                         ▼]   │
│                                          │
│ ℹ️ This category currently has $150.00   │
│                                          │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━         │
│                                          │
│        [Cancel]      [Budget It!]        │
└─────────────────────────────────────────┘
```

## Implementation Tasks

### Modal Component
- [ ] Create `public/components/modal-budget.php`
- [ ] Design clean, focused modal layout
- [ ] Show available to budget prominently
- [ ] Amount input field
- [ ] Category dropdown selector
- [ ] Show current category balance

### Quick Templates
Add quick budget options:
- [ ] "Budget All" - Assign all remaining to selected category
- [ ] "Distribute Evenly" - Split across all categories
- [ ] "Follow Last Month" - Copy last month's allocations

### JavaScript
- [ ] Create `public/js/quick-budget.js`
- [ ] Modal open/close functionality
- [ ] Real-time balance updates
- [ ] Form validation
- [ ] AJAX submission
- [ ] Success feedback

### API Integration
- [ ] Use existing `api.assign_to_category()` function
- [ ] Handle API response
- [ ] Update UI on success
- [ ] Error handling

### Smart Features
- [ ] Pre-fill amount if only small balance remaining
- [ ] Suggest categories with $0 budgeted
- [ ] Warning if budgeting more than available
- [ ] Show preview of result

### Integration Points
- [ ] Trigger from dashboard "Budget Money" button
- [ ] Trigger from quick actions widget
- [ ] Trigger from category list
- [ ] Keyboard shortcut (Alt+B)

## Acceptance Criteria
- [ ] Modal opens from multiple locations
- [ ] Clearly shows available to budget amount
- [ ] Easy category selection
- [ ] Real-time validation
- [ ] AJAX submission without page reload
- [ ] Clear success/error feedback
- [ ] Mobile-friendly
- [ ] Keyboard accessible

## Advanced Features (Optional)
- [ ] Multi-category budgeting (budget to multiple at once)
- [ ] Category group selection
- [ ] Recently used categories list
- [ ] Budget templates dropdown

## Files to Create/Modify
- `public/components/modal-budget.php`
- `public/js/quick-budget.js`
- `public/css/modals.css`
- Integration with dashboard and other pages

## Testing
- [ ] Test with various amounts
- [ ] Test validation (negative, too large, etc.)
- [ ] Test category selection
- [ ] Test on mobile
- [ ] Test keyboard navigation

## Related Issues
- Part of: Quick Actions Widget
- Similar to: Quick Add Transaction Modal
```

---

## ⚪ NICE-TO-HAVE ISSUES

### Issue #13: Implement Simple/Advanced Mode Toggle

**Title:** ⚪ [Nice-to-Have] Implement Simple/Advanced Mode Toggle

**Labels:** `priority:low`, `enhancement`, `ux`, `phase-6`

**Description:**
```markdown
## Overview
Add UI complexity toggle allowing users to choose between simplified interface (hiding advanced features) and full-featured advanced mode.

## Context
- Part of Phase 6 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 6.1
- Impact: **Low-Medium** - Benefits some users
- Estimated Effort: 5-7 days

## Mode Definitions

### Simple Mode
Shows only:
- Basic budgeting and categories
- Simple transactions
- Account balances
- Basic goals
- Essential reports

Hides:
- Loan management
- Installment plans
- Split transactions
- Double-entry details
- Advanced reports
- Account reconciliation

### Advanced Mode
Shows all features (current behavior)

## Implementation Tasks

### User Preference
- [ ] Add `ui_mode` column to users table
- [ ] Create settings page toggle
- [ ] Default new users to "simple" mode
- [ ] Store preference in database

### Feature Flags
- [ ] Define which features are "advanced"
- [ ] Create feature flag system
- [ ] Check mode throughout application
- [ ] Conditionally render based on mode

### Navigation
- [ ] Hide advanced menu items in simple mode
- [ ] Show "Unlock More Features" prompt
- [ ] Easy toggle to switch modes

### In-App Promotion
- [ ] After 2 weeks, suggest advanced features
- [ ] "You're ready for [Feature]!" banners
- [ ] Gradual feature introduction

### Settings Page
```
┌─────────────────────────────────────────┐
│ Interface Mode                           │
│                                          │
│ ○ Simple Mode (Recommended for new      │
│   users)                                 │
│ ● Advanced Mode (Show all features)     │
│                                          │
│ ℹ️ Simple Mode hides advanced features   │
│   until you're ready for them.          │
│                                          │
│ [Save Changes]                           │
└─────────────────────────────────────────┘
```

### Feature List

Define what's shown in each mode:

**Always Available (Both Modes):**
- Dashboard
- Add transactions
- Budget categories
- Account balances
- Basic reports

**Advanced Only:**
- Loans
- Installments
- Split transactions
- Reconciliation
- Advanced reports
- Custom date ranges

## Acceptance Criteria
- [ ] Users can toggle between modes in settings
- [ ] Simple mode hides advanced features
- [ ] Advanced mode shows everything
- [ ] Preference persists across sessions
- [ ] Clear indication of current mode
- [ ] Easy promotion path to advanced mode

## Files to Modify
- All navigation/menu files
- All feature pages (conditional rendering)
- Settings page
- Database migration for preference

## Testing
- [ ] Test in simple mode
- [ ] Test in advanced mode
- [ ] Test mode switching
- [ ] Verify features show/hide correctly

## Related Issues
- Works with: Feature Introduction Timeline
```

---

### Issue #14: Implement Celebration Moments & Achievements

**Title:** ⚪ [Nice-to-Have] Implement Celebration Moments & Achievements

**Labels:** `priority:low`, `enhancement`, `ux`, `gamification`, `phase-7`

**Description:**
```markdown
## Overview
Add positive reinforcement through celebrations, achievements, and encouraging messages when users reach milestones.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 7.2
- Impact: **Low-Medium** - Delight factor
- Estimated Effort: 3-4 days

## Celebration Types

### 1. Budget Milestones
```
🎉 Congratulations!
You've budgeted all your money!

Every dollar now has a job. This is the foundation of successful budgeting.

[Awesome!]
```

Trigger: `left_to_budget` reaches $0

### 2. Consistency Streaks
```
🔥 7 Day Streak!
You've checked your budget every day this week.
Building great financial habits!

[Keep it up!]
```

Triggers:
- 3 day streak
- 7 day streak
- 30 day streak
- 100 day streak

### 3. Goal Completion
```
🏆 Goal Reached!
Your "Emergency Fund" goal is complete!

You saved $1,000 just as planned. Time to celebrate!

[Set New Goal] [Share Achievement]
```

Trigger: Goal reaches 100%

### 4. Monthly Success
```
✨ Great month!
All categories stayed on track in December.
You're mastering your budget!

[View Report]
```

Trigger: End of month, no overspending

### 5. First Achievements
- First transaction recorded
- First budget allocation
- First goal created
- First month completed
- First reconciliation

## Implementation Tasks

### Database
- [ ] Create `data.user_achievements` table
- [ ] Track achievement timestamps
- [ ] Store achievement metadata

### Achievement Types
```sql
CREATE TABLE data.user_achievements (
  id SERIAL PRIMARY KEY,
  user_id INTEGER REFERENCES data.users(id),
  achievement_type VARCHAR(50),
  achieved_at TIMESTAMP DEFAULT NOW(),
  metadata JSONB DEFAULT '{}'
);
```

### Achievement Tracking
- [ ] Function to check achievements
- [ ] Trigger on relevant actions
- [ ] Store achievement unlocks
- [ ] Prevent duplicate celebrations

### Celebration Display
- [ ] Create celebration modal component
- [ ] Add confetti animation (canvas-confetti.js)
- [ ] Sound effects (optional, with mute option)
- [ ] Shareable achievement cards (optional)

### Achievement List
Define all achievement types:
- [ ] `first_budget` - Created first budget
- [ ] `first_transaction` - Recorded first transaction
- [ ] `all_budgeted` - Budgeted all available money
- [ ] `first_goal` - Created first goal
- [ ] `goal_completed` - Completed a goal
- [ ] `streak_3` - 3 day login streak
- [ ] `streak_7` - 7 day login streak
- [ ] `streak_30` - 30 day streak
- [ ] `month_on_track` - No overspending in month
- [ ] `first_month` - Completed first month

### Confetti Implementation
```javascript
// Example using canvas-confetti
confetti({
  particleCount: 100,
  spread: 70,
  origin: { y: 0.6 }
});
```

### Settings
- [ ] User preference to enable/disable celebrations
- [ ] Mute sound effects option
- [ ] Reduce animations option

## Acceptance Criteria
- [ ] Celebrations trigger at appropriate times
- [ ] Confetti animation works
- [ ] Achievements are tracked in database
- [ ] Users can view achievement history
- [ ] Settings allow disabling celebrations
- [ ] Not annoying or excessive

## Files to Create
- `migrations/087_achievements.sql`
- `public/components/celebration-modal.php`
- `public/js/celebrations.js`
- `public/css/celebrations.css`
- `public/achievements/list.php` (achievement history)

## Libraries
- canvas-confetti.js for confetti animation
- SweetAlert2 or custom modal for achievement display

## Testing
- [ ] Trigger each achievement type
- [ ] Test confetti animation
- [ ] Test on different browsers
- [ ] User feedback: delightful or annoying?

## Related Issues
- Enhances overall UX
- Gamification element
```

---

### Issue #15: Implement Feature Introduction Timeline

**Title:** ⚪ [Nice-to-Have] Implement Gradual Feature Introduction Timeline

**Labels:** `priority:low`, `enhancement`, `ux`, `phase-6`

**Description:**
```markdown
## Overview
Gradually introduce advanced features over time as users become comfortable with basics, reducing initial overwhelm.

## Context
- Part of Phase 6 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 6.2
- Impact: **Low** - Gradual learning curve
- Estimated Effort: 5-6 days

## Feature Timeline

| Timeframe | Features Unlocked |
|-----------|------------------|
| Day 1 | Basic budgeting, simple transactions, accounts |
| Week 1 | Recurring transactions, budget goals |
| Week 2 | Credit card tracking, reports |
| Week 3 | Loan management, installment plans |
| Month 2+ | All advanced features |

## Implementation Tasks

### Feature Flags
- [ ] Create `data.feature_flags` table
- [ ] Define unlock requirements for each feature
- [ ] Check feature availability on page load

### Unlock Banner
```
┌─────────────────────────────────────────┐
│ 🎉 New Feature Available!                │
│                                          │
│ You're ready for Goals! Track savings    │
│ targets and see your progress.           │
│                                          │
│ [Learn More] [Enable Feature] [Not Now]  │
└─────────────────────────────────────────┘
```

### Feature Unlock Logic
- [ ] Track user registration date
- [ ] Calculate days since registration
- [ ] Check if feature should be unlocked
- [ ] Show unlock banner when available

### Database
```sql
CREATE TABLE data.feature_flags (
  id SERIAL PRIMARY KEY,
  feature_name VARCHAR(50) UNIQUE,
  requires_days_active INTEGER,
  enabled_by_default BOOLEAN DEFAULT TRUE,
  description TEXT
);

-- User tracking
ALTER TABLE data.users
ADD COLUMN registered_at TIMESTAMP DEFAULT NOW();
```

### Feature Introduction Banners
- [ ] Design banner component
- [ ] Show when feature unlocks
- [ ] Link to feature tutorial
- [ ] Allow dismissal
- [ ] Track banner interactions

### API Function
```sql
CREATE FUNCTION api.is_feature_available(
  feature_name TEXT
) RETURNS BOOLEAN AS $$
  -- Check user's registration date
  -- Check feature requirements
  -- Return true/false
$$ LANGUAGE plpgsql;
```

### Integration
- [ ] Check feature availability in navigation
- [ ] Check before showing feature pages
- [ ] Show "locked" state for unavailable features
- [ ] Provide unlock information

## Feature List

**Day 1 (Always Available):**
- Dashboard
- Accounts
- Basic transactions
- Categories
- Basic budgeting

**Week 1:**
- Recurring transactions
- Goals system

**Week 2:**
- Credit card management
- Reports

**Week 3:**
- Loan management
- Installment plans

**Month 2:**
- Split transactions
- Advanced reports
- All remaining features

## Acceptance Criteria
- [ ] Features unlock based on timeline
- [ ] Users notified when features unlock
- [ ] Clear indication of locked features
- [ ] Users can unlock early (optional)
- [ ] Settings to disable gradual intro

## User Override
- [ ] Admin users see all features immediately
- [ ] Setting to "Show all features now"
- [ ] Respect user preference

## Files to Create/Modify
- `migrations/088_feature_timeline.sql`
- `public/components/feature-unlock-banner.php`
- `public/js/feature-timeline.js`
- All feature pages (check availability)

## Testing
- [ ] Test with new user account
- [ ] Test timeline progression
- [ ] Test unlock banners
- [ ] Test manual unlock

## Related Issues
- Depends on: Simple/Advanced Mode
- Works with: First-Time Tips
```

---

### Issue #16: Implement Smart Category Suggestions

**Title:** ⚪ [Nice-to-Have] Implement Smart Category Suggestions

**Labels:** `priority:low`, `enhancement`, `ai`, `phase-8`

**Description:**
```markdown
## Overview
Auto-suggest categories based on payee/merchant names using machine learning from user's transaction history.

## Context
- Part of Phase 8 of Usability Improvement Plan
- Reference: `docs/USABILITY_IMPROVEMENT_PLAN.md` - Phase 8.2
- Impact: **Low** - Convenience feature
- Estimated Effort: 4-5 days

## How It Works

1. User enters payee: "Safeway"
2. System checks previous transactions
3. Found: "Safeway" → Groceries (used 15 times)
4. Auto-select "Groceries" category
5. User can override if needed

## Implementation Tasks

### Database
- [ ] Create `data.payee_category_mapping` table
- [ ] Track payee → category associations
- [ ] Store use count and last used date

```sql
CREATE TABLE data.payee_category_mapping (
  id SERIAL PRIMARY KEY,
  user_id INTEGER REFERENCES data.users(id),
  payee_name TEXT,
  category_id INTEGER REFERENCES data.accounts(id),
  use_count INTEGER DEFAULT 1,
  last_used_at TIMESTAMP DEFAULT NOW(),
  UNIQUE(user_id, payee_name)
);
```

### Learning System
- [ ] On transaction save, record payee → category
- [ ] Increment use_count if exists
- [ ] Update last_used_at
- [ ] Handle payee name variations (fuzzy matching)

### Suggestion API
- [ ] GET `/api/suggestions/category?payee=Safeway`
- [ ] Return most likely category
- [ ] Return confidence score
- [ ] Return alternative suggestions

### Transaction Form Integration
- [ ] Add suggestion logic to quick-add modal
- [ ] Auto-fill category when payee entered
- [ ] Show confidence indicator
- [ ] Allow user override

### Common Merchant Database
Pre-populate with common merchants:
```json
{
  "safeway": "Groceries",
  "walmart": "Groceries",
  "target": "Shopping",
  "shell": "Gas",
  "chevron": "Gas",
  "starbucks": "Dining",
  "amazon": "Shopping",
  "netflix": "Subscriptions",
  "spotify": "Subscriptions",
  "pg&e": "Utilities"
}
```

- [ ] Create merchant database
- [ ] Use for new users (no history yet)
- [ ] Fall back to when user data insufficient

### Fuzzy Matching
Handle variations:
- "Safeway" vs "SAFEWAY #123" vs "Safeway Inc"
- [ ] Implement fuzzy string matching
- [ ] Normalize payee names
- [ ] Match partial strings

### Machine Learning (Advanced)
- [ ] Analyze transaction patterns
- [ ] Consider amount ranges
- [ ] Consider transaction dates
- [ ] Improve over time

## Acceptance Criteria
- [ ] Suggestions appear when typing payee
- [ ] Suggestions improve with usage
- [ ] Users can override suggestions
- [ ] Works with common merchants immediately
- [ ] Handles name variations
- [ ] No performance impact

## Example Workflow

1. User opens "Add Transaction"
2. Types "Safeway" in payee field
3. System suggests "Groceries" category
4. User accepts or changes
5. Transaction saved
6. System learns association

## Files to Create
- `migrations/089_smart_suggestions.sql`
- `public/api/suggestions.php`
- `public/js/suggestion-engine.js`
- `data/merchant-database.json`

## Future Enhancements
- Amount-based suggestions
- Date-based suggestions (recurring patterns)
- Community-sourced merchant database
- Import bank's merchant categorization

## Testing
- [ ] Test with various payee names
- [ ] Test learning over time
- [ ] Test fuzzy matching
- [ ] Test with common merchants
- [ ] User feedback on accuracy

## Related Issues
- Enhances: Quick Add Transaction
- Optional enhancement
```

---

## Summary

**Total Issues: 16**

- 🔴 **Critical Priority: 4 issues** (Do First)
- 🟡 **High Priority: 4 issues** (Do Soon)
- 🟢 **Medium Priority: 4 issues** (Do Later)
- ⚪ **Nice-to-Have: 4 issues** (Polish)

## Next Steps

1. Review and approve this issue breakdown
2. Create actual GitHub issues (see instructions at top)
3. Assign issues to team members
4. Set up project board for tracking
5. Begin with Critical Priority issues

## Creating the Issues

### Using GitHub CLI:
```bash
# Make sure you're authenticated
gh auth login

# Then use the script
bash docs/create-github-issues.sh
```

### Using GitHub Web Interface:
1. Go to your repository
2. Click "Issues" tab
3. Click "New Issue"
4. Copy content from each issue above
5. Set appropriate labels
6. Create issue
7. Repeat for all 16 issues

### Using GitHub API:
See provided Python script: `scripts/create_issues.py`

---

**Document Version:** 1.0
**Created:** October 26, 2025
**Last Updated:** October 26, 2025
