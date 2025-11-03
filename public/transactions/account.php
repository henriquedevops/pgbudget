<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';
require_once '../../includes/help-icon.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$account_uuid = $_GET['account'] ?? '';

if (empty($ledger_uuid) || empty($account_uuid)) {
    $_SESSION['error'] = 'Invalid ledger or account specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();
    setUserContext($db);

    // Get ledger details to verify access
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found or access denied.';
        header('Location: ../index.php');
        exit;
    }

    // Get account details to verify access and display
    $stmt = $db->prepare("SELECT * FROM api.accounts WHERE uuid = ? AND ledger_uuid = ?");
    $stmt->execute([$account_uuid, $ledger_uuid]);
    $account = $stmt->fetch();

    if (!$account) {
        $_SESSION['error'] = 'Account not found or access denied.';
        header('Location: ../accounts/list.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get transactions for this account
    // Since this is a double-entry system, an account can be either the debit or credit side
    $stmt = $db->prepare("
        SELECT
            t.uuid,
            t.description,
            t.amount,
            t.date,
            t.created_at,
            -- Determine if this account is on the debit or credit side
            CASE
                WHEN da.uuid = ? THEN 'debit'
                WHEN ca.uuid = ? THEN 'credit'
            END as side,
            -- Get the other account involved in the transaction
            CASE
                WHEN da.uuid = ? THEN ca.name
                WHEN ca.uuid = ? THEN da.name
            END as other_account_name,
            CASE
                WHEN da.uuid = ? THEN ca.type
                WHEN ca.uuid = ? THEN da.type
            END as other_account_type
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        WHERE l.uuid = ?
          AND (da.uuid = ? OR ca.uuid = ?)
          AND t.deleted_at IS NULL
          AND t.description NOT LIKE 'DELETED:%'
          AND t.description NOT LIKE 'REVERSAL:%'
        ORDER BY t.date DESC, t.created_at DESC
    ");
    $stmt->execute([
        $account_uuid, $account_uuid, // for side determination
        $account_uuid, $account_uuid, // for other account name
        $account_uuid, $account_uuid, // for other account type
        $ledger_uuid, $account_uuid, $account_uuid // for filtering
    ]);
    $transactions = $stmt->fetchAll();

    // Calculate running balance (simplified - would need more complex logic for proper accounting)
    $balance = 0;
    $transactions = array_reverse($transactions); // Reverse to oldest first
    foreach ($transactions as &$transaction) {
        if ($transaction['side'] === 'debit') {
            if (in_array($account['type'], ['asset', 'expense'])) {
                $balance += $transaction['amount']; // Debit increases assets/expenses
            } else {
                $balance -= $transaction['amount']; // Debit decreases liabilities/equity/revenue
            }
        } else { // credit
            if (in_array($account['type'], ['asset', 'expense'])) {
                $balance -= $transaction['amount']; // Credit decreases assets/expenses
            } else {
                $balance += $transaction['amount']; // Credit increases liabilities/equity/revenue
            }
        }
        $transaction['running_balance'] = $balance;
    }
    unset($transaction); // Break the reference
    $transactions = array_reverse($transactions); // Restore original order (newest first)

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1><?= htmlspecialchars($account['name']) ?></h1>
        <p>
            <strong><?= htmlspecialchars($account['type']) ?></strong> account in
            <strong><?= htmlspecialchars($ledger['name']) ?></strong>
            <?php if ($account['description']): ?>
                <br><small><?= htmlspecialchars($account['description']) ?></small>
            <?php endif; ?>
        </p>
    </div>

    <div class="account-summary">
        <div class="balance-card">
            <h3>Current Balance</h3>
            <div class="balance-amount <?= $balance >= 0 ? 'positive' : 'negative' ?>">
                <?= formatCurrency($balance) ?>
            </div>
        </div>
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-label">Total Transactions</span>
                <span class="stat-value"><?= count($transactions) ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Account Type</span>
                <span class="stat-value account-type-badge <?= $account['type'] ?>"><?= ucfirst($account['type']) ?></span>
            </div>
        </div>
    </div>

    <div class="actions-bar">
        <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($account_uuid) ?>" class="btn btn-primary">
            + Add Transaction
        </a>
        <?php if (in_array($account['type'], ['asset', 'liability'])): ?>
            <button type="button" class="btn btn-primary" onclick="TransferModal.open({ledger_uuid: '<?= htmlspecialchars($ledger_uuid) ?>', from_account_uuid: '<?= htmlspecialchars($account_uuid) ?>'})">
                ⇄ Transfer Money
            </button>
        <?php endif; ?>
        <a href="../accounts/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">
            Back to Accounts
        </a>
    </div>

    <div class="transactions-section">
        <h2>Transaction History</h2>

        <?php if (empty($transactions)): ?>
            <div class="empty-state">
                <h3>No transactions yet</h3>
                <p>This account doesn't have any transactions. Start by adding your first transaction.</p>
                <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($account_uuid) ?>" class="btn btn-primary">
                    Add First Transaction
                </a>
            </div>
        <?php else: ?>
            <div class="transactions-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Other Account</th>
                            <th>Amount</th>
                            <th>Running Balance <?php renderHelpIcon("The account balance after each transaction, calculated chronologically."); ?></th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr class="transaction-row">
                                <td class="date-cell">
                                    <?= date('M j, Y', strtotime($transaction['date'])) ?>
                                </td>
                                <td class="description-cell">
                                    <?= htmlspecialchars($transaction['description']) ?>
                                </td>
                                <td class="account-cell">
                                    <span class="account-name"><?= htmlspecialchars($transaction['other_account_name']) ?></span>
                                    <small class="account-type"><?= ucfirst($transaction['other_account_type']) ?></small>
                                </td>
                                <td class="amount-cell">
                                    <span class="amount <?= $transaction['side'] === 'debit' ? 'debit' : 'credit' ?>">
                                        <?= $transaction['side'] === 'debit' ? '+' : '-' ?><?= formatCurrency($transaction['amount']) ?>
                                    </span>
                                    <small class="side-label"><?= ucfirst($transaction['side']) ?></small>
                                </td>
                                <td class="balance-cell">
                                    <span class="running-balance <?= $transaction['running_balance'] >= 0 ? 'positive' : 'negative' ?>">
                                        <?= formatCurrency($transaction['running_balance']) ?>
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a href="edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($transaction['uuid']) ?>" class="btn btn-small btn-edit" title="Edit Transaction">✏️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.account-summary {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 2rem;
    margin: 2rem 0;
}

.balance-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    text-align: center;
}

.balance-card h3 {
    margin: 0 0 1rem 0;
    opacity: 0.9;
}

.balance-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin: 0;
}

.balance-amount.positive {
    color: #48bb78;
}

.balance-amount.negative {
    color: #f56565;
}

.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.stat-item {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    text-align: center;
}

.stat-label {
    display: block;
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.5rem;
}

.stat-value {
    display: block;
    font-size: 1.5rem;
    font-weight: bold;
    color: #2d3748;
}

.account-type-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem !important;
    font-weight: 600 !important;
}

