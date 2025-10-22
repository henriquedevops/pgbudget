<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = $_GET['ledger'] ?? '';
$selected_period = $_GET['period'] ?? date('Ym'); // YYYYMM format

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

try {
    $db = getDbConnection();
    setUserContext($db);

    // Get ledger details
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found.';
        header('Location: ../index.php');
        exit;
    }

    // Get budget status for selected period
    $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?, ?)");
    $stmt->execute([$ledger_uuid, $selected_period]);
    $budget_status = $stmt->fetchAll();

    // Get budget totals for selected period
    $stmt = $db->prepare("SELECT * FROM api.get_budget_totals(?, ?)");
    $stmt->execute([$ledger_uuid, $selected_period]);
    $budget_totals = $stmt->fetch();

    // Get available periods
    $stmt = $db->prepare("
        SELECT DISTINCT period
        FROM data.budget_amounts
        WHERE account_id IN (
            SELECT id FROM data.accounts WHERE ledger_uuid = ?
        )
        ORDER BY period DESC
        LIMIT 24
    ");
    $stmt->execute([$ledger_uuid]);
    $available_periods = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Format period for display
    function formatPeriod($period) {
        $year = substr($period, 0, 4);
        $month = substr($period, 4, 2);
        $date = DateTime::createFromFormat('Y-m', "$year-$month");
        return $date ? $date->format('F Y') : $period;
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="report-header">
        <div>
            <h1>📊 Budget Report</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="filter-card">
        <h3>Budget Period</h3>
        <form method="GET" class="period-filter-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="period">Select Period:</label>
                    <select id="period" name="period" class="form-input" onchange="this.form.submit()">
                        <?php foreach ($available_periods as $period): ?>
                            <option value="<?= htmlspecialchars($period) ?>" <?= $period === $selected_period ? 'selected' : '' ?>>
                                <?= htmlspecialchars(formatPeriod($period)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-label">Total Budgeted</div>
            <div class="summary-value"><?= formatCurrency($budget_totals['total_budgeted'] ?? 0) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Spent</div>
            <div class="summary-value"><?= formatCurrency($budget_totals['total_actual'] ?? 0) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Remaining</div>
            <div class="summary-value <?= ($budget_totals['total_available'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
                <?= formatCurrency($budget_totals['total_available'] ?? 0) ?>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Budget Usage</div>
            <div class="summary-value">
                <?php
                $usage = 0;
                if (($budget_totals['total_budgeted'] ?? 0) > 0) {
                    $usage = (($budget_totals['total_actual'] ?? 0) / $budget_totals['total_budgeted']) * 100;
                }
                echo number_format($usage, 1) . '%';
                ?>
            </div>
        </div>
    </div>

    <!-- Budget vs Actual Chart -->
    <div class="charts-section">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Budget vs Actual by Category</h3>
            </div>
            <div class="chart-container">
                <canvas id="budgetVsActualChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Category Details Table -->
    <div class="data-table-card">
        <div class="table-header">
            <h3>Category Breakdown</h3>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-right">Budgeted</th>
                        <th class="text-right">Actual</th>
                        <th class="text-right">Remaining</th>
                        <th class="text-right">Usage %</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($budget_status)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No budget data for this period</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($budget_status as $category): ?>
                            <?php
                            $usage_percent = 0;
                            if ($category['budgeted_amount'] > 0) {
                                $usage_percent = ($category['actual_amount'] / $category['budgeted_amount']) * 100;
                            }
                            $is_overspent = $category['available_amount'] < 0;
                            ?>
                            <tr class="<?= $is_overspent ? 'overspent' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                                </td>
                                <td class="text-right"><?= formatCurrency($category['budgeted_amount']) ?></td>
                                <td class="text-right"><?= formatCurrency($category['actual_amount']) ?></td>
                                <td class="text-right <?= $category['available_amount'] >= 0 ? 'positive' : 'negative' ?>">
                                    <?= formatCurrency($category['available_amount']) ?>
                                </td>
                                <td class="text-right">
                                    <span class="<?= $usage_percent > 100 ? 'text-danger' : ($usage_percent > 80 ? 'text-warning' : '') ?>">
                                        <?= number_format($usage_percent, 1) ?>%
                                    </span>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?= $usage_percent > 100 ? 'overspent' : '' ?>"
                                             style="width: <?= min($usage_percent, 100) ?>%">
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.report-header h1 {
    margin: 0;
    font-size: 2rem;
    color: #2d3748;
}

.report-subtitle {
    color: #718096;
    margin: 0.5rem 0 0 0;
    font-size: 1rem;
}

.report-actions {
    display: flex;
    gap: 0.5rem;
}

.filter-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.filter-card h3 {
    margin: 0 0 1rem 0;
    color: #2d3748;
}

.period-filter-form {
    display: flex;
    gap: 1rem;
}

.form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
    font-size: 0.875rem;
}

.form-input {
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
    min-width: 200px;
}

.form-input:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.summary-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-size: 2rem;
    font-weight: 700;
    color: #2d3748;
}

.summary-value.positive {
    color: #38a169;
}

.summary-value.negative {
    color: #e53e3e;
}

.charts-section {
    margin-bottom: 2rem;
}

.chart-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.chart-header h3 {
    margin: 0;
    color: #2d3748;
}

.chart-container {
    position: relative;
    height: 400px;
}

.data-table-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.table-header h3 {
    margin: 0;
    color: #2d3748;
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

.data-table th {
    background: #f7fafc;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.875rem;
}

.data-table tbody tr:hover {
    background: #f7fafc;
}

.data-table tbody tr.overspent {
    background: #fff5f5;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.text-danger {
    color: #e53e3e;
}

.text-warning {
    color: #f59e0b;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78 0%, #f59e0b 80%, #e53e3e 100%);
    transition: width 0.3s ease;
}

.progress-fill.overspent {
    background: #e53e3e;
}

@media (max-width: 768px) {
    .container {
        padding: 1rem;
    }

    .report-header {
        flex-direction: column;
        gap: 1rem;
    }

    .summary-cards {
        grid-template-columns: 1fr;
    }

    .chart-container {
        height: 300px;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const budgetData = <?= json_encode($budget_status) ?>;

// Create Budget vs Actual Chart
const ctx = document.getElementById('budgetVsActualChart').getContext('2d');

const categories = budgetData.map(item => item.category_name);
const budgetedAmounts = budgetData.map(item => item.budgeted_amount / 100);
const actualAmounts = budgetData.map(item => item.actual_amount / 100);

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: categories,
        datasets: [
            {
                label: 'Budgeted',
                data: budgetedAmounts,
                backgroundColor: 'rgba(49, 130, 206, 0.6)',
                borderColor: '#3182ce',
                borderWidth: 2
            },
            {
                label: 'Actual',
                data: actualAmounts,
                backgroundColor: 'rgba(245, 158, 11, 0.6)',
                borderColor: '#f59e0b',
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': $' + context.parsed.y.toFixed(2);
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toFixed(0);
                    }
                }
            }
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
