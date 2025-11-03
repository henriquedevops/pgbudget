<?php
require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/help-icon.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = sanitizeInput($_POST['description']);
    $amount = parseCurrency($_POST['amount']);
    $date = $_POST['date'];
    $type = $_POST['type']; // 'inflow' or 'outflow'
    $account_uuid = sanitizeInput($_POST['account']);
    $category_uuid = sanitizeInput($_POST['category']);
    $payee_name = isset($_POST['payee']) ? sanitizeInput($_POST['payee']) : null;
    $is_split = isset($_POST['is_split']) && $_POST['is_split'] === '1';

    if (empty($description) || $amount <= 0 || empty($date) || empty($account_uuid)) {
        $_SESSION['error'] = 'Please fill in all required fields.';
    } else {
        try {
            $db = getDbConnection();

            // Set user context
            $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
            $stmt->execute([$_SESSION['user_id']]);

            if ($is_split) {
                // Handle split transaction
                $splits = [];
                $split_total = 0;

                if (isset($_POST['split_category']) && is_array($_POST['split_category'])) {
                    foreach ($_POST['split_category'] as $index => $split_category_uuid) {
                        if (!empty($split_category_uuid) && isset($_POST['split_amount'][$index])) {
                            $split_amount = parseCurrency($_POST['split_amount'][$index]);
                            $split_memo = isset($_POST['split_memo'][$index]) ? sanitizeInput($_POST['split_memo'][$index]) : '';

                            if ($split_amount > 0) {
                                $splits[] = [
                                    'category_uuid' => $split_category_uuid,
                                    'amount' => $split_amount,
                                    'memo' => $split_memo
                                ];
                                $split_total += $split_amount;
                            }
                        }
                    }
                }

                if (empty($splits)) {
                    $_SESSION['error'] = 'Split transaction must have at least one category.';
                } elseif ($split_total != $amount) {
                    $_SESSION['error'] = 'Sum of splits must equal total transaction amount.';
                } else {
                    // Add split transaction using the API function
                    $stmt = $db->prepare("SELECT api.add_split_transaction(?, ?, ?, ?, ?, ?, ?::jsonb)");
                    $stmt->execute([
                        $ledger_uuid,
                        $date,
                        $description,
                        $type,
                        $amount,
                        $account_uuid,
                        json_encode($splits)
                    ]);

                    $result = $stmt->fetch();
                    if ($result) {
                        $_SESSION['success'] = 'Split transaction added successfully!';
                        header("Location: ../budget/dashboard.php?ledger=" . $ledger_uuid);
                        exit;
                    } else {
                        $_SESSION['error'] = 'Failed to add split transaction.';
                    }
                }
            } else {
                // Handle regular transaction
                $stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $ledger_uuid,
                    $date,
                    $description,
                    $type,
                    $amount,
                    $account_uuid,
                    ($category_uuid && $category_uuid !== 'unassigned') ? $category_uuid : null,
                    $payee_name
                ]);

                $result = $stmt->fetch();
                if ($result) {
                    $_SESSION['success'] = 'Transaction added successfully!';
                    header("Location: ../budget/dashboard.php?ledger=" . $ledger_uuid);
                    exit;
                } else {
                    $_SESSION['error'] = 'Failed to add transaction.';
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23503') { // Foreign key violation
                $_SESSION['error'] = '❌ Oops! This transaction couldn\'t be saved. The account or category you selected may no longer exist. Please refresh and try again.';
            } elseif (strpos($e->getCode(), 'P0001') !== false || strpos($e->getMessage(), 'SQLSTATE[P0001]') !== false) { // Insufficient funds
                // Extract error details from exception message
                $message = $e->getMessage();

                // Check for insufficient funds error
                if (strpos($message, 'Insufficient funds in category') !== false) {
                    // Extract details from JSON in DETAIL section
                    preg_match('/"overspent_amount"\s*:\s*(\d+)/', $message, $overspent_matches);
                    preg_match('/"category_name"\s*:\s*"([^"]+)"/', $message, $category_matches);

                    $overspent_amount = isset($overspent_matches[1]) ? (int)$overspent_matches[1] : 0;
                    $category_name = isset($category_matches[1]) ? $category_matches[1] : 'unknown';

                    $_SESSION['overspending_error'] = [
                        'overspent_amount' => $overspent_amount,
                        'category_name' => $category_name,
                        'original_data' => $_POST
                    ];
                    header('Location: add.php?ledger=' . $ledger_uuid);
                    exit;
                } else {
                    // Other P0001 errors
                    $_SESSION['error'] = 'Validation error: ' . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = 'Database error: ' . $e->getMessage();
            }
        }
    }
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

    // Get accounts for this ledger
    $stmt = $db->prepare("SELECT uuid, name, type FROM api.accounts WHERE ledger_uuid = ? ORDER BY name");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Get categories (equity accounts that aren't special, groups, or CC payment categories)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        AND (is_group = false OR is_group IS NULL)
        AND (metadata->>'is_cc_payment_category' IS NULL OR metadata->>'is_cc_payment_category' != 'true')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <h1>Add Transaction</h1>
        <p>Add a new transaction to <?= htmlspecialchars($ledger['name']) ?></p>
    </div>

    <div class="form-container">
        <form method="POST" class="transaction-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="type" class="form-label">Transaction Type * <?php renderHelpIcon("'Income' increases an account balance (like a paycheck), while 'Expense' decreases it (like a purchase)."); ?></label>
                    <select id="type" name="type" class="form-select" required onchange="updateFormForType()">
                        <option value="">Choose type...</option>
                        <option value="inflow" <?= (isset($_POST['type']) && $_POST['type'] === 'inflow') ? 'selected' : '' ?>>
                            Income (Money In)
                        </option>
                        <option value="outflow" <?= (isset($_POST['type']) && $_POST['type'] === 'outflow') ? 'selected' : '' ?>>
                            Expense (Money Out)
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">Amount *</label>
                    <input type="text" id="amount" name="amount" class="form-input" required
                           placeholder="0.00 or 0,00"
                           value="<?= isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : '' ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description *</label>
                <input type="text" id="description" name="description" class="form-input" required
                       placeholder="e.g., Grocery shopping, Paycheck, Gas"
                       value="<?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?>">
            </div>

            <div class="form-group">
                <label for="payee" class="form-label">Payee (Optional)</label>
                <div class="payee-autocomplete-wrapper">
                    <input type="text" id="payee" name="payee" class="form-input" autocomplete="off"
                           placeholder="Start typing payee name..."
                           value="<?= isset($_POST['payee']) ? htmlspecialchars($_POST['payee']) : '' ?>">
                    <div id="payee-suggestions" class="autocomplete-suggestions" style="display: none;"></div>
                </div>
                <small class="form-help">Auto-saves payees for faster future entry</small>
            </div>

            <div class="form-group">
                <label for="date" class="form-label">Date *</label>
                <input type="date" id="date" name="date" class="form-input" required
                       value="<?= isset($_POST['date']) ? $_POST['date'] : date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label for="account" class="form-label">Account *</label>
                <select id="account" name="account" class="form-select" required>
                    <option value="">Choose account...</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= $account['uuid'] ?>"
                                <?= (isset($_POST['account']) && $_POST['account'] === $account['uuid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($account['name']) ?> (<?= htmlspecialchars($account['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="category-group">
                <label for="category" class="form-label">Category</label>
                <select id="category" name="category" class="form-select">
                    <option value="">Choose category...</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['uuid'] ?>"
                                <?= (isset($_POST['category']) && $_POST['category'] === $category['uuid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="unassigned">Unassigned</option>
                </select>
                <small class="form-help">Leave blank to use default Income account for inflows</small>
            </div>

            <!-- Installment Plan Section (only for CC outflows) -->
            <div id="installment-section" class="installment-section" style="display: none;">
                <div class="installment-header">
                    <label class="installment-toggle">
                        <input type="checkbox" id="enable-installment" name="enable_installment" value="1">
                        <span class="installment-toggle-label">💳 Create installment plan for this purchase</span>
                    </label>
                    <small class="form-help">Spread this purchase across multiple budget periods</small>
                </div>

                <div id="installment-config" class="installment-config" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="number_of_installments" class="form-label">Number of Installments *</label>
                            <input type="range"
                                   id="installments-range"
                                   min="2"
                                   max="36"
                                   value="6"
                                   step="1"
                                   class="installment-range">
                            <div class="range-value-display">
                                <input type="number"
                                       id="number_of_installments"
                                       name="number_of_installments"
                                       min="2"
                                       max="36"
                                       value="6"
                                       class="form-input installment-number">
                                <span>payments</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="installment_frequency" class="form-label">Frequency *</label>
                            <select id="installment_frequency" name="installment_frequency" class="form-select">
                                <option value="monthly" selected>Monthly</option>
                                <option value="bi-weekly">Bi-weekly</option>
                                <option value="weekly">Weekly</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="installment_start_date" class="form-label">First Installment Date *</label>
                        <input type="date"
                               id="installment_start_date"
                               name="installment_start_date"
                               class="form-input"
                               value="">
                        <small class="form-help">When should the first installment be processed?</small>
                    </div>

                    <div class="form-group">
                        <label for="installment_notes" class="form-label">Notes (Optional)</label>
                        <textarea id="installment_notes"
                                  name="installment_notes"
                                  class="form-input"
                                  rows="2"
                                  placeholder="e.g., 0% APR promotion, special payment terms..."></textarea>
                    </div>

                    <div class="installment-preview">
                        <h4>Payment Preview</h4>
                        <div id="installment-preview-content">
                            <div class="preview-loading">Enter an amount to see payment breakdown</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="split-toggle">
                    <input type="checkbox" id="enable-split" name="enable_split" value="1">
                    Split this transaction across multiple categories
                </label>
            </div>

            <div id="split-container" style="display: none;">
                <input type="hidden" id="is-split" name="is_split" value="0">
                <div class="split-header">
                    <h3>Split Categories</h3>
                    <p class="split-help">Divide this transaction across multiple categories. The sum must equal the total amount.</p>
                </div>

                <div id="split-rows">
                    <!-- Split rows will be added here by JavaScript -->
                </div>

                <div class="split-summary">
                    <div class="split-summary-row">
                        <span>Total Amount:</span>
                        <span id="split-total-amount">$0.00</span>
                    </div>
                    <div class="split-summary-row">
                        <span>Assigned:</span>
                        <span id="split-assigned">$0.00</span>
                    </div>
                    <div class="split-summary-row split-remaining">
                        <span>Remaining:</span>
                        <span id="split-remaining">$0.00</span>
                    </div>
                </div>

                <button type="button" class="btn btn-secondary" id="add-split-row">+ Add Category</button>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add Transaction</button>
                <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="transaction-help">
        <h3>Transaction Types</h3>
        <div class="help-grid">
            <div class="help-item">
                <h4>Income (Inflow)</h4>
                <p>Money coming into your budget from external sources like salary, gifts, or returns.</p>
                <ul>
                    <li>Increases your account balance</li>
                    <li>Goes to Income category by default</li>
                    <li>Must be assigned to budget categories</li>
                </ul>
            </div>
            <div class="help-item">
                <h4>Expense (Outflow)</h4>
                <p>Money leaving your budget for purchases, bills, or transfers.</p>
                <ul>
                    <li>Decreases your account balance</li>
                    <li>Assigned to a budget category</li>
                    <li>Reduces available budget in that category</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 0 auto 3rem;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.transaction-form {
    width: 100%;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

.form-help {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
}

.transaction-help {
    max-width: 800px;
    margin: 0 auto;
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.help-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-top: 1rem;
}

.help-item h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.help-item p {
    color: #4a5568;
    margin-bottom: 1rem;
}

.help-item ul {
    padding-left: 1.5rem;
}

.help-item li {
    color: #4a5568;
    margin-bottom: 0.25rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .help-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .form-actions {
        flex-direction: column;
    }
}

/* Installment plan styles */
.installment-section {
    background: #ebf8ff;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #bee3f8;
    margin-top: 1rem;
}

.installment-header {
    margin-bottom: 1rem;
}

.installment-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.installment-toggle input[type="checkbox"] {
    cursor: pointer;
    width: 18px;
    height: 18px;
}

.installment-toggle-label {
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.installment-config {
    background: white;
    padding: 1.5rem;
    border-radius: 6px;
    margin-top: 1rem;
    border: 1px solid #bee3f8;
}

.installment-range {
    width: 100%;
    margin: 0.5rem 0;
    cursor: pointer;
}

.range-value-display {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.installment-number {
    width: 80px;
    text-align: center;
    font-weight: 600;
}

.installment-preview {
    background: #f7fafc;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    margin-top: 1.5rem;
}

.installment-preview h4 {
    color: #2d3748;
    margin: 0 0 0.75rem 0;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#installment-preview-content {
    font-size: 0.875rem;
}

.preview-loading {
    color: #718096;
    font-style: italic;
}

.preview-installments {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.preview-installment-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem;
    background: white;
    border-radius: 4px;
    border: 1px solid #e2e8f0;
}

.preview-installment-info {
    color: #4a5568;
}

.preview-installment-amount {
    font-weight: 600;
    color: #2d3748;
}

.preview-total {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 2px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}

.preview-total-label {
    color: #4a5568;
}

.preview-total-amount {
    color: #2d3748;
    font-size: 1.1rem;
}

.preview-total-amount.match {
    color: #38a169;
}

.preview-total-amount.no-match {
    color: #e53e3e;
}

@media (max-width: 768px) {
    .installment-config .form-row {
        grid-template-columns: 1fr;
    }

    .range-value-display {
        justify-content: center;
    }
}

/* Split transaction styles */
.split-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    color: #2d3748;
}

.split-toggle input[type="checkbox"] {
    cursor: pointer;
    width: 18px;
    height: 18px;
}

#split-container {
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    margin-top: 1rem;
}

.split-header h3 {
    color: #2d3748;
    margin: 0 0 0.5rem 0;
    font-size: 1.125rem;
}

.split-help {
    color: #718096;
    font-size: 0.875rem;
    margin: 0 0 1rem 0;
}

.split-row {
    display: grid;
    grid-template-columns: 2fr 1fr 2fr auto;
    gap: 0.75rem;
    align-items: start;
    margin-bottom: 0.75rem;
    padding: 1rem;
    background: white;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.split-row .form-group {
    margin: 0;
}

.split-row .form-label {
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.split-row .form-input,
.split-row .form-select {
    font-size: 0.875rem;
}

.remove-split {
    align-self: end;
    background: #e53e3e;
    color: white;
    border: none;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.875rem;
    margin-bottom: 0.125rem;
}

.remove-split:hover {
    background: #c53030;
}

.split-summary {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    margin: 1rem 0;
    border: 2px solid #e2e8f0;
}

.split-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    color: #4a5568;
}

.split-summary-row.split-remaining {
    border-top: 2px solid #e2e8f0;
    margin-top: 0.5rem;
    padding-top: 0.75rem;
    font-weight: 600;
    font-size: 1.125rem;
}

.split-remaining.positive {
    color: #38a169;
}

.split-remaining.negative {
    color: #e53e3e;
}

.split-remaining.zero {
    color: #3182ce;
}

#add-split-row {
    margin-top: 0.5rem;
}

@media (max-width: 768px) {
    .split-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .remove-split {
        width: 100%;
        margin-top: 0.5rem;
    }
}

/* Payee Autocomplete Styles */
.payee-autocomplete-wrapper {
    position: relative;
}

.autocomplete-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 6px 6px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: -1px;
}

.autocomplete-suggestion {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s;
}

.autocomplete-suggestion:last-child {
    border-bottom: none;
}

.autocomplete-suggestion:hover,
.autocomplete-suggestion.active {
    background-color: #ebf8ff;
}

.autocomplete-payee-name {
    font-weight: 500;
    color: #2d3748;
    display: block;
}

.autocomplete-payee-meta {
    font-size: 0.75rem;
    color: #718096;
    margin-top: 0.25rem;
}

.autocomplete-empty {
    padding: 0.75rem 1rem;
    color: #a0aec0;
    font-style: italic;
    text-align: center;
}
</style>

<script>
function updateFormForType() {
    const type = document.getElementById('type').value;
    const categoryGroup = document.getElementById('category-group');
    const categorySelect = document.getElementById('category');
    const categoryLabel = categoryGroup.querySelector('label');

    if (type === 'inflow') {
        categoryLabel.textContent = 'Category (Optional)';
        categoryGroup.querySelector('.form-help').textContent = 'Leave blank to use default Income account';
        categorySelect.required = false;
    } else if (type === 'outflow') {
        categoryLabel.textContent = 'Category *';
        categoryGroup.querySelector('.form-help').textContent = 'Choose the budget category for this expense';
        categorySelect.required = true;
    }

    // Update installment section visibility
    updateInstallmentVisibility();
}

function updateInstallmentVisibility() {
    const type = document.getElementById('type').value;
    const account = document.getElementById('account').value;
    const installmentSection = document.getElementById('installment-section');

    // Only show installment option for outflows on credit card accounts
    if (type === 'outflow' && account) {
        // Check if selected account is a liability (credit card)
        const accountSelect = document.getElementById('account');
        const selectedOption = accountSelect.options[accountSelect.selectedIndex];
        const accountType = selectedOption.textContent.match(/\((.*?)\)/)?.[1];

        if (accountType === 'liability') {
            installmentSection.style.display = 'block';
        } else {
            installmentSection.style.display = 'none';
            // Reset installment checkbox if hidden
            document.getElementById('enable-installment').checked = false;
            document.getElementById('installment-config').style.display = 'none';
        }
    } else {
        installmentSection.style.display = 'none';
        // Reset installment checkbox if hidden
        document.getElementById('enable-installment').checked = false;
        document.getElementById('installment-config').style.display = 'none';
    }
}

// Installment Plan Management
function initializeInstallment() {
    const enableInstallmentCheckbox = document.getElementById('enable-installment');
    const installmentConfig = document.getElementById('installment-config');
    const installmentsRange = document.getElementById('installments-range');
    const numberOfInstallments = document.getElementById('number_of_installments');
    const startDateInput = document.getElementById('installment_start_date');
    const frequencySelect = document.getElementById('installment_frequency');
    const amountInput = document.getElementById('amount');

    // Set default start date to next month
    const nextMonth = new Date();
    nextMonth.setMonth(nextMonth.getMonth() + 1);
    nextMonth.setDate(1); // First day of next month
    startDateInput.value = nextMonth.toISOString().split('T')[0];

    // Toggle installment config visibility
    enableInstallmentCheckbox.addEventListener('change', function() {
        if (this.checked) {
            installmentConfig.style.display = 'block';
            updateInstallmentPreview();
            // Disable split transaction if installment is enabled
            const enableSplit = document.getElementById('enable-split');
            if (enableSplit.checked) {
                enableSplit.checked = false;
                document.getElementById('split-container').style.display = 'none';
                document.getElementById('is-split').value = '0';
                document.getElementById('category-group').style.display = 'block';
            }
        } else {
            installmentConfig.style.display = 'none';
        }
    });

    // Sync range slider with number input
    installmentsRange.addEventListener('input', function() {
        numberOfInstallments.value = this.value;
        updateInstallmentPreview();
    });

    numberOfInstallments.addEventListener('input', function() {
        const value = parseInt(this.value);
        if (value >= 2 && value <= 36) {
            installmentsRange.value = value;
            updateInstallmentPreview();
        }
    });

    // Update preview when amount, frequency, or start date changes
    amountInput.addEventListener('input', updateInstallmentPreview);
    frequencySelect.addEventListener('change', updateInstallmentPreview);
    startDateInput.addEventListener('change', updateInstallmentPreview);
}

function updateInstallmentPreview() {
    const enableInstallment = document.getElementById('enable-installment').checked;
    if (!enableInstallment) return;

    const amountInput = document.getElementById('amount');
    const numberOfInstallments = parseInt(document.getElementById('number_of_installments').value);
    const frequency = document.getElementById('installment_frequency').value;
    const startDate = document.getElementById('installment_start_date').value;
    const previewContent = document.getElementById('installment-preview-content');

    // Parse amount
    let amountValue = amountInput.value.replace(/[^0-9,.]/g, '');
    amountValue = amountValue.replace(',', '.');
    const amount = parseFloat(amountValue);

    if (isNaN(amount) || amount <= 0 || !numberOfInstallments || numberOfInstallments < 2) {
        previewContent.innerHTML = '<div class="preview-loading">Enter an amount to see payment breakdown</div>';
        return;
    }

    // Calculate installment amounts
    const installmentAmount = Math.floor((amount * 100) / numberOfInstallments) / 100;
    const totalScheduled = installmentAmount * (numberOfInstallments - 1);
    const lastInstallment = Math.round((amount - totalScheduled) * 100) / 100;

    // Generate preview HTML
    let html = '<div class="preview-installments">';

    // Calculate dates
    let currentDate = new Date(startDate || new Date());
    if (!startDate) {
        currentDate.setMonth(currentDate.getMonth() + 1);
        currentDate.setDate(1);
    }

    for (let i = 1; i <= Math.min(numberOfInstallments, 6); i++) {
        const displayAmount = (i === numberOfInstallments) ? lastInstallment : installmentAmount;
        const dateStr = currentDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        html += `
            <div class="preview-installment-row">
                <span class="preview-installment-info">Payment ${i} - ${dateStr}</span>
                <span class="preview-installment-amount">$${displayAmount.toFixed(2)}</span>
            </div>
        `;

        // Calculate next date
        switch (frequency) {
            case 'weekly':
                currentDate.setDate(currentDate.getDate() + 7);
                break;
            case 'bi-weekly':
                currentDate.setDate(currentDate.getDate() + 14);
                break;
            case 'monthly':
            default:
                currentDate.setMonth(currentDate.getMonth() + 1);
                break;
        }
    }

    if (numberOfInstallments > 6) {
        html += `<div class="preview-installment-row" style="font-style: italic; opacity: 0.7;">
            <span class="preview-installment-info">... and ${numberOfInstallments - 6} more payments</span>
            <span class="preview-installment-amount">$${installmentAmount.toFixed(2)} each</span>
        </div>`;
    }

    html += '</div>';

    // Add total
    const calculatedTotal = (installmentAmount * (numberOfInstallments - 1)) + lastInstallment;
    const matches = Math.abs(calculatedTotal - amount) < 0.01;

    html += `
        <div class="preview-total">
            <span class="preview-total-label">Total:</span>
            <span class="preview-total-amount ${matches ? 'match' : 'no-match'}">
                $${calculatedTotal.toFixed(2)} ${matches ? '✓' : '⚠'}
            </span>
        </div>
    `;

    if (numberOfInstallments > 1) {
        html += `<div style="margin-top: 0.5rem; font-size: 0.75rem; color: #718096;">
            ${numberOfInstallments - 1} payments of $${installmentAmount.toFixed(2)} + 1 payment of $${lastInstallment.toFixed(2)}
        </div>`;
    }

    previewContent.innerHTML = html;
}

// Handle amount input with both comma and period decimal separators
document.getElementById('amount').addEventListener('input', function(e) {
    let value = e.target.value;

    // Remove any characters that aren't digits, comma, or period
    value = value.replace(/[^0-9,.]/g, '');

    // If there's both comma and period, keep only the last one as decimal separator
    const commaIndex = value.lastIndexOf(',');
    const periodIndex = value.lastIndexOf('.');

    if (commaIndex !== -1 && periodIndex !== -1) {
        if (commaIndex > periodIndex) {
            // Comma is the decimal separator, remove periods
            value = value.replace(/\./g, '');
        } else {
            // Period is the decimal separator, remove commas
            value = value.replace(/,/g, '');
        }
    }

    // Ensure only one decimal separator and at most 2 decimal places
    if (value.includes(',')) {
        const parts = value.split(',');
        if (parts.length > 2) {
            value = parts[0] + ',' + parts.slice(1).join('');
        }
        if (parts[1] && parts[1].length > 2) {
            value = parts[0] + ',' + parts[1].substring(0, 2);
        }
    } else if (value.includes('.')) {
        const parts = value.split('.');
        if (parts.length > 2) {
            value = parts[0] + '.' + parts.slice(1).join('');
        }
        if (parts[1] && parts[1].length > 2) {
            value = parts[0] + '.' + parts[1].substring(0, 2);
        }
    }

    e.target.value = value;
});

// Validate amount before form submission
document.querySelector('.transaction-form').addEventListener('submit', function(e) {
    const amountInput = document.getElementById('amount');
    let value = amountInput.value.replace(/[^0-9,.]/g, '');

    // Convert comma to period for validation
    const normalizedValue = value.replace(',', '.');
    const numValue = parseFloat(normalizedValue);

    if (isNaN(numValue) || numValue <= 0) {
        e.preventDefault();
        alert('Please enter a valid amount greater than 0');
        amountInput.focus();
        return false;
    }
});

// Initialize form state
document.addEventListener('DOMContentLoaded', function() {
    updateFormForType();
    initializeSplitTransaction();
    initializeInstallment();
    initializePayeeAutocomplete();

    // Add listener for account changes to update installment visibility
    document.getElementById('account').addEventListener('change', updateInstallmentVisibility);
    document.getElementById('type').addEventListener('change', updateInstallmentVisibility);
});

// Split Transaction Management
let splitRowCounter = 0;
const categories = <?= json_encode($categories) ?>;

function initializeSplitTransaction() {
    const enableSplitCheckbox = document.getElementById('enable-split');
    const splitContainer = document.getElementById('split-container');
    const categoryGroup = document.getElementById('category-group');
    const addSplitButton = document.getElementById('add-split-row');
    const amountInput = document.getElementById('amount');
    const isSplitInput = document.getElementById('is-split');

    // Toggle split mode
    enableSplitCheckbox.addEventListener('change', function() {
        if (this.checked) {
            splitContainer.style.display = 'block';
            categoryGroup.style.display = 'none';
            isSplitInput.value = '1';

            // Disable installments if split is enabled
            const enableInstallment = document.getElementById('enable-installment');
            if (enableInstallment.checked) {
                enableInstallment.checked = false;
                document.getElementById('installment-config').style.display = 'none';
            }

            // Add first split row if none exist
            if (document.querySelectorAll('.split-row').length === 0) {
                addSplitRow();
            }
            updateSplitSummary();
        } else {
            splitContainer.style.display = 'none';
            categoryGroup.style.display = 'block';
            isSplitInput.value = '0';
        }
    });

    // Add split row button
    addSplitButton.addEventListener('click', function() {
        addSplitRow();
    });

    // Update summary when amount changes
    amountInput.addEventListener('input', function() {
        updateSplitSummary();
    });
}

function addSplitRow(categoryUuid = '', amount = '', memo = '') {
    const splitRows = document.getElementById('split-rows');
    const rowId = splitRowCounter++;

    const row = document.createElement('div');
    row.className = 'split-row';
    row.dataset.rowId = rowId;

    let categoryOptions = '<option value="">Choose category...</option>';
    categories.forEach(cat => {
        const selected = cat.uuid === categoryUuid ? 'selected' : '';
        categoryOptions += `<option value="${cat.uuid}" ${selected}>${cat.name}</option>`;
    });

    row.innerHTML = `
        <div class="form-group">
            <label class="form-label">Category</label>
            <select name="split_category[]" class="form-select split-category" required>
                ${categoryOptions}
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Amount</label>
            <input type="text" name="split_amount[]" class="form-input split-amount"
                   placeholder="0.00" value="${amount}" required>
        </div>
        <div class="form-group">
            <label class="form-label">Memo (optional)</label>
            <input type="text" name="split_memo[]" class="form-input split-memo"
                   placeholder="Notes..." value="${memo}">
        </div>
        <button type="button" class="remove-split" onclick="removeSplitRow(${rowId})">Remove</button>
    `;

    splitRows.appendChild(row);

    // Add event listeners for split amount inputs
    const amountInput = row.querySelector('.split-amount');
    amountInput.addEventListener('input', function(e) {
        let value = e.target.value;
        value = value.replace(/[^0-9,.]/g, '');

        const commaIndex = value.lastIndexOf(',');
        const periodIndex = value.lastIndexOf('.');

        if (commaIndex !== -1 && periodIndex !== -1) {
            if (commaIndex > periodIndex) {
                value = value.replace(/\./g, '');
            } else {
                value = value.replace(/,/g, '');
            }
        }

        if (value.includes(',')) {
            const parts = value.split(',');
            if (parts.length > 2) {
                value = parts[0] + ',' + parts.slice(1).join('');
            }
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + ',' + parts[1].substring(0, 2);
            }
        } else if (value.includes('.')) {
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            if (parts[1] && parts[1].length > 2) {
                value = parts[0] + '.' + parts[1].substring(0, 2);
            }
        }

        e.target.value = value;
        updateSplitSummary();
    });

    updateSplitSummary();
}

function removeSplitRow(rowId) {
    const row = document.querySelector(`[data-row-id="${rowId}"]`);
    if (row) {
        row.remove();
        updateSplitSummary();
    }

    // Add a row back if all rows are removed
    if (document.querySelectorAll('.split-row').length === 0) {
        addSplitRow();
    }
}

function updateSplitSummary() {
    const totalAmountInput = document.getElementById('amount');
    const totalAmountText = document.getElementById('split-total-amount');
    const assignedText = document.getElementById('split-assigned');
    const remainingText = document.getElementById('split-remaining');
    const remainingRow = document.querySelector('.split-remaining');

    // Parse total amount
    let totalAmountValue = totalAmountInput.value.replace(/[^0-9,.]/g, '');
    totalAmountValue = totalAmountValue.replace(',', '.');
    const totalAmount = parseFloat(totalAmountValue) || 0;

    // Calculate assigned amount
    let assignedAmount = 0;
    document.querySelectorAll('.split-amount').forEach(input => {
        let value = input.value.replace(/[^0-9,.]/g, '');
        value = value.replace(',', '.');
        assignedAmount += parseFloat(value) || 0;
    });

    // Calculate remaining
    const remaining = totalAmount - assignedAmount;

    // Format currency
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount);
    };

    // Update display
    totalAmountText.textContent = formatCurrency(totalAmount);
    assignedText.textContent = formatCurrency(assignedAmount);
    remainingText.textContent = formatCurrency(remaining);

    // Update styling based on remaining amount
    remainingRow.classList.remove('positive', 'negative', 'zero');
    if (remaining > 0.01) {
        remainingRow.classList.add('positive');
    } else if (remaining < -0.01) {
        remainingRow.classList.add('negative');
    } else {
        remainingRow.classList.add('zero');
    }
}

// Handle installment transaction submission via AJAX
document.querySelector('.transaction-form').addEventListener('submit', function(e) {
    const enableInstallment = document.getElementById('enable-installment').checked;

    if (enableInstallment) {
        e.preventDefault();

        // Gather form data
        const formData = {
            ledger_uuid: document.querySelector('input[name="ledger_uuid"]')?.value || '<?= $ledger_uuid ?>',
            type: document.getElementById('type').value,
            amount: document.getElementById('amount').value,
            date: document.getElementById('date').value,
            description: document.getElementById('description').value,
            account: document.getElementById('account').value,
            category: document.getElementById('category').value,
            payee: document.getElementById('payee').value,
            installment: {
                enabled: true,
                number_of_installments: parseInt(document.getElementById('number_of_installments').value),
                start_date: document.getElementById('installment_start_date').value,
                frequency: document.getElementById('installment_frequency').value,
                notes: document.getElementById('installment_notes').value
            }
        };

        // Show loading state
        const submitButton = e.target.querySelector('button[type="submit"]');
        const originalText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Creating...';

        // Send to combined API endpoint
        fetch('/pgbudget/public/api/add-transaction-with-installment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message and redirect
                alert(data.message || 'Transaction and installment plan created successfully!');
                window.location.href = '../budget/dashboard.php?ledger=' + formData.ledger_uuid;
            } else {
                // Show error
                alert('Error: ' + (data.error || 'Failed to create transaction and installment plan'));
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        });

        return false;
    }
});

// Validate split transaction before submission
document.querySelector('.transaction-form').addEventListener('submit', function(e) {
    const isSplit = document.getElementById('is-split').value === '1';

    if (isSplit) {
        const totalAmountInput = document.getElementById('amount');
        let totalAmountValue = totalAmountInput.value.replace(/[^0-9,.]/g, '');
        totalAmountValue = totalAmountValue.replace(',', '.');
        const totalAmount = parseFloat(totalAmountValue) || 0;

        let assignedAmount = 0;
        document.querySelectorAll('.split-amount').forEach(input => {
            let value = input.value.replace(/[^0-9,.]/g, '');
            value = value.replace(',', '.');
            assignedAmount += parseFloat(value) || 0;
        });

        const remaining = Math.abs(totalAmount - assignedAmount);

        if (remaining > 0.01) {
            e.preventDefault();
            alert(`Split amounts must equal total transaction amount. Remaining: $${remaining.toFixed(2)}`);
            return false;
        }

        // Check that all splits have categories
        const splitCategories = document.querySelectorAll('.split-category');
        let hasEmptyCategory = false;
        splitCategories.forEach(select => {
            if (!select.value) {
                hasEmptyCategory = true;
            }
        });

        if (hasEmptyCategory) {
            e.preventDefault();
            alert('Please select a category for all splits.');
            return false;
        }
    }
});

// Payee Autocomplete
function initializePayeeAutocomplete() {
    const payeeInput = document.getElementById('payee');
    const suggestionsContainer = document.getElementById('payee-suggestions');
    let activeIndex = -1;
    let searchTimeout = null;
    let currentSuggestions = [];

    // Search payees with debounce
    payeeInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        if (query.length < 2) {
            hideSuggestions();
            return;
        }

        searchTimeout = setTimeout(() => {
            searchPayees(query);
        }, 300);
    });

    // Keyboard navigation
    payeeInput.addEventListener('keydown', function(e) {
        if (!suggestionsContainer.style.display || suggestionsContainer.style.display === 'none') {
            return;
        }

        const suggestions = suggestionsContainer.querySelectorAll('.autocomplete-suggestion');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, suggestions.length - 1);
            updateActiveSuggestion(suggestions);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, -1);
            updateActiveSuggestion(suggestions);
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            suggestions[activeIndex].click();
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    });

    // Click outside to close
    document.addEventListener('click', function(e) {
        if (!payeeInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
            hideSuggestions();
        }
    });

    function searchPayees(query) {
        fetch(`/pgbudget/public/api/payees-search.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                currentSuggestions = data;
                displaySuggestions(data);
            })
            .catch(error => {
                console.error('Error searching payees:', error);
                hideSuggestions();
            });
    }

    function displaySuggestions(payees) {
        activeIndex = -1;

        if (payees.length === 0) {
            suggestionsContainer.innerHTML = '<div class="autocomplete-empty">No payees found. Type to create new.</div>';
            suggestionsContainer.style.display = 'block';
            return;
        }

        let html = '';
        payees.forEach((payee, index) => {
            let meta = [];
            if (payee.transaction_count > 0) {
                meta.push(`${payee.transaction_count} transactions`);
            }
            if (payee.default_category_name) {
                meta.push(`Default: ${payee.default_category_name}`);
            }

            html += `
                <div class="autocomplete-suggestion" data-index="${index}">
                    <span class="autocomplete-payee-name">${escapeHtml(payee.name)}</span>
                    ${meta.length > 0 ? `<div class="autocomplete-payee-meta">${meta.join(' • ')}</div>` : ''}
                </div>
            `;
        });

        suggestionsContainer.innerHTML = html;
        suggestionsContainer.style.display = 'block';

        // Add click handlers
        suggestionsContainer.querySelectorAll('.autocomplete-suggestion').forEach((el, index) => {
            el.addEventListener('click', function() {
                selectPayee(currentSuggestions[index]);
            });
        });
    }

    function updateActiveSuggestion(suggestions) {
        suggestions.forEach((el, index) => {
            if (index === activeIndex) {
                el.classList.add('active');
                el.scrollIntoView({ block: 'nearest' });
            } else {
                el.classList.remove('active');
            }
        });
    }

    function selectPayee(payee) {
        payeeInput.value = payee.name;
        hideSuggestions();

        // Auto-fill category if available and auto_categorize is enabled
        if (payee.auto_categorize && payee.default_category_uuid) {
            const categorySelect = document.getElementById('category');
            if (categorySelect && !categorySelect.value) {
                categorySelect.value = payee.default_category_uuid;
            }
        }
    }

    function hideSuggestions() {
        suggestionsContainer.style.display = 'none';
        suggestionsContainer.innerHTML = '';
        activeIndex = -1;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
</script>

<!-- Overspending Modal -->
<div id="overspending-modal" class="modal-backdrop" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>⚠️ Hold on!</h2>
        </div>
        <div class="modal-body">
            <p>This would overspend your "<span id="overspent-category-name"></span>" category by <span id="overspent-amount"></span>.</p>
            <p>What would you like to do?</p>
            <ul>
                <li>Move money from another category</li>
                <li>Record it anyway (creates overspending)</li>
                <li>Cancel and adjust the amount</li>
            </ul>
        </div>
        <div class="modal-footer">
            <button type="button" id="move-money-btn" class="btn btn-primary">Move Money</button>
            <button type="button" id="record-anyway-btn" class="btn btn-secondary">Record Anyway</button>
            <button type="button" id="cancel-btn" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['overspending_error'])):
        $overspending_error = $_SESSION['overspending_error'];
        unset($_SESSION['overspending_error']); // Unset after use
    ?>
        const modal = document.getElementById('overspending-modal');
        const overspentCategory = document.getElementById('overspent-category-name');
        const overspentAmount = document.getElementById('overspent-amount');
        const originalData = <?= json_encode($overspending_error['original_data']) ?>;

        overspentCategory.textContent = '<?= addslashes($overspending_error['category_name']) ?>';
        overspentAmount.textContent = formatCurrency(<?= $overspending_error['overspent_amount'] ?>);
        modal.style.display = 'flex';

        // "Record Anyway" button
        document.getElementById('record-anyway-btn').addEventListener('click', function() {
            const form = document.querySelector('.transaction-form');
            // Add a hidden input to the form to allow overspending
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'allow_overspending';
            hiddenInput.value = 'true';
            form.appendChild(hiddenInput);
            // Repopulate form and submit
            repopulateForm(form, originalData);
            form.submit();
        });

        // "Cancel" button
        document.getElementById('cancel-btn').addEventListener('click', function() {
            modal.style.display = 'none';
            // Repopulate form so user can edit
            repopulateForm(document.querySelector('.transaction-form'), originalData);
        });

        // "Move Money" button (to be implemented)
        document.getElementById('move-money-btn').addEventListener('click', function() {
            alert('"Move Money" functionality is not yet implemented.');
        });

        function repopulateForm(form, data) {
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    const field = form.querySelector(`[name="${key}"]`);
                    if (field) {
                        field.value = data[key];
                    }
                }
            }
        }
    <?php endif; ?>
});
</script>

<?php require_once '../../includes/footer.php'; ?>