<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Require authentication - redirect to login if not logged in
requireAuth(true); // Allow demo mode for backward compatibility

// Set PostgreSQL user context
$db = getDbConnection();
setUserContext($db);

// Get user's ledgers
$stmt = $db->prepare("SELECT uuid, name, description FROM api.ledgers ORDER BY name");
$stmt->execute();
$ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If there's exactly one budget, redirect directly to it
if (count($ledgers) === 1) {
    header('Location: budget/dashboard.php?ledger=' . urlencode($ledgers[0]['uuid']));
    exit;
}

require_once '../includes/header.php';
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
                            <button
                                type="button"
                                class="btn btn-delete delete-ledger-btn"
                                data-ledger-uuid="<?= htmlspecialchars($ledger['uuid']) ?>"
                                data-ledger-name="<?= htmlspecialchars($ledger['name']) ?>"
                                title="Delete this budget"
                            >
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>