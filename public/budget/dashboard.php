<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = pgb_current_ledger();
$selected_period = $_GET['period'] ?? date('Ym'); // YYYYMM format; default to current month

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get budget status (with optional period)
    if ($selected_period) {
        $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?, ?)");
        $stmt->execute([$ledger_uuid, $selected_period]);
    } else {
        $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
        $stmt->execute([$ledger_uuid]);
    }
    $budget_status = $stmt->fetchAll();

    // Get budget totals — always for the current/selected month so "Income this month" is accurate
    $totals_period = $selected_period ?: date('Ym');
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?, ?)");
    $stmt->execute([$ledger_uuid, $totals_period]);
    $budget_totals = $stmt->fetch();

    // Get overspent categories
    $stmt = $db->prepare("SELECT * FROM api.get_overspent_categories(?)");
    $stmt->execute([$ledger_uuid]);
    $overspent_categories = $stmt->fetchAll();
    $total_overspending = array_sum(array_column($overspent_categories, 'overspent_amount'));

    // Calculate total activity (spending) from budget status
    $total_activity = array_sum(array_column($budget_status, 'activity'));

    // Get recent transactions
    $stmt = $db->prepare("
        SELECT t.uuid, t.date, t.description, t.amount,
               ca.name as credit_account, da.name as debit_account,
               CASE
                   WHEN t.transaction_type = 'transfer' THEN 'transfer'
                   WHEN da.name = 'Income' THEN 'inflow'
                   ELSE 'outflow'
               END as type
        FROM data.transactions t
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.accounts da ON t.debit_account_id = da.id
        LEFT JOIN data.transaction_log tl ON t.id = tl.original_transaction_id
        WHERE t.ledger_id = (SELECT id FROM data.ledgers WHERE uuid = ?)
        AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
        AND tl.id IS NULL
        ORDER BY t.date DESC, t.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$ledger_uuid]);
    $recent_transactions = $stmt->fetchAll();

    // Get accounts for quick-add transaction modal
    $stmt = $db->prepare("
        SELECT uuid, name, type
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type IN ('asset', 'liability')
        ORDER BY type, name
    ");
    $stmt->execute([$ledger_uuid]);
    $ledger_accounts = $stmt->fetchAll();

    // Get categories organized by groups for grouped view
    $stmt = $db->prepare("SELECT * FROM api.get_categories_by_group(?)");
    $stmt->execute([$ledger_uuid]);
    $categories_by_group = $stmt->fetchAll();

    // Get group subtotals if there are groups
    $group_subtotals = [];
    if ($selected_period) {
        $stmt = $db->prepare("SELECT * FROM api.get_group_subtotals(?, ?)");
        $stmt->execute([$ledger_uuid, $selected_period]);
    } else {
        $stmt = $db->prepare("SELECT * FROM api.get_group_subtotals(?)");
        $stmt->execute([$ledger_uuid]);
    }
    $group_subtotals = $stmt->fetchAll();

    // Get 6-month cash flow projection outlook (optional — ignore errors)
    $projection_outlook = [];
    try {
        $pstmt = $db->prepare("SELECT * FROM api.get_projection_summary(?, ?::date, ?)");
        $pstmt->execute([$ledger_uuid, date('Y-m-01'), 6]);
        $projection_outlook = $pstmt->fetchAll();
    } catch (Exception $e) {
        // Projection is optional
    }

    // Organize grouped categories into a hierarchical structure
    $grouped_categories = [];
    foreach ($categories_by_group as $cat) {
        // Skip auto-created CC Payment categories
        if (strpos($cat['category_name'], 'CC Payment: ') === 0) {
            continue;
        }

        if ($cat['is_group']) {
            // This is a group header
            if (!isset($grouped_categories[$cat['category_uuid']])) {
                $grouped_categories[$cat['category_uuid']] = [
                    'group' => $cat,
                    'categories' => []
                ];
            }
        } else {
            // This is a regular category
            if ($cat['parent_uuid']) {
                // Category belongs to a group
                if (!isset($grouped_categories[$cat['parent_uuid']])) {
                    $grouped_categories[$cat['parent_uuid']] = [
                        'group' => null,
                        'categories' => []
                    ];
                }
                $grouped_categories[$cat['parent_uuid']]['categories'][] = $cat;
            } else {
                // Category has no group - put in special "ungrouped" section
                if (!isset($grouped_categories['_ungrouped'])) {
                    $grouped_categories['_ungrouped'] = [
                        'group' => null,
                        'categories' => []
                    ];
                }
                $grouped_categories['_ungrouped']['categories'][] = $cat;
            }
        }
    }

    // Setup checklist state
    $has_accounts    = !empty($ledger_accounts);
    $has_categories  = !empty($grouped_categories);
    $has_transactions = !empty($recent_transactions);
    $setup_complete  = $has_accounts && $has_categories && $has_transactions;

} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage()); $_SESSION['error'] = 'An unexpected database error occurred. Please try again or contact support if the problem persists.';
    header('Location: ../index.php');
    exit;
}

