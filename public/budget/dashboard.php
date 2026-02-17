<?php
require_once '../../includes/session.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$selected_period = $_GET['period'] ?? null; // YYYYMM format

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

    // Get budget totals (with optional period)
    if ($selected_period) {
        $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?, ?)");
        $stmt->execute([$ledger_uuid, $selected_period]);
    } else {
        $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?)");
        $stmt->execute([$ledger_uuid]);
    }
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
                   WHEN da.name = 'Income' THEN 'inflow'
                   ELSE 'outflow'
               END as type
        FROM data.transactions t
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.accounts da ON t.debit_account_id = da.id
        LEFT JOIN data.transaction_log tl ON t.id = tl.original_transaction_id AND tl.mutation_type = 'deletion'
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

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
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

<div class="container">
    <!-- Hidden data for JavaScript -->
    <div id="ledger-accounts-data"
         data-accounts='<?= json_encode(array_map(function($acc) {
             return ['uuid' => $acc['uuid'], 'name' => $acc['name'], 'type' => $acc['type']];
         }, $ledger_accounts)) ?>'
         data-ledger-uuid="<?= htmlspecialchars($ledger_uuid) ?>"
         style="display: none;"></div>

    <div class="budget-header">
        <div class="budget-title">
            <h1><?= htmlspecialchars($ledger['name']) ?></h1>
            <?php if ($ledger['description']): ?>
                <p class="budget-description"><?= htmlspecialchars($ledger['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="budget-actions">
            <button type="button" class="btn btn-primary quick-add-transaction-btn" onclick="QuickAddModal.open({ledger_uuid: '<?= htmlspecialchars($ledger_uuid) ?>'})">
                ⚡ Quick Add
            </button>
            <a href="../transactions/assign.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-success">
                💵 Assign Money
            </a>
            <button type="button" class="btn btn-primary" onclick="TransferModal.open({ledger_uuid: '<?= htmlspecialchars($ledger_uuid) ?>'})">
                ⇄ Transfer
            </button>
            <button type="button" id="show-help-sidebar" class="btn btn-info">[?] Show Help</button>
        </div>
    </div>

    <!-- Budget Summary Card -->
    <?php if ($budget_totals): ?>
        <div class="budget-summary-card">
            <div class="summary-item">
                <span class="summary-label">Available to Budget</span>
                <span class="summary-amount <?= $budget_totals['left_to_budget'] > 0 ? 'positive' : ($budget_totals['left_to_budget'] < 0 ? 'negative' : 'zero') ?>"><?= formatCurrency($budget_totals['left_to_budget']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Income this month</span>
                <span class="summary-amount"><?= formatCurrency($budget_totals['income']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Budgeted</span>
                <span class="summary-amount"><?= formatCurrency($budget_totals['budgeted']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Spent so far</span>
                <span class="summary-amount"><?= formatCurrency($total_activity) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Overspending Warning Banner -->
    <?php if (!empty($overspent_categories)): ?>
        <div class="overspending-warning-banner">
            <div class="warning-content">
                <div class="warning-icon">⚠️</div>
                <div class="warning-text">
                    <strong>You have <?= count($overspent_categories) ?> overspent categor<?= count($overspent_categories) === 1 ? 'y' : 'ies' ?></strong>
                    <span>Total overspending: <?= formatCurrency($total_overspending) ?></span>
                </div>
            </div>
            <button type="button" class="btn btn-warning-action" onclick="showCoverOverspendingModal()">
                Cover Overspending
            </button>
        </div>
    <?php endif; ?>

    <div class="budget-grid">
        <div class="budget-main">

            <!-- Budget Categories -->
            <div class="categories-section">
                <div class="categories-header">
                    <h2>Budget Categories</h2>
                    <div class="view-toggle">
                        <button type="button" id="view-toggle-flat" class="view-toggle-btn active" title="Flat view">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M0 2h16v2H0V2zm0 5h16v2H0V7zm0 5h16v2H0v-2z"/>
                            </svg>
                            <span>List</span>
                        </button>
                        <button type="button" id="view-toggle-grouped" class="view-toggle-btn" title="Grouped view">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M0 2h16v2H0V2zm2 5h14v2H2V7zm2 5h12v2H4v-2z"/>
                            </svg>
                            <span>Groups</span>
                        </button>
                    </div>
                </div>
                <?php if (empty($budget_status)): ?>
                    <div class="empty-state">
                        <h3>🎯 Ready to Budget!</h3>
                        <p>You have <?= formatCurrency($budget_totals['left_to_budget']) ?> waiting to be budgeted.</p>
                        <p>Click the button below to assign it to your categories.</p>
                        <a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">💵 Budget Money</a>
                    </div>
                <?php else: ?>
                    <!-- Flat View (default) -->
                    <div id="categories-flat-view" class="categories-view">
                    <table class="table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Category</th>
                                <th>Budgeted</th>
                                <th>Activity</th>
                                <th>Progress</th>
                                <th>Balance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budget_status as $category): ?>
                                <?php
                                $spent_percentage = 0;
                                if ($category['budgeted'] > 0) {
                                    $spent_percentage = (abs($category['activity']) / $category['budgeted']) * 100;
                                }

                                $row_class = '';
                                if ($category['balance'] < 0) {
                                    $row_class = 'overspent';
                                } elseif ($spent_percentage >= 76) {
                                    $row_class = 'warning';
                                } else {
                                    $row_class = 'on-track';
                                }
                                ?>
                                <tr class="category-row <?= $row_class ?>">
                                    <td><?= getCategoryIcon($category['category_name']) ?></td>
                                    <td class="category-name-cell">
                                        <span class="category-name"><?= htmlspecialchars($category['category_name']) ?></span>
                                    </td>
                                    <td class="amount budget-amount-editable"
                                        data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                        data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                        data-current-amount="<?= $category['budgeted'] ?>"
                                        title="Click to assign budget">
                                        <?= formatCurrency($category['budgeted']) ?>
                                    </td>
                                    <td class="amount category-activity <?= $category['activity'] < 0 ? 'negative' : 'positive' ?>">
                                        <?= formatCurrency($category['activity']) ?>
                                    </td>
                                    <td>
                                        <div class="progress-bar <?= $row_class ?>">
                                            <div class="progress-bar-fill" style="width: <?= min(100, $spent_percentage) ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="amount category-balance <?= $category['balance'] > 0 ? 'positive' : ($category['balance'] < 0 ? 'negative' : 'zero') ?>">
                                        <?= formatCurrency($category['balance']) ?>
                                    </td>
                                    <td class="category-actions-cell">
                                        <?php if ($category['balance'] < 0): ?>
                                            <button type="button"
                                                    class="btn btn-small cover-overspending-btn"
                                                    data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                                    data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                    data-overspent-amount="<?= abs($category['balance']) ?>"
                                                    onclick="showCoverOverspendingModal('<?= htmlspecialchars($category['category_uuid']) ?>', '<?= htmlspecialchars($category['category_name']) ?>', <?= abs($category['balance']) ?>)"
                                                    title="Cover this overspending">
                                                🩹 Cover
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-small btn-move move-money-btn"
                                                    data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                                    data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                    title="Move money from this category"
                                                    <?= $category['balance'] <= 0 ? 'disabled' : '' ?>>
                                                💸 Move
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- end flat view -->

                    <!-- Grouped View -->
                    <div id="categories-grouped-view" class="categories-view" style="display: none;">
                    <?php if (empty($grouped_categories) || (count($grouped_categories) === 1 && isset($grouped_categories['_ungrouped']))): ?>
                        <div class="no-groups-message">
                            <p>You haven't created any category groups yet.</p>
                            <p><a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>">Organize your categories into groups</a> to use this view.</p>
                        </div>
                    <?php else: ?>
                        <table class="table grouped-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Category</th>
                                    <th>Budgeted</th>
                                    <th>Activity</th>
                                    <th>Progress</th>
                                    <th>Balance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grouped_categories as $group_uuid => $group_data): ?>
                                    <?php if ($group_uuid === '_ungrouped' && empty($group_data['categories'])): continue; endif; ?>

                                    <?php if ($group_data['group']): ?>
                                        <!-- Group Header Row -->
                                        <?php
                                        // Find group subtotals
                                        $group_subtotal = null;
                                        foreach ($group_subtotals as $subtotal) {
                                            if ($subtotal['group_uuid'] === $group_uuid) {
                                                $group_subtotal = $subtotal;
                                                break;
                                            }
                                        }
                                        ?>
                                        <tr class="group-header-row" data-group-uuid="<?= $group_uuid ?>">
                                            <td colspan="2" class="group-name-cell">
                                                <button type="button" class="group-toggle-btn">▼</button>
                                                <strong><?= htmlspecialchars($group_data['group']['category_name']) ?></strong>
                                                <span class="group-count">(<?= count($group_data['categories']) ?> categories)</span>
                                            </td>
                                            <?php if ($group_subtotal): ?>
                                                <td class="amount"><?= formatCurrency($group_subtotal['total_budgeted']) ?></td>
                                                <td class="amount <?= $group_subtotal['total_activity'] < 0 ? 'negative' : 'positive' ?>"><?= formatCurrency($group_subtotal['total_activity']) ?></td>
                                                <td>
                                                    <?php
                                                    $group_spent_percentage = 0;
                                                    if ($group_subtotal['total_budgeted'] > 0) {
                                                        $group_spent_percentage = (abs($group_subtotal['total_activity']) / $group_subtotal['total_budgeted']) * 100;
                                                    }
                                                    $group_row_class = 'on-track';
                                                    if ($group_subtotal['total_balance'] < 0) {
                                                        $group_row_class = 'overspent';
                                                    } elseif ($group_spent_percentage >= 76) {
                                                        $group_row_class = 'warning';
                                                    }
                                                    ?>
                                                    <div class="progress-bar <?= $group_row_class ?>">
                                                        <div class="progress-bar-fill" style="width: <?= min(100, $group_spent_percentage) ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="amount <?= $group_subtotal['total_balance'] > 0 ? 'positive' : ($group_subtotal['total_balance'] < 0 ? 'negative' : 'zero') ?>"><?= formatCurrency($group_subtotal['total_balance']) ?></td>
                                            <?php else: ?>
                                                <td colspan="4" class="amount">—</td>
                                            <?php endif; ?>
                                            <td></td>
                                        </tr>
                                    <?php elseif ($group_uuid === '_ungrouped'): ?>
                                        <!-- Ungrouped Section Header -->
                                        <tr class="group-header-row ungrouped-header" data-group-uuid="_ungrouped">
                                            <td colspan="7" class="group-name-cell">
                                                <button type="button" class="group-toggle-btn">▼</button>
                                                <span style="opacity: 0.7;">Ungrouped Categories</span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <!-- Category Rows under this group -->
                                    <?php foreach ($group_data['categories'] as $cat): ?>
                                        <?php
                                        // Find budget status for this category
                                        $category_budget = null;
                                        foreach ($budget_status as $bs) {
                                            if ($bs['category_uuid'] === $cat['category_uuid']) {
                                                $category_budget = $bs;
                                                break;
                                            }
                                        }

                                        if (!$category_budget) continue; // Skip if no budget data

                                        $spent_percentage = 0;
                                        if ($category_budget['budgeted'] > 0) {
                                            $spent_percentage = (abs($category_budget['activity']) / $category_budget['budgeted']) * 100;
                                        }

                                        $row_class = '';
                                        if ($category_budget['balance'] < 0) {
                                            $row_class = 'overspent';
                                        } elseif ($spent_percentage >= 76) {
                                            $row_class = 'warning';
                                        } else {
                                            $row_class = 'on-track';
                                        }
                                        ?>
                                        <tr class="category-row grouped-category-row <?= $row_class ?>" data-parent-group="<?= $group_uuid ?>">
                                            <td><?= getCategoryIcon($category_budget['category_name']) ?></td>
                                            <td class="category-name-cell indented">
                                                <span class="category-name"><?= htmlspecialchars($category_budget['category_name']) ?></span>
                                            </td>
                                            <td class="amount budget-amount-editable"
                                                data-category-uuid="<?= htmlspecialchars($category_budget['category_uuid']) ?>"
                                                data-category-name="<?= htmlspecialchars($category_budget['category_name']) ?>"
                                                data-current-amount="<?= $category_budget['budgeted'] ?>"
                                                title="Click to assign budget">
                                                <?= formatCurrency($category_budget['budgeted']) ?>
                                            </td>
                                            <td class="amount category-activity <?= $category_budget['activity'] < 0 ? 'negative' : 'positive' ?>">
                                                <?= formatCurrency($category_budget['activity']) ?>
                                            </td>
                                            <td>
                                                <div class="progress-bar <?= $row_class ?>">
                                                    <div class="progress-bar-fill" style="width: <?= min(100, $spent_percentage) ?>%"></div>
                                                </div>
                                            </td>
                                            <td class="amount category-balance <?= $category_budget['balance'] > 0 ? 'positive' : ($category_budget['balance'] < 0 ? 'negative' : 'zero') ?>">
                                                <?= formatCurrency($category_budget['balance']) ?>
                                            </td>
                                            <td class="category-actions-cell">
                                                <?php if ($category_budget['balance'] < 0): ?>
                                                    <button type="button"
                                                            class="btn btn-small cover-overspending-btn"
                                                            data-category-uuid="<?= htmlspecialchars($category_budget['category_uuid']) ?>"
                                                            data-category-name="<?= htmlspecialchars($category_budget['category_name']) ?>"
                                                            data-overspent-amount="<?= abs($category_budget['balance']) ?>"
                                                            onclick="showCoverOverspendingModal('<?= htmlspecialchars($category_budget['category_uuid']) ?>', '<?= htmlspecialchars($category_budget['category_name']) ?>', <?= abs($category_budget['balance']) ?>)"
                                                            title="Cover this overspending">
                                                        🩹 Cover
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="btn btn-small btn-move move-money-btn"
                                                            data-category-uuid="<?= htmlspecialchars($category_budget['category_uuid']) ?>"
                                                            data-category-name="<?= htmlspecialchars($category_budget['category_name']) ?>"
                                                            title="Move money from this category"
                                                            <?= $category_budget['balance'] <= 0 ? 'disabled' : '' ?>>
                                                        💸 Move
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    </div><!-- end grouped view -->
                <?php endif; ?>
            </div>
        </div>

        <div class="budget-sidebar">
            <!-- Cash Flow Outlook Widget -->
            <?php if (!empty($projection_outlook)): ?>
            <div class="cfp-widget">
                <h3>
                    Cash Flow Outlook
                    <a href="../reports/cash-flow-projection.php?ledger=<?= $ledger_uuid ?>" class="cfp-widget-link">Full Report →</a>
                </h3>
                <?php
                $max_abs = 1;
                foreach ($projection_outlook as $ps) {
                    $abs = abs((int)$ps['net_monthly_balance']);
                    if ($abs > $max_abs) $max_abs = $abs;
                }
                ?>
                <div class="cfp-widget-bars">
                    <?php foreach ($projection_outlook as $ps):
                        $net = (int)$ps['net_monthly_balance'];
                        $pct = (int)round(abs($net) / $max_abs * 100);
                        $lbl = (new DateTime($ps['month']))->format('M');
                        $bar_class = $net >= 0 ? 'bar-pos' : 'bar-neg';
                        $tip = $lbl . ': ' . ($net >= 0 ? '+' : '') . '$' . number_format(abs($net) / 100, 2);
                    ?>
                    <div class="cfp-bar-col">
                        <div class="cfp-bar-wrap">
                            <div class="cfp-bar <?= $bar_class ?>" style="height:<?= $pct ?>%;" title="<?= htmlspecialchars($tip) ?>"></div>
                        </div>
                        <div class="cfp-bar-lbl"><?= $lbl ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php
                $six_mo_net = array_sum(array_column($projection_outlook, 'net_monthly_balance'));
                $cum_class  = $six_mo_net >= 0 ? 'positive' : 'negative';
                ?>
                <div class="cfp-widget-total">
                    6-mo net: <span class="<?= $cum_class ?>"><?= ($six_mo_net >= 0 ? '+' : '') . '$' . number_format(abs($six_mo_net) / 100, 2) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Transactions -->
            <div class="recent-transactions">
                <h3>Recent Transactions</h3>
                <?php if (empty($recent_transactions)): ?>
                    <p class="empty-state">No transactions yet.</p>
                <?php else: ?>
                    <div class="transaction-list">
                        <?php foreach ($recent_transactions as $txn): ?>
                            <div class="transaction-item">
                                <div class="transaction-info">
                                    <div class="transaction-description"><?= htmlspecialchars($txn['description']) ?></div>
                                    <div class="transaction-accounts">
                                        <?= htmlspecialchars($txn['debit_account']) ?> → <?= htmlspecialchars($txn['credit_account']) ?>
                                    </div>
                                    <div class="transaction-date"><?= date('M j', strtotime($txn['date'])) ?></div>
                                </div>
                                <div class="transaction-actions">
                                    <div class="transaction-amount <?= $txn['type'] === 'inflow' ? 'positive' : 'negative' ?>">
                                        <?= $txn['type'] === 'inflow' ? '+' : '-' ?><?= formatCurrency($txn['amount']) ?>
                                    </div>
                                    <a href="../transactions/edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>" class="btn btn-small btn-edit" title="Edit Transaction">✏️</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="../transactions/list.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-small">View All</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.budget-summary-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}
.summary-item {
    display: flex;
    flex-direction: column;
}
.summary-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.5rem;
}
.summary-amount {
    font-size: 1.5rem;
    font-weight: 600;
}
.summary-amount.positive {
    color: #38a169;
}
.summary-amount.negative {
    color: #e53e3e;
}
.summary-amount.zero {
    color: #718096;
}

/* Category View Toggle Styles */
.categories-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.view-toggle {
    display: flex;
    gap: 0;
    background: #f7fafc;
    border-radius: 6px;
    padding: 4px;
    border: 1px solid #e2e8f0;
}

.view-toggle-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: transparent;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.875rem;
    color: #4a5568;
    transition: all 0.2s;
}

