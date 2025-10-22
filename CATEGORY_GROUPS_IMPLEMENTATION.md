# Category Groups Implementation Plan

## Overview
Implement hierarchical category organization with category groups (parent categories) to help users better organize their budget categories.

## Current Database Schema Support
The `data.accounts` table already includes:
- `parent_category_id` (bigint) - Foreign key to parent category
- `is_group` (boolean) - Whether this is a group/parent category
- `sort_order` (integer) - For ordering within groups
- Constraint: `category_groups_equity_only` ensures only equity accounts can have parents

## Goals
1. Allow users to create category groups
2. Organize categories under groups
3. Display grouped categories hierarchically in the UI
4. Support drag-and-drop reordering within groups
5. Show group totals (sum of child categories)
6. Maintain backward compatibility (ungrouped categories still work)

---

## Phase 1: Backend API Layer

### Step 1.1: Create Category Group API Function
**File**: `database/functions/api/add_category_group.sql`

**Function**: `api.add_category_group(p_ledger_uuid text, p_group_name text)`

**Purpose**: Create a new category group
- Validates group name
- Creates equity account with `is_group = true`
- Returns the group UUID

**Implementation**:
```sql
CREATE OR REPLACE FUNCTION api.add_category_group(
    p_ledger_uuid text,
    p_group_name text
)
RETURNS TABLE(uuid text) AS $$
DECLARE
    v_ledger_id bigint;
    v_new_uuid text;
BEGIN
    -- Get ledger ID
    SELECT id INTO v_ledger_id
    FROM data.ledgers
    WHERE uuid = p_ledger_uuid
    AND user_data = utils.get_user();

    IF v_ledger_id IS NULL THEN
        RAISE EXCEPTION 'Ledger not found';
    END IF;

    -- Create the group account
    INSERT INTO data.accounts (
        name,
        type,
        internal_type,
        ledger_id,
        is_group,
        user_data
    ) VALUES (
        p_group_name,
        'equity',
        'liability_like',
        v_ledger_id,
        true,
        utils.get_user()
    )
    RETURNING accounts.uuid INTO v_new_uuid;

    RETURN QUERY SELECT v_new_uuid;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
```

### Step 1.2: Update Category to Support Groups
**File**: `database/functions/api/add_category.sql`

**Modification**: Add optional `p_parent_group_uuid` parameter

**Updated signature**:
```sql
api.add_category(
    p_ledger_uuid text,
    p_category_name text,
    p_parent_group_uuid text DEFAULT NULL
)
```

### Step 1.3: Move Category to Group API
**File**: `database/functions/api/move_category_to_group.sql`

**Function**: `api.move_category_to_group(p_category_uuid text, p_group_uuid text)`

**Purpose**: Move an existing category into a group (or remove from group if NULL)

### Step 1.4: Get Grouped Categories API
**File**: `database/functions/api/get_categories_with_groups.sql`

**Function**: `api.get_categories_with_groups(p_ledger_uuid text)`

**Returns**: Hierarchical structure of groups and categories with budget status
```sql
TABLE(
    uuid text,
    name text,
    is_group boolean,
    parent_group_uuid text,
    sort_order integer,
    budgeted bigint,
    activity bigint,
    balance bigint,
    child_count integer  -- For groups: how many categories inside
)
```

### Step 1.5: Reorder Categories API
**File**: `database/functions/api/reorder_categories.sql`

**Function**: `api.reorder_categories(p_category_uuids text[])`

**Purpose**: Update sort_order for multiple categories at once

---

## Phase 2: UI Components

### Step 2.1: Create Category Group Page
**File**: `public/categories/create-group.php`

**Features**:
- Form to create new category group
- Group name input
- Optional description
- Color/icon selector (optional enhancement)

**UI Elements**:
```
┌─────────────────────────────────────┐
│ Create Category Group               │
├─────────────────────────────────────┤
│ Group Name: [___________________]   │
│                                     │
│ Examples:                           │
│  • Monthly Bills                    │
│  • Lifestyle & Entertainment        │
│  • Savings Goals                    │
│  • Debt & Obligations              │
│                                     │
│ [Create Group] [Cancel]            │
└─────────────────────────────────────┘
```

### Step 2.2: Enhanced Category Creation
**File**: `public/categories/create.php` (modify existing)

**Additions**:
- Dropdown to select parent group (optional)
- "Create in group:" selector
- Shows existing groups

**Updated Form**:
```
┌─────────────────────────────────────┐
│ Create New Category                 │
├─────────────────────────────────────┤
│ Category Name: [___________________]│
│                                     │
│ Parent Group (optional):            │
│ ┌─────────────────────────────────┐ │
│ │ [Select a group...]         ▼  │ │
│ ├─────────────────────────────────┤ │
│ │ (None - Ungrouped)              │ │
│ │ Monthly Bills                   │ │
│ │ Lifestyle & Entertainment       │ │
│ │ Savings Goals                   │ │
│ └─────────────────────────────────┘ │
│                                     │
│ [Create Category] [Cancel]         │
└─────────────────────────────────────┘
```

