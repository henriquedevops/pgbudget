<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

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

    // Get all recurring transactions for this ledger
    $stmt = $db->prepare("SELECT * FROM api.get_recurring_transactions(?)");
    $stmt->execute([$ledger_uuid]);
    $recurring_transactions = $stmt->fetchAll();

    // Get due recurring transactions
    $stmt = $db->prepare("SELECT * FROM api.get_due_recurring_transactions(?, CURRENT_DATE)");
    $stmt->execute([$ledger_uuid]);
    $due_transactions = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <div>
            <h1>Recurring Transactions</h1>
            <p>Manage scheduled repeating transactions for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <a href="add.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Recurring Transaction</a>
    </div>

    <?php if (!empty($due_transactions)): ?>
    <div class="alert alert-info">
        <h3>Due Now (<?= count($due_transactions) ?>)</h3>
        <p>These recurring transactions are ready to be created:</p>
        <div class="due-transactions">
            <?php foreach ($due_transactions as $due): ?>
            <div class="due-transaction">
                <div class="due-info">
                    <strong><?= htmlspecialchars($due['description']) ?></strong>
                    <span class="due-details">
                        <?= formatCurrency($due['amount']) ?> •
                        <?= htmlspecialchars($due['account_name']) ?> •
                        <?= htmlspecialchars($due['category_name'] ?? 'Unassigned') ?> •
                        Due <?= date('M j, Y', strtotime($due['next_date'])) ?>
                    </span>
                </div>
                <form method="POST" action="create.php" style="display: inline;">
                    <input type="hidden" name="recurring_uuid" value="<?= $due['recurring_uuid'] ?>">
                    <input type="hidden" name="ledger_uuid" value="<?= $ledger_uuid ?>">
                    <button type="submit" class="btn btn-sm btn-success">Create Now</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="recurring-list">
        <?php if (empty($recurring_transactions)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔄</div>
            <h2>No Recurring Transactions</h2>
            <p>Set up recurring transactions for regular income or expenses like rent, salary, subscriptions, and bills.</p>
            <a href="add.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Recurring Transaction</a>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Frequency</th>
                    <th>Next Date</th>
                    <th>Account</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recurring_transactions as $recurring): ?>
                <tr class="<?= !$recurring['enabled'] ? 'disabled' : '' ?>">
                    <td><strong><?= htmlspecialchars($recurring['description']) ?></strong></td>
                    <td class="amount <?= $recurring['transaction_type'] === 'inflow' ? 'inflow' : 'outflow' ?>">
                        <?= formatCurrency($recurring['amount']) ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $recurring['transaction_type'] === 'inflow' ? 'success' : 'warning' ?>">
                            <?= $recurring['transaction_type'] === 'inflow' ? 'Income' : 'Expense' ?>
                        </span>
                    </td>
                    <td><?= ucfirst($recurring['frequency']) ?></td>
                    <td>
                        <?php
                        $next_date = strtotime($recurring['next_date']);
                        $is_due = $next_date <= time();
                        ?>
                        <span class="<?= $is_due ? 'text-danger font-weight-bold' : '' ?>">
                            <?= date('M j, Y', $next_date) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($recurring['account_name']) ?></td>
                    <td><?= htmlspecialchars($recurring['category_name'] ?? 'Unassigned') ?></td>
                    <td>
                        <?php if ($recurring['enabled']): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Paused</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="POST" action="create.php" style="display: inline;">
                            <input type="hidden" name="recurring_uuid" value="<?= $recurring['recurring_uuid'] ?>">
                            <input type="hidden" name="ledger_uuid" value="<?= $ledger_uuid ?>">
                            <button type="submit" class="btn btn-sm btn-success" <?= !$recurring['enabled'] ? 'disabled' : '' ?>>
                                Create
                            </button>
                        </form>
                        <a href="edit.php?uuid=<?= $recurring['recurring_uuid'] ?>&ledger=<?= $ledger_uuid ?>"
                           class="btn btn-sm btn-secondary">Edit</a>
                        <form method="POST" action="delete.php" style="display: inline;"
                              onsubmit="return confirm('Are you sure you want to delete this recurring transaction?');">
                            <input type="hidden" name="recurring_uuid" value="<?= $recurring['recurring_uuid'] ?>">
                            <input type="hidden" name="ledger_uuid" value="<?= $ledger_uuid ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="help-section">
        <h3>About Recurring Transactions</h3>
        <div class="help-grid">
            <div class="help-item">
                <h4>How It Works</h4>
                <p>Set up transactions that repeat automatically. When the next date arrives, you can create the transaction with a single click.</p>
            </div>
            <div class="help-item">
                <h4>Frequency Options</h4>
                <p>Choose from daily, weekly, biweekly, monthly, or yearly schedules. Perfect for rent, salary, subscriptions, and bills.</p>
            </div>
            <div class="help-item">
                <h4>Manual vs Auto-Create</h4>
                <p>Choose whether transactions are created automatically or require manual confirmation. Manual mode gives you more control.</p>
            </div>
        </div>
    </div>
</div>

<style>
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
}

.alert {
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.alert-info {
    background: #ebf8ff;
    border-left: 4px solid #3182ce;
}

.alert h3 {
    margin: 0 0 0.5rem 0;
    color: #2c5282;
}

.alert p {
    margin: 0 0 1rem 0;
    color: #2d3748;
}

.due-transactions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.due-transaction {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: white;
    border-radius: 6px;
    border: 1px solid #cbd5e0;
}

.due-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.due-details {
    font-size: 0.875rem;
    color: #718096;
}

.recurring-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    overflow: hidden;
    margin-bottom: 2rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f7fafc;
    border-bottom: 2px solid #e2e8f0;
}

.data-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.data-table td {
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.data-table tr:hover {
    background: #f7fafc;
}

.data-table tr.disabled {
    opacity: 0.6;
}

.data-table .amount {
    font-weight: 600;
    font-family: 'Monaco', 'Courier New', monospace;
}

.data-table .amount.inflow {
    color: #38a169;
}

.data-table .amount.outflow {
    color: #e53e3e;
}

.data-table .actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.badge-success {
    background: #c6f6d5;
    color: #22543d;
}

.badge-warning {
    background: #fed7d7;
    color: #742a2a;
}

.badge-secondary {
    background: #e2e8f0;
    color: #4a5568;
}

.text-danger {
    color: #e53e3e;
}

.font-weight-bold {
    font-weight: 700;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #718096;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
}

.empty-state h2 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.empty-state p {
    margin-bottom: 2rem;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

.help-section {
    background: #f7fafc;
    padding: 2rem;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.help-section h3 {
    margin: 0 0 1rem 0;
    color: #2d3748;
}

.help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
}

.help-item h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.help-item p {
    color: #4a5568;
    margin: 0;
    font-size: 0.875rem;
}

@media (max-width: 768px) {
    .header {
        flex-direction: column;
        gap: 1rem;
    }

    .data-table {
        display: block;
        overflow-x: auto;
    }

    .help-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
