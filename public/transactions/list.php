<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

// Require authentication
requireAuth();

$ledger_uuid = pgb_current_ledger();
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
        LEFT JOIN data.transaction_log tl ON t.id = tl.original_transaction_id
        WHERE $where_clause AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
        AND tl.id IS NULL
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
               ip.status as installment_plan_status,
               op.uuid as obligation_payment_uuid,
               o.uuid as obligation_uuid,
               o.name as obligation_name,
               o.payee_name as obligation_payee,
               op.status as obligation_payment_status,
               op.due_date as obligation_due_date
        FROM data.transactions t
        JOIN data.accounts da ON t.debit_account_id = da.id
        JOIN data.accounts ca ON t.credit_account_id = ca.id
        JOIN data.ledgers l ON t.ledger_id = l.id
        LEFT JOIN data.installment_plans ip ON t.id = ip.original_transaction_id
        LEFT JOIN data.obligation_payments op ON t.id = op.transaction_id
        LEFT JOIN data.obligations o ON op.obligation_id = o.id
        LEFT JOIN data.transaction_log tl ON t.id = tl.original_transaction_id
        WHERE $where_clause AND t.deleted_at IS NULL
        AND t.description NOT LIKE 'DELETED:%'
        AND t.description NOT LIKE 'REVERSAL:%'
        AND tl.id IS NULL
        $order_by
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($transactions_query);
    $stmt->execute([...$params, $per_page, $offset]);
    $transactions = $stmt->fetchAll();

    // Fetch unrealized projected events for the link modal
    $stmt = $db->prepare("
        SELECT uuid, name, direction, amount, event_date
        FROM api.projected_events
        WHERE ledger_uuid = ?
          AND is_realized = false
          AND linked_transaction_uuid IS NULL
        ORDER BY event_date
    ");
    $stmt->execute([$ledger_uuid]);
    $unrealized_events = $stmt->fetchAll();

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}

// Build base URL params (without type/page) for seg-toggle links
$base_params = array_filter([
    'ledger'     => $ledger_uuid,
    'search'     => $search,
    'account'    => $account_filter,
    'category'   => $category_filter,
    'date_from'  => $date_from,
    'date_to'    => $date_to,
    'amount_min' => $amount_min,
    'amount_max' => $amount_max,
    'sort_by'    => $sort_by !== 'date_desc' ? $sort_by : '',
]);
function type_url(array $base, string $type): string {
    $p = $base;
    if ($type !== '') $p['type'] = $type; else unset($p['type']);
    return '?' . http_build_query($p);
}
?>

