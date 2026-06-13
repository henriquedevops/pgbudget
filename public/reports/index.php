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
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    $_SESSION['error'] = 'An unexpected database error occurred. Please try again or contact support if the problem persists.';
    header('Location: ../index.php');
    exit;
}

$reports = [
    [
        'title' => 'Cash Flow Projection',
        'description' => 'Project your balances months ahead using bills, loans, income sources and planned events.',
        'href' => 'cash-flow-projection.php',
        'icon' => 'trending-up',
    ],
    [
        'title' => 'What-If Scenarios',
        'description' => 'Test hypothetical changes to income and expenses and see how they affect your projection.',
        'href' => 'what-if-projection.php',
        'icon' => 'git-branch',
    ],
    [
        'title' => 'Spending by Category',
        'description' => 'See where your money went over any date range, broken down by category.',
        'href' => 'spending-by-category.php',
        'icon' => 'pie-chart',
    ],
    [
        'title' => 'Income vs Expense',
        'description' => 'Compare income against expenses month by month to track your savings rate.',
        'href' => 'income-vs-expense.php',
        'icon' => 'bar-chart-2',
    ],
    [
        'title' => 'Net Worth',
        'description' => 'Track assets minus liabilities over time to see your overall financial trajectory.',
        'href' => 'net-worth.php',
        'icon' => 'line-chart',
    ],
    [
        'title' => 'Category Trends',
        'description' => 'Follow how spending in each category evolves across months.',
        'href' => 'category-trends.php',
        'icon' => 'activity',
    ],
    [
        'title' => 'Age of Money',
        'description' => 'Measure how long money sits in your accounts before being spent.',
        'href' => 'age-of-money.php',
        'icon' => 'clock',
    ],
    [
        'title' => 'Budget Report',
        'description' => 'Review budgeted versus actual amounts for each category.',
        'href' => 'budget.php',
        'icon' => 'target',
    ],
    [
        'title' => 'Installments Overview',
        'description' => 'Overview of your installment plans, schedules and remaining payments.',
        'href' => 'installments.php',
        'icon' => 'credit-card',
    ],
    [
        'title' => 'Installment Impact',
        'description' => 'See how current installment plans will weigh on future budgets.',
        'href' => 'installment-impact.php',
        'icon' => 'calendar',
    ],
];

$page_title = 'Reports';
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="../css/reports.css">
<style>
.reports-hub-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-4);
    margin-top: var(--space-5);
}
.report-card-link {
    display: flex;
    gap: var(--space-4);
    align-items: flex-start;
    padding: var(--space-5);
    background: var(--color-bg-card, #fff);
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: var(--radius-lg, 12px);
    text-decoration: none;
    color: inherit;
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.report-card-link:hover {
    border-color: var(--color-primary, #2563eb);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    transform: translateY(-1px);
}
.report-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: var(--radius-md, 8px);
    background: var(--color-primary-soft, rgba(37, 99, 235, 0.1));
    color: var(--color-primary, #2563eb);
}
.report-card-icon i, .report-card-icon svg { width: 20px; height: 20px; }
.report-card-title { font-weight: 600; margin-bottom: var(--space-1); }
.report-card-desc { font-size: var(--text-sm, 0.875rem); color: var(--color-fg-muted, #64748b); line-height: 1.45; }
</style>

<div class="container">
    <div class="report-header">
        <div>
            <h1>📈 Reports</h1>
            <p class="report-subtitle"><?= htmlspecialchars($ledger['name']) ?></p>
        </div>
        <div class="report-actions">
            <a href="../projected-events/?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Projected Events</a>
            <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">← Back to Budget</a>
        </div>
    </div>

    <div class="reports-hub-grid">
        <?php foreach ($reports as $report): ?>
            <a class="report-card-link" href="<?= htmlspecialchars($report['href']) ?>?ledger=<?= urlencode($ledger_uuid) ?>">
                <div class="report-card-icon"><i data-lucide="<?= htmlspecialchars($report['icon']) ?>" aria-hidden="true"></i></div>
                <div>
                    <div class="report-card-title"><?= htmlspecialchars($report['title']) ?></div>
                    <div class="report-card-desc"><?= htmlspecialchars($report['description']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
