<?php
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
        ORDER BY t.date DESC, t.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$ledger_uuid]);
    $recent_transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="budget-header">
        <div class="budget-title">
            <h1><?= htmlspecialchars($ledger['name']) ?></h1>
            <?php if ($ledger['description']): ?>
                <p class="budget-description"><?= htmlspecialchars($ledger['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="budget-actions">
            <a href="../transactions/add.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ Add Transaction</a>
            <a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Manage Categories</a>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="period-selector">
        <form method="GET" class="period-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <label for="period">View Period:</label>
            <select name="period" id="period" onchange="this.form.submit()">
                <option value="" <?= !$selected_period ? 'selected' : '' ?>>All Time</option>
                <?php
                // Generate last 12 months options
                for ($i = 0; $i < 12; $i++) {
                    $date = new DateTime();
                    $date->modify("-$i months");
                    $period_value = $date->format('Ym');
                    $period_label = $date->format('F Y');
                    $selected = ($selected_period === $period_value) ? 'selected' : '';
                    echo "<option value=\"$period_value\" $selected>$period_label</option>";
                }
                ?>
            </select>
            <?php if ($selected_period): ?>
                <span class="period-info">
                    Showing data for <?= DateTime::createFromFormat('Ym', $selected_period)->format('F Y') ?>
                </span>
            <?php endif; ?>
        </form>
    </div>

    <div class="budget-grid">
        <div class="budget-main">
            <!-- Budget Totals -->
            <div class="budget-summary">
                <h2>Budget Overview</h2>
                <?php if ($budget_totals): ?>
                    <div class="summary-row">
                        <span>Income this period:</span>
                        <span class="amount positive"><?= formatCurrency($budget_totals['income']) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Budgeted:</span>
                        <span class="amount"><?= formatCurrency($budget_totals['budgeted']) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Left to budget:</span>
                        <span class="amount <?= $budget_totals['left_to_budget'] > 0 ? 'positive' : ($budget_totals['left_to_budget'] < 0 ? 'negative' : 'zero') ?>">
                            <?= formatCurrency($budget_totals['left_to_budget']) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Budget Categories -->
            <div class="categories-section">
                <h2>Budget Categories</h2>
                <?php if (empty($budget_status)): ?>
                    <div class="empty-state">
                        <p>No budget categories yet. Start by creating your first category!</p>
                        <a href="../categories/manage.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Category</a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Budgeted</th>
                                <th>Activity</th>
                                <th>Balance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budget_status as $category): ?>
                                <tr>
                                    <td><?= htmlspecialchars($category['category_name']) ?></td>
                                    <td class="amount"><?= formatCurrency($category['budgeted']) ?></td>
                                    <td class="amount <?= $category['activity'] < 0 ? 'negative' : 'positive' ?>">
                                        <?= formatCurrency($category['activity']) ?>
                                    </td>
                                    <td class="amount <?= $category['balance'] > 0 ? 'positive' : ($category['balance'] < 0 ? 'negative' : 'zero') ?>">
                                        <?= formatCurrency($category['balance']) ?>
                                    </td>
                                    <td>
                                        <a href="../transactions/assign.php?ledger=<?= $ledger_uuid ?>&category=<?= $category['category_uuid'] ?>" class="btn btn-small">Assign</a>
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

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-buttons">
                    <a href="../accounts/list.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-small">View Accounts</a>
                    <a href="../transactions/list.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-small">All Transactions</a>
                    <a href="../reports/budget.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-small">Budget Report</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.budget-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.budget-title h1 {
    margin: 0;
    color: #2d3748;
}

.budget-description {
    color: #718096;
    margin: 0.5rem 0 0 0;
}

.budget-actions {
    display: flex;
    gap: 1rem;
}

.categories-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.categories-section h2 {
    margin-bottom: 1.5rem;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #718096;
}

.recent-transactions {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.transaction-list {
    margin-bottom: 1rem;
}

.transaction-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.transaction-item:last-child {
    border-bottom: none;
}

.transaction-description {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.transaction-accounts {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.transaction-date {
    font-size: 0.75rem;
    color: #a0aec0;
}

.transaction-amount {
    font-weight: 600;
    white-space: nowrap;
}

.quick-actions {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.btn-small {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-edit {
    background: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    text-decoration: none;
    border-radius: 4px;
    transition: all 0.2s;
}

.btn-edit:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.transaction-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.period-selector {
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.period-form {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.period-form label {
    font-weight: 600;
    color: #2d3748;
    white-space: nowrap;
}

.period-form select {
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background: white;
    color: #2d3748;
    font-size: 0.875rem;
    min-width: 150px;
}

.period-form select:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.period-info {
    color: #718096;
    font-size: 0.875rem;
    font-style: italic;
}

@media (max-width: 768px) {
    .budget-header {
        flex-direction: column;
        gap: 1rem;
    }

    .budget-actions {
        width: 100%;
    }

    .budget-actions a {
        flex: 1;
        text-align: center;
    }

    .period-form {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .period-form select {
        width: 100%;
        min-width: auto;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>