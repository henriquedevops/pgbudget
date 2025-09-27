<?php
require_once '../../config/database.php';

$ledger_uuid = $_GET['ledger'] ?? '';

if (empty($ledger_uuid)) {
    $_SESSION['error'] = 'No budget specified.';
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = sanitizeInput($_POST['description']);
    $amount = parseCurrency($_POST['amount']);
    $date = $_POST['date'];
    $type = $_POST['type']; // 'inflow' or 'outflow'
    $account_uuid = sanitizeInput($_POST['account']);
    $category_uuid = sanitizeInput($_POST['category']);

    if (empty($description) || $amount <= 0 || empty($date) || empty($account_uuid)) {
        $_SESSION['error'] = 'Please fill in all required fields.';
    } else {
        try {
            $db = getDbConnection();

            // Set user context
            $stmt = $db->prepare("SELECT set_config('app.current_user_id', ?, false)");
            $stmt->execute([$_SESSION['user_id']]);

            // Add transaction using the API function
            $stmt = $db->prepare("SELECT api.add_transaction(?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $ledger_uuid,
                $date,
                $description,
                $type,
                $amount,
                $account_uuid,
                $category_uuid ?: null
            ]);

            $result = $stmt->fetch();
            if ($result) {
                $_SESSION['success'] = 'Transaction added successfully!';
                header("Location: ../budget/dashboard.php?ledger=" . $ledger_uuid);
                exit;
            } else {
                $_SESSION['error'] = 'Failed to add transaction.';
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
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

    // Get accounts for this ledger
    $stmt = $db->prepare("SELECT uuid, name, type FROM api.accounts WHERE ledger_uuid = ? ORDER BY name");
    $stmt->execute([$ledger_uuid]);
    $accounts = $stmt->fetchAll();

    // Get categories (equity accounts that aren't special)
    $stmt = $db->prepare("
        SELECT uuid, name FROM api.accounts
        WHERE ledger_uuid = ? AND type = 'equity'
        AND name NOT IN ('Income', 'Off-budget', 'Unassigned')
        ORDER BY name
    ");
    $stmt->execute([$ledger_uuid]);
    $categories = $stmt->fetchAll();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . $e->getMessage();
    header('Location: ../index.php');
    exit;
}

require_once '../../includes/header.php';
?>

<div class="container">
    <div class="header">
        <h1>Add Transaction</h1>
        <p>Add a new transaction to <?= htmlspecialchars($ledger['name']) ?></p>
    </div>

    <div class="form-container">
        <form method="POST" class="transaction-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="type" class="form-label">Transaction Type *</label>
                    <select id="type" name="type" class="form-select" required onchange="updateFormForType()">
                        <option value="">Choose type...</option>
                        <option value="inflow" <?= (isset($_POST['type']) && $_POST['type'] === 'inflow') ? 'selected' : '' ?>>
                            Income (Money In)
                        </option>
                        <option value="outflow" <?= (isset($_POST['type']) && $_POST['type'] === 'outflow') ? 'selected' : '' ?>>
                            Expense (Money Out)
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount" class="form-label">Amount *</label>
                    <input type="text" id="amount" name="amount" class="form-input" required
                           placeholder="$0.00"
                           value="<?= isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : '' ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description *</label>
                <input type="text" id="description" name="description" class="form-input" required
                       placeholder="e.g., Grocery shopping, Paycheck, Gas"
                       value="<?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?>">
            </div>

            <div class="form-group">
                <label for="date" class="form-label">Date *</label>
                <input type="date" id="date" name="date" class="form-input" required
                       value="<?= isset($_POST['date']) ? $_POST['date'] : date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label for="account" class="form-label">Account *</label>
                <select id="account" name="account" class="form-select" required>
                    <option value="">Choose account...</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= $account['uuid'] ?>"
                                <?= (isset($_POST['account']) && $_POST['account'] === $account['uuid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($account['name']) ?> (<?= htmlspecialchars($account['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="category-group">
                <label for="category" class="form-label">Category</label>
                <select id="category" name="category" class="form-select">
                    <option value="">Choose category...</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['uuid'] ?>"
                                <?= (isset($_POST['category']) && $_POST['category'] === $category['uuid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="unassigned">Unassigned</option>
                </select>
                <small class="form-help">Leave blank to use default Income account for inflows</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Add Transaction</button>
                <a href="../budget/dashboard.php?ledger=<?= $ledger_uuid ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="transaction-help">
        <h3>Transaction Types</h3>
        <div class="help-grid">
            <div class="help-item">
                <h4>Income (Inflow)</h4>
                <p>Money coming into your budget from external sources like salary, gifts, or returns.</p>
                <ul>
                    <li>Increases your account balance</li>
                    <li>Goes to Income category by default</li>
                    <li>Must be assigned to budget categories</li>
                </ul>
            </div>
            <div class="help-item">
                <h4>Expense (Outflow)</h4>
                <p>Money leaving your budget for purchases, bills, or transfers.</p>
                <ul>
                    <li>Decreases your account balance</li>
                    <li>Assigned to a budget category</li>
                    <li>Reduces available budget in that category</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.form-container {
    max-width: 600px;
    margin: 0 auto 3rem;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.transaction-form {
    width: 100%;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 2rem;
}

.form-help {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
}

.transaction-help {
    max-width: 800px;
    margin: 0 auto;
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid #3182ce;
}

.help-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-top: 1rem;
}

.help-item h4 {
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.help-item p {
    color: #4a5568;
    margin-bottom: 1rem;
}

.help-item ul {
    padding-left: 1.5rem;
}

.help-item li {
    color: #4a5568;
    margin-bottom: 0.25rem;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .help-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .form-actions {
        flex-direction: column;
    }
}
</style>

<script>
function updateFormForType() {
    const type = document.getElementById('type').value;
    const categoryGroup = document.getElementById('category-group');
    const categorySelect = document.getElementById('category');
    const categoryLabel = categoryGroup.querySelector('label');

    if (type === 'inflow') {
        categoryLabel.textContent = 'Category (Optional)';
        categoryGroup.querySelector('.form-help').textContent = 'Leave blank to use default Income account';
        categorySelect.required = false;
    } else if (type === 'outflow') {
        categoryLabel.textContent = 'Category *';
        categoryGroup.querySelector('.form-help').textContent = 'Choose the budget category for this expense';
        categorySelect.required = true;
    }
}

// Format amount input
document.getElementById('amount').addEventListener('input', function(e) {
    let value = e.target.value.replace(/[^0-9.]/g, '');
    if (value && !value.startsWith('$')) {
        const numValue = parseFloat(value);
        if (!isNaN(numValue)) {
            e.target.value = '$' + numValue.toFixed(2);
        }
    }
});

// Initialize form state
document.addEventListener('DOMContentLoaded', function() {
    updateFormForType();
});
</script>

<?php require_once '../../includes/footer.php'; ?>