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

    // For each liability account, get the payment available amount
    foreach ($accounts as &$account) {
        if ($account['account_type'] === 'liability') {
            try {
                $stmt = $db->prepare("SELECT api.get_cc_payment_available(?)");
                $stmt->execute([$account['account_uuid']]);
                $account['payment_available'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $account['payment_available'] = 0;
            }
        }
    }
    unset($account); // break the reference

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
                                    <?php if ($type === 'liability'): ?>
                                        <th>Payment Available</th>
                                    <?php endif; ?>
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
                                        <?php if ($type === 'liability'): ?>
                                            <td>
                                                <span class="payment-available amount <?= $account['payment_available'] > 0 ? 'positive' : 'zero' ?>" title="Budget available to pay this credit card">
                                                    <?= formatCurrency($account['payment_available'] ?? 0) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
                                        <td class="account-actions">
                                            <a href="../transactions/account.php?ledger=<?= $ledger_uuid ?>&account=<?= $account['account_uuid'] ?>" class="btn btn-small btn-secondary">View</a>
                                            <a href="balance-history.php?ledger=<?= $ledger_uuid ?>&account=<?= $account['account_uuid'] ?>" class="btn btn-small btn-info" title="Balance History">📊</a>
                                            <?php if ($account['account_type'] === 'asset' || $account['account_type'] === 'liability'): ?>
                                                <a href="reconcile.php?account=<?= $account['account_uuid'] ?>" class="btn btn-small btn-warning" title="Reconcile Account">⚖️ Reconcile</a>
                                            <?php endif; ?>
                                            <?php if ($account['account_type'] === 'liability'): ?>
                                                <button class="btn btn-small btn-success pay-cc-btn"
                                                        data-cc-uuid="<?= $account['account_uuid'] ?>"
                                                        data-cc-name="<?= htmlspecialchars($account['account_name']) ?>"
                                                        data-cc-balance="<?= $account['current_balance'] ?>"
                                                        data-payment-available="<?= $account['payment_available'] ?? 0 ?>"
                                                        data-ledger-uuid="<?= $ledger_uuid ?>"
                                                        title="Pay Credit Card">💳 Pay</button>
                                            <?php endif; ?>
                                            <?php if ($account['account_type'] === 'equity' && !in_array($account['account_name'], ['Income', 'Off-budget', 'Unassigned'])): ?>
                                                <a href="../transactions/assign.php?ledger=<?= $ledger_uuid ?>&category=<?= $account['account_uuid'] ?>" class="btn btn-small btn-primary">Assign</a>
                                                <?php
                                                // Check if it's not a CC payment category
                                                $is_cc_payment = false;
                                                try {
                                                    $check_stmt = $db->prepare("SELECT metadata->>'is_cc_payment_category' as is_cc FROM data.accounts WHERE uuid = ?");
                                                    $check_stmt->execute([$account['account_uuid']]);
                                                    $meta = $check_stmt->fetchColumn();
                                                    $is_cc_payment = ($meta === 'true');
                                                } catch (Exception $e) {}

                                                if (!$is_cc_payment): ?>
                                                    <button class="btn btn-small btn-danger delete-category-btn"
                                                            data-category-uuid="<?= $account['account_uuid'] ?>"
                                                            data-category-name="<?= htmlspecialchars($account['account_name']) ?>"
                                                            data-ledger-uuid="<?= $ledger_uuid ?>"
                                                            title="Delete Category">Delete</button>
                                                <?php endif; ?>
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

.payment-available {
    font-weight: 600;
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

/* Delete category button */
.btn-danger {
    background-color: #fc8181;
    color: #742a2a;
}

.btn-danger:hover {
    background-color: #f56565;
    color: white;
}

/* Delete modal styles */
.delete-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.delete-modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    margin-bottom: 1.5rem;
}

.modal-header h2 {
    margin: 0 0 0.5rem 0;
    color: #2d3748;
}

.modal-body {
    margin-bottom: 1.5rem;
}

.impact-summary {
    background: #f7fafc;
    border-left: 4px solid #4299e1;
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 4px;
}

.impact-item {
    padding: 0.5rem 0;
    display: flex;
    justify-content: space-between;
}

.impact-item strong {
    color: #2d3748;
}

.warning-box {
    background: #fff5f5;
    border-left: 4px solid #fc8181;
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 4px;
    color: #742a2a;
}

.reassign-section {
    margin: 1rem 0;
}

.reassign-section label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.reassign-section select {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
}

.modal-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Payment modal styles */
.payment-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.payment-modal.active {
    display: flex;
}

.payment-info {
    background: #f7fafc;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.info-label {
    font-weight: 500;
    color: #4a5568;
}

.info-value {
    font-weight: 600;
    font-size: 1.1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #2d3748;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    font-size: 1rem;
}

.form-hint {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #718096;
}

.btn-link {
    background: none;
    border: none;
    color: #3182ce;
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
}

.btn-link:hover {
    color: #2c5282;
}

.btn-success {
    background-color: #48bb78;
    color: white;
}

.btn-success:hover {
    background-color: #38a169;
}

.btn-warning {
    background-color: #f6ad55;
    color: #744210;
}

.btn-warning:hover {
    background-color: #ed8936;
    color: white;
}
</style>

<!-- Pay Credit Card Modal -->
<div id="payCreditCardModal" class="payment-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Pay Credit Card: <span id="pay-cc-name"></span></h2>
        </div>
        <div class="modal-body">
            <div class="payment-info">
                <div class="info-item">
                    <span class="info-label">Current Balance Owed:</span>
                    <span class="info-value amount negative" id="cc-balance"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Payment Available (Budgeted):</span>
                    <span class="info-value amount positive" id="payment-available"></span>
                </div>
            </div>

            <form id="paymentForm">
                <div class="form-group">
                    <label for="bank-account">Pay From (Bank Account) *</label>
                    <select id="bank-account" name="bank_account" required>
                        <option value="">-- Select a bank account --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="payment-amount">Payment Amount *</label>
                    <input type="number" id="payment-amount" name="amount" step="0.01" min="0.01" required>
                    <div class="form-hint">
                        <button type="button" class="btn-link" onclick="setPaymentAmount('available')">Use Payment Available</button>
                        |
                        <button type="button" class="btn-link" onclick="setPaymentAmount('full')">Pay Full Balance</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="payment-date">Payment Date *</label>
                    <input type="date" id="payment-date" name="date" required>
                </div>

                <div class="form-group">
                    <label for="payment-memo">Memo (Optional)</label>
                    <input type="text" id="payment-memo" name="memo" placeholder="Optional note">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
            <button class="btn btn-success" id="confirm-payment-btn" onclick="submitPayment()">Make Payment</button>
        </div>
    </div>
</div>

<!-- Delete Category Modal -->
<div id="deleteCategoryModal" class="delete-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Delete Category: <span id="modal-category-name"></span></h2>
        </div>
        <div class="modal-body">
            <div id="loading-message" style="text-align: center; padding: 2rem;">
                <p>Checking deletion impact...</p>
            </div>
            <div id="impact-content" style="display: none;">
                <div class="impact-summary" id="impact-summary"></div>

                <div class="reassign-section">
                    <label>
                        <input type="radio" name="deletion-strategy" value="reassign" checked>
                        Reassign all transactions to another category (recommended)
                    </label>
                    <select id="reassign-category-select" style="margin-top: 0.5rem;">
                        <option value="">-- Select a category --</option>
                    </select>
                </div>

                <div class="reassign-section">
                    <label>
                        <input type="radio" name="deletion-strategy" value="delete">
                        Delete all related transactions (cannot be undone)
                    </label>
                </div>

                <div class="warning-box">
                    <strong>Warning:</strong> This action cannot be undone. All goals, balance history, and related data will be permanently deleted.
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" id="confirm-delete-btn" onclick="confirmDelete()" disabled>Delete Category</button>
        </div>
    </div>
</div>

<script>
let currentCategoryUuid = null;
let currentLedgerUuid = null;
let impactData = null;

// Open delete modal
document.querySelectorAll('.delete-category-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        currentCategoryUuid = this.dataset.categoryUuid;
        currentLedgerUuid = this.dataset.ledgerUuid;
        const categoryName = this.dataset.categoryName;

        document.getElementById('modal-category-name').textContent = categoryName;
        document.getElementById('deleteCategoryModal').classList.add('active');
        document.getElementById('loading-message').style.display = 'block';
        document.getElementById('impact-content').style.display = 'none';

        // Fetch deletion impact
        fetchDeletionImpact();
    });
});

