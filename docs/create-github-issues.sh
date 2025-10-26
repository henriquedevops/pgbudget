#!/bin/bash

# Script to create GitHub issues for Usability Improvement Plan
# Requires: gh CLI installed and authenticated (gh auth login)

set -e

echo "🚀 Creating GitHub Issues for Usability Improvement Plan"
echo "=========================================================="
echo ""

# Check if gh is installed
if ! command -v gh &> /dev/null; then
    echo "❌ Error: GitHub CLI (gh) is not installed"
    echo "Install it from: https://cli.github.com/"
    exit 1
fi

# Check if authenticated
if ! gh auth status &> /dev/null; then
    echo "❌ Error: Not authenticated with GitHub"
    echo "Run: gh auth login"
    exit 1
fi

echo "✓ GitHub CLI is installed and authenticated"
echo ""

# Function to create an issue
create_issue() {
    local title="$1"
    local labels="$2"
    local body="$3"

    echo "Creating issue: $title"
    gh issue create \
        --title "$title" \
        --label "$labels" \
        --body "$body"
    echo "✓ Created"
    echo ""
}

# =============================================================================
# CRITICAL PRIORITY ISSUES
# =============================================================================

echo "📌 Creating Critical Priority Issues..."
echo ""

# Issue #1: Onboarding Wizard
create_issue \
"🔴 [Critical] Implement Onboarding Wizard" \
"priority:critical,enhancement,ux,phase-1" \
"$(cat <<'EOF'
## Overview
Create a 5-step onboarding wizard for new users to guide them through initial setup and teach budgeting principles.

## Context
- Part of Phase 1 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 1
- Impact: **Highest** - First impressions determine retention
- Estimated Effort: 5-7 days

## Implementation Tasks

