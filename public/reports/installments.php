<?php
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
            <h1>💳 Installment Report</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="installment-impact.php?ledger=<?= $ledger_uuid ?>" class="btn btn-primary">Budget Impact Report</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div id="summary-cards" class="summary-cards">
        <div class="summary-card installment-card">
            <div class="summary-label">Total Installment Debt</div>
            <div class="summary-value" id="total-debt">Loading...</div>
        </div>
        <div class="summary-card installment-card">
            <div class="summary-label">Monthly Obligations</div>
            <div class="summary-value" id="monthly-obligations">-</div>
        </div>
        <div class="summary-card installment-card">
            <div class="summary-label">Active Plans</div>
            <div class="summary-value" id="active-plans">-</div>
        </div>
        <div class="summary-card installment-card">
            <div class="summary-label">Average Plan Size</div>
            <div class="summary-value" id="average-plan">-</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-row">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Installment Debt Over Time</h3>
                </div>
                <div class="chart-container">
                    <canvas id="debtOverTimeChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Category Breakdown</h3>
                </div>
                <div class="chart-container">
                    <canvas id="categoryBreakdownChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Obligations Chart -->
    <div class="charts-section">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Monthly Installment Obligations (Next 12 Months)</h3>
            </div>
            <div class="chart-container">
                <canvas id="monthlyObligationsChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Completion Rate -->
    <div class="stat-card">
        <h3>Completion Statistics</h3>
        <div class="completion-stats">
            <div class="stat-item">
                <div class="stat-label">On-Time Completion Rate</div>
                <div class="stat-value" id="on-time-rate">-</div>
                <div class="stat-progress">
                    <div class="stat-progress-bar" id="on-time-bar" style="width: 0%"></div>
                </div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Total Installments Processed</div>
                <div class="stat-value" id="total-processed">-</div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Total Installments Scheduled</div>
                <div class="stat-value" id="total-scheduled">-</div>
            </div>
        </div>
    </div>

    <!-- Active Plans Table -->
    <div class="data-table-card">
        <div class="table-header">
            <h3>Active Installment Plans</h3>
            <a href="../installments/?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-small btn-primary">View All Plans</a>
        </div>
        <div id="plans-table" class="table-responsive">
            <p class="loading-text">Loading data...</p>
        </div>
    </div>

    <!-- Category Analysis Table -->
    <div class="data-table-card">
        <div class="table-header">
            <h3>Category Analysis</h3>
        </div>
        <div id="category-table" class="table-responsive">
            <p class="loading-text">Loading data...</p>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Custom JavaScript -->