// Close modal
function closeDeleteModal() {
    document.getElementById('deleteCategoryModal').classList.remove('active');
    currentCategoryUuid = null;
    currentLedgerUuid = null;
    impactData = null;
}

// Fetch deletion impact
async function fetchDeletionImpact() {
    try {
        const response = await fetch('../api/delete-category.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                category_uuid: currentCategoryUuid,
                action: 'preview'
            })
        });

        const data = await response.json();

        if (!response.ok) {
            alert('Error: ' + (data.error || 'Failed to check deletion impact'));
            closeDeleteModal();
            return;
        }

        impactData = data.impact;
        displayImpact(data.impact);
    } catch (error) {
        alert('Error: ' + error.message);
        closeDeleteModal();
    }
}

// Display impact
function displayImpact(impact) {
    const summary = document.getElementById('impact-summary');
    summary.innerHTML = `
        <div class="impact-item"><strong>Transactions:</strong> <span>${impact.transaction_count}</span></div>
        <div class="impact-item"><strong>Transaction Splits:</strong> <span>${impact.split_count}</span></div>
        <div class="impact-item"><strong>Has Goal:</strong> <span>${impact.goal_exists ? 'Yes' : 'No'}</span></div>
        <div class="impact-item"><strong>Payees Using Default:</strong> <span>${impact.payee_count}</span></div>
        <div class="impact-item"><strong>Recurring Transactions:</strong> <span>${impact.recurring_count}</span></div>
        <div class="impact-item"><strong>Current Balance:</strong> <span>${formatMoney(impact.current_balance)}</span></div>
    `;

    // Populate reassign category dropdown
    populateReassignSelect();

    document.getElementById('loading-message').style.display = 'none';
    document.getElementById('impact-content').style.display = 'block';
    document.getElementById('confirm-delete-btn').disabled = false;
}

