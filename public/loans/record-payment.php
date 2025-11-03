<?php
/**
 * Record Loan Payment Page
 * Form to record a payment against a loan
 * Part of Step 3.5 of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

require_once '../../includes/session.php';
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

    // Get next unpaid payment
    $stmt = $db->prepare("
        SELECT * FROM api.loan_payments
        WHERE loan_uuid = ? AND status != 'paid'
        ORDER BY payment_number
        LIMIT 1
    ");
    $stmt->execute([$loan_uuid]);
    $next_payment = $stmt->fetch();

    // Get asset accounts to pay from
    $stmt = $db->prepare("
        SELECT uuid, name, type
        FROM api.accounts
        WHERE ledger_uuid = ? AND type IN ('asset', 'liability')
        ORDER BY type, name
    ");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

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
            <h1>💵 Record Loan Payment</h1>
            <p>Record a payment for <?= htmlspecialchars($loan['lender_name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </div>

    <?php if (!$next_payment): ?>
        <div class="alert alert-info">
            <h3>All Payments Completed</h3>
            <p>All scheduled payments for this loan have been marked as paid.</p>
            <p>Current loan balance: <strong><?= formatCurrency($loan['current_balance']) ?></strong></p>
            <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-primary">Back to Loan</a>
        </div>
    <?php else: ?>

    <!-- Loan Summary Card -->
    <div class="loan-summary-card">
        <h3>Loan Summary</h3>
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-label">Current Balance:</span>
                <span class="summary-value amount negative"><?= formatCurrency($loan['current_balance']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Regular Payment:</span>
                <span class="summary-value"><?= formatCurrency($loan['payment_amount']) ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Interest Rate:</span>
                <span class="summary-value"><?= number_format($loan['interest_rate'], 2) ?>%</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Remaining Payments:</span>
                <span class="summary-value"><?= $loan['remaining_months'] ?></span>
            </div>
        </div>
    </div>

    <!-- Next Payment Info -->
    <div class="next-payment-card">
        <h3>Next Scheduled Payment</h3>
        <div class="payment-info-grid">
            <div class="payment-info-item">
                <span class="payment-info-label">Payment #:</span>
                <span class="payment-info-value"><?= $next_payment['payment_number'] ?></span>
            </div>
            <div class="payment-info-item">
                <span class="payment-info-label">Due Date:</span>
                <span class="payment-info-value"><?= date('M d, Y', strtotime($next_payment['due_date'])) ?></span>
            </div>
            <div class="payment-info-item">
                <span class="payment-info-label">Scheduled Amount:</span>
                <span class="payment-info-value"><?= formatCurrency($next_payment['scheduled_amount']) ?></span>
            </div>
            <div class="payment-info-item">
                <span class="payment-info-label">Principal:</span>
                <span class="payment-info-value text-muted"><?= formatCurrency($next_payment['scheduled_principal']) ?></span>
            </div>
            <div class="payment-info-item">
                <span class="payment-info-label">Interest:</span>
                <span class="payment-info-value text-muted"><?= formatCurrency($next_payment['scheduled_interest']) ?></span>
            </div>
        </div>
    </div>

    <!-- Payment Form -->
    <div class="form-container">
        <form id="recordPaymentForm" class="payment-form">
            <input type="hidden" name="payment_uuid" value="<?= htmlspecialchars($next_payment['uuid']) ?>">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <input type="hidden" name="loan_uuid" value="<?= htmlspecialchars($loan_uuid) ?>">

            <div class="form-section">
                <h3>Payment Details</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="paid_date">Payment Date *</label>
                        <input type="date"
                               id="paid_date"
                               name="paid_date"
                               required
                               value="<?= date('Y-m-d') ?>"
                               max="<?= date('Y-m-d') ?>">
                        <small class="form-hint">Date the payment was made</small>
                    </div>

                    <div class="form-group">
                        <label for="actual_amount">Amount Paid *</label>
                        <input type="number"
                               id="actual_amount"
                               name="actual_amount"
                               required
                               min="0.01"
                               step="0.01"
                               value="<?= number_format($next_payment['scheduled_amount'], 2, '.', '') ?>"
                               placeholder="<?= number_format($next_payment['scheduled_amount'], 2, '.', '') ?>">
                        <small class="form-hint">Amount you actually paid</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="from_account_uuid">Paid From Account *</label>
                    <select id="from_account_uuid" name="from_account_uuid" required>
                        <option value="">Select account...</option>
                        <?php
                        $current_type = '';
                        foreach ($accounts as $account):
                            if ($current_type !== $account['type']):
                                if ($current_type !== '') echo '</optgroup>';
                                $current_type = $account['type'];
                                echo '<optgroup label="' . ucfirst($account['type']) . ' Accounts">';
                            endif;
                        ?>
                            <option value="<?= $account['uuid'] ?>">
                                <?= htmlspecialchars($account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($current_type !== ''): ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <small class="form-hint">Which account did you pay from?</small>
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="500"
                              placeholder="Optional notes about this payment..."></textarea>
                    <small class="form-hint">Any additional details</small>
                </div>
            </div>

            <!-- Payment Preview -->
            <div class="form-section preview-section">
                <h3>💡 Payment Breakdown</h3>
                <p class="section-description">Based on the scheduled payment, the amount will be split as follows:</p>

                <div class="preview-grid">
                    <div class="preview-item">
                        <span class="preview-label">Principal Reduction:</span>
                        <span class="preview-value text-success" id="previewPrincipal">
                            <?= formatCurrency($next_payment['scheduled_principal']) ?>
                        </span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Interest Payment:</span>
                        <span class="preview-value text-warning" id="previewInterest">
                            <?= formatCurrency($next_payment['scheduled_interest']) ?>
                        </span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">New Balance:</span>
                        <span class="preview-value text-primary" id="previewBalance">
                            <?= formatCurrency(max(0, $loan['current_balance'] - $next_payment['scheduled_principal'])) ?>
                        </span>
                    </div>
                </div>

                <div class="preview-note">
                    <strong>Note:</strong> The principal/interest split is automatically calculated based on your loan's interest rate and remaining balance.
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                    ✓ Record Payment
                </button>
                <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <!-- Payment Impact Info -->
    <div class="info-section">
        <h3>What happens when you record a payment?</h3>
        <ul>
            <li>✓ This payment will be marked as <strong>paid</strong> in your payment schedule</li>
            <li>✓ Your loan balance will be reduced by the <strong>principal amount</strong></li>
            <li>✓ The remaining payments count will decrease by <strong>1</strong></li>
            <li>✓ Your payment history will be updated</li>
        </ul>
    </div>

    <?php endif; ?>
</div>

<style>
.loan-summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 1.5rem;
}

.loan-summary-card h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: #333;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.summary-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem;
    background: #f9f9f9;
    border-radius: 4px;
}

.summary-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
}

.summary-value {
    font-size: 1.25rem;
    font-weight: bold;
    color: #333;
}

.next-payment-card {
    background: #e3f2fd;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #2196f3;
    margin-bottom: 1.5rem;
}

.next-payment-card h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: #1565c0;
}

.payment-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.payment-info-item {
    display: flex;
    flex-direction: column;
}

.payment-info-label {
    font-size: 0.875rem;
    color: #1565c0;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.payment-info-value {
    font-size: 1rem;
    font-weight: bold;
    color: #0d47a1;
}

.form-container {
    max-width: 700px;
    margin: 0 auto 2rem;
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
    margin-top: 0;
    margin-bottom: 1rem;
    color: #2d3748;
    font-size: 1.25rem;
}

.section-description {
    color: #718096;
    margin-bottom: 1rem;
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

.preview-section {
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border: 2px solid #bee3f8;
}

.preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.preview-item {
    display: flex;
    flex-direction: column;
    padding: 1rem;
    background: white;
    border-radius: 4px;
}

.preview-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.preview-value {
    font-size: 1.25rem;
    font-weight: bold;
}

.preview-note {
    padding: 1rem;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    color: #856404;
    font-size: 0.9rem;
}

.preview-note strong {
    font-weight: 600;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.btn-lg {
    padding: 1rem 2rem;
    font-size: 1.1rem;
    font-weight: 600;
}

.info-section {
    max-width: 700px;
    margin: 0 auto;
    background: #e8f5e9;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #4caf50;
}

.info-section h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    color: #2e7d32;
}

.info-section ul {
    margin: 0;
    padding-left: 1.5rem;
}

.info-section li {
    margin-bottom: 0.5rem;
    color: #1b5e20;
    line-height: 1.6;
}

.alert {
    max-width: 700px;
    margin: 2rem auto;
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
}

.alert-info {
    background: #e3f2fd;
    border: 2px solid #2196f3;
    color: #0d47a1;
}

.alert h3 {
    margin-top: 0;
    color: #1565c0;
}

.text-success { color: #28a745; }
.text-warning { color: #f59e0b; }
.text-primary { color: #3182ce; }
.text-muted { color: #6c757d; }

.amount.negative { color: #dc3545; }

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .summary-grid,
    .payment-info-grid,
    .preview-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('recordPaymentForm');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Get form values
        const paymentUuid = document.querySelector('input[name="payment_uuid"]').value;
        const ledgerUuid = document.querySelector('input[name="ledger_uuid"]').value;
        const loanUuid = document.querySelector('input[name="loan_uuid"]').value;
        const paidDate = document.getElementById('paid_date').value;
        const actualAmount = parseFloat(document.getElementById('actual_amount').value);
        const fromAccountUuid = document.getElementById('from_account_uuid').value;
        const notes = document.getElementById('notes').value.trim();

        // Validation
        if (!paidDate) {
            alert('Payment date is required');
            return;
        }

        if (!actualAmount || actualAmount <= 0) {
            alert('Payment amount must be greater than 0');
            return;
        }

        if (!fromAccountUuid) {
            alert('Please select an account to pay from');
            return;
        }

        // Build request data
        const formData = {
            payment_uuid: paymentUuid,
            paid_date: paidDate,
            actual_amount: actualAmount,
            from_account_uuid: fromAccountUuid
        };

        // Only include notes if not empty
        if (notes) {
            formData.notes = notes;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Recording Payment...';

        try {
            const response = await fetch('../api/loan-payments.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                // Redirect to loan view page
                window.location.href = 'view.php?ledger=' + encodeURIComponent(ledgerUuid) +
                                      '&loan=' + encodeURIComponent(loanUuid);
            } else {
                alert('Error recording payment: ' + result.error);
                submitBtn.disabled = false;
                submitBtn.textContent = '✓ Record Payment';
            }
        } catch (error) {
            alert('Error recording payment: ' + error.message);
            submitBtn.disabled = false;
            submitBtn.textContent = '✓ Record Payment';
        }
    });

    // Update preview when amount changes
    const amountInput = document.getElementById('actual_amount');
    const scheduledPrincipal = <?= $next_payment['scheduled_principal'] ?? 0 ?>;
    const scheduledInterest = <?= $next_payment['scheduled_interest'] ?? 0 ?>;
    const currentBalance = <?= $loan['current_balance'] ?>;

    amountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;

        // For now, just show scheduled amounts
        // The API will calculate the actual split
        document.getElementById('previewPrincipal').textContent =
            '$' + scheduledPrincipal.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('previewInterest').textContent =
            '$' + scheduledInterest.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        const newBalance = Math.max(0, currentBalance - scheduledPrincipal);
        document.getElementById('previewBalance').textContent =
            '$' + newBalance.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
