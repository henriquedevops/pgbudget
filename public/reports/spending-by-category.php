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

    // Get date range from query params (default to current month)
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');

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
            <h1>📊 Spending by Category</h1>
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
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('this-month')">This Month</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('last-month')">Last Month</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('last-3-months')">Last 3 Months</button>
                <button type="button" class="btn btn-small btn-secondary" onclick="setDateRange('ytd')">Year to Date</button>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div id="summary-cards" class="summary-cards">
        <div class="summary-card">
            <div class="summary-label">Total Spending</div>
            <div class="summary-value" id="total-spending">Loading...</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Categories</div>
            <div class="summary-value" id="category-count">-</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Transactions</div>
            <div class="summary-value" id="transaction-count">-</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Largest Category</div>
            <div class="summary-value" id="largest-category">-</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Spending Breakdown</h3>
                <div class="chart-controls">
                    <button class="btn btn-small" id="toggle-chart-type">
                        <span id="chart-type-icon">📊</span> <span id="chart-type-label">Switch to Bar Chart</span>
                    </button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="spendingChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="data-table-card">
        <div class="table-header">
            <h3>Spending Details</h3>
            <a href="../api/get-spending-report.php?action=csv&ledger=<?= urlencode($ledger_uuid) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
               class="btn btn-small btn-success">📥 Export CSV</a>
        </div>
        <div id="spending-table" class="table-responsive">
            <p class="loading-text">Loading data...</p>
        </div>
    </div>
</div>

<!-- Drill-down Modal -->
<div id="transactionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Transactions: <span id="modal-category-name"></span></h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modal-transactions" class="transactions-list">
                <p class="loading-text">Loading transactions...</p>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- Custom JavaScript -->
