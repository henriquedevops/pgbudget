<?php
require_once '../config/database.php';
require_once '../includes/header.php';

// Set user context if not already set
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'demo_user';
}

// Set PostgreSQL user context
$db = getDbConnection();
$stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
$stmt->execute([$_SESSION['user_id']]);

// Get user's ledgers
$stmt = $db->prepare("SELECT uuid, name, description, created_at FROM api.ledgers ORDER BY created_at DESC");
$stmt->execute();
$ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <div class="header">
        <h1>💰 PgBudget</h1>
        <p>Zero-sum budgeting with double-entry accounting</p>
    </div>

    <div class="dashboard">
        <?php if (empty($ledgers)): ?>
            <div class="welcome-card">
                <h2>Welcome to PgBudget!</h2>
                <p>Get started by creating your first budget ledger.</p>
                <a href="ledgers/create.php" class="btn btn-primary">Create Your First Budget</a>
            </div>
        <?php else: ?>
            <div class="ledgers-grid">
                <?php foreach ($ledgers as $ledger): ?>
                    <div class="ledger-card">
                        <h3><a href="budget/dashboard.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>"><?= htmlspecialchars($ledger['name']) ?></a></h3>
                        <?php if ($ledger['description']): ?>
                            <p class="description"><?= htmlspecialchars($ledger['description']) ?></p>
                        <?php endif; ?>
                        <div class="ledger-actions">
                            <a href="budget/dashboard.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-primary">Open Budget</a>
                            <a href="accounts/list.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-secondary">Accounts</a>
                        </div>
                        <small class="created-date">Created: <?= date('M j, Y', strtotime($ledger['created_at'])) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="actions">
                <a href="ledgers/create.php" class="btn btn-success">+ Create New Budget</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>