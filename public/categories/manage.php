<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/header.php';

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

    // Get ledger details to verify access
    $stmt = $db->prepare("SELECT * FROM api.ledgers WHERE uuid = ?");
    $stmt->execute([$ledger_uuid]);
    $ledger = $stmt->fetch();

    if (!$ledger) {
        $_SESSION['error'] = 'Budget not found or access denied.';
        header('Location: ../index.php');
        exit;
    }

    // Get all categories (budget accounts) for this ledger
    $stmt = $db->prepare("
        SELECT uuid, name, type,
               CASE WHEN name IN ('Income', 'Off-budget', 'Unassigned') THEN true ELSE false END as is_system
        FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        ORDER BY
            CASE WHEN name = 'Income' THEN 1
                 WHEN name = 'Unassigned' THEN 2
                 WHEN name = 'Off-budget' THEN 3
                 ELSE 4 END,
            name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

    // Get budget status for categories
    $stmt = $db->prepare("SELECT * FROM api.get_budget_status(?)");
    $stmt->execute([$ledger_uuid]);
    $budget_status = $stmt->fetchAll();

    // Create a lookup array for budget status
    $budget_lookup = [];
    foreach ($budget_status as $status) {
        $budget_lookup[$status['category_uuid']] = $status;
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Database error occurred.';
    error_log("Database error: " . $e->getMessage());
    header('Location: ../index.php');
    exit;
}
?>

<div class="container">
    <div class="header">
        <h1>Manage Categories</h1>
        <p>Budget categories for <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="actions-bar">
        <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">+ Add New Category</a>
        <a href="../budget/dashboard.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <?php if (empty($categories)): ?>
        <div class="empty-state">
            <h3>No categories yet</h3>
            <p>Get started by creating your first budget category.</p>
            <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Create First Category</a>
        </div>
    <?php else: ?>
        <div class="categories-section">
            <h2>System Categories</h2>
            <div class="categories-grid">
                <?php foreach ($categories as $category): ?>
                    <?php if ($category['is_system']): ?>
                        <?php $status = $budget_lookup[$category['uuid']] ?? null; ?>
                        <div class="category-card system-category">
                            <div class="category-header">
                                <h3><?= htmlspecialchars($category['name']) ?></h3>
                                <span class="category-type system">System</span>
                            </div>

                            <?php if ($status): ?>
                                <div class="category-stats">
                                    <div class="stat-item">
                                        <span class="stat-label">Budgeted</span>
                                        <span class="stat-value"><?= formatCurrency($status['budgeted']) ?></span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Activity</span>
                                        <span class="stat-value <?= $status['activity'] >= 0 ? 'positive' : 'negative' ?>">
                                            <?= formatCurrency($status['activity']) ?>
                                        </span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="stat-label">Balance</span>
                                        <span class="stat-value <?= $status['balance'] >= 0 ? 'positive' : 'negative' ?>">
                                            <?= formatCurrency($status['balance']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="category-actions">
                                <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($category['uuid']) ?>" class="btn btn-small btn-secondary">View Transactions</a>
                                <?php if ($category['name'] === 'Income'): ?>
                                    <a href="../transactions/assign.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-small btn-primary">Assign Money</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <h2>Budget Categories</h2>
            <div class="categories-grid">
                <?php $user_categories = array_filter($categories, function($cat) { return !$cat['is_system']; }); ?>
                <?php if (empty($user_categories)): ?>
                    <div class="no-categories">
                        <p>No budget categories created yet.</p>
                        <a href="create.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-primary">Create Your First Category</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <?php if (!$category['is_system']): ?>
                            <?php $status = $budget_lookup[$category['uuid']] ?? null; ?>
                            <div class="category-card user-category">
                                <div class="category-header">
                                    <h3><?= htmlspecialchars($category['name']) ?></h3>
                                    <span class="category-type user">Budget</span>
                                </div>

                                <?php if ($status): ?>
                                    <div class="category-stats">
                                        <div class="stat-item">
                                            <span class="stat-label">Budgeted</span>
                                            <span class="stat-value"><?= formatCurrency($status['budgeted']) ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Activity</span>
                                            <span class="stat-value <?= $status['activity'] >= 0 ? 'positive' : 'negative' ?>">
                                                <?= formatCurrency($status['activity']) ?>
                                            </span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">Balance</span>
                                            <span class="stat-value <?= $status['balance'] >= 0 ? 'positive' : 'negative' ?>">
                                                <?= formatCurrency($status['balance']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="category-actions">
                                    <a href="../transactions/account.php?ledger=<?= urlencode($ledger_uuid) ?>&account=<?= urlencode($category['uuid']) ?>" class="btn btn-small btn-secondary">View Transactions</a>
                                    <a href="../transactions/assign.php?ledger=<?= urlencode($ledger_uuid) ?>&category=<?= urlencode($category['uuid']) ?>" class="btn btn-small btn-primary">Assign Money</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.actions-bar {
    display: flex;
    gap: 1rem;
    margin: 2rem 0;
}

.categories-section {
    margin-top: 2rem;
}

.categories-section h2 {
    margin: 2rem 0 1rem 0;
    color: #2d3748;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0.5rem;
}

.categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.category-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
    transition: box-shadow 0.2s;
}

.category-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.system-category {
    border-left: 4px solid #805ad5;
}

.user-category {
    border-left: 4px solid #38a169;
}

.category-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.category-header h3 {
    margin: 0;
    color: #2d3748;
}

.category-type {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.category-type.system {
    background: #e9d8fd;
    color: #553c9a;
}

.category-type.user {
    background: #c6f6d5;
    color: #2f855a;
}

.category-stats {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 6px;
}

.stat-item {
    text-align: center;
}

.stat-label {
    display: block;
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    font-weight: 600;
}

.stat-value {
    display: block;
    font-size: 1rem;
    font-weight: 600;
    color: #2d3748;
}

.stat-value.positive {
    color: #38a169;
}

.stat-value.negative {
    color: #e53e3e;
}

.category-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-small {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: #f7fafc;
    border-radius: 8px;
}

.empty-state h3 {
    color: #4a5568;
    margin-bottom: 1rem;
}

.empty-state p {
    color: #718096;
    margin-bottom: 2rem;
}

.no-categories {
    grid-column: 1 / -1;
    text-align: center;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 8px;
    border: 2px dashed #cbd5e0;
}

.no-categories p {
    color: #718096;
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .categories-grid {
        grid-template-columns: 1fr;
    }

    .category-stats {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .actions-bar {
        flex-direction: column;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>