### Step 1: Welcome Screen
- [ ] Create \`public/onboarding/wizard.php\` main controller
- [ ] Create \`public/onboarding/step1-welcome.php\`
- [ ] Design welcome screen with "Get Started" and "Skip" options
- [ ] Add progress indicator (Step 1 of 5)

### Step 2: Philosophy Introduction
- [ ] Create \`public/onboarding/step2-philosophy.php\`
- [ ] Present PGBudget Method (4 principles)
- [ ] Add engaging copy about budgeting philosophy

### Step 3: Budget Creation
- [ ] Create \`public/onboarding/step3-budget.php\`
- [ ] Form to create first ledger with name and description
- [ ] Provide helpful examples

### Step 4: First Account
- [ ] Create \`public/onboarding/step4-account.php\`
- [ ] Account type selection (Checking, Savings, Cash, Other)
- [ ] Account name and starting balance inputs

### Step 5: Quick Start Categories
- [ ] Create \`public/onboarding/step5-categories.php\`
- [ ] Pre-select common categories (Groceries, Rent, etc.)
- [ ] Allow adding custom categories
- [ ] Option to use budget templates

### Backend & Database
- [ ] Add database columns to track onboarding state
- [ ] Create migration file \`migrations/084_onboarding_system.sql\`
- [ ] Implement session management for wizard state
- [ ] Add "resume onboarding" functionality
- [ ] Redirect logic: check onboarding status on login

### UI & Styling
- [ ] Create \`public/css/onboarding.css\`
- [ ] Create \`public/js/onboarding.js\` for navigation
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
- \`public/onboarding/wizard.php\`
- \`public/onboarding/step1-welcome.php\`
- \`public/onboarding/step2-philosophy.php\`
- \`public/onboarding/step3-budget.php\`
- \`public/onboarding/step4-account.php\`
- \`public/onboarding/step5-categories.php\`
- \`public/css/onboarding.css\`
- \`public/js/onboarding.js\`
- \`migrations/084_onboarding_system.sql\`
EOF
)"

# Issue #2: Language Simplification
create_issue \
"🔴 [Critical] Simplify Technical Language Throughout Application" \
"priority:critical,ux,copy,phase-2" \
"$(cat <<'EOF'
## Overview
Replace technical accounting terminology with user-friendly language throughout the application to reduce intimidation and improve accessibility.

## Context
- Part of Phase 2 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 2.1
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

## Implementation Tasks

### Phase 1: Audit & Document
- [ ] Audit all user-facing text across application
- [ ] Document every instance of technical terms
- [ ] Create comprehensive terminology mapping
- [ ] Get stakeholder approval on new terms

### Phase 2: Update Pages
- [ ] Update \`public/budget/dashboard.php\`
- [ ] Update \`public/accounts/*.php\`
- [ ] Update \`public/transactions/*.php\`
- [ ] Update \`public/categories/*.php\`
- [ ] Update \`public/reports/*.php\`
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

## Acceptance Criteria
- [ ] All user-facing pages use simplified language
- [ ] Technical terms only appear in developer documentation
- [ ] Error messages are clear and actionable
- [ ] Navigation is intuitive with new labels
- [ ] Help text uses consistent terminology

## Files to Audit/Modify
- All \`public/**/*.php\` files
- \`includes/header.php\` (navigation)
- API endpoint files
- JavaScript files with user-facing strings

## Testing
- [ ] Manual review of every page
- [ ] User testing with non-technical users
- [ ] Ensure no broken functionality from label changes
EOF
)"

# Issue #3: Quick Add Transaction Modal
create_issue \
"🔴 [Critical] Implement Quick Add Transaction Modal" \
"priority:critical,enhancement,ux,phase-4" \
"$(cat <<'EOF'
## Overview
Replace the multi-page transaction creation flow with a quick modal dialog accessible from anywhere in the application.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 4.1
- Impact: **High** - Most common user action
- Estimated Effort: 2-3 days

## Modal Component Tasks
- [ ] Create \`public/components/modal-transaction.php\`
- [ ] Design modal with simplified form layout
- [ ] Include fields: Amount, Payee/Description, Account, Category, Date
- [ ] Add "Advanced Options" collapsible section

### Advanced Options (Collapsed by Default)
- [ ] Split transaction checkbox
- [ ] Recurring transaction checkbox
- [ ] Memo/notes field

### JavaScript
- [ ] Create \`public/js/quick-transaction.js\`
- [ ] Modal open/close functionality
- [ ] Form validation
- [ ] AJAX submission to API
- [ ] Success/error handling
- [ ] Update page without reload

### Integration
- [ ] Add "Add Transaction" button to header
- [ ] Add to dashboard quick actions
- [ ] Add keyboard shortcut (Alt+T)
- [ ] Trigger modal from multiple locations

## Acceptance Criteria
- [ ] Modal opens from any page via button/shortcut
- [ ] Form can be completed in < 30 seconds
- [ ] Validation provides clear error messages
- [ ] Success adds transaction without page reload
- [ ] Works on mobile devices
- [ ] Keyboard accessible (Tab, Enter, Esc)

## Files to Create/Modify
- \`public/components/modal-transaction.php\`
- \`public/js/quick-transaction.js\`
- \`public/css/modals.css\`
- \`public/api/transactions.php\`
- \`includes/header.php\`
EOF
)"

# Issue #4: Dashboard Redesign
create_issue \
"🔴 [Critical] Redesign Dashboard Summary Section" \
"priority:critical,enhancement,ux,design,phase-3" \
"$(cat <<'EOF'
## Overview
Redesign the top section of the dashboard to provide a clear, visual summary of budget status at a glance.

## Context
- Part of Phase 3 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 3.1
- Impact: **High** - Main landing page
- Estimated Effort: 3-4 days

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

## Acceptance Criteria
- [ ] Summary card is prominent at top of dashboard
- [ ] All key metrics are visible at a glance
- [ ] Quick action buttons are easily accessible
- [ ] Visual design is clean and modern
- [ ] Responsive on all screen sizes
- [ ] Loads quickly (< 2 seconds)

## Files to Create/Modify
- \`public/budget/dashboard.php\`
- \`public/css/dashboard-v2.css\`
- \`public/js/dashboard.js\`
EOF
)"

# =============================================================================
# HIGH PRIORITY ISSUES
# =============================================================================

echo "📌 Creating High Priority Issues..."
echo ""

# Issue #5: Tooltips
create_issue \
"🟡 [High Priority] Implement Tooltips & Inline Help System" \
"priority:high,enhancement,ux,help,phase-5" \
"$(cat <<'EOF'
## Overview
Add comprehensive tooltip system throughout the application to provide contextual help for every feature, term, and action.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 5.3
- Impact: **High** - Helps users everywhere
- Estimated Effort: 4-5 days

## Implementation Tasks

### Tooltip Library Integration
- [ ] Evaluate and choose tooltip library (Tippy.js recommended)
- [ ] Add library to project
- [ ] Create initialization script
- [ ] Set up theme and styling

### Core Implementation
- [ ] Create tooltip helper function in \`public/js/tooltips.js\`
- [ ] Create PHP helper for consistent markup
- [ ] Define tooltip trigger patterns

### Tooltip Coverage Areas
- [ ] Financial Terms (Available to Budget, Category Balance, etc.)
- [ ] Action Buttons (What happens when clicked?)
- [ ] Status Indicators (Color meanings)
- [ ] Complex Features (Goals, recurring, splits)
- [ ] Forms & Inputs (Why required? Format help)

### Styling
- [ ] Create \`public/css/tooltips.css\`
- [ ] Design consistent tooltip appearance
- [ ] Ensure readability
- [ ] Mobile-friendly touch behavior

## Acceptance Criteria
- [ ] Tooltips appear on hover (desktop) and tap (mobile)
- [ ] All financial terms have tooltips
- [ ] All action buttons have explanatory tooltips
- [ ] Tooltips use clear, friendly language
- [ ] Keyboard accessible
- [ ] Consistent styling across application

## Files to Create/Modify
- \`public/js/tooltips.js\`
- \`public/css/tooltips.css\`
- \`includes/functions.php\`
- All user-facing PHP pages
EOF
)"

# Issue #6: Friendly Messaging
create_issue \
"🟡 [High Priority] Implement Friendly & Encouraging Messaging" \
"priority:high,ux,copy,phase-7" \
"$(cat <<'EOF'
## Overview
Update all user-facing messages to be friendly, encouraging, and helpful rather than technical or bland.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 7.1
- Impact: **Medium-High** - Emotional connection
- Estimated Effort: 2-3 days

## Message Categories

### Empty States
Transform blank pages into encouraging guidance
- Before: "No transactions found."
- After: "🎉 Ready to start! Add your first transaction..."

### Success Messages
- Before: "Transaction added successfully."
- After: "✓ Nice! Transaction added. Your category now has $205 remaining."

### Error Messages
- Before: "Error: Invalid input"
- After: "❌ Oops! That didn't work. Here's how to fix it..."

## Implementation Tasks

- [ ] Audit all success messages
- [ ] Audit all error messages
- [ ] Audit all empty states
- [ ] Rewrite with friendly tone
- [ ] Update PHP files
- [ ] Update JavaScript files
- [ ] Create message style guide

## Message Writing Principles
1. Be Human - Write like a helpful friend
2. Be Positive - Focus on what users can do
3. Be Specific - Tell exactly what happened
4. Be Actionable - Provide next steps
5. Be Encouraging - Celebrate wins
6. Be Brief - Respect time

## Acceptance Criteria
- [ ] All empty states have helpful messages
- [ ] All success messages celebrate actions
- [ ] All errors explain problem + solution
- [ ] Consistent friendly tone
- [ ] Appropriate emoji use

## Files to Modify
- All \`public/**/*.php\` files
- \`public/js/**/*.js\` files
- API endpoint files
EOF
)"

# Issue #7: Quick Actions Widget
create_issue \
"🟡 [High Priority] Implement Persistent Quick Actions Widget" \
"priority:high,enhancement,ux,phase-4" \
"$(cat <<'EOF'
## Overview
Add a persistent quick actions bar at the top of every page for instant access to common actions.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 4.3
- Impact: **Medium** - Improves accessibility
- Estimated Effort: 1-2 days

## Implementation Tasks

### Quick Actions Component
- [ ] Create \`public/components/quick-actions.php\`
- [ ] Design horizontal button bar
- [ ] Add icons and labels
- [ ] Responsive design for mobile

### Button Actions
- [ ] "Add Transaction" → Opens quick transaction modal
- [ ] "Budget Money" → Opens budget assignment modal
- [ ] "Transfer" → Opens transfer modal

### Header Integration
- [ ] Add to \`includes/header.php\`
- [ ] Position below main navigation
- [ ] Show on all authenticated pages

### Keyboard Shortcuts
- [ ] Alt+T → Add Transaction
- [ ] Alt+B → Budget Money
- [ ] Alt+R → Transfer
- [ ] Create keyboard shortcut handler

## Acceptance Criteria
- [ ] Quick actions bar visible on all pages
- [ ] Three main actions easily accessible
- [ ] Keyboard shortcuts work globally
- [ ] Buttons open appropriate modals
- [ ] Responsive design works on mobile

## Files to Create/Modify
- \`public/components/quick-actions.php\`
- \`includes/header.php\`
- \`public/css/quick-actions.css\`
- \`public/js/keyboard-shortcuts.js\`
EOF
)"

# Issue #8: Visual Enhancements
create_issue \
"🟡 [High Priority] Implement Visual Enhancements & Design System" \
"priority:high,design,ux,phase-7" \
"$(cat <<'EOF'
## Overview
Implement consistent color system, icons, typography, and visual hierarchy throughout the application.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 7.3-7.4
- Impact: **Medium** - Professional appearance
- Estimated Effort: 4-5 days

## Implementation Tasks

### 1. Color System
- [ ] Create \`public/css/design-system.css\`
- [ ] Define CSS custom properties for semantic colors
- [ ] Apply colors to status indicators
- [ ] Apply colors to buttons and actions
- [ ] Ensure accessibility (WCAG AA contrast)

Colors: Success (green), Warning (yellow), Danger (red), Info (blue), Primary (purple)

### 2. Icon System
- [ ] Choose icon library (Font Awesome or emoji)
- [ ] Create icon component/helper
- [ ] Apply icons to categories
- [ ] Apply icons to actions
- [ ] Apply icons to status messages

### 3. Typography Scale
- [ ] Define typography hierarchy
- [ ] Apply to headings consistently
- [ ] Set appropriate line heights
- [ ] Ensure readability

### 4. Progress Bars
- [ ] Design progress bar component
- [ ] Color-code by status
- [ ] Integrate into dashboard
- [ ] Integrate into category list

### 5. Cards & Containers
- [ ] Define card component styles
- [ ] Add subtle shadows/borders
- [ ] Apply to summary sections

## Acceptance Criteria
- [ ] Consistent color usage throughout
- [ ] Status colors clearly communicate meaning
- [ ] Icons used consistently
- [ ] Typography hierarchy is clear
- [ ] Adequate whitespace
- [ ] Progress bars show spending visually
- [ ] Passes accessibility checks

## Files to Create/Modify
- \`public/css/design-system.css\`
- \`public/css/colors.css\`
- \`public/css/typography.css\`
- \`public/css/components.css\`
EOF
)"

# =============================================================================
# MEDIUM PRIORITY ISSUES
# =============================================================================

echo "📌 Creating Medium Priority Issues..."
echo ""

# Issue #9: Budget Templates
create_issue \
"🟢 [Medium Priority] Create Budget Templates System" \
"priority:medium,enhancement,feature,phase-8" \
"$(cat <<'EOF'
## Overview
Create pre-configured budget templates for common scenarios to help new users get started quickly.

## Context
- Part of Phase 8 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 8.1
- Impact: **Medium** - Helps new users
- Estimated Effort: 3-4 days

## Templates to Create
1. Single Person Starter
2. Family Budget
3. Student Budget
4. Freelancer / Variable Income
5. Debt Payoff Focus
6. Custom (Start from scratch)

## Implementation Tasks

### Database Schema
- [ ] Create migration file
- [ ] Create \`data.budget_templates\` table
- [ ] Define template structure (JSON)
- [ ] Insert default templates

### Template Definitions
- [ ] Single Person Starter template
- [ ] Family Budget template
- [ ] Student Budget template
- [ ] Freelancer template
- [ ] Debt Payoff template

### Template Selection UI
- [ ] Create template selection page
- [ ] Show during onboarding
- [ ] Preview template details
- [ ] Allow customization before applying

### API Endpoints
- [ ] GET \`/api/templates\`
- [ ] GET \`/api/templates/{id}\`
- [ ] POST \`/api/templates/apply\`

## Acceptance Criteria
- [ ] At least 5 templates available
- [ ] Templates can be previewed
- [ ] Applying template creates categories
- [ ] Integrates with onboarding
- [ ] Users can customize after applying

## Files to Create
- \`migrations/085_budget_templates.sql\`
- \`public/api/templates.php\`
- \`public/onboarding/templates.php\`
EOF
)"

# Issue #10: Help Center
create_issue \
"🟢 [Medium Priority] Create Comprehensive Help Center" \
"priority:medium,enhancement,documentation,phase-5" \
"$(cat <<'EOF'
## Overview
Create a comprehensive help center with guides, tutorials, FAQ, and troubleshooting documentation.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 5.2
- Impact: **Medium** - Reduces support burden
- Estimated Effort: 7-10 days (can be built incrementally)

## Help Center Structure
- Getting Started
- Core Concepts
- How-To Guides
- Advanced Features
- FAQ
- Troubleshooting

## Implementation Tasks

### Infrastructure
- [ ] Create \`public/help/\` directory
- [ ] Create main help index page
- [ ] Create navigation system
- [ ] Design help article template

### Content Sections
- [ ] Getting Started guides
- [ ] Core Concepts explanations
- [ ] How-To step-by-step guides
- [ ] Advanced Features documentation
- [ ] FAQ compilation
- [ ] Troubleshooting guides

### Visual Elements
- [ ] Take screenshots for tutorials
- [ ] Create diagrams for concepts
- [ ] Use consistent formatting

### Integration
- [ ] Add "Help" link to navigation
- [ ] Add contextual help links
- [ ] Link from tooltips

## Acceptance Criteria
- [ ] All major features documented
- [ ] Step-by-step guides with visuals
- [ ] FAQ covers common questions
- [ ] Easy navigation
- [ ] Mobile-friendly design
- [ ] Clear, beginner-friendly language

## Files to Create
- \`public/help/index.php\`
- \`public/help/getting-started/*.php\`
- \`public/help/how-to/*.php\`
- \`public/help/faq.php\`
- \`public/css/help.css\`
EOF
)"

# Issue #11: First-Time Tips
create_issue \
"🟢 [Medium Priority] Implement First-Time Tips System" \
"priority:medium,enhancement,ux,phase-5" \
"$(cat <<'EOF'
## Overview
Create a contextual tips system that shows helpful hints to users as they use the application.

## Context
- Part of Phase 5 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 5.1
- Impact: **Medium** - Educates users
- Estimated Effort: 3-4 days

## Implementation Tasks

### Database Schema
- [ ] Create \`data.tips\` table (tip library)
- [ ] Create \`data.user_tips\` table (user progress)
- [ ] Define tip structure
- [ ] Insert default tips

### Tip Library
Define tips for:
- [ ] Available to Budget
- [ ] Category Spending
- [ ] Overspending
- [ ] Goals
- [ ] Recurring Transactions
- [ ] Monthly Rollover
- [ ] Account Reconciliation

### Tip Component
- [ ] Create \`public/components/tip-card.php\`
- [ ] Design tip display component
- [ ] Add dismiss functionality
- [ ] Add "Show me how" button

### Tip Display Logic
- [ ] Create \`public/js/tips-system.js\`
- [ ] Check which tips user has seen
- [ ] Show one tip per session
- [ ] Track dismissals

## Acceptance Criteria
- [ ] Tips appear contextually
- [ ] Only one tip shown per session
- [ ] Tips can be dismissed permanently
- [ ] "Show me how" links work
- [ ] User can disable all tips

## Files to Create
- \`migrations/086_tips_system.sql\`
- \`public/components/tip-card.php\`
- \`public/js/tips-system.js\`
- \`public/api/tips.php\`
- \`public/css/tips.css\`
EOF
)"

# Issue #12: Simplified Budget Assignment
create_issue \
"🟢 [Medium Priority] Implement Simplified Budget Assignment Modal" \
"priority:medium,enhancement,ux,phase-4" \
"$(cat <<'EOF'
## Overview
Create a simple modal dialog for budgeting money to categories.

## Context
- Part of Phase 4 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 4.2
- Impact: **Medium** - Second most common action
- Estimated Effort: 2-3 days

## Implementation Tasks

### Modal Component
- [ ] Create \`public/components/modal-budget.php\`
- [ ] Design clean modal layout
- [ ] Show available to budget
- [ ] Amount input field
- [ ] Category dropdown
- [ ] Show current category balance

### Quick Templates
- [ ] "Budget All" button
- [ ] "Distribute Evenly" button
- [ ] "Follow Last Month" button

### JavaScript
- [ ] Create \`public/js/quick-budget.js\`
- [ ] Modal open/close functionality
- [ ] Real-time balance updates
- [ ] Form validation
- [ ] AJAX submission

### Integration
- [ ] Trigger from dashboard
- [ ] Trigger from quick actions
- [ ] Trigger from category list
- [ ] Keyboard shortcut (Alt+B)

## Acceptance Criteria
- [ ] Modal opens from multiple locations
- [ ] Shows available to budget
- [ ] Easy category selection
- [ ] Real-time validation
- [ ] AJAX submission
- [ ] Mobile-friendly

## Files to Create
- \`public/components/modal-budget.php\`
- \`public/js/quick-budget.js\`
EOF
)"

# =============================================================================
# NICE-TO-HAVE ISSUES
# =============================================================================

echo "📌 Creating Nice-to-Have Issues..."
echo ""

# Issue #13: Simple/Advanced Mode
create_issue \
"⚪ [Nice-to-Have] Implement Simple/Advanced Mode Toggle" \
"priority:low,enhancement,ux,phase-6" \
"$(cat <<'EOF'
## Overview
Add UI complexity toggle allowing users to choose between simplified interface and full-featured advanced mode.

## Context
- Part of Phase 6 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 6.1
- Impact: **Low-Medium**
- Estimated Effort: 5-7 days

## Implementation Tasks

### User Preference
- [ ] Add \`ui_mode\` column to users table
- [ ] Create settings page toggle
- [ ] Default new users to "simple"
- [ ] Store preference in database

### Feature Flags
- [ ] Define which features are "advanced"
- [ ] Create feature flag system
- [ ] Conditionally render based on mode

### Simple Mode Hides
- Loan management
- Installment plans
- Split transactions
- Advanced reports

### Simple Mode Shows
- Basic budgeting
- Simple transactions
- Account balances
- Basic goals

## Acceptance Criteria
- [ ] Users can toggle in settings
- [ ] Simple mode hides advanced features
- [ ] Advanced mode shows everything
- [ ] Preference persists
- [ ] Easy promotion to advanced mode
EOF
)"

# Issue #14: Celebrations
create_issue \
"⚪ [Nice-to-Have] Implement Celebration Moments & Achievements" \
"priority:low,enhancement,ux,gamification,phase-7" \
"$(cat <<'EOF'
## Overview
Add positive reinforcement through celebrations and achievements when users reach milestones.

## Context
- Part of Phase 7 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 7.2
- Impact: **Low-Medium** - Delight factor
- Estimated Effort: 3-4 days

## Celebration Types

### Budget Milestones
- [ ] All money budgeted
- [ ] First transaction
- [ ] First goal created
- [ ] Goal completed

### Consistency Streaks
- [ ] 3 day streak
- [ ] 7 day streak
- [ ] 30 day streak

### Monthly Success
- [ ] No overspending
- [ ] All categories on track

## Implementation Tasks

### Database
- [ ] Create \`data.user_achievements\` table
- [ ] Track achievement timestamps

### Celebration Display
- [ ] Create celebration modal
- [ ] Add confetti animation (canvas-confetti.js)
- [ ] Sound effects (optional)

### Achievement Tracking
- [ ] Function to check achievements
- [ ] Trigger on relevant actions
- [ ] Prevent duplicates

## Acceptance Criteria
- [ ] Celebrations trigger appropriately
- [ ] Confetti animation works
- [ ] Achievements tracked
- [ ] Settings allow disabling
- [ ] Not annoying or excessive
EOF
)"

# Issue #15: Feature Introduction
create_issue \
"⚪ [Nice-to-Have] Implement Gradual Feature Introduction Timeline" \
"priority:low,enhancement,ux,phase-6" \
"$(cat <<'EOF'
## Overview
Gradually introduce advanced features over time as users become comfortable with basics.

## Context
- Part of Phase 6 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 6.2
- Impact: **Low**
- Estimated Effort: 5-6 days

## Feature Timeline
- Day 1: Basic budgeting
- Week 1: Recurring transactions, goals
- Week 2: Credit cards, reports
- Week 3: Loans, installments
- Month 2+: All features

## Implementation Tasks

### Feature Flags
- [ ] Create \`data.feature_flags\` table
- [ ] Define unlock requirements
- [ ] Check availability on page load

### Unlock Banner
- [ ] Design banner component
- [ ] Show when feature unlocks
- [ ] Link to tutorial
- [ ] Allow dismissal

### Database
- [ ] Track user registration date
- [ ] Feature unlock logic

## Acceptance Criteria
- [ ] Features unlock based on timeline
- [ ] Users notified when unlocked
- [ ] Clear locked feature indication
- [ ] Can unlock early (optional)
EOF
)"

# Issue #16: Smart Suggestions
create_issue \
"⚪ [Nice-to-Have] Implement Smart Category Suggestions" \
"priority:low,enhancement,ai,phase-8" \
"$(cat <<'EOF'
## Overview
Auto-suggest categories based on payee/merchant names using machine learning from transaction history.

## Context
- Part of Phase 8 of Usability Improvement Plan
- Reference: \`docs/USABILITY_IMPROVEMENT_PLAN.md\` - Phase 8.2
- Impact: **Low** - Convenience
- Estimated Effort: 4-5 days

## How It Works
1. User enters payee: "Safeway"
2. System checks previous transactions
3. Found: "Safeway" → Groceries (used 15 times)
4. Auto-select "Groceries" category
5. User can override

## Implementation Tasks

### Database
- [ ] Create \`data.payee_category_mapping\` table
- [ ] Track payee → category associations
- [ ] Store use count and last used date

### Learning System
- [ ] On transaction save, record mapping
- [ ] Increment use_count if exists
- [ ] Handle name variations (fuzzy matching)

### Suggestion API
- [ ] GET \`/api/suggestions/category?payee=X\`
- [ ] Return most likely category
- [ ] Return confidence score

### Common Merchant Database
- [ ] Pre-populate common merchants
- [ ] Use for new users

## Acceptance Criteria
- [ ] Suggestions appear when typing payee
- [ ] Suggestions improve with usage
- [ ] Users can override
- [ ] Works with common merchants
- [ ] No performance impact

## Files to Create
- \`migrations/089_smart_suggestions.sql\`
- \`public/api/suggestions.php\`
- \`public/js/suggestion-engine.js\`
EOF
)"

echo ""
echo "=========================================================="
echo "✅ All 16 GitHub issues created successfully!"
echo ""
echo "Next steps:"
echo "1. Review issues in GitHub"
echo "2. Set up project board for tracking"
echo "3. Assign issues to team members"
echo "4. Begin with Critical Priority issues"
echo ""
echo "View issues: gh issue list"
echo "=========================================================="
