<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

$ledger_uuid = pgb_current_ledger();

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

    // Get date range from query params (default to last 12 months)
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months', strtotime($end_date)));

} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage()); $_SESSION['error'] = 'An unexpected database error occurred. Please try again or contact support if the problem persists.';
    header('Location: ../index.php');
    exit;
}

$page_title = 'Income vs Expense';
require_once '../../includes/header.php';
?>

<div class="container">
    <div class="report-header">
        <div>
            <h1>💰 Income vs Expense</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="filter-card">
        <h3>Date Range</h3>
        <form method="GET" class="date-filter-form">
            <input type="hidden" name="ledger" value="<?= htmlspecialchars($ledger_uuid) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="start_date">From:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label for="end_date">To:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-input">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Update Report</button>
                </div>
            </div>
            <div class="quick-filters">
                <button type="button" class="btn btn-sm btn-secondary" onclick="setDateRange('last-3-months')">Last 3 Months</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setDateRange('last-6-months')">Last 6 Months</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setDateRange('last-12-months')">Last 12 Months</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setDateRange('ytd')">Year to Date</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setDateRange('all-time')">All Time</button>
            </div>
        </form>
    </div>

    <!-- Skeleton loading state (shown while JS fetches data) -->
    <div id="report-skeleton" class="report-loading">
        <div class="skeleton-card">
            <div class="skeleton skeleton-text w-33" style="height:1.5rem; margin-bottom:1rem;"></div>
            <div class="skeleton-row">
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
            </div>
            <div class="skeleton-row">
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
            </div>
            <div class="skeleton-row">
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
                <span class="skeleton skeleton-text" style="flex:1;"></span>
            </div>
        </div>
        <div class="skeleton-card">
            <div class="skeleton" style="height:300px; border-radius: var(--radius-md,6px);"></div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div id="summary-cards" class="summary-cards" style="display:none;">
        <div class="summary-card income-card">
            <div class="summary-label">Total Income</div>
            <div class="summary-value" id="total-income">Loading...</div>
            <div class="summary-sublabel">Avg: <span id="avg-income">-</span>/month</div>
        </div>
        <div class="summary-card expense-card">
            <div class="summary-label">Total Expenses</div>
            <div class="summary-value" id="total-expense">Loading...</div>
            <div class="summary-sublabel">Avg: <span id="avg-expense">-</span>/month</div>
        </div>
        <div class="summary-card net-card">
            <div class="summary-label">Net Savings</div>
            <div class="summary-value" id="net-total">Loading...</div>
            <div class="summary-sublabel">Avg: <span id="avg-net">-</span>/month</div>
        </div>
        <div class="summary-card rate-card">
            <div class="summary-label">Savings Rate</div>
            <div class="summary-value" id="savings-rate">Loading...</div>
            <div class="summary-sublabel"><span id="surplus-months">-</span> surplus, <span id="deficit-months">-</span> deficit</div>
        </div>
    </div>

    <!-- Chart Section -->
    <div class="charts-section" id="charts-section" style="display:none;">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Monthly Income vs Expense</h3>
                <div class="chart-controls">
                    <a href="../api/get-income-expense-report.php?action=csv&ledger=<?= urlencode($ledger_uuid) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" 
                       class="btn btn-sm btn-secondary">Export CSV</a>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="incomeExpenseChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Trend Insights -->
    <div class="insights-card" id="insights-card" style="display:none;">
        <h3>💡 Insights</h3>
        <div id="insights-content">
            <p class="loading-text">Analyzing your data...</p>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="/pgbudget/js/vendor/chart-4.4.0.umd.min.js"></script>