function getCategoryIcon($name) {
    $map = [
        'food' => '🍔',
        'dining' => '🍔',
        'groceries' => '🛒',
        'rent' => '🏠',
        'mortgage' => '🏠',
        'housing' => '🏠',
        'transportation' => '🚗',
        'gas' => '⛽',
        'utilities' => '💡',
        'entertainment' => '🎬',
        'clothing' => '👕',
        'healthcare' => '🏥',
        'education' => '📚',
        'savings' => '💰',
        'goals' => '🎯',
    ];

    $name = strtolower($name);

    foreach ($map as $key => $icon) {
        if (strpos($name, $key) !== false) {
            return $icon;
        }
    }

    return '📁'; // Default icon
}

require_once '../../includes/header.php';
?>

<div id="ledger-accounts-data"
     data-accounts='<?= json_encode(array_map(function($acc) {
         return ['uuid' => $acc['uuid'], 'name' => $acc['name'], 'type' => $acc['type']];
     }, $ledger_accounts)) ?>'
     data-ledger-uuid="<?= htmlspecialchars($ledger_uuid) ?>"
     style="display:none;"></div>

<div class="container" style="display:flex;flex-direction:column;gap:var(--space-6);">

    <?php if (!empty($overspent_categories)): ?>
    <div class="banner warning">
        <i data-lucide="alert-triangle" style="width:18px;height:18px;flex-shrink:0;"></i>
        <div style="flex:1;">
            <strong><?= count($overspent_categories) ?> overspent categor<?= count($overspent_categories) === 1 ? 'y' : 'ies' ?></strong>
            &mdash; <?= formatCurrency($total_overspending) ?> total overspending
        </div>
        <button type="button" class="btn btn-sm" onclick="showCoverOverspendingModal()">Cover</button>
    </div>
    <?php endif; ?>

    <?php if (!$setup_complete): ?>
    <div class="card">
        <div class="card-head">
            <span class="card-title">Get started</span>
        </div>
        <div class="checklist">
            <div class="check <?= $has_accounts ? 'done' : '' ?>">
                <div class="dot"><?= $has_accounts ? '✓' : '' ?></div>
                <?php if ($has_accounts): ?>
                    <span class="label">Add your bank accounts</span>
                <?php else: ?>
                    <a href="../accounts/create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="label">Add your bank accounts</a>
                <?php endif; ?>
            </div>
            <div class="check <?= $has_categories ? 'done' : '' ?>">
                <div class="dot"><?= $has_categories ? '✓' : '' ?></div>
                <?php if ($has_categories): ?>
                    <span class="label">Create budget categories</span>
                <?php else: ?>
                    <a href="../categories/manage.php?ledger=<?= urlencode($ledger_uuid) ?>" class="label">Create budget categories</a>
                <?php endif; ?>
            </div>
            <div class="check <?= $has_transactions ? 'done' : '' ?>">
                <div class="dot"><?= $has_transactions ? '✓' : '' ?></div>
                <?php if ($has_transactions): ?>
                    <span class="label">Record your first transaction</span>
                <?php else: ?>
                    <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>" class="label">Record your first transaction</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($budget_totals): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);" class="dashboard-hero-grid">
        <!-- Hero: left to assign -->
        <div class="hero-card">
            <div class="eyebrow"><?= htmlspecialchars($ledger['name']) ?></div>
            <?php $ltb = (int)($budget_totals['left_to_budget'] ?? 0); ?>
            <div class="tnum" style="font-size:2.5rem;font-weight:700;line-height:1.1;margin:var(--space-2) 0 var(--space-1);">
                <?= formatCurrency($ltb) ?>
            </div>
            <div style="font-size:var(--text-sm);opacity:0.8;margin-bottom:var(--space-5);">Ready to assign</div>
            <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
                <button type="button" class="btn btn-sm"
                        style="background:rgba(255,255,255,0.2);color:inherit;border:1px solid rgba(255,255,255,0.35);"
                        onclick="QuickAddModal.open({ledger_uuid:'<?= htmlspecialchars($ledger_uuid) ?>'})">
                    Quick Add
                </button>
                <a href="../transactions/assign.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-sm"
                   style="background:rgba(255,255,255,0.2);color:inherit;border:1px solid rgba(255,255,255,0.35);">
                    Assign Money
                </a>
                <button type="button" class="btn btn-sm"
                        style="background:rgba(255,255,255,0.2);color:inherit;border:1px solid rgba(255,255,255,0.35);"
                        onclick="TransferModal.open({ledger_uuid:'<?= htmlspecialchars($ledger_uuid) ?>'})">
                    Transfer
                </button>
            </div>
        </div>

        <!-- Stats grid -->
        <div class="card" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-5);align-content:center;">
            <div>
                <div class="eyebrow">Income</div>
                <div class="money pos tnum" style="font-size:var(--text-xl);font-weight:700;"><?= formatCurrency($budget_totals['income']) ?></div>
            </div>
            <div>
                <div class="eyebrow">Budgeted</div>
                <div class="tnum" style="font-size:var(--text-xl);font-weight:700;"><?= formatCurrency($budget_totals['budgeted']) ?></div>
            </div>
            <div>
                <div class="eyebrow">Spent</div>
                <div class="money neg tnum" style="font-size:var(--text-xl);font-weight:700;"><?= formatCurrency($total_activity) ?></div>
            </div>
            <div>
                <div class="eyebrow">Left to Assign</div>
                <div class="money <?= $ltb > 0 ? 'pos' : ($ltb < 0 ? 'neg' : 'zero') ?> tnum" style="font-size:var(--text-xl);font-weight:700;"><?= formatCurrency($ltb) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Categories card -->
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-head" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--color-border);">
            <span class="card-title">Budget Categories</span>
            <div class="seg-toggle">
                <button type="button" id="view-toggle-flat" class="seg-btn active">List</button>
                <button type="button" id="view-toggle-grouped" class="seg-btn">Groups</button>
            </div>
        </div>

        <?php if (empty($budget_status)): ?>
            <div style="padding:var(--space-10);text-align:center;">
                <div style="font-size:2rem;margin-bottom:var(--space-3);">🎯</div>
                <div style="font-weight:600;margin-bottom:var(--space-2);">Ready to Budget!</div>
                <p style="color:var(--color-fg-muted);margin-bottom:var(--space-4);">
                    You have <?= formatCurrency($budget_totals['left_to_budget'] ?? 0) ?> waiting to be assigned.
                </p>
                <a href="../categories/manage.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Budget Money</a>
            </div>
        <?php else: ?>

            <!-- Flat view -->
            <div id="categories-flat-view" class="categories-view">
                <table class="tbl" style="border-radius:0;border:0;">
                    <thead>
                        <tr>
                            <th style="width:44px;"></th>
                            <th>Category</th>
                            <th class="num">Budgeted</th>
                            <th class="num">Activity</th>
                            <th style="min-width:110px;">Progress</th>
                            <th class="num">Balance</th>
                            <th style="width:76px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budget_status as $category): ?>
                            <?php
                            if (strpos($category['category_name'], 'CC Payment: ') === 0) continue;
                            $spent_pct = $category['budgeted'] > 0 ? (abs($category['activity']) / $category['budgeted']) * 100 : 0;
                            $bar_mod   = $category['balance'] < 0 ? ' over' : ($spent_pct >= 76 ? '' : ' under');
                            $bal_cls   = $category['balance'] > 0 ? 'pos' : ($category['balance'] < 0 ? 'neg' : 'zero');
                            $act_cls   = $category['activity'] < 0 ? 'neg' : ($category['activity'] > 0 ? 'pos' : 'zero');
                            ?>
                            <tr class="category-row">
                                <td>
                                    <div class="cat-icon" style="width:28px;height:28px;font-size:14px;">
                                        <?= getCategoryIcon($category['category_name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight:600;"><?= htmlspecialchars($category['category_name']) ?></span>
                                </td>
                                <td class="num budget-amount-editable"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Assign budget for <?= htmlspecialchars($category['category_name']) ?>"
                                    data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                    data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                    data-current-amount="<?= $category['budgeted'] ?>"
                                    title="Click or press Enter to assign budget">
                                    <span class="tnum"><?= formatCurrency($category['budgeted']) ?></span>
                                </td>
                                <td class="num">
                                    <span class="money <?= $act_cls ?> tnum"><?= formatCurrency($category['activity']) ?></span>
                                </td>
                                <td>
                                    <div class="bar<?= $bar_mod ?>">
                                        <i style="width:<?= min(100, $spent_pct) ?>%"></i>
                                    </div>
                                </td>
                                <td class="num">
                                    <span class="money <?= $bal_cls ?> tnum"><?= formatCurrency($category['balance']) ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ($category['balance'] < 0): ?>
                                        <button type="button" class="btn btn-sm cover-overspending-btn"
                                                onclick="showCoverOverspendingModal('<?= htmlspecialchars($category['category_uuid']) ?>','<?= htmlspecialchars($category['category_name']) ?>',<?= abs($category['balance']) ?>)"
                                                title="Cover overspending">Cover</button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm move-money-btn"
                                                data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                title="Move money" <?= $category['balance'] <= 0 ? 'disabled' : '' ?>>Move</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Grouped view -->
            <div id="categories-grouped-view" class="categories-view" style="display:none;">
                <?php if (empty($grouped_categories) || (count($grouped_categories) === 1 && isset($grouped_categories['_ungrouped']))): ?>
                    <div style="padding:var(--space-8);text-align:center;color:var(--color-fg-muted);">
                        <p>No category groups yet.</p>
                        <p><a href="../categories/manage.php?ledger=<?= urlencode($ledger_uuid) ?>">Organize categories into groups</a> to use this view.</p>
                    </div>
                <?php else: ?>
                    <table class="tbl" style="border-radius:0;border:0;">
                        <thead>
                            <tr>
                                <th style="width:44px;"></th>
                                <th>Category</th>
                                <th class="num">Budgeted</th>
                                <th class="num">Activity</th>
                                <th style="min-width:110px;">Progress</th>
                                <th class="num">Balance</th>
                                <th style="width:76px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped_categories as $group_uuid => $group_data): ?>
                                <?php if ($group_uuid === '_ungrouped' && empty($group_data['categories'])): continue; endif; ?>

                                <?php if ($group_data['group']): ?>
                                    <?php
                                    $group_subtotal = null;
                                    foreach ($group_subtotals as $subtotal) {
                                        if ($subtotal['group_uuid'] === $group_uuid) { $group_subtotal = $subtotal; break; }
                                    }
                                    $g_spent_pct = ($group_subtotal && $group_subtotal['total_budgeted'] > 0)
                                        ? (abs($group_subtotal['total_activity']) / $group_subtotal['total_budgeted']) * 100 : 0;
                                    $g_bar_mod   = ($group_subtotal && $group_subtotal['total_balance'] < 0) ? ' over' : ($g_spent_pct >= 76 ? '' : ' under');
                                    $g_bal_cls   = !$group_subtotal ? '' : ($group_subtotal['total_balance'] > 0 ? 'pos' : ($group_subtotal['total_balance'] < 0 ? 'neg' : 'zero'));
                                    $g_act_cls   = !$group_subtotal ? '' : ($group_subtotal['total_activity'] < 0 ? 'neg' : ($group_subtotal['total_activity'] > 0 ? 'pos' : 'zero'));
                                    ?>
                                    <tr class="group-head" data-group-uuid="<?= htmlspecialchars($group_uuid) ?>">
                                        <td colspan="2">
                                            <button type="button" class="group-toggle-btn">▼</button>
                                            <?= htmlspecialchars($group_data['group']['category_name']) ?>
                                            <span class="badge badge-neutral" style="margin-left:var(--space-2);"><?= count($group_data['categories']) ?></span>
                                        </td>
                                        <?php if ($group_subtotal): ?>
                                            <td class="num"><span class="tnum"><?= formatCurrency($group_subtotal['total_budgeted']) ?></span></td>
                                            <td class="num"><span class="money <?= $g_act_cls ?> tnum"><?= formatCurrency($group_subtotal['total_activity']) ?></span></td>
                                            <td><div class="bar<?= $g_bar_mod ?>"><i style="width:<?= min(100, $g_spent_pct) ?>%"></i></div></td>
                                            <td class="num"><span class="money <?= $g_bal_cls ?> tnum"><?= formatCurrency($group_subtotal['total_balance']) ?></span></td>
                                        <?php else: ?>
                                            <td colspan="4" class="num">—</td>
                                        <?php endif; ?>
                                        <td></td>
                                    </tr>
                                <?php elseif ($group_uuid === '_ungrouped'): ?>
                                    <tr class="group-head" data-group-uuid="_ungrouped">
                                        <td colspan="7">
                                            <button type="button" class="group-toggle-btn">▼</button>
                                            <span style="opacity:0.7;">Ungrouped</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($group_data['categories'] as $cat): ?>
                                    <?php
                                    $category_budget = null;
                                    foreach ($budget_status as $bs) {
                                        if ($bs['category_uuid'] === $cat['category_uuid']) { $category_budget = $bs; break; }
                                    }
                                    if (!$category_budget) continue;
                                    $spent_pct = $category_budget['budgeted'] > 0 ? (abs($category_budget['activity']) / $category_budget['budgeted']) * 100 : 0;
                                    $bar_mod   = $category_budget['balance'] < 0 ? ' over' : ($spent_pct >= 76 ? '' : ' under');
                                    $bal_cls   = $category_budget['balance'] > 0 ? 'pos' : ($category_budget['balance'] < 0 ? 'neg' : 'zero');
                                    $act_cls   = $category_budget['activity'] < 0 ? 'neg' : ($category_budget['activity'] > 0 ? 'pos' : 'zero');
                                    ?>
                                    <tr class="category-row grouped-category-row" data-parent-group="<?= htmlspecialchars($group_uuid) ?>">
                                        <td>
                                            <div class="cat-icon" style="width:28px;height:28px;font-size:14px;">
                                                <?= getCategoryIcon($category_budget['category_name']) ?>
                                            </div>
                                        </td>
                                        <td style="padding-left:var(--space-8);">
                                            <span style="font-weight:600;"><?= htmlspecialchars($category_budget['category_name']) ?></span>
                                        </td>
                                        <td class="num budget-amount-editable"
                                            role="button"
                                            tabindex="0"
                                            aria-label="Assign budget for <?= htmlspecialchars($category_budget['category_name']) ?>"
                                            data-category-uuid="<?= htmlspecialchars($category_budget['category_uuid']) ?>"
                                            data-category-name="<?= htmlspecialchars($category_budget['category_name']) ?>"
                                            data-current-amount="<?= $category_budget['budgeted'] ?>"
                                            title="Click or press Enter to assign budget">
                                            <span class="tnum"><?= formatCurrency($category_budget['budgeted']) ?></span>
                                        </td>
                                        <td class="num"><span class="money <?= $act_cls ?> tnum"><?= formatCurrency($category_budget['activity']) ?></span></td>
                                        <td><div class="bar<?= $bar_mod ?>"><i style="width:<?= min(100, $spent_pct) ?>%"></i></div></td>
                                        <td class="num"><span class="money <?= $bal_cls ?> tnum"><?= formatCurrency($category_budget['balance']) ?></span></td>
                                        <td style="text-align:right;">
                                            <?php if ($category_budget['balance'] < 0): ?>
                                                <button type="button" class="btn btn-sm cover-overspending-btn"
                                                        onclick="showCoverOverspendingModal('<?= htmlspecialchars($category_budget['category_uuid']) ?>','<?= htmlspecialchars($category_budget['category_name']) ?>',<?= abs($category_budget['balance']) ?>)"
                                                        title="Cover overspending">Cover</button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm move-money-btn"
                                                        data-category-uuid="<?= htmlspecialchars($category_budget['category_uuid']) ?>"
                                                        data-category-name="<?= htmlspecialchars($category_budget['category_name']) ?>"
                                                        title="Move money" <?= $category_budget['balance'] <= 0 ? 'disabled' : '' ?>>Move</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Bottom row: accounts + cash flow / recent transactions -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);" class="dashboard-bottom-grid">

        <!-- Accounts -->
        <div class="card">
            <div class="card-head">
                <span class="card-title">Accounts</span>
                <a href="../accounts/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-ghost btn-sm">Manage</a>
            </div>
            <?php if (empty($ledger_accounts)): ?>
                <div style="text-align:center;padding:var(--space-6);color:var(--color-fg-muted);">
                    <p style="margin-bottom:var(--space-3);">No accounts yet.</p>
                    <a href="../accounts/create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary btn-sm">Add Account</a>
                </div>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:var(--space-2);">
                    <?php foreach ($ledger_accounts as $acc): ?>
                    <div class="account-card">
                        <div class="cat-icon" style="background:var(--primary-100,#dbeafe);color:var(--color-primary);">
                            <?= htmlspecialchars(strtoupper(substr($acc['name'], 0, 1))) ?>
                        </div>
                        <div class="meta">
                            <div class="name"><?= htmlspecialchars($acc['name']) ?></div>
                            <div class="type"><?= htmlspecialchars($acc['type']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cash Flow Outlook or Recent Transactions -->
        <div class="card">
            <?php if (!empty($projection_outlook)): ?>
                <div class="card-head">
                    <span class="card-title">Cash Flow Outlook</span>
                    <a href="../reports/cash-flow-projection.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-ghost btn-sm">Full Report</a>
                </div>
                <?php
                $max_abs = 1;
                foreach ($projection_outlook as $ps) {
                    $abs = abs((int)$ps['net_monthly_balance']);
                    if ($abs > $max_abs) $max_abs = $abs;
                }
                ?>
                <div style="display:flex;gap:4px;align-items:flex-end;height:80px;margin-bottom:var(--space-3);">
                    <?php foreach ($projection_outlook as $ps):
                        $net = (int)$ps['net_monthly_balance'];
                        $pct = (int)round(abs($net) / $max_abs * 100);
                        $lbl = (new DateTime($ps['month']))->format('M');
                        $bar_col = $net >= 0 ? 'var(--success-500,#22c55e)' : 'var(--danger-500,#ef4444)';
                        $tip = $lbl . ': ' . ($net >= 0 ? '+' : '') . formatCurrency(abs($net));
                    ?>
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;">
                        <div style="flex:1;display:flex;align-items:flex-end;width:100%;justify-content:center;">
                            <div style="width:70%;min-height:3px;border-radius:2px 2px 0 0;background:<?= $bar_col ?>;height:<?= $pct ?>%;" title="<?= htmlspecialchars($tip) ?>"></div>
                        </div>
                        <div style="font-size:0.62rem;color:var(--color-fg-muted);margin-top:2px;"><?= $lbl ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                $six_mo_net = array_sum(array_column($projection_outlook, 'net_monthly_balance'));
                $cum_cls    = $six_mo_net >= 0 ? 'pos' : 'neg';
                ?>
                <div style="font-size:var(--text-xs);color:var(--color-fg-muted);text-align:right;border-top:1px solid var(--color-border);padding-top:var(--space-2);">
                    6-mo net: <span class="money <?= $cum_cls ?> tnum"><?= ($six_mo_net >= 0 ? '+' : '') . formatCurrency(abs($six_mo_net)) ?></span>
                </div>
            <?php else: ?>
                <div class="card-head">
                    <span class="card-title">Recent Transactions</span>
                    <a href="../transactions/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <?php if (empty($recent_transactions)): ?>
                    <div style="text-align:center;padding:var(--space-6);color:var(--color-fg-muted);">No transactions yet.</div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:var(--space-2);">
                        <?php foreach (array_slice($recent_transactions, 0, 5) as $txn):
                            $tv_cls = $txn['type'] === 'inflow' ? 'pos' : ($txn['type'] === 'transfer' ? 'zero' : 'neg');
                            $prefix = $txn['type'] === 'transfer' ? '⇄' : ($txn['type'] === 'inflow' ? '+' : '−');
                        ?>
                        <div style="display:flex;align-items:center;gap:var(--space-3);">
                            <div class="cat-icon" style="width:30px;height:30px;font-size:13px;flex-shrink:0;">
                                <?= htmlspecialchars(strtoupper(substr($txn['description'] ?: '?', 0, 1))) ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:var(--text-sm);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($txn['description']) ?>
                                </div>
                                <div style="font-size:var(--text-xs);color:var(--color-fg-muted);"><?= date('M j', strtotime($txn['date'])) ?></div>
                            </div>
                            <span class="money <?= $tv_cls ?> tnum" style="font-size:var(--text-sm);flex-shrink:0;">
                                <?= $prefix ?> <?= formatCurrency(abs((int)$txn['amount'])) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
/* group-head toggle button */
.group-head .group-toggle-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    margin-right: var(--space-2);
    color: var(--color-fg-muted);
    transition: transform var(--duration-fast, 150ms) var(--ease-out, ease-out);
    vertical-align: middle;
}
.group-head .group-toggle-btn.collapsed { transform: rotate(-90deg); }
.grouped-category-row.hidden { display: none; }

@media (max-width: 768px) {
    .dashboard-hero-grid,
    .dashboard-bottom-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php require_once '../../includes/transfer-modal.php'; ?>
<?php require_once '../../includes/quick-add-modal.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // View toggle
    const flatViewBtn    = document.getElementById('view-toggle-flat');
    const groupedViewBtn = document.getElementById('view-toggle-grouped');
    const flatView       = document.getElementById('categories-flat-view');
    const groupedView    = document.getElementById('categories-grouped-view');

    if (flatViewBtn && groupedViewBtn && flatView && groupedView) {
        if ((localStorage.getItem('categoryView') || 'flat') === 'grouped') switchToGrouped();

        flatViewBtn.addEventListener('click', switchToFlat);
        groupedViewBtn.addEventListener('click', switchToGrouped);

        function switchToFlat() {
            flatView.style.display = 'block';
            groupedView.style.display = 'none';
            flatViewBtn.classList.add('active');
            groupedViewBtn.classList.remove('active');
            localStorage.setItem('categoryView', 'flat');
        }

        function switchToGrouped() {
            flatView.style.display = 'none';
            groupedView.style.display = 'block';
            flatViewBtn.classList.remove('active');
            groupedViewBtn.classList.add('active');
            localStorage.setItem('categoryView', 'grouped');
        }
    }

    // Group collapse
    document.querySelectorAll('.group-head .group-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const groupUuid = this.closest('tr').dataset.groupUuid;
            this.classList.toggle('collapsed');
            document.querySelectorAll(`tr.grouped-category-row[data-parent-group="${groupUuid}"]`).forEach(row => {
                row.classList.toggle('hidden');
            });
        });
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>
