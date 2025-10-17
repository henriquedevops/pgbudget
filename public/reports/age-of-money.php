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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Age of Money - <?= htmlspecialchars($ledger['name']) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/reports.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="report-header">
            <div class="report-header-top">
                <h1>Age of Money</h1>
                <div class="report-actions">
                    <a href="/budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>

        <!-- What is Age of Money? -->
        <div class="card info-card">
            <div class="card-header">
                <h2>💡 What is Age of Money?</h2>
            </div>
            <div class="card-body">
                <p><strong>Age of Money (AOM)</strong> measures the average number of days between receiving money and spending it. It shows how long money "sits" in your budget before being spent.</p>

                <div class="aom-explanation">
                    <div class="aom-example">
                        <div class="aom-example-icon">📈</div>
                        <div class="aom-example-content">
                            <h4>Higher AOM = Better Financial Health</h4>
                            <p>An AOM of 30+ days means you're living on last month's income, creating a strong financial buffer.</p>
                        </div>
                    </div>

                    <div class="aom-goals">
                        <h4>Age of Money Goals:</h4>
                        <ul>
                            <li><strong>30+ days:</strong> Excellent! You're living on last month's income</li>
                            <li><strong>20-29 days:</strong> Good progress! Keep building your buffer</li>
                            <li><strong>10-19 days:</strong> Fair - Some buffer, but room to improve</li>
                            <li><strong>0-9 days:</strong> Needs improvement - Focus on building a buffer</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Indicator -->
        <div id="loading-indicator" class="loading" style="display: none;">
            <p>Calculating Age of Money...</p>
        </div>

        <!-- Error Display -->
        <div id="error-display" class="alert alert-error" style="display: none;"></div>

        <!-- Current Age of Money Display -->
        <div id="current-aom-section" style="display: none;">
            <div class="aom-current-card">
                <div class="aom-current-header">
                    <h2>Current Age of Money</h2>
                    <div class="aom-date" id="calculation-date"></div>
                </div>
                <div class="aom-current-body">
                    <div class="aom-value-container">
                        <div class="aom-value" id="aom-value">0</div>
                        <div class="aom-label">days</div>
                    </div>
                    <div class="aom-status" id="aom-status">
                        <div class="status-badge" id="status-badge"></div>
                        <div class="status-message" id="status-message"></div>
                    </div>
                </div>
                <div class="aom-current-footer">
                    <div class="aom-stat">
                        <span class="stat-label">Transactions Analyzed</span>
                        <span class="stat-value" id="transaction-count">0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Time Period Selection -->
        <div class="card" id="trend-controls" style="display: none;">
            <div class="card-body">
                <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <label for="days-select"><strong>Show Trend:</strong></label>
                    <select id="days-select" class="form-control" style="max-width: 200px;">
                        <option value="30">Last 30 days</option>
                        <option value="60">Last 60 days</option>
                        <option value="90" selected>Last 90 days</option>
                        <option value="180">Last 6 months</option>
                        <option value="365">Last year</option>
                    </select>
                    <button id="refresh-btn" class="btn btn-primary">Refresh</button>
                    <button id="export-csv-btn" class="btn btn-secondary">Export CSV</button>
                </div>
            </div>
        </div>

        <!-- Age of Money Trend Chart -->
        <div class="card" id="trend-section" style="display: none;">
            <div class="card-header">
                <h2>Age of Money Trend</h2>
            </div>
            <div class="card-body">
                <canvas id="aom-trend-chart"></canvas>
            </div>
        </div>

        <!-- Insights -->
        <div class="card" id="insights-section" style="display: none;">
            <div class="card-header">
                <h2>Insights & Tips</h2>
            </div>
            <div class="card-body">
                <div id="insights-content"></div>
            </div>
        </div>
    </div>

    <script>
        const AgeOfMoneyReport = {
            ledgerUuid: '<?= $ledger_uuid ?>',
            days: 90,
            currentData: null,
            trendData: [],
            chart: null,

            init() {
                this.attachEventListeners();
                this.loadData();
            },

            attachEventListeners() {
                const daysSelect = document.getElementById('days-select');
                const refreshBtn = document.getElementById('refresh-btn');
                const exportBtn = document.getElementById('export-csv-btn');

                daysSelect.addEventListener('change', (e) => {
                    this.days = parseInt(e.target.value);
                });

                refreshBtn.addEventListener('click', () => this.loadData());
                exportBtn.addEventListener('click', () => this.exportCSV());
            },

            async loadData() {
                const loadingIndicator = document.getElementById('loading-indicator');
                const errorDisplay = document.getElementById('error-display');

                loadingIndicator.style.display = 'block';
                errorDisplay.style.display = 'none';

                try {
                    // Fetch current AOM and trend data in parallel
                    const [currentResponse, trendResponse] = await Promise.all([
                        fetch(`../api/get-age-of-money.php?action=current&ledger=${this.ledgerUuid}`),
                        fetch(`../api/get-age-of-money.php?action=trend&ledger=${this.ledgerUuid}&days=${this.days}`)
                    ]);

                    if (!currentResponse.ok || !trendResponse.ok) {
                        const errorText = !currentResponse.ok ? await currentResponse.text() : await trendResponse.text();
                        throw new Error(`HTTP ${!currentResponse.ok ? currentResponse.status : trendResponse.status}: ${errorText}`);
                    }

                    const currentResult = await currentResponse.json();
                    const trendResult = await trendResponse.json();

                    if (!currentResult.success || !trendResult.success) {
                        throw new Error(currentResult.error || trendResult.error || 'Unknown error occurred');
                    }

                    this.currentData = currentResult.current;
                    this.trendData = trendResult.data;

                    this.renderCurrent();
                    this.renderTrend();
                    this.renderInsights();

                    // Show sections
                    document.getElementById('current-aom-section').style.display = 'block';
                    document.getElementById('trend-controls').style.display = 'block';
                    document.getElementById('trend-section').style.display = 'block';
                    document.getElementById('insights-section').style.display = 'block';

                } catch (error) {
                    console.error('Error loading Age of Money data:', error);
                    errorDisplay.textContent = 'Error loading Age of Money data: ' + error.message;
                    errorDisplay.style.display = 'block';
                } finally {
                    loadingIndicator.style.display = 'none';
                }
            },

            renderCurrent() {
                const data = this.currentData;

                document.getElementById('aom-value').textContent = data.age_days;
                document.getElementById('calculation-date').textContent = 'As of ' + this.formatDate(data.calculation_date);
                document.getElementById('transaction-count').textContent = data.transaction_count.toLocaleString();

                const statusBadge = document.getElementById('status-badge');
                statusBadge.textContent = this.getStatusText(data.status);
                statusBadge.className = 'status-badge status-' + data.status;

                document.getElementById('status-message').textContent = data.status_message;
            },

            renderTrend() {
                const ctx = document.getElementById('aom-trend-chart');

                // Destroy existing chart if it exists
                if (this.chart) {
                    this.chart.destroy();
                }

                const labels = this.trendData.map(d => this.formatDate(d.calculation_date));
                const aomValues = this.trendData.map(d => d.age_days);

                // Create gradient
                const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
                gradient.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
                gradient.addColorStop(1, 'rgba(59, 130, 246, 0.05)');

                this.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Age of Money (Days)',
                                data: aomValues,
                                borderColor: 'rgb(59, 130, 246)',
                                backgroundColor: gradient,
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 2,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Goal (30 days)',
                                data: new Array(labels.length).fill(30),
                                borderColor: 'rgba(34, 197, 94, 0.5)',
                                borderWidth: 2,
                                borderDash: [10, 5],
                                fill: false,
                                pointRadius: 0
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
                                        if (context.datasetIndex === 0) {
                                            return 'Age of Money: ' + context.parsed.y + ' days';
                                        }
                                        return context.dataset.label;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Days'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + ' days';
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    maxTicksLimit: 10
                                }
                            }
                        }
                    }
                });
            },

            renderInsights() {
                const data = this.currentData;
                const insights = [];

                // Current status insight
                if (data.age_days >= 30) {
                    insights.push(`🎉 <strong>Excellent!</strong> Your Age of Money is ${data.age_days} days. You're successfully living on last month's income, which provides a strong financial buffer against unexpected expenses.`);
                } else if (data.age_days >= 20) {
                    insights.push(`📈 <strong>Good progress!</strong> Your Age of Money is ${data.age_days} days. You're ${30 - data.age_days} days away from the 30-day goal. Keep building your buffer!`);
                } else if (data.age_days >= 10) {
                    insights.push(`💪 <strong>You're building momentum!</strong> At ${data.age_days} days, you have some buffer. Focus on spending less than you earn to increase this further.`);
                } else {
                    insights.push(`🎯 <strong>Opportunity for improvement.</strong> Your Age of Money is ${data.age_days} days. Focus on building a financial buffer by reducing expenses and increasing savings.`);
                }

                // Trend analysis
                if (this.trendData.length >= 7) {
                    const recent = this.trendData.slice(-7).map(d => d.age_days);
                    const older = this.trendData.slice(0, 7).map(d => d.age_days);
                    const recentAvg = recent.reduce((a, b) => a + b, 0) / recent.length;
                    const olderAvg = older.reduce((a, b) => a + b, 0) / older.length;

                    if (recentAvg > olderAvg * 1.1) {
                        insights.push(`📊 <strong>Positive trend!</strong> Your Age of Money has been increasing recently. Keep up the great work!`);
                    } else if (recentAvg < olderAvg * 0.9) {
                        insights.push(`📊 <strong>Declining trend.</strong> Your Age of Money has decreased recently. Review your spending to identify areas where you can cut back.`);
                    } else {
                        insights.push(`📊 Your Age of Money has remained relatively stable over the selected period.`);
                    }
                }

                // Tips for improvement
                insights.push(`<strong>💡 Tips to increase Age of Money:</strong>
                    <ul>
                        <li>Spend less than you earn each month</li>
                        <li>Build an emergency fund to avoid dipping into current income</li>
                        <li>Delay non-essential purchases to let money "age"</li>
                        <li>Set aside money for future expenses in advance</li>
                        <li>Track your progress and celebrate milestones</li>
                    </ul>`);

                const insightsHtml = insights.map(insight => `<div class="insight-item">${insight}</div>`).join('');
                document.getElementById('insights-content').innerHTML = insightsHtml;
            },

            exportCSV() {
                window.location.href = `../api/get-age-of-money.php?action=csv&ledger=${this.ledgerUuid}&days=${this.days}`;
            },

            getStatusText(status) {
                const statusMap = {
                    'excellent': 'Excellent',
                    'good': 'Good',
                    'fair': 'Fair',
                    'needs_improvement': 'Needs Improvement'
                };
                return statusMap[status] || status;
            },

            formatDate(dateString) {
                const date = new Date(dateString + 'T00:00:00');
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
        };

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            AgeOfMoneyReport.init();
        });
    </script>

    <style>
        .info-card .card-body {
            line-height: 1.6;
        }

        .aom-explanation {
            margin-top: 1.5rem;
        }

        .aom-example {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f0f9ff;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .aom-example-icon {
            font-size: 3rem;
            flex-shrink: 0;
        }

        .aom-example-content h4 {
            margin: 0 0 0.5rem 0;
            color: #1e40af;
        }

        .aom-example-content p {
            margin: 0;
            color: #1e3a8a;
        }

        .aom-goals {
            background: #fefce8;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #eab308;
        }

        .aom-goals h4 {
            margin: 0 0 0.75rem 0;
            color: #854d0e;
        }

        .aom-goals ul {
            margin: 0;
            padding-left: 1.5rem;
        }

        .aom-goals li {
            margin-bottom: 0.5rem;
            color: #713f12;
        }

        .aom-current-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
            margin-bottom: 2rem;
        }

        .aom-current-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .aom-current-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .aom-date {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .aom-current-body {
            display: flex;
            gap: 3rem;
            align-items: center;
            margin-bottom: 2rem;
        }

        .aom-value-container {
            text-align: center;
        }

        .aom-value {
            font-size: 5rem;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .aom-label {
            font-size: 1.25rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .aom-status {
            flex: 1;
        }

        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            background: rgba(255, 255, 255, 0.2);
        }

        .status-badge.status-excellent {
            background: rgba(34, 197, 94, 0.3);
            border: 2px solid rgba(34, 197, 94, 0.6);
        }

        .status-badge.status-good {
            background: rgba(59, 130, 246, 0.3);
            border: 2px solid rgba(59, 130, 246, 0.6);
        }

        .status-badge.status-fair {
            background: rgba(251, 191, 36, 0.3);
            border: 2px solid rgba(251, 191, 36, 0.6);
        }

        .status-badge.status-needs_improvement {
            background: rgba(239, 68, 68, 0.3);
            border: 2px solid rgba(239, 68, 68, 0.6);
        }

        .status-message {
            font-size: 1.125rem;
            line-height: 1.6;
        }

        .aom-current-footer {
            display: flex;
            gap: 2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .aom-stat {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .aom-stat .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        .aom-stat .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .insight-item {
            padding: 1rem;
            margin: 0.75rem 0;
            background: #f9fafb;
            border-left: 3px solid #3b82f6;
            border-radius: 4px;
            line-height: 1.6;
        }

        .insight-item ul {
            margin: 0.5rem 0;
            padding-left: 1.5rem;
        }

        .insight-item li {
            margin-bottom: 0.25rem;
        }

        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1.1em;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .aom-current-body {
                flex-direction: column;
                gap: 1.5rem;
            }

            .aom-value {
                font-size: 4rem;
            }

            .aom-current-footer {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</body>
</html>
