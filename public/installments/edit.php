<?php
/**
 * Edit Installment Plan Page
 * Edit limited fields of an installment plan to prevent data integrity issues
 * Part of Step 3.5 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$plan_uuid = $_GET['plan'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($plan_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: index.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get installment plan details
    $stmt = $db->prepare("
        SELECT
            ip.id, ip.uuid, ip.created_at, ip.updated_at,
            ip.ledger_id, ip.original_transaction_id,
            ip.purchase_amount, ip.purchase_date, ip.description,
            ip.credit_card_account_id, ip.number_of_installments,
            ip.installment_amount, ip.frequency, ip.start_date,
            ip.category_account_id, ip.status, ip.completed_installments,
            ip.notes, ip.metadata,
            cc.name as credit_card_name,
            cc.uuid as credit_card_uuid,
            cat.name as category_name,
            cat.uuid as category_uuid,
            l.uuid as ledger_uuid,
            l.name as ledger_name
        FROM data.installment_plans ip
        JOIN data.ledgers l ON ip.ledger_id = l.id
        JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
        WHERE ip.uuid = ? AND l.uuid = ?
    ");
    $stmt->execute([$plan_uuid, $ledger_uuid]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $_SESSION['error'] = 'Installment plan not found.';
        header('Location: index.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Check if plan is editable (must be active with scheduled installments)
    if ($plan['status'] !== 'active') {
        $_SESSION['error'] = 'Cannot edit a ' . $plan['status'] . ' plan.';
        header('Location: view.php?ledger=' . urlencode($ledger_uuid) . '&plan=' . urlencode($plan_uuid));
        exit;
    }

    $remaining_installments = $plan['number_of_installments'] - $plan['completed_installments'];

    if ($remaining_installments <= 0) {
        $_SESSION['error'] = 'Cannot edit plan - all installments have been processed.';
        header('Location: view.php?ledger=' . urlencode($ledger_uuid) . '&plan=' . urlencode($plan_uuid));
        exit;
    }

    // Get category accounts (equity accounts, excluding groups) for category reassignment
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND (is_group = false OR is_group IS NULL)
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

    // Get scheduled installments (to show what will be affected)
    $stmt = $db->prepare("
        SELECT
            isch.id, isch.uuid, isch.installment_number,
            isch.due_date, isch.scheduled_amount, isch.status
        FROM data.installment_schedules isch
        WHERE isch.installment_plan_id = ?
        AND isch.status = 'scheduled'
        ORDER BY isch.installment_number ASC
    ");
    $stmt->execute([$plan['id']]);
    $scheduled_installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total remaining amount
    $total_remaining = 0;
    foreach ($scheduled_installments as $item) {
        $total_remaining += floatval($item['scheduled_amount']);
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: index.php?ledger=' . urlencode($ledger_uuid));
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>✏️ Edit Installment Plan</h1>
            <p>Modify plan details for <?= htmlspecialchars($plan['description']) ?></p>
        </div>
        <div class="page-actions">
            <a href="view.php?ledger=<?= $ledger_uuid ?>&plan=<?= $plan_uuid ?>" class="btn btn-secondary">← Back to Plan</a>
        </div>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info">
        <strong>ℹ️ Limited Editing</strong>
        <p>To maintain data integrity, only certain fields can be edited. You cannot change the purchase amount, credit card, or already processed installments.</p>
    </div>

    <div class="content-grid">
        <!-- Left Column: Current Plan Info -->
        <div class="info-section">
            <h2>Current Plan Information</h2>

            <div class="info-card readonly">
                <div class="info-item">
                    <span class="info-label">Purchase Amount</span>
                    <span class="info-value amount-large"><?= formatCurrency($plan['purchase_amount']) ?></span>
                    <small class="text-muted">Cannot be changed</small>
                </div>

                <div class="info-item">
                    <span class="info-label">Credit Card</span>
                    <span class="info-value"><?= htmlspecialchars($plan['credit_card_name']) ?></span>
                    <small class="text-muted">Cannot be changed</small>
                </div>

                <div class="info-item">
                    <span class="info-label">Purchase Date</span>
                    <span class="info-value"><?= date('F j, Y', strtotime($plan['purchase_date'])) ?></span>
                    <small class="text-muted">Cannot be changed</small>
                </div>

                <div class="info-item">
                    <span class="info-label">Frequency</span>
                    <span class="info-value"><?= ucfirst($plan['frequency']) ?></span>
                    <small class="text-muted">Cannot be changed</small>
                </div>
            </div>

            <div class="progress-card">
                <h3>Progress</h3>
                <div class="progress-stats">
                    <div class="stat">
                        <span class="stat-label">Completed</span>
                        <span class="stat-value"><?= $plan['completed_installments'] ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Remaining</span>
                        <span class="stat-value"><?= $remaining_installments ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-label">Total</span>
                        <span class="stat-value"><?= $plan['number_of_installments'] ?></span>
                    </div>
                </div>
                <p class="text-muted" style="margin-top: 12px;">
                    Only the <?= $remaining_installments ?> remaining installment<?= $remaining_installments != 1 ? 's' : '' ?> can be affected by changes.
                </p>
            </div>

            <div class="affected-section">
                <h3>Affected Installments</h3>
                <p class="text-muted">These scheduled installments will be updated if you change the plan:</p>
                <div class="installments-list">
                    <?php foreach ($scheduled_installments as $item): ?>
                        <div class="installment-item">
                            <span class="installment-num">#<?= $item['installment_number'] ?></span>
                            <span class="installment-date"><?= date('M j, Y', strtotime($item['due_date'])) ?></span>
                            <span class="installment-amount"><?= formatCurrency($item['scheduled_amount']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="total-remaining">
                    <strong>Total Remaining:</strong>
                    <span class="amount"><?= formatCurrency($total_remaining) ?></span>
                </div>
            </div>
        </div>

        <!-- Right Column: Edit Form -->
        <div class="form-section">
            <h2>Editable Fields</h2>

            <form id="editPlanForm" onsubmit="return handleSubmit(event)">
                <!-- Description -->
                <div class="form-group">
                    <label for="description" class="required">Description</label>
                    <input
                        type="text"
                        id="description"
                        name="description"
                        class="form-control"
                        value="<?= htmlspecialchars($plan['description']) ?>"
                        maxlength="255"
                        required
                    />
                    <small class="form-hint">A brief description of the purchase</small>
                </div>

                <!-- Category -->
                <div class="form-group">
                    <label for="category_uuid">Budget Category</label>
                    <select id="category_uuid" name="category_uuid" class="form-control">
                        <option value="">-- No Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option
                                value="<?= $cat['uuid'] ?>"
                                <?= $plan['category_uuid'] === $cat['uuid'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">
                        Changing the category will affect remaining installments when processed
                    </small>
                </div>

                <!-- Number of Remaining Installments -->
                <div class="form-group">
                    <label for="remaining_installments">Number of Remaining Installments</label>
                    <input
                        type="number"
                        id="remaining_installments"
                        name="remaining_installments"
                        class="form-control"
                        value="<?= $remaining_installments ?>"
                        min="1"
                        max="<?= $remaining_installments ?>"
                    />
                    <small class="form-hint">
                        Current: <?= $remaining_installments ?> remaining.
                        <strong>Warning:</strong> Reducing this will reschedule and recalculate installment amounts.
                    </small>
                    <div id="recalculation-preview" class="recalc-preview" style="display: none;">
                        <strong>Preview:</strong>
                        <div id="recalc-details"></div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        rows="4"
                        maxlength="1000"
                    ><?= htmlspecialchars($plan['notes'] ?? '') ?></textarea>
                    <small class="form-hint">Optional notes about this installment plan (max 1000 characters)</small>
                </div>

                <!-- Change Summary -->
                <div id="change-summary" class="change-summary" style="display: none;">
                    <h3>Summary of Changes</h3>
                    <ul id="change-list"></ul>
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-large" id="submitBtn">
                        💾 Save Changes
                    </button>
                    <a href="view.php?ledger=<?= $ledger_uuid ?>&plan=<?= $plan_uuid ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}

.page-title h1 {
    margin: 0 0 8px 0;
    font-size: 2rem;
    color: #1a202c;
}

.page-title p {
    margin: 0;
    color: #718096;
}

.page-actions {
    display: flex;
    gap: 12px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}

.btn-primary {
    background: #3182ce;
    color: white;
}

.btn-primary:hover {
    background: #2c5282;
}

.btn-primary:disabled {
    background: #a0aec0;
    cursor: not-allowed;
}

.btn-secondary {
    background: #e2e8f0;
    color: #2d3748;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

.btn-large {
    padding: 14px 28px;
    font-size: 16px;
}

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    border-left: 4px solid;
}

.alert strong {
    display: block;
    margin-bottom: 4px;
}

.alert p {
    margin: 8px 0 0 0;
}

.alert-info {
    background: #ebf8ff;
    border-color: #3182ce;
    color: #2c5282;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1.3fr;
    gap: 24px;
}

.info-section,
.form-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
}

.info-section h2,
.form-section h2 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 1.25rem;
    color: #1a202c;
    border-bottom: 2px solid #3182ce;
    padding-bottom: 8px;
}

.info-section h3 {
    margin-top: 24px;
    margin-bottom: 12px;
    font-size: 1rem;
    color: #2d3748;
}

.info-card {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.info-card.readonly {
    background: #fff9e6;
    border-color: #fbd38d;
}

.info-item {
    display: flex;
    flex-direction: column;
    margin-bottom: 16px;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.info-value {
    font-size: 16px;
    color: #1a202c;
    font-weight: 600;
    margin-bottom: 4px;
}

.amount-large {
    font-size: 1.5rem;
    font-weight: 700;
    color: #3182ce;
}

.text-muted {
    color: #a0aec0;
    font-size: 12px;
}

.progress-card {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.progress-card h3 {
    margin-top: 0;
    margin-bottom: 16px;
    font-size: 1rem;
    color: #2d3748;
}

.progress-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    text-align: center;
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #3182ce;
}

.affected-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
}

.installments-list {
    max-height: 300px;
    overflow-y: auto;
    margin-top: 12px;
    margin-bottom: 12px;
}

.installment-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid #e2e8f0;
}

.installment-item:last-child {
    border-bottom: none;
}

.installment-num {
    font-weight: 700;
    color: #3182ce;
    min-width: 40px;
}

.installment-date {
    flex: 1;
    color: #4a5568;
    font-size: 14px;
}

.installment-amount {
    font-weight: 600;
    color: #1a202c;
}

.total-remaining {
    display: flex;
    justify-content: space-between;
    padding-top: 12px;
    border-top: 2px solid #3182ce;
    margin-top: 12px;
}

.total-remaining .amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #3182ce;
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-group label.required::after {
    content: ' *';
    color: #e53e3e;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-hint {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: #718096;
}

.recalc-preview {
    margin-top: 12px;
    padding: 12px;
    background: #ebf8ff;
    border: 1px solid #90cdf4;
    border-radius: 6px;
    font-size: 13px;
}

#recalc-details {
    margin-top: 8px;
    color: #2c5282;
}

.change-summary {
    background: #fffaf0;
    border: 2px solid #fbd38d;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
}

.change-summary h3 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 1rem;
    color: #744210;
}

#change-list {
    margin: 0;
    padding-left: 24px;
}

#change-list li {
    margin-bottom: 6px;
    color: #975a16;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 2px solid #e2e8f0;
}

@media (max-width: 968px) {
    .content-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .progress-stats {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
const planData = {
    uuid: '<?= $plan_uuid ?>',
    ledgerUuid: '<?= $ledger_uuid ?>',
    description: <?= json_encode($plan['description']) ?>,
    categoryUuid: <?= json_encode($plan['category_uuid']) ?>,
    notes: <?= json_encode($plan['notes'] ?? '') ?>,
    remainingInstallments: <?= $remaining_installments ?>,
    totalRemaining: <?= $total_remaining ?>
};

// Track changes
let hasChanges = false;

// Monitor form changes
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editPlanForm');
    const inputs = form.querySelectorAll('input, select, textarea');

    inputs.forEach(input => {
        input.addEventListener('change', function() {
            checkForChanges();
        });
    });

    // Monitor remaining installments for recalculation preview
    document.getElementById('remaining_installments').addEventListener('input', function() {
        updateRecalculationPreview();
    });
});

function checkForChanges() {
    const description = document.getElementById('description').value;
    const categoryUuid = document.getElementById('category_uuid').value;
    const notes = document.getElementById('notes').value;
    const remainingInstallments = parseInt(document.getElementById('remaining_installments').value);

    const changes = [];

    if (description !== planData.description) {
        changes.push(`Description changed to: "${description}"`);
    }

    if (categoryUuid !== (planData.categoryUuid || '')) {
        const categorySelect = document.getElementById('category_uuid');
        const categoryName = categorySelect.options[categorySelect.selectedIndex].text;
        changes.push(`Category changed to: ${categoryName}`);
    }

    if (notes !== planData.notes) {
        changes.push(`Notes ${planData.notes ? 'updated' : 'added'}`);
    }

    if (remainingInstallments !== planData.remainingInstallments) {
        changes.push(`Remaining installments changed from ${planData.remainingInstallments} to ${remainingInstallments}`);
    }

    hasChanges = changes.length > 0;

    const changeSummary = document.getElementById('change-summary');
    const changeList = document.getElementById('change-list');

    if (hasChanges) {
        changeSummary.style.display = 'block';
        changeList.innerHTML = changes.map(c => `<li>${c}</li>`).join('');
    } else {
        changeSummary.style.display = 'none';
    }
}

function updateRecalculationPreview() {
    const newCount = parseInt(document.getElementById('remaining_installments').value);
    const preview = document.getElementById('recalculation-preview');
    const details = document.getElementById('recalc-details');

    if (newCount !== planData.remainingInstallments && newCount > 0) {
        const newAmount = planData.totalRemaining / newCount;
        const formattedAmount = formatCurrency(newAmount);

        details.innerHTML = `
            <strong>New installment amount:</strong> ${formattedAmount} × ${newCount} installments<br>
            <strong>Total:</strong> ${formatCurrency(planData.totalRemaining)}
        `;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

function formatCurrency(amount) {
    return '$' + (amount / 100).toFixed(2);
}

async function handleSubmit(event) {
    event.preventDefault();

    if (!hasChanges) {
        alert('No changes were made.');
        return false;
    }

    const description = document.getElementById('description').value;
    const categoryUuid = document.getElementById('category_uuid').value;
    const notes = document.getElementById('notes').value;
    const remainingInstallments = parseInt(document.getElementById('remaining_installments').value);

    // Validate
    if (!description.trim()) {
        alert('Description is required.');
        return false;
    }

    if (remainingInstallments < 1 || remainingInstallments > planData.remainingInstallments) {
        alert(`Remaining installments must be between 1 and ${planData.remainingInstallments}.`);
        return false;
    }

    // Confirm if changing installment count
    if (remainingInstallments !== planData.remainingInstallments) {
        const confirm = window.confirm(
            `You are changing the number of remaining installments from ${planData.remainingInstallments} to ${remainingInstallments}.\n\n` +
            `This will reschedule and recalculate the payment amounts.\n\n` +
            `Continue?`
        );
        if (!confirm) return false;
    }

    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Saving...';

    try {
        const response = await fetch(`/pgbudget/api/installment-plans.php?plan_uuid=${planData.uuid}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                description: description,
                category_uuid: categoryUuid || null,
                notes: notes || null,
                remaining_installments: remainingInstallments
            })
        });

        const result = await response.json();

        if (result.success) {
            alert('✅ Installment plan updated successfully!');
            window.location.href = `view.php?ledger=${planData.ledgerUuid}&plan=${planData.uuid}`;
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            alert('Error: ' + (result.error || 'Failed to update plan'));
        }
    } catch (error) {
        console.error('Error:', error);
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        alert('An error occurred. Please try again.');
    }

    return false;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
