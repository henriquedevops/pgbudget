<?php
require_once '../includes/session.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Require authentication - redirect to login if not logged in
requireAuth(true); // Allow demo mode for backward compatibility

// Set PostgreSQL user context
$db = getDbConnection();
setUserContext($db);

// Check if user needs onboarding (only if columns exist)
try {
    $stmt = $db->prepare("
        SELECT onboarding_completed, onboarding_step
        FROM data.users
        WHERE username = current_setting('app.current_user_id', true)
    ");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redirect to onboarding if not completed (unless they just completed it)
    if ($user && !$user['onboarding_completed'] && !isset($_GET['onboarding_complete'])) {
        header('Location: onboarding/wizard.php?step=' . max(1, $user['onboarding_step']));
        exit;
    }
} catch (PDOException $e) {
    // Columns don't exist yet - migrations not run
    // Continue without onboarding check
    error_log("Onboarding columns not found - migrations may not be run yet: " . $e->getMessage());
}

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

// Show success message if just completed onboarding
$showOnboardingSuccess = isset($_GET['onboarding_complete']);
?>

<?php if ($showOnboardingSuccess): ?>
<div class="alert alert-success" style="margin: 2rem auto; max-width: 800px;">
    <h3>🎉 Welcome to PGBudget!</h3>
    <p>Your budget is all set up and ready to go. Start by adding some income or recording your first transaction!</p>
</div>
<?php endif; ?>

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
                <a href="/onboarding/wizard.php?step=1" class="btn btn-primary">Create Your First Budget</a>
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