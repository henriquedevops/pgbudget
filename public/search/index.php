<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$query = $_GET['q'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();
    setUserContext($db);

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found or access denied.';
        header('Location: ../index.php');
        exit;
    }

    $results = [
        'transactions' => [],
        'accounts' => [],
        'categories' => []
    ];

    if (!empty($query)) {
        // Search transactions
        $stmt = $db->prepare("
            SELECT t.uuid, t.date, t.description, t.amount,
                   da.name as debit_account, ca.name as credit_account,
                   CASE WHEN da.name = 'Income' THEN 'inflow' ELSE 'outflow' END as type
            FROM data.transactions t
            JOIN data.accounts da ON t.debit_account_id = da.id
            JOIN data.accounts ca ON t.credit_account_id = ca.id
            JOIN data.ledgers l ON t.ledger_id = l.id
            LEFT JOIN data.transaction_log tl ON t.id = tl.original_transaction_id AND tl.mutation_type = 'deletion'
            WHERE l.uuid = ? AND t.deleted_at IS NULL
              AND t.description ILIKE ?
              AND t.description NOT LIKE 'DELETED:%'
              AND t.description NOT LIKE 'REVERSAL:%'
              AND tl.id IS NULL
            ORDER BY t.date DESC
            LIMIT 20
        ");
        $stmt->execute([$ledger_uuid, "%$query%"]);
        $results['transactions'] = $stmt->fetchAll();

        // Search accounts (asset/liability)
        $stmt = $db->prepare("
            SELECT uuid, name, type, description
            FROM api.accounts
            WHERE ledger_uuid = ?
              AND type IN ('asset', 'liability')
              AND name ILIKE ?
            ORDER BY name
            LIMIT 10
        ");
        $stmt->execute([$ledger_uuid, "%$query%"]);
        $results['accounts'] = $stmt->fetchAll();

        // Search categories (equity)
        $stmt = $db->prepare("
            SELECT uuid, name, description
            FROM api.accounts
            WHERE ledger_uuid = ?
              AND type = 'equity'
              AND name ILIKE ?
            ORDER BY name
            LIMIT 10
        ");
        $stmt->execute([$ledger_uuid, "%$query%"]);
        $results['categories'] = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1>🔍 Search</h1>
        <p>Search transactions, accounts, and categories in <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <!-- Global Search Box -->
    <div class="search-box-section">
        <form method="GET" class="global-search-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <div class="search-input-group">
                <input type="text"
                       name="q"
                       id="global-search-input"
                       class="search-input"
                       value="<?= htmlspecialchars($query) ?>"
                       placeholder="Search transactions, accounts, categories..."
                       autofocus>
                <button type="submit" class="btn btn-primary search-btn">
                    🔍 Search
                </button>
            </div>
            <div class="search-help">
                <p>Search by transaction description, account name, or category name</p>
            </div>
        </form>
    </div>

    <?php if (!empty($query)): ?>
        <?php
        $total_results = count($results['transactions']) + count($results['accounts']) + count($results['categories']);
        ?>

        <div class="search-results-summary">
            <h2>Search Results for "<?= htmlspecialchars($query) ?>"</h2>
            <p>Found <?= $total_results ?> result<?= $total_results !== 1 ? 's' : '' ?></p>
        </div>

        <?php if ($total_results === 0): ?>
            <div class="empty-state">
                <h3>No results found</h3>
                <p>Try different search terms or check your spelling.</p>
                <a href="../transactions/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">View All Transactions</a>
            </div>
        <?php else: ?>
            <!-- Transactions Results -->
            <?php if (!empty($results['transactions'])): ?>
                <div class="results-section">
                    <h3>💰 Transactions (<?= count($results['transactions']) ?>)</h3>
                    <div class="transactions-list">
                        <?php foreach ($results['transactions'] as $txn): ?>
                            <div class="result-item transaction-result">
                                <div class="result-main">
                                    <div class="result-title"><?= htmlspecialchars($txn['description']) ?></div>
                                    <div class="result-meta">
                                        <?= date('M j, Y', strtotime($txn['date'])) ?> •
                                        <?= htmlspecialchars($txn['debit_account']) ?> → <?= htmlspecialchars($txn['credit_account']) ?>
                                    </div>
                                </div>
                                <div class="result-action">
                                    <div class="result-amount <?= $txn['type'] === 'inflow' ? 'positive' : 'negative' ?>">
                                        <?= $txn['type'] === 'inflow' ? '+' : '-' ?><?= formatCurrency($txn['amount']) ?>
                                    </div>
                                    <a href="../transactions/edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>"
                                       class="btn btn-small btn-secondary">View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($results['transactions']) >= 20): ?>
                        <p class="more-results">Showing first 20 results. <a href="../transactions/list.php?ledger=<?= urlencode($ledger_uuid) ?>&search=<?= urlencode($query) ?>">View all transaction results</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Accounts Results -->
            <?php if (!empty($results['accounts'])): ?>
                <div class="results-section">
                    <h3>🏦 Accounts (<?= count($results['accounts']) ?>)</h3>
                    <div class="accounts-list">
                        <?php foreach ($results['accounts'] as $acc): ?>
                            <div class="result-item account-result">
                                <div class="result-main">
                                    <div class="result-title"><?= htmlspecialchars($acc['name']) ?></div>
                                    <div class="result-meta">
                                        <span class="account-type-badge <?= $acc['type'] ?>"><?= ucfirst($acc['type']) ?></span>
                                        <?php if ($acc['description']): ?>
                                            • <?= htmlspecialchars($acc['description']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="result-action">
                                    <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($acc['uuid']) ?>"
                                       class="btn btn-small btn-secondary">View Transactions</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Categories Results -->
            <?php if (!empty($results['categories'])): ?>
                <div class="results-section">
                    <h3>📁 Categories (<?= count($results['categories']) ?>)</h3>
                    <div class="categories-list">
                        <?php foreach ($results['categories'] as $cat): ?>
                            <div class="result-item category-result">
                                <div class="result-main">
                                    <div class="result-title"><?= htmlspecialchars($cat['name']) ?></div>
                                    <?php if ($cat['description']): ?>
                                        <div class="result-meta"><?= htmlspecialchars($cat['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="result-action">
                                    <a href="../transactions/assign.php?ledger=<?= urlencode($ledger_uuid) ?>&category=<?= urlencode($cat['uuid']) ?>"
                                       class="btn btn-small btn-secondary">Assign Budget</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- Suggestions when no search query -->
        <div class="search-suggestions">
            <h3>💡 Quick Actions</h3>
            <div class="suggestion-links">
                <a href="../transactions/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="suggestion-card">
                    <div class="suggestion-icon">📋</div>
                    <div class="suggestion-title">View All Transactions</div>
                    <div class="suggestion-description">Browse complete transaction history</div>
                </a>

                <a href="../accounts/list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="suggestion-card">
                    <div class="suggestion-icon">🏦</div>
                    <div class="suggestion-title">View Accounts</div>
                    <div class="suggestion-description">See all asset and liability accounts</div>
                </a>

                <a href="../categories/manage.php?ledger=<?= urlencode($ledger_uuid) ?>" class="suggestion-card">
                    <div class="suggestion-icon">📁</div>
                    <div class="suggestion-title">Manage Categories</div>
                    <div class="suggestion-description">View and edit budget categories</div>
                </a>

                <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="suggestion-card">
                    <div class="suggestion-icon">📊</div>
                    <div class="suggestion-title">Budget Dashboard</div>
                    <div class="suggestion-description">Return to budget overview</div>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.search-box-section {
    background: white;
    padding: 2rem;
    border-radius: 12px;
    margin: 2rem 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.global-search-form {
    max-width: 800px;
    margin: 0 auto;
}

.search-input-group {
    display: flex;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.search-input {
    flex: 1;
    padding: 0.875rem 1.25rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.search-btn {
    padding: 0.875rem 1.5rem;
    white-space: nowrap;
}

.search-help {
    text-align: center;
    color: #718096;
    font-size: 0.875rem;
}

.search-results-summary {
    margin: 2rem 0 1rem 0;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.search-results-summary h2 {
    margin-bottom: 0.5rem;
    color: #2d3748;
}

.search-results-summary p {
    color: #718096;
    margin: 0;
}

.results-section {
    margin: 2rem 0;
}

.results-section h3 {
    margin-bottom: 1rem;
    color: #2d3748;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.transactions-list,
.accounts-list,
.categories-list {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.result-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    transition: background-color 0.2s;
}

.result-item:last-child {
    border-bottom: none;
}

.result-item:hover {
    background-color: #f7fafc;
}

.result-main {
    flex: 1;
}

.result-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.result-meta {
    font-size: 0.875rem;
    color: #718096;
}

.result-action {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.result-amount {
    font-weight: 600;
    font-size: 1.1rem;
    white-space: nowrap;
}

.result-amount.positive {
    color: #38a169;
}

.result-amount.negative {
    color: #e53e3e;
}

.account-type-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.account-type-badge.asset {
    background: #c6f6d5;
    color: #2f855a;
}

.account-type-badge.liability {
    background: #fed7d7;
    color: #c53030;
}

.more-results {
    margin-top: 1rem;
    text-align: center;
    color: #718096;
    font-size: 0.875rem;
}

.more-results a {
    color: #3182ce;
    font-weight: 600;
    text-decoration: none;
}

.more-results a:hover {
    text-decoration: underline;
}

.search-suggestions {
    margin: 3rem 0;
}

.search-suggestions h3 {
    margin-bottom: 1.5rem;
    color: #2d3748;
}

.suggestion-links {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.suggestion-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
    border: 1px solid #e2e8f0;
}

.suggestion-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: #3182ce;
}

.suggestion-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.suggestion-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.suggestion-description {
    font-size: 0.875rem;
    color: #718096;
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

@media (max-width: 768px) {
    .search-input-group {
        flex-direction: column;
    }

    .result-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .result-action {
        width: 100%;
        justify-content: space-between;
    }

    .suggestion-links {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
