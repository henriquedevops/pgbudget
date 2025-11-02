<?php
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
        WHERE t.ledger_id = (SELECT id FROM data.ledgers WHERE uuid = ?)
        AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
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
                <h2>Budget Categories</h2>
                <?php if (empty($budget_status)): ?>
                    <div class="empty-state">
                        <h3>🎯 Ready to Budget!</h3>
                        <p>You have <?= formatCurrency($budget_totals['left_to_budget']) ?> waiting to be budgeted.</p>
                        <p>Click the button below to assign it to your categories.</p>
                        <a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">💵 Budget Money</a>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
            </div>
        </div>

        <div class="budget-sidebar">
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
});
</script>
<script src="../js/quick-add-modal.js"></script>
<?php require_once '../../includes/footer.php'; ?>