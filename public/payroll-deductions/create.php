<?php
/**
 * Create Payroll Deduction Page
 * Form to create a new payroll deduction
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get equity accounts for category dropdown
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ?
        AND type = 'equity'
        AND is_group = false
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
    <div class="page-header">
        <div class="page-title">
            <h1>Create Payroll Deduction</h1>
            <p>Add a new payroll deduction to <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions" class="btn btn-secondary">Back to Deductions</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createDeductionForm" class="obligation-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           placeholder="e.g., Federal Income Tax">
                    <small class="form-hint">A descriptive name for this deduction</small>
                </div>

                <div class="form-group">
                    <label for="deduction_type">Deduction Type *</label>
                    <select id="deduction_type" name="deduction_type" required>
                        <option value="tax">Tax</option>
                        <option value="social_security">Social Security</option>
                        <option value="health_plan">Health Plan</option>
                        <option value="pension_fund">Pension Fund</option>
                        <option value="union_dues">Union Dues</option>
                        <option value="donation">Donation</option>
                        <option value="loan_repayment">Loan Repayment</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="employer_name">Employer Name (Optional)</label>
                    <input type="text" id="employer_name" name="employer_name" maxlength="255"
                           placeholder="e.g., Acme Corporation">
                    <small class="form-hint">Used to group deductions by employer</small>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"
                              placeholder="Additional notes about this deduction..."></textarea>
                </div>
            </div>

            <!-- Amount -->
            <div class="form-section">
                <h3>Amount</h3>

                <div class="form-group">
                    <label>Amount Type *</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="is_fixed_amount" value="true" checked
                                   onchange="toggleAmountFields()">
                            <span>Fixed Amount</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="is_fixed_amount" value="false"
                                   onchange="toggleAmountFields()">
                            <span>Estimated Amount</span>
                        </label>
                    </div>
                </div>

                <div id="fixed_amount_fields">
                    <div class="form-group">
                        <label for="fixed_amount">Fixed Amount *</label>
                        <input type="number" id="fixed_amount" name="fixed_amount"
                               min="0.01" step="0.01" placeholder="0.00">
                        <small class="form-hint">The exact deduction amount each period</small>
                    </div>
                </div>

                <div id="estimated_amount_fields" style="display: none;">
                    <div class="form-group">
                        <label for="estimated_amount">Estimated Amount *</label>
                        <input type="number" id="estimated_amount" name="estimated_amount"
                               min="0.01" step="0.01" placeholder="0.00">
                        <small class="form-hint">Average or expected deduction amount</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_percentage" name="is_percentage_check"
                               onchange="togglePercentageFields()">
                        Calculate as percentage
                    </label>
                    <small class="form-hint">Check if this deduction is calculated as a percentage of income</small>
                </div>

                <div id="percentage_fields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="percentage_value">Percentage Value (%)</label>
                            <input type="number" id="percentage_value" name="percentage_value"
                                   min="0" max="100" step="0.01" placeholder="e.g., 7.5">
                        </div>

                        <div class="form-group">
                            <label for="percentage_base">Percentage Base</label>
                            <select id="percentage_base" name="percentage_base">
                                <option value="gross_salary">Gross Salary</option>
                                <option value="base_salary">Base Salary</option>
                                <option value="net_salary">Net Salary</option>
                                <option value="total_income">Total Income</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule -->
            <div class="form-section">
                <h3>Schedule</h3>

                <div class="form-group">
                    <label for="frequency">Frequency *</label>
                    <select id="frequency" name="frequency" required onchange="updateFrequencyFields()">
                        <option value="monthly" selected>Monthly</option>
                        <option value="biweekly">Bi-Weekly</option>
                        <option value="weekly">Weekly</option>
                        <option value="annual">Annual</option>
                        <option value="semiannual">Semi-Annual</option>
                    </select>
                </div>

                <div id="occurrence_months_fields" style="display: none;">
                    <div class="form-group">
                        <label>Occurrence Months</label>
                        <small class="form-hint">Select which months this deduction applies</small>
                        <div class="months-grid">
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="1"> Jan</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="2"> Feb</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="3"> Mar</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="4"> Apr</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="5"> May</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="6"> Jun</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="7"> Jul</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="8"> Aug</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="9"> Sep</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="10"> Oct</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="11"> Nov</label>
                            <label class="checkbox-label"><input type="checkbox" name="occurrence_months[]" value="12"> Dec</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date *</label>
                    <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_end_date" onchange="toggleEndDate()">
                        This deduction has an end date
                    </label>
                </div>

                <div id="end_date_field" style="display: none;">
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>
                </div>
            </div>

            <!-- Category -->
            <div class="form-section">
                <h3>Category</h3>

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
                </div>

                <div class="form-group">
                    <label for="group_tag">Group Tag (Optional)</label>
                    <input type="text" id="group_tag" name="group_tag" maxlength="100"
                           placeholder="e.g., mandatory, voluntary">
                </div>
            </div>

            <!-- Notes -->
            <div class="form-section">
                <h3>Notes</h3>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"
                              placeholder="Any additional information..."></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Create Deduction</button>
                <a href="../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-container { max-width: 900px; margin: 0 auto; }
.obligation-form { background: white; border-radius: 8px; border: 1px solid #e0e0e0; }
.form-section { padding: 2rem; border-bottom: 1px solid #e0e0e0; }
.form-section:last-of-type { border-bottom: none; }
.form-section h3 { margin-top: 0; margin-bottom: 1.5rem; color: #333; font-size: 1.25rem; }
.form-group { margin-bottom: 1.5rem; }
.form-group label { display: block; font-weight: 500; margin-bottom: 0.5rem; color: #333; }
.form-group input[type="text"],
.form-group input[type="number"],
.form-group input[type="date"],
.form-group select,
.form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; }
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline: none; border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1); }
.form-hint { display: block; margin-top: 0.25rem; font-size: 0.875rem; color: #666; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.radio-group { display: flex; gap: 2rem; }
.radio-label { display: flex; align-items: center; font-weight: normal; cursor: pointer; }
.radio-label input[type="radio"] { margin-right: 0.5rem; }
.checkbox-label { display: flex; align-items: center; font-weight: normal; cursor: pointer; }
.checkbox-label input[type="checkbox"] { margin-right: 0.5rem; }
.months-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; margin-top: 0.5rem; }
.form-actions { padding: 2rem; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; gap: 1rem; justify-content: flex-end; }
.btn-lg { padding: 0.75rem 2rem; font-size: 1rem; }
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .radio-group { flex-direction: column; gap: 1rem; }
    .months-grid { grid-template-columns: repeat(3, 1fr); }
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; }
}
</style>

<script>
function toggleAmountFields() {
    const isFixed = document.querySelector('input[name="is_fixed_amount"]:checked').value === 'true';
    document.getElementById('fixed_amount_fields').style.display = isFixed ? 'block' : 'none';
    document.getElementById('estimated_amount_fields').style.display = isFixed ? 'none' : 'block';
}

function togglePercentageFields() {
    const isPercentage = document.getElementById('is_percentage').checked;
    document.getElementById('percentage_fields').style.display = isPercentage ? 'block' : 'none';
}

function updateFrequencyFields() {
    const frequency = document.getElementById('frequency').value;
    document.getElementById('occurrence_months_fields').style.display =
        (frequency === 'annual' || frequency === 'semiannual') ? 'block' : 'none';
}

function toggleEndDate() {
    document.getElementById('end_date_field').style.display =
        document.getElementById('has_end_date').checked ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleAmountFields();
    updateFrequencyFields();
});

document.getElementById('createDeductionForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'create');

    // Set is_percentage from checkbox
    formData.set('is_percentage', document.getElementById('is_percentage').checked ? 'true' : 'false');
    formData.delete('is_percentage_check');

    // Handle occurrence_months
    const frequency = formData.get('frequency');
    if (frequency === 'annual' || frequency === 'semiannual') {
        const months = [];
        document.querySelectorAll('input[name="occurrence_months[]"]:checked').forEach(cb => {
            months.push(cb.value);
        });
        if (months.length > 0) {
            formData.set('occurrence_months', months.join(','));
        }
    }
    formData.delete('occurrence_months[]');

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    try {
        const response = await fetch('../api/payroll-deductions.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions&success=Payroll deduction created successfully';
        } else {
            alert('Error creating deduction: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error creating deduction: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