<!-- Custom JavaScript -->
<script>
const IncomeExpenseReport = {
    ledgerUuid: '<?= addslashes($ledger_uuid) ?>',
    startDate: '<?= addslashes($start_date) ?>',
    endDate: '<?= addslashes($end_date) ?>',
    chart: null,
    monthlyData: [],
    summary: null,

    async init() {
        await this.loadData();
        this.renderChart();
        this.renderInsights();
    },

    async loadData() {
        try {
            // Load summary
            const summaryResp = await fetch(`../api/get-income-expense-report.php?action=summary&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);
            const summaryData = await summaryResp.json();

            if (summaryData.success && summaryData.summary) {
                this.summary = summaryData.summary;
                this.renderSummary(summaryData.summary);
            }

            // Load monthly data
            const monthlyResp = await fetch(`../api/get-income-expense-report.php?action=monthly&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);
            const monthlyData = await monthlyResp.json();

            if (monthlyData.success) {
                this.monthlyData = monthlyData.data;
            }
        } catch (error) {
            console.error('Error loading data:', error);
            Toast.error('Error loading report data');
        }
    },

    renderSummary(summary) {
        document.getElementById('total-income').textContent = this.formatCurrency(summary.total_income);
        document.getElementById('total-expense').textContent = this.formatCurrency(summary.total_expense);
        document.getElementById('net-total').textContent = this.formatCurrency(summary.net_total);
        document.getElementById('savings-rate').textContent = summary.overall_savings_rate + '%';

        document.getElementById('avg-income').textContent = this.formatCurrency(summary.average_monthly_income);
        document.getElementById('avg-expense').textContent = this.formatCurrency(summary.average_monthly_expense);
        document.getElementById('avg-net').textContent = this.formatCurrency(summary.average_monthly_net);

        document.getElementById('surplus-months').textContent = summary.surplus_months;
        document.getElementById('deficit-months').textContent = summary.deficit_months;

        // Update card color based on net
        const netCard = document.querySelector('.net-card');
        if (summary.net_total > 0) {
            netCard.classList.replace('net-card', 'income-card');
        } else if (summary.net_total < 0) {
            netCard.classList.replace('net-card', 'expense-card');
        }

        // Hide skeleton, show real content
        const skeleton = document.getElementById('report-skeleton');
        if (skeleton) skeleton.style.display = 'none';
        const summaryCards = document.getElementById('summary-cards');
        if (summaryCards) summaryCards.style.display = '';
        const chartsSection = document.getElementById('charts-section');
        if (chartsSection) chartsSection.style.display = '';
        const insightsCard = document.getElementById('insights-card');
        if (insightsCard) insightsCard.style.display = '';
    },

    renderChart() {
        const ctx = document.getElementById('incomeExpenseChart').getContext('2d');

        if (this.chart) {
            this.chart.destroy();
        }

        const labels = this.monthlyData.map(d => d.month_name);
        const incomeData = this.monthlyData.map(d => d.total_income / 100);
        const expenseData = this.monthlyData.map(d => d.total_expense / 100);
        const netData = this.monthlyData.map(d => d.net / 100);

        this.chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Income',
                        data: incomeData,
                        backgroundColor: 'rgba(72, 187, 120, 0.8)',
                        borderColor: 'rgb(72, 187, 120)',
                        borderWidth: 2
                    },
                    {
                        label: 'Expenses',
                        data: expenseData,
                        backgroundColor: 'rgba(245, 101, 101, 0.8)',
                        borderColor: 'rgb(245, 101, 101)',
                        borderWidth: 2
                    },
                    {
                        label: 'Net',
                        data: netData,
                        type: 'line',
                        backgroundColor: 'rgba(66, 153, 225, 0.2)',
                        borderColor: 'rgb(66, 153, 225)',
                        borderWidth: 3,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                size: 14
                            },
                            padding: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.dataset.label || '';
                                const value = this.formatCurrency(context.parsed.y * 100);
                                if (context.datasetIndex === 2) {
                                    // Net line - show savings rate too
                                    const rate = this.monthlyData[context.dataIndex].savings_rate;
                                    return `${label}: ${value} (${rate}% savings rate)`;
                                }
                                return `${label}: ${value}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => {
                                return window.pgbFormatAmount(value);
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    },

    renderInsights() {
        if (!this.summary || this.monthlyData.length === 0) {
            document.getElementById('insights-content').innerHTML = '<p>Not enough data to generate insights.</p>';
            return;
        }

        const insights = [];

        // Savings rate insight
        if (this.summary.overall_savings_rate > 20) {
            insights.push(`<li class="insight-positive">🎉 Excellent savings rate of ${this.summary.overall_savings_rate}%! You're saving more than 20% of your income.</li>`);
        } else if (this.summary.overall_savings_rate > 10) {
            insights.push(`<li class="insight-neutral">👍 Good savings rate of ${this.summary.overall_savings_rate}%. Aim for 20% or more to build wealth faster.</li>`);
        } else if (this.summary.overall_savings_rate > 0) {
            insights.push(`<li class="insight-warning">⚠️ Your savings rate is ${this.summary.overall_savings_rate}%. Try to increase it to at least 10% of your income.</li>`);
        } else {
            insights.push(`<li class="insight-negative">🚨 You're spending more than you earn! Focus on reducing expenses or increasing income.</li>`);
        }

        // Surplus/deficit months insight
        if (this.summary.deficit_months > this.summary.surplus_months) {
            insights.push(`<li class="insight-warning">📉 You had more deficit months (${this.summary.deficit_months}) than surplus months (${this.summary.surplus_months}). Review your budget to identify areas to cut.</li>`);
        } else if (this.summary.surplus_months > 0) {
            insights.push(`<li class="insight-positive">📈 You had ${this.summary.surplus_months} surplus months! Keep up the good work.</li>`);
        }

        // Trend analysis
        if (this.monthlyData.length >= 3) {
            const recentMonths = this.monthlyData.slice(-3);
            const avgRecent = recentMonths.reduce((sum, m) => sum + parseInt(m.net), 0) / recentMonths.length;
            const olderMonths = this.monthlyData.slice(0, -3);
            const avgOlder = olderMonths.length > 0 ? olderMonths.reduce((sum, m) => sum + parseInt(m.net), 0) / olderMonths.length : 0;

            if (avgRecent > avgOlder && olderMonths.length > 0) {
                insights.push(`<li class="insight-positive">📊 Your finances are trending upward! Recent months show improvement.</li>`);
            } else if (avgRecent < avgOlder && olderMonths.length > 0) {
                insights.push(`<li class="insight-warning">📊 Recent months show declining savings. Review what changed.</li>`);
            }
        }

        // Average income vs expense
        const avgDiff = this.summary.average_monthly_income - this.summary.average_monthly_expense;
        if (avgDiff > 0) {
            insights.push(`<li class="insight-neutral">💵 On average, you save ${this.formatCurrency(avgDiff)} per month.</li>`);
        }

        // Render insights
        if (insights.length > 0) {
            document.getElementById('insights-content').innerHTML = '<ul>' + insights.join('') + '</ul>';
        } else {
            document.getElementById('insights-content').innerHTML = '<p>Track more data to get personalized insights.</p>';
        }
    },

    formatCurrency(cents) {
        return window.pgbFormatCurrency(cents);
    }
};

function setDateRange(period) {
    const form = document.querySelector('.date-filter-form');
    const today = new Date();
    let startDate, endDate = today;

    switch(period) {
        case 'last-3-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 3, 1);
            break;
        case 'last-6-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 6, 1);
            break;
        case 'last-12-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 12, 1);
            break;
        case 'ytd':
            startDate = new Date(today.getFullYear(), 0, 1);
            break;
        case 'all-time':
            startDate = new Date(2020, 0, 1); // Arbitrary old date
            break;
    }

    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
    document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
    form.submit();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    IncomeExpenseReport.init();
});
</script>

<!-- Include reports.css for consistent styling -->
<link rel="stylesheet" href="../css/reports.css">

<?php require_once '../../includes/footer.php'; ?>
