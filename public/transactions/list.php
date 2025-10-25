<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$search = $_GET['search'] ?? '';
$account_filter = $_GET['account'] ?? '';
$category_filter = $_GET['category'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'date_desc';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
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

    // Get accounts for filter dropdown
    $stmt = $db->prepare("SELECT uuid, name, type FROM api.accounts WHERE ledger_uuid = ? ORDER BY name");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Build search query
    $where_conditions = ["l.uuid = ?"];
    $params = [$ledger_uuid];

    // Text search in description
    if (!empty($search)) {
        $where_conditions[] = "t.description ILIKE ?";
        $params[] = "%$search%";
    }

    // Account filter
    if (!empty($account_filter)) {
        $where_conditions[] = "(da.uuid = ? OR ca.uuid = ?)";
        $params[] = $account_filter;
        $params[] = $account_filter;
    }

    // Category filter
    if (!empty($category_filter)) {
        $where_conditions[] = "(da.uuid = ? OR ca.uuid = ?)";
        $params[] = $category_filter;
        $params[] = $category_filter;
    }

    // Type filter
    if (!empty($type_filter)) {
        if ($type_filter === 'inflow') {
            $where_conditions[] = "da.name = 'Income'";
        } elseif ($type_filter === 'outflow') {
            $where_conditions[] = "da.name != 'Income'";
        }
    }

    // Date range filter
    if (!empty($date_from)) {
        $where_conditions[] = "t.date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where_conditions[] = "t.date <= ?";
        $params[] = $date_to;
    }

    // Amount range filter (convert to cents)
    if (!empty($amount_min)) {
        $min_cents = floatval($amount_min) * 100;
        $where_conditions[] = "t.amount >= ?";
        $params[] = $min_cents;
    }

    if (!empty($amount_max)) {
        $max_cents = floatval($amount_max) * 100;
        $where_conditions[] = "t.amount <= ?";
        $params[] = $max_cents;
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Determine sort order
    $order_by = match($sort_by) {
        'date_asc' => 'ORDER BY t.date ASC, t.created_at ASC',
        'date_desc' => 'ORDER BY t.date DESC, t.created_at DESC',
        'amount_asc' => 'ORDER BY t.amount ASC, t.date DESC',
        'amount_desc' => 'ORDER BY t.amount DESC, t.date DESC',
        'description' => 'ORDER BY t.description ASC, t.date DESC',
        default => 'ORDER BY t.date DESC, t.created_at DESC'
    };

    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*)
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        WHERE $where_clause AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
    ";
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_transactions = $stmt->fetchColumn();
    $total_pages = ceil($total_transactions / $per_page);

    // Get transactions with pagination
    $transactions_query = "
        SELECT t.uuid, t.date, t.description, t.amount, t.created_at,
               da.name as debit_account, da.uuid as debit_uuid, da.type as debit_type,
               ca.name as credit_account, ca.uuid as credit_uuid, ca.type as credit_type,
               CASE
                   WHEN da.name = 'Income' THEN 'inflow'
                   ELSE 'outflow'
               END as type,
               ip.uuid as installment_plan_uuid,
               ip.description as installment_plan_description,
               ip.number_of_installments,
               ip.completed_installments,
               ip.status as installment_plan_status
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        LEFT JOIN data.installment_plans ip ON t.id = ip.original_transaction_id
        WHERE $where_clause AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
        $order_by
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($transactions_query);
    $stmt->execute([...$params, $per_page, $offset]);
    $transactions = $stmt->fetchAll();

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1>All Transactions</h1>
        <p>Complete transaction history for <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <!-- Search and Filters -->
    <div class="filters-section">
        <form method="GET" class="filters-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <div class="filters-row">
                <div class="filter-group">
                    <label for="search">Search Description</label>
                    <input type="text"
                           id="search"
                           name="search"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search transactions...">
                </div>

                <div class="filter-group">
                    <label for="type">Transaction Type</label>
                    <select id="type" name="type">
                        <option value="">All Types</option>
                        <option value="inflow" <?= $type_filter === 'inflow' ? 'selected' : '' ?>>Income (Inflow)</option>
                        <option value="outflow" <?= $type_filter === 'outflow' ? 'selected' : '' ?>>Expense (Outflow)</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="account">Account</label>
                    <select id="account" name="account">
                        <option value="">All Accounts</option>
                        <?php foreach ($accounts as $account): ?>
                            <?php if ($account['type'] !== 'equity'): ?>
                                <option value="<?= $account['uuid'] ?>" <?= $account_filter === $account['uuid'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($account['name']) ?> (<?= ucfirst($account['type']) ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filters-row">
                <div class="filter-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($accounts as $account): ?>
                            <?php if ($account['type'] === 'equity'): ?>
                                <option value="<?= $account['uuid'] ?>" <?= $category_filter === $account['uuid'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($account['name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
            </div>

            <div class="filters-row">
                <div class="filter-group">
                    <label for="amount_min">Min Amount ($)</label>
                    <input type="number"
                           id="amount_min"
                           name="amount_min"
                           step="0.01"
                           min="0"
                           value="<?= htmlspecialchars($amount_min) ?>"
                           placeholder="0.00">
                </div>

                <div class="filter-group">
                    <label for="amount_max">Max Amount ($)</label>
                    <input type="number"
                           id="amount_max"
                           name="amount_max"
                           step="0.01"
                           min="0"
                           value="<?= htmlspecialchars($amount_max) ?>"
                           placeholder="0.00">
                </div>

                <div class="filter-group">
                    <label for="sort_by">Sort By</label>
                    <select id="sort_by" name="sort_by">
                        <option value="date_desc" <?= $sort_by === 'date_desc' ? 'selected' : '' ?>>Date (Newest First)</option>
                        <option value="date_asc" <?= $sort_by === 'date_asc' ? 'selected' : '' ?>>Date (Oldest First)</option>
                        <option value="amount_desc" <?= $sort_by === 'amount_desc' ? 'selected' : '' ?>>Amount (High to Low)</option>
                        <option value="amount_asc" <?= $sort_by === 'amount_asc' ? 'selected' : '' ?>>Amount (Low to High)</option>
                        <option value="description" <?= $sort_by === 'description' ? 'selected' : '' ?>>Description (A-Z)</option>
                    </select>
                </div>
            </div>

            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Clear All</a>
            </div>
        </form>
    </div>

    <!-- Results Summary -->
    <div class="results-summary">
        <div class="results-info">
            Showing <?= number_format($total_transactions) ?> transactions
            <?php if ($total_transactions > $per_page): ?>
                (Page <?= $page ?> of <?= $total_pages ?>)
            <?php endif; ?>
        </div>

        <div class="results-actions">
            <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">+ Add Transaction</a>
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <!-- Bulk Action Bar (hidden by default) -->
    <div id="bulk-action-bar" class="hidden">
        <div class="bulk-action-info">
            <span id="selected-count">0</span> transaction(s) selected
        </div>
        <div class="bulk-action-buttons">
            <button id="bulk-categorize-btn" class="bulk-action-btn">
                📂 Categorize
            </button>
            <button id="bulk-edit-date-btn" class="bulk-action-btn">
                📅 Edit Date
            </button>
            <button id="bulk-edit-account-btn" class="bulk-action-btn">
                💳 Change Account
            </button>
            <button id="bulk-delete-btn" class="bulk-action-btn danger">
                🗑️ Delete
            </button>
            <button onclick="window.bulkOps.clearSelection()" class="bulk-action-btn bulk-action-clear">
                ✕ Clear Selection
            </button>
        </div>
    </div>

    <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <h3>No transactions found</h3>
            <?php if (!empty($search) || !empty($account_filter) || !empty($category_filter) || !empty($type_filter) || !empty($date_from) || !empty($date_to) || !empty($amount_min) || !empty($amount_max)): ?>
                <p>No transactions match your current filters. Try adjusting your search criteria.</p>
                <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Clear Filters</a>
            <?php else: ?>
                <p>No transactions have been recorded yet. Start by adding your first transaction.</p>
                <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Add First Transaction</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Transactions Table -->
        <div class="transactions-table">
            <table>
                <thead>
                    <tr>
                        <th class="transaction-checkbox-col">
                            <input type="checkbox" id="select-all-transactions" title="Select All">
                        </th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>From Account</th>
                        <th>To Account</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $txn): ?>
                        <tr class="transaction-row swipeable">
                            <td class="transaction-checkbox-col">
                                <input type="checkbox"
                                       class="transaction-checkbox"
                                       data-transaction-uuid="<?= htmlspecialchars($txn['uuid']) ?>">
                            </td>
                            <td class="date-cell">
                                <?= date('M j, Y', strtotime($txn['date'])) ?>
                                <small><?= date('g:i A', strtotime($txn['created_at'])) ?></small>
                            </td>
                            <td class="description-cell">
                                <?= htmlspecialchars($txn['description']) ?>
                                <?php if (!empty($txn['installment_plan_uuid'])): ?>
                                    <a href="../installments/view.php?ledger=<?= urlencode($ledger_uuid) ?>&plan=<?= urlencode($txn['installment_plan_uuid']) ?>"
                                       class="installment-indicator"
                                       title="Installment Plan: <?= htmlspecialchars($txn['installment_plan_description']) ?> (<?= $txn['completed_installments'] ?>/<?= $txn['number_of_installments'] ?> completed)">
                                        💳 Installment Plan
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="type-cell">
                                <span class="transaction-type <?= $txn['type'] ?>">
                                    <?= ucfirst($txn['type']) ?>
                                </span>
                            </td>
                            <td class="account-cell">
                                <span class="account-name"><?= htmlspecialchars($txn['debit_account']) ?></span>
                                <small class="account-type"><?= ucfirst($txn['debit_type']) ?></small>
                            </td>
                            <td class="account-cell">
                                <span class="account-name"><?= htmlspecialchars($txn['credit_account']) ?></span>
                                <small class="account-type"><?= ucfirst($txn['credit_type']) ?></small>
                            </td>
                            <td class="amount-cell">
                                <span class="amount <?= $txn['type'] === 'inflow' ? 'positive' : 'negative' ?>">
                                    <?= formatCurrency($txn['amount']) ?>
                                </span>
                            </td>
                            <td class="actions-cell">
                                <a href="edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>"
                                   class="btn btn-small btn-edit" title="Edit Transaction">✏️</a>
                                <?php
                                // Show "Create Installment Plan" button for credit card transactions without existing plans
                                $is_cc_transaction = ($txn['credit_type'] === 'liability' || $txn['debit_type'] === 'liability');
                                $has_no_plan = empty($txn['installment_plan_uuid']);
                                if ($is_cc_transaction && $has_no_plan):
                                ?>
                                    <a href="../installments/create.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>"
                                       class="btn btn-small btn-create-installment"
                                       title="Create Installment Plan">💳</a>
                                <?php endif; ?>
                            </td>
                            <!-- Swipe actions (mobile only) -->
                            <div class="swipe-actions">
                                <button class="swipe-action-btn edit"
                                        onclick="window.location.href='edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>'"
                                        title="Edit">
                                    ✏️
                                </button>
                                <button class="swipe-action-btn delete"
                                        onclick="if(confirm('Delete this transaction?')) { /* Add delete logic */ }"
                                        title="Delete">
                                    🗑️
                                </button>
                            </div>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>" class="btn btn-secondary">Previous</a>
                <?php endif; ?>

                <div class="page-numbers">
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1): ?>
                        <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=1&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="page-link current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                        <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $total_pages ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link"><?= $total_pages ?></a>
                    <?php endif; ?>
                </div>

                <?php if ($page < $total_pages): ?>
                    <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>" class="btn btn-secondary">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.filters-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    margin: 2rem 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.filters-form {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.filters-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
    font-size: 0.875rem;
}

.filter-group input,
.filter-group select {
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.filters-actions {
    display: flex;
    gap: 1rem;
}

.results-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 1rem 0;
    padding: 1rem;
    background: white;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.results-info {
    color: #4a5568;
    font-weight: 500;
}

.results-actions {
    display: flex;
    gap: 0.5rem;
}

.transactions-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin: 1rem 0;
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
    font-size: 0.875rem;
}

.transaction-row:hover {
    background: #f7fafc;
}

.date-cell small {
    display: block;
    color: #718096;
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.description-cell {
    font-weight: 500;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.transaction-type {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.transaction-type.inflow {
    background: #c6f6d5;
    color: #2f855a;
}

.transaction-type.outflow {
    background: #fed7d7;
    color: #c53030;
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
    font-weight: 600;
    font-size: 1.1rem;
}

.amount-cell .amount.positive {
    color: #38a169;
}

.amount-cell .amount.negative {
    color: #e53e3e;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin: 2rem 0;
}

.page-numbers {
    display: flex;
    gap: 0.25rem;
}

.page-link {
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    text-decoration: none;
    color: #4a5568;
    transition: all 0.2s;
}

.page-link:hover {
    background: #f7fafc;
    border-color: #cbd5e0;
}

.page-link.current {
    background: #3182ce;
    color: white;
    border-color: #3182ce;
}

.page-ellipsis {
    padding: 0.5rem 0.25rem;
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

.btn-info {
    background: #3182ce;
    color: white;
    border: 1px solid #3182ce;
}

.btn-info:hover {
    background: #2c5aa0;
    border-color: #2c5aa0;
}

/* Filter Summary Banner */
.filter-summary-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin: 1rem 0;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.filter-summary-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-summary-label {
    font-weight: 600;
    opacity: 0.9;
}

.filter-summary-text {
    opacity: 0.95;
}

@media (max-width: 768px) {
    .filters-row {
        grid-template-columns: 1fr;
    }

    .filters-actions {
        flex-direction: column;
    }

    .results-summary {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }

    .results-actions {
        flex-direction: column;
        width: 100%;
    }

    .transactions-table {
        overflow-x: auto;
    }

    .description-cell {
        max-width: 150px;
    }

    .pagination {
        flex-direction: column;
        gap: 0.5rem;
    }

    .filter-summary-content {
        flex-direction: column;
        align-items: flex-start;
    }
}

/* Installment Plan Indicator */
.installment-indicator {
    display: inline-block;
    margin-top: 0.25rem;
    padding: 0.25rem 0.5rem;
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #92400e;
    text-decoration: none;
    transition: all 0.2s;
}

.installment-indicator:hover {
    background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
}

/* Create Installment Plan Button */
.btn-create-installment {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #f59e0b;
    color: #92400e;
    margin-left: 0.25rem;
}

.btn-create-installment:hover {
    background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
    border-color: #d97706;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
}

.actions-cell {
    white-space: nowrap;
}
</style>

<!-- Bulk Categorize Modal -->
<div id="bulk-categorize-modal" class="bulk-modal hidden">
    <div class="bulk-modal-content">
        <div class="bulk-modal-header">
            <h2>Categorize Transactions</h2>
        </div>
        <div class="bulk-modal-body">
            <label for="bulk-category-select">Select Category:</label>
            <select id="bulk-category-select">
                <option value="">-- Select a category --</option>
                <?php foreach ($accounts as $account): ?>
                    <?php if ($account['type'] === 'equity'): ?>
                        <option value="<?= $account['uuid'] ?>">
                            <?= htmlspecialchars($account['name']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-modal-footer">
            <button onclick="closeBulkModal('bulk-categorize-modal')" class="bulk-modal-btn secondary">Cancel</button>
            <button onclick="submitBulkCategorize()" class="bulk-modal-btn primary">Categorize</button>
        </div>
    </div>
</div>

<!-- Bulk Edit Date Modal -->
<div id="bulk-edit-date-modal" class="bulk-modal hidden">
    <div class="bulk-modal-content">
        <div class="bulk-modal-header">
            <h2>Edit Transaction Date</h2>
        </div>
        <div class="bulk-modal-body">
            <label for="bulk-date-input">Select New Date:</label>
            <input type="date" id="bulk-date-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="bulk-modal-footer">
            <button onclick="closeBulkModal('bulk-edit-date-modal')" class="bulk-modal-btn secondary">Cancel</button>
            <button onclick="submitBulkEditDate()" class="bulk-modal-btn primary">Update Date</button>
        </div>
    </div>
</div>

<!-- Bulk Edit Account Modal -->
<div id="bulk-edit-account-modal" class="bulk-modal hidden">
    <div class="bulk-modal-content">
        <div class="bulk-modal-header">
            <h2>Change Account</h2>
        </div>
        <div class="bulk-modal-body">
            <label for="bulk-account-select">Select Account:</label>
            <select id="bulk-account-select">
                <option value="">-- Select an account --</option>
                <?php foreach ($accounts as $account): ?>
                    <?php if ($account['type'] !== 'equity'): ?>
                        <option value="<?= $account['uuid'] ?>">
                            <?= htmlspecialchars($account['name']) ?> (<?= ucfirst($account['type']) ?>)
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-modal-footer">
            <button onclick="closeBulkModal('bulk-edit-account-modal')" class="bulk-modal-btn secondary">Cancel</button>
            <button onclick="submitBulkEditAccount()" class="bulk-modal-btn primary">Update Account</button>
        </div>
    </div>
</div>

<!-- Include bulk operations CSS and JavaScript -->
<link rel="stylesheet" href="../css/bulk-operations.css">
<script src="../js/bulk-operations.js"></script>

<!-- Include search-filter JavaScript -->
<script src="../js/search-filter.js"></script>

<?php require_once '../../includes/footer.php'; ?>