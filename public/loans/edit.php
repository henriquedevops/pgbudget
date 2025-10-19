<?php
/**
 * Edit Loan Page
 * Form to edit mutable loan fields
 * Part of Step 3.3 of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$loan_uuid = $_GET['loan'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($loan_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get loan details
    $stmt = $db->prepare("SELECT * FROM api.get_loan(?)");
    $stmt->execute([$loan_uuid]);
    $loan = $stmt->fetch();

    if (!$loan) {
        $_SESSION['error'] = 'Loan not found.';
        header('Location: index.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
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
            <h1>Edit Loan</h1>
            <p>Update details for <?= htmlspecialchars($loan['lender_name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-secondary">Back to Loan</a>
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Loans</a>
        </div>
    </div>

    <div class="form-container">
        <form id="editLoanForm" class="loan-form">
            <input type="hidden" name="loan_uuid" value="<?= htmlspecialchars($loan_uuid) ?>">

            <!-- Editable Fields Section -->
            <div class="form-section">
                <h3>Editable Information</h3>
                <p class="section-description">Only certain fields can be changed after a loan is created to maintain payment history integrity.</p>

                <div class="form-group">
                    <label for="lender_name">Lender Name *</label>
                    <input type="text"
                           id="lender_name"
                           name="lender_name"
                           required
                           maxlength="255"
                           value="<?= htmlspecialchars($loan['lender_name']) ?>"
                           placeholder="e.g., First National Bank">
                    <small class="form-hint">The institution or person you borrowed from</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="interest_rate">Interest Rate (%) *</label>
                        <input type="number"
                               id="interest_rate"
                               name="interest_rate"
                               required
                               min="0"
                               max="100"
                               step="0.01"
                               value="<?= htmlspecialchars($loan['interest_rate']) ?>"
                               placeholder="5.25">
                        <small class="form-hint">Annual interest rate</small>
                    </div>

                    <div class="form-group">
                        <label for="interest_type">Interest Type *</label>
                        <select id="interest_type" name="interest_type" required>
                            <option value="fixed" <?= $loan['interest_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Rate</option>
                            <option value="variable" <?= $loan['interest_type'] === 'variable' ? 'selected' : '' ?>>Variable Rate</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="status">Loan Status *</label>
                    <select id="status" name="status" required>
                        <option value="active" <?= $loan['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="paid_off" <?= $loan['status'] === 'paid_off' ? 'selected' : '' ?>>Paid Off</option>
                        <option value="defaulted" <?= $loan['status'] === 'defaulted' ? 'selected' : '' ?>>Defaulted</option>
                        <option value="refinanced" <?= $loan['status'] === 'refinanced' ? 'selected' : '' ?>>Refinanced</option>
                        <option value="closed" <?= $loan['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                    <small class="form-hint">Current status of the loan</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes"
                              name="notes"
                              rows="4"
                              maxlength="1000"
                              placeholder="Optional notes about this loan..."><?= htmlspecialchars($loan['notes'] ?? '') ?></textarea>
                    <small class="form-hint">Any additional details or reminders</small>
                </div>
            </div>

            <!-- Read-Only Fields Section -->
            <div class="form-section readonly-section">
                <h3>Fixed Information (Cannot be Changed)</h3>
                <p class="section-description">These fields cannot be edited to preserve payment schedule integrity and history.</p>

                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Loan Type:</span>
                        <span class="info-value">
                            <?php
                            $type_labels = [
                                'mortgage' => '🏠 Mortgage',
                                'auto' => '🚗 Auto Loan',
                                'personal' => '👤 Personal Loan',
                                'student' => '🎓 Student Loan',
                                'credit_line' => '💳 Line of Credit',
                                'other' => '📋 Other'
                            ];
                            echo $type_labels[$loan['loan_type']] ?? ucfirst($loan['loan_type']);
                            ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Principal Amount:</span>
                        <span class="info-value"><?= formatCurrency($loan['principal_amount']) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Current Balance:</span>
                        <span class="info-value amount <?= $loan['current_balance'] > 0 ? 'negative' : 'zero' ?>">
                            <?= formatCurrency($loan['current_balance']) ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Loan Term:</span>
                        <span class="info-value"><?= $loan['loan_term_months'] ?> months</span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Remaining:</span>
                        <span class="info-value"><?= $loan['remaining_months'] ?> months</span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Payment Amount:</span>
                        <span class="info-value"><?= formatCurrency($loan['payment_amount']) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Payment Frequency:</span>
                        <span class="info-value"><?= ucfirst(str_replace('_', '-', $loan['payment_frequency'])) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Start Date:</span>
                        <span class="info-value"><?= date('M d, Y', strtotime($loan['start_date'])) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">First Payment Date:</span>
                        <span class="info-value"><?= date('M d, Y', strtotime($loan['first_payment_date'])) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Amortization Type:</span>
                        <span class="info-value"><?= ucfirst(str_replace('_', ' ', $loan['amortization_type'])) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Compounding Frequency:</span>
                        <span class="info-value"><?= ucfirst($loan['compounding_frequency']) ?></span>
                    </div>

                    <?php if ($loan['account_name']): ?>
                    <div class="info-item">
                        <span class="info-label">Linked Account:</span>
                        <span class="info-value"><?= htmlspecialchars($loan['account_name']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-note">
                    <strong>Note:</strong> If you need to change these fields, you'll need to delete this loan and create a new one.
                    Make sure to record any payments already made before doing so.
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="submitBtn">Update Loan</button>
                <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<style>
.form-container {
    max-width: 800px;
    margin: 2rem auto;
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid #e0e0e0;
}

.form-section:last-of-type {
    border-bottom: none;
}

.form-section h3 {
    margin-bottom: 0.5rem;
    color: #2d3748;
    font-size: 1.25rem;
}

.section-description {
    color: #718096;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
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
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #718096;
}

.readonly-section {
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem;
    background: white;
    border-radius: 4px;
    border-left: 3px solid #cbd5e0;
}

.info-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.info-value {
    font-size: 1rem;
    color: #2d3748;
    font-weight: 600;
}

.info-note {
    padding: 1rem;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    color: #856404;
    font-size: 0.9rem;
}

.info-note strong {
    font-weight: 600;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.amount.negative {
    color: #dc3545;
}

.amount.zero {
    color: #6c757d;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('editLoanForm');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Get form values
        const loanUuid = document.querySelector('input[name="loan_uuid"]').value;
        const lenderName = document.getElementById('lender_name').value.trim();
        const interestRate = parseFloat(document.getElementById('interest_rate').value);
        const interestType = document.getElementById('interest_type').value;
        const status = document.getElementById('status').value;
        const notes = document.getElementById('notes').value.trim();

        // Validation
        if (!lenderName) {
            alert('Lender name is required');
            return;
        }

        if (isNaN(interestRate) || interestRate < 0 || interestRate > 100) {
            alert('Interest rate must be between 0 and 100');
            return;
        }

        // Build request data
        const formData = {
            loan_uuid: loanUuid,
            lender_name: lenderName,
            interest_rate: interestRate,
            interest_type: interestType,
            status: status
        };

        // Only include notes if not empty
        if (notes) {
            formData.notes = notes;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Updating...';

        try {
            const response = await fetch('../api/loans.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                // Redirect to loan view page
                const urlParams = new URLSearchParams(window.location.search);
                const ledgerUuid = urlParams.get('ledger');
                window.location.href = 'view.php?ledger=' + encodeURIComponent(ledgerUuid) +
                                      '&loan=' + encodeURIComponent(loanUuid);
            } else {
                alert('Error updating loan: ' + result.error);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Update Loan';
            }
        } catch (error) {
            alert('Error updating loan: ' + error.message);
            submitBtn.disabled = false;
            submitBtn.textContent = 'Update Loan';
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
