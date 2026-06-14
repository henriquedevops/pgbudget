<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/help-icon.php';

// Require authentication
requireAuth();

$db = getDbConnection();
setUserContext($db);

// Handle currency change for a budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_currency'], $_POST['ledger_uuid'])) {
    $currency = $_POST['currency'] ?? '';
    if (!isset(pgb_currencies()[$currency])) {
        $_SESSION['error'] = 'Invalid currency.';
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE api.ledgers
                SET metadata = jsonb_set(coalesce(metadata, '{}'::jsonb), '{currency}', to_jsonb(?::text))
                WHERE uuid = ?
            ");
            $stmt->execute([$currency, $_POST['ledger_uuid']]);
            $_SESSION['success'] = $stmt->rowCount() === 1
                ? 'Currency updated.'
                : 'Budget not found.';
        } catch (PDOException $e) {
            error_log('Database error: ' . $e->getMessage());
            $_SESSION['error'] = 'An unexpected database error occurred. Please try again or contact support if the problem persists.';
        }
    }
    header('Location: index.php');
    exit;
}

// Get user's ledgers
$stmt = $db->prepare("SELECT uuid, name, description, metadata->>'currency' AS currency FROM api.ledgers ORDER BY name");
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
                <p>Manage and create budgets <?php renderHelpIcon("A Budget (sometimes called a \"ledger\" in URLs and the API) is the top-level container for all your financial data, including accounts, categories, and transactions."); ?></p>

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
                                    <form method="POST" class="currency-form">
                                        <input type="hidden" name="set_currency" value="1">
                                        <input type="hidden" name="ledger_uuid" value="<?= htmlspecialchars($ledger['uuid']) ?>">
                                        <label class="sr-only" for="currency-<?= htmlspecialchars($ledger['uuid']) ?>">Currency for <?= htmlspecialchars($ledger['name']) ?></label>
                                        <select id="currency-<?= htmlspecialchars($ledger['uuid']) ?>" name="currency" class="form-input form-input-sm" onchange="this.form.submit()">
                                            <?php foreach (pgb_currencies() as $code => $cfg): ?>
                                                <option value="<?= $code ?>" <?= ($ledger['currency'] ?? 'USD') === $code ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cfg['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <a href="../budget/dashboard.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-sm btn-primary">Open</a>
                                    <a href="../accounts/list.php?ledger=<?= htmlspecialchars($ledger['uuid']) ?>" class="btn btn-sm btn-secondary">Accounts</a>
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

    <!-- Appearance Section -->
    <div class="settings-section">
        <div class="section-header">
            <h2>🎨 Appearance</h2>
        </div>
        <div class="settings-card">
            <div class="card-content">
                <h3>Theme</h3>
                <p>Choose a light or dark theme, or follow your device setting. Saved on this device.</p>
                <div class="theme-toggle" role="radiogroup" aria-label="Theme">
                    <button type="button" class="theme-option" role="radio" aria-checked="false" data-theme-value="light">☀️ Light</button>
                    <button type="button" class="theme-option" role="radio" aria-checked="false" data-theme-value="auto">🖥️ Auto</button>
                    <button type="button" class="theme-option" role="radio" aria-checked="false" data-theme-value="dark">🌙 Dark</button>
                </div>
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
    color: var(--color-text-primary);
}

.settings-header p {
    margin: 0;
    color: var(--color-text-muted);
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
    color: var(--color-text-primary);
}

.settings-card {
    background: var(--color-surface, white);
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
    border: 1px solid var(--color-border, #e2e8f0);
}

.card-content {
    padding: 2rem;
}

.card-content h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
    color: var(--color-text-primary);
}

.card-content > p {
    margin: 0 0 1.5rem 0;
    color: var(--color-text-muted);
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
    background: var(--gray-50, #f7fafc);
    border-radius: 6px;
    border-left: 4px solid var(--color-primary, #3182ce);
}

.budget-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.budget-info strong {
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.budget-description {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.budget-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.currency-form .form-input-sm {
    padding: 0.35rem 0.5rem;
    font-size: 0.875rem;
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: 6px;
    background: var(--color-surface, #fff);
    color: var(--color-text-primary, #2d3748);
}

.section-actions {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border, #e2e8f0);
}

.user-info {
    margin: 1rem 0;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--color-border, #e2e8f0);
}

.info-label {
    font-weight: 600;
    color: var(--color-text-muted, #4a5568);
    width: 150px;
}

.info-value {
    color: var(--color-text-primary);
}

.empty-state {
    text-align: center;
    padding: 2rem;
    background: var(--gray-50, #f7fafc);
    border-radius: 6px;
    margin: 1rem 0;
}

.empty-state p {
    margin: 0 0 1rem 0;
    color: var(--color-text-muted);
}

.theme-toggle {
    display: inline-flex;
    gap: 0.25rem;
    padding: 0.25rem;
    background: var(--gray-50, #f7fafc);
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: 8px;
}

.theme-option {
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.theme-option:hover {
    color: var(--color-text-primary);
}

.theme-option[aria-checked="true"] {
    background: var(--color-surface, #fff);
    color: var(--color-text-primary);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
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

<script>
(function () {
    var group = document.querySelector('.theme-toggle');
    if (!group) return;
    var options = group.querySelectorAll('.theme-option');

    function sync() {
        var pref = window.pgbGetThemePref ? window.pgbGetThemePref() : 'auto';
        options.forEach(function (btn) {
            var on = btn.getAttribute('data-theme-value') === pref;
            btn.setAttribute('aria-checked', on ? 'true' : 'false');
            btn.tabIndex = on ? 0 : -1;
        });
    }

    options.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (window.pgbSetTheme) window.pgbSetTheme(btn.getAttribute('data-theme-value'));
            sync();
        });
    });

    sync();
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