// Populate reassign category select
async function populateReassignSelect() {
    const select = document.getElementById('reassign-category-select');

    // Fetch categories for this ledger
    try {
        const response = await fetch(`../api/get-categories.php?ledger=${currentLedgerUuid}`);
        const data = await response.json();

        select.innerHTML = '<option value="">-- Select a category --</option>';

        if (data.categories) {
            data.categories.forEach(cat => {
                if (cat.uuid !== currentCategoryUuid) {
                    const option = document.createElement('option');
                    option.value = cat.uuid;
                    option.textContent = cat.name;
                    select.appendChild(option);
                }
            });
        }
    } catch (error) {
        console.error('Failed to load categories:', error);
    }
}

// Confirm deletion
async function confirmDelete() {
    const strategy = document.querySelector('input[name="deletion-strategy"]:checked').value;
    let reassignTo = null;

    if (strategy === 'reassign') {
        reassignTo = document.getElementById('reassign-category-select').value;
        if (!reassignTo) {
            alert('Please select a category to reassign transactions to.');
            return;
        }
    }

    const confirmMsg = strategy === 'delete'
        ? `Are you sure you want to DELETE this category and all ${impactData.transaction_count} related transactions? This cannot be undone!`
        : `Are you sure you want to delete this category and reassign all ${impactData.transaction_count} transactions?`;

    if (!confirm(confirmMsg)) {
        return;
    }

    // Disable button and show loading
    const btn = document.getElementById('confirm-delete-btn');
    btn.disabled = true;
    btn.textContent = 'Deleting...';

    try {
        const response = await fetch('../api/delete-category.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                category_uuid: currentCategoryUuid,
                action: 'delete',
                reassign_to_category_uuid: reassignTo
            })
        });

        const data = await response.json();

        if (!response.ok) {
            alert('Error: ' + (data.error || 'Failed to delete category'));
            btn.disabled = false;
            btn.textContent = 'Delete Category';
            return;
        }

        alert('Category deleted successfully!');
        window.location.reload();
    } catch (error) {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.textContent = 'Delete Category';
    }
}

// Format money helper
function formatMoney(cents) {
    return '$' + (cents / 100).toFixed(2);
}

