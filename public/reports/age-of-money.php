<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireAuth();

$ledger_uuid = pgb_current_ledger();

if (empty($ledger_uuid)) {
    header('Location: ../budget/dashboard.php');
    exit;
}

$db = getDbConnection();

$stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
$stmt->execute([$_SESSION['user_id']]);

$stmt = $db->prepare("SELECT name FROM data.ledgers WHERE uuid = ? AND user_data = current_setting('app.current_user_id')");
$stmt->execute([$ledger_uuid]);
$ledger = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ledger) {
    header('Location: ../budget/dashboard.php');
    exit;
}

$page_title = 'Age of Money';
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="../css/reports.css">
<script src="/pgbudget/js/vendor/chart-4.4.0.umd.min.js"></script>

<div class="container">
    <div class="report-header">
        <div>
            <span class="eyebrow">Analytics</span>
            <h1>Age of Money</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Explainer -->
    <div class="filter-card" style="margin-bottom:var(--space-5);">
        <p style="margin:0 0 var(--space-4);line-height:1.6;"><strong>Age of Money (AOM)</strong> measures the average number of days between receiving money and spending it — how long money "sits" in your budget before being used.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
            <div style="background:var(--color-primary-50,#eff6ff);border-radius:var(--radius-md);padding:var(--space-4);">
                <h4 style="margin:0 0 var(--space-2);color:var(--color-primary,#2563eb);">Higher AOM = Better Financial Health</h4>
                <p style="margin:0;color:var(--color-fg-muted);font-size:var(--text-sm);">An AOM of 30+ days means you're living on last month's income, creating a strong financial buffer.</p>
            </div>
            <div style="background:#fefce8;border-radius:var(--radius-md);padding:var(--space-4);border-left:4px solid #eab308;">
                <h4 style="margin:0 0 var(--space-2);color:#854d0e;">Age of Money Goals</h4>
                <ul style="margin:0;padding-left:1.25rem;color:#713f12;font-size:var(--text-sm);">
                    <li><strong>30+ days:</strong> Excellent — living on last month's income</li>
                    <li><strong>20–29 days:</strong> Good — keep building your buffer</li>
                    <li><strong>10–19 days:</strong> Fair — some buffer, room to improve</li>
                    <li><strong>0–9 days:</strong> Focus on building a buffer</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Loading -->
    <div id="loading-indicator" style="display:none;text-align:center;padding:var(--space-10);color:var(--color-fg-muted);">
        Calculating Age of Money...
    </div>

    <!-- Error -->
    <div id="error-display" class="banner banner-warning" style="display:none;"></div>

    <!-- Current AoM hero -->
    <div id="current-aom-section" style="display:none;">
        <div class="aom-hero">
            <div class="aom-hero-header">
                <h2 style="margin:0;">Current Age of Money</h2>
                <span id="calculation-date" style="font-size:var(--text-sm);opacity:0.9;"></span>
            </div>
            <div class="aom-hero-body">
                <div class="aom-value-block">
                    <div class="aom-value" id="aom-value">0</div>
                    <div style="font-size:var(--text-lg);opacity:0.9;text-transform:uppercase;letter-spacing:0.1em;">days</div>
                </div>
                <div class="aom-status-block">
                    <div id="status-badge" class="badge" style="font-size:var(--text-sm);padding:var(--space-2) var(--space-4);margin-bottom:var(--space-3);display:inline-block;"></div>
                    <p id="status-message" style="margin:0;font-size:var(--text-base);line-height:1.6;opacity:0.95;"></p>
                </div>
            </div>
            <div class="aom-hero-footer">
                <span style="font-size:var(--text-xs);opacity:0.85;display:block;margin-bottom:var(--space-1);">Transactions Analyzed</span>
                <span class="tnum" style="font-size:var(--text-xl);font-weight:600;" id="transaction-count">0</span>
            </div>
        </div>
    </div>

    <!-- Trend controls -->
    <div class="filter-card" id="trend-controls" style="display:none;">
        <div style="display:flex;align-items:center;gap:var(--space-4);flex-wrap:wrap;">
            <label style="font-weight:600;font-size:var(--text-sm);">Show Trend:</label>
            <select id="days-select" class="input" style="max-width:200px;">
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

    <!-- Trend chart -->
    <div class="chart-card" id="trend-section" style="display:none;margin-bottom:var(--space-5);">
        <div class="chart-header">
            <h3>Age of Money Trend</h3>
        </div>
        <div class="chart-container">
            <canvas id="aom-trend-chart"></canvas>
        </div>
    </div>

    <!-- Insights -->
    <div class="insights-card" id="insights-section" style="display:none;">
        <h3>Insights &amp; Tips</h3>
        <div id="insights-content"></div>
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
        document.getElementById('days-select').addEventListener('change',  (e) => { this.days = parseInt(e.target.value); });
        document.getElementById('refresh-btn').addEventListener('click',   () => this.loadData());
        document.getElementById('export-csv-btn').addEventListener('click', () => this.exportCSV());
    },

    async loadData() {
        const loadingEl = document.getElementById('loading-indicator');
        const errorEl   = document.getElementById('error-display');

        loadingEl.style.display = 'block';
        errorEl.style.display   = 'none';

        try {
            const [currResp, trendResp] = await Promise.all([
                fetch(`../api/get-age-of-money.php?action=current&ledger=${this.ledgerUuid}`),
                fetch(`../api/get-age-of-money.php?action=trend&ledger=${this.ledgerUuid}&days=${this.days}`)
            ]);

            if (!currResp.ok || !trendResp.ok) {
                const errText = !currResp.ok ? await currResp.text() : await trendResp.text();
                throw new Error(`HTTP ${!currResp.ok ? currResp.status : trendResp.status}: ${errText}`);
            }

            const currResult  = await currResp.json();
            const trendResult = await trendResp.json();

            if (!currResult.success || !trendResult.success) {
                throw new Error(currResult.error || trendResult.error || 'Unknown error');
            }

            this.currentData = currResult.current;
            this.trendData   = trendResult.data;

            this.renderCurrent();
            this.renderTrend();
            this.renderInsights();

            document.getElementById('current-aom-section').style.display = 'block';
            document.getElementById('trend-controls').style.display       = 'block';
            document.getElementById('trend-section').style.display        = 'block';
            document.getElementById('insights-section').style.display     = 'block';

        } catch (error) {
            console.error('Error loading Age of Money data:', error);
            errorEl.textContent   = 'Error loading Age of Money data: ' + error.message;
            errorEl.style.display = 'block';
        } finally {
            loadingEl.style.display = 'none';
        }
    },

    renderCurrent() {
        const data = this.currentData;
        document.getElementById('aom-value').textContent          = data.age_days;
        document.getElementById('calculation-date').textContent   = 'As of ' + this.formatDate(data.calculation_date);
        document.getElementById('transaction-count').textContent  = data.transaction_count.toLocaleString();
        document.getElementById('status-message').textContent     = data.status_message;

        const badge = document.getElementById('status-badge');
        badge.textContent = this.getStatusText(data.status);
        badge.className   = ({
            excellent:        'badge badge-success',
            good:             'badge badge-success',
            fair:             'badge badge-warning',
            needs_improvement:'badge badge-danger'
        }[data.status] || 'badge badge-neutral');
    },

    renderTrend() {
        const ctx = document.getElementById('aom-trend-chart');
        if (this.chart) this.chart.destroy();

        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(37,99,235,0.3)');
        gradient.addColorStop(1, 'rgba(37,99,235,0.05)');

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: this.trendData.map(d => this.formatDate(d.calculation_date)),
                datasets: [
                    {
                        label: 'Age of Money (Days)',
                        data:  this.trendData.map(d => d.age_days),
                        borderColor: 'rgb(37,99,235)', backgroundColor: gradient,
                        borderWidth: 3, fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 6
                    },
                    {
                        label: 'Goal (30 days)',
                        data: new Array(this.trendData.length).fill(30),
                        borderColor: 'rgba(34,197,94,0.5)', borderWidth: 2,
                        borderDash: [10,5], fill: false, pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (c) => c.datasetIndex === 0 ? 'Age of Money: ' + c.parsed.y + ' days' : c.dataset.label
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Days' },
                        ticks: { callback: (v) => v + ' days' }
                    },
                    x: { ticks: { maxTicksLimit: 10 } }
                }
            }
        });
    },

    renderInsights() {
        const data = this.currentData;
        const insights = [];

        if (data.age_days >= 30) {
            insights.push(`<strong>Excellent!</strong> Your Age of Money is ${data.age_days} days. You're successfully living on last month's income, providing a strong financial buffer.`);
        } else if (data.age_days >= 20) {
            insights.push(`<strong>Good progress!</strong> Your Age of Money is ${data.age_days} days — ${30 - data.age_days} days from the 30-day goal. Keep building!`);
        } else if (data.age_days >= 10) {
            insights.push(`<strong>Building momentum!</strong> At ${data.age_days} days you have some buffer. Focus on spending less than you earn to increase this further.`);
        } else {
            insights.push(`<strong>Opportunity for improvement.</strong> Your Age of Money is ${data.age_days} days. Focus on building a buffer by reducing expenses.`);
        }

        if (this.trendData.length >= 7) {
            const recent    = this.trendData.slice(-7).map(d => d.age_days);
            const older     = this.trendData.slice(0, 7).map(d => d.age_days);
            const recentAvg = recent.reduce((a, b) => a + b, 0) / recent.length;
            const olderAvg  = older.reduce((a, b) => a + b, 0) / older.length;
            if      (recentAvg > olderAvg * 1.1) insights.push(`<strong>Positive trend!</strong> Your Age of Money has been increasing recently.`);
            else if (recentAvg < olderAvg * 0.9) insights.push(`<strong>Declining trend.</strong> Your Age of Money has decreased recently — review spending.`);
            else                                  insights.push(`Your Age of Money has remained relatively stable over the selected period.`);
        }

        insights.push(`<strong>Tips to increase Age of Money:</strong>
            <ul style="margin:var(--space-2) 0 0;padding-left:1.25rem;">
                <li>Spend less than you earn each month</li>
                <li>Build an emergency fund to avoid dipping into current income</li>
                <li>Delay non-essential purchases to let money age</li>
                <li>Set aside money for future expenses in advance</li>
            </ul>`);

        document.getElementById('insights-content').innerHTML =
            insights.map(t => `<div class="insight-item">${t}</div>`).join('');
    },

    exportCSV() {
        window.location.href = `../api/get-age-of-money.php?action=csv&ledger=${this.ledgerUuid}&days=${this.days}`;
    },

    getStatusText(status) {
        return { excellent: 'Excellent', good: 'Good', fair: 'Fair', needs_improvement: 'Needs Improvement' }[status] || status;
    },

    formatDate(dateString) {
        const date = new Date(dateString + 'T00:00:00');
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
};

