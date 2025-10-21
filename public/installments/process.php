<?php
/**
 * Process Installment Page
 * Display installment processing interface with preview and confirmation
 * Part of Step 3.4 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
 */

require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$schedule_uuid = $_GET['schedule'] ?? '';
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($schedule_uuid) || empty($ledger_uuid)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: index.php?ledger=' . urlencode($ledger_uuid));
    exit;
}

try {
    $db = getDbConnection();

    // Set user context
    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    // Get installment schedule details with plan information
    $stmt = $db->prepare("
        SELECT
            isch.id, isch.uuid, isch.created_at, isch.updated_at,
            isch.installment_plan_id, isch.installment_number,
            isch.due_date, isch.scheduled_amount, isch.status,
            isch.processed_date, isch.actual_amount,
            isch.budget_transaction_id, isch.notes,
            ip.uuid as plan_uuid,
            ip.description as plan_description,
            ip.purchase_amount, ip.purchase_date,
            ip.number_of_installments, ip.installment_amount,
            ip.frequency, ip.start_date, ip.status as plan_status,
            ip.completed_installments,
            cc.id as credit_card_id,
            cc.name as credit_card_name,
            cc.uuid as credit_card_uuid,
            cat.id as category_id,
            cat.name as category_name,
            cat.uuid as category_uuid,
            l.id as ledger_id,
            l.uuid as ledger_uuid,
            l.name as ledger_name
        FROM data.installment_schedules isch
        JOIN data.installment_plans ip ON isch.installment_plan_id = ip.id
        JOIN data.ledgers l ON ip.ledger_id = l.id
        JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
        WHERE isch.uuid = ? AND l.uuid = ?
    ");
    $stmt->execute([$schedule_uuid, $ledger_uuid]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        $_SESSION['error'] = 'Installment not found.';
        header('Location: index.php?ledger=' . urlencode($ledger_uuid));
        exit;
    }

    // Check if already processed
    if ($schedule['status'] === 'processed') {
        $_SESSION['error'] = 'This installment has already been processed.';
        header('Location: view.php?ledger=' . urlencode($ledger_uuid) . '&plan=' . urlencode($schedule['plan_uuid']));
        exit;
    }

    // Check if plan is active
    if ($schedule['plan_status'] !== 'active') {
        $_SESSION['error'] = 'Cannot process installment for a ' . $schedule['plan_status'] . ' plan.';
        header('Location: view.php?ledger=' . urlencode($ledger_uuid) . '&plan=' . urlencode($schedule['plan_uuid']));
        exit;
    }

    // Calculate if overdue
    $today = date('Y-m-d');
    $is_overdue = $schedule['due_date'] < $today;
    $is_early = $schedule['due_date'] > $today;

    // Get all scheduled installments for this plan (for batch processing)
    $stmt = $db->prepare("
        SELECT
            isch.id, isch.uuid, isch.installment_number,
            isch.due_date, isch.scheduled_amount, isch.status
        FROM data.installment_schedules isch
        WHERE isch.installment_plan_id = ?
        AND isch.status = 'scheduled'
        ORDER BY isch.installment_number ASC
    ");
    $stmt->execute([$schedule['installment_plan_id']]);
    $all_scheduled = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Find overdue installments
    $overdue_installments = array_filter($all_scheduled, function($item) use ($today) {
        return $item['due_date'] < $today;
    });

    // Calculate progress
    $progress_percent = $schedule['number_of_installments'] > 0
        ? ($schedule['completed_installments'] / $schedule['number_of_installments']) * 100
        : 0;

    $remaining_installments = $schedule['number_of_installments'] - $schedule['completed_installments'];

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
            <h1>💳 Process Installment Payment</h1>
            <p>Review and process installment #<?= $schedule['installment_number'] ?> of <?= $schedule['number_of_installments'] ?></p>
        </div>
        <div class="page-actions">
            <a href="view.php?ledger=<?= $ledger_uuid ?>&plan=<?= $schedule['plan_uuid'] ?>" class="btn btn-secondary">← Back to Plan</a>
        </div>
    </div>

    <!-- Status Alert -->
    <?php if ($is_overdue): ?>
        <div class="alert alert-warning">
            <strong>⚠️ Overdue Payment</strong>
            <p>This installment was due on <?= date('F j, Y', strtotime($schedule['due_date'])) ?>. Processing it now will help keep your budget accurate.</p>
        </div>
    <?php elseif ($is_early): ?>
        <div class="alert alert-info">
            <strong>ℹ️ Early Payment</strong>
            <p>This installment is not due until <?= date('F j, Y', strtotime($schedule['due_date'])) ?>. You can process it early if you prefer.</p>
        </div>
    <?php endif; ?>

    <!-- Batch Processing Alert -->
    <?php if (count($overdue_installments) > 1): ?>
        <div class="alert alert-info">
            <strong>📋 Multiple Overdue Installments</strong>
            <p>You have <?= count($overdue_installments) ?> overdue installments for this plan. You can process them individually or all at once.</p>
            <button class="btn btn-primary" onclick="processBatch()">
                Process All <?= count($overdue_installments) ?> Overdue Installments
            </button>
        </div>
    <?php endif; ?>

    <div class="content-grid">
        <!-- Left Column: Plan Details -->
        <div class="details-section">
            <h2>Installment Plan Details</h2>

            <div class="detail-item">
                <span class="detail-label">Description</span>
                <span class="detail-value"><?= htmlspecialchars($schedule['plan_description']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Credit Card</span>
                <span class="detail-value"><?= htmlspecialchars($schedule['credit_card_name']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Budget Category</span>
                <span class="detail-value">
                    <?php if ($schedule['category_name']): ?>
                        <?= htmlspecialchars($schedule['category_name']) ?>
                    <?php else: ?>
                        <span class="text-muted">Not assigned</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Purchase Amount</span>
                <span class="detail-value amount-large"><?= formatCurrency($schedule['purchase_amount']) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Purchase Date</span>
                <span class="detail-value"><?= date('F j, Y', strtotime($schedule['purchase_date'])) ?></span>
            </div>

            <div class="detail-item">
                <span class="detail-label">Payment Frequency</span>
                <span class="detail-value"><?= ucfirst($schedule['frequency']) ?></span>
            </div>

            <!-- Progress Bar -->
            <div class="progress-section">
                <h3>Plan Progress</h3>
                <div class="progress-bar-label">
                    <span><?= $schedule['completed_installments'] ?>/<?= $schedule['number_of_installments'] ?> completed</span>
                    <span><?= number_format($progress_percent, 1) ?>%</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: <?= $progress_percent ?>%"></div>
                </div>
                <p class="text-muted" style="margin-top: 8px;">
                    <?= $remaining_installments ?> installment<?= $remaining_installments != 1 ? 's' : '' ?> remaining
                </p>
            </div>
        </div>

        <!-- Right Column: Processing Preview -->
        <div class="processing-section">
            <h2>Payment to Process</h2>

            <div class="installment-card <?= $is_overdue ? 'overdue' : ($is_early ? 'early' : 'on-time') ?>">
                <div class="installment-header">
                    <div class="installment-number">Installment #<?= $schedule['installment_number'] ?></div>
                    <div class="installment-status">
                        <?php if ($is_overdue): ?>
                            <span class="badge badge-danger">Overdue</span>
                        <?php elseif ($is_early): ?>
                            <span class="badge badge-info">Early Payment</span>
                        <?php else: ?>
                            <span class="badge badge-success">Due Now</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="installment-details">
                    <div class="installment-detail-row">
                        <span class="label">Due Date:</span>
                        <span class="value"><?= date('F j, Y', strtotime($schedule['due_date'])) ?></span>
                    </div>
                    <div class="installment-detail-row">
                        <span class="label">Payment Amount:</span>
                        <span class="value amount-highlight"><?= formatCurrency($schedule['scheduled_amount']) ?></span>
                    </div>
                </div>
            </div>

            <h3>What Will Happen?</h3>
            <div class="preview-box">
                <p><strong>A budget transaction will be created:</strong></p>
                <div class="transaction-preview">
                    <div class="transaction-entry debit">
                        <span class="entry-label">From Category:</span>
                        <span class="entry-account">
                            <?= htmlspecialchars($schedule['category_name'] ?? 'Unassigned') ?>
                        </span>
                        <span class="entry-amount">-<?= formatCurrency($schedule['scheduled_amount']) ?></span>
                    </div>
                    <div class="transaction-arrow">→</div>
                    <div class="transaction-entry credit">
                        <span class="entry-label">To CC Payment:</span>
                        <span class="entry-account">
                            <?= htmlspecialchars($schedule['credit_card_name']) ?> Payment
                        </span>
                        <span class="entry-amount">+<?= formatCurrency($schedule['scheduled_amount']) ?></span>
                    </div>
                </div>

                <div class="budget-impact">
                    <p><strong>Budget Impact:</strong></p>
                    <ul>
                        <li>
                            <strong><?= htmlspecialchars($schedule['category_name'] ?? 'Category') ?></strong>
                            will be credited <?= formatCurrency($schedule['scheduled_amount']) ?>
                        </li>
                        <li>
                            <strong><?= htmlspecialchars($schedule['credit_card_name']) ?> Payment Category</strong>
                            will be debited <?= formatCurrency($schedule['scheduled_amount']) ?>
                        </li>
                        <li>This spreads the budget impact of the original purchase across multiple months</li>
                    </ul>
                </div>
            </div>

            <div class="action-section">
                <button class="btn btn-primary btn-large" onclick="processInstallment()">
                    ✓ Process Installment #<?= $schedule['installment_number'] ?>
                </button>
                <button class="btn btn-secondary" onclick="window.history.back()">
                    Cancel
                </button>
            </div>

            <?php if ($schedule['notes']): ?>
                <div class="notes-section">
                    <strong>Notes:</strong>
                    <p><?= nl2br(htmlspecialchars($schedule['notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
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
    font-size: 1rem;
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

.btn-large {
    padding: 16px 32px;
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
    margin: 8px 0;
}

.alert-warning {
    background: #fef5e7;
    border-color: #f59e0b;
    color: #92400e;
}

.alert-info {
    background: #ebf8ff;
    border-color: #3182ce;
    color: #2c5282;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 24px;
    margin-top: 24px;
}

.details-section,
.processing-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
}

.details-section h2,
.processing-section h2 {
    margin-top: 0;
    margin-bottom: 20px;
    font-size: 1.25rem;
    color: #1a202c;
    border-bottom: 2px solid #3182ce;
    padding-bottom: 8px;
}

.processing-section h3 {
    margin-top: 24px;
    margin-bottom: 12px;
    font-size: 1rem;
    color: #2d3748;
}

.detail-item {
    display: flex;
    flex-direction: column;
    margin-bottom: 16px;
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

.text-muted {
    color: #a0aec0;
}

.progress-section {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e2e8f0;
}

.progress-section h3 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 1rem;
    color: #2d3748;
}

.progress-bar-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2d3748;
    font-size: 14px;
}

.progress-bar-container {
    width: 100%;
    height: 20px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    transition: width 0.3s ease;
}

.installment-card {
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}

.installment-card.overdue {
    border-color: #fc8181;
    background: #fff5f5;
}

.installment-card.early {
    border-color: #90cdf4;
    background: #ebf8ff;
}

.installment-card.on-time {
    border-color: #68d391;
    background: #f0fff4;
}

.installment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.installment-number {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a202c;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-danger {
    background: #fed7d7;
    color: #c53030;
}

.badge-info {
    background: #bee3f8;
    color: #2c5282;
}

.badge-success {
    background: #c6f6d5;
    color: #22543d;
}

.installment-details {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.installment-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.installment-detail-row .label {
    font-weight: 600;
    color: #718096;
}

.installment-detail-row .value {
    font-weight: 600;
    color: #1a202c;
}

.amount-highlight {
    font-size: 1.5rem;
    font-weight: 700;
    color: #3182ce;
}

.preview-box {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 24px;
}

.preview-box p {
    margin: 0 0 12px 0;
    color: #2d3748;
}

.transaction-preview {
    display: flex;
    align-items: center;
    gap: 16px;
    margin: 16px 0;
    padding: 16px;
    background: white;
    border-radius: 6px;
}

.transaction-entry {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px;
    border-radius: 6px;
}

.transaction-entry.debit {
    background: #fff5f5;
    border: 2px solid #fc8181;
}

.transaction-entry.credit {
    background: #f0fff4;
    border: 2px solid #68d391;
}

.entry-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
}

.entry-account {
    font-weight: 600;
    color: #1a202c;
}

.entry-amount {
    font-size: 1.25rem;
    font-weight: 700;
}

.transaction-entry.debit .entry-amount {
    color: #e53e3e;
}

.transaction-entry.credit .entry-amount {
    color: #48bb78;
}

.transaction-arrow {
    font-size: 1.5rem;
    color: #718096;
    font-weight: 700;
}

.budget-impact {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}

.budget-impact p {
    margin-bottom: 8px;
}

.budget-impact ul {
    margin: 8px 0;
    padding-left: 24px;
}

.budget-impact li {
    margin-bottom: 8px;
    color: #4a5568;
}

.action-section {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}

.notes-section {
    margin-top: 24px;
    padding: 16px;
    background: #fffaf0;
    border: 1px solid #fbd38d;
    border-radius: 6px;
}

.notes-section strong {
    color: #744210;
}

.notes-section p {
    margin: 8px 0 0 0;
    color: #975a16;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .content-grid {
        grid-template-columns: 1fr;
    }

    .transaction-preview {
        flex-direction: column;
    }

    .transaction-arrow {
        transform: rotate(90deg);
    }

    .action-section {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
const scheduleUuid = '<?= $schedule['uuid'] ?>';
const ledgerUuid = '<?= $ledger_uuid ?>';
const planUuid = '<?= $schedule['plan_uuid'] ?>';
const installmentNumber = <?= $schedule['installment_number'] ?>;
const amount = '<?= formatCurrency($schedule['scheduled_amount']) ?>';

async function processInstallment() {
    if (!confirm(`Process installment #${installmentNumber} for ${amount}?\n\nThis will create a budget transaction and update the installment schedule.`)) {
        return;
    }

    // Show loading state
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Processing...';

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
            window.location.href = `view.php?ledger=${ledgerUuid}&plan=${planUuid}`;
        } else {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Error: ' + (result.error || 'Failed to process installment'));
        }
    } catch (error) {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('An error occurred. Please try again.');
    }
}

async function processBatch() {
    const overdueCount = <?= count($overdue_installments) ?>;

    if (!confirm(`Process all ${overdueCount} overdue installments?\n\nThis will create ${overdueCount} budget transactions.`)) {
        return;
    }

    // Show loading state
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Processing...';

    try {
        const scheduleUuids = <?= json_encode(array_column($overdue_installments, 'uuid')) ?>;
        let successCount = 0;
        let failCount = 0;

        for (const uuid of scheduleUuids) {
            try {
                const response = await fetch('/pgbudget/api/process-installment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        schedule_uuid: uuid
                    })
                });

                const result = await response.json();

                if (result.success) {
                    successCount++;
                } else {
                    failCount++;
                }
            } catch (error) {
                failCount++;
            }
        }

        if (successCount > 0) {
            alert(`✅ Processed ${successCount} installment${successCount > 1 ? 's' : ''} successfully!${failCount > 0 ? `\n⚠️ ${failCount} failed.` : ''}`);
            window.location.href = `view.php?ledger=${ledgerUuid}&plan=${planUuid}`;
        } else {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Failed to process installments. Please try again.');
        }
    } catch (error) {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('An error occurred. Please try again.');
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
