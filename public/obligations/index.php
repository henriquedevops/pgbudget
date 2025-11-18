<?php
/**
 * Obligations List Page
 * Display all obligations and upcoming bills for the current ledger
 * Part of Phase 2 of OBLIGATIONS_BILLS_IMPLEMENTATION_PLAN.md
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

// Get filter parameters
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$days_ahead = isset($_GET['days']) ? (int)$_GET['days'] : 30;

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

    // Get all obligations for this ledger
    $stmt = $db->prepare("SELECT * FROM api.get_obligations(?)");
    $stmt->execute([$ledger_uuid]);
    $all_obligations = $stmt->fetchAll();

    // Get upcoming obligation payments
    $stmt = $db->prepare("SELECT * FROM api.get_upcoming_obligations(?, ?, true)");
    $stmt->execute([$ledger_uuid, $days_ahead]);
    $upcoming_payments = $stmt->fetchAll();

    // Apply filters to payments
    $filtered_payments = array_filter($upcoming_payments, function($payment) use ($type_filter, $search_query) {
        $type_match = ($type_filter === 'all' || $payment['obligation_type'] === $type_filter);

        if (!empty($search_query)) {
            $search_match = (
                stripos($payment['name'], $search_query) !== false ||
                stripos($payment['payee_name'], $search_query) !== false
            );
        } else {
            $search_match = true;
        }

        return $type_match && $search_match;
    });

    // Group payments by status
    $overdue_payments = [];
    $due_soon_payments = [];
    $upcoming_payments_list = [];

    foreach ($filtered_payments as $payment) {
        if ($payment['is_overdue']) {
            $overdue_payments[] = $payment;
        } elseif ($payment['days_until_due'] <= 7) {
            $due_soon_payments[] = $payment;
        } else {
            $upcoming_payments_list[] = $payment;
        }
    }

    // Calculate monthly summary
    $total_monthly_obligations = 0;
    $paid_this_month = 0;
    $current_month = date('Y-m');

    foreach ($all_obligations as $obligation) {
        if (!$obligation['is_active']) continue;

        $amount = $obligation['is_fixed_amount']
            ? floatval($obligation['fixed_amount'])
            : floatval($obligation['estimated_amount'] ?? 0);

        // Convert to monthly equivalent
        switch ($obligation['frequency']) {
            case 'weekly':
                $monthly_amount = $amount * 52 / 12;
                break;
            case 'biweekly':
                $monthly_amount = $amount * 26 / 12;
                break;
            case 'monthly':
                $monthly_amount = $amount;
                break;
            case 'quarterly':
                $monthly_amount = $amount / 3;
                break;
            case 'semiannual':
                $monthly_amount = $amount / 6;
                break;
            case 'annual':
                $monthly_amount = $amount / 12;
                break;
            default:
                $monthly_amount = $amount;
        }

        $total_monthly_obligations += $monthly_amount;
    }

    // Calculate paid this month from obligation_payments
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(actual_amount_paid), 0) as paid_total
        FROM api.obligation_payments
        WHERE obligation_uuid IN (
            SELECT uuid FROM api.obligations WHERE ledger_uuid = ?
        )
        AND status IN ('paid', 'late')
        AND DATE_TRUNC('month', paid_date) = DATE_TRUNC('month', CURRENT_DATE)
    ");
    $stmt->execute([$ledger_uuid]);
    $paid_result = $stmt->fetch();
    $paid_this_month = floatval($paid_result['paid_total'] ?? 0);

    // Get unique obligation types for filter
    $obligation_types = [];
    foreach ($all_obligations as $obligation) {
        $type = $obligation['obligation_type'];
        if (!in_array($type, $obligation_types)) {
            $obligation_types[] = $type;
        }
    }
    sort($obligation_types);

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
            <h1>📋 Obligations & Bills</h1>
            <p>Manage bills and recurring obligations for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Obligation</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <?php if (empty($all_obligations)): ?>
        <div class="empty-state">
            <h3>No obligations found</h3>
            <p>Track your recurring bills and obligations like utilities, rent, subscriptions, insurance, and more.</p>
            <p class="empty-state-hint">Never miss a payment with automatic reminders and payment tracking.</p>
            <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Obligation</a>
        </div>
    <?php else: ?>
        <!-- Monthly Summary Cards -->
        <div class="obligations-summary-cards">
            <div class="summary-card">
                <div class="summary-card-label">Active Obligations</div>
                <div class="summary-card-value"><?= count(array_filter($all_obligations, fn($o) => $o['is_active'])) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total Monthly Obligations</div>
                <div class="summary-card-value"><?= formatLoanAmount($total_monthly_obligations) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Paid This Month</div>
                <div class="summary-card-value amount positive"><?= formatLoanAmount($paid_this_month) ?></div>
                <div class="summary-card-percentage">
                    <?php
                    $percent_paid = $total_monthly_obligations > 0
                        ? ($paid_this_month / $total_monthly_obligations) * 100
                        : 0;
                    ?>
                    <?= number_format($percent_paid, 0) ?>%
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Remaining This Month</div>
                <div class="summary-card-value amount"><?= formatLoanAmount($total_monthly_obligations - $paid_this_month) ?></div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="obligations-filters">
            <form method="GET" action="" class="filters-form">
                <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">

                <div class="filter-group">
                    <label for="search">Search:</label>
                    <input type="text"
                           id="search"
                           name="search"
                           placeholder="Search by name or payee..."
                           value="<?= htmlspecialchars($search_query) ?>">
                </div>

                <div class="filter-group">
                    <label for="type">Type:</label>
                    <select id="type" name="type">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <?php foreach ($obligation_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $type_filter === $type ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="days">Show next:</label>
                    <select id="days" name="days">
                        <option value="7" <?= $days_ahead === 7 ? 'selected' : '' ?>>7 days</option>
                        <option value="14" <?= $days_ahead === 14 ? 'selected' : '' ?>>14 days</option>
                        <option value="30" <?= $days_ahead === 30 ? 'selected' : '' ?>>30 days</option>
                        <option value="60" <?= $days_ahead === 60 ? 'selected' : '' ?>>60 days</option>
                        <option value="90" <?= $days_ahead === 90 ? 'selected' : '' ?>>90 days</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Apply</button>
                <?php if (!empty($search_query) || $type_filter !== 'all' || $days_ahead !== 30): ?>
                    <a href="?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Overdue Section -->
        <?php if (!empty($overdue_payments)): ?>
            <div class="obligations-section overdue-section">
                <div class="section-header">
                    <h2>⚠️ Overdue (<?= count($overdue_payments) ?>)</h2>
                </div>
                <div class="obligations-list">
                    <?php foreach ($overdue_payments as $payment): ?>
                        <div class="obligation-card overdue">
                            <div class="obligation-info">
                                <div class="obligation-name">
                                    <strong><?= htmlspecialchars($payment['name']) ?></strong>
                                    <span class="obligation-type-badge type-<?= $payment['obligation_type'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $payment['obligation_type'])) ?>
                                    </span>
                                </div>
                                <div class="obligation-payee">
                                    Payee: <?= htmlspecialchars($payment['payee_name']) ?>
                                </div>
                                <div class="obligation-due-date overdue-text">
                                    Due: <?= date('M j, Y', strtotime($payment['due_date'])) ?>
                                    (<?= abs($payment['days_until_due']) ?> days overdue)
                                </div>
                            </div>
                            <div class="obligation-actions">
                                <div class="obligation-amount overdue-amount">
                                    <?= formatLoanAmount($payment['amount']) ?>
                                </div>
                                <button class="btn btn-success btn-small mark-paid-btn"
                                        data-payment-uuid="<?= $payment['payment_uuid'] ?>"
                                        data-payment-name="<?= htmlspecialchars($payment['name']) ?>"
                                        data-payment-amount="<?= $payment['amount'] ?>"
                                        data-due-date="<?= $payment['due_date'] ?>">
                                    Mark as Paid
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Due Soon Section (Next 7 Days) -->
        <?php if (!empty($due_soon_payments)): ?>
            <div class="obligations-section due-soon-section">
                <div class="section-header">
                    <h2>🔔 Due Soon - Next 7 Days (<?= count($due_soon_payments) ?>)</h2>
                </div>
                <div class="obligations-list">
                    <?php foreach ($due_soon_payments as $payment): ?>
                        <div class="obligation-card due-soon">
                            <div class="obligation-info">
                                <div class="obligation-name">
                                    <strong><?= htmlspecialchars($payment['name']) ?></strong>
                                    <span class="obligation-type-badge type-<?= $payment['obligation_type'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $payment['obligation_type'])) ?>
                                    </span>
                                </div>
                                <div class="obligation-payee">
                                    Payee: <?= htmlspecialchars($payment['payee_name']) ?>
                                </div>
                                <div class="obligation-due-date">
                                    Due: <?= date('M j, Y', strtotime($payment['due_date'])) ?>
                                    <?php if ($payment['days_until_due'] == 0): ?>
                                        (Today)
                                    <?php elseif ($payment['days_until_due'] == 1): ?>
                                        (Tomorrow)
                                    <?php else: ?>
                                        (in <?= $payment['days_until_due'] ?> days)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="obligation-actions">
                                <div class="obligation-amount">
                                    <?= formatLoanAmount($payment['amount']) ?>
                                </div>
                                <button class="btn btn-success btn-small mark-paid-btn"
                                        data-payment-uuid="<?= $payment['payment_uuid'] ?>"
                                        data-payment-name="<?= htmlspecialchars($payment['name']) ?>"
                                        data-payment-amount="<?= $payment['amount'] ?>"
                                        data-due-date="<?= $payment['due_date'] ?>">
                                    Mark as Paid
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upcoming Section (8+ Days) -->
        <?php if (!empty($upcoming_payments_list)): ?>
            <div class="obligations-section upcoming-section">
                <div class="section-header">
                    <h2>📅 Upcoming (8-<?= $days_ahead ?> Days) - (<?= count($upcoming_payments_list) ?>)</h2>
                </div>
                <div class="obligations-list">
                    <?php foreach ($upcoming_payments_list as $payment): ?>
                        <div class="obligation-card upcoming">
                            <div class="obligation-info">
                                <div class="obligation-name">
                                    <strong><?= htmlspecialchars($payment['name']) ?></strong>
                                    <span class="obligation-type-badge type-<?= $payment['obligation_type'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $payment['obligation_type'])) ?>
                                    </span>
                                </div>
                                <div class="obligation-payee">
                                    Payee: <?= htmlspecialchars($payment['payee_name']) ?>
                                </div>
                                <div class="obligation-due-date">
                                    Due: <?= date('M j, Y', strtotime($payment['due_date'])) ?>
                                    (in <?= $payment['days_until_due'] ?> days)
                                </div>
                            </div>
                            <div class="obligation-actions">
                                <div class="obligation-amount">
                                    <?= formatLoanAmount($payment['amount']) ?>
                                </div>
                                <button class="btn btn-success btn-small mark-paid-btn"
                                        data-payment-uuid="<?= $payment['payment_uuid'] ?>"
                                        data-payment-name="<?= htmlspecialchars($payment['name']) ?>"
                                        data-payment-amount="<?= $payment['amount'] ?>"
                                        data-due-date="<?= $payment['due_date'] ?>">
                                    Mark as Paid
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($filtered_payments)): ?>
            <div class="empty-state">
                <h3>No upcoming payments found</h3>
                <p>There are no scheduled payments matching your filters for the next <?= $days_ahead ?> days.</p>
                <?php if (!empty($search_query) || $type_filter !== 'all'): ?>
                    <a href="?ledger=<?= $ledger_uuid ?>&days=<?= $days_ahead ?>" class="btn btn-secondary">Clear Filters</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- All Obligations Section -->
        <div class="obligations-section all-obligations-section">
            <div class="section-header">
                <h2>📝 All Obligations (<?= count($all_obligations) ?>)</h2>
            </div>
            <div class="obligations-table-container">
                <table class="table obligations-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Payee</th>
                            <th>Amount</th>
                            <th>Frequency</th>
                            <th>Next Due</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_obligations as $obligation): ?>
                            <tr class="obligation-row <?= $obligation['is_active'] ? '' : 'inactive' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($obligation['name']) ?></strong>
                                    <?php if ($obligation['description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($obligation['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="obligation-type-badge type-<?= $obligation['obligation_type'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $obligation['obligation_type'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($obligation['payee_name']) ?></td>
                                <td>
                                    <?php if ($obligation['is_fixed_amount']): ?>
                                        <span class="amount"><?= formatLoanAmount($obligation['fixed_amount']) ?></span>
                                    <?php else: ?>
                                        <span class="amount">~<?= formatLoanAmount($obligation['estimated_amount']) ?></span>
                                        <br><small class="text-muted">Variable</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= ucfirst($obligation['frequency']) ?></td>
                                <td>
                                    <?php if ($obligation['next_due_date']): ?>
                                        <?= date('M j, Y', strtotime($obligation['next_due_date'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($obligation['is_paused']): ?>
                                        <span class="status-badge status-paused">⏸ Paused</span>
                                    <?php elseif ($obligation['is_active']): ?>
                                        <span class="status-badge status-active">✓ Active</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">✗ Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="obligation-actions-cell">
                                    <a href="view.php?ledger=<?= $ledger_uuid ?>&obligation=<?= $obligation['uuid'] ?>"
                                       class="btn btn-small btn-primary">View</a>
                                    <a href="edit.php?ledger=<?= $ledger_uuid ?>&obligation=<?= $obligation['uuid'] ?>"
                                       class="btn btn-small btn-secondary">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Mark as Paid Modal -->
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
                <label for="modalNotes">Notes (optional):</label>
                <textarea id="modalNotes"
                          rows="2"
                          placeholder="Add any notes about this payment..."
                          class="form-control"></textarea>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="modalCreateTransaction">
                    Create transaction automatically
                </label>
            </div>
        </div>

        <div class="modal-actions">
            <button id="confirmMarkPaid" class="btn btn-success">Mark as Paid</button>
            <button id="cancelMarkPaid" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<style>
.obligations-summary-cards {
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

.summary-card-percentage {
    font-size: 0.875rem;
    color: #666;
    margin-top: 0.25rem;
}

.obligations-filters {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 2rem;
}

.filters-form {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    min-width: 200px;
}

.filter-group label {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
}

.filter-group input,
.filter-group select {
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.875rem;
}

.obligations-section {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 2rem;
}

.section-header {
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e0e0e0;
}

.section-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #333;
}

.overdue-section .section-header h2 {
    color: #d32f2f;
}

.due-soon-section .section-header h2 {
    color: #f57c00;
}

.upcoming-section .section-header h2 {
    color: #1976d2;
}

.obligations-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.obligation-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background: #fafafa;
    transition: all 0.2s ease;
}

.obligation-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}

.obligation-card.overdue {
    border-left: 4px solid #d32f2f;
    background: #ffebee;
}

.obligation-card.due-soon {
    border-left: 4px solid #f57c00;
    background: #fff3e0;
}

.obligation-card.upcoming {
    border-left: 4px solid #1976d2;
}

.obligation-info {
    flex: 1;
}

.obligation-name {
    font-size: 1rem;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.obligation-payee {
    font-size: 0.875rem;
    color: #666;
    margin-bottom: 0.25rem;
}

.obligation-due-date {
    font-size: 0.875rem;
    color: #666;
}

.overdue-text {
    color: #d32f2f;
    font-weight: 500;
}

.obligation-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.5rem;
}

.obligation-amount {
    font-size: 1.25rem;
    font-weight: bold;
    color: #333;
}

.overdue-amount {
    color: #d32f2f;
}

.obligation-type-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.type-utility { background: #e3f2fd; color: #1976d2; }
.type-housing { background: #f3e5f5; color: #7b1fa2; }
.type-subscription { background: #e8f5e9; color: #388e3c; }
.type-education { background: #fff3e0; color: #f57c00; }
.type-debt { background: #ffebee; color: #c62828; }
.type-insurance { background: #e0f2f1; color: #00796b; }
.type-tax { background: #fce4ec; color: #c2185b; }
.type-other { background: #f5f5f5; color: #616161; }

.obligations-table {
    width: 100%;
}

.obligations-table th {
    background: #f5f5f5;
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid #ddd;
    font-size: 0.875rem;
}

.obligations-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
    font-size: 0.875rem;
}

.obligation-row:hover {
    background: #f9f9f9;
}

.obligation-row.inactive {
    opacity: 0.6;
}

.obligation-actions-cell {
    white-space: nowrap;
}

.obligation-actions-cell .btn {
    margin-right: 0.25rem;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.status-active { background: #e8f5e9; color: #2e7d32; }
.status-paused { background: #fff3e0; color: #f57c00; }
.status-inactive { background: #f5f5f5; color: #757575; }

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
    font-size: 0.875rem;
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

.text-muted {
    color: #666;
}

.amount.positive {
    color: #2e7d32;
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
    margin-bottom: 1rem;
}

.empty-state-hint {
    font-style: italic;
}

@media (max-width: 768px) {
    .obligations-summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters-form {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group {
        width: 100%;
    }

    .obligation-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .obligation-actions {
        width: 100%;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px solid #e0e0e0;
    }

    .obligations-table-container {
        overflow-x: auto;
    }
}
</style>

<script>
// Mark as Paid functionality
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('markPaidModal');
    const modalPaymentName = document.getElementById('modalPaymentName');
    const modalDueDate = document.getElementById('modalDueDate');
    const modalActualAmount = document.getElementById('modalActualAmount');
    const modalPaidDate = document.getElementById('modalPaidDate');
    const modalNotes = document.getElementById('modalNotes');
    const modalCreateTransaction = document.getElementById('modalCreateTransaction');
    const confirmBtn = document.getElementById('confirmMarkPaid');
    const cancelBtn = document.getElementById('cancelMarkPaid');

    let currentPaymentUuid = null;
    let currentPaymentAmount = null;

    // Handle mark as paid button clicks
    document.querySelectorAll('.mark-paid-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentPaymentUuid = this.dataset.paymentUuid;
            currentPaymentAmount = parseFloat(this.dataset.paymentAmount);

            modalPaymentName.textContent = this.dataset.paymentName;
            modalDueDate.textContent = new Date(this.dataset.dueDate).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            modalActualAmount.value = currentPaymentAmount.toFixed(2);
            modalNotes.value = '';
            modalCreateTransaction.checked = false;

            modal.style.display = 'flex';
            modalActualAmount.focus();
        });
    });

    // Handle cancel
    cancelBtn.addEventListener('click', function() {
        modal.style.display = 'none';
        currentPaymentUuid = null;
    });

    // Handle confirm mark as paid
    confirmBtn.addEventListener('click', async function() {
        if (!currentPaymentUuid) return;

        const actualAmount = parseFloat(modalActualAmount.value);
        const paidDate = modalPaidDate.value;
        const notes = modalNotes.value;
        const createTransaction = modalCreateTransaction.checked;

        if (!actualAmount || actualAmount <= 0) {
            alert('Please enter a valid payment amount.');
            return;
        }

        if (!paidDate) {
            alert('Please select a payment date.');
            return;
        }

        try {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Processing...';

            const formData = new FormData();
            formData.append('action', 'mark_paid');
            formData.append('payment_uuid', currentPaymentUuid);
            formData.append('paid_date', paidDate);
            formData.append('actual_amount', actualAmount);
            if (notes) formData.append('notes', notes);
            if (createTransaction) formData.append('create_transaction', '1');

            const response = await fetch('../api/obligations.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                window.location.reload();
            } else {
                alert('Error marking payment as paid: ' + result.error);
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Mark as Paid';
            }
        } catch (error) {
            alert('Error marking payment as paid: ' + error.message);
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Mark as Paid';
        }
    });

    // Close modal on background click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
            currentPaymentUuid = null;
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') {
            modal.style.display = 'none';
            currentPaymentUuid = null;
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
