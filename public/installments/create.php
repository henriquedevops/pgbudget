<?php
/**
 * Create Installment Plan Page
 * Form to create a new installment payment plan for credit card purchases
 * Part of Step 3.1 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

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

    // Get credit card accounts (liability accounts)
    $stmt = $db->prepare("
        SELECT uuid, name, balance
        FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'liability'
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $credit_cards = $stmt->fetchAll();

    // Get category accounts (equity accounts)
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

    // Get recent credit card transactions for quick selection
    $stmt = $db->prepare("
        SELECT
            t.uuid,
            t.date,
            t.description,
            t.amount,
            a.name as account_name,
            a.uuid as account_uuid
        FROM api.transactions t
        JOIN api.accounts a ON (
            (t.debit_account_uuid = a.uuid OR t.credit_account_uuid = a.uuid)
            AND a.type = 'liability'
        )
        WHERE t.ledger_uuid = ?
        ORDER BY t.date DESC
        LIMIT 50
    ");
    $stmt->execute([$ledger_uuid]);
    $recent_transactions = $stmt->fetchAll();

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
            <h1>Create Installment Plan</h1>
            <p>Spread a large purchase across multiple budget periods in <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Plans</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createInstallmentForm" class="installment-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Transaction Selection or Manual Entry -->
            <div class="form-section">
                <h3>Purchase Information</h3>

                <div class="form-group">
                    <label>Entry Method</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="entry_method" value="manual" checked>
                            <span>Manual Entry</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="entry_method" value="transaction">
                            <span>Select from Recent Transaction</span>
                        </label>
                    </div>
                </div>

                <!-- Transaction Selector -->
                <div id="transaction-selector" class="form-group" style="display: none;">
                    <label for="original_transaction_uuid">Recent Credit Card Transaction</label>
                    <select id="original_transaction_uuid" name="original_transaction_uuid">
                        <option value="">Select a transaction...</option>
                        <?php foreach ($recent_transactions as $txn): ?>
                            <option value="<?= $txn['uuid'] ?>"
                                    data-amount="<?= $txn['amount'] / 100 ?>"
                                    data-date="<?= $txn['date'] ?>"
                                    data-description="<?= htmlspecialchars($txn['description']) ?>"
                                    data-account="<?= $txn['account_uuid'] ?>">
                                <?= date('Y-m-d', strtotime($txn['date'])) ?> -
                                <?= htmlspecialchars($txn['description']) ?> -
                                $<?= number_format($txn['amount'] / 100, 2) ?> -
                                <?= htmlspecialchars($txn['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Select a recent credit card transaction to create an installment plan</small>
                </div>

                <!-- Manual Entry Fields -->
                <div id="manual-entry" class="manual-entry-fields">
                    <div class="form-group">
                        <label for="credit_card_account_uuid">Credit Card *</label>
                        <select id="credit_card_account_uuid" name="credit_card_account_uuid" required>
                            <option value="">Select credit card...</option>
                            <?php foreach ($credit_cards as $card): ?>
                                <option value="<?= $card['uuid'] ?>">
                                    <?= htmlspecialchars($card['name']) ?>
                                    (Balance: $<?= number_format($card['balance'] / 100, 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Which credit card was used for this purchase?</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="purchase_amount">Purchase Amount *</label>
                            <input type="number"
                                   id="purchase_amount"
                                   name="purchase_amount"
                                   required
                                   min="0.01"
                                   step="0.01"
                                   placeholder="1200.00">
                            <small class="form-hint">Total purchase amount</small>
                        </div>

                        <div class="form-group">
                            <label for="purchase_date">Purchase Date *</label>
                            <input type="date"
                                   id="purchase_date"
                                   name="purchase_date"
                                   required
                                   value="<?= date('Y-m-d') ?>">
                            <small class="form-hint">When was the purchase made?</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description *</label>
                        <input type="text"
                               id="description"
                               name="description"
                               required
                               maxlength="255"
                               placeholder="e.g., New laptop, Furniture set, Electronics">
                        <small class="form-hint">Brief description of the purchase</small>
                    </div>
                </div>
            </div>

            <!-- Installment Configuration -->
            <div class="form-section">
                <h3>Installment Configuration</h3>

                <div class="form-group">
                    <label for="number_of_installments">Number of Installments *</label>
                    <div class="slider-container">
                        <input type="range"
                               id="installments_slider"
                               min="2"
                               max="36"
                               value="6"
                               class="slider">
                        <input type="number"
                               id="number_of_installments"
                               name="number_of_installments"
                               required
                               min="2"
                               max="36"
                               value="6"
                               class="slider-value">
                        <span class="slider-label">months</span>
                    </div>
                    <small class="form-hint">How many payments to spread this purchase over? (2-36 months)</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">First Installment Date *</label>
                        <input type="date"
                               id="start_date"
                               name="start_date"
                               required
                               value="<?= date('Y-m-d', strtotime('first day of next month')) ?>">
                        <small class="form-hint">When should the first installment be processed?</small>
                    </div>

                    <div class="form-group">
                        <label for="frequency">Payment Frequency *</label>
                        <select id="frequency" name="frequency" required>
                            <option value="monthly" selected>Monthly</option>
                            <option value="bi-weekly">Bi-weekly (every 2 weeks)</option>
                            <option value="weekly">Weekly</option>
                        </select>
                        <small class="form-hint">How often to process installments</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="category_account_uuid">Budget Category *</label>
                    <select id="category_account_uuid" name="category_account_uuid" required>
                        <option value="">Select category...</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['uuid'] ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Which category should absorb the monthly installments?</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"
                              placeholder="Additional notes about this installment plan..."></textarea>
                    <small class="form-hint">Any additional information</small>
                </div>
            </div>

            <!-- Preview Section -->
            <div class="form-section preview-section">
                <h3>Installment Preview</h3>

                <div class="installment-summary" id="installment-summary">
                    <div class="summary-card">
                        <div class="summary-item">
                            <span class="summary-label">Total Purchase:</span>
                            <span class="summary-value" id="preview-total">$0.00</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Number of Payments:</span>
                            <span class="summary-value" id="preview-count">6</span>
                        </div>
                        <div class="summary-item highlight">
                            <span class="summary-label">Each Payment:</span>
                            <span class="summary-value" id="preview-payment">$0.00</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Last Payment:</span>
                            <span class="summary-value" id="preview-last-payment">$0.00</span>
                        </div>
                    </div>
                </div>

                <div class="schedule-preview" id="schedule-preview">
                    <h4>Payment Schedule</h4>
                    <div class="schedule-table-wrapper">
                        <table class="schedule-table" id="schedule-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody id="schedule-body">
                                <!-- Generated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="validation-message" id="validation-message" style="display: none;"></div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?ledger=<?= $ledger_uuid ?>'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="submit-btn">
                    Create Installment Plan
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.installment-form {
    max-width: 1000px;
    margin: 0 auto;
}

.form-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 1.25rem;
    color: #1a202c;
    border-bottom: 2px solid #3182ce;
    padding-bottom: 8px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #2d3748;
}

.form-group input[type="text"],
.form-group input[type="number"],
.form-group input[type="date"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-hint {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: #718096;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.radio-group {
    display: flex;
    gap: 20px;
}

.radio-label {
    display: flex;
    align-items: center;
    cursor: pointer;
}

.radio-label input[type="radio"] {
    margin-right: 8px;
}

.slider-container {
    display: flex;
    align-items: center;
    gap: 12px;
}

.slider {
    flex: 1;
    height: 6px;
    -webkit-appearance: none;
    appearance: none;
    background: #e2e8f0;
    border-radius: 3px;
    outline: none;
}

.slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 20px;
    height: 20px;
    background: #3182ce;
    border-radius: 50%;
    cursor: pointer;
}

.slider::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: #3182ce;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

.slider-value {
    width: 60px;
    text-align: center;
}

.slider-label {
    font-size: 14px;
    color: #718096;
}

.preview-section {
    background: #f7fafc;
}

.installment-summary {
    margin-bottom: 24px;
}

.summary-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f7fafc;
    border-radius: 6px;
}

.summary-item.highlight {
    background: #ebf8ff;
    border: 2px solid #3182ce;
}

.summary-label {
    font-weight: 600;
    color: #4a5568;
}

.summary-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a202c;
}

.schedule-preview h4 {
    margin-top: 0;
    margin-bottom: 12px;
    color: #2d3748;
}

.schedule-table-wrapper {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
}

.schedule-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}

.schedule-table thead {
    position: sticky;
    top: 0;
    background: #f7fafc;
    z-index: 1;
}

.schedule-table th,
.schedule-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.schedule-table th {
    font-weight: 600;
    color: #2d3748;
}

.schedule-table tbody tr:hover {
    background: #f7fafc;
}

.validation-message {
    margin-top: 16px;
    padding: 12px;
    border-radius: 6px;
    font-weight: 500;
}

.validation-message.success {
    background: #c6f6d5;
    border: 1px solid #68d391;
    color: #22543d;
}

.validation-message.error {
    background: #fed7d7;
    border: 1px solid #fc8181;
    color: #742a2a;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #3182ce;
    color: white;
}

.btn-primary:hover {
    background: #2c5282;
}

.btn-secondary {
    background: #e2e8f0;
    color: #2d3748;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .summary-card {
        grid-template-columns: 1fr;
    }
}
</style>

<script src="/pgbudget/js/installments.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('createInstallmentForm');
    const entryMethodRadios = document.querySelectorAll('input[name="entry_method"]');
    const transactionSelector = document.getElementById('transaction-selector');
    const manualEntry = document.getElementById('manual-entry');
    const originalTransactionSelect = document.getElementById('original_transaction_uuid');

    // Handle entry method toggle
    entryMethodRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'transaction') {
                transactionSelector.style.display = 'block';
                manualEntry.style.display = 'none';
                // Make manual fields optional
                document.getElementById('credit_card_account_uuid').removeAttribute('required');
                document.getElementById('purchase_amount').removeAttribute('required');
                document.getElementById('description').removeAttribute('required');
            } else {
                transactionSelector.style.display = 'none';
                manualEntry.style.display = 'block';
                // Make manual fields required
                document.getElementById('credit_card_account_uuid').setAttribute('required', 'required');
                document.getElementById('purchase_amount').setAttribute('required', 'required');
                document.getElementById('description').setAttribute('required', 'required');
            }
            updatePreview();
        });
    });

    // Handle transaction selection
    originalTransactionSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option.value) {
            document.getElementById('purchase_amount').value = option.dataset.amount;
            document.getElementById('purchase_date').value = option.dataset.date;
            document.getElementById('description').value = option.dataset.description;
            document.getElementById('credit_card_account_uuid').value = option.dataset.account;
            updatePreview();
        }
    });

    // Sync slider and number input
    const slider = document.getElementById('installments_slider');
    const numberInput = document.getElementById('number_of_installments');

    slider.addEventListener('input', function() {
        numberInput.value = this.value;
        updatePreview();
    });

    numberInput.addEventListener('input', function() {
        slider.value = this.value;
        updatePreview();
    });

    // Update preview on any input change
    const inputs = form.querySelectorAll('input, select');
    inputs.forEach(input => {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });

    // Initial preview
    updatePreview();

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';

        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());

            // Remove empty optional fields
            if (!data.original_transaction_uuid) delete data.original_transaction_uuid;
            if (!data.notes) delete data.notes;

            const response = await fetch('/pgbudget/api/installment-plans.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                window.location.href = 'view.php?plan=' + result.plan.uuid + '&ledger=' + data.ledger_uuid;
            } else {
                alert('Error: ' + (result.error || 'Failed to create installment plan'));
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Installment Plan';
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Installment Plan';
        }
    });

    function updatePreview() {
        const amount = parseFloat(document.getElementById('purchase_amount').value) || 0;
        const numInstallments = parseInt(document.getElementById('number_of_installments').value) || 6;
        const startDate = document.getElementById('start_date').value;
        const frequency = document.getElementById('frequency').value;

        // Calculate installment amounts
        const installmentAmount = Math.floor((amount / numInstallments) * 100) / 100;
        const totalScheduled = installmentAmount * (numInstallments - 1);
        const lastInstallment = Math.round((amount - totalScheduled) * 100) / 100;

        // Update summary
        document.getElementById('preview-total').textContent = '$' + amount.toFixed(2);
        document.getElementById('preview-count').textContent = numInstallments;
        document.getElementById('preview-payment').textContent = '$' + installmentAmount.toFixed(2);
        document.getElementById('preview-last-payment').textContent = '$' + lastInstallment.toFixed(2);

        // Generate schedule
        if (startDate && amount > 0) {
            generateSchedule(startDate, numInstallments, installmentAmount, lastInstallment, frequency);
        }

        // Validate total
        const calculatedTotal = (installmentAmount * (numInstallments - 1)) + lastInstallment;
        const validationMsg = document.getElementById('validation-message');

        if (Math.abs(calculatedTotal - amount) < 0.01) {
            validationMsg.style.display = 'block';
            validationMsg.className = 'validation-message success';
            validationMsg.textContent = '✓ Schedule validated: Total matches purchase amount';
        } else {
            validationMsg.style.display = 'block';
            validationMsg.className = 'validation-message error';
            validationMsg.textContent = '⚠ Warning: Rounding difference of $' +
                Math.abs(calculatedTotal - amount).toFixed(2);
        }
    }

    function generateSchedule(startDate, count, regularAmount, lastAmount, frequency) {
        const tbody = document.getElementById('schedule-body');
        tbody.innerHTML = '';

        let currentDate = new Date(startDate);

        for (let i = 1; i <= count; i++) {
            const row = document.createElement('tr');
            const amount = (i === count) ? lastAmount : regularAmount;

            row.innerHTML = `
                <td>${i}</td>
                <td>${currentDate.toISOString().split('T')[0]}</td>
                <td>$${amount.toFixed(2)}</td>
            `;

            tbody.appendChild(row);

            // Calculate next date based on frequency
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
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
