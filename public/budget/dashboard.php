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

require_once '../../includes/header.php';
?>

<div class="container">
    <!-- Hidden data for JavaScript -->
    <div id="ledger-accounts-data"
         data-accounts='<?= json_encode(array_map(function($acc) {
             return ['uuid' => $acc['uuid'], 'name' => $acc['name'], 'type' => $acc['type']];
         }, $ledger_accounts)) ?>'
         style="display: none;"></div>

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

    <!-- Ready to Assign Banner -->
    <?php if ($budget_totals): ?>
        <div class="ready-to-assign-banner <?= $budget_totals['left_to_budget'] > 0 ? 'has-funds' : ($budget_totals['left_to_budget'] < 0 ? 'negative-funds' : 'zero-funds') ?>">
            <div class="ready-to-assign-content">
                <div class="ready-to-assign-label">Ready to Assign</div>
                <div class="ready-to-assign-amount"><?= formatCurrency($budget_totals['left_to_budget']) ?></div>
                <?php if ($budget_totals['left_to_budget'] > 0): ?>
                    <div class="ready-to-assign-hint">💡 Click a category amount below to assign money</div>
                <?php elseif ($budget_totals['left_to_budget'] === 0): ?>
                    <div class="ready-to-assign-hint">✓ All income assigned! Great job!</div>
                <?php else: ?>
                    <div class="ready-to-assign-hint">⚠️ Overbudgeted by <?= formatCurrency(abs($budget_totals['left_to_budget'])) ?></div>
                <?php endif; ?>
            </div>
            <div class="ready-to-assign-stats">
                <div class="stat-item">
                    <span class="stat-label">Income</span>
                    <span class="stat-value"><?= formatCurrency($budget_totals['income']) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Budgeted</span>
                    <span class="stat-value total-budgeted-amount"><?= formatCurrency($budget_totals['budgeted']) ?></span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="budget-grid">
        <div class="budget-main">

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
                                <tr class="category-row <?= $category['balance'] < 0 ? 'overspent' : '' ?>"
                                    data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>">
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
                                    <td class="amount category-balance <?= $category['balance'] > 0 ? 'positive' : ($category['balance'] < 0 ? 'negative' : 'zero') ?>">
                                        <?= formatCurrency($category['balance']) ?>
                                    </td>
                                    <td class="category-actions-cell">
                                        <button type="button"
                                                class="btn btn-small btn-move move-money-btn"
                                                data-category-uuid="<?= htmlspecialchars($category['category_uuid']) ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                title="Move money from this category"
                                                <?= $category['balance'] <= 0 ? 'disabled' : '' ?>>
                                            💸 Move
                                        </button>
                                        <a href="../transactions/assign.php?ledger=<?= $ledger_uuid ?>&category=<?= $category['category_uuid'] ?>" class="btn btn-small btn-secondary">Assign</a>
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

/* Ready to Assign Banner */
.ready-to-assign-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ready-to-assign-banner.has-funds {
    background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
}

.ready-to-assign-banner.zero-funds {
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
}

.ready-to-assign-banner.negative-funds {
    background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
}

.ready-to-assign-content {
    flex: 1;
}

.ready-to-assign-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.ready-to-assign-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.ready-to-assign-hint {
    font-size: 0.875rem;
    opacity: 0.9;
}

.ready-to-assign-stats {
    display: flex;
    gap: 2rem;
}

.ready-to-assign-stats .stat-item {
    text-align: center;
}

.ready-to-assign-stats .stat-label {
    display: block;
    font-size: 0.75rem;
    opacity: 0.8;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
}

.ready-to-assign-stats .stat-value {
    display: block;
    font-size: 1.25rem;
    font-weight: 600;
}

/* Inline Budget Editing */
.budget-amount-editable {
    cursor: pointer;
    position: relative;
    transition: background-color 0.2s;
}

.budget-amount-editable:hover {
    background-color: #f7fafc;
}

