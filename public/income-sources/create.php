<?php
/**
 * Create Income Source Page
 * Form to create a new income source
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
            <h1>Create Income Source</h1>
            <p>Add a new income source to <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Income</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createIncomeForm" class="obligation-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           placeholder="e.g., Monthly Salary - Acme Corp">
                    <small class="form-hint">A descriptive name for this income source</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="income_type">Income Type *</label>
                        <select id="income_type" name="income_type" required>
                            <option value="salary">Salary</option>
                            <option value="bonus">Bonus</option>
                            <option value="benefit">Benefit</option>
                            <option value="freelance">Freelance</option>
                            <option value="rental">Rental</option>
                            <option value="investment">Investment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="income_subtype">Subtype (Optional)</label>
                        <input type="text" id="income_subtype" name="income_subtype" maxlength="100"
                               placeholder="e.g., Base Pay, Commission, etc.">
                    </div>
                </div>

                <div class="form-group">
                    <label for="employer_name">Employer / Source Name (Optional)</label>
                    <input type="text" id="employer_name" name="employer_name" maxlength="255"
                           placeholder="e.g., Acme Corporation">
                    <small class="form-hint">Used to group income sources by employer</small>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"
                              placeholder="Additional notes about this income source..."></textarea>
                </div>
            </div>

            <!-- Amount -->
            <div class="form-section">
                <h3>Amount</h3>

                <div class="form-group">
                    <label for="amount">Amount *</label>
                    <input type="number" id="amount" name="amount" required min="0.01" step="0.01"
                           placeholder="0.00">
                    <small class="form-hint">The amount received each period (before deductions)</small>
                </div>
            </div>

            <!-- Schedule -->
            <div class="form-section">
                <h3>Schedule</h3>

                <div class="form-group">
                    <label for="frequency">Frequency *</label>
                    <select id="frequency" name="frequency" required onchange="updateFrequencyFields()">
                        <option value="monthly" selected>Monthly</option>
                        <option value="biweekly">Bi-Weekly (Every 2 weeks)</option>
                        <option value="weekly">Weekly</option>
                        <option value="annual">Annual (Once a year)</option>
                        <option value="semiannual">Semi-Annual (Twice a year)</option>
                        <option value="one_time">One-Time</option>
                    </select>
                </div>

                <div id="pay_day_fields">
                    <div class="form-group">
                        <label for="pay_day_of_month">Pay Day of Month</label>
                        <input type="number" id="pay_day_of_month" name="pay_day_of_month"
                               min="1" max="31" placeholder="e.g., 5">
                        <small class="form-hint">Day of the month you receive payment</small>
                    </div>
                </div>

                <div id="occurrence_months_fields" style="display: none;">
                    <div class="form-group">
                        <label>Occurrence Months *</label>
                        <small class="form-hint">Select which months this income occurs</small>
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
                    <small class="form-hint">When this income source begins</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_end_date" onchange="toggleEndDate()">
                        This income source has an end date
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
                    <small class="form-hint">Budget category for this income</small>
                </div>

                <div class="form-group">
                    <label for="group_tag">Group Tag (Optional)</label>
                    <input type="text" id="group_tag" name="group_tag" maxlength="100"
                           placeholder="e.g., primary-job, side-hustle">
                    <small class="form-hint">Tag for grouping related items</small>
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
                <button type="submit" class="btn btn-primary btn-lg">Create Income Source</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
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
.checkbox-label { display: flex; align-items: center; font-weight: normal; cursor: pointer; }
.checkbox-label input[type="checkbox"] { margin-right: 0.5rem; }
.months-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; margin-top: 0.5rem; }
.form-actions { padding: 2rem; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; gap: 1rem; justify-content: flex-end; }
.btn-lg { padding: 0.75rem 2rem; font-size: 1rem; }
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .months-grid { grid-template-columns: repeat(3, 1fr); }
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; }
}
</style>

<script>
function updateFrequencyFields() {
    const frequency = document.getElementById('frequency').value;
    const payDayFields = document.getElementById('pay_day_fields');
    const occurrenceFields = document.getElementById('occurrence_months_fields');

    // Show/hide pay day field
    if (frequency === 'monthly' || frequency === 'biweekly') {
        payDayFields.style.display = 'block';
    } else {
        payDayFields.style.display = 'none';
    }

    // Show/hide occurrence months
    if (frequency === 'annual' || frequency === 'semiannual') {
        occurrenceFields.style.display = 'block';
    } else {
        occurrenceFields.style.display = 'none';
    }
}

function toggleEndDate() {
    const hasEndDate = document.getElementById('has_end_date').checked;
    document.getElementById('end_date_field').style.display = hasEndDate ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    updateFrequencyFields();
});

document.getElementById('createIncomeForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'create');

    // Handle occurrence_months (checkbox array to comma-separated)
    const frequency = formData.get('frequency');
    if (frequency === 'annual' || frequency === 'semiannual') {
        const months = [];
        document.querySelectorAll('input[name="occurrence_months[]"]:checked').forEach(cb => {
            months.push(cb.value);
        });
        if (months.length === 0) {
            alert('Please select at least one occurrence month.');
            return;
        }
        formData.set('occurrence_months', months.join(','));
    }
    // Remove checkbox array entries
    formData.delete('occurrence_months[]');

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating...';

    try {
        const response = await fetch('../api/income-sources.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Income source created successfully';
        } else {
            alert('Error creating income source: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error creating income source: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
