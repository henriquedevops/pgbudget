<?php
/**
 * Edit Obligation Page
 * Form to edit an existing obligation/bill
 * Part of Phase 3 of OBLIGATIONS_BILLS_IMPLEMENTATION_PLAN.md
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$obligation_uuid = $_GET['obligation'] ?? '';

if (empty($ledger_uuid) || empty($obligation_uuid)) {
    $_SESSION['error'] = 'Invalid parameters.';
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

    // Get obligation details
    $stmt = $db->prepare("SELECT * FROM api.get_obligation(?)");
    $stmt->execute([$obligation_uuid]);
    $obligation = $stmt->fetch();

    if (!$obligation) {
        $_SESSION['error'] = 'Obligation not found.';
        header("Location: index.php?ledger=$ledger_uuid");
        exit;
    }

    // Get existing payees
    $stmt = $db->prepare("
        SELECT DISTINCT name
        FROM data.payees
        WHERE user_data = ?
        ORDER BY name
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
            <h1>Edit Obligation</h1>
            <p>Update <?= htmlspecialchars($obligation['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Obligations</a>
        </div>
    </div>

    <div class="form-container">
        <div class="alert alert-info">
            <strong>ℹ️ Note:</strong> Some fields like frequency and start date cannot be changed after creation as they affect the payment schedule. To change these, create a new obligation.
        </div>

        <form id="editObligationForm" class="obligation-form">
            <input type="hidden" name="obligation_uuid" value="<?= htmlspecialchars($obligation_uuid) ?>">
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
                           value="<?= htmlspecialchars($obligation['name']) ?>"
                           placeholder="e.g., Electric Bill - Main Street">
                </div>

                <div class="form-group">
                    <label for="payee_name">Payee *</label>
                    <input type="text"
                           id="payee_name"
                           name="payee_name"
                           required
                           list="payee_list"
                           maxlength="255"
                           value="<?= htmlspecialchars($obligation['payee_name']) ?>"
                           placeholder="Start typing to search or add new payee...">
                    <datalist id="payee_list">
                        <?php foreach ($existing_payees as $payee): ?>
                            <option value="<?= htmlspecialchars($payee) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description"
                              name="description"
                              rows="2"
                              maxlength="500"
                              placeholder="Additional notes about this obligation..."><?= htmlspecialchars($obligation['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="readonly-label">Type:</label>
                    <div class="readonly-value">
                        <?= ucfirst(str_replace('_', ' ', $obligation['obligation_type'])) ?>
                        <?php if ($obligation['obligation_subtype']): ?>
                            - <?= htmlspecialchars($obligation['obligation_subtype']) ?>
                        <?php endif; ?>
                    </div>
                    <small class="form-hint">Cannot be changed after creation</small>
                </div>

                <div class="form-group">
                    <label class="readonly-label">Frequency:</label>
                    <div class="readonly-value">
                        <?= ucfirst($obligation['frequency']) ?>
                    </div>
                    <small class="form-hint">Cannot be changed after creation</small>
                </div>

                <div class="form-group">
                    <label class="readonly-label">Start Date:</label>
                    <div class="readonly-value">
                        <?= date('F j, Y', strtotime($obligation['start_date'])) ?>
                    </div>
                    <small class="form-hint">Cannot be changed after creation</small>
                </div>
            </div>

            <!-- Amount Details -->
            <div class="form-section">
                <h3>Amount Details</h3>

                <input type="hidden" name="is_fixed_amount" value="<?= $obligation['is_fixed_amount'] ? 'true' : 'false' ?>">

                <?php if ($obligation['is_fixed_amount']): ?>
                    <div class="form-group">
                        <label for="fixed_amount">Fixed Amount *</label>
                        <input type="number"
                               id="fixed_amount"
                               name="fixed_amount"
                               required
                               min="0.01"
                               step="0.01"
                               value="<?= $obligation['fixed_amount'] ?>"
                               placeholder="0.00"
                               data-original="<?= $obligation['fixed_amount'] ?>">
                        <small class="form-hint">The exact amount due each period</small>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label for="estimated_amount">Estimated Amount *</label>
                        <input type="number"
                               id="estimated_amount"
                               name="estimated_amount"
                               required
                               min="0.01"
                               step="0.01"
                               value="<?= $obligation['estimated_amount'] ?>"
                               placeholder="0.00"
                               data-original="<?= $obligation['estimated_amount'] ?>">
                        <small class="form-hint">Average or expected amount</small>
                    </div>
                <?php endif; ?>

                <!-- Effective-date selector — shown when amount changes -->
                <div id="amountTimingSection" class="amount-timing-section" style="display:none;">
                    <div class="timing-header">When should this new amount take effect?</div>
                    <div class="timing-options">
                        <label class="timing-opt">
                            <input type="radio" name="amount_timing" value="immediately" checked>
                            <span class="timing-opt-label">
                                <strong>Immediately</strong>
                                <small>Update the current amount now — affects all future months</small>
                            </span>
                        </label>
                        <label class="timing-opt">
                            <input type="radio" name="amount_timing" value="future">
                            <span class="timing-opt-label">
                                <strong>Starting from a specific date</strong>
                                <small>Keep the current amount until that date; projection uses new amount after</small>
                            </span>
                        </label>
                    </div>
                    <div id="futureDatePicker" class="future-date-picker" style="display:none;">
                        <label for="future_amount_effective_date">Effective from (first of month)</label>
                        <input type="month"
                               id="future_amount_effective_date"
                               name="future_amount_effective_date"
                               min="<?= date('Y-m', strtotime('+1 month')) ?>">
                        <small class="form-hint">The new amount will appear in the cash-flow projection from this month onward</small>
                    </div>
                </div>

                <?php if (!empty($obligation['future_amount_effective_date'])): ?>
                <div class="future-amount-banner" id="futureAmountBanner">
                    <div class="future-amount-info">
                        <span class="future-amount-icon">📅</span>
                        <div>
                            <strong>Scheduled amount change:</strong>
                            <?php
                            $future_val = $obligation['future_fixed_amount'] ?? $obligation['future_estimated_amount'];
                            $eff_date   = date('F Y', strtotime($obligation['future_amount_effective_date']));
                            ?>
                            $<?= number_format((float)$future_val, 2) ?> starting <?= $eff_date ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-small btn-danger" id="clearFutureAmountBtn">
                        Remove scheduled change
                    </button>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="readonly-label">Amount Type:</label>
                    <div class="readonly-value">
                        <?= $obligation['is_fixed_amount'] ? 'Fixed' : 'Variable/Estimated' ?>
                    </div>
                    <small class="form-hint">Cannot be changed after creation</small>
                </div>
            </div>

            <!-- Reminder Settings -->
            <div class="form-section">
                <h3>Payment Reminders</h3>

                <div class="form-group">
                    <label for="reminder_days_before">Remind me (days before due date)</label>
                    <input type="number"
                           id="reminder_days_before"
                           name="reminder_days_before"
                           min="0"
                           max="30"
                           value="<?= $obligation['reminder_days_before'] ?>">
                </div>
            </div>

            <!-- Advanced Settings -->
            <div class="form-section">
                <h3>Advanced Settings</h3>

                <div class="form-group">
                    <label for="grace_period_days">Grace Period (Days)</label>
                    <input type="number"
                           id="grace_period_days"
                           name="grace_period_days"
                           min="0"
                           max="60"
                           value="<?= $obligation['grace_period_days'] ?>"
                           placeholder="0">
                    <small class="form-hint">Days after due date before it's considered late</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"
                              placeholder="Any additional information about this obligation..."><?= htmlspecialchars($obligation['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Status Controls -->
            <div class="form-section">
                <h3>Status</h3>

                <div class="form-group">
                    <label>
                        <input type="checkbox"
                               id="is_active"
                               name="is_active"
                               <?= $obligation['is_active'] ? 'checked' : '' ?>>
                        Active (uncheck to deactivate this obligation)
                    </label>
                    <small class="form-hint">Inactive obligations won't generate new payment schedules</small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox"
                               id="is_paused"
                               name="is_paused"
                               <?= $obligation['is_paused'] ? 'checked' : '' ?>>
                        Temporarily paused
                    </label>
                    <small class="form-hint">Useful for temporary holds like summer breaks for subscriptions</small>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Update Obligation</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary btn-lg">Cancel</a>
                <button type="button" class="btn btn-danger btn-lg" id="deleteBtn">Delete Obligation</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Delete Obligation</h2>
        <p>Are you sure you want to delete <strong><?= htmlspecialchars($obligation['name']) ?></strong>?</p>
        <p class="warning-text">⚠️ This will also delete all scheduled and completed payments for this obligation. This action cannot be undone.</p>
        <div class="modal-actions">
            <button id="confirmDelete" class="btn btn-danger">Delete Obligation</button>
            <button id="cancelDelete" class="btn btn-secondary">Cancel</button>
        </div>
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
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 1rem;
}

.form-group input:focus,
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

.readonly-label {
    font-weight: 500;
    color: #666;
}

.readonly-value {
    padding: 0.75rem;
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    margin-top: 0.5rem;
    color: #666;
}

.form-actions {
    padding: 2rem;
    background: #f9f9f9;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
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

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
}

.modal-content h2 {
    margin-top: 0;
}

.warning-text {
    color: #d32f2f;
    font-weight: 500;
}

.modal-actions {
    margin-top: 1.5rem;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

@media (max-width: 768px) {
    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
    }
}

/* Amount timing section */
.amount-timing-section {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 6px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}