### Step 2.3: Enhanced Category Management Page
**File**: `public/categories/manage.php` (major update)

**Features**:
1. **Grouped Display**
   - Show categories organized by groups
   - Collapsible/expandable groups
   - Visual hierarchy (indentation, lines)

2. **Group Actions**
   - Rename group
   - Delete group (moves children to ungrouped)
   - Collapse/expand all groups

3. **Category Actions**
   - Move to different group (drag-drop or dropdown)
   - Reorder within group
   - Remove from group

4. **Group Totals**
   - Show sum of budgeted amounts in group
   - Show sum of activity in group
   - Show sum of available in group

**UI Layout**:
```
┌──────────────────────────────────────────────────────────────┐
│ Manage Categories                                            │
│ [+ New Category] [+ New Group] [Collapse All] [Expand All]  │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ ▼ Monthly Bills (4 categories)              Budgeted: $2,500│
│   ├─ Rent                    $1,200  -$1,200  $0           │
│   ├─ Utilities               $300    -$280    $20          │
│   ├─ Internet                $80     -$80     $0           │
│   └─ Phone                   $100    -$95     $5           │
│                                                              │
│ ▼ Lifestyle & Entertainment (3 categories)   Budgeted: $600 │
│   ├─ Dining Out              $300    -$245    $55          │
│   ├─ Entertainment           $200    -$180    $20          │
│   └─ Hobbies                 $100    -$65     $35          │
│                                                              │
│ ▼ Ungrouped Categories (5 categories)      Budgeted: $1,200 │
│   ├─ Groceries               $500    -$430    $70          │
│   ├─ Transportation          $200    -$175    $25          │
│   ├─ Medical                 $150    -$0      $150         │
│   ├─ Clothing                $200    -$120    $80          │
│   └─ Miscellaneous           $150    -$95     $55          │
└──────────────────────────────────────────────────────────────┘
```

### Step 2.4: Budget Dashboard Integration
**File**: `public/budget/dashboard.php` (modify)

**Changes**:
- Display categories grouped on main dashboard
- Show group headers with collapse/expand
- Group totals in headers
- Maintain quick-assign functionality within groups

---

## Phase 3: Enhanced Features

### Step 3.1: Drag and Drop Reordering
**Technology**: JavaScript with HTML5 Drag and Drop API or SortableJS

**Features**:
- Drag categories to reorder within same group
- Drag categories between groups
- Drag categories to "Ungrouped" area
- Visual feedback during drag

**Files**:
- `public/js/category-groups.js`
- `public/css/category-groups.css`

### Step 3.2: Bulk Operations
**Features**:
- Select multiple categories
- Move selected to group
- Delete selected
- Set budget for all in group

### Step 3.3: Group Settings
**Features**:
- Group color/theme
- Group icon/emoji
- Group description
- Hide/show group on dashboard

### Step 3.4: Budget Templates by Group
**Features**:
- Save group budgets as template
- Quick-apply template to new month
- "Copy from last month" per group

---

## Phase 4: Reporting & Analytics

### Step 4.1: Group-Level Reports
**New Report**: `public/reports/spending-by-group.php`

**Features**:
- Spending breakdown by category group
- Trend analysis by group
- Budget vs actual by group
- Pie chart of group allocation

### Step 4.2: Enhanced Category Trends
**Modify**: `public/reports/category-trends.php`

**Add**:
- Group filter
- Compare groups
- Group trend lines

---

## Technical Implementation Details

### Database Queries

**Get Categories with Groups**:
```sql
WITH RECURSIVE category_tree AS (
    -- Get all groups (parent categories)
    SELECT
        a.id,
        a.uuid,
        a.name,
        a.parent_category_id,
        a.is_group,
        a.sort_order,
        0 as level
    FROM data.accounts a
    WHERE a.ledger_id = (SELECT id FROM data.ledgers WHERE uuid = $1)
    AND a.type = 'equity'
    AND a.parent_category_id IS NULL

    UNION ALL

    -- Get all child categories
    SELECT
        a.id,
        a.uuid,
        a.name,
        a.parent_category_id,
        a.is_group,
        a.sort_order,
        ct.level + 1
    FROM data.accounts a
    JOIN category_tree ct ON a.parent_category_id = ct.id
    WHERE a.type = 'equity'
)
SELECT
    ct.*,
    COALESCE(bs.budgeted, 0) as budgeted,
    COALESCE(bs.activity, 0) as activity,
    COALESCE(bs.balance, 0) as balance
FROM category_tree ct
LEFT JOIN budget_status bs ON ct.uuid = bs.category_uuid
ORDER BY ct.level, ct.sort_order, ct.name;
```

### JavaScript Structure

