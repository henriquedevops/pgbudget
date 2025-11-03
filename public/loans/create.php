<?php
/**
 * Create Loan Page
 * Form to create a new loan with payment schedule
 * Part of Step 3.2 of LOAN_MANAGEMENT_IMPLEMENTATION.md
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

    // Get liability accounts for the dropdown
    $stmt = $db->prepare("
        SELECT uuid, name
        FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'liability'
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $liability_accounts = $stmt->fetchAll();

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
            <h1>Create New Loan</h1>
            <p>Add a new loan to track in <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Loans</a>
        </div>
    </div>

    <div class="form-container">
        <form id="createLoanForm" class="loan-form">
            <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger_uuid) ?>">

            <!-- Loan Identification -->
            <div class="form-section">
                <h3>Loan Details</h3>

                <div class="form-group">
                    <label for="lender_name">Lender Name *</label>
                    <input type="text"
                           id="lender_name"
                           name="lender_name"
                           required
                           maxlength="255"
                           placeholder="e.g., First National Bank, Toyota Financial">
                    <small class="form-hint">The institution or person you borrowed from</small>
                </div>

                <div class="form-group">
                    <label for="loan_type">Loan Type *</label>
                    <select id="loan_type" name="loan_type" required>
                        <option value="">Select loan type...</option>
                        <option value="mortgage">🏠 Mortgage</option>
                        <option value="auto">🚗 Auto Loan</option>
                        <option value="personal">👤 Personal Loan</option>
                        <option value="student">🎓 Student Loan</option>
                        <option value="credit_line">💳 Line of Credit</option>
                        <option value="other">📋 Other</option>
                    </select>
                    <small class="form-hint">What type of loan is this?</small>
                </div>

                <div class="form-group">
                    <label for="account_uuid">Linked Liability Account (Optional)</label>
                    <select id="account_uuid" name="account_uuid">
                        <option value="">-- None --</option>
                        <?php foreach ($liability_accounts as $account): ?>
                            <option value="<?= $account['uuid'] ?>">
                                <?= htmlspecialchars($account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Link this loan to a liability account (optional)</small>
                </div>
            </div>

            <!-- Loan Amounts -->
            <div class="form-section">
                <h3>Loan Amount & Terms</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="principal_amount">Principal Amount *</label>
                        <input type="number"
                               id="principal_amount"
                               name="principal_amount"
                               required
                               min="0.01"
                               step="0.01"
                               placeholder="25000.00">
                        <small class="form-hint">Total amount borrowed</small>
                    </div>

                    <div class="form-group">
                        <label for="interest_rate">Interest Rate (%) *</label>
                        <input type="number"
                               id="interest_rate"
                               name="interest_rate"
                               required
                               min="0"
                               max="100"
                               step="0.01"
                               placeholder="5.25">
                        <small class="form-hint">Annual interest rate</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="interest_type">Interest Type *</label>
                        <select id="interest_type" name="interest_type" required>
                            <option value="fixed">Fixed Rate</option>
                            <option value="variable">Variable Rate</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="compounding_frequency">Compounding Frequency *</label>
                        <select id="compounding_frequency" name="compounding_frequency" required>
                            <option value="monthly" selected>Monthly</option>
                            <option value="daily">Daily</option>
                            <option value="annually">Annually</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="loan_term_months">Loan Term (Months) *</label>
                        <input type="number"
                               id="loan_term_months"
                               name="loan_term_months"
                               required
                               min="1"
                               step="1"
                               placeholder="60">
                        <small class="form-hint">Or use common terms:</small>
                        <div class="term-shortcuts">
                            <button type="button" class="btn-term" data-months="12">1 year</button>
                            <button type="button" class="btn-term" data-months="24">2 years</button>
                            <button type="button" class="btn-term" data-months="36">3 years</button>
                            <button type="button" class="btn-term" data-months="60">5 years</button>
                            <button type="button" class="btn-term" data-months="120">10 years</button>
                            <button type="button" class="btn-term" data-months="180">15 years</button>
                            <button type="button" class="btn-term" data-months="240">20 years</button>
                            <button type="button" class="btn-term" data-months="360">30 years</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="amortization_type">Amortization Type *</label>
                        <select id="amortization_type" name="amortization_type" required>
                            <option value="standard" selected>Standard (Principal + Interest)</option>
                            <option value="interest_only">Interest Only</option>
                            <option value="balloon">Balloon Payment</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Payment Schedule -->
            <div class="form-section">
                <h3>Payment Schedule</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Loan Start Date *</label>
                        <input type="date"
                               id="start_date"
                               name="start_date"
                               required>
                        <small class="form-hint">When the loan was originated</small>
                    </div>

                    <div class="form-group">
                        <label for="first_payment_date">First Payment Date *</label>
                        <input type="date"
                               id="first_payment_date"
                               name="first_payment_date"
                               required>
                        <small class="form-hint">When your first payment is due</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="payment_frequency">Payment Frequency *</label>
                        <select id="payment_frequency" name="payment_frequency" required>
                            <option value="monthly" selected>Monthly</option>
                            <option value="bi-weekly">Bi-Weekly (every 2 weeks)</option>
                            <option value="weekly">Weekly</option>
                            <option value="quarterly">Quarterly</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="payment_day_of_month">Payment Day of Month</label>
                        <input type="number"
                               id="payment_day_of_month"
                               name="payment_day_of_month"
                               min="1"
                               max="31"
                               step="1"
                               placeholder="15">
                        <small class="form-hint">Day of month payment is due (for monthly payments)</small>
                    </div>
                </div>
            </div>

            <!-- Initial Payments Section -->
            <div class="form-section">
                <h3>📊 Already Made Payments?</h3>
                <p class="form-hint">If you've already made payments on this loan before tracking it here, enter those details below.</p>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="has_initial_payment" name="has_initial_payment">
                        I've already made payments on this loan
                    </label>
                </div>

                <div id="initialPaymentFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="initial_amount_paid">Total Amount Paid So Far *</label>
                            <input type="number"
                                   id="initial_amount_paid"
                                   name="initial_amount_paid"
                                   min="0.01"
                                   step="0.01"
                                   placeholder="0.00">
                            <small class="form-hint">Total amount you've paid toward principal and interest</small>
                        </div>

                        <div class="form-group">
                            <label for="initial_paid_as_of_date">As of Date *</label>
                            <input type="date"
                                   id="initial_paid_as_of_date"
                                   name="initial_paid_as_of_date">
                            <small class="form-hint">Date when this paid amount was current</small>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <strong>Note:</strong> The current balance and remaining months will be automatically adjusted based on the amount you've already paid.
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="form-section">
                <h3>Additional Information</h3>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"
                              placeholder="Optional notes about this loan..."></textarea>
                    <small class="form-hint">Any additional details or reminders</small>
                </div>
            </div>

            <!-- Preview Section -->
            <div class="form-section preview-section" id="previewSection" style="display: none;">
                <h3>💡 Loan Preview</h3>
                <div class="preview-grid">
                    <div class="preview-item">
                        <span class="preview-label">Monthly Payment:</span>
                        <span class="preview-value" id="previewPayment">--</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Total Interest:</span>
                        <span class="preview-value" id="previewInterest">--</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Total Paid:</span>
                        <span class="preview-value" id="previewTotal">--</span>
                    </div>
                </div>
                <button type="button" id="calculateBtn" class="btn btn-info">Calculate Payment</button>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="submitBtn">Create Loan</button>
                <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Loan Types Guide -->
    <div class="loan-guide">
        <h3>Loan Types Guide</h3>
        <div class="guide-grid">
            <div class="guide-card">
                <h4>🏠 Mortgage</h4>
                <p>Home loans and real estate financing</p>
            </div>
            <div class="guide-card">
                <h4>🚗 Auto Loan</h4>
                <p>Vehicle financing and car loans</p>
            </div>
            <div class="guide-card">
                <h4>👤 Personal Loan</h4>
                <p>General purpose unsecured loans</p>
            </div>
            <div class="guide-card">
                <h4>🎓 Student Loan</h4>
                <p>Education and tuition financing</p>
            </div>
            <div class="guide-card">
                <h4>💳 Line of Credit</h4>
                <p>Revolving credit and HELOC</p>
            </div>
            <div class="guide-card">
                <h4>📋 Other</h4>
                <p>Other types of loans and financing</p>
            </div>
        </div>
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
    margin-bottom: 1rem;
    color: #2d3748;
    font-size: 1.25rem;
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

.term-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.btn-term {
    padding: 0.25rem 0.75rem;
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-term:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.btn-term:active {
    background: #e2e8f0;
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
    color: #2d3748;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.loan-guide {
    max-width: 800px;
    margin: 3rem auto;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 8px;
}

.loan-guide h3 {
    margin-bottom: 1rem;
    color: #2d3748;
}

.guide-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.guide-card {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #3182ce;
}

.guide-card h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.guide-card p {
    color: #4a5568;
    font-size: 0.875rem;
}

.alert {
    padding: 1rem;
    border-radius: 6px;
    margin-top: 1rem;
}

.alert-info {
    background: #e6f7ff;
    border: 1px solid #91d5ff;
    color: #0050b3;
}

.alert strong {
    font-weight: 600;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('createLoanForm');
    const calculateBtn = document.getElementById('calculateBtn');
    const previewSection = document.getElementById('previewSection');
    const submitBtn = document.getElementById('submitBtn');

    // Term shortcuts
    document.querySelectorAll('.btn-term').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('loan_term_months').value = this.dataset.months;
        });
    });

    // Show preview section when key fields are filled
    ['principal_amount', 'interest_rate', 'loan_term_months'].forEach(id => {
        document.getElementById(id).addEventListener('input', function() {
            const principal = parseFloat(document.getElementById('principal_amount').value);
            const rate = parseFloat(document.getElementById('interest_rate').value);
            const term = parseInt(document.getElementById('loan_term_months').value);

            if (principal > 0 && rate >= 0 && term > 0) {
                previewSection.style.display = 'block';
            }
        });
    });

    // Calculate payment preview
    calculateBtn.addEventListener('click', function() {
        const principal = parseFloat(document.getElementById('principal_amount').value);
        const annualRate = parseFloat(document.getElementById('interest_rate').value);
        const term = parseInt(document.getElementById('loan_term_months').value);

        if (!principal || !term || annualRate === undefined) {
            alert('Please fill in principal amount, interest rate, and loan term');
            return;
        }

        const monthlyRate = annualRate / 100 / 12;
        let monthlyPayment;

        if (monthlyRate > 0) {
            monthlyPayment = principal * (monthlyRate * Math.pow(1 + monthlyRate, term)) /
                           (Math.pow(1 + monthlyRate, term) - 1);
        } else {
            monthlyPayment = principal / term;
        }

        const totalPaid = monthlyPayment * term;
        const totalInterest = totalPaid - principal;

        document.getElementById('previewPayment').textContent =
            '$' + monthlyPayment.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('previewInterest').textContent =
            '$' + totalInterest.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        document.getElementById('previewTotal').textContent =
            '$' + totalPaid.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    });

    // Toggle initial payment fields
    const hasInitialPaymentCheckbox = document.getElementById('has_initial_payment');
    const initialPaymentFields = document.getElementById('initialPaymentFields');

    hasInitialPaymentCheckbox.addEventListener('change', function() {
        if (this.checked) {
            initialPaymentFields.style.display = 'block';
            document.getElementById('initial_amount_paid').required = true;
            document.getElementById('initial_paid_as_of_date').required = true;
        } else {
            initialPaymentFields.style.display = 'none';
            document.getElementById('initial_amount_paid').required = false;
            document.getElementById('initial_paid_as_of_date').required = false;
            document.getElementById('initial_amount_paid').value = '';
            document.getElementById('initial_paid_as_of_date').value = '';
        }
    });

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Validate required fields
        const lenderName = document.getElementById('lender_name').value.trim();
        const loanType = document.getElementById('loan_type').value;
        const principal = parseFloat(document.getElementById('principal_amount').value);
        const interestRate = parseFloat(document.getElementById('interest_rate').value);
        const loanTerm = parseInt(document.getElementById('loan_term_months').value);
        const startDate = document.getElementById('start_date').value;
        const firstPaymentDate = document.getElementById('first_payment_date').value;
        const paymentFrequency = document.getElementById('payment_frequency').value;

        if (!lenderName || !loanType || !principal || interestRate === undefined ||
            !loanTerm || !startDate || !firstPaymentDate || !paymentFrequency) {
            alert('Please fill in all required fields');
            return;
        }

        // Build request data
        const formData = {
            ledger_uuid: document.querySelector('input[name="ledger_uuid"]').value,
            lender_name: lenderName,
            loan_type: loanType,
            principal_amount: principal,
            interest_rate: interestRate,
            loan_term_months: loanTerm,
            start_date: startDate,
            first_payment_date: firstPaymentDate,
            payment_frequency: paymentFrequency,
            interest_type: document.getElementById('interest_type').value,
            compounding_frequency: document.getElementById('compounding_frequency').value,
            amortization_type: document.getElementById('amortization_type').value
        };

        // Optional fields
        const accountUuid = document.getElementById('account_uuid').value;
        if (accountUuid) {
            formData.account_uuid = accountUuid;
        }

        const paymentDay = document.getElementById('payment_day_of_month').value;
        if (paymentDay) {
            formData.payment_day_of_month = parseInt(paymentDay);
        }

        const notes = document.getElementById('notes').value.trim();
        if (notes) {
            formData.notes = notes;
        }

        // Include initial payment data if provided
        if (hasInitialPaymentCheckbox.checked) {
            const initialAmountPaid = parseFloat(document.getElementById('initial_amount_paid').value);
            const initialPaidDate = document.getElementById('initial_paid_as_of_date').value;

            if (!initialAmountPaid || !initialPaidDate) {
                alert('Please fill in both initial payment amount and date');
                return;
            }

            if (initialAmountPaid > principal) {
                alert('Initial amount paid cannot exceed the principal amount');
                return;
            }

            formData.initial_amount_paid = initialAmountPaid;
            formData.initial_paid_as_of_date = initialPaidDate;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating Loan...';

        try {
            const response = await fetch('../api/loans.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const result = await response.json();

            if (result.success) {
                window.location.href = 'index.php?ledger=' + encodeURIComponent(formData.ledger_uuid);
            } else {
                alert('Error creating loan: ' + result.error);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Loan';
            }
        } catch (error) {
            alert('Error creating loan: ' + error.message);
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Loan';
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
