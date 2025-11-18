<?php
/**
 * Create Obligation Page
 * Form to create a new obligation/bill
 * Part of Phase 3 of OBLIGATIONS_BILLS_IMPLEMENTATION_PLAN.md
 */

require_once '../../includes/session.php';
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

    // Get accounts for dropdowns
    $stmt = $db->prepare("
        SELECT uuid, name, type, subtype
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type IN ('asset', 'equity')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Separate into payment accounts and categories
    $payment_accounts = array_filter($accounts, fn($a) => $a['type'] === 'asset');
    $categories = array_filter($accounts, fn($a) => $a['type'] === 'equity');

    // Get existing payees
    $stmt = $db->prepare("
        SELECT DISTINCT payee_name
        FROM data.payees
        WHERE user_data = ?
        ORDER BY payee_name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $existing_payees = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
            <h1>Create New Obligation</h1>
            <p>Add a recurring bill or obligation to <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Obligations</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createObligationForm" class="obligation-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label for="name">Obligation Name *</label>
                    <input type="text"
                           id="name"
                           name="name"
                           required
                           maxlength="255"
                           placeholder="e.g., Electric Bill - Main Street">
                    <small class="form-hint">A descriptive name for this obligation</small>
                </div>

                <div class="form-group">
                    <label for="payee_name">Payee *</label>
                    <input type="text"
                           id="payee_name"
                           name="payee_name"
                           required
                           list="payee_list"
                           maxlength="255"
                           placeholder="Start typing to search or add new payee...">
                    <datalist id="payee_list">
                        <?php foreach ($existing_payees as $payee): ?>
                            <option value="<?= htmlspecialchars($payee) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <small class="form-hint">Who you pay this to (will be created if new)</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="obligation_type">Type *</label>
                        <select id="obligation_type" name="obligation_type" required>
                            <option value="">Select type...</option>
                            <option value="utility">⚡ Utility</option>
                            <option value="housing">🏠 Housing</option>
                            <option value="subscription">📺 Subscription</option>
                            <option value="education">🎓 Education</option>
                            <option value="debt">💳 Debt</option>
                            <option value="insurance">🛡️ Insurance</option>
                            <option value="tax">📋 Tax</option>
                            <option value="other">📌 Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="obligation_subtype">Subtype (Optional)</label>
                        <input type="text"
                               id="obligation_subtype"
                               name="obligation_subtype"
                               maxlength="100"
                               placeholder="e.g., Electricity, Netflix, etc.">
                        <small class="form-hint">Specific category within the type</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description"
                              name="description"
                              rows="2"
                              maxlength="500"
                              placeholder="Additional notes about this obligation..."></textarea>
                </div>
            </div>

            <!-- Amount Details -->
            <div class="form-section">
                <h3>Amount Details</h3>

                <div class="form-group">
                    <label>Amount Type *</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio"
                                   name="is_fixed_amount"
                                   value="true"
                                   checked
                                   onchange="toggleAmountFields()">
                            <span>Fixed Amount</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio"
                                   name="is_fixed_amount"
                                   value="false"
                                   onchange="toggleAmountFields()">
                            <span>Variable/Estimated Amount</span>
                        </label>
                    </div>
                </div>

                <div id="fixed_amount_fields">
                    <div class="form-group">
                        <label for="fixed_amount">Fixed Amount *</label>
                        <input type="number"
                               id="fixed_amount"
                               name="fixed_amount"
                               min="0.01"
                               step="0.01"
                               placeholder="0.00">
                        <small class="form-hint">The exact amount due each period</small>
                    </div>
                </div>

                <div id="variable_amount_fields" style="display: none;">
                    <div class="form-group">
                        <label for="estimated_amount">Estimated Amount *</label>
                        <input type="number"
                               id="estimated_amount"
                               name="estimated_amount"
                               min="0.01"
                               step="0.01"
                               placeholder="0.00">
                        <small class="form-hint">Average or expected amount</small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount_range_min">Minimum Amount (Optional)</label>
                            <input type="number"
                                   id="amount_range_min"
                                   name="amount_range_min"
                                   min="0"
                                   step="0.01"
                                   placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label for="amount_range_max">Maximum Amount (Optional)</label>
                            <input type="number"
                                   id="amount_range_max"
                                   name="amount_range_max"
                                   min="0"
                                   step="0.01"
                                   placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Frequency -->
            <div class="form-section">
                <h3>Payment Frequency</h3>

                <div class="form-group">
                    <label for="frequency">Frequency *</label>
                    <select id="frequency" name="frequency" required onchange="updateFrequencyFields()">
                        <option value="">Select frequency...</option>
                        <option value="weekly">Weekly</option>
                        <option value="biweekly">Bi-Weekly (Every 2 weeks)</option>
                        <option value="monthly" selected>Monthly</option>
                        <option value="quarterly">Quarterly (Every 3 months)</option>
                        <option value="semiannual">Semi-Annual (Twice a year)</option>
                        <option value="annual">Annual (Once a year)</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>

                <!-- Weekly/Biweekly Fields -->
                <div id="weekly_fields" style="display: none;">
                    <div class="form-group">
                        <label for="due_day_of_week">Due Day of Week *</label>
                        <select id="due_day_of_week" name="due_day_of_week">
                            <option value="0">Sunday</option>
                            <option value="1">Monday</option>
                            <option value="2">Tuesday</option>
                            <option value="3">Wednesday</option>
                            <option value="4">Thursday</option>
                            <option value="5">Friday</option>
                            <option value="6">Saturday</option>
                        </select>
                    </div>
                </div>

                <!-- Monthly/Quarterly Fields -->
                <div id="monthly_fields">
                    <div class="form-group">
                        <label for="due_day_of_month">Due Day of Month *</label>
                        <input type="number"
                               id="due_day_of_month"
                               name="due_day_of_month"
                               min="1"
                               max="31"
                               placeholder="15">
                        <small class="form-hint">For months with fewer days, the last day will be used</small>
                    </div>
                </div>

                <!-- Semi-Annual/Annual Fields -->
                <div id="annual_fields" style="display: none;">
                    <div class="form-group">
                        <label for="due_day_of_month_annual">Due Day of Month *</label>
                        <input type="number"
                               id="due_day_of_month_annual"
                               name="due_day_of_month_annual"
                               min="1"
                               max="31"
                               placeholder="15">
                    </div>

                    <div class="form-group">
                        <label>Due Months *</label>
                        <small class="form-hint">Select which months this is due</small>
                        <div class="months-grid">
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="1"> Jan
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="2"> Feb
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="3"> Mar
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="4"> Apr
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="5"> May
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="6"> Jun
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="7"> Jul
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="8"> Aug
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="9"> Sep
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="10"> Oct
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="11"> Nov
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="due_months[]" value="12"> Dec
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Custom Frequency Fields -->
                <div id="custom_fields" style="display: none;">
                    <div class="form-group">
                        <label for="custom_frequency_days">Every X Days *</label>
                        <input type="number"
                               id="custom_frequency_days"
                               name="custom_frequency_days"
                               min="1"
                               placeholder="30">
                        <small class="form-hint">Number of days between payments</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date *</label>
                    <input type="date"
                           id="start_date"
                           name="start_date"
                           required
                           value="<?= date('Y-m-d') ?>">
                    <small class="form-hint">When this obligation begins</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_end_date" onchange="toggleEndDate()">
                        This obligation has an end date
                    </label>
                </div>

                <div id="end_date_field" style="display: none;">
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date"
                               id="end_date"
                               name="end_date">
                        <small class="form-hint">When this obligation ends (leave blank for indefinite)</small>
                    </div>
                </div>
            </div>

            <!-- Payment Settings -->
            <div class="form-section">
                <h3>Payment Settings</h3>

                <div class="form-group">
                    <label for="default_payment_account_uuid">Default Payment Account (Optional)</label>
                    <select id="default_payment_account_uuid" name="default_payment_account_uuid">
                        <option value="">None</option>
                        <?php foreach ($payment_accounts as $account): ?>
                            <option value="<?= htmlspecialchars($account['uuid']) ?>">
                                <?= htmlspecialchars($account['name']) ?>
                                <?php if ($account['subtype']): ?>
                                    (<?= ucfirst($account['subtype']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Which account you typically pay from</small>
                </div>

                <div class="form-group">
                    <label for="default_category_uuid">Default Category (Optional)</label>
                    <select id="default_category_uuid" name="default_category_uuid">
                        <option value="">None</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['uuid']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Budget category for this expense</small>
                </div>

                <div class="form-group">
                    <label for="account_number">Account Number (Optional)</label>
                    <input type="text"
                           id="account_number"
                           name="account_number"
                           maxlength="100"
                           placeholder="e.g., Account #, Policy #, Customer #">
                    <small class="form-hint">Your account or policy number with the payee</small>
                </div>
            </div>

            <!-- Reminders -->
            <div class="form-section">
                <h3>Payment Reminders</h3>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="reminder_enabled" name="reminder_enabled" checked>
                        Enable payment reminders
                    </label>
                </div>

                <div id="reminder_settings">
                    <div class="form-group">
                        <label for="reminder_days_before">Remind me (days before due date)</label>
                        <input type="number"
                               id="reminder_days_before"
                               name="reminder_days_before"
                               min="0"
                               max="30"
                               value="3">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="reminder_email" checked>
                            Email notification
                        </label>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="reminder_dashboard" checked>
                            Dashboard alert
                        </label>
                    </div>
                </div>
            </div>

            <!-- Advanced Settings -->
            <div class="form-section">
                <h3>Advanced Settings</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="grace_period_days">Grace Period (Days)</label>
                        <input type="number"
                               id="grace_period_days"
                               name="grace_period_days"
                               min="0"
                               max="60"
                               value="0"
                               placeholder="0">
                        <small class="form-hint">Days after due date before it's considered late</small>
                    </div>

                    <div class="form-group">
                        <label for="late_fee_amount">Late Fee Amount</label>
                        <input type="number"
                               id="late_fee_amount"
                               name="late_fee_amount"
                               min="0"
                               step="0.01"
                               placeholder="0.00">
                        <small class="form-hint">Fee charged for late payment</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"
                              placeholder="Any additional information about this obligation..."></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Create Obligation</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-container {
    max-width: 900px;
    margin: 0 auto;
}

.obligation-form {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.form-section {
    padding: 2rem;
    border-bottom: 1px solid #e0e0e0;
}

.form-section:last-of-type {
    border-bottom: none;
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
    font-size: 1.25rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #333;
}

.form-group input[type="text"],
.form-group input[type="number"],
.form-group input[type="date"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

.form-group input[type="text"]:focus,
.form-group input[type="number"]:focus,
.form-group input[type="date"]:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #0066cc;
    box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
}

.form-hint {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #666;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.radio-group {
    display: flex;
    gap: 2rem;
}

.radio-label {
    display: flex;
    align-items: center;
    font-weight: normal;
    cursor: pointer;
}

.radio-label input[type="radio"] {
    margin-right: 0.5rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    font-weight: normal;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    margin-right: 0.5rem;
}

.months-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.form-actions {
    padding: 2rem;
    background: #f9f9f9;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.btn-lg {
    padding: 0.75rem 2rem;
    font-size: 1rem;
}

.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.alert-info {
    background: #e3f2fd;
    border: 1px solid #90caf9;
    color: #1565c0;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .radio-group {
        flex-direction: column;
        gap: 1rem;
    }

    .months-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .form-actions {
        flex-direction: column-reverse;
    }

    .form-actions .btn {
        width: 100%;
    }
}
</style>

<script>
// Toggle amount fields based on fixed/variable selection
function toggleAmountFields() {
    const isFixed = document.querySelector('input[name="is_fixed_amount"]:checked').value === 'true';
    document.getElementById('fixed_amount_fields').style.display = isFixed ? 'block' : 'none';
    document.getElementById('variable_amount_fields').style.display = isFixed ? 'none' : 'block';

    // Update required attribute
    document.getElementById('fixed_amount').required = isFixed;
    document.getElementById('estimated_amount').required = !isFixed;
}

// Update frequency-specific fields
function updateFrequencyFields() {
    const frequency = document.getElementById('frequency').value;

    // Hide all frequency-specific fields
    document.getElementById('weekly_fields').style.display = 'none';
    document.getElementById('monthly_fields').style.display = 'none';
    document.getElementById('annual_fields').style.display = 'none';
    document.getElementById('custom_fields').style.display = 'none';

    // Clear required attributes
    document.getElementById('due_day_of_week').required = false;
    document.getElementById('due_day_of_month').required = false;
    document.getElementById('due_day_of_month_annual').required = false;
    document.getElementById('custom_frequency_days').required = false;

    // Show relevant fields and set required
    if (frequency === 'weekly' || frequency === 'biweekly') {
        document.getElementById('weekly_fields').style.display = 'block';
        document.getElementById('due_day_of_week').required = true;
    } else if (frequency === 'monthly' || frequency === 'quarterly') {
        document.getElementById('monthly_fields').style.display = 'block';
        document.getElementById('due_day_of_month').required = true;
    } else if (frequency === 'semiannual' || frequency === 'annual') {
        document.getElementById('annual_fields').style.display = 'block';
        document.getElementById('due_day_of_month_annual').required = true;
    } else if (frequency === 'custom') {
        document.getElementById('custom_fields').style.display = 'block';
        document.getElementById('custom_frequency_days').required = true;
    }
}

// Toggle end date field
function toggleEndDate() {
    const hasEndDate = document.getElementById('has_end_date').checked;
    document.getElementById('end_date_field').style.display = hasEndDate ? 'block' : 'none';
    document.getElementById('end_date').required = hasEndDate;
}

// Toggle reminder settings
document.getElementById('reminder_enabled').addEventListener('change', function() {
    document.getElementById('reminder_settings').style.display = this.checked ? 'block' : 'none';
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleAmountFields();
    updateFrequencyFields();
});

// Form submission
document.getElementById('createObligationForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'create');

    // Handle due_months for annual/semiannual
    const frequency = formData.get('frequency');
    if (frequency === 'semiannual' || frequency === 'annual') {
        const dueMonths = [];
        document.querySelectorAll('input[name="due_months[]"]:checked').forEach(cb => {
            dueMonths.push(cb.value);
        });

        if (dueMonths.length === 0) {
            alert('Please select at least one month for the payment.');
            return;
        }

        formData.set('due_months', dueMonths.join(','));

        // Use the annual day of month field
        const dayOfMonthAnnual = formData.get('due_day_of_month_annual');
        formData.set('due_day_of_month', dayOfMonthAnnual);
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    try {
        const response = await fetch('../api/obligations.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Obligation created successfully';
        } else {
            alert('Error creating obligation: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error creating obligation: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
