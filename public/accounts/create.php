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

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = sanitizeInput($_POST['name'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $type = $_POST['type'] ?? '';

        // Validation
        if (empty($name)) {
            $_SESSION['error'] = 'Account name is required.';
        } elseif (empty($type)) {
            $_SESSION['error'] = 'Account type is required.';
        } elseif (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'])) {
            $_SESSION['error'] = 'Invalid account type.';
        } else {
            // Determine internal_type based on type
            $internal_type = in_array($type, ['asset', 'expense']) ? 'asset_like' : 'liability_like';

            try {
                // Insert new account
                $stmt = $db->prepare("
                    INSERT INTO data.accounts (name, description, type, internal_type, ledger_id)
                    VALUES (?, ?, ?, ?, (SELECT id FROM data.ledgers WHERE uuid = ?))
                ");

                $stmt->execute([$name, $description, $type, $internal_type, $ledger_uuid]);

                $_SESSION['success'] = 'Account created successfully!';
                header('Location: list.php?ledger=' . urlencode($ledger_uuid));
                exit;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'accounts_name_ledger_unique') !== false) {
                    $_SESSION['error'] = 'An account with this name already exists in this budget.';
                } else {
                    $_SESSION['error'] = 'Failed to create account. Please try again.';
                    error_log("Account creation error: " . $e->getMessage());
                }
            }
        }
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
        <h1>Create New Account</h1>
        <p>Add a new account to <strong><?= htmlspecialchars($ledger['name']) ?></strong></p>
    </div>

    <div class="form-container">
        <form method="POST" class="account-form">
            <div class="form-group">
                <label for="name">Account Name *</label>
                <input type="text"
                       id="name"
                       name="name"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       required
                       maxlength="255"
                       placeholder="e.g., Checking Account, Credit Card, Groceries">
                <small class="form-hint">Choose a descriptive name for this account</small>
            </div>

            <div class="form-group">
                <label for="type">Account Type *</label>
                <select id="type" name="type" required>
                    <option value="">Select account type...</option>
                    <option value="asset" <?= ($_POST['type'] ?? '') === 'asset' ? 'selected' : '' ?>>
                        Asset (Bank accounts, Cash, Investments)
                    </option>
                    <option value="liability" <?= ($_POST['type'] ?? '') === 'liability' ? 'selected' : '' ?>>
                        Liability (Credit cards, Loans, Debts)
                    </option>
                    <option value="equity" <?= ($_POST['type'] ?? '') === 'equity' ? 'selected' : '' ?>>
                        Equity (Net worth, Initial balances)
                    </option>
                    <option value="revenue" <?= ($_POST['type'] ?? '') === 'revenue' ? 'selected' : '' ?>>
                        Revenue (Income, Salary, Investments)
                    </option>
                    <option value="expense" <?= ($_POST['type'] ?? '') === 'expense' ? 'selected' : '' ?>>
                        Expense (Groceries, Rent, Utilities)
                    </option>
                </select>
                <small class="form-hint">Choose the type that best describes this account</small>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description"
                          name="description"
                          maxlength="255"
                          rows="3"
                          placeholder="Optional description of this account"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                <small class="form-hint">Optional: Additional details about this account</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Account</button>
                <a href="list.php?ledger=<?= urlencode($ledger_uuid) ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="account-types-info">
        <h3>Account Types Guide</h3>
        <div class="types-grid">
            <div class="type-card">
                <h4>Assets</h4>
                <p>Things you own that have value</p>
                <small>Bank accounts, cash, investments</small>
            </div>
            <div class="type-card">
                <h4>Liabilities</h4>
                <p>Debts and obligations you owe</p>
                <small>Credit cards, loans, mortgages</small>
            </div>
            <div class="type-card">
                <h4>Equity</h4>
                <p>Your net worth and initial balances</p>
                <small>Opening balances, retained earnings</small>
            </div>
            <div class="type-card">
                <h4>Revenue</h4>
                <p>Income and money coming in</p>
                <small>Salary, business income, interest</small>
            </div>
            <div class="type-card">
                <h4>Expenses</h4>
                <p>Money going out for goods/services</p>
                <small>Groceries, rent, utilities, entertainment</small>
            </div>
        </div>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 2rem auto;
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.account-form .form-group {
    margin-bottom: 1.5rem;
}

.account-form label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.account-form input,
.account-form select,
.account-form textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.account-form input:focus,
.account-form select:focus,
.account-form textarea:focus {
    outline: none;
    border-color: #3182ce;
    box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
}

.form-hint {
    display: block;
    margin-top: 0.25rem;
    font-size: 0.875rem;
    color: #718096;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.account-types-info {
    max-width: 800px;
    margin: 3rem auto;
    padding: 2rem;
    background: #f7fafc;
    border-radius: 8px;
}

.account-types-info h3 {
    margin-bottom: 1rem;
    color: #2d3748;
}

.types-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.type-card {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #3182ce;
}

.type-card h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.type-card p {
    color: #4a5568;
    margin-bottom: 0.5rem;
}

.type-card small {
    color: #718096;
    font-style: italic;
}
</style>

<?php require_once '../../includes/footer.php'; ?>