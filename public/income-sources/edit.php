<?php
/**
 * Edit Income Source Page
 * Form to edit an existing income source
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$income_uuid = $_GET['source'] ?? '';

if (empty($ledger_uuid) || empty($income_uuid)) {
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

    // Get income source details
    $stmt = $db->prepare("SELECT * FROM api.get_income_source(?)");
    $stmt->execute([$income_uuid]);
    $source = $stmt->fetch();

    if (!$source) {
        $_SESSION['error'] = 'Income source not found.';
        header("Location: index.php?ledger=$ledger_uuid");
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

    // Parse occurrence_months from PG array format {1,6,12} to PHP array
    $occurrence_months = [];
    if (!empty($source['occurrence_months'])) {
        $months_str = trim($source['occurrence_months'], '{}');
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
            <h1>Edit Income Source</h1>
            <p>Update <?= htmlspecialchars($source['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Income</a>
        </div>
    </div>

    <div class="form-container">
        <form id="editIncomeForm" class="obligation-form">
            <input type="hidden" name="income_uuid" value="<?= htmlspecialchars($income_uuid) ?>">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h3>Basic Information</h3>

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required maxlength="255"
                           value="<?= htmlspecialchars($source['name']) ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="income_type">Income Type *</label>
                        <select id="income_type" name="income_type" required>
                            <?php foreach (['salary','bonus','benefit','freelance','rental','investment','other'] as $type): ?>
                                <option value="<?= $type ?>" <?= $source['income_type'] === $type ? 'selected' : '' ?>>
                                    <?= ucfirst($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="income_subtype">Subtype (Optional)</label>
                        <input type="text" id="income_subtype" name="income_subtype" maxlength="100"
                               value="<?= htmlspecialchars($source['income_subtype'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="employer_name">Employer / Source Name (Optional)</label>
                    <input type="text" id="employer_name" name="employer_name" maxlength="255"
                           value="<?= htmlspecialchars($source['employer_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="2" maxlength="500"><?= htmlspecialchars($source['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Amount -->
            <div class="form-section">
                <h3>Amount</h3>

                <div class="form-group">
                    <label for="amount">Amount *</label>
                    <input type="number" id="amount" name="amount" required min="0.01" step="0.01"
                           value="<?= number_format($source['amount'] / 100, 2, '.', '') ?>">
                    <small class="form-hint">The amount received each period (before deductions)</small>
                </div>
            </div>

            <!-- Schedule -->
            <div class="form-section">
                <h3>Schedule</h3>

                <div class="form-group">
                    <label for="frequency">Frequency *</label>
                    <select id="frequency" name="frequency" required onchange="updateFrequencyFields()">
                        <?php foreach (['monthly','biweekly','weekly','annual','semiannual','one_time'] as $freq): ?>
                            <option value="<?= $freq ?>" <?= $source['frequency'] === $freq ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $freq)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="pay_day_fields">
                    <div class="form-group">
                        <label for="pay_day_of_month">Pay Day of Month</label>
                        <input type="number" id="pay_day_of_month" name="pay_day_of_month"
                               min="1" max="31" value="<?= $source['pay_day_of_month'] ?? '' ?>">
                    </div>
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
                           value="<?= $source['start_date'] ?>">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_end_date" onchange="toggleEndDate()"
                               <?= !empty($source['end_date']) ? 'checked' : '' ?>>
                        This income source has an end date
                    </label>
                </div>

                <div id="end_date_field" style="<?= empty($source['end_date']) ? 'display:none' : '' ?>">
                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date"
                               value="<?= $source['end_date'] ?? '' ?>">
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
                                    <?= ($source['default_category_uuid'] ?? '') === $category['uuid'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="group_tag">Group Tag (Optional)</label>
                    <input type="text" id="group_tag" name="group_tag" maxlength="100"
                           value="<?= htmlspecialchars($source['group_tag'] ?? '') ?>">
                </div>
            </div>

            <!-- Status -->
            <div class="form-section">
                <h3>Status</h3>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active"
                               <?= $source['is_active'] ? 'checked' : '' ?>>
                        Active (uncheck to deactivate this income source)
                    </label>
                    <small class="form-hint">Inactive income sources won't appear in projections</small>
                </div>
            </div>

            <!-- Notes -->
            <div class="form-section">
                <h3>Notes</h3>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="1000"><?= htmlspecialchars($source['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Update Income Source</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
                <button type="button" class="btn btn-danger btn-lg" id="deleteBtn">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Delete Income Source</h2>
        <p>Are you sure you want to delete <strong><?= htmlspecialchars($source['name']) ?></strong>?</p>
        <p class="warning-text">This action cannot be undone.</p>
        <div class="modal-actions">
            <button id="confirmDelete" class="btn btn-danger">Delete Income Source</button>
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
    .months-grid { grid-template-columns: repeat(3, 1fr); }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; }
}
</style>

<script>
function updateFrequencyFields() {
    const frequency = document.getElementById('frequency').value;
    document.getElementById('pay_day_fields').style.display =
        (frequency === 'monthly' || frequency === 'biweekly') ? 'block' : 'none';
    document.getElementById('occurrence_months_fields').style.display =
        (frequency === 'annual' || frequency === 'semiannual') ? 'block' : 'none';
}

function toggleEndDate() {
    document.getElementById('end_date_field').style.display =
        document.getElementById('has_end_date').checked ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    updateFrequencyFields();
});

// Form submission
document.getElementById('editIncomeForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'update');

    // Handle is_active checkbox
    formData.set('is_active', document.getElementById('is_active').checked ? 'true' : 'false');

    // Handle occurrence_months
    const frequency = formData.get('frequency');
    if (frequency === 'annual' || frequency === 'semiannual') {
        const months = [];
        document.querySelectorAll('input[name="occurrence_months[]"]:checked').forEach(cb => {
            months.push(cb.value);
        });
        formData.set('occurrence_months', months.join(','));
    }
    formData.delete('occurrence_months[]');

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating...';

    try {
        const response = await fetch('../api/income-sources.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Income source updated successfully';
        } else {
            alert('Error updating income source: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error updating income source: ' + error.message);
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
        const response = await fetch(`../api/income-sources.php?source_uuid=<?= $income_uuid ?>`, {
            method: 'DELETE'
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Income source deleted successfully';
        } else {
            alert('Error deleting income source: ' + result.error);
            this.disabled = false;
            this.textContent = 'Delete Income Source';
        }
    } catch (error) {
        alert('Error deleting income source: ' + error.message);
        this.disabled = false;
        this.textContent = 'Delete Income Source';
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