<div class="container" style="display:flex;flex-direction:column;gap:var(--space-5);">

    <!-- Page header -->
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--space-3);">
        <div>
            <div class="eyebrow"><?= htmlspecialchars($ledger['name']) ?></div>
            <h1 style="margin:0;font-size:var(--text-2xl);font-weight:700;">Transactions</h1>
        </div>
        <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">
            <i data-lucide="plus" style="width:16px;height:16px;"></i> Add Transaction
        </a>
    </div>

    <!-- Filters card -->
    <div class="card">
        <form method="GET" id="filter-form" style="display:flex;flex-direction:column;gap:var(--space-4);">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Search bar -->
            <div class="search-wrap">
                <i data-lucide="search" class="search-icon"></i>
                <input type="text"
                       name="search"
                       class="search-input"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search transactions…">
            </div>

            <!-- Filter row 1 -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3);">
                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="account">Account</label>
                    <select id="account" name="account" class="input">
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

                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="category">Category</label>
                    <select id="category" name="category" class="input">
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

                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="date_from">From Date</label>
                    <input type="date" id="date_from" name="date_from" class="input" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="date_to">To Date</label>
                    <input type="date" id="date_to" name="date_to" class="input" value="<?= htmlspecialchars($date_to) ?>">
                </div>
            </div>

            <!-- Filter row 2 -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3);">
                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="amount_min">Min Amount ($)</label>
                    <input type="number" id="amount_min" name="amount_min" class="input" step="0.01" min="0" value="<?= htmlspecialchars($amount_min) ?>" placeholder="0.00">
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="amount_max">Max Amount ($)</label>
                    <input type="number" id="amount_max" name="amount_max" class="input" step="0.01" min="0" value="<?= htmlspecialchars($amount_max) ?>" placeholder="0.00">
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-1);">
                    <label class="eyebrow" for="sort_by">Sort By</label>
                    <select id="sort_by" name="sort_by" class="input">
                        <option value="date_desc" <?= $sort_by === 'date_desc' ? 'selected' : '' ?>>Date (Newest First)</option>
                        <option value="date_asc" <?= $sort_by === 'date_asc' ? 'selected' : '' ?>>Date (Oldest First)</option>
                        <option value="amount_desc" <?= $sort_by === 'amount_desc' ? 'selected' : '' ?>>Amount (High to Low)</option>
                        <option value="amount_asc" <?= $sort_by === 'amount_asc' ? 'selected' : '' ?>>Amount (Low to High)</option>
                        <option value="description" <?= $sort_by === 'description' ? 'selected' : '' ?>>Description (A-Z)</option>
                    </select>
                </div>

                <div style="display:flex;align-items:flex-end;gap:var(--space-2);">
                    <button type="submit" class="btn btn-primary" style="flex:1;">Apply</button>
                    <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-ghost">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Type filter + results count -->
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--space-3);">
        <div class="seg-toggle">
            <a href="<?= htmlspecialchars(type_url($base_params, '')) ?>"
               class="seg-btn <?= !$type_filter ? 'active' : '' ?>">All</a>
            <a href="<?= htmlspecialchars(type_url($base_params, 'inflow')) ?>"
               class="seg-btn <?= $type_filter === 'inflow' ? 'active' : '' ?>">Income</a>
            <a href="<?= htmlspecialchars(type_url($base_params, 'outflow')) ?>"
               class="seg-btn <?= $type_filter === 'outflow' ? 'active' : '' ?>">Expenses</a>
        </div>
        <span style="font-size:var(--text-sm);color:var(--color-fg-muted);">
            <?= number_format($total_transactions) ?> transaction<?= $total_transactions !== 1 ? 's' : '' ?>
            <?php if ($total_pages > 1): ?> &mdash; page <?= $page ?> of <?= $total_pages ?><?php endif; ?>
        </span>
    </div>

    <!-- Bulk action bar -->
    <div id="bulk-action-bar" class="hidden">
        <div class="bulk-action-info">
            <span id="selected-count">0</span> transaction(s) selected
        </div>
        <div class="bulk-action-buttons">
            <button id="bulk-categorize-btn" class="bulk-action-btn">📂 Categorize</button>
            <button id="bulk-edit-date-btn" class="bulk-action-btn">📅 Edit Date</button>
            <button id="bulk-edit-account-btn" class="bulk-action-btn">💳 Change Account</button>
            <button id="bulk-delete-btn" class="bulk-action-btn danger">🗑️ Delete</button>
            <button onclick="window.bulkOps.clearSelection()" class="bulk-action-btn bulk-action-clear">✕ Clear</button>
        </div>
    </div>

    <?php if (empty($transactions)): ?>
        <div style="text-align:center;padding:var(--space-12);color:var(--color-fg-muted);">
            <div style="font-size:2rem;margin-bottom:var(--space-3);">📋</div>
            <div style="font-weight:600;font-size:var(--text-lg);margin-bottom:var(--space-2);">No transactions found</div>
            <?php if (!empty($search) || !empty($account_filter) || !empty($category_filter) || !empty($type_filter) || !empty($date_from) || !empty($date_to) || !empty($amount_min) || !empty($amount_max)): ?>
                <p style="margin-bottom:var(--space-4);">No transactions match your filters.</p>
                <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Clear Filters</a>
            <?php else: ?>
                <p style="margin-bottom:var(--space-4);">No transactions yet.</p>
                <a href="../transactions/add.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Add First Transaction</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Transactions table -->
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="overflow-x:auto;">
                <table class="tbl" style="border-radius:0;border:0;">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" id="select-all-transactions" title="Select All">
                            </th>
                            <th>Date</th>
                            <th>Payee</th>
                            <th>Type</th>
                            <th>From</th>
                            <th>To</th>
                            <th class="num">Amount</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <tr class="transaction-row swipeable">
                                <td data-label="">
                                    <input type="checkbox"
                                           class="transaction-checkbox"
                                           data-transaction-uuid="<?= htmlspecialchars($txn['uuid']) ?>">
                                </td>
                                <td data-label="Date">
                                    <div style="font-size:var(--text-sm);font-weight:500;"><?= date('M j, Y', strtotime($txn['date'])) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--color-fg-muted);"><?= date('g:i A', strtotime($txn['created_at'])) ?></div>
                                </td>
                                <td data-label="Payee" style="max-width:220px;">
                                    <div style="display:flex;align-items:center;gap:var(--space-2);">
                                        <div class="cat-icon" style="width:28px;height:28px;font-size:12px;flex-shrink:0;">
                                            <?= htmlspecialchars(strtoupper(substr($txn['description'] ?: '?', 0, 1))) ?>
                                        </div>
                                        <div style="min-width:0;">
                                            <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:var(--text-sm);">
                                                <?= htmlspecialchars($txn['description']) ?>
                                            </div>
                                            <?php if (!empty($txn['installment_plan_uuid'])): ?>
                                                <a href="../installments/view.php?ledger=<?= urlencode($ledger_uuid) ?>&plan=<?= urlencode($txn['installment_plan_uuid']) ?>"
                                                   class="badge badge-warning" style="text-decoration:none;margin-top:2px;"
                                                   title="Installment Plan: <?= htmlspecialchars($txn['installment_plan_description']) ?> (<?= $txn['completed_installments'] ?>/<?= $txn['number_of_installments'] ?>)">
                                                    💳 Installment
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($txn['obligation_payment_uuid'])): ?>
                                                <a href="../obligations/view.php?ledger=<?= urlencode($ledger_uuid) ?>&obligation=<?= urlencode($txn['obligation_uuid']) ?>"
                                                   class="badge badge-success" style="text-decoration:none;margin-top:2px;"
                                                   title="Bill: <?= htmlspecialchars($txn['obligation_name']) ?>">
                                                    📋 <?= htmlspecialchars($txn['obligation_name']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Type">
                                    <?php if ($txn['type'] === 'inflow'): ?>
                                        <span class="badge badge-success">Income</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Expense</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="From">
                                    <div style="font-size:var(--text-sm);font-weight:500;"><?= htmlspecialchars($txn['debit_account']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--color-fg-muted);"><?= ucfirst($txn['debit_type']) ?></div>
                                </td>
                                <td data-label="To">
                                    <div style="font-size:var(--text-sm);font-weight:500;"><?= htmlspecialchars($txn['credit_account']) ?></div>
                                    <div style="font-size:var(--text-xs);color:var(--color-fg-muted);"><?= ucfirst($txn['credit_type']) ?></div>
                                </td>
                                <td class="num" data-label="Amount">
                                    <span class="money <?= $txn['type'] === 'inflow' ? 'pos' : 'neg' ?> tnum">
                                        <?= $txn['type'] === 'inflow' ? '+' : '−' ?><?= formatCurrency($txn['amount']) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a href="edit.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>"
                                       class="btn btn-sm btn-ghost" title="Edit">✏️</a>
                                    <button class="btn btn-sm btn-ghost" style="color:var(--danger-500);"
                                            title="Delete"
                                            onclick="deleteSingleTransaction('<?= htmlspecialchars($txn['uuid']) ?>', <?= json_encode($txn['description']) ?>)">🗑️</button>
                                    <?php
                                    $is_cc_transaction = ($txn['credit_type'] === 'liability' || $txn['debit_type'] === 'liability');
                                    $has_no_plan = empty($txn['installment_plan_uuid']);
                                    if ($is_cc_transaction && $has_no_plan):
                                    ?>
                                        <a href="../installments/create.php?ledger=<?= urlencode($ledger_uuid) ?>&transaction=<?= urlencode($txn['uuid']) ?>"
                                           class="btn btn-sm btn-ghost" title="Create Installment Plan">💳</a>
                                    <?php endif; ?>
                                    <?php if (!empty($unrealized_events)): ?>
                                        <button class="btn btn-sm btn-ghost"
                                                title="Link to Projected Event"
                                                onclick="openLinkModal('<?= htmlspecialchars($txn['uuid']) ?>', <?= json_encode($txn['description']) ?>, <?= (int)$txn['amount'] ?>)">🔗</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display:flex;justify-content:center;align-items:center;gap:var(--space-3);">
                <?php if ($page > 1): ?>
                    <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>" class="btn btn-secondary btn-sm">Previous</a>
                <?php endif; ?>

                <div style="display:flex;gap:var(--space-1);">
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    if ($start_page > 1): ?>
                        <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=1&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link">1</a>
                        <?php if ($start_page > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="page-link current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                        <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $total_pages ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>" class="page-link"><?= $total_pages ?></a>
                    <?php endif; ?>
                </div>

                <?php if ($page < $total_pages): ?>
                    <a href="?ledger=<?= urlencode($ledger_uuid) ?>&page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>" class="btn btn-secondary btn-sm">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<style>
.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 var(--space-2);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-fg);
    font-size: var(--text-sm);
    transition: background var(--duration-fast) var(--ease-out);
}
.page-link:hover { background: var(--gray-50); }
.page-link.current { background: var(--color-primary); color: #fff; border-color: var(--color-primary); font-weight: 600; }
.page-ellipsis { display: inline-flex; align-items: center; padding: 0 var(--space-1); color: var(--color-fg-muted); font-size: var(--text-sm); }
</style>

<!-- Bulk Categorize Modal -->
<div id="bulk-categorize-modal" class="bulk-modal hidden">
    <div class="bulk-modal-content">
        <div class="bulk-modal-header"><h2>Categorize Transactions</h2></div>
        <div class="bulk-modal-body">
            <label for="bulk-category-select">Select Category:</label>
            <select id="bulk-category-select">
                <option value="">-- Select a category --</option>
                <?php foreach ($accounts as $account): ?>
                    <?php if ($account['type'] === 'equity'): ?>
                        <option value="<?= $account['uuid'] ?>"><?= htmlspecialchars($account['name']) ?></option>
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
        <div class="bulk-modal-header"><h2>Edit Transaction Date</h2></div>
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
        <div class="bulk-modal-header"><h2>Change Account</h2></div>
        <div class="bulk-modal-body">
            <label for="bulk-account-select">Select Account:</label>
            <select id="bulk-account-select">
                <option value="">-- Select an account --</option>
                <?php foreach ($accounts as $account): ?>
                    <?php if ($account['type'] !== 'equity'): ?>
                        <option value="<?= $account['uuid'] ?>"><?= htmlspecialchars($account['name']) ?> (<?= ucfirst($account['type']) ?>)</option>
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

<!-- Link to Projected Event Modal -->
<div id="link-event-modal" class="bulk-modal hidden">
    <div class="bulk-modal-content" style="max-width:520px;">
        <div class="bulk-modal-header"><h2>Link to Projected Event</h2></div>
        <div class="bulk-modal-body">
            <div style="background:var(--gray-50);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:var(--space-3);margin-bottom:var(--space-4);font-size:var(--text-sm);">
                <strong id="link-modal-txn-desc" style="display:block;margin-bottom:var(--space-1);"></strong>
                <span id="link-modal-txn-amount" style="color:var(--color-fg-muted);"></span>
            </div>
            <label for="link-event-select" style="display:block;margin-bottom:var(--space-2);font-size:var(--text-sm);font-weight:600;">Select Projected Event:</label>
            <select id="link-event-select" class="input">
                <option value="">— Select an event —</option>
                <?php foreach ($unrealized_events as $ev): ?>
                    <option value="<?= htmlspecialchars($ev['uuid']) ?>">
                        <?= htmlspecialchars(date('M j, Y', strtotime($ev['event_date']))) ?>
                        — <?= htmlspecialchars($ev['name']) ?>
                        (<?= $ev['direction'] === 'inflow' ? '+' : '−' ?>R$ <?= number_format($ev['amount'] / 100, 2, ',', '.') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bulk-modal-footer">
            <button onclick="closeBulkModal('link-event-modal')" class="bulk-modal-btn secondary">Cancel</button>
            <button onclick="submitLinkEvent()" class="bulk-modal-btn primary">Link</button>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../css/bulk-operations.css">
<script src="../js/bulk-operations.js"></script>
<script src="../js/search-filter.js"></script>

<script>
let _linkTxnUuid = null;

function openLinkModal(txnUuid, txnDescription, txnAmountCents) {
    _linkTxnUuid = txnUuid;
    document.getElementById('link-modal-txn-desc').textContent = txnDescription;
    document.getElementById('link-modal-txn-amount').textContent =
        'R$ ' + (txnAmountCents / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('link-event-select').value = '';
    document.getElementById('link-event-modal').classList.remove('hidden');
}

async function submitLinkEvent() {
    const eventUuid = document.getElementById('link-event-select').value;
    if (!eventUuid) { Toast.error('Please select a projected event.'); return; }
    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('event_uuid', eventUuid);
    formData.append('is_realized', '1');
    formData.append('linked_transaction_uuid', _linkTxnUuid);
    try {
        const response = await fetch('/pgbudget/api/projected-events.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) { closeBulkModal('link-event-modal'); window.location.reload(); }
        else { Toast.error('Error linking: ' + (data.error || 'Unknown error')); }
    } catch (err) { Toast.error('Error: ' + err.message); }
}

async function deleteSingleTransaction(uuid, description) {
    ConfirmModal.show({
        title:        'Delete Transaction?',
        message:      `Delete "${description}"? This cannot be undone.`,
        confirmText:  'Delete',
        confirmClass: 'btn-danger',
        onConfirm:    async () => {
            try {
                const response = await fetch(`/pgbudget/api/delete-transaction.php?uuid=${encodeURIComponent(uuid)}`, { method: 'DELETE' });
                const data = await response.json();
                if (data.success) { window.location.reload(); }
                else { Toast.error('Error deleting transaction: ' + (data.error || 'Unknown error')); }
            } catch (err) { Toast.error('Error deleting transaction: ' + err.message); }
        }
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
