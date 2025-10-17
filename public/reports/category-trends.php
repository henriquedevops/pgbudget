<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Require authentication
requireAuth();

// Get ledger UUID from query parameter
$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    header('Location: /budget/dashboard.php');
    exit;
}

// Get database connection
$db = getDbConnection();

// Set user context
$stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
$stmt->execute([$_SESSION['user_id']]);

// Get ledger name
$stmt = $db->prepare("SELECT name FROM data.ledgers WHERE uuid = ? AND user_data = current_setting('app.current_user_id')");
$stmt->execute([$ledger_uuid]);
$ledger = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ledger) {
    header('Location: /budget/dashboard.php');
    exit;
}

// Get all budget categories (equity accounts)
$stmt = $db->prepare("
    SELECT uuid, name
    FROM data.accounts
    WHERE ledger_id = (SELECT id FROM data.ledgers WHERE uuid = ? AND user_data = current_setting('app.current_user_id'))
      AND type = 'equity'
      AND user_data = current_setting('app.current_user_id')
    ORDER BY name
");
$stmt->execute([$ledger_uuid]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Spending Trends - <?= htmlspecialchars($ledger['name']) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="report-header">
            <div class="report-header-top">
                <h1>Category Spending Trends</h1>
                <div class="report-actions">
                    <a href="/budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>

        <!-- Category Selection -->
        <div class="card">
            <div class="card-header">
                <h2>Select Category</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="category-select">Category:</label>
                    <select id="category-select" class="form-control">
                        <option value="">-- Select a category --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['uuid']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="months-select">Time Period:</label>
                    <select id="months-select" class="form-control">
                        <option value="3">Last 3 months</option>
                        <option value="6">Last 6 months</option>
                        <option value="12" selected>Last 12 months</option>
                        <option value="24">Last 24 months</option>
                        <option value="36">Last 36 months</option>
                    </select>
                </div>

                <button id="load-trend-btn" class="btn btn-primary" disabled>Load Trend</button>
                <button id="export-csv-btn" class="btn btn-secondary" style="display: none;">Export CSV</button>
            </div>
        </div>

        <!-- Loading Indicator -->
        <div id="loading-indicator" class="loading" style="display: none;">
            <p>Loading trend data...</p>
        </div>

        <!-- Error Display -->
        <div id="error-display" class="alert alert-error" style="display: none;"></div>

        <!-- Results Section (hidden until data loaded) -->
        <div id="results-section" style="display: none;">
            <!-- Statistics Summary -->
            <div class="summary-cards" id="summary-section">
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Average Spending</div>
                        <div class="summary-value" id="avg-spending">$0</div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Average Budgeted</div>
                        <div class="summary-value" id="avg-budgeted">$0</div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Total Spending</div>
                        <div class="summary-value" id="total-spending">$0</div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Trend Direction</div>
                        <div class="summary-value" id="trend-direction">--</div>
                    </div>
                </div>
            </div>

            <!-- Spending Trend Chart -->
            <div class="card">
                <div class="card-header">
                    <h2>Spending vs Budgeted Over Time</h2>
                </div>
                <div class="card-body">
                    <canvas id="trend-chart"></canvas>
                </div>
            </div>

            <!-- Insights -->
            <div class="card">
                <div class="card-header">
                    <h2>Insights</h2>
                </div>
                <div class="card-body">
                    <div id="insights-section"></div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="card">
                <div class="card-header">
                    <h2>Monthly Data</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-right">Actual Spending</th>
                                    <th class="text-right">Budgeted Amount</th>
                                    <th class="text-right">Difference</th>
                                    <th class="text-right">% of Budget</th>
                                </tr>
                            </thead>
                            <tbody id="data-table-body">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CategoryTrendReport = {
            ledgerUuid: '<?= $ledger_uuid ?>',
            categoryUuid: null,
            months: 12,
            trendData: [],
            statistics: null,
            chart: null,

            init() {
                this.attachEventListeners();
            },

            attachEventListeners() {
                const categorySelect = document.getElementById('category-select');
                const monthsSelect = document.getElementById('months-select');
                const loadBtn = document.getElementById('load-trend-btn');
                const exportBtn = document.getElementById('export-csv-btn');

                categorySelect.addEventListener('change', (e) => {
                    this.categoryUuid = e.target.value;
                    loadBtn.disabled = !this.categoryUuid;
                });

                monthsSelect.addEventListener('change', (e) => {
                    this.months = parseInt(e.target.value);
                });

                loadBtn.addEventListener('click', () => this.loadData());
                exportBtn.addEventListener('click', () => this.exportCSV());
            },

            async loadData() {
                if (!this.categoryUuid) return;

                const loadingIndicator = document.getElementById('loading-indicator');
                const errorDisplay = document.getElementById('error-display');
                const resultsSection = document.getElementById('results-section');
                const exportBtn = document.getElementById('export-csv-btn');

                loadingIndicator.style.display = 'block';
                errorDisplay.style.display = 'none';
                resultsSection.style.display = 'none';

                try {
                    // Fetch trend data and statistics in parallel
                    const [trendResponse, statsResponse] = await Promise.all([
                        fetch(`/api/get-category-trends.php?action=trend&category=${this.categoryUuid}&months=${this.months}`),
                        fetch(`/api/get-category-trends.php?action=statistics&category=${this.categoryUuid}&months=${this.months}`)
                    ]);

                    if (!trendResponse.ok || !statsResponse.ok) {
                        const errorText = !trendResponse.ok ? await trendResponse.text() : await statsResponse.text();
                        throw new Error(`HTTP ${!trendResponse.ok ? trendResponse.status : statsResponse.status}: ${errorText}`);
                    }

                    const trendResult = await trendResponse.json();
                    const statsResult = await statsResponse.json();

                    if (!trendResult.success || !statsResult.success) {
                        throw new Error(trendResult.error || statsResult.error || 'Unknown error occurred');
                    }

                    this.trendData = trendResult.data;
                    this.statistics = statsResult.statistics;

                    this.renderSummary();
                    this.renderChart();
                    this.renderInsights();
                    this.renderDataTable();

                    resultsSection.style.display = 'block';
                    exportBtn.style.display = 'inline-block';

                } catch (error) {
                    console.error('Error loading trend data:', error);
                    errorDisplay.textContent = 'Error loading trend data: ' + error.message;
                    errorDisplay.style.display = 'block';
                } finally {
                    loadingIndicator.style.display = 'none';
                }
            },

            renderSummary() {
                const stats = this.statistics;

                document.getElementById('avg-spending').textContent = this.formatCurrency(stats.average_spending);
                document.getElementById('avg-budgeted').textContent = this.formatCurrency(stats.average_budgeted);
                document.getElementById('total-spending').textContent = this.formatCurrency(stats.total_spending);

                const trendEl = document.getElementById('trend-direction');
                const trendText = stats.trend_direction.charAt(0).toUpperCase() + stats.trend_direction.slice(1);
                trendEl.textContent = trendText;
                trendEl.className = 'summary-value trend-' + stats.trend_direction;
            },

            renderChart() {
                const ctx = document.getElementById('trend-chart');

                // Destroy existing chart if it exists
                if (this.chart) {
                    this.chart.destroy();
                }

                const labels = this.trendData.map(d => d.month_name);
                const actualData = this.trendData.map(d => d.actual_spending / 100);
                const budgetedData = this.trendData.map(d => d.budgeted_amount / 100);

                this.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Actual Spending',
                                data: actualData,
                                borderColor: 'rgb(220, 38, 38)',
                                backgroundColor: 'rgba(220, 38, 38, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: 'Budgeted Amount',
                                data: budgetedData,
                                borderColor: 'rgb(59, 130, 246)',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3,
                                borderDash: [5, 5]
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
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
                                        return '$' + value.toFixed(2);
                                    }
                                }
                            }
                        }
                    }
                });
            },

            renderInsights() {
                const stats = this.statistics;
                const insights = [];

                // Trend insight
                if (stats.trend_direction === 'increasing') {
                    insights.push(`📈 Your spending in this category is <strong>increasing</strong> over time. Consider reviewing if this aligns with your budget goals.`);
                } else if (stats.trend_direction === 'decreasing') {
                    insights.push(`📉 Your spending in this category is <strong>decreasing</strong> over time. Great job reducing expenses!`);
                } else {
                    insights.push(`➡️ Your spending in this category has remained <strong>stable</strong> over time.`);
                }

                // Budget performance
                const avgSpending = stats.average_spending / 100;
                const avgBudgeted = stats.average_budgeted / 100;
                if (avgSpending > avgBudgeted * 1.1) {
                    insights.push(`⚠️ On average, you're spending <strong>${((avgSpending / avgBudgeted * 100) - 100).toFixed(1)}% more</strong> than budgeted. Consider increasing your budget or reducing spending.`);
                } else if (avgSpending < avgBudgeted * 0.9) {
                    insights.push(`✅ On average, you're spending <strong>${(100 - (avgSpending / avgBudgeted * 100)).toFixed(1)}% less</strong> than budgeted. You might consider reallocating these funds.`);
                } else {
                    insights.push(`🎯 Your spending is closely aligned with your budget on average.`);
                }

                // Over/under budget months
                if (stats.months_over_budget > 0) {
                    insights.push(`📊 You were over budget in <strong>${stats.months_over_budget} of ${stats.months_count}</strong> months analyzed.`);
                }
                if (stats.months_under_budget > 0) {
                    insights.push(`💰 You were under budget in <strong>${stats.months_under_budget} of ${stats.months_count}</strong> months analyzed.`);
                }

                // Highest/lowest spending months
                insights.push(`📌 Highest spending was <strong>${this.formatCurrency(stats.max_spending)}</strong> in ${stats.max_month}.`);
                insights.push(`📌 Lowest spending was <strong>${this.formatCurrency(stats.min_spending)}</strong> in ${stats.min_month}.`);

                const insightsHtml = insights.map(insight => `<p class="insight-item">${insight}</p>`).join('');
                document.getElementById('insights-section').innerHTML = insightsHtml;
            },

            renderDataTable() {
                const tbody = document.getElementById('data-table-body');
                tbody.innerHTML = '';

                this.trendData.forEach(row => {
                    const tr = document.createElement('tr');
                    const isOverBudget = row.actual_spending > row.budgeted_amount;

                    tr.innerHTML = `
                        <td>${row.month_name}</td>
                        <td class="text-right">${this.formatCurrency(row.actual_spending)}</td>
                        <td class="text-right">${this.formatCurrency(row.budgeted_amount)}</td>
                        <td class="text-right ${isOverBudget ? 'text-danger' : 'text-success'}">
                            ${row.difference >= 0 ? '+' : ''}${this.formatCurrency(row.difference)}
                        </td>
                        <td class="text-right ${isOverBudget ? 'text-danger' : ''}">${row.percent_of_budget}%</td>
                    `;
                    tbody.appendChild(tr);
                });
            },

            exportCSV() {
                if (!this.categoryUuid) return;
                window.location.href = `/api/get-category-trends.php?action=csv&category=${this.categoryUuid}&months=${this.months}`;
            },

            formatCurrency(cents) {
                const dollars = cents / 100;
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: 'USD'
                }).format(dollars);
            }
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            CategoryTrendReport.init();
        });
    </script>

    <style>
        .trend-increasing {
            color: #dc2626;
        }
        .trend-decreasing {
            color: #16a34a;
        }
        .trend-stable {
            color: #6b7280;
        }
        .insight-item {
            padding: 10px;
            margin: 5px 0;
            background: #f9fafb;
            border-left: 3px solid #3b82f6;
            border-radius: 3px;
        }
        .text-danger {
            color: #dc2626;
        }
        .text-success {
            color: #16a34a;
        }
        .text-right {
            text-align: right;
        }
        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1.1em;
            color: #6b7280;
        }
    </style>
</body>
</html>