**category-groups.js**:
```javascript
class CategoryGroupManager {
    constructor(ledgerUuid) {
        this.ledgerUuid = ledgerUuid;
        this.initDragDrop();
        this.initCollapseExpand();
    }

    initDragDrop() {
        // Setup drag and drop listeners
    }

    moveCategory(categoryUuid, newGroupUuid, newPosition) {
        // API call to move category
    }

    toggleGroup(groupUuid) {
        // Collapse/expand group
    }

    reorderCategories(categoryOrder) {
        // Update sort order
    }
}
```

---

## User Experience Flow

### Creating First Group
1. User goes to "Manage Categories"
2. Clicks "+ New Group"
3. Enters "Monthly Bills"
4. Group created, shown at top
5. User drags existing categories into group

### Moving Category to Group
**Option 1: Drag and Drop**
1. User drags "Rent" category
2. Hovers over "Monthly Bills" group (highlights)
3. Drops category
4. Category moves into group, updates display

**Option 2: Dropdown**
1. User clicks "⋮" menu on category
2. Selects "Move to group"
3. Chooses "Monthly Bills" from dropdown
4. Category moves

### Budget Dashboard Experience
1. Dashboard shows grouped categories
2. Groups are collapsible
3. Clicking group name shows/hides children
4. Group header shows totals
5. Can quick-assign to any category in expanded group

---

## Migration & Compatibility

### Backward Compatibility
- All existing categories remain ungrouped (parent_category_id = NULL)
- Dashboard works without groups
- Users can opt-in to groups gradually

### Data Migration
No migration needed - schema already supports groups!

---

## Testing Checklist

### Unit Tests
- [ ] Create category group
- [ ] Create category in group
- [ ] Move category to group
- [ ] Move category out of group
- [ ] Delete group (children become ungrouped)
- [ ] Delete category in group
- [ ] Reorder categories in group
- [ ] Get grouped categories with budget data

### Integration Tests
- [ ] Create group from UI
- [ ] Move category via drag-drop
- [ ] Move category via dropdown
- [ ] Collapse/expand groups
- [ ] Group totals calculate correctly
- [ ] Budget assignment works in grouped view
- [ ] Reports show group data correctly

### Edge Cases
- [ ] Category with no group (ungrouped)
- [ ] Empty group (no categories)
- [ ] Delete group with categories
- [ ] Move category between groups
- [ ] Special categories (Income, Unassigned) cannot be grouped
- [ ] Group name conflicts
- [ ] Very long group names
- [ ] Many categories in one group (performance)

---

## Implementation Priority

### Must Have (MVP)
1. ✅ Database already supports groups
2. Create group API function
3. Update add_category to support parent
4. Get grouped categories API
5. Create group UI page
6. Display grouped categories on manage page
7. Basic move category to group (dropdown)

### Should Have (V1)
8. Drag and drop reordering
9. Group totals display
10. Collapse/expand groups
11. Dashboard grouped display
12. Rename/delete group

### Nice to Have (V2)
13. Group colors/icons
14. Bulk operations
15. Budget templates by group
16. Group-level reports

---

## File Structure

```
public/
├── categories/
│   ├── create.php (modify - add group selector)
│   ├── create-group.php (new)
│   ├── manage.php (modify - grouped display)
│   ├── edit-group.php (new)
│   └── delete-group.php (new)
├── api/
│   ├── add-category-group.php (new)
│   ├── move-category.php (new)
│   ├── reorder-categories.php (new)
│   └── get-grouped-categories.php (new)
├── js/
│   └── category-groups.js (new)
└── css/
    └── category-groups.css (new)

database/
└── functions/
    └── api/
        ├── add_category_group.sql (new)
        ├── move_category_to_group.sql (new)
        ├── get_categories_with_groups.sql (new)
        └── reorder_categories.sql (new)
```

---

## UI/UX Design Principles

1. **Progressive Disclosure**
   - Groups are optional, not required
   - Users can start simple, add groups later
   - Advanced features hidden until needed

2. **Clear Visual Hierarchy**
   - Indentation shows parent-child relationship
   - Group headers visually distinct
   - Clear borders and spacing

3. **Familiar Patterns**
   - Tree view like file explorers
   - Drag-drop like modern apps
   - Collapse/expand like accordions

4. **Performance**
   - Lazy load large groups
   - Collapse by default if many categories
   - Efficient queries with indexes

---

## Next Steps

1. **Review & Approve Plan**
   - Get feedback on approach
   - Prioritize features
   - Confirm UI mockups

2. **Start with Backend**
   - Create API functions
   - Test with SQL directly
   - Ensure data integrity

3. **Build Basic UI**
   - Create group page
   - Update category creation
   - Basic grouped display

4. **Iterate & Enhance**
   - Add drag-drop
   - Polish UX
   - Add reports

Would you like me to start implementing any specific phase of this plan?