<script>
const SpendingReport = {
    ledgerUuid: '<?= addslashes($ledger_uuid) ?>',
    startDate: '<?= addslashes($start_date) ?>',
    endDate: '<?= addslashes($end_date) ?>',
    chart: null,
    chartType: 'doughnut',
    spendingData: [],

    async init() {
        await this.loadData();
        this.renderChart();
        this.renderTable();
    },

    async loadData() {
        try {
            // Load summary
            const summaryResp = await fetch(`../api/get-spending-report.php?action=summary&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);
            const summaryData = await summaryResp.json();

            if (summaryData.success && summaryData.summary) {
                this.renderSummary(summaryData.summary);
            }

            // Load spending data
            const spendingResp = await fetch(`../api/get-spending-report.php?action=spending&ledger=${this.ledgerUuid}&start_date=${this.startDate}&end_date=${this.endDate}`);
            const spendingData = await spendingResp.json();
            
            if (spendingData.success) {
                this.spendingData = spendingData.data;
            }
        } catch (error) {
            console.error('Error loading data:', error);
            alert('Error loading report data');
        }
    },

    renderSummary(summary) {
        document.getElementById('total-spending').textContent = this.formatCurrency(summary.total_spending);
        document.getElementById('category-count').textContent = summary.category_count;
        document.getElementById('transaction-count').textContent = summary.transaction_count;
        document.getElementById('largest-category').textContent = summary.largest_category_name || '-';
    },

    renderChart() {
        const ctx = document.getElementById('spendingChart').getContext('2d');
        
        if (this.chart) {
            this.chart.destroy();
        }

        const labels = this.spendingData.map(d => d.category_name);
        const data = this.spendingData.map(d => d.total_spent / 100);
        const colors = this.generateColors(this.spendingData.length);

        this.chart = new Chart(ctx, {
            type: this.chartType,
            data: {
                labels: labels,
                datasets: [{
                    label: 'Spending',
                    data: data,
                    backgroundColor: colors,
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
                                const percent = this.spendingData[context.dataIndex].percentage;
                                return `${label}: ${value} (${percent}%)`;
                            }
                        }
                    }
                },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const category = this.spendingData[index];
                        this.showTransactions(category);
                    }
                }
            }
        });
    },

    renderTable() {
        const tableHtml = `
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total Spent</th>
                        <th>Transactions</th>
                        <th>% of Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${this.spendingData.map(row => `
                        <tr>
                            <td><strong>${row.category_name}</strong></td>
                            <td class="amount negative">${this.formatCurrency(row.total_spent)}</td>
                            <td>${row.transaction_count}</td>
                            <td>${row.percentage}%</td>
                            <td>
                                <button class="btn btn-small btn-secondary" onclick="SpendingReport.showTransactions(${JSON.stringify(row).replace(/"/g, '&quot;')})">
                                    View Transactions
                                </button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
        document.getElementById('spending-table').innerHTML = tableHtml;
    },

    async showTransactions(category) {
        document.getElementById('modal-category-name').textContent = category.category_name;
        document.getElementById('transactionModal').classList.add('active');
        document.getElementById('modal-transactions').innerHTML = '<p class="loading-text">Loading transactions...</p>';

        try {
            const resp = await fetch(`../api/get-spending-report.php?action=transactions&category=${category.category_uuid}&start_date=${this.startDate}&end_date=${this.endDate}`);
            const data = await resp.json();

            if (!resp.ok) {
                console.error('API Error:', data);
                throw new Error(data.error || 'Failed to load transactions');
            }

            if (data.success) {
                const html = `
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Account</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.transactions.map(t => `
                                <tr>
                                    <td>${new Date(t.transaction_date).toLocaleDateString()}</td>
                                    <td>${t.description}</td>
                                    <td>${t.other_account_name}</td>
                                    <td class="amount negative">${this.formatCurrency(t.amount)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
                document.getElementById('modal-transactions').innerHTML = html;
            }
        } catch (error) {
            console.error('Error loading transactions:', error);
            document.getElementById('modal-transactions').innerHTML = '<p class="error-text">Error loading transactions</p>';
        }
    },

    toggleChartType() {
        this.chartType = this.chartType === 'doughnut' ? 'bar' : 'doughnut';
        document.getElementById('chart-type-label').textContent = 
            this.chartType === 'doughnut' ? 'Switch to Bar Chart' : 'Switch to Donut Chart';
        document.getElementById('chart-type-icon').textContent = 
            this.chartType === 'doughnut' ? '📊' : '🍩';
        this.renderChart();
    },

    generateColors(count) {
        const hueStep = 360 / count;
        return Array.from({length: count}, (_, i) => 
            `hsl(${i * hueStep}, 70%, 60%)`
        );
    },

    formatCurrency(cents) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(cents / 100);
    }
};

function closeModal() {
    document.getElementById('transactionModal').classList.remove('active');
}

function setDateRange(period) {
    const form = document.querySelector('.date-filter-form');
    const today = new Date();
    let startDate, endDate;

    switch(period) {
        case 'this-month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'last-month':
            startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            endDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'last-3-months':
            startDate = new Date(today.getFullYear(), today.getMonth() - 2, 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'ytd':
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = today;
            break;
    }

    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
    document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
    form.submit();
}

document.getElementById('toggle-chart-type').addEventListener('click', () => {
    SpendingReport.toggleChartType();
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    SpendingReport.init();
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

.filter-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.filter-card h3 {
    margin-top: 0;
}

.date-filter-form .form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.form-group {
    flex: 1;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #cbd5e0;
    border-radius: 4px;
}

.quick-filters {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
}

.chart-container {
    height: 400px;
    position: relative;
}

.data-table-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.table-header h3 {
    margin: 0;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    max-width: 800px;
    width: 90%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.modal-header h2 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 2rem;
    cursor: pointer;
    color: #718096;
}

.modal-body {
    padding: 1.5rem;
    overflow-y: auto;
}

.loading-text {
    text-align: center;
    color: #718096;
    padding: 2rem;
}

.error-text {
    text-align: center;
    color: #e53e3e;
    padding: 2rem;
}

@media (max-width: 768px) {
    .report-header {
        flex-direction: column;
        gap: 1rem;
    }

    .date-filter-form .form-row {
        flex-direction: column;
    }

    .quick-filters {
        flex-wrap: wrap;
    }

    .chart-container {
        height: 300px;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