// Enable/disable reassign select based on strategy
document.querySelectorAll('input[name="deletion-strategy"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('reassign-category-select').disabled = (this.value !== 'reassign');
    });
});

// ============================================================================
// CREDIT CARD PAYMENT MODAL HANDLERS
// ============================================================================

let currentCCUuid = null;
let currentCCBalance = 0;
let currentPaymentAvailable = 0;
let currentCCLedgerUuid = null;

// Open payment modal
document.querySelectorAll('.pay-cc-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        currentCCUuid = this.dataset.ccUuid;
        currentCCBalance = parseInt(this.dataset.ccBalance);
        currentPaymentAvailable = parseInt(this.dataset.paymentAvailable);
        currentCCLedgerUuid = this.dataset.ledgerUuid;
        const ccName = this.dataset.ccName;

        document.getElementById('pay-cc-name').textContent = ccName;
        document.getElementById('cc-balance').textContent = formatMoney(currentCCBalance);
        document.getElementById('payment-available').textContent = formatMoney(currentPaymentAvailable);

        // Set today's date
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('payment-date').value = today;

        // Load bank accounts
        loadBankAccounts();

        document.getElementById('payCreditCardModal').classList.add('active');
    });
});

// Close payment modal
function closePaymentModal() {
    document.getElementById('payCreditCardModal').classList.remove('active');
    document.getElementById('paymentForm').reset();
    currentCCUuid = null;
    currentCCBalance = 0;
    currentPaymentAvailable = 0;
}

// Load bank accounts for payment
async function loadBankAccounts() {
    const select = document.getElementById('bank-account');

    try {
        const response = await fetch(`../api/get-accounts.php?ledger=${currentCCLedgerUuid}&type=asset`);
        const data = await response.json();

        select.innerHTML = '<option value="">-- Select a bank account --</option>';

        if (data.accounts) {
            data.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.uuid;
                option.textContent = `${account.name} (${formatMoney(account.balance)})`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Failed to load bank accounts:', error);
        alert('Failed to load bank accounts. Please refresh and try again.');
    }
}

// Set payment amount helper
function setPaymentAmount(type) {
    const input = document.getElementById('payment-amount');

    if (type === 'available') {
        // Use payment available amount (budgeted)
        input.value = (currentPaymentAvailable / 100).toFixed(2);
    } else if (type === 'full') {
        // Use full CC balance owed (convert to positive for payment)
        input.value = (Math.abs(currentCCBalance) / 100).toFixed(2);
    }
}

// Submit payment
async function submitPayment() {
    const form = document.getElementById('paymentForm');

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const bankAccountUuid = document.getElementById('bank-account').value;
    const amount = Math.round(parseFloat(document.getElementById('payment-amount').value) * 100);
    const date = document.getElementById('payment-date').value;
    const memo = document.getElementById('payment-memo').value;

    if (!bankAccountUuid) {
        alert('Please select a bank account to pay from.');
        return;
    }

    if (amount <= 0) {
        alert('Payment amount must be greater than zero.');
        return;
    }

    // Warn if paying more than budgeted
    if (amount > currentPaymentAvailable) {
        const overage = amount - currentPaymentAvailable;
        const confirmMsg = `You're paying $${(amount / 100).toFixed(2)}, but only have $${(currentPaymentAvailable / 100).toFixed(2)} budgeted.\n\n` +
                          `This will create $${(overage / 100).toFixed(2)} overspending in your payment category.\n\n` +
                          `Continue anyway?`;
        if (!confirm(confirmMsg)) {
            return;
        }
    }

    // Disable button and show loading
    const btn = document.getElementById('confirm-payment-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    try {
        const response = await fetch('../api/pay-credit-card.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                credit_card_uuid: currentCCUuid,
                bank_account_uuid: bankAccountUuid,
                amount: amount,
                date: date + ' 00:00:00',
                memo: memo || null
            })
        });

        const data = await response.json();

        if (!response.ok) {
            alert('Error: ' + (data.error || 'Failed to process payment'));
            btn.disabled = false;
            btn.textContent = 'Make Payment';
            return;
        }

        alert('Payment processed successfully!');
        window.location.reload();
    } catch (error) {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.textContent = 'Make Payment';
    }
}

// Close modal when clicking outside
document.getElementById('payCreditCardModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>