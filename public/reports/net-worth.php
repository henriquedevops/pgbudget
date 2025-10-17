<?php
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

    // Get date range from query params (default to last 12 months)
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months', strtotime($end_date)));

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
            <h1>📊 Net Worth Report</h1>
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
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('last-3-months')">Last 3 Months</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('last-6-months')">Last 6 Months</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('last-12-months')">Last 12 Months</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('ytd')">Year to Date</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('all-time')">All Time</button>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div id="summary-cards" class="summary-cards">
        <div class="summary-card networth-card">
            <div class="summary-label">Current Net Worth</div>
            <div class="summary-value" id="current-networth">Loading...</div>
            <div class="summary-sublabel">Change: <span id="total-change">-</span></div>
        </div>
        <div class="summary-card assets-card">
            <div class="summary-label">Total Assets</div>
            <div class="summary-value" id="total-assets">Loading...</div>
            <div class="summary-sublabel">Cash, Bank Accounts, etc.</div>
        </div>
        <div class="summary-card liabilities-card">
            <div class="summary-label">Total Liabilities</div>
            <div class="summary-value" id="total-liabilities">Loading...</div>
            <div class="summary-sublabel">Credit Cards, Loans, etc.</div>
        </div>
        <div class="summary-card growth-card">
            <div class="summary-label">Period Growth</div>
            <div class="summary-value" id="percent-change">Loading...</div>
            <div class="summary-sublabel">Avg: <span id="avg-monthly-change">-</span>/month</div>
        </div>
    </div>

    <!-- Chart Section -->
    <div class="charts-section">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Net Worth Over Time</h3>
                <div class="chart-controls">
                    <a href="../api/get-net-worth-report.php?action=csv&ledger=<?= urlencode($ledger_uuid) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                       class="btn btn-small btn-success">📥 Export CSV</a>
                </div>
            </div>
            <div class="chart-container" style="height: 500px;">
                <canvas id="netWorthChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Assets vs Liabilities Chart -->
    <div class="charts-section">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Assets vs Liabilities</h3>
            </div>
            <div class="chart-container" style="height: 400px;">
                <canvas id="assetsLiabilitiesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Milestones & Insights -->
    <div class="insights-card">
        <h3>💡 Insights & Milestones</h3>
        <div id="insights-content">
            <p class="loading-text">Analyzing your net worth data...</p>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Custom JavaScript -->
