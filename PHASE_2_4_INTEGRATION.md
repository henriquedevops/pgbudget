# Phase 2.4: Goal UI Components - Integration Guide

## Files Created

### 1. API Endpoint
**File:** `public/api/goals.php`
- Handles GET, POST, PUT, DELETE for goal CRUD operations
- Endpoints:
  - `POST /api/goals.php` - Create new goal
  - `PUT /api/goals.php` - Update existing goal
  - `DELETE /api/goals.php?goal_uuid=X` - Delete goal
  - `GET /api/goals.php?ledger_uuid=X` - Get all goals for ledger
  - `GET /api/goals.php?goal_uuid=X` - Get single goal status
  - `GET /api/goals.php?ledger_uuid=X&underfunded=1` - Get underfunded goals

### 2. JavaScript Manager
**File:** `public/js/goals-manager.js`
- `GoalsManager` class handles all goal UI interactions
- Features:
  - Goal creation modal with type selector (monthly_funding, target_balance, target_by_date)
  - Goal editing modal (reuses create modal)
  - Goal deletion confirmation
  - Live preview of goal as user types
  - Conditional form fields based on goal type
  - Form validation and error handling
  - Notification system

### 3. Dashboard Integration Helper
**File:** `public/goals/dashboard-integration.php`
- PHP helper functions for displaying goals on dashboard
- Functions:
  - `getCategoryGoal($category_uuid)` - Get goal for category
  - `hasGoal($category_uuid)` - Check if category has goal
  - `renderGoalIndicator($category_uuid)` - HTML for goal progress bar
  - `renderGoalButton($category_uuid, $category_name)` - Set/Edit/Delete buttons
- Fetches all goals for ledger with current status
- Creates lookup array for fast access

### 4. Goal Styles
**File:** `public/css/goals.css`
- Complete styling for goals feature:
  - Goal indicators (progress bars, status icons)
  - Goal modal (type selector, form fields, preview)
  - Goal buttons (Set, Edit, Delete)
  - Underfunded goals sidebar section
  - Responsive mobile styles
  - Notifications

## Integration Steps for dashboard.php

### Step 1: Include Goal Integration Helper

Add after line 83 (after `$ledger_accounts` query):

```php
// Load goals for this ledger
require_once __DIR__ . '/../goals/dashboard-integration.php';
```

### Step 2: Add Goal CSS

Add after the existing styles section (after line 1313):

```php
<!-- Goal Styles -->
<link rel="stylesheet" href="../css/goals.css">
```

### Step 3: Add Data Attribute for JavaScript

Modify line 96 to include ledger UUID for JavaScript:

```php
<div id="ledger-accounts-data"
     data-accounts='<?= json_encode(array_map(function($acc) {
         return ['uuid' => $acc['uuid'], 'name' => $acc['name'], 'type' => $acc['type']];
     }, $ledger_accounts)) ?>'
     data-ledger-uuid="<?= htmlspecialchars($ledger_uuid) ?>"
     style="display: none;"></div>
```

### Step 4: Add Goal Display in Category Table

Modify the category row section (around line 156-197) to include goal indicators.

**Replace the category name cell (line 156-158):**

```php
<td class="category-name-cell">
    <div class="category-name-with-goal">
        <span class="category-name"><?= htmlspecialchars($category['category_name']) ?></span>
        <?php if (hasGoal($category['category_uuid'])): ?>
            <?= renderGoalIndicator($category['category_uuid']) ?>
        <?php endif; ?>
    </div>
</td>
```

**Add goal button to actions cell (line 172-182):**

Add after the "Assign" button:
```php
<?= renderGoalButton($category['category_uuid'], $category['category_name']) ?>
```

### Step 5: Add Underfunded Goals to Sidebar

Add this section in the sidebar (after "Recent Transactions", around line 259):

