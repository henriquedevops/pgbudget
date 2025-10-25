<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

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
        header('Location: list.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get current account balance
    $stmt = $db->prepare("SELECT api.get_account_balance(?)");
    $stmt->execute([$account_uuid]);
    $current_balance = $stmt->fetchColumn();

    // Get balance history (last 100 entries)
    $stmt = $db->prepare("SELECT * FROM api.get_account_balance_history(?, 100)");
    $stmt->execute([$account_uuid]);
    $balance_history = $stmt->fetchAll();

    // Get account transactions for context
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
            END as other_account_name
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
        LIMIT 20
    ");
    $stmt->execute([
        $account_uuid, $account_uuid, // for side determination
        $account_uuid, $account_uuid, // for other account name
        $ledger_uuid, $account_uuid, $account_uuid // for filtering
    ]);
    $recent_transactions = $stmt->fetchAll();

    // Calculate balance statistics
    $balance_values = array_column($balance_history, 'balance');
    $max_balance = !empty($balance_values) ? max($balance_values) : $current_balance;
    $min_balance = !empty($balance_values) ? min($balance_values) : $current_balance;
    $avg_balance = !empty($balance_values) ? array_sum($balance_values) / count($balance_values) : $current_balance;

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1>Balance History: <?= htmlspecialchars($account['name']) ?></h1>
        <p>Balance trends and history for <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="balance-overview">
        <div class="balance-stats">
            <div class="stat-card current">
                <h3>Current Balance</h3>
                <div class="stat-value <?= $current_balance >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatCurrency($current_balance) ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Highest Balance</h3>
                <div class="stat-value <?= $max_balance >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatCurrency($max_balance) ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Lowest Balance</h3>
                <div class="stat-value <?= $min_balance >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatCurrency($min_balance) ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Average Balance</h3>
                <div class="stat-value <?= $avg_balance >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatCurrency($avg_balance) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="actions-bar">
        <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($account_uuid) ?>" class="btn btn-primary">View Transactions</a>
        <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Back to Accounts</a>
    </div>

    <?php if (empty($balance_history)): ?>
        <div class="empty-state">
            <h3>No balance history available</h3>
            <p>Balance history will appear here as transactions are added to this account.</p>
            <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($account_uuid) ?>" class="btn btn-primary">Add First Transaction</a>
        </div>
    <?php else: ?>
        <!-- Balance Chart -->
        <div class="chart-section">
            <h2>Balance Trend</h2>
            <div class="chart-container">
                <canvas id="balanceChart" width="800" height="400"></canvas>
            </div>
        </div>

        <!-- Balance History Table -->
        <div class="history-section">
            <h2>Balance History</h2>
            <div class="history-table">
                <table>
                    <thead>
                        <tr>
                            <th>Transaction #</th>
                            <th>Date</th>
                            <th>Balance</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $prev_balance = null;
                        foreach ($balance_history as $entry):
                            $change = $prev_balance ? $entry['balance'] - $prev_balance : 0;
                            $prev_balance = $entry['balance'];
                        ?>
                            <tr>
                                <td>#<?= $entry['transaction_id'] ?></td>
                                <td><?= date('M j, Y g:i A', strtotime($entry['created_at'])) ?></td>
                                <td class="balance-value <?= $entry['balance'] >= 0 ? 'positive' : 'negative' ?>">
                                    <?= formatCurrency($entry['balance']) ?>
                                </td>
                                <td class="change-value <?= $change > 0 ? 'positive' : ($change < 0 ? 'negative' : 'neutral') ?>">
                                    <?php if ($change != 0): ?>
                                        <?= $change > 0 ? '+' : '' ?><?= formatCurrency($change) ?>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent Transactions Context -->
    <?php if (!empty($recent_transactions)): ?>
        <div class="context-section">
            <h2>Recent Transactions</h2>
            <div class="transactions-preview">
                <?php foreach (array_slice($recent_transactions, 0, 5) as $txn): ?>
                    <div class="transaction-preview">
                        <div class="transaction-info">
                            <div class="transaction-description"><?= htmlspecialchars($txn['description']) ?></div>
                            <div class="transaction-details">
                                <?= date('M j, Y', strtotime($txn['date'])) ?> •
                                <?= htmlspecialchars($txn['other_account_name']) ?>
                            </div>
                        </div>
                        <div class="transaction-amount <?= $txn['side'] === 'debit' ? 'positive' : 'negative' ?>">
                            <?= $txn['side'] === 'debit' ? '+' : '-' ?><?= formatCurrency($txn['amount']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($account_uuid) ?>" class="btn btn-secondary btn-small">View All Transactions</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Chart.js for balance visualization -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (!empty($balance_history)): ?>
// Prepare chart data
const balanceData = <?= json_encode(array_reverse($balance_history)) ?>;
const labels = balanceData.map(entry => {
    const date = new Date(entry.created_at);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
});
const values = balanceData.map(entry => entry.balance / 100); // Convert from cents to dollars

// Create chart
const ctx = document.getElementById('balanceChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Account Balance',
            data: values,
            borderColor: '#3182ce',
            backgroundColor: 'rgba(49, 130, 206, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: false,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toFixed(2);
                    }
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Balance: $' + context.parsed.y.toFixed(2);
                    }
                }
            }
        }
    }
});
<?php endif; ?>
</script>

<style>
.balance-overview {
    margin: 2rem 0;
}

.balance-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.stat-card.current {
    border-left: 4px solid #3182ce;
}

.stat-card h3 {
    margin-bottom: 0.5rem;
    color: #4a5568;
    font-size: 0.875rem;
    text-transform: uppercase;
    font-weight: 600;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: bold;
}

.stat-value.positive {
    color: #38a169;
}

.stat-value.negative {
    color: #e53e3e;
}

.chart-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    margin: 2rem 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-container {
    height: 400px;
    margin-top: 1rem;
}

.history-section {
    background: white;
    border-radius: 8px;
    margin: 2rem 0;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.history-section h2 {
    padding: 1.5rem 2rem 0 2rem;
    margin-bottom: 1rem;
    color: #2d3748;
}

.history-table table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th,
.history-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.history-table th {
    background: #f7fafc;
    font-weight: 600;
    color: #4a5568;
}

.balance-value.positive {
    color: #38a169;
    font-weight: 600;
}

.balance-value.negative {
    color: #e53e3e;
    font-weight: 600;
}

.change-value.positive {
    color: #38a169;
    font-weight: 500;
}

.change-value.negative {
    color: #e53e3e;
    font-weight: 500;
}

.change-value.neutral {
    color: #718096;
}

.context-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    margin: 2rem 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.context-section h2 {
    margin-bottom: 1rem;
    color: #2d3748;
}

.transactions-preview {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.transaction-preview {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 6px;
    border-left: 3px solid #e2e8f0;
}

.transaction-description {
    font-weight: 500;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.transaction-details {
    font-size: 0.875rem;
    color: #718096;
}

.transaction-amount {
    font-weight: 600;
    font-size: 1.1rem;
}

.transaction-amount.positive {
    color: #38a169;
}

.transaction-amount.negative {
    color: #e53e3e;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: #f7fafc;
    border-radius: 8px;
    margin: 2rem 0;
}

.empty-state h3 {
    color: #4a5568;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #718096;
    margin-bottom: 2rem;
}

.actions-bar {
    display: flex;
    gap: 1rem;
    margin: 2rem 0;
}

@media (max-width: 768px) {
    .balance-stats {
        grid-template-columns: 1fr;
    }

    .actions-bar {
        flex-direction: column;
    }

    .chart-container {
        height: 300px;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>