.view-toggle-btn:hover {
    background: #e2e8f0;
    color: #2d3748;
}

.view-toggle-btn.active {
    background: white;
    color: #3182ce;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.view-toggle-btn svg {
    width: 16px;
    height: 16px;
}

/* Grouped View Styles */
.grouped-table .group-header-row {
    background: #f7fafc;
    font-weight: 600;
    border-top: 2px solid #e2e8f0;
}

.grouped-table .group-header-row:first-child {
    border-top: none;
}

.group-name-cell {
    padding: 0.75rem 1rem !important;
}

.group-toggle-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    margin-right: 0.5rem;
    font-size: 0.875rem;
    color: #4a5568;
    transition: transform 0.2s;
}

.group-toggle-btn.collapsed {
    transform: rotate(-90deg);
}

.group-count {
    font-size: 0.875rem;
    color: #718096;
    font-weight: normal;
    margin-left: 0.5rem;
}

.grouped-category-row {
    display: table-row;
}

.grouped-category-row.hidden {
    display: none;
}

.grouped-category-row .category-name-cell.indented {
    padding-left: 2.5rem;
}

.no-groups-message {
    text-align: center;
    padding: 3rem 2rem;
    background: #f7fafc;
    border-radius: 8px;
    color: #4a5568;
}

.no-groups-message p {
    margin: 0.5rem 0;
}

