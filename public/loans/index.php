<?php
/**
 * Loans List Page
 * Display all loans for the current ledger
 * Part of Step 3.1 of LOAN_MANAGEMENT_IMPLEMENTATION.md
 */

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

    // Get all loans for this ledger
    $stmt = $db->prepare("SELECT * FROM api.get_loans(?)");
    $stmt->execute([$ledger_uuid]);
    $loans = $stmt->fetchAll();

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
            <h1>💰 Loans</h1>
            <p>Manage loans for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Loan</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <?php if (empty($loans)): ?>
        <div class="empty-state">
            <h3>No loans found</h3>
            <p>Track your mortgages, auto loans, student loans, and other debts with detailed payment schedules.</p>
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Loan</a>
        </div>
    <?php else: ?>
        <!-- Quick Summary -->
        <?php
        $total_principal = 0;
        $total_balance = 0;
        $total_monthly_payment = 0;
        $active_loans = 0;

        foreach ($loans as $loan) {
            $total_principal += floatval($loan['principal_amount']);
            $total_balance += floatval($loan['current_balance']);

            if ($loan['status'] === 'active') {
                $active_loans++;
                if ($loan['payment_frequency'] === 'monthly') {
                    $total_monthly_payment += floatval($loan['payment_amount']);
                } elseif ($loan['payment_frequency'] === 'bi-weekly') {
                    $total_monthly_payment += floatval($loan['payment_amount']) * 26 / 12;
                } elseif ($loan['payment_frequency'] === 'weekly') {
                    $total_monthly_payment += floatval($loan['payment_amount']) * 52 / 12;
                } elseif ($loan['payment_frequency'] === 'quarterly') {
                    $total_monthly_payment += floatval($loan['payment_amount']) / 3;
                }
            }
        }
        $total_paid = $total_principal - $total_balance;
        ?>

        <div class="loan-summary-cards">
            <div class="summary-card">
                <div class="summary-card-label">Active Loans</div>
                <div class="summary-card-value"><?= $active_loans ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total Borrowed</div>
                <div class="summary-card-value"><?= formatCurrency($total_principal) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Current Balance</div>
                <div class="summary-card-value amount negative"><?= formatCurrency($total_balance) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total Paid</div>
                <div class="summary-card-value amount positive"><?= formatCurrency($total_paid) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Monthly Payments</div>
                <div class="summary-card-value"><?= formatCurrency($total_monthly_payment) ?></div>
            </div>
        </div>

        <!-- Loans Table -->
        <div class="loans-section">
            <table class="table loans-table">
                <thead>
                    <tr>
                        <th>Lender</th>
                        <th>Type</th>
                        <th>Principal</th>
                        <th>Current Balance</th>
                        <th>Interest Rate</th>
                        <th>Payment</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loans as $loan): ?>
                        <tr class="loan-row loan-status-<?= strtolower($loan['status']) ?>">
                            <td>
                                <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan['uuid'] ?>" class="loan-name">
                                    <strong><?= htmlspecialchars($loan['lender_name']) ?></strong>
                                </a>
                                <?php if ($loan['account_name']): ?>
                                    <br><small class="text-muted">→ <?= htmlspecialchars($loan['account_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="loan-type-badge loan-type-<?= $loan['loan_type'] ?>">
                                    <?php
                                    $type_labels = [
                                        'mortgage' => '🏠 Mortgage',
                                        'auto' => '🚗 Auto',
                                        'personal' => '👤 Personal',
                                        'student' => '🎓 Student',
                                        'credit_line' => '💳 Credit Line',
                                        'other' => '📋 Other'
                                    ];
                                    echo $type_labels[$loan['loan_type']] ?? ucfirst($loan['loan_type']);
                                    ?>
                                </span>
                            </td>
                            <td>
                                <span class="amount"><?= formatCurrency($loan['principal_amount']) ?></span>
                            </td>
                            <td>
                                <span class="amount <?= $loan['current_balance'] > 0 ? 'negative' : 'zero' ?>">
                                    <?= formatCurrency($loan['current_balance']) ?>
                                </span>
                                <?php if ($loan['current_balance'] > 0): ?>
                                    <?php
                                    $percent_paid = (($loan['principal_amount'] - $loan['current_balance']) / $loan['principal_amount']) * 100;
                                    ?>
                                    <div class="progress-bar-container" title="<?= number_format($percent_paid, 1) ?>% paid">
                                        <div class="progress-bar" style="width: <?= min(100, $percent_paid) ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="interest-rate"><?= number_format($loan['interest_rate'], 2) ?>%</span>
                                <br><small class="text-muted"><?= ucfirst($loan['interest_type']) ?></small>
                            </td>
                            <td>
                                <span class="amount"><?= formatCurrency($loan['payment_amount']) ?></span>
                                <br><small class="text-muted"><?= ucfirst(str_replace('_', '-', $loan['payment_frequency'])) ?></small>
                            </td>
                            <td>
                                <span class="loan-term">
                                    <?= $loan['remaining_months'] ?> / <?= $loan['loan_term_months'] ?> months
                                </span>
                                <br><small class="text-muted">
                                    <?php
                                    $months_paid = $loan['loan_term_months'] - $loan['remaining_months'];
                                    $percent_complete = ($months_paid / $loan['loan_term_months']) * 100;
                                    echo number_format($percent_complete, 0) . '% complete';
                                    ?>
                                </small>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($loan['status']) ?>">
                                    <?php
                                    $status_labels = [
                                        'active' => '✓ Active',
                                        'paid_off' => '✓ Paid Off',
                                        'defaulted' => '⚠ Defaulted',
                                        'refinanced' => '🔄 Refinanced',
                                        'closed' => '✗ Closed'
                                    ];
                                    echo $status_labels[$loan['status']] ?? ucfirst($loan['status']);
                                    ?>
                                </span>
                            </td>
                            <td class="loan-actions">
                                <a href="view.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan['uuid'] ?>" class="btn btn-small btn-primary">View</a>
                                <?php if ($loan['status'] === 'active'): ?>
                                    <a href="record-payment.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan['uuid'] ?>" class="btn btn-small btn-success">💵 Pay</a>
                                <?php endif; ?>
                                <a href="edit.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan['uuid'] ?>" class="btn btn-small btn-secondary">Edit</a>
                                <button class="btn btn-small btn-danger delete-loan-btn"
                                        data-loan-uuid="<?= $loan['uuid'] ?>"
                                        data-loan-name="<?= htmlspecialchars($loan['lender_name']) ?>"
                                        data-ledger-uuid="<?= $ledger_uuid ?>"
                                        title="Delete Loan">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteLoanModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Delete Loan</h2>
        <p>Are you sure you want to delete the loan from <strong id="deleteLoanName"></strong>?</p>
        <p class="warning-text">⚠️ This will also delete all payment records for this loan. This action cannot be undone.</p>
        <div class="modal-actions">
            <button id="confirmDeleteLoan" class="btn btn-danger">Delete Loan</button>
            <button id="cancelDeleteLoan" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<style>
.loan-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    text-align: center;
}

.summary-card-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.5rem;
}

