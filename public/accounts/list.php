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

    // Get all account balances for this ledger
    $stmt = $db->prepare("SELECT * FROM api.get_ledger_balances(?)");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>Accounts</h1>
            <p>All accounts in <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ Add Account</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <div class="accounts-section">
        <?php if (empty($accounts)): ?>
            <div class="empty-state">
                <h3>No accounts found</h3>
                <p>Create your first account to get started with budget tracking.</p>
                <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Account</a>
            </div>
        <?php else: ?>
            <!-- Group accounts by type -->
            <?php
            $account_groups = [];
            foreach ($accounts as $account) {
                $account_groups[$account['account_type']][] = $account;
            }

            // Define display order and descriptions
            $type_info = [
                'asset' => ['title' => 'Asset Accounts', 'description' => 'Bank accounts, cash, investments - money you own'],
                'liability' => ['title' => 'Liability Accounts', 'description' => 'Credit cards, loans - money you owe'],
                'equity' => ['title' => 'Budget Categories', 'description' => 'Your budget categories and special accounts']
            ];
            ?>

            <?php foreach ($type_info as $type => $info): ?>
                <?php if (isset($account_groups[$type])): ?>
                    <div class="account-group">
                        <div class="group-header">
                            <h2><?= $info['title'] ?></h2>
                            <p><?= $info['description'] ?></p>
                        </div>

                        <table class="table accounts-table">
                            <thead>
                                <tr>
                                    <th>Account Name</th>
                                    <th>Type</th>
                                    <th>Current Balance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($account_groups[$type] as $account): ?>
                                    <tr>
                                        <td>
                                            <a href="../transactions/account.php?ledger=<?= $ledger_uuid ?>&account=<?= $account['account_uuid'] ?>" class="account-name">
                                                <?= htmlspecialchars($account['account_name']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="account-type <?= $account['account_type'] ?>">
                                                <?= ucfirst($account['account_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="amount <?= $account['current_balance'] > 0 ? 'positive' : ($account['current_balance'] < 0 ? 'negative' : 'zero') ?>">
                                                <?= formatCurrency($account['current_balance']) ?>
                                            </span>
                                        </td>
                                        <td class="account-actions">
                                            <a href="../transactions/account.php?ledger=<?= $ledger_uuid ?>&account=<?= $account['account_uuid'] ?>" class="btn btn-small btn-secondary">View</a>
                                            <a href="balance-history.php?ledger=<?= $ledger_uuid ?>&account=<?= $account['account_uuid'] ?>" class="btn btn-small btn-info" title="Balance History">📊</a>
                                            <?php if ($account['account_type'] === 'equity' && !in_array($account['account_name'], ['Income', 'Off-budget', 'Unassigned'])): ?>
                                                <a href="../transactions/assign.php?ledger=<?= $ledger_uuid ?>&category=<?= $account['account_uuid'] ?>" class="btn btn-small btn-primary">Assign</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Summary card -->
            <div class="balance-summary">
                <h3>Balance Summary</h3>
                <div class="summary-grid">
                    <?php
                    $total_assets = array_sum(array_column(array_filter($accounts, fn($a) => $a['account_type'] === 'asset'), 'current_balance'));
                    $total_liabilities = array_sum(array_column(array_filter($accounts, fn($a) => $a['account_type'] === 'liability'), 'current_balance'));
                    $total_equity = array_sum(array_column(array_filter($accounts, fn($a) => $a['account_type'] === 'equity'), 'current_balance'));
                    $net_worth = $total_assets - $total_liabilities;
                    ?>
                    <div class="summary-item">
                        <span class="summary-label">Total Assets:</span>
                        <span class="summary-value amount positive"><?= formatCurrency($total_assets) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Total Liabilities:</span>
                        <span class="summary-value amount negative"><?= formatCurrency($total_liabilities) ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Net Worth:</span>
                        <span class="summary-value amount <?= $net_worth > 0 ? 'positive' : ($net_worth < 0 ? 'negative' : 'zero') ?>">
                            <?= formatCurrency($net_worth) ?>
                        </span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Budget Balance:</span>
                        <span class="summary-value amount <?= $total_equity > 0 ? 'positive' : ($total_equity < 0 ? 'negative' : 'zero') ?>">
                            <?= formatCurrency($total_equity) ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.page-title h1 {
    margin: 0;
    color: #2d3748;
}

.page-title p {
    color: #718096;
    margin: 0.5rem 0 0 0;
}

.page-actions {
    display: flex;
    gap: 1rem;
}

.account-group {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.group-header {
    margin-bottom: 1.5rem;
}

.group-header h2 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
}

.group-header p {
    margin: 0;
    color: #718096;
    font-size: 0.875rem;
}

.accounts-table .account-name {
    color: #2b6cb0;
    text-decoration: none;
    font-weight: 500;
}

.accounts-table .account-name:hover {
    text-decoration: underline;
}

.account-type {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
}

.account-type.asset {
    background-color: #c6f6d5;
    color: #22543d;
}

.account-type.liability {
    background-color: #fed7d7;
    color: #742a2a;
}

.account-type.equity {
    background-color: #bee3f8;
    color: #2a4365;
}

.account-actions {
    display: flex;
    gap: 0.5rem;
}

.balance-summary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.balance-summary h3 {
    margin: 0 0 1rem 0;
    color: white;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
}

.summary-label {
    font-weight: 500;
}

.summary-value {
    font-weight: 600;
    font-size: 1.1rem;
}

.summary-value.amount.positive {
    color: #9ae6b4;
}

.summary-value.amount.negative {
    color: #feb2b2;
}

.summary-value.amount.zero {
    color: #cbd5e0;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 1rem;
    }

    .page-actions {
        width: 100%;
    }

    .page-actions a {
        flex: 1;
        text-align: center;
    }

    .account-actions {
        flex-direction: column;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>