.timing-header {
    font-weight: 600;
    font-size: 0.9rem;
    color: #0369a1;
    margin-bottom: 0.75rem;
}

.timing-options {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.timing-opt {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    cursor: pointer;
    padding: 0.5rem 0.65rem;
    border-radius: 4px;
    border: 1px solid transparent;
    transition: background 0.15s;
}

.timing-opt:hover {
    background: #e0f2fe;
}

.timing-opt input[type="radio"] {
    margin-top: 0.2rem;
    flex-shrink: 0;
}

.timing-opt-label {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.timing-opt-label strong {
    font-size: 0.9rem;
    color: #1e293b;
}

.timing-opt-label small {
    font-size: 0.78rem;
    color: #64748b;
}

.future-date-picker {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid #bae6fd;
}

.future-date-picker label {
    display: block;
    font-weight: 500;
    font-size: 0.85rem;
    color: #334155;
    margin-bottom: 0.35rem;
}

.future-date-picker input[type="month"] {
    padding: 0.5rem 0.65rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
    font-size: 0.9rem;
}

/* Scheduled future amount banner */
.future-amount-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    padding: 0.75rem 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.future-amount-info {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.875rem;
    color: #78350f;
}

.future-amount-icon {
    font-size: 1.1rem;
}

.future-amount-info strong {
    color: #92400e;
}
</style>

<script>
/* ---- Amount timing UI -------------------------------------------- */
(function () {
    const amountInput = document.getElementById('fixed_amount') || document.getElementById('estimated_amount');
    const timingSection = document.getElementById('amountTimingSection');
    const futureDatePicker = document.getElementById('futureDatePicker');
    const futureDateInput = document.getElementById('future_amount_effective_date');
    const timingRadios = document.querySelectorAll('input[name="amount_timing"]');

    if (!amountInput || !timingSection) return;

    // Show timing section when the amount value changes from its original
    amountInput.addEventListener('input', function () {
        const orig = parseFloat(this.dataset.original || '0');
        const curr = parseFloat(this.value || '0');
        timingSection.style.display = (Math.abs(curr - orig) > 0.001) ? '' : 'none';
        if (timingSection.style.display === 'none') {
            // Reset to default so no spurious timing is submitted
            document.querySelector('input[name="amount_timing"][value="immediately"]').checked = true;
            futureDatePicker.style.display = 'none';
            if (futureDateInput) futureDateInput.removeAttribute('required');
        }
    });

    // Show/hide date picker based on radio selection
    timingRadios.forEach(function (r) {
        r.addEventListener('change', function () {
            const isFuture = this.value === 'future';
            futureDatePicker.style.display = isFuture ? '' : 'none';
            if (futureDateInput) {
                if (isFuture) {
                    futureDateInput.setAttribute('required', '');
                    // Default to next month
                    if (!futureDateInput.value) {
                        const d = new Date();
                        d.setMonth(d.getMonth() + 1);
                        futureDateInput.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                    }
                } else {
                    futureDateInput.removeAttribute('required');
                }
            }
        });
    });

    // "Remove scheduled change" button
    const clearBtn = document.getElementById('clearFutureAmountBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', async function () {
            if (!confirm('Remove the scheduled amount change? The current amount will remain unchanged.')) return;
            clearBtn.disabled = true;
            clearBtn.textContent = 'Removing…';
            const fd = new FormData();
            fd.append('action', 'update');
            fd.append('obligation_uuid', '<?= $obligation_uuid ?>');
            fd.append('amount_timing', 'clear');
            try {
                const res = await fetch('../api/obligations.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('futureAmountBanner').remove();
                } else {
                    alert('Error: ' + data.error);
                    clearBtn.disabled = false;
                    clearBtn.textContent = 'Remove scheduled change';
                }
            } catch (err) {
                alert('Error: ' + err.message);
                clearBtn.disabled = false;
                clearBtn.textContent = 'Remove scheduled change';
            }
        });
    }
})();

/* ---- Form submission --------------------------------------------- */
document.getElementById('editObligationForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'update');

    // Convert checkboxes to boolean strings
    formData.set('is_active', document.getElementById('is_active').checked ? 'true' : 'false');
    formData.set('is_paused', document.getElementById('is_paused').checked ? 'true' : 'false');

    // Convert future_amount_effective_date from YYYY-MM to YYYY-MM-01
    const futureMonthInput = document.getElementById('future_amount_effective_date');
    if (futureMonthInput && futureMonthInput.value) {
        formData.set('future_amount_effective_date', futureMonthInput.value + '-01');
    }

    // Ensure amount_timing is set
    const timingSection = document.getElementById('amountTimingSection');
    if (!timingSection || timingSection.style.display === 'none') {
        // Amount didn't change — don't touch amounts at all
        formData.delete('fixed_amount');
        formData.delete('estimated_amount');
        formData.set('amount_timing', 'noop');
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating...';

    try {
        const response = await fetch('../api/obligations.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Obligation updated successfully';
        } else {
            alert('Error updating obligation: ' + result.error);
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error updating obligation: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

// Delete functionality
const deleteModal = document.getElementById('deleteModal');
const deleteBtn = document.getElementById('deleteBtn');
const confirmDeleteBtn = document.getElementById('confirmDelete');
const cancelDeleteBtn = document.getElementById('cancelDelete');

deleteBtn.addEventListener('click', function() {
    deleteModal.style.display = 'flex';
});

cancelDeleteBtn.addEventListener('click', function() {
    deleteModal.style.display = 'none';
});

confirmDeleteBtn.addEventListener('click', async function() {
    const obligationUuid = '<?= $obligation_uuid ?>';

    try {
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.textContent = 'Deleting...';

        const response = await fetch(`../api/obligations.php?obligation_uuid=${obligationUuid}`, {
            method: 'DELETE'
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>&success=Obligation deleted successfully';
        } else {
            alert('Error deleting obligation: ' + result.error);
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = 'Delete Obligation';
        }
    } catch (error) {
        alert('Error deleting obligation: ' + error.message);
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.textContent = 'Delete Obligation';
    }
});

// Close modal on background click
deleteModal.addEventListener('click', function(e) {
    if (e.target === deleteModal) {
        deleteModal.style.display = 'none';
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && deleteModal.style.display === 'flex') {
        deleteModal.style.display = 'none';
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
