<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$account_uuid = $_GET['account'] ?? '';

if (empty($account_uuid)) {
    $_SESSION['error'] = 'No account specified.';
    header('Location: list.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get account details
    $stmt = $db->prepare("SELECT * FROM api.accounts WHERE uuid = ?");
    $stmt->execute([$account_uuid]);
    $account = $stmt->fetch();

    if (!$account) {
        $_SESSION['error'] = 'Account not found.';
        header('Location: list.php');
        exit;
    }

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$account['ledger_uuid']]);
    $ledger = $stmt->fetch();

    // Get current account balance
    $account_balance = $account['balance'];

    // Get reconciliation history
    $stmt = $db->prepare("SELECT * FROM api.get_reconciliation_history(?)");
    $stmt->execute([$account_uuid]);
    $reconciliation_history = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: list.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="reconcile-header">
        <div>
            <h1>Reconcile: <?= htmlspecialchars($account['name']) ?></h1>
            <p class="account-info">
                <strong>Type:</strong> <?= ucfirst($account['type']) ?> &nbsp;|&nbsp;
                <strong>Ledger:</strong> <?= htmlspecialchars($ledger['name']) ?>
            </p>
        </div>
        <div class="reconcile-actions">
            <a href="list.php?ledger=<?= urlencode($account['ledger_uuid']) ?>" class="btn btn-secondary">← Back to Accounts</a>
        </div>
    </div>

    <!-- Current Balance Display -->
    <div class="current-balance-card">
        <div class="balance-label">Current PGBudget Balance</div>
        <div class="balance-amount <?= $account_balance >= 0 ? 'positive' : 'negative' ?>">
            <?= formatCurrency($account_balance) ?>
        </div>
        <div class="balance-hint">This is your current balance in PGBudget</div>
    </div>

    <!-- Reconciliation Form -->
    <div class="reconcile-form-card">
        <h2>Start New Reconciliation</h2>
        <p class="form-description">
            Reconciling ensures your PGBudget balance matches your real-world statement.
            Enter your statement balance and select which transactions have cleared.
        </p>

        <form id="reconcile-form">
            <input type="hidden" id="account-uuid" value="<?= htmlspecialchars($account_uuid) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="statement-date" class="form-label">Statement Date *</label>
                    <input type="date" id="statement-date" class="form-input" required value="<?= date('Y-m-d') ?>">
                    <span class="form-help">Date of your bank/credit card statement</span>
                </div>

                <div class="form-group">
                    <label for="statement-balance" class="form-label">Statement Balance *</label>
                    <input type="text" id="statement-balance" class="form-input" placeholder="0.00" required>
                    <span class="form-help">Balance shown on your statement</span>
                </div>
            </div>

            <div class="form-group">
                <label for="reconcile-notes" class="form-label">Notes (Optional)</label>
                <textarea id="reconcile-notes" class="form-textarea" rows="2" placeholder="Any notes about this reconciliation..."></textarea>
            </div>

            <div class="reconcile-summary" id="reconcile-summary" style="display: none;">
                <h3>Reconciliation Summary</h3>
                <div class="summary-row">
                    <span>Statement Balance:</span>
                    <span id="summary-statement-balance" class="summary-value"></span>
                </div>
                <div class="summary-row">
                    <span>PGBudget Balance:</span>
                    <span id="summary-pgbudget-balance" class="summary-value"><?= formatCurrency($account_balance) ?></span>
                </div>
                <div class="summary-row difference-row">
                    <span>Difference:</span>
                    <span id="summary-difference" class="summary-value"></span>
                </div>
                <div id="difference-explanation" class="difference-explanation" style="display: none;">
                    <p id="difference-text"></p>
                </div>
            </div>

            <button type="button" id="load-transactions-btn" class="btn btn-primary btn-block">
                Load Uncleared Transactions
            </button>
        </form>
    </div>

    <!-- Transactions List (loaded dynamically) -->
    <div id="transactions-section" class="transactions-section" style="display: none;">
        <h2>Mark Cleared Transactions</h2>
        <p class="section-description">
            Select the transactions that appear on your statement. These will be marked as cleared.
        </p>

        <div class="transactions-actions">
            <button type="button" id="select-all-btn" class="btn btn-small btn-secondary">Select All</button>
            <button type="button" id="deselect-all-btn" class="btn btn-small btn-secondary">Deselect All</button>
            <span class="transactions-count">
                <span id="selected-count">0</span> of <span id="total-count">0</span> selected
            </span>
        </div>

        <div id="transactions-list" class="transactions-list">
            <!-- Populated by JavaScript -->
        </div>

        <div class="reconcile-actions-bottom">
            <button type="button" id="cancel-reconcile-btn" class="btn btn-secondary">Cancel</button>
            <button type="submit" id="complete-reconcile-btn" class="btn btn-success">Complete Reconciliation</button>
        </div>
    </div>

    <!-- Reconciliation History -->
    <?php if (!empty($reconciliation_history)): ?>
        <div class="reconciliation-history-section">
            <h2>Reconciliation History</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Statement Balance</th>
                        <th>PGBudget Balance</th>
                        <th>Difference</th>
                        <th>Notes</th>
                        <th>Reconciled On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reconciliation_history as $recon): ?>
                        <tr>
                            <td><?= date('M j, Y', strtotime($recon['reconciliation_date'])) ?></td>
                            <td class="amount"><?= formatCurrency($recon['statement_balance']) ?></td>
                            <td class="amount"><?= formatCurrency($recon['pgbudget_balance']) ?></td>
                            <td class="amount <?= $recon['difference'] > 0 ? 'positive' : ($recon['difference'] < 0 ? 'negative' : 'zero') ?>">
                                <?= formatCurrency($recon['difference']) ?>
                                <?php if ($recon['difference'] != 0 && $recon['adjustment_transaction_uuid']): ?>
                                    <span class="adjustment-badge" title="Adjustment created">⚙️</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($recon['notes'] ?? '-') ?></td>
                            <td><?= date('M j, Y g:i A', strtotime($recon['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.reconcile-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.reconcile-header h1 {
    margin: 0;
    color: #2d3748;
}

.account-info {
    color: #718096;
    margin: 0.5rem 0 0 0;
    font-size: 0.875rem;
}

.current-balance-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    text-align: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.balance-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.balance-amount {
    font-size: 3rem;
    font-weight: bold;
    margin-bottom: 0.5rem;
}

.balance-hint {
    font-size: 0.875rem;
    opacity: 0.9;
}

.reconcile-form-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.reconcile-form-card h2 {
    margin-top: 0;
    color: #2d3748;
}

.form-description {
    color: #718096;
    margin-bottom: 1.5rem;
    line-height: 1.6;
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
.form-textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
    transition: border-color 0.2s;
}

.form-input:focus,
.form-textarea:focus {
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

.btn-block {
    width: 100%;
}

.reconcile-summary {
    background: #f7fafc;
    border-radius: 8px;
    padding: 1.5rem;
    margin: 1.5rem 0;
    border-left: 4px solid #3182ce;
}

.reconcile-summary h3 {
    margin: 0 0 1rem 0;
    font-size: 1.1rem;
    color: #2d3748;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.summary-row:last-child {
    border-bottom: none;
}

.difference-row {
    font-weight: 600;
    font-size: 1.1rem;
    padding-top: 1rem;
    margin-top: 0.5rem;
    border-top: 2px solid #cbd5e0;
}

.summary-value {
    font-weight: 600;
}

.difference-explanation {
    margin-top: 1rem;
    padding: 1rem;
    background: #fffbeb;
    border-left: 4px solid #f59e0b;
    border-radius: 4px;
}

.difference-explanation p {
    margin: 0;
    color: #78350f;
    line-height: 1.5;
}

.transactions-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.transactions-section h2 {
    margin-top: 0;
    color: #2d3748;
}

.section-description {
    color: #718096;
    margin-bottom: 1.5rem;
}

.transactions-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.transactions-count {
    margin-left: auto;
    font-size: 0.875rem;
    color: #718096;
    font-weight: 600;
}

.transactions-list {
    max-height: 500px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}

.transaction-item {
    display: flex;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background-color 0.2s;
}

.transaction-item:last-child {
    border-bottom: none;
}

.transaction-item:hover {
    background-color: #f7fafc;
}

.transaction-item.selected {
    background-color: #ebf8ff;
}

.transaction-checkbox {
    margin-right: 1rem;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.transaction-details {
    flex: 1;
}

.transaction-description {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.transaction-meta {
    font-size: 0.75rem;
    color: #718096;
}

.transaction-amount {
    font-weight: 600;
    font-size: 1rem;
    white-space: nowrap;
}

.transaction-amount.debit {
    color: #e53e3e;
}

.transaction-amount.credit {
    color: #38a169;
}

.reconcile-actions-bottom {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.reconcile-actions-bottom button {
    flex: 1;
}

.reconciliation-history-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.reconciliation-history-section h2 {
    margin-top: 0;
    color: #2d3748;
}

.adjustment-badge {
    font-size: 0.875rem;
    margin-left: 0.25rem;
    cursor: help;
}

/* Loading State */
.loading {
    text-align: center;
    padding: 2rem;
    color: #718096;
}

.loading::after {
    content: '...';
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0%, 20% { content: '.'; }
    40% { content: '..'; }
    60%, 100% { content: '...'; }
}

/* Notification */
.notification {
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

.notification.show {
    opacity: 1;
    transform: translateY(0);
}

.notification.success {
    background-color: #38a169;
    color: white;
}

.notification.error {
    background-color: #e53e3e;
    color: white;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .reconcile-header {
        flex-direction: column;
        gap: 1rem;
    }

    .balance-amount {
        font-size: 2rem;
    }

    .notification {
        right: 1rem;
        left: 1rem;
    }
}
</style>

<!-- Include reconciliation JavaScript -->
<script src="../js/reconcile-account.js"></script>

<?php require_once '../../includes/footer.php'; ?>