.summary-card-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: #333;
}

.loans-table {
    width: 100%;
    margin-top: 1rem;
}

.loans-table th {
    background: #f5f5f5;
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid #ddd;
}

.loans-table td {
    padding: 1rem 0.75rem;
    border-bottom: 1px solid #eee;
}

.loan-row:hover {
    background: #f9f9f9;
}

.loan-name {
    color: #0066cc;
    text-decoration: none;
    font-weight: 600;
}

.loan-name:hover {
    text-decoration: underline;
}

.loan-type-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}

.loan-type-mortgage { background: #e3f2fd; color: #1976d2; }
.loan-type-auto { background: #fff3e0; color: #f57c00; }
.loan-type-personal { background: #f3e5f5; color: #7b1fa2; }
.loan-type-student { background: #e8f5e9; color: #388e3c; }
.loan-type-credit_line { background: #fce4ec; color: #c2185b; }
.loan-type-other { background: #f5f5f5; color: #616161; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}

.status-active { background: #e8f5e9; color: #2e7d32; }
.status-paid_off { background: #e3f2fd; color: #1565c0; }
.status-defaulted { background: #ffebee; color: #c62828; }
.status-refinanced { background: #fff3e0; color: #ef6c00; }
.status-closed { background: #f5f5f5; color: #757575; }

.progress-bar-container {
    width: 100%;
    height: 4px;
    background: #e0e0e0;
    border-radius: 2px;
    margin-top: 4px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #8bc34a);
    transition: width 0.3s ease;
}

.loan-actions {
    white-space: nowrap;
}

.loan-actions .btn {
    margin-right: 0.25rem;
    margin-bottom: 0.25rem;
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

.text-muted {
    color: #666;
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state h3 {
    color: #666;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #999;
    margin-bottom: 2rem;
}
</style>

<script>
// Delete loan functionality
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('deleteLoanModal');
    const deleteLoanName = document.getElementById('deleteLoanName');
    const confirmBtn = document.getElementById('confirmDeleteLoan');
    const cancelBtn = document.getElementById('cancelDeleteLoan');

    let currentLoanUuid = null;
    let currentLedgerUuid = null;

    // Handle delete button clicks
    document.querySelectorAll('.delete-loan-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentLoanUuid = this.dataset.loanUuid;
            currentLedgerUuid = this.dataset.ledgerUuid;
            deleteLoanName.textContent = this.dataset.loanName;
            modal.style.display = 'flex';
        });
    });

    // Handle cancel
    cancelBtn.addEventListener('click', function() {
        modal.style.display = 'none';
        currentLoanUuid = null;
    });

    // Handle confirm delete
    confirmBtn.addEventListener('click', async function() {
        if (!currentLoanUuid) return;

        try {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Deleting...';

            const response = await fetch(`../api/loans.php?loan_uuid=${currentLoanUuid}`, {
                method: 'DELETE'
            });

            const result = await response.json();

            if (result.success) {
                window.location.reload();
            } else {
                alert('Error deleting loan: ' + result.error);
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Delete Loan';
            }
        } catch (error) {
            alert('Error deleting loan: ' + error.message);
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Delete Loan';
        }
    });

    // Close modal on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            currentLoanUuid = null;
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