document.addEventListener('DOMContentLoaded', () => AgeOfMoneyReport.init());
</script>

<style>
.aom-hero {
    background: linear-gradient(135deg, var(--color-primary-700, #1d4ed8) 0%, #7c3aed 100%);
    color: #fff;
    border-radius: var(--radius-xl, 16px);
    padding: var(--space-6, 1.5rem) var(--space-8, 2rem);
    box-shadow: var(--shadow-e3, 0 8px 24px rgba(0,0,0,0.12));
    margin-bottom: var(--space-5, 1.25rem);
}
.aom-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-6, 1.5rem);
    padding-bottom: var(--space-4, 1rem);
    border-bottom: 1px solid rgba(255,255,255,0.2);
}
.aom-hero-body {
    display: flex;
    gap: var(--space-8, 2rem);
    align-items: center;
    margin-bottom: var(--space-6, 1.5rem);
}
.aom-value-block { text-align: center; }
.aom-value { font-size: 5rem; font-weight: 700; line-height: 1; margin-bottom: var(--space-2); }
.aom-status-block { flex: 1; }
.aom-hero-footer {
    padding-top: var(--space-4, 1rem);
    border-top: 1px solid rgba(255,255,255,0.2);
}
@media (max-width: 640px) {
    .aom-hero-body { flex-direction: column; gap: var(--space-4); }
    .aom-value { font-size: 3.5rem; }
    .aom-hero { padding: var(--space-5) var(--space-4); }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
