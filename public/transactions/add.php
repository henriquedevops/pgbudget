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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = sanitizeInput($_POST['description']);
    $amount = parseCurrency($_POST['amount']);
    $date = $_POST['date'];
    $type = $_POST['type']; // 'inflow' or 'outflow'
    $account_uuid = sanitizeInput($_POST['account']);
    $category_uuid = sanitizeInput($_POST['category']);
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
                $stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $ledger_uuid,
                    $date,
                    $description,
                    $type,
                    $amount,
                    $account_uuid,
                    ($category_uuid && $category_uuid !== 'unassigned') ? $category_uuid : null
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
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
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

    // Get categories (equity accounts that aren't special)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
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
                    <label for="type" class="form-label">Transaction Type *</label>
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
</script>

<?php require_once '../../includes/footer.php'; ?>