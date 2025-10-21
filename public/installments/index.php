<?php
/**
 * Installment Plans List Page
 * Display all installment plans for the current ledger
 * Part of Step 3.2 of INSTALLMENT_PAYMENTS_IMPLEMENTATION.md
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

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$card_filter = $_GET['card'] ?? 'all';

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

    // Get all installment plans with schedule details
    $stmt = $db->prepare("
        SELECT
            ip.id, ip.uuid, ip.created_at, ip.purchase_amount,
            ip.purchase_date, ip.description, ip.number_of_installments,
            ip.installment_amount, ip.frequency, ip.start_date,
            ip.status, ip.completed_installments,
            cc.name as credit_card_name,
            cc.uuid as credit_card_uuid,
            cat.name as category_name,
            cat.uuid as category_uuid,
            (
                SELECT MIN(isch.due_date)
                FROM data.installment_schedules isch
                WHERE isch.installment_plan_id = ip.id
                AND isch.status = 'scheduled'
            ) as next_due_date,
            (
                SELECT COUNT(*)
                FROM data.installment_schedules isch
                WHERE isch.installment_plan_id = ip.id
                AND isch.status = 'scheduled'
            ) as remaining_count,
            (
                SELECT SUM(isch.scheduled_amount)
                FROM data.installment_schedules isch
                WHERE isch.installment_plan_id = ip.id
                AND isch.status = 'scheduled'
            ) as remaining_amount
        FROM data.installment_plans ip
        JOIN data.ledgers l ON ip.ledger_id = l.id
        JOIN data.accounts cc ON ip.credit_card_account_id = cc.id
        LEFT JOIN data.accounts cat ON ip.category_account_id = cat.id
        WHERE l.uuid = ?
        ORDER BY ip.created_at DESC
    ");
    $stmt->execute([$ledger_uuid]);
    $all_plans = $stmt->fetchAll();

    // Get unique credit cards for filter
    $credit_cards = [];
    foreach ($all_plans as $plan) {
        $card_uuid = $plan['credit_card_uuid'];
        if (!isset($credit_cards[$card_uuid])) {
            $credit_cards[$card_uuid] = $plan['credit_card_name'];
        }
    }

    // Apply filters
    $plans = array_filter($all_plans, function($plan) use ($status_filter, $card_filter) {
        $status_match = ($status_filter === 'all' || $plan['status'] === $status_filter);
        $card_match = ($card_filter === 'all' || $plan['credit_card_uuid'] === $card_filter);
        return $status_match && $card_match;
    });

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
            <h1>📊 Installment Plans</h1>
            <p>Manage installment payment plans for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Plan</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <?php if (empty($all_plans)): ?>
        <div class="empty-state">
            <h3>No installment plans found</h3>
            <p>Create installment plans to spread large credit card purchases across multiple budget periods.</p>
            <p class="empty-state-hint">Perfect for furniture, electronics, vacations, or any large purchase you want to budget over time.</p>
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Plan</a>
        </div>
    <?php else: ?>
        <!-- Summary Statistics -->
        <?php
        $active_plans = 0;
        $total_active_debt = 0;
        $total_monthly_obligation = 0;
        $total_remaining = 0;

        foreach ($all_plans as $plan) {
            if ($plan['status'] === 'active') {
                $active_plans++;
                $remaining = floatval($plan['remaining_amount'] ?? 0);
                $total_remaining += $remaining;

                // Calculate monthly obligation based on frequency
                $installment_amount = floatval($plan['installment_amount']);
                switch ($plan['frequency']) {
                    case 'weekly':
                        $total_monthly_obligation += $installment_amount * 52 / 12;
                        break;
                    case 'bi-weekly':
                        $total_monthly_obligation += $installment_amount * 26 / 12;
                        break;
                    case 'monthly':
                    default:
                        $total_monthly_obligation += $installment_amount;
                        break;
                }
            }
        }
        ?>

        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-label">Active Plans</div>
                <div class="stat-value"><?= $active_plans ?></div>
                <div class="stat-hint">Currently processing</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Remaining</div>
                <div class="stat-value"><?= formatCurrency($total_remaining) ?></div>
                <div class="stat-hint">Left to pay</div>
            </div>
            <div class="stat-card highlight">
                <div class="stat-label">Monthly Obligation</div>
                <div class="stat-value"><?= formatCurrency($total_monthly_obligation) ?></div>
                <div class="stat-hint">Average per month</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label for="status-filter">Status:</label>
                <select id="status-filter" class="filter-select">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="card-filter">Credit Card:</label>
                <select id="card-filter" class="filter-select">
                    <option value="all" <?= $card_filter === 'all' ? 'selected' : '' ?>>All Cards</option>
                    <?php foreach ($credit_cards as $uuid => $name): ?>
                        <option value="<?= $uuid ?>" <?= $card_filter === $uuid ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($status_filter !== 'all' || $card_filter !== 'all'): ?>
                <button class="btn-clear-filters" onclick="window.location.href='index.php?ledger=<?= $ledger_uuid ?>'">
                    Clear Filters
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($plans)): ?>
            <div class="no-results">
                <p>No installment plans match the selected filters.</p>
                <button class="btn btn-secondary" onclick="window.location.href='index.php?ledger=<?= $ledger_uuid ?>'">
                    Clear Filters
                </button>
            </div>
        <?php else: ?>
            <!-- Plans Table -->
            <div class="plans-section">
                <table class="table plans-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Credit Card</th>
                            <th>Category</th>
                            <th>Total Amount</th>
                            <th>Payment</th>
                            <th>Progress</th>
                            <th>Next Due</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plans as $plan): ?>
                            <?php
                            $progress_percent = $plan['number_of_installments'] > 0
                                ? ($plan['completed_installments'] / $plan['number_of_installments']) * 100
                                : 0;
                            $remaining = floatval($plan['remaining_amount'] ?? 0);
                            ?>
                            <tr class="plan-row plan-status-<?= strtolower($plan['status']) ?>">
                                <td>
                                    <a href="view.php?ledger=<?= $ledger_uuid ?>&plan=<?= $plan['uuid'] ?>" class="plan-name">
                                        <strong><?= htmlspecialchars($plan['description']) ?></strong>
                                    </a>
                                    <br><small class="text-muted">
                                        Purchase: <?= date('M j, Y', strtotime($plan['purchase_date'])) ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($plan['credit_card_name']) ?></td>
                                <td>
                                    <?php if ($plan['category_name']): ?>
                                        <?= htmlspecialchars($plan['category_name']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount"><?= formatCurrency($plan['purchase_amount']) ?></td>
                                <td>
                                    <strong><?= formatCurrency($plan['installment_amount']) ?></strong>
                                    <br><small class="text-muted">
                                        <?= ucfirst($plan['frequency']) ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="progress-info">
                                        <div class="progress-text">
                                            <?= $plan['completed_installments'] ?>/<?= $plan['number_of_installments'] ?>
                                            <span class="text-muted">(<?= number_format($progress_percent, 0) ?>%)</span>
                                        </div>
                                        <div class="progress-bar-container">
                                            <div class="progress-bar" style="width: <?= $progress_percent ?>%"></div>
                                        </div>
                                        <?php if ($remaining > 0): ?>
                                            <small class="text-muted">
                                                Remaining: <?= formatCurrency($remaining) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($plan['next_due_date']): ?>
                                        <?= date('M j, Y', strtotime($plan['next_due_date'])) ?>
                                        <?php
                                        $today = new DateTime();
                                        $due_date = new DateTime($plan['next_due_date']);
                                        $days_until = $today->diff($due_date)->days;
                                        $is_overdue = $due_date < $today;
                                        ?>
                                        <br><small class="<?= $is_overdue ? 'text-danger' : 'text-muted' ?>">
                                            <?php if ($is_overdue): ?>
                                                <?= $days_until ?> days overdue
                                            <?php else: ?>
                                                in <?= $days_until ?> days
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($plan['status']) ?>">
                                        <?= ucfirst($plan['status']) ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="view.php?ledger=<?= $ledger_uuid ?>&plan=<?= $plan['uuid'] ?>"
                                       class="btn-action" title="View Details">
                                        👁️ View
                                    </a>
                                    <?php if ($plan['status'] === 'active' && $plan['completed_installments'] == 0): ?>
                                        <button class="btn-action btn-danger"
                                                onclick="cancelPlan('<?= $plan['uuid'] ?>', '<?= htmlspecialchars($plan['description']) ?>')"
                                                title="Cancel Plan">
                                            ❌ Cancel
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
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

.btn-secondary {
    background: #e2e8f0;
    color: #2d3748;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #f7fafc;
    border: 2px dashed #cbd5e0;
    border-radius: 12px;
    margin-top: 40px;
}

.empty-state h3 {
    margin-top: 0;
    color: #2d3748;
}

.empty-state p {
    color: #718096;
    margin-bottom: 24px;
}

.empty-state-hint {
    font-style: italic;
}

.summary-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.stat-card.highlight {
    background: #ebf8ff;
    border: 2px solid #3182ce;
}

.stat-label {
    font-size: 14px;
    font-weight: 600;
    color: #718096;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 4px;
}

.stat-hint {
    font-size: 12px;
    color: #a0aec0;
}

.filters-bar {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
    padding: 16px;
    background: #f7fafc;
    border-radius: 8px;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-weight: 600;
    color: #4a5568;
    font-size: 14px;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    cursor: pointer;
}

.btn-clear-filters {
    padding: 8px 16px;
    background: #e2e8f0;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    color: #2d3748;
    margin-left: auto;
}

.btn-clear-filters:hover {
    background: #cbd5e0;
}

.no-results {
    text-align: center;
    padding: 40px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}

.plans-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
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

.plan-name {
    color: #3182ce;
    text-decoration: none;
    font-weight: 600;
}

.plan-name:hover {
    text-decoration: underline;
}

.text-muted {
    color: #a0aec0;
}

.text-danger {
    color: #e53e3e;
}

.amount {
    font-weight: 600;
    color: #2d3748;
}

.progress-info {
    min-width: 150px;
}

.progress-text {
    font-weight: 600;
    margin-bottom: 6px;
    font-size: 14px;
}

.progress-bar-container {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 4px;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    transition: width 0.3s ease;
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

.actions {
    white-space: nowrap;
}

.btn-action {
    padding: 6px 12px;
    margin-right: 4px;
    border: 1px solid #cbd5e0;
    background: white;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    text-decoration: none;
    color: #2d3748;
    display: inline-block;
}

.btn-action:hover {
    background: #f7fafc;
}

.btn-danger {
    border-color: #fc8181;
    color: #c53030;
}

.btn-danger:hover {
    background: #fff5f5;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .summary-stats {
        grid-template-columns: 1fr;
    }

    .filters-bar {
        flex-direction: column;
        align-items: stretch;
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
// Filter handling
document.getElementById('status-filter')?.addEventListener('change', function() {
    const status = this.value;
    const card = document.getElementById('card-filter').value;
    window.location.href = `index.php?ledger=<?= $ledger_uuid ?>&status=${status}&card=${card}`;
});

document.getElementById('card-filter')?.addEventListener('change', function() {
    const card = this.value;
    const status = document.getElementById('status-filter').value;
    window.location.href = `index.php?ledger=<?= $ledger_uuid ?>&status=${status}&card=${card}`;
});

// Cancel plan
async function cancelPlan(planUuid, description) {
    if (!confirm(`Are you sure you want to cancel the installment plan for "${description}"?\n\nThis action cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch(`/pgbudget/api/installment-plans.php?plan_uuid=${planUuid}`, {
            method: 'DELETE'
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
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
