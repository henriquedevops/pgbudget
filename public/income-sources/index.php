<?php
/**
 * Income Sources & Payroll Deductions Dashboard
 * Combined listing with tabs for income sources and payroll deductions
 */

require_once '../../includes/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

// Handle success message redirect
if (isset($_GET['success'])) {
    $_SESSION['success'] = $_GET['success'];
    $tab = isset($_GET['tab']) ? '&tab=' . $_GET['tab'] : '';
    header("Location: index.php?ledger=$ledger_uuid$tab");
    exit;
}

$active_tab = $_GET['tab'] ?? 'income';

try {
    $db = getDbConnection();

    $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
    $stmt->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get all income sources
    $stmt = $db->prepare("SELECT * FROM api.get_income_sources(?)");
    $stmt->execute([$ledger_uuid]);
    $income_sources = $stmt->fetchAll();

    // Get all payroll deductions
    $stmt = $db->prepare("SELECT * FROM api.get_payroll_deductions(?)");
    $stmt->execute([$ledger_uuid]);
    $deductions = $stmt->fetchAll();

    // Calculate summary values
    $monthly_gross = 0;
    $monthly_deductions = 0;
    $active_sources = 0;

    foreach ($income_sources as $source) {
        if (!$source['is_active']) continue;
        $active_sources++;
        $amount = floatval($source['amount']) / 100; // cents to dollars

        switch ($source['frequency']) {
            case 'weekly': $monthly_gross += $amount * 52 / 12; break;
            case 'biweekly': $monthly_gross += $amount * 26 / 12; break;
            case 'monthly': $monthly_gross += $amount; break;
            case 'semiannual': $monthly_gross += $amount / 6; break;
            case 'annual': $monthly_gross += $amount / 12; break;
            case 'one_time': break; // Don't include in monthly
            default: $monthly_gross += $amount;
        }
    }

    foreach ($deductions as $ded) {
        if (!$ded['is_active']) continue;
        $amount = $ded['is_fixed_amount']
            ? floatval($ded['fixed_amount']) / 100
            : floatval($ded['estimated_amount'] ?? 0) / 100;

        switch ($ded['frequency']) {
            case 'weekly': $monthly_deductions += $amount * 52 / 12; break;
            case 'biweekly': $monthly_deductions += $amount * 26 / 12; break;
            case 'monthly': $monthly_deductions += $amount; break;
            case 'semiannual': $monthly_deductions += $amount / 6; break;
            case 'annual': $monthly_deductions += $amount / 12; break;
            default: $monthly_deductions += $amount;
        }
    }

    $net_take_home = $monthly_gross - $monthly_deductions;

    // Group income sources by employer
    $income_by_employer = [];
    foreach ($income_sources as $source) {
        $employer = $source['employer_name'] ?? 'Other';
        $income_by_employer[$employer][] = $source;
    }

    // Group deductions by employer
    $deductions_by_employer = [];
    foreach ($deductions as $ded) {
        $employer = $ded['employer_name'] ?? 'Other';
        $deductions_by_employer[$employer][] = $ded;
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

// Helper: format occurrence months from PG array to readable string
function formatOccurrenceMonths($pg_array) {
    if (empty($pg_array)) return 'All';
    $months_str = trim($pg_array, '{}');
    if (empty($months_str)) return 'All';
    $month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $months = array_map('intval', explode(',', $months_str));
    $names = array_map(function($m) use ($month_names) {
        return $month_names[$m - 1] ?? '?';
    }, $months);
    return implode(', ', $names);
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1>Income & Deductions</h1>
            <p>Manage income sources and payroll deductions for <?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="page-actions">
            <?php if ($active_tab === 'income'): ?>
                <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Income Source</a>
            <?php else: ?>
                <a href="../payroll-deductions/create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">+ New Deduction</a>
            <?php endif; ?>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Back to Budget</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="income-summary-cards">
        <div class="summary-card">
            <div class="summary-card-label">Monthly Gross Income</div>
            <div class="summary-card-value amount positive">$<?= number_format($monthly_gross, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Monthly Deductions</div>
            <div class="summary-card-value amount negative">$<?= number_format($monthly_deductions, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Est. Net Take-Home</div>
            <div class="summary-card-value amount <?= $net_take_home >= 0 ? 'positive' : 'negative' ?>">$<?= number_format($net_take_home, 2) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-card-label">Active Sources</div>
            <div class="summary-card-value"><?= $active_sources ?></div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-nav">
        <a href="?ledger=<?= $ledger_uuid ?>&tab=income"
           class="tab-link <?= $active_tab === 'income' ? 'active' : '' ?>">
            Income Sources (<?= count($income_sources) ?>)
        </a>
        <a href="?ledger=<?= $ledger_uuid ?>&tab=deductions"
           class="tab-link <?= $active_tab === 'deductions' ? 'active' : '' ?>">
            Payroll Deductions (<?= count($deductions) ?>)
        </a>
    </div>

    <?php if ($active_tab === 'income'): ?>
        <!-- Income Sources Tab -->
        <?php if (empty($income_sources)): ?>
            <div class="empty-state">
                <h3>No income sources found</h3>
                <p>Track your salary, freelance income, bonuses, and other income sources.</p>
                <p class="empty-state-hint">Income sources are used in the financial projection engine to forecast your cash flow.</p>
                <a href="create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Income Source</a>
            </div>
        <?php else: ?>
            <?php foreach ($income_by_employer as $employer => $sources): ?>
                <div class="income-section">
                    <div class="section-header">
                        <h2><?= htmlspecialchars($employer) ?></h2>
                    </div>
                    <div class="income-table-container">
                        <table class="table income-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Frequency</th>
                                    <th>Months</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sources as $source): ?>
                                    <tr class="<?= $source['is_active'] ? '' : 'inactive' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($source['name']) ?></strong>
                                            <?php if ($source['description']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($source['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="income-type-badge type-<?= $source['income_type'] ?>">
                                                <?= ucfirst($source['income_type']) ?>
                                            </span>
                                        </td>
                                        <td><span class="amount"><?= formatCurrency($source['amount']) ?></span></td>
                                        <td><?= ucfirst(str_replace('_', ' ', $source['frequency'])) ?></td>
                                        <td><?= formatOccurrenceMonths($source['occurrence_months']) ?></td>
                                        <td>
                                            <?php if ($source['is_active']): ?>
                                                <span class="status-badge status-active">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions-cell">
                                            <a href="edit.php?ledger=<?= $ledger_uuid ?>&source=<?= $source['uuid'] ?>"
                                               class="btn btn-small btn-secondary">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- Payroll Deductions Tab -->
        <?php if (empty($deductions)): ?>
            <div class="empty-state">
                <h3>No payroll deductions found</h3>
                <p>Track taxes, social security, health plans, pension contributions, and other deductions.</p>
                <p class="empty-state-hint">Deductions are subtracted from income in the financial projection engine.</p>
                <a href="../payroll-deductions/create.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Create Your First Deduction</a>
            </div>
        <?php else: ?>
            <?php foreach ($deductions_by_employer as $employer => $deds): ?>
                <div class="income-section">
                    <div class="section-header">
                        <h2><?= htmlspecialchars($employer) ?></h2>
                    </div>
                    <div class="income-table-container">
                        <table class="table income-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Amount Type</th>
                                    <th>Amount</th>
                                    <th>Frequency</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deds as $ded): ?>
                                    <tr class="<?= $ded['is_active'] ? '' : 'inactive' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($ded['name']) ?></strong>
                                            <?php if ($ded['description']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($ded['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="deduction-type-badge type-<?= $ded['deduction_type'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $ded['deduction_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($ded['is_percentage']): ?>
                                                <?= number_format($ded['percentage_value'], 2) ?>%
                                                <br><small class="text-muted">of <?= ucfirst(str_replace('_', ' ', $ded['percentage_base'] ?? 'gross')) ?></small>
                                            <?php elseif ($ded['is_fixed_amount']): ?>
                                                Fixed
                                            <?php else: ?>
                                                Estimated
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($ded['is_fixed_amount'] && $ded['fixed_amount']): ?>
                                                <span class="amount"><?= formatCurrency($ded['fixed_amount']) ?></span>
                                            <?php elseif ($ded['estimated_amount']): ?>
                                                <span class="amount">~<?= formatCurrency($ded['estimated_amount']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= ucfirst(str_replace('_', ' ', $ded['frequency'])) ?></td>
                                        <td>
                                            <?php if ($ded['is_active']): ?>
                                                <span class="status-badge status-active">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions-cell">
                                            <a href="../payroll-deductions/edit.php?ledger=<?= $ledger_uuid ?>&deduction=<?= $ded['uuid'] ?>"
                                               class="btn btn-small btn-secondary">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.income-summary-cards {
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

.summary-card-value.positive { color: #2e7d32; }
.summary-card-value.negative { color: #d32f2f; }

.tab-nav {
    display: flex;
    gap: 0;
    margin-bottom: 2rem;
    border-bottom: 2px solid #e0e0e0;
}

.tab-link {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: #666;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.tab-link:hover {
    color: #333;
    background: #f5f5f5;
}

.tab-link.active {
    color: #0066cc;
    border-bottom-color: #0066cc;
}

.income-section {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
    margin-bottom: 1.5rem;
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

.income-table { width: 100%; }

.income-table th {
    background: #f5f5f5;
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid #ddd;
    font-size: 0.875rem;
}

.income-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
    font-size: 0.875rem;
}

.income-table tr:hover { background: #f9f9f9; }
.income-table tr.inactive { opacity: 0.6; }

.income-type-badge,
.deduction-type-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.type-salary { background: #e8f5e9; color: #2e7d32; }
.type-bonus { background: #fff3e0; color: #f57c00; }
.type-benefit { background: #e3f2fd; color: #1976d2; }
.type-freelance { background: #f3e5f5; color: #7b1fa2; }
.type-rental { background: #e0f2f1; color: #00796b; }
.type-investment { background: #fce4ec; color: #c2185b; }
.type-other { background: #f5f5f5; color: #616161; }

.type-tax { background: #ffebee; color: #c62828; }
.type-social_security { background: #e3f2fd; color: #1565c0; }
.type-health_plan { background: #e8f5e9; color: #2e7d32; }
.type-pension_fund { background: #f3e5f5; color: #7b1fa2; }
.type-union_dues { background: #fff3e0; color: #ef6c00; }
.type-donation { background: #e0f2f1; color: #00796b; }
.type-loan_repayment { background: #fce4ec; color: #c2185b; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
}

.status-active { background: #e8f5e9; color: #2e7d32; }
.status-inactive { background: #f5f5f5; color: #757575; }

.actions-cell { white-space: nowrap; }

.text-muted { color: #666; }

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 8px;
    border: 1px solid #e0e0e0;
}

.empty-state h3 { color: #666; margin-bottom: 1rem; }
.empty-state p { color: #999; margin-bottom: 1rem; }
.empty-state-hint { font-style: italic; }

@media (max-width: 768px) {
    .income-summary-cards { grid-template-columns: repeat(2, 1fr); }
    .income-table-container { overflow-x: auto; }
    .tab-nav { overflow-x: auto; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