```php
<!-- Underfunded Goals -->
<?php if (!empty($underfunded_goals)): ?>
    <div class="underfunded-goals-section">
        <h3>Goals Needing Attention</h3>
        <div class="goal-summary-stats">
            <div class="goal-stat">
                <span class="goal-stat-value"><?= count($underfunded_goals) ?></span>
                <span class="goal-stat-label">Underfunded</span>
            </div>
            <div class="goal-stat">
                <span class="goal-stat-value"><?= count($ledger_goals) ?></span>
                <span class="goal-stat-label">Total Goals</span>
            </div>
        </div>
        <?php foreach (array_slice($underfunded_goals, 0, 5) as $ug): ?>
            <div class="underfunded-goal-item">
                <div class="underfunded-goal-category"><?= htmlspecialchars($ug['category_name']) ?></div>
                <div class="underfunded-goal-details">
                    <?php if ($ug['goal_type'] === 'monthly_funding' && $ug['needed_this_month'] > 0): ?>
                        Need <?= formatCurrency($ug['needed_this_month']) ?> this month
                    <?php elseif ($ug['goal_type'] === 'target_by_date'): ?>
                        <?= formatCurrency($ug['needed_per_month']) ?>/month needed
                        <?php if (!$ug['is_on_track']): ?>
                            <span class="underfunded-goal-amount">⚠️ Behind schedule</span>
                        <?php endif; ?>
                    <?php elseif ($ug['goal_type'] === 'target_balance'): ?>
                        <?= formatCurrency($ug['remaining_amount']) ?> remaining
                    <?php endif; ?>
                </div>
                <div class="goal-progress-container" style="margin-top: 0.5rem;">
                    <div class="goal-progress-bar <?= $ug['percent_complete'] >= 75 ? 'good' : ($ug['percent_complete'] >= 50 ? 'fair' : 'low') ?>">
                        <div class="goal-progress-fill" style="width: <?= min(100, $ug['percent_complete']) ?>%"></div>
                    </div>
                    <div class="goal-progress-text"><?= number_format($ug['percent_complete'], 0) ?>%</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif (!empty($ledger_goals)): ?>
    <div class="underfunded-goals-section">
        <div class="empty-underfunded">
            <div class="success-icon">🎯</div>
            <p><strong>All goals on track!</strong></p>
            <p>Great job staying on top of your budget.</p>
        </div>
    </div>
<?php endif; ?>
```

### Step 6: Include Goals JavaScript

Add after the existing JavaScript includes (after line 1361):

```php
<!-- Include goals manager JavaScript -->
<script src="../js/goals-manager.js"></script>
```

## Testing the Integration

### Test Goal Creation

1. Navigate to budget dashboard
2. Click "Set Goal" button on any category
3. Select goal type (monthly_funding, target_balance, or target_by_date)
4. Enter target amount
5. For target_by_date: enter target date
6. Submit and verify goal appears below category name

### Test Goal Editing

1. Click "Edit" button on category with existing goal
2. Modify target amount or date
3. Submit and verify changes appear

### Test Goal Deletion

1. Click "X" button next to Edit button
2. Confirm deletion
3. Verify goal removed and "Set Goal" button reappears

### Test Goal Progress Display

1. Create a monthly_funding goal (e.g., $500/month for Groceries)
2. Assign money to that category (e.g., $250)
3. Verify progress bar shows 50% progress
4. Assign remaining $250
5. Verify progress bar shows 100% with checkmark

### Test Underfunded Goals Sidebar

1. Create multiple goals on different categories
2. Partially fund some goals
3. Verify underfunded goals appear in sidebar
4. Verify "Goals Needing Attention" section shows correct count
5. Verify priority ordering (most urgent first)

## API Response Examples

### GET /api/goals.php?ledger_uuid=XYZ
```json
{
    "success": true,
    "goals": [
        {
            "goal_uuid": "abc123",
            "goal_type": "monthly_funding",
            "category_uuid": "cat1",
            "category_name": "Groceries",
            "target_amount": 50000,
            "current_amount": 25000,
            "remaining_amount": 25000,
            "percent_complete": 50.00,
            "is_complete": false,
            "funded_this_month": 25000,
            "needed_this_month": 25000,
            "target_date": null,
            "months_remaining": null,
            "needed_per_month": null,
            "is_on_track": null
        }
    ]
}
```

### POST /api/goals.php (Create Goal)
```json
{
    "category_uuid": "cat1",
    "goal_type": "monthly_funding",
    "target_amount": 50000,
    "target_date": null,
    "repeat_frequency": "monthly"
}
```

Response:
```json
{
    "success": true,
    "message": "Goal created successfully",
    "goal": {
        "uuid": "newgoal1",
        "goal_type": "monthly_funding",
        "target_amount": 50000,
        ...
    }
}
```

## UI/UX Features

### Goal Type Descriptions