.budget-amount-editable::after {
    content: '✎';
    position: absolute;
    right: 0.25rem;
    opacity: 0;
    font-size: 0.75rem;
    color: #718096;
    transition: opacity 0.2s;
}

.budget-amount-editable:hover::after {
    opacity: 1;
}

.inline-edit-container {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.inline-edit-input {
    width: 100px;
    padding: 0.25rem 0.5rem;
    border: 2px solid #3182ce;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 600;
}

.inline-edit-input:focus {
    outline: none;
    border-color: #2c5aa0;
}

.inline-edit-buttons {
    display: flex;
    gap: 0.25rem;
}

.inline-edit-save,
.inline-edit-cancel {
    padding: 0.25rem 0.5rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.inline-edit-save {
    background-color: #38a169;
    color: white;
}

.inline-edit-save:hover {
    background-color: #2f855a;
}

.inline-edit-cancel {
    background-color: #e2e8f0;
    color: #4a5568;
}

.inline-edit-cancel:hover {
    background-color: #cbd5e0;
}

.inline-edit-loading {
    color: #718096;
    font-style: italic;
}

.budget-updated {
    animation: highlight 0.6s ease-out;
}

@keyframes highlight {
    0% { background-color: #c6f6d5; }
    100% { background-color: transparent; }
}

/* Overspent row styling */
.category-row.overspent {
    background-color: #fff5f5;
}

.category-row.overspent td {
    border-left: 3px solid #fc8181;
}

/* Notifications */
.inline-edit-notification {
    position: fixed;
    top: 2rem;
    right: 2rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    font-weight: 500;
    z-index: 1000;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.3s ease-out;
}

.inline-edit-notification.show {
    opacity: 1;
    transform: translateY(0);
}

.notification-success {
    background-color: #38a169;
    color: white;
}

.notification-error {
    background-color: #e53e3e;
    color: white;
}

.notification-info {
    background-color: #3182ce;
    color: white;
}

@media (max-width: 768px) {
    .ready-to-assign-banner {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }

    .ready-to-assign-amount {
        font-size: 2rem;
    }

    .ready-to-assign-stats {
        width: 100%;
        justify-content: space-around;
    }

    .inline-edit-notification {
        right: 1rem;
        left: 1rem;
    }
}
</style>

<!-- Move Money Modal Styles -->
<style>
.btn-move {
    background-color: #9f7aea;
    color: white;
    border: none;
}

.btn-move:hover:not(:disabled) {
    background-color: #805ad5;
}

.btn-move:disabled {
    background-color: #e2e8f0;
    color: #a0aec0;
    cursor: not-allowed;
}

.category-actions-cell {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

/* Modal Backdrop */
.modal-backdrop {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease-out;
}

.modal-backdrop.show {
    opacity: 1;
}

/* Modal Content */
.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    transform: scale(0.9);
    transition: transform 0.3s ease-out;
}

.modal-backdrop.show .modal-content {
    transform: scale(1);
}

.modal-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    color: #2d3748;
    font-size: 1.5rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    line-height: 1;
    color: #718096;
    cursor: pointer;
    padding: 0;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
}

.modal-close:hover {
    background-color: #f7fafc;
    color: #2d3748;
}

.modal-body {
    padding: 2rem;
}

.modal-description {
    color: #4a5568;
    margin-bottom: 1.5rem;
    line-height: 1.6;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.form-actions .btn {
    flex: 1;
}

/* Override default btn-secondary for modal cancel button */
.modal-content .btn-secondary {
    background-color: #f7fafc;
    color: #4a5568;
    border: 1px solid #e2e8f0;
}

.modal-content .btn-secondary:hover {
    background-color: #edf2f7;
    color: #2d3748;
    border-color: #cbd5e0;
}

.move-money-help {
    margin-top: 2rem;
    padding: 1rem;
    background-color: #f7fafc;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.move-money-help h4 {
    margin: 0 0 0.75rem 0;
    color: #2d3748;
}

.move-money-help ul {
    margin: 0;
    padding-left: 1.5rem;
}

.move-money-help li {
    margin-bottom: 0.5rem;
    color: #4a5568;
}

.move-money-help strong {
    color: #2d3748;
}

.move-money-notification {
    position: fixed;
    top: 2rem;
    right: 2rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    font-weight: 500;
    z-index: 1001;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.3s ease-out;
}

.move-money-notification.show {
    opacity: 1;
    transform: translateY(0);
}

@media (max-width: 768px) {
    .modal-content {
        width: 95%;
        max-height: 95vh;
    }

    .modal-header {
        padding: 1rem 1.5rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .category-actions-cell {
        flex-direction: column;
        gap: 0.25rem;
    }

    .btn-small {
        font-size: 0.75rem;
        padding: 0.375rem 0.625rem;
    }

    .move-money-notification {
        right: 1rem;
        left: 1rem;
    }
}
</style>

<!-- Additional styles for Phase 1.3 enhancements -->
<style>
/* Sticky Header */
.sticky-budget-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 999;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    opacity: 0;
    transform: translateY(-100%);
    transition: opacity 0.3s ease-out, transform 0.3s ease-out;
}

.sticky-budget-header.show-sticky {
    opacity: 1;
    transform: translateY(0);
}

.sticky-budget-header .ready-to-assign-amount {
    font-size: 1.75rem;
}

.sticky-budget-header .ready-to-assign-stats {
    gap: 1rem;
}

/* Enhanced Color Coding */
.category-row.category-green {
    background-color: rgba(72, 187, 120, 0.05);
}

.category-row.category-green:hover {
    background-color: rgba(72, 187, 120, 0.1);
}

.category-row.category-yellow {
    background-color: rgba(237, 137, 54, 0.05);
}

.category-row.category-yellow:hover {
    background-color: rgba(237, 137, 54, 0.1);
}

.category-row.category-red {
    background-color: rgba(245, 101, 101, 0.08);
    border-left: 4px solid #fc8181;
}

.category-row.category-red:hover {
    background-color: rgba(245, 101, 101, 0.12);
}

/* Quick Add Transaction Button */
.quick-add-transaction-btn {
    background-color: #38a169;
    color: white;
    font-weight: 600;
    transition: all 0.2s;
}

.quick-add-transaction-btn:hover {
    background-color: #2f855a;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(56, 161, 105, 0.3);
}

/* Overspending Warning Banner */
.overspending-warning-banner {
    background: linear-gradient(135deg, #fc8181 0%, #f56565 100%);
    color: white;
    border-radius: 8px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(245, 101, 101, 0.3);
    animation: slideInDown 0.5s ease-out;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.warning-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex: 1;
}

.warning-icon {
    font-size: 2rem;
    line-height: 1;
}

.warning-text strong {
    display: block;
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}

.warning-text span {
    font-size: 0.875rem;
    opacity: 0.95;
}

.btn-warning-action {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.4);
    white-space: nowrap;
}

.btn-warning-action:hover {
    background-color: rgba(255, 255, 255, 0.3);
    border-color: rgba(255, 255, 255, 0.6);
}

/* Cover Overspending Button */
.cover-overspending-btn {
    background-color: #f56565;
    color: white;
    border: none;
    font-weight: 600;
}

.cover-overspending-btn:hover {
    background-color: #e53e3e;
}

/* Overspending Summary in Modal */
.overspending-summary {
    background-color: #fff5f5;
    border-left: 4px solid #fc8181;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.overspending-summary p {
    margin: 0.5rem 0;
}

.overspending-summary .negative {
    color: #c53030;
    font-weight: 700;
    font-size: 1.1rem;
}

/* Overspending Explanation Section */
.overspending-explanation {
    background-color: #fffbeb;
    border-left: 4px solid #f59e0b;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.overspending-explanation h4 {
    color: #92400e;
    margin: 0 0 0.75rem 0;
    font-size: 1rem;
}

.overspending-explanation p {
    margin: 0.5rem 0;
    color: #78350f;
    line-height: 1.5;
}

.overspending-explanation ul {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
    color: #78350f;
}

.overspending-explanation li {
    margin-bottom: 0.5rem;
}

/* Radio Group Styles */
.radio-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.radio-option {
    display: flex;
    align-items: flex-start;
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.radio-option:hover {
    border-color: #cbd5e0;
    background-color: #f7fafc;
}

.radio-option input[type="radio"] {
    margin-top: 0.25rem;
    margin-right: 0.75rem;
    cursor: pointer;
    flex-shrink: 0;
}

.radio-option input[type="radio"]:checked + .radio-label {
    color: #2d3748;
}

.radio-option:has(input[type="radio"]:checked) {
    border-color: #3182ce;
    background-color: #ebf8ff;
}

.radio-label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    color: #4a5568;
    flex: 1;
}

.radio-label strong {
    font-size: 0.95rem;
    color: #2d3748;
}

.radio-label small {
    font-size: 0.8rem;
    color: #718096;
    line-height: 1.4;
}

/* Conditional Sections */
.conditional-section {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Info Boxes */
.info-box {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    border-left: 4px solid;
    margin-bottom: 1rem;
}

.info-box p {
    margin: 0.5rem 0;
    line-height: 1.5;
}

.info-box ul {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
}

.info-box li {
    margin-bottom: 0.5rem;
}

.info-warning {
    background-color: #fffbeb;
    border-color: #f59e0b;
    color: #78350f;
}

.info-warning strong {
    color: #92400e;
}

.warning-text {
    color: #b45309;
    font-weight: 600;
    font-size: 0.9rem;
    margin-top: 0.75rem;
}

/* Tooltip Styles */
.info-tooltip {
    display: inline-block;
    margin-left: 0.5rem;
    cursor: help;
    font-size: 0.9rem;
    opacity: 0.8;
    transition: opacity 0.2s;
}

.info-tooltip:hover {
    opacity: 1;
}

/* Quick Add Modal */
.quick-add-modal .modal-content {
    max-width: 600px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #2d3748;
}

.form-input,
.form-select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
    transition: border-color 0.2s;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-help {
    display: block;
    font-size: 0.75rem;
    color: #718096;
    margin-top: 0.25rem;
}

/* Dashboard Notification */
.dashboard-notification {
    position: fixed;
    top: 2rem;
    right: 2rem;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    font-weight: 500;
    z-index: 1002;
    opacity: 0;
    transform: translateY(-20px);
    transition: all 0.3s ease-out;
}

.dashboard-notification.show {
    opacity: 1;
    transform: translateY(0);
}

/* Success button styling */
.btn-success {
    background-color: #38a169;
    color: white;
    border: none;
}

.btn-success:hover {
    background-color: #2f855a;
}

.btn-danger {
    background-color: #e53e3e;
    color: white;
    border: none;
}

.btn-danger:hover {
    background-color: #c53030;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .overspending-warning-banner {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }

    .warning-content {
        flex-direction: column;
        text-align: center;
    }

    .btn-warning-action {
        width: 100%;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .sticky-budget-header .ready-to-assign-amount {
        font-size: 1.5rem;
    }

    .category-actions-cell {
        flex-wrap: wrap;
    }

    .cover-overspending-btn {
        width: 100%;
        margin-bottom: 0.25rem;
    }

    .dashboard-notification {
        right: 1rem;
        left: 1rem;
    }
}
</style>

<!-- Include inline editing JavaScript -->
<script src="../js/budget-inline-edit.js"></script>

<!-- Include move money modal JavaScript -->
<script src="../js/move-money-modal.js"></script>

<!-- Include dashboard enhancements JavaScript (Phase 1.3) -->
<script src="../js/budget-dashboard-enhancements.js"></script>

<?php require_once '../../includes/footer.php'; ?>