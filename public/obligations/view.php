<?php
/**
 * View Obligation Details & Payment History Page
 * Displays comprehensive obligation information and payment timeline
 * Part of Phase 4 of OBLIGATIONS_BILLS_IMPLEMENTATION_PLAN.md
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

    // Get payment history
    $stmt = $db->prepare("SELECT * FROM api.get_obligation_payment_history(?) ORDER BY due_date DESC");
    $stmt->execute([$obligation_uuid]);
    $payments = $stmt->fetchAll();

    // Calculate statistics
    $total_payments = count($payments);
    $paid_count = 0;
    $missed_count = 0;
    $late_count = 0;
    $total_paid_amount = 0;
    $total_scheduled_amount = 0;
    $total_late_fees = 0;
    $upcoming_count = 0;

    foreach ($payments as $payment) {
        $total_scheduled_amount += floatval($payment['scheduled_amount']);

        if ($payment['status'] === 'paid' || $payment['status'] === 'late') {
            $paid_count++;
            $total_paid_amount += floatval($payment['actual_amount_paid'] ?? 0);
        }

        if ($payment['status'] === 'late') {
            $late_count++;
            $total_late_fees += floatval($payment['late_fee_charged'] ?? 0);
        }

        if ($payment['status'] === 'missed') {
            $missed_count++;
        }

        if ($payment['status'] === 'scheduled' && strtotime($payment['due_date']) >= strtotime('today')) {
            $upcoming_count++;
        }
    }

    $on_time_count = $paid_count - $late_count;
    $on_time_percentage = $paid_count > 0 ? ($on_time_count / $paid_count) * 100 : 0;

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
            <h1><?= htmlspecialchars($obligation['name']) ?></h1>
            <p>Payment history and details</p>
        </div>
        <div class="page-actions">
            <button id="exportCsvBtn" class="btn btn-success">📥 Export CSV</button>
            <a href="/pgbudget/reports/cash-flow-projection.php?ledger=<?= $ledger_uuid ?>&highlight=<?= $obligation_uuid ?>" class="btn btn-secondary">📊 View in Projection</a>
            <a href="edit.php?ledger=<?= $ledger_uuid ?>&obligation=<?= $obligation_uuid ?>" class="btn btn-primary">Edit Obligation</a>
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Obligations</a>
        </div>
    </div>

    <!-- Obligation Overview -->
    <div class="obligation-overview">
        <div class="overview-section">
            <h3>Obligation Details</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Payee:</span>
                    <span class="detail-value"><?= htmlspecialchars($obligation['payee_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Type:</span>
                    <span class="detail-value">
                        <span class="obligation-type-badge type-<?= $obligation['obligation_type'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $obligation['obligation_type'])) ?>
                        </span>
                        <?php if ($obligation['obligation_subtype']): ?>
                            - <?= htmlspecialchars($obligation['obligation_subtype']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Frequency:</span>
                    <span class="detail-value"><?= ucfirst($obligation['frequency']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value">
                        <?php if ($obligation['is_fixed_amount']): ?>
                            <?= formatLoanAmount($obligation['fixed_amount']) ?> (Fixed)
                        <?php else: ?>
                            ~<?= formatLoanAmount($obligation['estimated_amount']) ?> (Variable)
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Start Date:</span>
                    <span class="detail-value"><?= date('M j, Y', strtotime($obligation['start_date'])) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Next Due:</span>
                    <span class="detail-value">
                        <?php if ($obligation['next_due_date']): ?>
                            <?= date('M j, Y', strtotime($obligation['next_due_date'])) ?>
                            (<?= formatLoanAmount($obligation['next_payment_amount']) ?>)
                        <?php else: ?>
                            <span class="text-muted">No upcoming payment</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <?php if ($obligation['is_paused']): ?>
                            <span class="status-badge status-paused">⏸ Paused</span>
                        <?php elseif ($obligation['is_active']): ?>
                            <span class="status-badge status-active">✓ Active</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">✗ Inactive</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Reminder:</span>
                    <span class="detail-value"><?= $obligation['reminder_days_before'] ?> days before</span>
                </div>
            </div>

            <?php if ($obligation['description']): ?>
                <div class="detail-description">
                    <span class="detail-label">Description:</span>
                    <p><?= nl2br(htmlspecialchars($obligation['description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($obligation['notes']): ?>
                <div class="detail-description">
                    <span class="detail-label">Notes:</span>
                    <p><?= nl2br(htmlspecialchars($obligation['notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Statistics -->
    <div class="payment-statistics">
        <h3>Payment Statistics</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value"><?= $total_payments ?></div>
                <div class="stat-sublabel">All time</div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-label">Paid</div>
                <div class="stat-value"><?= $paid_count ?></div>
                <div class="stat-sublabel"><?= formatLoanAmount($total_paid_amount) ?> total</div>
            </div>
            <div class="stat-card stat-info">
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?= $upcoming_count ?></div>
                <div class="stat-sublabel">Scheduled</div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-label">Late Payments</div>
                <div class="stat-value"><?= $late_count ?></div>
                <?php if ($total_late_fees > 0): ?>
                    <div class="stat-sublabel"><?= formatLoanAmount($total_late_fees) ?> in fees</div>
                <?php else: ?>
                    <div class="stat-sublabel">No fees</div>
                <?php endif; ?>
            </div>
            <div class="stat-card stat-danger">
                <div class="stat-label">Missed</div>
                <div class="stat-value"><?= $missed_count ?></div>
                <div class="stat-sublabel">Payments</div>
            </div>
            <div class="stat-card stat-primary">
                <div class="stat-label">On-Time Rate</div>
                <div class="stat-value"><?= number_format($on_time_percentage, 1) ?>%</div>
                <div class="stat-sublabel"><?= $on_time_count ?> of <?= $paid_count ?> paid</div>
            </div>
        </div>
    </div>

    <!-- Payment History Timeline -->
    <div class="payment-history">
        <h3>Payment History (<?= count($payments) ?> payments)</h3>

        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <p>No payment history yet. Payment schedules will be generated automatically.</p>
            </div>
        <?php else: ?>
            <div class="payment-timeline">
                <?php foreach ($payments as $payment): ?>
                    <div class="payment-item payment-status-<?= $payment['status'] ?>">
                        <div class="payment-date">
                            <div class="payment-month"><?= date('M', strtotime($payment['due_date'])) ?></div>
                            <div class="payment-day"><?= date('d', strtotime($payment['due_date'])) ?></div>
                            <div class="payment-year"><?= date('Y', strtotime($payment['due_date'])) ?></div>
                        </div>

                        <div class="payment-details">
                            <div class="payment-status-header">
                                <span class="payment-status-badge status-<?= $payment['status'] ?>">
                                    <?php
                                    $status_labels = [
                                        'scheduled' => '📅 Scheduled',
                                        'paid' => '✓ Paid',
                                        'partial' => '⚠ Partial',
                                        'late' => '⏰ Paid Late',
                                        'missed' => '✗ Missed',
                                        'skipped' => '⊘ Skipped'
                                    ];
                                    echo $status_labels[$payment['status']] ?? ucfirst($payment['status']);
                                    ?>
                                </span>

                                <?php if ($payment['status'] === 'scheduled' || $payment['status'] === 'partial'): ?>
                                    <?php
                                    $due_timestamp = strtotime($payment['due_date']);
                                    $today = strtotime('today');
                                    $days_diff = floor(($due_timestamp - $today) / 86400);
                                    ?>
                                    <?php if ($days_diff < 0): ?>
                                        <span class="overdue-badge">
                                            <?= abs($days_diff) ?> days overdue
                                        </span>
                                    <?php elseif ($days_diff === 0): ?>
                                        <span class="today-badge">Due today</span>
                                    <?php elseif ($days_diff === 1): ?>
                                        <span class="soon-badge">Due tomorrow</span>
                                    <?php elseif ($days_diff <= 7): ?>
                                        <span class="soon-badge">Due in <?= $days_diff ?> days</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="payment-amount-info">
                                <div class="scheduled-amount">
                                    Scheduled: <strong><?= formatLoanAmount($payment['scheduled_amount']) ?></strong>
                                </div>

                                <?php if ($payment['status'] === 'paid' || $payment['status'] === 'late' || $payment['status'] === 'partial'): ?>
                                    <div class="actual-amount">
                                        Paid: <strong><?= formatLoanAmount($payment['actual_amount_paid']) ?></strong>
                                        <?php if ($payment['paid_date']): ?>
                                            on <?= date('M j, Y', strtotime($payment['paid_date'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($payment['status'] === 'late' && $payment['days_late']): ?>
                                    <div class="late-info">
                                        <?= $payment['days_late'] ?> days late
                                        <?php if ($payment['late_fee_charged'] > 0): ?>
                                            - Late fee: <?= formatLoanAmount($payment['late_fee_charged']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($payment['payment_method']): ?>
                                <div class="payment-method">
                                    Payment Method: <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($payment['confirmation_number']): ?>
                                <div class="confirmation-number">
                                    Confirmation #: <?= htmlspecialchars($payment['confirmation_number']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($payment['notes']): ?>
                                <div class="payment-notes">
                                    <?= htmlspecialchars($payment['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($payment['transaction_uuid']): ?>
                                <div class="payment-transaction">
                                    <a href="../transactions/view.php?ledger=<?= $ledger_uuid ?>&transaction=<?= $payment['transaction_uuid'] ?>">
                                        View linked transaction →
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="payment-actions">
                            <?php if ($payment['status'] === 'scheduled' || $payment['status'] === 'partial'): ?>
                                <button class="btn btn-small btn-success mark-paid-btn"
                                        data-payment-uuid="<?= $payment['uuid'] ?>"
                                        data-payment-name="<?= htmlspecialchars($obligation['name']) ?>"
                                        data-payment-amount="<?= $payment['scheduled_amount'] ?>"
                                        data-due-date="<?= $payment['due_date'] ?>">
                                    Mark as Paid
                                </button>
                            <?php endif; ?>

                            <?php if ($payment['status'] !== 'scheduled'): ?>
                                <button class="btn btn-small btn-secondary edit-payment-btn"
                                        data-payment-uuid="<?= $payment['uuid'] ?>"
                                        data-payment-data='<?= htmlspecialchars(json_encode($payment)) ?>'>
                                    Edit Payment
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mark as Paid Modal (reused from index.php) -->
<div id="markPaidModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Mark Payment as Paid</h2>
        <div id="markPaidForm">
            <p><strong>Obligation:</strong> <span id="modalPaymentName"></span></p>
            <p><strong>Due Date:</strong> <span id="modalDueDate"></span></p>

            <div class="form-group">
                <label for="modalActualAmount">Actual Amount Paid:</label>
                <input type="number"
                       id="modalActualAmount"
                       step="0.01"
                       min="0"
                       placeholder="0.00"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="modalPaidDate">Payment Date:</label>
                <input type="date"
                       id="modalPaidDate"
                       value="<?= date('Y-m-d') ?>"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="modalPaymentMethod">Payment Method (Optional):</label>
                <select id="modalPaymentMethod" class="form-control">
                    <option value="">Select payment method...</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="credit_card">Credit Card</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                    <option value="autopay">Auto-Pay</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="modalConfirmationNumber">Confirmation Number (Optional):</label>
                <input type="text"
                       id="modalConfirmationNumber"
                       placeholder="e.g., TXN123456789"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="modalNotes">Notes (optional):</label>
                <textarea id="modalNotes"
                          rows="2"
                          placeholder="Add any notes about this payment..."
                          class="form-control"></textarea>
            </div>
        </div>

        <div class="modal-actions">
            <button id="confirmMarkPaid" class="btn btn-success">Mark as Paid</button>
            <button id="cancelMarkPaid" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div id="editPaymentModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Edit Payment Details</h2>
        <div id="editPaymentForm">
            <input type="hidden" id="editPaymentUuid">

            <div class="form-group">
                <label for="editActualAmount">Actual Amount Paid:</label>
                <input type="number"
                       id="editActualAmount"
                       step="0.01"
                       min="0"
                       placeholder="0.00"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="editPaidDate">Payment Date:</label>
                <input type="date"
                       id="editPaidDate"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="editPaymentMethod">Payment Method:</label>
                <select id="editPaymentMethod" class="form-control">
                    <option value="">Select payment method...</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="credit_card">Credit Card</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                    <option value="autopay">Auto-Pay</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="editConfirmationNumber">Confirmation Number:</label>
                <input type="text"
                       id="editConfirmationNumber"
                       placeholder="e.g., TXN123456789"
                       class="form-control">
            </div>

            <div class="form-group">
                <label for="editNotes">Notes:</label>
                <textarea id="editNotes"
                          rows="2"
                          placeholder="Add any notes about this payment..."
                          class="form-control"></textarea>
            </div>

            <div class="form-group">
                <label for="editStatus">Payment Status:</label>
                <select id="editStatus" class="form-control">
                    <option value="paid">Paid</option>
                    <option value="late">Paid Late</option>
                    <option value="partial">Partial Payment</option>
                    <option value="missed">Missed</option>
                    <option value="skipped">Skipped</option>
                </select>
            </div>
        </div>

        <div class="modal-actions">
            <button id="confirmEditPayment" class="btn btn-primary">Update Payment</button>
            <button id="cancelEditPayment" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<style>
.obligation-overview {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    padding: 2rem;
    margin-bottom: 2rem;
}

.overview-section h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    gap: 0.5rem;
}

.detail-label {
    font-weight: 600;
    color: #666;
    min-width: 120px;
}

.detail-value {
    color: #333;
}

.detail-description {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e0e0e0;
}

.detail-description .detail-label {
    display: block;
    margin-bottom: 0.5rem;
}

.detail-description p {
    color: #666;
    line-height: 1.6;
    margin: 0;
}

.obligation-type-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.type-utility { background: #e3f2fd; color: #1976d2; }
.type-housing { background: #f3e5f5; color: #7b1fa2; }
.type-subscription { background: #e8f5e9; color: #388e3c; }
.type-education { background: #fff3e0; color: #f57c00; }
.type-debt { background: #ffebee; color: #c62828; }
.type-insurance { background: #e0f2f1; color: #00796b; }
.type-tax { background: #fce4ec; color: #c2185b; }
.type-other { background: #f5f5f5; color: #616161; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-active { background: #e8f5e9; color: #2e7d32; }
.status-paused { background: #fff3e0; color: #f57c00; }
.status-inactive { background: #f5f5f5; color: #757575; }

.payment-statistics {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    padding: 2rem;
    margin-bottom: 2rem;
}

.payment-statistics h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: #f9f9f9;
    padding: 1.5rem;
    border-radius: 6px;
    text-align: center;
    border: 2px solid transparent;
}

.stat-card.stat-success { border-color: #4caf50; }
.stat-card.stat-info { border-color: #2196f3; }
.stat-card.stat-warning { border-color: #ff9800; }
.stat-card.stat-danger { border-color: #f44336; }
.stat-card.stat-primary { border-color: #9c27b0; }

.stat-label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #333;
}

.stat-sublabel {
    font-size: 0.75rem;
    color: #999;
    margin-top: 0.25rem;
}

.payment-history {
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    padding: 2rem;
}

.payment-history h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #333;
}

.payment-timeline {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.payment-item {
    display: grid;
    grid-template-columns: 80px 1fr auto;
    gap: 1.5rem;
    padding: 1.5rem;
    border: 1px solid #e0e0e0;
    border-left: 4px solid #ccc;
    border-radius: 6px;
    background: #fafafa;
    transition: all 0.2s ease;
}

.payment-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

.payment-item.payment-status-paid { border-left-color: #4caf50; }
.payment-item.payment-status-late { border-left-color: #ff9800; }
.payment-item.payment-status-missed { border-left-color: #f44336; }
.payment-item.payment-status-scheduled { border-left-color: #2196f3; }
.payment-item.payment-status-partial { border-left-color: #ff9800; }
.payment-item.payment-status-skipped { border-left-color: #9e9e9e; }

.payment-date {
    text-align: center;
    background: white;
    border-radius: 6px;
    padding: 0.5rem;
    border: 1px solid #e0e0e0;
}

.payment-month {
    font-size: 0.75rem;
    color: #666;
    text-transform: uppercase;
}

.payment-day {
    font-size: 1.75rem;
    font-weight: bold;
    color: #333;
    line-height: 1;
    margin: 0.25rem 0;
}

.payment-year {
    font-size: 0.75rem;
    color: #999;
}

.payment-details {
    flex: 1;
}

.payment-status-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.payment-status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}

.payment-status-badge.status-scheduled { background: #e3f2fd; color: #1976d2; }
.payment-status-badge.status-paid { background: #e8f5e9; color: #2e7d32; }
.payment-status-badge.status-partial { background: #fff3e0; color: #f57c00; }
.payment-status-badge.status-late { background: #fff3e0; color: #f57c00; }
.payment-status-badge.status-missed { background: #ffebee; color: #c62828; }
.payment-status-badge.status-skipped { background: #f5f5f5; color: #757575; }

.overdue-badge {
    background: #ffebee;
    color: #c62828;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.today-badge, .soon-badge {
    background: #fff3e0;
    color: #f57c00;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.payment-amount-info {
    margin-bottom: 0.5rem;
}

.scheduled-amount {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
}

.actual-amount {
    font-size: 0.875rem;
    color: #2e7d32;
    margin-bottom: 0.25rem;
}

.late-info {
    font-size: 0.875rem;
    color: #f57c00;
    margin-top: 0.5rem;
}

.payment-method, .confirmation-number {
    font-size: 0.875rem;
    color: #666;
    margin-top: 0.5rem;
}

.payment-notes {
    font-size: 0.875rem;
    color: #666;
    font-style: italic;
    margin-top: 0.5rem;
    padding: 0.5rem;
    background: white;
    border-radius: 4px;
}

.payment-transaction {
    margin-top: 0.5rem;
}

.payment-transaction a {
    color: #0066cc;
    text-decoration: none;
    font-size: 0.875rem;
}

.payment-transaction a:hover {
    text-decoration: underline;
}

.payment-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    align-items: flex-end;
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
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content h2 {
    margin-top: 0;
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.25rem;
    color: #333;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.875rem;
}

.modal-actions {
    margin-top: 1.5rem;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: #999;
}

.text-muted {
    color: #999;
}

@media (max-width: 768px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .payment-item {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .payment-actions {
        flex-direction: row;
        justify-content: flex-start;
    }

    .payment-date {
        width: 80px;
        margin: 0 auto;
    }
}
</style>

<script src="../js/obligation-payment-handlers.js"></script>

<script>
// CSV Export functionality
document.getElementById('exportCsvBtn').addEventListener('click', function() {
    const obligationName = '<?= addslashes($obligation['name']) ?>';
    const payments = <?= json_encode($payments) ?>;

    // Create CSV content
    let csv = 'Due Date,Status,Scheduled Amount,Actual Amount,Paid Date,Days Late,Late Fee,Payment Method,Confirmation Number,Notes\n';

    payments.forEach(payment => {
        const row = [
            payment.due_date || '',
            payment.status || '',
            payment.scheduled_amount || '',
            payment.actual_amount_paid || '',
            payment.paid_date || '',
            payment.days_late || '',
            payment.late_fee_charged || '',
            payment.payment_method || '',
            payment.confirmation_number || '',
            (payment.notes || '').replace(/"/g, '""') // Escape quotes
        ];
        csv += row.map(field => `"${field}"`).join(',') + '\n';
    });

    // Create download link
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    link.setAttribute('href', url);
    link.setAttribute('download', `${obligationName.replace(/[^a-z0-9]/gi, '_')}_payment_history.csv`);
    link.style.visibility = 'hidden';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
});
</script>

<?php require_once '../../includes/footer.php'; ?>
