<?php
/**
 * View Installment Plan Details Page
 * Display complete installment plan information with payment schedule
 * Part of Step 3.3 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$plan_uuid = $_GET['plan'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($plan_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ../index.php');
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
            l.name as ledger_name,
            t.uuid as original_transaction_uuid,
            t.description as original_transaction_description,
            t.amount as original_transaction_amount,
            t.date as original_transaction_date
        FROM data.installment_plans ip
        JOIN data.ledgers l ON ip.ledger_id = l.id
        JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
        LEFT JOIN data.transactions t ON ip.original_transaction_id = t.id
        WHERE ip.uuid = ? AND l.uuid = ?
    ");
    $stmt->execute([$plan_uuid, $ledger_uuid]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $_SESSION['error'] = 'Installment plan not found.';
        header('Location: index.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Get installment schedule
    $stmt = $db->prepare("
        SELECT
            isch.id, isch.uuid, isch.created_at, isch.updated_at,
            isch.installment_plan_id, isch.installment_number,
            isch.due_date, isch.scheduled_amount, isch.status,
            isch.processed_date, isch.actual_amount,
            isch.budget_transaction_id, isch.notes,
            bt.uuid as budget_transaction_uuid,
            bt.description as transaction_description
        FROM data.installment_schedules isch
        LEFT JOIN data.transactions bt ON isch.budget_transaction_id = bt.id
        WHERE isch.installment_plan_id = ?
        ORDER BY isch.installment_number ASC
    ");
    $stmt->execute([$plan['id']]);
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_installments = count($schedule);
    $processed_count = 0;
    $scheduled_count = 0;
    $skipped_count = 0;
    $total_processed = 0;
    $total_remaining = 0;
    $next_installment = null;
    $overdue_installments = [];
    $today = date('Y-m-d');

    foreach ($schedule as $item) {
        if ($item['status'] === 'processed') {
            $processed_count++;
            $total_processed += floatval($item['actual_amount'] ?? 0);
        } elseif ($item['status'] === 'scheduled') {
            $scheduled_count++;
            $total_remaining += floatval($item['scheduled_amount']);

            if ($next_installment === null) {
                $next_installment = $item;
            }

            // Check if overdue
            if ($item['due_date'] < $today) {
                $overdue_installments[] = $item;
            }
        } elseif ($item['status'] === 'skipped') {
            $skipped_count++;
        }
    }

    $percent_complete = $total_installments > 0 ? ($processed_count / $total_installments) * 100 : 0;

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
            <h1><?= htmlspecialchars($plan['description']) ?></h1>
            <p>
                Installment Plan for <?= htmlspecialchars($plan['credit_card_name']) ?>
            </p>
        </div>
        <div class="page-actions">
            <?php if ($plan['status'] === 'active' && $scheduled_count > 0): ?>
                <a href="edit.php?ledger=<?= $ledger_uuid ?>&plan=<?= $plan_uuid ?>" class="btn btn-secondary">✏️ Edit</a>
            <?php endif; ?>
            <a href="index.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Plans</a>
        </div>
    </div>

    <!-- Status Banner for Overdue -->
    <?php if (!empty($overdue_installments) && $plan['status'] === 'active'): ?>
        <div class="alert alert-warning">
            <strong>⚠️ <?= count($overdue_installments) ?> Overdue Installment<?= count($overdue_installments) > 1 ? 's' : '' ?></strong>
            <p>You have installments that are past their due date. Process them to keep your budget accurate.</p>
        </div>
    <?php endif; ?>

    <!-- Status Badge for Completed/Cancelled -->
    <?php if ($plan['status'] === 'completed'): ?>
        <div class="alert alert-success">
            <strong>✅ Plan Completed</strong>
            <p>All installments have been processed. This plan is now complete.</p>
        </div>
    <?php elseif ($plan['status'] === 'cancelled'): ?>
        <div class="alert alert-danger">
            <strong>❌ Plan Cancelled</strong>
            <p>This installment plan has been cancelled.</p>
        </div>
    <?php endif; ?>

    <!-- Plan Details -->
    <div class="details-section">
        <h2>Plan Details</h2>
        <div class="details-grid">
            <div class="detail-item">
                <span class="detail-label">Purchase Date</span>
                <span class="detail-value"><?= date('F j, Y', strtotime($plan['purchase_date'])) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Purchase Amount</span>
                <span class="detail-value amount-large"><?= formatCurrency($plan['purchase_amount']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Credit Card</span>
                <span class="detail-value"><?= htmlspecialchars($plan['credit_card_name']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Budget Category</span>
                <span class="detail-value">
                    <?php if ($plan['category_name']): ?>
                        <?= htmlspecialchars($plan['category_name']) ?>
                    <?php else: ?>
                        <span class="text-muted">Not assigned</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Number of Installments</span>
                <span class="detail-value"><?= $plan['number_of_installments'] ?> payments</span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Payment Frequency</span>
                <span class="detail-value"><?= ucfirst($plan['frequency']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Payment Amount</span>
                <span class="detail-value amount-large"><?= formatCurrency($plan['installment_amount']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Status</span>
                <span class="status-badge status-<?= strtolower($plan['status']) ?>">
                    <?= ucfirst($plan['status']) ?>
                </span>
            </div>
        </div>

        <?php if ($plan['notes']): ?>
            <div class="detail-notes">
                <strong>Notes:</strong>
                <p><?= nl2br(htmlspecialchars($plan['notes'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($plan['original_transaction_uuid']): ?>
            <div class="detail-notes">
                <strong>Linked to Transaction:</strong>
                <p>
                    <?= htmlspecialchars($plan['original_transaction_description']) ?> -
                    <?= formatCurrency($plan['original_transaction_amount'] / 100) ?> on
                    <?= date('M j, Y', strtotime($plan['original_transaction_date'])) ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Progress Summary -->
    <div class="progress-section">
        <h2>Progress Summary</h2>
        <div class="progress-cards">
            <div class="progress-card">
                <div class="progress-card-label">Completed</div>
                <div class="progress-card-value"><?= $processed_count ?>/<?= $total_installments ?></div>
                <div class="progress-card-hint"><?= number_format($percent_complete, 1) ?>% complete</div>
            </div>
            <div class="progress-card">
                <div class="progress-card-label">Total Processed</div>
                <div class="progress-card-value amount-positive"><?= formatCurrency($total_processed) ?></div>
                <div class="progress-card-hint">Already paid</div>
            </div>
            <div class="progress-card">
                <div class="progress-card-label">Remaining</div>
                <div class="progress-card-value amount-negative"><?= formatCurrency($total_remaining) ?></div>
                <div class="progress-card-hint"><?= $scheduled_count ?> installments left</div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar-section">
            <div class="progress-bar-label">
                <span>Overall Progress</span>
                <span><?= number_format($percent_complete, 1) ?>%</span>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar" style="width: <?= $percent_complete ?>%"></div>
            </div>
        </div>

        <?php if ($next_installment): ?>
            <div class="next-payment-info">
                <strong>Next Payment:</strong>
                <?= formatCurrency($next_installment['scheduled_amount']) ?> due on
                <?= date('F j, Y', strtotime($next_installment['due_date'])) ?>
                <?php
                $due_date = new DateTime($next_installment['due_date']);
                $today_dt = new DateTime();
                $diff = $today_dt->diff($due_date);
                $days = $diff->days;
                $is_overdue = $due_date < $today_dt;
                ?>
                <span class="<?= $is_overdue ? 'text-danger' : 'text-muted' ?>">
                    (<?= $is_overdue ? $days . ' days overdue' : 'in ' . $days . ' days' ?>)
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Installment Schedule -->
    <div class="schedule-section">
        <h2>Payment Schedule</h2>
        <div class="table-container">
            <table class="table schedule-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Due Date</th>
                        <th>Scheduled Amount</th>
                        <th>Actual Amount</th>
                        <th>Processed Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedule as $item): ?>
                        <?php
                        $is_overdue = ($item['status'] === 'scheduled' && $item['due_date'] < $today);
                        $row_class = $item['status'];
                        if ($is_overdue) $row_class .= ' overdue';
                        ?>
                        <tr class="schedule-row schedule-<?= $row_class ?>">
                            <td class="installment-number"><?= $item['installment_number'] ?></td>
                            <td>
                                <?= date('M j, Y', strtotime($item['due_date'])) ?>
                                <?php if ($is_overdue): ?>
                                    <br><small class="text-danger">Overdue</small>
                                <?php endif; ?>
                            </td>
                            <td class="amount"><?= formatCurrency($item['scheduled_amount']) ?></td>
                            <td class="amount">
                                <?php if ($item['actual_amount']): ?>
                                    <?= formatCurrency($item['actual_amount']) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['processed_date']): ?>
                                    <?= date('M j, Y', strtotime($item['processed_date'])) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $item['status'] ?>">
                                    <?= ucfirst($item['status']) ?>
                                </span>
                            </td>
                            <td class="actions">
                                <?php if ($item['status'] === 'scheduled' && $plan['status'] === 'active'): ?>
                                    <button class="btn-process"
                                            onclick="processInstallment('<?= $item['uuid'] ?>', <?= $item['installment_number'] ?>, '<?= formatCurrency($item['scheduled_amount']) ?>')"
                                            <?= $is_overdue ? 'data-overdue="true"' : '' ?>>
                                        💳 Process Now
                                    </button>
                                <?php elseif ($item['status'] === 'processed' && $item['budget_transaction_uuid']): ?>
                                    <a href="../transactions/view.php?uuid=<?= $item['budget_transaction_uuid'] ?>&ledger=<?= $ledger_uuid ?>"
                                       class="btn-link"
                                       title="View Transaction">
                                        📝 View
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Danger Zone -->
    <?php if ($plan['status'] === 'active' && $processed_count == 0): ?>
        <div class="danger-zone">
            <h3>Danger Zone</h3>
            <p>Cancel this installment plan. This action cannot be undone.</p>
            <button class="btn btn-danger" onclick="cancelPlan()">
                ❌ Cancel Installment Plan
            </button>
        </div>
    <?php endif; ?>
</div>

<style>
.container {
    max-width: 1200px;
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

.btn-secondary {
    background: #e2e8f0;
    color: #2d3748;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

.btn-success {
    background: #48bb78;
    color: white;
}

.btn-success:hover {
    background: #38a169;
}

.btn-danger {
    background: #e53e3e;
    color: white;
}

.btn-danger:hover {
    background: #c53030;
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
    margin: 0;
}

.alert-warning {
    background: #fef5e7;
    border-color: #f59e0b;
    color: #92400e;
}

.alert-success {
    background: #d1fae5;
    border-color: #10b981;
    color: #065f46;
}

.alert-danger {
    background: #fee;
    border-color: #ef4444;
    color: #991b1b;
}

.details-section,
.progress-section,
.schedule-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}

.details-section h2,
.progress-section h2,
.schedule-section h2 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 1.25rem;
    color: #1a202c;
    border-bottom: 2px solid #3182ce;
    padding-bottom: 8px;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.detail-value {
    font-size: 16px;
    color: #1a202c;
    font-weight: 500;
}

.amount-large {
    font-size: 1.5rem;
    font-weight: 700;
    color: #3182ce;
}

.detail-notes {
    margin-top: 20px;
    padding: 16px;
    background: #f7fafc;
    border-radius: 6px;
}

.detail-notes strong {
    color: #2d3748;
}

.detail-notes p {
    margin: 8px 0 0 0;
    color: #4a5568;
}

.progress-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.progress-card {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.progress-card-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.progress-card-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 4px;
}

.amount-positive {
    color: #48bb78;
}

.amount-negative {
    color: #e53e3e;
}

.progress-card-hint {
    font-size: 12px;
    color: #a0aec0;
}

.progress-bar-section {
    margin-bottom: 20px;
}

.progress-bar-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2d3748;
}

.progress-bar-container {
    width: 100%;
    height: 24px;
    background: #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    transition: width 0.3s ease;
}

.next-payment-info {
    padding: 16px;
    background: #ebf8ff;
    border: 2px solid #3182ce;
    border-radius: 8px;
    color: #2c5282;
    font-size: 14px;
}

.text-muted {
    color: #a0aec0;
}

.text-danger {
    color: #e53e3e;
    font-weight: 600;
}

.table-container {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table thead {
    background: #f7fafc;
    border-bottom: 2px solid #e2e8f0;
}

.table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #2d3748;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table td {
    padding: 16px 12px;
    border-bottom: 1px solid #e2e8f0;
}

.table tbody tr:hover {
    background: #f7fafc;
}

.schedule-row.overdue {
    background: #fff5f5;
}

.installment-number {
    font-weight: 700;
    color: #3182ce;
}

.amount {
    font-weight: 600;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: #c6f6d5;
    color: #22543d;
}

.status-completed {
    background: #bee3f8;
    color: #2c5282;
}

.status-cancelled {
    background: #fed7d7;
    color: #742a2a;
}

.status-scheduled {
    background: #fef5e7;
    color: #92400e;
}

.status-processed {
    background: #c6f6d5;
    color: #22543d;
}

.status-skipped {
    background: #e2e8f0;
    color: #4a5568;
}

.actions {
    white-space: nowrap;
}

.btn-process {
    padding: 8px 16px;
    background: #3182ce;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-process:hover {
    background: #2c5282;
}

.btn-process[data-overdue="true"] {
    background: #e53e3e;
}

.btn-process[data-overdue="true"]:hover {
    background: #c53030;
}

.btn-link {
    color: #3182ce;
    text-decoration: none;
    font-size: 13px;
}

.btn-link:hover {
    text-decoration: underline;
}

.danger-zone {
    background: #fff5f5;
    border: 2px solid #e53e3e;
    border-radius: 8px;
    padding: 24px;
    margin-top: 32px;
}

.danger-zone h3 {
    margin-top: 0;
    color: #c53030;
}

.danger-zone p {
    color: #742a2a;
    margin-bottom: 16px;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .details-grid {
        grid-template-columns: 1fr;
    }

    .progress-cards {
        grid-template-columns: 1fr;
    }

    .table {
        font-size: 12px;
    }

    .table th,
    .table td {
        padding: 8px 6px;
    }
}
</style>

<script>
async function processInstallment(scheduleUuid, installmentNumber, amount) {
    if (!confirm(`Process installment #${installmentNumber} for ${amount}?\n\nThis will create a budget transaction to spread this payment across your categories.`)) {
        return;
    }

    try {
        const response = await fetch('/pgbudget/api/process-installment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                schedule_uuid: scheduleUuid
            })
        });

        const result = await response.json();

        if (result.success) {
            alert(`✅ Installment #${installmentNumber} processed successfully!\n\nTransaction created: ${result.data.transaction_description}`);
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to process installment'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    }
}

async function cancelPlan() {
    if (!confirm('Are you sure you want to cancel this installment plan?\n\nThis action cannot be undone. The plan will be marked as cancelled and no further installments can be processed.')) {
        return;
    }

    try {
        const response = await fetch('/pgbudget/api/installment-plans.php?plan_uuid=<?= $plan_uuid ?>', {
            method: 'DELETE'
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = 'index.php?ledger=<?= $ledger_uuid ?>';
        } else {
            alert('Error: ' + (result.error || 'Failed to cancel plan'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