<script>
const NetWorthReport = {
    ledgerUuid: '<?= addslashes($ledger_uuid) ?>',
    startDate: '<?= addslashes($start_date) ?>',
    endDate: '<?= addslashes($end_date) ?>',
    netWorthChart: null,
    assetsLiabilitiesChart: null,
    monthlyData: [],
    summary: null,

    async init() {
        await this.loadData();
        this.renderNetWorthChart();
        this.renderAssetsLiabilitiesChart();
        this.renderInsights();
    },

    async loadData() {
        try {
            // Load summary
            const summaryResp = await fetch(`../api/get-net-worth-report.php?action=summary&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);

            if (!summaryResp.ok) {
                const errorText = await summaryResp.text();
                console.error('Summary API Error Response:', errorText);
                throw new Error(`HTTP ${summaryResp.status}: ${errorText.substring(0, 200)}`);
            }

            const summaryData = await summaryResp.json();

            if (summaryData.success && summaryData.summary) {
                this.summary = summaryData.summary;
                this.renderSummary(summaryData.summary);
            } else if (summaryData.error) {
                throw new Error('Summary: ' + summaryData.error);
            }

            // Load monthly data
            const monthlyResp = await fetch(`../api/get-net-worth-report.php?action=monthly&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);

            if (!monthlyResp.ok) {
                const errorText = await monthlyResp.text();
                console.error('Monthly API Error Response:', errorText);
                throw new Error(`HTTP ${monthlyResp.status}: ${errorText.substring(0, 200)}`);
            }

            const monthlyData = await monthlyResp.json();

            if (monthlyData.success) {
                this.monthlyData = monthlyData.data;
            } else if (monthlyData.error) {
                throw new Error('Monthly: ' + monthlyData.error);
            }
        } catch (error) {
            console.error('Error loading data:', error);
            alert('Error loading report data: ' + error.message);
        }
    },

    renderSummary(summary) {
        document.getElementById('current-networth').textContent = this.formatCurrency(summary.current_net_worth);
        document.getElementById('total-assets').textContent = this.formatCurrency(summary.current_assets);
        document.getElementById('total-liabilities').textContent = this.formatCurrency(summary.current_liabilities);

        const percentChange = summary.percent_change;
        const percentChangeEl = document.getElementById('percent-change');
        percentChangeEl.textContent = percentChange > 0 ? '+' + percentChange + '%' : percentChange + '%';

        document.getElementById('total-change').textContent = this.formatCurrency(summary.total_change);
        document.getElementById('avg-monthly-change').textContent = this.formatCurrency(summary.average_monthly_change);

        // Update card colors based on growth
        const growthCard = document.querySelector('.growth-card');
        if (percentChange > 0) {
            growthCard.style.background = 'linear-gradient(135deg, #38a169 0%, #48bb78 100%)';
        } else if (percentChange < 0) {
            growthCard.style.background = 'linear-gradient(135deg, #fc8181 0%, #f56565 100%)';
        }

        // Update net worth card color
        const networthCard = document.querySelector('.networth-card');
        if (summary.current_net_worth > 0) {
            networthCard.style.background = 'linear-gradient(135deg, #4299e1 0%, #3182ce 100%)';
        } else if (summary.current_net_worth < 0) {
            networthCard.style.background = 'linear-gradient(135deg, #fc8181 0%, #f56565 100%)';
        }
    },

    renderNetWorthChart() {
        const ctx = document.getElementById('netWorthChart').getContext('2d');

        if (this.netWorthChart) {
            this.netWorthChart.destroy();
        }

        const labels = this.monthlyData.map(d => d.month_name);
        const netWorthData = this.monthlyData.map(d => d.net_worth / 100);

        // Create gradient
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(66, 153, 225, 0.4)');
        gradient.addColorStop(1, 'rgba(66, 153, 225, 0.0)');

        this.netWorthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Net Worth',
                        data: netWorthData,
                        backgroundColor: gradient,
                        borderColor: 'rgb(66, 153, 225)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        pointBackgroundColor: 'rgb(66, 153, 225)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
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
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = this.formatCurrency(context.parsed.y * 100);
                                const dataPoint = this.monthlyData[context.dataIndex];
                                const change = dataPoint.change_from_previous;
                                const changeText = change !== 0
                                    ? ` (${change > 0 ? '+' : ''}${this.formatCurrency(change)} from previous month)`
                                    : '';
                                return `Net Worth: ${value}${changeText}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (value) => {
                                return '$' + value.toLocaleString();
                            },
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    },

    renderAssetsLiabilitiesChart() {
        const ctx = document.getElementById('assetsLiabilitiesChart').getContext('2d');

        if (this.assetsLiabilitiesChart) {
            this.assetsLiabilitiesChart.destroy();
        }

        const labels = this.monthlyData.map(d => d.month_name);
        const assetsData = this.monthlyData.map(d => d.total_assets / 100);
        const liabilitiesData = this.monthlyData.map(d => d.total_liabilities / 100);

        this.assetsLiabilitiesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Assets',
                        data: assetsData,
                        backgroundColor: 'rgba(72, 187, 120, 0.1)',
                        borderColor: 'rgb(72, 187, 120)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Liabilities',
                        data: liabilitiesData,
                        backgroundColor: 'rgba(245, 101, 101, 0.1)',
                        borderColor: 'rgb(245, 101, 101)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6
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
                                return '$' + value.toLocaleString();
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

        // Current net worth status
        if (this.summary.current_net_worth > 0) {
            insights.push(`<li class="insight-positive">✅ Your net worth is <strong>${this.formatCurrency(this.summary.current_net_worth)}</strong>. Keep building wealth!</li>`);
        } else if (this.summary.current_net_worth < 0) {
            insights.push(`<li class="insight-negative">⚠️ Your net worth is negative at <strong>${this.formatCurrency(this.summary.current_net_worth)}</strong>. Focus on paying down debt.</li>`);
        } else {
            insights.push(`<li class="insight-neutral">📊 Your net worth is currently at zero. Focus on saving and reducing liabilities.</li>`);
        }

        // Growth trend
        if (this.summary.percent_change > 10) {
            insights.push(`<li class="insight-positive">📈 Excellent growth! Your net worth increased by ${this.summary.percent_change}% over this period.</li>`);
        } else if (this.summary.percent_change > 0) {
            insights.push(`<li class="insight-neutral">👍 Your net worth grew by ${this.summary.percent_change}%. Keep up the momentum!</li>`);
        } else if (this.summary.percent_change < 0) {
            insights.push(`<li class="insight-warning">📉 Your net worth decreased by ${Math.abs(this.summary.percent_change)}%. Review your spending and debt.</li>`);
        }

        // Monthly change
        if (this.summary.average_monthly_change > 0) {
            insights.push(`<li class="insight-positive">💰 You're growing your net worth by an average of ${this.formatCurrency(this.summary.average_monthly_change)} per month!</li>`);
        } else if (this.summary.average_monthly_change < 0) {
            insights.push(`<li class="insight-warning">⚠️ Your net worth is declining by an average of ${this.formatCurrency(Math.abs(this.summary.average_monthly_change))} per month. Take action to reverse this trend.</li>`);
        }

        // Highest and lowest points
        if (this.summary.highest_net_worth !== this.summary.current_net_worth) {
            const highDate = new Date(this.summary.highest_net_worth_date).toLocaleDateString('en-US', {month: 'short', year: 'numeric'});
            insights.push(`<li class="insight-neutral">🎯 Your highest net worth was ${this.formatCurrency(this.summary.highest_net_worth)} in ${highDate}.</li>`);
        }

        // Debt ratio
        if (this.summary.current_liabilities > 0 && this.summary.current_assets > 0) {
            const debtRatio = (this.summary.current_liabilities / this.summary.current_assets * 100).toFixed(1);
            if (debtRatio < 30) {
                insights.push(`<li class="insight-positive">💪 Your debt ratio is ${debtRatio}% (low). You're managing debt well!</li>`);
            } else if (debtRatio < 50) {
                insights.push(`<li class="insight-neutral">📊 Your debt ratio is ${debtRatio}% (moderate). Consider paying down some debt.</li>`);
            } else {
                insights.push(`<li class="insight-warning">⚠️ Your debt ratio is ${debtRatio}% (high). Focus on reducing liabilities.</li>`);
            }
        }

        // Milestones
        const milestones = [];
        if (this.summary.current_net_worth >= 10000 && this.summary.current_net_worth < 100000) {
            milestones.push('Next milestone: $100,000 net worth');
        } else if (this.summary.current_net_worth >= 100000 && this.summary.current_net_worth < 250000) {
            milestones.push('Next milestone: $250,000 net worth');
        } else if (this.summary.current_net_worth >= 250000 && this.summary.current_net_worth < 500000) {
            milestones.push('Next milestone: $500,000 net worth');
        } else if (this.summary.current_net_worth >= 500000 && this.summary.current_net_worth < 1000000) {
            milestones.push('Next milestone: $1,000,000 net worth');
        }

        if (milestones.length > 0) {
            insights.push(`<li class="insight-neutral">🎯 ${milestones.join(', ')}</li>`);
        }

        // Render insights
        if (insights.length > 0) {
            document.getElementById('insights-content').innerHTML = '<ul>' + insights.join('') + '</ul>';
        } else {
            document.getElementById('insights-content').innerHTML = '<p>Track more data to get personalized insights.</p>';
        }
    },

    formatCurrency(cents) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
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
    NetWorthReport.init();
});
</script>

<!-- Include reports.css for consistent styling -->
<link rel="stylesheet" href="../css/reports.css">

<style>
.networth-card {
    background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
}

.assets-card {
    background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
}

.liabilities-card {
    background: linear-gradient(135deg, #fc8181 0%, #f56565 100%);
}

.growth-card {
    background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
}

.summary-sublabel {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-top: 0.5rem;
}

.insights-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-top: 2rem;
}

.insights-card h3 {
    margin-top: 0;
}

.insights-card ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.insights-card li {
    padding: 1rem;
    margin-bottom: 0.75rem;
    border-radius: 8px;
    line-height: 1.6;
}

.insight-positive {
    background-color: #f0fff4;
    border-left: 4px solid #48bb78;
    color: #22543d;
}

.insight-negative {
    background-color: #fff5f5;
    border-left: 4px solid #fc8181;
    color: #742a2a;
}

.insight-warning {
    background-color: #fffbeb;
    border-left: 4px solid #f6ad55;
    color: #744210;
}

.insight-neutral {
    background-color: #ebf8ff;
    border-left: 4px solid #4299e1;
    color: #2c5282;
}
</style>

<?php require_once '../../includes/footer.php'; ?>
