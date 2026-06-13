<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = pgb_current_ledger();

if (empty($ledger_uuid)) {
    header('Location: /budget/dashboard.php');
    exit;
}

$db = getDbConnection();

$stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
$stmt->execute([$_SESSION['user_id']]);

$stmt = $db->prepare("SELECT name FROM data.ledgers WHERE uuid = ? AND user_data = current_setting('app.current_user_id')");
$stmt->execute([$ledger_uuid]);
$ledger = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ledger) {
    header('Location: /budget/dashboard.php');
    exit;
}

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

$page_title = 'Category Trends';
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="../css/reports.css">
<script src="/pgbudget/js/vendor/chart-4.4.0.umd.min.js"></script>

<div class="container">
    <div class="report-header">
        <div>
            <span class="eyebrow">Analytics</span>
            <h1>Category Spending Trends</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <div style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:var(--space-4);align-items:flex-end;">
            <div>
                <label class="eyebrow" style="display:block;margin-bottom:var(--space-1);">Category</label>
                <select id="category-select" class="input">
                    <option value="">-- Select a category --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category['uuid']) ?>">
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="eyebrow" style="display:block;margin-bottom:var(--space-1);">Time Period</label>
                <select id="months-select" class="input">
                    <option value="3">Last 3 months</option>
                    <option value="6">Last 6 months</option>
                    <option value="12" selected>Last 12 months</option>
                    <option value="24">Last 24 months</option>
                    <option value="36">Last 36 months</option>
                </select>
            </div>
            <button id="load-trend-btn" class="btn btn-primary" disabled style="align-self:flex-end;">Load Trend</button>
            <button id="export-csv-btn" class="btn btn-secondary" style="display:none;align-self:flex-end;">Export CSV</button>
        </div>
    </div>

    <!-- Loading -->
    <div id="loading-indicator" style="display:none;text-align:center;padding:var(--space-10);color:var(--color-fg-muted);">
        Loading trend data...
    </div>

    <!-- Error -->
    <div id="error-display" class="banner banner-warning" style="display:none;"></div>

    <!-- Results -->
    <div id="results-section" style="display:none;">

        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-label">Average Spending</div>
                <div class="summary-value" id="avg-spending">$0</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Average Budgeted</div>
                <div class="summary-value" id="avg-budgeted">$0</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Total Spending</div>
                <div class="summary-value" id="total-spending">$0</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Trend Direction</div>
                <div class="summary-value" id="trend-direction">--</div>
            </div>
        </div>

        <div class="chart-card" style="margin-bottom:var(--space-5);">
            <div class="chart-header">
                <h3>Spending vs Budgeted Over Time</h3>
            </div>
            <div class="chart-container">
                <canvas id="trend-chart"></canvas>
            </div>
        </div>

        <div class="insights-card">
            <h3>Insights</h3>
            <div id="insights-section"></div>
        </div>

        <div class="data-table-card" style="margin-top:var(--space-5);">
            <div class="table-header">
                <h3>Monthly Data</h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="num">Actual Spending</th>
                            <th class="num">Budgeted Amount</th>
                            <th class="num">Difference</th>
                            <th class="num">% of Budget</th>
                        </tr>
                    </thead>
                    <tbody id="data-table-body"></tbody>
                </table>
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
        const monthsSelect   = document.getElementById('months-select');
        const loadBtn        = document.getElementById('load-trend-btn');
        const exportBtn      = document.getElementById('export-csv-btn');

        categorySelect.addEventListener('change', (e) => {
            this.categoryUuid = e.target.value;
            loadBtn.disabled  = !this.categoryUuid;
        });
        monthsSelect.addEventListener('change', (e) => { this.months = parseInt(e.target.value); });
        loadBtn.addEventListener('click',  () => this.loadData());
        exportBtn.addEventListener('click', () => this.exportCSV());
    },

    async loadData() {
        if (!this.categoryUuid) return;

        const loadingEl    = document.getElementById('loading-indicator');
        const errorEl      = document.getElementById('error-display');
        const resultsEl    = document.getElementById('results-section');
        const exportBtn    = document.getElementById('export-csv-btn');

        loadingEl.style.display = 'block';
        errorEl.style.display   = 'none';
        resultsEl.style.display = 'none';

        try {
            const [trendResp, statsResp] = await Promise.all([
                fetch(`../api/get-category-trends.php?action=trend&category=${this.categoryUuid}&months=${this.months}`),
                fetch(`../api/get-category-trends.php?action=statistics&category=${this.categoryUuid}&months=${this.months}`)
            ]);

            if (!trendResp.ok || !statsResp.ok) {
                const errText = !trendResp.ok ? await trendResp.text() : await statsResp.text();
                throw new Error(`HTTP ${!trendResp.ok ? trendResp.status : statsResp.status}: ${errText}`);
            }

            const trendResult = await trendResp.json();
            const statsResult = await statsResp.json();

            if (!trendResult.success || !statsResult.success) {
                throw new Error(trendResult.error || statsResult.error || 'Unknown error');
            }

            this.trendData  = trendResult.data;
            this.statistics = statsResult.statistics;

            this.renderSummary();
            this.renderChart();
            this.renderInsights();
            this.renderDataTable();

            resultsEl.style.display    = 'block';
            exportBtn.style.display    = 'inline-flex';
        } catch (error) {
            console.error('Error loading trend data:', error);
            errorEl.textContent     = 'Error loading trend data: ' + error.message;
            errorEl.style.display   = 'block';
        } finally {
            loadingEl.style.display = 'none';
        }
    },

    renderSummary() {
        const s = this.statistics;
        document.getElementById('avg-spending').textContent   = this.formatCurrency(s.average_spending);
        document.getElementById('avg-budgeted').textContent   = this.formatCurrency(s.average_budgeted);
        document.getElementById('total-spending').textContent = this.formatCurrency(s.total_spending);

        const trendEl       = document.getElementById('trend-direction');
        trendEl.textContent = s.trend_direction.charAt(0).toUpperCase() + s.trend_direction.slice(1);
        trendEl.className   = 'summary-value trend-' + s.trend_direction;
    },

    renderChart() {
        const ctx = document.getElementById('trend-chart');
        if (this.chart) this.chart.destroy();

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: this.trendData.map(d => d.month_name),
                datasets: [
                    {
                        label: 'Actual Spending',
                        data:  this.trendData.map(d => d.actual_spending / 100),
                        borderColor: 'rgb(220,38,38)', backgroundColor: 'rgba(220,38,38,0.1)',
                        borderWidth: 2, fill: true, tension: 0.3
                    },
                    {
                        label: 'Budgeted Amount',
                        data:  this.trendData.map(d => d.budgeted_amount / 100),
                        borderColor: 'rgb(37,99,235)', backgroundColor: 'rgba(37,99,235,0.1)',
                        borderWidth: 2, fill: true, tension: 0.3, borderDash: [5,5]
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': $' + c.parsed.y.toFixed(2) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (v) => window.pgbFormatAmount(v) } }
                }
            }
        });
    },

    renderInsights() {
        const s = this.statistics;
        const insights = [];

        if (s.trend_direction === 'increasing') {
            insights.push(`Your spending is <strong>increasing</strong> over time. Consider reviewing if this aligns with your budget goals.`);
        } else if (s.trend_direction === 'decreasing') {
            insights.push(`Your spending is <strong>decreasing</strong> over time. Great job reducing expenses!`);
        } else {
            insights.push(`Your spending has remained <strong>stable</strong> over time.`);
        }

        const avgSpend   = s.average_spending / 100;
        const avgBudget  = s.average_budgeted  / 100;
        if (avgSpend > avgBudget * 1.1) {
            insights.push(`On average, you're spending <strong>${((avgSpend / avgBudget * 100) - 100).toFixed(1)}% more</strong> than budgeted.`);
        } else if (avgSpend < avgBudget * 0.9) {
            insights.push(`On average, you're spending <strong>${(100 - (avgSpend / avgBudget * 100)).toFixed(1)}% less</strong> than budgeted. Consider reallocating these funds.`);
        } else {
            insights.push(`Your spending is closely aligned with your budget on average.`);
        }

        if (s.months_over_budget  > 0) insights.push(`Over budget in <strong>${s.months_over_budget} of ${s.months_count}</strong> months.`);
        if (s.months_under_budget > 0) insights.push(`Under budget in <strong>${s.months_under_budget} of ${s.months_count}</strong> months.`);
        insights.push(`Highest spending: <strong>${this.formatCurrency(s.max_spending)}</strong> in ${s.max_month}.`);
        insights.push(`Lowest spending: <strong>${this.formatCurrency(s.min_spending)}</strong> in ${s.min_month}.`);

        document.getElementById('insights-section').innerHTML =
            insights.map(t => `<p class="insight-item">${t}</p>`).join('');
    },

    renderDataTable() {
        const tbody = document.getElementById('data-table-body');
        tbody.innerHTML = '';

        this.trendData.forEach(row => {
            const over = row.actual_spending > row.budgeted_amount;
            const tr   = document.createElement('tr');
            tr.innerHTML = `
                <td>${row.month_name}</td>
                <td class="num"><span class="money neg tnum">${this.formatCurrency(row.actual_spending)}</span></td>
                <td class="num"><span class="tnum">${this.formatCurrency(row.budgeted_amount)}</span></td>
                <td class="num"><span class="${over ? 'money neg tnum' : 'money pos tnum'}">${row.difference >= 0 ? '+' : ''}${this.formatCurrency(row.difference)}</span></td>
                <td class="num ${over ? 'money neg' : ''}">${row.percent_of_budget}%</td>
            `;
            tbody.appendChild(tr);
        });
    },

    exportCSV() {
        if (!this.categoryUuid) return;
        window.location.href = `../api/get-category-trends.php?action=csv&category=${this.categoryUuid}&months=${this.months}`;
    },

    formatCurrency(cents) {
        return window.pgbFormatCurrency(cents);
    }
};

document.addEventListener('DOMContentLoaded', () => CategoryTrendReport.init());
</script>

<style>
.trend-increasing { color: var(--color-danger, #dc2626); }
.trend-decreasing { color: var(--color-success, #16a34a); }
.trend-stable     { color: var(--color-fg-muted, #6b7280); }
</style>

<?php require_once '../../includes/footer.php'; ?>
