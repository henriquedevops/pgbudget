<?php
/**
 * View Loan Details Page
 * Display complete loan information with payment schedule
 * Part of Step 3.4 of LOAN_MANAGEMENT_IMPLEMENTATION.md
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

    // Get payment schedule
    $stmt = $db->prepare("SELECT * FROM api.get_loan_payments(?)");
    $stmt->execute([$loan_uuid]);
    $payments = $stmt->fetchAll();

    // Calculate payment statistics
    $total_payments = count($payments);
    $paid_count = 0;
    $total_paid = 0;
    $total_interest_paid = 0;
    $total_principal_paid = 0;
    $next_payment = null;

    foreach ($payments as $payment) {
        if ($payment['status'] === 'paid') {
            $paid_count++;
            $total_paid += floatval($payment['actual_amount_paid'] ?? 0);
            $total_interest_paid += floatval($payment['actual_interest'] ?? 0);
            $total_principal_paid += floatval($payment['actual_principal'] ?? 0);
        } else {
            if ($next_payment === null) {
                $next_payment = $payment;
            }
        }
    }

    // Add initial amount paid (payments made before tracking began)
    $initial_amount_paid = floatval($loan['initial_amount_paid'] ?? 0);
    $total_paid += $initial_amount_paid;

    // Calculate actual amount paid from principal reduction
    // This is more accurate as it reflects the true balance change
    $total_amount_paid_against_principal = floatval($loan['principal_amount']) - floatval($loan['current_balance']);

    $percent_complete = $total_payments > 0 ? ($paid_count / $total_payments) * 100 : 0;
    $percent_paid = $loan['principal_amount'] > 0 ?
        (($loan['principal_amount'] - $loan['current_balance']) / $loan['principal_amount']) * 100 : 0;

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
            <h1><?= htmlspecialchars($loan['lender_name']) ?></h1>
            <p>
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
            </p>
        </div>
        <div class="page-actions">
            <?php if ($loan['status'] === 'active'): ?>
                <a href="record-payment.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-success">💵 Record Payment</a>
            <?php endif; ?>
            <a href="edit.php?ledger=<?= $ledger_uuid ?>&loan=<?= $loan_uuid ?>" class="btn btn-secondary">Edit Loan</a>
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Loans</a>
        </div>
    </div>

    <!-- Loan Overview Cards -->
    <div class="overview-cards">
        <div class="overview-card">
            <div class="card-icon">💰</div>
            <div class="card-content">
                <div class="card-label">Current Balance</div>
                <div class="card-value amount negative"><?= formatCurrency($loan['current_balance']) ?></div>
                <div class="card-detail">of <?= formatCurrency($loan['principal_amount']) ?> borrowed</div>
            </div>
        </div>

        <div class="overview-card">
            <div class="card-icon">📊</div>
            <div class="card-content">
                <div class="card-label">Payment Progress</div>
                <div class="card-value"><?= $paid_count ?> / <?= $total_payments ?></div>
                <div class="card-detail"><?= number_format($percent_complete, 1) ?>% complete</div>
            </div>
        </div>

        <div class="overview-card">
            <div class="card-icon">📅</div>
            <div class="card-content">
                <div class="card-label">Next Payment</div>
                <?php if ($next_payment): ?>
                    <div class="card-value"><?= formatCurrency($next_payment['scheduled_amount']) ?></div>
                    <div class="card-detail">Due: <?= date('M d, Y', strtotime($next_payment['due_date'])) ?></div>
                <?php else: ?>
                    <div class="card-value">—</div>
                    <div class="card-detail">No upcoming payments</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="overview-card">
            <div class="card-icon">💸</div>
            <div class="card-content">
                <div class="card-label">Total Paid</div>
                <div class="card-value amount positive"><?= formatCurrency($total_paid) ?></div>
                <div class="card-detail">
                    <?php if ($initial_amount_paid > 0): ?>
                        Tracked: <?= formatCurrency($total_paid - $initial_amount_paid) ?>
                        + Initial: <?= formatCurrency($initial_amount_paid) ?>
                    <?php else: ?>
                        Interest: <?= formatCurrency($total_interest_paid) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="loan-progress">
        <div class="progress-header">
            <span>Loan Payoff Progress</span>
            <span><?= number_format($percent_paid, 1) ?>% Paid</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: <?= min(100, $percent_paid) ?>%"></div>
        </div>
        <div class="progress-details">
            <span>Paid: <?= formatCurrency($loan['principal_amount'] - $loan['current_balance']) ?></span>
            <span>Remaining: <?= formatCurrency($loan['current_balance']) ?></span>
        </div>
    </div>

    <!-- Loan Details Section -->
    <div class="details-section">
        <h2>Loan Details</h2>
        <div class="details-grid">
            <div class="detail-item">
                <span class="detail-label">Lender:</span>
                <span class="detail-value"><?= htmlspecialchars($loan['lender_name']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Interest Rate:</span>
                <span class="detail-value"><?= number_format($loan['interest_rate'], 2) ?>% (<?= ucfirst($loan['interest_type']) ?>)</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Loan Term:</span>
                <span class="detail-value"><?= $loan['loan_term_months'] ?> months (<?= number_format($loan['loan_term_months'] / 12, 1) ?> years)</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Remaining Term:</span>
                <span class="detail-value"><?= $loan['remaining_months'] ?> months</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Payment Amount:</span>
                <span class="detail-value"><?= formatCurrency($loan['payment_amount']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Payment Frequency:</span>
                <span class="detail-value"><?= ucfirst(str_replace('_', '-', $loan['payment_frequency'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Start Date:</span>
                <span class="detail-value"><?= date('M d, Y', strtotime($loan['start_date'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">First Payment:</span>
                <span class="detail-value"><?= date('M d, Y', strtotime($loan['first_payment_date'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Status:</span>
                <span class="detail-value">
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
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Amortization:</span>
                <span class="detail-value"><?= ucfirst(str_replace('_', ' ', $loan['amortization_type'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Compounding:</span>
                <span class="detail-value"><?= ucfirst($loan['compounding_frequency']) ?></span>
            </div>
            <?php if ($loan['account_name']): ?>
            <div class="detail-item">
                <span class="detail-label">Linked Account:</span>
                <span class="detail-value"><?= htmlspecialchars($loan['account_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($loan['payment_day_of_month']): ?>
            <div class="detail-item">
                <span class="detail-label">Payment Day:</span>
                <span class="detail-value">Day <?= $loan['payment_day_of_month'] ?> of month</span>
            </div>
            <?php endif; ?>
            <?php if ($loan['initial_amount_paid'] > 0): ?>
            <div class="detail-item" style="grid-column: 1 / -1;">
                <div class="initial-payment-notice">
                    <strong>📊 Initial Payments Recorded:</strong>
                    <?= formatCurrency($loan['initial_amount_paid']) ?> paid as of <?= date('M d, Y', strtotime($loan['initial_paid_as_of_date'])) ?>
                    <br><small>The current balance and payment schedule reflect payments made before tracking began.</small>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($loan['notes']): ?>
        <div class="notes-section">
            <h3>Notes</h3>
            <p><?= nl2br(htmlspecialchars($loan['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment Schedule -->
    <div class="payment-schedule-section">
        <h2>Payment Schedule</h2>

        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <p>No payment schedule generated yet.</p>
                <p>Payment schedules are automatically created when you add a loan.</p>
            </div>
        <?php else: ?>
            <div class="schedule-stats">
                <div class="stat-item">
                    <span class="stat-label">Total Payments:</span>
                    <span class="stat-value"><?= $total_payments ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Paid:</span>
                    <span class="stat-value text-success"><?= $paid_count ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Remaining:</span>
                    <span class="stat-value text-warning"><?= $total_payments - $paid_count ?></span>
                </div>
            </div>

            <div class="table-container">
                <table class="payment-schedule-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Due Date</th>
                            <th>Scheduled</th>
                            <th>Principal</th>
                            <th>Interest</th>
                            <th>Status</th>
                            <th>Paid Date</th>
                            <th>Actual Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr class="payment-row status-<?= strtolower($payment['status']) ?>">
                                <td><?= $payment['payment_number'] ?></td>
                                <td><?= date('M d, Y', strtotime($payment['due_date'])) ?></td>
                                <td class="amount"><?= formatCurrency($payment['scheduled_amount']) ?></td>
                                <td class="amount text-muted"><?= formatCurrency($payment['scheduled_principal']) ?></td>
                                <td class="amount text-muted"><?= formatCurrency($payment['scheduled_interest']) ?></td>
                                <td>
                                    <span class="payment-status-badge status-<?= strtolower($payment['status']) ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($payment['paid_date']): ?>
                                        <?= date('M d, Y', strtotime($payment['paid_date'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($payment['actual_amount_paid']): ?>
                                        <span class="amount text-success"><?= formatCurrency($payment['actual_amount_paid']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.overview-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.overview-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.card-icon {
    font-size: 2.5rem;
    opacity: 0.8;
}

.card-content {
    flex: 1;
}

.card-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
}

.card-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: #333;
    margin-bottom: 0.25rem;
}

.card-detail {
    font-size: 0.875rem;
    color: #999;
}

.loan-progress {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 2rem;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #333;
}

.progress-bar-container {
    width: 100%;
    height: 20px;
    background: #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #8bc34a);
    transition: width 0.3s ease;
}

.progress-details {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    color: #666;
}

.details-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 2rem;
}

.details-section h2 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem;
    background: #f9f9f9;
    border-radius: 4px;
    border-left: 3px solid #3182ce;
}

.detail-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.detail-value {
    font-size: 1rem;
    color: #333;
    font-weight: 600;
}

.initial-payment-notice {
    background: #e6f7ff;
    border: 1px solid #91d5ff;
    border-radius: 6px;
    padding: 1rem;
    color: #0050b3;
}

.initial-payment-notice strong {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 1.05rem;
}

.initial-payment-notice small {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #0066cc;
}

.notes-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e0e0e0;
}

.notes-section h3 {
    margin-top: 0;
    margin-bottom: 0.75rem;
    color: #333;
}

.notes-section p {
    color: #666;
    line-height: 1.6;
}

.payment-schedule-section {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.payment-schedule-section h2 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
}

.schedule-stats {
    display: flex;
    gap: 2rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 4px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stat-label {
    font-size: 0.9rem;
    color: #666;
}

.stat-value {
    font-size: 1.1rem;
    font-weight: bold;
    color: #333;
}

.table-container {
    overflow-x: auto;
}

.payment-schedule-table {
    width: 100%;
    border-collapse: collapse;
}

.payment-schedule-table th {
    background: #f5f5f5;
    padding: 0.75rem;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #ddd;
    white-space: nowrap;
}

.payment-schedule-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
}

.payment-row.status-paid {
    background: #f1f8f4;
}

.payment-row.status-scheduled {
    background: white;
}

.payment-status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}

.payment-status-badge.status-scheduled {
    background: #fff3cd;
    color: #856404;
}

.payment-status-badge.status-paid {
    background: #d4edda;
    color: #155724;
}

.payment-status-badge.status-late {
    background: #f8d7da;
    color: #721c24;
}

.payment-status-badge.status-partial {
    background: #d1ecf1;
    color: #0c5460;
}

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

.text-success { color: #28a745; }
.text-warning { color: #ffc107; }
.text-muted { color: #6c757d; }

.amount.positive { color: #28a745; }
.amount.negative { color: #dc3545; }

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #999;
}

@media (max-width: 768px) {
    .overview-cards {
        grid-template-columns: 1fr;
    }

    .details-grid {
        grid-template-columns: 1fr;
    }

    .schedule-stats {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
