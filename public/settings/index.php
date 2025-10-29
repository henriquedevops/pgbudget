<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/help-icon.php';

// Require authentication
requireAuth();

$db = getDbConnection();
setUserContext($db);

// Get user's ledgers
$stmt = $db->prepare("SELECT uuid, name, description FROM api.ledgers ORDER BY name");
$stmt->execute();
$ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="settings-header">
        <h1>⚙️ Settings</h1>
        <p>Manage your PgBudget preferences and budgets</p>
    </div>

    <!-- Budget Management Section -->
    <div class="settings-section">
        <div class="section-header">
            <h2>📊 Budget Management</h2>
        </div>
        <div class="settings-card">
            <div class="card-content">
                <h3>Your Budgets</h3>
                <p>Manage and create budget ledgers <?php renderHelpIcon("A Budget (or Ledger) is the top-level container for all your financial data, including accounts, categories, and transactions."); ?></p>

                <?php if (empty($ledgers)): ?>
                    <div class="empty-state">
                        <p>You don't have any budgets yet.</p>
                        <a href="../ledgers/create.php" class="btn btn-primary">Create Your First Budget</a>
                    </div>
                <?php else: ?>
                    <div class="budgets-list">
                        <?php foreach ($ledgers as $ledger): ?>
                            <div class="budget-item">
                                <div class="budget-info">
                                    <strong><?= htmlspecialchars($ledger['name']) ?></strong>
                                    <?php if ($ledger['description']): ?>
                                        <span class="budget-description"><?= htmlspecialchars($ledger['description']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="budget-actions">
                                    <a href="../budget/dashboard.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-small btn-primary">Open</a>
                                    <a href="../accounts/list.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-small btn-secondary">Accounts</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-actions">
                        <a href="../ledgers/create.php" class="btn btn-success">+ Create New Budget</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification Settings Section -->
    <div class="settings-section">
        <div class="section-header">
            <h2>🔔 Notifications</h2>
        </div>
        <div class="settings-card">
            <div class="card-content">
                <h3>Email Notifications</h3>
                <p>Configure when you want to receive email notifications</p>
                <a href="notifications.php" class="btn btn-secondary">Manage Notifications</a>
            </div>
        </div>
    </div>

    <!-- Action History Section -->
    <div class="settings-section">
        <div class="section-header">
            <h2>📜 Action History</h2>
        </div>
        <div class="settings-card">
            <div class="card-content">
                <h3>Transaction History</h3>
                <p>View your transaction correction and deletion history</p>
                <a href="action-history.php" class="btn btn-secondary">View History</a>
            </div>
        </div>
    </div>

    <!-- Account Information Section -->
    <div class="settings-section">
        <div class="section-header">
            <h2>👤 Account</h2>
        </div>
        <div class="settings-card">
            <div class="card-content">
                <h3>User Information</h3>
                <div class="user-info">
                    <div class="info-row">
                        <span class="info-label">Username:</span>
                        <span class="info-value"><?= htmlspecialchars($_SESSION['user_id']) ?></span>
                    </div>
                </div>
                <div class="section-actions">
                    <a href="../auth/logout.php" class="btn btn-danger">Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 2rem;
}

.settings-header {
    margin-bottom: 2rem;
}

.settings-header h1 {
    margin: 0 0 0.5rem 0;
    font-size: 2rem;
    color: #2d3748;
}

.settings-header p {
    margin: 0;
    color: #718096;
}

.settings-section {
    margin-bottom: 2rem;
}

.section-header {
    margin-bottom: 1rem;
}

.section-header h2 {
    margin: 0;
    font-size: 1.5rem;
    color: #2d3748;
}

.settings-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.card-content {
    padding: 2rem;
}

.card-content h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
    color: #2d3748;
}

.card-content > p {
    margin: 0 0 1.5rem 0;
    color: #718096;
}

.budgets-list {
    margin: 1.5rem 0;
}

.budget-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    margin-bottom: 0.5rem;
    background: #f7fafc;
    border-radius: 6px;
    border-left: 4px solid #3182ce;
}

.budget-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.budget-info strong {
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.budget-description {
    font-size: 0.875rem;
    color: #718096;
}

.budget-actions {
    display: flex;
    gap: 0.5rem;
}

.section-actions {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.user-info {
    margin: 1rem 0;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.info-label {
    font-weight: 600;
    color: #4a5568;
    width: 150px;
}

.info-value {
    color: #2d3748;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 6px;
    margin: 1rem 0;
}

.empty-state p {
    margin: 0 0 1rem 0;
    color: #718096;
}

@media (max-width: 768px) {
    .container {
        padding: 1rem;
    }

    .budget-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .budget-actions {
        width: 100%;
        justify-content: flex-start;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