.no-groups-message a {
    color: #3182ce;
    text-decoration: underline;
}

.ungrouped-header .group-name-cell {
    font-weight: normal;
}

@media (max-width: 768px) {
    .categories-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .view-toggle {
        width: 100%;
    }

    .view-toggle-btn {
        flex: 1;
        justify-content: center;
    }
}

/* Cash Flow Outlook Widget */
.cfp-widget {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.cfp-widget h3 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 0 0 0.75rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #1e293b;
}

.cfp-widget-link {
    font-size: 0.75rem;
    font-weight: 400;
    color: #3b82f6;
    text-decoration: none;
}

.cfp-widget-link:hover { text-decoration: underline; }

.cfp-widget-bars {
    display: flex;
    gap: 4px;
    align-items: flex-end;
    height: 72px;
    margin-bottom: 0.4rem;
}

.cfp-bar-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
}

.cfp-bar-wrap {
    flex: 1;
    width: 100%;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.cfp-bar {
    width: 70%;
    min-height: 3px;
    border-radius: 2px 2px 0 0;
}

.bar-pos { background: #4ade80; }
.bar-neg { background: #f87171; }

.cfp-bar-lbl {
    font-size: 0.62rem;
    color: #94a3b8;
    margin-top: 0.15rem;
    text-align: center;
}

.cfp-widget-total {
    font-size: 0.78rem;
    color: #64748b;
    text-align: right;
    border-top: 1px solid #f1f5f9;
    padding-top: 0.4rem;
}

.cfp-widget-total .positive { color: #166534; font-weight: 600; }
.cfp-widget-total .negative { color: #991b1b; font-weight: 600; }
</style>

<?php
require_once '../../includes/help-sidebar.php';
require_once '../../includes/transfer-modal.php';
require_once '../../includes/quick-add-modal.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const showHelpBtn = document.getElementById('show-help-sidebar');
    const helpSidebar = document.getElementById('help-sidebar');
    const closeHelpBtn = document.getElementById('close-help-sidebar');

    if (showHelpBtn && helpSidebar && closeHelpBtn) {
        showHelpBtn.addEventListener('click', function() {
            helpSidebar.classList.add('active');
        });

        closeHelpBtn.addEventListener('click', function() {
            helpSidebar.classList.remove('active');
        });

        // Close on backdrop click
        helpSidebar.addEventListener('click', function(e) {
            if (e.target.id === 'help-sidebar') {
                helpSidebar.classList.remove('active');
            }
        });
    }

    // Category View Toggle
    const flatViewBtn = document.getElementById('view-toggle-flat');
    const groupedViewBtn = document.getElementById('view-toggle-grouped');
    const flatView = document.getElementById('categories-flat-view');
    const groupedView = document.getElementById('categories-grouped-view');

    if (flatViewBtn && groupedViewBtn && flatView && groupedView) {
        // Load saved preference from localStorage
        const savedView = localStorage.getItem('categoryView') || 'flat';
        if (savedView === 'grouped') {
            switchToGroupedView();
        }

        flatViewBtn.addEventListener('click', function() {
            switchToFlatView();
        });

        groupedViewBtn.addEventListener('click', function() {
            switchToGroupedView();
        });

        function switchToFlatView() {
            flatView.style.display = 'block';
            groupedView.style.display = 'none';
            flatViewBtn.classList.add('active');
            groupedViewBtn.classList.remove('active');
            localStorage.setItem('categoryView', 'flat');
        }

        function switchToGroupedView() {
            flatView.style.display = 'none';
            groupedView.style.display = 'block';
            flatViewBtn.classList.remove('active');
            groupedViewBtn.classList.add('active');
            localStorage.setItem('categoryView', 'grouped');
        }
    }

    // Group Expand/Collapse Functionality
    const groupToggleBtns = document.querySelectorAll('.group-toggle-btn');
    groupToggleBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const groupRow = this.closest('.group-header-row');
            const groupUuid = groupRow.dataset.groupUuid;
            const categoryRows = document.querySelectorAll(`tr.grouped-category-row[data-parent-group="${groupUuid}"]`);

            // Toggle button rotation
            this.classList.toggle('collapsed');

            // Toggle category rows visibility
            categoryRows.forEach(row => {
                row.classList.toggle('hidden');
            });
        });
    });
});
</script>
<script src="../js/quick-add-modal.js"></script>
<?php require_once '../../includes/footer.php'; ?>