.account-type-badge.asset { background: #c6f6d5; color: #2f855a; }
.account-type-badge.liability { background: #fed7d7; color: #c53030; }
.account-type-badge.equity { background: #e6fffa; color: #319795; }
.account-type-badge.revenue { background: #d6f5d6; color: #38a169; }
.account-type-badge.expense { background: #feebc8; color: #dd6b20; }

.actions-bar {
    display: flex;
    gap: 1rem;
    margin: 2rem 0;
}

.transactions-section {
    margin-top: 3rem;
}

.transactions-section h2 {
    margin-bottom: 1.5rem;
    color: #2d3748;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: #f7fafc;
    border-radius: 8px;
}

.empty-state h3 {
    color: #4a5568;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #718096;
    margin-bottom: 2rem;
}

.transactions-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.transactions-table table {
    width: 100%;
    border-collapse: collapse;
}

.transactions-table th,
.transactions-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.transactions-table th {
    background: #f7fafc;
    font-weight: 600;
    color: #4a5568;
}

.transaction-row:hover {
    background: #f7fafc;
}

.date-cell {
    color: #718096;
    font-size: 0.875rem;
}

.description-cell {
    font-weight: 500;
}

.account-cell .account-name {
    display: block;
    font-weight: 500;
}

.account-cell .account-type {
    color: #718096;
    font-size: 0.75rem;
}

.amount-cell .amount {
    display: block;
    font-weight: 600;
    font-size: 1.1rem;
}

.amount-cell .amount.debit {
    color: #38a169;
}

.amount-cell .amount.credit {
    color: #e53e3e;
}

.amount-cell .side-label {
    color: #718096;
    font-size: 0.75rem;
}

.balance-cell .running-balance {
    font-weight: 600;
}

.balance-cell .running-balance.positive {
    color: #38a169;
}

.balance-cell .running-balance.negative {
    color: #e53e3e;
}

@media (max-width: 768px) {
    .account-summary {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .actions-bar {
        flex-direction: column;
    }

    .transactions-table {
        overflow-x: auto;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>