<script>
const InstallmentReport = {
    ledgerUuid: '<?= addslashes($ledger_uuid) ?>',
    charts: {},
    data: {},

    async init() {
        await this.loadData();
        this.renderSummary();
        this.renderCharts();
        this.renderTables();
    },

    async loadData() {
        try {
            // Load all report data
            const resp = await fetch(`../api/installment-reports.php?ledger=${this.ledgerUuid}`);
            const result = await resp.json();

            if (!resp.ok) {
                console.error('API Error:', result);
                throw new Error(result.error || 'Failed to load report data');
            }

            if (result.success) {
                this.data = result.data;
            } else {
                throw new Error(result.error || 'Failed to load report data');
            }
        } catch (error) {
            console.error('Error loading data:', error);
            alert('Error loading report data: ' + error.message);
        }
    },

    renderSummary() {
        const summary = this.data.summary;
        document.getElementById('total-debt').textContent = this.formatCurrency(summary.total_remaining_debt);
        document.getElementById('monthly-obligations').textContent = this.formatCurrency(summary.monthly_obligations);
        document.getElementById('active-plans').textContent = summary.active_plan_count;
        document.getElementById('average-plan').textContent = this.formatCurrency(summary.average_plan_size);

        // Completion stats
        document.getElementById('on-time-rate').textContent = summary.on_time_rate + '%';
        document.getElementById('on-time-bar').style.width = summary.on_time_rate + '%';
        document.getElementById('total-processed').textContent = summary.total_processed;
        document.getElementById('total-scheduled').textContent = summary.total_scheduled;
    },

    renderCharts() {
        this.renderDebtOverTimeChart();
        this.renderCategoryBreakdownChart();
        this.renderMonthlyObligationsChart();
    },

    renderDebtOverTimeChart() {
        const ctx = document.getElementById('debtOverTimeChart').getContext('2d');
        const data = this.data.debt_over_time;

        this.charts.debtOverTime = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(d => d.month),
                datasets: [{
                    label: 'Total Remaining Debt',
                    data: data.map(d => d.total_remaining / 100),
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => this.formatCurrency(context.parsed.y * 100)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => '$' + value.toFixed(0)
                        }
                    }
                }
            }
        });
    },

    renderCategoryBreakdownChart() {
        const ctx = document.getElementById('categoryBreakdownChart').getContext('2d');
        const data = this.data.category_breakdown;

        this.charts.categoryBreakdown = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.category_name),
                datasets: [{
                    data: data.map(d => d.total_amount / 100),
                    backgroundColor: this.generateColors(data.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = this.formatCurrency(context.parsed * 100);
                                return `${label}: ${value}`;
                            }
                        }
                    }
                }
            }
        });
    },

    renderMonthlyObligationsChart() {
        const ctx = document.getElementById('monthlyObligationsChart').getContext('2d');
        const data = this.data.monthly_obligations;

        this.charts.monthlyObligations = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => d.month),
                datasets: [{
                    label: 'Monthly Installment Payments',
                    data: data.map(d => d.total_amount / 100),
                    backgroundColor: '#f59e0b',
                    borderColor: '#d97706',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => this.formatCurrency(context.parsed.y * 100)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => '$' + value.toFixed(0)
                        }
                    }
                }
            }
        });
    },

    renderTables() {
        this.renderPlansTable();
        this.renderCategoryTable();
    },

    renderPlansTable() {
        const plans = this.data.active_plans;

        if (plans.length === 0) {
            document.getElementById('plans-table').innerHTML = '<p class="empty-text">No active installment plans</p>';
            return;
        }

        const tableHtml = `
            <table class="table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Credit Card</th>
                        <th>Total Amount</th>
                        <th>Monthly Payment</th>
                        <th>Progress</th>
                        <th>Next Due</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${plans.map(plan => `
                        <tr>
                            <td><strong>${this.escapeHtml(plan.description)}</strong></td>
                            <td>${this.escapeHtml(plan.credit_card_name)}</td>
                            <td class="amount">${this.formatCurrency(plan.purchase_amount)}</td>
                            <td class="amount">${this.formatCurrency(plan.installment_amount)}</td>
                            <td>
                                <div class="progress-info">
                                    ${plan.completed_installments}/${plan.number_of_installments}
                                    <div class="mini-progress">
                                        <div class="mini-progress-bar" style="width: ${(plan.completed_installments / plan.number_of_installments * 100)}%"></div>
                                    </div>
                                </div>
                            </td>
                            <td>${plan.next_due_date ? new Date(plan.next_due_date).toLocaleDateString() : 'Completed'}</td>
                            <td>
                                <a href="../installments/view.php?plan=${plan.plan_uuid}&ledger=${this.ledgerUuid}" class="btn btn-small btn-secondary">View</a>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
        document.getElementById('plans-table').innerHTML = tableHtml;
    },

    renderCategoryTable() {
        const categories = this.data.category_breakdown;

        if (categories.length === 0) {
            document.getElementById('category-table').innerHTML = '<p class="empty-text">No category data</p>';
            return;
        }

        const tableHtml = `
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total Amount</th>
                        <th>Remaining Debt</th>
                        <th>Active Plans</th>
                        <th>Avg Plan Size</th>
                    </tr>
                </thead>
                <tbody>
                    ${categories.map(cat => `
                        <tr>
                            <td><strong>${this.escapeHtml(cat.category_name)}</strong></td>
                            <td class="amount">${this.formatCurrency(cat.total_amount)}</td>
                            <td class="amount warning">${this.formatCurrency(cat.remaining_debt)}</td>
                            <td>${cat.plan_count}</td>
                            <td class="amount">${this.formatCurrency(cat.average_plan_size)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
        document.getElementById('category-table').innerHTML = tableHtml;
    },

    generateColors(count) {
        const hueStep = 360 / count;
        return Array.from({length: count}, (_, i) =>
            `hsl(${i * hueStep}, 70%, 60%)`
        );
    },

    formatCurrency(cents) {
        if (cents === null || cents === undefined) return '$0.00';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    InstallmentReport.init();
});
</script>

<style>
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
    color: #2d3748;
}

.report-subtitle {
    color: #718096;
    margin: 0.5rem 0 0 0;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.summary-card.installment-card {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.summary-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: bold;
}

.charts-section {
    margin-bottom: 2rem;
}

.chart-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1rem;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.chart-header h3 {
    margin: 0;
    color: #2d3748;
}

.chart-container {
    height: 300px;
    position: relative;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.stat-card h3 {
    margin-top: 0;
    color: #2d3748;
}

.completion-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
}

.stat-item {
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
}

.stat-label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: bold;
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.stat-progress {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}

.stat-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    transition: width 0.3s ease;
}

.data-table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.table-header h3 {
    margin: 0;
    color: #2d3748;
}

.progress-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mini-progress {
    width: 100%;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
}

.mini-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #f59e0b, #d97706);
    transition: width 0.3s ease;
}

.loading-text, .empty-text {
    text-align: center;
    color: #718096;
    padding: 2rem;
}

.amount {
    font-weight: 600;
    color: #2d3748;
}

.amount.warning {
    color: #ed8936;
}

@media (max-width: 768px) {
    .report-header {
        flex-direction: column;
        gap: 1rem;
    }

    .chart-row {
        grid-template-columns: 1fr;
    }

    .chart-container {
        height: 250px;
    }

    .completion-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
