<?php
/**
 * Edit Payroll Deduction Page
 * Form to edit an existing payroll deduction
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$deduction_uuid = $_GET['deduction'] ?? '';

if (empty($ledger_uuid) || empty($deduction_uuid)) {
    $_SESSION['error'] = 'Invalid parameters.';
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

    $stmt = $db->prepare("SELECT * FROM api.get_payroll_deduction(?)");
    $stmt->execute([$deduction_uuid]);
    $deduction = $stmt->fetch();

    if (!$deduction) {
        $_SESSION['error'] = 'Payroll deduction not found.';
        header("Location: ../income-sources/index.php?ledger=$ledger_uuid&tab=deductions");
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

    // Parse occurrence_months
    $occurrence_months = [];
    if (!empty($deduction['occurrence_months'])) {
        $months_str = trim($deduction['occurrence_months'], '{}');
        if (!empty($months_str)) {
            $occurrence_months = array_map('intval', explode(',', $months_str));
        }
    }

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
            <h1>Edit Payroll Deduction</h1>
            <p>Update <?= htmlspecialchars($deduction['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions" class="btn btn-secondary">Back to Deductions</a>
        </div>
    </div>

    <div class="form-container">
        <form id="editDeductionForm" class="obligation-form">
            <input type="hidden" name="deduction_uuid" value="<?= htmlspecialchars($deduction_uuid) ?>">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           value="<?= htmlspecialchars($deduction['name']) ?>">
                </div>

                <div class="form-group">
                    <label for="deduction_type">Deduction Type *</label>
                    <select id="deduction_type" name="deduction_type" required>
                        <?php foreach (['tax','social_security','health_plan','pension_fund','union_dues','donation','loan_repayment','other'] as $type): ?>
                            <option value="<?= $type ?>" <?= $deduction['deduction_type'] === $type ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="employer_name">Employer Name (Optional)</label>
                    <input type="text" id="employer_name" name="employer_name" maxlength="255"
                           value="<?= htmlspecialchars($deduction['employer_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"><?= htmlspecialchars($deduction['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Amount -->
            <div class="form-section">
                <h3>Amount</h3>

                <div class="form-group">
                    <label>Amount Type *</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="is_fixed_amount" value="true"
                                   <?= $deduction['is_fixed_amount'] ? 'checked' : '' ?>
                                   onchange="toggleAmountFields()">
                            <span>Fixed Amount</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="is_fixed_amount" value="false"
                                   <?= !$deduction['is_fixed_amount'] ? 'checked' : '' ?>
                                   onchange="toggleAmountFields()">
                            <span>Estimated Amount</span>
                        </label>
                    </div>
                </div>

                <div id="fixed_amount_fields" style="<?= !$deduction['is_fixed_amount'] ? 'display:none' : '' ?>">
                    <div class="form-group">
                        <label for="fixed_amount">Fixed Amount</label>
                        <input type="number" id="fixed_amount" name="fixed_amount"
                               min="0.01" step="0.01"
                               value="<?= $deduction['fixed_amount'] ? number_format($deduction['fixed_amount'] / 100, 2, '.', '') : '' ?>">
                    </div>
                </div>

                <div id="estimated_amount_fields" style="<?= $deduction['is_fixed_amount'] ? 'display:none' : '' ?>">
                    <div class="form-group">
                        <label for="estimated_amount">Estimated Amount</label>
                        <input type="number" id="estimated_amount" name="estimated_amount"
                               min="0.01" step="0.01"
                               value="<?= $deduction['estimated_amount'] ? number_format($deduction['estimated_amount'] / 100, 2, '.', '') : '' ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_percentage" name="is_percentage_check"
                               <?= $deduction['is_percentage'] ? 'checked' : '' ?>
                               onchange="togglePercentageFields()">
                        Calculate as percentage
                    </label>
                </div>

                <div id="percentage_fields" style="<?= !$deduction['is_percentage'] ? 'display:none' : '' ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="percentage_value">Percentage Value (%)</label>
                            <input type="number" id="percentage_value" name="percentage_value"
                                   min="0" max="100" step="0.01"
                                   value="<?= $deduction['percentage_value'] ?? '' ?>">
                        </div>

                        <div class="form-group">
                            <label for="percentage_base">Percentage Base</label>
                            <select id="percentage_base" name="percentage_base">
                                <?php foreach (['gross_salary','base_salary','net_salary','total_income'] as $base): ?>
                                    <option value="<?= $base ?>" <?= ($deduction['percentage_base'] ?? '') === $base ? 'selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $base)) ?>
                                    </option>
                                <?php endforeach; ?>
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
                        <?php foreach (['monthly','biweekly','weekly','annual','semiannual'] as $freq): ?>
                            <option value="<?= $freq ?>" <?= $deduction['frequency'] === $freq ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $freq)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="occurrence_months_fields" style="display: none;">
                    <div class="form-group">
                        <label>Occurrence Months</label>
                        <div class="months-grid">
                            <?php
                            $month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                            for ($m = 1; $m <= 12; $m++):
                            ?>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="occurrence_months[]" value="<?= $m ?>"
                                           <?= in_array($m, $occurrence_months) ? 'checked' : '' ?>>
                                    <?= $month_names[$m-1] ?>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date *</label>
                    <input type="date" id="start_date" name="start_date" required
                           value="<?= $deduction['start_date'] ?>">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_end_date" onchange="toggleEndDate()"
                               <?= !empty($deduction['end_date']) ? 'checked' : '' ?>>
                        This deduction has an end date
                    </label>
                </div>

                <div id="end_date_field" style="<?= empty($deduction['end_date']) ? 'display:none' : '' ?>">
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date"
                               value="<?= $deduction['end_date'] ?? '' ?>">
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
                            <option value="<?= htmlspecialchars($category['uuid']) ?>"
                                    <?= ($deduction['default_category_uuid'] ?? '') === $category['uuid'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="group_tag">Group Tag (Optional)</label>
                    <input type="text" id="group_tag" name="group_tag" maxlength="100"
                           value="<?= htmlspecialchars($deduction['group_tag'] ?? '') ?>">
                </div>
            </div>

            <!-- Status -->
            <div class="form-section">
                <h3>Status</h3>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active"
                               <?= $deduction['is_active'] ? 'checked' : '' ?>>
                        Active (uncheck to deactivate this deduction)
                    </label>
                    <small class="form-hint">Inactive deductions won't appear in projections</small>
                </div>
            </div>

            <!-- Notes -->
            <div class="form-section">
                <h3>Notes</h3>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"><?= htmlspecialchars($deduction['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Update Deduction</button>
                <a href="../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions" class="btn btn-secondary btn-lg">Cancel</a>
                <button type="button" class="btn btn-danger btn-lg" id="deleteBtn">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Delete Payroll Deduction</h2>
        <p>Are you sure you want to delete <strong><?= htmlspecialchars($deduction['name']) ?></strong>?</p>
        <p class="warning-text">This action cannot be undone.</p>
        <div class="modal-actions">
            <button id="confirmDelete" class="btn btn-danger">Delete Deduction</button>
            <button id="cancelDelete" class="btn btn-secondary">Cancel</button>
        </div>
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
.form-actions { padding: 2rem; background: #f9f9f9; border-top: 1px solid #e0e0e0; display: flex; gap: 1rem; justify-content: space-between; }
.btn-lg { padding: 0.75rem 2rem; font-size: 1rem; }
.warning-text { color: #d32f2f; font-weight: 500; }
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: white; padding: 2rem; border-radius: 8px; max-width: 500px; width: 90%; }
.modal-content h2 { margin-top: 0; }
.modal-actions { margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: flex-end; }
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .radio-group { flex-direction: column; gap: 1rem; }
    .months-grid { grid-template-columns: repeat(3, 1fr); }
    .form-actions { flex-direction: column; }
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
    document.getElementById('percentage_fields').style.display =
        document.getElementById('is_percentage').checked ? 'block' : 'none';
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

// Form submission
document.getElementById('editDeductionForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'update');

    // Handle booleans
    formData.set('is_active', document.getElementById('is_active').checked ? 'true' : 'false');
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
    submitBtn.textContent = 'Updating...';

    try {
        const response = await fetch('../api/payroll-deductions.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions&success=Payroll deduction updated successfully';
        } else {
            alert('Error updating deduction: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error updating deduction: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

// Delete functionality
const deleteModal = document.getElementById('deleteModal');
document.getElementById('deleteBtn').addEventListener('click', () => deleteModal.style.display = 'flex');
document.getElementById('cancelDelete').addEventListener('click', () => deleteModal.style.display = 'none');

document.getElementById('confirmDelete').addEventListener('click', async function() {
    this.disabled = true;
    this.textContent = 'Deleting...';

    try {
        const response = await fetch(`../api/payroll-deductions.php?deduction_uuid=<?= $deduction_uuid ?>`, {
            method: 'DELETE'
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '../income-sources/index.php?ledger=<?= $ledger_uuid ?>&tab=deductions&success=Payroll deduction deleted successfully';
        } else {
            alert('Error deleting deduction: ' + result.error);
            this.disabled = false;
            this.textContent = 'Delete Deduction';
        }
    } catch (error) {
        alert('Error deleting deduction: ' + error.message);
        this.disabled = false;
        this.textContent = 'Delete Deduction';
    }
});

deleteModal.addEventListener('click', function(e) {
    if (e.target === deleteModal) deleteModal.style.display = 'none';
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && deleteModal.style.display === 'flex') deleteModal.style.display = 'none';
});
</script>

<?php require_once '../../includes/footer.php'; ?>
