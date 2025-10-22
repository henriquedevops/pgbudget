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

    // Get budget period for current month
    $current_period = date('Ym');

    // Get current budget to show available amounts
    $stmt = $db->prepare("
        SELECT
            a.uuid,
            a.name as category_name,
            ba.budgeted_amount,
            ba.actual_amount,
            (ba.budgeted_amount - ba.actual_amount) as available
        FROM data.budget_amounts ba
        JOIN data.accounts a ON ba.account_id = a.id
        WHERE ba.period = ?
        AND a.ledger_uuid = ?
        AND a.type = 'equity'
        ORDER BY a.name
    ");
    $stmt->execute([$current_period, $ledger_uuid]);
    $budget_categories = $stmt->fetchAll();

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
            <h1>📊 Budget Impact Report</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="installments.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Installment Report</a>
            <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <!-- Upcoming Installments (Next 30 Days) -->
    <div class="impact-section">
        <div class="section-header">
            <h2>📅 Upcoming Installments (Next 30 Days)</h2>
            <p class="section-description">Forecast of installment payments due in the next 30 days</p>
        </div>

        <div id="upcoming-installments-container" class="upcoming-installments">
            <div class="loading-state">Loading upcoming installments...</div>
        </div>
    </div>

    <!-- Category Impact Analysis -->
    <div class="impact-section">
        <div class="section-header">
            <h2>💰 Category-by-Category Impact</h2>
            <p class="section-description">How installment payments affect your budget categories</p>
        </div>

        <div id="category-impact-container" class="category-impact-grid">
            <div class="loading-state">Loading category impact...</div>
        </div>
    </div>

    <!-- Cash Flow Projection -->
    <div class="impact-section">
        <div class="section-header">
            <h2>📈 Cash Flow Projection (Next 6 Months)</h2>
            <p class="section-description">Projected installment obligations over time</p>
        </div>

        <div class="chart-card">
            <div class="chart-container">
                <canvas id="cashFlowProjectionChart"></canvas>
            </div>
        </div>
    </div>

    <!-- What-If Scenario Calculator -->
    <div class="impact-section">
        <div class="section-header">
            <h2>🔮 What-If Scenario</h2>
            <p class="section-description">See how adding a new installment plan would impact your budget</p>
        </div>

        <div class="what-if-calculator">
            <form id="what-if-form" class="what-if-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="what-if-amount">Purchase Amount</label>
                        <input type="number" id="what-if-amount" step="0.01" min="0" placeholder="1000.00" required>
                    </div>

                    <div class="form-group">
                        <label for="what-if-installments">Number of Installments</label>
                        <input type="number" id="what-if-installments" min="2" max="36" placeholder="12" required>
                    </div>

                    <div class="form-group">
                        <label for="what-if-category">Category</label>
                        <select id="what-if-category" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($budget_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['uuid']) ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="what-if-start-date">Start Date</label>
                        <input type="date" id="what-if-start-date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Calculate Impact</button>
                    <button type="reset" class="btn btn-secondary">Reset</button>
                </div>
            </form>

            <div id="what-if-result" class="what-if-result hidden">
                <div class="result-header">
                    <h3>Scenario Results</h3>
                </div>
                <div class="result-content">
                    <div class="result-item">
                        <span class="result-label">Monthly Payment:</span>
                        <span class="result-value" id="what-if-monthly">-</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Total Payments:</span>
                        <span class="result-value" id="what-if-total">-</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Impact on Category:</span>
                        <span class="result-value" id="what-if-impact">-</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Category After Impact:</span>
                        <span class="result-value" id="what-if-remaining">-</span>
                    </div>
                </div>
                <div class="result-chart">
                    <canvas id="whatIfProjectionChart"></canvas>
                </div>
            </div>
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

.impact-section {
    background: white;
    border-radius: 8px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.section-header {
    margin-bottom: 1.5rem;
}

.section-header h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    color: #2d3748;
}

.section-description {
    color: #718096;
    margin: 0;
    font-size: 0.875rem;
}

.loading-state {
    text-align: center;
    padding: 2rem;
    color: #718096;
    font-style: italic;
}

/* Upcoming Installments */
.upcoming-installments {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.installment-item {
    display: grid;
    grid-template-columns: 100px 1fr 150px 120px 120px;
    gap: 1rem;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 6px;
    border-left: 4px solid #3182ce;
    align-items: center;
}

.installment-item.overdue {
    border-left-color: #e53e3e;
    background: #fff5f5;
}

.installment-item.due-soon {
    border-left-color: #f59e0b;
    background: #fffbeb;
}

.installment-date {
    font-weight: 600;
    color: #2d3748;
}

.installment-date .day {
    font-size: 1.25rem;
    display: block;
}

.installment-date .month {
    font-size: 0.75rem;
    color: #718096;
    text-transform: uppercase;
}

.installment-details {
    display: flex;
    flex-direction: column;
}

.installment-plan-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.installment-progress {
    font-size: 0.875rem;
    color: #718096;
}

.installment-category {
    font-size: 0.875rem;
    color: #4a5568;
    padding: 0.25rem 0.75rem;
    background: white;
    border-radius: 4px;
    text-align: center;
}

.installment-amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
    text-align: right;
}

.installment-status {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    align-items: flex-end;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-badge.overdue {
    background: #fed7d7;
    color: #c53030;
}

.status-badge.due-soon {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.upcoming {
    background: #bee3f8;
    color: #2c5aa0;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #718096;
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

/* Category Impact Grid */
.category-impact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.category-impact-card {
    background: #f7fafc;
    border-radius: 6px;
    padding: 1.5rem;
    border: 1px solid #e2e8f0;
}

.category-impact-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.category-name {
    font-weight: 600;
    color: #2d3748;
    font-size: 1rem;
}

.impact-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.impact-badge.high {
    background: #fed7d7;
    color: #c53030;
}

.impact-badge.medium {
    background: #fef3c7;
    color: #92400e;
}

.impact-badge.low {
    background: #c6f6d5;
    color: #2f855a;
}

.category-impact-details {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
}

.detail-label {
    color: #718096;
}

.detail-value {
    font-weight: 600;
    color: #2d3748;
}

.detail-value.negative {
    color: #e53e3e;
}

.detail-value.positive {
    color: #38a169;
}

.impact-progress-bar {
    margin-top: 0.5rem;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}

.impact-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78 0%, #f59e0b 50%, #e53e3e 100%);
    transition: width 0.3s ease;
}

/* Chart Container */
.chart-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
}

.chart-container {
    position: relative;
    height: 400px;
}

/* What-If Calculator */
.what-if-calculator {
    background: #f7fafc;
    border-radius: 8px;
    padding: 2rem;
}

.what-if-form {
    margin-bottom: 2rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
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

.form-group input,
.form-group select {
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.875rem;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-actions {
    display: flex;
    gap: 0.5rem;
}

/* What-If Results */
.what-if-result {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    border: 2px solid #3182ce;
}

.what-if-result.hidden {
    display: none;
}

.result-header h3 {
    margin: 0 0 1rem 0;
    color: #2d3748;
}

.result-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.result-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.result-label {
    font-size: 0.875rem;
    color: #718096;
}

.result-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #2d3748;
}

.result-chart {
    margin-top: 1.5rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .container {
        padding: 1rem;
    }

    .report-header {
        flex-direction: column;
        gap: 1rem;
    }

    .report-actions {
        flex-direction: column;
        width: 100%;
    }

    .installment-item {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .installment-amount,
    .installment-status {
        text-align: left;
        align-items: flex-start;
    }

    .category-impact-grid {
        grid-template-columns: 1fr;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .result-content {
        grid-template-columns: 1fr;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ledgerUuid = <?= json_encode($ledger_uuid) ?>;
const budgetCategories = <?= json_encode($budget_categories) ?>;

// Load upcoming installments
async function loadUpcomingInstallments() {
    try {
        const response = await fetch(`../api/installment-schedules.php?ledger=${ledgerUuid}&upcoming=30`);
        const data = await response.json();

        const container = document.getElementById('upcoming-installments-container');

        if (!data.schedules || data.schedules.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>No Upcoming Installments</h3>
                    <p>You have no installment payments due in the next 30 days.</p>
                </div>
            `;
            return;
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        container.innerHTML = data.schedules.map(schedule => {
            const dueDate = new Date(schedule.scheduled_date);
            const daysUntilDue = Math.ceil((dueDate - today) / (1000 * 60 * 60 * 24));

            let statusClass = 'upcoming';
            let statusText = 'Upcoming';

            if (schedule.status === 'processed') {
                statusClass = 'processed';
                statusText = 'Processed';
            } else if (daysUntilDue < 0) {
                statusClass = 'overdue';
                statusText = `${Math.abs(daysUntilDue)} days overdue`;
            } else if (daysUntilDue <= 7) {
                statusClass = 'due-soon';
                statusText = daysUntilDue === 0 ? 'Due today' : `Due in ${daysUntilDue} days`;
            } else {
                statusText = `Due in ${daysUntilDue} days`;
            }

            const itemClass = daysUntilDue < 0 ? 'overdue' : (daysUntilDue <= 7 ? 'due-soon' : '');

            return `
                <div class="installment-item ${itemClass}">
                    <div class="installment-date">
                        <span class="day">${dueDate.getDate()}</span>
                        <span class="month">${dueDate.toLocaleDateString('en-US', { month: 'short' })}</span>
                    </div>
                    <div class="installment-details">
                        <div class="installment-plan-name">${escapeHtml(schedule.plan_description)}</div>
                        <div class="installment-progress">Installment ${schedule.installment_number} of ${schedule.total_installments}</div>
                    </div>
                    <div class="installment-category">${escapeHtml(schedule.category_name || 'Uncategorized')}</div>
                    <div class="installment-amount">${formatCurrency(schedule.scheduled_amount)}</div>
                    <div class="installment-status">
                        <span class="status-badge ${statusClass}">${statusText}</span>
                        ${schedule.status !== 'processed' ? `
                            <a href="../installments/process.php?ledger=${ledgerUuid}&plan=${schedule.plan_uuid}" class="btn btn-small btn-primary">Process</a>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');

    } catch (error) {
        console.error('Error loading upcoming installments:', error);
        document.getElementById('upcoming-installments-container').innerHTML = `
            <div class="error-state">Error loading upcoming installments. Please try again.</div>
        `;
    }
}

// Load category impact analysis
async function loadCategoryImpact() {
    try {
        const response = await fetch(`../api/installment-impact.php?ledger=${ledgerUuid}`);
        const data = await response.json();

        const container = document.getElementById('category-impact-container');

        if (!data.categories || data.categories.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <p>No category impact data available.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = data.categories.map(cat => {
            const impactPercent = cat.budgeted > 0 ? (cat.installment_impact / cat.budgeted * 100) : 0;
            const remaining = cat.available - cat.installment_impact;

            let impactLevel = 'low';
            if (impactPercent > 50) impactLevel = 'high';
            else if (impactPercent > 25) impactLevel = 'medium';

            return `
                <div class="category-impact-card">
                    <div class="category-impact-header">
                        <div class="category-name">${escapeHtml(cat.category_name)}</div>
                        <span class="impact-badge ${impactLevel}">${Math.round(impactPercent)}% Impact</span>
                    </div>
                    <div class="category-impact-details">
                        <div class="detail-row">
                            <span class="detail-label">Budgeted:</span>
                            <span class="detail-value">${formatCurrency(cat.budgeted)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Available:</span>
                            <span class="detail-value">${formatCurrency(cat.available)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Installments (30 days):</span>
                            <span class="detail-value negative">${formatCurrency(cat.installment_impact)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">After Installments:</span>
                            <span class="detail-value ${remaining >= 0 ? 'positive' : 'negative'}">${formatCurrency(remaining)}</span>
                        </div>
                        <div class="impact-progress-bar">
                            <div class="impact-progress-fill" style="width: ${Math.min(impactPercent, 100)}%"></div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

    } catch (error) {
        console.error('Error loading category impact:', error);
    }
}

// Load cash flow projection chart
async function loadCashFlowProjection() {
    try {
        const response = await fetch(`../api/installment-projection.php?ledger=${ledgerUuid}&months=6`);
        const data = await response.json();

        const ctx = document.getElementById('cashFlowProjectionChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.months.map(m => m.label),
                datasets: [{
                    label: 'Projected Installment Payments',
                    data: data.months.map(m => m.total_amount / 100),
                    borderColor: '#3182ce',
                    backgroundColor: 'rgba(49, 130, 206, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
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
                                return 'Payment: $' + context.parsed.y.toFixed(2);
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
    } catch (error) {
        console.error('Error loading cash flow projection:', error);
    }
}

// What-If Calculator
let whatIfChart = null;

document.getElementById('what-if-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const amount = parseFloat(document.getElementById('what-if-amount').value);
    const installments = parseInt(document.getElementById('what-if-installments').value);
    const categoryUuid = document.getElementById('what-if-category').value;
    const startDate = document.getElementById('what-if-start-date').value;

    if (!amount || !installments || !categoryUuid) {
        alert('Please fill in all fields');
        return;
    }

    // Calculate monthly payment
    const monthlyPayment = amount / installments;

    // Find selected category
    const category = budgetCategories.find(c => c.uuid === categoryUuid);
    if (!category) {
        alert('Category not found');
        return;
    }

    const categoryAvailable = category.available / 100;
    const afterImpact = categoryAvailable - monthlyPayment;

    // Display results
    document.getElementById('what-if-monthly').textContent = formatCurrency(Math.round(monthlyPayment * 100));
    document.getElementById('what-if-total').textContent = formatCurrency(Math.round(amount * 100));
    document.getElementById('what-if-impact').textContent = category.category_name;
    document.getElementById('what-if-remaining').textContent = formatCurrency(Math.round(afterImpact * 100));
    document.getElementById('what-if-remaining').className = 'result-value ' + (afterImpact >= 0 ? 'positive' : 'negative');

    document.getElementById('what-if-result').classList.remove('hidden');

    // Generate projection chart
    const months = [];
    const payments = [];
    let currentDate = new Date(startDate);

    for (let i = 0; i < installments; i++) {
        months.push(currentDate.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }));
        payments.push(monthlyPayment);
        currentDate.setMonth(currentDate.getMonth() + 1);
    }

    const ctx = document.getElementById('whatIfProjectionChart').getContext('2d');

    if (whatIfChart) {
        whatIfChart.destroy();
    }

    whatIfChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: 'Monthly Payment',
                data: payments,
                backgroundColor: 'rgba(245, 158, 11, 0.6)',
                borderColor: '#f59e0b',
                borderWidth: 2
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
                        label: function(context) {
                            return 'Payment: $' + context.parsed.y.toFixed(2);
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
});

document.getElementById('what-if-form').addEventListener('reset', function() {
    document.getElementById('what-if-result').classList.add('hidden');
    if (whatIfChart) {
        whatIfChart.destroy();
        whatIfChart = null;
    }
});

// Helper functions
function formatCurrency(cents) {
    const dollars = cents / 100;
    return '$' + dollars.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadUpcomingInstallments();
    loadCategoryImpact();
    loadCashFlowProjection();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