1. **Monthly Funding** (💰)
   - Budget a fixed amount every month
   - Example: $500/month for groceries
   - Progress resets each month
   - Shows "Need $X more this month"

2. **Target Balance** (🎯)
   - Save up to a specific total amount
   - Example: Build $5,000 emergency fund
   - Cumulative progress (doesn't reset)
   - Shows "Need $X more to reach goal"

3. **Target by Date** (📅)
   - Reach a target amount by a specific date
   - Example: Save $1,200 for vacation by June
   - Calculates monthly needed amount
   - Shows on-track/behind status
   - Can repeat (yearly, monthly, weekly)

### Progress Bar Colors

- **Green (complete)**: 100% funded
- **Light green (good)**: 75-99% funded
- **Orange (fair)**: 50-74% funded
- **Red (low)**: 0-49% funded

### Status Indicators

- **✓** - Goal met
- **⚠️** - Behind schedule (target_by_date only)
- **Progress percentage** - Always shown

## Database Schema Used

### Tables
- `data.category_goals` - Stores goal configuration
- Via `api.category_goals` view

### Functions Used
- `api.create_category_goal(p_category_uuid, p_goal_type, p_target_amount, p_target_date, p_repeat_frequency)`
- `api.update_category_goal(p_goal_uuid, p_target_amount, p_target_date, p_repeat_frequency)`
- `api.delete_category_goal(p_goal_uuid)`
- `api.get_category_goal_status(p_goal_uuid, p_month)`
- `api.get_ledger_goals(p_ledger_uuid, p_month)`
- `api.get_underfunded_goals(p_ledger_uuid, p_month)`

## Next Steps (Phase 2.5)

After integrating Phase 2.4, the following enhancements can be added:

1. **Quick Fund Goals** button - Auto-assign to underfunded goals
2. **Goal templates** - Pre-configured goals for common categories
3. **Goal history** - Track goal completion over time
4. **Goal suggestions** - AI-suggested goals based on spending patterns
5. **Goal insights** - "You're X months ahead of schedule!"

## Troubleshooting

### Goals Not Displaying
- Check that `goals/dashboard-integration.php` is included
- Verify database functions exist: `\df api.*goal*`
- Check browser console for JavaScript errors

### Modal Not Opening
- Verify `goals-manager.js` is loaded
- Check that `data-ledger-uuid` attribute exists on page
- Inspect browser console for errors

### API Errors
- Check that migrations 20251010000001, 20251010000002, 20251010000003 are applied
- Verify user context is set before API calls
- Check PostgreSQL logs for function errors

### Progress Bar Not Updating
- Ensure budget assignments are going to the correct category
- Verify period selector is set to current month (or "All Time")
- Check that goal calculation functions are working: `SELECT * FROM api.get_category_goal_status('goal_uuid', '202510');`

## File Structure Summary

```
pgbudget/
├── public/
│   ├── api/
│   │   └── goals.php              # NEW - Goal CRUD API
│   ├── budget/
│   │   └── dashboard.php          # MODIFIED - Add goals integration
│   ├── css/
│   │   └── goals.css              # NEW - Goal styles
│   ├── js/
│   │   └── goals-manager.js       # NEW - Goal UI manager
│   └── goals/
│       └── dashboard-integration.php  # NEW - Helper functions
├── migrations/
│   ├── 20251010000001_add_category_goals_table.sql      # Phase 2.1 ✅
│   ├── 20251010000002_add_goal_calculation_functions.sql # Phase 2.2 ✅
│   └── 20251010000003_add_goal_api_functions.sql        # Phase 2.3 ✅
└── PHASE_2_4_INTEGRATION.md       # NEW - This file
```

## Completion Checklist

- [x] Create `public/api/goals.php` endpoint
- [x] Create `public/js/goals-manager.js` UI manager
- [x] Create `public/goals/dashboard-integration.php` helper
- [x] Create `public/css/goals.css` styles
- [ ] Modify `public/budget/dashboard.php` to integrate goals
- [ ] Test goal creation workflow
- [ ] Test goal editing workflow
- [ ] Test goal deletion workflow
- [ ] Test progress indicators
- [ ] Test underfunded goals sidebar
- [ ] Update documentation
- [ ] Commit changes to git

---

**Phase 2.4 Implementation Status:** Ready for integration testing

Once dashboard.php is modified according to the steps above, Phase 2.4 will be complete!
